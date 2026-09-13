<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use RuntimeException;
use ZipArchive;

final class ArcadeLinkBundleService
{
    /**
     * @param array<int,array{filename:string,content:string}> $links
     * @return array{path:string,filename:string,count:int,launcher:string}
     */
    public function create(array $links, string $portalUrl): array
    {
        if ($links === []) {
            throw new FederationException('No hay ArcadeLinks para empaquetar.');
        }

        $portalUrl = trim($portalUrl);
        $parts = parse_url($portalUrl);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new FederationException('URL del portal FederationCloud inválida.', 500);
        }

        if (!class_exists(ZipArchive::class)) {
            throw new FederationException('La extensión ZIP de PHP no está disponible.', 500);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'arcadelink_bundle_');
        if ($tmp === false) {
            throw new FederationException('No se pudo preparar el paquete ArcadeLink.', 500);
        }

        $zipPath = $tmp . '.zip';
        @unlink($tmp);

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($zipPath);
            throw new FederationException('No se pudo crear el ZIP ArcadeLink.', 500);
        }

        try {
            $launcher = 'ABRIR-FEDERATIONCLOUD-WINDOWS-LINUX-MAC.html';
            if (!$zip->addFromString($launcher, $this->launcherHtml($portalUrl))) {
                throw new RuntimeException('No se pudo agregar el acceso al portal.');
            }

            $used = [];
            foreach ($links as $index => $link) {
                $content = (string)($link['content'] ?? '');
                if ($content === '' || strlen($content) > ArcadeLinkService::MAX_BYTES) {
                    throw new FederationException('ArcadeLink inválido dentro del paquete.');
                }

                $name = $this->safeArcadeLinkName((string)($link['filename'] ?? ''), $index + 1);
                $base = $name;
                $suffix = 2;
                while (isset($used[strtolower($name)])) {
                    $stem = preg_replace('/\.arcadelink\z/i', '', $base) ?: 'resource';
                    $name = $stem . '-' . $suffix . '.arcadelink';
                    $suffix++;
                }
                $used[strtolower($name)] = true;

                if (!$zip->addFromString($name, $content)) {
                    throw new RuntimeException('No se pudo agregar un ArcadeLink al ZIP.');
                }
            }
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($zipPath);
            if ($error instanceof FederationException) {
                throw $error;
            }
            throw new FederationException('No se pudo construir el paquete portable ArcadeLink.', 500);
        }

        if (!$zip->close() || !is_file($zipPath)) {
            @unlink($zipPath);
            throw new FederationException('No se pudo finalizar el ZIP ArcadeLink.', 500);
        }

        return [
            'path' => $zipPath,
            'filename' => count($links) === 1 ? 'ArcadeLink-portable.zip' : 'ArcadeLinks-portables.zip',
            'count' => count($links),
            'launcher' => $launcher,
        ];
    }

    private function safeArcadeLinkName(string $filename, int $position): string
    {
        $filename = preg_replace('/[^\pL\pN._ -]+/u', '-', trim($filename)) ?? '';
        $filename = trim($filename, " .-_\t\n\r\0\x0B");
        if ($filename === '') {
            $filename = 'resource-' . $position . '.arcadelink';
        }
        if (!preg_match('/\.arcadelink\z/i', $filename)) {
            $filename .= '.arcadelink';
        }
        if (function_exists('mb_substr')) {
            $stem = preg_replace('/\.arcadelink\z/i', '', $filename) ?? $filename;
            $stem = mb_substr($stem, 0, 120);
            return $stem . '.arcadelink';
        }
        $stem = preg_replace('/\.arcadelink\z/i', '', $filename) ?? $filename;
        return substr($stem, 0, 120) . '.arcadelink';
    }

    private function launcherHtml(string $portalUrl): string
    {
        $url = htmlspecialchars($portalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html>' . "\n"
            . '<html lang="es"><head><meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width,initial-scale=1">' . "\n"
            . '<title>Abrir FederationCloud</title></head>' . "\n"
            . '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:760px;margin:48px auto;padding:0 20px;line-height:1.5">' . "\n"
            . '<h1>FederationCloud · ArcadeLink</h1>' . "\n"
            . '<p>Este acceso funciona en Windows, Linux y macOS usando el navegador predeterminado.</p>' . "\n"
            . '<p><a href="' . $url . '" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#0d6efd;color:#fff;text-decoration:none;font-weight:700">Abrir FederationCloud</a></p>' . "\n"
            . '<p>Después, selecciona o deposita el archivo <strong>.arcadelink</strong> incluido en este ZIP para validar y abrir el recurso.</p>' . "\n"
            . '<p><small>El .arcadelink contiene un pasaporte firmado del recurso; no incluye credenciales AWS ni una URL S3 permanente.</small></p>' . "\n"
            . '</body></html>' . "\n";
    }
}
