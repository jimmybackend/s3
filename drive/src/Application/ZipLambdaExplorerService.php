<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use Aws\Lambda\LambdaClient;
use RuntimeException;
use Throwable;

/**
 * Feature-gated server-side Lambda ZIP explorer.
 * The client never chooses the bucket, user, function or AWS credentials.
 */
final class ZipLambdaExplorerService
{
    public static function enabled(): bool
    {
        return filter_var(getenv('ARCADECLOUD_ZIP_LAMBDA_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN)
            && trim((string)getenv('ARCADECLOUD_ZIP_LAMBDA_FUNCTION')) !== '';
    }

    public static function handle(): void
    {
        $app = ApplicationKernel::app();
        $session = $app->session();
        $session->start();
        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            http_response_code(401);
            if (($_GET['format'] ?? '') === 'json') \ArcadeCloud\Drive\Http\JsonResponse::error('Sesión requerida.', 401);
            exit('Sesión requerida.');
        }
        $userId = $session->userId();
        $key = trim((string)($_GET['archivo'] ?? ''));
        try {
            $row = $app->fileRecordLocator()->requireReadableByKey($userId, $key);
        } catch (Throwable $error) {
            if (($_GET['format'] ?? '') === 'json') \ArcadeCloud\Drive\Http\JsonResponse::error('Archivo no disponible o protegido.', 403);
            throw $error;
        }
        if (strtolower(pathinfo((string)$row['Nombre'], PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('El archivo seleccionado no es ZIP.');
        }
        $nested = self::stringArray($_GET['nested'] ?? '');
        $folder = (string)($_GET['dir'] ?? '');
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = $page === false ? 1 : max(1, min(100000, $page));
        $entry = isset($_GET['file']) ? (string)$_GET['file'] : null;
        $selection = isset($_GET['folder']) ? (string)$_GET['folder'] : null;
        $action = $entry !== null ? 'download_file' : ($selection !== null ? 'download_folder' : 'list');
        $request = ['action' => $action, 'key' => (string)$row['_key'], 'nested' => $nested];
        if ($action === 'list') {
            $request['folder'] = $folder;
            $request['page'] = $page;
        } else {
            $request['path'] = $entry ?? $selection;
        }
        $started = microtime(true);
        $recorder = ActivityCostRecorder::fromDatabase($app->db());
        $fileId = (int)($row['id_'] ?? 0);
        $correlation = ActivityCostRecorder::correlation('zip-lambda', bin2hex(random_bytes(16)));
        $region = trim((string)(getenv('ARCADECLOUD_ZIP_LAMBDA_REGION') ?: 'us-east-1'));
        $client = new LambdaClient(['version' => 'latest', 'region' => $region, 'http' => ['timeout' => 900, 'connect_timeout' => 5]]);
        try {
            $response = $client->invoke([
                'FunctionName' => trim((string)getenv('ARCADECLOUD_ZIP_LAMBDA_FUNCTION')),
                'InvocationType' => 'RequestResponse',
                'Payload' => json_encode($request, JSON_THROW_ON_ERROR),
            ]);
            $payload = json_decode((string)$response['Payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || isset($response['FunctionError'])) {
                throw new RuntimeException('La función Lambda no devolvió una respuesta válida.');
            }
            $status = (int)($payload['statusCode'] ?? 500);
            $body = json_decode((string)($payload['body'] ?? ''), true);
            if (!is_array($body)) throw new RuntimeException('Respuesta ZIP inválida.');
            // On an invocation failure Lambda billing may still occur. Record all calls.
            $durationMs = (int)round((microtime(true) - $started) * 1000);
            $units = ['lambda.request' => 1, 'lambda.gb_second_estimate' => ($durationMs / 1000) * (self::memoryMb() / 1024)];
            $metadata = ['lambda_action' => $action, 'duration_estimated_ms' => $durationMs, 'lambda_memory_config_mb' => self::memoryMb(), 'billing_basis' => 'approximate_client_wall_time'];
            if ($status !== 200 || empty($body['ok'])) {
                $recorder->failure($userId, 'zip.lambda.' . $action, 'Lambda', $started, $metadata, $correlation);
                throw new RuntimeException('Lambda rechazó la operación ZIP: ' . (string)($body['error'] ?? 'Error desconocido'));
            }
            $recorder->success($userId, 'zip.lambda.' . $action, 'Lambda', $fileId > 0 ? $fileId : null, $units, $started, $metadata, $correlation);
            if ($action !== 'list') {
                $url = (string)($body['download_url'] ?? '');
                $urlParts = parse_url($url);
                $host = (string)($urlParts['host'] ?? '');
                $expectedBucket = $app->bucket();
                // Prevent unexpected redirects: S3 virtual-hosted endpoint only.
                if (($urlParts['scheme'] ?? '') !== 'https' || $host !== $expectedBucket . '.s3.amazonaws.com'
                    && $host !== $expectedBucket . '.s3.' . $region . '.amazonaws.com') {
                    throw new RuntimeException('Destino de descarga no autorizado.');
                }
                header('Cache-Control: no-store');
                header('Referrer-Policy: no-referrer');
                if (($_GET['format'] ?? '') === 'json') \ArcadeCloud\Drive\Http\JsonResponse::send(['ok' => true, 'download_url' => $url]);
                header('Location: ' . $url, true, 303);
                return;
            }
            if (($_GET['format'] ?? '') === 'json') \ArcadeCloud\Drive\Http\JsonResponse::send($body);
            self::render($key, (string)$row['Nombre'], $nested, $body);
        } catch (Throwable $error) {
            $recorder->failure($userId, 'zip.lambda.' . $action, 'Lambda', $started, ['error_type' => get_class($error)], $correlation);
            if (($_GET['format'] ?? '') === 'json') \ArcadeCloud\Drive\Http\JsonResponse::error('No se pudo completar la operación ZIP con Lambda.', 502);
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'No se pudo completar la operación con Lambda. El ZIP original no fue modificado.';
            error_log('ArcadeCloud ZIP Lambda: ' . $error->getMessage());
        }
    }

    private static function memoryMb(): int
    {
        $value = (int)(getenv('ARCADECLOUD_ZIP_LAMBDA_MEMORY_MB') ?: 128);
        return max(128, min(10240, $value));
    }

    private static function stringArray(mixed $encoded): array
    {
        if (!is_string($encoded) || $encoded === '') return [];
        $value = json_decode($encoded, true);
        if (!is_array($value) || count($value) > 5) throw new RuntimeException('Ruta ZIP anidada inválida.');
        foreach ($value as $part) {
            if (!is_string($part) || strlen($part) > 4096 || !str_ends_with(strtolower($part), '.zip')) {
                throw new RuntimeException('Ruta ZIP anidada inválida.');
            }
        }
        return array_values($value);
    }

    private static function render(string $key, string $name, array $nested, array $body): void
    {
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $base = 'zip_explorer.php?archivo=' . rawurlencode($key);
        $url = static fn(array $stack, array $params = []): string =>
            $base . '&nested=' . rawurlencode(json_encode($stack, JSON_UNESCAPED_SLASHES)) . '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $dir = (string)($body['folder'] ?? '');
        $page = (int)($body['page'] ?? 1);
        $total = (int)($body['total'] ?? 0);
        $size = max(1, (int)($body['page_size'] ?? 100));
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>'
            . '*{box-sizing:border-box}body{background:#0d2033;color:#e3eef8;font:14px Arial,sans-serif;margin:0}header,main{padding:16px}a{color:#95ddff}'
            . '.item{padding:12px 5px;border-bottom:1px solid #345;display:flex;gap:12px;align-items:center}.name{flex:1;overflow-wrap:anywhere}'
            . 'nav{display:flex;gap:16px;flex-wrap:wrap;margin:16px 0}.muted{color:#b8c9d6}</style></head><body><header><strong>ZIP en AWS Lambda — '
            . $esc($name) . '</strong><p class="muted">Extracción en S3; la EC2 no descarga el ZIP.</p></header><main>';
        if ($dir !== '') {
            $parent = rtrim($dir, '/');
            $parent = str_contains($parent, '/') ? substr($parent, 0, strrpos($parent, '/') + 1) : '';
            echo '<nav><a href="' . $esc($url($nested, ['dir' => $parent])) . '">← Carpeta anterior</a></nav>';
        } elseif ($nested !== []) {
            $parentStack = $nested;
            array_pop($parentStack);
            echo '<nav><a href="' . $esc($url($parentStack)) . '">← ZIP anterior</a></nav>';
        }
        echo '<p>/' . $esc(implode(' → ', $nested)) . ($nested !== [] ? ' → ' : '') . $esc($dir) . '</p>';
        foreach (($body['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $path = (string)($item['path'] ?? '');
            $label = $esc((string)($item['name'] ?? ''));
            $type = (string)($item['type'] ?? '');
            echo '<div class="item"><span>' . ($type === 'folder' ? '📁' : ($type === 'zip' ? '🗜️' : '📄')) . '</span><span class="name">' . $label . '</span>';
            if ($type === 'folder') {
                echo '<a href="' . $esc($url($nested, ['dir' => $path])) . '">Abrir</a> '
                    . '<a href="' . $esc($url($nested, ['folder' => $path])) . '">Descargar carpeta</a>';
            } elseif ($type === 'zip') {
                echo '<a href="' . $esc($url(array_merge($nested, [$path]))) . '">Abrir ZIP</a> ';
                echo '<a href="' . $esc($url($nested, ['file' => $path])) . '">Descargar ZIP</a>';
            } else {
                echo '<a href="' . $esc($url($nested, ['file' => $path])) . '">Descargar</a>';
            }
            echo '</div>';
        }
        if (empty($body['items'])) echo '<p>Esta carpeta no contiene elementos.</p>';
        echo '<nav><span>Página ' . $page . ' / ' . max(1, (int)ceil($total / $size)) . '</span>';
        if ($page > 1) echo '<a href="' . $esc($url($nested, ['dir' => $dir, 'page' => $page - 1])) . '">Anterior</a>';
        if ($page * $size < $total) echo '<a href="' . $esc($url($nested, ['dir' => $dir, 'page' => $page + 1])) . '">Siguiente</a>';
        echo '</nav></main></body></html>';
    }
}
