# Administrador de Descargas Federadas — auditoría de base

Fecha: 2026-10-16. Rama: feature/federated-download-audit.

## Hechos contrastados con adbbmis1_Cloud.sql y servicios actuales

- `FederatedResources`: ResourceId, OriginNodeId, OwnerUserId (sólo nodo dueño), ResourceType, Title, MediaType, SizeBytes, Visibility, DiscoveryPolicy, Rights, UpdatedAt, Tombstoned. **UpdatedAt no equivale necesariamente a fecha de publicación**.
- `FederationAccessRequests`: RequestId, Direction, ResourceId, LocalUserId, RemoteNodeId, Status, RequestedAt, Attempts, LastAttemptAt, LastError y expiración. Los intentos registrados son intentos de entrega de la petición; no pruebas de descarga del archivo.
- `FederationShares`: Direction, ResourceId, UserId, RemoteNodeId, Title, MediaType, Status, ExpiresAt, ImportedAt, LocalFileId, CreatedAt. AccessUrl y DecisionJson pueden contener información sensible: **no devolverlos por la API de auditoría**.
- `FederationShareImportJobs`: UserId, ShareId, ResourceId, VersionKey, Status, Attempts, LastError, LocalFileId, CreatedAt, UpdatedAt. Dedupe por (UserId, ShareId, VersionKey); es historial de trabajos de importación, no prueba de descarga a un equipo.
- `FederationResourceDeliveries`: DeliveryId, ResourceId, RequestNodeId, BytesDelivered, SourceCount, Transport, CompletedAt, EventId. Índices (ResourceId, CompletedAt), EventId único. Registra transferencias federadas completas según el servicio, no descargas finales de navegador.
- `FederationResourceDeliverySources`: nodos y roles participantes por entrega.
- `FederationDrops`: DownloadCount y LastDownloadAt se actualizan dentro de claimDownload() ANTES de retornar un presigned GetObject de S3. Son reclamaciones/autorizaciones de descarga, NO evidencia de consumo completo. Nunca modificar su significado: MaxDownloads depende de este comportamiento.
- `FederationResourceLocations`: nodo, rol, estado y fechas de sincronización.
- `FileS3`: id_, user_id_, Nombre, Tamano, Ruta, Fecha y metadatos. No proporciona histórico individual de descargas.
- `FederationEvents` y `FederationEventStore`: eventos firmados para sincronización del catálogo y delivery.record; NO almacenar por defecto eventos privados de acceso/usuario en la réplica pública del catálogo.

## Lo que el histórico permite y lo que no

Podemos recuperar metadatos actuales, estado de solicitudes/comparticiones, trabajos de importación, nodos involucrados, entregas federadas, bytes de esas entregas y fecha de su última entrega. No se puede determinar universalmente: visitante real, visualización, descarga final completa de usuario, primera descarga de navegador, bytes exactos de la transferencia final ni costo atribuible. No inferir ceros a partir de NULL/desconocido.

## Primera implementación en esta rama

`FederationDownloadAuditRepository` expone lecturas limitadas a OwnerUserId: listado paginado (máximo 100), información por recurso e historial de entregas. No publica endpoint, no aplica cambios al esquema ni modifica flujos de acceso/descarga. `verified_final_downloads=null` significa desconocido, no cero. No enlazar directamente el repositorio a una ruta pública sin autenticación, cuotas, rate limits y pruebas adicionales.

## Próximas etapas antes de producción

1. Auditar los controladores de ArcadeLink, FederationDrop, privados y rutas de descarga, sus comprobaciones de propiedad/autorización, y logs ya existentes.
2. Comparar `SHOW CREATE TABLE` e índices de producción usando un canal MySQL de **solo lectura**, si disponible; evitar dumps de datos personales.
3. Proponer un registro de eventos de acceso **local y privado**, con event_id único e idempotente, resource_id, contexto (share/drop), tipo, fase, origen, marcas UTC, bytes verificados, código de fallo saneado y observabilidad sin tokens, URLs firmadas, IP completas o PII innecesaria. No aplicar migración hasta validar todas las relaciones. Política de retención y agregados a definir con datos reales.
4. Distinguir `page_view`, `preview`, `access_request`, `presign_issued`, `transfer_started`, `federated_delivery_complete`, `final_download_verified`, `failed`, `import_complete`, `expired`.
5. Para S3 presign directo, la emisión de URL no verifica transferencia final. Investigar S3 server access logs/CloudTrail data events y sus costos, cobertura, retardo y correlación; un GetObject 200 puede no probar recepción íntegra por el usuario. Considerar un proxy/stream medido o recibos verificables para casos que requieran certeza de fin a fin, evaluando su costo y latencia.
6. Consultas agregadas con filtros por propietario en SQL, índices compuestos por propietario, resource_id y timestamp; paginación por cursor para millones de eventos. UTC almacenado y conversión solamente al presentar.
7. Interfaz OS compatible con so.php y FederationCloud; pruebas unitarias/integración para IDOR, roles, expiración, reintentos, duplicados y eventos perdidos. No activar públicamente sin pruebas.
8. Abrir PR y fusionar sólo cuando cumpla las pruebas y la validación de seguridad.

## Seguridad y cautelas

No se ha verificado esquema de producción ni acceso MySQL con el puente de EC2 status; el workflow `.github/workflows/arcadecloud-ec2-status-bridge.yml` muestra consulta de EC2/SSM, no una API MySQL. No tocar ni modificar AWS desde esta fase. No registrar en eventos privados `AccessUrl`, `DecisionJson`, tokens, credenciales ni claves S3. Evitar que identificadores de propietarios se repliquen entre nodos.
