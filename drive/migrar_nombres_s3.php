<?php
require_once __DIR__ . '/app_bootstrap.php';

use Aws\S3\S3Client;

$s3 = Config::getS3();
$bucket = Config::BUCKET;
$rutaRaiz = Config::RUTA_RAIZ ?? 'Data/';

try {
    $result = $s3->listObjectsV2([
        'Bucket' => $bucket,
        'Prefix' => $rutaRaiz
    ]);

    if (!isset($result['Contents'])) {
        die("No se encontraron archivos.");
    }

    foreach ($result['Contents'] as $objeto) {
        $keyOriginal = $objeto['Key'];

        if (substr($keyOriginal, -1) === '/') continue; // omitir "carpetas"

        $nombreOriginal = basename($keyOriginal);
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $nuevoNombre = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $nuevoKey = dirname($keyOriginal) . '/' . $nuevoNombre;

        // Copiar con nuevo nombre
        $s3->copyObject([
            'Bucket'     => $bucket,
            'CopySource' => "{$bucket}/{$keyOriginal}",
            'Key'        => $nuevoKey,
            'ACL'        => 'private'
        ]);

        // Eliminar archivo con nombre antiguo
        $s3->deleteObject([
            'Bucket' => $bucket,
            'Key'    => $keyOriginal
        ]);

        // Guardar en base de datos
        $stmt = $db_connection->prepare("INSERT INTO FileS3 (Nombre, Encriptado, user_id_) VALUES (?, ?, ?)");
        $user_id = 1; // ⚠️ Ajusta esto si tienes forma de identificar al usuario original
        $stmt->bind_param("ssi", $nombreOriginal, $nuevoNombre, $user_id);
        $stmt->execute();

        echo "Archivo '{$nombreOriginal}' renombrado como '{$nuevoNombre}' y guardado en la BD.<br>";
    }

    echo "<strong>Proceso finalizado con éxito.</strong>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
