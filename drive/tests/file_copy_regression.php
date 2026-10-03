<?php
declare(strict_types=1);
set_error_handler(static function(int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
if (getenv('ARCADECLOUD_ISOLATED_TEST') !== '1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__,2).'/vendor/autoload.php';
spl_autoload_register(static function(string $class): void {
 $p='ArcadeCloud\\Drive\\'; if(str_starts_with($class,$p)) require_once dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen($p))).'.php';
});
use ArcadeCloud\Drive\Application\FileMutationService;
use ArcadeCloud\Drive\Storage\FileRecordRepository;
use Aws\S3\S3Client;
use Aws\Result;
use Aws\Exception\AwsException;
use GuzzleHttp\Promise\Create;
function copyCheck(bool $ok,string $msg):void {if(!$ok) throw new RuntimeException($msg); echo "OK: $msg\n";}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','fixture-only','',(int)(getenv('TEST_DB_PORT')?:3306));
$database='copy_regression_'.bin2hex(random_bytes(6)); $db->query("CREATE DATABASE `$database`");$db->select_db($database);$db->set_charset('utf8mb4');
try {
 $schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');
 preg_match('/CREATE TABLE IF NOT EXISTS `FileS3` \\(.*?;\\s/s',$schema,$m);
 $db->query(str_contains($db->server_info,'MariaDB')?str_replace('utf8mb4_0900_ai_ci','utf8mb4_unicode_ci',$m[0]):$m[0]);
 $key='Data2/opaque/á + #%.bin'; $route='Data2/opaque/'; $size=6*1024*1024*1024;
 $st=$db->prepare("INSERT INTO FileS3(Nombre,Encriptado,Tamano,Ruta,Found,user_id_) VALUES ('Visible.mp4',?,?,?,1,2)");$st->bind_param('sis',$key,$size,$route);$st->execute();$id=(int)$st->insert_id;$st->close();
 $objects=[$key=>$size];$calls=[];$failPart=false;$failDelete=false;
 $s3=new S3Client(['version'=>'latest','region'=>'us-east-1','credentials'=>['key'=>'fixture','secret'=>'fixture'],
 'handler'=>static function($c)use(&$objects,&$calls,&$failPart,&$failDelete,$key,$size){
  $n=$c->getName();$k=(string)$c['Key'];$calls[]=[$n,$c->toArray()];
  switch($n){
   case 'HeadObject':
    if(!isset($objects[$k])) return Create::rejectionFor(new AwsException('absent',$c,['code'=>'NotFound','status_code'=>404]));
    return Create::promiseFor(new Result(['ContentLength'=>$objects[$k],'ETag'=>'"source-etag"','ContentType'=>'video/mp4','Metadata'=>['fixture'=>'kept']]));
   case 'CopyObject':
    if(isset($objects[$k]) && $c['IfNoneMatch']==='*') return Create::rejectionFor(new AwsException('destination exists',$c,['code'=>'PreconditionFailed','status_code'=>412]));
    copyCheck(str_contains((string)$c['CopySource'],'%C3%A1%20%2B%20%23%25.bin'),'source header URL encoded');
    if($objects[$key]>5*1024*1024*1024) return Create::rejectionFor(new AwsException('too large',$c,['code'=>'EntityTooLarge']));
    $objects[$k]=$objects[$key];return Create::promiseFor(new Result(['CopyObjectResult'=>['ETag'=>'"copied"']]));
   case 'CreateMultipartUpload':
    copyCheck($c['ContentType']==='video/mp4'&&$c['Metadata']===['fixture'=>'kept'],'multipart preserves source metadata');
    return Create::promiseFor(new Result(['UploadId'=>'fixture-upload']));
   case 'UploadPartCopy':
    copyCheck(rawurldecode((string)$c['CopySource'])==='/fixture/'.$key, 'multipart source header resolves exact key');
    copyCheck($c['CopySourceIfMatch']==='"source-etag"','part protects source version');
    if($failPart) return Create::rejectionFor(new AwsException('fixture part failure',$c,['code'=>'AccessDenied']));
    return Create::promiseFor(new Result(['CopyPartResult'=>['ETag'=>'"part"']]));
   case 'CompleteMultipartUpload':
    if(isset($objects[$k]) && $c['IfNoneMatch']==='*') return Create::rejectionFor(new AwsException('destination exists',$c,['code'=>'PreconditionFailed','status_code'=>412]));
    $objects[$k]=$size;return Create::promiseFor(new Result(['ETag'=>'"copied"']));
   case 'AbortMultipartUpload':return Create::promiseFor(new Result([]));
   case 'DeleteObject':
    if($failDelete) return Create::rejectionFor(new AwsException('fixture delete failure',$c,['code'=>'AccessDenied']));
    unset($objects[$k]);return Create::promiseFor(new Result([]));
   default: throw new LogicException('Unexpected S3 operation '.$n);
  }
 }]);
 $repo=new FileRecordRepository($db);$service=new FileMutationService($repo,$s3,'fixture');
 $copy=$service->copy(2,$id,'Data2/dest/');
 copyCheck(isset($objects[$key],$objects[$copy['key_s3']])&&$repo->requireByRef(2,$copy['id'])['Nombre']==='Visible.mp4','6 GiB copy preserves source and creates DB/S3 destination');
 $ranges=array_values(array_map(fn($c)=>$c[1]['CopySourceRange'],array_filter($calls,fn($c)=>$c[0]==='UploadPartCopy')));
 $next=0;foreach($ranges as $r){preg_match('/bytes=(\d+)-(\d+)/',$r,$m);copyCheck((int)$m[1]===$next,'multipart ranges contiguous');$next=(int)$m[2]+1;}
 copyCheck($next===$size,'multipart covers exact large object without downloading bytes');
 $collision='Data2/collision/'.basename($key);$objects[$collision]=77;
 try{$service->move(2,$id,'Data2/collision/');throw new LogicException('Multipart destination overwritten');}catch(RuntimeException){}
 copyCheck($objects[$collision]===77&&isset($objects[$key])&&$repo->requireByRef(2,$id)['_key']===$key,'multipart collision preserves existing destination, source and catalog');
 $calls=[];$failPart=true;
 try{$service->move(2,$id,'Data2/failed/');throw new LogicException('Failure hidden');}catch(RuntimeException){}
 copyCheck(isset($objects[$key])&&$repo->requireByRef(2,$id)['_key']===$key,'failed multipart move retains origin and catalog');
 copyCheck(in_array('AbortMultipartUpload',array_column($calls,0),true)&&!in_array('DeleteObject',array_column($calls,0),true),'failed multipart aborted without deleting source');
 $failPart=false;$objects[$key]=123;$calls=[];
 try{$service->move(2,$id,'Data2/collision/');throw new LogicException('Single-copy destination overwritten');}catch(RuntimeException){}
 copyCheck($objects[$collision]===77&&isset($objects[$key])&&$repo->requireByRef(2,$id)['_key']===$key,'single-copy collision preserves both files and catalog');
 $calls=[];
 $small=$service->copy(2,$id,'Data2/small/');
 copyCheck(in_array('CopyObject',array_column($calls,0),true)&&!in_array('CreateMultipartUpload',array_column($calls,0),true),'small object keeps single-copy path');
 $db->query("CREATE TRIGGER reject_copy BEFORE INSERT ON FileS3 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='catalog fixture failure'");$calls=[];
 try{$service->copy(2,$id,'Data2/reject/');throw new LogicException('SQL failure hidden');}catch(RuntimeException){}
 copyCheck(isset($objects[$key])&&array_column($calls,0)===['HeadObject','CopyObject','DeleteObject'],'catalog failure cleans copy only');
 $db->query('DROP TRIGGER reject_copy');$failDelete=true;
 try{$service->move(2,$id,'Data2/moved/');throw new LogicException('Delete failure hidden');}catch(RuntimeException){}
 $moved=$repo->requireByRef(2,$id);copyCheck($moved['Ruta']==='Data2/moved/'&&isset($objects[$moved['_key']],$objects[$key]),'delete failure retains valid destination plus recoverable origin');
 $calls=[];try{$service->copy(3,$id,'Data3/');throw new LogicException('Foreign owner accepted');}catch(RuntimeException){}
 copyCheck($calls===[],'foreign owner rejected before S3');
} finally {$db->query("DROP DATABASE `$database`");}
