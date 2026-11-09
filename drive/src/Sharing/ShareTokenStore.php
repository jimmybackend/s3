<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sharing;

use DateTimeImmutable;
use Throwable;

final class ShareTokenStore
{
    public function __construct(private string $path)
    {
    }

    public function create(array $payload, ?string $replaceBridgeToken=null): string
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new ShareException('No se pudo abrir el almacén de enlaces compartidos.', 500);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new ShareException('No se pudo bloquear el almacén de enlaces compartidos.', 500);
            }

            $tokens = $this->withoutExpired($this->readFromHandle($handle));

            if ($replaceBridgeToken!==null) {
                if (!preg_match('/^[a-f0-9]{32}$/D',$replaceBridgeToken) || ($payload['legal_bridge']??false)!==true) throw new ShareException('Renovación inválida.',400);
                $previous=$tokens[$replaceBridgeToken]??null;
                if ($previous!==null && (!is_array($previous) || ($previous['legal_bridge']??false)!==true || (int)($previous['user_id']??0)!==(int)$payload['user_id'] || (int)($previous['file_id']??0)!==(int)$payload['file_id'])) throw new ShareException('No puede renovar ese enlace.',403);
                unset($tokens[$replaceBridgeToken]);
            }
            do {
                $token = bin2hex(random_bytes(16));
            } while (isset($tokens[$token]));

            $tokens[$token] = $payload;
            $this->writeToHandle($handle, $tokens);
            flock($handle, LOCK_UN);

            return $token;
        } finally {
            fclose($handle);
        }
    }

    public function find(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !is_file($this->path)) {
            return null;
        }

        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            throw new ShareException('No se pudo leer el almacén de enlaces compartidos.', 500);
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new ShareException('No se pudo leer el almacén de enlaces compartidos.', 500);
            }

            $tokens = $this->readFromHandle($handle);
            flock($handle, LOCK_UN);

            $value = $tokens[$token] ?? null;
            return is_array($value) ? $value : null;
        } finally {
            fclose($handle);
        }
    }

    public function revokeBridge(string $token,int $owner): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$token) || $owner<1) throw new ShareException('Enlace no disponible.',404);
        $h=@fopen($this->path,'r+');
        if ($h===false) throw new ShareException('Enlace no disponible.',404);
        try {
            if (!flock($h,LOCK_EX)) throw new ShareException('No se pudo retirar el enlace.',503);
            $all=$this->readFromHandle($h);$p=$all[$token]??null;
            if (!is_array($p) || ($p['legal_bridge']??false)!==true || (int)($p['user_id']??0)!==$owner) throw new ShareException('Enlace no disponible.',404);
            unset($all[$token]);$this->writeToHandle($h,$all);
        } finally { flock($h,LOCK_UN);fclose($h); }
    }

    private function readFromHandle($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ShareException(
                'El almacén de enlaces compartidos contiene JSON inválido; no se sobrescribió.',
                500
            );
        }

        return $decoded;
    }

    private function writeToHandle($handle, array $tokens): void
    {
        $json = json_encode(
            $tokens,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new ShareException('No se pudo codificar el almacén de enlaces compartidos.', 500);
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new ShareException('No se pudo actualizar el almacén de enlaces compartidos.', 500);
        }

        if (fwrite($handle, $json) === false || !fflush($handle)) {
            throw new ShareException('No se pudo guardar el enlace compartido.', 500);
        }
    }

    private function withoutExpired(array $tokens): array
    {
        $now = new DateTimeImmutable('now');

        foreach ($tokens as $token => $payload) {
            if (!is_array($payload) || empty($payload['expira'])) {
                continue;
            }

            try {
                $expires = new DateTimeImmutable((string)$payload['expira']);
                if ($now > $expires) {
                    unset($tokens[$token]);
                }
            } catch (Throwable $e) {
                // Mantener tokens legacy con fecha no interpretable para no romper compatibilidad.
            }
        }

        return $tokens;
    }
}
