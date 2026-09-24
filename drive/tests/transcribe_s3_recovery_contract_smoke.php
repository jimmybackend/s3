<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);

function transcribeRecoveryContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$service = (string)file_get_contents($repo . '/drive/src/Aws/TranscriptionFileService.php');
$controller = (string)file_get_contents($repo . '/drive/src/Http/Controller/TranscriptionController.php');
$reconciler = (string)file_get_contents($repo . '/drive/src/Activity/TranscriptionReconciler.php');
$tasks = (string)file_get_contents($repo . '/drive/src/Http/Controller/BackgroundTaskController.php');
$tasksJs = (string)file_get_contents($repo . '/drive/js/background-tasks.js');

transcribeRecoveryContract(str_contains($service, 'recoverCompletedFromS3'), 'servicio puede recuperar una transcripción ya presente en S3');
transcribeRecoveryContract(str_contains($service, 'headObject(['), 'recuperación consulta una clave concreta con HeadObject');
transcribeRecoveryContract(!str_contains($service, 'listObjects'), 'recuperación no lista S3');
transcribeRecoveryContract(str_contains($service, 'isFreshObject'), 'resultado S3 debe ser posterior a la tarea');
transcribeRecoveryContract(str_contains($service, 'GeneratedFileRepository'), 'resultado recuperado se registra en FileS3');
transcribeRecoveryContract(str_contains($controller, "'job_name' => \$jobName"), 'inicio persiste el nombre real del job AWS');
transcribeRecoveryContract(str_contains($controller, "'aws_output_key'"), 'inicio persiste la clave S3 esperada');
transcribeRecoveryContract(str_contains($controller, "'drive_output_key'"), 'inicio persiste la clave canónica del Drive');
transcribeRecoveryContract(str_contains($reconciler, 'getTranscriptionJob(['), 'reconciliador consulta directamente el job AWS persistido');
transcribeRecoveryContract(str_contains($reconciler, 'recoverOneFromS3'), 'reconciliador tiene fallback por objeto S3 esperado');
transcribeRecoveryContract(str_contains($reconciler, "'reconciled_by' => \$reconciledBy"), 'estado terminal conserva la fuente de reconciliación');
transcribeRecoveryContract(str_contains($tasks, "'output_route'"), 'centro de tareas expone carpeta de salida');
transcribeRecoveryContract(str_contains($tasksJs, 'refreshDriveIfRelevant'), 'ventana Drive visible se actualiza cuando aparece el resultado');

fwrite(STDOUT, "Transcribe S3 recovery contract: OK\n");
