# FederationCloud: permisos del archivo de identidad

La identidad criptográfica de un nodo vive normalmente en:

```text
/etc/arcadecloud-drive/federation-node.json
```

Ese archivo contiene material secreto del nodo y nunca debe quedar world-writable ni almacenarse en GitHub.

## Escritura administrativa desde el Drive

El editor de `node_name` para `superadmin` modifica solamente la etiqueta firmada. No cambia `node_id`, clave pública, clave privada ni `payload_key`.

Cuando el archivo de identidad ya existe, `NodeIdentityService` lo actualiza **in-place** usando bloqueo exclusivo (`flock`), `ftruncate`, escritura completa, `fflush` y verificación posterior. De esta forma no necesita crear archivos temporales dentro de `/etc/arcadecloud-drive/` y conserva propietario, grupo y ACL del archivo existente.

La creación inicial de una identidad nueva sí mantiene el flujo de archivo temporal + `rename()` para instalación atómica.

## Privilegios

El proceso PHP-FPM **no debe recibir sudo general**. La configuración recomendada es:

1. `root` conserva la propiedad administrativa del directorio.
2. El usuario real del pool PHP-FPM recibe únicamente permiso de lectura/escritura sobre `federation-node.json`, preferiblemente mediante ACL o un grupo dedicado.
3. El directorio `/etc/arcadecloud-drive/` no necesita ser escribible por PHP para renombrar un nodo existente.
4. La clave privada nunca debe exponerse al navegador, logs o respuestas JSON.

Ejemplo con ACL, sustituyendo `PHP_FPM_USER` por el usuario real del pool:

```bash
sudo chown root:root /etc/arcadecloud-drive/federation-node.json
sudo chmod 600 /etc/arcadecloud-drive/federation-node.json
sudo setfacl -m u:PHP_FPM_USER:rw /etc/arcadecloud-drive/federation-node.json
```

Comprobación:

```bash
getfacl /etc/arcadecloud-drive/federation-node.json
```

No se debe usar `chmod 666`, `chmod 777`, ni una regla sudoers que permita al servidor web ejecutar comandos arbitrarios como root.
