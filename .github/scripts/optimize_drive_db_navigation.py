from pathlib import Path
import re

ROOT = Path.cwd()
DRIVE = ROOT / 'drive'


def read(path: Path):
    data = path.read_bytes()
    try:
        return data.decode('utf-8'), 'utf-8'
    except UnicodeDecodeError:
        return data.decode('latin-1'), 'latin-1'


def write(path: Path, text: str, encoding: str = 'utf-8'):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(text.encode(encoding))


def normalize(text: str) -> str:
    return text.replace('\r\n', '\n').replace('\r', '\n')


def replace_once(path: Path, old: str, new: str, label: str):
    text, enc = read(path)
    text = normalize(text)
    if old not in text:
        raise SystemExit(f'No se encontró patrón {label} en {path}')
    write(path, text.replace(old, new, 1), enc)


def regex_once(path: Path, pattern: str, repl: str, label: str, flags=0):
    text, enc = read(path)
    text = normalize(text)
    out, n = re.subn(pattern, repl, text, count=1, flags=flags)
    if n != 1:
        raise SystemExit(f'Patrón regex {label} coincidió {n} veces en {path}')
    write(path, out, enc)


# ---------------------------------------------------------------------------
# 1) Destino de subida: explícito, normalizado y validado por usuario/BD
# ---------------------------------------------------------------------------
write(DRIVE / 'src/Application/UploadDestinationService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use mysqli;
use RuntimeException;

final class UploadDestinationService
{
    public function __construct(
        private mysqli $db,
        private UserStoragePath $paths
    ) {
    }

    public function resolve(int $userId, string $requestedRoute): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para establecer el destino de subida.');
        }

        $requestedRoute = trim($requestedRoute);
        if ($requestedRoute === '') {
            throw new RuntimeException('Falta la ruta objetivo de la subida.');
        }

        $route = $this->paths->normalizeForUser($requestedRoute, $userId);
        $root = $this->paths->rootForUser($userId);

        if ($route === $root) {
            return $route;
        }

        $stmt = $this->db->prepare(
            'SELECT 1 FROM S3Folders WHERE user_id_ = ? AND Prefix = ? AND Found = 1 LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar la carpeta destino: ' . $this->db->error);
        }

        $stmt->bind_param('is', $userId, $route);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            throw new RuntimeException('La carpeta destino ya no existe o no pertenece al usuario.');
        }

        return $route;
    }
}
''')

# DriveApplication: registrar servicio
p = DRIVE / 'src/Core/DriveApplication.php'
text, enc = read(p)
text = normalize(text)
text = text.replace(
    'use ArcadeCloud\\Drive\\Application\\FileListService;\n',
    'use ArcadeCloud\\Drive\\Application\\FileListService;\nuse ArcadeCloud\\Drive\\Application\\UploadDestinationService;\n',
    1
)
text = text.replace(
    '    private ?DrivePageService $drivePageService = null;\n',
    '    private ?DrivePageService $drivePageService = null;\n    private ?UploadDestinationService $uploadDestinationService = null;\n',
    1
)
needle = '''    public function storageUsageService(): StorageUsageService
    {
        return $this->storageUsageService ??= new StorageUsageService($this->db);
    }
'''
insert = '''    public function uploadDestinationService(): UploadDestinationService
    {
        return $this->uploadDestinationService ??= new UploadDestinationService(
            $this->db,
            $this->userStoragePath()
        );
    }

''' + needle
if needle not in text:
    raise SystemExit('No se encontró storageUsageService en DriveApplication')
text = text.replace(needle, insert, 1)
write(p, text, enc)


# ---------------------------------------------------------------------------
# 2) FileListService: navegación DB-only con 2 consultas por carpeta
# ---------------------------------------------------------------------------
write(DRIVE / 'src/Application/FileListService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use mysqli;
use mysqli_stmt;
use RuntimeException;

final class FileListService
{
    public function __construct(private mysqli $db)
    {
    }

    public function load(int $userId, string $route, array $query): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para listar archivos.');
        }

        $search = trim((string) ($query['buscar'] ?? ''));
        $type = strtolower(trim((string) ($query['tipo'] ?? '')));
        $dateFrom = trim((string) ($query['fecha_inicio'] ?? ''));
        $dateTo = trim((string) ($query['fecha_fin'] ?? ''));
        $page = max(1, (int) ($query['pagina'] ?? 1));
        $limit = max(5, min(100, (int) ($query['limite'] ?? 5)));

        $where = 'user_id_ = ? AND Ruta = ? AND Found = 1';
        $types = 'is';
        $params = [$userId, $route];

        if ($search !== '') {
            $where .= " AND (Nombre LIKE CONCAT('%',?,'%') OR Encriptado LIKE CONCAT('%',?,'%'))";
            $types .= 'ss';
            $params[] = $search;
            $params[] = $search;
        }
        if ($type !== '') {
            $where .= " AND LOWER(SUBSTRING_INDEX(Nombre,'.',-1)) = ?";
            $types .= 's';
            $params[] = $type;
        }
        if ($dateFrom !== '') {
            $where .= ' AND DATE(Fecha) >= ?';
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where .= ' AND DATE(Fecha) <= ?';
            $types .= 's';
            $params[] = $dateTo;
        }

        // Una sola ida a MySQL para: total filtrado + total/peso de la carpeta.
        $aggregateSql = "SELECT
                (SELECT COUNT(*) FROM FileS3 WHERE {$where}) AS filtered_total,
                COUNT(*) AS folder_total,
                COALESCE(SUM(Tamano), 0) AS folder_bytes
            FROM FileS3
            WHERE user_id_ = ? AND Ruta = ? AND Found = 1";

        $aggregateStmt = $this->prepare($aggregateSql);
        $aggregateParams = array_merge($params, [$userId, $route]);
        $this->bind($aggregateStmt, $types . 'is', $aggregateParams);
        $aggregateStmt->execute();
        $aggregate = $aggregateStmt->get_result()->fetch_assoc() ?: [];
        $aggregateStmt->close();

        $total = (int) ($aggregate['filtered_total'] ?? 0);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        // Segunda y última consulta de navegación: las filas de la página.
        $sql = "SELECT id_, Nombre, Encriptado, Tamano, Metadatos, Ruta,
                       Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt, Fecha, user_id_
                FROM FileS3
                WHERE {$where}
                ORDER BY Fecha DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->prepare($sql);
        $pageParams = array_merge($params, [$limit, $offset]);
        $this->bind($stmt, $types . 'ii', $pageParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return [
            'rows' => $rows,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
            'type' => $type,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'folder_total' => (int) ($aggregate['folder_total'] ?? 0),
            'folder_bytes' => (int) ($aggregate['folder_bytes'] ?? 0),
        ];
    }

    public function listExtensions(int $userId, string $route): array
    {
        $stmt = $this->prepare(
            "SELECT DISTINCT LOWER(SUBSTRING_INDEX(Nombre,'.',-1)) AS ext
             FROM FileS3
             WHERE user_id_ = ? AND Ruta = ? AND Found = 1 AND Nombre LIKE '%.%'
             ORDER BY ext"
        );
        $params = [$userId, $route];
        $this->bind($stmt, 'is', $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $extensions = [];
        while ($row = $result->fetch_assoc()) {
            $ext = trim((string) ($row['ext'] ?? ''));
            if ($ext !== '') {
                $extensions[] = $ext;
            }
        }
        $stmt->close();
        return $extensions;
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error SQL prepare: ' . $this->db->error);
        }
        return $stmt;
    }

    private function bind(mysqli_stmt $stmt, string $types, array $params): void
    {
        if ($params === []) {
            return;
        }

        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }
        unset($value);
        $stmt->bind_param($types, ...$refs);
    }
}
''')


# ---------------------------------------------------------------------------
# 3) API de subida: ruta explícita + liberar sesión durante subidas largas
# ---------------------------------------------------------------------------
write(DRIVE / 'api/upload.php', r'''<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
require_once $root . '/app_bootstrap.php';
require_once $root . '/upload/core/UploaderInterface.php';
require_once $root . '/upload/core/UploadResponse.php';
require_once $root . '/upload/UploadFactory.php';

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    UploadResponse::fail('Sesión inválida.', 401);
}

$action = trim((string) ($_REQUEST['action'] ?? ''));
$mode = trim((string) ($_REQUEST['mode'] ?? ''));
if ($action === '' || $mode === '') {
    UploadResponse::fail('Faltan parámetros action/mode', 400);
}

try {
    $req = array_merge($_GET, $_POST);
    $req['_files'] = $_FILES;
    $req['_user_id'] = $session->userId();
    $req['_usuario'] = $session->userName();

    if ($action === 'init') {
        $requestedRoute = trim((string) ($req['ruta_objetivo'] ?? ''));
        if ($requestedRoute === '') {
            UploadResponse::fail('Falta ruta_objetivo. La subida debe fijar su destino al iniciar.', 422);
        }
        $req['ruta_objetivo'] = $app->uploadDestinationService()->resolve(
            $session->userId(),
            $requestedRoute
        );
    }

    $uploader = UploadFactory::make($mode);

    // local_put usa la sesión unos milisegundos para guardar/consumir el intent.
    // Los demás modos pueden tardar mucho: liberamos el lock de sesión antes de S3.
    $keepSessionOpen = ($mode === 'local_put' && in_array($action, ['init', 'complete'], true));
    if (!$keepSessionOpen && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($action === 'init') {
        $result = $uploader->init($req);
    } elseif ($action === 'part') {
        $result = $uploader->part($req);
    } elseif ($action === 'complete') {
        $result = $uploader->complete($req);
    } else {
        UploadResponse::fail('Acción inválida', 400);
    }

    if ($keepSessionOpen && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    UploadResponse::ok($result);
} catch (Throwable $e) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    UploadResponse::fail($e->getMessage(), 500);
}
''')


# ---------------------------------------------------------------------------
# 4) local_put: la BD se escribe solamente tras PUT exitoso
# ---------------------------------------------------------------------------
write(DRIVE / 'upload/drivers/LocalPresignedPutUploader.php', r'''<?php
declare(strict_types=1);

use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class LocalPresignedPutUploader implements UploaderInterface
{
    private const PENDING_KEY = 'drive_pending_local_uploads';
    private const TTL_SECONDS = 7200;

    private function db(): mysqli
    {
        global $db_connection;
        if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
            throw new RuntimeException('DB no disponible ($db_connection).');
        }
        return $db_connection;
    }

    private function s3(): S3Client
    {
        return Config::getS3();
    }

    private function bucket(): string
    {
        return (string) Config::BUCKET;
    }

    private function userId(array $req): int
    {
        return (int) ($req['_user_id'] ?? $_SESSION['user_id'] ?? 0);
    }

    private function cleanupPending(): void
    {
        $now = time();
        $pending = $_SESSION[self::PENDING_KEY] ?? [];
        if (!is_array($pending)) {
            $_SESSION[self::PENDING_KEY] = [];
            return;
        }
        foreach ($pending as $token => $row) {
            $created = (int) ($row['created_at'] ?? 0);
            if ($created <= 0 || ($now - $created) > self::TTL_SECONDS) {
                unset($pending[$token]);
            }
        }
        $_SESSION[self::PENDING_KEY] = $pending;
    }

    public function init(array $req): array
    {
        $this->cleanupPending();

        $nombreOriginal = trim((string) ($req['nombre'] ?? ''));
        $rutaObjetivo = rtrim((string) ($req['ruta_objetivo'] ?? ''), '/') . '/';
        $userId = $this->userId($req);
        if ($nombreOriginal === '' || $rutaObjetivo === '/' || $userId <= 0) {
            throw new RuntimeException('Datos incompletos para iniciar la subida.');
        }

        $ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
        $key = $rutaObjetivo . $nombreEncriptado;

        $cmd = $this->s3()->getCommand('PutObject', [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ACL' => 'private',
        ]);
        $request = $this->s3()->createPresignedRequest($cmd, '+1 hour');

        $metadatos = json_encode([
            'ip_origen' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido',
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'ninguno',
            'fecha_servidor' => date('Y-m-d'),
            'hora_servidor' => date('H:i:s'),
            'usuario_envio' => (string) ($req['_usuario'] ?? 'usuario'),
        ], JSON_UNESCAPED_UNICODE);

        $token = bin2hex(random_bytes(18));
        $_SESSION[self::PENDING_KEY][$token] = [
            'created_at' => time(),
            'user_id' => $userId,
            'Nombre' => $nombreOriginal,
            'Encriptado' => $nombreEncriptado,
            'Metadatos' => $metadatos,
            'Ruta' => $rutaObjetivo,
            'key' => $key,
        ];

        return [
            'url' => (string) $request->getUri(),
            'key' => $key,
            'ruta_objetivo' => $rutaObjetivo,
            'nombreOriginal' => $nombreOriginal,
            'nombreEncriptado' => $nombreEncriptado,
            'upload_token' => $token,
        ];
    }

    public function part(array $req): array
    {
        return ['ok' => true];
    }

    public function complete(array $req): array
    {
        $this->cleanupPending();

        $token = trim((string) ($req['upload_token'] ?? ''));
        $tamano = max(0, (int) ($req['tamano'] ?? 0));
        $userId = $this->userId($req);
        $pending = $token !== '' ? ($_SESSION[self::PENDING_KEY][$token] ?? null) : null;

        if (!is_array($pending)) {
            throw new RuntimeException('La sesión de subida expiró o no existe.');
        }
        if ((int) ($pending['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('La subida no pertenece al usuario actual.');
        }

        $repo = new FileS3Repository($this->db());
        $fileId = $repo->insertFile([
            'Nombre' => (string) $pending['Nombre'],
            'Encriptado' => (string) $pending['Encriptado'],
            'Tamano' => $tamano,
            'Metadatos' => $pending['Metadatos'] ?? null,
            'Ruta' => (string) $pending['Ruta'],
            'Found' => 1,
            'AccessType' => 'normal',
            'Fecha' => date('Y-m-d H:i:s'),
            'user_id_' => $userId,
        ]);

        unset($_SESSION[self::PENDING_KEY][$token]);

        return [
            'ok' => true,
            'file_id' => $fileId,
            'key' => (string) $pending['key'],
            'ruta_objetivo' => (string) $pending['Ruta'],
            'tamano' => $tamano,
        ];
    }
}
''')


# ---------------------------------------------------------------------------
# 5) Otros uploaders: jamás decidir destino desde $_SESSION['ruta_actual']
# ---------------------------------------------------------------------------
# DropboxUploader
p = DRIVE / 'upload/drivers/DropboxUploader.php'
text, enc = read(p)
text = normalize(text)
text = re.sub(r'''\n    private function rutaBase\(\)\n    \{.*?\n    \}\n\n    public function init''', '\n    public function init', text, count=1, flags=re.S)
text = text.replace(
    '        $rutaBase = $this->rutaBase();',
    "        $rutaBase = rtrim((string)($req['ruta_objetivo'] ?? ''), '/') . '/';\n        if ($rutaBase === '/') throw new RuntimeException('Falta ruta_objetivo');",
    1
)
text = text.replace("        $userId = (int)($_SESSION['user_id'] ?? 0);", "        $userId = (int)($req['_user_id'] ?? 0);", 1)
text = text.replace("'subido_por'  => $_SESSION['usuario'] ?? 'publico',", "'subido_por'  => (string)($req['_usuario'] ?? 'usuario'),", 1)
write(p, text, enc)

# RemoteUrlUploader
p = DRIVE / 'upload/drivers/RemoteUrlUploader.php'
text, enc = read(p)
text = normalize(text)
text = re.sub(r'''\n  private function carpetaSesion\(\): string \{.*?\n  \}\n''', '\n', text, count=1, flags=re.S)
text = text.replace(
    '    $carpeta = $this->carpetaSesion();',
    "    $carpeta = trim((string)($req['ruta_objetivo'] ?? ''), '/');\n    if ($carpeta === '') throw new RuntimeException('Falta ruta_objetivo');",
    1
)
text = text.replace("'usuario_envio'=> $_SESSION['usuario'] ?? 'publico',", "'usuario_envio'=> (string)($req['_usuario'] ?? 'usuario'),", 1)
text = text.replace("    $userId = (int)($_SESSION['user_id'] ?? 0);", "    $userId = (int)($req['_user_id'] ?? 0);", 1)
write(p, text, enc)

# Chunked15MBUploader
p = DRIVE / 'upload/drivers/Chunked15MBUploader.php'
text, enc = read(p)
text = normalize(text)
text = re.sub(r'''\n  private function carpetaSesion\(\): string \{.*?\n  \}\n''', '\n', text, count=1, flags=re.S)
text = text.replace(
    "  private function signature(string $filename, int $filesize): string {\n    return sha1($filename . '|' . $filesize);\n  }",
    "  private function signature(string $filename, int $filesize, string $route, int $userId): string {\n    return sha1($userId . '|' . $route . '|' . $filename . '|' . $filesize);\n  }",
    1
)
text = text.replace(
    "    $sig = $this->signature($filename, $filesize);\n\n    // key: usa carpeta de sesión + prefijo uploads/YYYYMMDD/\n    $carpeta = $this->carpetaSesion();",
    "    $userId = (int)($req['_user_id'] ?? 0);\n    $carpeta = trim((string)($req['ruta_objetivo'] ?? ''), '/');\n    if ($userId <= 0 || $carpeta === '') throw new RuntimeException('Usuario/ruta objetivo inválidos');\n    $sig = $this->signature($filename, $filesize, $carpeta, $userId);\n\n    // key: el destino queda congelado desde INIT",
    1
)
text = text.replace(
    "      'parts'    => [], // num => etag\n      'created'  => time(),",
    "      'parts'    => [], // num => etag\n      'user_id'  => $userId,\n      'ruta_objetivo' => rtrim($carpeta, '/') . '/',\n      'created'  => time(),",
    1
)
# Validar usuario del estado tanto en part como complete: insertar tras cargas de meta.
text = text.replace(
    "      $meta = $stateId !== '' ? $this->store->load($stateId) : null;\n      if ($meta) {",
    "      $meta = $stateId !== '' ? $this->store->load($stateId) : null;\n      if ($meta && (int)($meta['user_id'] ?? 0) !== (int)($req['_user_id'] ?? 0)) {\n        throw new RuntimeException('La subida multipart no pertenece al usuario actual.');\n      }\n      if ($meta) {",
    1
)
# Segundo meta corresponde complete
idx = text.find("    $meta = $stateId !== '' ? $this->store->load($stateId) : null;", text.find('public function complete'))
if idx == -1:
    raise SystemExit('No se encontró meta complete chunked')
old = "    $meta = $stateId !== '' ? $this->store->load($stateId) : null;\n    if ($meta) {"
segment = text[idx:]
if old not in segment:
    raise SystemExit('No se encontró bloque meta complete chunked')
segment = segment.replace(old, "    $meta = $stateId !== '' ? $this->store->load($stateId) : null;\n    if ($meta && (int)($meta['user_id'] ?? 0) !== (int)($req['_user_id'] ?? 0)) {\n      throw new RuntimeException('La subida multipart no pertenece al usuario actual.');\n    }\n    if ($meta) {", 1)
text = text[:idx] + segment
text = text.replace("    $userId = (int)($_SESSION['user_id'] ?? 0);", "    $userId = (int)($req['_user_id'] ?? 0);", 1)
text = text.replace("'usuario'   => $_SESSION['usuario'] ?? 'publico',", "'usuario'   => (string)($req['_usuario'] ?? 'usuario'),", 1)
write(p, text, enc)


# ---------------------------------------------------------------------------
# 6) Frontend: capturar el destino una vez por upload
# ---------------------------------------------------------------------------
write(DRIVE / 'js/upload-destination.js', r'''(() => {
  'use strict';

  function currentRoute() {
    const contextRoute = document.getElementById('archivosContexto')?.dataset?.rutaActual;
    const footerRoute = document.getElementById('footerRutaActual')?.textContent;
    return String(contextRoute || window.rutaActual || window.DRIVE_INITIAL_ROUTE || footerRoute || '').trim();
  }

  function capture() {
    const route = currentRoute();
    if (!route) {
      throw new Error('No se pudo determinar la carpeta destino de la subida.');
    }
    return route.endsWith('/') ? route : route + '/';
  }

  function sameRoute(a, b) {
    const norm = (v) => String(v || '').trim().replace(/\\/g, '/').replace(/\/+$/, '') + '/';
    return norm(a) === norm(b);
  }

  async function afterSuccess(route) {
    try { document.dispatchEvent(new Event('drive:storage-changed')); } catch (_) {}

    // Solo consultamos MySQL para refrescar la lista si el usuario sigue en
    // la misma carpeta donde terminó la subida.
    if (sameRoute(route, currentRoute()) && typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos({ ruta: route, pagina: 1 });
    }
  }

  window.DriveUploadDestination = Object.freeze({
    currentRoute,
    capture,
    sameRoute,
    afterSuccess
  });
})();
''')

# Dropzone: ruta por archivo capturada al agregarlo
write(DRIVE / 'js/subir-dropzone.js', r'''Dropzone.autoDiscover = false;

const API = window.UPLOAD_API || 'api/upload.php';

const drop = new Dropzone('#dropzonePublico', {
  url: `${API}?mode=dropbox&action=init`,
  paramName: 'file',
  addRemoveLinks: true,
  withCredentials: true,
  headers: { 'X-Requested-With': 'XMLHttpRequest' },

  addedfile(file) {
    try {
      file._driveTargetRoute = window.DriveUploadDestination.capture();
    } catch (error) {
      console.error(error);
      this.removeFile(file);
      alert(error.message || error);
    }
  },

  sending(file, xhr, formData) {
    const route = file._driveTargetRoute || window.DriveUploadDestination.capture();
    formData.append('ruta_objetivo', route);
  },

  async success(file, response) {
    console.log('✅ Archivo subido:', response);
    await window.DriveUploadDestination.afterSuccess(file._driveTargetRoute || '');
  },

  error(file, response) {
    console.error('❌ Error al subir:', response);
  }
});
''')

# subir.js: local + URL
p = DRIVE / 'js/subir.js'
text, enc = read(p)
text = normalize(text)
text = text.replace(
    "      setStatus(uploadResult, 'Subiendo...', 'primary');",
    "      const rutaObjetivo = window.DriveUploadDestination.capture();\n      setStatus(uploadResult, 'Subiendo a ' + rutaObjetivo + '...', 'primary');",
    1
)
old = """        // 1) Obtener URL firmada (backend usa la ruta de sesión)
        const nombre = encodeURIComponent(archivo.name);
        const resFirma = await fetch(API + '?mode=local_put&action=init&nombre=' + nombre, {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });"""
new = """        // 1) Obtener URL firmada con destino inmutable.
        const initParams = new URLSearchParams({
          mode: 'local_put',
          action: 'init',
          nombre: archivo.name,
          ruta_objetivo: rutaObjetivo
        });
        const resFirma = await fetch(API + '?' + initParams.toString(), {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });"""
if old not in text:
    raise SystemExit('No se encontró init local_put en subir.js')
text = text.replace(old, new, 1)
text = text.replace(
    "          body.append('nombreEncriptado', json.nombreEncriptado || '');\n          body.append('tamano', String(archivo.size || 0));",
    "          body.append('upload_token', json.upload_token || '');\n          body.append('tamano', String(archivo.size || 0));",
    1
)
text = text.replace(
    "        // 6) Refresca listado si existe\n        if (typeof window.actualizarBloqueArchivos === 'function') {\n          window.actualizarBloqueArchivos();\n        }",
    "        // 6) Actualiza espacio y, solo si seguimos en el mismo destino, la lista DB.\n        await window.DriveUploadDestination.afterSuccess(rutaObjetivo);",
    1
)
text = text.replace(
    "    toggleButton(btnSubirUrl, true, spinnerUrl, btnTxtUrl, 'Subiendo...');\n    setStatus(uploadUrlResult, 'Descargando y subiendo a S3...', 'primary');",
    "    const rutaObjetivo = window.DriveUploadDestination.capture();\n    toggleButton(btnSubirUrl, true, spinnerUrl, btnTxtUrl, 'Subiendo...');\n    setStatus(uploadUrlResult, 'Descargando y subiendo a ' + rutaObjetivo + '...', 'primary');",
    1
)
text = text.replace(
    "      body.append('url', url);\n      body.append('u64', toBase64Utf8(url));",
    "      body.append('url', url);\n      body.append('u64', toBase64Utf8(url));\n      body.append('ruta_objetivo', rutaObjetivo);",
    1
)
text = text.replace(
    "        const qs = new URLSearchParams({ u64: toBase64Utf8(url) }).toString();",
    "        const qs = new URLSearchParams({ u64: toBase64Utf8(url), ruta_objetivo: rutaObjetivo }).toString();",
    1
)
text = text.replace(
    "      if (typeof window.actualizarBloqueArchivos === 'function') {\n        window.actualizarBloqueArchivos();\n      }",
    "      await window.DriveUploadDestination.afterSuccess(rutaObjetivo);",
    1
)
write(p, text, enc)

# subir-chunked.js
p = DRIVE / 'js/subir-chunked.js'
text, enc = read(p)
text = normalize(text)
text = text.replace('    let stateId = null;\n', '    let stateId = null;\n    let rutaObjetivo = null;\n', 1)
text = text.replace(
    "        stateId = saved.stateId;\n        setStatus('Reanudando sesión anterior…', 'primary');",
    "        stateId = saved.stateId;\n        rutaObjetivo = saved.rutaObjetivo || (String(key).includes('/') ? String(key).slice(0, String(key).lastIndexOf('/') + 1) : '');\n        setStatus('Reanudando sesión anterior en ' + rutaObjetivo + '…', 'primary');",
    1
)
text = text.replace(
    "        const initBody = new URLSearchParams();\n        initBody.append('filename', file.name);",
    "        rutaObjetivo = window.DriveUploadDestination.capture();\n        const initBody = new URLSearchParams();\n        initBody.append('ruta_objetivo', rutaObjetivo);\n        initBody.append('filename', file.name);",
    1
)
text = text.replace(
    "          createdAt: Date.now()",
    "          rutaObjetivo: rutaObjetivo,\n          createdAt: Date.now()",
    1
)
text = text.replace(
    "        signBody.append('step', 'sign');\n        signBody.append('uploadId', uploadId);",
    "        signBody.append('step', 'sign');\n        if (stateId) signBody.append('stateId', stateId);\n        signBody.append('uploadId', uploadId);",
    1
)
text = text.replace(
    "          etags: etags,\n          updatedAt: Date.now()",
    "          etags: etags,\n          rutaObjetivo: rutaObjetivo,\n          updatedAt: Date.now()",
    1
)
text = text.replace(
    "      // refrescar listado\n      if (typeof window.actualizarBloqueArchivos === 'function') {\n        window.actualizarBloqueArchivos();\n      }",
    "      // Actualiza solo lo necesario: espacio y lista si seguimos en el destino original.\n      await window.DriveUploadDestination.afterSuccess(rutaObjetivo);",
    1
)
write(p, text, enc)


# ---------------------------------------------------------------------------
# 7) s3.php: solo Archivos + Subir, y bloque de archivos siempre DB-driven
# ---------------------------------------------------------------------------
p = DRIVE / 's3.php'
text, enc = read(p)
text = normalize(text)
services_tab = '''  
  <li class="nav-item">
    <a class="nav-link" id="tab-servicios" data-toggle="tab"
       href="#pane-servicios" role="tab" aria-controls="pane-servicios"
       aria-selected="false">
      Servicios
    </a>
  </li>
'''
if services_tab not in text:
    raise SystemExit('No se encontró pestaña Servicios en s3.php')
text = text.replace(services_tab, '', 1)
text = text.replace("<!-- PESTAÑA servicios -->\n<div class=\"tab-pane fade\" id=\"pane-servicios\" role=\"tabpanel\" aria-labelledby=\"tab-servicios\"></div>\n", '', 1)
old_files = '''<?php if (!empty($archivosPaginados)): ?>
          <form id="multiDeleteForm" action="delete_multiple.php" method="POST" class="w-100">
            <input type="hidden" name="ruta" value="<?= htmlspecialchars($basePrefix) ?>">
            <div id="bloque-archivos">
              <?php
                $_GET['ruta'] = $basePrefix;
                include 'bloque_archivos.php';
              ?>
            </div>
          </form>
          
        <?php endif; ?>'''
new_files = '''<form id="multiDeleteForm" action="delete_multiple.php" method="POST" class="w-100">
          <input type="hidden" name="ruta" value="<?= htmlspecialchars($basePrefix) ?>">
          <div id="bloque-archivos">
            <?php
              $_GET['ruta'] = $basePrefix;
              include 'bloque_archivos.php';
            ?>
          </div>
        </form>'''
if old_files not in text:
    raise SystemExit('No se encontró condicional obsoleto de bloque_archivos en s3.php')
text = text.replace(old_files, new_files, 1)
old_scripts = '''<script src="js/soportesMediaTypes.js"></script>
<script src="js/subir-dropzone.js"></script>
<script src="js/subir.js"></script>'''
new_scripts = '''<script src="js/soportesMediaTypes.js"></script>
<script>
  window.UPLOAD_API = "api/upload.php";
  window.DRIVE_INITIAL_ROUTE = <?= json_encode($basePrefix) ?>;
  window.rutaActual = <?= json_encode($basePrefix) ?>;
</script>
<script src="js/upload-destination.js"></script>
<script src="js/subir-dropzone.js"></script>
<script src="js/subir.js"></script>'''
if old_scripts not in text:
    raise SystemExit('No se encontró bloque de scripts de subida en s3.php')
text = text.replace(old_scripts, new_scripts, 1)
old_dup = '''<script>
  // Ruta relativa desde s3.php (s3v2/s3.php) hacia el API (s3v2/api/upload.php)
  window.UPLOAD_API = "api/upload.php";
</script>
<!-- Cargar JS chunked -->
<script src="js/subir-chunked.js"></script>

<script>
window.rutaActual = <?= json_encode($basePrefix) ?>;
</script>'''
new_dup = '''<!-- Cargar JS chunked -->
<script src="js/subir-chunked.js"></script>'''
if old_dup not in text:
    raise SystemExit('No se encontró bloque duplicado UPLOAD_API/rutaActual')
text = text.replace(old_dup, new_dup, 1)
write(p, text, enc)


# ---------------------------------------------------------------------------
# 8) Navegación: una sola petición; bloque_archivos fija sesión y consulta DB
# ---------------------------------------------------------------------------
p = DRIVE / 'js/carpetas.js'
text, enc = read(p)
text = normalize(text)
text = re.sub(r'''\n  async function actualizarRutaSesion\(ruta\) \{.*?\n  \}\n\n  async function refrescarBloqueArchivosCompat''', '\n  async function refrescarBloqueArchivosCompat', text, count=1, flags=re.S)
text = text.replace('  await actualizarRutaSesion(ruta);\n\n', '', 1)
# URL actualizarRuta queda fuera del flujo; quitar config si existe.
text = text.replace("      actualizarRuta: 'actualizar_ruta.php',\n", '', 1)
write(p, text, enc)

p = DRIVE / 'js/archivos.js'
text, enc = read(p)
text = normalize(text)
text = text.replace("  const URL_SET_RUTA = 'actualizar_ruta.php';\n", '', 1)
text = re.sub(r'''\n  async function setRutaSesion\(ruta\) \{.*?\n  \}\n\n  async function abrirCarpetaSinRefresco''', '\n  async function abrirCarpetaSinRefresco', text, count=1, flags=re.S)
text = text.replace('    await setRutaSesion(ruta);\n\n', '', 1)
write(p, text, enc)

# Endpoint de compatibilidad: seguro, OOP y sin consultar BD/S3.
write(DRIVE / 'actualizar_ruta.php', r'''<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/app_bootstrap.php';

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión inválida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = (string) ($_POST['ruta'] ?? $_POST['rutaNueva'] ?? '');
if (trim($input) === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Ruta vacía'], JSON_UNESCAPED_UNICODE);
    exit;
}

$route = $app->userStoragePath()->normalizeForUser($input, $session->userId());
$_SESSION['ruta_actual'] = $route;

echo json_encode(['ok' => true, 'ruta' => $route], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
''')


# ---------------------------------------------------------------------------
# 9) Listado sin miniaturas automáticas: cero GET/HEAD S3 al navegar
# ---------------------------------------------------------------------------
p = DRIVE / 'bloque_archivos.php'
text, enc = read(p)
text = normalize(text)
text = text.replace("      $uid    = (int)($row['user_id_'] ?? 1);\n", '', 1)
text = text.replace("      $thumbUrl = \"thumb.php?key={$s3keyQ}&uid={$uid}&w=128&h=128\";\n", '', 1)
text = text.replace("      $placeholder = \"img/loading.gif\";\n", '', 1)
text = text.replace(' <?= $clsSec ?> is-pending"', ' <?= $clsSec ?> is-ready"', 1)
old_img = '''          <img
            class="thumb-img js-thumb"
            src="img/loading.gif"
            data-thumb="<?= FileViewHelper::escape($thumbUrl) ?>"
            width="32" height="32"
            loading="lazy"
            alt=""
          >'''
new_img = '''          <img
            class="thumb-img"
            src="img/file.png"
            width="32" height="32"
            loading="lazy"
            alt="Archivo"
          >'''
if old_img not in text:
    raise SystemExit('No se encontró img thumbnail en bloque_archivos')
text = text.replace(old_img, new_img, 1)
# Loader: ya no espera S3/miniaturas.
pattern = r'''function initLoaderBloqueArchivos\(\) \{.*?\n\}\n\n/\* -------------------------\n   6\) Inicialización general'''
replacement = '''function initLoaderBloqueArchivos() {
  const wrap = document.getElementById('archivosWrap');
  const overlay = document.getElementById('archivosLoaderOverlay');
  const backdrop = document.getElementById('archivosLoaderBackdrop');
  if (wrap) {
    wrap.querySelectorAll('li.file-row').forEach(row => {
      row.classList.remove('is-pending');
      row.classList.add('is-ready');
    });
    wrap.classList.remove('is-loading');
  }
  if (overlay) overlay.style.display = 'none';
  if (backdrop) backdrop.style.display = 'none';
}

/* -------------------------
   6) Inicialización general'''
out, n = re.subn(pattern, replacement, text, count=1, flags=re.S)
if n != 1:
    raise SystemExit(f'No se pudo simplificar loader bloque_archivos: {n}')
text = out
# Eliminar IIFE final que hacía GET de todas las miniaturas.
pattern = r'''\n<script>\n\(async function \(\) \{\n  const MAX_TRIES = 12;.*?</script>\s*$'''
out, n = re.subn(pattern, '\n', text, count=1, flags=re.S)
if n != 1:
    raise SystemExit(f'No se pudo eliminar loader S3 final: {n}')
write(p, out, enc)


# ---------------------------------------------------------------------------
# 10) Sincronización: S3 solo al pulsar manualmente, sin sync_status automático
# ---------------------------------------------------------------------------
write(DRIVE / 'js/sincronizar.js', r'''(() => {
  'use strict';

  const btn = document.getElementById('btnSyncS3');
  const host = document.getElementById('syncStatus');
  if (!btn) return;

  function setStatus(message, kind = 'muted') {
    if (!host) return;
    host.className = 'mb-2 small ' + (
      kind === 'success' ? 'text-success' :
      kind === 'danger' ? 'text-danger' :
      kind === 'primary' ? 'text-primary' : 'text-muted'
    );
    host.textContent = message;
  }

  async function triggerSyncS3(event) {
    if (event) event.preventDefault();
    if (btn.disabled) return;

    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sincronizando…';
    setStatus('Comparando S3 con la base de datos…', 'primary');

    try {
      const response = await fetch('sync_s3_to_db.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const raw = await response.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (_) {}

      if (!response.ok || !data || data.ok !== true) {
        throw new Error((data && (data.error || data.message)) || `HTTP ${response.status}`);
      }

      setStatus('Sincronización completada.', 'success');

      // Solo después de una sincronización manual actualizamos las vistas DB.
      if (typeof window.actualizarBloqueArchivos === 'function') {
        await window.actualizarBloqueArchivos({ pagina: 1 });
      }
      if (typeof window.actualizarBloqueCarpetas === 'function') {
        await window.actualizarBloqueCarpetas();
      }
      try { document.dispatchEvent(new Event('drive:storage-changed')); } catch (_) {}
    } catch (error) {
      console.error(error);
      setStatus('No se pudo sincronizar: ' + (error.message || error), 'danger');
    } finally {
      btn.disabled = false;
      btn.innerHTML = oldHtml;
    }
  }

  btn.addEventListener('click', triggerSyncS3);
  window.triggerSyncS3 = triggerSyncS3;
})();
''')


# ---------------------------------------------------------------------------
# 11) CSS: borrar selector de pestaña Servicios ya eliminada
# ---------------------------------------------------------------------------
for css_name in ['styles.css', 'styles-old.css']:
    p = DRIVE / 'css' / css_name
    text, enc = read(p)
    text = normalize(text)
    text = text.replace('body.ui-theme #pane-servicios,\n', '')
    write(p, text, enc)

print('Drive DB-first optimization prepared successfully.')
