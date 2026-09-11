# Auditoría OOP — ArcadeCloud Drive

> Generado automáticamente. No sustituye pruebas funcionales; detecta estructura y dependencias procedurales.

## Resumen

- PHP analizados: **262**
- PHP que ya contienen clases/interfaces: **162**
- PHP marcados para migración/revisión: **19**
- JavaScript analizados: **36**
- JavaScript que ya contienen clases: **35**
- JavaScript marcados para migración/revisión: **7**

## Criterio

- `src/` y `upload/`: lógica de negocio e infraestructura en clases.
- Entry points públicos: bootstrap + Controller/Service; sin SQL/AWS ni funciones globales.
- Vistas: pueden contener HTML, pero no deben crear clientes AWS/DB ni declarar funciones globales.
- JavaScript: comportamiento en clases; `window` solo para una fachada de compatibilidad explícita.

## PHP

| Archivo | Líneas | Tipo detectado | Clases | Sesión | DB | AWS/S3 | Observaciones |
|---|---:|---|---:|:---:|:---:|:---:|---|
| `drive/activity_costs.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/actualizar_ruta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/api/upload.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/app_bootstrap.php` | 53 | procedural endpoint | 0 | — | ⚠️ | — | DB in endpoint |
| `drive/aws.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/bin/arcadecloud-drive-admin-helper.php` | 166 | procedural endpoint | 0 | — | — | — | global functions: fail, isRoot, base64UrlEncode, base64UrlDecode, nodeIdFromPublicKey, normalizeNodeName, readConfig, safeConfiguredPath |
| `drive/bin/federation_identity_backup.php` | 49 | procedural endpoint | 0 | — | — | — | — |
| `drive/bin/federation_identity_init.php` | 33 | procedural endpoint | 0 | — | — | — | — |
| `drive/bin/federation_identity_name.php` | 35 | procedural endpoint | 0 | — | — | — | — |
| `drive/bin/federation_identity_restore.php` | 48 | procedural endpoint | 0 | — | — | — | — |
| `drive/bin/federation_provider_request.php` | 86 | procedural endpoint | 0 | — | — | — | — |
| `drive/bin/sync_worker.php` | 113 | endpoint with logic | 0 | — | — | — | — |
| `drive/bin/upload_cleanup.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/bloque_archivos.php` | 662 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/bloque_carpetas.php` | 75 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/bloque_footer.php` | 163 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/buscar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/comprehend_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/costos_aws.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/crear_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/delete_multiple.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/descargar_zip.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/download_multiple.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/ec2-cron.php` | 39 | thin endpoint | 0 | — | — | — | — |
| `drive/ec2.php` | 841 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/editor.php` | 485 | view/entrypoint | 0 | — | — | — | global functions: detectarLenguaje, setStatus, limpiarMarcadores, marcarGuardado, actualizarBotonValidar, aplicarLenguaje, poblarLenguajes, deshacer |
| `drive/eliminar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/eliminar_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/encriptar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/create.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/index.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/name-availability.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/node-admin.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/node.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/nodes.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/provider-admin.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/provider-request.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/providers.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/register.php` | 12 | thin endpoint | 0 | — | — | — | — |
| `drive/federationcloud/resolve.php` | 13 | thin endpoint | 0 | — | — | — | — |
| `drive/generar_token.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/guardar_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/index.php` | 87 | view/entrypoint | 0 | — | — | — | — |
| `drive/leer_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/listar_carpetas.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/login.php` | 92 | view/entrypoint | 0 | — | — | — | — |
| `drive/logout.php` | 14 | thin endpoint | 0 | — | — | — | — |
| `drive/media_playlist.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/move_multiple.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/move_task.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/move_task_status.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/mover_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/mover_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_cargar_texto.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_list_voices.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/polly_tts.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/procesar_textract.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/profile.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/psesion.php` | 14 | thin endpoint | 0 | — | — | — | — |
| `drive/rekognition_labels.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/relock_file.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/renombrar_archivo.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/renombrar_carpeta.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/s3.php` | 2227 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/server-settings.php` | 11 | thin endpoint | 0 | — | — | — | — |
| `drive/set_file_security.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/src/Activity/ActivityCostRecorder.php` | 155 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/ActivityCostRepository.php` | 173 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Activity/ActivityCostService.php` | 148 | class/module | 1 | — | — | — | — |
| `drive/src/Activity/AwsUnitPriceCatalog.php` | 81 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/ManagedRuntimeEnvironment.php` | 153 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/PrivilegedServerHelper.php` | 76 | class/module | 1 | — | — | — | — |
| `drive/src/Admin/ServerSettingsAdminService.php` | 89 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/AiFileSearchService.php` | 470 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/DrivePageService.php` | 40 | class/module | 1 | — | — | — | — |
| `drive/src/Application/DrivePageViewModel.php` | 18 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileAccessService.php` | 114 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileKeyRotationService.php` | 67 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FileListService.php` | 155 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FileMutationService.php` | 119 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FileSearchService.php` | 104 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Application/FolderMutationService.php` | 214 | class/module | 1 | — | — | — | — |
| `drive/src/Application/FolderQueryService.php` | 152 | class/module | 1 | — | — | — | — |
| `drive/src/Application/MoveJobService.php` | 117 | class/module | 1 | — | — | — | — |
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
| `drive/src/Aws/PersonalTotpService.php` | 44 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/PollyFileService.php` | 68 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/RdsGateway.php` | 233 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/RekognitionFileService.php` | 30 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/SesEmailService.php` | 29 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/TextractFileService.php` | 107 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/TranscriptionFileService.php` | 126 | class/module | 1 | — | — | — | — |
| `drive/src/Aws/TranslateFileService.php` | 60 | class/module | 1 | — | — | — | — |
| `drive/src/Console/UploadCleanupCommand.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Core/ApplicationKernel.php` | 38 | class/module | 1 | — | — | — | — |
| `drive/src/Core/DriveApplication.php` | 488 | class/module | 1 | — | — | ⚠️ | — |
| `drive/src/Federation/ArcadeLinkService.php` | 282 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederatedResourceRepository.php` | 117 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationCodec.php` | 54 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationConfig.php` | 89 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationDirectoryService.php` | 151 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationException.php` | 20 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationHttpClient.php` | 152 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationNodeAdminService.php` | 213 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationNodeDescriptorValidator.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationNodeRepository.php` | 166 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationProviderAuthorizationRepository.php` | 215 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Federation/FederationProviderAuthorizationService.php` | 233 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationProviderGrant.php` | 101 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationResolverService.php` | 185 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationSeedConfig.php` | 83 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/FederationService.php` | 146 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/NodeIdentityBackupService.php` | 228 | class/module | 1 | — | — | — | — |
| `drive/src/Federation/NodeIdentityService.php` | 294 | class/module | 1 | — | — | — | — |
| `drive/src/Http/BinaryResponse.php` | 85 | class/module | 1 | — | — | — | — |
| `drive/src/Http/ByteRange.php` | 45 | procedural endpoint | 0 | — | — | — | — |
| `drive/src/Http/Controller/AbstractJsonController.php` | 69 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ActivityCostController.php` | 70 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AuthController.php` | 94 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AwsCostController.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/AwsFileController.php` | 325 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationController.php` | 248 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationDirectoryController.php` | 85 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationNodeAdminController.php` | 46 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FederationProviderController.php` | 115 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileAccessController.php` | 145 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileKeyRotationController.php` | 35 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileMutationController.php` | 124 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileSearchController.php` | 65 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FileSecurityController.php` | 97 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FolderMutationController.php` | 203 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/FolderQueryController.php` | 30 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/LegacyUploadController.php` | 140 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/MediaPlaylistController.php` | 35 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/MoveJobController.php` | 243 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/NavigationController.php` | 42 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PersonalAwsController.php` | 74 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PublicShareController.php` | 209 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PublicSharedBrowserController.php` | 76 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/PublicUploadController.php` | 81 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ServerSettingsAdminController.php` | 52 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ShareController.php` | 78 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/StorageUsageController.php` | 28 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/SyncController.php` | 181 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/TextEditorController.php` | 61 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/ThumbnailController.php` | 110 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/TranscriptionController.php` | 128 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UploadCleanupController.php` | 76 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UploadController.php` | 252 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Controller/UserProfileController.php` | 118 | class/module | 1 | — | — | — | — |
| `drive/src/Http/JsonResponse.php` | 27 | class/module | 1 | — | — | — | — |
| `drive/src/Http/Request.php` | 112 | class/module | 1 | — | — | — | — |
| `drive/src/Mail/SmtpConfig.php` | 111 | class/module | 1 | — | — | — | — |
| `drive/src/Mail/SmtpEmailService.php` | 228 | class/module | 1 | — | — | — | — |
| `drive/src/Media/MediaPlaylistRepository.php` | 49 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Media/MediaPlaylistService.php` | 66 | class/module | 1 | — | — | — | — |
| `drive/src/Media/ThumbnailService.php` | 380 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/AuthenticationRepository.php` | 59 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/AuthenticationService.php` | 45 | class/module | 1 | — | — | — | — |
| `drive/src/Security/FileSecurityRepository.php` | 92 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/FileSecurityService.php` | 183 | class/module | 1 | — | — | — | — |
| `drive/src/Security/PasswordChangeService.php` | 141 | class/module | 1 | — | — | — | — |
| `drive/src/Security/PersonalToolAccessService.php` | 53 | class/module | 1 | — | — | — | — |
| `drive/src/Security/SessionManager.php` | 149 | class/module | 1 | ⚠️ | — | — | — |
| `drive/src/Security/SuperAdminReauthenticationService.php` | 31 | class/module | 1 | — | — | — | — |
| `drive/src/Security/UserDirectoryRepository.php` | 72 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/UserProfileRepository.php` | 117 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Security/UserProfileService.php` | 183 | class/module | 1 | — | — | — | — |
| `drive/src/Security/UserProfileValidator.php` | 96 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareAccessService.php` | 105 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareException.php` | 22 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareFileRepository.php` | 72 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sharing/ShareLinkService.php` | 78 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareObjectStorage.php` | 37 | class/module | 1 | — | — | — | — |
| `drive/src/Sharing/ShareTokenStore.php` | 132 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/FileRecordRepository.php` | 122 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/FolderMutationRepository.php` | 239 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/FolderRepository.php` | 108 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/MoveJobStore.php` | 171 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/StorageObjectNameCodec.php` | 104 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/StorageUsageService.php` | 103 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Storage/UserStoragePath.php` | 50 | class/module | 1 | — | — | — | — |
| `drive/src/Storage/UserStorageProvisioner.php` | 105 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Sync/S3SyncService.php` | 387 | class/module | 1 | — | — | — | — |
| `drive/src/Sync/SyncJobStore.php` | 138 | class/module | 1 | — | — | — | — |
| `drive/src/Sync/SyncRepository.php` | 630 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Upload/AdminMultipartUploadService.php` | 123 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicDropzoneUploadService.php` | 125 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicMultipartUploadService.php` | 262 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/PublicSharedBrowserRepository.php` | 61 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Upload/PublicSharedBrowserService.php` | 189 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/SingleUploadService.php` | 104 | class/module | 1 | — | — | — | — |
| `drive/src/Upload/UploadCatalogRepository.php` | 115 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/Upload/UploadCleanupService.php` | 316 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/View/ActivityCostPageRenderer.php` | 358 | class/module | 1 | — | — | — | — |
| `drive/src/View/Ec2PanelHelper.php` | 69 | class/module | 1 | — | — | — | — |
| `drive/src/View/FederationPageRenderer.php` | 186 | class/module | 1 | — | — | — | — |
| `drive/src/View/FileIconResolver.php` | 136 | class/module | 1 | — | — | — | — |
| `drive/src/View/FileViewHelper.php` | 108 | class/module | 1 | — | — | — | — |
| `drive/src/View/FolderTreeRenderer.php` | 123 | class/module | 1 | — | ⚠️ | — | — |
| `drive/src/View/PersonalAwsPageRenderer.php` | 135 | class/module | 1 | — | — | — | — |
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
| `drive/tests/activity_costs_smoke.php` | 79 | view/entrypoint | 0 | — | — | — | global functions: check |
| `drive/tests/federation_directory_smoke.php` | 102 | procedural endpoint | 0 | — | — | — | global functions: directoryOk |
| `drive/tests/federation_node_name_admin_smoke.php` | 91 | procedural endpoint | 0 | — | — | — | — |
| `drive/tests/federation_provider_smoke.php` | 76 | procedural endpoint | 0 | — | — | — | global functions: providerOk |
| `drive/tests/federationcloud_smoke.php` | 146 | procedural endpoint | 0 | — | — | — | global functions: ok, legacyDocument |
| `drive/tests/server_admin_config_smoke.php` | 67 | procedural endpoint | 0 | — | — | — | global functions: serverAdminOk |
| `drive/tests/smtp_config_smoke.php` | 42 | procedural endpoint | 0 | — | — | — | — |
| `drive/tests/user_identity_presenter_smoke.php` | 30 | procedural endpoint | 0 | — | — | — | — |
| `drive/tests/user_profile_validator_smoke.php` | 57 | procedural endpoint | 0 | — | — | — | — |
| `drive/thumb.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_audio.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_texto.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/token_video.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/traducir_archivo.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/transcribir_estado.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/transcribir_iniciar.php` | 8 | thin endpoint | 0 | — | — | — | — |
| `drive/unlock_file.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/up-clean.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/up.php` | 759 | view/entrypoint | 0 | ⚠️ | — | — | — |
| `drive/upload/UploadFactory.php` | 60 | class/module | 1 | — | — | — | — |
| `drive/upload/core/UploadResponse.php` | 15 | class/module | 1 | — | — | — | — |
| `drive/upload/core/UploaderInterface.php` | 11 | class/module | 0 | — | — | — | — |
| `drive/upload/drivers/Chunked15MBUploader.php` | 266 | class/module | 1 | — | — | — | — |
| `drive/upload/drivers/DropboxUploader.php` | 129 | class/module | 1 | — | — | — | — |
| `drive/upload/drivers/LocalPresignedPutUploader.php` | 287 | class/module | 1 | — | ⚠️ | — | — |
| `drive/upload/drivers/RemoteUrlUploader.php` | 260 | class/module | 1 | — | — | — | — |
| `drive/upload/repositories/FileS3Repository.php` | 86 | class/module | 1 | — | ⚠️ | — | — |
| `drive/upload/storage/UploadStateStore.php` | 51 | class/module | 1 | — | — | — | — |
| `drive/upload.php` | 10 | thin endpoint | 0 | — | — | — | — |
| `drive/upload_audio_recording.php` | 91 | thin endpoint | 0 | ⚠️ | — | ⚠️ | — |
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
| `drive/js/arcadelink-share.js` | 195 | class/module | ArcadeLinkShareModule | — | — | — |
| `drive/js/archivos.js` | 2155 | class/module | ArchivosModule | — | abrirModalRenombrarArchivo, cerrarModalCompartir, setFileSecurity | window functions: abrirModalRenombrarArchivo, cerrarModalCompartir, setFileSecurity |
| `drive/js/audiovideo.js` | 619 | class/module | AudiovideoModule | — | audioNext, audioPrev, reproducirVideoDesde, videoNext, videoPlayPause, videoPrev | window functions: audioNext, audioPrev, reproducirVideoDesde, videoNext, videoPlayPause, videoPrev, wavePlayPause |
| `drive/js/aws-comprehend.js` | 342 | class/module | AwsComprehendModule, AwsFileActionRouter | — | — | — |
| `drive/js/carpetas.js` | 1105 | class/module | CarpetasModule | — | actualizarBloqueCarpetas | window functions: actualizarBloqueCarpetas |
| `drive/js/descarga-multiple.js` | 114 | class/module | DescargaMultipleModule | — | — | — |
| `drive/js/descarga-uno.js` | 110 | class/module | DescargaUnoModule | — | — | — |
| `drive/js/editar-txt.js` | 68 | class/module | EditarTxtModule | — | — | — |
| `drive/js/elimina-multiple.js` | 104 | class/module | EliminaMultipleModule | — | — | — |
| `drive/js/elimina-uno.js` | 122 | class/module | EliminaUnoModule | — | — | — |
| `drive/js/estilo.js` | 351 | class/module | EstiloModule | — | — | — |
| `drive/js/federation-footer.js` | 341 | class/module | FederationFooterModule | — | — | — |
| `drive/js/federation-page.js` | 95 | class/module | FederationPageModule | — | — | — |
| `drive/js/file-block.js` | 305 | class/module | FileBlockApp | — | — | — |
| `drive/js/filtros.js` | 97 | class/module | FiltrosModule | — | — | — |
| `drive/js/imagenes.js` | 535 | class/module | ImagenesModule | — | getGaleriaGridSize, setGaleriaGridSize | window functions: getGaleriaGridSize, setGaleriaGridSize |
| `drive/js/media-floating.js` | 685 | procedural script | — | — | — | no ES class |
| `drive/js/mediaFloating.js` | 38 | class/module | MediaFloatingModule | — | — | — |
| `drive/js/move-tasks.js` | 215 | class/module | DriveMoveTasks | — | — | — |
| `drive/js/obtenerFiltros.js` | 125 | class/module | ObtenerFiltrosModule | — | — | — |
| `drive/js/pdf-pantalla-completa.js` | 45 | class/module | PdfPantallaCompletaModule | — | — | — |
| `drive/js/polly.js` | 1008 | class/module | PollyModule | — | abrirModalGrabarAudio, abrirModalPolly, abrirModalRekognition, abrirModalTraducir, abrirModalTranscribir, copiarTextract | window functions: abrirModalGrabarAudio, abrirModalPolly, abrirModalRekognition, abrirModalTraducir, abrirModalTranscribir, copiarTextract, copiarTraducido, copiarTx |
| `drive/js/profile.js` | 277 | class/module | UserProfileModule | — | — | — |
| `drive/js/recargarPagina.js` | 27 | class/module | RecargarPaginaModule | — | — | — |
| `drive/js/server-admin.js` | 153 | class/module | ServerAdminModule | — | — | — |
| `drive/js/sincronizar.js` | 315 | class/module | SincronizarModule | — | — | — |
| `drive/js/soportesMediaTypes.js` | 390 | class/module | SoportesMediaTypesModule | — | — | — |
| `drive/js/storage-usage.js` | 51 | class/module | StorageUsageModule | — | — | — |
| `drive/js/subir-chunked.js` | 586 | class/module | SubirChunkedModule | — | — | — |
| `drive/js/subir-dropzone.js` | 868 | class/module | SubirDropzoneModule | — | — | — |
| `drive/js/subir.js` | 339 | class/module | SubirModule | — | — | — |
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
