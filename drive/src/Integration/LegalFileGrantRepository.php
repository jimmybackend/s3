<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Integration;
use mysqli;
use RuntimeException;

/** Explicit file/case delegation. Schema is installed only by the SQL migration. */
final class LegalFileGrantRepository
{
    public function __construct(private mysqli $db) {}
    public function authorize(int $owner, string $ownerGrant, int $file, string $service, int $case): string
    {
        $ref=bin2hex(random_bytes(32));
        $s=$this->db->prepare('INSERT INTO legal_file_grants (authorization_ref,owner_user_id,owner_grant_id,file_id,service_grant_id,legal_case_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE authorization_ref=VALUES(authorization_ref),owner_grant_id=VALUES(owner_grant_id),revoked_at=NULL,authorized_at=CURRENT_TIMESTAMP');
        $s->bind_param('sisisi',$ref,$owner,$ownerGrant,$file,$service,$case);$s->execute();
        return $ref;
    }
    public function require(string $ref,string $service,int $case): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$ref) || $case<1) throw new RuntimeException('Autorización no disponible.',404);
        $s=$this->db->prepare('SELECT * FROM legal_file_grants WHERE authorization_ref=? AND service_grant_id=? AND legal_case_id=? AND revoked_at IS NULL LIMIT 1');
        $s->bind_param('ssi',$ref,$service,$case);$s->execute();$r=$s->get_result()->fetch_assoc();
        if (!$r) throw new RuntimeException('Autorización no disponible.',404);
        return $r;
    }
    public function revoke(string $ref,int $owner): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$ref)) throw new RuntimeException('Autorización no disponible.',404);
        $s=$this->db->prepare('UPDATE legal_file_grants SET revoked_at=CURRENT_TIMESTAMP WHERE authorization_ref=? AND owner_user_id=?');
        $s->bind_param('si',$ref,$owner);$s->execute();
        if ($s->affected_rows<1) throw new RuntimeException('Autorización no disponible.',404);
    }
}
