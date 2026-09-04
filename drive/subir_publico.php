<?php
require_once __DIR__ . '/app_bootstrap.php';
 
$bucket = Config::BUCKET;
$basePrefix = Config::RUTA_COMPARTIDA;
$ruta = $_GET['ruta'] ?? '';
$ruta = trim($ruta, '/.');
$ruta = $ruta === '' ? '' : $ruta;
$prefix = rtrim($basePrefix . $ruta, '/') . '/';

$s3 = Config::getS3();

// Crear carpeta si se envía POST
if (isset($_POST['nueva']) && $_POST['nueva']) {
    $nuevaCarpeta = $prefix . trim($_POST['nueva']) . '/';
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $nuevaCarpeta,
        'Body'        => '',
        'ContentType' => 'application/x-directory',
        'ACL'         => 'private'
    ]);
    header("Location: ?ruta=" . urlencode($ruta));
    exit;
}

$carpetas = [];
$archivos = [];
$error = '';

try {
    $result = $s3->listObjectsV2([
        'Bucket'    => $bucket,
        'Prefix'    => $prefix,
        'Delimiter' => '/'
    ]);

    foreach ($result['CommonPrefixes'] ?? [] as $sub) {
        $carpetas[] = basename(rtrim($sub['Prefix'], '/'));
    }

    foreach ($result['Contents'] ?? [] as $obj) {
        if (substr($obj['Key'], -1) !== '/') {
            $archivos[] = $obj;
        }
    }
} catch (Exception $e) {
    $error = "Error al listar: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Subida y Navegación Pública</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.css">
    <link rel="icon" href="../assets/img/icono.png" type="image/x-icon">
    <style>
        #zona-subida {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #fff;
            padding-top: 20px;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div id="zona-subida">
        <h5>⬆ Subir archivos</h5>
        <form action="upload_publico.php?prefix=<?= urlencode($prefix) ?>" class="dropzone mb-4" id="dropzonePublico"></form>
    </div>

    <h4 class="mb-3">Carpeta actual: <?= htmlspecialchars('Compartidos' . ($ruta ? '/' . $ruta : '')) ?></h4>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($ruta): ?>
        <a href="?ruta=<?= urlencode(dirname($ruta) === '.' ? '' : dirname($ruta)) ?>" class="btn btn-secondary mb-3">⬅ Volver</a>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Crear nueva carpeta</div>
        <div class="card-body">
            <form method="post" class="form-inline">
                <input type="hidden" name="ruta" value="<?= htmlspecialchars($ruta) ?>">
                <input type="text" name="nueva" class="form-control mr-2" placeholder="Nombre carpeta" required>
                <button class="btn btn-primary">Crear</button>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <h5>📁 Subcarpetas</h5>
            <ul class="list-group mb-4">
                <?php foreach ($carpetas as $sub): ?>
                    <li class="list-group-item">
                        <a href="?ruta=<?= urlencode(trim($ruta . '/' . $sub, '/')) ?>">📁 <?= htmlspecialchars($sub) ?></a>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($carpetas)): ?>
                    <li class="list-group-item text-muted">Sin subcarpetas</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="col-md-6">
            <h5>📄 Archivos</h5>
            <ul class="list-group mb-4">
                <?php foreach ($archivos as $obj): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <?php
                           

                            $nombreEncriptado = basename($obj['Key']);
                            $nombreMostrado = $nombreEncriptado;
                            
                            if (isset($db_connection)) {
                                $stmt = $db_connection->prepare("SELECT Nombre FROM FileS3 WHERE Encriptado = ? LIMIT 1");
                                if ($stmt) {
                                    $stmt->bind_param("s", $nombreEncriptado);
                                    $stmt->execute();
                                    $res = $stmt->get_result();
                                    if ($fila = $res->fetch_assoc()) {
                                        $nombreMostrado = $fila['Nombre'];
                                    }
                                    $stmt->close();
                                }
                            }
                            ?>
                            <strong><?= htmlspecialchars($nombreMostrado) ?></strong><br>
                            <small class="text-muted">
                                <?= date('Y-m-d H:i:s', strtotime($obj['LastModified'])) ?> |
                                <?= round($obj['Size'] / 1024 / 1024, 2) ?> MB
                            </small>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($archivos)): ?>
                    <li class="list-group-item text-muted">Sin archivos</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>

</body>
</html>
