# Crear documento desde una carpeta

## Objetivo

Cada carpeta real del Drive tiene un menú de acciones `⋮`. Desde ese menú se puede elegir **Crear documento desde texto** para pegar una respuesta, prompt o texto formateado y guardarlo directamente dentro de esa carpeta.

La acción pertenece a la carpeta porque el documento todavía no existe. Una vez creado, aparece como un archivo normal y pasa a ser administrado por `bloque_archivos.php` y las operaciones existentes de archivos.

## Formatos

- **HTML (.html)**: opción predeterminada. Conserva estructura semántica del contenido pegado: títulos, negritas, cursivas, listas, tablas, enlaces, citas y bloques de código.
- **Markdown (.md)**: conserva el texto editable recibido por el portapapeles. Es útil cuando el origen ya contiene sintaxis Markdown.
- **Texto (.txt)**: guarda únicamente texto plano.

No se copian los estilos visuales propios de ChatGPT o de la página de origen. El HTML final usa estilos simples propios del documento.

## Flujo

```text
carpeta
  -> menú ⋮
  -> Crear documento desde texto
  -> pegar / editar contenido
  -> elegir nombre + HTML/MD/TXT
  -> create_folder_document.php
  -> FolderDocumentController
  -> FolderDocumentService
  -> SingleUploadService
  -> S3 + FileS3
  -> aparece como archivo normal
```

MySQL sigue siendo la fuente de verdad para comprobar que la carpeta pertenece al usuario autenticado y está activa. La ruta se normaliza con `UserStoragePath`, de modo que un usuario no puede guardar en `Data/` o `DataN/` de otro usuario.

No se añadió ninguna tabla ni migración SQL.

## Seguridad del contenido HTML

El HTML del portapapeles se limpia tanto en el navegador como nuevamente en el servidor. Se eliminan elementos ejecutables o embebidos como `script`, `iframe`, `object`, `embed`, formularios, multimedia y estilos del origen. También se eliminan atributos no permitidos, handlers `onclick` y URLs con esquemas peligrosos como `javascript:`.

La lista permitida conserva sólo estructura documental. El servidor es la autoridad final aunque el cliente sea manipulado.

## Costos

La creación reutiliza `SingleUploadService`, por lo que físicamente realiza un `PUT` a S3 y registra el archivo en `FileS3`. La acción se registra mediante `ActivityCostRecorder` como `create_document` con las unidades S3 observables y los bytes escritos.

## Archivos principales

```text
drive/bloque_carpetas.php
drive/create_folder_document.php
drive/js/folder-document.js
drive/src/Application/FolderDocumentService.php
drive/src/Http/Controller/FolderDocumentController.php
drive/src/View/FolderTreeRenderer.php
drive/tests/folder_document_sanitizer.php
```

## Prueba manual

1. Abrir el menú `⋮` de una carpeta.
2. Elegir **Crear documento desde texto**.
3. Copiar una respuesta de ChatGPT con títulos, negritas, lista y bloque de código.
4. Usar **Pegar desde portapapeles** o pegar directamente dentro del editor.
5. Guardar como HTML.
6. Confirmar que aparece un solo archivo en esa misma carpeta y que su estructura visual se conserva al abrirlo.
7. Repetir como `.md` y `.txt` para verificar el comportamiento de texto editable/plano.
8. Probar desde un segundo usuario y confirmar que sólo puede elegir carpetas de su propia raíz.
