<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

final class MoveJobController extends AbstractJsonController
{
    public function start(): never
    {
        $userId = 0;
        $type = '';
        $jobId = '';
        $started = microtime(true);
        $createdDestination = false;

        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $type = strtolower(trim($this->request->postString('type')));

            if ($type === 'files') {
                $refs = $this->keysFromRequest();
                if ($refs === []) {
                    $single = trim($this->request->postString('archivo'));
                    if ($single !== '') $refs = [$single];
                }

                $destination = $this->request->postString('nueva_ruta');
                if ($destination === '') $destination = $this->request->postString('ruta_destino');
                $destination = $this->requireNonEmpty($destination, 'Falta la ruta destino.');

                if ($destination === '__crear__') {
                    $session = $this->app->session();
                    $base = $this->request->postString('ruta_actual');
                    if ($base === '') {
                        $base = (string)$session->get(
                            'ruta_actual',
                            $this->app->userStoragePath()->rootForUser($userId)
                        );
                    }
                    $base = $this->app->userStoragePath()->normalizeForUser($base, $userId);
                    $name = $this->requireNonEmpty(
                        trim($this->request->postString('nueva_carpeta')),
                        'Escribe el nombre de la nueva carpeta.'
                    );
                    $created = $this->app->folderMutationService()->create($userId, $base, $name);
                    $createdDestination = true;
                    $destination = (string)($created['ruta'] ?? '');
                    if ($destination === '') {
                        throw new RuntimeException('No se pudo obtener la ruta de la carpeta creada.');
                    }
                }

                $job = $this->app->moveJobService()->queueFiles($userId, $refs, $destination);
            } elseif ($type === 'folder') {
                $origin = $this->requireNonEmpty($this->request->postString('origen'), 'Falta la carpeta origen.');
                $destination = $this->requireNonEmpty($this->request->postString('destino'), 'Falta la carpeta destino.');
                $job = $this->app->moveJobService()->queueFolder($userId, $origin, $destination);
            } else {
                throw new RuntimeException('Tipo de movimiento inválido.');
            }

            $jobId = (string)$job['id'];
            $this->releaseSession();
            $this->accept($jobId, $type);

            ignore_user_abort(true);
            @set_time_limit(0);
            $this->app->moveJobService()->run($jobId);

            $completed = $this->app->moveJobService()->statusForUser($userId, $jobId);
            $status = (string)($completed['status'] ?? '');
            $result = is_array($completed['result'] ?? null) ? $completed['result'] : [];
            $correlation = ActivityCostRecorder::correlation('move-job', $jobId);

            if ($type === 'files') {
                if ($status === 'completed') {
                    $total = max(0, (int)($result['total'] ?? 0));
                    $units = [
                        's3.copy_request' => $total,
                        's3.delete_request' => $total,
                    ];
                    if ($createdDestination) $units['s3.put_request'] = 1;
                    $this->activity()->success(
                        $userId,
                        'move',
                        'S3',
                        null,
                        $units,
                        $started,
                        ['items' => $total, 'async' => true, 'created_destination' => $createdDestination],
                        $correlation
                    );
                } elseif ($status === 'failed') {
                    $this->activity()->failure(
                        $userId,
                        'move',
                        'S3',
                        $started,
                        ['async' => true, 'created_destination' => $createdDestination],
                        $correlation
                    );
                }
            } elseif ($type === 'folder') {
                if ($status === 'completed') {
                    $this->activity()->success(
                        $userId,
                        'folder_move',
                        'S3',
                        null,
                        [
                            's3.list_request' => (int)($result['s3_list_requests'] ?? 0),
                            's3.copy_request' => (int)($result['s3_copy_requests'] ?? 0),
                            's3.delete_request' => (int)($result['s3_delete_requests'] ?? 0),
                        ],
                        $started,
                        ['items' => (int)($result['s3_copy_requests'] ?? 0), 'async' => true],
                        $correlation
                    );
                } elseif ($status === 'failed') {
                    $this->activity()->failure(
                        $userId,
                        'folder_move',
                        'S3',
                        $started,
                        ['async' => true],
                        $correlation
                    );
                }
            }
            exit;
        } catch (\Throwable $error) {
            if ($userId > 0 && in_array($type, ['files', 'folder'], true)) {
                $this->activity()->failure(
                    $userId,
                    $type === 'folder' ? 'folder_move' : 'move',
                    'S3',
                    $started,
                    ['async' => true, 'created_destination' => $createdDestination],
                    $jobId !== '' ? ActivityCostRecorder::correlation('move-job', $jobId) : null
                );
            }
            $this->fail($error, 400);
        }
    }

    public function status(): never
    {
        try {
            $userId = $this->guardAuthenticated();
            $jobId = trim($this->request->queryString('job_id'));
            if ($jobId === '') $jobId = trim($this->request->postString('job_id'));
            if ($jobId === '') throw new RuntimeException('Falta el identificador de la tarea.');

            $job = $this->app->moveJobService()->statusForUser($userId, $jobId);
            $status = (string)($job['status'] ?? '');
            $type = (string)($job['type'] ?? '');
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $result = is_array($job['result'] ?? null) ? $job['result'] : [];

            $rutaActual = null;
            if ($status === 'completed' && $type === 'folder') {
                $origin = (string)($payload['origin'] ?? '');
                $final = (string)($result['destino'] ?? '');
                if ($origin !== '' && $final !== '') {
                    $paths = $this->app->userStoragePath();
                    $root = $paths->rootForUser($userId);
                    $session = $this->app->session();
                    $current = $paths->normalizeForUser((string)$session->get('ruta_actual', $root), $userId);
                    if (str_starts_with($current, $origin)) {
                        $current = $final . substr($current, strlen($origin));
                        $session->set('ruta_actual', $current);
                    }
                    $rutaActual = $current;
                }
            }

            $destination = (string)($payload['destination'] ?? '');
            $destinationLabel = '';
            if ($destination !== '') {
                try {
                    $destinationLabel = $this->app->folderQueryService()->displayPathForUser($userId, $destination);
                } catch (\Throwable) {
                    $destinationLabel = '';
                }
            }

            JsonResponse::send([
                'ok' => true,
                'job_id' => $jobId,
                'type' => $type,
                'estado' => $status,
                'mensaje' => (string)($job['message'] ?? ''),
                'error' => $status === 'failed' ? (string)($job['error'] ?? '') : null,
                'ruta_actual' => $rutaActual,
                'destino_visible' => $destinationLabel,
                'created_at' => $job['created_at'] ?? null,
                'updated_at' => $job['updated_at'] ?? null,
            ]);
        } catch (\Throwable $error) {
            $this->fail($error, 400);
        }
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }

    private function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }

    private function accept(string $jobId, string $type): void
    {
        http_response_code(202);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Accel-Buffering: no');

        echo json_encode([
            'ok' => true,
            'estado' => 'queued',
            'job_id' => $jobId,
            'type' => $type,
            'mensaje' => 'Movimiento enviado a segundo plano. Puedes seguir usando el Drive.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        @ob_flush();
        @flush();
    }
}
