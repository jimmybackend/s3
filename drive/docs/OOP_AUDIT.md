# Auditoría OOP — ArcadeCloud Drive

> Generado automáticamente. No sustituye pruebas funcionales; detecta estructura y dependencias procedurales.

## Resumen

- PHP analizados: **454**
- PHP que ya contienen clases/interfaces: **271**
- PHP marcados para migración/revisión: **0**
- Tests PHP separados del objetivo OOP de runtime: **42**
- JavaScript analizados: **48**
- JavaScript que ya contienen clases: **48**
- JavaScript sin clase/encapsulación OOP: **0**
- JavaScript OOP con fachada `window` de compatibilidad: **7**

## Criterio

- `src/` y `upload/`: lógica de negocio e infraestructura en clases.
- Entry points públicos: bootstrap + Controller/Service; sin SQL/AWS ni funciones globales.
- CLI: el archivo ejecutable puede ser procedural si es un wrapper delgado que delega en clases.
- Tests: se auditan, pero no cuentan como deuda OOP del runtime.
- Vistas: pueden contener HTML; funciones JavaScript incrustadas no se confunden con funciones PHP.
- JavaScript: comportamiento en clases; `window` sólo como fachada de compatibilidad explícita.

## PHP

| Archivo | Líneas | Tipo detectado | Clases | Sesión | DB | AWS/S3 | Observaciones |
|---|---:|---|---:|:---:|:---:|:---:|---|
| `drive/activity_costs.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/actualizar_ruta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/api/upload.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/app_bootstrap.php` | 62 | bootstrap | 0 | — | ⚠️ | — | — |
| `drive/aws.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/background_tasks.php` | 18 | thin endpoint | 0 | — | — | — | — |
| `drive/bin/arcadecloud-drive-admin-helper.php` | 914 | class/module | 1 | — | ⚠️ | — | — |
| `drive/bin/arcadecloud-drive-updater.php` | 274 | class/module | 1 | — | — | — | — |
| `drive/bin/federation_catalog_migrate.php` | 18 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_drop_cleanup.php` | 19 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_endpoint_refresh.php` | 87 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_https_reconcile.php` | 474 | class/module | 1 | — | — | — | — |
| `drive/bin/federation_identity_backup.php` | 49 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_identity_init.php` | 33 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_identity_name.php` | 35 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_identity_restore.php` | 48 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_provider_request.php` | 90 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_replica_presence.php` | 21 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/federation_sync.php` | 37 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/media_processing_worker.php` | 31 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/move_job_worker.php` | 11 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/polly_reconcile.php` | 50 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/sync_node_worker.php` | 75 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/sync_schema_migrate.php` | 17 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/sync_worker.php` | 11 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/transcribe_reconcile.php` | 54 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/upload_cleanup.php` | 11 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bloque_archivos.php` | 705 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/bloque_carpetas.php` | 226 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/bloque_footer.php` | 207 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/buscar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/comprehend_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/costos_aws.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/crear_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/create_folder_document.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/delete_multiple.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar_zip.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/download.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/download_multiple.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/ec2-cron.php` | 39 | thin endpoint | 0 | — | — | — | — |
| `drive/ec2.php` | 1056 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/editor.php` | 485 | view/entrypoint | 0 | — | — | — | — |
| `drive/eliminar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/eliminar_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/encriptar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/fastdrive-control.php` | 142 | view/entrypoint | 0 | — | — | — | — |
| `drive/fastdrive-wake.php` | 188 | view/entrypoint | 0 | — | — | — | — |
| `drive/federationcloud/access-request.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/access-status.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/access.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/collection.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/create.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/drop-ingress.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/index.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/moderation-api.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/moderation.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/name-availability.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/node-admin.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/node.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/nodes.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/portal.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/provider-admin.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/provider-presence.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/provider-request.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/providers.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/public-drive.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/register.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/replica-offer.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/replica-open.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/replica-resolve.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/replica.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/report-api.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/report.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/resolve.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/resource.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/search.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/share-drive.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/sync-pull.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/sync-push.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/sync-status.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationdrop/api.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationdrop/arcadelink.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationdrop/d.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationdrop/google-callback.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationdrop/google-login.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationdrop/google-logout.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationdrop/index.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/generar_token.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/guardar_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/index.php` | 509 | view/entrypoint | 0 | — | — | — | — |
| `drive/leer_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/listar_carpetas.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/login.php` | 93 | view/entrypoint | 0 | — | — | — | — |
| `drive/logout.php` | 14 | thin endpoint | 0 | — | — | — | — |
| `drive/media_playlist.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/media_processing.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/move_multiple.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/move_task.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/move_task_status.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/mover_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/mover_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/personal_aws_bootstrap.php` | 86 | class/module | 1 | — | — | — | — |
| `drive/polly_cargar_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_list_voices.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_task_status.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_tasks.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_tts.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/procesar_textract.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/profile.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/psesion.php` | 14 | thin endpoint | 0 | — | — | — | — |
| `drive/rekognition_labels.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/relock_file.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/renombrar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/renombrar_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/s3.php` | 2357 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/server-console.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/server-settings.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/set_file_security.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/setup/api.php` | 15 | thin endpoint | 0 | — | — | — | — |
| `drive/setup/index.php` | 90 | view/entrypoint | 0 | — | — | — | — |
| `drive/src/Activity/ActivityCostRecorder.php` | 155 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/ActivityCostRepository.php` | 198 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Activity/ActivityCostService.php` | 153 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/AwsUnitPriceCatalog.php` | 89 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/PollyTaskReconciler.php` | 308 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Activity/TranscriptionCostAttribution.php` | 117 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/TranscriptionReconciler.php` | 476 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/ArcadeCloudUpdaterService.php` | 133 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/FastDriveControlService.php` | 143 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/FastDriveWakeService.php` | 183 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/ManagedRuntimeEnvironment.php` | 374 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/PrivilegedServerHelper.php` | 183 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/ServerConsoleService.php` | 247 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/ServerSettingsAdminService.php` | 211 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/AiFileSearchService.php` | 470 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/BackgroundWorkerLauncher.php` | 99 | class/module | 1 | — | — | — | — |
| `drive/src/Application/DrivePageService.php` | 40 | class/module | 1 | — | — | — | — |
| `drive/src/Application/DrivePageViewModel.php` | 18 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileAccessService.php` | 114 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileKeyRotationService.php` | 67 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FileListService.php` | 155 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FileMutationService.php` | 119 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileSearchService.php` | 104 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FolderDocumentService.php` | 298 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FolderMutationService.php` | 214 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FolderQueryService.php` | 152 | class/module | 1 | — | — | — | — |
| `drive/src/Application/MoveJobService.php` | 194 | class/module | 1 | — | — | — | — |
| `drive/src/Application/PhpLintService.php` | 70 | class/module | 1 | — | — | — | — |
| `drive/src/Application/TemporaryZip.php` | 27 | class/module | 1 | — | — | — | — |
| `drive/src/Application/TextFileService.php` | 157 | class/module | 1 | — | — | — | — |
| `drive/src/Application/UploadDestinationService.php` | 56 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/ZipDownloadService.php` | 62 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/AwsCostService.php` | 115 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/ComprehendFileService.php` | 230 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/CostExplorerGateway.php` | 76 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/Ec2CostGuardService.php` | 116 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/Ec2CronLogger.php` | 28 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/Ec2Gateway.php` | 103 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/FileMetadataRepository.php` | 118 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Aws/FileRecordLocator.php` | 75 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Aws/GeneratedFileRepository.php` | 36 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Aws/PersonalAwsConfig.php` | 122 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/PersonalAwsRuntime.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/PersonalTotpService.php` | 44 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/PollyFileService.php` | 546 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/RdsGateway.php` | 233 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/RekognitionFileService.php` | 30 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/SesEmailService.php` | 29 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/TextractFileService.php` | 107 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/TranscriptionFileService.php` | 712 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/TranslateFileService.php` | 60 | class/module | 1 | — | — | — | — |
| `drive/src/Console/MediaProcessingWorkerCommand.php` | 452 | class/module | 1 | — | — | — | — |
| `drive/src/Console/MoveJobWorkerCommand.php` | 137 | class/module | 1 | — | — | — | — |
| `drive/src/Console/SyncWorkerCommand.php` | 182 | class/module | 1 | — | — | — | — |
| `drive/src/Console/UploadCleanupCommand.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Core/ApplicationKernel.php` | 38 | class/module | 1 | — | — | — | — |
| `drive/src/Core/BackgroundWorkerLease.php` | 97 | class/module | 1 | — | — | — | — |
| `drive/src/Core/DriveApplication.php` | 485 | class/module | 1 | — | — | ⚠️ | — |
| `drive/src/Federation/ArcadeLinkFileFormat.php` | 41 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/ArcadeLinkService.php` | 516 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederatedCatalogRepository.php` | 428 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederatedResourceRepository.php` | 117 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationAccessMessageCodec.php` | 193 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationAccessRepository.php` | 304 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationAccessService.php` | 242 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationCatalogService.php` | 214 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationCodec.php` | 54 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationConfig.php` | 129 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationContentFingerprintService.php` | 101 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationCustomsService.php` | 248 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDirectoryService.php` | 158 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropAccountRepository.php` | 145 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationDropConfig.php` | 130 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropEncryptedCookie.php` | 99 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropGoogleAuthConfig.php` | 113 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropGoogleAuthService.php` | 272 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropGoogleOidcClient.php` | 374 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropIngressCodec.php` | 111 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropIngressDownloader.php` | 82 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropIngressRepository.php` | 291 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationDropIngressService.php` | 395 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropRepository.php` | 452 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationDropService.php` | 879 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropStorageService.php` | 173 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropStripeCheckoutService.php` | 245 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropStripeClient.php` | 109 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropStripeWebhookVerifier.php` | 73 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDropWorkerService.php` | 49 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationEndpointResolver.php` | 127 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationEventCodec.php` | 117 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationEventStore.php` | 288 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationException.php` | 20 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationGossipService.php` | 119 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationHttpClient.php` | 184 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationIngressQueueRepository.php` | 288 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationLocationSelector.php` | 66 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationModerationRepository.php` | 405 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationModerationSchemaService.php` | 103 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationModerationService.php` | 479 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationMultiSourceDownloader.php` | 358 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationNodeAdminService.php` | 231 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationNodeDescriptorValidator.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationNodeRepository.php` | 145 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationOriginStorageResolver.php` | 80 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationPeerSyncRepository.php` | 125 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationProviderAuthorizationRepository.php` | 275 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationProviderAuthorizationService.php` | 326 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationProviderGrant.php` | 101 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationPublicImportRepository.php` | 243 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationPublicImportService.php` | 184 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationReplicaDownloader.php` | 161 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationReplicaMessageCodec.php` | 154 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationReplicaPresenceService.php` | 129 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationReplicaRepository.php` | 294 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationReplicaResolverService.php` | 282 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationReplicaService.php` | 366 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationResolverService.php` | 185 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationSchemaMigrationService.php` | 147 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationSeedConfig.php` | 83 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationService.php` | 324 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationShareDownloader.php` | 188 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationShareDriveRepository.php` | 350 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationShareDriveService.php` | 255 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationSourceFailover.php` | 120 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationSyncConfig.php` | 36 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationSyncCycleService.php` | 81 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/NodeIdentityBackupService.php` | 228 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/NodeIdentityService.php` | 294 | class/module | 1 | — | — | — | — |
| `drive/src/Http/BinaryResponse.php` | 85 | class/module | 1 | — | — | — | — |
| `drive/src/Http/ByteRange.php` | 45 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AbstractJsonController.php` | 81 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ActivityCostController.php` | 82 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ArcadeCloudUpdateController.php` | 48 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AudioRecordingUploadController.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AuthController.php` | 114 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AwsCostController.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AwsFileController.php` | 338 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/BackgroundTaskCompatibilityController.php` | 245 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Http/Controller/BackgroundTaskController.php` | 821 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Http/Controller/FederationAccessController.php` | 123 | class/module | 1 | ⚠️ | — | — | — |
| `drive/src/Http/Controller/FederationCatalogController.php` | 144 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationCollectionController.php` | 128 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationController.php` | 270 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDirectoryController.php` | 88 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDropController.php` | 238 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDropGoogleAuthController.php` | 120 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDropIngressController.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationModerationController.php` | 117 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationNodeAdminController.php` | 46 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationPortalController.php` | 41 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationProviderController.php` | 178 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationPublicImportController.php` | 68 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationReplicaController.php` | 135 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationShareDriveController.php` | 68 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileAccessController.php` | 145 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileKeyRotationController.php` | 35 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileMutationController.php` | 124 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileSearchController.php` | 65 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileSecurityController.php` | 97 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FolderDocumentController.php` | 71 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FolderMutationController.php` | 203 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FolderQueryController.php` | 30 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/LegacyUploadController.php` | 154 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/MediaPlaylistController.php` | 35 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/MediaProcessingController.php` | 48 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/MoveJobController.php` | 164 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/NavigationController.php` | 42 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PersonalAwsController.php` | 74 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PollyTaskController.php` | 347 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Http/Controller/PublicShareController.php` | 209 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PublicSharedBrowserController.php` | 76 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PublicUploadController.php` | 81 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ServerConsoleController.php` | 72 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ServerSettingsAdminController.php` | 73 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ShareController.php` | 78 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/StorageUsageController.php` | 28 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/SyncController.php` | 147 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/TextEditorController.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ThumbnailController.php` | 110 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/TranscriptionController.php` | 224 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UploadCleanupController.php` | 76 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UploadController.php` | 281 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UserProfileController.php` | 115 | class/module | 1 | — | — | — | — |
| `drive/src/Http/JsonResponse.php` | 27 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Request.php` | 122 | class/module | 1 | — | — | — | — |
| `drive/src/Mail/SmtpConfig.php` | 120 | class/module | 1 | — | — | — | — |
| `drive/src/Mail/SmtpEmailService.php` | 346 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaPlaylistRepository.php` | 49 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Media/MediaPlaylistService.php` | 66 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaProcessingJobRepository.php` | 340 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Media/MediaProcessingService.php` | 99 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaWorkerNodeService.php` | 232 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaWorkerNodeSessionRepository.php` | 202 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Media/ThumbnailService.php` | 380 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/AuthenticationRepository.php` | 101 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/AuthenticationService.php` | 55 | class/module | 1 | — | — | — | — |
| `drive/src/Security/FileSecurityRepository.php` | 92 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/FileSecurityService.php` | 183 | class/module | 1 | — | — | — | — |
| `drive/src/Security/LoginRateLimiter.php` | 143 | class/module | 1 | — | — | — | — |
| `drive/src/Security/PasswordChangeService.php` | 80 | class/module | 1 | — | — | — | — |
| `drive/src/Security/PasswordCredentialVerifier.php` | 35 | class/module | 1 | — | — | — | — |
| `drive/src/Security/PersonalToolAccessService.php` | 53 | class/module | 1 | — | — | — | — |
| `drive/src/Security/SessionManager.php` | 181 | class/module | 1 | ⚠️ | — | — | — |
| `drive/src/Security/SuperAdminReauthenticationService.php` | 89 | class/module | 1 | — | — | — | — |
| `drive/src/Security/UserDirectoryRepository.php` | 72 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/UserProfileRepository.php` | 117 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/UserProfileService.php` | 183 | class/module | 1 | — | — | — | — |
| `drive/src/Security/UserProfileValidator.php` | 96 | class/module | 1 | — | — | — | — |
| `drive/src/Setup/BootstrapSetupAuth.php` | 183 | class/module | 1 | ⚠️ | — | — | — |
| `drive/src/Setup/CanonicalDatabaseSchemaService.php` | 149 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Setup/SetupApiController.php` | 120 | class/module | 1 | — | — | — | — |
| `drive/src/Setup/SetupConfigurationService.php` | 182 | class/module | 1 | — | — | — | — |
| `drive/src/Setup/SetupEntryGuard.php` | 22 | class/module | 1 | — | — | — | — |
| `drive/src/Setup/SuperAdminBootstrapService.php` | 290 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sharing/ShareAccessService.php` | 105 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareException.php` | 22 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareFileRepository.php` | 72 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sharing/ShareLinkService.php` | 78 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareObjectStorage.php` | 37 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareTokenStore.php` | 132 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/FileRecordRepository.php` | 122 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/FolderMutationRepository.php` | 239 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/FolderRepository.php` | 108 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/MoveJobStore.php` | 315 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/StorageObjectNameCodec.php` | 104 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/StorageUsageService.php` | 103 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/UserStoragePath.php` | 50 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/UserStorageProvisioner.php` | 105 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sync/NodeSyncService.php` | 126 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sync/S3SyncService.php` | 265 | class/module | 1 | — | — | — | — |
| `drive/src/Sync/SyncJobStore.php` | 263 | class/module | 1 | — | — | — | — |
| `drive/src/Sync/SyncRepository.php` | 499 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sync/SyncSchemaMigrator.php` | 114 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Upload/AdminMultipartUploadService.php` | 221 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicDropzoneUploadService.php` | 133 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicMultipartUploadService.php` | 262 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicSharedBrowserRepository.php` | 61 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Upload/PublicSharedBrowserService.php` | 189 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/SingleUploadService.php` | 112 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/UploadCatalogRepository.php` | 173 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Upload/UploadCleanupService.php` | 316 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/View/ActivityCostPageRenderer.php` | 396 | class/module | 1 | — | — | — | — |
| `drive/src/View/Ec2PanelHelper.php` | 69 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationDropPageRenderer.php` | 228 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationModerationPageRenderer.php` | 63 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationPageRenderer.php` | 264 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationPortalRenderer.php` | 169 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationReportPageRenderer.php` | 70 | class/module | 1 | — | — | — | — |
| `drive/src/View/FileIconResolver.php` | 136 | class/module | 1 | — | — | — | — |
| `drive/src/View/FileViewHelper.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/View/FolderTreeRenderer.php` | 143 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/View/PersonalAwsPageRenderer.php` | 171 | class/module | 1 | — | — | — | — |
| `drive/src/View/PublicSharedPageRenderer.php` | 104 | class/module | 1 | — | — | — | — |
| `drive/src/View/SharePageRenderer.php` | 118 | class/module | 1 | — | — | — | — |
| `drive/src/View/SyncStatusRenderer.php` | 14 | class/module | 1 | — | — | — | — |
| `drive/src/View/UserIdentityPresenter.php` | 71 | class/module | 1 | — | — | — | — |
| `drive/src/View/UserProfileModalRenderer.php` | 127 | class/module | 1 | — | — | — | — |
| `drive/storage_usage.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/subir_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/subir_publico.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/sync_s3_to_db.php` | 5 | thin endpoint | 0 | — | — | — | — |
| `drive/sync_status.php` | 5 | thin endpoint | 0 | — | — | — | — |
| `drive/tests/activity_costs_smoke.php` | 150 | test script | 0 | — | — | — | — |
| `drive/tests/arcadelink_bulk_contract_regression.php` | 71 | test script | 0 | — | — | — | — |
| `drive/tests/arcadelink_collection_regression.php` | 63 | test script | 0 | — | — | — | — |
| `drive/tests/database_schema_contract_smoke.php` | 106 | test script | 0 | — | — | — | — |
| `drive/tests/fastdrive_control_contract_smoke.php` | 54 | test script | 0 | — | — | — | — |
| `drive/tests/federation_access_message_smoke.php` | 72 | test script | 0 | — | — | — | — |
| `drive/tests/federation_catalog_event_smoke.php` | 60 | test script | 0 | — | — | — | — |
| `drive/tests/federation_customs_contract_smoke.php` | 82 | test script | 0 | — | — | — | — |
| `drive/tests/federation_directory_smoke.php` | 102 | test script | 0 | — | — | — | — |
| `drive/tests/federation_drop_contract_smoke.php` | 115 | test script | 0 | — | — | — | — |
| `drive/tests/federation_drop_google_oidc_smoke.php` | 159 | test script | 0 | — | — | — | — |
| `drive/tests/federation_drop_ingress_smoke.php` | 117 | test script | 0 | — | — | — | — |
| `drive/tests/federation_drop_stripe_smoke.php` | 97 | test script | 0 | — | — | — | — |
| `drive/tests/federation_endpoint_resolver_smoke.php` | 95 | test script | 0 | — | — | — | — |
| `drive/tests/federation_https_contract_smoke.php` | 61 | test script | 0 | — | — | — | — |
| `drive/tests/federation_moderation_contract_smoke.php` | 146 | test script | 0 | — | — | — | — |
| `drive/tests/federation_node_name_admin_smoke.php` | 91 | test script | 0 | — | — | — | — |
| `drive/tests/federation_provider_smoke.php` | 76 | test script | 0 | — | — | — | — |
| `drive/tests/federation_public_download_failover_smoke.php` | 215 | test script | 0 | — | — | — | — |
| `drive/tests/federation_replica_reconnect_contract_smoke.php` | 81 | test script | 0 | — | — | — | — |
| `drive/tests/federation_replica_smoke.php` | 115 | test script | 0 | — | — | — | — |
| `drive/tests/federationcloud_smoke.php` | 197 | test script | 0 | — | — | — | — |
| `drive/tests/folder_document_sanitizer.php` | 49 | test script | 0 | — | — | — | — |
| `drive/tests/fresh_install_contract_smoke.php` | 67 | test script | 0 | — | — | — | — |
| `drive/tests/index_federation_drop_smoke.php` | 121 | test script | 0 | — | — | — | — |
| `drive/tests/installer_service_reconcile_contract_smoke.php` | 142 | test script | 0 | — | — | — | — |
| `drive/tests/media_processing_contract_smoke.php` | 54 | test script | 0 | — | — | — | — |
| `drive/tests/password_credential_verifier_smoke.php` | 44 | test script | 0 | — | — | — | — |
| `drive/tests/scoped_sync_repository_regression.php` | 96 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/security_hardening_smoke.php` | 59 | test script | 0 | — | — | — | — |
| `drive/tests/server_admin_config_smoke.php` | 218 | test script | 0 | — | — | — | — |
| `drive/tests/server_console_contract_smoke.php` | 107 | test script | 0 | — | — | — | — |
| `drive/tests/setup_bootstrap_smoke.php` | 61 | test script | 0 | — | — | — | — |
| `drive/tests/setup_entry_guard_smoke.php` | 38 | test script | 0 | — | — | — | — |
| `drive/tests/setup_finalize_contract_smoke.php` | 75 | test script | 0 | — | — | — | — |
| `drive/tests/smtp_config_smoke.php` | 49 | test script | 0 | — | — | — | — |
| `drive/tests/sync_repository_regression.php` | 91 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/sync_schema_migrator_regression.php` | 75 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/transcribe_s3_recovery_contract_smoke.php` | 36 | test script | 0 | — | — | — | — |
| `drive/tests/upload_catalog_registration_regression.php` | 125 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/user_identity_presenter_smoke.php` | 30 | test script | 0 | — | — | — | — |
| `drive/tests/user_profile_validator_smoke.php` | 72 | test script | 0 | — | — | — | — |
| `drive/thumb.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_audio.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_texto.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_video.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/traducir_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/transcribir_estado.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/transcribir_iniciar.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/unlock_file.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/up-clean.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/up.php` | 881 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/update.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/upload/ModerationUploadGuard.php` | 187 | class/module | 2 | — | ⚠️ | — | — |
| `drive/upload/UploadFactory.php` | 60 | class/module | 1 | — | — | — | — |
| `drive/upload/core/UploadResponse.php` | 15 | class/module | 1 | — | — | — | — |
| `drive/upload/core/UploaderInterface.php` | 11 | class/module | 0 | — | — | — | — |
| `drive/upload/drivers/Chunked15MBUploader.php` | 278 | class/module | 1 | — | — | — | — |
| `drive/upload/drivers/DropboxUploader.php` | 147 | class/module | 1 | — | — | — | — |
| `drive/upload/drivers/LocalPresignedPutUploader.php` | 325 | class/module | 1 | — | ⚠️ | — | — |
| `drive/upload/drivers/RemoteUrlUploader.php` | 493 | class/module | 1 | — | — | — | — |
| `drive/upload/repositories/FileS3Repository.php` | 86 | class/module | 1 | — | ⚠️ | — | — |
| `drive/upload/storage/UploadStateStore.php` | 51 | class/module | 1 | — | — | — | — |
| `drive/upload.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/upload_audio_recording.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/upload_publico.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/validar_php.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/ver.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/ver_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/ver_pdf.php` | 10 | thin endpoint | 0 | — | — | — | — |

## JavaScript

| Archivo | Líneas | Tipo detectado | Clases | Funciones globales | `window` funciones | Observaciones |
|---|---:|---|---|---|---|---|
| `drive/js/actualizar-hora.js` | 36 | class/module | ActualizarHoraModule | — | — | — |
| `drive/js/ai-search.js` | 187 | class/module | DriveAiSearchModule | — | — | — |
| `drive/js/arcadecloud-updater.js` | 228 | class/module | ArcadeCloudUpdaterModule | — | — | — |
| `drive/js/arcadelink-share.js` | 387 | class/module | ArcadeLinkShareModule | — | — | — |
| `drive/js/archivos.js` | 2155 | class/module | ArchivosModule | — | abrirModalRenombrarArchivo, cerrarModalCompartir, setFileSecurity | window functions: abrirModalRenombrarArchivo, cerrarModalCompartir, setFileSecurity |
| `drive/js/audiovideo.js` | 619 | class/module | AudiovideoModule | — | audioNext, audioPrev, reproducirVideoDesde, videoNext, videoPlayPause, videoPrev | window functions: audioNext, audioPrev, reproducirVideoDesde, videoNext, videoPlayPause, videoPrev, wavePlayPause |
| `drive/js/aws-comprehend.js` | 342 | class/module | AwsComprehendModule, AwsFileActionRouter | — | — | — |
| `drive/js/background-task-feedback.js` | 180 | class/module | BackgroundTaskFeedbackModule | — | — | — |
| `drive/js/background-tasks.js` | 684 | class/module | BackgroundTaskCenter | — | — | — |
| `drive/js/carpetas.js` | 1105 | class/module | CarpetasModule | — | actualizarBloqueCarpetas | window functions: actualizarBloqueCarpetas |
| `drive/js/descarga-multiple.js` | 114 | class/module | DescargaMultipleModule | — | — | — |
| `drive/js/descarga-uno.js` | 110 | class/module | DescargaUnoModule | — | — | — |
| `drive/js/editar-txt.js` | 68 | class/module | EditarTxtModule | — | — | — |
| `drive/js/elimina-multiple.js` | 104 | class/module | EliminaMultipleModule | — | — | — |
| `drive/js/elimina-uno.js` | 122 | class/module | EliminaUnoModule | — | — | — |
| `drive/js/estilo.js` | 267 | class/module | EstiloModule | — | — | — |
| `drive/js/federation-drop.js` | 488 | class/module | FederationDropApp | — | — | — |
| `drive/js/federation-footer.js` | 374 | class/module | FederationFooterModule | — | — | — |
| `drive/js/federation-page.js` | 99 | class/module | FederationPageModule | — | — | — |
| `drive/js/federation-portal.js` | 506 | class/module | FederationPortalModule | — | — | — |
| `drive/js/federation-share-drive.js` | 211 | class/module | FederationShareDriveModule | — | — | — |
| `drive/js/file-block.js` | 305 | class/module | FileBlockApp | — | — | — |
| `drive/js/filtros.js` | 97 | class/module | FiltrosModule | — | — | — |
| `drive/js/folder-document.js` | 430 | class/module | FolderDocumentModule | — | — | — |
| `drive/js/imagenes.js` | 535 | class/module | ImagenesModule | — | getGaleriaGridSize, setGaleriaGridSize | window functions: getGaleriaGridSize, setGaleriaGridSize |
| `drive/js/media-floating.js` | 696 | class/module | MediaFloatingApp | — | — | — |
| `drive/js/media-processing.js` | 378 | class/module | MediaProcessingModule | — | — | — |
| `drive/js/mediaFloating.js` | 38 | class/module | MediaFloatingModule | — | — | — |
| `drive/js/move-tasks.js` | 248 | class/module | DriveMoveTasks | — | — | — |
| `drive/js/obtenerFiltros.js` | 125 | class/module | ObtenerFiltrosModule | — | — | — |
| `drive/js/pdf-pantalla-completa.js` | 45 | class/module | PdfPantallaCompletaModule | — | — | — |
| `drive/js/polly-background.js` | 301 | class/module | PollyBackgroundModule | — | — | — |
| `drive/js/polly.js` | 1008 | class/module | PollyModule | — | abrirModalGrabarAudio, abrirModalPolly, abrirModalRekognition, abrirModalTraducir, abrirModalTranscribir, copiarTextract | window functions: abrirModalGrabarAudio, abrirModalPolly, abrirModalRekognition, abrirModalTraducir, abrirModalTranscribir, copiarTextract, copiarTraducido, copiarTx |
| `drive/js/profile.js` | 289 | class/module | UserProfileModule | — | — | — |
| `drive/js/recargarPagina.js` | 27 | class/module | RecargarPaginaModule | — | — | — |
| `drive/js/server-admin.js` | 405 | class/module | ServerAdminModule | — | — | — |
| `drive/js/setup.js` | 192 | class/module | ArcadeCloudSetup | — | — | — |
| `drive/js/sincronizar.js` | 262 | class/module | SincronizarModule | — | triggerSyncFolderS3, triggerSyncS3 | window functions: triggerSyncFolderS3, triggerSyncS3 |
| `drive/js/soportesMediaTypes.js` | 397 | class/module | SoportesMediaTypesModule | — | — | — |
| `drive/js/storage-usage.js` | 51 | class/module | StorageUsageModule | — | — | — |
| `drive/js/subir-chunked.js` | 594 | class/module | SubirChunkedModule | — | — | — |
| `drive/js/subir-dropzone.js` | 910 | class/module | SubirDropzoneModule | — | — | — |
| `drive/js/subir.js` | 358 | class/module | SubirModule | — | — | — |
| `drive/js/theme-state-bridge.js` | 73 | class/module | ThemeStateBridge | — | — | — |
| `drive/js/transcribe-background.js` | 319 | class/module | TranscribeBackgroundModule | — | — | — |
| `drive/js/upload-destination.js` | 63 | class/module | UploadDestinationModule | — | — | — |
| `drive/js/ver-metadatos.js` | 75 | class/module | VerMetadatosModule | — | verMetadatos | window functions: verMetadatos |
| `drive/js/ver-pdf.js` | 112 | class/module | VerPdfModule | — | — | — |

## Objetivo de refactorización

```text
HTTP entrypoint -> Controller -> Application Service -> Repository/Infrastructure
                                      |
                                      +-> S3 / AWS service
                                      +-> MySQL repository

Browser -> JS App class -> DOM/HTTP services -> PHP endpoint
```

El objetivo no es envolver código procedural en una clase gigante, sino separar responsabilidades y dependencias.
