<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use mysqli;
use RuntimeException;

final class GeneratedFileRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function upsert(int $userId, string $name, string $key, int $size, array $metadata, string $route): string
    {
        $json=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('No se pudieron serializar metadatos del archivo generado.');
        $stmt=$this->db->prepare('SELECT id_ FROM FileS3 WHERE user_id_=? AND Encriptado=? LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo comprobar el archivo generado.');
        $stmt->bind_param('is',$userId,$key); $stmt->execute(); $res=$stmt->get_result();
        $row=$res?$res->fetch_assoc():null; $stmt->close();
        if ($row) {
            $id=(int)$row['id_'];
            $stmt=$this->db->prepare("UPDATE FileS3 SET Nombre=?,Tamano=?,Metadatos=?,Ruta=?,Found=1 WHERE id_=? AND user_id_=?");
            if (!$stmt) throw new RuntimeException('No se pudo actualizar FileS3.');
            $stmt->bind_param('sissii',$name,$size,$json,$route,$id,$userId); $stmt->execute(); $stmt->close();
            return 'actualizado';
        }
        $stmt=$this->db->prepare("INSERT INTO FileS3 (Nombre,Encriptado,Tamano,Metadatos,Ruta,Found,AccessType,user_id_) VALUES (?,?,?,?,?,1,'normal',?)");
        if (!$stmt) throw new RuntimeException('No se pudo insertar FileS3.');
        $stmt->bind_param('ssissi',$name,$key,$size,$json,$route,$userId); $stmt->execute(); $stmt->close();
        return 'insertado';
    }
}
