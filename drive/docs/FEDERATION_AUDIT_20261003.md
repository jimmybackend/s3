# Auditoría integral FederationCloud — 2026-10-03

## Alcance

Auditoría del estado real de FederationCloud sobre `main`, incluyendo:

- identidad y descubrimiento de nodos;
- seed primario y registro;
- ArcadeLink individual y colecciones;
- catálogo federado;
- Shares y solicitudes de acceso;
- providers, mirrors y réplicas;
- transporte multisource;
- moderación por huella;
- recuperación;
- instalación de nuevos nodos;
- historial y conteo de descargas;
- duplicidades funcionales.

## Resultado ejecutivo

FederationCloud ya dispone de una arquitectura federada real y no depende de un nodo central para almacenar los bytes. La instalación nueva conoce por defecto el seed de `drive.esforzados.com`, registra una identidad criptográfica propia y mantiene autonomía de MySQL/S3.

Sin embargo, todavía NO debe considerarse cerrado el comportamiento multinodo completo. Hay dos brechas funcionales principales:

1. La descarga pública directa del navegador usa failover entre nodos, pero termina descargando el archivo completo desde una sola ubicación. El transporte multisource por rangos existe, pero hoy se usa en procesos servidor-a-servidor como réplicas, importación a Mi Drive y FederationDrop.
2. No existe un ledger FederationCloud general de descargas por recurso/nodo. FederationDrop sí posee `DownloadCount`, pero los recursos normales de FederationCloud no mantienen conteo/historial federado de cuándo se entregaron, desde qué nodo fueron servidos ni cuántas veces.

Estas dos brechas impiden afirmar que el módulo cumple todavía, de extremo a extremo, el objetivo de entrega fragmentada al usuario final y trazabilidad histórica por recurso/nodo.

## Capacidades verificadas en código

### 1. Seed primario y alta de nodos

El repositorio contiene `drive/config/federation-seeds.json` con:

`https://drive.esforzados.com/federationcloud/`

`ARCADECLOUD_FEDERATION_SEED_URL` permite sustituir el seed sin modificar código.

El instalador no considera terminada FederationCloud sólo por crear la identidad local: presenta el descriptor firmado al seed y exige confirmación del directorio global.

Conclusión: implementado.

### 2. Identidad independiente por nodo

Cada instalación mantiene:

- `node_id`;
- `node_name`;
- clave pública Ed25519;
- clave privada fuera del DocumentRoot;
- `payload_key`;
- `public_url`;
- `federation_url`.

El Node ID es criptográfico y no depende del dominio/IP.

Conclusión: implementado.

### 3. ArcadeLink individual

ArcadeLink v1 conserva:

- Resource ID estable;
- nodo de origen;
- visibilidad;
- derechos;
- referencia privada cifrada;
- firma Ed25519;
- Content ID SHA-256 cuando aplica.

Conclusión: implementado y documentado como probado en producción para origen único.

### 4. ArcadeLink de colección

ArcadeLink v2 permite un único `.arcadelink` para varios archivos.

Cada recurso interno conserva su propia firma y `origin_node_id`. No se empaquetan físicamente los archivos ni se usa ZIP.

Límites actuales:

- hasta 500 recursos;
- hasta 4 MiB por archivo ArcadeLink.

Conclusión: implementado y probado en producción con una colección de tres archivos en origen único.

### 5. Catálogo federado y ubicaciones

Existen:

- `FederatedResources`;
- `FederationResourceLocations`;
- `FederationEvents`;
- `FederationClocks`;
- materialización de eventos firmados;
- tombstones;
- selección de ubicación.

Prioridad actual:

1. mirror activo;
2. provider activo;
3. origin activo;
4. mirror stale;
5. provider stale;
6. origin stale.

Conclusión: implementado.

### 6. Búsqueda global

La búsqueda consulta la copia local sincronizada de `FederatedResources`. No hace fan-out HTTP en tiempo real contra todos los nodos.

Esto es coherente con un catálogo replicado y evita convertir cada búsqueda en una dependencia de disponibilidad global.

Conclusión: implementado.

### 7. Providers, mirrors y réplicas

Existen autorizaciones separadas de la mera presencia del nodo.

Roles:

- provider;
- mirror.

La réplica automática requiere `all_allowed_resources`.

Los recursos elegibles son `PUBLIC + copy_allowed` con Content ID SHA-256.

Conclusión: implementado.

### 8. Transporte multisource

`FederationMultiSourceDownloader` soporta hasta cuatro fuentes y:

- usa HTTP Range;
- exige 206;
- asigna rangos disjuntos;
- puede hacer fallback por rango;
- ensambla por offset;
- verifica SHA-256 final.

Actualmente se utiliza en:

- importación pública a Mi Drive;
- materialización de nuevas réplicas;
- materialización de FederationDrop desde recursos públicos.

Conclusión: transporte multisource real implementado.

### 9. Descarga pública del navegador

`replica-open.php` usa `FederationReplicaResolverService::openPreferred()`.

El resolver prueba ubicaciones por prioridad y redirige al primer URL S3 temporal válido.

Esto proporciona failover real, pero NO divide una descarga del navegador entre varios nodos.

Conclusión: failover implementado; descarga final multisource pendiente.

### 10. Shares y solicitudes de acceso

Existen:

- `FederationAccessRequests`;
- `FederationShares`;
- `FederationShareImportJobs`;
- grants temporales;
- received/sent;
- expiración;
- importación a FileS3.

Conclusión: implementado.

### 11. Moderación

FederationCloud identifica contenido por SHA-256, no por nombre.

Existen:

- `FederationContentFingerprints`;
- `FederationAbuseReports`;
- `FederationModerationBlocks`;
- `FederationModerationActions`;
- eventos firmados `moderation.block` y `moderation.unblock`.

Conclusión: implementado.

### 12. Recuperación

La identidad del nodo se conserva fuera del DocumentRoot y existen rutas de backup/restore de identidad.

ArcadeLink mantiene la identidad lógica del recurso separada de su ruta física.

Conclusión: implementado para identidad y referencias; la validación multinodo completa continúa pendiente según la matriz de producción.

## Brechas reales detectadas

### A. Historial de descargas FederationCloud

No existe una tabla o servicio general equivalente a:

`FederationResourceDownloads`

o un ledger equivalente para recursos normales.

La actividad local `arcadelink_open` no reemplaza este requisito porque:

- sólo cubre usuario autenticado/local;
- no constituye historial federado;
- no conserva de forma explícita nodo servidor por recurso;
- no produce un contador global o por ubicación.

FederationDrop sí mantiene `DownloadCount`, pero pertenece al servicio comercial temporal.

Estado: pendiente.

### B. Descarga directa multinodo por partes

El downloader multisource existe, pero no está conectado a la entrega final del navegador.

Estado: pendiente.

### C. Validación manual multinodo de ArcadeLink

La propia matriz `ARCADELINK_PRODUCTION_VALIDATION.md` mantiene pendientes de prioridad alta:

- abrir desde un segundo nodo real;
- failover real entre dos ubicaciones;
- colección cuyos recursos tengan orígenes diferentes;
- réplica física PUBLIC + copy_allowed validada de extremo a extremo;
- recuperación de un nodo que vuelve tarde.

Estado: pendiente de evidencia manual de producción.

## Duplicidades revisadas

No deben considerarse duplicados:

- `FederationShareImportJobs` y `FederationPublicImportJobs`: una importa grants privados/Compartidos y la otra recursos públicos.
- `FederationReplicaJobs` y los jobs anteriores: la primera materializa infraestructura de réplica, no archivos de usuario.
- `FederationDrop DownloadCount` y un futuro historial general FederationCloud: FederationDrop cuenta redenciones comerciales; el historial federado normal debe describir entregas de recursos.

No se recomienda fusionar estas responsabilidades.

## Criterio de cierre funcional

FederationCloud debe considerarse completo para el objetivo multinodo descrito cuando:

- [ ] exista ledger por recurso con contador e historial de entregas;
- [ ] cada entrega registre al menos Resource ID, nodo que sirvió, rol y fecha;
- [ ] pueda consultarse historial por recurso sin exponer secretos ni URLs S3;
- [ ] la descarga final pueda usar múltiples nodos por rangos o exista una ruta explícita equivalente para ese modo;
- [ ] el hash SHA-256 del archivo final sea obligatorio después del ensamblado;
- [ ] fallen rangos individuales hacia fuentes alternativas;
- [ ] se completen los cinco escenarios multinodo de prioridad alta;
- [ ] CI cubra contrato de historial y entrega multisource final;
- [ ] la documentación de estado ya no marque esos escenarios como pendientes.

## Principio de arquitectura

`drive.esforzados.com` debe continuar siendo el seed/directorio primario por defecto, NO un almacenamiento central obligatorio.

Cada nodo:

- mantiene identidad propia;
- puede poseer el archivo completo, una réplica o ninguna copia;
- anuncia solamente ubicaciones que realmente posee;
- puede servir partes cuando dispone de una copia válida;
- participa mediante metadatos firmados;
- no recibe secretos de otros nodos.

Esto conserva FederationCloud como federación de nubes y no como un único Drive con proxies.
