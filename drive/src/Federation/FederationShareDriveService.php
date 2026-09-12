<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationShareDriveService
{
    private FederationShareDriveRepository $shares;
    private FederationShareDownloader $downloader;

    public function __construct(private DriveApplication $app)
    {
        $this->shares = new FederationShareDriveRepository($this->app->db());
        $this->downloader = new FederationShareDownloader();
    }

    public function state(int $userId): array
    {
        if ($userId <= 0) throw new FederationException('Usuario local inválido.', 401);
        return [
            'ok' => true,
            'received' => $this->shares->receivedForUser($userId),
        ];
    }

    public function queueImport(int $userId, string $shareId): array
    {
        $share = $this->requireImportableShare($userId, $shareId);
        $existingFound = (int)($share['LocalFileFound'] ?? 0) === 1 && (int)($share['LocalFileId'] ?? 0) > 0;
        if ($this->copyIsCurrent($share, $existingFound)) {
            return [
                'ok' => true,
                'already_imported' => true,
                'file_id' => (int)$share['LocalFileId'],
                'status' => 'completed',
                'message' => 'Este Share ya tiene una copia vigente en Mi Drive.',
            ];
        }

        $resourceUpdatedAt = is_string($share['ResourceUpdatedAt'] ?? null) ? (string)$share['ResourceUpdatedAt'] : null;
        $contentId = is_string($share['ResourceContentId'] ?? null) && $share['ResourceContentId'] !== ''
            ? (string)$share['ResourceContentId'] : null;
        $versionBasis = $contentId !== null
            ? 'content:' . $contentId
            : 'updated:' . ($resourceUpdatedAt ?? (string)($share['UpdatedAt'] ?? $share['CreatedAt'] ?? 'unknown'));
        $versionKey = hash('sha256', (string)$share['ResourceId'] . '|' . $versionBasis);
        $job = $this->shares->queueImport(
            $userId,
            $shareId,
            (string)$share['ResourceId'],
            $versionKey,
            $resourceUpdatedAt
        );
        $status = (string)($job['Status'] ?? 'queued');

        // Si el usuario borró su copia personal, la misma versión puede volver a copiarse.
        if (!$existingFound && $status === 'completed') {
            $this->shares->requeueCompleted((string)$job['ImportId']);
            $status = 'queued';
        }

        return [
            'ok' => true,
            'already_imported' => $status === 'completed',
            'import_id' => (string)$job['ImportId'],
            'status' => $status,
            'message' => $status === 'completed'
                ? 'Esta versión ya fue agregada a Mi Drive.'
                : 'La copia quedó en cola. FederationCloud la agregará a Mi Drive en segundo plano.',
        ];
    }

    public function syncPending(int $limit = 1): array
    {
        $processed = 0;
        $completed = 0;
        $retry = 0;
        $failed = 0;

        foreach ($this->shares->dueImports($limit) as $job) {
            $importId = (string)$job['ImportId'];
            if (!$this->shares->markProcessing($importId)) continue;
            $processed++;
            try {
                $fileId = $this->performImport(
                    (int)$job['UserId'],
                    (string)$job['ShareId'],
                    (string)$job['ResourceId']
                );
                $this->shares->markImportCompleted($importId, $fileId);
                $completed++;
            } catch (FederationException $e) {
                if (in_array($e->httpStatus(), [400, 401, 403, 404, 409], true)) {
                    $this->shares->markImportFailed($importId, $e->getMessage());
                    $failed++;
                } else {
                    $state = $this->shares->markImportRetry($importId, $e->getMessage());
                    if ($state === 'failed') $failed++;
                    else $retry++;
                }
            } catch (Throwable $e) {
                error_log('[FederationCloud Share import worker] ' . $e->getMessage());
                $state = $this->shares->markImportRetry($importId, 'Error interno al copiar el archivo compartido.');
                if ($state === 'failed') $failed++;
                else $retry++;
            }
        }

        return [
            'processed' => $processed,
            'completed' => $completed,
            'retry' => $retry,
            'failed' => $failed,
        ];
    }

    private function performImport(int $userId, string $shareId, string $expectedResourceId): int
    {
        $share = $this->requireImportableShare($userId, $shareId);
        if (!hash_equals($expectedResourceId, (string)$share['ResourceId'])) {
            throw new FederationException('El recurso del Share cambió durante la importación.', 409);
        }

        $accessUrl = trim((string)($share['AccessUrl'] ?? ''));
        $download = $this->downloader->download($accessUrl);
        $tmp = (string)$download['path'];
        try {
            $title = $this->safeFileName((string)$share['Title'], (string)$share['MediaType']);
            $mimeType = trim((string)$share['MediaType']);
            if ($mimeType === '') $mimeType = (string)($download['content_type'] ?? 'application/octet-stream');
            $root = $this->app->userStoragePath()->rootForUser($userId);
            $result = $this->app->singleUploadService()->upload(
                $tmp,
                $title,
                $root,
                $userId,
                $mimeType,
                (int)$download['size_bytes'],
                'FederationCloud',
                'FederationCloud Share import'
            );

            $fileId = (int)$result['id'];
            $s3Key = (string)$result['key_s3'];
            $resourceUpdatedAt = is_string($share['ResourceUpdatedAt'] ?? null) ? (string)$share['ResourceUpdatedAt'] : null;
            $contentId = is_string($share['ResourceContentId'] ?? null) && $share['ResourceContentId'] !== ''
                ? (string)$share['ResourceContentId'] : null;
            $this->shares->attachProvenance(
                $userId,
                $fileId,
                $shareId,
                (string)$share['ResourceId'],
                (string)$share['RemoteNodeId']
            );
            $this->shares->markImported($userId, $shareId, $fileId, $s3Key, $resourceUpdatedAt, $contentId);
            return $fileId;
        } catch (Throwable $e) {
            if ($e instanceof FederationException) throw $e;
            error_log('[FederationCloud Share import] ' . $e->getMessage());
            throw new FederationException('No se pudo agregar el Share a Mi Drive.', 500);
        } finally {
            @unlink($tmp);
        }
    }

    private function requireImportableShare(int $userId, string $shareId): array
    {
        if ($userId <= 0) throw new FederationException('Usuario local inválido.', 401);
        if (!preg_match('/\Afar_[A-Za-z0-9_-]{16,80}\z/', $shareId)) {
            throw new FederationException('Share ID inválido.', 400);
        }
        $share = $this->shares->receivedShare($userId, $shareId);
        if ($share === null) throw new FederationException('Archivo compartido no encontrado.', 404);
        if ((string)$share['Status'] !== 'active') throw new FederationException('El Share ya no está activo.', 409);
        if ($share['ExpiresAt'] !== null && strtotime((string)$share['ExpiresAt']) <= time()) {
            throw new FederationException('El Share ya expiró.', 409);
        }
        if (trim((string)($share['AccessUrl'] ?? '')) === '') {
            throw new FederationException('El Share no tiene acceso descargable.', 409);
        }
        return $share;
    }

    private function copyIsCurrent(array $share, bool $existingFound): bool
    {
        if (!$existingFound) return false;
        $contentId = is_string($share['ResourceContentId'] ?? null) && $share['ResourceContentId'] !== ''
            ? (string)$share['ResourceContentId'] : null;
        $importedContentId = is_string($share['ImportedContentId'] ?? null) && $share['ImportedContentId'] !== ''
            ? (string)$share['ImportedContentId'] : null;
        if ($contentId !== null && $importedContentId !== null) {
            return hash_equals($importedContentId, $contentId);
        }

        $updatedAt = is_string($share['ResourceUpdatedAt'] ?? null) ? (string)$share['ResourceUpdatedAt'] : null;
        $importedUpdatedAt = is_string($share['ImportedResourceUpdatedAt'] ?? null)
            ? (string)$share['ImportedResourceUpdatedAt'] : null;
        if ($updatedAt !== null && $importedUpdatedAt !== null) {
            return strtotime($updatedAt) <= strtotime($importedUpdatedAt);
        }

        // Sin un identificador/versionado remoto mejor, no duplicamos una copia existente.
        return true;
    }

    private function safeFileName(string $name, string $mediaType): string
    {
        $name = trim(str_replace(['\\', '/'], '-', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? $name;
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') $name = 'archivo-compartido';
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 220);
        else $name = substr($name, 0, 220);

        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $extension = $this->extensionForMime($mediaType);
            if ($extension !== '') $name .= '.' . $extension;
        }
        return $name;
    }

    private function extensionForMime(string $mediaType): string
    {
        return match (strtolower(trim($mediaType))) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'application/zip' => 'zip',
            default => '',
        };
    }
}
