<?php
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
        $sourceS3Get = false;
        $textractPages = 0;
        if (in_array($ext,self::TEXT,true)) {
            $obj=$this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$real]); $text=(string)$obj['Body'];
            $sourceS3Get = true;
            if (function_exists('mb_detect_encoding') && !mb_detect_encoding($text,'UTF-8',true)) $text=mb_convert_encoding($text,'UTF-8');
        } elseif (in_array($ext,self::DOCUMENT,true)) {
            $extraction=$this->textract->extract($userId,$real);
            $text=(string)($extraction['textoJ']??'');
            $textractPages=max(1,(int)($extraction['page_count']??1));
        } else throw new RuntimeException('Extensión no soportada para traducción');

        $characters = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $requests = 0;
        $translation=''; foreach ($this->chunks($text) as $chunk) {
            if ($chunk==='') continue;
            $response=$this->translate->translateText(['Text'=>$chunk,'SourceLanguageCode'=>$source,'TargetLanguageCode'=>$target]);
            $translation.=(string)$response['TranslatedText']."\n";
            $requests++;
        }
        return ['ok'=>true,'archivo'=>$real,'file_id'=>(int)$row['id_'],'target'=>$target,'sourceUsed'=>$source,'traduccion'=>trim($translation),'characters_input'=>$characters,'input_bytes'=>strlen($text),'requests'=>$requests,'source_s3_get'=>$sourceS3Get,'textract_pages'=>$textractPages];
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
