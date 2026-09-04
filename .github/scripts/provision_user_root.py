from pathlib import Path

root = Path('.')

service = root / 'drive/src/Storage/UserStorageProvisioner.php'
service.parent.mkdir(parents=True, exist_ok=True)
service.write_text(r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class UserStorageProvisioner
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $paths
    ) {
    }

    public function ensureRoot(int $userId): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para provisionar almacenamiento.');
        }

        $root = $this->paths->rootForUser($userId);

        if ($this->rootIsRegistered($userId, $root)) {
            return $root;
        }

        // S3 no tiene carpetas reales. Para un usuario nuevo creamos un
        // objeto vacío con slash final para que su raíz exista aun sin archivos.
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $root,
            'Body' => '',
            'ContentType' => 'application/x-directory',
            'Metadata' => [
                'drive-user-id' => (string) $userId,
                'drive-root' => '1',
            ],
        ]);

        $name = rtrim($root, '/');
        $stmt = $this->db->prepare(
            "INSERT INTO S3Folders
                (user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, CreatedAt, UpdatedAt)
             VALUES (?, ?, ?, NULL, 1, 'normal', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                Nombre = VALUES(Nombre),
                ParentPrefix = NULL,
                Found = 1,
                UpdatedAt = NOW()"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo registrar la raíz del usuario: ' . $this->db->error);
        }

        $stmt->bind_param('iss', $userId, $root, $name);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo registrar la raíz del usuario: ' . $error);
        }
        $stmt->close();

        return $root;
    }

    private function rootIsRegistered(int $userId, string $root): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id_, Found FROM S3Folders WHERE user_id_ = ? AND Prefix = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo verificar la raíz del usuario: ' . $this->db->error);
        }

        $stmt->bind_param('is', $userId, $root);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        if ((int) ($row['Found'] ?? 0) !== 1) {
            $update = $this->db->prepare(
                'UPDATE S3Folders SET Found = 1, UpdatedAt = NOW() WHERE id_ = ? AND user_id_ = ?'
            );
            if (!$update) {
                throw new RuntimeException('No se pudo reactivar la raíz del usuario: ' . $this->db->error);
            }
            $id = (int) $row['id_'];
            $update->bind_param('ii', $id, $userId);
            $update->execute();
            $update->close();
        }

        return true;
    }
}
''', encoding='utf-8')

app = root / 'drive/src/Core/DriveApplication.php'
text = app.read_text(encoding='utf-8')
text = text.replace(
    'use ArcadeCloud\\Drive\\Storage\\StorageUsageService;\nuse ArcadeCloud\\Drive\\Storage\\UserStoragePath;',
    'use ArcadeCloud\\Drive\\Storage\\StorageUsageService;\nuse ArcadeCloud\\Drive\\Storage\\UserStoragePath;\nuse ArcadeCloud\\Drive\\Storage\\UserStorageProvisioner;'
)
text = text.replace(
    '    private ?StorageUsageService $storageUsageService = null;\n    private ?UserStoragePath $userStoragePath = null;',
    '    private ?StorageUsageService $storageUsageService = null;\n    private ?UserStoragePath $userStoragePath = null;\n    private ?UserStorageProvisioner $userStorageProvisioner = null;'
)
needle = '''    public function storageUsageService(): StorageUsageService\n    {\n        return $this->storageUsageService ??= new StorageUsageService($this->db);\n    }\n\n'''
replacement = needle + '''    public function userStorageProvisioner(): UserStorageProvisioner\n    {\n        return $this->userStorageProvisioner ??= new UserStorageProvisioner(\n            $this->db,\n            $this->s3,\n            $this->bucket,\n            $this->userStoragePath()\n        );\n    }\n\n'''
if needle not in text:
    raise SystemExit('DriveApplication insertion point not found')
text = text.replace(needle, replacement, 1)
app.write_text(text, encoding='utf-8')

s3 = root / 'drive/s3.php'
text = s3.read_text(encoding='utf-8')
needle = '''$session->requireAuthenticated('index.php');\n$userId = $session->userId();\n\n$pageService = $app->drivePageService();'''
replacement = '''$session->requireAuthenticated('index.php');\n$userId = $session->userId();\n\n// Provisionamiento multiusuario idempotente:\n// user 1 => Data/, user 2 => Data2/, user N => DataN/.\n$userRoot = $app->userStorageProvisioner()->ensureRoot($userId);\n$_SESSION['ruta_actual'] = $app->userStoragePath()->normalizeForUser(\n    (string) ($_SESSION['ruta_actual'] ?? $userRoot),\n    $userId\n);\n\n$pageService = $app->drivePageService();'''
if needle not in text:
    raise SystemExit('s3.php insertion point not found')
text = text.replace(needle, replacement, 1)
s3.write_text(text, encoding='utf-8')

doc = root / 'drive/ARCHITECTURE.md'
text = doc.read_text(encoding='utf-8')
addition = r'''

## Provisionamiento multiusuario

La raíz física/lógica del Drive se deriva exclusivamente del `Users.id` autenticado:

```text
user_id = 1  -> Data/
user_id = 2  -> Data2/
user_id = 3  -> Data3/
user_id = N  -> DataN/
```

`UserStoragePath` es la única clase autorizada para calcular y normalizar esas raíces.
`UserStorageProvisioner` se ejecuta al entrar a `s3.php` y es idempotente:

1. consulta `S3Folders` por `user_id_ + Prefix`;
2. si la raíz ya está registrada, no consulta ni escribe S3;
3. si es el primer acceso, crea el objeto vacío `DataN/` en S3;
4. registra la raíz en `S3Folders` con `Found=1`;
5. la sesión se normaliza de nuevo contra la raíz del usuario antes de construir la página.

Esto permite que un usuario creado por cualquier sistema de registro quede provisionado en su
primer acceso al Drive, siempre usando el ID real asignado por MySQL. La separación lógica en
BD sigue siendo obligatoria mediante `user_id_` y ningún endpoint debe aceptar una raíz de otro usuario.
'''
if '## Provisionamiento multiusuario' not in text:
    text += addition
doc.write_text(text, encoding='utf-8')
