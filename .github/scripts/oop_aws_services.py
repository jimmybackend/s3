from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
DRIVE=ROOT/'drive'; SRC=DRIVE/'src'

def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True); path.write_text(content, encoding='utf-8')

# ------------------------------------------------------------------
# Config: one factory per AWS client; no endpoint creates credentials.
# ------------------------------------------------------------------
config=ROOT/'Config-s3.php'; text=config.read_text(encoding='utf-8')
imports={
'use Aws\\Polly\\PollyClient;':'use Aws\\Polly\\PollyClient;',
'use Aws\\Translate\\TranslateClient;':'use Aws\\Translate\\TranslateClient;',
'use Aws\\Rekognition\\RekognitionClient;':'use Aws\\Rekognition\\RekognitionClient;',
}
anchor='use Aws\\S3\\S3Client;'
for line in imports:
    if line not in text:
        text=text.replace(anchor, line+'\n'+anchor,1)
methods=r'''
        public static function getPolly(): PollyClient
        {
            return new PollyClient([
                'region' => self::REGION,
                'version' => 'latest',
                'credentials' => self::getAwsCredentials(),
                'http' => ['connect_timeout' => 15, 'timeout' => 120],
            ]);
        }

        public static function getTranslate(): TranslateClient
        {
            return new TranslateClient([
                'region' => self::REGION,
                'version' => 'latest',
                'credentials' => self::getAwsCredentials(),
                'http' => ['connect_timeout' => 15, 'timeout' => 120],
            ]);
        }
'''
if 'public static function getPolly()' not in text:
    pos=text.rfind('\n}')
    text=text[:pos]+methods+text[pos:]
config.write_text(text,encoding='utf-8')

write(SRC/'Aws/FileMetadataRepository.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use mysqli;
use RuntimeException;

final class FileMetadataRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function merge(int $userId, int $fileId, string $section, array $payload): void
    {
        $stmt=$this->db->prepare('SELECT Metadatos FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo leer metadatos: '.$this->db->error);
        $stmt->bind_param('ii',$fileId,$userId); $stmt->execute(); $res=$stmt->get_result();
        $row=$res?$res->fetch_assoc():null; $stmt->close();
        if (!$row) throw new RuntimeException('Archivo no encontrado para guardar metadatos.');
        $meta=[]; $raw=trim((string)($row['Metadatos']??''));
        if ($raw!=='') { $decoded=json_decode($raw,true); if (is_array($decoded)) $meta=$decoded; }
        $payload['ts']=$payload['ts']??date('c'); $meta[$section]=$payload;
        $json=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('No se pudieron serializar metadatos.');
        $stmt=$this->db->prepare('UPDATE FileS3 SET Metadatos=? WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo actualizar metadatos: '.$this->db->error);
        $stmt->bind_param('sii',$json,$fileId,$userId); $stmt->execute(); $stmt->close();
    }
}
''')

write(SRC/'Aws/GeneratedFileRepository.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use mysqli;
use RuntimeException;

final class GeneratedFileRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function upsert(int $userId, string $name, string $key, int $size, array $metadata, string $route): string
    {
        $json=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('No se pudieron serializar metadatos del archivo generado.');
        $stmt=$this->db->prepare('SELECT id_ FROM FileS3 WHERE user_id_=? AND Encriptado=? LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo comprobar el archivo generado.');
        $stmt->bind_param('is',$userId,$key); $stmt->execute(); $res=$stmt->get_result();
        $row=$res?$res->fetch_assoc():null; $stmt->close();
        if ($row) {
            $id=(int)$row['id_'];
            $stmt=$this->db->prepare("UPDATE FileS3 SET Nombre=?,Tamano=?,Metadatos=?,Ruta=?,Found=1 WHERE id_=? AND user_id_=?");
            if (!$stmt) throw new RuntimeException('No se pudo actualizar FileS3.');
            $stmt->bind_param('sissii',$name,$size,$json,$route,$id,$userId); $stmt->execute(); $stmt->close();
            return 'actualizado';
        }
        $stmt=$this->db->prepare("INSERT INTO FileS3 (Nombre,Encriptado,Tamano,Metadatos,Ruta,Found,AccessType,user_id_) VALUES (?,?,?,?,?,1,'normal',?)");
        if (!$stmt) throw new RuntimeException('No se pudo insertar FileS3.');
        $stmt->bind_param('ssissi',$name,$key,$size,$json,$route,$userId); $stmt->execute(); $stmt->close();
        return 'insertado';
    }
}
''')

write(SRC/'Aws/TextractFileService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Textract\TextractClient;
use RuntimeException;

final class TextractFileService
{
    private const EXTENSIONS=['jpg','jpeg','png','tif','tiff','pdf'];
    public function __construct(private FileRecordLocator $locator, private TextractClient $client, private string $bucket) {}

    public function extract(int $userId,string $key): array
    {
        $row=$this->locator->requireReadableByKey($userId,$key); $real=(string)$row['_key'];
        $ext=strtolower((string)pathinfo((string)($row['Nombre']??$real),PATHINFO_EXTENSION));
        if (!in_array($ext,self::EXTENSIONS,true)) throw new RuntimeException('Extensión no soportada para Textract');
        $result=$this->client->detectDocumentText(['Document'=>['S3Object'=>['Bucket'=>$this->bucket,'Name'=>$real]]]);
        $lines=[]; foreach ((array)($result['Blocks']??[]) as $block) {
            if (($block['BlockType']??'')==='LINE' && isset($block['Text'])) $lines[]=(string)$block['Text'];
        }
        return ['ok'=>true,'archivo'=>$real,'texto'=>$lines,'textoJ'=>implode("\n",$lines)];
    }

    public function extractText(int $userId,string $key): string
    {
        return (string)$this->extract($userId,$key)['textoJ'];
    }
}
''')

write(SRC/'Aws/TranslateFileService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\S3\S3Client;
use Aws\Translate\TranslateClient;
use RuntimeException;

final class TranslateFileService
{
    private const TEXT=['txt','md','markdown','jas'];
    private const DOCUMENT=['pdf','jpg','jpeg','png','tif','tiff'];
    public function __construct(
        private FileRecordLocator $locator, private S3Client $s3, private string $bucket,
        private TextractFileService $textract, private TranslateClient $translate
    ) {}

    public function translate(int $userId,string $key,string $target='es',string $source='auto'): array
    {
        $row=$this->locator->requireReadableByKey($userId,$key); $real=(string)$row['_key'];
        $ext=strtolower((string)pathinfo((string)($row['Nombre']??$real),PATHINFO_EXTENSION));
        if (in_array($ext,self::TEXT,true)) {
            $obj=$this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$real]); $text=(string)$obj['Body'];
            if (function_exists('mb_detect_encoding') && !mb_detect_encoding($text,'UTF-8',true)) $text=mb_convert_encoding($text,'UTF-8');
        } elseif (in_array($ext,self::DOCUMENT,true)) {
            $text=$this->textract->extractText($userId,$real);
        } else throw new RuntimeException('Extensión no soportada para traducción');
        $translation=''; foreach ($this->chunks($text) as $chunk) {
            if ($chunk==='') continue;
            $response=$this->translate->translateText(['Text'=>$chunk,'SourceLanguageCode'=>$source,'TargetLanguageCode'=>$target]);
            $translation.=(string)$response['TranslatedText']."\n";
        }
        return ['ok'=>true,'archivo'=>$real,'target'=>$target,'sourceUsed'=>$source,'traduccion'=>trim($translation)];
    }

    private function chunks(string $text): array
    {
        if ($text==='') return [];
        $chunks=[]; $remaining=$text;
        while ($remaining!=='') {
            if (strlen($remaining)<=9500) { $chunks[]=$remaining; break; }
            $slice=function_exists('mb_strcut')?mb_strcut($remaining,0,9500,'UTF-8'):substr($remaining,0,9500);
            $cut=max(strrpos($slice,"\n")?:0,strrpos($slice,' ')?:0);
            if ($cut>1000) $slice=substr($slice,0,$cut);
            $chunks[]=$slice; $remaining=substr($remaining,strlen($slice));
        }
        return $chunks;
    }
}
''')

write(SRC/'Aws/RekognitionFileService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Rekognition\RekognitionClient;
use RuntimeException;

final class RekognitionFileService
{
    private const EXTENSIONS=['jpg','jpeg','png'];
    public function __construct(private FileRecordLocator $locator,private FileMetadataRepository $metadata,private RekognitionClient $client,private string $bucket) {}
    public function analyze(int $userId,string $key,float $minConfidence=70.0,int $maxLabels=50): array
    {
        $row=$this->locator->requireReadableByKey($userId,$key); $real=(string)$row['_key'];
        $ext=strtolower((string)pathinfo((string)($row['Nombre']??$real),PATHINFO_EXTENSION));
        if (!in_array($ext,self::EXTENSIONS,true)) throw new RuntimeException('Rekognition admite aquí JPG/JPEG/PNG.');
        $labelsResp=$this->client->detectLabels(['Image'=>['S3Object'=>['Bucket'=>$this->bucket,'Name'=>$real]],'MaxLabels'=>max(1,min(100,$maxLabels)),'MinConfidence'=>max(0,min(100,$minConfidence))]);
        $labels=[]; foreach ((array)$labelsResp->get('Labels') as $label) {
            $parents=[]; foreach ((array)($label['Parents']??[]) as $parent) if (isset($parent['Name'])) $parents[]=(string)$parent['Name'];
            $labels[]=['Name'=>(string)($label['Name']??''),'Confidence'=>isset($label['Confidence'])?(float)$label['Confidence']:null,'Parents'=>$parents,'Instances'=>count((array)($label['Instances']??[]))];
        }
        $modResp=$this->client->detectModerationLabels(['Image'=>['S3Object'=>['Bucket'=>$this->bucket,'Name'=>$real]],'MinConfidence'=>max(0,min(100,$minConfidence))]);
        $moderation=[]; foreach ((array)$modResp->get('ModerationLabels') as $item) $moderation[]=['Name'=>(string)($item['Name']??''),'ParentName'=>(string)($item['ParentName']??''),'Confidence'=>isset($item['Confidence'])?(float)$item['Confidence']:null];
        $payload=['Bucket'=>$this->bucket,'S3Key'=>$real,'Ruta'=>(string)$row['Ruta'],'Nombre'=>(string)$row['Nombre'],'MinConf'=>$minConfidence,'MaxLabels'=>$maxLabels,'Labels'=>$labels,'Moderation'=>$moderation];
        $this->metadata->merge($userId,(int)$row['id_'],'Rekognition',$payload);
        return ['ok'=>true,'bucket'=>$this->bucket,'s3_key'=>$real,'ruta'=>$row['Ruta'],'nombre'=>$row['Nombre'],'min_conf'=>$minConfidence,'max_labels'=>$maxLabels,'labels'=>$labels,'moderation'=>$moderation,'saved'=>true,'message'=>'Análisis realizado y metadatos guardados correctamente.'];
    }
}
''')

write(SRC/'Aws/PollyFileService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Polly\PollyClient;
use Aws\S3\S3Client;
use RuntimeException;

final class PollyFileService
{
    private const TEXT_EXTENSIONS=['txt','md','markdown','jas'];
    public function __construct(
        private FileRecordLocator $locator, private GeneratedFileRepository $generated,
        private S3Client $s3, private PollyClient $polly, private string $bucket
    ) {}

    public function voices(int $userId,string $language=''): array
    {
        if ($userId<=0) throw new RuntimeException('Sesión inválida.');
        $args=$language!==''?['LanguageCode'=>$language]:[]; $resp=$this->polly->describeVoices($args); $voices=[];
        foreach ((array)$resp['Voices'] as $v) $voices[]=['Id'=>$v['Id'],'Name'=>$v['Name']??$v['Id'],'LanguageCode'=>$v['LanguageCode'],'Gender'=>$v['Gender']??null,'SupportedEngines'=>$v['SupportedEngines']??[]];
        usort($voices,static fn($a,$b)=>strcmp((string)$a['Name'],(string)$b['Name']));
        return ['ok'=>true,'voices'=>$voices];
    }

    public function loadText(int $userId,string $key): array
    {
        $row=$this->locator->requireReadableByKey($userId,$key); $real=(string)$row['_key'];
        $ext=strtolower((string)pathinfo((string)($row['Nombre']??$real),PATHINFO_EXTENSION));
        if (!in_array($ext,self::TEXT_EXTENSIONS,true)) throw new RuntimeException('Solo se pueden cargar archivos de texto compatibles en Polly.');
        $obj=$this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$real]); $body=(string)$obj['Body'];
        if (function_exists('mb_detect_encoding') && !mb_detect_encoding($body,'UTF-8',true)) $body=mb_convert_encoding($body,'UTF-8');
        return ['ok'=>true,'archivo'=>$real,'texto'=>$body];
    }

    public function synthesize(int $userId,array $input): array
    {
        $text=trim((string)($input['texto']??'')); $voice=trim((string)($input['voiceId']??''));
        $engine=trim((string)($input['engine']??'neural')); $format=trim((string)($input['format']??'mp3'));
        $sample=trim((string)($input['sampleRate']??'22050')); $from=trim((string)($input['from_key']??$input['fromKey']??$input['pollyArchivoKey']??''));
        $toS3=(int)($input['to_s3']??1);
        if ($text===''||$voice===''||$from==='') throw new RuntimeException('Texto, VoiceId y archivo origen son requeridos.');
        $origin=$this->locator->requireReadableByKey($userId,$from); $realFrom=(string)$origin['_key'];
        [$format,$ext,$contentType]=$this->format($format);
        $dir=rtrim(dirname($realFrom),'/').'/'; $physicalBase=pathinfo(basename($realFrom),PATHINFO_FILENAME); $dest=$dir.$physicalBase.'.'.$ext;
        $visible=(string)($origin['Nombre']??$physicalBase); $visible=(preg_replace('/\.[^.]+$/','',$visible)?:$visible).'.'.$ext;
        $params=['Text'=>$text,'VoiceId'=>$voice,'Engine'=>$engine,'OutputFormat'=>$format,'SampleRate'=>$sample];
        try {$res=$this->polly->synthesizeSpeech($params);} catch (\Aws\Exception\AwsException $e) {
            if ($engine==='neural' && stripos($e->getMessage(),'does not support the selected engine')!==false) {$params['Engine']='standard';$res=$this->polly->synthesizeSpeech($params);} else throw $e;
        }
        $bytes=(string)$res->get('AudioStream'); $size=strlen($bytes); if ($size<=0) throw new RuntimeException('Polly no devolvió contenido de audio.');
        $meta=['tipo'=>$contentType,'servicio'=>'Amazon Polly','engine'=>$params['Engine'],'voiceId'=>$voice,'format'=>$format,'sampleRate'=>$sample,'origen'=>$realFrom,'destino'=>$dest,'ruta'=>$dir,'tamano_bytes'=>$size,'fecha'=>date('Y-m-d'),'hora'=>date('H:i:s')];
        $dbStatus='no_intentado';
        if ($toS3===1) {
            $this->s3->putObject(['Bucket'=>$this->bucket,'Key'=>$dest,'Body'=>$bytes,'ACL'=>'private','ContentType'=>$contentType,'Metadata'=>['origin'=>$realFrom,'voiceid'=>$voice,'engine'=>$params['Engine'],'format'=>$format,'sample_rate'=>$sample]]);
            $dbStatus=$this->generated->upsert($userId,$visible,$dest,$size,$meta,$dir);
        }
        return ['ok'=>true,'mode'=>$toS3?'s3':'inline','s3_key'=>$toS3?$dest:null,'filename'=>basename($dest),'nombre'=>$visible,'ruta'=>$dir,'audioBase64'=>base64_encode($bytes),'contentType'=>$contentType,'db_status'=>$dbStatus,'db_message'=>$dbStatus==='no_intentado'?'':('Registro '.$dbStatus.' correctamente en FileS3.'),'db_error'=>''];
    }

    private function format(string $format): array
    {
        return match($format){'ogg_vorbis'=>['ogg_vorbis','ogg','audio/ogg'],'pcm'=>['pcm','pcm','audio/pcm'],default=>['mp3','mp3','audio/mpeg']};
    }
}
''')

write(SRC/'Http/Controller/AwsFileController.php', r'''<?php
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
    private function textractService(): TextractFileService {return new TextractFileService($this->locator(),\Config::getTextract(),$this->app->bucket());}
    private function translateService(): TranslateFileService {return new TranslateFileService($this->locator(),$this->app->s3(),$this->app->bucket(),$this->textractService(),\Config::getTranslate());}
    private function rekognitionService(): RekognitionFileService {return new RekognitionFileService($this->locator(),new FileMetadataRepository($this->app->db()),\Config::getRekognition(),$this->app->bucket());}
    private function pollyService(): PollyFileService {return new PollyFileService($this->locator(),new GeneratedFileRepository($this->app->db()),$this->app->s3(),\Config::getPolly(),$this->app->bucket());}
    private function first(string ...$names): string {foreach($names as $name){$value=$this->request->postString($name);if($value!=='')return $value;}return '';}
}
''')

# Request allPost safe copy for controller->service DTO compatibility.
req=SRC/'Http/Request.php'; t=req.read_text(encoding='utf-8')
needle='''    public function files(): array\n    {\n'''
insert='''    public function allPost(): array\n    {\n        return $this->post;\n    }\n\n'''
if 'allPost()' not in t: t=t.replace(needle,insert+needle,1)
req.write_text(t,encoding='utf-8')

methods={'procesar_textract.php':'textract','traducir_archivo.php':'translate','rekognition_labels.php':'rekognition','polly_list_voices.php':'pollyVoices','polly_cargar_texto.php':'pollyText','polly_tts.php':'pollyTts','comprehend_archivo.php':'comprehend'}
for filename,method in methods.items():
    write(DRIVE/filename,f'''<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/app_bootstrap.php';\n(new \\ArcadeCloud\\Drive\\Http\\Controller\\AwsFileController(\n    \\ArcadeCloud\\Drive\\Core\\ApplicationKernel::app(),\n    \\ArcadeCloud\\Drive\\Http\\Request::fromGlobals()\n))->{method}();\n''')
print('AWS services migrated')
