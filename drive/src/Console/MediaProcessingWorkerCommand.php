<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Console;

use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use RuntimeException;

final class MediaProcessingWorkerCommand
{
    public function __construct(
        private DriveApplication $app,
        private MediaProcessingJobRepository $jobs,
        private GeneratedFileRepository $generated
    ) {
    }

    public function run(bool $loop = false, int $sleepSeconds = 5): int
    {
        if ((string)getenv('ARCADECLOUD_MEDIA_WORKER') !== '1') {
            throw new RuntimeException(
                'Este proceso sólo puede ejecutarse en un nodo autorizado con ARCADECLOUD_MEDIA_WORKER=1.'
            );
        }

        do {
            $job = $this->jobs->claimNext($this->workerId());
            if ($job === null) {
                if (!$loop) return 0;
                sleep(max(1, min(60, $sleepSeconds)));
                continue;
            }

            try {
                $outputs = $this->process($job);
                $this->jobs->complete((string)$job['job_id'], $outputs);
            } catch (\Throwable $e) {
                $this->jobs->fail((string)$job['job_id'], $e->getMessage());
                error_log('[ArcadeCloud media-worker] ' . $e->getMessage());
            }
        } while ($loop);

        return 0;
    }

    private function process(array $job): array
    {
        $jobId = (string)$job['job_id'];
        $tmpRoot = rtrim((string)(getenv('ARCADECLOUD_MEDIA_TMP') ?: '/var/lib/arcadecloud-media/tmp'), '/');
        if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0750, true) && !is_dir($tmpRoot)) {
            throw new RuntimeException('No se pudo crear el directorio temporal multimedia.');
        }

        $workDir = $tmpRoot . '/' . $jobId;
        if (!mkdir($workDir, 0750, true) && !is_dir($workDir)) {
            throw new RuntimeException('No se pudo crear el espacio de trabajo multimedia.');
        }

        $sourceKey = (string)$job['source_key'];
        $sourceExt = strtolower((string)pathinfo($sourceKey, PATHINFO_EXTENSION));
        $sourcePath = $workDir . '/source.' . ($sourceExt !== '' ? $sourceExt : 'bin');

        try {
            $this->assertTools();
            $this->assertFreeSpace($tmpRoot, (int)$job['source_bytes']);

            $this->app->s3()->getObject([
                'Bucket' => $this->app->bucket(),
                'Key' => $sourceKey,
                'SaveAs' => $sourcePath,
            ]);
            if (!is_file($sourcePath) || filesize($sourcePath) === 0) {
                throw new RuntimeException('El nodo no pudo descargar el archivo fuente desde S3.');
            }

            $this->jobs->progress($jobId, 10);

            $outputs = match ((string)$job['operation']) {
                'extract_mp3' => $this->extractMp3($job, $sourcePath, $workDir),
                'split_video', 'split_audio' => $this->splitMedia($job, $sourcePath, $workDir),
                default => throw new RuntimeException('Operación multimedia desconocida.'),
            };

            return $outputs;
        } finally {
            $this->removeTree($workDir);
        }
    }

    private function extractMp3(array $job, string $sourcePath, string $workDir): array
    {
        $dest = $this->destination($job, 1, 'mp3', false);
        $this->assertDestinationAvailable($dest['key']);

        $out = $workDir . '/audio.mp3';
        $this->runProcess([
            'ffmpeg','-hide_banner','-loglevel','error','-y',
            '-i',$sourcePath,'-vn','-map','0:a:0',
            '-c:a','libmp3lame','-b:a','128k',$out,
        ]);
        $this->jobs->progress((string)$job['job_id'], 75);

        return [$this->publish($job, $dest, $out, 'audio/mpeg', [
            'media_operation' => 'extract_mp3',
        ])];
    }

    private function splitMedia(array $job, string $sourcePath, string $workDir): array
    {
        $parts = max(2, (int)$job['parts']);
        $before = max(0, (int)$job['overlap_before']);
        $after = max(0, (int)$job['overlap_after']);
        $duration = $this->probeDuration($sourcePath);
        if ($duration <= 0.0) {
            throw new RuntimeException('FFprobe no pudo determinar la duración del archivo.');
        }

        $segment = $duration / $parts;
        $sourceExt = strtolower((string)pathinfo((string)$job['source_key'], PATHINFO_EXTENSION));
        if ($sourceExt === '') $sourceExt = (string)$job['operation'] === 'split_video' ? 'mp4' : 'mp3';

        $destinations = [];
        for ($i = 1; $i <= $parts; $i++) {
            $destinations[$i] = $this->destination($job, $i, $sourceExt, true);
            $this->assertDestinationAvailable($destinations[$i]['key']);
        }

        $localFiles = [];
        for ($i = 1; $i <= $parts; $i++) {
            $coreStart = ($i - 1) * $segment;
            $coreEnd = $i === $parts ? $duration : $i * $segment;
            $start = max(0.0, $coreStart - ($i > 1 ? $before : 0));
            $end = min($duration, $coreEnd + ($i < $parts ? $after : 0));
            $length = max(0.1, $end - $start);
            $out = $workDir . '/part-' . $i . '.' . $sourceExt;

            $this->runProcess([
                'ffmpeg','-hide_banner','-loglevel','error','-y',
                '-ss',sprintf('%.3f',$start),
                '-i',$sourcePath,
                '-t',sprintf('%.3f',$length),
                '-map','0',
                '-c','copy',
                '-avoid_negative_ts','make_zero',
                $out,
            ]);

            if (!is_file($out) || filesize($out) === 0) {
                throw new RuntimeException('FFmpeg no produjo correctamente la parte ' . $i . '.');
            }
            $localFiles[$i] = [
                'path' => $out,
                'start_seconds' => round($start, 3),
                'end_seconds' => round($end, 3),
            ];
            $this->jobs->progress(
                (string)$job['job_id'],
                10 + (int)floor(($i / $parts) * 60)
            );
        }

        $outputs = [];
        foreach ($localFiles as $i => $local) {
            $mime = (string)$job['operation'] === 'split_video'
                ? $this->videoMime($sourceExt)
                : $this->audioMime($sourceExt);
            $outputs[] = $this->publish(
                $job,
                $destinations[$i],
                $local['path'],
                $mime,
                [
                    'media_operation' => (string)$job['operation'],
                    'part' => (string)$i,
                    'parts_total' => (string)$parts,
                    'overlap_before_seconds' => (string)$before,
                    'overlap_after_seconds' => (string)$after,
                    'segment_start_seconds' => (string)$local['start_seconds'],
                    'segment_end_seconds' => (string)$local['end_seconds'],
                ]
            );
            $this->jobs->progress(
                (string)$job['job_id'],
                70 + (int)floor(($i / $parts) * 29)
            );
        }

        return $outputs;
    }

    private function publish(
        array $job,
        array $dest,
        string $localPath,
        string $contentType,
        array $extraMeta
    ): array {
        $size = (int)filesize($localPath);
        $metadata = array_merge([
            'origin' => (string)$job['source_key'],
            'service' => 'arcadecloud-media-worker',
            'job-id' => (string)$job['job_id'],
        ], $extraMeta);

        $this->app->s3()->putObject([
            'Bucket' => $this->app->bucket(),
            'Key' => $dest['key'],
            'SourceFile' => $localPath,
            'ACL' => 'private',
            'ContentType' => $contentType,
            'Metadata' => $metadata,
        ]);

        $dbMeta = [
            'tipo' => $contentType,
            'servicio' => 'ArcadeCloud Media Worker',
            'job_id' => (string)$job['job_id'],
            'operacion' => (string)$job['operation'],
            'origen_nombre' => (string)$job['source_name'],
            'origen_encriptado' => (string)$job['source_key'],
            'destino_nombre' => $dest['name'],
            'destino_encriptado' => $dest['key'],
            'ruta' => $dest['route'],
            'tamano_bytes' => $size,
            'original_conservado' => true,
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ];

        $status = $this->generated->upsert(
            (int)$job['user_id'],
            $dest['name'],
            $dest['key'],
            $size,
            $dbMeta,
            $dest['route']
        );

        return [
            'name' => $dest['name'],
            'key' => $dest['key'],
            'route' => $dest['route'],
            'bytes' => $size,
            'db_status' => $status,
        ];
    }

    private function destination(array $job, int $part, string $ext, bool $withPart): array
    {
        $sourceKey = (string)$job['source_key'];
        $route = dirname($sourceKey);
        $route = $route === '.' ? '' : rtrim($route, '/') . '/';
        $physicalBase = pathinfo(basename($sourceKey), PATHINFO_FILENAME);
        $visibleBase = pathinfo((string)$job['source_name'], PATHINFO_FILENAME);
        $suffix = $withPart ? '-parte' . $part : '';

        return [
            'route' => $route,
            'key' => $route . $physicalBase . $suffix . '.' . $ext,
            'name' => $visibleBase . $suffix . '.' . $ext,
        ];
    }

    private function assertDestinationAvailable(string $key): void
    {
        try {
            $this->app->s3()->headObject([
                'Bucket' => $this->app->bucket(),
                'Key' => $key,
            ]);
            throw new RuntimeException(
                'Ya existe un archivo de salida con el mismo nombre. No se sobrescribió nada: ' . basename($key)
            );
        } catch (\Aws\Exception\AwsException $e) {
            $code = (string)$e->getAwsErrorCode();
            if ($e->getStatusCode() === 404 || in_array($code, ['NotFound','NoSuchKey'], true)) {
                return;
            }
            throw $e;
        }
    }

    private function probeDuration(string $path): float
    {
        $result = $this->runProcess([
            'ffprobe','-v','error',
            '-show_entries','format=duration',
            '-of','default=noprint_wrappers=1:nokey=1',
            $path,
        ], true);
        return (float)trim($result);
    }

    private function runProcess(array $command, bool $capture = false): string
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar FFmpeg/FFprobe.');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('FFmpeg/FFprobe falló: ' . trim($stderr));
        }
        return $capture ? $stdout : '';
    }

    private function assertTools(): void
    {
        foreach (['/usr/bin/ffmpeg','/usr/bin/ffprobe'] as $tool) {
            if (!is_executable($tool)) {
                throw new RuntimeException('Falta ' . basename($tool) . ' en el nodo multimedia.');
            }
        }
    }

    private function assertFreeSpace(string $path, int $sourceBytes): void
    {
        $free = @disk_free_space($path);
        if ($free === false || $sourceBytes <= 0) return;
        $required = (int)ceil($sourceBytes * 2.25);
        if ($free < $required) {
            throw new RuntimeException('El nodo no tiene espacio temporal suficiente para procesar este archivo.');
        }
    }

    private function workerId(): string
    {
        $host = gethostname() ?: 'worker';
        return substr($host . '-' . getmypid(), 0, 190);
    }

    private function videoMime(string $ext): string
    {
        return match ($ext) {
            'mp4','m4v','mov' => 'video/mp4',
            'webm','mkv' => 'video/webm',
            'ogv','ogg' => 'video/ogg',
            default => 'application/octet-stream',
        };
    }

    private function audioMime(string $ext): string
    {
        return match ($ext) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg','opus' => 'audio/ogg',
            'm4a','aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'webm' => 'audio/webm',
            default => 'application/octet-stream',
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) $this->removeTree($path);
            else @unlink($path);
        }
        @rmdir($dir);
    }
}
