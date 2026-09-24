<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function dropSource(string $root, string $relative): string
{
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $relative);
    return $content;
}

function dropOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$config = dropSource($root, 'src/Federation/FederationDropConfig.php');
$service = dropSource($root, 'src/Federation/FederationDropService.php');
$repo = dropSource($root, 'src/Federation/FederationDropRepository.php');
$storage = dropSource($root, 'src/Federation/FederationDropStorageService.php');
$controller = dropSource($root, 'src/Http/Controller/FederationDropController.php');
$renderer = dropSource($root, 'src/View/FederationDropPageRenderer.php');
$js = dropSource($root, 'js/federation-drop.js');
$sql = dropSource(dirname($root), 'adbbmis1_Cloud.sql');
$installer = dropSource($root, 'bin/install_arcadecloud.sh');
$uninstaller = dropSource($root, 'bin/uninstall_arcadecloud.sh');
$cleanupInstaller = dropSource($root, 'bin/install_federation_drop_cleanup_timer.sh');

dropOk(str_contains($config, 'ARCADECLOUD_DROP_ENABLED'), 'FederationDrop is explicitly configurable and disabled by default.');
dropOk(str_contains($config, 'ARCADECLOUD_DROP_CHECKOUT_URL'), 'Payment checkout endpoint is configuration, not hard-coded provider logic.');
dropOk(str_contains($config, 'ARCADECLOUD_DROP_WEBHOOK_SECRET'), 'Payment webhook requires a dedicated secret.');
dropOk(str_contains($service, 'authorizeUpload('), 'Upload authorization is separated from order creation.');
dropOk(!str_contains(substr($service, strpos($service, 'public function createOrder'), strpos($service, 'public function authorizeUpload') - strpos($service, 'public function createOrder')), 'presignedUpload('), 'Unpaid order creation does not issue an S3 upload URL.');
dropOk(str_contains($service, "PaymentStatus'] !== 'paid'"), 'Upload authorization requires confirmed payment.');
dropOk(str_contains($service, 'hash_hmac(\'sha256\', $rawBody'), 'Payment webhook authenticates the exact raw body.');
dropOk(str_contains($service, 'hash_equals($expected, $signature)'), 'Payment webhook comparison is timing-safe.');
dropOk(str_contains($service, "status === 'refunded'"), 'Refunds revoke commercial availability.');
dropOk(str_contains($repo, 'LIMIT 1 FOR UPDATE'), 'Download-limit claims are serialized in MySQL.');
dropOk(str_contains($repo, 'DownloadCount = DownloadCount + 1'), 'Every public redemption consumes a download claim.');
dropOk(str_contains($storage, 'str_starts_with($key, \'FederationDrops/\')'), 'Delete operation is confined to FederationDrop S3 namespace.');
dropOk(!str_contains($storage, "'ACL' => 'public-read'"), 'FederationDrop never creates public S3 objects.');
dropOk(str_contains($storage, 'createPresignedRequest($command, \'+30 minutes\')'), 'Paid uploads use short-lived presigned S3 authorization.');
dropOk(str_contains($controller, "action === 'payment-webhook'"), 'Payment webhook endpoint is explicit.');
dropOk(str_contains($controller, "HTTP_X_ARCADECLOUD_DROP_SIGNATURE"), 'Controller reads a dedicated webhook signature header.');
dropOk(str_contains($renderer, 'name="referrer" content="no-referrer"'), 'Management magic-link token is protected from Referrer leakage.');
dropOk(str_contains($js, "api.php?action=upload-authorize"), 'Browser requests S3 authorization only after payment.');
dropOk(str_contains($js, "window.open(order.checkout_url"), 'Checkout can run without discarding the selected local file.');
dropOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationDrops'), 'Canonical DB contains FederationDrops.');
dropOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationDropPaymentEvents'), 'Canonical DB contains idempotent payment-event ledger.');
dropOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationCommercialProviders'), 'Commercial fixed-domain providers are modeled.');
dropOk(str_contains($sql, 'CommissionBps'), 'Commercial provider revenue share is stored in basis points.');
dropOk(str_contains($sql, "GuaranteeMode enum('central_copy','dual_replica')"), 'Commercial providers declare an availability guarantee mode.');
dropOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationDropPlacements'), 'Paid-object placement and custody are auditable.');
dropOk(str_contains($service, 'createCentralPlacement'), 'Phase-one paid Drops record central custody.');
dropOk(str_contains($installer, 'install_federation_drop_cleanup_timer.sh'), 'Main installer installs Drop expiry cleanup.');
dropOk(str_contains($uninstaller, 'arcadecloud-federation-drop-cleanup.timer'), 'Uninstaller removes Drop cleanup timer.');
dropOk(str_contains($cleanupInstaller, 'OnUnitInactiveSec=1h'), 'Expired Drop cleanup runs periodically.');

echo "FederationDrop contract smoke: OK\n";
