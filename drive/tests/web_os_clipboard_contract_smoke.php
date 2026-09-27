<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$files = [
    'shell' => $root . '/js/so.js',
    'clipboard' => $root . '/js/so-clipboard.js',
    'clipboardCss' => $root . '/css/so-clipboard.css',
    'moveTasks' => $root . '/js/move-tasks.js',
    'fileService' => $root . '/src/Application/FileMutationService.php',
    'fileRepo' => $root . '/src/Storage/FileRecordRepository.php',
    'folderService' => $root . '/src/Application/FolderMutationService.php',
    'folderRepo' => $root . '/src/Storage/FolderMutationRepository.php',
    'moveService' => $root . '/src/Application/MoveJobService.php',
    'moveController' => $root . '/src/Http/Controller/MoveJobController.php',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Falta archivo de contrato: {$name} ({$path})\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
};

$assert(str_contains($files['clipboard'], 'longPressMs = 560'), 'pulsación larga abre acciones');
$assert(str_contains($files['shell'], 'fileSecondClickMs = 320'), 'segundo clic en 320 ms abre el archivo');
$assert(str_contains($files['shell'], 'toggleFileSelection(entry)'), 'clic simple alterna selección de archivo');
$assert(str_contains($files['shell'], 'selectedFileEntries()'), 'shell expone selección múltiple');
$assert(str_contains($files['shell'], "classList.toggle('is-selected'"), 'selección mantiene estado visual');
$assert(str_contains($files['clipboard'], 'captureFiles(entry, mode)'), 'portapapeles captura uno o varios archivos');
$assert(str_contains($files['clipboard'], 'keys.length + \' archivos\''), 'portapapeles etiqueta lotes múltiples');
$assert(str_contains($files['clipboard'], 'JSON.stringify(keys)'), 'pegar envía todos los archivos seleccionados');
$assert(str_contains($files['clipboard'], "data-os-clipboard-action"), 'menús incorporan acciones de portapapeles');
$assert(str_contains($files['clipboard'], "operation: item.mode"), 'pegar conserva copy o move');
$assert(str_contains($files['clipboard'], 'injectPasteToolbar()'), 'SO crea botón temporal para pegar en carpeta llena');
$assert(str_contains($files['clipboard'], 'visibleFiles >= 20'), 'botón superior sólo aparece cuando la página tiene 20 archivos');
$assert(str_contains($files['clipboard'], "'Mover aquí'"), 'botón superior distingue movimiento');
$assert(str_contains($files['clipboard'], "'Copiar aquí'"), 'botón superior distingue copia');
$assert(str_contains($files['clipboard'], "data.osPasteCurrent"), 'botón superior se identifica como destino actual');
$assert(str_contains($files['clipboardCss'], '.os-toolbar-paste'), 'botón superior tiene estilo propio');
$assert(str_contains($files['moveTasks'], 'so-clipboard.js?v=20260927-2'), 'cambio de portapapeles invalida caché del navegador');
$assert(str_contains($files['clipboard'], "generar_token.php"), 'menú del SO puede compartir archivos');
$assert(str_contains($files['clipboard'], "eliminar_archivo.php"), 'menú del SO puede eliminar archivos');
$assert(str_contains($files['clipboard'], "osTransferHud"), 'SO muestra medidor de transferencia');
$assert(str_contains($files['moveTasks'], "drive:move-task-progress"), 'polling publica progreso al SO');
$assert(str_contains($files['fileService'], 'public function copy('), 'servicio de archivos soporta copia');
$assert(str_contains($files['fileRepo'], 'duplicateFrom'), 'copia crea registro nuevo en FileS3');
$assert(str_contains($files['folderService'], 'public function copy('), 'servicio de carpetas soporta copia');
$assert(str_contains($files['folderRepo'], 'copyTree'), 'copia duplica catálogo de carpetas y archivos');
$assert(str_contains($files['moveService'], "['move', 'copy']"), 'job en segundo plano acepta copy y move');
$assert(str_contains($files['moveController'], "'progress' => \$progress"), 'estado del job expone porcentaje');

fwrite(STDOUT, "Contrato Web OS clipboard correcto.\n");
