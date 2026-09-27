<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Console;

use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Media\MediaWorkerNodeService;
use RuntimeException;

final class MediaProcessingWorkerCommand
{
    private const MAX_SOURCE_BYTES = 8 * 1024 * 1024 * 1024;
    private const MIN_VCPU = 2;
    private const MIN_VISIBLE_MEMORY_BYTES = 7 * 1024 * 1024 * 1024;
    public function __construct(
        private DriveApplication $app,
        private MediaProcessingJobRepository $jobs,
        private GeneratedFileRepository $generated,
        private MediaWorkerNodeService $node
    ) {
    }

    public function run(bool $loop = false, int $sleepSeconds = 5): int
    {
        if (!$this->mediaWorkerAuthorized()) {
            throw new RuntimeException(
                'Este proceso sólo puede ejecutarse en un nodo autorizado con ARCADECLOUD_MEDIA_WORKER=1/true.'
            );
        }

        do {
            // Un nodo incapaz no debe reclamar un job para fallarlo después.
            // El preflight se ejecuta antes de tocar la cola y se repite dentro
            // de process() como defensa ante cambios de capacidad.
            try {
                $this->assertTools();
                $this->assertWorkerCapacity();
            } catch (\Throwable $e) {
                error_log('[ArcadeCloud media-worker preflight] ' . $e->getMessage());
                if (!$loop) {
                    throw $e;
                }
                sleep(max(30, min(300, $sleepSeconds)));
                continue;
            }

            $job = $this->jobs->claimNext($this->workerId());
            if ($job === null) {
                $this->node->handleIdle($this->jobs);
                if (!$loop) return 0;
                sleep(max(1, min(60, $sleepSeconds)));
                continue;
            }

            $this->node->markBusy();

            try {
                $outputs = $this->process($job);
                $this->jobs->complete((string)$job['job_id'], $outputs);
            } catch (\Throwable $e) {
                $jobId = (string)$job['job_id'];
                if (str_starts_with($e->getMessage(), '[CANCELLED]')) {
                    $this->jobs->markCancelled($jobId, 'Tarea cancelada por el usuario.');
                } else {
                    $this->jobs->fail($jobId, $e->getMessage());
                }
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
        // El formato pertenece al nombre lógico del archivo, no a la clave física
        // de S3. La clave puede ser opaca y no conservar una extensión multimedia.
        $sourceExt = $this->logicalSourceExtension($job, (string)$job['operation']);
        $sourcePath = $workDir . '/source.' . ($sourceExt !== '' ? $sourceExt : 'bin');

        try {
            $this->assertTools();
            $this->assertWorkerCapacity();

            $head = $this->app->s3()->headObject([
                'Bucket' => $this->app->bucket(),
                'Key' => $sourceKey,
            ]);
            $actualBytes = max(0, (int)($head['ContentLength'] ?? 0));
            if ($actualBytes > self::MAX_SOURCE_BYTES) {
                throw new RuntimeException('[SOURCE_TOO_LARGE] El archivo supera el máximo de 8 GB para procesamiento multimedia.');
            }
            $this->assertFreeSpace(
                $tmpRoot,
                $actualBytes > 0 ? $actualBytes : (int)$job['source_bytes']
            );

            $this->app->s3()->getObject([
                'Bucket' => $this->app->bucket(),
                'Key' => $sourceKey,
                'SaveAs' => $sourcePath,
            ]);
            if (!is_file($sourcePath) || filesize($sourcePath) === 0) {
                throw new RuntimeException('El nodo no pudo descargar el archivo fuente desde S3.');
            }

            $this->jobs->progress($jobId, 10);
            $this->assertNotCancelled($jobId);

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
        $jobId = (string)$job['job_id'];
        $this->assertNotCancelled($jobId);
        $dest = $this->destination($job, 1, 'mp3', false);
        $this->assertDestinationAvailable($dest['key']);

        $out = $workDir . '/audio.mp3';
        if ($this->ffmpegHasEncoder('libmp3lame')) {
            $this->runProcess([
                'ffmpeg','-hide_banner','-loglevel','error','-y',
                '-i',$sourcePath,'-vn','-map','0:a:0',
                '-c:a','libmp3lame','-b:a','128k',$out,
            ]);
        } else {
            $lame = $this->findExecutable('lame');
            if ($lame === null) {
                throw new RuntimeException(
                    '[DEPENDENCY_MISSING] El nodo necesita libmp3lame en FFmpeg o el ejecutable lame para crear MP3.'
                );
            }

            $wav = $workDir . '/audio-for-mp3.wav';
            $this->runProcess([
                'ffmpeg','-hide_banner','-loglevel','error','-y',
                '-i',$sourcePath,'-vn','-map','0:a:0',
                '-ac','2','-ar','44100','-c:a','pcm_s16le',$wav,
            ]);
            $this->runProcess([$lame, '-b', '128', '--quiet', $wav, $out]);
            @unlink($wav);
        }
        $this->assertNotCancelled($jobId);
        $this->jobs->progress($jobId, 75);

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
        $sourceExt = $this->logicalSourceExtension($job, (string)$job['operation']);

        $destinations = [];
        for ($i = 1; $i <= $parts; $i++) {
            $destinations[$i] = $this->destination($job, $i, $sourceExt, true);
            $this->assertDestinationAvailable($destinations[$i]['key']);
        }

        $localFiles = [];
        for ($i = 1; $i <= $parts; $i++) {
            $this->assertNotCancelled((string)$job['job_id']);
            $coreStart = ($i - 1) * $segment;
            $coreEnd = $i === $parts ? $duration : $i * $segment;
            $start = max(0.0, $coreStart - ($i > 1 ? $before : 0));
            $end = min($duration, $coreEnd + ($i < $parts ? $after : 0));
            $length = max(0.1, $end - $start);
            $out = $workDir . '/part-' . $i . '.' . $sourceExt;

            $this->createSplitSegment(
                (string)$job['operation'],
                $sourcePath,
                $out,
                $sourceExt,
                $start,
                $length,
                $i
            );
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

        // Antes de publicar comprobamos una última vez la cancelación. A partir
        // de aquí publicamos el conjunto completo para no dejar sólo algunas partes.
        $this->assertNotCancelled((string)$job['job_id']);
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

    /**
     * Divide un segmento con una estrategia de dos niveles:
     * 1) stream-copy sólo de video/audio para conservar calidad y velocidad;
     * 2) si el contenedor/timestamps no permiten copiar, recodifica el segmento.
     *
     * Nunca se mapean streams de datos, adjuntos o subtítulos de forma genérica:
     * son una causa frecuente de fallos al remuxear MP4 reales.
     */
    private function createSplitSegment(
        string $operation,
        string $sourcePath,
        string $out,
        string $sourceExt,
        float $start,
        float $length,
        int $part
    ): void {
        $isVideo = $operation === 'split_video';

        $command = [
            'ffmpeg','-hide_banner','-loglevel','warning','-nostdin','-y',
            '-fflags','+genpts',
            '-ss',sprintf('%.3f',$start),
            '-i',$sourcePath,
            '-t',sprintf('%.3f',$length),
        ];

        if ($isVideo) {
            $command = array_merge($command, [
                '-map','0:v:0',
                '-map','0:a:0?',
                '-sn','-dn',
                '-map_metadata','-1',
                '-map_chapters','-1',
                '-c','copy',
            ]);
        } else {
            $command = array_merge($command, [
                '-map','0:a:0',
                '-vn','-sn','-dn',
                '-map_metadata','-1',
                '-map_chapters','-1',
                '-c','copy',
            ]);
        }

        $command[] = '-avoid_negative_ts';
        $command[] = 'make_zero';
        if ($isVideo && in_array($sourceExt, ['mp4','m4v','mov'], true)) {
            $command[] = '-movflags';
            $command[] = '+faststart';
        }
        $muxer = $this->outputMuxer($sourceExt, $isVideo);
        if ($muxer !== '') {
            $command[] = '-f';
            $command[] = $muxer;
        }
        $command[] = $out;

        try {
            $this->runProcess($command);
            $this->assertMediaOutput($out, $part);
            return;
        } catch (\Throwable $copyError) {
            @unlink($out);

            // El fallback de recodificación se usa para contenedores de video
            // compatibles. Para audio u otros contenedores conservamos el error
            // preciso del intento seguro, sin producir un archivo engañoso.
            if (!$isVideo || !in_array($sourceExt, ['mp4','m4v','mov','mkv'], true)) {
                throw new RuntimeException(
                    'No se pudo dividir la parte ' . $part . ' mediante copia segura: '
                    . $copyError->getMessage(),
                    0,
                    $copyError
                );
            }

            error_log(
                '[ArcadeCloud media-worker] stream-copy falló en parte ' . $part
                . '; se reintentará recodificando: ' . $copyError->getMessage()
            );

            try {
                $this->transcodeVideoSegment($sourcePath, $out, $sourceExt, $start, $length, $part);
                $this->assertMediaOutput($out, $part);
            } catch (\Throwable $transcodeError) {
                @unlink($out);
                throw new RuntimeException(
                    'Parte ' . $part . ': fallaron copia directa y recodificación. '
                    . 'Recodificación: ' . $transcodeError->getMessage()
                    . ' | Copia directa: ' . $copyError->getMessage(),
                    0,
                    $transcodeError
                );
            }
        }
    }

    private function transcodeVideoSegment(
        string $sourcePath,
        string $out,
        string $sourceExt,
        float $start,
        float $length,
        int $part
    ): void {
        $encoders = [];
        if ($this->ffmpegHasEncoder('libx264')) $encoders[] = 'libx264';
        if ($this->ffmpegHasEncoder('mpeg4')) $encoders[] = 'mpeg4';

        if ($encoders === []) {
            throw new RuntimeException(
                '[DEPENDENCY_MISSING] La copia directa falló y FFmpeg no ofrece libx264 ni mpeg4 '
                . 'para recodificar la parte ' . $part . '.'
            );
        }

        $errors = [];
        foreach ($encoders as $videoEncoder) {
            @unlink($out);
            $command = [
                'ffmpeg','-hide_banner','-loglevel','warning','-nostdin','-y',
                '-fflags','+genpts',
                '-i',$sourcePath,
                '-ss',sprintf('%.3f',$start),
                '-t',sprintf('%.3f',$length),
                '-map','0:v:0',
                '-map','0:a:0?',
                '-sn','-dn',
                '-map_metadata','-1',
                '-map_chapters','-1',
                '-c:v',$videoEncoder,
            ];

            if ($videoEncoder === 'libx264') {
                $command = array_merge($command, [
                    '-preset','veryfast',
                    '-crf','18',
                    '-vf','scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p',
                ]);
            } else {
                $command = array_merge($command, [
                    '-q:v','2',
                    '-vf','scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p',
                ]);
            }

            if ($this->ffmpegHasEncoder('aac')) {
                $command = array_merge($command, ['-c:a','aac','-b:a','160k']);
            } else {
                $command = array_merge($command, ['-c:a','copy']);
            }

            $command = array_merge($command, [
                '-max_muxing_queue_size','4096',
                '-avoid_negative_ts','make_zero',
            ]);
            if (in_array($sourceExt, ['mp4','m4v','mov'], true)) {
                $command = array_merge($command, ['-movflags','+faststart']);
            }
            $muxer = $this->outputMuxer($sourceExt, true);
            if ($muxer !== '') {
                $command = array_merge($command, ['-f',$muxer]);
            }
            $command[] = $out;

            try {
                $this->runProcess($command);
                return;
            } catch (\Throwable $e) {
                $errors[] = $videoEncoder . ': ' . $e->getMessage();
                error_log(
                    '[ArcadeCloud media-worker] recodificación ' . $videoEncoder
                    . ' falló en parte ' . $part . ': ' . $e->getMessage()
                );
            }
        }

        throw new RuntimeException(
            'No se pudo recodificar la parte ' . $part . '. ' . implode(' | ', $errors)
        );
    }

    private function assertMediaOutput(string $path, int $part): void
    {
        if (!is_file($path) || (int)filesize($path) <= 0) {
            throw new RuntimeException('FFmpeg no produjo correctamente la parte ' . $part . '.');
        }

        $duration = $this->probeDuration($path);
        if (!is_finite($duration) || $duration <= 0.05) {
            throw new RuntimeException(
                'La parte ' . $part . ' fue creada pero FFprobe no la considera reproducible.'
            );
        }
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
        $binary = (string)($command[0] ?? '');
        if ($binary === '') {
            throw new RuntimeException('Comando multimedia vacío.');
        }
        if (!str_contains($binary, DIRECTORY_SEPARATOR)) {
            $resolved = $this->findExecutable($binary);
            if ($resolved === null) {
                throw new RuntimeException('[DEPENDENCY_MISSING] No se encontró el ejecutable multimedia: ' . $binary . '.');
            }
            $command[0] = $resolved;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar FFmpeg/FFprobe.');
        }

        $stdout = '';
        $stderr = '';
        $lastStatus = null;
        $limit = 256 * 1024;

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        try {
            while (true) {
                $read = [];
                if (!feof($pipes[1])) $read[] = $pipes[1];
                if (!feof($pipes[2])) $read[] = $pipes[2];

                if ($read !== []) {
                    $write = null;
                    $except = null;
                    $selected = @stream_select($read, $write, $except, 0, 200000);
                    if ($selected !== false && $selected > 0) {
                        foreach ($read as $stream) {
                            $chunk = (string)fread($stream, 8192);
                            if ($chunk === '') continue;
                            if ($stream === $pipes[1] && strlen($stdout) < $limit) {
                                $stdout .= substr($chunk, 0, $limit - strlen($stdout));
                            } elseif ($stream === $pipes[2] && strlen($stderr) < $limit) {
                                $stderr .= substr($chunk, 0, $limit - strlen($stderr));
                            }
                        }
                    }
                } else {
                    usleep(20000);
                }

                $status = proc_get_status($process);
                $lastStatus = $status;
                if (!$status['running']) {
                    break;
                }
            }

            $tail = stream_get_contents($pipes[1]);
            if (is_string($tail) && $tail !== '' && strlen($stdout) < $limit) {
                $stdout .= substr($tail, 0, $limit - strlen($stdout));
            }
            $tail = stream_get_contents($pipes[2]);
            if (is_string($tail) && $tail !== '' && strlen($stderr) < $limit) {
                $stderr .= substr($tail, 0, $limit - strlen($stderr));
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
        }

        $code = proc_close($process);
        if ($code === -1 && is_array($lastStatus)) {
            $observed = (int)($lastStatus['exitcode'] ?? -1);
            if ($observed >= 0) {
                $code = $observed;
            }
        }

        if ($code !== 0) {
            $detail = trim($stderr);
            if ($detail === '') {
                $detail = trim($stdout);
            }
            if ($detail === '') {
                $signaled = is_array($lastStatus) && !empty($lastStatus['signaled']);
                $signal = is_array($lastStatus) ? (int)($lastStatus['termsig'] ?? 0) : 0;
                $detail = 'sin texto de diagnóstico'
                    . ($signaled ? ' · señal=' . $signal : '')
                    . ' · stdout=' . strlen($stdout)
                    . ' bytes · stderr=' . strlen($stderr) . ' bytes';
            }
            $detail = mb_substr($detail, 0, 3200);

            if (stripos($detail, 'libmp3lame') !== false && stripos($detail, 'encoder') !== false) {
                throw new RuntimeException(
                    '[DEPENDENCY_MISSING] El FFmpeg del nodo multimedia no incluye el codificador libmp3lame requerido para crear MP3.'
                );
            }

            throw new RuntimeException(
                'FFmpeg/FFprobe falló (código ' . $code . '): ' . $detail
            );
        }

        return $capture ? $stdout : '';
    }

    private function ffmpegHasEncoder(string $encoder): bool
    {
        $output = $this->runProcess(
            ['ffmpeg','-hide_banner','-encoders'],
            true
        );
        return preg_match('/(?:^|\s)' . preg_quote($encoder, '/') . '(?:\s|$)/m', $output) === 1;
    }

    private function assertTools(): void
    {
        $missing = [];
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            if ($this->findExecutable($tool) === null) {
                $missing[] = $tool;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                '[DEPENDENCY_MISSING] El nodo multimedia ' . (gethostname() ?: 'worker')
                . ' necesita instalar FFmpeg/FFprobe. Faltan: ' . implode(', ', $missing)
                . '. Instala las dependencias y reinicia arcadecloud-media-worker.service.'
            );
        }
    }

    private function assertWorkerCapacity(): void
    {
        $cpu = 0;
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo) && $cpuInfo !== '') {
            preg_match_all('/^processor\s*:/m', $cpuInfo, $matches);
            $cpu = count($matches[0] ?? []);
        }

        $memoryBytes = 0;
        $memInfo = @file_get_contents('/proc/meminfo');
        if (is_string($memInfo) && preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $memInfo, $match) === 1) {
            $memoryBytes = (int)$match[1] * 1024;
        }

        if ($cpu < self::MIN_VCPU || $memoryBytes < self::MIN_VISIBLE_MEMORY_BYTES) {
            $memoryGiB = $memoryBytes > 0 ? round($memoryBytes / (1024 ** 3), 1) : 0;
            throw new RuntimeException(
                '[CAPACITY_INSUFFICIENT] El nodo multimedia no cumple el mínimo soportado: '
                . self::MIN_VCPU . ' vCPU y una instancia de 8 GiB de RAM. '
                . 'Detectado: ' . $cpu . ' vCPU, ' . $memoryGiB . ' GiB visibles. '
                . 'Configura un media worker con capacidad suficiente; un nodo web pequeño no debe ejecutar este trabajo.'
            );
        }
    }

    private function logicalSourceExtension(array $job, string $operation): string
    {
        $logical = strtolower((string)pathinfo((string)($job['source_name'] ?? ''), PATHINFO_EXTENSION));
        $physical = strtolower((string)pathinfo((string)($job['source_key'] ?? ''), PATHINFO_EXTENSION));
        $ext = $logical !== '' ? $logical : $physical;

        if ($operation === 'split_video') {
            return in_array($ext, ['mp4','m4v','mov','mkv','webm','ogv','ogg'], true) ? $ext : 'mp4';
        }
        if ($operation === 'split_audio') {
            return in_array($ext, ['mp3','wav','ogg','opus','m4a','aac','flac','webm'], true) ? $ext : 'mp3';
        }
        return $ext !== '' ? $ext : 'bin';
    }

    private function outputMuxer(string $ext, bool $video): string
    {
        return match ($ext) {
            'mp4','m4v','mov' => 'mp4',
            'mkv' => 'matroska',
            'webm' => 'webm',
            'ogv','ogg' => 'ogg',
            'mp3' => 'mp3',
            'wav' => 'wav',
            'flac' => 'flac',
            'm4a' => 'mp4',
            'aac' => 'adts',
            default => '',
        };
    }

    private function assertNotCancelled(string $jobId): void
    {
        if ($jobId !== '' && $this->jobs->isCancellationRequested($jobId)) {
            throw new RuntimeException('[CANCELLED] Detención solicitada por el usuario.');
        }
    }

    private function mediaWorkerAuthorized(): bool
    {
        $value = strtolower(trim((string)(getenv('ARCADECLOUD_MEDIA_WORKER') ?: '')));
        return in_array($value, ['1','true','yes','on'], true);
    }

    private function findExecutable(string $binary): ?string
    {
        $paths = explode(PATH_SEPARATOR, (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'));
        foreach ($paths as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
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
