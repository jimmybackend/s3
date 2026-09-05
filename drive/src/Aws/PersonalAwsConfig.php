<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use RuntimeException;

final class PersonalAwsConfig
{
    private ?array $data = null;

    public function __construct(private string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isConfigured(): bool
    {
        return is_file($this->path) && is_readable($this->path);
    }

    public function passwordHash(): string
    {
        return trim((string)($this->load()['password_hash'] ?? ''));
    }

    public function actionPasswordHash(): string
    {
        return trim((string)($this->load()['action_password_hash'] ?? ''));
    }

    public function issuer(): string
    {
        $issuer = trim((string)($this->load()['issuer'] ?? 'ArcadeCloud'));
        return $issuer !== '' ? $issuer : 'ArcadeCloud';
    }

    /**
     * Devuelve sólo identificadores y etiquetas públicas para renderizar el selector.
     * Nunca expone la semilla TOTP.
     *
     * @return array<int,array{id:string,label:string}>
     */
    public function publicAccounts(): array
    {
        $out = [];
        foreach ($this->accounts() as $id => $account) {
            $out[] = [
                'id' => $id,
                'label' => (string)$account['label'],
            ];
        }
        return $out;
    }

    /** @return array{label:string,secret:string,note:string}|null */
    public function account(string $id): ?array
    {
        $accounts = $this->accounts();
        return $accounts[$id] ?? null;
    }

    /** @return array<string,array{label:string,secret:string,note:string}> */
    private function accounts(): array
    {
        $raw = $this->load()['accounts'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $id => $account) {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9._-]{1,80}$/', $id) || !is_array($account)) {
                continue;
            }

            $label = trim((string)($account['label'] ?? ''));
            $secret = preg_replace('/\s+/', '', (string)($account['secret'] ?? '')) ?? '';
            $note = trim((string)($account['note'] ?? ''));

            if ($label === '' || $secret === '') {
                continue;
            }

            $out[$id] = [
                'label' => $label,
                'secret' => $secret,
                'note' => $note,
            ];
        }

        return $out;
    }

    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!$this->isConfigured()) {
            return $this->data = [];
        }

        $json = file_get_contents($this->path);
        if ($json === false) {
            throw new RuntimeException('No se pudo leer la configuración privada de AWS personal.');
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('La configuración privada de AWS personal no contiene JSON válido.');
        }

        return $this->data = $decoded;
    }
}
