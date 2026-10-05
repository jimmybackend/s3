# Auditoría OOP — ArcadeCloud Drive

> Generado automáticamente. No sustituye pruebas funcionales; detecta estructura y dependencias procedurales.

## Resumen

- PHP analizados: **553**
- PHP que ya contienen clases/interfaces: **315**
- PHP marcados para migración/revisión: **0**
- Tests PHP separados del objetivo OOP de runtime: **76**
- JavaScript analizados: **81**
- JavaScript que ya contienen clases: **74**
- JavaScript runtime marcados para migración/revisión: **1**
- Tests JavaScript separados del objetivo OOP de runtime: **11**
- JavaScript OOP con fachada `window` de compatibilidad: **8**
- Clientes AJAX detectados: **53** módulos / **113** llamadas
- JSON analizados: **4**; inválidos: **0**

## Criterio

- `src/` y `upload/`: lógica de negocio e infraestructura en clases.
- Entry points públicos: bootstrap + Controller/Service; sin SQL/AWS ni funciones globales.
- CLI: el archivo ejecutable puede ser procedural si es un wrapper delgado que delega en clases.
- Tests PHP/JavaScript: se auditan, pero no cuentan como deuda OOP del runtime.
- Vistas: pueden contener HTML; funciones JavaScript incrustadas no se confunden con funciones PHP.
- JavaScript: comportamiento en clases; `window` sólo como fachada de compatibilidad explícita.
- AJAX: es un mecanismo de transporte, no un paradigma; se revisa dentro de la clase cliente que lo posee.
- JSON: es un formato de datos, no código OOP; se valida sintaxis, tipo raíz y contrato en sus consumidores.

## PHP

| Archivo | Líneas | Tipo detectado | Clases | Sesión | DB | AWS/S3 | Observaciones |
|---|---:|---|---:|:---:|:---:|:---:|---|
| `Config-s3.php` | 324 | class/module | 1 | — | — | — | — |
| `db.php` | 51 | bootstrap | 0 | — | ⚠️ | — | — |
| `drive/activity_costs.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/actualizar_ruta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/api/upload.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/app_bootstrap.php` | 62 | bootstrap | 0 | — | ⚠️ | — | — |
| `drive/aws.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/background_tasks.php` | 18 | thin endpoint | 0 | — | — | — | — |
| `drive/bin/activity_retention.php` | 9 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/arcadecloud-drive-admin-helper.php` | 1359 | class/module | 1 | — | ⚠️ | — | — |
| `drive/bin/arcadecloud-drive-updater.php` | 321 | class/module | 1 | — | — | — | — |
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
| `drive/bin/folder_textract_worker.php` | 7 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/media_processing_worker.php` | 31 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/move_job_worker.php` | 11 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/polly_reconcile.php` | 50 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/production_preflight.php` | 25 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/server_maintenance_worker.php` | 7 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/sync_node_worker.php` | 75 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/sync_schema_migrate.php` | 17 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/sync_worker.php` | 11 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/transcribe_reconcile.php` | 54 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/upload_cleanup.php` | 11 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bin/workstation_health.php` | 35 | thin cli entrypoint | 0 | — | — | — | — |
| `drive/bloque_archivos.php` | 719 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/bloque_carpetas.php` | 226 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/bloque_footer.php` | 207 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/buscar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/comprehend_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/costos_aws.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/crear_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/create_folder_document.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/database-backup.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/delete_multiple.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar_zip.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/download.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/download_multiple.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/ec2-cron.php` | 39 | thin endpoint | 0 | — | — | — | — |
| `drive/ec2.php` | 1090 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/editor.php` | 485 | view/entrypoint | 0 | — | — | — | — |
| `drive/eliminar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/eliminar_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/encriptar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/fastdrive-control.php` | 163 | view/entrypoint | 0 | — | — | — | — |
| `drive/fastdrive-power.php` | 62 | thin endpoint | 0 | — | — | — | — |
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
| `drive/federationcloud/os-admin.php` | 16 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/portal.php` | 15 | thin endpoint | 0 | — | — | — | — |
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
| `drive/folder-suggestions.php` | 41 | thin endpoint | 0 | — | — | — | — |
| `drive/generar_token.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/guardar_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/index.php` | 509 | view/entrypoint | 0 | — | — | — | — |
| `drive/leer_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/listar_carpetas.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/login.php` | 93 | view/entrypoint | 0 | — | — | — | — |
| `drive/logout.php` | 14 | thin endpoint | 0 | — | — | — | — |
| `drive/media_playlist.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/media_processing.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/migrations/20260930_add_users_os_preferences.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/move_multiple.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/move_task.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/move_task_status.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/mover_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/mover_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/node-status.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/notebook-api.php` | 69 | thin endpoint | 0 | — | — | — | — |
| `drive/notebook.php` | 206 | view/entrypoint | 0 | — | — | — | — |
| `drive/office-gateway.php` | 674 | view/entrypoint | 0 | — | — | — | — |
| `drive/office-launch.php` | 81 | thin endpoint | 0 | — | — | — | — |
| `drive/os-preferences.php` | 71 | thin endpoint | 0 | — | — | — | — |
| `drive/personal_aws_bootstrap.php` | 86 | class/module | 1 | — | — | — | — |
| `drive/polly_cargar_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_list_voices.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_task_status.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_tasks.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_tts.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/procesar_textract.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/procesar_textract_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/profile.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/psesion.php` | 14 | thin endpoint | 0 | — | — | — | — |
| `drive/rekognition_labels.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/relock_file.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/renombrar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/renombrar_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/s3.php` | 2371 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/server-console.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/server-settings.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/set_file_security.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/setup/api.php` | 15 | thin endpoint | 0 | — | — | — | — |
| `drive/setup/index.php` | 90 | view/entrypoint | 0 | — | — | — | — |
| `drive/so.php` | 2212 | view/entrypoint | 0 | — | — | — | — |
| `drive/src/Activity/ActivityCostRecorder.php` | 155 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/ActivityCostRepository.php` | 198 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Activity/ActivityCostService.php` | 153 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/ActivityRetentionService.php` | 129 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Activity/AwsUnitPriceCatalog.php` | 89 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/PollyTaskReconciler.php` | 308 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Activity/TranscriptionCostAttribution.php` | 117 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/TranscriptionReconciler.php` | 476 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/ArcadeCloudUpdaterService.php` | 162 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/DatabaseBackupService.php` | 74 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/DatabaseSqlDumpWriter.php` | 492 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/FastDriveControlService.php` | 327 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/FastDriveWakeService.php` | 191 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/ManagedRuntimeEnvironment.php` | 374 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/NodeServiceControlService.php` | 51 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/PrivilegedServerHelper.php` | 323 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/ProductionPreflightService.php` | 228 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/ServerConsoleService.php` | 262 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/ServerMaintenanceJobStore.php` | 186 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/ServerMaintenanceService.php` | 119 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/ServerSettingsAdminService.php` | 211 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Admin/ServerTaskActivityProbe.php` | 72 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/AiFileSearchService.php` | 471 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/BackgroundWorkerLauncher.php` | 115 | class/module | 1 | — | — | — | — |
| `drive/src/Application/DrivePageService.php` | 40 | class/module | 1 | — | — | — | — |
| `drive/src/Application/DrivePageViewModel.php` | 18 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileAccessService.php` | 114 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileKeyRotationService.php` | 67 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FileListService.php` | 157 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FileMutationService.php` | 208 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileSearchService.php` | 159 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FolderDocumentService.php` | 298 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FolderMutationService.php` | 323 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FolderQueryService.php` | 224 | class/module | 1 | — | — | — | — |
| `drive/src/Application/MoveJobService.php` | 348 | class/module | 1 | — | — | — | — |
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
| `drive/src/Aws/FolderTextractJobStore.php` | 143 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/FolderTextractService.php` | 231 | class/module | 1 | — | ⚠️ | — | — |
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
| `drive/src/Console/ActivityRetentionCommand.php` | 38 | class/module | 1 | — | — | — | — |
| `drive/src/Console/FolderTextractWorkerCommand.php` | 130 | class/module | 1 | — | — | — | — |
| `drive/src/Console/MediaProcessingWorkerCommand.php` | 919 | class/module | 1 | — | — | — | — |
| `drive/src/Console/MoveJobWorkerCommand.php` | 155 | class/module | 1 | — | — | — | — |
| `drive/src/Console/ServerMaintenanceWorkerCommand.php` | 32 | class/module | 1 | — | — | — | — |
| `drive/src/Console/SyncWorkerCommand.php` | 182 | class/module | 1 | — | — | — | — |
| `drive/src/Console/UploadCleanupCommand.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Core/ApplicationKernel.php` | 38 | class/module | 1 | — | — | — | — |
| `drive/src/Core/BackgroundWorkerLease.php` | 97 | class/module | 1 | — | — | — | — |
| `drive/src/Core/DriveApplication.php` | 485 | class/module | 1 | — | — | ⚠️ | — |
| `drive/src/Federation/ArcadeLinkFileFormat.php` | 41 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/ArcadeLinkService.php` | 516 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederatedCatalogRepository.php` | 536 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederatedResourceRepository.php` | 117 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationAccessMessageCodec.php` | 193 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationAccessRepository.php` | 304 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationAccessService.php` | 242 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationCatalogService.php` | 217 | class/module | 1 | — | — | — | — |
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
| `drive/src/Federation/FederationEventCodec.php` | 118 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationEventStore.php` | 288 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationException.php` | 20 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationGossipService.php` | 119 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationHttpClient.php` | 184 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationIngressQueueRepository.php` | 288 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationLocationSelector.php` | 66 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationModerationRepository.php` | 405 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationModerationSchemaService.php` | 103 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationModerationService.php` | 479 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationMultiSourceDownloader.php` | 360 | class/module | 1 | — | — | — | — |
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
| `drive/src/Federation/FederationReplicaResolverService.php` | 346 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationReplicaService.php` | 366 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationResolverService.php` | 185 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationResourceDeliveryRepository.php` | 104 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationResourceDeliveryService.php` | 87 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationSchemaMigrationService.php` | 149 | class/module | 1 | — | ⚠️ | — | — |
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
| `drive/src/Http/Controller/AbstractJsonController.php` | 95 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ActivityCostController.php` | 82 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ArcadeCloudUpdateController.php` | 55 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AudioRecordingUploadController.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AuthController.php` | 119 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AwsCostController.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AwsFileController.php` | 384 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/BackgroundTaskCompatibilityController.php` | 245 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Http/Controller/BackgroundTaskController.php` | 1026 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Http/Controller/DatabaseBackupController.php` | 50 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationAccessController.php` | 123 | class/module | 1 | ⚠️ | — | — | — |
| `drive/src/Http/Controller/FederationCatalogController.php` | 144 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationCollectionController.php` | 128 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationController.php` | 270 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDirectoryController.php` | 88 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDropController.php` | 238 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDropGoogleAuthController.php` | 120 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDropIngressController.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationModerationController.php` | 120 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationNodeAdminController.php` | 46 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationOsAdminController.php` | 52 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationPortalController.php` | 44 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationProviderController.php` | 178 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationPublicImportController.php` | 68 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationReplicaController.php` | 211 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationShareDriveController.php` | 68 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileAccessController.php` | 145 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileKeyRotationController.php` | 36 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileMutationController.php` | 128 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileSearchController.php` | 107 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileSecurityController.php` | 100 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FolderDocumentController.php` | 72 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FolderMutationController.php` | 207 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FolderQueryController.php` | 30 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/LegacyUploadController.php` | 154 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/MediaPlaylistController.php` | 35 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/MediaProcessingController.php` | 79 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/MoveJobController.php` | 205 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/NavigationController.php` | 42 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/NodeStatusController.php` | 140 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/OfficeDocumentController.php` | 53 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Http/Controller/PersonalAwsController.php` | 110 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PollyTaskController.php` | 347 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Http/Controller/PublicShareController.php` | 209 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PublicSharedBrowserController.php` | 76 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PublicUploadController.php` | 81 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ServerConsoleController.php` | 72 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ServerSettingsAdminController.php` | 73 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ShareController.php` | 79 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/StorageUsageController.php` | 28 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/SyncController.php` | 148 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/TextEditorController.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ThumbnailController.php` | 119 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/TranscriptionController.php` | 225 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UploadCleanupController.php` | 76 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UploadController.php` | 281 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UserProfileController.php` | 115 | class/module | 1 | — | — | — | — |
| `drive/src/Http/JsonResponse.php` | 27 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Request.php` | 122 | class/module | 1 | — | — | — | — |
| `drive/src/Mail/SmtpConfig.php` | 120 | class/module | 1 | — | — | — | — |
| `drive/src/Mail/SmtpEmailService.php` | 346 | class/module | 1 | — | — | — | — |
| `drive/src/Media/DerivedImageAssetService.php` | 87 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaPlaylistRepository.php` | 49 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Media/MediaPlaylistService.php` | 66 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaProcessingJobRepository.php` | 460 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Media/MediaProcessingService.php` | 104 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaWorkerNodeService.php` | 799 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaWorkerNodeSessionRepository.php` | 243 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Media/ThumbnailService.php` | 559 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Notebook/NotebookAiImproveService.php` | 327 | class/module | 1 | — | — | — | — |
| `drive/src/Notebook/NotebookService.php` | 394 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Office/OfficeActivityProbe.php` | 57 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Office/OfficeDocumentSessionRepository.php` | 351 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Office/OfficeDocumentStorageService.php` | 605 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Office/OfficeGatewayService.php` | 175 | class/module | 1 | — | — | — | — |
| `drive/src/Office/OfficeLaunchTokenRepository.php` | 164 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Office/OfficeSchemaMigrationService.php` | 141 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Office/OfficeSessionLeaseRepository.php` | 207 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Office/OfficeSessionReconciler.php` | 287 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Office/OfficeWorkstationClient.php` | 178 | class/module | 1 | — | — | — | — |
| `drive/src/Security/AuthenticationRepository.php` | 101 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/AuthenticationService.php` | 55 | class/module | 1 | — | — | — | — |
| `drive/src/Security/FileSecurityRepository.php` | 92 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/FileSecurityService.php` | 183 | class/module | 1 | — | — | — | — |
| `drive/src/Security/LoginRateLimiter.php` | 143 | class/module | 1 | — | — | — | — |
| `drive/src/Security/OsPreferenceNodeResolver.php` | 43 | class/module | 1 | — | — | — | — |
| `drive/src/Security/PasswordChangeService.php` | 80 | class/module | 1 | — | — | — | — |
| `drive/src/Security/PasswordCredentialVerifier.php` | 35 | class/module | 1 | — | — | — | — |
| `drive/src/Security/PersonalToolAccessService.php` | 53 | class/module | 1 | — | — | — | — |
| `drive/src/Security/SessionManager.php` | 181 | class/module | 1 | ⚠️ | — | — | — |
| `drive/src/Security/SuperAdminReauthenticationService.php` | 89 | class/module | 1 | — | — | — | — |
| `drive/src/Security/UserDirectoryRepository.php` | 72 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/UserOsPreferencesRepository.php` | 111 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/UserOsPreferencesSchemaService.php` | 34 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/UserProfileRepository.php` | 117 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/UserProfileService.php` | 183 | class/module | 1 | — | — | — | — |
| `drive/src/Security/UserProfileValidator.php` | 96 | class/module | 1 | — | — | — | — |
| `drive/src/Setup/BootstrapSetupAuth.php` | 183 | class/module | 1 | ⚠️ | — | — | — |
| `drive/src/Setup/CanonicalDatabaseSchemaService.php` | 152 | class/module | 1 | — | ⚠️ | — | — |
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
| `drive/src/Storage/FileRecordRepository.php` | 174 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/FolderMutationRepository.php` | 326 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/FolderRepository.php` | 132 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/MoveJobStore.php` | 335 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/S3ObjectCopyService.php` | 53 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/StorageObjectNameCodec.php` | 104 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/StorageUsageService.php` | 103 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/UserStoragePath.php` | 50 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/UserStorageProvisioner.php` | 105 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sync/NodeSyncService.php` | 126 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sync/S3SyncService.php` | 265 | class/module | 1 | — | — | — | — |
| `drive/src/Sync/SyncJobStore.php` | 282 | class/module | 1 | — | — | — | — |
| `drive/src/Sync/SyncRepository.php` | 499 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sync/SyncSchemaMigrator.php` | 114 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/System/ComputeNodeAdmissionLock.php` | 64 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/System/Ec2InstanceIdentityService.php` | 107 | class/module | 1 | — | — | — | — |
| `drive/src/System/LocalContainerCapabilityService.php` | 58 | class/module | 1 | — | — | — | — |
| `drive/src/System/NodeCapabilityService.php` | 255 | class/module | 1 | — | — | — | — |
| `drive/src/System/NodeRuntimeStatusService.php` | 509 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/System/NodeServiceCatalog.php` | 35 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/AdminMultipartUploadService.php` | 221 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/ChunkedUploadCleanupService.php` | 101 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicDropzoneUploadService.php` | 143 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicMultipartUploadService.php` | 262 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicSharedBrowserRepository.php` | 61 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Upload/PublicSharedBrowserService.php` | 189 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/SingleUploadService.php` | 112 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/UploadCatalogRepository.php` | 173 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Upload/UploadCleanupService.php` | 294 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/View/ActivityCostPageRenderer.php` | 401 | class/module | 1 | — | — | — | — |
| `drive/src/View/Ec2PanelHelper.php` | 69 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationDropPageRenderer.php` | 228 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationModerationPageRenderer.php` | 73 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationOsAdminRenderer.php` | 200 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationPageRenderer.php` | 264 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationPortalRenderer.php` | 171 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationReportPageRenderer.php` | 70 | class/module | 1 | — | — | — | — |
| `drive/src/View/FileIconResolver.php` | 136 | class/module | 1 | — | — | — | — |
| `drive/src/View/FileViewHelper.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/View/FolderTreeRenderer.php` | 143 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/View/PersonalAwsPageRenderer.php` | 169 | class/module | 1 | — | — | — | — |
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
| `drive/tests/activity_retention_regression.php` | 67 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/arcadelink_bulk_contract_regression.php` | 71 | test script | 0 | — | — | — | — |
| `drive/tests/arcadelink_collection_regression.php` | 63 | test script | 0 | — | — | — | — |
| `drive/tests/catalog_folder_paths_regression.php` | 63 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/chunked_upload_cleanup_regression.php` | 117 | test script | 1 | — | — | — | — |
| `drive/tests/compute_admission_lock_contract_smoke.php` | 33 | test script | 0 | — | — | — | — |
| `drive/tests/database_backup_contract_smoke.php` | 67 | test script | 0 | — | — | — | — |
| `drive/tests/database_dump_full_integration.php` | 126 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/database_schema_contract_smoke.php` | 127 | test script | 0 | — | — | — | — |
| `drive/tests/fastdrive_control_contract_smoke.php` | 72 | test script | 0 | — | — | — | — |
| `drive/tests/federation_access_message_smoke.php` | 72 | test script | 0 | — | — | — | — |
| `drive/tests/federation_catalog_event_smoke.php` | 60 | test script | 0 | — | — | — | — |
| `drive/tests/federation_customs_contract_smoke.php` | 82 | test script | 0 | — | — | — | — |
| `drive/tests/federation_delivery_history_contract_smoke.php` | 55 | test script | 0 | — | — | — | — |
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
| `drive/tests/file_copy_regression.php` | 84 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/file_mutation_csrf_regression.php` | 97 | test script | 2 | ⚠️ | — | — | — |
| `drive/tests/folder_deletion_regression.php` | 93 | test script | 0 | — | ⚠️ | ⚠️ | — |
| `drive/tests/folder_document_sanitizer.php` | 49 | test script | 0 | — | — | — | — |
| `drive/tests/folder_textract_background_task_contract.php` | 39 | test script | 0 | — | — | — | — |
| `drive/tests/folder_textract_contract_smoke.php` | 34 | test script | 0 | — | — | — | — |
| `drive/tests/fresh_install_contract_smoke.php` | 76 | test script | 0 | — | — | — | — |
| `drive/tests/idle_stop_office_regression.php` | 341 | test script | 1 | — | ⚠️ | — | — |
| `drive/tests/index_federation_drop_smoke.php` | 121 | test script | 0 | — | — | — | — |
| `drive/tests/installer_service_reconcile_contract_smoke.php` | 197 | test script | 0 | — | — | — | — |
| `drive/tests/large_folder_regression.php` | 34 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/local_container_capabilities_regression.php` | 56 | test script | 0 | — | — | — | — |
| `drive/tests/media_processing_contract_smoke.php` | 189 | test script | 0 | — | — | — | — |
| `drive/tests/media_worker_behavior_regression.php` | 65 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/node_diagnostics_contract_smoke.php` | 62 | test script | 0 | — | — | — | — |
| `drive/tests/node_diagnostics_control_smoke.php` | 43 | test script | 0 | — | — | — | — |
| `drive/tests/notebook_contract_smoke.php` | 91 | test script | 0 | — | — | — | — |
| `drive/tests/office_conditional_save_regression.php` | 148 | test script | 1 | — | ⚠️ | — | — |
| `drive/tests/office_gateway_contract_smoke.php` | 376 | test script | 0 | — | — | — | — |
| `drive/tests/password_credential_verifier_smoke.php` | 44 | test script | 0 | — | — | — | — |
| `drive/tests/production_preflight_contract_smoke.php` | 35 | test script | 0 | — | — | — | — |
| `drive/tests/public_upload_rollback_regression.php` | 68 | test script | 0 | — | ⚠️ | ⚠️ | — |
| `drive/tests/scoped_sync_repository_regression.php` | 96 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/security_hardening_smoke.php` | 59 | test script | 0 | — | — | — | — |
| `drive/tests/server_admin_config_smoke.php` | 218 | test script | 0 | — | — | — | — |
| `drive/tests/server_console_contract_smoke.php` | 146 | test script | 0 | — | — | — | — |
| `drive/tests/setup_bootstrap_smoke.php` | 61 | test script | 0 | — | — | — | — |
| `drive/tests/setup_entry_guard_smoke.php` | 38 | test script | 0 | — | — | — | — |
| `drive/tests/setup_finalize_contract_smoke.php` | 75 | test script | 0 | — | — | — | — |
| `drive/tests/smtp_config_smoke.php` | 49 | test script | 0 | — | — | — | — |
| `drive/tests/sync_repository_regression.php` | 91 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/sync_schema_migrator_regression.php` | 75 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/system_panel_fixture.php` | 28 | test script | 0 | — | — | — | — |
| `drive/tests/transcribe_s3_recovery_contract_smoke.php` | 36 | test script | 0 | — | — | — | — |
| `drive/tests/upload_catalog_registration_regression.php` | 125 | test script | 0 | — | ⚠️ | — | — |
| `drive/tests/user_identity_presenter_smoke.php` | 30 | test script | 0 | — | — | — | — |
| `drive/tests/user_profile_validator_smoke.php` | 72 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_clipboard_contract_smoke.php` | 126 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_contract_smoke.php` | 628 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_desktop_shell_smoke.php` | 20 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_file_applications_smoke.php` | 21 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_interactions_theme_smoke.php` | 30 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_multiwindow_smoke.php` | 62 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_pending_fixes_smoke.php` | 59 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_remote_applications_smoke.php` | 49 | test script | 0 | — | — | — | — |
| `drive/tests/web_os_theme_contract_smoke.php` | 33 | test script | 0 | — | — | — | — |
| `drive/tests/workstation_phase1_contract_smoke.php` | 89 | test script | 0 | — | — | — | — |
| `drive/thumb.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_audio.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_texto.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_video.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/traducir_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/transcribir_estado.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/transcribir_iniciar.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/unlock_file.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/up-clean.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/up.php` | 879 | view/entrypoint | 0 | — | — | — | — |
| `drive/update.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/upload/ModerationUploadGuard.php` | 187 | class/module | 2 | — | ⚠️ | — | — |
| `drive/upload/UploadFactory.php` | 60 | class/module | 1 | — | — | — | — |
| `drive/upload/core/UploadResponse.php` | 15 | class/module | 1 | — | — | — | — |
| `drive/upload/core/UploaderInterface.php` | 11 | class/module | 0 | — | — | — | — |
| `drive/upload/drivers/Chunked15MBUploader.php` | 292 | class/module | 1 | — | — | — | — |
| `drive/upload/drivers/DropboxUploader.php` | 147 | class/module | 1 | — | — | — | — |
| `drive/upload/drivers/LocalPresignedPutUploader.php` | 325 | class/module | 1 | — | ⚠️ | — | — |
| `drive/upload/drivers/RemoteUrlUploader.php` | 493 | class/module | 1 | — | — | — | — |
| `drive/upload/repositories/FileS3Repository.php` | 86 | class/module | 1 | — | ⚠️ | — | — |
| `drive/upload/storage/UploadStateStore.php` | 101 | class/module | 1 | — | — | — | — |
| `drive/upload.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/upload_audio_recording.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/upload_publico.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/validar_php.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/ver.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/ver_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/ver_pdf.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/workstation-control.php` | 54 | thin endpoint | 0 | — | — | — | — |
| `drive/workstation-document.php` | 10 | thin endpoint | 0 | — | — | — | — |

## JavaScript

| Archivo | Líneas | Tipo detectado | Clases | Funciones globales | `window` funciones | Observaciones |
|---|---:|---|---|---|---|---|
| `drive/js/actualizar-hora.js` | 36 | class/module | ActualizarHoraModule | — | — | — |
| `drive/js/ai-search.js` | 187 | class/module | DriveAiSearchModule | — | — | — |
| `drive/js/arcadecloud-updater.js` | 326 | class/module | ArcadeCloudUpdaterModule | — | — | — |
| `drive/js/arcadelink-share.js` | 396 | class/module | ArcadeLinkShareModule | — | — | — |
| `drive/js/archivos.js` | 2173 | class/module | ArchivosModule | — | abrirModalRenombrarArchivo, cerrarModalCompartir, setFileSecurity | window functions: abrirModalRenombrarArchivo, cerrarModalCompartir, setFileSecurity |
| `drive/js/audiovideo.js` | 619 | class/module | AudiovideoModule | — | audioNext, audioPrev, reproducirVideoDesde, videoNext, videoPlayPause, videoPrev | window functions: audioNext, audioPrev, reproducirVideoDesde, videoNext, videoPlayPause, videoPrev, wavePlayPause |
| `drive/js/aws-comprehend.js` | 355 | class/module | AwsComprehendModule, AwsFileActionRouter | — | — | — |
| `drive/js/background-task-feedback.js` | 180 | class/module | BackgroundTaskFeedbackModule | — | — | — |
| `drive/js/background-tasks.js` | 1187 | class/module | BackgroundTaskCenter | — | — | — |
| `drive/js/carpetas.js` | 1200 | class/module | CarpetasModule | — | actualizarBloqueCarpetas | window functions: actualizarBloqueCarpetas |
| `drive/js/compute-node-idle.js` | 290 | class/module | ArcadeCloudComputeIdleGuard | — | — | — |
| `drive/js/descarga-multiple.js` | 114 | class/module | DescargaMultipleModule | — | — | — |
| `drive/js/descarga-uno.js` | 110 | class/module | DescargaUnoModule | — | — | — |
| `drive/js/desktop-shell.js` | 182 | class/module | ArcadeCloudDesktopShell | — | — | — |
| `drive/js/editar-txt.js` | 68 | class/module | EditarTxtModule | — | — | — |
| `drive/js/elimina-multiple.js` | 104 | class/module | EliminaMultipleModule | — | — | — |
| `drive/js/elimina-uno.js` | 122 | class/module | EliminaUnoModule | — | — | — |
| `drive/js/estilo.js` | 267 | class/module | EstiloModule | — | — | — |
| `drive/js/federation-drop.js` | 488 | class/module | FederationDropApp | — | — | — |
| `drive/js/federation-footer.js` | 374 | class/module | FederationFooterModule | — | — | — |
| `drive/js/federation-os-admin.js` | 325 | class/module | FederationOsAdminModule | — | — | — |
| `drive/js/federation-page.js` | 99 | class/module | FederationPageModule | — | — | — |
| `drive/js/federation-portal.js` | 511 | class/module | FederationPortalModule | — | — | — |
| `drive/js/federation-share-drive.js` | 214 | class/module | FederationShareDriveModule | — | — | — |
| `drive/js/file-applications.js` | 238 | class/module | ArcadeCloudFileApplicationService | — | — | — |
| `drive/js/file-block.js` | 306 | class/module | FileBlockApp | — | — | — |
| `drive/js/file-security.js` | 325 | class/module | ArcadeCloudFileSecurity | — | — | — |
| `drive/js/filesystem-operations.js` | 192 | class/module | ArcadeCloudFilesystemOperations | — | — | — |
| `drive/js/filtros.js` | 97 | class/module | FiltrosModule | — | — | — |
| `drive/js/folder-document.js` | 465 | class/module | FolderDocumentModule | — | openFolderDocumentCreator | window functions: openFolderDocumentCreator |
| `drive/js/imagenes.js` | 535 | class/module | ImagenesModule | — | getGaleriaGridSize, setGaleriaGridSize | window functions: getGaleriaGridSize, setGaleriaGridSize |
| `drive/js/media-floating.js` | 696 | class/module | MediaFloatingApp | — | — | — |
| `drive/js/media-processing.js` | 498 | class/module | MediaProcessingModule | — | — | — |
| `drive/js/mediaFloating.js` | 38 | class/module | MediaFloatingModule | — | — | — |
| `drive/js/move-tasks.js` | 262 | class/module | DriveMoveTasks | — | — | — |
| `drive/js/notebook.js` | 262 | procedural script | — | uid, status, api, canvasSize, applyZoom, background | — | top-level functions: uid, status, api, canvasSize, applyZoom, background, styleFor, drawStroke; top-level state: $, canvas, imageCache, defaultZoom, state, paper, WRITE_LEFT, fontStacks; no ES class |
| `drive/js/obtenerFiltros.js` | 125 | class/module | ObtenerFiltrosModule | — | — | — |
| `drive/js/os-media-cloud.js` | 543 | class/module | ArcadeCloudMediaCloud | — | — | — |
| `drive/js/os-window-manager.js` | 1307 | class/module | ArcadeCloudEventBus, ArcadeCloudWindowLayoutConfig, ArcadeCloudWindowManager, ExplorerWindowFactory, ArcadeCloudExplorerWindow, ArcadeCloudDesktopRuntime | — | — | — |
| `drive/js/page-task-manager.js` | 119 | class/module | ArcadeCloudPageTaskManager | — | — | — |
| `drive/js/pdf-pantalla-completa.js` | 45 | class/module | PdfPantallaCompletaModule | — | — | — |
| `drive/js/polly-background.js` | 306 | class/module | PollyBackgroundModule | — | — | — |
| `drive/js/polly.js` | 1009 | class/module | PollyModule | — | abrirModalGrabarAudio, abrirModalPolly, abrirModalRekognition, abrirModalTraducir, abrirModalTranscribir, copiarTextract | window functions: abrirModalGrabarAudio, abrirModalPolly, abrirModalRekognition, abrirModalTraducir, abrirModalTranscribir, copiarTextract, copiarTraducido, copiarTx |
| `drive/js/profile.js` | 289 | class/module | UserProfileModule | — | — | — |
| `drive/js/recargarPagina.js` | 27 | class/module | RecargarPaginaModule | — | — | — |
| `drive/js/server-admin.js` | 415 | class/module | ServerAdminModule | — | — | — |
| `drive/js/setup.js` | 192 | class/module | ArcadeCloudSetup | — | — | — |
| `drive/js/sincronizar.js` | 265 | class/module | SincronizarModule | — | triggerSyncFolderS3, triggerSyncS3 | window functions: triggerSyncFolderS3, triggerSyncS3 |
| `drive/js/so-appearance.js` | 165 | class/module | ArcadeCloudOsAppearance | — | — | — |
| `drive/js/so-clipboard.js` | 922 | class/module | ArcadeCloudOsClipboard | — | — | — |
| `drive/js/so-federation.js` | 102 | class/module | ArcadeCloudOsFederationApp | — | — | — |
| `drive/js/so-folders.js` | 485 | class/module | ArcadeCloudOsFolderActions | — | — | — |
| `drive/js/so-node.js` | 513 | class/module | ArcadeCloudOsNodeMonitor | — | — | — |
| `drive/js/so-power.js` | 108 | class/module | ArcadeCloudFastDrivePower | — | — | — |
| `drive/js/so-screenshot-paste.js` | 341 | class/module | ArcadeCloudOsScreenshotPaste | — | — | — |
| `drive/js/so-search.js` | 292 | class/module | ArcadeCloudOsSearch | — | — | — |
| `drive/js/so-share.js` | 180 | class/module | ArcadeCloudOsShare | — | — | — |
| `drive/js/so-terminal.js` | 314 | class/module | ArcadeCloudOsTerminal | — | — | — |
| `drive/js/so.js` | 1349 | class/module | ArcadeCloudOsShell | — | — | — |
| `drive/js/soportesMediaTypes.js` | 397 | class/module | SoportesMediaTypesModule | — | — | — |
| `drive/js/storage-usage.js` | 51 | class/module | StorageUsageModule | — | — | — |
| `drive/js/subir-chunked.js` | 594 | class/module | SubirChunkedModule | — | — | — |
| `drive/js/subir-dropzone.js` | 910 | class/module | SubirDropzoneModule | — | — | — |
| `drive/js/subir.js` | 358 | class/module | SubirModule | — | — | — |
| `drive/js/theme-state-bridge.js` | 73 | class/module | ThemeStateBridge | — | — | — |
| `drive/js/transcribe-background.js` | 324 | class/module | TranscribeBackgroundModule | — | — | — |
| `drive/js/upload-center.js` | 979 | class/module | ArcadeCloudUploadCenter | — | — | — |
| `drive/js/upload-destination.js` | 95 | class/module | UploadDestinationModule | — | — | — |
| `drive/js/ver-metadatos.js` | 75 | class/module | VerMetadatosModule | — | verMetadatos | window functions: verMetadatos |
| `drive/js/ver-pdf.js` | 112 | class/module | VerPdfModule | — | — | — |
| `drive/tests/background_tasks_refresh_functional.js` | 22 | procedural script | — | — | — | top-level state: assert, vm, fs, source, context; no ES class |
| `drive/tests/classic_mutation_csrf_functional.js` | 56 | class/module | FixtureFormData | — | — | — |
| `drive/tests/local_container_programs_functional.js` | 25 | class/module | Element | — | — | — |
| `drive/tests/web_os_desktop_shell_functional.js` | 40 | procedural script | — | assert | — | top-level functions: assert; top-level state: editable, records, instance, registered, declarative, launcher, remoteApps; no ES class |
| `drive/tests/web_os_file_applications_functional.js` | 54 | class/module | Bus | assert | — | top-level functions: assert |
| `drive/tests/web_os_filesystem_operations_functional.js` | 59 | procedural script | — | assert | — | top-level functions: assert; top-level state: events, busEvents, doc, win, service; no ES class |
| `drive/tests/web_os_folder_rename_functional.js` | 34 | procedural script | — | — | — | top-level state: assert, listeners, document, window, desktop, navigations; no ES class |
| `drive/tests/web_os_multiwindow_functional.js` | 125 | class/module | Classes, ElementStub | windowStub, assert | — | top-level functions: windowStub, assert |
| `drive/tests/web_os_same_explorer_clipboard_functional.js` | 76 | procedural script | — | assert, storage | — | top-level functions: assert, storage; top-level state: requests, refreshed, filesystemEvents, explorer, win, doc, clipboard, entries; no ES class |
| `drive/tests/web_os_search_location_functional.js` | 62 | class/module | Element | — | — | — |
| `drive/tests/web_os_upload_context_functional.js` | 44 | procedural script | — | assert, button | — | top-level functions: assert, button; top-level state: routeLabel, doc, center; no ES class |

## AJAX y contratos JSON

| Cliente JavaScript | `fetch` | XHR | jQuery AJAX | Lecturas JSON | Comprobaciones de respuesta |
|---|---:|---:|---:|---:|---:|
| `drive/js/ai-search.js` | 1 | 0 | 0 | 1 | 2 |
| `drive/js/arcadecloud-updater.js` | 2 | 0 | 0 | 0 | 30 |
| `drive/js/arcadelink-share.js` | 1 | 0 | 0 | 0 | 2 |
| `drive/js/archivos.js` | 3 | 0 | 0 | 1 | 22 |
| `drive/js/audiovideo.js` | 1 | 0 | 0 | 1 | 0 |
| `drive/js/aws-comprehend.js` | 1 | 0 | 0 | 0 | 3 |
| `drive/js/background-tasks.js` | 2 | 0 | 0 | 0 | 16 |
| `drive/js/carpetas.js` | 2 | 0 | 0 | 2 | 11 |
| `drive/js/compute-node-idle.js` | 3 | 0 | 0 | 3 | 6 |
| `drive/js/descarga-multiple.js` | 1 | 0 | 0 | 0 | 2 |
| `drive/js/descarga-uno.js` | 1 | 0 | 0 | 0 | 2 |
| `drive/js/elimina-multiple.js` | 2 | 0 | 0 | 1 | 3 |
| `drive/js/elimina-uno.js` | 2 | 0 | 0 | 1 | 3 |
| `drive/js/federation-drop.js` | 5 | 0 | 0 | 3 | 16 |
| `drive/js/federation-footer.js` | 6 | 0 | 0 | 6 | 18 |
| `drive/js/federation-os-admin.js` | 1 | 0 | 0 | 0 | 3 |
| `drive/js/federation-portal.js` | 1 | 0 | 0 | 0 | 11 |
| `drive/js/federation-share-drive.js` | 1 | 0 | 0 | 0 | 5 |
| `drive/js/file-block.js` | 1 | 0 | 0 | 1 | 2 |
| `drive/js/file-security.js` | 1 | 0 | 0 | 0 | 6 |
| `drive/js/filesystem-operations.js` | 1 | 0 | 0 | 0 | 8 |
| `drive/js/folder-document.js` | 1 | 0 | 0 | 1 | 2 |
| `drive/js/media-floating.js` | 1 | 0 | 0 | 1 | 3 |
| `drive/js/media-processing.js` | 3 | 0 | 0 | 3 | 5 |
| `drive/js/move-tasks.js` | 2 | 0 | 0 | 0 | 6 |
| `drive/js/notebook.js` | 2 | 0 | 0 | 2 | 5 |
| `drive/js/obtenerFiltros.js` | 3 | 0 | 0 | 0 | 0 |
| `drive/js/os-media-cloud.js` | 1 | 0 | 0 | 1 | 2 |
| `drive/js/os-window-manager.js` | 5 | 0 | 0 | 2 | 16 |
| `drive/js/polly-background.js` | 1 | 0 | 0 | 0 | 3 |
| `drive/js/polly.js` | 0 | 0 | 2 | 0 | 8 |
| `drive/js/profile.js` | 2 | 0 | 0 | 1 | 4 |
| `drive/js/server-admin.js` | 2 | 0 | 0 | 2 | 6 |
| `drive/js/setup.js` | 2 | 0 | 0 | 2 | 6 |
| `drive/js/sincronizar.js` | 1 | 0 | 0 | 0 | 3 |
| `drive/js/so-appearance.js` | 1 | 0 | 0 | 0 | 0 |
| `drive/js/so-clipboard.js` | 1 | 0 | 0 | 1 | 2 |
| `drive/js/so-federation.js` | 2 | 0 | 0 | 0 | 5 |
| `drive/js/so-folders.js` | 1 | 0 | 0 | 1 | 3 |
| `drive/js/so-node.js` | 5 | 0 | 0 | 5 | 10 |
| `drive/js/so-power.js` | 1 | 0 | 0 | 1 | 2 |
| `drive/js/so-screenshot-paste.js` | 3 | 0 | 0 | 1 | 7 |
| `drive/js/so-search.js` | 2 | 0 | 0 | 1 | 2 |
| `drive/js/so-share.js` | 1 | 0 | 0 | 0 | 1 |
| `drive/js/so-terminal.js` | 2 | 0 | 0 | 2 | 5 |
| `drive/js/so.js` | 1 | 0 | 0 | 0 | 1 |
| `drive/js/soportesMediaTypes.js` | 2 | 1 | 0 | 2 | 9 |
| `drive/js/storage-usage.js` | 1 | 0 | 0 | 1 | 3 |
| `drive/js/subir-chunked.js` | 4 | 1 | 0 | 1 | 11 |
| `drive/js/subir-dropzone.js` | 1 | 1 | 0 | 0 | 12 |
| `drive/js/subir.js` | 4 | 0 | 0 | 1 | 11 |
| `drive/js/transcribe-background.js` | 1 | 0 | 0 | 0 | 7 |
| `drive/js/upload-center.js` | 8 | 2 | 0 | 1 | 21 |

### Archivos JSON

| Archivo | Válido | Tipo raíz | Claves raíz |
|---|:---:|---|---|
| `composer.json` | sí | object | `require` |
| `drive/config/activity-cost-pricing.json` | sí | object | `currency`, `notes`, `rates`, `region_reference`, `source_id`, `version` |
| `drive/config/federation-seeds.json` | sí | object | `bootstrap_nodes`, `seeds`, `version` |
| `drive/docs/runtime_endpoints.json` | sí | object | `active`, `rows` |

## Dictamen

- **PHP runtime:** consistente estructuralmente con entrypoints delgados y capas Controller/Service/Repository. Las vistas, bootstraps y tests son excepciones deliberadas; convertirlos en clases no aportaría encapsulación.
- **JavaScript:** todos los archivos están encapsulados en clases. Las fachadas globales existentes son deuda de compatibilidad, no lógica procedural nueva; deben reducirse sólo al migrar sus consumidores HTML.
- **AJAX:** las llamadas permanecen dentro de módulos OOP. La cantidad de lecturas JSON y comprobaciones es una señal heurística, no una prueba de corrección: los contratos funcionales continúan cubiertos por smoke tests.
- **JSON:** los documentos válidos se consideran DTO/configuración. No corresponde convertir datos JSON a clases; la conversión a objetos tipados debe ocurrir en el límite PHP/JavaScript cuando el dominio lo requiera.

### Prioridades de mantenimiento

1. No añadir SQL, SDK AWS ni acceso directo a superglobales en entrypoints.
2. Centralizar gradualmente transporte AJAX repetido en colaboradores inyectables, sin romper URLs públicas.
3. Mantener las fachadas `window` como adaptadores mínimos y evitar estado de negocio global.
4. Validar todo JSON al cargarlo y versionar explícitamente los payloads federados persistentes.

## Objetivo de refactorización

```text
HTTP entrypoint -> Controller -> Application Service -> Repository/Infrastructure
                                      |
                                      +-> S3 / AWS service
                                      +-> MySQL repository

Browser -> JS App class -> DOM/HTTP services -> PHP endpoint
```

El objetivo no es envolver código procedural en una clase gigante, sino separar responsabilidades y dependencias.
