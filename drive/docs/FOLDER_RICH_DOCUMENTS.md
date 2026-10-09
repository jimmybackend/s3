# Crear documentos desde una carpeta

## Objetivo

Cada carpeta real de ArcadeCloud Drive expone un menú de acciones mediante el botón de tres puntos (`⋮`). Desde ese menú se puede crear un documento pegando texto o contenido con formato, sin subir primero un archivo desde el dispositivo.

La acción vive en la carpeta porque el documento todavía no existe cuando el usuario inicia el flujo. Una vez creado, el archivo aparece como cualquier otro elemento de `FileS3` y las acciones normales de archivos se aplican sobre él.

## Menú de carpeta

Las carpetas muestran un único botón de acciones. El menú reúne:

- Crear documento desde texto.
- Nueva subcarpeta.
- Sincronizar desde S3.
- Mover, renombrar y eliminar cuando no se trata de la raíz del usuario.

La raíz también usa el mismo menú, pero no ofrece operaciones que no son válidas sobre la raíz.

## Nuevo archivo vacío en ArcadeCloud OS

En `so.php`, el menú `⋮` de una carpeta (incluida la carpeta actual) ofrece ahora dos opciones independientes:

- **Nuevo archivo de texto:** abre un formulario que solicita nombre y tipo `.txt`, `.md` o `.html`. La extensión se añade automáticamente y el archivo se guarda en esa carpeta, sin requerir contenido previo. TXT y MD se crean con cero bytes; HTML incluye únicamente su estructura básica.
- **Crear desde texto pegado:** conserva el editor con formato y el flujo de portapapeles existentes; continúa rechazando contenido vacío.

Ambos flujos usan `create_folder_document.php` con sesión y CSRF. El modo vacío envía `create_empty=1`, valida `UserStoragePath`, comprueba en `FileS3` que no exista el mismo nombre visible en esa carpeta y reutiliza `SingleUploadService` para S3 y catálogo. El evento `drive:folder-document-created` actualiza únicamente los exploradores de la ruta afectada.

El editor Monaco `editor.php` incorpora en pantallas táctiles `Marcar inicio`, `Marcar fin`, `Todo`, `Copiar`, `Cortar` y `Pegar`. Si el navegador no permite leer el portapapeles, ofrece un campo para pegar mediante el menú nativo del móvil e insertar el contenido en la selección. No se modifica la API de guardado de texto.

## Formatos

La opción recomendada para copiar una respuesta o prompt de ChatGPT conservando su estructura es:

- **HTML (`.html`)**: conserva títulos, negritas, cursivas, listas, tablas, enlaces, bloques de código y párrafos.
- **Markdown (`.md`)**: guarda texto editable. Si el portapapeles aporta sintaxis Markdown en `text/plain`, se conserva.
- **Texto (`.txt`)**: guarda únicamente texto plano.

El formato predeterminado es HTML porque es el que mejor conserva el formato rico que entrega el portapapeles del navegador.

## Seguridad del HTML pegado

El HTML del portapapeles nunca se guarda directamente.

Hay dos capas de saneamiento:

1. `drive/js/folder-document.js` limpia el contenido antes de mostrarlo en el editor.
2. `FolderDocumentService` vuelve a sanear en el servidor antes de escribir S3.

Se permiten únicamente elementos semánticos de texto, listas, tablas, código y enlaces. Se eliminan, entre otros:

- `script`, `style`, `iframe`, `object`, `embed`, `svg`, formularios y contenido multimedia;
- atributos `on*`, `style`, `class` y demás atributos no necesarios;
- enlaces con esquemas inseguros como `javascript:`.

Los enlaces válidos se limitan a HTTP, HTTPS, mailto y anclas internas.

## Persistencia

El endpoint es:

```text
drive/create_folder_document.php
  -> FolderDocumentController
     -> FolderDocumentService
        -> SingleUploadService
           -> S3
           -> UploadCatalogRepository
              -> FileS3
```

`SingleUploadService` se reutiliza para mantener el mismo modelo de subida del Drive. No se agregó una tabla ni una ruta paralela de almacenamiento.

La carpeta destino se normaliza con `UserStoragePath` y, salvo la raíz, debe existir como fila activa de `S3Folders` para el `user_id_` autenticado. El navegador no puede elegir la raíz de otro usuario.

## Costos

Crear el documento registra la misma unidad observable que una subida simple:

- una solicitud S3 PUT;
- bytes agregados al almacenamiento.

Se registra mediante `ActivityCostRecorder` con `Action=create_document` y `Service=S3`.

## Límites

- tamaño máximo del documento generado: 2 MB;
- nombre visible máximo: 180 caracteres;
- formatos admitidos: `html`, `md`, `txt`;
- caracteres de ruta y nombre peligrosos se rechazan.

## Archivos principales

```text
drive/bloque_carpetas.php
drive/src/View/FolderTreeRenderer.php
drive/js/folder-document.js
drive/create_folder_document.php
drive/src/Http/Controller/FolderDocumentController.php
drive/src/Application/FolderDocumentService.php
drive/tests/folder_document_sanitizer.php
```

No requiere migración SQL.
