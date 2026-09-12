<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Sync\SyncJobStore;
use ArcadeCloud\Drive\Sync\SyncRepository;
use ArcadeCloud\Drive\View\SyncStatusRenderer;
use RuntimeException;

final class SyncController extends AbstractJsonController
{
    public function run(): never
    {
        try {
            $this->requirePost();

            $userId = $this->guardAuthenticated();
            $requestedPrefix = trim($this->request->postString('prefix'));
            $scopePrefix = '';

            if ($requestedPrefix !== '') {
                $root = $this->app->userStoragePath()->rootForUser($userId);
                $candidate = preg_replace(
                    '~/+~',
                    '/',
                    ltrim(str_replace('\\', '/', $requestedPrefix), '/')
                ) ?? '';

                if (preg_match('~^Data(?:\d+)?/~i', $candidate)) {
                    $candidateRoot = explode('/', $candidate, 2)[0] ?? '';
                    $expectedRoot = rtrim($root, '/');

                    if (strcasecmp($candidateRoot, $expectedRoot) !== 0) {
                        throw new RuntimeException(
                            'La carpeta solicitada no pertenece al usuario autenticado.'
                        );
                    }
                }

                $scopePrefix = $this->app
                    ->userStoragePath()
                    ->normalizeForUser($candidate, $userId);

                if ($scopePrefix === $root) {
                    $scopePrefix = '';
                }
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $jobId = bin2hex(random_bytes(16));

            $store = new SyncJobStore();
            $store->create($userId, $jobId);
            $store->update($userId, $jobId, [
                'scope' => $scopePrefix === '' ? 'user' : 'folder',
                'scope_prefix' => $scopePrefix,
            ]);

            $worker = dirname(__DIR__, 3) . '/bin/sync_worker.php';

            if (!is_file($worker)) {
                throw new RuntimeException('Worker de sincronización no encontrado.');
            }

            $php = '/usr/bin/php';
            $setsid = is_executable('/usr/bin/setsid')
                ? '/usr/bin/setsid'
                : '/bin/setsid';

            if (!is_executable($php)) {
                throw new RuntimeException('PHP CLI no disponible.');
            }

            if (!is_executable($setsid)) {
                throw new RuntimeException('setsid no disponible.');
            }

            $command =
                escapeshellarg($setsid) .
                ' -f ' .
                escapeshellarg($php) .
                ' ' .
                escapeshellarg($worker) .
                ' ' .
                (int)$userId .
                ' ' .
                escapeshellarg($jobId) .
                ' ' .
                escapeshellarg($scopePrefix) .
                ' >/dev/null 2>&1 </dev/null';

            $output = [];
            $exitCode = 0;

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                $store->update($userId, $jobId, [
                    'state' => 'error',
                    'message' => 'No se pudo iniciar worker.',
                ]);

                throw new RuntimeException('No se pudo iniciar sincronización.');
            }

            JsonResponse::send([
                'ok' => true,
                'job_id' => $jobId,
                'state' => 'queued',
                'scope' => $scopePrefix === '' ? 'user' : 'folder',
                'scope_prefix' => $scopePrefix,
                'message' => $scopePrefix === ''
                    ? 'Sincronización de usuario iniciada'
                    : 'Sincronización de carpeta iniciada',
            ], 202);

        } catch (\Throwable $error) {
            JsonResponse::send([
                'ok' => false,
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    public function status(): never
    {
        try {
            $userId = $this->guardAuthenticated();

            $jobId = $this->request->queryString('job_id');

            if ($jobId !== '') {
                $store = new SyncJobStore();
                $job = $store->read($userId, $jobId);

                if ($job === null) {
                    JsonResponse::send([
                        'ok' => false,
                        'error' => 'Job no encontrado.',
                    ], 404);
                }

                JsonResponse::send(array_merge(['ok' => true], $job));
            }

            $status = (new SyncRepository($this->app->db()))->status($userId);

            if ($this->request->queryString('view') === 'html') {
                header('Content-Type: text/html; charset=utf-8');

                echo (new SyncStatusRenderer())->render(
                    $status,
                    $this->request->queryString('loading') === '1'
                );

                exit;
            }

            JsonResponse::send([
                'ok' => true,
                'user_id' => $userId
            ] + $status);

        } catch (\Throwable $error) {
            JsonResponse::send([
                'ok' => false,
                'error' => $error->getMessage(),
            ], 500);
        }
    }
}
