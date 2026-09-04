from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
DRIVE = ROOT / 'drive'


def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8')


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'No se encontró bloque para: {label}')
    return text.replace(old, new, 1)

# -----------------------------------------------------------------------------
# 1) Resolver OOP de iconos por tipo de archivo.
# -----------------------------------------------------------------------------
write(DRIVE / 'src/View/FileIconResolver.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FileIconResolver
{
    /**
     * Devuelve un icono Font Awesome y una categoría visual estable.
     * Los formatos desconocidos siempre caen en un archivo genérico.
     *
     * @return array{icon:string, category:string, label:string}
     */
    public static function resolve(string $extension): array
    {
        $ext = strtolower(ltrim(trim($extension), '.'));

        $groups = [
            'pdf' => [
                'extensions' => ['pdf'],
                'icon' => 'fa-file-pdf',
                'label' => 'PDF',
            ],
            'word' => [
                'extensions' => ['doc', 'docx', 'docm', 'dot', 'dotx', 'odt', 'rtf'],
                'icon' => 'fa-file-word',
                'label' => 'Documento',
            ],
            'excel' => [
                'extensions' => ['xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'ods', 'csv'],
                'icon' => 'fa-file-excel',
                'label' => 'Hoja de cálculo',
            ],
            'powerpoint' => [
                'extensions' => ['ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'odp'],
                'icon' => 'fa-file-powerpoint',
                'label' => 'Presentación',
            ],
            'archive' => [
                'extensions' => ['zip', 'rar', '7z', 'tar', 'gz', 'gzip', 'tgz', 'bz', 'bz2', 'xz', 'zst', 'cab', 'jar', 'war'],
                'icon' => 'fa-file-archive',
                'label' => 'Archivo comprimido',
            ],
            'text' => [
                'extensions' => ['txt', 'jas', 'log', 'md', 'markdown', 'ini', 'cfg', 'conf', 'properties', 'nfo', 'srt', 'vtt'],
                'icon' => 'fa-file-lines',
                'label' => 'Texto',
            ],
            'code' => [
                'extensions' => [
                    'php', 'phtml', 'phar', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx',
                    'html', 'htm', 'xhtml', 'css', 'scss', 'sass', 'less',
                    'json', 'jsonl', 'xml', 'xsl', 'yaml', 'yml', 'toml', 'sql',
                    'py', 'pyw', 'java', 'class', 'c', 'cc', 'cpp', 'cxx', 'h', 'hpp',
                    'cs', 'go', 'rs', 'rb', 'swift', 'kt', 'kts', 'dart', 'lua',
                    'sh', 'bash', 'zsh', 'fish', 'ps1', 'bat', 'cmd', 'vue', 'svelte',
                    'graphql', 'gql', 'env'
                ],
                'icon' => 'fa-file-code',
                'label' => 'Código',
            ],
            'image' => [
                'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'heic', 'heif', 'tif', 'tiff', 'svg'],
                'icon' => 'fa-file-image',
                'label' => 'Imagen',
            ],
            'audio' => [
                'extensions' => ['mp3', 'wav', 'ogg', 'opus', 'm4a', 'aac', 'flac', 'wma', 'aiff', 'aif', 'mid', 'midi'],
                'icon' => 'fa-file-audio',
                'label' => 'Audio',
            ],
            'video' => [
                'extensions' => ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'mpeg', 'mpg', 'wmv', 'flv', '3gp', 'ts', 'mts', 'm2ts'],
                'icon' => 'fa-file-video',
                'label' => 'Video',
            ],
            'database' => [
                'extensions' => ['db', 'db3', 'sqlite', 'sqlite3', 'mdb', 'accdb', 'bak', 'dump'],
                'icon' => 'fa-database',
                'label' => 'Base de datos',
            ],
            'ebook' => [
                'extensions' => ['epub', 'mobi', 'azw', 'azw3', 'fb2'],
                'icon' => 'fa-book',
                'label' => 'Libro electrónico',
            ],
            'mail' => [
                'extensions' => ['eml', 'msg', 'mbox', 'pst', 'ost'],
                'icon' => 'fa-envelope',
                'label' => 'Correo',
            ],
            'font' => [
                'extensions' => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
                'icon' => 'fa-font',
                'label' => 'Fuente',
            ],
            'certificate' => [
                'extensions' => ['pem', 'crt', 'cer', 'key', 'pfx', 'p12', 'csr', 'der'],
                'icon' => 'fa-key',
                'label' => 'Certificado / clave',
            ],
            'package' => [
                'extensions' => ['apk', 'aab', 'ipa', 'exe', 'msi', 'dmg', 'pkg', 'deb', 'rpm', 'appimage', 'bin', 'iso'],
                'icon' => 'fa-cube',
                'label' => 'Paquete / ejecutable',
            ],
            'design' => [
                'extensions' => ['psd', 'psb', 'ai', 'eps', 'indd', 'sketch', 'fig'],
                'icon' => 'fa-pen-ruler',
                'label' => 'Diseño',
            ],
            'model' => [
                'extensions' => ['dwg', 'dxf', 'stl', 'obj', 'fbx', 'blend', 'gltf', 'glb', 'step', 'stp', 'iges', 'igs'],
                'icon' => 'fa-cubes',
                'label' => 'CAD / 3D',
            ],
        ];

        foreach ($groups as $category => $group) {
            if (in_array($ext, $group['extensions'], true)) {
                return [
                    'icon' => $group['icon'],
                    'category' => $category,
                    'label' => $group['label'],
                ];
            }
        }

        return [
            'icon' => 'fa-file',
            'category' => 'generic',
            'label' => $ext !== '' ? strtoupper($ext) : 'Archivo',
        ];
    }
}
''')

# -----------------------------------------------------------------------------
# 2) Servicio OOP de miniaturas: DB -> caché local privada -> S3 thumb -> original.
# -----------------------------------------------------------------------------
write(DRIVE / 'src/Media/ThumbnailService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\View\FileViewHelper;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class ThumbnailService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'tif', 'tiff'];

    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private string $localCacheDir
    ) {
        $this->localCacheDir = rtrim($this->localCacheDir, '/\\');
        if (!is_dir($this->localCacheDir)
            && !@mkdir($this->localCacheDir, 0770, true)
            && !is_dir($this->localCacheDir)) {
            throw new RuntimeException('No se pudo crear la caché privada de miniaturas.');
        }
    }

    /**
     * @return array{bytes:string,content_type:string,status:string,thumb_key:string}
     */
    public function get(int $userId, string $requestedKey, int $width, int $height, string $fit, array $session): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Sesión inválida para miniatura.');
        }

        $key = $this->normalizeKey($requestedKey);
        $extension = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
        if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('El archivo no admite miniatura de imagen.');
        }

        $width = max(24, min(512, $width));
        $height = max(24, min(512, $height));
        $fit = strtolower($fit) === 'contain' ? 'contain' : 'cover';

        $file = $this->lookupFile($userId, $key);
        if ($file === null) {
            throw new RuntimeException('Archivo no encontrado en FileS3.');
        }
        if (!$this->canPreview($file, $key, $session)) {
            throw new RuntimeException('Archivo protegido.');
        }

        $thumbKey = $this->thumbnailKey($key, $width, $height, $fit);
        $localPath = $this->localPath($userId, $thumbKey);

        $cached = $this->readLocal($localPath);
        if ($cached !== null) {
            return [
                'bytes' => $cached,
                'content_type' => 'image/jpeg',
                'status' => 'LOCAL_HIT',
                'thumb_key' => $thumbKey,
            ];
        }

        $this->ensureDirectory(dirname($localPath));
        $lockHandle = @fopen($localPath . '.lock', 'c');
        if ($lockHandle !== false) {
            @flock($lockHandle, LOCK_EX);
        }

        try {
            // Otro proceso pudo crearla mientras esperábamos el lock.
            $cached = $this->readLocal($localPath);
            if ($cached !== null) {
                return [
                    'bytes' => $cached,
                    'content_type' => 'image/jpeg',
                    'status' => 'LOCAL_HIT_AFTER_LOCK',
                    'thumb_key' => $thumbKey,
                ];
            }

            // No hacemos HEAD. Intentamos leer directamente la miniatura persistente.
            // Si existe en S3, esta es la única lectura S3 necesaria tras perder caché local.
            try {
                $object = $this->s3->getObject([
                    'Bucket' => $this->bucket,
                    'Key' => $thumbKey,
                ]);
                $bytes = (string)$object['Body'];
                if ($bytes !== '') {
                    $this->writeLocal($localPath, $bytes);
                    return [
                        'bytes' => $bytes,
                        'content_type' => 'image/jpeg',
                        'status' => 'S3_THUMB_HIT',
                        'thumb_key' => $thumbKey,
                    ];
                }
            } catch (AwsException $e) {
                if (!$this->isNotFound($e)) {
                    throw $e;
                }
            }

            // Primera vez real: leer original, generar una sola miniatura y persistirla.
            $original = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            $sourceBytes = (string)$original['Body'];
            if ($sourceBytes === '') {
                throw new RuntimeException('La imagen original está vacía.');
            }

            $thumbnailBytes = $this->resizeToJpeg($sourceBytes, $width, $height, $fit);

            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $thumbKey,
                'Body' => $thumbnailBytes,
                'ContentType' => 'image/jpeg',
                'ACL' => 'private',
            ]);
            $this->writeLocal($localPath, $thumbnailBytes);

            return [
                'bytes' => $thumbnailBytes,
                'content_type' => 'image/jpeg',
                'status' => 'GENERATED_ONCE',
                'thumb_key' => $thumbKey,
            ];
        } finally {
            if ($lockHandle !== false) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        }
    }

    private function lookupFile(int $userId, string $key): ?array
    {
        $slash = strrpos($key, '/');
        $route = $slash === false ? '' : substr($key, 0, $slash + 1);
        $basename = $slash === false ? $key : substr($key, $slash + 1);

        $stmt = $this->db->prepare(
            'SELECT id_, Nombre, Ruta, Encriptado, AccessType, PasswordHash, SecureHint, Found
             FROM FileS3
             WHERE user_id_ = ? AND Found = 1
               AND ((Ruta = ? AND Encriptado = ?) OR Encriptado = ?)
             ORDER BY id_ DESC
             LIMIT 20'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar la miniatura en FileS3: ' . $this->db->error);
        }

        $stmt->bind_param('isss', $userId, $route, $basename, $key);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $realKey = $this->normalizeKey(FileViewHelper::buildS3Key(
                (string)($row['Ruta'] ?? ''),
                (string)($row['Encriptado'] ?? '')
            ));
            if ($realKey === $key) {
                $stmt->close();
                return $row;
            }
        }

        $stmt->close();
        return null;
    }

    private function canPreview(array $row, string $key, array $session): bool
    {
        $accessType = strtolower(trim((string)($row['AccessType'] ?? 'normal')));
        $passwordHash = trim((string)($row['PasswordHash'] ?? ''));

        if ($accessType === 'unlocked') {
            return true;
        }
        if ($accessType !== 'secure' && $passwordHash === '') {
            return true;
        }

        $candidateKeys = array_values(array_unique(array_filter([
            $key,
            (string)($row['Encriptado'] ?? ''),
            basename($key),
        ])));

        foreach (['secure_ok_files', 'secure_files_ok', 'unlocked_files'] as $bucketName) {
            $bucket = $session[$bucketName] ?? null;
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($candidateKeys as $candidate) {
                if (!array_key_exists($candidate, $bucket)) {
                    continue;
                }
                $value = $bucket[$candidate];
                if (is_numeric($value) && (int)$value < time()) {
                    continue;
                }
                if ($value) {
                    return true;
                }
            }
        }

        return false;
    }

    private function thumbnailKey(string $key, int $width, int $height, string $fit): string
    {
        $root = 'Data/';
        $rest = $key;
        if (preg_match('~^(Data\\d*/)(.*)$~i', $key, $match)) {
            $root = $match[1];
            $rest = $match[2];
        }

        $withoutExtension = preg_replace('/\\.[A-Za-z0-9]+$/', '', $rest) ?? $rest;
        return 'thumbs/' . $root . $withoutExtension . '__'
            . $width . 'x' . $height . '_' . $fit . '.jpg';
    }

    private function localPath(int $userId, string $thumbKey): string
    {
        $hash = hash('sha256', $userId . '|' . $thumbKey);
        return $this->localCacheDir
            . DIRECTORY_SEPARATOR . $userId
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . $hash . '.jpg';
    }

    private function readLocal(string $path): ?string
    {
        if (!is_file($path) || filesize($path) <= 0) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    private function writeLocal(string $path, string $bytes): void
    {
        $this->ensureDirectory(dirname($path));
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(5));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            @unlink($tmp);
            return; // La caché local es una optimización, no debe romper la miniatura.
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace(["\\0", '\\'], ['', '/'], trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        $key = ltrim($key, '/');
        if ($key === '' || str_contains('/' . $key . '/', '/../')) {
            throw new RuntimeException('Key de miniatura inválida.');
        }
        return $key;
    }

    private function isNotFound(AwsException $e): bool
    {
        return $e->getStatusCode() === 404
            || in_array((string)$e->getAwsErrorCode(), ['NoSuchKey', 'NotFound', '404'], true);
    }

    private function resizeToJpeg(string $bytes, int $width, int $height, string $fit): string
    {
        if (class_exists('Imagick')) {
            $image = new \Imagick();
            if (!$image->readImageBlob($bytes)) {
                throw new RuntimeException('Imagick no pudo leer la imagen.');
            }
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
            }
            if (method_exists($image, 'autoOrientImage')) {
                @$image->autoOrientImage();
            }
            $image->setImageBackgroundColor('white');
            if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                @$image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            }
            $image->setImageFormat('jpeg');

            if ($fit === 'contain') {
                $image->thumbnailImage($width, $height, true, true);
                $canvas = new \Imagick();
                $canvas->newImage($width, $height, 'white', 'jpeg');
                $x = (int)(($width - $image->getImageWidth()) / 2);
                $y = (int)(($height - $image->getImageHeight()) / 2);
                $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, $x, $y);
                $canvas->setImageCompressionQuality(82);
                $output = $canvas->getImageBlob();
                $canvas->clear();
                $image->clear();
                return $output;
            }

            $image->cropThumbnailImage($width, $height);
            $image->setImageCompressionQuality(82);
            $output = $image->getImageBlob();
            $image->clear();
            return $output;
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('El servidor necesita Imagick o GD para crear miniaturas.');
        }

        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            throw new RuntimeException('GD no pudo leer la imagen.');
        }

        $sourceWidth = imagesx($src);
        $sourceHeight = imagesy($src);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($src);
            throw new RuntimeException('Dimensiones de imagen inválidas.');
        }

        if ($fit === 'contain') {
            $scale = min($width / $sourceWidth, $height / $sourceHeight);
            $newWidth = max(1, (int)floor($sourceWidth * $scale));
            $newHeight = max(1, (int)floor($sourceHeight * $scale));
            $dst = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $width, $height, $white);
            $x = (int)floor(($width - $newWidth) / 2);
            $y = (int)floor(($height - $newHeight) / 2);
            imagecopyresampled($dst, $src, $x, $y, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
        } else {
            $scale = max($width / $sourceWidth, $height / $sourceHeight);
            $newWidth = max(1, (int)ceil($sourceWidth * $scale));
            $newHeight = max(1, (int)ceil($sourceHeight * $scale));
            $tmp = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($tmp, $src, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
            $dst = imagecreatetruecolor($width, $height);
            $x = (int)floor(($newWidth - $width) / 2);
            $y = (int)floor(($newHeight - $height) / 2);
            imagecopy($dst, $tmp, 0, 0, $x, $y, $width, $height);
            imagedestroy($tmp);
        }

        ob_start();
        imagejpeg($dst, null, 82);
        $output = (string)ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $output;
    }
}
''')

# -----------------------------------------------------------------------------
# 3) thumb.php se vuelve controlador fino y seguro.
# -----------------------------------------------------------------------------
write(DRIVE / 'thumb.php', r'''<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Media\ThumbnailService;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/app_bootstrap.php';

function thumbnailFallback(): never
{
    $path = __DIR__ . '/img/file.png';
    http_response_code(200);
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=60');
    if (is_file($path)) {
        readfile($path);
    } else {
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
    }
    exit;
}

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    header('X-Thumb-Status: NO_SESSION');
    thumbnailFallback();
}

$key = trim((string)($_GET['key'] ?? ''));
$width = max(24, min(512, (int)($_GET['w'] ?? 96)));
$height = max(24, min(512, (int)($_GET['h'] ?? 96)));
$fit = strtolower(trim((string)($_GET['fit'] ?? 'cover'))) === 'contain' ? 'contain' : 'cover';

if ($key === '') {
    header('X-Thumb-Status: NO_KEY');
    thumbnailFallback();
}

$userId = $session->userId();
$sessionSnapshot = $_SESSION;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new ThumbnailService(
        $app->db(),
        $app->s3(),
        $app->bucket(),
        sys_get_temp_dir() . '/arcadecloud-drive-thumbnails'
    );

    $thumbnail = $service->get($userId, $key, $width, $height, $fit, $sessionSnapshot);
    $bytes = $thumbnail['bytes'];
    $etag = '"' . sha1($bytes) . '"';

    header('Content-Type: ' . $thumbnail['content_type']);
    header('Cache-Control: private, max-age=300');
    header('ETag: ' . $etag);
    header('X-Thumb-Status: ' . $thumbnail['status']);
    header('X-Thumb-Key: ' . $thumbnail['thumb_key']);

    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    echo $bytes;
} catch (Throwable $e) {
    header('X-Thumb-Status: FALLBACK');
    thumbnailFallback();
}
''')

# -----------------------------------------------------------------------------
# 4) Galería DB-first: nunca lista S3 para descubrir imágenes.
# -----------------------------------------------------------------------------
write(DRIVE / 'generar_galeria.php', r'''<?php
declare(strict_types=1);

use ArcadeCloud\Drive\View\FileViewHelper;

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_bootstrap.php';

try {
    $app = drive_app();
    $session = $app->session();
    $session->start();
    if (!$session->isAuthenticated() || $session->userId() <= 0) {
        http_response_code(401);
        echo json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = $session->userId();
    $requestedRoute = trim((string)($_GET['ruta'] ?? $_SESSION['ruta_actual'] ?? ''));
    $route = $app->userStoragePath()->normalizeForUser($requestedRoute, $userId);
    $_SESSION['ruta_actual'] = $route;

    $width = max(64, min(512, (int)($_GET['w'] ?? 384)));
    $height = max(64, min(512, (int)($_GET['h'] ?? 216)));

    $sql = "SELECT Nombre, Encriptado, Ruta, AccessType, PasswordHash
            FROM FileS3
            WHERE user_id_ = ?
              AND Ruta = ?
              AND Found = 1
              AND LOWER(SUBSTRING_INDEX(Nombre, '.', -1)) IN
                  ('jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff')
            ORDER BY Fecha DESC
            LIMIT 1000";

    $stmt = $app->db()->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la galería: ' . $app->db()->error);
    }
    $stmt->bind_param('is', $userId, $route);
    $stmt->execute();
    $result = $stmt->get_result();

    $out = [];
    while ($row = $result->fetch_assoc()) {
        // Conserva la misma regla del bloque: un archivo en estado secure no se previsualiza.
        if (FileViewHelper::isLocked($row)) {
            continue;
        }

        $key = FileViewHelper::buildS3Key(
            (string)($row['Ruta'] ?? ''),
            (string)($row['Encriptado'] ?? '')
        );
        if ($key === '') {
            continue;
        }

        $out[] = [
            'key' => $key,
            'nombre' => (string)($row['Nombre'] ?? basename($key)),
            'original' => 'ver_archivo.php?archivo=' . rawurlencode($key),
            'thumb' => 'thumb.php?key=' . rawurlencode($key)
                . '&w=' . $width . '&h=' . $height . '&fit=cover',
        ];
    }
    $stmt->close();

    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Galería no disponible: ' . $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
''')

# -----------------------------------------------------------------------------
# 5) bloque_archivos.php: miniaturas reales solo en imágenes + iconos por extensión.
# -----------------------------------------------------------------------------
path = DRIVE / 'bloque_archivos.php'
text = path.read_text(encoding='utf-8')
text = replace_once(
    text,
    "use ArcadeCloud\\Drive\\View\\FileViewHelper;",
    "use ArcadeCloud\\Drive\\View\\FileViewHelper;\nuse ArcadeCloud\\Drive\\View\\FileIconResolver;",
    'import FileIconResolver'
)
text = replace_once(
    text,
    "$imagenesExt = ['jpg','jpeg','png','gif','webp','bmp'];",
    "$imagenesExt = ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff'];",
    'extensiones imagen'
)
text = replace_once(
    text,
    "    if (in_array($ext, $imagenesExt, true)) {",
    "    if (in_array($ext, $imagenesExt, true) && !FileViewHelper::isLocked($row)) {",
    'galeria seguridad'
)
text = replace_once(
    text,
    "      $metaJson = json_encode($metaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);",
    "      $metaJson = json_encode($metaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);\n      $iconInfo = FileIconResolver::resolve($ext);\n      $thumbUrl = 'thumb.php?key=' . rawurlencode($s3key) . '&w=96&h=96&fit=cover';",
    'icon y thumb variables'
)
old_img = '''          <img
            class="thumb-img"
            src="img/file.png"
            width="32" height="32"
            loading="lazy"
            alt="Archivo"
          >'''
new_img = '''          <?php if ($esImg && !$soloSeguridad): ?>
            <button type="button"
                    class="file-preview-button js-ver-imagen"
                    data-key="<?= FileViewHelper::escape($s3key) ?>"
                    data-nombre="<?= FileViewHelper::escape($nombre) ?>"
                    data-original="<?= FileViewHelper::escape($origUrl) ?>"
                    title="Ver imagen completa">
              <img
                class="thumb-img file-thumb-image"
                src="<?= FileViewHelper::escape($thumbUrl) ?>"
                width="56" height="56"
                loading="lazy"
                decoding="async"
                alt="Miniatura de <?= FileViewHelper::escape($nombre) ?>"
              >
            </button>
          <?php else: ?>
            <?php
              $visualIcon = $soloSeguridad
                  ? ['icon' => 'fa-lock', 'category' => 'locked', 'label' => 'Archivo protegido']
                  : $iconInfo;
            ?>
            <span class="file-type-icon file-type-<?= FileViewHelper::escape($visualIcon['category']) ?>"
                  title="<?= FileViewHelper::escape($visualIcon['label']) ?>"
                  aria-label="<?= FileViewHelper::escape($visualIcon['label']) ?>">
              <i class="fas <?= FileViewHelper::escape($visualIcon['icon']) ?>" aria-hidden="true"></i>
            </span>
          <?php endif; ?>'''
text = replace_once(text, old_img, new_img, 'render icon/thumb')
path.write_text(text, encoding='utf-8')

# -----------------------------------------------------------------------------
# 6) imagenes.js: reconoce la miniatura del listado, pasa ruta a galería y
#    siempre usa original para visor 1x1.
# -----------------------------------------------------------------------------
path = DRIVE / 'js/imagenes.js'
text = path.read_text(encoding='utf-8')
text = text.replace("imgExts: ['jpg','jpeg','png','gif','webp','bmp','svg']", "imgExts: ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff']")
text = replace_once(
    text,
    "var thumbEl = li.querySelector('img.thumb');",
    "var thumbEl = li.querySelector('img.file-thumb-image, img.thumb-img, img.thumb');",
    'selector miniatura'
)
text = replace_once(
    text,
    "        html += '        <img src=\"' + it.original + '\" alt=\"' + (it.nombre || '') + '\" style=\"max-width:100%;max-height:75vh;object-fit:contain;\" loading=\"lazy\" decoding=\"async\">';",
    "        html += '        <img class=\"gu-original-image\" src=\"' + it.original + '\" alt=\"' + (it.nombre || '') + '\" loading=\"lazy\" decoding=\"async\" draggable=\"false\">';",
    'visor original'
)
old_fetch = '''        var url = new URL('generar_galeria.php', window.location.href);

        if (params && typeof params === 'object') {'''
new_fetch = '''        var url = new URL('generar_galeria.php', window.location.href);
        var contextRoute = document.getElementById('archivosContexto')?.dataset?.rutaActual || '';
        if (contextRoute) {
          url.searchParams.set('ruta', contextRoute);
        }

        if (params && typeof params === 'object') {'''
text = replace_once(text, old_fetch, new_fetch, 'ruta galeria')
path.write_text(text, encoding='utf-8')

# -----------------------------------------------------------------------------
# 7) CSS. styles-old.css es el cargado por s3.php; mantenemos styles.css alineado.
# -----------------------------------------------------------------------------
css_block = r'''

/* =========================================================
   FILE TYPE ICONS + IMAGE THUMBNAILS
   ========================================================= */
.file-preview-button{
  width:58px;height:58px;min-width:58px;padding:0;margin-right:.65rem;
  border:0;background:transparent;border-radius:9px;overflow:hidden;
  display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
}
.file-preview-button:focus{outline:2px solid var(--accent);outline-offset:2px;}
.file-thumb-image{
  width:56px!important;height:56px!important;min-width:56px;
  object-fit:cover;display:block;border-radius:8px;
}
.file-type-icon{
  width:56px;height:56px;min-width:56px;margin-right:.65rem;
  display:inline-flex;align-items:center;justify-content:center;
  border-radius:9px;border:1px solid rgba(var(--accent-rgb),.22);
  background:var(--bg2);font-size:1.75rem;line-height:1;
}
.file-type-pdf{color:#e34b4b;}
.file-type-word{color:#4d82d8;}
.file-type-excel{color:#39a96b;}
.file-type-powerpoint{color:#d97941;}
.file-type-archive{color:#d5a740;}
.file-type-text{color:var(--text);}
.file-type-code{color:#b78cff;}
.file-type-audio{color:#e06db0;}
.file-type-video{color:#63b3ed;}
.file-type-database{color:#56c7c7;}
.file-type-ebook{color:#d6a65c;}
.file-type-mail{color:#8eb5ff;}
.file-type-font{color:#c7c7c7;}
.file-type-certificate{color:#e0c45d;}
.file-type-package,.file-type-model{color:#b59cff;}
.file-type-design{color:#ec85cb;}
.file-type-generic{color:var(--muted);}
.file-type-locked{color:#f0ad4e;}

/* El visor 1x1 usa SIEMPRE ver_archivo.php (original S3), nunca la miniatura. */
#modalImagenUnica .modal-dialog{
  width:auto;max-width:min(1200px,96vw);margin:2vh auto;
}
#modalImagenUnica .modal-content{
  max-height:96vh;overflow:hidden;background:#000!important;
}
#modalImagenUnica .modal-body{overflow:hidden;background:#000!important;}
#modalImagenUnica .carousel,#modalImagenUnica .carousel-inner,#modalImagenUnica .carousel-item{
  max-height:88vh;background:#000;
}
#modalImagenUnica .gu-frame{
  height:76vh;min-height:0!important;padding:1rem;
  display:flex!important;align-items:center!important;justify-content:center!important;
  overflow:hidden;background:#000;
}
#modalImagenUnica .gu-original-image{
  display:block;width:auto!important;height:auto!important;
  max-width:100%!important;max-height:100%!important;
  object-fit:contain!important;margin:auto!important;
}
#modalImagenUnica .gu-caption{
  max-height:8vh;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
@media (max-width:767.98px){
  .file-preview-button,.file-type-icon{width:48px;height:48px;min-width:48px;}
  .file-thumb-image{width:46px!important;height:46px!important;min-width:46px;}
  #modalImagenUnica .modal-dialog{max-width:98vw;margin:1vh auto;}
  #modalImagenUnica .gu-frame{height:70vh;padding:.5rem;}
}
'''
for css_name in ['styles-old.css', 'styles.css']:
    path = DRIVE / 'css' / css_name
    text = path.read_text(encoding='utf-8')
    marker = '/* =========================================================\n   FILE TYPE ICONS + IMAGE THUMBNAILS'
    if marker in text:
        text = text[:text.index(marker)].rstrip() + '\n'
    text = text.rstrip() + css_block + '\n'
    path.write_text(text, encoding='utf-8')

# -----------------------------------------------------------------------------
# 8) Validaciones estáticas del parche antes de que el workflow ejecute linters.
# -----------------------------------------------------------------------------
checks = {
    'bloque usa FileIconResolver': 'FileIconResolver::resolve' in (DRIVE/'bloque_archivos.php').read_text(encoding='utf-8'),
    'bloque conserva peso': 'FileViewHelper::formatBytes($tamano)' in (DRIVE/'bloque_archivos.php').read_text(encoding='utf-8'),
    'bloque conserva reproductor audio': 'audio-player-pro' in (DRIVE/'bloque_archivos.php').read_text(encoding='utf-8'),
    'bloque conserva reproductor video': 'js-inline-video-open' in (DRIVE/'bloque_archivos.php').read_text(encoding='utf-8'),
    'bloque conserva seguridad': 'js-unlock-file' in (DRIVE/'bloque_archivos.php').read_text(encoding='utf-8'),
    'bloque conserva bloqueo': 'js-lock-file' in (DRIVE/'bloque_archivos.php').read_text(encoding='utf-8'),
    'galeria sin listObjects': 'listObjectsV2' not in (DRIVE/'generar_galeria.php').read_text(encoding='utf-8'),
    'galeria usa FileS3': 'FROM FileS3' in (DRIVE/'generar_galeria.php').read_text(encoding='utf-8'),
    'thumb sin HEAD S3': 'doesObjectExistV2' not in (DRIVE/'thumb.php').read_text(encoding='utf-8'),
    'visor usa original': 'gu-original-image' in (DRIVE/'js/imagenes.js').read_text(encoding='utf-8'),
}
failed = [name for name, ok in checks.items() if not ok]
if failed:
    raise SystemExit('Fallaron validaciones: ' + ', '.join(failed))

print('OK: parche de iconos, miniaturas y visor preparado')
