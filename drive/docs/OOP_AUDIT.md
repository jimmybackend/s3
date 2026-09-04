# Auditoría OOP — ArcadeCloud Drive

> Generado automáticamente. No sustituye pruebas funcionales; detecta estructura y dependencias procedurales.

## Resumen

- PHP analizados: **107**
- PHP que ya contienen clases/interfaces: **30**
- PHP marcados para migración/revisión: **42**
- JavaScript analizados: **27**
- JavaScript que ya contienen clases: **0**
- JavaScript marcados para migración/revisión: **27**

## Criterio

- `src/` y `upload/`: lógica de negocio e infraestructura en clases.
- Entry points públicos: bootstrap + Controller/Service; sin SQL/AWS ni funciones globales.
- Vistas: pueden contener HTML, pero no deben crear clientes AWS/DB ni declarar funciones globales.
- JavaScript: comportamiento en clases; `window` solo para una fachada de compatibilidad explícita.

## PHP

| Archivo | Líneas | Tipo detectado | Clases | Sesión | DB | AWS/S3 | Observaciones |
|---|---:|---|---:|:---:|:---:|:---:|---|
| `drive/S3Manager.php` | 2052 | class/module | 1 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/actualizar_ruta.php` | 28 | thin endpoint | 0 | ⚠️ | — | — | — |
| `drive/api/upload.php` | 73 | thin endpoint | 0 | — | — | — | — |
| `drive/api.php` | 61 | thin endpoint | 0 | ⚠️ | — | — | — |
| `drive/app_bootstrap.php` | 73 | procedural endpoint | 0 | — | ⚠️ | — | global functions: drive_app; DB in endpoint |
| `drive/aws.php` | 392 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/bloque_archivos.php` | 858 | view/entrypoint | 0 | ⚠️ | — | — | global functions: initTooltipsBloqueArchivos, initContextoBloqueArchivos, getLimiteActualPaginacion, manejarClickPaginacionBloqueArchivos, initPaginacionBloqueArchivos, initLoaderBloqueArchivos, getSeleccionadosBloqueArchivos |
| `drive/bloque_carpetas.php` | 69 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/bloque_footer.php` | 32 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/buscar_archivo.php` | 51 | thin endpoint | 0 | — | — | — | — |
| `drive/comprehend_archivo.php` | 47 | thin endpoint | 0 | — | — | — | — |
| `drive/costos_aws.php` | 260 | endpoint with logic | 0 | ⚠️ | — | — | session in endpoint |
| `drive/crear_carpeta.php` | 44 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/delete_multiple.php` | 60 | thin endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/descargar.php` | 127 | endpoint with logic | 0 | ⚠️ | ⚠️ | ⚠️ | DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/descargar_archivo.php` | 155 | view/entrypoint | 0 | ⚠️ | ⚠️ | ⚠️ | global functions: security_resolve_user_id, security_normalize_key, security_lookup_file, security_session_is_unlocked, render_blocked_page |
| `drive/descargar_zip.php` | 154 | endpoint with logic | 0 | ⚠️ | ⚠️ | ⚠️ | DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/download_multiple.php` | 33 | thin endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/ec2-cron-old.php` | 404 | procedural endpoint | 0 | — | — | — | global functions: logLine, stateName, isRunningLike, isStoppedLike, isPriority, secondaryMustBeOffNow, shouldForceNow, listAllInstances |
| `drive/ec2-cron.php` | 405 | procedural endpoint | 0 | — | — | — | global functions: logLine, stateName, isRunningLike, isStoppedLike, isPriority, secondaryMustBeOffNow, shouldForceNow, listAllInstances |
| `drive/ec2-db-stop.php` | 321 | procedural endpoint | 0 | — | — | — | global functions: logLine, outLine, dbInstanceStatus, dbClusterStatus, isDatabaseStoppable, listAllDbInstances, listAllDbClusters, findDatabaseTarget |
| `drive/ec2-db.php` | 676 | procedural endpoint | 0 | — | — | — | global functions: logLine, stateName, isRunningLike, isStoppedLike, isPriority, secondaryMustBeOffNow, mayBeOnNow, shouldForceNow |
| `drive/ec2.php` | 1233 | class/module | 2 | ⚠️ | — | — | global functions: getTag, e, is_protected_id, is_manual_database_id, e, is_protected_id, is_manual_database_id, database_status_from_target |
| `drive/ec22.php` | 1371 | class/module | 2 | ⚠️ | — | — | global functions: actualizar_estado_servidor_palabra, sincronizar_estado_palabra, getTag, e, is_protected_id, is_manual_database_id, e, is_protected_id |
| `drive/editor.php` | 485 | view/entrypoint | 0 | — | — | — | global functions: detectarLenguaje, setStatus, limpiarMarcadores, marcarGuardado, actualizarBotonValidar, aplicarLenguaje, poblarLenguajes, deshacer |
| `drive/eliminar_archivo.php` | 53 | thin endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/eliminar_carpeta.php` | 36 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/encriptar_archivo.php` | 102 | endpoint with logic | 0 | ⚠️ | ⚠️ | ⚠️ | DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/firma.php` | 108 | endpoint with logic | 0 | ⚠️ | ⚠️ | ⚠️ | DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/firmado.php` | 83 | thin endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/firmadowww.php` | 438 | view/entrypoint | 0 | ⚠️ | ⚠️ | ⚠️ | global functions: respond, driveIdFromUrl, driveNormalizeToUc, driveConfirmedUrlFromHtml, isHtmlContentType, http_get_with_headers, http_head_or_null, extraerFilenameDeContentDisposition |
| `drive/fixfiles.php` | 759 | class/module | 1 | — | ⚠️ | ⚠️ | — |
| `drive/generar_galeria.php` | 79 | thin endpoint | 0 | ⚠️ | ⚠️ | — | — |
| `drive/generar_token.php` | 108 | procedural endpoint | 0 | — | — | — | global functions: jserr |
| `drive/get_playlist.php` | 89 | thin endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/guardar_texto.php` | 51 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/index.php` | 87 | view/entrypoint | 0 | — | — | — | — |
| `drive/leer_texto.php` | 42 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/listar_archivos.php` | 35 | view/entrypoint | 0 | — | — | ⚠️ | — |
| `drive/listar_carpetas.php` | 27 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/login.php` | 92 | view/entrypoint | 0 | — | — | — | — |
| `drive/logout.php` | 6 | procedural endpoint | 0 | ⚠️ | — | — | session in endpoint |
| `drive/migrar_nombres_s3.php` | 58 | thin endpoint | 0 | — | ⚠️ | ⚠️ | — |
| `drive/move_multiple.php` | 68 | thin endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/mover_archivo.php` | 114 | endpoint with logic | 0 | ⚠️ | ⚠️ | ⚠️ | DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/mover_carpeta.php` | 42 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/polly_cargar_texto.php` | 53 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/polly_list_voices.php` | 48 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_tts.php` | 376 | endpoint with logic | 0 | ⚠️ | ⚠️ | ⚠️ | DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/procesar_textract.php` | 74 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/psesion.php` | 112 | endpoint with logic | 0 | ⚠️ | ⚠️ | — | DB in endpoint; session in endpoint |
| `drive/rekognition_labels.php` | 131 | endpoint with logic | 0 | — | ⚠️ | — | DB in endpoint |
| `drive/relock_file.php` | 288 | procedural endpoint | 0 | ⚠️ | ⚠️ | — | global functions: json_out, resolve_relock_user_id, normalize_relock_key, split_relock_key, clear_relock_session_keys; DB in endpoint; session in endpoint |
| `drive/renombrar_archivo.php` | 52 | thin endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/renombrar_carpeta.php` | 42 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/s3.php` | 1983 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/set_file_security.php` | 359 | procedural endpoint | 0 | ⚠️ | ⚠️ | — | global functions: json_out, normalize_key, split_key_parts, clear_secure_session_keys; DB in endpoint; session in endpoint |
| `drive/src/Application/DrivePageService.php` | 40 | class/module | 1 | — | — | — | — |
| `drive/src/Application/DrivePageViewModel.php` | 18 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileListService.php` | 155 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FileSearchService.php` | 104 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/UploadDestinationService.php` | 56 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Aws/ComprehendFileService.php` | 223 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Aws/FileRecordLocator.php` | 75 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Core/DriveApplication.php` | 106 | class/module | 1 | — | — | ⚠️ | — |
| `drive/src/Http/JsonResponse.php` | 27 | class/module | 1 | — | — | — | — |
| `drive/src/Media/ThumbnailService.php` | 380 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/SessionManager.php` | 40 | class/module | 1 | ⚠️ | — | — | — |
| `drive/src/Storage/StorageUsageService.php` | 78 | class/module | 1 | ⚠️ | ⚠️ | — | — |
| `drive/src/Storage/UserStoragePath.php` | 50 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicMultipartUploadService.php` | 233 | class/module | 1 | — | — | — | — |
| `drive/src/View/FileIconResolver.php` | 136 | class/module | 1 | — | — | — | — |
| `drive/src/View/FileViewHelper.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/View/FolderTreeRenderer.php` | 123 | class/module | 1 | — | ⚠️ | — | — |
| `drive/storage_usage.php` | 24 | thin endpoint | 0 | — | — | — | — |
| `drive/subir_archivo.php` | 140 | endpoint with logic | 0 | ⚠️ | ⚠️ | ⚠️ | DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/subir_publico.php` | 157 | view/entrypoint | 0 | — | ⚠️ | ⚠️ | — |
| `drive/sync_s3_to_db.php` | 379 | procedural endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | global functions: db_exec, db_fetch_one, db_fetch_all, db_like_escape, add_folder, upsert_folder, upsert_file; DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/sync_status.php` | 91 | view/entrypoint | 0 | ⚠️ | ⚠️ | — | global functions: qscalar |
| `drive/thumb.php` | 78 | procedural endpoint | 0 | ⚠️ | — | — | global functions: thumbnailFallback; session in endpoint |
| `drive/token_audio.php` | 149 | view/entrypoint | 0 | — | ⚠️ | ⚠️ | global functions: presigned_url, fail_html |
| `drive/token_texto.php` | 260 | view/entrypoint | 0 | — | ⚠️ | ⚠️ | global functions: fail_html, presigned_url, is_text_ext, is_image_ext |
| `drive/token_video.php` | 149 | view/entrypoint | 0 | — | ⚠️ | ⚠️ | global functions: presigned_url, fail_html |
| `drive/traducir_archivo.php` | 113 | endpoint with logic | 0 | ⚠️ | — | ⚠️ | AWS/S3 in endpoint; session in endpoint |
| `drive/transcribir_estado.php` | 458 | procedural endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | global functions: tx_json_response, tx_get_param, tx_fetch_remote_binary, tx_normalize_path, tx_find_file_row, tx_upsert_generated_file, tx_parse_s3_location_from_uri, tx_fetch_remote_binary_smart; DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/transcribir_iniciar.php` | 355 | procedural endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | global functions: tx_json_error, tx_post_bool, tx_post_array, tx_sanitize_job_name, tx_build_s3_uri, tx_normalize_path, tx_dirname_key, tx_find_source_file; DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/unlock_file.php` | 282 | procedural endpoint | 0 | ⚠️ | ⚠️ | — | global functions: json_out, resolve_unlock_user_id, normalize_unlock_key, split_unlock_key, clear_unlock_session_keys; DB in endpoint; session in endpoint |
| `drive/up-clean.php` | 60 | thin endpoint | 0 | — | — | ⚠️ | — |
| `drive/up-old.php` | 505 | view/entrypoint | 0 | — | — | ⚠️ | global functions: cfg_s3, cfg_bucket, signature, meta_path, save_meta, load_meta, list_parts_etags, handle_init |
| `drive/up.php` | 327 | view/entrypoint | 0 | — | — | — | — |
| `drive/up2.php` | 581 | view/entrypoint | 0 | — | — | ⚠️ | global functions: cfg_s3, cfg_bucket, signature, meta_path, save_meta, load_meta, list_parts_etags, handle_init |
| `drive/upload/UploadFactory.php` | 31 | class/module | 1 | — | — | — | — |
| `drive/upload/core/UploadResponse.php` | 15 | class/module | 1 | — | — | — | — |
| `drive/upload/core/UploaderInterface.php` | 11 | class/module | 0 | — | — | — | — |
| `drive/upload/drivers/Chunked15MBUploader.php` | 301 | class/module | 1 | — | ⚠️ | ⚠️ | — |
| `drive/upload/drivers/DropboxUploader.php` | 137 | class/module | 1 | — | — | ⚠️ | — |
| `drive/upload/drivers/LocalPresignedPutUploader.php` | 152 | class/module | 1 | ⚠️ | ⚠️ | ⚠️ | — |
| `drive/upload/drivers/RemoteUrlUploader.php` | 232 | class/module | 1 | — | ⚠️ | ⚠️ | — |
| `drive/upload/repositories/FileS3Repository.php` | 86 | class/module | 1 | — | ⚠️ | — | — |
| `drive/upload/storage/UploadStateStore.php` | 51 | class/module | 1 | — | — | — | — |
| `drive/upload.php` | 144 | endpoint with logic | 0 | ⚠️ | ⚠️ | ⚠️ | DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/upload_audio_recording.php` | 91 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
| `drive/upload_publico.php` | 132 | procedural endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | global functions: safeMeta; DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/validar_php.php` | 129 | procedural endpoint | 0 | — | — | — | global functions: responder |
| `drive/ver.php` | 155 | view/entrypoint | 0 | — | — | ⚠️ | global functions: guessTipoPorExt |
| `drive/ver_archivo.php` | 433 | procedural endpoint | 0 | ⚠️ | ⚠️ | ⚠️ | global functions: out_text, resolve_user_id, normalize_prefix_local, normalize_file_key_local, build_stored_file_key_local, security_lookup_file, is_secure_file, security_session_is_unlocked; DB in endpoint; AWS/S3 in endpoint; session in endpoint |
| `drive/ver_pdf.php` | 44 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |

## JavaScript

| Archivo | Líneas | Tipo detectado | Clases | Funciones globales | `window` funciones | Observaciones |
|---|---:|---|---|---|---|---|
| `drive/js/actualizar-hora.js` | 13 | procedural script | — | actualizarHoraFooter | — | top-level functions: actualizarHoraFooter; no ES class |
| `drive/js/archivos.js` | 2037 | procedural script | — | getBloqueArchivosContext, llenarModalRenombrarArchivo, ensureSecurityModal | abrirModalRenombrarArchivo, cerrarModalCompartir, setFileSecurity | top-level functions: getBloqueArchivosContext, llenarModalRenombrarArchivo, ensureSecurityModal; window functions: abrirModalRenombrarArchivo, cerrarModalCompartir, setFileSecurity; no ES class |
| `drive/js/audiovideo.js` | 595 | encapsulated legacy module | — | — | audioNext, audioPrev, reproducirVideoDesde, videoNext, videoPlayPause, videoPrev | window functions: audioNext, audioPrev, reproducirVideoDesde, videoNext, videoPlayPause, videoPrev, wavePlayPause; no ES class |
| `drive/js/aws-comprehend.js` | 120 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/carpetas.js` | 1076 | procedural script | — | toggleCampoNuevaCarpeta, initNuevaRutaSelect, bindModalEvents, bindDelegationGlobal | actualizarBloqueCarpetas | top-level functions: toggleCampoNuevaCarpeta, initNuevaRutaSelect, bindModalEvents, bindDelegationGlobal; window functions: actualizarBloqueCarpetas; no ES class |
| `drive/js/descarga-multiple.js` | 91 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/descarga-uno.js` | 87 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/editar-txt.js` | 44 | procedural script | — | editarTxt | — | top-level functions: editarTxt; no ES class |
| `drive/js/elimina-multiple.js` | 81 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/elimina-uno.js` | 98 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/estilo.js` | 117 | procedural script | — | — | — | no ES class |
| `drive/js/filtros.js` | 74 | procedural script | — | — | — | top-level state: FiltroUI; no ES class |
| `drive/js/imagenes.js` | 511 | encapsulated legacy module | — | — | getGaleriaGridSize, setGaleriaGridSize | window functions: getGaleriaGridSize, setGaleriaGridSize; no ES class |
| `drive/js/mediaFloating.js` | 14 | procedural script | — | — | — | no ES class |
| `drive/js/obtenerFiltros.js` | 101 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/pdf-pantalla-completa.js` | 22 | procedural script | — | pantallaCompletaPDF | — | top-level functions: pantallaCompletaPDF; no ES class |
| `drive/js/polly.js` | 982 | procedural script | — | generarAudioPolly, pintarRekognition | abrirModalGrabarAudio, abrirModalPolly, abrirModalRekognition, abrirModalTraducir, abrirModalTranscribir, copiarTextract | top-level functions: generarAudioPolly, pintarRekognition; window functions: abrirModalGrabarAudio, abrirModalPolly, abrirModalRekognition, abrirModalTraducir, abrirModalTranscribir, copiarTextract, copiarTraducido, copiarTx; top-level state: URLS; no ES class |
| `drive/js/recargarPagina.js` | 3 | procedural script | — | recargarPagina | — | top-level functions: recargarPagina; no ES class |
| `drive/js/sincronizar.js` | 64 | procedural script | — | — | — | no ES class |
| `drive/js/soportesMediaTypes.js` | 367 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/storage-usage.js` | 28 | procedural script | — | — | — | no ES class |
| `drive/js/subir-chunked.js` | 432 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/subir-dropzone.js` | 47 | procedural script | — | — | — | top-level state: API, drop; no ES class |
| `drive/js/subir.js` | 315 | encapsulated legacy module | — | — | — | no ES class |
| `drive/js/upload-destination.js` | 40 | procedural script | — | — | — | no ES class |
| `drive/js/ver-metadatos.js` | 52 | procedural script | — | — | verMetadatos | window functions: verMetadatos; no ES class |
| `drive/js/ver-pdf.js` | 88 | encapsulated legacy module | — | — | — | no ES class |

## Objetivo de refactorización

```text
HTTP entrypoint -> Controller -> Application Service -> Repository/Infrastructure
                                      |
                                      +-> S3 / AWS service
                                      +-> MySQL repository

Browser -> JS App class -> DOM/HTTP services -> PHP endpoint
```

El objetivo no es envolver código procedural en una clase gigante, sino separar responsabilidades y dependencias.
