<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Application\FileAccessService;
use ArcadeCloud\Drive\Application\ZipDownloadService;
use ArcadeCloud\Drive\Http\BinaryResponse;

final class FileAccessController
{
    public function __construct(
        private \ArcadeCloud\Drive\Core\DriveApplication $app,
        private \ArcadeCloud\Drive\Http\Request $request,
        private BinaryResponse $response = new BinaryResponse()
    ) {
    }

    public function signedDownload(): never
    {
        try {
            $userId=$this->userId(); $key=$this->request->queryString('archivo');
            $result=$this->service()->signedDownload($userId,$key);
            $this->response->redirect((string)$result['url_descarga']);
        } catch (\Throwable $e) { $this->response->text($this->status($e), 'Error: '.$e->getMessage()); }
    }

    public function download(): never
    {
        try {
            $file=$this->service()->downloadStream($this->userId(), $this->request->queryString('archivo'), $this->request->queryString('nombre'));
            $this->response->attachment($file);
        } catch (\Throwable $e) { $this->response->text($this->status($e), 'Error al descargar: '.$e->getMessage()); }
    }

    public function pdf(): never
    {
        try {
            $file=$this->service()->downloadStream($this->userId(), $this->request->queryString('archivo'));
            $this->response->inlineDocument($file, 'application/pdf', 86400);
        } catch (\Throwable $e) {
            $this->response->text($this->status($e), 'Error al cargar PDF: '.$e->getMessage());
        }
    }

    public function view(): never
    {
        try {
            $range=$this->request->serverString('HTTP_RANGE');
            $file=$this->service()->inlineRange($this->userId(), $this->request->queryString('archivo'), $range !== '' ? $range : null);
            $this->response->inlineRange($file);
        } catch (\Throwable $e) {
            $status=$this->status($e);
            if ($status===416) header('Accept-Ranges: bytes');
            $this->response->text($status, $status===423 ? 'Archivo protegido. Debes desbloquearlo antes de visualizarlo.' : $e->getMessage());
        }
    }

    public function zip(): never
    {
        try {
            if ($this->request->method() !== 'POST') $this->response->text(405, 'Método no permitido');
            $keys=$this->request->postArray('archivos');
            if (!$keys) $keys=$this->request->postJsonArray('archivos_json');
            $service=new ZipDownloadService($this->app->fileRecordLocator(), $this->app->s3(), $this->app->bucket());
            $this->response->zip($service->create($this->userId(),$keys));
        } catch (\Throwable $e) { $this->response->text($this->status($e), 'Error: '.$e->getMessage()); }
    }

    private function service(): FileAccessService
    {
        return new FileAccessService($this->app->fileRecordLocator(), $this->app->s3(), $this->app->bucket(), $this->app->s3Manager());
    }

    private function userId(): int
    {
        $session=$this->app->session(); $session->start();
        if (!$session->isAuthenticated() || $session->userId()<=0) $this->response->text(401,'Sesión inválida');
        return $session->userId();
    }

    private function status(\Throwable $error): int
    {
        $code=(int)$error->getCode();
        if (in_array($code,[400,401,403,404,416,423],true)) return $code;
        $message=strtolower($error->getMessage());
        if (str_contains($message,'protegido')) return 423;
        if (str_contains($message,'no encontrado')) return 404;
        return 500;
    }
}
