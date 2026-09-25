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
| `drive/app_bootstrap.php` | `drive/activity_costs.php`, `drive/actualizar_ruta.php`, `drive/api/upload.php`, `drive/aws.php`, `drive/background_tasks.php`, `drive/bin/federation_catalog_migrate.php`, `drive/bin/federation_drop_cleanup.php`, `drive/bin/federation_endpoint_refresh.php`, `drive/bin/federation_replica_presence.php`, `drive/bin/federation_sync.php`, `drive/bin/media_processing_worker.php`, `drive/bin/move_job_worker.php`, `drive/bin/polly_reconcile.php`, `drive/bin/sync_node_worker.php`, `drive/bin/sync_schema_migrate.php`, `drive/bin/sync_worker.php`, `drive/bin/transcribe_reconcile.php`, `drive/bin/upload_cleanup.php`, `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/buscar_archivo.php`, `drive/comprehend_archivo.php`, `drive/costos_aws.php`, `drive/crear_carpeta.php`, `drive/create_folder_document.php`, `drive/delete_multiple.php`, `drive/descargar.php`, `drive/descargar_archivo.php`, `drive/descargar_zip.php`, `drive/download.php`, `drive/download_multiple.php`, `drive/ec2-cron.php`, `drive/ec2.php`, `drive/eliminar_archivo.php`, `drive/eliminar_carpeta.php`, `drive/encriptar_archivo.php`, `drive/federationcloud/access-request.php`, `drive/federationcloud/access-status.php`, `drive/federationcloud/access.php`, `drive/federationcloud/collection.php`, `drive/federationcloud/create.php`, `drive/federationcloud/drop-ingress.php`, `drive/federationcloud/index.php`, `drive/federationcloud/moderation-api.php`, `drive/federationcloud/moderation.php`, `drive/federationcloud/name-availability.php`, `drive/federationcloud/node-admin.php`, `drive/federationcloud/node.php`, `drive/federationcloud/nodes.php`, `drive/federationcloud/portal.php`, `drive/federationcloud/provider-admin.php`, `drive/federationcloud/provider-presence.php`, `drive/federationcloud/provider-request.php`, `drive/federationcloud/providers.php`, `drive/federationcloud/public-drive.php`, `drive/federationcloud/register.php`, `drive/federationcloud/replica-offer.php`, `drive/federationcloud/replica-open.php`, `drive/federationcloud/replica-resolve.php`, `drive/federationcloud/replica.php`, `drive/federationcloud/report-api.php`, `drive/federationcloud/report.php`, `drive/federationcloud/resolve.php`, `drive/federationcloud/resource.php`, `drive/federationcloud/search.php`, `drive/federationcloud/share-drive.php`, `drive/federationcloud/sync-pull.php`, `drive/federationcloud/sync-push.php`, `drive/federationcloud/sync-status.php`, `drive/federationdrop/api.php`, `drive/federationdrop/arcadelink.php`, `drive/federationdrop/d.php`, `drive/federationdrop/google-callback.php`, `drive/federationdrop/google-login.php`, `drive/federationdrop/google-logout.php`, `drive/federationdrop/index.php`, `drive/generar_token.php`, `drive/guardar_texto.php`, `drive/leer_texto.php`, `drive/listar_carpetas.php`, `drive/logout.php`, `drive/media_playlist.php`, `drive/media_processing.php`, `drive/move_multiple.php`, `drive/move_task.php`, `drive/move_task_status.php`, `drive/mover_archivo.php`, `drive/mover_carpeta.php`, `drive/polly_cargar_texto.php`, `drive/polly_list_voices.php`, `drive/polly_task_status.php`, `drive/polly_tasks.php`, `drive/polly_tts.php`, `drive/procesar_textract.php`, `drive/profile.php`, `drive/psesion.php`, `drive/rekognition_labels.php`, `drive/relock_file.php`, `drive/renombrar_archivo.php`, `drive/renombrar_carpeta.php`, `drive/s3.php`, `drive/server-settings.php`, `drive/set_file_security.php`, `drive/storage_usage.php`, `drive/subir_archivo.php`, `drive/subir_publico.php`, `drive/sync_s3_to_db.php`, `drive/sync_status.php`, `drive/tests/index_federation_drop_smoke.php`, `drive/tests/installer_service_reconcile_contract_smoke.php`, `drive/thumb.php`, `drive/token_audio.php`, `drive/token_texto.php`, `drive/token_video.php`, `drive/traducir_archivo.php`, `drive/transcribir_estado.php`, `drive/transcribir_iniciar.php`, `drive/unlock_file.php`, `drive/up-clean.php`, `drive/up.php`, `drive/update.php`, `drive/upload.php`, `drive/upload_audio_recording.php`, `drive/upload_publico.php`, `drive/validar_php.php`, `drive/ver.php`, `drive/ver_archivo.php`, `drive/ver_pdf.php` |
| `drive/aws.php` | `drive/ec2.php`, `drive/s3.php`, `drive/src/Http/Controller/PersonalAwsController.php`, `drive/js/estilo.js` |
| `drive/bloque_archivos.php` | `drive/s3.php`, `drive/tests/media_processing_contract_smoke.php`, `drive/js/archivos.js`, `drive/js/carpetas.js`, `drive/js/elimina-multiple.js`, `drive/js/elimina-uno.js`, `drive/js/imagenes.js`, `drive/js/obtenerFiltros.js` |
| `drive/bloque_carpetas.php` | `drive/s3.php`, `drive/js/carpetas.js`, `drive/js/obtenerFiltros.js` |
| `drive/bloque_footer.php` | `drive/s3.php`, `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/costos_aws.php` | `drive/s3.php` |
| `drive/delete_multiple.php` | `drive/s3.php`, `drive/js/elimina-multiple.js`, `drive/js/file-block.js` |
| `drive/descargar_archivo.php` | `drive/bloque_archivos.php`, `drive/tests/arcadelink_bulk_contract_regression.php` |
| `drive/ec2.php` | `drive/s3.php`, `drive/src/View/PersonalAwsPageRenderer.php`, `drive/js/estilo.js` |
| `drive/editor.php` | `drive/bloque_archivos.php`, `drive/js/editar-txt.js` |
| `drive/federationcloud/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Http/Controller/FederationModerationController.php`, `drive/src/Security/SessionManager.php`, `drive/src/View/FederationReportPageRenderer.php`, `drive/tests/index_federation_drop_smoke.php`, `drive/up.php` |
| `drive/federationcloud/moderation.php` | `drive/bloque_footer.php` |
| `drive/federationcloud/portal.php` | `drive/bloque_carpetas.php`, `drive/src/Federation/FederationService.php`, `drive/src/View/FederationDropPageRenderer.php`, `drive/src/View/FederationPageRenderer.php`, `drive/js/arcadelink-share.js` |
| `drive/federationdrop/d.php` | `drive/app_bootstrap.php`, `drive/index.php`, `drive/s3.php`, `drive/src/Federation/FederationDropService.php`, `drive/tests/federation_moderation_contract_smoke.php`, `drive/tests/federationcloud_smoke.php`, `drive/tests/media_processing_contract_smoke.php`, `drive/tests/setup_entry_guard_smoke.php`, `drive/upload/drivers/Chunked15MBUploader.php`, `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/LocalPresignedPutUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php`, `drive/js/soportesMediaTypes.js`, `drive/js/subir-chunked.js`, `drive/js/subir-dropzone.js`, `drive/js/subir.js` |
| `drive/federationdrop/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Http/Controller/FederationModerationController.php`, `drive/src/Security/SessionManager.php`, `drive/src/View/FederationReportPageRenderer.php`, `drive/tests/index_federation_drop_smoke.php`, `drive/up.php` |
| `drive/guardar_texto.php` | `drive/editor.php` |
| `drive/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Http/Controller/FederationModerationController.php`, `drive/src/Security/SessionManager.php`, `drive/src/View/FederationReportPageRenderer.php`, `drive/tests/index_federation_drop_smoke.php`, `drive/up.php` |
| `drive/leer_texto.php` | `drive/editor.php` |
| `drive/login.php` | `drive/src/Federation/FederationDropGoogleAuthService.php`, `drive/src/View/FederationDropPageRenderer.php` |
| `drive/logout.php` | `drive/s3.php`, `drive/src/Federation/FederationDropGoogleAuthService.php`, `drive/src/View/FederationDropPageRenderer.php` |
| `drive/psesion.php` | `drive/index.php`, `drive/login.php`, `drive/tests/index_federation_drop_smoke.php` |
| `drive/s3.php` | `drive/app_bootstrap.php`, `drive/ec2.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/View/ActivityCostPageRenderer.php`, `drive/src/View/FederationModerationPageRenderer.php`, `drive/src/View/FederationPageRenderer.php`, `drive/src/View/FederationPortalRenderer.php`, `drive/src/View/FederationReportPageRenderer.php`, `drive/src/View/PersonalAwsPageRenderer.php`, `drive/tests/index_federation_drop_smoke.php`, `drive/tests/media_processing_contract_smoke.php`, `drive/up.php`, `drive/js/archivos.js`, `drive/js/carpetas.js`, `drive/js/federation-share-drive.js`, `drive/js/subir.js` |
| `drive/setup/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Http/Controller/FederationModerationController.php`, `drive/src/Security/SessionManager.php`, `drive/src/View/FederationReportPageRenderer.php`, `drive/tests/index_federation_drop_smoke.php`, `drive/up.php` |
| `drive/src/Admin/ManagedRuntimeEnvironment.php` | `drive/app_bootstrap.php`, `drive/setup/api.php`, `drive/tests/installer_service_reconcile_contract_smoke.php`, `drive/tests/server_admin_config_smoke.php` |
| `drive/src/Setup/BootstrapSetupAuth.php` | `drive/setup/api.php`, `drive/setup/index.php`, `drive/tests/setup_bootstrap_smoke.php` |
| `drive/src/Setup/SetupEntryGuard.php` | `drive/index.php`, `drive/tests/setup_entry_guard_smoke.php` |
| `drive/thumb.php` | `drive/bloque_archivos.php`, `drive/js/imagenes.js` |
| `drive/up.php` | `drive/src/Http/Controller/UploadCleanupController.php`, `drive/src/Upload/AdminMultipartUploadService.php`, `drive/tests/upload_catalog_registration_regression.php`, `drive/js/estilo.js` |
| `drive/upload.php` | `drive/s3.php`, `drive/js/soportesMediaTypes.js`, `drive/js/subir-chunked.js`, `drive/js/subir-dropzone.js`, `drive/js/subir.js` |
| `drive/validar_php.php` | `drive/editor.php` |
| `drive/ver_archivo.php` | `drive/bloque_archivos.php`, `drive/src/Media/MediaPlaylistService.php`, `drive/js/archivos.js`, `drive/js/imagenes.js` |

## PHP no alcanzables por referencias internas

| Archivo | Referencias encontradas |
|---|---|
| `drive/actualizar_ruta.php` | `drive/js/carpetas.js`, `drive/js/obtenerFiltros.js` |
| `drive/background_tasks.php` | `drive/js/background-tasks.js` |
| `drive/bin/arcadecloud-drive-admin-helper.php` | `drive/tests/installer_service_reconcile_contract_smoke.php`, `drive/tests/setup_finalize_contract_smoke.php` |
| `drive/bin/arcadecloud-drive-updater.php` | `drive/tests/installer_service_reconcile_contract_smoke.php` |
| `drive/bin/federation_catalog_migrate.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_moderation_contract_smoke.php`, `drive/tests/installer_service_reconcile_contract_smoke.php`, `drive/tests/setup_finalize_contract_smoke.php` |
| `drive/bin/federation_drop_cleanup.php` | ninguna |
| `drive/bin/federation_endpoint_refresh.php` | `drive/bin/federation_https_reconcile.php`, `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_https_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php`, `drive/tests/media_processing_contract_smoke.php`, `drive/tests/setup_finalize_contract_smoke.php` |
| `drive/bin/federation_https_reconcile.php` | `drive/tests/federation_https_contract_smoke.php` |
| `drive/bin/federation_identity_backup.php` | ninguna |
| `drive/bin/federation_identity_init.php` | ninguna |
| `drive/bin/federation_identity_name.php` | ninguna |
| `drive/bin/federation_identity_restore.php` | ninguna |
| `drive/bin/federation_provider_request.php` | `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/bin/federation_replica_presence.php` | ninguna |
| `drive/bin/federation_sync.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/bin/media_processing_worker.php` | ninguna |
| `drive/bin/move_job_worker.php` | `drive/src/Application/BackgroundWorkerLauncher.php` |
| `drive/bin/polly_reconcile.php` | `drive/src/Application/BackgroundWorkerLauncher.php` |
| `drive/bin/sync_node_worker.php` | ninguna |
| `drive/bin/sync_schema_migrate.php` | ninguna |
| `drive/bin/sync_worker.php` | `drive/src/Application/BackgroundWorkerLauncher.php` |
| `drive/bin/transcribe_reconcile.php` | `drive/src/Application/BackgroundWorkerLauncher.php` |
| `drive/bin/upload_cleanup.php` | `drive/src/Http/Controller/UploadCleanupController.php` |
| `drive/buscar_archivo.php` | `drive/js/ai-search.js`, `drive/js/archivos.js` |
| `drive/comprehend_archivo.php` | `drive/js/aws-comprehend.js` |
| `drive/crear_carpeta.php` | `drive/js/carpetas.js` |
| `drive/create_folder_document.php` | `drive/js/folder-document.js` |
| `drive/descargar.php` | `drive/js/descarga-uno.js` |
| `drive/descargar_zip.php` | `drive/js/descarga-multiple.js`, `drive/js/file-block.js` |
| `drive/download.php` | ninguna |
| `drive/download_multiple.php` | ninguna |
| `drive/ec2-cron.php` | ninguna |
| `drive/eliminar_archivo.php` | `drive/js/archivos.js`, `drive/js/elimina-uno.js` |
| `drive/eliminar_carpeta.php` | `drive/js/carpetas.js` |
| `drive/encriptar_archivo.php` | `drive/js/archivos.js` |
| `drive/federationcloud/access-request.php` | `drive/src/Federation/FederationAccessService.php`, `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/access-status.php` | `drive/src/Federation/FederationAccessService.php`, `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/access.php` | `drive/js/federation-portal.js` |
| `drive/federationcloud/collection.php` | `drive/tests/arcadelink_bulk_contract_regression.php`, `drive/tests/arcadelink_collection_regression.php`, `drive/js/arcadelink-share.js` |
| `drive/federationcloud/create.php` | `drive/js/arcadelink-share.js` |
| `drive/federationcloud/drop-ingress.php` | `drive/src/Federation/FederationDropIngressService.php`, `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/moderation-api.php` | `drive/src/View/FederationModerationPageRenderer.php`, `drive/js/federation-footer.js` |
| `drive/federationcloud/name-availability.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationNodeAdminService.php` |
| `drive/federationcloud/node-admin.php` | `drive/js/federation-footer.js` |
| `drive/federationcloud/node.php` | `drive/bin/federation_provider_request.php`, `drive/src/Federation/FederationCustomsService.php`, `drive/src/Federation/FederationDirectoryService.php`, `drive/src/Federation/FederationDropIngressService.php`, `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationProviderAuthorizationService.php`, `drive/src/Federation/FederationReplicaPresenceService.php`, `drive/src/Federation/FederationResolverService.php`, `drive/tests/federation_customs_contract_smoke.php` |
| `drive/federationcloud/nodes.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/js/federation-footer.js` |
| `drive/federationcloud/provider-admin.php` | `drive/js/federation-footer.js` |
| `drive/federationcloud/provider-presence.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationReplicaPresenceService.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/federationcloud/provider-request.php` | `drive/bin/federation_provider_request.php`, `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationProviderAuthorizationService.php`, `drive/src/Federation/FederationReplicaPresenceService.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/federationcloud/providers.php` | `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/public-drive.php` | `drive/js/federation-portal.js` |
| `drive/federationcloud/register.php` | `drive/src/Federation/FederationCustomsService.php`, `drive/src/Federation/FederationDirectoryService.php`, `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationNodeAdminService.php`, `drive/src/Http/Controller/FederationProviderController.php`, `drive/tests/federation_customs_contract_smoke.php` |
| `drive/federationcloud/replica-offer.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationReplicaService.php` |
| `drive/federationcloud/replica-open.php` | `drive/src/Federation/FederationService.php`, `drive/js/federation-portal.js` |
| `drive/federationcloud/replica-resolve.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationReplicaResolverService.php`, `drive/tests/federation_public_download_failover_smoke.php` |
| `drive/federationcloud/replica.php` | `drive/js/federation-portal.js` |
| `drive/federationcloud/report-api.php` | `drive/src/View/FederationReportPageRenderer.php` |
| `drive/federationcloud/report.php` | `drive/tests/federation_moderation_contract_smoke.php`, `drive/js/federation-drop.js`, `drive/js/federation-portal.js` |
| `drive/federationcloud/resolve.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationReplicaResolverService.php`, `drive/src/Federation/FederationResolverService.php`, `drive/tests/federation_public_download_failover_smoke.php` |
| `drive/federationcloud/resource.php` | ninguna |
| `drive/federationcloud/search.php` | `drive/js/federation-portal.js` |
| `drive/federationcloud/share-drive.php` | `drive/js/federation-share-drive.js` |
| `drive/federationcloud/sync-pull.php` | `drive/src/Federation/FederationGossipService.php`, `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/sync-push.php` | `drive/src/Federation/FederationGossipService.php`, `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/sync-status.php` | ninguna |
| `drive/federationdrop/api.php` | `drive/src/View/FederationModerationPageRenderer.php`, `drive/src/View/FederationReportPageRenderer.php`, `drive/tests/federation_drop_contract_smoke.php`, `drive/js/federation-drop.js`, `drive/js/federation-footer.js`, `drive/js/setup.js` |
| `drive/federationdrop/arcadelink.php` | `drive/src/Federation/FederationDropService.php` |
| `drive/federationdrop/google-callback.php` | `drive/src/Federation/FederationDropGoogleAuthConfig.php`, `drive/tests/federation_drop_google_oidc_smoke.php` |
| `drive/federationdrop/google-login.php` | `drive/src/Federation/FederationDropGoogleAuthService.php`, `drive/src/View/FederationDropPageRenderer.php` |
| `drive/federationdrop/google-logout.php` | `drive/src/Federation/FederationDropGoogleAuthService.php`, `drive/src/View/FederationDropPageRenderer.php` |
| `drive/generar_token.php` | `drive/js/archivos.js` |
| `drive/listar_carpetas.php` | `drive/js/carpetas.js` |
| `drive/media_playlist.php` | `drive/js/media-floating.js` |
| `drive/media_processing.php` | `drive/js/media-processing.js` |
| `drive/move_multiple.php` | ninguna |
| `drive/move_task.php` | `drive/js/move-tasks.js` |
| `drive/move_task_status.php` | `drive/js/move-tasks.js` |
| `drive/mover_archivo.php` | `drive/js/archivos.js` |
| `drive/mover_carpeta.php` | `drive/js/carpetas.js` |
| `drive/polly_cargar_texto.php` | `drive/js/polly.js` |
| `drive/polly_list_voices.php` | `drive/js/polly.js` |
| `drive/polly_task_status.php` | ninguna |
| `drive/polly_tasks.php` | ninguna |
| `drive/polly_tts.php` | `drive/js/polly-background.js`, `drive/js/polly.js` |
| `drive/procesar_textract.php` | `drive/js/polly.js` |
| `drive/profile.php` | `drive/js/profile.js` |
| `drive/rekognition_labels.php` | `drive/js/polly.js` |
| `drive/relock_file.php` | `drive/js/archivos.js` |
| `drive/renombrar_archivo.php` | `drive/js/archivos.js` |
| `drive/renombrar_carpeta.php` | `drive/js/carpetas.js` |
| `drive/server-settings.php` | `drive/js/server-admin.js` |
| `drive/set_file_security.php` | `drive/js/archivos.js` |
| `drive/setup/api.php` | `drive/src/View/FederationModerationPageRenderer.php`, `drive/src/View/FederationReportPageRenderer.php`, `drive/tests/federation_drop_contract_smoke.php`, `drive/js/federation-drop.js`, `drive/js/federation-footer.js`, `drive/js/setup.js` |
| `drive/src/Activity/ActivityCostRecorder.php` | ninguna |
| `drive/src/Activity/ActivityCostRepository.php` | ninguna |
| `drive/src/Activity/ActivityCostService.php` | ninguna |
| `drive/src/Activity/AwsUnitPriceCatalog.php` | ninguna |
| `drive/src/Activity/PollyTaskReconciler.php` | ninguna |
| `drive/src/Activity/TranscriptionCostAttribution.php` | ninguna |
| `drive/src/Activity/TranscriptionReconciler.php` | `drive/tests/transcribe_s3_recovery_contract_smoke.php` |
| `drive/src/Admin/ArcadeCloudUpdaterService.php` | `drive/tests/federation_moderation_contract_smoke.php`, `drive/tests/installer_service_reconcile_contract_smoke.php` |
| `drive/src/Admin/PrivilegedServerHelper.php` | `drive/setup/api.php`, `drive/tests/setup_finalize_contract_smoke.php` |
| `drive/src/Admin/ServerSettingsAdminService.php` | ninguna |
| `drive/src/Application/AiFileSearchService.php` | ninguna |
| `drive/src/Application/BackgroundWorkerLauncher.php` | ninguna |
| `drive/src/Application/DrivePageService.php` | ninguna |
| `drive/src/Application/DrivePageViewModel.php` | ninguna |
| `drive/src/Application/FileAccessService.php` | `drive/tests/arcadelink_bulk_contract_regression.php` |
| `drive/src/Application/FileKeyRotationService.php` | ninguna |
| `drive/src/Application/FileListService.php` | ninguna |
| `drive/src/Application/FileMutationService.php` | ninguna |
| `drive/src/Application/FileSearchService.php` | ninguna |
| `drive/src/Application/FolderDocumentService.php` | `drive/tests/folder_document_sanitizer.php` |
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
| `drive/src/Aws/SesEmailService.php` | ninguna |
| `drive/src/Aws/TextractFileService.php` | ninguna |
| `drive/src/Aws/TranscriptionFileService.php` | `drive/tests/transcribe_s3_recovery_contract_smoke.php` |
| `drive/src/Aws/TranslateFileService.php` | ninguna |
| `drive/src/Console/MediaProcessingWorkerCommand.php` | `drive/tests/media_processing_contract_smoke.php` |
| `drive/src/Console/MoveJobWorkerCommand.php` | ninguna |
| `drive/src/Console/SyncWorkerCommand.php` | ninguna |
| `drive/src/Console/UploadCleanupCommand.php` | ninguna |
| `drive/src/Core/ApplicationKernel.php` | ninguna |
| `drive/src/Core/BackgroundWorkerLease.php` | ninguna |
| `drive/src/Core/DriveApplication.php` | ninguna |
| `drive/src/Federation/ArcadeLinkFileFormat.php` | `drive/tests/arcadelink_collection_regression.php` |
| `drive/src/Federation/ArcadeLinkService.php` | `drive/bin/federation_provider_request.php`, `drive/tests/arcadelink_collection_regression.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederatedCatalogRepository.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederatedResourceRepository.php` | `drive/tests/arcadelink_bulk_contract_regression.php` |
| `drive/src/Federation/FederationAccessMessageCodec.php` | `drive/tests/federation_access_message_smoke.php` |
| `drive/src/Federation/FederationAccessRepository.php` | ninguna |
| `drive/src/Federation/FederationAccessService.php` | ninguna |
| `drive/src/Federation/FederationCatalogService.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationCodec.php` | `drive/bin/federation_identity_backup.php`, `drive/bin/federation_identity_init.php`, `drive/bin/federation_identity_name.php`, `drive/bin/federation_identity_restore.php`, `drive/bin/federation_provider_request.php`, `drive/tests/federation_access_message_smoke.php`, `drive/tests/federation_catalog_event_smoke.php`, `drive/tests/federation_directory_smoke.php`, `drive/tests/federation_drop_ingress_smoke.php`, `drive/tests/federation_node_name_admin_smoke.php`, `drive/tests/federation_provider_smoke.php`, `drive/tests/federation_replica_smoke.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationConfig.php` | `drive/bin/federation_provider_request.php`, `drive/tests/federation_directory_smoke.php`, `drive/tests/federation_node_name_admin_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationContentFingerprintService.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederationCustomsService.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationDirectoryService.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationDropAccountRepository.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Federation/FederationDropConfig.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Federation/FederationDropEncryptedCookie.php` | `drive/tests/federation_drop_google_oidc_smoke.php` |
| `drive/src/Federation/FederationDropGoogleAuthConfig.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Federation/FederationDropGoogleAuthService.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Federation/FederationDropGoogleOidcClient.php` | `drive/tests/federation_drop_google_oidc_smoke.php` |
| `drive/src/Federation/FederationDropIngressCodec.php` | `drive/tests/federation_drop_ingress_smoke.php` |
| `drive/src/Federation/FederationDropIngressDownloader.php` | ninguna |
| `drive/src/Federation/FederationDropIngressRepository.php` | `drive/tests/federation_drop_ingress_smoke.php` |
| `drive/src/Federation/FederationDropIngressService.php` | `drive/tests/federation_drop_ingress_smoke.php` |
| `drive/src/Federation/FederationDropRepository.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Federation/FederationDropService.php` | `drive/tests/federation_drop_contract_smoke.php`, `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederationDropStorageService.php` | `drive/tests/federation_drop_contract_smoke.php`, `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederationDropStripeCheckoutService.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Federation/FederationDropStripeClient.php` | `drive/tests/federation_drop_contract_smoke.php`, `drive/tests/federation_drop_stripe_smoke.php` |
| `drive/src/Federation/FederationDropStripeWebhookVerifier.php` | `drive/tests/federation_drop_contract_smoke.php`, `drive/tests/federation_drop_stripe_smoke.php` |
| `drive/src/Federation/FederationDropWorkerService.php` | ninguna |
| `drive/src/Federation/FederationEndpointResolver.php` | `drive/bin/federation_https_reconcile.php`, `drive/tests/federation_endpoint_resolver_smoke.php` |
| `drive/src/Federation/FederationEventCodec.php` | `drive/tests/federation_catalog_event_smoke.php`, `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederationEventStore.php` | ninguna |
| `drive/src/Federation/FederationException.php` | `drive/bin/federation_https_reconcile.php`, `drive/bin/federation_identity_backup.php`, `drive/bin/federation_identity_init.php`, `drive/bin/federation_identity_name.php`, `drive/bin/federation_identity_restore.php`, `drive/bin/federation_provider_request.php`, `drive/tests/federation_access_message_smoke.php`, `drive/tests/federation_catalog_event_smoke.php`, `drive/tests/federation_directory_smoke.php`, `drive/tests/federation_drop_google_oidc_smoke.php`, `drive/tests/federation_drop_ingress_smoke.php`, `drive/tests/federation_drop_stripe_smoke.php`, `drive/tests/federation_endpoint_resolver_smoke.php`, `drive/tests/federation_node_name_admin_smoke.php`, `drive/tests/federation_provider_smoke.php`, `drive/tests/federation_public_download_failover_smoke.php`, `drive/tests/federation_replica_smoke.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationGossipService.php` | ninguna |
| `drive/src/Federation/FederationHttpClient.php` | `drive/bin/federation_provider_request.php`, `drive/tests/federation_public_download_failover_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationIngressQueueRepository.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationLocationSelector.php` | `drive/tests/federation_public_download_failover_smoke.php`, `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationModerationRepository.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederationModerationSchemaService.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederationModerationService.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederationMultiSourceDownloader.php` | `drive/tests/federation_drop_contract_smoke.php`, `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationNodeAdminService.php` | `drive/tests/setup_finalize_contract_smoke.php` |
| `drive/src/Federation/FederationNodeDescriptorValidator.php` | `drive/bin/federation_provider_request.php`, `drive/tests/federation_directory_smoke.php` |
| `drive/src/Federation/FederationNodeRepository.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationOriginStorageResolver.php` | `drive/tests/federation_public_download_failover_smoke.php` |
| `drive/src/Federation/FederationPeerSyncRepository.php` | ninguna |
| `drive/src/Federation/FederationProviderAuthorizationRepository.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationProviderAuthorizationService.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationProviderGrant.php` | `drive/bin/federation_provider_request.php`, `drive/tests/federation_provider_smoke.php`, `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationPublicImportRepository.php` | ninguna |
| `drive/src/Federation/FederationPublicImportService.php` | ninguna |
| `drive/src/Federation/FederationReplicaDownloader.php` | `drive/tests/federation_drop_ingress_smoke.php`, `drive/tests/federation_public_download_failover_smoke.php` |
| `drive/src/Federation/FederationReplicaMessageCodec.php` | `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationReplicaPresenceService.php` | `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationReplicaRepository.php` | `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationReplicaResolverService.php` | `drive/tests/federation_drop_contract_smoke.php`, `drive/tests/federation_public_download_failover_smoke.php`, `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationReplicaService.php` | `drive/tests/federation_public_download_failover_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php`, `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationResolverService.php` | ninguna |
| `drive/src/Federation/FederationSchemaMigrationService.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/installer_service_reconcile_contract_smoke.php` |
| `drive/src/Federation/FederationSeedConfig.php` | `drive/tests/federation_directory_smoke.php` |
| `drive/src/Federation/FederationService.php` | `drive/tests/arcadelink_bulk_contract_regression.php`, `drive/tests/arcadelink_collection_regression.php`, `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Federation/FederationShareDownloader.php` | ninguna |
| `drive/src/Federation/FederationShareDriveRepository.php` | ninguna |
| `drive/src/Federation/FederationShareDriveService.php` | ninguna |
| `drive/src/Federation/FederationSourceFailover.php` | `drive/tests/federation_public_download_failover_smoke.php` |
| `drive/src/Federation/FederationSyncConfig.php` | ninguna |
| `drive/src/Federation/FederationSyncCycleService.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_moderation_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/NodeIdentityBackupService.php` | `drive/bin/federation_identity_backup.php`, `drive/bin/federation_identity_restore.php`, `drive/tests/federation_directory_smoke.php` |
| `drive/src/Federation/NodeIdentityService.php` | `drive/bin/federation_identity_backup.php`, `drive/bin/federation_identity_init.php`, `drive/bin/federation_identity_name.php`, `drive/bin/federation_identity_restore.php`, `drive/bin/federation_provider_request.php`, `drive/tests/federation_access_message_smoke.php`, `drive/tests/federation_catalog_event_smoke.php`, `drive/tests/federation_directory_smoke.php`, `drive/tests/federation_drop_ingress_smoke.php`, `drive/tests/federation_node_name_admin_smoke.php`, `drive/tests/federation_provider_smoke.php`, `drive/tests/federation_replica_smoke.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Http/BinaryResponse.php` | ninguna |
| `drive/src/Http/ByteRange.php` | ninguna |
| `drive/src/Http/Controller/AbstractJsonController.php` | ninguna |
| `drive/src/Http/Controller/ActivityCostController.php` | ninguna |
| `drive/src/Http/Controller/ArcadeCloudUpdateController.php` | ninguna |
| `drive/src/Http/Controller/AudioRecordingUploadController.php` | ninguna |
| `drive/src/Http/Controller/AuthController.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Http/Controller/AwsCostController.php` | ninguna |
| `drive/src/Http/Controller/AwsFileController.php` | ninguna |
| `drive/src/Http/Controller/BackgroundTaskCompatibilityController.php` | ninguna |
| `drive/src/Http/Controller/BackgroundTaskController.php` | `drive/tests/transcribe_s3_recovery_contract_smoke.php` |
| `drive/src/Http/Controller/FederationAccessController.php` | ninguna |
| `drive/src/Http/Controller/FederationCatalogController.php` | ninguna |
| `drive/src/Http/Controller/FederationCollectionController.php` | `drive/tests/arcadelink_bulk_contract_regression.php`, `drive/tests/arcadelink_collection_regression.php` |
| `drive/src/Http/Controller/FederationController.php` | `drive/tests/arcadelink_collection_regression.php` |
| `drive/src/Http/Controller/FederationDirectoryController.php` | ninguna |
| `drive/src/Http/Controller/FederationDropController.php` | `drive/tests/arcadelink_collection_regression.php`, `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Http/Controller/FederationDropGoogleAuthController.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Http/Controller/FederationDropIngressController.php` | `drive/tests/federation_drop_ingress_smoke.php` |
| `drive/src/Http/Controller/FederationModerationController.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Http/Controller/FederationNodeAdminController.php` | ninguna |
| `drive/src/Http/Controller/FederationPortalController.php` | ninguna |
| `drive/src/Http/Controller/FederationProviderController.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Http/Controller/FederationPublicImportController.php` | ninguna |
| `drive/src/Http/Controller/FederationReplicaController.php` | `drive/tests/federation_public_download_failover_smoke.php` |
| `drive/src/Http/Controller/FederationShareDriveController.php` | ninguna |
| `drive/src/Http/Controller/FileAccessController.php` | `drive/tests/arcadelink_bulk_contract_regression.php` |
| `drive/src/Http/Controller/FileKeyRotationController.php` | ninguna |
| `drive/src/Http/Controller/FileMutationController.php` | ninguna |
| `drive/src/Http/Controller/FileSearchController.php` | ninguna |
| `drive/src/Http/Controller/FileSecurityController.php` | ninguna |
| `drive/src/Http/Controller/FolderDocumentController.php` | ninguna |
| `drive/src/Http/Controller/FolderMutationController.php` | ninguna |
| `drive/src/Http/Controller/FolderQueryController.php` | ninguna |
| `drive/src/Http/Controller/LegacyUploadController.php` | ninguna |
| `drive/src/Http/Controller/MediaPlaylistController.php` | ninguna |
| `drive/src/Http/Controller/MediaProcessingController.php` | `drive/tests/media_processing_contract_smoke.php` |
| `drive/src/Http/Controller/MoveJobController.php` | ninguna |
| `drive/src/Http/Controller/NavigationController.php` | ninguna |
| `drive/src/Http/Controller/PersonalAwsController.php` | ninguna |
| `drive/src/Http/Controller/PollyTaskController.php` | ninguna |
| `drive/src/Http/Controller/PublicShareController.php` | ninguna |
| `drive/src/Http/Controller/PublicSharedBrowserController.php` | ninguna |
| `drive/src/Http/Controller/PublicUploadController.php` | ninguna |
| `drive/src/Http/Controller/ServerSettingsAdminController.php` | ninguna |
| `drive/src/Http/Controller/ShareController.php` | ninguna |
| `drive/src/Http/Controller/StorageUsageController.php` | ninguna |
| `drive/src/Http/Controller/SyncController.php` | ninguna |
| `drive/src/Http/Controller/TextEditorController.php` | ninguna |
| `drive/src/Http/Controller/ThumbnailController.php` | ninguna |
| `drive/src/Http/Controller/TranscriptionController.php` | `drive/tests/transcribe_s3_recovery_contract_smoke.php` |
| `drive/src/Http/Controller/UploadCleanupController.php` | ninguna |
| `drive/src/Http/Controller/UploadController.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Http/Controller/UserProfileController.php` | ninguna |
| `drive/src/Http/JsonResponse.php` | ninguna |
| `drive/src/Http/Request.php` | ninguna |
| `drive/src/Mail/SmtpConfig.php` | `drive/tests/smtp_config_smoke.php` |
| `drive/src/Mail/SmtpEmailService.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/Media/MediaPlaylistRepository.php` | ninguna |
| `drive/src/Media/MediaPlaylistService.php` | ninguna |
| `drive/src/Media/MediaProcessingJobRepository.php` | `drive/tests/media_processing_contract_smoke.php` |
| `drive/src/Media/MediaProcessingService.php` | `drive/tests/media_processing_contract_smoke.php` |
| `drive/src/Media/MediaWorkerNodeService.php` | `drive/tests/media_processing_contract_smoke.php` |
| `drive/src/Media/MediaWorkerNodeSessionRepository.php` | `drive/tests/media_processing_contract_smoke.php` |
| `drive/src/Media/ThumbnailService.php` | ninguna |
| `drive/src/Security/AuthenticationRepository.php` | ninguna |
| `drive/src/Security/AuthenticationService.php` | ninguna |
| `drive/src/Security/FileSecurityRepository.php` | ninguna |
| `drive/src/Security/FileSecurityService.php` | ninguna |
| `drive/src/Security/LoginRateLimiter.php` | `drive/tests/security_hardening_smoke.php` |
| `drive/src/Security/PasswordChangeService.php` | ninguna |
| `drive/src/Security/PasswordCredentialVerifier.php` | `drive/tests/password_credential_verifier_smoke.php` |
| `drive/src/Security/PersonalToolAccessService.php` | ninguna |
| `drive/src/Security/SessionManager.php` | ninguna |
| `drive/src/Security/SuperAdminReauthenticationService.php` | ninguna |
| `drive/src/Security/UserDirectoryRepository.php` | ninguna |
| `drive/src/Security/UserProfileRepository.php` | ninguna |
| `drive/src/Security/UserProfileService.php` | ninguna |
| `drive/src/Security/UserProfileValidator.php` | `drive/tests/user_profile_validator_smoke.php` |
| `drive/src/Setup/SetupApiController.php` | `drive/setup/api.php` |
| `drive/src/Setup/SetupConfigurationService.php` | `drive/setup/api.php` |
| `drive/src/Setup/SuperAdminBootstrapService.php` | `drive/setup/api.php`, `drive/tests/setup_finalize_contract_smoke.php` |
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
| `drive/src/Sync/NodeSyncService.php` | ninguna |
| `drive/src/Sync/S3SyncService.php` | ninguna |
| `drive/src/Sync/SyncJobStore.php` | ninguna |
| `drive/src/Sync/SyncRepository.php` | `drive/tests/scoped_sync_repository_regression.php`, `drive/tests/sync_repository_regression.php` |
| `drive/src/Sync/SyncSchemaMigrator.php` | `drive/tests/sync_schema_migrator_regression.php` |
| `drive/src/Upload/AdminMultipartUploadService.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Upload/PublicDropzoneUploadService.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Upload/PublicMultipartUploadService.php` | ninguna |
| `drive/src/Upload/PublicSharedBrowserRepository.php` | ninguna |
| `drive/src/Upload/PublicSharedBrowserService.php` | ninguna |
| `drive/src/Upload/SingleUploadService.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/Upload/UploadCatalogRepository.php` | `drive/tests/federation_moderation_contract_smoke.php`, `drive/tests/upload_catalog_registration_regression.php` |
| `drive/src/Upload/UploadCleanupService.php` | ninguna |
| `drive/src/View/ActivityCostPageRenderer.php` | ninguna |
| `drive/src/View/Ec2PanelHelper.php` | ninguna |
| `drive/src/View/FederationDropPageRenderer.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/View/FederationModerationPageRenderer.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/View/FederationPageRenderer.php` | `drive/tests/arcadelink_collection_regression.php`, `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/View/FederationPortalRenderer.php` | `drive/tests/federation_drop_contract_smoke.php` |
| `drive/src/View/FederationReportPageRenderer.php` | `drive/tests/federation_moderation_contract_smoke.php` |
| `drive/src/View/FileIconResolver.php` | ninguna |
| `drive/src/View/FileViewHelper.php` | `drive/tests/federation_public_download_failover_smoke.php` |
| `drive/src/View/FolderTreeRenderer.php` | ninguna |
| `drive/src/View/PersonalAwsPageRenderer.php` | ninguna |
| `drive/src/View/PublicSharedPageRenderer.php` | ninguna |
| `drive/src/View/SharePageRenderer.php` | ninguna |
| `drive/src/View/SyncStatusRenderer.php` | ninguna |
| `drive/src/View/UserIdentityPresenter.php` | `drive/tests/user_identity_presenter_smoke.php` |
| `drive/src/View/UserProfileModalRenderer.php` | ninguna |
| `drive/storage_usage.php` | `drive/js/storage-usage.js` |
| `drive/subir_archivo.php` | ninguna |
| `drive/subir_publico.php` | ninguna |
| `drive/sync_s3_to_db.php` | `drive/js/sincronizar.js` |
| `drive/sync_status.php` | `drive/js/sincronizar.js` |
| `drive/tests/activity_costs_smoke.php` | ninguna |
| `drive/tests/arcadelink_bulk_contract_regression.php` | ninguna |
| `drive/tests/arcadelink_collection_regression.php` | ninguna |
| `drive/tests/database_schema_contract_smoke.php` | ninguna |
| `drive/tests/federation_access_message_smoke.php` | ninguna |
| `drive/tests/federation_catalog_event_smoke.php` | ninguna |
| `drive/tests/federation_customs_contract_smoke.php` | ninguna |
| `drive/tests/federation_directory_smoke.php` | ninguna |
| `drive/tests/federation_drop_contract_smoke.php` | ninguna |
| `drive/tests/federation_drop_google_oidc_smoke.php` | ninguna |
| `drive/tests/federation_drop_ingress_smoke.php` | ninguna |
| `drive/tests/federation_drop_stripe_smoke.php` | ninguna |
| `drive/tests/federation_endpoint_resolver_smoke.php` | ninguna |
| `drive/tests/federation_https_contract_smoke.php` | ninguna |
| `drive/tests/federation_moderation_contract_smoke.php` | ninguna |
| `drive/tests/federation_node_name_admin_smoke.php` | ninguna |
| `drive/tests/federation_provider_smoke.php` | ninguna |
| `drive/tests/federation_public_download_failover_smoke.php` | ninguna |
| `drive/tests/federation_replica_reconnect_contract_smoke.php` | ninguna |
| `drive/tests/federation_replica_smoke.php` | ninguna |
| `drive/tests/federationcloud_smoke.php` | ninguna |
| `drive/tests/folder_document_sanitizer.php` | ninguna |
| `drive/tests/index_federation_drop_smoke.php` | ninguna |
| `drive/tests/installer_service_reconcile_contract_smoke.php` | ninguna |
| `drive/tests/media_processing_contract_smoke.php` | ninguna |
| `drive/tests/password_credential_verifier_smoke.php` | ninguna |
| `drive/tests/scoped_sync_repository_regression.php` | ninguna |
| `drive/tests/security_hardening_smoke.php` | ninguna |
| `drive/tests/server_admin_config_smoke.php` | ninguna |
| `drive/tests/setup_bootstrap_smoke.php` | ninguna |
| `drive/tests/setup_entry_guard_smoke.php` | ninguna |
| `drive/tests/setup_finalize_contract_smoke.php` | ninguna |
| `drive/tests/smtp_config_smoke.php` | ninguna |
| `drive/tests/sync_repository_regression.php` | ninguna |
| `drive/tests/sync_schema_migrator_regression.php` | ninguna |
| `drive/tests/transcribe_s3_recovery_contract_smoke.php` | ninguna |
| `drive/tests/upload_catalog_registration_regression.php` | ninguna |
| `drive/tests/user_identity_presenter_smoke.php` | ninguna |
| `drive/tests/user_profile_validator_smoke.php` | ninguna |
| `drive/token_audio.php` | `drive/src/Sharing/ShareLinkService.php` |
| `drive/token_texto.php` | `drive/src/Sharing/ShareLinkService.php`, `drive/tests/federation_access_message_smoke.php` |
| `drive/token_video.php` | `drive/src/Sharing/ShareLinkService.php`, `drive/js/audiovideo.js` |
| `drive/traducir_archivo.php` | `drive/js/polly.js` |
| `drive/transcribir_estado.php` | `drive/js/polly.js`, `drive/js/transcribe-background.js` |
| `drive/transcribir_iniciar.php` | `drive/js/polly.js`, `drive/js/transcribe-background.js` |
| `drive/unlock_file.php` | `drive/js/archivos.js` |
| `drive/up-clean.php` | ninguna |
| `drive/update.php` | `drive/js/arcadecloud-updater.js` |
| `drive/upload/ModerationUploadGuard.php` | `drive/tests/federation_moderation_contract_smoke.php`, `drive/upload/drivers/Chunked15MBUploader.php`, `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/LocalPresignedPutUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php` |
| `drive/upload/UploadFactory.php` | `drive/src/Core/DriveApplication.php` |
| `drive/upload/core/UploadResponse.php` | ninguna |
| `drive/upload/core/UploaderInterface.php` | `drive/tests/security_hardening_smoke.php`, `drive/upload/UploadFactory.php`, `drive/upload/drivers/Chunked15MBUploader.php`, `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/LocalPresignedPutUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php` |
| `drive/upload/drivers/Chunked15MBUploader.php` | `drive/tests/federation_moderation_contract_smoke.php`, `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/DropboxUploader.php` | `drive/tests/federation_moderation_contract_smoke.php`, `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/LocalPresignedPutUploader.php` | `drive/tests/federation_moderation_contract_smoke.php`, `drive/upload/UploadFactory.php` |
| `drive/upload/drivers/RemoteUrlUploader.php` | `drive/tests/federation_moderation_contract_smoke.php`, `drive/tests/security_hardening_smoke.php`, `drive/upload/UploadFactory.php` |
| `drive/upload/repositories/FileS3Repository.php` | `drive/tests/security_hardening_smoke.php`, `drive/upload/drivers/Chunked15MBUploader.php`, `drive/upload/drivers/DropboxUploader.php`, `drive/upload/drivers/LocalPresignedPutUploader.php`, `drive/upload/drivers/RemoteUrlUploader.php` |
| `drive/upload/storage/UploadStateStore.php` | `drive/upload/UploadFactory.php`, `drive/upload/drivers/Chunked15MBUploader.php` |
| `drive/upload_audio_recording.php` | ninguna |
| `drive/upload_publico.php` | `drive/src/View/PublicSharedPageRenderer.php` |
| `drive/ver.php` | `drive/bin/federation_https_reconcile.php`, `drive/tests/federation_endpoint_resolver_smoke.php`, `drive/tests/federation_public_download_failover_smoke.php` |
| `drive/ver_pdf.php` | `drive/js/ver-pdf.js` |
