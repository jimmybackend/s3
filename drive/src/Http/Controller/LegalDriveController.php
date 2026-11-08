<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Http\Controller;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Integration\LegalApiConfig;
use ArcadeCloud\Drive\Integration\LegalDriveService;

final class LegalDriveController
{
    public function __construct(private DriveApplication $app) {}
    public function dispatch(bool $content=false): never
    {
        header('Cache-Control: no-store, private'); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
        try {
            if (($_SERVER['HTTPS']??'')!=='on') throw new \RuntimeException('Se requiere HTTPS.',403);
            if (($_SERVER['REQUEST_METHOD']??'')!=='GET') throw new \RuntimeException('Método no permitido.',405);
            $config=LegalApiConfig::load(); $service=new LegalDriveService($this->app,$config);
            if ($content) {
                $ticket=$config->redeem($this->arg('t')); $file=$service->content($ticket['grant'],$ticket['file'],$ticket['mode'],$_SERVER['HTTP_RANGE']??null);
                header('Content-Type: '.$file['mime']); header('Content-Disposition: '.$file['disposition']); header('Accept-Ranges: bytes');
                header('Content-Length: '.max(0,$file['end']-$file['start']+1));
                if ($file['partial']) { http_response_code(206); header('Content-Range: bytes '.$file['start'].'-'.$file['end'].'/'.$file['size']); }
                while (!$file['body']->eof()) { echo $file['body']->read(65536); if (connection_aborted()) break; }
                exit;
            }
            $grant=$config->authenticate($_SERVER['HTTP_AUTHORIZATION']??''); $user=$service->requireUser($grant);
            $action=$this->arg('action','browse');
            $data=match($action) {
                'browse'=>$service->browse($user,$this->number('folder',0),$this->arg('q'),$this->number('page',1),$this->number('folder_page',1)),
                'files'=>$service->files($user,$this->arg('ids')),
                'file'=>['instance'=>$config->instance(),'file'=>LegalDriveService::metadata($service->file($user,$this->number('id',0)))],
                'access'=>$service->access($grant,$this->number('id',0),$this->arg('mode','open'))+['instance'=>$config->instance()],
                default=>throw new \RuntimeException('Operación desconocida.',404),
            };
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true]+$data,JSON_THROW_ON_ERROR); exit;
        } catch (\Throwable $e) {
            if (headers_sent()) { error_log('Legal Drive content interrupted: '.get_class($e)); exit; }
            $code=in_array($e->getCode(),[400,401,403,404,405,416,423,503],true)?$e->getCode():503;
            if ($code===503) error_log('Legal Drive API: '.get_class($e));
            http_response_code($code); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>match($code){401=>'Autorización vencida o revocada.',403=>'Acceso no autorizado.',404=>'Archivo o carpeta no disponible.',423=>'Archivo o carpeta protegido.',400=>'Solicitud inválida.',405=>'Método no permitido.',416=>'Rango inválido.',default=>'Drive no disponible temporalmente.'}]); exit;
        }
    }
    private function arg(string $name,string $default=''): string
    {
        $v=$_GET[$name]??$default;
        if (!is_string($v) || strlen($v)>2048) throw new \RuntimeException('Solicitud inválida.',400);
        return $v;
    }
    private function number(string $name,int $default): int
    {
        $v=$this->arg($name,(string)$default);
        if (!preg_match('/^\d{1,18}$/D',$v) || (int)$v<0) throw new \RuntimeException('Identificador inválido.',400);
        return (int)$v;
    }
}
