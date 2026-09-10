# FederationCloud — autorización de proveedores

## Objetivo

Permitir que un nodo FederationCloud solicite servir recursos de otro nodo sin obtener autoridad automática por el simple hecho de estar registrado.

Ejemplo:

- origen: `jimmybackend` en `drive.esforzados.com`;
- candidato proveedor: `fastdrive` en `fastdrive.esforzados.com`.

## Flujo

1. El candidato debe tener una identidad FederationCloud distinta y un `node.php` HTTPS accesible.
2. Desde el candidato se ejecuta `drive/bin/federation_provider_request.php` apuntando al Federation URL del origen.
3. El origen verifica criptográficamente el descriptor y vuelve a consultar el `node.php` del candidato mediante el cliente protegido contra SSRF.
4. La relación se guarda como `pending` en `FederationNodeAuthorizations`.
5. En el Drive del origen, una sesión con rol `Administración` ve `Solicitudes: N` en el footer.
6. El administrador abre el modal y elige `Aprobar` o `Rechazar`.
7. Al aprobar, el origen firma con Ed25519 el vínculo exacto origen→proveedor, rol y alcance.
8. Sólo proveedores `active` aparecen en `/federationcloud/providers.php`.
9. Una autorización activa puede revocarse desde el mismo modal.

## Solicitud desde un nodo candidato

Ejemplo, únicamente después de que `fastdrive.esforzados.com` tenga su propia identidad y `node.php` operativo:

```bash
sudo -u nginx php drive/bin/federation_provider_request.php \
  --origin=https://drive.esforzados.com/federationcloud/ \
  --public-url=https://fastdrive.esforzados.com \
  --federation-url=https://fastdrive.esforzados.com/federationcloud/ \
  --identity=/etc/arcadecloud-drive/federation-node.json \
  --role=provider \
  --scope=all_allowed_resources
```

El resultado esperado antes de aprobación es `status=pending`.

## Seguridad

- Registrar un nodo no autoriza recursos.
- La solicitud no se confía de forma ciega: el origen consulta al candidato y valida firma, Node ID, clave, nombre y URLs.
- Sólo el rol de sesión `Administración` puede aprobar/rechazar/revocar.
- Las decisiones POST requieren token CSRF de sesión.
- La autorización queda firmada por el nodo origen.
- El proveedor no recibe por este flujo permisos de escritura en S3 o MySQL.
- `provider-request.php` debe tener rate limiting perimetral antes de exposición amplia.

## Alcance actual

Esta fase establece confianza entre nodos. Todavía no implementa selección de servidor durante una descarga. La siguiente capa será `FederatedResources` + `FederationResourceLocations`, para que un recurso pueda anunciar origen/proveedores autorizados y el usuario pueda elegir desde qué nodo descargar.
