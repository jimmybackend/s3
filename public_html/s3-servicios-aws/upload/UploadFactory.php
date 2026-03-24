<?php
/**
 * Archivo: upload/UploadFactory.php
 * Versión: 3.0
 * Descripción: Crea el driver de subida según el modo solicitado por la capa API.
 */
// upload/UploadFactory.php
declare(strict_types=1);

require_once __DIR__ . '/core/UploaderInterface.php';

require_once __DIR__ . '/drivers/LocalPresignedPutUploader.php';
require_once __DIR__ . '/drivers/RemoteUrlUploader.php';
require_once __DIR__ . '/drivers/DropboxUploader.php';
require_once __DIR__ . '/drivers/Chunked15MBUploader.php';

final class UploadFactory
{
    // Punto único de resolución de estrategia de upload para mantener uniforme la integración.
    public static function make($mode)
    {
        $mode = (string)$mode;

        switch ($mode) {
            case 'local_put':
                return new LocalPresignedPutUploader();
            case 'remote_url':
                return new RemoteUrlUploader();
            case 'dropbox':
                return new DropboxUploader();
            case 'chunked':
                return new Chunked15MBUploader();
            default:
                throw new RuntimeException('Modo inválido: ' . $mode);
        }
    }
}
