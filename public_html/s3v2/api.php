<?php
session_start();
require 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\S3\S3Client;

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(['estado' => 'error', 'mensaje' => 'Sesión no válida']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'generar_token':
        require 'generar_token.php';
        break;
    case 'renombrar_archivo':
        require 'renombrar_archivo.php';
        break;
    case 'eliminar_archivo':
        require 'delete.php';
        break;
    case 'eliminar_multiple':
        require 'delete_multiple.php';
        break;
    case 'guardar_texto':
        require 'guardar_texto.php';
        break;
    case 'token_audio':
        require 'token_audio.php';
        break;
    case 'token_video':
        require 'token_video.php';
        break;
    case 'token_texto':
        require 'token_texto.php';
        break;
    case 'mover_archivos':
        require 'mover.php';
        break;
    case 'mover_carpeta':
        require 'mover_carpeta.php';
        break;
    case 'buscar_archivo':
        require 'buscar_archivo.php';
        break;
    case 'generar_galeria':
        require 'generar_galeria.php';
        break;
    case 'ver_pdf':
        require 'ver_pdf.php';
        break;
    default:
        echo json_encode(['estado' => 'error', 'mensaje' => 'Acción no reconocida']);
        break;
}
