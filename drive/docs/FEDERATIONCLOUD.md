# ArcadeLink v1 + FederationCloud

Estado: fase 1 funcional de pasaporte/resolución + fase 1.1 de descubrimiento de nodos. No incluye P2P, buscador global, OAuth ni replicación automática.

## Contrato v1

- `.arcadelink` es el pasaporte portable y firmado de un recurso.
- `/federationcloud/` es la puerta humana para validarlo y resolverlo.
- `resource_id` (`arl_...`) identifica lógicamente el recurso.
- `content_id` (`sha256:...`) identifica los bytes exactos cuando esa huella ya existe.
- `node_id` (`acn_...`) identifica criptográficamente al nodo emisor.
- El nombre visible nunca se usa como identidad.

Variables de entorno:

```text
ARCADECLOUD_PUBLIC_URL=https://drive.tudominio.com
ARCADECLOUD_FEDERATION_URL=https://drive.tudominio.com/federationcloud/
ARCADECLOUD_FEDERATION_ENABLED=true
ARCADECLOUD_FEDERATION_IDENTITY=/etc/arcadecloud-drive/federation-node.json
```

Opcionalmente `ARCADECLOUD_FEDERATION_SEED_URL` permite sustituir el seed primario sin cambiar código. Si no se define, el repositorio usa `drive/config/federation-seeds.json`.

La identidad se crea explícitamente con `drive/bin/federation_identity_init.php` y no se genera durante una petición web.

## Formato

ArcadeLink v1 publica: versión, `resource_id`, `origin_node_id`, URL pública, URL federada, tipo, título, tamaño, MIME, visibilidad, derechos, `content_id` cuando procede, fecha de emisión, payload cifrado y firma digital.

Firma: Ed25519.

Payload: XChaCha20-Poly1305. La referencia local `user_id + file_id + resource_id` viaja cifrada y autenticada; otro nodo puede verificar el documento pero no resolver esa referencia interna por sí mismo.

## Visibilidad

- `PUBLIC`: preparado para anuncio/búsqueda futura; puede publicar SHA-256 si existe.
- `UNLISTED`: no aparecerá en búsqueda general; resuelve quien posee el `.arcadelink`; puede publicar SHA-256 si existe.
- `PRIVATE`: no se anuncia y fuerza `content_id = null`.

Un `FileS3.AccessType=secure` sólo puede emitirse PRIVATE y debe abrirse desde el Drive original.

## Derechos

Valores v1: `copy_allowed`, `link_only`, `unknown_rights`, `user_owned_authorized`.

El valor por defecto es `unknown_rights`, tratado conservadoramente como sólo enlace. La existencia de un archivo en un Drive no se interpreta automáticamente como autorización para redistribuirlo.

## Resolución

Local:

```text
.arcadelink
 -> validar formato y límites
 -> verificar Node ID y firma
 -> descifrar referencia local
 -> consultar FileS3 por user_id_ + id_ + Found=1
 -> comparar SHA-256 si existe en ambos lados
 -> aplicar visibilidad y protección del archivo
```

Remota:

```text
.arcadelink
 -> verificar firma localmente
 -> validar federation_url
 -> consultar sólo node.php
 -> comprobar Node ID, clave pública y firma del descriptor
 -> consultar resolve.php
 -> recibir estado público del recurso
```

El cliente remoto acepta únicamente HTTPS:443, no sigue redirects, limita tiempos y payloads, rechaza IPv4 privadas/reservadas y fija la IP DNS elegida al conectar. No existe un descargador de URLs arbitrarias.

## Directorio de nodos: seed inicial

El seed inicial vive en:

```text
https://drive.esforzados.com/federationcloud/
```

No se codifica dentro de la lógica PHP: está declarado en `drive/config/federation-seeds.json` y puede reemplazarse con la variable `ARCADECLOUD_FEDERATION_SEED_URL`.

Cada instalación posee su propia identidad Ed25519 y se anuncia al seed mediante `POST /federationcloud/register.php`. El anuncio contiene únicamente el descriptor público firmado. Antes de persistirlo, el seed:

1. valida protocolo, versión, Node ID, clave pública y firma;
2. valida que las URLs sean HTTPS;
3. consulta mediante el cliente SSRF-safe el `node.php` de la URL anunciada;
4. vuelve a validar la firma obtenida directamente del nodo;
5. exige coincidencia de Node ID, clave pública y URLs;
6. actualiza `LastSeen` en `FederationNodes`.

No se persiste IP, ciudad, país ni ubicación física. La identidad federada es el `node_id`.

`GET /federationcloud/nodes.php` devuelve el nodo local, el seed, el contador y hasta 100 nodos activos. En esta fase, “Nodos conectados” significa nodos con `Status=active` observados durante los últimos 15 minutos. El seed se auto-registra localmente, por lo que una red recién creada empieza con 1 nodo.

Los nodos no-seed se anuncian al seed al consultar su directorio. Si el seed no está disponible, el nodo conserva operación local y el endpoint responde en modo degradado con su propio nodo; no se bloquea la navegación del Drive.

El footer carga este estado de forma asíncrona con `drive/js/federation-footer.js`; la carga HTML normal del Drive no espera una llamada federada.

## Tabla FederationNodes

La fase 1.1 materializa la primera tabla federada. La migración puntual está en `drive/docs/sql/FederationNodes.sql`.

Campos persistidos: `NodeId`, `PublicKey`, `PublicUrl`, `FederationUrl`, `Status`, `FirstSeen`, `LastSeen`.

En una producción existente se ejecuta únicamente esa migración específica; nunca se reimporta el dump maestro completo.

## Apertura local

Si la política lo permite, FederationCloud reutiliza `ShareLinkService` para generar el acceso temporal ya existente del Drive. FederationCloud no publica una dirección física permanente del objeto almacenado.

## Actividad y costos

Para usuarios autenticados se registran en `DriveActivityEvents`:

- `arcadelink_create`
- `arcadelink_resolve`
- `arcadelink_open`

Las operaciones sin costo AWS directo usan la unidad existente `drive.no_direct_aws_charge=1`. Las peticiones anónimas no se atribuyen a un usuario ficticio.

## Seguridad del registro

`register.php` sólo acepta POST JSON de hasta 64 KiB. Los descriptores deben estar firmados y el seed verifica activamente el endpoint anunciado mediante el cliente FederationCloud protegido contra SSRF. Para exposición pública se recomienda además rate limiting en Nginx/ALB sobre `/federationcloud/register.php`; esta protección perimetral no se sustituye por la validación criptográfica.

## Diseño persistente posterior

Implementado ahora:

1. `FederationNodes`: Node ID, clave pública, URLs, estado y fechas de observación.

Pendiente para fases posteriores:

2. `FederatedResources`: Resource ID, nodo origen, metadatos públicos, SHA-256 nullable, visibilidad, derechos, procedencia, versión/firma y estado.
3. `FederationResourceLocations`: relación `origin|mirror` entre recurso/contenido y nodos que anuncian una ubicación, con última verificación.
4. `FederationLocalBindings`: binding explícita `resource_id -> user_id_ + FileS3.id_` para ciclo de vida y revocación futura.

Reglas: PRIVATE nunca tendrá fingerprint público; toda binding local conservará `user_id_`; `FileS3` seguirá siendo fuente de verdad local; no se duplicarán `Nombre`, `Ruta` o `Encriptado` como autoridad. Para una producción existente se entregan sólo los `CREATE TABLE`/`ALTER` puntuales, nunca una reimportación del dump maestro.

## Siguiente fase

Pendiente: índice público de recursos, mirror lookup por SHA-256, copia autorizada/Guardar en mi Drive, OAuth para nubes externas, revocación persistente y registro administrable de confianza. P2P/BitTorrent sigue fuera de esta etapa.
