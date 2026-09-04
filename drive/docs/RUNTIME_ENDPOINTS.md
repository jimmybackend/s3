# Mapa de runtime y endpoints

> Generado por análisis estático de referencias. Un archivo sin referencias puede seguir siendo una URL externa conocida; antes de eliminar se valida manualmente.

## Entradas consideradas

- `drive/aws.php`
- `drive/ec2.php`
- `drive/index.php`
- `drive/login.php`
- `drive/logout.php`
- `drive/s3.php`
- `drive/up.php`

## PHP alcanzables desde el runtime principal

| Archivo | Referenciado por |
|---|---|
| `drive/S3Manager.php` | `drive/descargar_archivo.php`, `drive/download_multiple.php`, `drive/generar_token.php`, `drive/get_playlist.php`, `drive/guardar_texto.php`, `drive/leer_texto.php`, `drive/listar_carpetas.php`, `drive/polly_tts.php`, `drive/src/Core/DriveApplication.php`, `drive/subir_archivo.php`, `drive/sync_s3_to_db.php`, `drive/sync_status.php`, `drive/token_audio.php`, `drive/token_texto.php`, `drive/token_video.php`, `drive/traducir_archivo.php`, `drive/transcribir_estado.php`, `drive/ver.php`, `drive/ver_archivo.php` |
| `drive/api/upload.php` | `drive/s3.php`, `drive/js/soportesMediaTypes.js`, `drive/js/subir-chunked.js`, `drive/js/subir-dropzone.js`, `drive/js/subir.js` |
| `drive/app_bootstrap.php` | `drive/S3Manager.php`, `drive/actualizar_ruta.php`, `drive/api/upload.php`, `drive/api.php`, `drive/aws.php`, `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/buscar_archivo.php`, `drive/comprehend_archivo.php`, `drive/costos_aws.php`, `drive/crear_carpeta.php`, `drive/delete_multiple.php`, `drive/descargar.php`, `drive/descargar_archivo.php`, `drive/descargar_zip.php`, `drive/download_multiple.php`, `drive/ec2-cron-old.php`, `drive/ec2-cron.php`, `drive/ec2-db-stop.php`, `drive/ec2-db.php`, `drive/ec2.php`, `drive/ec22.php`, `drive/eliminar_archivo.php`, `drive/eliminar_carpeta.php`, `drive/encriptar_archivo.php`, `drive/firma.php`, `drive/firmado.php`, `drive/firmadowww.php`, `drive/fixfiles.php`, `drive/generar_galeria.php`, `drive/generar_token.php`, `drive/get_playlist.php`, `drive/guardar_texto.php`, `drive/leer_texto.php`, `drive/listar_archivos.php`, `drive/listar_carpetas.php`, `drive/migrar_nombres_s3.php`, `drive/move_multiple.php`, `drive/mover_archivo.php`, `drive/mover_carpeta.php`, `drive/polly_cargar_texto.php`, `drive/polly_list_voices.php`, `drive/polly_tts.php`, `drive/procesar_textract.php`, `drive/psesion.php`, `drive/rekognition_labels.php`, `drive/relock_file.php`, `drive/renombrar_archivo.php`, `drive/renombrar_carpeta.php`, `drive/s3.php`, `drive/set_file_security.php`, `drive/storage_usage.php`, `drive/subir_archivo.php`, `drive/subir_publico.php`, `drive/sync_s3_to_db.php`, `drive/sync_status.php`, `drive/thumb.php`, `drive/token_audio.php`, `drive/token_texto.php`, `drive/token_video.php`, `drive/traducir_archivo.php`, `drive/transcribir_estado.php`, `drive/transcribir_iniciar.php`, `drive/unlock_file.php`, `drive/up-clean.php`, `drive/up-old.php`, `drive/up.php`, `drive/up2.php`, `drive/upload/drivers/Chunked15MBUploader.php`, `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php`, `drive/upload.php`, `drive/upload_audio_recording.php`, `drive/upload_publico.php`, `drive/ver.php`, `drive/ver_archivo.php`, `drive/ver_pdf.php` |
| `drive/aws.php` | `drive/s3.php` |
| `drive/bloque_archivos.php` | `drive/s3.php`, `drive/js/archivos.js`, `drive/js/carpetas.js`, `drive/js/elimina-multiple.js`, `drive/js/elimina-uno.js`, `drive/js/imagenes.js`, `drive/js/obtenerFiltros.js` |
| `drive/bloque_carpetas.php` | `drive/s3.php`, `drive/js/carpetas.js`, `drive/js/obtenerFiltros.js` |
| `drive/bloque_footer.php` | `drive/s3.php` |
| `drive/costos_aws.php` | `drive/s3.php` |
| `drive/delete_multiple.php` | `drive/api.php`, `drive/s3.php`, `drive/js/elimina-multiple.js`, `drive/js/file-block.js` |
| `drive/descargar_archivo.php` | `drive/bloque_archivos.php` |
| `drive/ec2.php` | `drive/ec22.php`, `drive/s3.php` |
| `drive/editor.php` | `drive/bloque_archivos.php`, `drive/js/editar-txt.js` |
| `drive/guardar_texto.php` | `drive/api.php`, `drive/editor.php` |
| `drive/index.php` | `drive/aws.php`, `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/buscar_archivo.php`, `drive/comprehend_archivo.php`, `drive/ec2.php`, `drive/logout.php`, `drive/psesion.php`, `drive/rekognition_labels.php`, `drive/s3.php`, `drive/src/Security/SessionManager.php`, `drive/sync_s3_to_db.php` |
| `drive/leer_texto.php` | `drive/editor.php` |
| `drive/login.php` | entrada directa |
| `drive/logout.php` | `drive/s3.php` |
| `drive/psesion.php` | `drive/index.php`, `drive/login.php` |
| `drive/s3.php` | `drive/app_bootstrap.php`, `drive/descargar_archivo.php`, `drive/psesion.php`, `drive/js/archivos.js`, `drive/js/carpetas.js`, `drive/js/subir.js` |
| `drive/thumb.php` | `drive/bloque_archivos.php`, `drive/generar_galeria.php`, `drive/js/imagenes.js` |
| `drive/up.php` | `drive/up-old.php`, `drive/up2.php` |
| `drive/upload/UploadFactory.php` | `drive/api/upload.php` |
| `drive/upload/core/UploadResponse.php` | `drive/api/upload.php` |
| `drive/upload/core/UploaderInterface.php` | `drive/api/upload.php`, `drive/upload/UploadFactory.php`, `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/LocalPresignedPutUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php` |
| `drive/upload/drivers/Chunked15MBUploader.php` | `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/DropboxUploader.php` | `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/LocalPresignedPutUploader.php` | `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/RemoteUrlUploader.php` | `drive/upload/UploadFactory.php` |
| `drive/upload/repositories/FileS3Repository.php` | `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/LocalPresignedPutUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php` |
| `drive/upload/storage/UploadStateStore.php` | `drive/upload/drivers/Chunked15MBUploader.php` |
| `drive/upload.php` | `drive/s3.php`, `drive/js/soportesMediaTypes.js`, `drive/js/subir-chunked.js`, `drive/js/subir-dropzone.js`, `drive/js/subir.js` |
| `drive/validar_php.php` | `drive/editor.php` |
| `drive/ver_archivo.php` | `drive/bloque_archivos.php`, `drive/generar_galeria.php`, `drive/js/archivos.js`, `drive/js/imagenes.js` |

## PHP no alcanzables por referencias internas

| Archivo | Referencias encontradas |
|---|---|
| `drive/actualizar_ruta.php` | `drive/js/carpetas.js`, `drive/js/obtenerFiltros.js` |
| `drive/api.php` | ninguna |
| `drive/buscar_archivo.php` | `drive/api.php`, `drive/js/archivos.js` |
| `drive/comprehend_archivo.php` | `drive/js/aws-comprehend.js` |
| `drive/crear_carpeta.php` | `drive/js/carpetas.js` |
| `drive/descargar.php` | `drive/js/descarga-uno.js` |
| `drive/descargar_zip.php` | `drive/js/descarga-multiple.js`, `drive/js/file-block.js` |
| `drive/download_multiple.php` | ninguna |
| `drive/ec2-cron-old.php` | ninguna |
| `drive/ec2-cron.php` | ninguna |
| `drive/ec2-db-stop.php` | ninguna |
| `drive/ec2-db.php` | ninguna |
| `drive/ec22.php` | ninguna |
| `drive/eliminar_archivo.php` | `drive/js/archivos.js`, `drive/js/elimina-uno.js` |
| `drive/eliminar_carpeta.php` | `drive/js/carpetas.js` |
| `drive/encriptar_archivo.php` | `drive/js/archivos.js` |
| `drive/firma.php` | ninguna |
| `drive/firmado.php` | ninguna |
| `drive/firmadowww.php` | ninguna |
| `drive/fixfiles.php` | ninguna |
| `drive/generar_galeria.php` | `drive/api.php`, `drive/js/imagenes.js` |
| `drive/generar_token.php` | `drive/api.php`, `drive/js/archivos.js` |
| `drive/get_playlist.php` | ninguna |
| `drive/listar_archivos.php` | ninguna |
| `drive/listar_carpetas.php` | `drive/js/carpetas.js` |
| `drive/migrar_nombres_s3.php` | ninguna |
| `drive/move_multiple.php` | ninguna |
| `drive/mover_archivo.php` | `drive/js/archivos.js` |
| `drive/mover_carpeta.php` | `drive/api.php`, `drive/js/carpetas.js` |
| `drive/polly_cargar_texto.php` | `drive/js/polly.js` |
| `drive/polly_list_voices.php` | `drive/js/polly.js` |
| `drive/polly_tts.php` | `drive/js/polly.js` |
| `drive/procesar_textract.php` | `drive/js/polly.js` |
| `drive/rekognition_labels.php` | `drive/js/polly.js` |
| `drive/relock_file.php` | `drive/js/archivos.js` |
| `drive/renombrar_archivo.php` | `drive/api.php`, `drive/js/archivos.js` |
| `drive/renombrar_carpeta.php` | `drive/js/carpetas.js` |
| `drive/set_file_security.php` | `drive/js/archivos.js` |
| `drive/src/Application/DrivePageService.php` | ninguna |
| `drive/src/Application/DrivePageViewModel.php` | ninguna |
| `drive/src/Application/FileListService.php` | ninguna |
| `drive/src/Application/FileSearchService.php` | ninguna |
| `drive/src/Application/UploadDestinationService.php` | ninguna |
| `drive/src/Aws/ComprehendFileService.php` | ninguna |
| `drive/src/Aws/FileRecordLocator.php` | ninguna |
| `drive/src/Core/ApplicationKernel.php` | ninguna |
| `drive/src/Core/DriveApplication.php` | ninguna |
| `drive/src/Http/Controller/AbstractJsonController.php` | ninguna |
| `drive/src/Http/Controller/FileMutationController.php` | ninguna |
| `drive/src/Http/Controller/FileSecurityController.php` | ninguna |
| `drive/src/Http/Controller/FolderMutationController.php` | ninguna |
| `drive/src/Http/JsonResponse.php` | ninguna |
| `drive/src/Http/Request.php` | ninguna |
| `drive/src/Media/ThumbnailService.php` | ninguna |
| `drive/src/Security/FileSecurityRepository.php` | ninguna |
| `drive/src/Security/FileSecurityService.php` | ninguna |
| `drive/src/Security/SessionManager.php` | ninguna |
| `drive/src/Storage/StorageUsageService.php` | ninguna |
| `drive/src/Storage/UserStoragePath.php` | ninguna |
| `drive/src/Upload/PublicMultipartUploadService.php` | ninguna |
| `drive/src/View/FileIconResolver.php` | ninguna |
| `drive/src/View/FileViewHelper.php` | ninguna |
| `drive/src/View/FolderTreeRenderer.php` | ninguna |
| `drive/storage_usage.php` | `drive/js/storage-usage.js` |
| `drive/subir_archivo.php` | ninguna |
| `drive/subir_publico.php` | ninguna |
| `drive/sync_s3_to_db.php` | `drive/js/sincronizar.js` |
| `drive/sync_status.php` | ninguna |
| `drive/token_audio.php` | `drive/api.php`, `drive/generar_token.php` |
| `drive/token_texto.php` | `drive/api.php`, `drive/generar_token.php` |
| `drive/token_video.php` | `drive/api.php`, `drive/generar_token.php`, `drive/js/audiovideo.js` |
| `drive/traducir_archivo.php` | `drive/js/polly.js` |
| `drive/transcribir_estado.php` | `drive/js/polly.js` |
| `drive/transcribir_iniciar.php` | `drive/js/polly.js` |
| `drive/unlock_file.php` | `drive/js/archivos.js` |
| `drive/up-clean.php` | ninguna |
| `drive/up-old.php` | ninguna |
| `drive/up2.php` | ninguna |
| `drive/upload_audio_recording.php` | ninguna |
| `drive/upload_publico.php` | `drive/subir_publico.php` |
| `drive/ver.php` | `drive/api.php` |
| `drive/ver_pdf.php` | `drive/api.php`, `drive/js/ver-pdf.js` |
