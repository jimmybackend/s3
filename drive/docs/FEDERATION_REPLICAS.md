# FederationCloud: providers, mirrors y réplicas físicas

## Objetivo

El catálogo descentralizado permite saber que un recurso existe y dónde fue visto. Esta capa añade copias físicas autorizadas para que un recurso `PUBLIC + copy_allowed` pueda seguir abriéndose aunque el nodo origen esté temporalmente caído.

No existe un nodo matriz de almacenamiento.

## Autorización reutilizada

No se crea una segunda red de confianza. Las réplicas usan `FederationNodeAuthorizations` existentes.

Sólo participan autorizaciones:

```text
status = active
role = provider | mirror
scope = all_allowed_resources
```

`selected_resources` no se usa para replicación automática porque el grant actual no contiene una lista criptográficamente vinculada de Resource IDs.

## Requisitos del recurso

La réplica automática exige:

```text
rights = copy_allowed
content_id = sha256:<64 hex>
size <= 5 GiB
```

El límite de 5 GiB corresponde a esta primera implementación con `PutObject` simple. Archivos mayores requieren multipart y quedan fuera de esta versión.

## Distribución sin nodo central

Para un Resource ID, el origen calcula un orden determinista de providers activos con:

```text
sha256(resource_id | provider_node_id)
```

Esto distribuye recursos entre providers sin una base de planificación central. Por defecto la UI solicita dos copias y el backend permite entre 1 y 3.

Un provider que ya aparece como ubicación `active` del recurso no vuelve a ser elegido.

## Control plane privado

El origen crea `FederationReplicaJobs` locales. El worker procesa pocos trabajos por ciclo:

```text
máximo 3 ofertas salientes
máximo 2 descargas entrantes
```

La oferta contiene:

- Offer ID;
- Resource ID;
- NodeId origen y destino;
- role provider/mirror;
- Content ID SHA-256;
- tamaño;
- tipo/título;
- URL S3 prefirmada temporal;
- provider grant firmado;
- firma Ed25519 del nodo origen.

Las ofertas no se insertan en `FederationEvents` y no se gossip-ean.

## Los bytes no atraviesan PHP del origen

El nodo origen crea una URL S3 prefirmada de aproximadamente 10 minutos y envía únicamente esa URL dentro de la oferta privada firmada.

```text
S3 origen
   |
   | HTTPS prefirmado
   v
worker provider
   |
   v
S3 provider
```

Esto evita usar Nginx/PHP del nodo origen como proxy para archivos grandes.

El downloader receptor:

- acepta sólo HTTPS puerto 443;
- no sigue redirects;
- acepta únicamente endpoints S3 `*.amazonaws.com`;
- rechaza DNS que resuelva a IP privada/reservada;
- transmite a archivo temporal, no a memoria;
- limita la descarga al tamaño firmado y a 5 GiB;
- calcula SHA-256 durante el streaming;
- rechaza la copia si tamaño o hash no coinciden.

Después se sube el temporal a:

```text
FederationCloud/Replicas/<origin_node_id>/<resource_id>
```

en el bucket local del provider. Ese objeto no entra a `FileS3` ni a la navegación diaria del usuario.

## Cuándo se anuncia una ubicación

Un nodo receptor **no** anuncia `provider/mirror active` al recibir la oferta.

Primero debe:

1. verificar firma y grant;
2. descargar bytes;
3. comprobar tamaño + SHA-256;
4. subir a su S3;
5. comprobar `ContentLength` en su propio S3;
6. registrar `FederationReplicaObjects`;
7. emitir él mismo `location.upsert` firmado.

Esto conserva la regla criptográfica existente: un nodo sólo puede afirmar que **él** posee una ubicación.

## Selección automática y failover

`FederationLocationSelector` ordena ubicaciones así:

```text
active mirror
active provider
active origin
stale mirror
stale provider
stale origin
```

Dentro del mismo nivel se prefiere la ubicación más reciente.

La búsqueda global devuelve `preferred_location`.

Para `PUBLIC + copy_allowed`, `/federationcloud/replica-open.php` intenta cada ubicación en orden. Si un mirror no responde, prueba el siguiente provider y finalmente el origin.

Una ubicación remota responde por:

```text
POST /federationcloud/replica-resolve.php
```

Sólo devuelve una URL temporal cuando el recurso es `PUBLIC + copy_allowed` y el nodo realmente posee el original o una réplica local activa.

Para `PRIVATE` o `requestable_metadata` no existe bypass por mirror: continúa aplicando el flujo privado de solicitudes/grants del propietario.

## Tablas locales

### FederationReplicaJobs

Cola operacional `incoming/outgoing`, intentos, backoff, estado y errores. No se replica globalmente.

### FederationReplicaObjects

Mapa local de objetos físicos FederationCloud almacenados en el S3 del nodo. Tampoco se gossip-ea.

Lo único global es el evento firmado `location.upsert` emitido después de verificar almacenamiento.

## Reintentos

Los jobs fallidos usan backoff exponencial. Una oferta saliente se refresca con una nueva URL prefirmada cuando vuelve a intentarse. Si el provider estaba apagado, no se necesita conservar una URL expirada.

El ciclo FederationCloud sigue funcionando aunque la cola de réplicas falle; se marca como `degraded` dentro de la salida del worker, igual que la cola privada de Access Requests.

## Endpoints

Usuario autenticado:

```text
GET/POST /federationcloud/replica.php
GET      /federationcloud/replica-open.php?resource_id=arl_...
```

Máquina a máquina:

```text
POST /federationcloud/replica-offer.php
POST /federationcloud/replica-resolve.php
```

## Pruebas sin dos servidores

CI valida sin tráfico físico real:

- firma/verificación de ofertas;
- rechazo de manipulación;
- grant exacto origin/provider/role;
- rechazo de `selected_resources` para automático;
- orden mirror/provider/origin;
- sintaxis PHP/JS;
- esquema y límites;
- que las tablas privadas de réplica no entren al event log global;
- que la ubicación se emita sólo después del paso de almacenamiento.

La transferencia real S3→provider, propagación del `location.upsert` y failover con un nodo apagado se dejan para la prueba física entre nodos. El protocolo y el código quedan preparados antes de esa prueba.
