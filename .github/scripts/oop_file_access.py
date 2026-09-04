from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
DRIVE=ROOT/'drive'; SRC=DRIVE/'src'

def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True); path.write_text(content, encoding='utf-8')

write(SRC/'Http/ByteRange.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http;

final readonly class ByteRange
{
    public function __construct(
        public int $start,
        public int $end,
        public int $length,
        public int $status
    ) {
    }

    public static function parse(?string $header, int $fileSize, int $maxBytes = 1048576): ?self
    {
        if ($fileSize <= 0) return null;
        if ($header === null || trim($header) === '') {
            $end = min($fileSize - 1, $maxBytes - 1);
            return new self(0, $end, $end + 1, 200);
        }
        if (!preg_match('/bytes\s*=\s*(\d*)-(\d*)/i', $header, $match)) return null;
        [$all, $startRaw, $endRaw] = $match;
        if ($startRaw === '' && $endRaw === '') return null;
        if ($startRaw === '') {
            $suffix = (int)$endRaw;
            if ($suffix <= 0) return null;
            $length = min($suffix, $maxBytes, $fileSize);
            $start = max(0, $fileSize - $length);
            return new self($start, $fileSize - 1, $length, 206);
        }
        $start = (int)$startRaw;
        if ($start < 0 || $start >= $fileSize) return null;
        if ($endRaw === '') {
            $end = min($fileSize - 1, $start + $maxBytes - 1);
        } else {
            $requestedEnd = (int)$endRaw;
            if ($requestedEnd < $start) return null;
            $end = min($requestedEnd, $fileSize - 1, $start + $maxBytes - 1);
        }
        return new self($start, $end, ($end - $start) + 1, 206);
    }
}
''')

write(SRC/'Application/FileAccessService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Http\ByteRange;
use Aws\S3\S3Client;
use RuntimeException;

final class FileAccessService
{
    public function __construct(
        private FileRecordLocator $locator,
        private S3Client $s3,
        private string $bucket,
        private \S3Manager $manager
    ) {
    }

    public function locate(int $userId, string $key): array
    {
        return $this->locator->requireReadableByKey($userId, $this->normalizeKey($key));
    }

    public function signedDownload(int $userId, string $key): array
    {
        $row = $this->locate($userId, $key);
        return $this->manager->downloadFile((string)$row['_key']);
    }

    public function downloadStream(int $userId, string $key, string $fallbackName = ''): array
    {
        $row = $this->locate($userId, $key);
        $realKey = (string)$row['_key'];
        $head = $this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$realKey]);
        $object = $this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$realKey]);
        return [
            'row'=>$row,
            'key'=>$realKey,
            'name'=>$this->downloadName($row, $realKey, $fallbackName),
            'mime'=>trim((string)($head['ContentType'] ?? '')) ?: 'application/octet-stream',
            'length'=>isset($head['ContentLength']) ? (int)$head['ContentLength'] : null,
            'body'=>$object['Body'],
        ];
    }

    public function inlineRange(int $userId, string $key, ?string $rangeHeader, int $maxBytes = 1048576): array
    {
        $row = $this->locate($userId, $key);
        $realKey = (string)$row['_key'];
        $head = $this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$realKey]);
        $size = (int)($head['ContentLength'] ?? 0);
        $range = ByteRange::parse($rangeHeader, $size, $maxBytes);
        if ($range === null) {
            throw new RuntimeException('Rango no válido', 416);
        }
        $object = $this->s3->getObject([
            'Bucket'=>$this->bucket,'Key'=>$realKey,
            'Range'=>'bytes='.$range->start.'-'.$range->end,
        ]);
        return [
            'row'=>$row,'key'=>$realKey,'size'=>$size,'range'=>$range,
            'mime'=>trim((string)($head['ContentType'] ?? '')) ?: 'application/octet-stream',
            'etag'=>trim((string)($head['ETag'] ?? '')),
            'last_modified'=>$head['LastModified'] ?? null,
            'body'=>$object['Body'],
        ];
    }

    public function outputName(array $row): string
    {
        return $this->sanitizeName((string)($row['Nombre'] ?? 'archivo'));
    }

    private function downloadName(array $row, string $realKey, string $fallback): string
    {
        $visible = trim((string)($row['Nombre'] ?? '')) ?: trim($fallback) ?: basename($realKey);
        $physicalExt = strtolower((string)pathinfo($realKey, PATHINFO_EXTENSION));
        $base = preg_replace('/\.[^.]+$/', '', $visible) ?: $visible;
        return $this->sanitizeName($base . ($physicalExt !== '' ? '.'.$physicalExt : ''));
    }

    private function sanitizeName(string $name): string
    {
        $name = preg_replace('/[\/\\\\]/', '-', $name) ?? $name;
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? $name;
        $name = str_replace(['\r','\n','"'], ['', '', "'"], trim($name));
        return $name !== '' ? $name : 'archivo';
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }
}
''')

write(SRC/'Application/TemporaryZip.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

final class TemporaryZip
{
    public function __construct(
        public readonly string $path,
        public readonly string $downloadName,
        private array $temporaryFiles = []
    ) {
    }

    public function cleanup(): void
    {
        foreach ($this->temporaryFiles as $path) @unlink($path);
        @unlink($this->path);
        $this->temporaryFiles = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
''')

write(SRC/'Application/ZipDownloadService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use Aws\S3\S3Client;
use RuntimeException;
use ZipArchive;

final class ZipDownloadService
{
    public function __construct(
        private FileRecordLocator $locator,
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function create(int $userId, array $keys): TemporaryZip
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        if (!$keys) throw new RuntimeException('No hay archivos seleccionados.');
        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('drive_zip_', true).'.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el ZIP.');
        }
        $temps=[]; $seen=[];
        try {
            foreach ($keys as $requestedKey) {
                $row = $this->locator->requireReadableByKey($userId, $requestedKey);
                $realKey = (string)$row['_key'];
                if ($realKey === '' || str_ends_with($realKey, '/')) continue;
                $entry = $this->uniqueName((string)($row['Nombre'] ?? basename($realKey)), $realKey, $seen);
                $ext = pathinfo($realKey, PATHINFO_EXTENSION);
                $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('drive_s3_', true).($ext ? '.'.$ext : '');
                $this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$realKey,'SaveAs'=>$tmp]);
                $temps[]=$tmp;
                $zip->addFile($tmp, $entry);
            }
            $zip->close();
            return new TemporaryZip($zipPath, 'archivos_'.date('Ymd_His').'.zip', $temps);
        } catch (\Throwable $error) {
            $zip->close(); foreach ($temps as $tmp) @unlink($tmp); @unlink($zipPath); throw $error;
        }
    }

    private function uniqueName(string $visible, string $realKey, array &$seen): string
    {
        $ext = strtolower((string)pathinfo($realKey, PATHINFO_EXTENSION));
        $suffix = $ext !== '' ? '.'.$ext : '';
        $base = preg_replace('/\.[^.]+$/', '', $visible) ?: $visible;
        $base = trim((string)preg_replace('/[\/\\\\\x00-\x1F\x7F]/', '-', $base));
        if ($base === '') $base='archivo';
        $candidate=$base.$suffix; $n=2;
        while (isset($seen[strtolower($candidate)])) $candidate=$base.' ('.$n++.')'.$suffix;
        $seen[strtolower($candidate)]=true;
        return $candidate;
    }
}
''')

write(SRC/'Http/BinaryResponse.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http;

final class BinaryResponse
{
    public function text(int $status, string $message): never
    {
        http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $message; exit;
    }

    public function redirect(string $url): never
    {
        header('Location: '.$url); exit;
    }

    public function streamBody(mixed $body): void
    {
        if (is_object($body) && method_exists($body, 'rewind')) { try {$body->rewind();} catch (\Throwable) {} }
        if (is_object($body) && method_exists($body, 'read')) {
            while (!$body->eof()) { echo $body->read(8192); if (function_exists('ob_flush')) @ob_flush(); flush(); }
            return;
        }
        echo (string)$body;
    }

    public function attachment(array $file): never
    {
        while (ob_get_level()) ob_end_clean();
        set_time_limit(0);
        $name=(string)$file['name']; $encoded=rawurlencode($name);
        header('Content-Type: '.(string)$file['mime']);
        header('Content-Disposition: attachment; filename="'.$name.'"; filename*=UTF-8\'\''.$encoded);
        header('X-Filename: '.$encoded);
        header('Cache-Control: private, max-age=0, must-revalidate'); header('Pragma: public');
        if ($file['length'] !== null) header('Content-Length: '.(int)$file['length']);
        $this->streamBody($file['body']); exit;
    }

    public function inlineRange(array $file): never
    {
        /** @var ByteRange $range */ $range=$file['range'];
        http_response_code($range->status);
        header('Content-Type: '.$file['mime']); header('Accept-Ranges: bytes');
        header('Content-Length: '.$range->length);
        header('Content-Range: bytes '.$range->start.'-'.$range->end.'/'.$file['size']);
        header('Cache-Control: private, no-store, no-cache, must-revalidate'); header('Pragma: no-cache'); header('Expires: 0');
        if ($file['etag'] !== '') header('ETag: '.$file['etag']);
        if ($file['last_modified'] instanceof \DateTimeInterface) header('Last-Modified: '.gmdate('D, d M Y H:i:s', $file['last_modified']->getTimestamp()).' GMT');
        $name=str_replace(["\r","\n",'"'], ['', '', "'"], (string)($file['row']['Nombre'] ?? ''));
        if ($name !== '') header('Content-Disposition: inline; filename="'.$name.'"');
        $this->streamBody($file['body']); exit;
    }

    public function zip(\ArcadeCloud\Drive\Application\TemporaryZip $zip): never
    {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.rawurlencode($zip->downloadName).'"');
        header('X-Filename: '.rawurlencode($zip->downloadName));
        header('Content-Length: '.filesize($zip->path));
        $fp=fopen($zip->path, 'rb');
        if ($fp) { while (!feof($fp)) echo fread($fp, 8192); fclose($fp); }
        $zip->cleanup(); exit;
    }
}
''')

write(SRC/'Http/Controller/FileAccessController.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Application\FileAccessService;
use ArcadeCloud\Drive\Application\ZipDownloadService;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Http\BinaryResponse;

final class FileAccessController
{
    public function __construct(
        private \ArcadeCloud\Drive\Core\DriveApplication $app,
        private \ArcadeCloud\Drive\Http\Request $request,
        private BinaryResponse $response = new BinaryResponse()
    ) {
    }

    public function signedDownload(): never
    {
        try {
            $userId=$this->userId(); $key=$this->request->queryString('archivo');
            $result=$this->service()->signedDownload($userId,$key);
            $this->response->redirect((string)$result['url_descarga']);
        } catch (\Throwable $e) { $this->response->text($this->status($e), 'Error: '.$e->getMessage()); }
    }

    public function download(): never
    {
        try {
            $file=$this->service()->downloadStream($this->userId(), $this->request->queryString('archivo'), $this->request->queryString('nombre'));
            $this->response->attachment($file);
        } catch (\Throwable $e) { $this->response->text($this->status($e), 'Error al descargar: '.$e->getMessage()); }
    }

    public function view(): never
    {
        try {
            $range=$this->request->serverString('HTTP_RANGE');
            $file=$this->service()->inlineRange($this->userId(), $this->request->queryString('archivo'), $range !== '' ? $range : null);
            $this->response->inlineRange($file);
        } catch (\Throwable $e) {
            $status=$this->status($e);
            if ($status===416) header('Accept-Ranges: bytes');
            $this->response->text($status, $status===423 ? 'Archivo protegido. Debes desbloquearlo antes de visualizarlo.' : $e->getMessage());
        }
    }

    public function zip(): never
    {
        try {
            if ($this->request->method() !== 'POST') $this->response->text(405, 'Método no permitido');
            $keys=$this->request->postArray('archivos');
            if (!$keys) $keys=$this->request->postJsonArray('archivos_json');
            $service=new ZipDownloadService(new FileRecordLocator($this->app->db()), $this->app->s3(), $this->app->bucket());
            $this->response->zip($service->create($this->userId(),$keys));
        } catch (\Throwable $e) { $this->response->text($this->status($e), 'Error: '.$e->getMessage()); }
    }

    private function service(): FileAccessService
    {
        return new FileAccessService(new FileRecordLocator($this->app->db()), $this->app->s3(), $this->app->bucket(), $this->app->s3Manager());
    }

    private function userId(): int
    {
        $session=$this->app->session(); $session->start();
        if (!$session->isAuthenticated() || $session->userId()<=0) $this->response->text(401,'Sesión inválida');
        return $session->userId();
    }

    private function status(\Throwable $error): int
    {
        $code=(int)$error->getCode();
        if (in_array($code,[400,401,403,404,416,423],true)) return $code;
        $message=strtolower($error->getMessage());
        if (str_contains($message,'protegido')) return 423;
        if (str_contains($message,'no encontrado')) return 404;
        return 500;
    }
}
''')

# Request server accessor.
request=SRC/'Http/Request.php'; text=request.read_text(encoding='utf-8')
needle='''    public function queryString(string $name, string $default = ''): string\n    {\n'''
insert='''    public function serverString(string $name, string $default = ''): string\n    {\n        $value = $this->server[$name] ?? $default;\n        return is_scalar($value) ? trim((string)$value) : $default;\n    }\n\n'''
if 'serverString(' not in text: text=text.replace(needle,insert+needle,1)
request.write_text(text,encoding='utf-8')

methods={'descargar_archivo.php':'signedDownload','descargar.php':'download','ver_archivo.php':'view','descargar_zip.php':'zip','download_multiple.php':'zip'}
for filename,method in methods.items():
    write(DRIVE/filename, f'''<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/app_bootstrap.php';\n(new \\ArcadeCloud\\Drive\\Http\\Controller\\FileAccessController(\n    \\ArcadeCloud\\Drive\\Core\\ApplicationKernel::app(),\n    \\ArcadeCloud\\Drive\\Http\\Request::fromGlobals()\n))->{method}();\n''')
print('File access OOP migration applied')
