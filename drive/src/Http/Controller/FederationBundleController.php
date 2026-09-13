<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\ArcadeLinkBundleService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class FederationBundleController
{
    private const MAX_ITEMS = 500;

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
                'error' => 'Selecciona como máximo ' . self::MAX_ITEMS . ' archivos por paquete.',
            ], 413);
        }

        $visibility = $this->request->postString('visibility', 'UNLISTED');
        $rights = $this->request->postString('rights', 'link_only');
        $discoveryPolicy = $this->request->postString('discovery_policy');
        $started = microtime(true);
        $bundlePath = '';

        try {
            $service = new FederationService($this->app);
            $links = [];
            $portalUrl = '';

            foreach ($keys as $key) {
                $created = $service->createLinkByStorageRef(
                    $userId,
                    $key,
                    $visibility,
                    $rights,
                    $discoveryPolicy
                );

                $document = is_array($created['document'] ?? null) ? $created['document'] : [];
                if ($portalUrl === '') {
                    $portalUrl = (string)($document['federation_url'] ?? '');
                }

                $links[] = [
                    'filename' => (string)($created['filename'] ?? 'resource.arcadelink'),
                    'content' => (string)($created['content'] ?? ''),
                ];
            }

            $bundle = (new ArcadeLinkBundleService())->create($links, $portalUrl);
            $bundlePath = (string)$bundle['path'];
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$bundle['filename']) ?: 'ArcadeLinks-portables.zip';
            $size = is_file($bundlePath) ? filesize($bundlePath) : false;

            $this->activity()->success(
                $userId,
                'arcadelink_create',
                'FederationCloud',
                null,
                ['drive.no_direct_aws_charge' => max(1, count($links))],
                $started,
                [
                    'items' => count($links),
                    'visibility' => $visibility,
                    'rights' => $rights,
                    'discovery_policy' => $discoveryPolicy,
                    'source' => 'drive_bulk_selection',
                    'bundle' => 'portable_zip',
                    'aws_direct' => false,
                ]
            );

            header('Content-Type: application/zip');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            if (is_int($size) || is_float($size)) {
                header('Content-Length: ' . (string)$size);
            }

            $ok = readfile($bundlePath);
            @unlink($bundlePath);
            $bundlePath = '';
            if ($ok === false) {
                throw new FederationException('No se pudo enviar el ZIP ArcadeLink.', 500);
            }
            exit;
        } catch (FederationException $error) {
            if ($bundlePath !== '') @unlink($bundlePath);
            $this->activity()->failure($userId, 'arcadelink_create', 'FederationCloud', $started);
            JsonResponse::send(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus());
        } catch (Throwable) {
            if ($bundlePath !== '') @unlink($bundlePath);
            $this->activity()->failure($userId, 'arcadelink_create', 'FederationCloud', $started);
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo crear el paquete ArcadeLink.'], 500);
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
