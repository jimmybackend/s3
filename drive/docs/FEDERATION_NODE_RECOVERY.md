# FederationCloud: recuperación de identidad de nodo

La continuidad de un nodo depende de dos capas distintas:

1. Los archivos físicos permanecen en S3.
2. La identidad FederationCloud permanece en `federation-node.json`.

Un servidor reinstalado sólo conserva el mismo `node_id` si restaura la misma identidad criptográfica. Generar una identidad nueva crea un nodo distinto aunque use el mismo dominio o el mismo `node_name`.

## Nombre del nodo

`node_name` es público, firmado y legible. No es una credencial. El `node_id` derivado de Ed25519 sigue siendo la identidad real.

Para asignar por primera vez un nombre a una identidad existente, ejecutar el comando como el usuario propietario del archivo de identidad. El comando no permite cambiar silenciosamente un nombre ya establecido.

## Respaldo cifrado

No almacenar `federation-node.json` en texto plano en S3, Git, correo o una carpeta compartida.

`federation_identity_backup.php` cifra la identidad usando una frase de recuperación leída desde un archivo local. El resultado cifrado sí puede almacenarse en S3. La frase debe guardarse por separado.

## Restauración

En un servidor nuevo:

1. instalar ArcadeCloud Drive;
2. recuperar el respaldo cifrado del nodo;
3. restaurar la identidad antes de generar una nueva;
4. verificar que el `node_id` y `node_name` restaurados sean los esperados;
5. reconstruir/restaurar MySQL;
6. conservar `FileS3.Encriptado` al reconciliar los objetos S3;
7. iniciar FederationCloud y registrarse de nuevo en el seed.

Los ArcadeLink nuevos usan una referencia estable cifrada basada en `FileS3.Encriptado`, por lo que pueden recuperar el objeto aunque el `FileS3.id_` cambie. Los ArcadeLink históricos de payload versión 1 dependen del `file_id` original y no pueden modificarse retroactivamente porque están firmados.

## Protección frente a clonación

Copiar la identidad privada equivale a copiar la credencial criptográfica del nodo. Por eso el seed conserva el primer vínculo aceptado entre `NodeId`, `NodeName`, `PublicKey`, `PublicUrl` y `FederationUrl`. Un registro posterior no puede cambiar silenciosamente esa asociación. Un traslado real de dominio deberá hacerse mediante un procedimiento administrativo explícito de recuperación, no mediante auto-registro.
