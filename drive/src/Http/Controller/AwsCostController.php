<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

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

        try {
            $this->guardAuthenticated();
            $this->app->session()->closeWrite();

            JsonResponse::send($this->app->awsCostService()->summary());
        } catch (AwsException $e) {
            $message = $e->getAwsErrorMessage() ?: $e->getMessage();

            JsonResponse::send([
                'ok' => false,
                'error' => 'AWS Error: ' . $message,
                'aws_code' => $e->getAwsErrorCode(),
            ], 400);
        } catch (Throwable $e) {
            JsonResponse::send([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
