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
        $maxArchive = 128 * 1024 * 1024;
        $maxExtract = 256 * 1024 * 1024;
        $maxItems = 10000;
        $tmp = null;
        $zip = null;
        try {
            $row = $app->fileRecordLocator()->requireReadableByKey($session->userId(), $key);
            if (strtolower(pathinfo((string)$row['Nombre'], PATHINFO_EXTENSION)) !== 'zip') {
                throw new RuntimeException('El archivo seleccionado no es ZIP.');
            }
            if ((int)$row['Tamano'] > $maxArchive) {
                throw new RuntimeException('Este ZIP supera el límite de exploración temporal de 128 MB.');
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
            if (filesize($tmp) > $maxArchive) throw new RuntimeException('ZIP demasiado grande.');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) throw new RuntimeException('ZIP inválido.');
            if ($zip->numFiles > $maxItems) throw new RuntimeException('ZIP con demasiadas entradas.');

            $safeEntry = static function (string $name): bool {
                if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) return false;
                if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) return false;
                foreach (explode('/', $name) as $part) {
                    if ($part === '..') return false;
                }
                return true;
            };
            $items = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) continue;
                $name = (string)($stat['name'] ?? '');
                if (!$safeEntry($name)) continue;
                $isDir = str_ends_with($name, '/');
                if (!$isDir && (int)($stat['size'] ?? 0) > $maxExtract) continue;
                $items[] = ['index' => $i, 'name' => $name, 'dir' => $isDir, 'size' => (int)($stat['size'] ?? 0), 'encrypted' => !empty($stat['encryption_method']) && (int)$stat['encryption_method'] !== ZipArchive::EM_NONE];
            }

            if ($selected !== null) {
                if ($selected === false) throw new RuntimeException('Entrada inválida.');
                $found = null;
                foreach ($items as $item) if ($item['index'] === $selected) { $found = $item; break; }
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
                    $sent += strlen($part);
                    if ($sent > $maxExtract) break;
                    echo $part;
                }
                fclose($stream);
                $zip->close();
                $zip = null;
                @unlink($tmp);
                $tmp = null;
                exit;
            }

            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $baseUrl = 'zip_explorer.php?archivo=' . rawurlencode($key);
            echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
            *{box-sizing:border-box}body{margin:0;background:#0d2033;color:#e3eef8;font:13px Arial,sans-serif}
            header{padding:12px 16px;background:#132e46;border-bottom:1px solid #294962}
            .hint{font-size:12px;color:#a9c2d5;margin-top:6px}
            main{padding:12px 16px}.item{display:flex;gap:12px;align-items:center;padding:7px 9px;border-bottom:1px solid #294057}
            .item:hover{background:#183952}.name{flex:1;overflow-wrap:anywhere}.size{min-width:90px;text-align:right;color:#b5c6d4}
            button,a{color:#91ddff}button{background:none;border:1px solid #446b84;border-radius:5px;padding:5px 10px;cursor:pointer}
            .hidden{display:none!important}.crumb{display:flex;gap:10px;align-items:center;margin-bottom:10px}
            input{width:100%;padding:7px;border-radius:5px;border:1px solid #395875;background:#102a43;color:white;margin-bottom:10px}
            </style></head><body><header><strong>Explorador ZIP — ' . $escape((string)$row['Nombre']) . '</strong><div class="hint">' . count($items) . ' elementos. No se descomprime el ZIP completo en Mi Drive. Extraer descarga únicamente el archivo seleccionado.</div></header><main>
            <div class="crumb"><button type="button" id="back">⬅ Carpeta anterior</button><span id="path">/</span></div>
            <input id="search" type="search" placeholder="Buscar en la carpeta actual"><div id="files"></div></main>
            <script>const entries=' . json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) . ';
            const base=' . json_encode($baseUrl) . ';let current="";const root=document.getElementById("files"),path=document.getElementById("path"),search=document.getElementById("search");
            const render=()=>{root.replaceChildren();path.textContent="/"+current;const direct=new Map();
              entries.forEach(e=>{if(!e.name.startsWith(current))return;const rest=e.name.slice(current.length);if(!rest)return;const slash=rest.indexOf("/");const dir=slash>=0;const label=dir?rest.slice(0,slash):rest;const child=dir?current+label+"/":e.name;if(!label)return;
                const old=direct.get(child);if(!old||(!dir&&old.dir))direct.set(child,{name:label,dir,full:child,index:e.index,size:e.size,encrypted:e.encrypted});});
              [...direct.values()].filter(e=>e.name.toLowerCase().includes(search.value.toLowerCase())).sort((a,b)=>Number(b.dir)-Number(a.dir)||a.name.localeCompare(b.name,"es")).forEach(e=>{
                const line=document.createElement("div");line.className="item";const icon=document.createElement("span");icon.textContent=e.dir?"📁":"📄";const name=document.createElement("span");name.className="name";name.textContent=e.name;line.append(icon,name);
                if(e.dir){const button=document.createElement("button");button.textContent="Abrir";button.onclick=()=>{current=e.full;search.value="";render()};line.append(button)}
                else{const size=document.createElement("span");size.className="size";size.textContent=(e.size/1024).toFixed(1)+" KB";line.append(size);const link=document.createElement("a");link.href=base+"&entry="+e.index;link.textContent=e.encrypted?"Cifrado":"Extraer archivo";if(e.encrypted){link.removeAttribute("href");link.setAttribute("aria-disabled","true")};line.append(link)}
                root.append(line)});
              if(!root.children.length)root.textContent="No hay archivos en esta carpeta.";};
            document.getElementById("back").onclick=()=>{const p=current.replace(/\/$/,"");current=p.includes("/")?p.slice(0,p.lastIndexOf("/")+1):"";search.value="";render()};search.oninput=render;render();
            </script></body></html>';
        } catch (Throwable $error) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No se pudo explorar el ZIP: ' . $error->getMessage();
        } finally {
            if ($zip instanceof ZipArchive) $zip->close();
            if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
        }
    }
}
