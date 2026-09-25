<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    $content = @file_get_contents($full);
    if (!is_string($content)) {
        fwrite(STDERR, "Missing: {$path}\n");
        exit(1);
    }
    return $content;
};
$ok = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
};

$sql = $read('adbbmis1_Cloud.sql');
$codec = $read('drive/src/Federation/FederationEventCodec.php');
$catalog = $read('drive/src/Federation/FederatedCatalogRepository.php');
$drop = $read('drive/src/Federation/FederationDropService.php');
$storage = $read('drive/src/Federation/FederationDropStorageService.php');
$sync = $read('drive/src/Federation/FederationSyncCycleService.php');
$moderation = $read('drive/src/Federation/FederationModerationService.php');
$fingerprint = $read('drive/src/Federation/FederationContentFingerprintService.php');
$federationService = $read('drive/src/Federation/FederationService.php');
$moderationRepo = $read('drive/src/Federation/FederationModerationRepository.php');
$moderationSchema = $read('drive/src/Federation/FederationModerationSchemaService.php');
$updaterService = $read('drive/src/Admin/ArcadeCloudUpdaterService.php');
$controller = $read('drive/src/Http/Controller/FederationModerationController.php');
$footer = $read('drive/bloque_footer.php');
$portal = $read('drive/js/federation-portal.js');
$dropJs = $read('drive/js/federation-drop.js');
$uploadRepo = $read('drive/src/Upload/UploadCatalogRepository.php');
$singleUpload = $read('drive/src/Upload/SingleUploadService.php');
$publicDropzone = $read('drive/src/Upload/PublicDropzoneUploadService.php');
$uploadGuard = $read('drive/upload/ModerationUploadGuard.php');
$localPresigned = $read('drive/upload/drivers/LocalPresignedPutUploader.php');
$chunkedUpload = $read('drive/upload/drivers/Chunked15MBUploader.php');
$remoteUrlUpload = $read('drive/upload/drivers/RemoteUrlUploader.php');
$dropboxUpload = $read('drive/upload/drivers/DropboxUploader.php');
$adminMultipart = $read('drive/src/Upload/AdminMultipartUploadService.php');
$uploadController = $read('drive/src/Http/Controller/UploadController.php');
$localUploadJs = $read('drive/js/subir.js');
$dropzoneUploadJs = $read('drive/js/subir-dropzone.js');
$docs = $read('drive/docs/FEDERATION_CONTENT_MODERATION.md');
$reportView = $read('drive/src/View/FederationReportPageRenderer.php');
$adminView = $read('drive/src/View/FederationModerationPageRenderer.php');
$reconciler = $read('drive/bin/reconcile_arcadecloud_services.sh');

foreach ([
    'FederationContentFingerprints',
    'FederationAbuseReports',
    'FederationModerationBlocks',
    'FederationModerationActions',
] as $table) {
    $ok(str_contains($sql, 'CREATE TABLE IF NOT EXISTS ' . $table), "canonical SQL contains {$table}");
}

$ok(str_contains($codec, "'moderation.block'"), 'signed event allowlist contains moderation.block');
$ok(str_contains($codec, "'moderation.unblock'"), 'signed event allowlist contains moderation.unblock');
$ok(str_contains($catalog, 'applyModerationBlock'), 'catalog materializes moderation blocks');
$ok(str_contains($storage, "hash_init('sha256')"), 'FederationDrop streams SHA-256 from S3');
$ok(str_contains($drop, 'assertAllowed($contentId)'), 'FederationDrop checks denylist before activation');
$ok(str_contains($drop, "rememberFingerprint"), 'FederationDrop records content fingerprint');
$ok(str_contains($fingerprint, "hash_init('sha256')"), 'legacy Drive files can be fingerprinted from S3 by stream');
$ok(str_contains($fingerprint, "'hash_sha256'"), 'calculated SHA-256 is persisted in FileS3 metadata');
$ok(str_contains($federationService, 'FederationContentFingerprintService'), 'new non-private ArcadeLinks backfill missing SHA-256 before publication');
$ok(str_contains($moderation, 'recoverReportContentId'), 'legacy pending reports can recover a missing content ID');
$ok(str_contains($moderation, 'decryptLocalPayload'), 'moderation resolves the signed local ArcadeLink before hashing');
$ok(str_contains($moderation, 'content.fingerprint_backfill'), 'fingerprint backfill is audited');
$ok(str_contains($moderationRepo, 'attachContentIdToReport'), 'recovered hash is attached to the pending report');
$ok(str_contains($moderationRepo, 'attachContentIdToLocalResource'), 'recovered hash is cached in the local federated resource');
$ok(str_contains($moderation, "cleanupBlockedLocalContent"), 'moderation cleanup is implemented');
$ok(str_contains($moderation, "FederationReplicaObjects"), 'cleanup includes federation replicas');
$ok(str_contains($moderation, "FileS3"), 'cleanup includes Drive catalog objects');
$ok(str_contains($sync, "moderation_cleanup"), 'sync cycle runs moderation cleanup');
$ok(str_contains($controller, "isSuperAdmin()"), 'moderation decisions require superadmin');
$ok(str_contains($controller, "federation_moderation_csrf"), 'moderation decisions require CSRF');
$ok(str_contains($moderation, "public function unblock("), 'superadmin can emit moderation unblock');
$ok(str_contains($moderation, "'moderation.unblock'"), 'unblock is propagated as signed federation event');
$ok(str_contains($moderation, '$cleanup = $this->cleanupContent($contentId);') && strpos($moderation, '$cleanup = $this->cleanupContent($contentId);') < strpos($moderation, 'decideReport($reportId, \'confirmed\''), 'cleanup occurs before report leaves pending queue');
$ok(str_contains($reportView, '../css/styles.css'), 'public report page reuses Drive styles');
$ok(str_contains($adminView, '../css/styles.css'), 'moderation admin reuses Drive styles');
$ok(str_contains($adminView, 'Revocar bloqueo'), 'moderation admin exposes unblock control');
$ok(str_contains($reconciler, 'federation_catalog_migrate.php'), 'updater reconcile installs moderation schema automatically');
$ok(str_contains($moderation, 'schemaReady()'), 'report fails clearly when moderation schema is missing');
$ok(str_contains($moderation, 'ensureModerationSchema()'), 'report self-heals missing moderation tables before registering');
$ok(str_contains($moderation, 'FederationModerationSchemaService'), 'report uses isolated moderation schema migrator');
$ok(str_contains($moderationSchema, 'CREATE TABLE IF NOT EXISTS FederationAbuseReports'), 'isolated migrator can create abuse reports table');
$ok(str_contains($moderationSchema, 'CREATE TABLE IF NOT EXISTS FederationModerationBlocks'), 'isolated migrator can create moderation block table');
$ok(str_contains($moderationSchema, 'CREATE TABLE IF NOT EXISTS FederationModerationActions'), 'isolated migrator can create moderation audit table');
$ok(str_contains($moderationSchema, 'CREATE TABLE IF NOT EXISTS FederationContentFingerprints'), 'isolated migrator can create fingerprint table');
$ok(str_contains($updaterService, 'FederationModerationSchemaService'), 'updater prepares moderation independently from full FederationCloud schema');
$ok(str_contains($reportView, 'Volver a ArcadeLink'), 'report page links back to ArcadeLink');
$ok(str_contains($reportView, 'Volver al Drive'), 'report page links back to Drive');
$ok(str_contains($footer, "footerFederationModeration"), 'superadmin footer exposes pending moderation count');
$ok(str_contains($portal, "Reportar abuso"), 'federation catalog exposes abuse reporting');
$ok(str_contains($dropJs, "report.php?type=drop"), 'FederationDrop exposes abuse reporting');
$ok(str_contains($uploadRepo, 'isContentBlocked'), 'generic upload repository can query the denylist');
$ok(str_contains($singleUpload, 'isContentBlocked($sha256)'), 'normal Drive upload rejects blocked bytes before S3 write');
$ok(str_contains($publicDropzone, 'isContentBlocked($sha256)'), 'public dropzone rejects blocked bytes before S3 write');
$ok(str_contains($uploadGuard, 'FederationModerationBlocks'), 'shared upload guard reads moderation denylist');
$ok(str_contains($uploadGuard, 'verifyObjectAllowed'), 'shared upload guard can verify completed S3 objects');
$ok(str_contains($uploadGuard, 'deleteObject'), 'shared upload guard removes blocked temporary S3 objects');
$ok(str_contains($localPresigned, 'assertSha256Allowed($claimedSha256)'), 'local presigned upload preflights browser SHA-256 when provided');
$ok(str_contains($localPresigned, 'verifyObjectAllowed($key, $realSize, true)'), 'local presigned upload authoritatively verifies S3 bytes before FileS3 registration');
$ok(strpos($localPresigned, 'verifyObjectAllowed($key, $realSize, true)') < strpos($localPresigned, '$repo->insertFile'), 'local presigned moderation verification precedes catalog insert');
$ok(str_contains($chunkedUpload, 'verifyObjectAllowed($key, $filesize, true)'), 'chunked upload verifies completed object against denylist');
$ok(strpos($chunkedUpload, 'verifyObjectAllowed($key, $filesize, true)') < strpos($chunkedUpload, '$repo->insertFile'), 'chunked moderation verification precedes catalog insert');
$ok(str_contains($remoteUrlUpload, "hash_init('sha256')"), 'remote URL upload hashes bytes while streaming');
$ok(str_contains($remoteUrlUpload, 'assertSha256Allowed($sha256)'), 'remote URL upload checks denylist before final materialization');
$ok(strpos($remoteUrlUpload, 'assertSha256Allowed($sha256)') < strpos($remoteUrlUpload, '$this->s3->putObject(['), 'small remote URL upload checks denylist before PutObject');
$ok(strpos($remoteUrlUpload, 'assertSha256Allowed($sha256)') < strpos($remoteUrlUpload, '$this->s3->completeMultipartUpload(['), 'multipart remote URL upload checks denylist before CompleteMultipartUpload');
$ok(str_contains($dropboxUpload, 'assertSha256Allowed($sha256)'), 'Dropbox upload checks denylist from local temp SHA-256');
$ok(strpos($dropboxUpload, 'assertSha256Allowed($sha256)') < strpos($dropboxUpload, '$this->s3->putObject(['), 'Dropbox moderation check precedes S3 PutObject');
$ok(str_contains($adminMultipart, 'isContentBlocked($sha256)'), 'admin multipart checks denylist after authoritative S3 hash');
$ok(strpos($adminMultipart, 'isContentBlocked($sha256)') < strpos($adminMultipart, 'upsertCompletedMultipart'), 'admin multipart moderation check precedes Drive registration');
$ok(str_contains($uploadController, 'moderation_blocked'), 'upload API exposes a controlled moderation rejection');
$ok(str_contains($localUploadJs, 'sha256FileHex'), 'local upload UI supports SHA-256 preflight');
$ok(str_contains($dropzoneUploadJs, 'sha256FileHex'), 'dropzone UI supports SHA-256 preflight');
$ok(str_contains($docs, 'sin reembolso automático'), 'policy documents non-automatic refund rule');
$ok(str_contains($docs, 'revisión'), 'policy documents human review');
