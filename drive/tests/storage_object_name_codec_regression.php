<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Storage/StorageObjectNameCodec.php';

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;

function checkStorageName(bool $condition, string $description): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
    echo "OK: {$description}\n";
}

$codec = new StorageObjectNameCodec();
$seen = [];
for ($i = 0; $i < 200; $i++) {
    $file = $codec->createFileObjectName('Sentencia.pdf');
    $folder = $codec->createFolderObjectName('Expediente');
    checkStorageName((bool) preg_match('/^f_[a-f0-9]{16}-Sentencia\.pdf$/D', $file), 'Archivo nuevo usa 16 dígitos hexadecimales');
    checkStorageName((bool) preg_match('/^d_[a-f0-9]{16}-Expediente$/D', $folder), 'Carpeta nueva usa 16 dígitos hexadecimales');
    checkStorageName(!isset($seen[$file]) && !isset($seen[$folder]), 'No se repite el identificador aleatorio en la muestra');
    $seen[$file] = $seen[$folder] = true;
}
checkStorageName($codec->recoverFileVisibleName($file) === 'Sentencia.pdf', 'Recupera nombre nuevo de archivo');
checkStorageName($codec->recoverFolderVisibleName($folder) === 'Expediente', 'Recupera nombre nuevo de carpeta');

$legacy = str_repeat('a', 32);
checkStorageName($codec->recoverFileVisibleName('f_' . $legacy . '-Histórico.pdf') === 'Histórico.pdf', 'Recupera archivo anterior con 32 hex');
checkStorageName($codec->recoverFolderVisibleName('d_' . $legacy . '-Legal') === 'Legal', 'Recupera carpeta anterior con 32 hex');
checkStorageName($codec->recoverFileVisibleName(str_repeat('b', 40) . '-Acta.pdf') === 'Acta.pdf', 'Conserva compatibilidad multipart SHA-1 anterior');
checkStorageName($codec->recoverFileVisibleName('f_abcd-erróneo.pdf') === null, 'No decodifica prefijos incompletos');

function expectKeyRejected(string $key): void
{
    try {
        StorageObjectNameCodec::assertS3KeyLength($key);
    } catch (RuntimeException $e) {
        checkStorageName(str_contains($e->getMessage(), '1024'), 'Rechaza clave S3 inválida con explicación del límite');
        return;
    }
    checkStorageName(false, 'Debió rechazar una clave S3 inválida');
}

StorageObjectNameCodec::assertS3KeyLength(str_repeat('x', 1024));
checkStorageName(true, 'Acepta exactamente 1024 bytes');
expectKeyRejected(str_repeat('x', 1025));
expectKeyRejected('');
StorageObjectNameCodec::assertS3KeyLength(str_repeat('ñ', 512));
checkStorageName(true, '1024 bytes UTF-8 son válidos aunque sean 512 caracteres');
expectKeyRejected(str_repeat('ñ', 513));

// El límite es de la clave completa, no solo del último nombre del archivo.
$prefix = 'Data/' . str_repeat('carpeta/', 120);
$key = $prefix . $codec->createFileObjectName('Sentencia.pdf');
expectKeyRejected($key);

// Regresión contractual: las operaciones validan el destino antes de S3.
$folderService = (string) file_get_contents(dirname(__DIR__) . '/src/Application/FolderMutationService.php');
$fileService = (string) file_get_contents(dirname(__DIR__) . '/src/Application/FileMutationService.php');
$copyService = (string) file_get_contents(dirname(__DIR__) . '/src/Storage/S3ObjectCopyService.php');
checkStorageName(
    str_contains($folderService, 'preflightTreeDestination($origin, $final)')
        && str_contains($folderService, 'StorageObjectNameCodec::assertS3KeyLength($prefix)')
        && str_contains($folderService, 'StorageObjectNameCodec::assertS3KeyLength($newKey)'),
    'Creación/copia/movimiento de carpetas validan claves de toda la jerarquía'
);
checkStorageName(
    substr_count($fileService, 'StorageObjectNameCodec::assertS3KeyLength($newKey)') >= 2
        && str_contains($copyService, 'StorageObjectNameCodec::assertS3KeyLength($destinationKey)'),
    'Copia/movimiento de archivos protegen el destino'
);

echo "STORAGE_KEY_REGRESSIONS_OK\n";
