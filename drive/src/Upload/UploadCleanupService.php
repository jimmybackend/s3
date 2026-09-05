<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\S3\S3Client;
use DateTimeInterface;
use mysqli;
use RuntimeException;

final class UploadCleanupService
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $userStoragePath,
        private string $stateDir
    ) {
        $this->stateDir = rtrim($this->stateDir, '/\\');
    }

    /**
     * Limpieza segura de subidas abandonadas.
     *
     * Reglas:
     * - Sólo trabaja dentro de DataN/uploads/.
     * - Un objeto completado se borra únicamente si tiene más de $olderThanDays
     *   y NO existe en FileS3 para ese usuario.
     * - Los multipart incompletos se abortan únicamente si superan la edad indicada.
     * - Los archivos locales de estado .json se eliminan únicamente si están vencidos.
     * - $execute=false nunca modifica S3 ni el filesystem.
     */
    public function run(int $olderThanDays = 30, bool $execute = false): array
    {
        $olderThanDays = max(1, min(3650, $olderThanDays));
        $cutoff = time() - ($olderThanDays * 86400);

        $report = [
            'execute' => $execute,
            'older_than_days' => $olderThanDays,
            'cutoff' => gmdate('c', $cutoff),
            'users' => 0,
            'multipart_seen' => 0,
            'multipart_stale' => 0,
            'multipart_aborted' => 0,
            'objects_seen' => 0,
            'objects_old' => 0,
            'objects_registered' => 0,
            'objects_orphan' => 0,
            'objects_deleted' => 0,
            'states_seen' => 0,
            'states_stale' => 0,
            'states_deleted' => 0,
            'items' => [],
        ];

        foreach ($this->userIds() as $userId) {
            $report['users']++;
            $prefix = rtrim($this->userStoragePath->rootForUser($userId), '/') . '/uploads/';
            $registered = $this->registeredKeys($userId, $prefix);

            $this->scanMultipart($prefix, $cutoff, $execute, $report);
            $this->scanObjects($userId, $prefix, $registered, $cutoff, $execute, $report);
        }

        $this->scanStateFiles($cutoff, $execute, $report);

        return $report;
    }

    /** @return array<int,int> */
    private function userIds(): array
    {
        $result = $this->db->query('SELECT id FROM Users ORDER BY id ASC');
        if (!$result) {
            throw new RuntimeException('No se pudieron consultar los usuarios: ' . $this->db->error);
        }

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $result->free();

        return $ids;
    }

    /** @return array<string,true> */
    private function registeredKeys(int $userId, string $prefix): array
    {
        $stmt = $this->db->prepare(
            "SELECT Ruta, Encriptado
             FROM FileS3
             WHERE user_id_ = ?
               AND (Ruta = ? OR Ruta LIKE CONCAT(?, '%'))"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta de FileS3: ' . $this->db->error);
        }

        $stmt->bind_param('iss', $userId, $prefix, $prefix);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo consultar FileS3: ' . $error);
        }

        $result = $stmt->get_result();
        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $key = $this->buildKey(
                (string)($row['Ruta'] ?? ''),
                (string)($row['Encriptado'] ?? '')
            );
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        $stmt->close();

        return $keys;
    }

    private function scanMultipart(
        string $prefix,
        int $cutoff,
        bool $execute,
        array &$report
    ): void {
        $params = [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ];

        do {
            $res = $this->s3->listMultipartUploads($params);
            foreach (($res->get('Uploads') ?: []) as $upload) {
                $report['multipart_seen']++;

                $key = trim((string)($upload['Key'] ?? ''));
                $uploadId = trim((string)($upload['UploadId'] ?? ''));
                $initiatedTs = $this->timestamp($upload['Initiated'] ?? null);

                if ($key === '' || $uploadId === '' || $initiatedTs <= 0 || $initiatedTs > $cutoff) {
                    continue;
                }

                $report['multipart_stale']++;
                $item = [
                    'type' => 'multipart',
                    'key' => $key,
                    'age_days' => $this->ageDays($initiatedTs),
                    'action' => $execute ? 'abort' : 'would_abort',
                ];

                if ($execute) {
                    $this->s3->abortMultipartUpload([
                        'Bucket' => $this->bucket,
                        'Key' => $key,
                        'UploadId' => $uploadId,
                    ]);
                    $report['multipart_aborted']++;
                }

                $report['items'][] = $item;
            }

            $params['KeyMarker'] = $res->get('NextKeyMarker');
            $params['UploadIdMarker'] = $res->get('NextUploadIdMarker');
        } while ((bool)$res->get('IsTruncated'));
    }

    /** @param array<string,true> $registered */
    private function scanObjects(
        int $userId,
        string $prefix,
        array $registered,
        int $cutoff,
        bool $execute,
        array &$report
    ): void {
        $params = [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ];

        do {
            $res = $this->s3->listObjectsV2($params);

            foreach (($res->get('Contents') ?: []) as $object) {
                $key = trim((string)($object['Key'] ?? ''));
                if ($key === '' || str_ends_with($key, '/')) {
                    continue;
                }

                $report['objects_seen']++;
                $lastModifiedTs = $this->timestamp($object['LastModified'] ?? null);
                if ($lastModifiedTs <= 0 || $lastModifiedTs > $cutoff) {
                    continue;
                }

                $report['objects_old']++;

                if (isset($registered[$key])) {
                    $report['objects_registered']++;
                    continue;
                }

                $report['objects_orphan']++;
                $item = [
                    'type' => 'orphan_object',
                    'user_id' => $userId,
                    'key' => $key,
                    'age_days' => $this->ageDays($lastModifiedTs),
                    'action' => $execute ? 'delete' : 'would_delete',
                ];

                if ($execute) {
                    $this->s3->deleteObject([
                        'Bucket' => $this->bucket,
                        'Key' => $key,
                    ]);
                    $report['objects_deleted']++;
                }

                $report['items'][] = $item;
            }

            $next = $res->get('NextContinuationToken');
            if ($next) {
                $params['ContinuationToken'] = $next;
            } else {
                unset($params['ContinuationToken']);
            }
        } while ((bool)$res->get('IsTruncated'));
    }

    private function scanStateFiles(int $cutoff, bool $execute, array &$report): void
    {
        if (!is_dir($this->stateDir)) {
            return;
        }

        foreach (glob($this->stateDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $report['states_seen']++;

            $data = json_decode((string)@file_get_contents($file), true);
            $created = is_array($data) ? (int)($data['created'] ?? 0) : 0;
            $timestamp = $created > 0 ? $created : (int)(@filemtime($file) ?: 0);

            if ($timestamp <= 0 || $timestamp > $cutoff) {
                continue;
            }

            $key = is_array($data) ? trim((string)($data['key'] ?? '')) : '';
            if ($key !== '' && !$this->isUploadKey($key)) {
                continue;
            }

            $report['states_stale']++;
            $report['items'][] = [
                'type' => 'local_state',
                'file' => basename($file),
                'key' => $key,
                'age_days' => $this->ageDays($timestamp),
                'action' => $execute ? 'delete_state' : 'would_delete_state',
            ];

            if ($execute && @unlink($file)) {
                $report['states_deleted']++;
            }
        }
    }

    private function buildKey(string $route, string $encrypted): string
    {
        $route = rtrim(str_replace('\\', '/', trim($route)), '/') . '/';
        $encrypted = ltrim(str_replace('\\', '/', trim($encrypted)), '/');
        if ($encrypted === '') {
            return '';
        }
        if (str_starts_with($encrypted, $route)) {
            return $encrypted;
        }
        return $route . $encrypted;
    }

    private function isUploadKey(string $key): bool
    {
        $key = ltrim(str_replace('\\', '/', trim($key)), '/');
        return preg_match('~^Data(?:\d+)?/uploads/~', $key) === 1;
    }

    private function timestamp(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if ($value === null || $value === '') {
            return 0;
        }
        $timestamp = strtotime((string)$value);
        return $timestamp === false ? 0 : $timestamp;
    }

    private function ageDays(int $timestamp): float
    {
        return round(max(0, time() - $timestamp) / 86400, 1);
    }
}
