# ArcadeLink: uno para uno o uno para todos

FederationCloud usa un único archivo `.arcadelink` como pasaporte portable.

## Contrato de archivo

ArcadeLink es un **tipo de archivo propio de ArcadeCloud**, no un archivo `.json` para el usuario:

```text
Extensión:  .arcadelink
Media type: application/vnd.arcadecloud.arcadelink
```

La serialización interna firmada es un detalle de implementación. ArcadeCloud **genera, descarga, lee y presenta exclusivamente archivos `.arcadelink`**. Cualquier archivo con otra extensión debe rechazarse y volver a generarse desde la acción Compartir.

## Un archivo

Al compartir un solo recurso, el Drive descarga directamente:

```text
documento.pdf.arcadelink
```

Ese ArcadeLink conserva el contrato v1 existente: firma Ed25519, payload privado XChaCha20-Poly1305, `resource_id`, nodo origen, visibilidad, derechos y, cuando corresponde, `content_id`.

## Varios archivos

Al compartir varios recursos, el Drive descarga directamente un solo archivo:

```text
Compartidos-3-archivos.arcadelink
```

Ese documento usa ArcadeLink v2 y contiene una colección firmada:

```text
ArcadeLink v2
├── recurso 1 -> ArcadeLink v1 firmado por su nodo
├── recurso 2 -> ArcadeLink v1 firmado por su nodo
└── recurso N -> ArcadeLink v1 firmado por su nodo
```

No se crea ZIP, no se genera `Abrir-FederationCloud.html` y no se empaquetan los archivos físicos. Cada recurso interno conserva su firma original y su `origin_node_id`.

El límite actual es de 500 recursos y 4 MiB por archivo `.arcadelink`.

## Lectura

El mismo lector de FederationCloud acepta v1 y v2.

- v1: muestra un recurso;
- v2: valida la firma de la colección, valida de nuevo cada ArcadeLink interno y muestra todos los recursos;
- recurso local: se resuelve contra MySQL/FileS3 sin listar S3;
- recurso de otro nodo: se resuelve usando su `origin_node_id` y `federation_url`.

La colección no vuelve a firmar los recursos como si pertenecieran al nodo que la armó. Cada recurso mantiene su identidad criptográfica de origen.

## Catálogo global

Crear una colección no crea una segunda fuente de verdad. Cada recurso individual conserva su publicación normal en `FederatedResources` según `discovery_policy`.

## Seguridad

Un `.arcadelink` no contiene:

- credenciales AWS;
- cookies o sesiones;
- claves privadas;
- URLs S3 permanentes;
- bytes del archivo físico.

Modificar la metadata de un recurso, un elemento de la colección o la colección exterior invalida la firma correspondiente.

## Compatibilidad

Los ArcadeLinks v1 y v2 existentes con extensión `.arcadelink` siguen siendo válidos. ArcadeLink v2 agrega colecciones sin romper el formato anterior. Archivos con otra extensión no forman parte del contrato ArcadeLink y deben generarse nuevamente.


## Validación de producción

La matriz que separa pruebas manuales, cobertura CI y casos mult nodo pendientes está en:

`drive/docs/ARCADELINK_PRODUCTION_VALIDATION.md`

Al 24 de septiembre de 2026 se validó manualmente en producción tanto un ArcadeLink de **un recurso** como una colección de **tres recursos**, incluyendo la descarga correcta de todos los archivos.
