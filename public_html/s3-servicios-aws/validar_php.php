<?php
header('Content-Type: application/json; charset=utf-8');

function responder(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder([
        'estado' => 'error',
        'mensaje' => 'Método no permitido.'
    ]);
}

$archivo = isset($_POST['archivo']) ? trim((string)$_POST['archivo']) : '';
$contenido = isset($_POST['contenido']) ? (string)$_POST['contenido'] : '';

if ($archivo === '') {
    responder([
        'estado' => 'error',
        'mensaje' => 'Falta el archivo.'
    ]);
}

$nombreBase = strtolower((string)basename($archivo));
$extension = strtolower((string)pathinfo($nombreBase, PATHINFO_EXTENSION));

$permitidos = ['php', 'phtml', 'inc'];
if (!in_array($extension, $permitidos, true)) {
    responder([
        'estado' => 'error',
        'mensaje' => 'Solo se permite validar archivos PHP.'
    ]);
}

if (!function_exists('exec')) {
    responder([
        'estado' => 'error',
        'mensaje' => 'La función exec() no está disponible en este servidor.'
    ]);
}

$deshabilitadas = (string)ini_get('disable_functions');
if ($deshabilitadas !== '') {
    $listaDeshabilitadas = array_map('trim', explode(',', $deshabilitadas));
    if (in_array('exec', $listaDeshabilitadas, true)) {
        responder([
            'estado' => 'error',
            'mensaje' => 'La función exec() está deshabilitada en php.ini.'
        ]);
    }
}

$directorioTemporal = sys_get_temp_dir();
if (!is_dir($directorioTemporal) || !is_writable($directorioTemporal)) {
    responder([
        'estado' => 'error',
        'mensaje' => 'No hay acceso de escritura al directorio temporal.'
    ]);
}

$rutaTemporal = tempnam($directorioTemporal, 'lint_php_');
if ($rutaTemporal === false) {
    responder([
        'estado' => 'error',
        'mensaje' => 'No se pudo crear el archivo temporal.'
    ]);
}

$rutaPhpTemporal = $rutaTemporal . '.php';
@rename($rutaTemporal, $rutaPhpTemporal);
$rutaTemporal = $rutaPhpTemporal;

if (@file_put_contents($rutaTemporal, $contenido) === false) {
    @unlink($rutaTemporal);
    responder([
        'estado' => 'error',
        'mensaje' => 'No se pudo escribir el archivo temporal.'
    ]);
}

$binarioPhp = 'php';
$salida = [];
$codigo = 0;
$comando = escapeshellcmd($binarioPhp) . ' -l ' . escapeshellarg($rutaTemporal) . ' 2>&1';

exec($comando, $salida, $codigo);
@unlink($rutaTemporal);

$textoSalida = trim(implode("\n", $salida));

if ($codigo === 0) {
    responder([
        'estado' => 'ok',
        'valido' => true,
        'mensaje' => $textoSalida !== '' ? $textoSalida : 'No syntax errors detected',
        'errores' => []
    ]);
}

$errores = [];

// Parseo típico: "Parse error: syntax error, unexpected ... in /tmp/xxx.php on line 12"
if (preg_match('/in\s+.+?\s+on\s+line\s+(\d+)/i', $textoSalida, $coincidencias)) {
    $linea = (int)$coincidencias[1];
    $errores[] = [
        'linea' => $linea > 0 ? $linea : 1,
        'columna' => 1,
        'finColumna' => 120,
        'mensaje' => $textoSalida,
    ];
} else {
    $errores[] = [
        'linea' => 1,
        'columna' => 1,
        'finColumna' => 120,
        'mensaje' => $textoSalida !== '' ? $textoSalida : 'Error de sintaxis PHP no identificado.'
    ];
}

responder([
    'estado' => 'ok',
    'valido' => false,
    'mensaje' => 'Se detectaron errores de sintaxis PHP.',
    'errores' => $errores
]);
