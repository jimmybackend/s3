<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

/** Reuses authorized SO markup without embedding its desktop or executing its scripts. */
final class Drive3dActionContextView
{
    public static function payload(string $page, array $context): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $page, LIBXML_NONET);
            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query("//*[@id='fileContextMenu' or @id='folderContextMenu' or contains(concat(' ',normalize-space(@class),' '),' modal ') or contains(concat(' ',normalize-space(@class),' '),' os-explorer-window ')]");
            $html = '';
            foreach ($nodes as $node) {
                foreach (iterator_to_array($xpath->query('.//script', $node)) as $script) {
                    $script->parentNode?->removeChild($script);
                }
                $html .= $dom->saveHTML($node);
            }
            $modules = ['polly.js', 'aws-comprehend.js', 'media-processing.js',
                'background-task-feedback.js', 'polly-background.js', 'transcribe-background.js',
                'filesystem-operations.js', 'move-tasks.js', 'carpetas.js', 'folder-document.js',
                'so-new-text-file.js', 'sincronizar.js', 'so-folders.js', 'file-security.js',
                'arcadelink-share.js', 'so-share.js', 'os-window-manager.js', 'file-applications.js',
                'so.js', 'so-clipboard.js'];
            $scripts = [];
            foreach ($xpath->query('//script[@src]') as $node) {
                $src = $node->getAttribute('src');
                $path = (string)parse_url($src, PHP_URL_PATH);
                if (str_starts_with($src, 'js/') && in_array(basename($path), $modules, true)) {
                    $scripts[] = $src;
                }
            }
            return ['ok' => true, 'html' => $html, 'context' => $context, 'scripts' => $scripts];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
