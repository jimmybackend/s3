from pathlib import Path
import re

ROOT = Path('.')

def read(path):
    return (ROOT / path).read_text(encoding='utf-8')

def write(path, content):
    p = ROOT / path
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(content, encoding='utf-8')

def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'No se encontró bloque esperado: {label}')
    return text.replace(old, new, 1)

# 1) ViewModel sin preferencias visuales antiguas.
write('drive/src/Application/DrivePageViewModel.php', '''<?php
declare(strict_types=1);

namespace ArcadeCloud\\Drive\\Application;

final class DrivePageViewModel
{
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

# 2) DrivePageService: la visualización ya no se persiste en sesión.
write('drive/src/Application/DrivePageService.php', '''<?php
declare(strict_types=1);

namespace ArcadeCloud\\Drive\\Application;

use ArcadeCloud\\Drive\\Storage\\UserStoragePath;

final class DrivePageService
{
    public function __construct(
        private FileListService $files,
        private UserStoragePath $paths
    ) {
    }

    public function build(array &$session, array $query, int $userId): DrivePageViewModel
    {
        $vm = new DrivePageViewModel();

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
        } catch (\\Throwable $e) {
            $vm->error = 'No se pudieron cargar los tipos de archivo: ' . $e->getMessage();
        }

        return $vm;
    }
}
''')

# 3) Helper: peso y metadatos encapsulados.
write('drive/src/View/FileViewHelper.php', '''<?php
declare(strict_types=1);

namespace ArcadeCloud\\Drive\\View;

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
        $route = rtrim(str_replace('\\\\', '/', trim($route)), '/') . '/';
        $encryptedName = ltrim(str_replace('\\\\', '/', trim($encryptedName)), '/');

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

    public static function metadataArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw === null || trim((string) $raw) === '') {
            return [];
        }

        $text = trim((string) $raw);
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return ['valor' => $text];
    }

    public static function metadataTooltip(mixed $raw): string
    {
        $data = self::metadataArray($raw);
        if ($data === []) {
            return 'Sin metadatos';
        }

        $pretty = (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $length = function_exists('mb_strlen') ? mb_strlen($pretty) : strlen($pretty);
        if ($length > 2000) {
            $pretty = function_exists('mb_substr') ? mb_substr($pretty, 0, 2000) : substr($pretty, 0, 2000);
            $pretty .= '…';
        }

        $pretty = htmlspecialchars($pretty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return str_replace(["\\r\\n", "\\r", "\\n"], '&#10;', $pretty);
    }
}
''')

# 4) s3.php: quitar preferencias viejas y dejar filtros disponibles siempre.
s3 = read('drive/s3.php')
s3 = replace_once(s3, '''$pageService = $app->drivePageService();
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
''', '''$pageService = $app->drivePageService();
$vm = $pageService->build($_SESSION, $_GET, $userId);

''', 'preferencias backend s3')
s3 = replace_once(s3, '''          <button class="dropdown-item" data-toggle="modal" data-target="#modalPreferencias">
            <i class="fas fa-sliders-h"></i> Preferencias
          </button>
''', '', 'botón preferencias')
s3 = replace_once(s3, '''        <!-- Formulario de filtros -->
        <?php if ($showFilters): ?>
          <form id="formFiltros" class="form-inline" onsubmit="return false;">
''', '''        <!-- Formulario de filtros -->
          <form id="formFiltros" class="form-inline" onsubmit="return false;">
''', 'if filtros inicio')
s3 = replace_once(s3, '''          </form>
        <?php endif; ?>
        
                    <!-- Opciones de reproducción -->
''', '''          </form>
        
                    <!-- Opciones de reproducción -->
''', 'if filtros cierre')
s3, n = re.subn(r'\n<!-- Modal: Preferencias -->.*?\n<!-- Modal: Enlaces Utiles -->', '\n<!-- Modal: Enlaces Utiles -->', s3, count=1, flags=re.S)
if n != 1:
    raise SystemExit('No se eliminó modalPreferencias')
# Limpiar el cuerpo PHP muerto del modal de metadatos.
s3, n = re.subn(r'(<div class="modal-body" id="cuerpoMetadatos"[^>]*>).*?(</div>\n\s*</div>\n\s*</div>\n</div>\n\n<!-- Modal: Enlaces Utiles -->)', r'\1\n      \2', s3, count=0, flags=re.S)
# El patrón anterior puede no aplicar por posición; no es requisito funcional.
write('drive/s3.php', s3)

# 5) bloque_archivos: peso adaptable y botón de metadatos siempre visible para archivos accesibles.
block = read('drive/bloque_archivos.php')
block = replace_once(block, "$carpetaPesoMB = round($state['folder_bytes'] / 1048576, 2);", "$carpetaBytes = (int) $state['folder_bytes'];", 'peso carpeta variable')
block = replace_once(block, 'Peso: <strong><?= number_format($carpetaPesoMB, 2) ?></strong> MB |', 'Peso: <strong><?= FileViewHelper::formatBytes($carpetaBytes) ?></strong> |', 'peso carpeta salida')
block = replace_once(block, "      $metaTitle = FileViewHelper::metadataTooltip($row['Metadatos'] ?? null);\n", "      $metaTitle = FileViewHelper::metadataTooltip($row['Metadatos'] ?? null);\n      $metaData = FileViewHelper::metadataArray($row['Metadatos'] ?? null);\n      $metaJson = json_encode($metaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);\n", 'metadata variables')
meta_button = '''
  <button type="button"
          class="btn btn-sm btn-primary js-file-metadata"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>"
          data-meta="<?= FileViewHelper::escape($metaJson ?: '{}') ?>"
          title="METADATOS">
    <i class="fas fa-info-circle"></i>
  </button>

'''
needle = '''  <button type="button"
          class="btn btn-sm btn-primary js-rename-file"
'''
if needle not in block:
    raise SystemExit('No se encontró botón renombrar para insertar metadatos')
block = block.replace(needle, meta_button + needle, 1)
write('drive/bloque_archivos.php', block)

# 6) JS de metadatos encapsulado y delegado para recargas AJAX.
write('drive/js/ver-metadatos.js', '''(() => {
  'use strict';

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  window.verMetadatos = function verMetadatos(nombre, datos) {
    const contenedor = document.getElementById('cuerpoMetadatos');
    const titulo = document.getElementById('metaTitulo');
    if (!contenedor || !titulo) return;

    const entries = datos && typeof datos === 'object' ? Object.entries(datos) : [];
    titulo.textContent = 'Metadatos de: ' + String(nombre || 'archivo');

    if (!entries.length) {
      contenedor.innerHTML = '<div class="text-muted">Sin metadatos.</div>';
    } else {
      let html = '<div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Clave</th><th>Valor</th></tr></thead><tbody>';
      for (const [key, value] of entries) {
        const shown = value && typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
        html += '<tr><td>' + escapeHtml(key) + '</td><td><pre class="mb-0 text-wrap">' + escapeHtml(shown) + '</pre></td></tr>';
      }
      html += '</tbody></table></div>';
      contenedor.innerHTML = html;
    }

    if (window.jQuery && jQuery.fn.modal) {
      jQuery('#modalMetadatos').modal('show');
    }
  };

  document.addEventListener('click', (event) => {
    const button = event.target.closest('.js-file-metadata');
    if (!button) return;
    event.preventDefault();

    let data = {};
    try {
      data = JSON.parse(button.getAttribute('data-meta') || '{}');
    } catch (_) {
      data = { valor: button.getAttribute('data-meta') || '' };
    }
    window.verMetadatos(button.getAttribute('data-nombre') || 'archivo', data);
  });
})();
''')

# 7) Subida normal: complete es obligatorio; no mostrar éxito si FileS3 falló.
subir = read('drive/js/subir.js')
old = '''        // 3) Avisar tamaño real (no bloquea si falla)
        try {
          const body = new URLSearchParams();
          body.append('upload_token', json.upload_token || '');
          body.append('tamano', String(archivo.size || 0));

          await fetch(API + '?mode=local_put&action=complete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            credentials: 'same-origin',
            body: body.toString()
          });
        } catch (e) {
          console.warn('complete local_put falló:', e);
        }

        // 4) OK
'''
new = '''        // 3) Confirmar en FileS3. El éxito exige S3 + BD.
        const body = new URLSearchParams();
        body.append('upload_token', json.upload_token || '');
        body.append('tamano', String(archivo.size || 0));

        const completeResp = await fetch(API + '?mode=local_put&action=complete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          credentials: 'same-origin',
          body: body.toString()
        });
        const completeJson = await safeJson(completeResp);
        if (!completeResp.ok || !completeJson || completeJson.ok !== true) {
          throw new Error((completeJson && completeJson.error) || 'El objeto llegó a S3 pero no pudo registrarse en FileS3.');
        }

        // 4) OK
'''
subir = replace_once(subir, old, new, 'complete local_put')
write('drive/js/subir.js', subir)

# 8) Dropzone: validar respuesta real del backend.
write('drive/js/subir-dropzone.js', '''Dropzone.autoDiscover = false;

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
    let payload = response;
    if (typeof payload === 'string') {
      try { payload = JSON.parse(payload); } catch (_) { payload = null; }
    }
    const first = payload && Array.isArray(payload.resultados) ? payload.resultados[0] : null;
    if (!payload || payload.ok !== true || (first && first.estado !== 'ok')) {
      const message = (first && first.mensaje) || (payload && payload.error) || 'La subida no se confirmó correctamente.';
      this.emit('error', file, message);
      return;
    }

    console.log('✅ Archivo subido:', payload);
    await window.DriveUploadDestination.afterSuccess(file._driveTargetRoute || '');
  },

  error(file, response) {
    console.error('❌ Error al subir:', response);
  }
});
''')

# 9) Chunked: firmar partes solo si el estado pertenece al usuario y coincide con key/uploadId.
chunk = read('drive/upload/drivers/Chunked15MBUploader.php')
needle = '''    $uploadId     = (string)($req['uploadId'] ?? '');
    $key          = (string)($req['key'] ?? '');
    $partNumber   = (int)($req['partNumber'] ?? 0);
    $contentLength= (int)($req['contentLength'] ?? 0);

    if ($uploadId === '' || $key === '' || $partNumber <= 0 || $contentLength <= 0) {
'''
replacement = '''    $stateId      = (string)($req['stateId'] ?? '');
    $uploadId     = (string)($req['uploadId'] ?? '');
    $key          = (string)($req['key'] ?? '');
    $partNumber   = (int)($req['partNumber'] ?? 0);
    $contentLength= (int)($req['contentLength'] ?? 0);

    $meta = $stateId !== '' ? $this->store->load($stateId) : null;
    $currentUserId = (int)($req['_user_id'] ?? 0);
    if (!$meta || (int)($meta['user_id'] ?? 0) !== $currentUserId) {
      throw new RuntimeException('Estado multipart inválido o ajeno al usuario actual.');
    }
    if ((string)($meta['uploadId'] ?? '') !== $uploadId || (string)($meta['key'] ?? '') !== $key) {
      throw new RuntimeException('La parte no coincide con la subida multipart iniciada.');
    }

    if ($uploadId === '' || $key === '' || $partNumber <= 0 || $contentLength <= 0) {
'''
chunk = replace_once(chunk, needle, replacement, 'validación sign chunked')
write('drive/upload/drivers/Chunked15MBUploader.php', chunk)

# 10) up.php público pasa su backend a OOP y elimina PUTs de marcadores de carpeta.
service = '''<?php
declare(strict_types=1);

namespace ArcadeCloud\\Drive\\Upload;

use Aws\\S3\\S3Client;
use RuntimeException;

final class PublicMultipartUploadService
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $stateDir,
        private string $prefix = 'Data/uploads'
    ) {
        $this->stateDir = rtrim($this->stateDir, '/\\\\');
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0770, true) && !is_dir($this->stateDir)) {
            throw new RuntimeException('No se pudo crear el directorio privado de estado de subidas públicas.');
        }
    }

    public function init(array $input): array
    {
        $filename = trim((string)($input['filename'] ?? ''));
        $filesize = (int)($input['filesize'] ?? 0);
        $mime = trim((string)($input['mime'] ?? 'application/octet-stream'));
        if ($filename === '' || $filesize <= 0) {
            throw new RuntimeException('Datos de archivo inválidos.');
        }

        $sig = $this->signature($filename, $filesize);
        $basename = preg_replace('/[^\\w\\-.]+/u', '_', basename($filename)) ?: 'archivo';
        $key = $this->prefix . '/' . gmdate('Ymd') . '/' . $sig . '-' . $basename;

        $res = $this->s3->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $mime,
            'ACL' => 'private',
            'Metadata' => ['original-name' => $filename, 'original-size' => (string)$filesize],
        ]);
        $uploadId = (string)$res->get('UploadId');

        $this->save($sig, [
            'filename' => $filename,
            'filesize' => $filesize,
            'key' => $key,
            'uploadId' => $uploadId,
            'parts' => [],
            'created' => time(),
        ]);

        return ['ok' => true, 'uploadId' => $uploadId, 'key' => $key, 'signature' => $sig];
    }

    public function sign(array $input): array
    {
        $uploadId = trim((string)($input['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? ''));
        $partNumber = (int)($input['partNumber'] ?? 0);
        $this->assertKey($key);
        if ($uploadId === '' || $partNumber <= 0) {
            throw new RuntimeException('Parámetros inválidos para firmar.');
        }

        $cmd = $this->s3->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);
        $request = $this->s3->createPresignedRequest($cmd, '+1 hour');
        return ['ok' => true, 'url' => (string)$request->getUri()];
    }

    public function part(array $input, array $files): array
    {
        $uploadId = trim((string)($input['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? ''));
        $partNumber = (int)($input['partNumber'] ?? 0);
        $this->assertKey($key);
        if ($uploadId === '' || $partNumber <= 0 || empty($files['part']['tmp_name']) || !is_uploaded_file($files['part']['tmp_name'])) {
            throw new RuntimeException('Paquete inválido.');
        }

        $tmp = (string)$files['part']['tmp_name'];
        $size = (int)($files['part']['size'] ?? 0);
        if ($size <= 0) throw new RuntimeException('Paquete vacío.');
        $fh = fopen($tmp, 'rb');
        if (!$fh) throw new RuntimeException('No se pudo abrir el paquete temporal.');
        try {
            $res = $this->s3->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $fh,
                'ContentLength' => $size,
            ]);
        } finally {
            fclose($fh);
        }
        $etag = trim((string)$res->get('ETag'), '"');
        if ($etag === '') throw new RuntimeException('S3 no devolvió ETag del paquete.');
        return ['ok' => true, 'partNumber' => $partNumber, 'etag' => $etag, 'size' => $size];
    }

    public function resume(array $input): array
    {
        $filename = trim((string)($input['filename'] ?? ''));
        $filesize = (int)($input['filesize'] ?? 0);
        $uploadId = trim((string)($input['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? ''));

        if ($filename !== '' && $filesize > 0) {
            $sig = $this->signature($filename, $filesize);
            $meta = $this->load($sig);
            if ($meta) {
                $remote = $this->listParts((string)$meta['uploadId'], (string)$meta['key']);
                $meta['parts'] = $remote + (is_array($meta['parts'] ?? null) ? $meta['parts'] : []);
                $this->save($sig, $meta);
                return ['found' => true, 'uploadId' => $meta['uploadId'], 'key' => $meta['key'], 'etags' => $meta['parts']];
            }
        }

        if ($uploadId !== '' && $key !== '') {
            $this->assertKey($key);
            $etags = $this->listParts($uploadId, $key);
            if ($etags) return ['found' => true, 'uploadId' => $uploadId, 'key' => $key, 'etags' => $etags];
        }
        return ['found' => false];
    }

    public function complete(array $input): array
    {
        $uploadId = trim((string)($input['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? ''));
        $this->assertKey($key);
        $etags = json_decode((string)($input['etags'] ?? '{}'), true) ?: [];
        if ($uploadId === '' || !$etags) throw new RuntimeException('Faltan parámetros para completar.');

        $parts = [];
        foreach ($etags as $num => $tag) {
            $parts[] = ['PartNumber' => (int)$num, 'ETag' => '"' . trim((string)$tag, '"') . '"'];
        }
        usort($parts, static fn(array $a, array $b): int => ((int)$a['PartNumber']) <=> ((int)$b['PartNumber']));

        $res = $this->s3->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        $cmd = $this->s3->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);
        $request = $this->s3->createPresignedRequest($cmd, '+1 hour');
        $this->deleteStateFor($uploadId, $key);

        return [
            'ok' => true,
            'location' => (string)($res->get('Location') ?? ''),
            'objectUrl' => 's3://' . $this->bucket . '/' . $key,
            'key' => $key,
            'url' => (string)$request->getUri(),
        ];
    }

    private function signature(string $filename, int $filesize): string
    {
        return sha1($filename . '|' . $filesize);
    }

    private function assertKey(string $key): void
    {
        $prefix = rtrim($this->prefix, '/') . '/';
        if ($key === '' || strpos($key, $prefix) !== 0 || str_contains($key, '..')) {
            throw new RuntimeException('Key pública inválida.');
        }
    }

    private function statePath(string $sig): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . preg_replace('/[^a-f0-9]/i', '_', $sig) . '.json';
    }

    private function save(string $sig, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($this->statePath($sig), $json, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el estado de la subida.');
        }
    }

    private function load(string $sig): ?array
    {
        $path = $this->statePath($sig);
        if (!is_file($path)) return null;
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function listParts(string $uploadId, string $key): array
    {
        $this->assertKey($key);
        $out = [];
        $marker = null;
        do {
            $args = ['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId];
            if ($marker !== null) $args['PartNumberMarker'] = $marker;
            $res = $this->s3->listParts($args);
            foreach (($res->get('Parts') ?: []) as $part) {
                $num = (int)($part['PartNumber'] ?? 0);
                $etag = trim((string)($part['ETag'] ?? ''), '"');
                if ($num > 0 && $etag !== '') $out[(string)$num] = $etag;
            }
            $truncated = (bool)($res->get('IsTruncated') ?? false);
            $marker = $res->get('NextPartNumberMarker') ?? null;
        } while ($truncated);
        return $out;
    }

    private function deleteStateFor(string $uploadId, string $key): void
    {
        foreach (glob($this->stateDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && ($data['uploadId'] ?? '') === $uploadId && ($data['key'] ?? '') === $key) {
                @unlink($file);
            }
        }
    }
}
'''
write('drive/src/Upload/PublicMultipartUploadService.php', service)

up = read('drive/up.php')
marker = '// ========================== UI =========================='
if marker not in up:
    raise SystemExit('No se encontró marcador UI en up.php')
ui = marker + up.split(marker, 1)[1]
controller = '''<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\\Drive\\Upload\\PublicMultipartUploadService;

$app = drive_app();
$service = new PublicMultipartUploadService(
    $app->s3(),
    $app->bucket(),
    sys_get_temp_dir() . '/arcadecloud-public-upload-state'
);

$action = trim((string)($_POST['action'] ?? ''));
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $result = match ($action) {
            'init' => $service->init($_POST),
            'sign' => $service->sign($_POST),
            'part' => $service->part($_POST, $_FILES),
            'resume' => $service->resume($_POST),
            'complete' => $service->complete($_POST),
            default => throw new RuntimeException('Acción no válida.'),
        };
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

'''
write('drive/up.php', controller + ui)

print('Cambios aplicados correctamente')
