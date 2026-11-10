<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

use DateTimeImmutable;
use Throwable;

final class SharePageRenderer
{
    public function resolveKind(string $endpointKind, string $tokenType, string $key): string
    {
        if (in_array($endpointKind, ['audio', 'video'], true)) {
            return $endpointKind;
        }

        $tokenType = strtolower(trim($tokenType));
        if ($tokenType === 'audio') {
            return 'audio';
        }
        if ($tokenType === 'video') {
            return 'video';
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
        if (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'ogv'], true)) {
            return 'video';
        }
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'opus', 'm4a', 'aac', 'flac', 'amr'], true)) {
            return 'audio';
        }
        if (in_array($ext, ['txt', 'srt', 'vtt', 'md', 'html', 'htm', 'json', 'csv', 'log', 'ini', 'xml', 'yml', 'yaml'], true)) {
            return 'text';
        }

        return 'file';
    }

    public function render(string $kind, array $share, ?string $content = null, bool $transcription = false): string
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

        $expiry = $this->expiryLabel((string)($share['expira'] ?? ''));
        $expiryHtml = $expiry !== ''
            ? '<div class="meta">Expira: ' . $this->e($expiry) . '</div>'
            : '';

        if ($kind === 'text') {
            $body = $this->textBody((string)$content, $transcription);
        }
        $assets = 'css/share-reader.css?v=2';
        return '<!doctype html>' . "\n"
            . '<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->e($name) . ' · ArcadeCloud Drive</title><link rel="icon" href="ellogo.png">'
            . '<link rel="stylesheet" href="css/styles.css"><link rel="stylesheet" href="' . $assets . '">'
            . '<script src="js/share-reader.js?v=2" defer></script></head>'
            . '<body class="ui-theme theme-neon-green theme-dark vision-normal share-page">'
            . '<header class="share-header"><a href="./" class="share-brand"><img src="ellogo.png" alt="" width="50" height="40">ArcadeCloud <strong>Drive</strong></a><span>Servidor privado de Esforzados</span></header>'
            . '<main class="share-shell"><div class="share-heading"><span class="share-eyebrow">' . ($transcription && $kind === 'text' ? 'Lectura de documento' : $this->e($titleSuffix)) . '</span>'
            . '<h1>' . $this->e($name) . '</h1>' . $expiryHtml . '</div>'
            . $body
            . '<footer class="share-footer"><a class="share-button" href="' . $this->e($url) . '" target="_blank" rel="noopener noreferrer">Abrir original</a><span>ArcadeCloud Drive · Esforzados</span></footer></main></body></html>';
    }

    private function textBody(string $content, bool $transcription): string
    {
        $data = json_decode($content, true);
        $isJson = json_last_error() === JSON_ERROR_NONE;
        $pages = (new ExtractedTextReader())->pages($data);
        $hasTranscript = is_array($data) && is_array($data['results'] ?? null)
            && (isset($data['results']['transcripts']) || isset($data['results']['audio_segments']) || isset($data['results']['items']));
        $book = $transcription && count($pages) > 0;
        $chooser = '';
        if ($isJson) {
            $chooser = '<div class="json-reader-choices" aria-label="Tipo de lectura JSON">'
                . '<button type="button" data-json-mode="transcript"' . (!$hasTranscript ? ' disabled' : '') . ' aria-pressed="' . ($transcription && !$book && $hasTranscript ? 'true' : 'false') . '">Transcripción por tiempos</button>'
                . '<button type="button" data-json-mode="pages"' . (!$pages ? ' disabled' : '') . ' aria-pressed="' . ($book ? 'true' : 'false') . '">Texto de imágenes · Libro</button>'
                . '<button type="button" data-json-mode="raw" aria-pressed="' . (!$transcription ? 'true' : 'false') . '">Ver JSON</button></div>'
                . '<p class="reader-summary">' . ($pages ? 'Texto extraído de imágenes: disponible para lectura por páginas.' : ($hasTranscript ? 'Transcripción detectada: disponible para lectura por tiempos.' : 'Este JSON no contiene páginas ni segmentos de transcripción reconocidos.')) . '</p>';
        }
        // Both structured views are rendered only for recognized schemas.
        $parsed = $hasTranscript ? (new TranscriptReader())->parse($content) : null;
        $text = $parsed !== null ? $parsed['text'] : $content;
        $segments = $parsed['segments'] ?? [];
        $html = '<section class="reader" data-initial-mode="' . ($transcription ? ($book ? 'pages' : ($hasTranscript ? 'transcript' : 'raw')) : 'raw') . '" aria-label="Lector de texto">' . $chooser . '<div class="reader-toolbar">'
            . '<label class="reader-search">Buscar en el texto<input id="reader-search" type="search" placeholder="Escribe una palabra…" maxlength="200"></label>'
            . '<div class="reader-actions"><button type="button" id="reader-prev" aria-label="Coincidencia anterior">↑</button><button type="button" id="reader-next" aria-label="Coincidencia siguiente">↓</button>'
            . '<span id="reader-matches" role="status" aria-live="polite"></span>'
            . '<button type="button" id="reader-smaller" aria-label="Reducir letra">A−</button><button type="button" id="reader-larger" aria-label="Aumentar letra">A+</button>'
            . '<button type="button" id="reader-copy">Copiar texto</button><button type="button" id="reader-print">Imprimir</button>';
        if ($segments) $html .= '<button type="button" id="reader-mode" aria-pressed="false">Texto continuo</button>';
        $html .= '</div></div><p id="reader-status" role="status" aria-live="polite"></p>';
        if ($parsed !== null && $parsed['notice'] !== '') $html .= '<p class="reader-notice">' . $this->e($parsed['notice']) . '</p>';
        if ($segments) {
            $ends = array_filter(array_column($segments, 'end'), static fn($v) => $v !== null);
            $html .= '<p class="reader-summary">' . count($segments) . ' segmentos · Último tiempo: ' . TranscriptReader::timestamp($ends ? max($ends) : null) . '</p>';
        }
        $html .= '<div id="reader-content" class="reader-content">';
        if ($isJson) $html .= '<div data-reader-panel="raw"' . ($transcription && ($pages || $hasTranscript) ? ' hidden' : '') . '><pre class="reader-text reader-plain">' . $this->e($content) . '</pre></div>';
        if ($pages) {
            $html .= '<div data-reader-panel="pages"' . (!$book ? ' hidden' : '') . '><nav class="book-navigation" aria-label="Navegación del libro"><button type="button" id="book-prev">← Anterior</button><span id="book-position" role="status" aria-live="polite"></span><button type="button" id="book-next">Siguiente →</button><label>Ir a página <input id="book-jump" type="number" min="1" max="' . count($pages) . '" value="1"></label></nav><div class="book-spread">';
            foreach ($pages as $i => $page) {
                $html .= '<article class="book-page" data-page-index="' . $i . '"' . ($i > 1 ? ' hidden' : '') . '><header><strong>Página ' . ($i + 1) . '</strong><span>Hoja original ' . $this->e($page['sheet']) . '</span><small>' . $this->e($page['file']) . '</small></header><div class="reader-text book-text">' . $this->e($page['text']) . '</div></article>';
            }
            $html .= '</div></div>';
        }
        if ($parsed !== null) $html .= '<div data-reader-panel="transcript"' . (!$transcription || $book ? ' hidden' : '') . '>';
        $speakers = [];
        foreach ($segments as $i => $segment) {
            $speaker = $segment['speaker'];
            if ($speaker !== '' && !isset($speakers[$speaker])) $speakers[$speaker] = count($speakers) + 1;
            $label = $speaker === '' ? 'Hablante sin identificar' : 'Hablante ' . $speakers[$speaker];
            $html .= '<article class="reader-segment" id="segment-' . ($i + 1) . '"><div class="segment-meta"><a href="#segment-' . ($i + 1) . '">' . TranscriptReader::timestamp($segment['start']) . '–' . TranscriptReader::timestamp($segment['end']) . '</a><span>' . $this->e($label) . '</span></div><p class="reader-text">' . $this->e($segment['text']) . '</p></article>';
        }
        if (!$segments && (!$isJson || $parsed !== null)) $html .= '<pre class="reader-text reader-plain">' . $this->e($text) . '</pre>';
        if ($parsed !== null) $html .= '</div>';
        return $html . '</div></section>';
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

    private function expiryLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return $value;
        }
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
