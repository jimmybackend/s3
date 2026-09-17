<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use RuntimeException;

/**
 * Lanza workers CLI completamente desacoplados de la petición HTTP.
 *
 * El proceso hijo hereda el entorno ya cargado por php-fpm-drive y continúa
 * aunque el navegador cambie de página o cierre la sesión visual.
 */
final class BackgroundWorkerLauncher
{
    public function __construct(private string $driveRoot)
    {
        $this->driveRoot = rtrim($this->driveRoot, '/');
        if ($this->driveRoot === '') {
            throw new RuntimeException('Raíz del Drive inválida para workers.');
        }
    }

    public function launchSync(int $userId, string $jobId, string $scopePrefix = ''): void
    {
        if ($userId <= 0 || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new RuntimeException('Tarea de sincronización inválida.');
        }

        $this->launch('sync_worker.php', [
            (string)$userId,
            $jobId,
            $scopePrefix,
        ]);
    }

    public function launchMove(string $jobId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new RuntimeException('Tarea de movimiento inválida.');
        }

        $this->launch('move_job_worker.php', [$jobId]);
    }

    public function launchReconcile(string $kind): void
    {
        $script = match (strtolower(trim($kind))) {
            'polly' => 'polly_reconcile.php',
            'transcribe' => 'transcribe_reconcile.php',
            default => throw new RuntimeException('Tipo de reconciliación inválido.'),
        };

        $this->launch($script, ['--limit=250']);
    }

    private function launch(string $scriptName, array $args): void
    {
        $script = $this->driveRoot . '/bin/' . basename($scriptName);
        if (!is_file($script)) {
            throw new RuntimeException('Worker no encontrado: ' . basename($scriptName));
        }

        $php = '/usr/bin/php';
        if (!is_executable($php)) {
            $php = PHP_BINARY;
        }
        if ($php === '' || !is_executable($php)) {
            throw new RuntimeException('PHP CLI no disponible.');
        }

        $setsid = is_executable('/usr/bin/setsid')
            ? '/usr/bin/setsid'
            : (is_executable('/bin/setsid') ? '/bin/setsid' : '');
        if ($setsid === '') {
            throw new RuntimeException('setsid no disponible para lanzar trabajo independiente.');
        }

        $command = escapeshellarg($setsid)
            . ' -f '
            . escapeshellarg($php)
            . ' '
            . escapeshellarg($script);

        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg((string)$arg);
        }

        $command .= ' >/dev/null 2>&1 </dev/null';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException('No se pudo lanzar el worker de segundo plano.');
        }
    }
}
