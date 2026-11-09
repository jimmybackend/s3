<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Integration;

use RuntimeException;

/** Private, explicitly provisioned per-Legal-account grants. No email matching. */
final class LegalApiConfig
{
    public function __construct(private array $config) {}

    public static function load(): self
    {
        $path = getenv('ARCADECLOUD_LEGAL_API_CONFIG') ?: '/etc/arcadecloud-drive/legal-api.json';
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Integración no configurada.', 503);
        $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Configuración inválida.', 503);
        return new self($data);
    }

    public function instance(): string
    {
        $id = $this->config['instance_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $id)) throw new RuntimeException('Instalación sin identidad.', 503);
        return $id;
    }

    public function authenticate(string $authorization): array
    {
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $m)) throw new RuntimeException('Credencial requerida.', 401);
        $hash = hash('sha256', $m[1]);
        foreach (($this->config['grants'] ?? []) as $id => $grant) {
            if (is_array($grant) && is_string($grant['token_hash'] ?? null) && hash_equals($grant['token_hash'], $hash)) return $this->grant((string)$id);
        }
        throw new RuntimeException('Credencial inválida.', 401);
    }

    public function grant(string $id): array
    {
        $g = $this->config['grants'][$id] ?? null;
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) || !is_array($g)
            || ($g['enabled'] ?? false) !== true
            || !in_array($g['scope']??'owner',['owner','case-service'],true)
            || (($g['scope']??'owner')==='owner' && (!is_int($g['user_id']??null) || $g['user_id']<1))
            || (($g['scope']??'owner')==='case-service' && isset($g['user_id']))
            || !is_int($g['expires_at'] ?? null) || $g['expires_at'] <= time()
            || !is_string($g['token_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $g['token_hash'])) {
            throw new RuntimeException('Autorización revocada o vencida.', 401);
        }
        return array_merge($g, ['id' => $id]);
    }

    /** Short-lived opaque capability; revocation/expiry/account/file are rechecked on redemption. */
    public function ticket(array $grant, int $fileId, string $mode, array $context=[]): string
    {
        $iv = random_bytes(12); $tag = '';
        $json = json_encode(['file' => $fileId, 'mode' => $mode, 'exp' => time()+120, 'instance' => $this->instance()]+$context, JSON_THROW_ON_ERROR);
        $cipher = openssl_encrypt($json, 'aes-256-gcm', $this->key($grant), OPENSSL_RAW_DATA, $iv, $tag, $grant['id']);
        if ($cipher === false) throw new RuntimeException('No se pudo emitir acceso.', 503);
        return $grant['id'] . '.' . rtrim(strtr(base64_encode($iv.$tag.$cipher), '+/', '-_'), '=');
    }

    public function redeem(string $ticket): array
    {
        if (strlen($ticket) > 1024 || !preg_match('/^([a-zA-Z0-9_-]{1,64})\.([a-zA-Z0-9_-]+)$/D', $ticket, $m)) throw new RuntimeException('Enlace inválido.', 401);
        $g = $this->grant($m[1]);
        $raw = base64_decode(strtr($m[2], '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Enlace inválido.', 401);
        $json = openssl_decrypt(substr($raw,28), 'aes-256-gcm', $this->key($g), OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16), $m[1]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p) || !is_int($p['exp'] ?? null) || $p['exp'] <= time() || $p['exp'] > time()+120
            || ($p['instance'] ?? '') !== $this->instance() || !is_int($p['file'] ?? null) || $p['file'] < 1
            || !in_array($p['mode'] ?? '', ['open','download'], true)) throw new RuntimeException('Enlace vencido o inválido.', 401);
        $shared=($g['scope']??'owner')==='case-service';
        if ($shared && (!is_string($p['share']??null) || !preg_match('/^[a-f0-9]{64}$/D',$p['share']) || !is_int($p['case']??null) || $p['case']<1)) throw new RuntimeException('Enlace inválido.',401);
        return ['grant'=>$g, 'file'=>$p['file'], 'mode'=>$p['mode'],'context'=>$shared?['share'=>$p['share'],'case'=>$p['case']]:[]];
    }

    private function key(array $grant): string { return hash('sha256', "legal-content-v1\0".$grant['token_hash'], true); }
}

