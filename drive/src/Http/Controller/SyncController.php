<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Http\Controller;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Sync\S3SyncService;
use ArcadeCloud\Drive\Sync\SyncRepository;
use ArcadeCloud\Drive\View\SyncStatusRenderer;

final class SyncController extends AbstractJsonController
{
    public function run(): never
    {
        try{$uid=$this->guardAuthenticated();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();ignore_user_abort(true);@set_time_limit(0);$service=new S3SyncService(new SyncRepository($this->app->db()),$this->app->s3(),$this->app->bucket(),$this->app->userStoragePath());JsonResponse::send($service->synchronize($uid));}
        catch(\Throwable $e){JsonResponse::send(['ok'=>false,'step'=>'sync','error'=>$e->getMessage()],500);}
    }
    public function status(): never
    {
        try{$uid=$this->guardAuthenticated();$status=(new SyncRepository($this->app->db()))->status($uid);if($this->request->queryString('view')==='html'){header('Content-Type: text/html; charset=utf-8');echo(new SyncStatusRenderer())->render($status,$this->request->queryString('loading')==='1');exit;}JsonResponse::send(['ok'=>true,'user_id'=>$uid]+$status);}
        catch(\Throwable $e){JsonResponse::send(['ok'=>false,'error'=>$e->getMessage()],500);}
    }
}
