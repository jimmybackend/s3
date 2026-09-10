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
| `drive/activity_costs.php` | `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/View/ActivityCostPageRenderer.php` |
| `drive/api/upload.php` | `drive/s3.php`, `drive/js/soportesMediaTypes.js`, `drive/js/subir-chunked.js`, `drive/js/subir-dropzone.js`, `drive/js/subir.js` |
| `drive/app_bootstrap.php` | `drive/activity_costs.php`, `drive/actualizar_ruta.php`, `drive/api/upload.php`, `drive/aws.php`, `drive/bin/sync_worker.php`, `drive/bin/upload_cleanup.php`, `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/buscar_archivo.php`, `drive/comprehend_archivo.php`, `drive/costos_aws.php`, `drive/crear_carpeta.php`, `drive/delete_multiple.php`, `drive/descargar.php`, `drive/descargar_archivo.php`, `drive/descargar_zip.php`, `drive/download_multiple.php`, `drive/ec2-cron.php`, `drive/ec2.php`, `drive/eliminar_archivo.php`, `drive/eliminar_carpeta.php`, `drive/encriptar_archivo.php`, `drive/federationcloud/index.php`, `drive/federationcloud/node.php`, `drive/federationcloud/resolve.php`, `drive/generar_token.php`, `drive/guardar_texto.php`, `drive/leer_texto.php`, `drive/listar_carpetas.php`, `drive/logout.php`, `drive/media_playlist.php`, `drive/move_multiple.php`, `drive/move_task.php`, `drive/move_task_status.php`, `drive/mover_archivo.php`, `drive/mover_carpeta.php`, `drive/polly_cargar_texto.php`, `drive/polly_list_voices.php`, `drive/polly_tts.php`, `drive/procesar_textract.php`, `drive/psesion.php`, `drive/rekognition_labels.php`, `drive/relock_file.php`, `drive/renombrar_archivo.php`, `drive/renombrar_carpeta.php`, `drive/s3.php`, `drive/set_file_security.php`, `drive/storage_usage.php`, `drive/subir_archivo.php`, `drive/subir_publico.php`, `drive/sync_s3_to_db.php`, `drive/sync_status.php`, `drive/thumb.php`, `drive/token_audio.php`, `drive/token_texto.php`, `drive/token_video.php`, `drive/traducir_archivo.php`, `drive/transcribir_estado.php`, `drive/transcribir_iniciar.php`, `drive/unlock_file.php`, `drive/up-clean.php`, `drive/up.php`, `drive/upload.php`, `drive/upload_audio_recording.php`, `drive/upload_publico.php`, `drive/validar_php.php`, `drive/ver.php`, `drive/ver_archivo.php`, `drive/ver_pdf.php` |
| `drive/aws.php` | `drive/s3.php`, `drive/src/Http/Controller/PersonalAwsController.php`, `drive/js/estilo.js` |
| `drive/bloque_archivos.php` | `drive/s3.php`, `drive/js/archivos.js`, `drive/js/carpetas.js`, `drive/js/elimina-multiple.js`, `drive/js/elimina-uno.js`, `drive/js/imagenes.js`, `drive/js/obtenerFiltros.js` |
| `drive/bloque_carpetas.php` | `drive/s3.php`, `drive/js/carpetas.js`, `drive/js/obtenerFiltros.js` |
| `drive/bloque_footer.php` | `drive/s3.php` |
| `drive/costos_aws.php` | `drive/s3.php` |
| `drive/delete_multiple.php` | `drive/s3.php`, `drive/js/elimina-multiple.js`, `drive/js/file-block.js` |
| `drive/descargar_archivo.php` | `drive/bloque_archivos.php` |
| `drive/ec2.php` | `drive/s3.php`, `drive/js/estilo.js` |
| `drive/editor.php` | `drive/bloque_archivos.php`, `drive/js/editar-txt.js` |
| `drive/federationcloud/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Security/SessionManager.php`, `drive/src/View/PersonalAwsPageRenderer.php`, `drive/up.php` |
| `drive/guardar_texto.php` | `drive/editor.php` |
| `drive/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Security/SessionManager.php`, `drive/src/View/PersonalAwsPageRenderer.php`, `drive/up.php` |
| `drive/leer_texto.php` | `drive/editor.php` |
| `drive/login.php` | entrada directa |
| `drive/logout.php` | `drive/s3.php` |
| `drive/psesion.php` | `drive/index.php`, `drive/login.php` |
| `drive/s3.php` | `drive/app_bootstrap.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/View/ActivityCostPageRenderer.php`, `drive/src/View/PersonalAwsPageRenderer.php`, `drive/js/archivos.js`, `drive/js/carpetas.js`, `drive/js/subir.js` |
| `drive/thumb.php` | `drive/bloque_archivos.php`, `drive/js/imagenes.js` |
| `drive/up.php` | `drive/src/Http/Controller/UploadCleanupController.php`, `drive/src/Upload/AdminMultipartUploadService.php`, `drive/js/estilo.js` |
| `drive/upload.php` | `drive/s3.php`, `drive/js/soportesMediaTypes.js`, `drive/js/subir-chunked.js`, `drive/js/subir-dropzone.js`, `drive/js/subir.js` |
| `drive/validar_php.php` | `drive/editor.php` |
| `drive/ver_archivo.php` | `drive/bloque_archivos.php`, `drive/src/Media/MediaPlaylistService.php`, `drive/js/archivos.js`, `drive/js/imagenes.js` |

## PHP no alcanzables por referencias internas

| Archivo | Referencias encontradas |
|---|---|
| `drive/actualizar_ruta.php` | `drive/js/carpetas.js`, `drive/js/obtenerFiltros.js` |
| `drive/bin/federation_identity_init.php` | ninguna |
| `drive/bin/sync_worker.php` | `drive/src/Http/Controller/SyncController.php` |
| `drive/bin/upload_cleanup.php` | `drive/src/Http/Controller/UploadCleanupController.php` |
| `drive/buscar_archivo.php` | `drive/js/ai-search.js`, `drive/js/archivos.js` |
| `drive/comprehend_archivo.php` | `drive/js/aws-comprehend.js` |
| `drive/crear_carpeta.php` | `drive/js/carpetas.js` |
| `drive/descargar.php` | `drive/js/descarga-uno.js` |
| `drive/descargar_zip.php` | `drive/js/descarga-multiple.js`, `drive/js/file-block.js` |
| `drive/download_multiple.php` | ninguna |
| `drive/ec2-cron.php` | ninguna |
| `drive/eliminar_archivo.php` | `drive/js/archivos.js`, `drive/js/elimina-uno.js` |
| `drive/eliminar_carpeta.php` | `drive/js/carpetas.js` |
| `drive/encriptar_archivo.php` | `drive/js/archivos.js` |
| `drive/federationcloud/node.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationResolverService.php` |
| `drive/federationcloud/resolve.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationResolverService.php` |
| `drive/generar_token.php` | `drive/js/archivos.js` |
| `drive/listar_carpetas.php` | `drive/js/carpetas.js` |
| `drive/media_playlist.php` | `drive/js/media-floating.js` |
| `drive/move_multiple.php` | ninguna |
| `drive/move_task.php` | `drive/js/move-tasks.js` |
| `drive/move_task_status.php` | `drive/js/move-tasks.js` |
| `drive/mover_archivo.php` | `drive/js/archivos.js` |
| `drive/mover_carpeta.php` | `drive/js/carpetas.js` |
| `drive/polly_cargar_texto.php` | `drive/js/polly.js` |
| `drive/polly_list_voices.php` | `drive/js/polly.js` |
| `drive/polly_tts.php` | `drive/js/polly.js` |
| `drive/procesar_textract.php` | `drive/js/polly.js` |
| `drive/rekognition_labels.php` | `drive/js/polly.js` |
| `drive/relock_file.php` | `drive/js/archivos.js` |
| `drive/renombrar_archivo.php` | `drive/js/archivos.js` |
| `drive/renombrar_carpeta.php` | `drive/js/carpetas.js` |
| `drive/set_file_security.php` | `drive/js/archivos.js` |
| `drive/src/Activity/ActivityCostRecorder.php` | ninguna |
| `drive/src/Activity/ActivityCostRepository.php` | ninguna |
| `drive/src/Activity/ActivityCostService.php` | ninguna |
| `drive/src/Activity/AwsUnitPriceCatalog.php` | ninguna |
| `drive/src/Application/AiFileSearchService.php` | ninguna |
| `drive/src/Application/DrivePageService.php` | ninguna |
| `drive/src/Application/DrivePageViewModel.php` | ninguna |
| `drive/src/Application/FileAccessService.php` | ninguna |
| `drive/src/Application/FileKeyRotationService.php` | ninguna |
| `drive/src/Application/FileListService.php` | ninguna |
| `drive/src/Application/FileMutationService.php` | ninguna |
| `drive/src/Application/FileSearchService.php` | ninguna |
| `drive/src/Application/FolderMutationService.php` | ninguna |
| `drive/src/Application/FolderQueryService.php` | ninguna |
| `drive/src/Application/MoveJobService.php` | ninguna |
| `drive/src/Application/PhpLintService.php` | ninguna |
| `drive/src/Application/TemporaryZip.php` | ninguna |
| `drive/src/Application/TextFileService.php` | ninguna |
| `drive/src/Application/UploadDestinationService.php` | ninguna |
| `drive/src/Application/ZipDownloadService.php` | ninguna |
| `drive/src/Aws/AwsCostService.php` | ninguna |
| `drive/src/Aws/ComprehendFileService.php` | ninguna |
| `drive/src/Aws/CostExplorerGateway.php` | ninguna |
| `drive/src/Aws/Ec2CostGuardService.php` | ninguna |
| `drive/src/Aws/Ec2CronLogger.php` | ninguna |
| `drive/src/Aws/Ec2Gateway.php` | ninguna |
| `drive/src/Aws/FileMetadataRepository.php` | ninguna |
| `drive/src/Aws/FileRecordLocator.php` | ninguna |
| `drive/src/Aws/GeneratedFileRepository.php` | ninguna |
| `drive/src/Aws/PersonalAwsConfig.php` | ninguna |
| `drive/src/Aws/PersonalTotpService.php` | ninguna |
| `drive/src/Aws/PollyFileService.php` | ninguna |
| `drive/src/Aws/RdsGateway.php` | ninguna |
| `drive/src/Aws/RekognitionFileService.php` | ninguna |
| `drive/src/Aws/TextractFileService.php` | ninguna |
| `drive/src/Aws/TranscriptionFileService.php` | ninguna |
| `drive/src/Aws/TranslateFileService.php` | ninguna |
| `drive/src/Console/UploadCleanupCommand.php` | ninguna |
| `drive/src/Core/ApplicationKernel.php` | ninguna |
| `drive/src/Core/DriveApplication.php` | ninguna |
| `drive/src/Federation/ArcadeLinkService.php` | `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederatedResourceRepository.php` | ninguna |
| `drive/src/Federation/FederationCodec.php` | `drive/bin/federation_identity_init.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationConfig.php` | `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationException.php` | `drive/bin/federation_identity_init.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationHttpClient.php` | ninguna |
| `drive/src/Federation/FederationResolverService.php` | ninguna |
| `drive/src/Federation/FederationService.php` | ninguna |
| `drive/src/Federation/NodeIdentityService.php` | `drive/bin/federation_identity_init.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Http/BinaryResponse.php` | ninguna |
| `drive/src/Http/ByteRange.php` | ninguna |
| `drive/src/Http/Controller/AbstractJsonController.php` | ninguna |
| `drive/src/Http/Controller/ActivityCostController.php` | ninguna |
| `drive/src/Http/Controller/AuthController.php` | ninguna |
| `drive/src/Http/Controller/AwsCostController.php` | ninguna |
| `drive/src/Http/Controller/AwsFileController.php` | ninguna |
| `drive/src/Http/Controller/FederationController.php` | ninguna |
| `drive/src/Http/Controller/FileAccessController.php` | ninguna |
| `drive/src/Http/Controller/FileKeyRotationController.php` | ninguna |
| `drive/src/Http/Controller/FileMutationController.php` | ninguna |
| `drive/src/Http/Controller/FileSearchController.php` | ninguna |
| `drive/src/Http/Controller/FileSecurityController.php` | ninguna |
| `drive/src/Http/Controller/FolderMutationController.php` | ninguna |
| `drive/src/Http/Controller/FolderQueryController.php` | ninguna |
| `drive/src/Http/Controller/LegacyUploadController.php` | ninguna |
| `drive/src/Http/Controller/MediaPlaylistController.php` | ninguna |
| `drive/src/Http/Controller/MoveJobController.php` | ninguna |
| `drive/src/Http/Controller/NavigationController.php` | ninguna |
| `drive/src/Http/Controller/PersonalAwsController.php` | ninguna |
| `drive/src/Http/Controller/PublicShareController.php` | ninguna |
| `drive/src/Http/Controller/PublicSharedBrowserController.php` | ninguna |
| `drive/src/Http/Controller/PublicUploadController.php` | ninguna |
| `drive/src/Http/Controller/ShareController.php` | ninguna |
| `drive/src/Http/Controller/StorageUsageController.php` | ninguna |
| `drive/src/Http/Controller/SyncController.php` | ninguna |
| `drive/src/Http/Controller/TextEditorController.php` | ninguna |
| `drive/src/Http/Controller/ThumbnailController.php` | ninguna |
| `drive/src/Http/Controller/TranscriptionController.php` | ninguna |
| `drive/src/Http/Controller/UploadCleanupController.php` | ninguna |
| `drive/src/Http/Controller/UploadController.php` | ninguna |
| `drive/src/Http/JsonResponse.php` | ninguna |
| `drive/src/Http/Request.php` | ninguna |
| `drive/src/Media/MediaPlaylistRepository.php` | ninguna |
| `drive/src/Media/MediaPlaylistService.php` | ninguna |
| `drive/src/Media/ThumbnailService.php` | ninguna |
| `drive/src/Security/AuthenticationRepository.php` | ninguna |
| `drive/src/Security/AuthenticationService.php` | ninguna |
| `drive/src/Security/FileSecurityRepository.php` | ninguna |
| `drive/src/Security/FileSecurityService.php` | ninguna |
| `drive/src/Security/PersonalToolAccessService.php` | ninguna |
| `drive/src/Security/SessionManager.php` | ninguna |
| `drive/src/Security/UserDirectoryRepository.php` | ninguna |
| `drive/src/Sharing/ShareAccessService.php` | ninguna |
| `drive/src/Sharing/ShareException.php` | ninguna |
| `drive/src/Sharing/ShareFileRepository.php` | ninguna |
| `drive/src/Sharing/ShareLinkService.php` | ninguna |
| `drive/src/Sharing/ShareObjectStorage.php` | ninguna |
| `drive/src/Sharing/ShareTokenStore.php` | ninguna |
| `drive/src/Storage/FileRecordRepository.php` | ninguna |
| `drive/src/Storage/FolderMutationRepository.php` | ninguna |
| `drive/src/Storage/FolderRepository.php` | ninguna |
| `drive/src/Storage/MoveJobStore.php` | ninguna |
| `drive/src/Storage/StorageObjectNameCodec.php` | ninguna |
| `drive/src/Storage/StorageUsageService.php` | ninguna |
| `drive/src/Storage/UserStoragePath.php` | ninguna |
| `drive/src/Storage/UserStorageProvisioner.php` | ninguna |
| `drive/src/Sync/S3SyncService.php` | ninguna |
| `drive/src/Sync/SyncJobStore.php` | ninguna |
| `drive/src/Sync/SyncRepository.php` | ninguna |
| `drive/src/Upload/AdminMultipartUploadService.php` | ninguna |
| `drive/src/Upload/PublicDropzoneUploadService.php` | ninguna |
| `drive/src/Upload/PublicMultipartUploadService.php` | ninguna |
| `drive/src/Upload/PublicSharedBrowserRepository.php` | ninguna |
| `drive/src/Upload/PublicSharedBrowserService.php` | ninguna |
| `drive/src/Upload/SingleUploadService.php` | ninguna |
| `drive/src/Upload/UploadCatalogRepository.php` | ninguna |
| `drive/src/Upload/UploadCleanupService.php` | ninguna |
| `drive/src/View/ActivityCostPageRenderer.php` | ninguna |
| `drive/src/View/Ec2PanelHelper.php` | ninguna |
| `drive/src/View/FederationPageRenderer.php` | ninguna |
| `drive/src/View/FileIconResolver.php` | ninguna |
| `drive/src/View/FileViewHelper.php` | ninguna |
| `drive/src/View/FolderTreeRenderer.php` | ninguna |
| `drive/src/View/PersonalAwsPageRenderer.php` | ninguna |
| `drive/src/View/PublicSharedPageRenderer.php` | ninguna |
| `drive/src/View/SharePageRenderer.php` | ninguna |
| `drive/src/View/SyncStatusRenderer.php` | ninguna |
| `drive/storage_usage.php` | `drive/js/storage-usage.js` |
| `drive/subir_archivo.php` | ninguna |
| `drive/subir_publico.php` | ninguna |
| `drive/sync_s3_to_db.php` | `drive/js/sincronizar.js` |
| `drive/sync_status.php` | `drive/js/sincronizar.js` |
| `drive/tests/activity_costs_smoke.php` | ninguna |
| `drive/tests/federationcloud_smoke.php` | ninguna |
| `drive/token_audio.php` | `drive/src/Sharing/ShareLinkService.php` |
| `drive/token_texto.php` | `drive/src/Sharing/ShareLinkService.php` |
| `drive/token_video.php` | `drive/src/Sharing/ShareLinkService.php`, `drive/js/audiovideo.js` |
| `drive/traducir_archivo.php` | `drive/js/polly.js` |
| `drive/transcribir_estado.php` | `drive/js/polly.js` |
| `drive/transcribir_iniciar.php` | `drive/js/polly.js` |
| `drive/unlock_file.php` | `drive/js/archivos.js` |
| `drive/up-clean.php` | ninguna |
| `drive/upload/UploadFactory.php` | `drive/src/Core/DriveApplication.php` |
| `drive/upload/core/UploadResponse.php` | ninguna |
| `drive/upload/core/UploaderInterface.php` | `drive/upload/UploadFactory.php`, `drive/upload/drivers/Chunked15MBUploader.php`, `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/LocalPresignedPutUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php` |
| `drive/upload/drivers/Chunked15MBUploader.php` | `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/DropboxUploader.php` | `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/LocalPresignedPutUploader.php` | `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/RemoteUrlUploader.php` | `drive/upload/UploadFactory.php` |
| `drive/upload/repositories/FileS3Repository.php` | `drive/upload/drivers/Chunked15MBUploader.php`, `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/LocalPresignedPutUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php` |
| `drive/upload/storage/UploadStateStore.php` | `drive/upload/UploadFactory.php`, `drive/upload/drivers/Chunked15MBUploader.php` |
| `drive/upload_audio_recording.php` | ninguna |
| `drive/upload_publico.php` | `drive/src/View/PublicSharedPageRenderer.php` |
| `drive/ver.php` | ninguna |
| `drive/ver_pdf.php` | `drive/js/ver-pdf.js` |
