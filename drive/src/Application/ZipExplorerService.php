<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

/**
 * ZIP exploration must never fetch archives to the EC2 filesystem.
 * All reads/extractions are delegated to the authenticated Lambda gateway.
 */
final class ZipExplorerService
{
    public static function handle(): void
    {
        if (!ZipLambdaExplorerService::enabled()) {
            // Fail closed: the EC2 must not process ZIP contents locally.
            if (($_GET['format'] ?? '') === 'json') \ArcadeCloud\Drive\Http\JsonResponse::error('Este nodo requiere Lambda habilitada para explorar ZIP.', 503);
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'El explorador ZIP requiere AWS Lambda habilitada. No se descargó ningún archivo ZIP en la EC2.';
            return;
        }

        ZipLambdaExplorerService::handle();
    }
}
