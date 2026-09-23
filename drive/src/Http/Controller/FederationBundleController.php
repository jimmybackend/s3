<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\ArcadeLinkService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class FederationBundleController
{
    private const MAX_ITEMS = ArcadeLinkService::MAX_COLLECTION_ITEMS;

    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function download(): never
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }

        $userId = $this->currentUserId();
        if ($userId <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'Debes iniciar sesión para compartir archivos.'], 401);
        }

        $keys = $this->request->postArray('archivos');
        if ($keys === []) {
            $keys = $this->request->postJsonArray('archivos_json');
        }
        $keys = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $keys
        ), static fn(string $value): bool => $value !== '')));

        if ($keys === []) {
            JsonResponse::send(['ok' => false, 'error' => 'Selecciona al menos un archivo.'], 400);
        }
        if (count($keys) > self::MAX_ITEMS) {
            JsonResponse::send([
                'ok' => false,
                'error' => 'Selecciona como máximo ' . self::MAX_ITEMS . ' archivos por ArcadeLink.',
            ], 413);
        }

        $visibility = $this->request->postString('visibility', 'UNLISTED');
        $rights = $this->request->postString('rights', 'link_only');
        $discoveryPolicy = $this->request->postString('discovery_policy');
        $started = microtime(true);

        try {
            $service = new FederationService($this->app);
            $created = $service->createCollectionByStorageRefs(
                $userId,
                $keys,
                $visibility,
                $rights,
                $discoveryPolicy
            );

            $content = (string)($created['content'] ?? '');
            if ($content === '' || strlen($content) > ArcadeLinkService::MAX_BYTES) {
                throw new FederationException('No se pudo preparar el archivo ArcadeLink.', 500);
            }

            $filename = preg_replace(
                '/[^A-Za-z0-9._-]+/',
                '_',
                (string)($created['filename'] ?? 'Compartidos.arcadelink')
            ) ?: 'Compartidos.arcadelink';
            if (!str_ends_with(strtolower($filename), '.arcadelink')) {
                $filename .= '.arcadelink';
            }

            $itemCount = max(1, (int)($created['item_count'] ?? count($keys)));
            $this->activity()->success(
                $userId,
                'arcadelink_create',
                'FederationCloud',
                null,
                ['drive.no_direct_aws_charge' => $itemCount],
                $started,
                [
                    'items' => $itemCount,
                    'visibility' => $visibility,
                    'rights' => $rights,
                    'discovery_policy' => $discoveryPolicy,
                    'source' => 'drive_bulk_selection',
                    'artifact' => 'arcadelink',
                    'collection' => !empty($created['collection']),
                    'aws_direct' => false,
                ]
            );

            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string)strlen($content));
            echo $content;
            exit;
        } catch (FederationException $error) {
            $this->activity()->failure($userId, 'arcadelink_create', 'FederationCloud', $started);
            JsonResponse::send(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus());
        } catch (Throwable) {
            $this->activity()->failure($userId, 'arcadelink_create', 'FederationCloud', $started);
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo crear el ArcadeLink.'], 500);
        }
    }

    private function currentUserId(): int
    {
        $session = $this->app->session();
        $session->start();
        return $session->isAuthenticated() ? $session->userId() : 0;
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }
}
