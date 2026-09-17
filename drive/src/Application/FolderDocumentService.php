<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\Upload\SingleUploadService;
use DOMDocument;
use DOMElement;
use DOMNode;
use mysqli;
use RuntimeException;

/**
 * Crea documentos de texto dentro de una carpeta real del Drive.
 *
 * MySQL sigue siendo la fuente de verdad para validar la carpeta destino y
 * SingleUploadService conserva la escritura normal S3 + FileS3.
 */
final class FolderDocumentService
{
    private const MAX_BYTES = 2_000_000;
    private const FORMATS = [
        'html' => ['extension' => 'html', 'mime' => 'text/html; charset=utf-8'],
        'md' => ['extension' => 'md', 'mime' => 'text/markdown; charset=utf-8'],
        'txt' => ['extension' => 'txt', 'mime' => 'text/plain; charset=utf-8'],
    ];

    public function __construct(
        private mysqli $db,
        private SingleUploadService $uploads,
        private UserStoragePath $paths
    ) {
    }

    public function create(
        int $userId,
        string $route,
        string $name,
        string $format,
        string $plainText,
        string $richHtml,
        string $remoteAddr = 'unknown',
        string $userAgent = 'unknown'
    ): array {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $route = $this->paths->normalizeForUser($route, $userId);
        $this->assertFolderExists($userId, $route);

        $format = strtolower(trim($format));
        if (!isset(self::FORMATS[$format])) {
            throw new RuntimeException('Formato de documento no permitido.');
        }

        $filename = $this->normalizeFilename($name, self::FORMATS[$format]['extension']);
        $payload = match ($format) {
            'html' => $this->htmlDocument($filename, $richHtml !== '' ? $richHtml : nl2br($this->escape($plainText))),
            'md', 'txt' => $this->normalizePlainText($plainText),
            default => throw new RuntimeException('Formato de documento no permitido.'),
        };

        if (trim($payload) === '') {
            throw new RuntimeException('Pega o escribe contenido antes de guardar.');
        }

        $bytes = strlen($payload);
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('El documento supera el límite de 2 MB.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'arcade-doc-');
        if (!is_string($tmp) || $tmp === '') {
            throw new RuntimeException('No se pudo crear el archivo temporal del documento.');
        }

        try {
            if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
                throw new RuntimeException('No se pudo preparar el documento para guardarlo.');
            }

            $result = $this->uploads->upload(
                $tmp,
                $filename,
                $route,
                $userId,
                self::FORMATS[$format]['mime'],
                $bytes,
                $remoteAddr,
                $userAgent
            );
        } finally {
            @unlink($tmp);
        }

        $result['format'] = $format;
        $result['mime_type'] = self::FORMATS[$format]['mime'];
        return $result;
    }

    private function assertFolderExists(int $userId, string $route): void
    {
        $root = $this->paths->rootForUser($userId);
        if ($route === $root) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id_ FROM S3Folders WHERE user_id_ = ? AND Prefix = ? AND Found = 1 LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar la carpeta destino: ' . $this->db->error);
        }
        $stmt->bind_param('is', $userId, $route);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()?->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            throw new RuntimeException('La carpeta destino ya no existe o no pertenece a este usuario.');
        }
    }

    private function normalizeFilename(string $name, string $extension): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Indica un nombre para el documento.');
        }
        if ($name === '.' || $name === '..' || strpbrk($name, "\\/:*?\"<>|") !== false || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('El nombre del documento contiene caracteres no permitidos.');
        }

        $name = preg_replace('/\.(html?|md|markdown|txt)$/i', '', $name) ?? $name;
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') {
            throw new RuntimeException('Indica un nombre válido para el documento.');
        }

        if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 180) {
            throw new RuntimeException('El nombre del documento es demasiado largo.');
        }
        if (!function_exists('mb_strlen') && strlen($name) > 180) {
            throw new RuntimeException('El nombre del documento es demasiado largo.');
        }

        return $name . '.' . $extension;
    }

    private function normalizePlainText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return rtrim($text) . "\n";
    }

    private function htmlDocument(string $filename, string $fragment): string
    {
        $safe = $this->sanitizeHtmlFragment($fragment);
        if (trim(strip_tags($safe)) === '') {
            throw new RuntimeException('Pega o escribe contenido antes de guardar.');
        }

        $title = $this->escape(pathinfo($filename, PATHINFO_FILENAME));
        return "<!doctype html>\n"
            . "<html lang=\"es\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
            . "<title>{$title}</title>\n"
            . "<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;line-height:1.55;max-width:960px;margin:2rem auto;padding:0 1rem;color:#1f2937}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f4f6;padding:1rem;border-radius:.5rem}code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}table{border-collapse:collapse;max-width:100%;overflow:auto}th,td{border:1px solid #d1d5db;padding:.45rem .6rem;text-align:left}blockquote{border-left:4px solid #9ca3af;margin-left:0;padding-left:1rem;color:#4b5563}</style>\n"
            . "</head>\n<body>\n{$safe}\n</body>\n</html>\n";
    }

    private function sanitizeHtmlFragment(string $html): string
    {
        if ($html === '') {
            return '';
        }

        if (!class_exists(DOMDocument::class)) {
            return '<pre>' . $this->escape(strip_tags($html)) . '</pre>';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!doctype html><html><body><div id="arcade-root">' . $html . '</div></body></html>';
        $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('arcade-root');
        if (!$root instanceof DOMElement) {
            return '<pre>' . $this->escape(strip_tags($html)) . '</pre>';
        }

        $this->sanitizeChildren($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= (string)$dom->saveHTML($child);
        }
        return $out;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        $allowed = [
            'p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'strong', 'b', 'em', 'i', 'u', 's', 'del',
            'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
            'a', 'hr', 'span',
        ];
        $dropEntirely = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select', 'option', 'meta', 'link', 'base', 'img', 'video', 'audio', 'canvas'];

        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, $dropEntirely, true)) {
                    $parent->removeChild($node);
                    continue;
                }
                if (!in_array($tag, $allowed, true)) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    continue;
                }

                $this->sanitizeAttributes($node);
                $this->sanitizeChildren($node);
            }
        }
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);
        $allowedByTag = [
            'a' => ['href', 'title'],
            'td' => ['colspan', 'rowspan'],
            'th' => ['colspan', 'rowspan'],
            'ol' => ['start'],
        ];
        $allowed = $allowedByTag[$tag] ?? [];

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttributeNode($attribute);
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $href = trim($element->getAttribute('href'));
            if (!$this->safeHref($href)) {
                $element->removeAttribute('href');
            } else {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        foreach (['colspan', 'rowspan', 'start'] as $numeric) {
            if ($element->hasAttribute($numeric)) {
                $value = (int)$element->getAttribute($numeric);
                if ($value < 1 || $value > 100) {
                    $element->removeAttribute($numeric);
                } else {
                    $element->setAttribute($numeric, (string)$value);
                }
            }
        }
    }

    private function safeHref(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return true;
        }
        $scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
