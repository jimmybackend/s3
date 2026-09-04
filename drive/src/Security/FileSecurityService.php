<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use RuntimeException;

final class FileSecurityService
{
    public function __construct(
        private FileSecurityRepository $repository,
        private SessionManager $session
    ) {
    }

    public function setMode(int $userId, array $keys, string $mode, string $password = '', string $hint = ''): array
    {
        if (!in_array($mode, ['secure', 'unlocked', 'normal'], true)) {
            throw new RuntimeException('Modo inválido');
        }
        $keys = $this->normalizeKeys($keys);
        if (!$keys) {
            throw new RuntimeException('Sin archivos seleccionados');
        }
        if ($mode === 'secure') {
            $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
            if ($length < 4 || $length > 100) {
                throw new RuntimeException('La contraseña debe tener entre 4 y 100 caracteres');
            }
        }

        $ok = 0;
        $errors = [];
        $hash = $mode === 'secure' ? password_hash($password, PASSWORD_DEFAULT) : '';

        foreach ($keys as $key) {
            try {
                $row = $this->repository->findByKey($userId, $key);
                if (!$row) {
                    throw new RuntimeException("No existe el archivo: {$key}");
                }
                $id = (int)$row['id_'];
                if ($mode === 'secure') {
                    $this->repository->setSecure($userId, $id, $hash, $hint);
                } elseif ($mode === 'unlocked') {
                    $this->repository->setUnlocked($userId, $id);
                } else {
                    $this->repository->setNormal($userId, $id);
                }
                $this->clearSessionState($row, $key);
                $ok++;
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }

        $message = match ($mode) {
            'secure' => 'Archivo(s) bloqueado(s) correctamente',
            'unlocked' => 'Archivo(s) desbloqueado(s) correctamente',
            default => 'Seguridad eliminada correctamente',
        };

        return [
            'ok' => $ok > 0,
            'ok_count' => $ok,
            'fail_count' => count($errors),
            'errors' => $errors,
            'mode' => $mode,
            'msg' => $ok > 0 ? $message : 'No se actualizó ningún archivo',
        ];
    }

    public function unlock(int $userId, string $key, string $password): array
    {
        $key = $this->normalizeKey($key);
        if ($key === '' || $password === '') {
            throw new RuntimeException('Parámetros incompletos');
        }
        $row = $this->repository->findByKey($userId, $key, 'secure');
        if (!$row) {
            throw new RuntimeException('Archivo no encontrado');
        }
        $hash = (string)($row['PasswordHash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return [
                'ok' => false,
                'msg' => 'Contraseña inválida',
                'secure_hint' => (string)($row['SecureHint'] ?? ''),
            ];
        }
        $this->repository->setUnlocked($userId, (int)$row['id_']);
        $this->clearSessionState($row, $key);
        return [
            'ok' => true,
            'msg' => 'Archivo desbloqueado correctamente',
            'key' => $key,
            'access_type' => 'unlocked',
            'secure_hint' => (string)($row['SecureHint'] ?? ''),
        ];
    }

    public function relock(int $userId, array $keys): array
    {
        $keys = $this->normalizeKeys($keys);
        if (!$keys) {
            throw new RuntimeException('key(s) requeridas');
        }
        $ok = 0;
        $errors = [];
        foreach ($keys as $key) {
            try {
                $row = $this->repository->findByKey($userId, $key);
                if (!$row) {
                    throw new RuntimeException("No existe el archivo: {$key}");
                }
                if (trim((string)($row['PasswordHash'] ?? '')) === '') {
                    throw new RuntimeException("El archivo no tiene contraseña configurada: {$key}");
                }
                $this->repository->setSecure($userId, (int)$row['id_'], (string)$row['PasswordHash'], (string)($row['SecureHint'] ?? ''));
                $this->clearSessionState($row, $key);
                $ok++;
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }
        return [
            'ok' => $ok > 0,
            'ok_count' => $ok,
            'fail_count' => count($errors),
            'errors' => $errors,
            'access_type' => 'secure',
            'msg' => $ok > 0 ? 'Archivo(s) bloqueado(s) correctamente' : 'No se bloqueó ningún archivo',
        ];
    }

    public function buildKey(string $key, string $route = '', string $encrypted = ''): string
    {
        if (trim($key) !== '') {
            return $this->normalizeKey($key);
        }
        if (trim($encrypted) === '') {
            return '';
        }
        $encrypted = $this->normalizeKey($encrypted);
        if ($route !== '' && !str_contains($encrypted, '/')) {
            return $this->normalizeKey($route . $encrypted);
        }
        return $encrypted;
    }

    public function normalizeKeys(array $keys): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn($key): string => $this->normalizeKey((string)$key),
            $keys
        ))));
    }

    private function clearSessionState(array $row, string $receivedKey): void
    {
        $encrypted = $this->normalizeKey((string)($row['Encriptado'] ?? ''));
        $route = $this->normalizeRoute((string)($row['Ruta'] ?? ''));
        $this->session->clearSecureAccessKeys([
            $this->normalizeKey($receivedKey),
            $encrypted,
            $route !== '' && $encrypted !== '' ? $route . $encrypted : '',
        ]);
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }

    private function normalizeRoute(string $route): string
    {
        $route = $this->normalizeKey($route);
        return $route !== '' ? rtrim($route, '/') . '/' : '';
    }
}
