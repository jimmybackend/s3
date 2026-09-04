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
