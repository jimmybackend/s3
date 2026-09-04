from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
DRIVE = ROOT / 'drive'


def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8')


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'No se encontró bloque para {label}')
    return text.replace(old, new, 1)

# -----------------------------------------------------------------------------
# 1) Config AWS: clientes centralizados para Comprehend y Rekognition.
# -----------------------------------------------------------------------------
config = (ROOT / 'Config-s3.php').read_text(encoding='utf-8')
config = replace_once(
    config,
    'use Aws\\BedrockRuntime\\BedrockRuntimeClient;\nuse Aws\\Textract\\TextractClient;\nuse Aws\\S3\\S3Client;',
    'use Aws\\BedrockRuntime\\BedrockRuntimeClient;\nuse Aws\\Comprehend\\ComprehendClient;\nuse Aws\\Rekognition\\RekognitionClient;\nuse Aws\\Textract\\TextractClient;\nuse Aws\\S3\\S3Client;',
    'imports Config'
)
marker = '''        public static function getTextract(): TextractClient\n        {\n            return new TextractClient([\n                'region'      => self::REGION,\n                'version'     => 'latest',\n                'credentials' => self::getAwsCredentials(),\n                'http'        => ['connect_timeout' => 15, 'timeout' => 120],\n            ]);\n        }\n'''
replacement = marker + '''\n        public static function getComprehend(): ComprehendClient\n        {\n            return new ComprehendClient([\n                'region'      => self::REGION,\n                'version'     => 'latest',\n                'credentials' => self::getAwsCredentials(),\n                'http'        => ['connect_timeout' => 15, 'timeout' => 120],\n            ]);\n        }\n\n        public static function getRekognition(): RekognitionClient\n        {\n            return new RekognitionClient([\n                'region'      => self::REGION,\n                'version'     => 'latest',\n                'credentials' => self::getAwsCredentials(),\n                'http'        => ['connect_timeout' => 15, 'timeout' => 120],\n            ]);\n        }\n'''
config = replace_once(config, marker, replacement, 'clientes Comprehend/Rekognition')
(ROOT / 'Config-s3.php').write_text(config, encoding='utf-8')

# -----------------------------------------------------------------------------
# 2) Localizador común de archivos: DB-first, multiusuario y seguridad.
# -----------------------------------------------------------------------------
write(DRIVE / 'src/Aws/FileRecordLocator.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use ArcadeCloud\Drive\View\FileViewHelper;
use mysqli;
use RuntimeException;

final class FileRecordLocator
{
    public function __construct(private mysqli $db)
    {
    }

    public function requireReadableByKey(int $userId, string $requestedKey): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $key = $this->normalizeKey($requestedKey);
        if ($key === '') {
            throw new RuntimeException('Falta la clave del archivo.');
        }

        $slash = strrpos($key, '/');
        $route = $slash === false ? '' : substr($key, 0, $slash + 1);
        $basename = $slash === false ? $key : substr($key, $slash + 1);

        $stmt = $this->db->prepare(
            'SELECT id_, user_id_, Nombre, Ruta, Encriptado, Tamano, Fecha, Metadatos, AccessType, PasswordHash, SecureHint, Found\n'
            . 'FROM FileS3\n'
            . 'WHERE user_id_ = ? AND Found = 1\n'
            . '  AND ((Ruta = ? AND Encriptado = ?) OR Encriptado = ?)\n'
            . 'ORDER BY id_ DESC LIMIT 20'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar el archivo: ' . $this->db->error);
        }

        $stmt->bind_param('isss', $userId, $route, $basename, $key);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $realKey = $this->normalizeKey(FileViewHelper::buildS3Key(
                (string)($row['Ruta'] ?? ''),
                (string)($row['Encriptado'] ?? '')
            ));
            if ($realKey !== $key) {
                continue;
            }

            $stmt->close();
            if (FileViewHelper::isLocked($row)) {
                throw new RuntimeException('El archivo está protegido. Desbloquéalo antes de usar servicios AWS.');
            }

            $row['_key'] = $realKey;
            return $row;
        }

        $stmt->close();
        throw new RuntimeException('Archivo no encontrado para este usuario.');
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }
}
''')

# -----------------------------------------------------------------------------
# 3) Amazon Comprehend sobre archivos de texto.
# -----------------------------------------------------------------------------
write(DRIVE / 'src/Aws/ComprehendFileService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Comprehend\ComprehendClient;
use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class ComprehendFileService
{
    private const TEXT_EXTENSIONS = [
        'txt','jas','md','markdown','csv','json','html','htm','xml','sql','log',
        'srt','vtt','php','phtml','js','mjs','css','py','ini','cfg','conf','yaml','yml'
    ];

    private const LANGUAGE_CODES = ['en','es','fr','de','it','pt','ar','hi','ja','ko','zh','zh-TW'];

    private FileRecordLocator $locator;

    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private ComprehendClient $comprehend,
        private string $bucket
    ) {
        $this->locator = new FileRecordLocator($db);
    }

    public function analyze(int $userId, string $key): array
    {
        $row = $this->locator->requireReadableByKey($userId, $key);
        $realKey = (string)$row['_key'];
        $ext = strtolower((string)pathinfo((string)($row['Nombre'] ?? $realKey), PATHINFO_EXTENSION));

        if (!in_array($ext, self::TEXT_EXTENSIONS, true)) {
            throw new RuntimeException('Amazon Comprehend se habilita aquí para archivos de texto y código.');
        }

        $object = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $realKey,
        ]);
        $text = (string)$object['Body'];
        if ($text === '') {
            throw new RuntimeException('El archivo está vacío.');
        }

        if (in_array($ext, ['html', 'htm'], true)) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = str_replace("\0", '', $text);
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('No se encontró texto analizable.');
        }

        $originalBytes = strlen($text);
        $truncated = $originalBytes > 90000;
        if ($truncated) {
            if (function_exists('mb_strcut')) {
                $text = mb_strcut($text, 0, 90000, 'UTF-8');
            } else {
                $text = substr($text, 0, 90000);
                if (function_exists('iconv')) {
                    $text = (string)iconv('UTF-8', 'UTF-8//IGNORE', $text);
                }
            }
        }

        if (strlen($text) < 20) {
            throw new RuntimeException('El texto es demasiado corto para un análisis útil.');
        }

        $warnings = [];
        $languages = [];
        $language = null;

        try {
            $langResp = $this->comprehend->detectDominantLanguage(['Text' => $text]);
            $languages = array_map(static function (array $item): array {
                return [
                    'code' => (string)($item['LanguageCode'] ?? ''),
                    'score' => isset($item['Score']) ? (float)$item['Score'] : null,
                ];
            }, array_slice((array)$langResp->get('Languages'), 0, 5));
            $language = $languages[0]['code'] ?? null;
        } catch (\Throwable $e) {
            $warnings[] = 'No se pudo detectar automáticamente el idioma.';
        }

        if (!in_array($language, self::LANGUAGE_CODES, true)) {
            $warnings[] = 'El idioma detectado no está cubierto por todas las operaciones preentrenadas de este análisis.';
        }

        $result = [
            'record_id' => (int)$row['id_'],
            'key' => $realKey,
            'nombre' => (string)($row['Nombre'] ?? basename($realKey)),
            'language' => $language,
            'languages' => $languages,
            'truncated' => $truncated,
            'bytes_original' => $originalBytes,
            'bytes_analyzed' => strlen($text),
            'sentiment' => null,
            'sentiment_scores' => [],
            'key_phrases' => [],
            'entities' => [],
            'pii' => [],
            'warnings' => $warnings,
        ];

        if (in_array($language, self::LANGUAGE_CODES, true)) {
            $this->fillAnalysis($result, $text, $language);
        }

        $this->storeMetadata($userId, $row, $result);
        unset($result['record_id']);
        return $result;
    }

    private function fillAnalysis(array &$result, string $text, string $language): void
    {
        try {
            $response = $this->comprehend->detectSentiment([
                'Text' => $text,
                'LanguageCode' => $language,
            ]);
            $result['sentiment'] = (string)$response->get('Sentiment');
            $result['sentiment_scores'] = (array)$response->get('SentimentScore');
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Sentimiento no disponible.';
        }

        try {
            $response = $this->comprehend->detectKeyPhrases([
                'Text' => $text,
                'LanguageCode' => $language,
            ]);
            $phrases = [];
            foreach (array_slice((array)$response->get('KeyPhrases'), 0, 25) as $item) {
                $phrases[] = [
                    'text' => (string)($item['Text'] ?? ''),
                    'score' => isset($item['Score']) ? (float)$item['Score'] : null,
                ];
            }
            $result['key_phrases'] = $phrases;
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Frases clave no disponibles.';
        }

        try {
            $response = $this->comprehend->detectEntities([
                'Text' => $text,
                'LanguageCode' => $language,
            ]);
            $entities = [];
            foreach (array_slice((array)$response->get('Entities'), 0, 30) as $item) {
                $entities[] = [
                    'text' => (string)($item['Text'] ?? ''),
                    'type' => (string)($item['Type'] ?? ''),
                    'score' => isset($item['Score']) ? (float)$item['Score'] : null,
                ];
            }
            $result['entities'] = $entities;
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Entidades no disponibles.';
        }

        try {
            $response = $this->comprehend->detectPiiEntities([
                'Text' => $text,
                'LanguageCode' => $language,
            ]);
            $pii = [];
            foreach (array_slice((array)$response->get('Entities'), 0, 30) as $item) {
                $pii[] = [
                    'type' => (string)($item['Type'] ?? ''),
                    'score' => isset($item['Score']) ? (float)$item['Score'] : null,
                    'begin' => isset($item['BeginOffset']) ? (int)$item['BeginOffset'] : null,
                    'end' => isset($item['EndOffset']) ? (int)$item['EndOffset'] : null,
                ];
            }
            $result['pii'] = $pii;
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Detección de PII no disponible.';
        }
    }

    private function storeMetadata(int $userId, array $row, array $analysis): void
    {
        $meta = [];
        $raw = trim((string)($row['Metadatos'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $stored = $analysis;
        unset($stored['record_id']);
        $stored['ts'] = date('c');
        $meta['Comprehend'] = $stored;

        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudieron serializar los metadatos de Comprehend.');
        }

        $id = (int)$row['id_'];
        $stmt = $this->db->prepare('UPDATE FileS3 SET Metadatos = ? WHERE id_ = ? AND user_id_ = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la actualización de metadatos: ' . $this->db->error);
        }
        $stmt->bind_param('sii', $json, $id, $userId);
        $stmt->execute();
        $stmt->close();
    }
}
''')

write(DRIVE / 'comprehend_archivo.php', r'''<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Aws\ComprehendFileService;

try {
    $app = drive_app();
    $session = $app->session();
    $session->start();
    $session->requireAuthenticated('index.php');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('Método no permitido.');
    }

    $key = trim((string)($_POST['key'] ?? $_POST['archivo'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('Falta el archivo a analizar.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $service = new ComprehendFileService(
        $app->db(),
        $app->s3(),
        Config::getComprehend(),
        $app->bucket()
    );

    echo json_encode([
        'ok' => true,
        'analysis' => $service->analyze($session->userId(), $key),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
''')

# -----------------------------------------------------------------------------
# 4) Rekognition: resolver FileS3 por key real y respetar bloqueo.
# -----------------------------------------------------------------------------
write(DRIVE / 'rekognition_labels.php', r'''<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Aws\FileRecordLocator;

try {
    $app = drive_app();
    $session = $app->session();
    $session->start();
    $session->requireAuthenticated('index.php');
    $userId = $session->userId();

    $key = trim((string)($_POST['archivo'] ?? $_POST['key'] ?? ''));
    $minConf = max(0.0, min(100.0, (float)($_POST['min_conf'] ?? 70.0)));
    $maxLabels = max(1, min(100, (int)($_POST['max_labels'] ?? 50)));
    if ($key === '') {
        throw new RuntimeException('Falta el archivo a analizar.');
    }

    $locator = new FileRecordLocator($app->db());
    $row = $locator->requireReadableByKey($userId, $key);
    $realKey = (string)$row['_key'];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $rek = Config::getRekognition();
    $image = [
        'S3Object' => [
            'Bucket' => $app->bucket(),
            'Name' => $realKey,
        ],
    ];

    $labelsResp = $rek->detectLabels([
        'Image' => $image,
        'MaxLabels' => $maxLabels,
        'MinConfidence' => $minConf,
        'Features' => ['GENERAL_LABELS', 'IMAGE_PROPERTIES'],
    ]);

    $labels = [];
    foreach ((array)$labelsResp->get('Labels') as $lab) {
        $parents = [];
        foreach ((array)($lab['Parents'] ?? []) as $parent) {
            if (!empty($parent['Name'])) {
                $parents[] = (string)$parent['Name'];
            }
        }
        $labels[] = [
            'Name' => (string)($lab['Name'] ?? ''),
            'Confidence' => isset($lab['Confidence']) ? (float)$lab['Confidence'] : null,
            'Parents' => $parents,
            'Instances' => count((array)($lab['Instances'] ?? [])),
        ];
    }

    $modResp = $rek->detectModerationLabels([
        'Image' => $image,
        'MinConfidence' => $minConf,
    ]);
    $moderation = [];
    foreach ((array)$modResp->get('ModerationLabels') as $item) {
        $moderation[] = [
            'Name' => (string)($item['Name'] ?? ''),
            'ParentName' => (string)($item['ParentName'] ?? ''),
            'Confidence' => isset($item['Confidence']) ? (float)$item['Confidence'] : null,
        ];
    }

    $meta = [];
    $rawMeta = trim((string)($row['Metadatos'] ?? ''));
    if ($rawMeta !== '') {
        $decoded = json_decode($rawMeta, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    $meta['Rekognition'] = [
        'ts' => date('c'),
        'Bucket' => $app->bucket(),
        'S3Key' => $realKey,
        'Ruta' => (string)($row['Ruta'] ?? ''),
        'Nombre' => (string)($row['Nombre'] ?? ''),
        'MinConf' => $minConf,
        'MaxLabels' => $maxLabels,
        'Labels' => $labels,
        'Moderation' => $moderation,
        'ImageProperties' => (array)$labelsResp->get('ImageProperties'),
    ];

    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('No se pudieron serializar los metadatos.');
    }

    $recordId = (int)$row['id_'];
    $stmt = $app->db()->prepare('UPDATE FileS3 SET Metadatos = ? WHERE id_ = ? AND user_id_ = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la actualización de metadatos.');
    }
    $stmt->bind_param('sii', $json, $recordId, $userId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'ok' => true,
        'bucket' => $app->bucket(),
        's3_key' => $realKey,
        'ruta' => (string)($row['Ruta'] ?? ''),
        'nombre' => (string)($row['Nombre'] ?? ''),
        'min_conf' => $minConf,
        'max_labels' => $maxLabels,
        'labels' => $labels,
        'moderation' => $moderation,
        'saved' => true,
        'message' => 'Análisis realizado y metadatos guardados correctamente.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
''')

# -----------------------------------------------------------------------------
# 5) Bloque archivos: etiquetas visibles + Comprehend según extensión.
# -----------------------------------------------------------------------------
block_path = DRIVE / 'bloque_archivos.php'
block = block_path.read_text(encoding='utf-8')
block = replace_once(
    block,
    "$analizarExt = ['jpg','jpeg','png','tif','tiff','bmp'];",
    "$analizarExt = ['jpg','jpeg','png','tif','tiff','bmp'];\n$comprehendExt = ['txt','jas','md','markdown','csv','json','html','htm','xml','sql','log','srt','vtt','php','phtml','js','mjs','css','py','ini','cfg','conf','yaml','yml'];",
    'extensiones Comprehend'
)
block = replace_once(
    block,
    "      $puedeAna= in_array($ext, $analizarExt, true);",
    "      $puedeAna= in_array($ext, $analizarExt, true);\n      $puedeComprehend = in_array($ext, $comprehendExt, true);",
    'flag Comprehend'
)

repls = {
    'class="btn btn-sm btn-primary btn-accion-ia js-textract"': 'class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-textract"',
    '<span class="btn-texto-oculto">DOC2TXT</span>': '<span class="aws-action-label">Extraer texto</span>',
    'title="DOC2TXT"': 'title="Amazon Textract · Extraer texto"',
    'aria-label="DOC2TXT"': 'aria-label="Amazon Textract · Extraer texto"',
    'class="btn btn-sm btn-primary btn-accion-ia js-transcribir"': 'class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-transcribir"',
    '<span class="btn-texto-oculto">AUDIO2TXT</span>': '<span class="aws-action-label">Transcribir</span>',
    '<span class="btn-texto-oculto">VIDEO2TXT</span>': '<span class="aws-action-label">Transcribir</span>',
    'title="AUDIO2TXT"': 'title="Amazon Transcribe · Audio a texto"',
    'aria-label="AUDIO2TXT"': 'aria-label="Amazon Transcribe · Audio a texto"',
    'title="VIDEO2TXT"': 'title="Amazon Transcribe · Video a texto"',
    'aria-label="VIDEO2TXT"': 'aria-label="Amazon Transcribe · Video a texto"',
    'class="btn btn-sm btn-primary btn-accion-ia js-polly"': 'class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-polly"',
    '<span class="btn-texto-oculto">TXT2AUDIO</span>': '<span class="aws-action-label">Crear audio</span>',
    'title="TXT2AUDIO"': 'title="Amazon Polly · Texto a audio"',
    'aria-label="TXT2AUDIO"': 'aria-label="Amazon Polly · Texto a audio"',
    'class="btn btn-sm btn-primary btn-accion-ia js-traducir"': 'class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-traducir"',
    '<span class="btn-texto-oculto">DOC2TRADUCIR</span>': '<span class="aws-action-label">Traducir</span>',
    'title="DOC2TRADUCIR"': 'title="Amazon Translate · Traducir"',
    'aria-label="DOC2TRADUCIR"': 'aria-label="Amazon Translate · Traducir"',
    'class="btn btn-sm btn-primary btn-accion-ia js-rekognition"': 'class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-rekognition"',
    '<span class="btn-texto-oculto">IMG2ANALISIS</span>': '<span class="aws-action-label">Analizar imagen</span>',
    'title="IMG2ANALISIS"': 'title="Amazon Rekognition · Analizar imagen"',
    'aria-label="IMG2ANALISIS"': 'aria-label="Amazon Rekognition · Analizar imagen"',
}
for old, new in repls.items():
    if old not in block:
        raise SystemExit('No se encontró en bloque_archivos.php: ' + old)
    block = block.replace(old, new)

rekog_end = '''    <i class="fas fa-tags"></i>\n    <span class="aws-action-label">Analizar imagen</span>\n  </button>\n<?php endif; ?>'''
comprehend_button = rekog_end + '''\n\n<?php if ($puedeComprehend): ?>\n  <button type="button"\n          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-comprehend"\n          data-key="<?= FileViewHelper::escape($s3key) ?>"\n          data-nombre="<?= FileViewHelper::escape($nombre) ?>"\n          data-toggle="tooltip"\n          data-placement="top"\n          title="Amazon Comprehend · Analizar texto"\n          aria-label="Amazon Comprehend · Analizar texto">\n    <i class="fas fa-brain"></i>\n    <span class="aws-action-label">Analizar texto</span>\n  </button>\n<?php endif; ?>'''
block = replace_once(block, rekog_end, comprehend_button, 'botón Comprehend')
block_path.write_text(block, encoding='utf-8')

# -----------------------------------------------------------------------------
# 6) Galería: solamente imágenes visibles en la página actual.
# -----------------------------------------------------------------------------
img_path = DRIVE / 'js/imagenes.js'
img = img_path.read_text(encoding='utf-8')
img = img.replace('   - generar_galeria.php\n', '')
img = re.sub(
    r'\n    async function fetchDesdeServidor\(params\)\{.*?\n    \}\n\n    function render\(list, perSlide\)\{',
    '\n    function render(list, perSlide){',
    img,
    count=1,
    flags=re.S
)
old_ver = '''    async function verCarrusel(params){\n      var perSlide = getPerSlide();\n      var list = await fetchDesdeServidor(params);\n\n      if (!list.length) {\n        list = recolectarDesdeBufferODOM();\n      }\n\n      render(list, perSlide);\n      showModal(document.getElementById(ModalId));\n      U.dispatch('galeria-grid:rendered', { total: list.length, perSlide: perSlide });\n    }'''
new_ver = '''    async function verCarrusel(){\n      var perSlide = getPerSlide();\n      // Fuente única: filas de bloque_archivos.php de la página actual.\n      // No consulta toda la carpeta ni S3 ni generar_galeria.php.\n      actualizarBufferGaleria();\n      var list = recolectarDesdeBufferODOM();\n\n      render(list, perSlide);\n      showModal(document.getElementById(ModalId));\n      U.dispatch('galeria-grid:rendered', { total: list.length, perSlide: perSlide });\n    }'''
img = replace_once(img, old_ver, new_ver, 'galería página actual')
needle = '''  document.addEventListener('DOMContentLoaded', function(){\n    actualizarBufferGaleria();\n  });'''
replacement = needle + '''\n\n  // Cada cambio AJAX de página sustituye #bloque-archivos. Reconstruimos\n  // inmediatamente el buffer para que nunca queden imágenes de la página anterior.\n  document.addEventListener('bloque-archivos:actualizado', function(){\n    setTimeout(actualizarBufferGaleria, 0);\n  });'''
img = replace_once(img, needle, replacement, 'refresco buffer galería')
img_path.write_text(img, encoding='utf-8')

# -----------------------------------------------------------------------------
# 7) JS Comprehend.
# -----------------------------------------------------------------------------
write(DRIVE / 'js/aws-comprehend.js', r'''(function (window, document) {
  'use strict';

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function pct(value) {
    var n = Number(value);
    return Number.isFinite(n) ? (n * 100).toFixed(1) + '%' : '—';
  }

  function showModal() {
    var el = document.getElementById('modalComprehend');
    if (!el) return;
    if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function') {
      window.jQuery(el).modal('show');
      return;
    }
    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(el).show();
    }
  }

  function render(data) {
    var body = document.getElementById('comprehendBody');
    if (!body) return;

    var phrases = Array.isArray(data.key_phrases) ? data.key_phrases : [];
    var entities = Array.isArray(data.entities) ? data.entities : [];
    var pii = Array.isArray(data.pii) ? data.pii : [];
    var warnings = Array.isArray(data.warnings) ? data.warnings : [];

    var html = '';
    html += '<div class="mb-3"><strong>Idioma:</strong> ' + esc(data.language || 'No determinado') +
      ' &nbsp; <strong>Sentimiento:</strong> ' + esc(data.sentiment || 'No disponible') + '</div>';

    if (data.truncated) {
      html += '<div class="alert alert-warning py-2">El archivo supera el límite del análisis inmediato; se analizaron los primeros ' +
        esc(data.bytes_analyzed || 0) + ' bytes.</div>';
    }

    if (warnings.length) {
      html += '<div class="alert alert-secondary py-2"><strong>Avisos:</strong><ul class="mb-0">' +
        warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></div>';
    }

    html += '<h6>Frases clave</h6>';
    html += phrases.length
      ? '<div class="mb-3">' + phrases.map(function (p) {
          return '<span class="badge badge-info mr-1 mb-1">' + esc(p.text) + ' · ' + pct(p.score) + '</span>';
        }).join('') + '</div>'
      : '<p class="text-muted">No se detectaron frases clave.</p>';

    html += '<h6>Entidades</h6>';
    if (entities.length) {
      html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Texto</th><th>Tipo</th><th>Conf.</th></tr></thead><tbody>';
      entities.forEach(function (e) {
        html += '<tr><td>' + esc(e.text) + '</td><td>' + esc(e.type) + '</td><td>' + pct(e.score) + '</td></tr>';
      });
      html += '</tbody></table></div>';
    } else {
      html += '<p class="text-muted">No se detectaron entidades.</p>';
    }

    html += '<h6>PII detectada</h6>';
    if (pii.length) {
      html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Tipo</th><th>Conf.</th><th>Rango</th></tr></thead><tbody>';
      pii.forEach(function (e) {
        html += '<tr><td>' + esc(e.type) + '</td><td>' + pct(e.score) + '</td><td>' + esc(e.begin) + '–' + esc(e.end) + '</td></tr>';
      });
      html += '</tbody></table></div>';
    } else {
      html += '<p class="text-muted mb-0">No se detectó PII.</p>';
    }

    body.innerHTML = html;
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.js-comprehend');
    if (!button) return;

    event.preventDefault();
    var key = button.getAttribute('data-key') || '';
    var name = button.getAttribute('data-nombre') || key;
    var title = document.getElementById('comprehendTitle');
    var body = document.getElementById('comprehendBody');
    if (title) title.textContent = 'Amazon Comprehend · ' + name;
    if (body) body.innerHTML = '<div class="alert alert-info mb-0"><i class="fas fa-spinner fa-spin"></i> Analizando texto…</div>';
    showModal();

    fetch('comprehend_archivo.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new URLSearchParams({ key: key })
    })
      .then(function (response) {
        return response.text().then(function (text) {
          var json = null;
          try { json = JSON.parse(text); } catch (_) {}
          if (!response.ok || !json || !json.ok) {
            throw new Error((json && json.error) || text || ('HTTP ' + response.status));
          }
          return json.analysis || {};
        });
      })
      .then(render)
      .catch(function (error) {
        if (body) body.innerHTML = '<div class="alert alert-danger mb-0">' + esc(error.message || error) + '</div>';
      });
  });
})(window, document);
''')

# -----------------------------------------------------------------------------
# 8) Modal + JS de Comprehend en s3.php.
# -----------------------------------------------------------------------------
s3_path = DRIVE / 's3.php'
s3 = s3_path.read_text(encoding='utf-8')
modal_marker = '<!-- Modal: Grabar Audio -->'
modal = '''<!-- Modal Amazon Comprehend -->\n<div class="modal fade" id="modalComprehend" tabindex="-1" role="dialog" aria-hidden="true">\n  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">\n    <div class="modal-content">\n      <div class="modal-header bg-dark text-white">\n        <h5 class="modal-title" id="comprehendTitle">Amazon Comprehend</h5>\n        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>\n      </div>\n      <div class="modal-body" id="comprehendBody">\n        <div class="text-muted">Selecciona un archivo de texto para analizarlo.</div>\n      </div>\n      <div class="modal-footer">\n        <small class="text-muted mr-auto">Entidades · frases clave · sentimiento · PII</small>\n        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>\n      </div>\n    </div>\n  </div>\n</div>\n'''
s3 = replace_once(s3, modal_marker, modal + modal_marker, 'modal Comprehend')
s3 = replace_once(
    s3,
    '<script src="js/imagenes.js"></script>',
    '<script src="js/imagenes.js"></script>\n<script src="js/aws-comprehend.js"></script>',
    'script Comprehend'
)
s3_path.write_text(s3, encoding='utf-8')

# -----------------------------------------------------------------------------
# 9) Etiquetas visibles y responsive en ambos CSS históricos.
# -----------------------------------------------------------------------------
css_block = r'''

/* AWS file actions: icono + etiqueta visible. */
.aws-file-action {
  width: auto !important;
  min-width: 0 !important;
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  gap: .3rem;
  white-space: nowrap;
  padding-left: .5rem !important;
  padding-right: .5rem !important;
}

.aws-file-action .aws-action-label {
  position: static !important;
  width: auto !important;
  height: auto !important;
  overflow: visible !important;
  clip: auto !important;
  white-space: nowrap !important;
  font-size: .72rem;
  line-height: 1;
}

@media (max-width: 768px) {
  .aws-file-action .aws-action-label {
    font-size: .68rem;
  }
}
'''
for rel in ['css/styles.css', 'css/styles-old.css']:
    path = DRIVE / rel
    text = path.read_text(encoding='utf-8')
    if '/* AWS file actions: icono + etiqueta visible. */' not in text:
        text += css_block
    path.write_text(text, encoding='utf-8')
