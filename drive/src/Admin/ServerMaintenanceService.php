<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Application\BackgroundWorkerLauncher;
use ArcadeCloud\Drive\Core\DriveApplication;
use RuntimeException;

final class ServerMaintenanceService
{
    public function __construct(
        private DriveApplication $app,
        private ServerMaintenanceJobStore $store,
        private PrivilegedServerHelper $helper
    ) {
    }

    public function queueMemoryClear(int $userId, string $accessPassword): array
    {
        return $this->queueMaintenance($userId, 'memory-clear', $accessPassword);
    }

    public function queueDiskCleanup(int $userId, string $accessPassword): array
    {
        return $this->queueMaintenance($userId, 'disk-clean', $accessPassword);
    }

    private function queueMaintenance(int $userId, string $action, string $accessPassword): array
    {
        $hash = $this->app->personalAwsConfig()->passwordHash();
        if ($accessPassword === '' || $hash === '' || !password_verify($accessPassword, $hash)) {
            throw new RuntimeException('Contraseña privada incorrecta.');
        }

        if ($action === 'disk-clean') {
            if (!$this->helper->supportsDiskCleanup()) {
                throw new RuntimeException('Actualiza el helper administrativo antes de limpiar el disco.');
            }
        } elseif (!$this->helper->supportsServerConsole()) {
            throw new RuntimeException('Actualiza el helper administrativo antes de usar la escobilla.');
        }

        $job = $this->store->create($userId, $action);
        if (($job['already_queued'] ?? false) !== true) {
            (new BackgroundWorkerLauncher(dirname(__DIR__, 2)))->launchMaintenance((string)$job['id']);
        }

        return [
            'job' => $job,
            'blocking' => (new ServerTaskActivityProbe($this->app))->summary(),
        ];
    }

    public function run(string $jobId): array
    {
        $job = $this->store->claimQueued($jobId);
        if ($job === null) return $this->store->get($jobId);

        $probe = new ServerTaskActivityProbe($this->app);
        $deadline = time() + 21600;

        while (time() < $deadline) {
            $current = $this->store->get($jobId);
            if ((string)($current['status'] ?? '') === 'cancelled') return $current;

            $blocking = $probe->summary();
            if ((int)($blocking['active'] ?? 0) === 0) break;

            $names = array_keys(array_filter((array)($blocking['sources'] ?? [])));
            $this->store->update($jobId, [
                'status' => 'waiting',
                'message' => 'Esperando tareas activas: ' . implode(', ', $names) . '.',
                'blocking' => $blocking,
            ]);
            sleep(10);
        }

        if (time() >= $deadline) {
            return $this->store->update($jobId, [
                'status' => 'failed',
                'message' => 'La limpieza no pudo ejecutarse: la cola permaneció ocupada demasiado tiempo.',
                'error' => 'Tiempo máximo de espera agotado.',
                'completed_at' => gmdate('c'),
            ]);
        }

        $action = (string)($job['action'] ?? 'memory-clear');
        $isDisk = $action === 'disk-clean';

        try {
            $this->store->update($jobId, [
                'status' => 'running',
                'message' => $isDisk
                    ? 'Limpiando temporales y logs archivados seguros.'
                    : 'Liberando cachés de memoria del servidor.',
            ]);
            $output = $this->helper->runServerConsole($isDisk ? 'disk-clean' : 'memory-clear');
            return $this->store->update($jobId, [
                'status' => 'completed',
                'message' => $isDisk
                    ? 'Limpieza segura de disco completada.'
                    : 'Memoria liberada de forma segura.',
                'output' => $output,
                'completed_at' => gmdate('c'),
            ]);
        } catch (\Throwable $error) {
            return $this->store->update($jobId, [
                'status' => 'failed',
                'message' => $isDisk
                    ? 'No se pudo completar la limpieza segura de disco.'
                    : 'No se pudo liberar la memoria.',
                'error' => $error->getMessage(),
                'completed_at' => gmdate('c'),
            ]);
        }
    }
}
