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
| `drive/app_bootstrap.php` | `drive/activity_costs.php`, `drive/actualizar_ruta.php`, `drive/api/upload.php`, `drive/aws.php`, `drive/bin/federation_catalog_migrate.php`, `drive/bin/federation_endpoint_refresh.php`, `drive/bin/federation_replica_presence.php`, `drive/bin/federation_sync.php`, `drive/bin/sync_node_worker.php`, `drive/bin/sync_schema_migrate.php`, `drive/bin/sync_worker.php`, `drive/bin/upload_cleanup.php`, `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/buscar_archivo.php`, `drive/comprehend_archivo.php`, `drive/costos_aws.php`, `drive/crear_carpeta.php`, `drive/delete_multiple.php`, `drive/descargar.php`, `drive/descargar_archivo.php`, `drive/descargar_zip.php`, `drive/download.php`, `drive/download_multiple.php`, `drive/ec2-cron.php`, `drive/ec2.php`, `drive/eliminar_archivo.php`, `drive/eliminar_carpeta.php`, `drive/encriptar_archivo.php`, `drive/federationcloud/access-request.php`, `drive/federationcloud/access-status.php`, `drive/federationcloud/access.php`, `drive/federationcloud/bundle.php`, `drive/federationcloud/create.php`, `drive/federationcloud/index.php`, `drive/federationcloud/name-availability.php`, `drive/federationcloud/node-admin.php`, `drive/federationcloud/node.php`, `drive/federationcloud/nodes.php`, `drive/federationcloud/portal.php`, `drive/federationcloud/provider-admin.php`, `drive/federationcloud/provider-presence.php`, `drive/federationcloud/provider-request.php`, `drive/federationcloud/providers.php`, `drive/federationcloud/register.php`, `drive/federationcloud/replica-offer.php`, `drive/federationcloud/replica-open.php`, `drive/federationcloud/replica-resolve.php`, `drive/federationcloud/replica.php`, `drive/federationcloud/resolve.php`, `drive/federationcloud/resource.php`, `drive/federationcloud/search.php`, `drive/federationcloud/share-drive.php`, `drive/federationcloud/sync-pull.php`, `drive/federationcloud/sync-push.php`, `drive/federationcloud/sync-status.php`, `drive/generar_token.php`, `drive/guardar_texto.php`, `drive/leer_texto.php`, `drive/listar_carpetas.php`, `drive/logout.php`, `drive/media_playlist.php`, `drive/move_multiple.php`, `drive/move_task.php`, `drive/move_task_status.php`, `drive/mover_archivo.php`, `drive/mover_carpeta.php`, `drive/polly_cargar_texto.php`, `drive/polly_list_voices.php`, `drive/polly_tts.php`, `drive/procesar_textract.php`, `drive/profile.php`, `drive/psesion.php`, `drive/rekognition_labels.php`, `drive/relock_file.php`, `drive/renombrar_archivo.php`, `drive/renombrar_carpeta.php`, `drive/s3.php`, `drive/server-settings.php`, `drive/set_file_security.php`, `drive/storage_usage.php`, `drive/subir_archivo.php`, `drive/subir_publico.php`, `drive/sync_s3_to_db.php`, `drive/sync_status.php`, `drive/thumb.php`, `drive/token_audio.php`, `drive/token_texto.php`, `drive/token_video.php`, `drive/traducir_archivo.php`, `drive/transcribir_estado.php`, `drive/transcribir_iniciar.php`, `drive/unlock_file.php`, `drive/up-clean.php`, `drive/up.php`, `drive/upload.php`, `drive/upload_audio_recording.php`, `drive/upload_publico.php`, `drive/validar_php.php`, `drive/ver.php`, `drive/ver_archivo.php`, `drive/ver_pdf.php` |
| `drive/aws.php` | `drive/ec2.php`, `drive/s3.php`, `drive/src/Http/Controller/PersonalAwsController.php`, `drive/js/estilo.js` |
| `drive/bloque_archivos.php` | `drive/s3.php`, `drive/js/archivos.js`, `drive/js/carpetas.js`, `drive/js/elimina-multiple.js`, `drive/js/elimina-uno.js`, `drive/js/imagenes.js`, `drive/js/obtenerFiltros.js` |
| `drive/bloque_carpetas.php` | `drive/s3.php`, `drive/js/carpetas.js`, `drive/js/obtenerFiltros.js` |
| `drive/bloque_footer.php` | `drive/s3.php` |
| `drive/costos_aws.php` | `drive/s3.php` |
| `drive/delete_multiple.php` | `drive/s3.php`, `drive/js/elimina-multiple.js`, `drive/js/file-block.js` |
| `drive/descargar_archivo.php` | `drive/bloque_archivos.php`, `drive/tests/arcadelink_bulk_contract_regression.php` |
| `drive/ec2.php` | `drive/s3.php`, `drive/src/View/PersonalAwsPageRenderer.php`, `drive/js/estilo.js` |
| `drive/editor.php` | `drive/bloque_archivos.php`, `drive/js/editar-txt.js` |
| `drive/federationcloud/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Security/SessionManager.php`, `drive/up.php` |
| `drive/federationcloud/portal.php` | `drive/bloque_carpetas.php`, `drive/js/arcadelink-share.js` |
| `drive/guardar_texto.php` | `drive/editor.php` |
| `drive/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Security/SessionManager.php`, `drive/up.php` |
| `drive/leer_texto.php` | `drive/editor.php` |
| `drive/login.php` | entrada directa |
| `drive/logout.php` | `drive/s3.php` |
| `drive/psesion.php` | `drive/index.php`, `drive/login.php` |
| `drive/s3.php` | `drive/app_bootstrap.php`, `drive/ec2.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/View/ActivityCostPageRenderer.php`, `drive/src/View/FederationPageRenderer.php`, `drive/src/View/FederationPortalRenderer.php`, `drive/src/View/PersonalAwsPageRenderer.php`, `drive/up.php`, `drive/js/archivos.js`, `drive/js/carpetas.js`, `drive/js/federation-share-drive.js`, `drive/js/subir.js` |
| `drive/setup/index.php` | `drive/bloque_archivos.php`, `drive/bloque_carpetas.php`, `drive/s3.php`, `drive/src/Http/Controller/ActivityCostController.php`, `drive/src/Http/Controller/AuthController.php`, `drive/src/Security/SessionManager.php`, `drive/up.php` |
| `drive/src/Admin/ManagedRuntimeEnvironment.php` | `drive/app_bootstrap.php`, `drive/setup/api.php`, `drive/tests/server_admin_config_smoke.php` |
| `drive/src/Setup/BootstrapSetupAuth.php` | `drive/setup/api.php`, `drive/setup/index.php`, `drive/tests/setup_bootstrap_smoke.php` |
| `drive/thumb.php` | `drive/bloque_archivos.php`, `drive/js/imagenes.js` |
| `drive/up.php` | `drive/src/Http/Controller/UploadCleanupController.php`, `drive/src/Upload/AdminMultipartUploadService.php`, `drive/tests/upload_catalog_registration_regression.php`, `drive/js/estilo.js` |
| `drive/upload.php` | `drive/s3.php`, `drive/js/soportesMediaTypes.js`, `drive/js/subir-chunked.js`, `drive/js/subir-dropzone.js`, `drive/js/subir.js` |
| `drive/validar_php.php` | `drive/editor.php` |
| `drive/ver_archivo.php` | `drive/bloque_archivos.php`, `drive/src/Media/MediaPlaylistService.php`, `drive/js/archivos.js`, `drive/js/imagenes.js` |

## PHP no alcanzables por referencias internas

| Archivo | Referencias encontradas |
|---|---|
| `drive/actualizar_ruta.php` | `drive/js/carpetas.js`, `drive/js/obtenerFiltros.js` |
| `drive/bin/arcadecloud-drive-admin-helper.php` | ninguna |
| `drive/bin/federation_catalog_migrate.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/bin/federation_endpoint_refresh.php` | `drive/bin/federation_https_reconcile.php`, `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_https_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/bin/federation_https_reconcile.php` | `drive/tests/federation_https_contract_smoke.php` |
| `drive/bin/federation_identity_backup.php` | ninguna |
| `drive/bin/federation_identity_init.php` | ninguna |
| `drive/bin/federation_identity_name.php` | ninguna |
| `drive/bin/federation_identity_restore.php` | ninguna |
| `drive/bin/federation_provider_request.php` | `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/bin/federation_replica_presence.php` | ninguna |
| `drive/bin/federation_sync.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/bin/sync_node_worker.php` | ninguna |
| `drive/bin/sync_schema_migrate.php` | ninguna |
| `drive/bin/sync_worker.php` | `drive/src/Http/Controller/SyncController.php` |
| `drive/bin/upload_cleanup.php` | `drive/src/Http/Controller/UploadCleanupController.php` |
| `drive/buscar_archivo.php` | `drive/js/ai-search.js`, `drive/js/archivos.js` |
| `drive/comprehend_archivo.php` | `drive/js/aws-comprehend.js` |
| `drive/crear_carpeta.php` | `drive/js/carpetas.js` |
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
| `drive/federationcloud/bundle.php` | `drive/tests/arcadelink_bulk_contract_regression.php`, `drive/js/arcadelink-share.js` |
| `drive/federationcloud/create.php` | `drive/js/arcadelink-share.js` |
| `drive/federationcloud/name-availability.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationNodeAdminService.php` |
| `drive/federationcloud/node-admin.php` | `drive/js/federation-footer.js` |
| `drive/federationcloud/node.php` | `drive/bin/federation_provider_request.php`, `drive/src/Federation/FederationCustomsService.php`, `drive/src/Federation/FederationDirectoryService.php`, `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationProviderAuthorizationService.php`, `drive/src/Federation/FederationReplicaPresenceService.php`, `drive/src/Federation/FederationResolverService.php`, `drive/tests/federation_customs_contract_smoke.php` |
| `drive/federationcloud/nodes.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/js/federation-footer.js` |
| `drive/federationcloud/provider-admin.php` | `drive/js/federation-footer.js` |
| `drive/federationcloud/provider-presence.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationReplicaPresenceService.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/federationcloud/provider-request.php` | `drive/bin/federation_provider_request.php`, `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationProviderAuthorizationService.php`, `drive/src/Federation/FederationReplicaPresenceService.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/federationcloud/providers.php` | `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/register.php` | `drive/src/Federation/FederationCustomsService.php`, `drive/src/Federation/FederationDirectoryService.php`, `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationNodeAdminService.php`, `drive/src/Http/Controller/FederationProviderController.php`, `drive/tests/federation_customs_contract_smoke.php` |
| `drive/federationcloud/replica-offer.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationReplicaService.php` |
| `drive/federationcloud/replica-open.php` | `drive/js/federation-portal.js` |
| `drive/federationcloud/replica-resolve.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationReplicaResolverService.php` |
| `drive/federationcloud/replica.php` | `drive/js/federation-portal.js` |
| `drive/federationcloud/resolve.php` | `drive/src/Federation/FederationHttpClient.php`, `drive/src/Federation/FederationReplicaResolverService.php`, `drive/src/Federation/FederationResolverService.php` |
| `drive/federationcloud/resource.php` | ninguna |
| `drive/federationcloud/search.php` | `drive/js/federation-portal.js` |
| `drive/federationcloud/share-drive.php` | `drive/js/federation-share-drive.js` |
| `drive/federationcloud/sync-pull.php` | `drive/src/Federation/FederationGossipService.php`, `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/sync-push.php` | `drive/src/Federation/FederationGossipService.php`, `drive/src/Federation/FederationHttpClient.php` |
| `drive/federationcloud/sync-status.php` | ninguna |
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
| `drive/profile.php` | `drive/js/profile.js` |
| `drive/rekognition_labels.php` | `drive/js/polly.js` |
| `drive/relock_file.php` | `drive/js/archivos.js` |
| `drive/renombrar_archivo.php` | `drive/js/archivos.js` |
| `drive/renombrar_carpeta.php` | `drive/js/carpetas.js` |
| `drive/server-settings.php` | `drive/js/server-admin.js` |
| `drive/set_file_security.php` | `drive/js/archivos.js` |
| `drive/setup/api.php` | `drive/js/setup.js` |
| `drive/src/Activity/ActivityCostRecorder.php` | ninguna |
| `drive/src/Activity/ActivityCostRepository.php` | ninguna |
| `drive/src/Activity/ActivityCostService.php` | ninguna |
| `drive/src/Activity/AwsUnitPriceCatalog.php` | ninguna |
| `drive/src/Admin/PrivilegedServerHelper.php` | `drive/setup/api.php` |
| `drive/src/Admin/ServerSettingsAdminService.php` | ninguna |
| `drive/src/Application/AiFileSearchService.php` | ninguna |
| `drive/src/Application/DrivePageService.php` | ninguna |
| `drive/src/Application/DrivePageViewModel.php` | ninguna |
| `drive/src/Application/FileAccessService.php` | `drive/tests/arcadelink_bulk_contract_regression.php` |
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
| `drive/src/Aws/SesEmailService.php` | ninguna |
| `drive/src/Aws/TextractFileService.php` | ninguna |
| `drive/src/Aws/TranscriptionFileService.php` | ninguna |
| `drive/src/Aws/TranslateFileService.php` | ninguna |
| `drive/src/Console/UploadCleanupCommand.php` | ninguna |
| `drive/src/Core/ApplicationKernel.php` | ninguna |
| `drive/src/Core/DriveApplication.php` | ninguna |
| `drive/src/Federation/ArcadeLinkBundleService.php` | `drive/tests/arcadelink_bundle_regression.php` |
| `drive/src/Federation/ArcadeLinkService.php` | `drive/bin/federation_provider_request.php`, `drive/tests/arcadelink_bundle_regression.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederatedCatalogRepository.php` | ninguna |
| `drive/src/Federation/FederatedResourceRepository.php` | `drive/tests/arcadelink_bulk_contract_regression.php` |
| `drive/src/Federation/FederationAccessMessageCodec.php` | `drive/tests/federation_access_message_smoke.php` |
| `drive/src/Federation/FederationAccessRepository.php` | ninguna |
| `drive/src/Federation/FederationAccessService.php` | ninguna |
| `drive/src/Federation/FederationCatalogService.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationCodec.php` | `drive/bin/federation_identity_backup.php`, `drive/bin/federation_identity_init.php`, `drive/bin/federation_identity_name.php`, `drive/bin/federation_identity_restore.php`, `drive/bin/federation_provider_request.php`, `drive/tests/federation_access_message_smoke.php`, `drive/tests/federation_catalog_event_smoke.php`, `drive/tests/federation_directory_smoke.php`, `drive/tests/federation_node_name_admin_smoke.php`, `drive/tests/federation_provider_smoke.php`, `drive/tests/federation_replica_smoke.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationConfig.php` | `drive/bin/federation_provider_request.php`, `drive/tests/federation_directory_smoke.php`, `drive/tests/federation_node_name_admin_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationCustomsService.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationDirectoryService.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationEndpointResolver.php` | `drive/bin/federation_https_reconcile.php`, `drive/tests/federation_endpoint_resolver_smoke.php` |
| `drive/src/Federation/FederationEventCodec.php` | `drive/tests/federation_catalog_event_smoke.php` |
| `drive/src/Federation/FederationEventStore.php` | ninguna |
| `drive/src/Federation/FederationException.php` | `drive/bin/federation_https_reconcile.php`, `drive/bin/federation_identity_backup.php`, `drive/bin/federation_identity_init.php`, `drive/bin/federation_identity_name.php`, `drive/bin/federation_identity_restore.php`, `drive/bin/federation_provider_request.php`, `drive/tests/arcadelink_bundle_regression.php`, `drive/tests/federation_access_message_smoke.php`, `drive/tests/federation_catalog_event_smoke.php`, `drive/tests/federation_directory_smoke.php`, `drive/tests/federation_endpoint_resolver_smoke.php`, `drive/tests/federation_node_name_admin_smoke.php`, `drive/tests/federation_provider_smoke.php`, `drive/tests/federation_replica_smoke.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Federation/FederationGossipService.php` | ninguna |
| `drive/src/Federation/FederationHttpClient.php` | `drive/bin/federation_provider_request.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationIngressQueueRepository.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationLocationSelector.php` | `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationNodeAdminService.php` | ninguna |
| `drive/src/Federation/FederationNodeDescriptorValidator.php` | `drive/bin/federation_provider_request.php`, `drive/tests/federation_directory_smoke.php` |
| `drive/src/Federation/FederationNodeRepository.php` | `drive/tests/federation_customs_contract_smoke.php` |
| `drive/src/Federation/FederationPeerSyncRepository.php` | ninguna |
| `drive/src/Federation/FederationProviderAuthorizationRepository.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationProviderAuthorizationService.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationProviderGrant.php` | `drive/bin/federation_provider_request.php`, `drive/tests/federation_provider_smoke.php`, `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationReplicaDownloader.php` | ninguna |
| `drive/src/Federation/FederationReplicaMessageCodec.php` | `drive/tests/federation_replica_smoke.php` |
| `drive/src/Federation/FederationReplicaPresenceService.php` | `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationReplicaRepository.php` | ninguna |
| `drive/src/Federation/FederationReplicaResolverService.php` | ninguna |
| `drive/src/Federation/FederationReplicaService.php` | `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Federation/FederationResolverService.php` | ninguna |
| `drive/src/Federation/FederationSeedConfig.php` | `drive/tests/federation_directory_smoke.php` |
| `drive/src/Federation/FederationService.php` | `drive/tests/arcadelink_bulk_contract_regression.php` |
| `drive/src/Federation/FederationShareDownloader.php` | ninguna |
| `drive/src/Federation/FederationShareDriveRepository.php` | ninguna |
| `drive/src/Federation/FederationShareDriveService.php` | ninguna |
| `drive/src/Federation/FederationSyncConfig.php` | ninguna |
| `drive/src/Federation/NodeIdentityBackupService.php` | `drive/bin/federation_identity_backup.php`, `drive/bin/federation_identity_restore.php`, `drive/tests/federation_directory_smoke.php` |
| `drive/src/Federation/NodeIdentityService.php` | `drive/bin/federation_identity_backup.php`, `drive/bin/federation_identity_init.php`, `drive/bin/federation_identity_name.php`, `drive/bin/federation_identity_restore.php`, `drive/bin/federation_provider_request.php`, `drive/tests/federation_access_message_smoke.php`, `drive/tests/federation_catalog_event_smoke.php`, `drive/tests/federation_directory_smoke.php`, `drive/tests/federation_node_name_admin_smoke.php`, `drive/tests/federation_provider_smoke.php`, `drive/tests/federation_replica_smoke.php`, `drive/tests/federationcloud_smoke.php` |
| `drive/src/Http/BinaryResponse.php` | ninguna |
| `drive/src/Http/ByteRange.php` | ninguna |
| `drive/src/Http/Controller/AbstractJsonController.php` | ninguna |
| `drive/src/Http/Controller/ActivityCostController.php` | ninguna |
| `drive/src/Http/Controller/AuthController.php` | ninguna |
| `drive/src/Http/Controller/AwsCostController.php` | ninguna |
| `drive/src/Http/Controller/AwsFileController.php` | ninguna |
| `drive/src/Http/Controller/FederationAccessController.php` | ninguna |
| `drive/src/Http/Controller/FederationBundleController.php` | `drive/tests/arcadelink_bulk_contract_regression.php` |
| `drive/src/Http/Controller/FederationCatalogController.php` | ninguna |
| `drive/src/Http/Controller/FederationController.php` | ninguna |
| `drive/src/Http/Controller/FederationDirectoryController.php` | ninguna |
| `drive/src/Http/Controller/FederationNodeAdminController.php` | ninguna |
| `drive/src/Http/Controller/FederationPortalController.php` | ninguna |
| `drive/src/Http/Controller/FederationProviderController.php` | `drive/tests/federation_customs_contract_smoke.php`, `drive/tests/federation_replica_reconnect_contract_smoke.php` |
| `drive/src/Http/Controller/FederationReplicaController.php` | ninguna |
| `drive/src/Http/Controller/FederationShareDriveController.php` | ninguna |
| `drive/src/Http/Controller/FileAccessController.php` | `drive/tests/arcadelink_bulk_contract_regression.php` |
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
| `drive/src/Http/Controller/ServerSettingsAdminController.php` | ninguna |
| `drive/src/Http/Controller/ShareController.php` | ninguna |
| `drive/src/Http/Controller/StorageUsageController.php` | ninguna |
| `drive/src/Http/Controller/SyncController.php` | ninguna |
| `drive/src/Http/Controller/TextEditorController.php` | ninguna |
| `drive/src/Http/Controller/ThumbnailController.php` | ninguna |
| `drive/src/Http/Controller/TranscriptionController.php` | ninguna |
| `drive/src/Http/Controller/UploadCleanupController.php` | ninguna |
| `drive/src/Http/Controller/UploadController.php` | ninguna |
| `drive/src/Http/Controller/UserProfileController.php` | ninguna |
| `drive/src/Http/JsonResponse.php` | ninguna |
| `drive/src/Http/Request.php` | ninguna |
| `drive/src/Mail/SmtpConfig.php` | `drive/tests/smtp_config_smoke.php` |
| `drive/src/Mail/SmtpEmailService.php` | ninguna |
| `drive/src/Media/MediaPlaylistRepository.php` | ninguna |
| `drive/src/Media/MediaPlaylistService.php` | ninguna |
| `drive/src/Media/ThumbnailService.php` | ninguna |
| `drive/src/Security/AuthenticationRepository.php` | ninguna |
| `drive/src/Security/AuthenticationService.php` | ninguna |
| `drive/src/Security/FileSecurityRepository.php` | ninguna |
| `drive/src/Security/FileSecurityService.php` | ninguna |
| `drive/src/Security/PasswordChangeService.php` | ninguna |
| `drive/src/Security/PersonalToolAccessService.php` | ninguna |
| `drive/src/Security/SessionManager.php` | ninguna |
| `drive/src/Security/SuperAdminReauthenticationService.php` | ninguna |
| `drive/src/Security/UserDirectoryRepository.php` | ninguna |
| `drive/src/Security/UserProfileRepository.php` | ninguna |
| `drive/src/Security/UserProfileService.php` | ninguna |
| `drive/src/Security/UserProfileValidator.php` | `drive/tests/user_profile_validator_smoke.php` |
| `drive/src/Setup/SetupConfigurationService.php` | `drive/setup/api.php` |
| `drive/src/Setup/SuperAdminBootstrapService.php` | `drive/setup/api.php` |
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
| `drive/src/Upload/AdminMultipartUploadService.php` | ninguna |
| `drive/src/Upload/PublicDropzoneUploadService.php` | ninguna |
| `drive/src/Upload/PublicMultipartUploadService.php` | ninguna |
| `drive/src/Upload/PublicSharedBrowserRepository.php` | ninguna |
| `drive/src/Upload/PublicSharedBrowserService.php` | ninguna |
| `drive/src/Upload/SingleUploadService.php` | ninguna |
| `drive/src/Upload/UploadCatalogRepository.php` | `drive/tests/upload_catalog_registration_regression.php` |
| `drive/src/Upload/UploadCleanupService.php` | ninguna |
| `drive/src/View/ActivityCostPageRenderer.php` | ninguna |
| `drive/src/View/Ec2PanelHelper.php` | ninguna |
| `drive/src/View/FederationPageRenderer.php` | ninguna |
| `drive/src/View/FederationPortalRenderer.php` | ninguna |
| `drive/src/View/FileIconResolver.php` | ninguna |
| `drive/src/View/FileViewHelper.php` | ninguna |
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
| `drive/tests/arcadelink_bundle_regression.php` | ninguna |
| `drive/tests/federation_access_message_smoke.php` | ninguna |
| `drive/tests/federation_catalog_event_smoke.php` | ninguna |
| `drive/tests/federation_customs_contract_smoke.php` | ninguna |
| `drive/tests/federation_directory_smoke.php` | ninguna |
| `drive/tests/federation_endpoint_resolver_smoke.php` | ninguna |
| `drive/tests/federation_https_contract_smoke.php` | ninguna |
| `drive/tests/federation_node_name_admin_smoke.php` | ninguna |
| `drive/tests/federation_provider_smoke.php` | ninguna |
| `drive/tests/federation_replica_reconnect_contract_smoke.php` | ninguna |
| `drive/tests/federation_replica_smoke.php` | ninguna |
| `drive/tests/federationcloud_smoke.php` | ninguna |
| `drive/tests/scoped_sync_repository_regression.php` | ninguna |
| `drive/tests/server_admin_config_smoke.php` | ninguna |
| `drive/tests/setup_bootstrap_smoke.php` | ninguna |
| `drive/tests/smtp_config_smoke.php` | ninguna |
| `drive/tests/sync_repository_regression.php` | ninguna |
| `drive/tests/sync_schema_migrator_regression.php` | ninguna |
| `drive/tests/upload_catalog_registration_regression.php` | ninguna |
| `drive/tests/user_identity_presenter_smoke.php` | ninguna |
| `drive/tests/user_profile_validator_smoke.php` | ninguna |
| `drive/token_audio.php` | `drive/src/Sharing/ShareLinkService.php` |
| `drive/token_texto.php` | `drive/src/Sharing/ShareLinkService.php`, `drive/tests/federation_access_message_smoke.php` |
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
| `drive/ver.php` | `drive/bin/federation_https_reconcile.php`, `drive/tests/federation_endpoint_resolver_smoke.php` |
| `drive/ver_pdf.php` | `drive/js/ver-pdf.js` |
