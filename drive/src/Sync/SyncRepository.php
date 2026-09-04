<?php
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
        if($row){$id=(int)$row['id_'];$this->exec('UPDATE S3Folders SET Found=1,ParentPrefix=?,UpdatedAt=NOW() WHERE id_=? AND user_id_=?',[$parent,$id,$userId],'sii');return;}
        $this->exec("INSERT INTO S3Folders (user_id_,Prefix,Nombre,ParentPrefix,Found,AccessType,CreatedAt,UpdatedAt) VALUES (?,?,?,?,1,'normal',NOW(),NOW())",[$userId,$prefix,$name,$parent],'isss');
    }

    public function upsertFile(int $userId,string $key,int $size,?string $recoveredName=null): void
    {
        $pos=strrpos($key,'/');$dir=$pos===false?'':substr($key,0,$pos+1);$dir=$dir!==''?rtrim($dir,'/').'/':'';$base=$pos===false?$key:substr($key,$pos+1);$visible=trim((string)$recoveredName);if($visible==='')$visible=$base;
        $row=$this->one('SELECT id_ FROM FileS3 WHERE user_id_=? AND Encriptado=? LIMIT 1',[$userId,$key],'is');
        if(!$row){$legacy=$this->all("SELECT id_,Encriptado FROM FileS3 WHERE user_id_=? AND Ruta=? AND Found=0 AND (Encriptado=? OR Encriptado LIKE ? ESCAPE '!') ORDER BY id_ ASC LIMIT 2",[$userId,$dir,$base,'%/'.$this->likeEscape($base)],'isss');if(count($legacy)===1)$row=$legacy[0];}
        if($row){$id=(int)$row['id_'];$this->exec("UPDATE FileS3 SET Encriptado=?,Tamano=?,Ruta=?,Nombre=IF(Nombre IS NULL OR Nombre='',?,Nombre),Found=1 WHERE id_=? AND user_id_=?",[$key,$size,$dir,$visible,$id,$userId],'sissii');return;}
        $this->exec("INSERT INTO FileS3 (Nombre,Encriptado,Tamano,Metadatos,Ruta,Found,AccessType,Fecha,user_id_) VALUES (?,?,?,NULL,?,1,'normal',NOW(),?)",[$visible,$key,$size,$dir,$userId],'ssisi');
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
