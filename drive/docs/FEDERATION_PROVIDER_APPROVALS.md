# FederationCloud — autorización de copias/proveedores con backend compartido

## Objetivo

`Solicitudes` no es el alta general de nodos FederationCloud. Un nodo independiente con su propia información se registra automáticamente mediante su descriptor firmado y no obtiene por ello acceso a recursos ajenos.

Este flujo existe únicamente para una relación privilegiada: una **copia, mirror o provider que pretende servir recursos del nodo origen**, por ejemplo porque el operador la ha preparado para trabajar con el mismo backend S3/MySQL o con una réplica controlada de ese backend.

La infraestructura compartida no se demuestra enviando hosts, buckets o secretos por la federación. La petición declara `relationship=shared_backend`; el superadmin verifica administrativamente que la máquina es realmente una copia autorizada antes de aprobarla.

## Flujo de primera autorización

1. La copia debe tener una identidad FederationCloud **distinta** y un `node.php` HTTPS accesible. Nunca se clona la clave Ed25519 del nodo origen.
2. Desde la copia se ejecuta `drive/bin/federation_provider_request.php` apuntando al Federation URL del origen, o se configura su origen para que el anuncio automático haga la primera solicitud.
3. `provider-request.php` acepta únicamente `relationship=shared_backend` y entrega la documentación a la **Aduana FederationCloud**.
4. El endpoint responde sin ejecutar trabajo pesado. El worker único toma la petición y verifica criptográficamente descriptor + `node.php` HTTPS.
5. Sólo después de esa verificación la relación se guarda como `pending` en `FederationNodeAuthorizations`.
6. En el Drive del origen, únicamente una sesión cuya fila `Users.system_role` sea `superadmin` ve `Solicitudes: N` en el footer.
7. El superusuario abre el modal y elige `Aprobar` o `Rechazar`.
8. Al aprobar, el origen firma con Ed25519 el vínculo exacto origen→proveedor, rol y alcance.
9. La relación pasa a `active` y puede participar como provider/mirror.
10. Una autorización activa puede revocarse desde el mismo modal, también sólo por `superadmin`.

Los nodos que sólo anuncian su propia IP/dominio y mantienen su propia información **no pasan por este flujo**. Usan `register.php`, Aduana los verifica automáticamente y su presencia se replica mediante `node.upsert` + gossip.

`Users.role` (por ejemplo `Administración` o `Soporte`) describe el área funcional del usuario y no concede autoridad para administrar FederationCloud. La autorización sensible usa exclusivamente `Users.system_role`, cuyos valores del esquema son `user`, `admin` y `superadmin`.

## Autorización no es lo mismo que disponibilidad

Una relación `active` es una autorización permanente hasta que un superadmin la revoque. Apagar la copia no elimina esa autorización.

```text
réplica apagada
  authorized = true
  available  = false

réplica encendida y presente
  authorized = true
  available  = true
```

`LastSeen` determina disponibilidad operativa. La ventana actual es 15 minutos.

Las vistas administrativas siguen mostrando una autorización activa aunque la copia esté offline. La asignación de nuevo trabajo físico, en cambio, usa sólo copias `active` cuya presencia sea reciente.

## Reactivación después de apagar/encender

Una copia ya aprobada **no vuelve a `Solicitudes` cada vez que arranca**.

La copia es quien inicia la reactivación:

```text
copia arranca
  -> resuelve HTTPS y endpoint actual
  -> firma descriptor
  -> POST provider-presence.php al origen
  -> origen comprueba autorización active
  -> valida descriptor + node.php HTTPS
  -> conserva Node ID / clave pública
  -> actualiza endpoint + LastSeen
  -> available=true
```

`provider-presence.php` es una puerta rápida de presencia, no una puerta de privilegios. Nunca crea una autorización ni cambia `role`/`scope`.

Si la relación no existe, responde `authorization_required` y la copia cae al flujo inicial `provider-request.php -> Aduana -> Solicitudes`.

Si la relación está `pending`, `revoked` o `blocked`, no se vuelve a crear automáticamente: requiere la decisión administrativa correspondiente.

## Configuración automática de una copia

En el `federation.env` de la copia:

```env
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL=https://drive.esforzados.com/federationcloud/
ARCADECLOUD_FEDERATION_REPLICA_ROLE=mirror
ARCADECLOUD_FEDERATION_REPLICA_SCOPE=all_allowed_resources
```

El nodo origen no configura `ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL`; por eso el origen nunca busca periódicamente a sus copias.

`federation_endpoint_refresh.php` anuncia la copia al arrancar/cambiar endpoint y `federation_sync.php` mantiene su presencia en ciclos posteriores.

## Solicitud manual desde una copia autorizable

Ejemplo, únicamente después de que la copia tenga su propia identidad y `node.php` operativo:

```bash
sudo -u apache php drive/bin/federation_provider_request.php \
  --origin=https://drive.esforzados.com/federationcloud/ \
  --public-url=https://fastdrive.esforzados.com \
  --federation-url=https://fastdrive.esforzados.com/federationcloud/ \
  --identity=/etc/arcadecloud-drive/federation-node.json \
  --role=mirror \
  --scope=all_allowed_resources
```

El endpoint primero devuelve aceptación en Aduana. En un ciclo posterior del worker, si la comprobación en vivo es válida, la relación aparecerá como `pending` en `Solicitudes`.

## Seguridad

- Registrar un nodo independiente no autoriza recursos y no requiere superadmin.
- `provider-request.php` está reservado a `relationship=shared_backend`.
- `provider-presence.php` sólo actualiza una relación que ya está `active`.
- La petición inicial privilegiada entra a una cola MySQL idempotente y el worker procesa una llegada por ciclo.
- El origen consulta al candidato y valida firma, Node ID, clave, nombre y URLs antes de crear la solicitud pendiente o actualizar presencia.
- Sólo `Users.system_role = 'superadmin'` puede aprobar, rechazar o revocar.
- Las decisiones POST requieren token CSRF de sesión.
- La autorización queda firmada por el nodo origen.
- Nunca se envían credenciales AWS, contraseñas DB, cookies, sesiones, `secret_key` ni `payload_key` como prueba de backend compartido.
- Una copia debe conservar Node ID/clave propios aun cuando comparta almacenamiento o base de datos con el origen.

La arquitectura completa está documentada en:

- `drive/docs/FEDERATION_CUSTOMS_QUEUE.md`
- `drive/docs/FEDERATION_OPERATIONS.md`
