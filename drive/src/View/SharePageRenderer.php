<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class SharePageRenderer
{
    public function resolveKind(string $endpointKind, string $tokenType, string $key): string
    {
        if (in_array($endpointKind, ['audio', 'video'], true)) {
            return $endpointKind;
        }

        if ($tokenType === 'imagen') {
            return 'image';
        }
        if ($tokenType === 'texto') {
            return 'text';
        }

        $ext = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return 'image';
        }
        if (in_array($ext, ['txt', 'srt', 'vtt', 'md', 'html', 'htm', 'json', 'csv', 'log', 'ini', 'xml', 'yml', 'yaml'], true)) {
            return 'text';
        }

        return 'file';
    }

    public function render(string $kind, array $share, ?string $content = null): string
    {
        $key = (string)($share['key'] ?? '');
        $name = (string)($share['nombre'] ?? basename($key));
        $url = (string)($share['url'] ?? '');

        $titleSuffix = match ($kind) {
            'audio' => 'Audio compartido',
            'video' => 'Video compartido',
            'image' => 'Imagen compartida',
            'text' => 'Texto compartido',
            default => 'Archivo compartido',
        };

        $body = match ($kind) {
            'audio' => '<audio controls preload="metadata" src="' . $this->e($url) . '"></audio>',
            'video' => '<video controls playsinline preload="metadata" src="' . $this->e($url) . '"></video>',
            'image' => '<img src="' . $this->e($url) . '" alt="">',
            'text' => '<pre>' . $this->e((string)$content) . '</pre>',
            default => '<p>Este archivo puede abrirse mediante el enlace compartido.</p>',
        };

        return '<!doctype html>\n'
            . '<html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->e($name) . ' · ' . $this->e($titleSuffix) . '</title>'
            . '<style>:root{color-scheme:dark}body{background:#0b0b0b;color:#fff;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}.wrap{max-width:1100px;margin:0 auto;padding:16px}.box{background:#111;padding:16px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.4);overflow:auto}audio,video{width:100%;height:auto}img{max-width:100%;height:auto;display:block;margin:0 auto;border-radius:8px}pre{white-space:pre-wrap;word-break:break-word;margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.meta{opacity:.8;font-size:.9rem;margin-top:8px;word-break:break-all}.btn{display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #888;border-radius:8px;color:#fff;text-decoration:none}.btn:hover{background:#1b1b1b}</style>'
            . '</head><body><div class="wrap">'
            . '<h1 style="font-size:1.1rem;font-weight:600;margin:8px 0 12px">' . $this->e($name) . '</h1>'
            . '<div class="box">' . $body . '</div>'
            . '<div class="meta">Ruta: ' . $this->e($key) . '</div>'
            . '<a class="btn" href="' . $this->e($url) . '" target="_blank" rel="noopener">Abrir directo</a>'
            . '</div></body></html>';
    }

    public function error(string $message): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Enlace no disponible</title></head>'
            . '<body style="font-family:system-ui,sans-serif;padding:2rem">'
            . $this->e($message)
            . '</body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
