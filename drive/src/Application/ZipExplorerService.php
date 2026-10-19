<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Core\ApplicationKernel;
use RuntimeException;
use Throwable;
use ZipArchive;

final class ZipExplorerService
{
    public static function handle(): void
    {
        $app = ApplicationKernel::app();
        $session = $app->session();
        $session->start();
        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            http_response_code(401);
            exit('Sesión requerida.');
        }

        $key = trim((string)($_GET['archivo'] ?? ''));
        $selected = isset($_GET['entry']) ? filter_var($_GET['entry'], FILTER_VALIDATE_INT) : null;
        $selectedFolder = isset($_GET['folder']) ? (string)$_GET['folder'] : null;
        $tmp = null;
        $zip = null;
        try {
            $row = $app->fileRecordLocator()->requireReadableByKey($session->userId(), $key);
            if (strtolower(pathinfo((string)$row['Nombre'], PATHINFO_EXTENSION)) !== 'zip') {
                throw new RuntimeException('El archivo seleccionado no es ZIP.');
            }
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('El servidor requiere la extensión PHP ZipArchive.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'arc_zip_');
            if ($tmp === false) throw new RuntimeException('No fue posible crear el temporal.');
            $app->s3()->getObject([
                'Bucket' => $app->bucket(),
                'Key' => (string)$row['_key'],
                'SaveAs' => $tmp,
            ]);
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) throw new RuntimeException('ZIP inválido.');

            $safeEntry = static function (string $name): bool {
                if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) return false;
                if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) return false;
                foreach (explode('/', $name) as $part) {
                    if ($part === '..') return false;
                }
                return true;
            };
            $directory = (string)($_GET['dir'] ?? '');
            if ($directory !== '' && (!$safeEntry($directory) || !str_ends_with($directory, '/'))) {
                throw new RuntimeException('Carpeta inválida.');
            }
            $query = trim((string)($_GET['q'] ?? ''));
            if (strlen($query) > 120) throw new RuntimeException('Búsqueda demasiado larga.');
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
            $page = $page === false ? 1 : max(1, $page);
            $pageSize = 100;
            if ($selectedFolder !== null) {
                if ($selectedFolder === '' || !$safeEntry($selectedFolder) || !str_ends_with($selectedFolder, '/')) {
                    throw new RuntimeException('Carpeta ZIP inválida.');
                }
                self::downloadFolder($zip, $selectedFolder);
                return;
            }

            if ($selected !== null) {
                if ($selected === false) throw new RuntimeException('Entrada inválida.');
                $stat = ($selected >= 0 && $selected < $zip->numFiles) ? $zip->statIndex($selected) : false;
                $name = is_array($stat) ? (string)($stat['name'] ?? '') : '';
                $found = ($stat && $safeEntry($name)) ? [
                    'index' => $selected, 'name' => $name,
                    'dir' => str_ends_with($name, '/'),
                    'encrypted' => !empty($stat['encryption_method']) && (int)$stat['encryption_method'] !== ZipArchive::EM_NONE,
                ] : null;
                if (!$found || $found['dir'] || $found['encrypted']) throw new RuntimeException('No se puede extraer esta entrada.');
                $stream = $zip->getStream($found['name']);
                if (!is_resource($stream)) throw new RuntimeException('No se puede leer esta entrada.');
                $downloadName = basename($found['name']);
                $downloadName = str_replace(["\r","\n",'"'], '', $downloadName);
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . substr($downloadName, 0, 180) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: no-store');
                $sent = 0;
                while (!feof($stream)) {
                    $part = fread($stream, 65536);
                    if ($part === false) break;
                    if ($part === '') break;
                    echo $part;
                    if (connection_aborted()) break;
                }
                fclose($stream);
                $zip->close();
                $zip = null;
                @unlink($tmp);
                $tmp = null;
                exit;
            }

            // Server-side directory listing. Never send the complete archive index to the browser.
            $children = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) continue;
                $name = (string)($stat['name'] ?? '');
                if (!$safeEntry($name) || !str_starts_with($name, $directory)) continue;
                $rest = substr($name, strlen($directory));
                if ($rest === '') continue;
                $slash = strpos($rest, '/');
                $isDir = $slash !== false;
                $label = $isDir ? substr($rest, 0, $slash) : $rest;
                if ($label === '') continue;
                if ($query !== '' && stripos($label, $query) === false) continue;
                $full = $isDir ? $directory . $label . '/' : $name;
                if (!isset($children[$full])) {
                    $children[$full] = [
                        'name' => $label, 'full' => $full, 'dir' => $isDir, 'index' => $i,
                        'size' => (int)($stat['size'] ?? 0),
                        'encrypted' => !empty($stat['encryption_method'])
                            && (int)$stat['encryption_method'] !== ZipArchive::EM_NONE,
                    ];
                }
            }
            $items = array_values($children);
            usort($items, static fn(array $a, array $b): int =>
                ((int)$b['dir'] <=> (int)$a['dir']) ?: strnatcasecmp($a['name'], $b['name']));
            $total = count($items);
            $pages = max(1, (int)ceil($total / $pageSize));
            $page = min($page, $pages);
            $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);
            $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $base = 'zip_explorer.php?archivo=' . rawurlencode($key);
            $url = static fn(string $dir, int $p = 1, string $q = ''): string =>
                $base . '&dir=' . rawurlencode($dir) . '&page=' . $p . '&q=' . rawurlencode($q);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<style>*{box-sizing:border-box}body{margin:0;background:#0d2033;color:#e3eef8;font:14px Arial,sans-serif}'
                . 'header{padding:14px;background:#132e46;border-bottom:1px solid #294962}main{padding:14px}'
                . 'a{color:#91ddff}.item{display:flex;align-items:center;gap:12px;padding:10px;border-bottom:1px solid #294057}'
                . '.name{flex:1;overflow-wrap:anywhere}.muted{color:#a9c2d5;font-size:12px}'
                . 'input{padding:9px;background:#102a43;color:white;border:1px solid #395875;border-radius:5px;max-width:100%}'
                . 'button{padding:9px;background:#22527a;color:white;border:0;border-radius:5px}nav{display:flex;gap:15px;margin:15px 0}'
                . '</style></head><body><header><strong>Explorador ZIP — ' . $escape((string)$row['Nombre'])
                . '</strong><div class="muted">Navegación por carpeta y páginas de 100 elementos. Se extrae solo lo seleccionado.</div></header><main>';
            $parent = rtrim($directory, '/');
            $parent = str_contains($parent, '/') ? substr($parent, 0, strrpos($parent, '/') + 1) : '';
            echo '<nav><a href="' . $escape($url($parent)) . '">⬅ Carpeta anterior</a><span>/'
                . $escape($directory) . '</span></nav>';
            echo '<form method="get" action="zip_explorer.php"><input type="hidden" name="archivo" value="' . $escape($key)
                . '"><input type="hidden" name="dir" value="' . $escape($directory)
                . '"><input type="search" name="q" value="' . $escape($query)
                . '" placeholder="Buscar en esta carpeta"><button type="submit">Buscar</button></form>';
            foreach ($items as $item) {
                echo '<div class="item"><span>' . ($item['dir'] ? '📁' : '📄') . '</span><span class="name">'
                    . $escape($item['name']) . '</span>';
                if ($item['dir']) {
                    echo '<a href="' . $escape($url($item['full'])) . '">Abrir</a>'
                        . ' <a href="' . $escape($base . '&folder=' . rawurlencode($item['full'])) . '">Extraer carpeta ZIP</a>';
                } elseif ($item['encrypted']) {
                    echo '<span class="muted">Cifrado</span>';
                } else {
                    echo '<span class="muted">' . $escape(number_format($item['size'] / 1024, 1)) . ' KB</span>'
                        . '<a href="' . $escape($base . '&entry=' . $item['index']) . '">Extraer archivo</a>';
                }
                echo '</div>';
            }
            if (!$items) echo '<p>No hay elementos en esta carpeta.</p>';
            echo '<nav><span>Página ' . $page . ' de ' . $pages . ' (' . $total . ' elementos)</span>';
            if ($page > 1) echo '<a href="' . $escape($url($directory, $page - 1, $query)) . '">Anterior</a>';
            if ($page < $pages) echo '<a href="' . $escape($url($directory, $page + 1, $query)) . '">Siguiente</a>';
            echo '</nav></main></body></html>';
        } catch (Throwable $error) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No se pudo explorar el ZIP: ' . $error->getMessage();
        } finally {
            if ($zip instanceof ZipArchive) $zip->close();
            if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
        }
    }

    /**
     * Create a ZIP containing only the requested subtree.
     * Streams one source entry at a time to disk; never loads extracted files into PHP memory.
     * Native temporary disk capacity, execution timeout and ZIP format limits still apply.
     */
    private static function downloadFolder(ZipArchive $source, string $prefix): void
    {
        $work = tempnam(sys_get_temp_dir(), 'arc_folder_');
        if ($work === false) throw new RuntimeException('No hay espacio temporal para extraer la carpeta.');
        @unlink($work);
        if (!mkdir($work, 0700)) throw new RuntimeException('No se pudo preparar la extracción.');
        $output = $work . '/selection.zip';
        $dest = new ZipArchive();
        $opened = $dest->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) throw new RuntimeException('No se pudo crear el ZIP de la carpeta.');
        $files = [];
        $completed = false;
        try {
            $count = 0;
            for ($i = 0; $i < $source->numFiles; $i++) {
                $stat = $source->statIndex($i);
                if (!is_array($stat)) continue;
                $name = (string)($stat['name'] ?? '');
                if (!str_starts_with($name, $prefix)) continue;
                $relative = substr($name, strlen($prefix));
                if ($relative === '') continue;
                if (str_contains($relative, "\0") || str_contains($relative, '\\')
                    || str_starts_with($relative, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $relative)) {
                    throw new RuntimeException('La carpeta contiene una ruta insegura.');
                }
                if (str_ends_with($relative, '/')) {
                    if (!$dest->addEmptyDir($relative)) throw new RuntimeException('No se pudo añadir un directorio.');
                    continue;
                }
                if (!empty($stat['encryption_method']) && (int)$stat['encryption_method'] !== ZipArchive::EM_NONE) {
                    throw new RuntimeException('La carpeta contiene archivos cifrados; no puede extraerse parcialmente.');
                }
                $input = $source->getStreamIndex($i);
                if (!is_resource($input)) throw new RuntimeException('No se pudo leer una entrada de la carpeta.');
                $file = $work . '/part_' . $i;
                $out = fopen($file, 'wb');
                if (!is_resource($out)) { fclose($input); throw new RuntimeException('Sin espacio temporal suficiente.'); }
                $copied = stream_copy_to_stream($input, $out);
                fclose($input);
                fclose($out);
                if ($copied === false || $copied !== (int)$stat['size']) {
                    throw new RuntimeException('Lectura incompleta de una entrada ZIP.');
                }
                $files[] = $file;
                if (!$dest->addFile($file, $relative)) throw new RuntimeException('No se pudo añadir el archivo seleccionado.');
                $count++;
            }
            if ($count === 0) throw new RuntimeException('La carpeta no contiene archivos extraíbles.');
            if (!$dest->close()) throw new RuntimeException('No se pudo completar el ZIP seleccionado.');
            $completed = true;
            $filename = str_replace(["\r", "\n", '"'], '', basename(rtrim($prefix, '/'))) . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="selected-folder.zip"; filename*=UTF-8' . "''" . rawurlencode($filename));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            $stream = fopen($output, 'rb');
            if (!is_resource($stream)) throw new RuntimeException('No se pudo abrir la carpeta comprimida.');
            while (!feof($stream) && !connection_aborted()) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
            }
            fclose($stream);
        } finally {
            if (!$completed) $dest->close();
            foreach ($files as $file) @unlink($file);
            @unlink($output);
            @rmdir($work);
        }
    }
}
