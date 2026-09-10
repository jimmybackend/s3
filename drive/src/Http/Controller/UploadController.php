<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class UploadController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function handle(): never
    {
        $session = $this->app->session();
        $session->start();

        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'Sesión inválida.'], 401);
        }

        $userId = $session->userId();
        $started = microtime(true);
        $action = $this->request->queryString('action', $this->request->postString('action'));
        $mode = $this->request->queryString('mode', $this->request->postString('mode'));

        if ($action === '' || $mode === '') {
            JsonResponse::send(['ok' => false, 'error' => 'Faltan parámetros action/mode'], 400);
        }

        try {
            $req = array_merge($this->request->allQuery(), $this->request->allPost());
            $req['_files'] = $this->request->files();
            $req['_user_id'] = $userId;
            $req['_usuario'] = $session->userName();
            $req['_remote_addr'] = $this->request->serverString('REMOTE_ADDR', '0.0.0.0');
            $req['_user_agent'] = $this->request->serverString('HTTP_USER_AGENT', 'desconocido');
            $req['_referer'] = $this->request->serverString('HTTP_REFERER', 'ninguno');

            if ($action === 'init') {
                $requestedRoute = trim((string)($req['ruta_objetivo'] ?? ''));
                if ($requestedRoute === '') {
                    JsonResponse::send([
                        'ok' => false,
                        'error' => 'Falta ruta_objetivo. La subida debe fijar su destino al iniciar.',
                    ], 422);
                }
                $req['ruta_objetivo'] = $this->app->uploadDestinationService()->resolve($userId, $requestedRoute);
            }

            $uploader = $this->app->uploadFactory()->make($mode);
            $keepSessionOpen = $mode === 'local_put'
                && in_array($action, ['init', 'part', 'complete'], true);

            if (!$keepSessionOpen) $session->closeWrite();

            $result = match ($action) {
                'init' => $uploader->init($req),
                'part' => $uploader->part($req),
                'complete' => $uploader->complete($req),
                default => throw new \RuntimeException('Acción inválida'),
            };

            if ($keepSessionOpen) $session->closeWrite();

            $this->recordObservedOperation($userId, $mode, $action, $req, $result, $started);
            JsonResponse::send(['ok' => true] + $result);
        } catch (Throwable $e) {
            $session->closeWrite();
            if ($this->isCostBearingPhase($mode, $action)) {
                $this->activity()->failure(
                    $userId,
                    $this->logicalAction($mode),
                    'S3',
                    $started,
                    ['mode' => $mode, 'phase' => $action]
                );
            }
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function recordObservedOperation(
        int $userId,
        string $mode,
        string $phase,
        array $request,
        array $result,
        float $started
    ): void {
        if ($mode === 'local_put' && $phase === 'complete') {
            if (!empty($result['idempotent'])) return;
            $size = max(0, (int)($result['tamano'] ?? $request['tamano'] ?? 0));
            $this->activity()->success(
                $userId,
                'upload',
                'S3',
                (int)($result['file_id'] ?? 0),
                [
                    's3.put_request' => 1,
                    's3.head_request' => 1,
                    's3.storage_bytes_delta' => $size,
                ],
                $started,
                ['mode' => $mode, 'size_bytes' => $size],
                ActivityCostRecorder::correlation('upload', (string)($request['upload_token'] ?? ''))
            );
            return;
        }

        if ($mode === 'chunked') {
            $correlationSeed = (string)($request['stateId'] ?? $request['uploadId'] ?? $result['stateId'] ?? $result['uploadId'] ?? '');
            $correlation = ActivityCostRecorder::correlation('multipart', $correlationSeed);

            if ($phase === 'init') {
                $this->activity()->success(
                    $userId,
                    'multipart_upload',
                    'S3',
                    null,
                    ['s3.put_request' => 1],
                    $started,
                    ['mode' => $mode, 'phase' => 'init'],
                    $correlation
                );
                return;
            }

            if ($phase === 'part' && (string)($request['step'] ?? 'sign') === 'resume') {
                $this->activity()->success(
                    $userId,
                    'multipart_resume',
                    'S3',
                    null,
                    ['s3.list_request' => 1],
                    $started,
                    ['mode' => $mode],
                    $this->newCorrelation('multipart-resume')
                );
                return;
            }

            if ($phase === 'complete') {
                $etags = json_decode((string)($request['etags'] ?? ''), true);
                $parts = is_array($etags) ? count($etags) : 0;
                $size = max(0, (int)($request['filesize'] ?? $request['tamano'] ?? 0));
                $units = ['s3.storage_bytes_delta' => $size];
                if ($parts > 0) {
                    $units['s3.put_request'] = $parts + 2;
                } else {
                    $units['s3.multipart_requests_unknown'] = 1;
                }

                $this->activity()->success(
                    $userId,
                    'multipart_upload',
                    'S3',
                    (int)($result['file_id'] ?? 0),
                    $units,
                    $started,
                    ['mode' => $mode, 'parts' => $parts, 'size_bytes' => $size],
                    $correlation
                );
                return;
            }
            return;
        }

        if ($mode === 'remote_url' && $phase === 'init') {
            $size = max(0, (int)($result['bytes'] ?? 0));
            $partSize = 8 * 1024 * 1024;
            $putRequests = $size < $partSize ? 1 : (int)ceil($size / $partSize) + 2;
            $this->activity()->success(
                $userId,
                'upload',
                'S3',
                (int)($result['file_id'] ?? 0),
                [
                    's3.put_request' => max(1, $putRequests),
                    's3.storage_bytes_delta' => $size,
                ],
                $started,
                ['mode' => $mode, 'size_bytes' => $size]
            );
            return;
        }

        if ($mode === 'dropbox' && $phase === 'init') {
            $rows = is_array($result['resultados'] ?? null) ? $result['resultados'] : [];
            $files = is_array($request['_files']['file'] ?? null) ? $request['_files']['file'] : [];
            $sizes = $files['size'] ?? [];
            if (!is_array($sizes)) $sizes = [$sizes];

            $successCount = 0;
            $successBytes = 0;
            foreach ($rows as $index => $row) {
                if (!is_array($row) || ($row['estado'] ?? '') !== 'ok') continue;
                $successCount++;
                $successBytes += max(0, (int)($sizes[$index] ?? 0));
            }

            if ($successCount > 0) {
                $this->activity()->success(
                    $userId,
                    'upload',
                    'S3',
                    null,
                    [
                        's3.put_request' => $successCount,
                        's3.storage_bytes_delta' => $successBytes,
                    ],
                    $started,
                    ['mode' => $mode, 'items' => $successCount, 'size_bytes' => $successBytes]
                );
            }
        }
    }

    private function isCostBearingPhase(string $mode, string $phase): bool
    {
        return ($mode === 'local_put' && $phase === 'complete')
            || ($mode === 'chunked' && in_array($phase, ['init', 'complete'], true))
            || (in_array($mode, ['remote_url', 'dropbox'], true) && $phase === 'init');
    }

    private function logicalAction(string $mode): string
    {
        return $mode === 'chunked' ? 'multipart_upload' : 'upload';
    }

    private function newCorrelation(string $kind): ?string
    {
        try {
            return ActivityCostRecorder::correlation($kind, bin2hex(random_bytes(16)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }
}
