# ArcadeLink portable ZIP

Al compartir un recurso desde ArcadeCloud Drive, FederationCloud entrega un paquete ZIP portable en lugar de descargar el `.arcadelink` aislado.

Para un recurso, el ZIP contiene exactamente:

```text
ArcadeLink-portable.zip
├── abrir-federtioncloud.html
└── <recurso>.arcadelink
```

`abrir-federtioncloud.html` es un archivo HTML estándar y por eso puede abrirse con el navegador predeterminado en Windows, Linux o macOS. El HTML apunta a la `federation_url` firmada del nodo que emitió el ArcadeLink y explica que el receptor debe seleccionar o depositar el `.arcadelink` en FederationCloud.

El `.arcadelink` conserva exactamente el mismo contrato criptográfico: firma Ed25519, payload privado XChaCha20-Poly1305, `resource_id`, nodo origen, visibilidad y derechos. El ZIP no contiene credenciales AWS, cookies, sesiones, claves privadas ni una URL S3 permanente.

## Compartir varios archivos desde el Drive

El bloque de archivos del Drive permite seleccionar uno o varios recursos y usar la acción **Compartir ArcadeLink**. La interfaz envía únicamente las referencias seleccionadas al endpoint autenticado `federationcloud/bundle.php`.

El backend valida cada referencia contra el usuario autenticado, genera un ArcadeLink individual por recurso y construye un solo ZIP. La acción masiva usa por defecto la política conservadora `UNLISTED + link_only` y no publica una URL permanente de S3.

Ejemplo:

```text
ArcadeLinks-portables.zip
├── abrir-federtioncloud.html
├── audiencia-1.mp4.arcadelink
├── audiencia-2.mp4.arcadelink
└── sentencia.pdf.arcadelink
```

Si dos recursos producen el mismo nombre de archivo, el empaquetador añade un sufijo numérico para impedir que uno sobrescriba al otro dentro del ZIP.

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
  -> ArcadeLinks-portables.zip
```

Receptor:

```text
descomprimir ZIP
  -> abrir abrir-federtioncloud.html
  -> abrir FederationCloud en el navegador
  -> seleccionar o depositar el .arcadelink deseado
  -> validar firma
  -> resolver / solicitar acceso / abrir según política
```

El archivo real continúa fuera del ZIP. FederationCloud resuelve el recurso mediante su ArcadeLink y aplica las políticas de acceso existentes.

## Descarga directa de archivos

`download.php` es un endpoint delgado que reutiliza `FileAccessController::signedDownload()`. PHP valida la sesión y la propiedad del archivo y genera una URL GET prefirmada temporal de S3; después responde con una redirección.

Por tanto, los bytes del archivo individual no atraviesan PHP-FPM ni Nginx del Drive:

```text
navegador
  -> download.php
  -> autorización + URL prefirmada
  -> redirección temporal
  -> S3
  -> navegador
```

`descargar_archivo.php` conserva el mismo comportamiento de descarga firmada por compatibilidad. Los ZIP de descarga de varios archivos siguen siendo una operación distinta porque deben construir un contenedor ZIP con los bytes seleccionados.
