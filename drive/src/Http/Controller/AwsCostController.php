<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Http\JsonResponse;
use Aws\Exception\AwsException;
use Throwable;

final class AwsCostController extends AbstractJsonController
{
    public function summary(): never
    {
        if ($this->request->method() !== 'GET') {
            JsonResponse::error('Método no permitido.', 405);
        }

        $userId = 0;
        $started = microtime(true);
        try {
            $userId = $this->guardAuthenticated();
            if ($this->app->personalToolAccessService()->state() !== 'owner') {
                JsonResponse::error('Costos reales AWS disponibles sólo para el propietario autorizado.', 403);
            }
            $this->app->session()->closeWrite();

            $summary = $this->app->awsCostService()->summary();
            $apiRequests = max(0, (int)($summary['api_requests'] ?? 0));
            if ($apiRequests > 0) {
                ActivityCostRecorder::fromDatabase($this->app->db())->success(
                    $userId,
                    'cost_explorer',
                    'CostExplorer',
                    null,
                    ['cost_explorer.api_request' => $apiRequests],
                    $started,
                    ['api_requests' => $apiRequests]
                );
            }

            JsonResponse::send($summary);
        } catch (AwsException $e) {
            if ($userId > 0) {
                ActivityCostRecorder::fromDatabase($this->app->db())->failure($userId, 'cost_explorer', 'CostExplorer', $started);
            }
            $message = $e->getAwsErrorMessage() ?: $e->getMessage();
            JsonResponse::send([
                'ok' => false,
                'error' => 'AWS Error: ' . $message,
                'aws_code' => $e->getAwsErrorCode(),
            ], 400);
        } catch (Throwable $e) {
            if ($userId > 0) {
                ActivityCostRecorder::fromDatabase($this->app->db())->failure($userId, 'cost_explorer', 'CostExplorer', $started);
            }
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
