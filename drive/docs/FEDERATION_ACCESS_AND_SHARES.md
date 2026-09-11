# FederationCloud: solicitudes privadas y zona lógica Shares

## Objetivo

La búsqueda global puede descubrir recursos con `DiscoveryPolicy = requestable_metadata` sin publicar acceso directo. El usuario de otro nodo puede solicitar acceso y el propietario decide localmente.

Las solicitudes y grants **no forman parte del gossip global**. Sólo viajan entre el nodo solicitante y el nodo origen del recurso.

## Identidad entre nodos

No se replica ni se transmite `Users.id` como identidad global. Un `user_id` sólo tiene significado en su MySQL local.

Una solicitud contiene:

```text
request_id
resource_id
requester_node_id
requester_federation_url
requested_at
expires_at
public_key
signature Ed25519
```

El nodo solicitante guarda localmente qué usuario hizo la solicitud. El nodo origen guarda qué usuario local es dueño del recurso. Ninguno necesita conocer el ID interno del usuario remoto.

## Flujo

```text
búsqueda local del catálogo global
  -> recurso requestable_metadata
  -> usuario pulsa Solicitar acceso
  -> solicitud Ed25519
  -> POST access-request.php al nodo origen
  -> propietario la ve como incoming
  -> aprobar / rechazar
  -> decisión Ed25519
  -> solicitante consulta access-status.php
  -> si se aprobó, recibe URL temporal
  -> aparece en Shares/received
```

Si el nodo origen está caído:

```text
solicitud -> queued
worker FederationCloud -> reintento por backoff
```

No se necesita un nodo central para guardar la cola.

## Grants

Al aprobar, el nodo origen crea un enlace temporal mediante el `ShareLinkService` existente. El grant firmado contiene la URL HTTPS y su expiración.

El URL temporal:

- no se publica en `FederationEvents`;
- no se replica al catálogo global;
- sólo se devuelve al mismo `request_id` firmado por el nodo solicitante;
- sólo se guarda en la DB local del solicitante.

## Shares/

`FederationShares` es una zona lógica MySQL, no una carpeta física S3.

Direcciones:

- `received`: acceso temporal recibido desde otro nodo;
- `sent`: acceso concedido por el propietario local.

MySQL sigue siendo la fuente de navegación. FederationCloud no lista buckets S3 para construir Shares.

## Reintentos

`drive/bin/federation_sync.php` procesa como máximo 5 solicitudes salientes pendientes por ciclo, además del gossip del catálogo. Los errores no detienen el gossip global.

Una solicitud expira por defecto después de 7 días. Un grant puede durar de 1 a 30 días según la decisión del propietario.

## Endpoints máquina a máquina

```text
POST /federationcloud/access-request.php
POST /federationcloud/access-status.php
```

Ambos reciben el documento de solicitud firmado. `access-status.php` no acepta solamente un Request ID; esto evita que conocer un identificador permita recuperar un grant.

## Endpoint de usuario

```text
GET  /federationcloud/access.php
POST /federationcloud/access.php
```

GET devuelve:

- solicitudes entrantes;
- solicitudes salientes;
- Shares;
- token CSRF de la sesión.

POST acepta:

```text
action=request
resource_id=arl_...
```

o:

```text
action=decision
request_id=far_...
decision=approve|reject
days=1..30
```

Las mutaciones exigen sesión autenticada y `X-Federation-Access-CSRF`.

## Privacidad

No se sincronizan globalmente:

- correo o nombre del solicitante;
- `user_id` remoto;
- URL temporal de grant;
- tokens de descarga;
- decisiones privadas;
- contraseñas o credenciales del servidor.

El catálogo global sabe que el recurso existe y dónde está; la relación de acceso sigue siendo privada entre los dos nodos implicados.

## Migración

El mismo comando del catálogo instala también estas tablas:

```bash
php drive/bin/federation_catalog_migrate.php
```

Crea idempotentemente:

```text
FederationAccessRequests
FederationShares
```

## Pruebas sin dos nodos reales

La fase se valida con:

- solicitud Ed25519 válida;
- rechazo de manipulación;
- grant aprobado firmado;
- rechazo firmado sin URL;
- lint de servicios/endpoints;
- guards que impiden meter estos mensajes privados al event log global.

El tráfico real entre servidores se puede probar después sin cambiar el protocolo.
