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
