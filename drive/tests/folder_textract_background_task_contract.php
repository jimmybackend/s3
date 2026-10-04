<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'controller' => file_get_contents($root . '/src/Http/Controller/AwsFileController.php'),
    'tasks' => file_get_contents($root . '/src/Http/Controller/BackgroundTaskController.php'),
    'launcher' => file_get_contents($root . '/src/Application/BackgroundWorkerLauncher.php'),
    'worker' => file_get_contents($root . '/src/Console/FolderTextractWorkerCommand.php'),
    'store' => file_get_contents($root . '/src/Aws/FolderTextractJobStore.php'),
    'service' => file_get_contents($root . '/src/Aws/FolderTextractService.php'),
    'folders_js' => file_get_contents($root . '/js/so-folders.js'),
    'tasks_js' => file_get_contents($root . '/js/background-tasks.js'),
];

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$check(str_contains($files['controller'], 'launchFolderTextract'), 'la acción HTTP lanza un worker desacoplado');
$check(str_contains($files['controller'], "'queued' => true"), 'la acción devuelve estado en cola');
$check(str_contains($files['tasks'], 'folderTextractTasks'), 'Tareas consulta extracciones de carpeta');
$check(str_contains($files['tasks'], "'kind' => 'folder-textract'"), 'Tareas publica el tipo folder-textract');
$check(str_contains($files['tasks'], "'processed_items' => \$processed"), 'Tareas publica hojas procesadas');
$check(str_contains($files['launcher'], "folder_textract_worker.php"), 'launcher ejecuta worker CLI');
$check(str_contains($files['worker'], "'status' => 'running'"), 'worker marca la tarea en proceso');
$check(str_contains($files['worker'], "'status' => 'completed'"), 'worker marca finalización');
$check(str_contains($files['worker'], 'textract.detect_document_text_page'), 'worker registra costo Textract');
$check(str_contains($files['service'], 'ORDER BY Fecha ASC, id_ ASC'), 'se conserva orden cronológico');
$check(str_contains($files['service'], '?callable $progress'), 'servicio reporta progreso hoja por hoja');
$check(str_contains($files['folders_js'], 'BackgroundTaskCenter.open = true'), 'al crearla se abre Tareas');
$check(str_contains($files['tasks_js'], 'Tiempo:'), 'Tareas muestra tiempo transcurrido');
$check(str_contains($files['tasks_js'], 'Hojas:'), 'Tareas muestra procesadas/total');

fwrite(STDOUT, "Folder Textract background task contract passed.\n");
