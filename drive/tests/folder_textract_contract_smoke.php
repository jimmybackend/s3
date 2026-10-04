<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Aws/FolderTextractService.php');
$controller = file_get_contents($root . '/src/Http/Controller/AwsFileController.php');
$folders = file_get_contents($root . '/js/so-folders.js');
$endpoint = file_get_contents($root . '/procesar_textract_carpeta.php');

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$check(str_contains($service, 'ORDER BY Fecha ASC, id_ ASC'), 'las hojas se ordenan de la fecha/hora más antigua a la más reciente');
$check(str_contains($service, "\$row['Nombre']"), 'el JSON conserva FileS3.Nombre como nombre visible');
$check(str_contains($service, "\$row['Encriptado']"), 'Textract usa FileS3.Encriptado para la key física');
$check(str_contains($service, "'fecha_creacion'"), 'cada hoja conserva la fecha/hora de creación');
$check(str_contains($service, "'hoja' => \$sheet"), 'cada imagen recibe número de hoja');
$check(str_contains($service, "'texto' =>"), 'cada hoja guarda el texto extraído');
$check(str_contains($service, "'costo_transcripcion'"), 'el JSON incluye costo agregado de Textract');
$check(str_contains($service, "'textract.detect_document_text_page'"), 'el costo usa la unidad real de Textract');
$check(str_contains($service, "'extraccion-%s-%d-paginas-%s.json'"), 'el archivo generado sigue el nombre solicitado');
$check(str_contains($service, "\$parentRoute"), 'el resultado se guarda en la carpeta padre');
$check(str_contains($controller, 'textractFolder'), 'el controlador expone la acción de carpeta');
$check(str_contains($controller, "'s3.put_request' => 1"), 'la actividad registra el PUT del JSON generado');
$check(str_contains($folders, "data-folder-action=\"extract-text\""), 'el menú de carpetas expone Extraer texto');
$check(str_contains($folders, "procesar_textract_carpeta.php"), 'la UI llama al endpoint de extracción de carpeta');
$check(str_contains($endpoint, '->textractFolder();'), 'el endpoint delega al controlador OOP');

fwrite(STDOUT, "Folder Textract contract smoke passed.\n");
