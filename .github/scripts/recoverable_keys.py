from pathlib import Path
import re

root = Path('.')

def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f'missing pattern: {label}')
    return text.replace(old, new, 1)

def replace_between(text: str, start: str, end: str, replacement: str, label: str) -> str:
    a = text.find(start)
    if a < 0:
        raise SystemExit(f'missing start: {label}')
    b = text.find(end, a)
    if b < 0:
        raise SystemExit(f'missing end: {label}')
    return text[:a] + replacement + text[b:]

# ------------------------------------------------------------------
# S3Manager: naming, folder rename semantics, root protection.
# ------------------------------------------------------------------
p = root / 'drive/S3Manager.php'
s = p.read_text()

s = replace_once(
    s,
    "    private $db;\n\n    public function __construct(?\\Aws\\S3\\S3Client $s3 = null, ?mysqli $db = null, ?string $bucket = null)\n",
    "    private $db;\n    private \\ArcadeCloud\\Drive\\Storage\\StorageObjectNameCodec $nameCodec;\n\n    public function __construct(?\\Aws\\S3\\S3Client $s3 = null, ?mysqli $db = null, ?string $bucket = null, ?\\ArcadeCloud\\Drive\\Storage\\StorageObjectNameCodec $nameCodec = null)\n",
    'S3Manager constructor signature'
)
s = replace_once(
    s,
    "        $this->db = $db;\n    }\n",
    "        $this->db = $db;\n        $this->nameCodec = $nameCodec ?? new \\ArcadeCloud\\Drive\\Storage\\StorageObjectNameCodec();\n    }\n",
    'S3Manager codec init'
)

old_base = """    private function getBasePrefix(): string
    {
        return $this->normalizePrefix(Config::RUTA_RAIZ ?? 'Data/');
    }
"""
new_base = """    private function getBasePrefix(): string
    {
        return (new \\ArcadeCloud\\Drive\\Storage\\UserStoragePath())->rootForUser($this->resolveUserId());
    }
"""
s = replace_once(s, old_base, new_base, 'multi-user base prefix')

helper_marker = "    private function upsertFolderDb(int $userId, string $prefix): void\n"
helper = r'''    private function folderVisibleNameExistsDb(int $userId, string $parentPrefix, string $visibleName, ?string $excludePrefix = null): bool
    {
        $parentPrefix = $this->normalizePrefix($parentPrefix);
        $sql = "SELECT 1 FROM S3Folders
                WHERE user_id_ = ? AND ParentPrefix = ? AND Nombre = ? AND Found = 1";
        $types = 'iss';
        $params = [$userId, $parentPrefix, $visibleName];
        if ($excludePrefix !== null) {
            $sql .= ' AND Prefix <> ?';
            $types .= 's';
            $params[] = $this->normalizePrefix($excludePrefix);
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error verificando nombre visible de carpeta: ' . $this->db->error);
        }
        $stmt->bind_param($types, ...$params);
        $this->executeStmt($stmt, 'Error verificando nombre visible de carpeta');
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

'''
if helper not in s:
    s = s.replace(helper_marker, helper + helper_marker, 1)

start = "    private function upsertFolderDb(int $userId, string $prefix): void\n"
end = "/**\n * ============================================================\n * FUNCTION: renameMoveFolderTreeDb"
new_upsert = r'''    private function upsertFolderDb(int $userId, string $prefix, ?string $visibleName = null): void
    {
        $prefix = $this->normalizePrefix($prefix);
        $physicalName = $this->folderNameFromPrefix($prefix);
        $nombre = trim((string)$visibleName);
        if ($nombre === '') {
            $nombre = $this->nameCodec->recoverFolderVisibleName($physicalName) ?? $physicalName;
        }
        $parent = $this->parentPrefix($prefix);

        $sql = "INSERT INTO S3Folders
                    (user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt)
                VALUES
                    (?, ?, ?, ?, 1, 'normal', NULL, NULL, NULL)
                ON DUPLICATE KEY UPDATE
                    Nombre = IF(Nombre IS NULL OR Nombre = '', VALUES(Nombre), Nombre),
                    ParentPrefix = VALUES(ParentPrefix),
                    Found = 1,
                    UpdatedAt = CURRENT_TIMESTAMP";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando upsertFolderDb: ' . $this->db->error);
        }

        $stmt->bind_param('isss', $userId, $prefix, $nombre, $parent);
        $this->executeStmt($stmt, 'Error ejecutando upsertFolderDb');
        $stmt->close();
    }

'''
s = replace_between(s, start, end, new_upsert, 'upsertFolderDb')

start = "    public function crearCarpeta(string $rutaBase, string $nombreCarpeta): void\n"
end = "public function eliminarCarpetaCompleta($ruta): array\n"
new_create = r'''    public function crearCarpeta(string $rutaBase, string $nombreCarpeta): void
    {
        $userId = $this->resolveUserId();
        $rutaBase = trim($rutaBase) !== '' ? $this->normalizePrefix($rutaBase) : $this->getSessionRoute();
        $nombreCarpeta = trim($nombreCarpeta);

        if ($nombreCarpeta === '') {
            throw new RuntimeException('Debes indicar un nombre de carpeta.');
        }
        if (!preg_match('/^[^\\\/:*?"<>|]+$/u', $nombreCarpeta)) {
            throw new RuntimeException('El nombre de la carpeta contiene caracteres no permitidos.');
        }

        $base = $this->getBasePrefix();
        if (strpos($rutaBase, $base) !== 0) {
            throw new RuntimeException('Ruta fuera de la carpeta base del usuario.');
        }
        if ($this->folderVisibleNameExistsDb($userId, $rutaBase, $nombreCarpeta)) {
            throw new RuntimeException('Ya existe una carpeta visible con ese nombre en este nivel.');
        }

        // El nombre físico no cambia nunca al renombrar visualmente.
        // Formato: d_<token>-<nombre de creación>/
        $physicalName = $this->nameCodec->createFolderObjectName($nombreCarpeta);
        $carpetaKey = $this->normalizePrefix($rutaBase . $physicalName);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $carpetaKey,
            'Body' => '',
            'ACL' => 'private',
            'ContentType' => 'application/x-directory'
        ]);

        $this->db->begin_transaction();
        try {
            $this->upsertFolderDb($userId, $carpetaKey, $nombreCarpeta);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $carpetaKey]);
            } catch (Throwable) {
            }
            throw $e;
        }
        $this->setSessionRoute($rutaBase);
    }

'''
s = replace_between(s, start, end, new_create, 'crearCarpeta')

start = "public function renombrarCarpeta(string $rutaAntigua, string $nuevoNombre): void\n"
end = "/**\n * ============================================================\n * FUNCTION: deleteFolderRecursive"
new_rename_folder = r'''public function renombrarCarpeta(string $rutaAntigua, string $nuevoNombre): void
{
    $userId = $this->resolveUserId();
    $rutaAntigua = $this->normalizePrefix($rutaAntigua);
    $nuevoNombre = trim($nuevoNombre);

    if ($nuevoNombre === '') {
        throw new RuntimeException('Debes indicar el nuevo nombre de la carpeta.');
    }
    if (!preg_match('/^[^\\\/:*?"<>|]+$/u', $nuevoNombre)) {
        throw new RuntimeException('El nuevo nombre contiene caracteres no permitidos.');
    }

    $base = $this->getBasePrefix();
    if (strpos($rutaAntigua, $base) !== 0) {
        throw new RuntimeException('Ruta fuera de la carpeta base del usuario.');
    }
    if ($rutaAntigua === $base) {
        throw new RuntimeException('No puedes renombrar la carpeta raíz del usuario.');
    }

    $stmt = $this->db->prepare('SELECT id_, ParentPrefix, Nombre FROM S3Folders WHERE user_id_ = ? AND Prefix = ? AND Found = 1 LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('No se pudo localizar la carpeta: ' . $this->db->error);
    }
    $stmt->bind_param('is', $userId, $rutaAntigua);
    $this->executeStmt($stmt, 'Error localizando carpeta para renombrar');
    $folder = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$folder) {
        throw new RuntimeException('Carpeta no encontrada.');
    }

    $parent = trim((string)($folder['ParentPrefix'] ?? ''));
    $parent = $parent !== '' ? $this->normalizePrefix($parent) : $base;
    if ($this->folderVisibleNameExistsDb($userId, $parent, $nuevoNombre, $rutaAntigua)) {
        throw new RuntimeException('Ya existe una carpeta visible con ese nombre en este nivel.');
    }

    // Renombrar es una operación exclusivamente lógica: no CopyObject,
    // no DeleteObject y no cambia Prefix/ParentPrefix.
    $id = (int)$folder['id_'];
    $update = $this->db->prepare('UPDATE S3Folders SET Nombre = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE id_ = ? AND user_id_ = ?');
    if (!$update) {
        throw new RuntimeException('No se pudo preparar el renombrado de carpeta: ' . $this->db->error);
    }
    $update->bind_param('sii', $nuevoNombre, $id, $userId);
    $this->executeStmt($update, 'Error renombrando carpeta en base de datos');
    $update->close();
}

'''
s = replace_between(s, start, end, new_rename_folder, 'renombrarCarpeta')

s = replace_once(
    s,
    "SELECT id_, Prefix, ParentPrefix\n               FROM S3Folders",
    "SELECT id_, Prefix, ParentPrefix, Nombre\n               FROM S3Folders",
    'move folder select keeps Nombre'
)
s = replace_once(
    s,
    "    $sqlUpdFolder = \"UPDATE S3Folders\n                     SET Prefix = ?, Nombre = ?, ParentPrefix = ?, Found = 1, UpdatedAt = CURRENT_TIMESTAMP\n                     WHERE id_ = ? AND user_id_ = ?\";",
    "    $sqlUpdFolder = \"UPDATE S3Folders\n                     SET Prefix = ?, ParentPrefix = ?, Found = 1, UpdatedAt = CURRENT_TIMESTAMP\n                     WHERE id_ = ? AND user_id_ = ?\";",
    'move folder update preserves Nombre'
)
s = replace_once(s, "        $newName = $this->folderNameFromPrefix($newFolderPrefix);\n        $id = (int)$folder['id_'];\n\n        $stmtUpdFolder->bind_param('sssii', $newFolderPrefix, $newName, $newParent, $id, $userId);", "        $id = (int)$folder['id_'];\n\n        $stmtUpdFolder->bind_param('ssii', $newFolderPrefix, $newParent, $id, $userId);", 'move folder bind preserves Nombre')

s = replace_once(
    s,
    "    if ($prefix === '') {\n        throw new RuntimeException('Prefijo de carpeta inválido.');\n    }\n\n    $deletedS3Objects",
    "    if ($prefix === '') {\n        throw new RuntimeException('Prefijo de carpeta inválido.');\n    }\n    if ($prefix === $this->getBasePrefix()) {\n        throw new RuntimeException('No puedes eliminar la carpeta raíz del usuario.');\n    }\n\n    $deletedS3Objects",
    'root delete protection'
)

old_upload_name = """    $extension = pathinfo($originalName, PATHINFO_EXTENSION);

    $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4));
    if ($extension !== '') {
        $nombreEncriptado .= '.' . $extension;
    }

    $key = $this->normalizeFileKey($ruta . $nombreEncriptado);
"""
new_upload_name = """    $nombreEncriptado = $this->nameCodec->createFileObjectName($originalName);
    $key = $this->normalizeFileKey($ruta . $nombreEncriptado);
"""
s = replace_once(s, old_upload_name, new_upload_name, 'S3Manager upload file naming')

p.write_text(s)

# ------------------------------------------------------------------
# Upload drivers use one physical naming codec.
# ------------------------------------------------------------------
for rel, old in [
    ('drive/upload/drivers/LocalPresignedPutUploader.php', "        $ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION);\n        $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');\n"),
    ('drive/upload/drivers/DropboxUploader.php', "            $ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION);\n            $nombreHash = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');\n")
]:
    fp = root / rel
    t = fp.read_text()
    if 'LocalPresigned' in rel:
        new = "        $nombreEncriptado = (new \\ArcadeCloud\\Drive\\Storage\\StorageObjectNameCodec())->createFileObjectName($nombreOriginal);\n"
    else:
        new = "            $nombreHash = (new \\ArcadeCloud\\Drive\\Storage\\StorageObjectNameCodec())->createFileObjectName($nombreOriginal);\n"
    t = replace_once(t, old, new, rel)
    fp.write_text(t)

fp = root / 'drive/upload/drivers/RemoteUrlUploader.php'
t = fp.read_text()
old = "    $ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION);\n    if ($ext === '') $ext = 'bin';\n    $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;\n"
new = "    $nombreEncriptado = (new \\ArcadeCloud\\Drive\\Storage\\StorageObjectNameCodec())->createFileObjectName($nombreOriginal);\n"
t = replace_once(t, old, new, 'RemoteUrlUploader')
fp.write_text(t)

fp = root / 'drive/upload/drivers/Chunked15MBUploader.php'
t = fp.read_text()
old = "    $ymd = gmdate('Ymd');\n    $safeBase = preg_replace('/[^\\w\\-.]+/u', '_', basename($filename));\n    $key = $carpeta . '/' . $sig . '-' . $safeBase; ///uploads/' . $ymd . '\n"
new = "    $physicalName = (new \\ArcadeCloud\\Drive\\Storage\\StorageObjectNameCodec())->createFileObjectName($filename);\n    $key = $carpeta . '/' . $physicalName;\n"
t = replace_once(t, old, new, 'Chunked15MBUploader')
fp.write_text(t)

# ------------------------------------------------------------------
# Synchronization: recover names, preserve DB-only renames.
# ------------------------------------------------------------------
fp = root / 'drive/src/Sync/SyncRepository.php'
t = fp.read_text()
t = replace_once(t,
"        if($row){$id=(int)$row['id_'];$this->exec('UPDATE S3Folders SET Found=1,Nombre=?,ParentPrefix=?,UpdatedAt=NOW() WHERE id_=? AND user_id_=?',[$name,$parent,$id,$userId],'ssii');return;}",
"        if($row){$id=(int)$row['id_'];$this->exec('UPDATE S3Folders SET Found=1,ParentPrefix=?,UpdatedAt=NOW() WHERE id_=? AND user_id_=?',[$parent,$id,$userId],'sii');return;}",
'preserve folder visible name on sync')
t = replace_once(t, "    public function upsertFile(int $userId,string $key,int $size): void\n", "    public function upsertFile(int $userId,string $key,int $size,?string $recoveredName=null): void\n", 'sync file signature')
t = replace_once(t, "$pos=strrpos($key,'/');$dir=$pos===false?'':substr($key,0,$pos+1);$dir=$dir!==''?rtrim($dir,'/').'/':'';$base=$pos===false?$key:substr($key,$pos+1);", "$pos=strrpos($key,'/');$dir=$pos===false?'':substr($key,0,$pos+1);$dir=$dir!==''?rtrim($dir,'/').'/':'';$base=$pos===false?$key:substr($key,$pos+1);$visible=trim((string)$recoveredName);if($visible==='')$visible=$base;", 'sync visible variable')
t = replace_once(t, "[$key,$size,$dir,$base,$id,$userId]", "[$key,$size,$dir,$visible,$id,$userId]", 'sync existing visible fallback')
t = replace_once(t, "[$base,$key,$size,$dir,$userId]", "[$visible,$key,$size,$dir,$userId]", 'sync insert visible')
fp.write_text(t)

fp = root / 'drive/src/Sync/S3SyncService.php'
fp.write_text(r'''<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Sync;

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\S3\S3Client;

final class S3SyncService
{
    public function __construct(
        private SyncRepository $repository,
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $paths,
        private StorageObjectNameCodec $names,
        private int $pageDelayUs = 150000
    ) {
    }

    public function synchronize(int $userId): array
    {
        $base = $this->paths->rootForUser($userId);
        $this->s3->headBucket(['Bucket' => $this->bucket]);
        $folders = [];
        $files = 0;
        $this->repository->begin();
        try {
            $this->repository->resetFound($userId);
            $this->addFolder($folders, $base);
            $params = ['Bucket' => $this->bucket, 'Prefix' => $base, 'MaxKeys' => 1000];
            do {
                $res = $this->s3->listObjectsV2($params);
                foreach ((array)($res['Contents'] ?? []) as $object) {
                    $key = (string)($object['Key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    if (str_ends_with($key, '/')) {
                        $this->addFolder($folders, $key);
                        continue;
                    }
                    $this->addParentFolders($folders, $key);
                    $visible = $this->recoverVisibleFileName($key);
                    $this->repository->upsertFile($userId, $key, (int)($object['Size'] ?? 0), $visible);
                    $files++;
                }
                if (!empty($res['IsTruncated']) && !empty($res['NextContinuationToken'])) {
                    $params['ContinuationToken'] = $res['NextContinuationToken'];
                } else {
                    unset($params['ContinuationToken']);
                }
                if ($this->pageDelayUs > 0) {
                    usleep($this->pageDelayUs);
                }
            } while (!empty($res['IsTruncated']));

            foreach ($folders as $prefix => $info) {
                $this->repository->upsertFolder($userId, $prefix, $info['name'], $info['parent']);
            }
            $this->repository->purgeMissing($userId);
            $this->repository->commit();
            return ['ok' => true, 'user_id' => $userId, 'bucket' => $this->bucket, 'base' => $base, 'files_upserted' => $files, 'folders_upserted' => count($folders)];
        } catch (\Throwable $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    private function recoverVisibleFileName(string $key): string
    {
        $base = basename($key);
        $decoded = $this->names->recoverFileVisibleName($base);
        if ($decoded !== null) {
            return $decoded;
        }

        // Formatos opacos históricos f_<id>... no contenían el nombre.
        // Durante la sincronización MANUAL intentamos recuperarlo de metadata.
        if ($this->names->looksLikeOpaqueLegacyFileName($base)) {
            try {
                $head = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
                foreach ((array)($head['Metadata'] ?? []) as $metaKey => $value) {
                    $normalized = strtolower(str_replace(['_', '-'], '', (string)$metaKey));
                    if (in_array($normalized, ['originalname', 'filename'], true) && trim((string)$value) !== '') {
                        return basename(str_replace('\\', '/', (string)$value));
                    }
                }
            } catch (\Throwable) {
                // La key sigue siendo recuperable aunque el nombre histórico no lo sea.
            }
        }
        return $base;
    }

    private function addParentFolders(array &$folders, string $key): void
    {
        $dir = dirname($key);
        if ($dir === '.' || $dir === '') {
            return;
        }
        $acc = '';
        foreach (explode('/', $dir) as $part) {
            if ($part === '') {
                continue;
            }
            $acc .= $part . '/';
            $this->addFolder($folders, $acc);
        }
    }

    private function addFolder(array &$folders, string $prefix): void
    {
        $prefix = rtrim($prefix, '/') . '/';
        if ($prefix === './') {
            return;
        }
        $trim = rtrim($prefix, '/');
        $pos = strrpos($trim, '/');
        $physical = $pos === false ? $trim : substr($trim, $pos + 1);
        $name = $this->names->recoverFolderVisibleName($physical) ?? $physical;
        $parent = $pos === false ? null : rtrim(substr($trim, 0, $pos + 1), '/') . '/';
        if ($parent === '/' || $parent === '') {
            $parent = null;
        }
        $folders[$prefix] = ['name' => $name !== '' ? $name : $prefix, 'parent' => $parent];
    }
}
''')

fp = root / 'drive/src/Http/Controller/SyncController.php'
t = fp.read_text()
old = "$service=new S3SyncService(new SyncRepository($this->app->db()),$this->app->s3(),$this->app->bucket(),$this->app->userStoragePath());"
new = "$service=new S3SyncService(new SyncRepository($this->app->db()),$this->app->s3(),$this->app->bucket(),$this->app->userStoragePath(),$this->app->storageObjectNameCodec());"
t = replace_once(t, old, new, 'SyncController codec')
fp.write_text(t)

# ------------------------------------------------------------------
# OOP physical-key rotation endpoint (keeps existing HTTP contract).
# ------------------------------------------------------------------
service = root / 'drive/src/Application/FileKeyRotationService.php'
service.write_text(r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class FileKeyRotationService
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private FileRecordLocator $locator,
        private StorageObjectNameCodec $names
    ) {
    }

    public function rotate(int $userId, string $requestedKey): array
    {
        $row = $this->locator->requireReadableByKey($userId, $requestedKey);
        $oldKey = (string)$row['_key'];
        $route = rtrim((string)$row['Ruta'], '/') . '/';
        $visible = trim((string)$row['Nombre']);
        if ($visible === '') {
            throw new RuntimeException('El archivo no tiene nombre visible.');
        }
        $newBase = $this->names->createFileObjectName($visible);
        $newKey = $route . $newBase;

        $this->s3->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => rawurlencode($this->bucket . '/' . $oldKey),
            'Key' => $newKey,
            'ACL' => 'private',
            'MetadataDirective' => 'COPY',
        ]);

        $id = (int)$row['id_'];
        $stmt = $this->db->prepare('UPDATE FileS3 SET Encriptado = ?, Ruta = ?, Found = 1 WHERE id_ = ? AND user_id_ = ?');
        if (!$stmt) {
            try {$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $newKey]);} catch (\Throwable) {}
            throw new RuntimeException('No se pudo preparar la rotación de key: ' . $this->db->error);
        }
        $stmt->bind_param('ssii', $newBase, $route, $id, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            try {$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $newKey]);} catch (\Throwable) {}
            throw new RuntimeException('No se pudo actualizar FileS3: ' . $error);
        }
        $stmt->close();

        try {
            $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $oldKey]);
        } catch (\Throwable $e) {
            throw new RuntimeException('La nueva key quedó registrada, pero no se pudo borrar la key anterior: ' . $e->getMessage());
        }

        return ['estado' => 'ok', 'newKey' => $newKey, 'encriptado' => $newBase, 'nombre' => $visible];
    }
}
''')

endpoint = root / 'drive/encriptar_archivo.php'
endpoint.write_text(r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Application\FileKeyRotationService;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Core\ApplicationKernel;

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Método no permitido.');
    }
    $app = ApplicationKernel::app();
    $session = $app->session();
    $session->start();
    $userId = $session->userId();
    if ($userId <= 0) {
        throw new RuntimeException('Sesión inválida.');
    }
    $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $key = trim((string)($data['key'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('Falta el nombre del archivo.');
    }
    $service = new FileKeyRotationService(
        $app->db(),
        $app->s3(),
        $app->bucket(),
        new FileRecordLocator($app->db()),
        $app->storageObjectNameCodec()
    );
    echo json_encode($service->rotate($userId, $key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['estado' => 'error', 'mensaje' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
''')

# ------------------------------------------------------------------
# Documentation.
# ------------------------------------------------------------------
arch = root / 'drive/ARCHITECTURE.md'
a = arch.read_text()
section = r'''

## Nombres visibles vs. nombres físicos en S3

El nombre que ve el usuario es un dato lógico de MySQL. Renombrar no renombra objetos en S3.

- Archivo nuevo: `f_<32hex>-<nombre-de-creacion.ext>`.
- Carpeta nueva: `d_<32hex>-<nombre-de-creacion>/`.
- Raíz: `Data/` para `user_id=1`; `DataN/` para los demás usuarios. La raíz no se puede renombrar, mover ni eliminar.
- `FileS3.Nombre` y `S3Folders.Nombre` son los nombres visibles y pueden cambiar sin modificar la key/prefix físico.
- Mover sí cambia la ubicación física, pero conserva el basename físico y el nombre visible.
- La sincronización nunca sobrescribe un `Nombre` visible existente. Si reconstruye una fila ausente, `StorageObjectNameCodec` recupera el nombre de creación desde el sufijo de la key.
- Para archivos históricos `f_*` que no incorporaban nombre, la sincronización manual intenta `headObject` y metadata `original-name`; si tampoco existe, solo puede recuperar el basename físico.

Esta separación evita colisiones de nombres y permite reconstruir el catálogo desde S3. Como consecuencia deliberada, un nombre cambiado únicamente en MySQL después de la creación no puede recuperarse desde S3 si se pierde completamente la base de datos; se recuperará el nombre de creación. Para preservar también los renombrados posteriores hace falta respaldar MySQL o un manifiesto independiente.
'''
if '## Nombres visibles vs. nombres físicos en S3' not in a:
    arch.write_text(a.rstrip() + section + '\n')

print('recoverable key migration applied')
