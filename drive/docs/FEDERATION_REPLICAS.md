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

## Workers cuando varios nodos comparten MySQL/S3

Una instalación puede tener dos identidades FederationCloud distintas sobre una MySQL común.
La cola operacional conserva el esquema existente, pero su consumo está particionado lógicamente:

- `outgoing`: sólo lo procesa el Node ID que es origen del recurso en `FederatedResources`;
- `incoming`: sólo lo procesa el `target_node_id` firmado en la oferta;
- la fila entrante usa un identificador operacional interno distinto del `offer_id` firmado para que
  las filas incoming/outgoing puedan coexistir en una tabla cuyo `OfferId` es clave primaria.

El `offer_id` protocolario no se modifica dentro de la oferta firmada. El identificador interno
sólo existe en la cola MySQL local/compartida.

Como `FederationReplicaObjects` también puede ser visible desde una DB compartida, resolver o
servir una copia exige además que el nodo actual tenga una `location.upsert` activa propia. Así
un nodo no se atribuye una ubicación anunciada por otro aunque ambos puedan alcanzar el mismo S3.
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

## Transporte multisource

Cuando el catálogo conoce dos o más ubicaciones activas del mismo `PUBLIC + copy_allowed`, el receptor puede pedir hasta cuatro URLs S3 temporales y dividir el archivo en rangos HTTP disjuntos.

```text
mirror A  -> bytes 0..N
provider B -> bytes N+1..M
origin C   -> bytes M+1..fin
                 |
                 v
          ensamblado temporal
                 |
          SHA-256 == Content ID
```

`FederationMultiSourceDownloader`:

- usa `Range` y exige respuesta HTTP 206;
- nunca confía en el orden de llegada: ensambla por offset;
- limita el total al tamaño global conocido y a 5 GiB;
- permite fallback de cada rango a otra fuente si una réplica falla;
- calcula SHA-256 del archivo completo después del ensamblado.

Si sólo existe una copia física todavía, no se finge multisource: esa transferencia necesariamente sale completa de la única fuente existente. El reparto comienza cuando existen varias copias verificadas.

Las nuevas réplicas y las importaciones públicas a Mi Drive usan este transporte automáticamente cuando hay varias fuentes.

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

Para `PUBLIC + copy_allowed`, `/federationcloud/replica-open.php` no exige sesión: la política pública se valida en el backend antes de emitir una URL. El resolver intenta cada ubicación en orden, obtiene una URL S3 temporal y hace una prueba real de rango antes de redirigir. Si una credencial S3 está vencida/incorrecta o un mirror no responde, prueba el siguiente provider y finalmente el origin.

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
GET      /federationcloud/replica-open.php?resource_id=arl_...  (PUBLIC + copy_allowed, sin login)
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
- que la ubicación se emita sólo después del paso de almacenamiento;
- reunión de varias fuentes públicas;
- prueba real de URL S3 antes del redirect;
- uso de rangos HTTP en transporte multisource;
- verificación SHA-256 después de ensamblar.

La transferencia real S3→provider, propagación del `location.upsert` y failover con un nodo apagado se dejan para la prueba física entre nodos. El protocolo y el código quedan preparados antes de esa prueba.
