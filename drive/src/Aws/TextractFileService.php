<?php
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
