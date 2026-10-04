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
foreach (['pencil','pen','fountain','marker','brush','eraser','line-eraser'] as $tool) {
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
$assert(str_contains($page, 'id="nbFont"') && str_contains($page, 'value="caveat" selected') && str_contains($page, 'value="dancing"') && str_contains($page, 'value="shadows"') && str_contains($page, 'value="sans"') && str_contains($page, 'value="serif"') && str_contains($page, 'value="mono"'), 'Notebook ofrece Caveat por defecto y varias familias tipográficas');
$assert(str_contains($js, 'fontStacks') && str_contains($js, "caveat:\"'Caveat'") && str_contains($js, 'font:state.font'), 'tipo de letra se renderiza y persiste por objeto');
$assert(str_contains($js, 'eraseWholeLine') && str_contains($js, 'objectTouchesLine'), 'borrador de línea elimina trazos y texto del renglón completo');
$assert(str_contains($js, 'Math.max(-26,Math.min(2,p.y-rawStart))'), 'modo Sobre línea restringe el trazo al renglón durante la escritura');
$assert(str_contains($api, "'recognize_line'") && str_contains($service, 'recognizeLine'), 'Notebook expone reconocimiento temporal de línea');
$assert(str_contains($js, 'scheduleLineRecognition') && str_contains($js, 'recognizeLine(baseline)'), 'cliente espera el fin de escritura antes de reconocer');
$assert(str_contains($js, 'recognizedFromInk:true') && str_contains($js, 'state.objects=state.objects.filter(o=>!ids.has(o.id))'), 'texto reconocido sustituye los trazos originales');
$assert(str_contains($service, "'notebook-tmp/f_'") && str_contains($service, 'deleteObject'), 'imagen temporal de reconocimiento se elimina de S3');
$assert(str_contains($page, 'id="nbFontSize"') && str_contains($js, 'fontSize'), 'Notebook permite cambiar tamaño de letra y persistirlo');
$assert(str_contains($page, 'id="nbZoom"') && str_contains($page, 'value="25"') && str_contains($page, 'value="50" selected') && str_contains($js, 'applyZoom'), 'Notebook permite zoom desde 25 por ciento y usa 50 por ciento por defecto');
$assert(str_contains($page, 'value="select"') && str_contains($js, 'hitObject') && str_contains($page, 'id="nbEditDialog"'), 'textos guardados pueden seleccionarse y editarse');
$assert(str_contains($js, "document.addEventListener('paste'") && str_contains($js, 'navigator.clipboard?.read') && str_contains($page, 'id="nbPasteImage"'), 'Notebook pega imágenes por evento o lectura del portapapeles');
$assert(str_contains($js, "action==='resize'") && str_contains($js, "action==='rotate'") && str_contains($js, "action==='move'"), 'imágenes pueden moverse redimensionarse y rotarse');
$assert(str_contains($js, 'normalizeObjects') && str_contains($js, 'version:6'), 'páginas antiguas se normalizan y nuevos objetos persisten en JSON');
$assert(str_contains($js, 'loadSelectedIntoPrompt') && str_contains($js, "current?.kind==='text'"), 'tocar texto carga el input y Enviar actualiza el mismo objeto');
$assert(str_contains($js, 'textCursor') && str_contains($js, "status('Punto de texto seleccionado')"), 'tocar un lugar libre fija el punto de inserción para varios textos en la misma línea');
$assert(str_contains($js, "action==='text-resize'") && str_contains($js, 'o.size=Math.max(12'), 'texto seleccionado puede redimensionarse directamente');
$assert(str_contains($page, 'id="nbPasteMode"') && str_contains($page, 'value="outline"') && str_contains($page, 'value="gray"') && str_contains($page, 'value="pencil"'), 'Notebook ofrece pegado normal contorno grises y lápiz');
$assert(str_contains($js, "mode==='outline'") && str_contains($js, "mode==='gray'") && str_contains($js, "mode==='pencil'"), 'procesamiento de imagen implementa los modos solicitados');
$assert(str_contains($js, 'setTimeout(()=>readClipboardImage(point),700)'), 'toque prolongado sobre la hoja intenta pegar la imagen del portapapeles');
$assert(str_contains($css, '@media(max-width:720px)'), 'Notebook incluye diseño móvil/tableta');

echo "Notebook contract OK\n";
