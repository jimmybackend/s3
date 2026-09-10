<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
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
        $userId = 0; $started = microtime(true);
        try {
            $userId=$this->userId(); $key=$this->request->queryString('archivo');
            $result=$this->service()->signedDownload($userId,$key);
            $this->activity()->success($userId, 'download', 'S3', (int)($result['id'] ?? 0), [
                's3.presigned_get_intent' => 1,
            ], $started);
            $this->response->redirect((string)$result['url_descarga']);
        } catch (\Throwable $e) {
            if ($userId > 0) $this->activity()->failure($userId, 'download', 'S3', $started);
            $this->response->text($this->status($e), 'Error: '.$e->getMessage());
        }
    }

    public function download(): never
    {
        $userId = 0; $started = microtime(true);
        try {
            $userId = $this->userId();
            $file=$this->service()->downloadStream($userId, $this->request->queryString('archivo'), $this->request->queryString('nombre'));
            $this->recordS3Read($userId, 'download', $file, $started);
            $this->response->attachment($file);
        } catch (\Throwable $e) {
            if ($userId > 0) $this->activity()->failure($userId, 'download', 'S3', $started);
            $this->response->text($this->status($e), 'Error al descargar: '.$e->getMessage());
        }
    }

    public function pdf(): never
    {
        $userId = 0; $started = microtime(true);
        try {
            $userId = $this->userId();
            $file=$this->service()->downloadStream($userId, $this->request->queryString('archivo'));
            $this->recordS3Read($userId, 'preview', $file, $started);
            $this->response->inlineDocument($file, 'application/pdf', 86400);
        } catch (\Throwable $e) {
            if ($userId > 0) $this->activity()->failure($userId, 'preview', 'S3', $started);
            $this->response->text($this->status($e), 'Error al cargar PDF: '.$e->getMessage());
        }
    }

    public function view(): never
    {
        $userId = 0; $started = microtime(true);
        try {
            $userId = $this->userId();
            $range=$this->request->serverString('HTTP_RANGE');
            $file=$this->service()->inlineRange($userId, $this->request->queryString('archivo'), $range !== '' ? $range : null);
            $bytes = isset($file['range']->start, $file['range']->end)
                ? max(0, (int)$file['range']->end - (int)$file['range']->start + 1)
                : 0;
            $this->activity()->success($userId, 'preview', 'S3', (int)($file['row']['id_'] ?? 0), [
                's3.head_request' => 1,
                's3.get_request' => 1,
                's3.transfer_bytes' => $bytes,
            ], $started);
            $this->response->inlineRange($file);
        } catch (\Throwable $e) {
            if ($userId > 0) $this->activity()->failure($userId, 'preview', 'S3', $started);
            $status=$this->status($e);
            if ($status===416) header('Accept-Ranges: bytes');
            $this->response->text($status, $status===423 ? 'Archivo protegido. Debes desbloquearlo antes de visualizarlo.' : $e->getMessage());
        }
    }

    public function zip(): never
    {
        $userId = 0; $started = microtime(true);
        try {
            if ($this->request->method() !== 'POST') $this->response->text(405, 'Método no permitido');
            $userId = $this->userId();
            $keys=$this->request->postArray('archivos');
            if (!$keys) $keys=$this->request->postJsonArray('archivos_json');
            $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
            $service=new ZipDownloadService($this->app->fileRecordLocator(), $this->app->s3(), $this->app->bucket());
            $zip = $service->create($userId,$keys);
            $this->activity()->success($userId, 'zip', 'S3', null, [
                's3.get_request' => count($keys),
            ], $started, ['items' => count($keys)]);
            $this->response->zip($zip);
        } catch (\Throwable $e) {
            if ($userId > 0) $this->activity()->failure($userId, 'zip', 'S3', $started);
            $this->response->text($this->status($e), 'Error: '.$e->getMessage());
        }
    }

    private function recordS3Read(int $userId, string $action, array $file, float $started): void
    {
        $this->activity()->success($userId, $action, 'S3', (int)($file['row']['id_'] ?? 0), [
            's3.head_request' => 1,
            's3.get_request' => 1,
            's3.transfer_bytes' => max(0, (int)($file['length'] ?? 0)),
        ], $started);
    }

    private function service(): FileAccessService
    {
        return new FileAccessService($this->app->fileRecordLocator(), $this->app->s3(), $this->app->bucket());
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
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
