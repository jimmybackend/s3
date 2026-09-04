from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DRIVE = ROOT / 'drive'
SRC = DRIVE / 'src'


def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8')

# SessionManager centraliza compatibilidad de sesión y estado temporal.
session_path = SRC / 'Security/SessionManager.php'
write(session_path, r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

final class SessionManager
{
    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['usuario']) && trim((string)$_SESSION['usuario']) !== '';
    }

    public function requireAuthenticated(string $redirect = 'index.php'): void
    {
        if ($this->isAuthenticated()) {
            return;
        }
        header('Location: ' . $redirect);
        exit;
    }

    public function userId(): int
    {
        foreach (['user_id', 'user_id_', 'id_usuario', 'id_user', 'id'] as $key) {
            $value = $_SESSION[$key] ?? null;
            if ($value !== null && $value !== '' && ctype_digit((string)$value)) {
                return (int)$value;
            }
        }
        return 0;
    }

    public function userName(): string
    {
        return isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : '';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clearSecureAccessKeys(array $keys): void
    {
        if (!isset($_SESSION['secure_ok_files']) || !is_array($_SESSION['secure_ok_files'])) {
            return;
        }
        foreach (array_unique(array_filter(array_map('strval', $keys))) as $key) {
            unset($_SESSION['secure_ok_files'][$key]);
        }
    }
}
''')

write(SRC / 'Security/FileSecurityRepository.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use mysqli;
use RuntimeException;

final class FileSecurityRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findByKey(int $userId, string $key, ?string $requiredAccessType = null): ?array
    {
        [$route, $encrypted] = $this->splitKey($key);
        $accessSql = $requiredAccessType !== null ? ' AND AccessType=?' : '';

        $sql = "SELECT id_, Nombre, Encriptado, Ruta, AccessType, PasswordHash, SecureHint, Found
                FROM FileS3
                WHERE user_id_=? AND Found=1{$accessSql}
                  AND (Encriptado=? OR (Ruta=? AND Encriptado=?))
                ORDER BY id_ DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la búsqueda de seguridad: ' . $this->db->error);
        }

        if ($requiredAccessType !== null) {
            $stmt->bind_param('issss', $userId, $requiredAccessType, $key, $route, $encrypted);
        } else {
            $stmt->bind_param('isss', $userId, $key, $route, $encrypted);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    public function setSecure(int $userId, int $fileId, string $passwordHash, string $hint): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FileS3 SET AccessType='secure', PasswordHash=?, SecureHint=?, SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );
        $this->execute($stmt, 'ssii', [$passwordHash, $hint, $fileId, $userId]);
    }

    public function setUnlocked(int $userId, int $fileId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FileS3 SET AccessType='unlocked', SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );
        $this->execute($stmt, 'ii', [$fileId, $userId]);
    }

    public function setNormal(int $userId, int $fileId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FileS3 SET AccessType='normal', PasswordHash=NULL, SecureHint=NULL, SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );
        $this->execute($stmt, 'ii', [$fileId, $userId]);
    }

    private function execute(\mysqli_stmt|false $stmt, string $types, array $values): void
    {
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la actualización de seguridad: ' . $this->db->error);
        }
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute() || $stmt->errno) {
            $message = $stmt->error ?: 'Error al actualizar seguridad.';
            $stmt->close();
            throw new RuntimeException($message);
        }
        $stmt->close();
    }

    private function splitKey(string $key): array
    {
        $position = strrpos($key, '/');
        if ($position === false) {
            return ['', $key];
        }
        return [substr($key, 0, $position + 1), substr($key, $position + 1)];
    }
}
''')

write(SRC / 'Security/FileSecurityService.php', r'''<?php
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
''')

write(SRC / 'Http/Controller/FileSecurityController.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Security\FileSecurityRepository;
use ArcadeCloud\Drive\Security\FileSecurityService;

final class FileSecurityController extends AbstractJsonController
{
    private function service(): FileSecurityService
    {
        return new FileSecurityService(
            new FileSecurityRepository($this->app->db()),
            $this->app->session()
        );
    }

    public function setMode(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $keys = $this->keys();
            $result = $this->service()->setMode(
                $userId,
                $keys,
                $this->request->postString('mode'),
                $this->request->postString('password'),
                $this->request->postString('secure_hint')
            );
            JsonResponse::send($result);
        } catch (\Throwable $error) {
            JsonResponse::send(['ok' => false, 'msg' => $error->getMessage()], 400);
        }
    }

    public function unlock(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $service = $this->service();
            $key = $service->buildKey(
                $this->request->postString('key'),
                $this->request->postString('ruta'),
                $this->firstNonEmpty('encriptado', 'enc')
            );
            $password = $this->firstNonEmpty('password', 'pass');
            JsonResponse::send($service->unlock($userId, $key, $password));
        } catch (\Throwable $error) {
            JsonResponse::send(['ok' => false, 'msg' => $error->getMessage()], 400);
        }
    }

    public function relock(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $service = $this->service();
            $keys = $this->keys();
            $compat = $service->buildKey(
                '',
                $this->request->postString('ruta'),
                $this->firstNonEmpty('encriptado', 'enc')
            );
            if ($compat !== '') {
                $keys[] = $compat;
            }
            JsonResponse::send($service->relock($userId, $keys));
        } catch (\Throwable $error) {
            JsonResponse::send(['ok' => false, 'msg' => $error->getMessage()], 400);
        }
    }

    private function keys(): array
    {
        $keys = $this->keysFromRequest();
        $single = $this->request->postString('key');
        if ($single !== '') {
            $keys[] = $single;
        }
        return $keys;
    }

    private function firstNonEmpty(string ...$names): string
    {
        foreach ($names as $name) {
            $value = $this->request->postString($name);
            if ($value !== '') return $value;
        }
        return '';
    }
}
''')

for filename, method in {
    'set_file_security.php': 'setMode',
    'unlock_file.php': 'unlock',
    'relock_file.php': 'relock',
}.items():
    write(DRIVE / filename, f'''<?php\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/app_bootstrap.php';\n\n(new \\ArcadeCloud\\Drive\\Http\\Controller\\FileSecurityController(\n    \\ArcadeCloud\\Drive\\Core\\ApplicationKernel::app(),\n    \\ArcadeCloud\\Drive\\Http\\Request::fromGlobals()\n))->{method}();\n''')

print('Security OOP migration applied')
