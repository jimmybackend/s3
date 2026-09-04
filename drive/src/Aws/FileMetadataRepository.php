<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use mysqli;
use RuntimeException;

final class FileMetadataRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function merge(int $userId, int $fileId, string $section, array $payload): void
    {
        $stmt=$this->db->prepare('SELECT Metadatos FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo leer metadatos: '.$this->db->error);
        $stmt->bind_param('ii',$fileId,$userId); $stmt->execute(); $res=$stmt->get_result();
        $row=$res?$res->fetch_assoc():null; $stmt->close();
        if (!$row) throw new RuntimeException('Archivo no encontrado para guardar metadatos.');
        $meta=[]; $raw=trim((string)($row['Metadatos']??''));
        if ($raw!=='') { $decoded=json_decode($raw,true); if (is_array($decoded)) $meta=$decoded; }
        $payload['ts']=$payload['ts']??date('c'); $meta[$section]=$payload;
        $json=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('No se pudieron serializar metadatos.');
        $stmt=$this->db->prepare('UPDATE FileS3 SET Metadatos=? WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo actualizar metadatos: '.$this->db->error);
        $stmt->bind_param('sii',$json,$fileId,$userId); $stmt->execute(); $stmt->close();
    }
}
