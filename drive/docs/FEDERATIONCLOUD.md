# ArcadeLink v1 + FederationCloud

Estado: fase 1 funcional de descubrimiento y resolución. No incluye P2P, buscador global, OAuth ni replicación automática.

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

Remota, fase 1:

```text
.arcadelink
 -> verificar firma localmente
 -> validar federation_url
 -> consultar sólo node.php
 -> comprobar Node ID, clave pública y firma del descriptor
 -> consultar sólo resolve.php
 -> recibir estado público del recurso
```

El cliente remoto acepta únicamente HTTPS:443, no sigue redirects, limita tiempos y payloads, rechaza IPv4 privadas/reservadas y fija la IP DNS elegida al conectar. No existe un descargador de URLs arbitrarias.

## Apertura local

Si la política lo permite, FederationCloud reutiliza `ShareLinkService` para generar el acceso temporal ya existente del Drive. FederationCloud no publica una dirección física permanente del objeto almacenado.

## Actividad y costos

Para usuarios autenticados se registran en `DriveActivityEvents`:

- `arcadelink_create`
- `arcadelink_resolve`
- `arcadelink_open`

Las operaciones sin costo AWS directo usan la unidad existente `drive.no_direct_aws_charge=1`. Las peticiones anónimas no se atribuyen a un usuario ficticio.

## Diseño de base de datos para la fase persistente

La fase 1 no necesita tablas nuevas. Por eso no modifica `adbbmis1_Cloud.sql`, no crea una migración y no requiere ejecutar SQL en producción.

Cuando se implemente descubrimiento persistente y mirrors, el modelo previsto es:

1. `FederationNodes`: Node ID, clave pública, URLs, confianza/estado y fechas de observación.
2. `FederatedResources`: Resource ID, nodo origen, metadatos públicos, SHA-256 nullable, visibilidad, derechos, procedencia, versión/firma y estado.
3. `FederationResourceLocations`: relación `origin|mirror` entre recurso/contenido y los nodos que anuncian una ubicación, con última verificación.
4. `FederationLocalBindings`: binding explícita `resource_id -> user_id_ + FileS3.id_` para ciclo de vida y revocación futura.

Reglas futuras: PRIVATE nunca tendrá fingerprint público; toda binding local conservará `user_id_`; `FileS3` seguirá siendo fuente de verdad local; no se duplicarán `Nombre`, `Ruta` o `Encriptado` como autoridad; y cualquier tabla nueva se añadirá directamente al SQL maestro. Para una producción existente se entregarán sólo los `CREATE TABLE`/`ALTER` puntuales, nunca una reimportación del dump maestro.

## Siguiente fase

Pendiente: anuncios entre nodos, índice público, mirror lookup por SHA-256, copia autorizada/Guardar en mi Drive, OAuth para nubes externas, revocación persistente y registro administrable de confianza. P2P/BitTorrent sigue fuera de esta etapa.
