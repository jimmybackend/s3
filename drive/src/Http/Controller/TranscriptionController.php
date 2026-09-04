<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\TranscriptionFileService;
use ArcadeCloud\Drive\Http\JsonResponse;

final class TranscriptionController extends AbstractJsonController
{
    public function start(): never
    {
        try{$this->requirePost();$uid=$this->guardAuthenticated();JsonResponse::send($this->service()->start($uid,$this->request->allPost()));}
        catch(\Throwable $e){JsonResponse::send(['ok'=>false,'error'=>$e->getMessage()],400);}
    }
    public function status(): never
    {
        try{$uid=$this->guardAuthenticated();$job=$this->first('jobName');$file=$this->first('archivo');JsonResponse::send($this->service()->status($uid,$job,$file));}
        catch(\Throwable $e){JsonResponse::send(['ok'=>false,'error'=>$e->getMessage()],400);}
    }
    private function service(): TranscriptionFileService{return new TranscriptionFileService(new FileRecordLocator($this->app->db()),new GeneratedFileRepository($this->app->db()),$this->app->s3(),\Config::getTranscribe(),$this->app->bucket());}
    private function first(string $name): string {$v=$this->request->postString($name);return $v!==''?$v:$this->request->queryString($name);}
}
