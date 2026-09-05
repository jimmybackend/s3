# Rotación de key física de archivos

`encriptar_archivo.php` conserva su URL y el contrato JSON usado por `drive/js/archivos.js`, pero ya es un entrypoint delgado.

```text
encriptar_archivo.php
    -> FileKeyRotationController
        -> FileKeyRotationService
            -> FileRecordLocator
            -> StorageObjectNameCodec
            -> MySQL / S3
```

## Contrato conservado

El frontend continúa enviando:

```json
{"key":"Data/..."}
```

La respuesta correcta conserva `estado`, `newKey`, `encriptado` y `nombre`.

## Reglas

1. sólo acepta `POST`;
2. exige sesión autenticada y `user_id` válido;
3. `FileRecordLocator::requireReadableByKey()` comprueba que el archivo pertenece al usuario antes de tocar S3;
4. la nueva key se genera mediante `StorageObjectNameCodec` usando el nombre visible almacenado en MySQL;
5. primero se copia el objeto a la nueva key;
6. después se actualiza `FileS3` limitado por `id_ + user_id_`;
7. si falla la actualización MySQL se intenta retirar la nueva copia para no dejar una rotación parcial;
8. sólo después de registrar correctamente la nueva key se elimina la key anterior;
9. `encriptar_archivo.php` no lee superglobals, no construye servicios y no llama directamente a MySQL o S3.

La validación automática vive en `.github/workflows/oop-key-rotation.yml` y comprueba sintaxis, frontera OOP, ownership, referencia real desde `js/archivos.js` y `git diff --check`.
