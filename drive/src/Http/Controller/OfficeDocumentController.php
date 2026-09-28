<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Office\OfficeDocumentStorageService;
use RuntimeException;
use Throwable;

final class OfficeDocumentController extends AbstractJsonController
{
    public function dispatch(): never
    {
        try {
            if ((string)getenv('ARCADECLOUD_OFFICE_DOCUMENT_GATE') !== '1') {
                JsonResponse::send(['ok' => false, 'error' => 'No encontrado.'], 404);
            }

            $this->requirePost();

            $action = strtolower($this->request->serverString(
                'HTTP_X_ARCADECLOUD_OFFICE_DOCUMENT_ACTION'
            ));
            if (!in_array($action, ['prepare', 'sync', 'close'], true)) {
                throw new RuntimeException('Acción documental no permitida.');
            }

            $sessionId = strtolower($this->request->postString('session_id'));
            $controlToken = strtolower($this->request->postString('control_token'));
            if (
                !preg_match('/^[a-f0-9]{32}$/', $sessionId)
                || !preg_match('/^[a-f0-9]{64}$/', $controlToken)
            ) {
                throw new RuntimeException('Credenciales documentales inválidas.');
            }

            $service = new OfficeDocumentStorageService($this->app);
            $result = match ($action) {
                'prepare' => $service->prepare($sessionId, $controlToken),
                'close' => $service->sync($sessionId, $controlToken, true),
                default => $service->sync($sessionId, $controlToken, false),
            };

            JsonResponse::send(array_merge(['ok' => true], $result));
        } catch (RuntimeException $error) {
            $this->fail($error, 409);
        } catch (Throwable $error) {
            $this->fail($error, 500, 'No se pudo completar la operación documental de Office.');
        }
    }
}
