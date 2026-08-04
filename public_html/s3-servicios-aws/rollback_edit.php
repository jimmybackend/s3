<?php
/**
 * rollback_edit.php
 * Revierte un archivo a su versión anterior en S3 y actualiza la BD.
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
$targetFilename = isset($_POST['target_filename']) ? trim($_POST['target_filename']) : '';
$rollbackToVersion = isset($_POST['rollback_to_version']) ? trim($_POST['rollback_to_version']) : ''; // Opcional, si es vacío, revierte a la anterior

if ($projectId <= 0 || $targetFilename === '') {
    jexit(['ok' => false, 'error' => 'Faltan parámetros: project_id, target_filename'], 400);
}

// ===== MODO: OBTENER HISTORIAL DE EDICIONES RECIENTES =====
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

if ($action === 'get_recent_edits') {
    try {
        // Obtener los últimos 10 archivos editados con su versión actual y conteo de ediciones
        $stmt = $db_connection->prepare("
            SELECT 
                fv.original_filename as filename,
                fv.version as current_version,
                COUNT(*) as edit_count
            FROM FileVersions fv
            WHERE fv.project_id_ = ?
            GROUP BY fv.original_filename
            ORDER BY MAX(fv.created_at) DESC
            LIMIT 10
        ");
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $recentFiles = [];
        while ($row = $res->fetch_assoc()) {
            $recentFiles[] = $row;
        }
        $stmt->close();

        jexit([
            'ok' => true,
            'recent_files' => $recentFiles
        ]);
    } catch (Throwable $e) {
        jexit(['ok' => false, 'error' => 'Error obteniendo historial: ' . $e->getMessage()], 500);
    }
}

try {
    require_once __DIR__ . '/app_bootstrap.php';
    require_once __DIR__ . '/S3Manager.php';
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Error cargando dependencias: ' . $e->getMessage()], 500);
}

if (!isset($db_connection)) jexit(['ok' => false, 'error' => 'DB no disponible.'], 500);

try {
    $manager = new S3Manager();
    $s3 = Config::getS3();
    $bucket = $manager->getBucket();

    // 1. Obtener la versión actual y la anterior
    $stmt = $db_connection->prepare("
        SELECT version, s3_path FROM FileVersions 
        WHERE project_id_ = ? AND original_filename = ? 
        ORDER BY id_ DESC LIMIT 2
    ");
    $stmt->bind_param('is', $projectId, $targetFilename);
    $stmt->execute();
    $res = $stmt->get_result();
    $versions = [];
    while ($row = $res->fetch_assoc()) { $versions[] = $row; }
    $stmt->close();

    if (count($versions) < 2) {
        jexit(['ok' => false, 'error' => 'No hay una versión anterior a la cual revertir.'], 400);
    }

    $currentVersion = $versions[0];
    // Si se especifica una versión, usarla, si no, usar la inmediatamente anterior (índice 1)
    $targetVersionData = null;
    if ($rollbackToVersion !== '') {
        foreach ($versions as $v) {
            if ($v['version'] === $rollbackToVersion) { $targetVersionData = $v; break; }
        }
    } else {
        $targetVersionData = $versions[1];
    }

    if (!$targetVersionData) {
        jexit(['ok' => false, 'error' => 'Versión de rollback no encontrada.'], 404);
    }

    // 2. Descargar el contenido de la versión objetivo desde S3
    $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $targetVersionData['s3_path']]);
    $rollbackContent = (string) $result['Body'];

    // 3. Obtener la ruta oficial actual del archivo en ProjectSources
    $stmtSrc = $db_connection->prepare("SELECT id_, s3_key FROM ProjectSources WHERE project_id_ = ? AND filename = ? LIMIT 1");
    $stmtSrc->bind_param('is', $projectId, $targetFilename);
    $stmtSrc->execute();
    $source = $stmtSrc->get_result()->fetch_assoc();
    $stmtSrc->close();

    if (!$source) {
        jexit(['ok' => false, 'error' => 'Archivo no registrado en ProjectSources.'], 404);
    }

    // 4. Subir el contenido de rollback a la ruta oficial (sobreescribiendo la actual)
    $ext = strtolower(pathinfo($targetFilename, PATHINFO_EXTENSION));
    $mimeMap = ['php' => 'text/x-php', 'js' => 'application/javascript', 'html' => 'text/html', 'css' => 'text/css', 'py' => 'text/x-python'];
    
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $source['s3_key'],
        'Body'        => $rollbackContent,
        'ContentType' => $mimeMap[$ext] ?? 'text/plain',
        'ACL'         => 'private'
    ]);

    // 5. Marcar la versión actual como 'stale' (obsoleta) en la BD
    $upd = $db_connection->prepare("UPDATE ProjectSources SET status = 'stale' WHERE id_ = ?");
    $upd->bind_param('i', $source['id_']);
    $upd->execute();
    $upd->close();

    jexit([
        'ok' => true,
        'message' => "✅ Revertido exitosamente a la versión {$targetVersionData['version']}.",
        'restored_version' => $targetVersionData['version'],
        'previous_version' => $currentVersion['version']
    ]);

} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Error en el rollback: ' . $e->getMessage()], 500);
}