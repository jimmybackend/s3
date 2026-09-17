<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Application/FolderDocumentService.php';

use ArcadeCloud\Drive\Application\FolderDocumentService;

$reflection = new ReflectionClass(FolderDocumentService::class);
$service = $reflection->newInstanceWithoutConstructor();

$sanitize = $reflection->getMethod('sanitizeHtmlFragment');
$sanitize->setAccessible(true);

$input = <<<'HTML'
<div onclick="alert(1)">
  <h2 style="color:red">Título</h2>
  <p>Texto <strong>importante</strong> <a href="javascript:alert(1)" onclick="x()">malo</a></p>
  <section><script>alert('x')</script><ul><li>Uno</li><li>Dos</li></ul></section>
  <iframe src="https://example.com"></iframe>
  <a href="https://example.com" style="position:fixed">válido</a>
</div>
HTML;

$result = (string)$sanitize->invoke($service, $input);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$assert(!str_contains(strtolower($result), '<script'), 'Debe eliminar script anidado.');
$assert(!str_contains(strtolower($result), '<iframe'), 'Debe eliminar iframe.');
$assert(!str_contains(strtolower($result), 'onclick'), 'Debe eliminar handlers inline.');
$assert(!str_contains(strtolower($result), 'style='), 'Debe eliminar estilos pegados.');
$assert(!str_contains(strtolower($result), 'javascript:'), 'Debe eliminar enlaces javascript:.');

if (class_exists(DOMDocument::class)) {
    $assert(str_contains($result, '<strong>importante</strong>'), 'Debe conservar formato semántico permitido.');
    $assert(str_contains($result, '<ul>') && str_contains($result, '<li>Uno</li>'), 'Debe conservar listas.');
    $assert(str_contains($result, 'href="https://example.com"'), 'Debe conservar enlaces https válidos.');
}

if ($failures !== []) {
    fwrite(STDERR, "FolderDocumentService sanitizer FAILED\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "FolderDocumentService sanitizer OK\n";
