<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Application\BackgroundWorkerLauncher;
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

            try {
                $this->workerLauncher()->launchSync($userId, $jobId, $scopePrefix);
            } catch (\Throwable $workerError) {
                $store->update($userId, $jobId, [
                    'state' => 'error',
                    'message' => 'No se pudo iniciar worker.',
                ]);

                throw new RuntimeException('No se pudo iniciar sincronización.', 0, $workerError);
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

    private function workerLauncher(): BackgroundWorkerLauncher
    {
        return new BackgroundWorkerLauncher(dirname(__DIR__, 3));
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
