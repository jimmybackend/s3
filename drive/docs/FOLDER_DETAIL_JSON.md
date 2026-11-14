# Catálogo detalle.json por carpeta

En **Mis datos** (ArcadeCloud Web OS), clic derecho sobre una carpeta en escritorio, o toque sobre ella en móvil/tableta, abre acciones. **Detalles de la carpeta** muestra el botón **Crear o actualizar detalle.json**. Se conserva **Abrir** para entrar desde el menú táctil.

El endpoint autenticado `generate_folder_detail.php` requiere POST y token CSRF. `FolderDetailCatalogService` consulta únicamente `FileS3` con `user_id_`, `Ruta` exacta y `Found=1`, sin paginación (lista completa de archivos directos), y verifica el directorio contra `S3Folders`. Genera ruta legible a partir de nombres de las carpetas, no de segmentos físicos. Nunca incluye `Encriptado` ni claves S3.

Formato: `formato`, `carpeta`, `ruta_visible`, `fecha_actualizacion`, `orden`, `total_archivos`, `archivos` (cada uno con `nombre`, `tipo`, `extension` y `tamano_bytes`). Orden natural por nombre y luego tipo. No incluye carpetas ni el propio `detalle.json`.

Si no existe, se crea usando la subida normal a S3 y el catálogo MySQL. Si existe uno generado por esta función, se actualiza en su mismo objeto S3 y su registro DB. Si existe un `detalle.json` ajeno o duplicado, se bloquea para evitar destrucción de información. No se introducen tablas ni migraciones SQL.

**Verificación pendiente en producción:** comprobar una carpeta vacía, otra con archivos y subcarpetas, una con nombres especiales, actualización posterior a agregar un archivo y el manejo de permisos en EC2 real. La integración GitHub no ejecuta esos casos contra la base de producción.