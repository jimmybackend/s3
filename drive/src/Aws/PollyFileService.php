<?php
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
        return ['ok'=>true,'archivo'=>$real,'file_id'=>(int)$row['id_'],'texto'=>$body,'bytes'=>strlen($body)];
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
        $characters=function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);
        return ['ok'=>true,'mode'=>$toS3?'s3':'inline','s3_key'=>$toS3?$dest:null,'filename'=>basename($dest),'nombre'=>$visible,'ruta'=>$dir,'audioBase64'=>base64_encode($bytes),'contentType'=>$contentType,'db_status'=>$dbStatus,'db_message'=>$dbStatus==='no_intentado'?'':('Registro '.$dbStatus.' correctamente en FileS3.'),'db_error'=>'','engine_used'=>(string)$params['Engine'],'characters_input'=>$characters,'output_bytes'=>$size,'source_file_id'=>(int)$origin['id_']];
    }

    private function format(string $format): array
    {
        return match($format){'ogg_vorbis'=>['ogg_vorbis','ogg','audio/ogg'],'pcm'=>['pcm','pcm','audio/pcm'],default=>['mp3','mp3','audio/mpeg']};
    }
}
