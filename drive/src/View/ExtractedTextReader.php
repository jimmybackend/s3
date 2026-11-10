<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\View;

final class ExtractedTextReader
{
    /** Natural filename order, stable for equal names; never follows source image paths. */
    public function pages(mixed $data): array
    {
        if (!is_array($data) || !is_array($data['documentos'] ?? null)) return [];
        $pages = [];
        foreach ($data['documentos'] as $index => $document) {
            if (!is_array($document) || !is_string($document['texto'] ?? null)) continue;
            $pages[] = ['text' => $document['texto'], 'file' => (string)($document['archivo'] ?? ''), 'sheet' => (string)($document['hoja'] ?? ($index + 1)), 'index' => count($pages)];
        }
        usort($pages, static function (array $a, array $b): int {
            $order = strnatcasecmp($a['file'], $b['file']);
            return $order !== 0 ? $order : ($a['index'] <=> $b['index']);
        });
        return $pages;
    }
}
