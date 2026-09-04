from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]; DRIVE=ROOT/'drive'; SRC=DRIVE/'src'
def write(p,c): p.parent.mkdir(parents=True,exist_ok=True); p.write_text(c,encoding='utf-8')

write(SRC/'Sync/SyncRepository.php',r'''<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Sync;
use mysqli;
use RuntimeException;

final class SyncRepository
{
    public function __construct(private mysqli $db) {}
    public function begin(): void {$this->db->begin_transaction();}
    public function commit(): void {$this->db->commit();}
    public function rollback(): void {try{$this->db->rollback();}catch(\Throwable){}}
    public function resetFound(int $userId): void {$this->exec('UPDATE FileS3 SET Found=0 WHERE user_id_=?',[$userId],'i');$this->exec('UPDATE S3Folders SET Found=0 WHERE user_id_=?',[$userId],'i');}
    public function purgeMissing(int $userId): void {$this->exec('DELETE FROM FileS3 WHERE user_id_=? AND Found=0',[$userId],'i');$this->exec('DELETE FROM S3Folders WHERE user_id_=? AND Found=0',[$userId],'i');}

    public function upsertFolder(int $userId,string $prefix,string $name,?string $parent): void
    {
        $row=$this->one('SELECT id_ FROM S3Folders WHERE user_id_=? AND Prefix=? LIMIT 1',[$userId,$prefix],'is');
        if($row){$id=(int)$row['id_'];$this->exec('UPDATE S3Folders SET Found=1,Nombre=?,ParentPrefix=?,UpdatedAt=NOW() WHERE id_=? AND user_id_=?',[$name,$parent,$id,$userId],'ssii');return;}
        $this->exec("INSERT INTO S3Folders (user_id_,Prefix,Nombre,ParentPrefix,Found,AccessType,CreatedAt,UpdatedAt) VALUES (?,?,?,?,1,'normal',NOW(),NOW())",[$userId,$prefix,$name,$parent],'isss');
    }

    public function upsertFile(int $userId,string $key,int $size): void
    {
        $pos=strrpos($key,'/');$dir=$pos===false?'':substr($key,0,$pos+1);$dir=$dir!==''?rtrim($dir,'/').'/':'';$base=$pos===false?$key:substr($key,$pos+1);
        $row=$this->one('SELECT id_ FROM FileS3 WHERE user_id_=? AND Encriptado=? LIMIT 1',[$userId,$key],'is');
        if(!$row){$legacy=$this->all("SELECT id_,Encriptado FROM FileS3 WHERE user_id_=? AND Ruta=? AND Found=0 AND (Encriptado=? OR Encriptado LIKE ? ESCAPE '!') ORDER BY id_ ASC LIMIT 2",[$userId,$dir,$base,'%/'.$this->likeEscape($base)],'isss');if(count($legacy)===1)$row=$legacy[0];}
        if($row){$id=(int)$row['id_'];$this->exec("UPDATE FileS3 SET Encriptado=?,Tamano=?,Ruta=?,Nombre=IF(Nombre IS NULL OR Nombre='',?,Nombre),Found=1 WHERE id_=? AND user_id_=?",[$key,$size,$dir,$base,$id,$userId],'sissii');return;}
        $this->exec("INSERT INTO FileS3 (Nombre,Encriptado,Tamano,Metadatos,Ruta,Found,AccessType,Fecha,user_id_) VALUES (?,?,?,NULL,?,1,'normal',NOW(),?)",[$base,$key,$size,$dir,$userId],'ssisi');
    }

    public function status(int $userId): array
    {
        return ['files_total'=>$this->scalar('SELECT COUNT(*) FROM FileS3 WHERE user_id_=?',[$userId],'i'),'files_found'=>$this->scalar('SELECT COUNT(*) FROM FileS3 WHERE user_id_=? AND Found=1',[$userId],'i'),'folders_total'=>$this->scalar('SELECT COUNT(*) FROM S3Folders WHERE user_id_=?',[$userId],'i'),'folders_found'=>$this->scalar('SELECT COUNT(*) FROM S3Folders WHERE user_id_=? AND Found=1',[$userId],'i'),'bytes_total'=>$this->scalar('SELECT COALESCE(SUM(Tamano),0) FROM FileS3 WHERE user_id_=? AND Found=1',[$userId],'i')];
    }

    private function exec(string $sql,array $bind=[],string $types=''): void {$stmt=$this->prepare($sql);if($bind)$stmt->bind_param($types,...$bind);if(!$stmt->execute()){$e=$stmt->error;$stmt->close();throw new RuntimeException($e);} $stmt->close();}
    private function one(string $sql,array $bind=[],string $types=''): ?array {$rows=$this->all($sql,$bind,$types);return$rows[0]??null;}
    private function all(string $sql,array $bind=[],string $types=''): array {$stmt=$this->prepare($sql);if($bind)$stmt->bind_param($types,...$bind);if(!$stmt->execute()){$e=$stmt->error;$stmt->close();throw new RuntimeException($e);}$res=$stmt->get_result();$rows=[];while($res&&($row=$res->fetch_assoc()))$rows[]=$row;$stmt->close();return$rows;}
    private function scalar(string $sql,array $bind=[],string $types=''): int {$stmt=$this->prepare($sql);if($bind)$stmt->bind_param($types,...$bind);$stmt->execute();$stmt->bind_result($v);$stmt->fetch();$stmt->close();return(int)($v??0);}
    private function prepare(string $sql): \mysqli_stmt {$stmt=$this->db->prepare($sql);if(!$stmt)throw new RuntimeException('SQL prepare failed: '.$this->db->error);return$stmt;}
    private function likeEscape(string $v): string{return str_replace(['!','%','_'],['!!','!%','!_'],$v);}
}
''')

write(SRC/'Sync/S3SyncService.php',r'''<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Sync;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\S3\S3Client;

final class S3SyncService
{
    public function __construct(private SyncRepository $repository,private S3Client $s3,private string $bucket,private UserStoragePath $paths,private int $pageDelayUs=150000){}
    public function synchronize(int $userId): array
    {
        $base=$this->paths->rootForUser($userId);$this->s3->headBucket(['Bucket'=>$this->bucket]);$folders=[];$files=0;
        $this->repository->begin();
        try{
            $this->repository->resetFound($userId);$this->addFolder($folders,$base);
            $params=['Bucket'=>$this->bucket,'Prefix'=>$base,'MaxKeys'=>1000];
            do{
                $res=$this->s3->listObjectsV2($params);
                foreach((array)($res['Contents']??[]) as $object){$key=(string)($object['Key']??'');if($key==='')continue;if(str_ends_with($key,'/')){$this->addFolder($folders,$key);continue;}$this->addParentFolders($folders,$key);$this->repository->upsertFile($userId,$key,(int)($object['Size']??0));$files++;}
                if(!empty($res['IsTruncated'])&&!empty($res['NextContinuationToken']))$params['ContinuationToken']=$res['NextContinuationToken'];else unset($params['ContinuationToken']);
                if($this->pageDelayUs>0)usleep($this->pageDelayUs);
            }while(!empty($res['IsTruncated']));
            foreach($folders as $prefix=>$info)$this->repository->upsertFolder($userId,$prefix,$info['name'],$info['parent']);
            $this->repository->purgeMissing($userId);$this->repository->commit();
            return ['ok'=>true,'user_id'=>$userId,'bucket'=>$this->bucket,'base'=>$base,'files_upserted'=>$files,'folders_upserted'=>count($folders)];
        }catch(\Throwable $e){$this->repository->rollback();throw$e;}
    }
    private function addParentFolders(array &$folders,string $key): void {$dir=dirname($key);if($dir==='.'||$dir==='')return;$acc='';foreach(explode('/',$dir) as $part){if($part==='')continue;$acc.=$part.'/';$this->addFolder($folders,$acc);}}
    private function addFolder(array &$folders,string $prefix): void {$prefix=rtrim($prefix,'/').'/';if($prefix==='./')return;$trim=rtrim($prefix,'/');$pos=strrpos($trim,'/');$name=$pos===false?$trim:substr($trim,$pos+1);$parent=$pos===false?null:rtrim(substr($trim,0,$pos+1),'/').'/';if($parent==='/'||$parent==='')$parent=null;$folders[$prefix]=['name'=>$name!==''?$name:$prefix,'parent'=>$parent];}
}
''')

write(SRC/'View/SyncStatusRenderer.php',r'''<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\View;
final class SyncStatusRenderer
{
    public function render(array $status,bool $loading): string
    {
        $filesFound=number_format((int)$status['files_found']);$filesTotal=number_format((int)$status['files_total']);$foldersFound=number_format((int)$status['folders_found']);$foldersTotal=number_format((int)$status['folders_total']);$bytes=number_format((int)$status['bytes_total']);
        $head=$loading?'<span class="sync-spinner" aria-hidden="true"></span><strong>Sincronizando…</strong>':'<i class="fas fa-check-circle" aria-hidden="true"></i><strong>Estatus actualizado</strong>';
        $body=$loading?'Comparando S3 y la base de datos. Por favor espera…':"Archivos: <b>{$filesFound}</b> / {$filesTotal} encontrados · Carpetas: <b>{$foldersFound}</b> / {$foldersTotal} · Tamaño total: <b>{$bytes}</b> bytes";
        $class=$loading?'sync-loading':'sync-success';return '<div class="sync-box '.$class.' sync-green" role="status" style="margin:0"><div class="sync-line">'.$head.'</div><div class="sync-text">'.$body.'</div></div>';
    }
}
''')

write(SRC/'Http/Controller/SyncController.php',r'''<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Http\Controller;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Sync\S3SyncService;
use ArcadeCloud\Drive\Sync\SyncRepository;
use ArcadeCloud\Drive\View\SyncStatusRenderer;

final class SyncController extends AbstractJsonController
{
    public function run(): never
    {
        try{$uid=$this->guardAuthenticated();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();ignore_user_abort(true);@set_time_limit(0);$service=new S3SyncService(new SyncRepository($this->app->db()),$this->app->s3(),$this->app->bucket(),$this->app->userStoragePath());JsonResponse::send($service->synchronize($uid));}
        catch(\Throwable $e){JsonResponse::send(['ok'=>false,'step'=>'sync','error'=>$e->getMessage()],500);}
    }
    public function status(): never
    {
        try{$uid=$this->guardAuthenticated();$status=(new SyncRepository($this->app->db()))->status($uid);if($this->request->queryString('view')==='html'){header('Content-Type: text/html; charset=utf-8');echo(new SyncStatusRenderer())->render($status,$this->request->queryString('loading')==='1');exit;}JsonResponse::send(['ok'=>true,'user_id'=>$uid]+$status);}
        catch(\Throwable $e){JsonResponse::send(['ok'=>false,'error'=>$e->getMessage()],500);}
    }
}
''')
for filename,method in {'sync_s3_to_db.php':'run','sync_status.php':'status'}.items():write(DRIVE/filename,f'''<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/app_bootstrap.php';\n(new \\ArcadeCloud\\Drive\\Http\\Controller\\SyncController(\\ArcadeCloud\\Drive\\Core\\ApplicationKernel::app(),\\ArcadeCloud\\Drive\\Http\\Request::fromGlobals()))->{method}();\n''')
print('Sync migrated')
