<?php
/**
 * run_tests.php
 * 
 * Ejecuta comandos de pruebas (PHPUnit, Jest, etc.) de forma segura y aislada.
 * 1. Valida que el comando sea un comando de tests permitido (seguridad).
 * 2. Crea un directorio temporal y descarga los archivos del proyecto desde S3.
 * 3. Ejecuta el comando con un timeout estricto (evita bucles infinitos).
 * 4. Registra el resultado en la tabla ToolCalls para trazabilidad.
 * 5. Limpia el directorio temporal.
 */

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

@ini_set('max_execution_time', '120'); // Timeout máximo de la petición HTTP
@set_time_limit(120);

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== 1. Validar parámetros =====
$sessionId = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
$testCommand = isset($_POST['test_command']) ? trim($_POST['test_command']) : '';

if ($sessionId <= 0 || $projectId <= 0 || $testCommand === '') {
    jexit(['ok' => false, 'error' => 'Faltan parámetros: session_id, project_id, test_command'], 400);
}

// ===== 2. Cargar dependencias =====
try {
    $vendorPath = __DIR__ . '/vendor/autoload.php';
    if (file_exists($vendorPath)) {
        require_once $vendorPath;
    } elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    }

    $bootstrapPath = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrapPath)) {
        throw new RuntimeException('app_bootstrap.php no encontrado en ' . __DIR__);
    }
    require_once $bootstrapPath;

    $s3ManagerPath = __DIR__ . '/S3Manager.php';
    if (!is_file($s3ManagerPath)) {
        throw new RuntimeException('S3Manager.php no encontrado en ' . __DIR__);
    }
    require_once $s3ManagerPath;
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Error cargando dependencias: ' . $e->getMessage()], 500);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok' => false, 'error' => 'DB no disponible.'], 500);
}

// ===== 3. Validación de Seguridad del Comando (Allowlist) =====
// Solo permitimos comandos de testing conocidos para evitar inyección de comandos (RCE)
$allowedCommands = [
    'vendor/bin/phpunit',
    './vendor/bin/phpunit',
    'php artisan test',
    'phpunit',
    'npm test',
    'npx jest',
    'npx mocha',
    'yarn test'
];

$isAllowed = false;
foreach ($allowedCommands as $allowed) {
    if (strpos($testCommand, $allowed) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    jexit([
        'ok' => false, 
        'error' => 'Comando rechazado por seguridad. Solo se permiten comandos de testing conocidos (phpunit, jest, npm test, etc.).'
    ], 403);
}

// ===== 4. Preparar Espacio de Trabajo Temporal =====
$tempDir = sys_get_temp_dir() . '/ai_test_workspace_' . uniqid();
mkdir($tempDir, 0755, true);

try {
    $manager = new S3Manager();
    $s3 = Config::getS3();
    $bucket = $manager->getBucket();

    // Obtener todos los archivos del proyecto en la BD
    $stmt = $db_connection->prepare("SELECT s3_key, filename FROM ProjectSources WHERE project_id_ = ? AND status = 'indexed'");
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $filesDownloaded = 0;
    while ($row = $res->fetch_assoc()) {
        $s3Key = $row['s3_key'];
        $filename = $row['filename'];
        
        // Crear directorios anidados si el filename tiene rutas (ej: app/Models/User.php)
        $filePath = $tempDir . '/' . $filename;
        $fileDir = dirname($filePath);
        if (!is_dir($fileDir)) {
            mkdir($fileDir, 0755, true);
        }

        // Descargar desde S3
        try {
            $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $s3Key]);
            file_put_contents($filePath, (string) $result['Body']);
            $filesDownloaded++;
        } catch (Throwable $e) {
            // Si un archivo falla, continuamos con los demás (puede ser un archivo borrado en S3 pero no en BD)
            error_log("No se pudo descargar $s3Key: " . $e->getMessage());
        }
    }
    $stmt->close();

    if ($filesDownloaded === 0) {
        throw new RuntimeException('No se encontraron archivos indexados para este proyecto en S3.');
    }

    // ===== 5. Ejecutar el Comando de Tests =====
    $startTime = microtime(true);
    $descriptorspec = [
        0 => ["pipe", "r"],  // stdin
        1 => ["pipe", "w"],  // stdout
        2 => ["pipe", "w"]   // stderr
    ];

    // Ejecutar en el directorio temporal
    $process = proc_open($testCommand, $descriptorspec, $pipes, $tempDir);

    $output = '';
    $errorOutput = '';
    $status = 'ok';

    if (is_resource($process)) {
        // Leer stdout y stderr
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        // Timeout de ejecución de 60 segundos para el proceso
        $timeout = 60; 
        $elapsed = 0;
        
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $output .= stream_get_contents($pipes[1]);
            $errorOutput .= stream_get_contents($pipes[2]);
            usleep(100000); // 100ms
            $elapsed += 0.1;
            
            if ($elapsed >= $timeout) {
                proc_terminate($process, 9); // Kill -9
                $status = 'timeout';
                $output .= "\n\n[TIMEOUT] El proceso excedió los {$timeout} segundos y fue terminado.";
                break;
            }
        }
        
        $returnCode = proc_close($process);
        if ($returnCode !== 0 && $status !== 'timeout') {
            $status = 'error';
        }
    } else {
        $status = 'error';
        $errorOutput = 'No se pudo iniciar el proceso. Verifica que el comando exista en el sistema.';
    }

    $durationMs = round((microtime(true) - $startTime) * 1000);
    $fullOutput = trim($output . "\n" . $errorOutput);

    // ===== 6. Registrar en ToolCalls para Trazabilidad =====
    $toolCallId = 0;
    $rs = $db_connection->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM ToolCalls");
    if ($rs) {
        $toolCallId = (int) ($rs->fetch_assoc()['nxt'] ?? 1);
        $rs->free();
    }

    $paramsJson = json_encode(['command' => $testCommand, 'workspace' => $tempDir]);
    $stmtTool = $db_connection->prepare("
        INSERT INTO ToolCalls (id_, session_id_, message_id_, tool, params, result, status, duration_ms)
        VALUES (?, ?, NULL, 'run_shell', ?, ?, ?, ?)
    ");
    $stmtTool->bind_param("iissid", $toolCallId, $sessionId, $paramsJson, $fullOutput, $status, $durationMs);
    $stmtTool->execute();
    $stmtTool->close();

    // ===== 7. Limpieza =====
    // Eliminar el directorio temporal recursivamente
    deleteDirectory($tempDir);

    // ===== 8. Respuesta Exitosa =====
    jexit([
        'ok' => true,
        'message' => $status === 'ok' ? '✅ Tests ejecutados correctamente.' : '⚠️ Tests fallaron o hubo un timeout.',
        'status' => $status,
        'exit_code' => $returnCode ?? -1,
        'duration_ms' => $durationMs,
        'files_processed' => $filesDownloaded,
        'output' => $fullOutput
    ]);

} catch (Throwable $e) {
    // Limpieza en caso de error
    if (isset($tempDir) && is_dir($tempDir)) {
        deleteDirectory($tempDir);
    }
    jexit(['ok' => false, 'error' => 'Error crítico ejecutando tests: ' . $e->getMessage()], 500);
}

/**
 * Función auxiliar para eliminar directorios recursivamente
 */
function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}