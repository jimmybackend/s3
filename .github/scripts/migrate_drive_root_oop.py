from pathlib import Path
import re
import subprocess
import sys

ROOT = Path.cwd()
SRC = ROOT / 'public_html' / 'drive'
DST = ROOT / 'drive'


def run(*args: str) -> None:
    subprocess.run(args, check=True)


def read_text(path: Path):
    data = path.read_bytes()
    try:
        return data.decode('utf-8'), 'utf-8'
    except UnicodeDecodeError:
        return data.decode('latin-1'), 'latin-1'


def write_text(path: Path, text: str, encoding: str = 'utf-8') -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(text.encode(encoding))


def create_oop_core() -> None:
    write_text(DST / 'src/Security/SessionManager.php', r'''<?php
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
        return isset($_SESSION['usuario']) && trim((string) $_SESSION['usuario']) !== '';
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
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    }

    public function userName(): string
    {
        return isset($_SESSION['usuario']) ? (string) $_SESSION['usuario'] : '';
    }
}
''')

    write_text(DST / 'src/Http/JsonResponse.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http;

final class JsonResponse
{
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = [], int $status = 200): void
    {
        self::send(['ok' => true] + $data, $status);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::send(['ok' => false, 'error' => $message] + $extra, $status);
    }
}
''')

    write_text(DST / 'src/Application/DrivePageViewModel.php', r'''<?php
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
    public int $totalPaginas = 1;
    public int $totalArchivos = 0;
    public float $pesoTotalMB = 0.0;
    public string $error = '';
    public array $extensionesUnicas = [];
    public array $playlistAudioAll = [];
    public array $playlistVideoAll = [];
    public bool $tieneAudio = false;
    public bool $tieneVideo = false;
    public array $archivosPaginados = [];
    public array $todasLasCarpetas = [];
    public array $imagenes = [];
    public array $folder = [];
    public array $ruta = [];
}
''')

    write_text(DST / 'src/Application/DrivePageService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

final class DrivePageService
{
    private \S3Manager $manager;

    public function __construct(\S3Manager $manager)
    {
        $this->manager = $manager;
    }

    public function preferencesRedirect(array &$session, array $query, string $method): ?string
    {
        if ($method !== 'GET' || !isset($query['preferencias'])) {
            return null;
        }

        $session['show_counts'] = isset($query['toggle_counts']);
        $session['show_metas'] = isset($query['toggle_metas']);
        $session['media_hidden'] = !isset($query['toggle_media']);
        $session['show_filters'] = filter_var(
            $query['toggle_filters'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        unset(
            $query['toggle_counts'],
            $query['toggle_metas'],
            $query['toggle_media'],
            $query['preferencias']
        );

        return 's3.php' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    public function build(array &$session, array $query): DrivePageViewModel
    {
        $vm = new DrivePageViewModel();
        $vm->showCounts = (bool) ($session['show_counts'] ?? false);
        $vm->showMetas = (bool) ($session['show_metas'] ?? false);
        $vm->mediaHidden = (bool) ($session['media_hidden'] ?? true);
        $vm->showFilters = (bool) ($session['show_filters'] ?? true);

        if (empty($session['ruta_actual'])) {
            $session['ruta_actual'] = \Config::RUTA_RAIZ;
        }

        $vm->basePrefix = rtrim((string) $session['ruta_actual'], '/') . '/';
        $vm->tipo = trim((string) ($query['tipo'] ?? ''));
        $vm->buscar = trim((string) ($query['buscar'] ?? ''));
        $vm->fechaInicio = trim((string) ($query['fecha_inicio'] ?? ''));
        $vm->fechaFin = trim((string) ($query['fecha_fin'] ?? ''));
        $vm->limite = max(1, (int) ($query['limite'] ?? 5));
        $vm->pagina = max(1, (int) ($query['pagina'] ?? 1));

        try {
            $archivos = $this->manager->listArchivos($vm->basePrefix, $vm->showMetas);

            $filtrados = array_filter(
                $archivos,
                static function (array $archivo) use ($vm): bool {
                    $nombre = basename((string) $archivo['Key']);
                    $fechaArchivo = $archivo['LastModified']->format('Y-m-d');
                    $ext = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));

                    if ($vm->buscar !== '' && stripos($nombre, $vm->buscar) === false) return false;
                    if ($vm->fechaInicio !== '' && $fechaArchivo < $vm->fechaInicio) return false;
                    if ($vm->fechaFin !== '' && $fechaArchivo > $vm->fechaFin) return false;
                    if ($vm->tipo !== '' && $ext !== $vm->tipo) return false;
                    return true;
                }
            );

            usort(
                $filtrados,
                static fn(array $a, array $b): int => $b['LastModified'] <=> $a['LastModified']
            );

            $vm->totalArchivos = count($filtrados);
            $vm->totalPaginas = max(1, (int) ceil($vm->totalArchivos / $vm->limite));
            $vm->pagina = min($vm->pagina, $vm->totalPaginas);
            $vm->archivosPaginados = array_slice(
                $filtrados,
                ($vm->pagina - 1) * $vm->limite,
                $vm->limite
            );

            $bytes = array_sum(array_map(
                static fn(array $archivo): int => (int) ($archivo['Size'] ?? 0),
                $vm->archivosPaginados
            ));
            $vm->pesoTotalMB = round($bytes / 1024 / 1024, 2);

            $vm->playlistAudioAll = array_values(array_filter(
                $vm->archivosPaginados,
                static function (array $archivo): bool {
                    $ext = strtolower((string) pathinfo((string) $archivo['Key'], PATHINFO_EXTENSION));
                    return in_array($ext, ['mp3', 'wav', 'ogg', 'opus', 'm4a'], true);
                }
            ));

            $vm->playlistVideoAll = array_values(array_filter(
                $vm->archivosPaginados,
                static function (array $archivo): bool {
                    $ext = strtolower((string) pathinfo((string) $archivo['Key'], PATHINFO_EXTENSION));
                    return in_array($ext, ['mp4', 'webm', 'ogg'], true);
                }
            ));

            $vm->imagenes = array_values(array_filter(
                $vm->archivosPaginados,
                static function (array $archivo): bool {
                    $ext = strtolower((string) pathinfo((string) $archivo['Key'], PATHINFO_EXTENSION));
                    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
                }
            ));

            $vm->tieneAudio = $vm->playlistAudioAll !== [];
            $vm->tieneVideo = $vm->playlistVideoAll !== [];
            $vm->todasLasCarpetas = $this->manager->obtenerTodasLasCarpetas();

            $extensiones = [];
            foreach ($archivos as $archivo) {
                $ext = strtolower((string) pathinfo(
                    basename((string) $archivo['Key']),
                    PATHINFO_EXTENSION
                ));
                if ($ext !== '') {
                    $extensiones[$ext] = true;
                }
            }
            $vm->extensionesUnicas = array_keys($extensiones);
            sort($vm->extensionesUnicas);
        } catch (\Throwable $e) {
            $vm->error = 'Error al filtrar archivos: ' . $e->getMessage();
        }

        return $vm;
    }
}
''')

    write_text(DST / 'src/Core/DriveApplication.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Core;

use ArcadeCloud\Drive\Application\DrivePageService;
use ArcadeCloud\Drive\Security\SessionManager;
use Aws\S3\S3Client;
use mysqli;

final class DriveApplication
{
    private mysqli $db;
    private S3Client $s3;
    private string $bucket;
    private ?\S3Manager $s3Manager = null;
    private ?SessionManager $session = null;
    private ?DrivePageService $drivePageService = null;

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
        if ($this->session === null) {
            $this->session = new SessionManager();
        }
        return $this->session;
    }

    public function s3Manager(): \S3Manager
    {
        if ($this->s3Manager === null) {
            require_once dirname(__DIR__, 2) . '/S3Manager.php';
            $this->s3Manager = new \S3Manager($this->s3, $this->db, $this->bucket);
        }
        return $this->s3Manager;
    }

    public function drivePageService(): DrivePageService
    {
        if ($this->drivePageService === null) {
            $this->drivePageService = new DrivePageService($this->s3Manager());
        }
        return $this->drivePageService;
    }
}
''')

    write_text(DST / 'app_bootstrap.php', r'''<?php
declare(strict_types=1);

/**
 * Bootstrap único de ArcadeCloud Drive.
 *
 * raíz_proyecto/
 * ├── vendor/
 * ├── Config-s3.php
 * ├── db.php
 * └── drive/
 *
 * Desde drive/ todas las dependencias privadas están una carpeta atrás.
 */

if (defined('APP_BOOTSTRAP_LOADED')) {
    return;
}

$PROJECT_ROOT = realpath(dirname(__DIR__));
if ($PROJECT_ROOT === false) {
    throw new RuntimeException('No se pudo resolver la raíz del proyecto.');
}

$autoloadPath = $PROJECT_ROOT . '/vendor/autoload.php';
$configPath = $PROJECT_ROOT . '/Config-s3.php';
$dbPath = $PROJECT_ROOT . '/db.php';

foreach ([
    'Composer' => $autoloadPath,
    'Configuración' => $configPath,
    'Base de datos' => $dbPath,
] as $nombre => $ruta) {
    if (!is_file($ruta)) {
        throw new RuntimeException($nombre . ' no encontrado: ' . $ruta);
    }
}

putenv('AWS_EC2_METADATA_DISABLED=true');
require_once $autoloadPath;
require_once $configPath;
require_once $dbPath;

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    throw new RuntimeException('La conexión mysqli $db_connection no fue inicializada por db.php.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$driveApplication = \ArcadeCloud\Drive\Core\DriveApplication::boot($db_connection);

function drive_app(): \ArcadeCloud\Drive\Core\DriveApplication
{
    global $driveApplication;
    if (!$driveApplication instanceof \ArcadeCloud\Drive\Core\DriveApplication) {
        throw new RuntimeException('DriveApplication no fue inicializada.');
    }
    return $driveApplication;
}

define('APP_BOOTSTRAP_LOADED', true);
''')


def patch_s3_manager() -> None:
    path = DST / 'S3Manager.php'
    text, enc = read_text(path)
    normalized = text.replace('\r\n', '\n')
    old = '''    public function __construct()\n    {\n        $this->s3 = Config::getS3();\n        $this->bucket = Config::BUCKET;\n\n        global $db_connection;\n        $this->db = $db_connection;\n\n        if (!$this->db instanceof mysqli) {\n            throw new RuntimeException('No existe una conexión mysqli válida en $db_connection');\n        }\n    }'''
    new = '''    public function __construct(?\\Aws\\S3\\S3Client $s3 = null, ?mysqli $db = null, ?string $bucket = null)\n    {\n        $this->s3 = $s3 ?? Config::getS3();\n        $this->bucket = $bucket ?? Config::BUCKET;\n\n        if ($db === null) {\n            global $db_connection;\n            $db = $db_connection ?? null;\n        }\n\n        if (!$db instanceof mysqli) {\n            throw new RuntimeException('No existe una conexión mysqli válida para S3Manager.');\n        }\n\n        $this->db = $db;\n    }'''
    if old not in normalized:
        raise RuntimeError('No se encontró el constructor esperado de S3Manager.php')
    write_text(path, normalized.replace(old, new, 1), enc)


def patch_s3_page() -> None:
    path = DST / 's3.php'
    text, enc = read_text(path)
    marker = text.find('?>')
    if marker < 0:
        raise RuntimeError('No se encontró el cierre PHP inicial de s3.php')

    head = r'''<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/utils/helpers.php';

$app = drive_app();
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');

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

$vm = $pageService->build($_SESSION, $_GET);

// Adaptador temporal de la vista heredada. La lógica ya vive en objetos.
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
$playlistAudioAll = $vm->playlistAudioAll;
$playlistVideoAll = $vm->playlistVideoAll;
$tieneAudio = $vm->tieneAudio;
$tieneVideo = $vm->tieneVideo;
$totalPaginas = $vm->totalPaginas;
$totalArchivos = $vm->totalArchivos;
$archivosPaginados = $vm->archivosPaginados;
$todasLasCarpetas = $vm->todasLasCarpetas;
$imagenes = $vm->imagenes;
$folder = $vm->folder;
$ruta = $vm->ruta;
$pesoTotalMB = $vm->pesoTotalMB;
$manager = $app->s3Manager();
$s3 = $app->s3();
$bucket = $app->bucket();
?>'''

    text = head + text[marker + 2:]
    text = re.sub(
        r'\s*<li class=["\']nav-item["\']>\s*<a[^>]+id=["\']tab-bitacora["\'][\s\S]*?</li>',
        '',
        text,
        flags=re.I,
    )
    text = re.sub(
        r'\s*<!--\s*PESTAÑA Bitacora\s*-->[\s\S]*?(?=<!--\s*PESTAÑA servicios\s*-->)',
        '\n',
        text,
        flags=re.I,
    )
    text = re.sub(
        r'\s*<!--\s*<script src=["\']js/calls\.js["\']></script>[\s\S]*?-->',
        '',
        text,
        flags=re.I,
    )
    text = re.sub(r'\n{4,}', '\n\n\n', text)
    write_text(path, text, enc)


def clean_video_call_residue() -> None:
    for rel in ('calls.php', 'videollamada.php', 'js/calls.js'):
        path = DST / rel
        if path.exists():
            run('git', 'rm', '-f', str(path.relative_to(ROOT)))

    for css_name in ('css/styles.css', 'css/styles-old.css'):
        path = DST / css_name
        if not path.exists():
            continue
        text, enc = read_text(path)
        text = re.sub(
            r'/\*\s*Bitácora específicamente\s*\*/\s*body\.ui-theme\s+#bitacoraBody\s*\{[\s\S]*?\}\s*',
            '',
            text,
            flags=re.I,
        )
        text = re.sub(r'^.*#bitacoraBody.*\r?\n', '', text, flags=re.I | re.M)
        write_text(path, text, enc)

    readme = ROOT / 'README.md'
    if readme.exists():
        text, enc = read_text(readme)
        text = re.sub(
            r'\n### Video Llamada\n[\s\S]*?(?=\n---\n)',
            '',
            text,
            flags=re.I,
        )
        write_text(readme, text, enc)

    sql = ROOT / 'adbbmis1_Cloud.sql'
    if sql.exists():
        text, enc = read_text(sql)
        text = re.sub(
            r'(?ms)^--\s*\n-- Estructura de tabla para la tabla `calls`\s*\n--\s*\n\s*CREATE TABLE `calls` \([\s\S]*?;\s*\n',
            '',
            text,
        )
        text = re.sub(r'(?ms)^ALTER TABLE `calls`\s*[\s\S]*?;\s*\n', '', text)
        text = re.sub(r'(?m)^-- .*`calls`.*\n', '', text)
        text = re.sub(r'\n{4,}', '\n\n\n', text)
        write_text(sql, text, enc)


def clean_old_paths() -> None:
    files = list(DST.rglob('*.php')) + list(DST.rglob('*.md')) + [ROOT / 'README.md']
    for path in files:
        if not path.exists():
            continue
        text, enc = read_text(path)
        text = text.replace('public_html/drive/', 'drive/')
        text = text.replace('public_html/drive', 'drive')
        write_text(path, text, enc)


def write_architecture() -> None:
    write_text(DST / 'ARCHITECTURE.md', '''# Arquitectura OOP de ArcadeCloud Drive

## Estructura

```text
raiz_proyecto/
├── vendor/
├── Config-s3.php
├── db.php
└── drive/
    ├── app_bootstrap.php
    ├── s3.php
    ├── S3Manager.php
    ├── src/
    │   ├── Core/
    │   ├── Application/
    │   ├── Security/
    │   └── Http/
    └── upload/
```

`drive/` es el DocumentRoot de la aplicación. `vendor/`, `Config-s3.php` y `db.php`
están exactamente una carpeta arriba y no deben exponerse por HTTP.

## Regla de diseño

El desarrollo nuevo y las refactorizaciones del Drive se implementan orientados a objetos:

- Los archivos PHP públicos son controladores o entrypoints delgados.
- La lógica de negocio vive en clases bajo `src/` o en módulos OOP existentes.
- `DriveApplication` actúa como composition root y centraliza dependencias compartidas.
- `SessionManager` encapsula sesión y autenticación.
- `DrivePageService` y `DrivePageViewModel` contienen la lógica de la página principal.
- `S3Manager` es un servicio de infraestructura y acepta inyección de S3, mysqli y bucket.
- `upload/` conserva Factory, drivers, repositories y storage orientados a objetos.
- Los endpoints JSON nuevos deben reutilizar `Http\\JsonResponse`.
- Los endpoints heredados se migran al patrón Controller -> Service -> Repository/Infrastructure.
- No debe agregarse nueva lógica de negocio procedural a los entrypoints.

## Compatibilidad

`s3.php` conserva temporalmente variables para alimentar el HTML heredado, pero filtros,
paginación, sesión y construcción de estado ya se obtienen mediante objetos. Esto permite
migrar los endpoints restantes por módulos sin romper de golpe la aplicación en producción.
''')


def audit() -> None:
    if not DST.is_dir():
        raise RuntimeError('drive/ no existe')
    if (ROOT / 'public_html').exists():
        raise RuntimeError('public_html todavía existe')

    for rel in ('calls.php', 'videollamada.php', 'js/calls.js'):
        if (DST / rel).exists():
            raise RuntimeError(f'Residuo de videollamada: {rel}')

    required = [
        'src/Core/DriveApplication.php',
        'src/Application/DrivePageService.php',
        'src/Application/DrivePageViewModel.php',
        'src/Security/SessionManager.php',
        'src/Http/JsonResponse.php',
        'app_bootstrap.php',
        'ARCHITECTURE.md',
    ]
    for rel in required:
        if not (DST / rel).is_file():
            raise RuntimeError(f'Falta {rel}')

    forbidden = re.compile(r'calls\.php|videollamada\.php|receiverCall|bitacoraBody|js/calls\.js', re.I)
    for path in list(DST.rglob('*.php')) + list(DST.rglob('*.js')) + list(DST.rglob('*.css')) + [ROOT / 'README.md']:
        if path.exists():
            text, _ = read_text(path)
            if forbidden.search(text):
                raise RuntimeError(f'Referencia de videollamada encontrada en {path.relative_to(ROOT)}')

    sql = ROOT / 'adbbmis1_Cloud.sql'
    if sql.exists():
        text, _ = read_text(sql)
        if re.search(r'CREATE TABLE `calls`|ALTER TABLE `calls`|tabla `calls`', text, re.I):
            raise RuntimeError('El dump SQL conserva la definición de calls')

    for path in list(DST.rglob('*.php')) + list(DST.rglob('*.md')) + [ROOT / 'README.md']:
        if path.exists():
            text, _ = read_text(path)
            if 'public_html/drive' in text:
                raise RuntimeError(f'Ruta antigua encontrada en {path.relative_to(ROOT)}')

    bootstrap, _ = read_text(DST / 'app_bootstrap.php')
    for token in (
        "realpath(dirname(__DIR__))",
        "'/vendor/autoload.php'",
        "'/Config-s3.php'",
        "'/db.php'",
    ):
        if token not in bootstrap:
            raise RuntimeError(f'Bootstrap incompleto: {token}')

    s3_page, _ = read_text(DST / 's3.php')
    if 'drive_app()' not in s3_page or 'drivePageService()' not in s3_page:
        raise RuntimeError('s3.php no usa el flujo OOP')

    php_files = list(DST.rglob('*.php')) + [ROOT / 'Config-s3.php', ROOT / 'db.php']
    for path in php_files:
        subprocess.run(['php', '-l', str(path)], check=True, stdout=subprocess.DEVNULL)


def migrate() -> None:
    if not SRC.is_dir():
        raise RuntimeError('No existe public_html/drive')
    if DST.exists():
        raise RuntimeError('Ya existe drive/ en la raíz')

    run('git', 'mv', str(SRC.relative_to(ROOT)), str(DST.relative_to(ROOT)))
    public_html = ROOT / 'public_html'
    if public_html.exists():
        public_html.rmdir()

    clean_video_call_residue()
    create_oop_core()
    patch_s3_manager()
    patch_s3_page()
    clean_old_paths()
    write_architecture()
    audit()


if __name__ == '__main__':
    try:
        migrate()
        print('MIGRATION_AUDIT_OK')
    except Exception as exc:
        print(f'MIGRATION_ERROR: {exc}', file=sys.stderr)
        raise
