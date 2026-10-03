<?php
declare(strict_types=1);
if(getenv('ARCADECLOUD_ISOLATED_TEST')!=='1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__,2).'/vendor/autoload.php';
spl_autoload_register(static function(string $c):void{$p='ArcadeCloud\\Drive\\';if(str_starts_with($c,$p))require_once dirname(__DIR__).'/src/'.str_replace('\\','/',substr($c,strlen($p))).'.php';});
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Console\MediaProcessingWorkerCommand;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use Aws\S3\S3Client;
use Aws\Result;
use Aws\Exception\AwsException;
use GuzzleHttp\Promise\Create;
function mediaCheck(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);echo "OK: $msg\n";}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','fixture-only','',(int)(getenv('TEST_DB_PORT')?:3306));
$database='media_regression_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$database`");$db->select_db($database);
$dir=sys_get_temp_dir().'/media-regression-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try{
 $schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');preg_match('/CREATE TABLE IF NOT EXISTS `FileS3` \\(.*?;\\s/s',$schema,$m);
 $db->query(str_contains($db->server_info,'MariaDB')?str_replace('utf8mb4_0900_ai_ci','utf8mb4_unicode_ci',$m[0]):$m[0]);
 $outputs=[];
 $s3=new S3Client(['version'=>'latest','region'=>'us-east-1','credentials'=>['key'=>'fixture','secret'=>'fixture'],
 'handler'=>static function($c)use(&$outputs){
  if($c->getName()==='HeadObject')return Create::rejectionFor(new AwsException('absent',$c,['code'=>'NotFound','status_code'=>404]));
  if($c->getName()!=='PutObject')throw new LogicException('Unexpected S3 action');
  $outputs[]=['key'=>$c['Key'],'bytes'=>(string)$c['Body'],'metadata'=>$c['Metadata'],'type'=>$c['ContentType']];
  return Create::promiseFor(new Result(['ETag'=>'"fixture"']));
 }]);
 $app=(new ReflectionClass(DriveApplication::class))->newInstanceWithoutConstructor();
 foreach(['s3'=>$s3,'bucket'=>'fixture','db'=>$db] as $p=>$v)(new ReflectionProperty($app,$p))->setValue($app,$v);
 $jobs=new MediaProcessingJobRepository($db);
 $worker=(new ReflectionClass(MediaProcessingWorkerCommand::class))->newInstanceWithoutConstructor();
 foreach(['app'=>$app,'jobs'=>$jobs,'generated'=>new GeneratedFileRepository($db)]as $p=>$v)(new ReflectionProperty($worker,$p))->setValue($worker,$v);
 $invoke=static fn(string $name,...$args)=>(new ReflectionMethod($worker,$name))->invoke($worker,...$args);
 $source=$dir.'/opaque-input';
 $invoke('runProcess',['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','testsrc=size=160x90:rate=10:duration=12','-f','lavfi','-i','sine=frequency=880:sample_rate=44100:duration=12','-c:v','mpeg4','-g','1','-c:a','aac','-shortest','-f','mp4',$source]);
 $digest=hash_file('sha256',$source);
 $record=['id_'=>1,'_key'=>'Data2/d_opaque/f_opaque','Nombre'=>'Audiencia.mp4','Ruta'=>'Data2/d_opaque/','Tamano'=>filesize($source)];
 $job=$jobs->enqueue(2,$record,'split_video',3,3,3);$job=$jobs->claimNext('fixture');
 $result=$invoke('splitMedia',$job,$source,$dir);
 mediaCheck(count($result)===3,'real worker creates three playable segments');
 foreach($outputs as $i=>$out){
  $start=[0,1,5][$i];$end=[7,11,12][$i];$meta=$out['metadata'];
  mediaCheck((float)$meta['segment_start_seconds']===$start*1.0&&(float)$meta['segment_end_seconds']===$end*1.0,'three seconds before/after each boundary, clamped at source ends');
  $path=$dir.'/verify-'.$i.'.mp4';file_put_contents($path,$out['bytes']);
  mediaCheck(abs($invoke('probeDuration',$path)-($end-$start))<0.35,'segment duration preserves expected interval with frame tolerance');
 }
 $jobs->complete($job['job_id'],$result);
 mediaCheck($jobs->recentForUser(2)[0]['status']==='completed','job completion persisted');
 $outputs=[];$job=$jobs->enqueue(2,$record,'extract_mp3',1,0,0);$job=$jobs->claimNext('fixture');
 $result=$invoke('extractMp3',$job,$source,$dir);$mp3=$dir.'/verify.mp3';file_put_contents($mp3,$outputs[0]['bytes']);
 mediaCheck(count($result)===1&&abs($invoke('probeDuration',$mp3)-12)<0.2,'production MP3 extraction preserves full audio duration');
 mediaCheck(hash_file('sha256',$source)===$digest,'split and MP3 leave source unchanged');
 mediaCheck((int)$db->query('SELECT COUNT(*) n FROM FileS3 WHERE user_id_=2 AND Found=1')->fetch_assoc()['n']===4,'all outputs registered in real catalog');
 try{$invoke('probeDuration',$dir.'/missing-private-name');throw new LogicException('Missing media accepted');}
 catch(RuntimeException $e){mediaCheck(str_contains($e->getMessage(),'ffprobe:')&&str_contains($e->getMessage(),'fallback FFmpeg:'),'both real diagnostics survive failure');}
 $jobs->cancelForUser(2,$job['job_id']);$count=count($outputs);
 try{$invoke('extractMp3',$job,$source,$dir);throw new LogicException('Cancellation ignored');}
 catch(RuntimeException $e){mediaCheck(str_starts_with($e->getMessage(),'[CANCELLED]'),'cancel requested prevents more work');}
 mediaCheck(count($outputs)===$count,'cancelled job publishes nothing else');
}finally{
 foreach(glob($dir.'/*')?:[]as $f)unlink($f);rmdir($dir);$db->query("DROP DATABASE `$database`");
}
