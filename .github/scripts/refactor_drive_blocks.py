from pathlib import Path
import re
import subprocess

ROOT = Path.cwd()
DRIVE = ROOT / 'drive'


def run(*args: str) -> None:
    subprocess.run(args, check=True)


def read(path: Path):
    data = path.read_bytes()
    try:
        return data.decode('utf-8'), 'utf-8'
    except UnicodeDecodeError:
        return data.decode('latin-1'), 'latin-1'


def write(path: Path, text: str, encoding: str = 'utf-8') -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(text.encode(encoding))


def remove_include(path: Path, include_text: str) -> None:
    text, enc = read(path)
    normalized = text.replace('\r\n', '\n')
    before = normalized
    normalized = normalized.replace(include_text + '\n', '')
    if normalized == before:
        raise SystemExit(f'Include no encontrado en {path}: {include_text}')
    write(path, normalized, enc)


# ---------------------------------------------------------------------------
# 1) Fuente única de raíz del usuario
# ---------------------------------------------------------------------------
config = ROOT / 'Config-s3.php'
text, enc = read(config)
normalized = text.replace('\r\n', '\n')
normalized = normalized.replace("public const RUTA_RAIZ      = 'Datos/';", "public const RUTA_RAIZ      = 'Data/';")
normalized = normalized.replace("public const RUTA_COMPARTIDA = 'Datos/Compartidos/';", "public const RUTA_COMPARTIDA = 'Data/Compartidos/';")
write(config, normalized, enc)


# ---------------------------------------------------------------------------
# 2) Eliminar utils/helpers.php: formatoPeso() no tiene consumidores reales
# ---------------------------------------------------------------------------
for rel in ['s3.php', 'sync_status.php', 'sync_s3_to_db.php']:
    remove_include(DRIVE / rel, "require_once __DIR__ . '/utils/helpers.php';")

helper = DRIVE / 'utils/helpers.php'
if helper.exists():
    run('git', 'rm', str(helper.relative_to(ROOT)))
utils_dir = DRIVE / 'utils'
if utils_dir.exists() and not any(utils_dir.iterdir()):
    utils_dir.rmdir()


# ---------------------------------------------------------------------------
# 3) Clases OOP nuevas
# ---------------------------------------------------------------------------
write(DRIVE / 'src/Storage/UserStoragePath.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

final class UserStoragePath
{
    public function rootForUser(int $userId): string
    {
        if ($userId <= 1) {
            return \Config::RUTA_RAIZ;
        }

        return 'Data' . $userId . '/';
    }

    public function normalizeForUser(string $path, int $userId): string
    {
        $root = $this->rootForUser($userId);
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('~/+~', '/', $path) ?? '';
        $path = ltrim($path, '/');

        if ($path === '' || $path === '/') {
            return $root;
        }

        if (preg_match('~(^|/)\.\.(/|$)~', $path)) {
            return $root;
        }

        if (strpos($path, $root) === 0) {
            return rtrim($path, '/') . '/';
        }

        // Nunca permitimos saltar a la raíz de otro usuario.
        if (preg_match('~^Data\d*/~i', $path) || strpos($path, 'Data/') === 0) {
            return $root;
        }

        return $root . trim($path, '/') . '/';
    }
}
''')

write(DRIVE / 'src/View/FileViewHelper.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FileViewHelper
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function formatBytes(int|float $bytes, int $decimals = 2): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        if ($index === 0) {
            return (string) ((int) $bytes) . ' B';
        }

        return number_format($bytes, $decimals, '.', '') . ' ' . $units[$index];
    }

    public static function extension(string $name): string
    {
        return strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    }

    public static function buildS3Key(string $route, string $encryptedName): string
    {
        $route = rtrim(str_replace('\\', '/', trim($route)), '/') . '/';
        $encryptedName = ltrim(str_replace('\\', '/', trim($encryptedName)), '/');

        if ($encryptedName === '') {
            return '';
        }

        if (strpos($encryptedName, $route) === 0) {
            return $encryptedName;
        }

        return $route . $encryptedName;
    }

    public static function isEncrypted(array $row): bool
    {
        return (string) ($row['Nombre'] ?? '') !== (string) ($row['Encriptado'] ?? '');
    }

    public static function isLocked(array $row): bool
    {
        return (string) ($row['AccessType'] ?? 'normal') === 'secure';
    }

    public static function hasSecurity(array $row): bool
    {
        $accessType = (string) ($row['AccessType'] ?? 'normal');
        $passwordHash = (string) ($row['PasswordHash'] ?? '');

        return in_array($accessType, ['secure', 'unlocked'], true) || $passwordHash !== '';
    }

    public static function metadataTooltip(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return 'Sin metadatos';
        }

        $raw = trim($raw);
        $decoded = json_decode($raw, true);
        $pretty = json_last_error() === JSON_ERROR_NONE
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $raw;

        $length = function_exists('mb_strlen') ? mb_strlen($pretty) : strlen($pretty);
        if ($length > 2000) {
            $pretty = function_exists('mb_substr') ? mb_substr($pretty, 0, 2000) : substr($pretty, 0, 2000);
            $pretty .= '…';
        }

        $pretty = htmlspecialchars($pretty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return str_replace(["\r\n", "\r", "\n"], '&#10;', $pretty);
    }
}
''')

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

        $total = $this->count("SELECT COUNT(*) FROM FileS3 WHERE {$where}", $types, $params);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $folderStmt = $this->prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(Tamano),0) AS s FROM FileS3 WHERE user_id_ = ? AND Ruta = ? AND Found = 1'
        );
        $folderParams = [$userId, $route];
        $this->bind($folderStmt, 'is', $folderParams);
        $folderStmt->execute();
        $folderRow = $folderStmt->get_result()->fetch_assoc() ?: [];
        $folderStmt->close();

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
            'folder_total' => (int) ($folderRow['n'] ?? 0),
            'folder_bytes' => (int) ($folderRow['s'] ?? 0),
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

    private function count(string $sql, string $types, array $params): int
    {
        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, $params);
        $stmt->execute();
        $stmt->bind_result($value);
        $stmt->fetch();
        $stmt->close();
        return (int) $value;
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

write(DRIVE / 'src/Storage/StorageUsageService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use mysqli;
use RuntimeException;

final class StorageUsageService
{
    private const SESSION_KEY = 'drive_storage_usage';

    public function __construct(private mysqli $db, private int $ttlSeconds = 300)
    {
    }

    public function getUsage(int $userId, bool $forceRefresh = false): array
    {
        if ($userId <= 0) {
            return $this->payload(0);
        }

        $cached = $_SESSION[self::SESSION_KEY][$userId] ?? null;
        if (!$forceRefresh && is_array($cached)) {
            $cachedAt = (int) ($cached['cached_at'] ?? 0);
            if ($cachedAt > 0 && (time() - $cachedAt) < $this->ttlSeconds) {
                return $cached;
            }
        }

        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(Tamano),0) FROM FileS3 WHERE user_id_ = ? AND Found = 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar el espacio usado: ' . $this->db->error);
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($bytes);
        $stmt->fetch();
        $stmt->close();

        $payload = $this->payload((int) $bytes);
        $_SESSION[self::SESSION_KEY][$userId] = $payload;
        return $payload;
    }

    public function invalidate(int $userId): void
    {
        unset($_SESSION[self::SESSION_KEY][$userId]);
    }

    private function payload(int $bytes): array
    {
        return [
            'bytes' => max(0, $bytes),
            'formatted' => $this->formatBytes(max(0, $bytes)),
            'cached_at' => time(),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $value = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        if ($index === 0) {
            return (string) $bytes . ' B';
        }
        return number_format($value, 2, '.', '') . ' ' . $units[$index];
    }
}
''')

write(DRIVE / 'src/View/FolderTreeRenderer.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use mysqli;
use RuntimeException;

final class FolderTreeRenderer
{
    private array $children = [];
    private bool $loaded = false;

    public function __construct(
        private mysqli $db,
        private int $userId,
        private UserStoragePath $paths
    ) {
    }

    public function root(): string
    {
        return $this->paths->rootForUser($this->userId);
    }

    public function normalize(string $prefix): string
    {
        return $this->paths->normalizeForUser($prefix, $this->userId);
    }

    public function hasChildren(string $parent): bool
    {
        $this->load();
        $parent = $this->normalize($parent);
        return !empty($this->children[$parent]);
    }

    public function renderChildren(string $parent, string $active): string
    {
        $this->load();
        $parent = $this->normalize($parent);
        $active = $this->normalize($active);
        $rows = $this->children[$parent] ?? [];
        if ($rows === []) {
            return '';
        }

        $html = '<ul class="subfolders list-unstyled mb-0" data-parent="' . self::e($parent) . '">';
        foreach ($rows as $row) {
            $prefix = $row['prefix'];
            $name = $row['name'];
            $isActive = $prefix === $active;
            $isAncestor = strpos($active, $prefix) === 0 && $prefix !== $active;
            $hasKids = !empty($this->children[$prefix]);
            $display = ($isAncestor || $isActive) ? 'block' : 'none';

            $html .= '<li class="folder-item" data-prefix="' . self::e($prefix) . '">';
            $html .= '<div class="folder-row d-flex align-items-center">';
            $html .= '<span class="' . ($hasKids ? 'toggle' : 'toggle empty') . '" title="Expandir/contraer">' . ($hasKids ? '−' : '·') . '</span>';
            $html .= '<a href="#" class="folder' . ($isActive ? ' active' : '') . '" data-route="' . self::e($prefix) . '" data-ruta="' . self::e($prefix) . '">';
            $html .= '<i class="fas fa-folder mr-1"></i> <span class="name">' . self::e($name) . '</span></a>';
            $html .= '<div class="ml-auto btn-group btn-group-sm">';
            $html .= '<button type="button" class="btn btn-light btn-xs" title="Mover" data-toggle="modal" data-target="#modalMoverCarpeta" data-route="' . self::e($prefix) . '" data-name="' . self::e($name) . '"><i class="fas fa-arrows-alt"></i></button>';
            $html .= '<button type="button" class="btn btn-light btn-xs" title="Renombrar" data-toggle="modal" data-target="#modalRenombrar" data-actual="' . self::e($prefix) . '" data-nombre="' . self::e($name) . '"><i class="fas fa-i-cursor"></i></button>';
            $html .= '<button type="button" class="btn btn-light btn-xs text-danger" title="Eliminar" data-toggle="modal" data-target="#modalEliminarCarpeta" data-route="' . self::e($prefix) . '" data-name="' . self::e($name) . '"><i class="fas fa-trash"></i></button>';
            $html .= '</div></div>';
            $html .= '<div class="children" style="display:' . $display . '">';
            if ($hasKids) {
                $html .= $this->renderChildren($prefix, $active);
            }
            $html .= '</div></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $stmt = $this->db->prepare(
            'SELECT Prefix, ParentPrefix, Nombre FROM S3Folders WHERE user_id_ = ? AND Found = 1 ORDER BY Nombre ASC'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo cargar el árbol de carpetas: ' . $this->db->error);
        }

        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $root = $this->root();

        while ($row = $result->fetch_assoc()) {
            $prefix = $this->normalize((string) ($row['Prefix'] ?? ''));
            if ($prefix === $root) {
                continue;
            }

            $parentRaw = trim((string) ($row['ParentPrefix'] ?? ''));
            $parent = $parentRaw === '' ? $root : $this->normalize($parentRaw);
            $name = trim((string) ($row['Nombre'] ?? ''));
            if ($name === '') {
                $name = basename(rtrim($prefix, '/'));
            }

            $this->children[$parent][] = [
                'prefix' => $prefix,
                'name' => $name,
            ];
        }
        $stmt->close();
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
''')


# ---------------------------------------------------------------------------
# 4) DrivePageService/ViewModel sin listado S3 redundante
# ---------------------------------------------------------------------------
write(DRIVE / 'src/Application/DrivePageViewModel.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

final class DrivePageViewModel
{
    public bool $showCounts = false;
    public bool $showMetas = false;
    public bool $mediaHidden = true;
    public bool $showFilters = true;
    public string $basePrefix = '';
    public string $tipo = '';
    public string $buscar = '';
    public string $fechaInicio = '';
    public string $fechaFin = '';
    public int $limite = 5;
    public int $pagina = 1;
    public string $error = '';
    public array $extensionesUnicas = [];
}
''')

write(DRIVE / 'src/Application/DrivePageService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\UserStoragePath;

final class DrivePageService
{
    public function __construct(
        private FileListService $files,
        private UserStoragePath $paths
    ) {
    }

    public function preferencesRedirect(array &$session, array $query, string $method): ?string
    {
        if ($method !== 'GET' || !isset($query['preferencias'])) {
            return null;
        }

        $session['show_counts'] = isset($query['toggle_counts']);
        $session['show_metas'] = isset($query['toggle_metas']);
        $session['media_hidden'] = !isset($query['toggle_media']);
        $session['show_filters'] = filter_var($query['toggle_filters'] ?? false, FILTER_VALIDATE_BOOLEAN);

        unset($query['toggle_counts'], $query['toggle_metas'], $query['toggle_media'], $query['preferencias']);
        return 's3.php' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    public function build(array &$session, array $query, int $userId): DrivePageViewModel
    {
        $vm = new DrivePageViewModel();
        $vm->showCounts = (bool) ($session['show_counts'] ?? false);
        $vm->showMetas = (bool) ($session['show_metas'] ?? false);
        $vm->mediaHidden = (bool) ($session['media_hidden'] ?? true);
        $vm->showFilters = (bool) ($session['show_filters'] ?? true);

        $route = $this->paths->normalizeForUser((string) ($session['ruta_actual'] ?? ''), $userId);
        $session['ruta_actual'] = $route;
        $vm->basePrefix = $route;

        $vm->tipo = strtolower(trim((string) ($query['tipo'] ?? '')));
        $vm->buscar = trim((string) ($query['buscar'] ?? ''));
        $vm->fechaInicio = trim((string) ($query['fecha_inicio'] ?? ''));
        $vm->fechaFin = trim((string) ($query['fecha_fin'] ?? ''));
        $vm->limite = max(5, min(100, (int) ($query['limite'] ?? 5)));
        $vm->pagina = max(1, (int) ($query['pagina'] ?? 1));

        try {
            $vm->extensionesUnicas = $this->files->listExtensions($userId, $route);
        } catch (\Throwable $e) {
            $vm->error = 'No se pudieron cargar los tipos de archivo: ' . $e->getMessage();
        }

        return $vm;
    }
}
''')


# ---------------------------------------------------------------------------
# 5) Composition root con los nuevos servicios
# ---------------------------------------------------------------------------
write(DRIVE / 'src/Core/DriveApplication.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Core;

use ArcadeCloud\Drive\Application\DrivePageService;
use ArcadeCloud\Drive\Application\FileListService;
use ArcadeCloud\Drive\Security\SessionManager;
use ArcadeCloud\Drive\Storage\StorageUsageService;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\View\FolderTreeRenderer;
use Aws\S3\S3Client;
use mysqli;

final class DriveApplication
{
    private mysqli $db;
    private S3Client $s3;
    private string $bucket;
    private ?\S3Manager $s3Manager = null;
    private ?SessionManager $session = null;
    private ?FileListService $fileListService = null;
    private ?DrivePageService $drivePageService = null;
    private ?StorageUsageService $storageUsageService = null;
    private ?UserStoragePath $userStoragePath = null;

    private function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->s3 = \Config::getS3();
        $this->bucket = \Config::BUCKET;
    }

    public static function boot(mysqli $db): self
    {
        return new self($db);
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    public function s3(): S3Client
    {
        return $this->s3;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function session(): SessionManager
    {
        return $this->session ??= new SessionManager();
    }

    public function userStoragePath(): UserStoragePath
    {
        return $this->userStoragePath ??= new UserStoragePath();
    }

    public function s3Manager(): \S3Manager
    {
        if ($this->s3Manager === null) {
            require_once dirname(__DIR__, 2) . '/S3Manager.php';
            $this->s3Manager = new \S3Manager($this->s3, $this->db, $this->bucket);
        }
        return $this->s3Manager;
    }

    public function fileListService(): FileListService
    {
        return $this->fileListService ??= new FileListService($this->db);
    }

    public function drivePageService(): DrivePageService
    {
        return $this->drivePageService ??= new DrivePageService(
            $this->fileListService(),
            $this->userStoragePath()
        );
    }

    public function storageUsageService(): StorageUsageService
    {
        return $this->storageUsageService ??= new StorageUsageService($this->db);
    }

    public function folderTreeRenderer(int $userId): FolderTreeRenderer
    {
        return new FolderTreeRenderer($this->db, $userId, $this->userStoragePath());
    }
}
''')


# ---------------------------------------------------------------------------
# 6) s3.php: controlador delgado + footer data precalculada
# ---------------------------------------------------------------------------
s3 = DRIVE / 's3.php'
text, enc = read(s3)
marker = text.find('?>')
if marker < 0:
    raise SystemExit('No se encontró el cierre PHP inicial de s3.php')
body = text[marker + 2:]
head = r'''<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app_bootstrap.php';

$app = drive_app();
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');
$userId = $session->userId();

$pageService = $app->drivePageService();
$redirect = $pageService->preferencesRedirect(
    $_SESSION,
    $_GET,
    $_SERVER['REQUEST_METHOD'] ?? 'GET'
);
if ($redirect !== null) {
    header('Location: ' . $redirect);
    exit;
}

$vm = $pageService->build($_SESSION, $_GET, $userId);

$showCounts = $vm->showCounts;
$showMetas = $vm->showMetas;
$mediaHidden = $vm->mediaHidden;
$showFilters = $vm->showFilters;
$basePrefix = $vm->basePrefix;
$tipo = $vm->tipo;
$buscar = $vm->buscar;
$fechaInicio = $vm->fechaInicio;
$fechaFin = $vm->fechaFin;
$limite = $vm->limite;
$pagina = $vm->pagina;
$error = $vm->error;
$extensiones_unicas = $vm->extensionesUnicas;

$storageUsage = $app->storageUsageService()->getUsage($userId);
$footerRutaActual = $basePrefix;
$footerEspacioUsado = $storageUsage['formatted'];
?>'''
text = head + body
text = text.replace('<script src="js/actualizarbloquefooter.js"></script>\r\n', '')
text = text.replace('<script src="js/actualizarbloquefooter.js"></script>\n', '')
needle = '<script src="js/actualizar-hora.js"></script>'
if needle not in text:
    raise SystemExit('No se encontró actualizar-hora.js en s3.php')
text = text.replace(needle, needle + '\n<script src="js/storage-usage.js"></script>', 1)
write(s3, text, enc)


# ---------------------------------------------------------------------------
# 7) bloque_archivos.php: vista + servicios OOP, sin funciones PHP globales
# ---------------------------------------------------------------------------
block = DRIVE / 'bloque_archivos.php'
text, enc = read(block)
normalized = text.replace('\r\n', '\n')
html_marker = normalized.find('<div class="archivos-wrap')
if html_marker < 0:
    raise SystemExit('No se encontró archivos-wrap en bloque_archivos.php')
html = normalized[html_marker:]

new_head = r'''<?php
declare(strict_types=1);

use ArcadeCloud\Drive\View\FileViewHelper;

require_once __DIR__ . '/app_bootstrap.php';

$app = drive_app();
$sessionManager = $app->session();
$sessionManager->start();
$sessionManager->requireAuthenticated('index.php');
$userId = $sessionManager->userId();

$rutaGet = trim((string) ($_GET['ruta'] ?? ''));
if ($rutaGet !== '') {
    $_SESSION['ruta_actual'] = $app->userStoragePath()->normalizeForUser($rutaGet, $userId);
}

$rutaActual = $app->userStoragePath()->normalizeForUser(
    (string) ($_SESSION['ruta_actual'] ?? ''),
    $userId
);
$_SESSION['ruta_actual'] = $rutaActual;

$state = $app->fileListService()->load($userId, $rutaActual, $_GET);
$filas = $state['rows'];
$total = $state['total'];
$pagina = $state['page'];
$limite = $state['limit'];
$buscar = $state['search'];
$tipo = $state['type'];
$fechaInicio = $state['date_from'];
$fechaFin = $state['date_to'];
$carpetaTotal = $state['folder_total'];
$carpetaPesoMB = round($state['folder_bytes'] / 1048576, 2);

$imagenesExt = ['jpg','jpeg','png','gif','webp','bmp'];
$audioExt = ['mp3','wav','ogg','opus','m4a','aac'];
$videoExt = ['mp4','webm','mov','avi','mkv'];
$txtEditExt = ['txt','srt','vtt','md','html','css','js','php','py','json','csv','sql','jas'];
$textractExt = ['jpg','jpeg','png','tif','tiff','pdf'];
$traducirExt = ['txt','pdf','jpg','jpeg','png','tif','tiff'];
$analizarExt = ['jpg','jpeg','png','tif','tiff','bmp'];

$imagenesPagina = [];
$visibles = 0;
$noVisibles = 0;
$segurosAbiertos = 0;

foreach ($filas as $row) {
    $ext = FileViewHelper::extension((string) ($row['Nombre'] ?? ''));
    if (in_array($ext, $imagenesExt, true)) {
        $imagenesPagina[] = [
            'key' => FileViewHelper::buildS3Key(
                (string) ($row['Ruta'] ?? ''),
                (string) ($row['Encriptado'] ?? '')
            ),
            'nombre' => (string) ($row['Nombre'] ?? ''),
        ];
    }

    if (FileViewHelper::isLocked($row)) {
        $noVisibles++;
    } else {
        $visibles++;
        if (FileViewHelper::hasSecurity($row)) {
            $segurosAbiertos++;
        }
    }
}
?>

'''
html = re.sub(r'\bh\(', 'FileViewHelper::escape(', html)
html = re.sub(r'\bbytes_human\(', 'FileViewHelper::formatBytes(', html)
html = re.sub(r'\bext_de\(', 'FileViewHelper::extension(', html)
html = re.sub(r'\bbuild_file_s3_key\(', 'FileViewHelper::buildS3Key(', html)
html = re.sub(r'\bregistro_encriptado\(', 'FileViewHelper::isEncrypted(', html)
html = re.sub(r'\bregistro_bloqueado\(', 'FileViewHelper::isLocked(', html)
html = re.sub(r'\bregistro_tiene_seguridad\(', 'FileViewHelper::hasSecurity(', html)
html = re.sub(r'\btooltip_from_metadatos\(', 'FileViewHelper::metadataTooltip(', html)

# Eliminar la segunda implementación de eliminación individual: archivos.js es el dueño.
html = re.sub(
    r"\nwindow\.deleteOne = window\.deleteOne \|\| async function deleteOne\(archivo\) \{[\s\S]*?\n\};\n\n"
    r"document\.addEventListener\('click', function \(e\) \{[\s\S]*?\n\}\);\n",
    '\n',
    html,
    count=1
)

# Evitar acumulación de listeners globales de selección en cada refresco AJAX.
old_change = """  document.addEventListener('change', function(e){
    if (!e.target || !e.target.matches('input[name=\"archivos[]\"]')) return;
    updateCount();
  });"""
new_change = """  if (window.__bloqueArchivosSelectionHandler) {
    document.removeEventListener('change', window.__bloqueArchivosSelectionHandler);
  }
  window.__bloqueArchivosSelectionHandler = function(e){
    if (!e.target || !e.target.matches('input[name=\"archivos[]\"]')) return;
    updateCount();
  };
  document.addEventListener('change', window.__bloqueArchivosSelectionHandler);"""
if old_change not in html:
    raise SystemExit('No se encontró listener de selección en bloque_archivos.php')
html = html.replace(old_change, new_change, 1)

# Tras borrado múltiple, refrescar la métrica separada de almacenamiento.
needle_delete = """    if (typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos({ pagina: 1 });
    } else {
      location.reload();
    }"""
replacement_delete = needle_delete + """

    try { document.dispatchEvent(new Event('drive:storage-changed')); } catch (_) {}"""
# Solo la última ocurrencia corresponde a deleteSelected después de quitar deleteOne.
idx = html.rfind(needle_delete)
if idx < 0:
    raise SystemExit('No se encontró refresh de deleteSelected')
html = html[:idx] + replacement_delete + html[idx + len(needle_delete):]

write(block, new_head + html, enc)


# ---------------------------------------------------------------------------
# 8) bloque_carpetas.php: una sola consulta y renderer OOP
# ---------------------------------------------------------------------------
write(DRIVE / 'bloque_carpetas.php', r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

$app = drive_app();
$sessionManager = $app->session();
$sessionManager->start();
$sessionManager->requireAuthenticated('index.php');
$userId = $sessionManager->userId();

$tree = $app->folderTreeRenderer($userId);
$root = $tree->root();
$currentInput = (string) ($_GET['ruta_actual'] ?? $_SESSION['ruta_actual'] ?? $root);
$current = $tree->normalize($currentInput);
$_SESSION['ruta_actual'] = $current;

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div id="bloque-carpetas" class="card">
  <div class="card-header py-2 d-flex align-items-center">
    <strong><i class="fas fa-folder-open"></i> Carpetas</strong>
    <div class="ml-auto">
      <button type="button"
              class="btn btn-sm btn-outline-primary"
              data-toggle="modal"
              data-target="#modalCrearCarpeta"
              data-ruta="<?= $e($root) ?>">
        <i class="fas fa-folder-plus"></i> Nueva
      </button>
    </div>
  </div>

  <div class="card-body p-2">
    <ul id="arbolCarpetas" class="folder-tree list-unstyled mb-0" data-root="<?= $e($root) ?>">
      <li class="folder-item" data-prefix="<?= $e($root) ?>">
        <div class="folder-row d-flex align-items-center">
          <span class="toggle <?= $tree->hasChildren($root) ? '' : 'empty' ?>" title="Expandir/contraer">
            <?= $tree->hasChildren($root) ? '−' : '·' ?>
          </span>

          <a href="#"
             class="folder<?= $current === $root ? ' active' : '' ?>"
             data-route="<?= $e($root) ?>"
             data-ruta="<?= $e($root) ?>">
            <i class="fas fa-hdd mr-1"></i>
            <span class="name"><?= $e(rtrim($root, '/')) ?></span>
          </a>

          <div class="ml-auto btn-group btn-group-sm">
            <button type="button"
                    class="btn btn-light btn-xs"
                    title="Nueva subcarpeta"
                    data-toggle="modal"
                    data-target="#modalCrearCarpeta"
                    data-ruta="<?= $e($root) ?>">
              <i class="fas fa-folder-plus"></i>
            </button>
          </div>
        </div>

        <div class="children" style="display:block">
          <?= $tree->renderChildren($root, $current) ?>
        </div>
      </li>
    </ul>
  </div>
</div>
''')


# ---------------------------------------------------------------------------
# 9) actualizar_ruta.php usa la misma política de rutas OOP
# ---------------------------------------------------------------------------
write(DRIVE / 'actualizar_ruta.php', r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sin sesión'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ruta = trim((string) ($_POST['ruta'] ?? $_POST['rutaNueva'] ?? ''));
if ($ruta === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Ruta vacía'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ruta = $app->userStoragePath()->normalizeForUser($ruta, $session->userId());
$_SESSION['ruta_actual'] = $ruta;

echo json_encode(['ok' => true, 'ruta' => $ruta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
''')


# ---------------------------------------------------------------------------
# 10) Footer puro: sin bootstrap, SQL ni S3
# ---------------------------------------------------------------------------
write(DRIVE / 'bloque_footer.php', r'''<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rutaActualFooter = isset($footerRutaActual)
    ? (string) $footerRutaActual
    : (string) ($_SESSION['ruta_actual'] ?? 'Data/');

$espacioUsadoFooter = isset($footerEspacioUsado)
    ? (string) $footerEspacioUsado
    : '0 B';

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<footer class="d-flex justify-content-between align-items-center">
  <div class="text-muted small mr-3 d-flex align-items-center">
    <i class="fas fa-folder-open mr-1"></i>
    <strong id="footerRutaActual"><?= $e($rutaActualFooter) ?></strong>
  </div>

  <div class="text-muted small flex-shrink-0 ml-3">
    Espacio usado: <strong id="footerEspacioUsado" class="text-info"><?= $e($espacioUsadoFooter) ?></strong>
  </div>

  <div class="text-muted small flex-shrink-0 ml-3">
    <span id="relojFooter"><strong><?= date('Y-m-d H:i:s') ?></strong></span>
  </div>
</footer>
''')


# ---------------------------------------------------------------------------
# 11) Endpoint separado para refrescar espacio SOLO cuando cambia almacenamiento
# ---------------------------------------------------------------------------
write(DRIVE / 'storage_usage.php', r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Http\JsonResponse;

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    JsonResponse::error('Sin sesión', 401);
}

$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$usage = $app->storageUsageService()->getUsage($session->userId(), $force);

JsonResponse::ok([
    'bytes' => $usage['bytes'],
    'formatted' => $usage['formatted'],
    'cached_at' => $usage['cached_at'],
]);
''')

write(DRIVE / 'js/storage-usage.js', r'''(() => {
  'use strict';

  async function actualizarEspacioUsado(force = true) {
    const target = document.getElementById('footerEspacioUsado');
    if (!target) return;

    try {
      const url = 'storage_usage.php' + (force ? '?refresh=1' : '');
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.error || `HTTP ${response.status}`);
      }
      target.textContent = data.formatted || '0 B';
    } catch (error) {
      console.error('[storage-usage] No se pudo actualizar el espacio usado:', error);
    }
  }

  window.actualizarEspacioUsado = actualizarEspacioUsado;
  document.addEventListener('drive:storage-changed', () => actualizarEspacioUsado(true));
})();
''')

old_footer_js = DRIVE / 'js/actualizarbloquefooter.js'
if old_footer_js.exists():
    run('git', 'rm', str(old_footer_js.relative_to(ROOT)))


# ---------------------------------------------------------------------------
# 12) archivos.js: footer DOM-only; nunca fetch bloque_footer.php
# ---------------------------------------------------------------------------
path = DRIVE / 'js/archivos.js'
text, enc = read(path)
normalized = text.replace('\r\n', '\n')
normalized = normalized.replace("  const URL_FOOTER = 'bloque_footer.php';\n", '')
pattern = re.compile(
    r"  window\.actualizarBloqueFooter = window\.actualizarBloqueFooter \|\| \(async function \(args\) \{[\s\S]*?\n  \}\);",
    re.M
)
replacement = r'''  window.actualizarBloqueFooter = window.actualizarBloqueFooter || (async function (args) {
    const route = String(
      (args && (args.rutaNueva || args.ruta)) || window.rutaActual || ''
    ).trim();
    const routeNode = document.getElementById('footerRutaActual');
    if (routeNode && route) routeNode.textContent = route;
  });'''
normalized, count = pattern.subn(replacement, normalized, count=1)
if count != 1:
    raise SystemExit('No se pudo reemplazar actualizarBloqueFooter en archivos.js')

# Cuando la eliminación individual termina correctamente, refrescar sólo la métrica separada.
needle = """      // Refrescar igual que en mover, para que se vea realmente eliminado
      if (typeof window.actualizarBloqueArchivos === 'function') {"""
if needle not in normalized:
    raise SystemExit('No se encontró bloque de eliminación individual en archivos.js')
normalized = normalized.replace(
    needle,
    """      try { document.dispatchEvent(new Event('drive:storage-changed')); } catch (_) {}

      // Refrescar igual que en mover, para que se vea realmente eliminado
      if (typeof window.actualizarBloqueArchivos === 'function') {""",
    1
)
write(path, normalized, enc)


# ---------------------------------------------------------------------------
# 13) sincronización: al terminar, refresca métrica separada una sola vez
# ---------------------------------------------------------------------------
path = DRIVE / 'js/sincronizar.js'
text, enc = read(path)
normalized = text.replace('\r\n', '\n')
needle = """    await renderStatusFinal();
    showToast({ title: 'Sincronización', message: '✅ Sincronización completada.' });"""
if needle not in normalized:
    raise SystemExit('No se encontró éxito de sincronización')
normalized = normalized.replace(
    needle,
    needle + "\n    try { document.dispatchEvent(new Event('drive:storage-changed')); } catch (_) {}",
    1
)
write(path, normalized, enc)


# ---------------------------------------------------------------------------
# 14) Validación estructural antes de permitir commit
# ---------------------------------------------------------------------------
critical = [
    'thumb.php', 'ver_archivo.php', 'editor.php', 'descargar_archivo.php',
    'eliminar_archivo.php', 'descargar_zip.php', 'delete_multiple.php',
    'actualizar_ruta.php', 'listar_carpetas.php', 'mover_carpeta.php',
    'renombrar_carpeta.php', 'eliminar_carpeta.php', 'crear_carpeta.php',
    'storage_usage.php',
]
missing = [name for name in critical if not (DRIVE / name).is_file()]
if missing:
    raise SystemExit('Endpoints faltantes: ' + ', '.join(missing))

print('Refactor preparado correctamente.')
