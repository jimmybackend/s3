# ArcadeLink portable ZIP

Al compartir un recurso desde ArcadeCloud Drive, FederationCloud entrega un paquete ZIP portable en lugar de descargar el `.arcadelink` aislado.

Para un recurso, el ZIP contiene exactamente:

```text
ArcadeLink-portable.zip
├── Abrir-FederationCloud.html
└── <recurso>.arcadelink
```

`Abrir-FederationCloud.html` es un archivo HTML estándar y por eso puede abrirse con el navegador predeterminado en Windows, Linux o macOS. El HTML apunta a la `federation_url` firmada del nodo que emitió el ArcadeLink y explica que el receptor debe seleccionar o depositar el `.arcadelink` en FederationCloud.

El `.arcadelink` conserva exactamente el mismo contrato criptográfico: firma Ed25519, payload privado XChaCha20-Poly1305, `resource_id`, nodo origen, visibilidad y derechos. El ZIP no contiene credenciales AWS, cookies, sesiones, claves privadas ni una URL S3 permanente.

## Compartir varios archivos desde el Drive

El bloque de archivos del Drive permite seleccionar uno o varios recursos y usar la acción **Compartir ArcadeLink**. La acción abre el mismo panel de políticas utilizado por el compartir individual para elegir:

- `visibility`;
- `rights`;
- `discovery_policy`.

Los valores iniciales siguen siendo conservadores (`UNLISTED`, `link_only` y descubrimiento automático), pero la selección masiva ya no fija esas políticas en código: el usuario puede modificarlas antes de generar el paquete.

La interfaz envía únicamente las referencias seleccionadas y las políticas elegidas al endpoint autenticado `federationcloud/bundle.php`. El backend valida cada referencia contra el `user_id` autenticado mediante `FederationService::createLinkByStorageRef()` y genera un ArcadeLink individual por recurso.

Ejemplo:

```text
ArcadeLinks-portables.zip
├── Abrir-FederationCloud.html
├── audiencia-1.mp4.arcadelink
├── audiencia-2.mp4.arcadelink
├── sentencia.pdf.arcadelink
└── pruebas.zip.arcadelink
```

El ZIP contiene exactamente una copia de `Abrir-FederationCloud.html`. Si dos recursos producen el mismo nombre, el empaquetador añade un sufijo numérico (`-2`, `-3`, ...) para impedir que un ArcadeLink sobrescriba a otro.

El paquete se crea temporalmente en el servidor y se elimina después de enviarlo al navegador. No se almacena en S3 y no incluye los archivos físicos originales, por lo que los videos, PDFs u otros objetos grandes no atraviesan PHP durante esta operación; sólo viajan los documentos `.arcadelink` y el HTML portable.

## Flujo de usuario

Archivo individual:

```text
Drive
  -> Compartir
  -> ArcadeLink FederationCloud
  -> elegir visibilidad / descubrimiento / derechos
  -> Descargar ArcadeLink ZIP
```

Selección múltiple:

```text
Drive
  -> seleccionar archivos
  -> Compartir ArcadeLink
  -> elegir visibilidad / descubrimiento / derechos
  -> ArcadeLinks-portables.zip
```

Receptor:

```text
descomprimir ZIP
  -> abrir Abrir-FederationCloud.html
  -> abrir FederationCloud en el navegador
  -> seleccionar o depositar el .arcadelink deseado
  -> validar firma
  -> resolver / solicitar acceso / abrir según política
```

El archivo real continúa fuera del ZIP. FederationCloud resuelve el recurso mediante su ArcadeLink y aplica las políticas de acceso existentes.

## Actividad y costos

La creación de ArcadeLinks continúa registrándose mediante `DriveActivityEvents`/`ActivityCostRecorder` con la clasificación `drive.no_direct_aws_charge`. Crear y empaquetar documentos ArcadeLink no se registra como una transferencia S3 real. Las operaciones que sí leen o transfieren objetos de S3 conservan sus métricas existentes.

## Descarga directa de archivos

La inspección del código actual confirmó que `descargar_archivo.php` ya era el endpoint normal de descarga directa y reutiliza `FileAccessController::signedDownload()`.

El flujo es:

```text
navegador
  -> descargar_archivo.php
  -> validar sesión y user_id
  -> localizar FileS3 en MySQL
  -> reconstruir y validar la key real
  -> generar URL GET prefirmada de S3 (10 minutos)
  -> HTTP redirect
  -> S3
  -> navegador
```

Por tanto, los bytes del archivo individual no atraviesan PHP-FPM ni Nginx del Drive. `FileAccessService::signedDownload()` usa `createPresignedRequest()` y no llama a `getObject()` para esa descarga.

`download.php` permanece únicamente como alias delgado de compatibilidad y delega en el mismo `FileAccessController::signedDownload()`; no contiene una segunda implementación ni una segunda fuente de verdad. El endpoint canónico existente para el Drive sigue siendo `descargar_archivo.php`.

Los ZIP de descarga de múltiples archivos físicos son una operación diferente porque sí deben construir un contenedor con los objetos seleccionados; eso no debe confundirse con el ZIP portable de ArcadeLinks, que sólo contiene metadatos firmados y el HTML launcher.
