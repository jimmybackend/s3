<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/UploadTaskStore.php';

use ArcadeCloud\Drive\Upload\UploadTaskStore;

function taskCenterUploadCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$tmp = sys_get_temp_dir() . '/arcadecloud-upload-task-test-' . bin2hex(random_bytes(6));
$store = new UploadTaskStore($tmp);

$store->put(11, 'upload:test-a', [
    'status' => 'running',
    'title' => 'prueba.bin',
    'progress' => 37,
    'bytes_total' => 1000,
    'bytes_uploaded' => 370,
]);
$store->put(12, 'upload:test-b', [
    'status' => 'failed',
    'title' => 'otro.bin',
    'detail' => 'fixture',
]);

$user11 = $store->recentForUser(11);
taskCenterUploadCheck(count($user11) === 1, 'store separa tareas por usuario');
taskCenterUploadCheck(($user11[0]['id'] ?? '') === 'upload:test-a', 'store conserva id estable para deduplicar navegador/servidor');
taskCenterUploadCheck((int)($user11[0]['progress'] ?? -1) === 37, 'store conserva progreso persistente');

$activeRejected = false;
try {
    $store->deleteForUser(11, 'upload:test-a');
} catch (RuntimeException) {
    $activeRejected = true;
}
taskCenterUploadCheck($activeRejected, 'una subida activa no puede borrarse del centro');

$store->put(11, 'upload:test-a', ['status' => 'completed', 'progress' => 100]);
$store->deleteForUser(11, 'upload:test-a');
taskCenterUploadCheck($store->recentForUser(11) === [], 'una subida terminal sí puede limpiarse');

foreach (glob($tmp . '/*') ?: [] as $file) @unlink($file);
@rmdir($tmp);

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/src/Http/Controller/BackgroundTaskController.php');
$uploadController = (string)file_get_contents($root . '/src/Http/Controller/UploadController.php');
$adminUpload = (string)file_get_contents($root . '/src/Upload/AdminMultipartUploadService.php');
$uploadJs = (string)file_get_contents($root . '/js/upload-center.js');
$tasksJs = (string)file_get_contents($root . '/js/background-tasks.js');
$publicUp = (string)file_get_contents($root . '/up.php');

taskCenterUploadCheck(str_contains($controller, '$this->uploadTasks($userId)'), 'Centro de Tareas agrega la fuente persistente de subidas');
taskCenterUploadCheck(str_contains($controller, "'upload-task:'"), 'Centro de Tareas controla limpieza de subidas persistentes');
taskCenterUploadCheck(str_contains($uploadController, "\$mode === 'task'") && str_contains($uploadController, 'handleTaskSignal'), 'API autenticada acepta heartbeats de progreso');
taskCenterUploadCheck(str_contains($uploadJs, 'syncServerTask(task)') && str_contains($uploadJs, 'task_id: task.id'), 'subidas del navegador sincronizan su tarea al servidor');
taskCenterUploadCheck(str_contains($tasksJs, 'const merged = new Map()') && str_contains($tasksJs, 'control_id: server.control_id'), 'cliente deduplica snapshot local y persistente sin perder controles');
taskCenterUploadCheck(str_contains($adminUpload, "'progress'") && str_contains($adminUpload, 'taskStore->put'), 'UP.php persiste progreso para el usuario destino');
taskCenterUploadCheck(str_contains($publicUp, "postForm('progress'") && str_contains($publicUp, 'state.taskId'), 'subida pública publica avance y conserva task_id');

fwrite(STDOUT, "Upload task center contract OK\n");
