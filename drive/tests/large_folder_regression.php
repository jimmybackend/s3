<?php
declare(strict_types=1);
if(getenv('ARCADECLOUD_ISOLATED_TEST')!=='1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__).'/src/Application/FileListService.php';
require_once dirname(__DIR__).'/src/Application/FileSearchService.php';
require_once dirname(__DIR__).'/src/View/FileViewHelper.php';
use ArcadeCloud\Drive\Application\FileListService;
use ArcadeCloud\Drive\Application\FileSearchService;
function folderCheck(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);echo "OK: $msg\n";}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','fixture-only','',(int)(getenv('TEST_DB_PORT')?:3306));
$database='large_folder_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$database`");$db->select_db($database);
try{
 $schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');preg_match('/CREATE TABLE IF NOT EXISTS `FileS3` \\(.*?;\\s/s',$schema,$m);
 $db->query(str_contains($db->server_info,'MariaDB')?str_replace('utf8mb4_0900_ai_ci','utf8mb4_unicode_ci',$m[0]):$m[0]);
 $db->begin_transaction();$s=$db->prepare("INSERT INTO FileS3(Nombre,Encriptado,Tamano,Ruta,Found,user_id_) VALUES (?,?,1024,'Data2/d_fixture/',1,2)");
 for($i=1;$i<=10000;$i++){$name='Informe '.$i.'.txt';$key='f_'.$i;$s->bind_param('ss',$name,$key);$s->execute();}
 $s->close();$db->commit();
 $count=static fn():int=>(int)$db->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch_assoc()['Value'];
 $service=new FileListService($db);$times=[];
 foreach([1,167,334,999999]as $page){
  $before=$count();$start=hrtime(true);
  $r=$service->load(2,'Data2/d_fixture/',['pagina'=>$page,'limite'=>FileListService::WEB_OS_PAGE_SIZE]);
  $times[]=round((hrtime(true)-$start)/1e6,2);
  folderCheck($count()-$before===2,'pagination uses two SQL executions independent of folder size');
  folderCheck($r['total']===10000&&$r['pages']===334&&$r['limit']===30,'page count derives from catalog and 30-item limit');
  folderCheck($r['page']===min($page,334)&&count($r['rows'])===($page>=334?10:30),'last and out-of-range pages are bounded correctly');
 }
 $r=$service->load(3,'Data2/d_fixture/',['limite'=>30]);folderCheck($r['total']===0&&$r['rows']===[],'another user cannot list large folder');
 $found=(new FileSearchService($db))->search(2,'Informe*',200);folderCheck(count($found)===200,'search result count stays bounded');
 echo 'Fixture milliseconds (not production benchmark): '.json_encode($times)."\n";
 echo 'PHP peak bytes: '.memory_get_peak_usage(true)."\n";
}finally{$db->query("DROP DATABASE `$database`");}
