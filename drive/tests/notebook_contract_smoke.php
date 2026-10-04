<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$page = (string)file_get_contents($root . '/notebook.php');
$api = (string)file_get_contents($root . '/notebook-api.php');
$service = (string)file_get_contents($root . '/src/Notebook/NotebookService.php');
$js = (string)file_get_contents($root . '/js/notebook.js');
$css = (string)file_get_contents($root . '/css/notebook.css');
$so = (string)file_get_contents($root . '/so.php');
$shell = (string)file_get_contents($root . '/js/desktop-shell.js');

$assert(str_contains($shell, "notebook: { target: 'notebookWindow'"), 'Notebook está registrado como aplicación principal');
$assert(str_contains($so, 'id="notebookWindow"') && str_contains($so, 'src="notebook.php"'), 'so.php contiene la ventana Notebook');
$assert(str_contains($service, "'Libretas'"), 'Notebook usa la carpeta visible Libretas');
$assert(str_contains($service, "base . '.png'") && str_contains($service, "base . '.json'") && str_contains($service, "base . '.txt'"), 'cada guardado produce PNG JSON y TXT');
$assert(strpos($service, "base . '.txt'") > strpos($service, "extractText"), 'TXT se persiste después de intentar la transcripción');
$assert(str_contains($service, 'TextractFileService'), 'la transcripción automática reutiliza Textract');
$assert(str_contains($js, "pointerdown") && str_contains($js, "pointermove") && str_contains($js, "pointerup"), 'cliente usa Pointer Events');
$assert(str_contains($page, 'id="nbTool"'), 'herramientas de escritura usan un selector desplegable');
foreach (['pencil','pen','fountain','marker','brush','eraser'] as $tool) {
    $assert(str_contains($page, 'value="' . $tool . '"'), "herramienta {$tool} disponible");
}
foreach (['blank','ruled','grid','millimeter','dots','notes'] as $background) {
    $assert(str_contains($page, 'value="' . $background . '"'), "papel {$background} disponible");
}
$assert(str_contains($js, "Segoe Print") && str_contains($page, 'nbWritePrompt'), 'prompt puede colocarse como escritura manual');
$assert(str_contains($service, 'movePage') && str_contains($page, 'nbMovePage'), 'hojas pueden moverse entre libretas');
$assert(str_contains($page, 'id="nbOnLine"') && str_contains($js, 'alignStrokeToRule'), 'modo Sobre línea alinea escritura manual');
$assert(str_contains($page, 'id="nbAlignment"') && str_contains($page, 'value="justify"'), 'Notebook ofrece izquierda derecha y justificada');
$assert(str_contains($js, "pieces.push(current+'-')"), 'texto largo usa guion al dividir palabra al final de línea');
$assert(str_contains($js, "alignment==='right'") && str_contains($js, "alignment==='justify'"), 'renderer aplica alineaciones de texto');
$assert(str_contains($js, 'onLine:state.onLine') && str_contains($js, 'alignment:state.alignment'), 'modo de línea y alineación persisten en JSON editable');
$assert(str_contains($page, 'class="nb-page-footer"') && str_contains($page, 'class="nb-page-counter"'), 'prompt y paginación viven sobre la hoja');
$assert(str_contains($page, 'id="nbMenuPage"') && str_contains($page, 'id="nbMenuTotal"'), 'paginación también es visible en el menú');
$assert(str_contains($js, 'previewPoints()') && str_contains($js, 'alignStrokeToRule'), 'Sobre línea ajusta también la previsualización del trazo en pantalla');
$assert(str_contains($css, '@media(max-width:720px)'), 'Notebook incluye diseño móvil/tableta');

echo "Notebook contract OK\n";
