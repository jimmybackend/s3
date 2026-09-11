# Provisionamiento de nodos FederationCloud

## Instalación nueva

Una instalación nueva puede crear su identidad desde el modal **Nodo** cuando la sesión pertenece a un `superadmin`.

Antes de generar llaves, el nodo consulta al seed configurado para comprobar que `node_name` no esté ocupado. El registro final vuelve a validar el descriptor firmado, por lo que la consulta previa no reemplaza la protección de unicidad de `FederationNodes`.

Si `/etc/arcadecloud-drive/federation-node.json` todavía no existe y PHP-FPM no puede crear archivos bajo `/etc/arcadecloud-drive`, la UI muestra la ruta, el usuario efectivo de PHP-FPM y el comando de preparación del helper privilegiado.

El helper se instala una sola vez con root. Después, la creación desde la UI genera:

- par Ed25519;
- `node_id` derivado de la clave pública;
- `payload_key` XChaCha20-Poly1305;
- `node_name` firmado.

Si el registro remoto no puede confirmarse después de crear las llaves, la identidad local se conserva. No se regeneran ni destruyen llaves por un fallo de red incierto.

## Instalación existente

Si el archivo ya existe, la UI entra en modo **Renombrar**. Cambiar `node_name` no cambia `node_id`, clave pública, clave privada ni `payload_key`.

Cuando PHP-FPM no puede escribir directamente la identidad, el helper privilegiado actúa únicamente sobre la ruta fijada por `/etc/arcadecloud-drive/admin-helper.json`.

## Colisiones

El seed expone `POST /federationcloud/name-availability.php` para preflight. Devuelve únicamente si el nombre normalizado está disponible.

Incluso con un resultado disponible, `register.php` mantiene la comprobación definitiva. Si dos instalaciones compiten por el mismo nombre, sólo una puede quedar ligada por la restricción única de `FederationNodes.NodeName`.
