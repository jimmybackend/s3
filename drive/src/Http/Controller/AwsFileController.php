<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Aws\FileMetadataRepository;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\PollyFileService;
use ArcadeCloud\Drive\Aws\RekognitionFileService;
use ArcadeCloud\Drive\Aws\TextractFileService;
use ArcadeCloud\Drive\Aws\TranslateFileService;
use ArcadeCloud\Drive\Http\JsonResponse;

final class AwsFileController extends AbstractJsonController
{
    public function textract(): never { $this->run(function(int $uid){$key=$this->first('archivo','archivoTextract');return $this->textractService()->extract($uid,$key);}); }
    public function translate(): never { $this->run(fn(int $uid)=>$this->translateService()->translate($uid,$this->request->postString('archivo'),$this->request->postString('target','es'),$this->request->postString('source','auto'))); }
    public function rekognition(): never { $this->run(fn(int $uid)=>$this->rekognitionService()->analyze($uid,$this->first('archivo','key'),(float)$this->request->postString('min_conf','70'),(int)$this->request->postString('max_labels','50'))); }
    public function pollyVoices(): never { $this->run(fn(int $uid)=>$this->pollyService()->voices($uid,$this->request->queryString('language')), false); }
    public function pollyText(): never { $this->run(fn(int $uid)=>$this->pollyService()->loadText($uid,$this->request->postString('archivo'))); }
    public function pollyTts(): never { $this->run(fn(int $uid)=>$this->pollyService()->synthesize($uid,$this->request->allPost())); }
    public function comprehend(): never
    {
        $this->run(function(int $uid){
            $service=new \ArcadeCloud\Drive\Aws\ComprehendFileService($this->app->db(),$this->app->s3(),\Config::getComprehend(),$this->app->bucket());
            return ['ok'=>true,'analysis'=>$service->analyze($uid,$this->first('key','archivo'))];
        });
    }

    private function run(callable $callback,bool $post=true): never
    {
        try { if($post)$this->requirePost(); $uid=$this->guardAuthenticated(); JsonResponse::send($callback($uid)); }
        catch(\Throwable $e){JsonResponse::send(['ok'=>false,'error'=>$e->getMessage()],400);}
    }
    private function locator(): FileRecordLocator {return new FileRecordLocator($this->app->db());}
    private function textractService(): TextractFileService
    {
        return new TextractFileService(
            $this->locator(),
            new FileMetadataRepository($this->app->db()),
            \Config::getTextract(),
            $this->app->bucket()
        );
    }
    private function translateService(): TranslateFileService {return new TranslateFileService($this->locator(),$this->app->s3(),$this->app->bucket(),$this->textractService(),\Config::getTranslate());}
    private function rekognitionService(): RekognitionFileService {return new RekognitionFileService($this->locator(),new FileMetadataRepository($this->app->db()),\Config::getRekognition(),$this->app->bucket());}
    private function pollyService(): PollyFileService {return new PollyFileService($this->locator(),new GeneratedFileRepository($this->app->db()),$this->app->s3(),\Config::getPolly(),$this->app->bucket());}
    private function first(string ...$names): string {foreach($names as $name){$value=$this->request->postString($name);if($value!=='')return $value;}return '';}
}
