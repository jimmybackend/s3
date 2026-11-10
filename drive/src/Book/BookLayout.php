<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Book;

/** AI can label original lines; it can never supply replacement text or HTML. */
final class BookLayout
{
    public const VERSION = 'nova-micro-lines-v1';
    public static function lines(string $text): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }

    public static function validate(string $text, mixed $response): array
    {
        $lines = self::lines($text);
        $styles = [];
        foreach ($lines as $index => $line) {
            $upper = preg_match('/\p{Lu}/u', $line) && !preg_match('/\p{Ll}/u', $line);
            $styles[$index] = ['kind' => 'body', 'align' => 'justify', 'bold' => (bool)$upper];
        }
        if (!is_array($response) || !is_array($response['lines'] ?? null)) return $styles;
        foreach ($response['lines'] as $entry) {
            if (!is_array($entry) || !is_int($entry['id'] ?? null) || !isset($styles[$entry['id']])) continue;
            $id = $entry['id'];
            $kind = in_array($entry['kind'] ?? '', ['body','heading','subtitle','signature'], true) ? $entry['kind'] : 'body';
            $align = in_array($entry['align'] ?? '', ['justify','center','right'], true) ? $entry['align'] : 'justify';
            $styles[$id] = ['kind' => $kind, 'align' => $align, 'bold' => $styles[$id]['bold'] || ($entry['bold'] ?? false) === true];
        }
        return $styles;
    }
}
