<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

use mysqli;
use RuntimeException;
use Throwable;

/** Bounded archival of synchronous telemetry, never the operational task journal. */
final class ActivityRetentionService
{
    private const SYNCHRONOUS = [
        'S3' => ['delete', 'move', 'upload'],
        'Drive' => ['rename'],
        'FederationCloud' => ['arcadelink_create', 'arcadelink_resolve', 'arcadelink_open'],
    ];

    public function __construct(private mysqli $db) {}

    public function run(int $days = 365, int $afterId = 0, int $limit = 500, bool $execute = false, ?string $archive = null): array
    {
        if ($days < 90 || $days > 3650 || $afterId < 0 || $limit < 1 || $limit > 1000) {
            throw new RuntimeException('Retención inválida: days=90..3650, after-id>=0, limit=1..1000.');
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $stream = null;
        $inTransaction = false;
        $report = ['execute' => $execute, 'cutoff_utc' => $cutoff, 'scanned' => 0, 'eligible' => 0,
            'preserved' => 0, 'deleted' => 0, 'next_after_id' => $afterId, 'has_more' => false];
        try {
            if ($execute) {
                $stream = $this->openArchive($archive);
                $this->writeLine($stream, ['format' => 'arcadecloud-activity-archive-v1', 'created_at_utc' => gmdate('c'), 'cutoff_utc' => $cutoff]);
                $this->db->begin_transaction();
                $inTransaction = true;
            }
            $stmt = $this->db->prepare('SELECT * FROM DriveActivityEvents WHERE id_ > ? ORDER BY id_ LIMIT ' . $limit . ($execute ? ' FOR UPDATE' : ''));
            $stmt->execute([$afterId]);
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $ids = [];
            foreach ($rows as $row) {
                $report['scanned']++;
                $report['next_after_id'] = (int)$row['id_'];
                if (!self::eligible($row, $cutoff)) { $report['preserved']++; continue; }
                $report['eligible']++;
                $ids[] = (int)$row['id_'];
                if ($execute) $this->writeLine($stream, ['row' => $row]);
            }
            $report['has_more'] = count($rows) === $limit;
            if ($execute) {
                $this->writeLine($stream, ['archived_rows' => count($ids)]);
                // Durable archive before any DELETE; failure rolls back the DB batch.
                if (!fflush($stream) || !fsync($stream)) throw new RuntimeException('No se pudo confirmar el archivo de respaldo.');
                $delete = $this->db->prepare('DELETE FROM DriveActivityEvents WHERE id_ = ?');
                foreach ($ids as $id) {
                    $delete->execute([$id]);
                    $report['deleted'] += $delete->affected_rows;
                }
                $delete->close();
                $this->db->commit();
                $inTransaction = false;
            }
            return $report;
        } catch (Throwable $error) {
            if ($inTransaction) $this->db->rollback();
            throw $error;
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
    }

    public static function eligible(array $row, string $cutoff): bool
    {
        if (!in_array((string)($row['Action'] ?? ''), self::SYNCHRONOUS[(string)($row['Service'] ?? '')] ?? [], true)) return false;
        if (!in_array($row['Status'] ?? '', ['ok', 'error'], true)) return false;
        // Correlation can be used for control/idempotency: preserve every correlated row.
        if (($row['CorrelationId'] ?? null) !== null && $row['CorrelationId'] !== '') return false;
        $created = (string)($row['CreatedAt'] ?? '');
        if ($created === '' || $created === '0000-00-00 00:00:00' || $created >= $cutoff) return false;
        $json = $row['MetadataJson'] ?? null;
        $metadata = $json === null || $json === '' ? [] : json_decode((string)$json, true);
        if (!is_array($metadata)) return false;
        // Unknown phases and job metadata are retained, even for an allowlisted action.
        foreach (['phase','status','task_status','task_id','job_id','job_name','cleanup_pending'] as $field) {
            if (array_key_exists($field, $metadata)) return false;
        }
        return true;
    }

    private function openArchive(?string $path)
    {
        if ($path === null || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new RuntimeException('--execute requiere --archive con ruta absoluta nueva fuera del repositorio.');
        }
        $parent = realpath(dirname($path));
        $repository = realpath(dirname(__DIR__, 3));
        if ($parent === false || $repository === false || $parent === $repository || str_starts_with($parent . '/', $repository . '/')) {
            throw new RuntimeException('El directorio de archivo debe existir fuera del repositorio público.');
        }
        $mask = umask(0077);
        try { $stream = @fopen($parent . '/' . basename($path), 'xb'); }
        finally { umask($mask); }
        if ($stream === false) throw new RuntimeException('No se pudo crear el archivo privado; no se sobrescriben archivos existentes.');
        // Persist the new directory entry as well as the file contents before deleting rows.
        $directory = @fopen($parent, 'r');
        try {
            if ($directory === false || !@fsync($directory)) {
                fclose($stream);
                throw new RuntimeException('No se pudo confirmar el directorio del respaldo.');
            }
        } finally {
            if (is_resource($directory)) fclose($directory);
        }
        return $stream;
    }

    private function writeLine($stream, array $data): void
    {
        $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        while ($line !== '') {
            $bytes = fwrite($stream, $line);
            if ($bytes === false || $bytes === 0) throw new RuntimeException('No se pudo escribir el respaldo de actividad.');
            $line = substr($line, $bytes);
        }
    }
}
