<?php
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
