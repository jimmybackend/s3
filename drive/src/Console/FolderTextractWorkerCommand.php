<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Console;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Aws\FileMetadataRepository;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\FolderTextractJobStore;
use ArcadeCloud\Drive\Aws\FolderTextractService;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\TextractFileService;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Storage\UserStoragePath;

final class FolderTextractWorkerCommand
{
    public function run(array $argv): int
    {
        $jobId = strtolower(trim((string)($argv[1] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) return 2;

        $store = new FolderTextractJobStore();
        try {
            $job = $store->get($jobId);
            if ((string)($job['status'] ?? '') !== 'queued') return 0;

            $userId = (int)($job['user_id'] ?? 0);
            if ($userId <= 0) return 2;

            $store->update($jobId, [
                'status' => 'running',
                'message' => 'Preparando imágenes para Amazon Textract.',
                'started_at' => gmdate('c'),
            ]);

            $app = ApplicationKernel::app();
            $locator = new FileRecordLocator($app->db());
            $textract = new TextractFileService(
                $locator,
                new FileMetadataRepository($app->db()),
                \Config::getTextract(),
                $app->bucket()
            );
            $service = new FolderTextractService(
                $app->db(),
                $textract,
                new GeneratedFileRepository($app->db()),
                $app->s3(),
                $app->bucket(),
                new UserStoragePath()
            );

            $result = $service->extract(
                $userId,
                (string)($job['route'] ?? ''),
                (string)($job['name'] ?? ''),
                function (int $processed, int $total, string $file) use ($store, $jobId): void {
                    $pct = $total > 0 ? (int)floor(($processed / $total) * 100) : 0;
                    $message = $processed <= 0
                        ? 'Preparando ' . $total . ' imagen(es).'
                        : 'Procesando hoja ' . $processed . ' de ' . $total . ($file !== '' ? ' · ' . $file : '');
                    $store->update($jobId, [
                        'processed' => $processed,
                        'total' => $total,
                        'progress' => max(0, min(99, $pct)),
                        'current_file' => $file,
                        'message' => $message,
                    ]);
                }
            );

            $pages = max(1, (int)($result['billable_pages'] ?? $result['paginas'] ?? 1));
            $correlation = ActivityCostRecorder::correlation('folder-textract', $jobId);
            $activity = ActivityCostRecorder::fromDatabase($app->db());
            $activity->success(
                $userId,
                'textract_folder',
                'Textract',
                null,
                ['textract.detect_document_text_page' => $pages],
                null,
                [
                    'pages' => $pages,
                    'source_images' => max(0, (int)($result['paginas'] ?? 0)),
                    'output_bytes' => max(0, (int)($result['bytes'] ?? 0)),
                ],
                $correlation
            );
            $activity->success(
                $userId,
                'textract_folder',
                'S3',
                null,
                ['s3.put_request' => 1],
                null,
                ['output_bytes' => max(0, (int)($result['bytes'] ?? 0))],
                $correlation
            );

            $store->update($jobId, [
                'status' => 'completed',
                'processed' => max(0, (int)($result['paginas'] ?? 0)),
                'total' => max(0, (int)($result['paginas'] ?? 0)),
                'progress' => 100,
                'message' => 'Extracción terminada. JSON generado en la carpeta padre.',
                'output_name' => (string)($result['archivo'] ?? ''),
                'output_route' => (string)($result['ruta'] ?? ''),
                'estimated_cost' => $result['estimated_cost'] ?? null,
                'currency' => (string)($result['currency'] ?? 'USD'),
                'completed_at' => gmdate('c'),
                'current_file' => '',
            ]);
            return 0;
        } catch (\Throwable $error) {
            try {
                $store->update($jobId, [
                    'status' => 'failed',
                    'message' => 'Falló la extracción: ' . $error->getMessage(),
                    'error' => $error->getMessage(),
                    'completed_at' => gmdate('c'),
                ]);
            } catch (\Throwable) {
            }
            error_log('[ArcadeCloud folder-textract-worker] ' . $error->getMessage());
            return 1;
        }
    }
}
