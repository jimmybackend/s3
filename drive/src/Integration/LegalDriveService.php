<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Integration;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\View\FileViewHelper;
use RuntimeException;

final class LegalDriveService
{
    private LegalCatalogRepository $catalog;
    public function __construct(private DriveApplication $app, private LegalApiConfig $config) { $this->catalog = new LegalCatalogRepository($app->db()); }

    public function requireUser(array $grant): int
    {
        if (($grant['scope']??'owner')!=='owner') throw new RuntimeException('La conexión de servicio no permite explorar Drive.',403);
        $id=(int)$grant['user_id'];
        try { $u=$this->app->userDirectoryRepository()->requireById($id); }
        catch (RuntimeException $e) { throw new RuntimeException('Cuenta no autorizada.',403); }
        if ($u['userstatus'] !== 'Activo') throw new RuntimeException('Cuenta no autorizada.',403);
        return $id;
    }
    public function file(int $user, int $id): array
    {
        if ($id<1) throw new RuntimeException('Archivo no disponible.',404);
        try { $row=$this->app->fileRecordRepository()->requireByRef($user,$id,true); }
        catch (RuntimeException $e) { throw new RuntimeException('Archivo no disponible.',404); }
        $this->catalog->requireReadableRoute($user,(string)$row['Ruta'],$this->app->userStoragePath()->rootForUser($user));
        if (FileViewHelper::isLocked($row)) throw new RuntimeException('Archivo protegido.',423);
        return $row;
    }
    public static function metadata(array $row): array
    {
        $ext=strtolower(pathinfo((string)$row['Nombre'],PATHINFO_EXTENSION));
        return ['id'=>(int)$row['id_'],'name'=>(string)$row['Nombre'],'type'=>$ext ?: 'archivo','bytes'=>max(0,(int)$row['Tamano'])];
    }
    public function browse(int $user, int $folder, string $query, int $page, int $folderPage): array
    {
        if (strlen($query)>200 || $page<1 || $page>10000 || $folderPage<1 || $folderPage>10000) throw new RuntimeException('Consulta inválida.',400);
        $root=$this->app->userStoragePath()->rootForUser($user);
        $f=$folder>0 ? $this->catalog->folder($user,$folder) : null;
        $route=$f ? (string)$f['Prefix'] : $root;
        $this->catalog->requireReadableRoute($user,$route,$root);
        $files=$this->catalog->files($user,$route,$query,($page-1)*30);
        $folders=$query==='' ? $this->catalog->children($user,$route,($folderPage-1)*30) : [];
        $more=count($files)>30; $moreFolders=count($folders)>30;
        $items=[];
        foreach (array_slice($files,0,30) as $row) {
            try { $this->file($user,(int)$row['id_']); $items[]=self::metadata($row)+['state'=>'available']; }
            catch (RuntimeException $e) {
                // Keep locked-file names like the existing Drive; hide anything outside authorized hierarchy.
                if ($e->getCode()===423) $items[]=self::metadata($row)+['state'=>'locked'];
            }
        }
        return ['instance'=>$this->config->instance(),'folder'=>$folder,'folder_name'=>$f ? $f['Nombre'] : 'Mis datos','parent_folder'=>$this->catalog->parentId($user,$f,$root),
            'files'=>$items,'folders'=>array_map(static fn($r)=>['id'=>(int)$r['id_'],'name'=>$r['Nombre'],'locked'=>($r['AccessType']??'normal')==='secure'],array_slice($folders,0,30)),
            'more'=>$more,'more_folders'=>$moreFolders];
    }
    public function files(int $user, string $ids): array
    {
        if (!preg_match('/^\d{1,18}(,\d{1,18}){0,29}$/D',$ids)) throw new RuntimeException('Selección inválida.',400);
        $items=[];
        foreach (array_unique(explode(',',$ids)) as $id) {
            try { $items[]=['id'=>(int)$id,'status'=>200,'file'=>self::metadata($this->file($user,(int)$id))]; }
            catch (RuntimeException $e) {
                if (!in_array($e->getCode(),[403,404,423],true)) throw $e;
                $items[]=['id'=>(int)$id,'status'=>$e->getCode()];
            }
        }
        return ['instance'=>$this->config->instance(),'files'=>$items];
    }
    public function share(array $owner,int $file,int $case,string $serviceId): array
    {
        $user=$this->requireUser($owner);$this->file($user,$file);
        $service=$this->config->grant($serviceId);
        if (($service['scope']??'owner')!=='case-service' || $case<1) throw new RuntimeException('Destino no autorizado.',403);
        $ref=(new LegalFileGrantRepository($this->app->db()))->authorize($user,$owner['id'],$file,$serviceId,$case);
        return ['share'=>$ref];
    }
    public function revoke(array $owner,string $ref): array
    {
        (new LegalFileGrantRepository($this->app->db()))->revoke($ref,$this->requireUser($owner));
        return ['revoked'=>true];
    }
    public function sharedFile(array $service,string $ref,int $case): array
    {
        if (($service['scope']??'owner')!=='case-service') throw new RuntimeException('Conexión de servicio requerida.',403);
        $r=(new LegalFileGrantRepository($this->app->db()))->require($ref,$service['id'],$case);
        $owner=$this->config->grant($r['owner_grant_id']);$user=$this->requireUser($owner);
        if ($user!==(int)$r['owner_user_id']) throw new RuntimeException('Autorización retirada.',403);
        return $this->file($user,(int)$r['file_id']);
    }
    public function sharedFiles(array $service,string $refs,int $case): array
    {
        if (($service['scope']??'owner')!=='case-service') throw new RuntimeException('Conexión de servicio requerida.',403);
        if (!preg_match('/^[a-f0-9]{64}(,[a-f0-9]{64}){0,29}$/D',$refs)) throw new RuntimeException('Selección inválida.',400);
        $items=[];
        foreach (array_unique(explode(',',$refs)) as $ref) {
            try { $items[]=['share'=>$ref,'status'=>200,'file'=>self::metadata($this->sharedFile($service,$ref,$case))]; }
            catch (RuntimeException $e) {
                if (!in_array($e->getCode(),[401,403,404,423],true)) throw $e;
                $items[]=['share'=>$ref,'status'=>$e->getCode()];
            }
        }
        return ['files'=>$items];
    }
    public function sharedAccess(array $service,string $ref,int $case,string $mode): array
    {
        $row=$this->sharedFile($service,$ref,$case);
        if (!in_array($mode,['open','download'],true)) throw new RuntimeException('Operación inválida.',400);
        try { $this->app->s3()->headObject(['Bucket'=>$this->app->bucket(),'Key'=>$row['_key']]); }
        catch (\Aws\S3\Exception\S3Exception $e) { throw new RuntimeException('Contenido no disponible.', $e->getStatusCode()===404 ? 404 : 503); }
        return ['ticket'=>$this->config->ticket($service,(int)$row['id_'],$mode,['share'=>$ref,'case'=>$case]),'expires_in'=>120];
    }
    public function access(array $grant, int $file, string $mode): array
    {
        $user=$this->requireUser($grant); $row=$this->file($user,$file);
        if (!in_array($mode,['open','download'],true)) throw new RuntimeException('Operación inválida.',400);
        // Check physical availability before issuing a capability; no S3 URL leaves Drive.
        try { $this->app->s3()->headObject(['Bucket'=>$this->app->bucket(),'Key'=>$row['_key']]); }
        catch (\Aws\S3\Exception\S3Exception $e) { throw new RuntimeException('Contenido no disponible.', $e->getStatusCode()===404 ? 404 : 503); }
        return ['ticket'=>$this->config->ticket($grant,$file,$mode),'expires_in'=>120];
    }
    public function content(array $grant, int $file, string $mode, ?string $range, array $context=[]): array
    {
        $row=($grant['scope']??'owner')==='case-service' ? $this->sharedFile($grant,(string)($context['share']??''),(int)($context['case']??0)) : $this->file($this->requireUser($grant),$file);
        if ((int)$row['id_']!==$file) throw new RuntimeException('Archivo no disponible.',404);
        $args=['Bucket'=>$this->app->bucket(),'Key'=>$row['_key']];
        try {
            $head=$this->app->s3()->headObject($args); $size=(int)$head['ContentLength'];
            $start=0; $end=$size-1; $partial=false;
            if ($range !== null && $range !== '') {
                if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m) || ($m[1]==='' && $m[2]==='') || $size===0) throw new RuntimeException('Rango inválido.',416);
                if ($m[1]==='') { $start=max(0,$size-(int)$m[2]); }
                else { $start=(int)$m[1]; if ($m[2]!=='') $end=min($end,(int)$m[2]); }
                if ($start>$end || $start<0) throw new RuntimeException('Rango inválido.',416);
                $args['Range']='bytes='.$start.'-'.$end; $partial=true;
            }
            $object=$this->app->s3()->getObject($args+['@http'=>['stream'=>true]]);
        } catch (\Aws\S3\Exception\S3Exception $e) { throw new RuntimeException('Contenido no disponible.', $e->getStatusCode()===404 ? 404 : 503); }
        $name=preg_replace('/[\x00-\x1f\x7f\/\\\\]/u','_', (string)$row['Nombre']) ?: 'archivo';
        $mime=trim((string)($head['ContentType']??''));
        $safe=['application/pdf','image/jpeg','image/png','image/webp','image/gif','image/avif','audio/mpeg','audio/ogg','audio/wav','audio/mp4','video/mp4','video/webm','text/plain'];
        $inline=$mode==='open' && in_array($mime,$safe,true);
        return ['body'=>$object['Body'],'size'=>$size,'start'=>$start,'end'=>$end,'partial'=>$partial,'mime'=>$inline ? $mime : 'application/octet-stream',
            'disposition'=>($inline ? 'inline' : 'attachment')."; filename=\"archivo\"; filename*=UTF-8''".rawurlencode($name)];
    }
}

