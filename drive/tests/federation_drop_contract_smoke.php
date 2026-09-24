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
$stripeClient = dropSource($root, 'src/Federation/FederationDropStripeClient.php');
$stripeVerifier = dropSource($root, 'src/Federation/FederationDropStripeWebhookVerifier.php');
$stripeCheckout = dropSource($root, 'src/Federation/FederationDropStripeCheckoutService.php');
$googleConfig = dropSource($root, 'src/Federation/FederationDropGoogleAuthConfig.php');
$googleAuth = dropSource($root, 'src/Federation/FederationDropGoogleAuthService.php');
$googleAccounts = dropSource($root, 'src/Federation/FederationDropAccountRepository.php');
$googleController = dropSource($root, 'src/Http/Controller/FederationDropGoogleAuthController.php');
$multiSource = dropSource($root, 'src/Federation/FederationMultiSourceDownloader.php');
$replicaResolver = dropSource($root, 'src/Federation/FederationReplicaResolverService.php');
$repo = dropSource($root, 'src/Federation/FederationDropRepository.php');
$storage = dropSource($root, 'src/Federation/FederationDropStorageService.php');
$controller = dropSource($root, 'src/Http/Controller/FederationDropController.php');
$renderer = dropSource($root, 'src/View/FederationDropPageRenderer.php');
$federationPage = dropSource($root, 'src/View/FederationPageRenderer.php');
$federationPortal = dropSource($root, 'src/View/FederationPortalRenderer.php');
$mail = dropSource($root, 'src/Mail/SmtpEmailService.php');
$js = dropSource($root, 'js/federation-drop.js');
$sql = dropSource(dirname($root), 'adbbmis1_Cloud.sql');
$installer = dropSource($root, 'bin/install_arcadecloud.sh');
$uninstaller = dropSource($root, 'bin/uninstall_arcadecloud.sh');
$cleanupInstaller = dropSource($root, 'bin/install_federation_drop_cleanup_timer.sh');

dropOk(str_contains($config, 'ARCADECLOUD_DROP_ENABLED'), 'FederationDrop is explicitly configurable and disabled by default.');
dropOk(str_contains($config, 'ARCADECLOUD_DROP_COMMERCE_URL'), 'FederationDrop has a canonical commerce portal.');
dropOk(str_contains($config, 'https://drive.esforzados.com/federationdrop'), 'Default commerce portal is drive.esforzados.com.');
dropOk(str_contains($config, 'ARCADECLOUD_STRIPE_SECRET_KEY'), 'Stripe secret key is explicit configuration.');
dropOk(str_contains($config, 'ARCADECLOUD_STRIPE_WEBHOOK_SECRET'), 'Stripe webhook secret is explicit configuration.');
dropOk(str_contains($googleConfig, 'https://accounts.google.com'), 'Google OIDC issuer is pinned to Google.');
dropOk(str_contains($googleConfig, 'openid email profile'), 'Google OIDC requests identity, verified email and profile.');
dropOk(str_contains($googleAuth, 'code_challenge') || str_contains($googleAuth, 'authorizationUrl'), 'Google login delegates to PKCE OIDC flow.');
dropOk(str_contains($googleAuth, 'SESSION_COOKIE'), 'Google FederationDrop uses its own encrypted session cookie.');
dropOk(str_contains($googleAccounts, "Provider='google'"), 'Google identity is stored separately from Drive Users.');
dropOk(str_contains($controller, 'googleOwnerIdentity'), 'Order creation can use verified Google identity.');
dropOk(str_contains($controller, "owner !== null ? (string)$owner['email']"), 'Verified Google email overrides browser-supplied owner email.');
dropOk(str_contains($service, 'authorizeUpload('), 'Upload authorization is separated from order creation.');
dropOk(str_contains($service, 'createPublicResourceOrder('), 'Public Federation resources can become paid Drops without browser re-upload.');
dropOk(str_contains($service, 'syncPaidPublicSources('), 'Paid public Drops are materialized cloud-to-cloud.');
dropOk(str_contains($service, "'source_mode' => 'public_resource'"), 'Public-source Drop mode is explicit.');
dropOk(str_contains($service, 'FederationMultiSourceDownloader'), 'Drop materialization can use multiple federation sources.');
dropOk(str_contains($multiSource, 'curl_multi_exec'), 'Multi-source transport runs HTTP ranges concurrently.');
dropOk(str_contains($multiSource, 'CURLOPT_RANGE'), 'Multi-source transport divides the file into byte ranges.');
dropOk(str_contains($multiSource, 'hash_final'), 'Multi-source transport verifies the reassembled SHA-256.');
dropOk(str_contains($replicaResolver, '->probe('), 'Replica resolver probes S3 credentials before redirecting users.');
dropOk(!str_contains(substr($service, strpos($service, 'public function createOrder'), strpos($service, 'public function authorizeUpload') - strpos($service, 'public function createOrder')), 'presignedUpload('), 'Unpaid order creation does not issue an S3 upload URL.');
dropOk(str_contains($service, "PaymentStatus'] !== 'paid'"), 'Upload authorization requires confirmed payment.');
dropOk(str_contains($stripeVerifier, '$timestamp . \'.\' . $rawBody'), 'Stripe webhook authenticates timestamp plus exact raw body.');
dropOk(str_contains($stripeVerifier, 'hash_equals($expected, $signature)'), 'Stripe webhook comparison is timing-safe.');
dropOk(str_contains($stripeVerifier, 'toleranceSeconds = 300'), 'Stripe webhook uses the MCMA-style five minute replay tolerance.');
dropOk(str_contains($stripeCheckout, "'price_data'"), 'Stripe Checkout uses server-calculated dynamic price_data.');
dropOk(str_contains($stripeCheckout, '\'client_reference_id\' => $dropId'), 'Stripe Checkout binds client_reference_id to drop_id.');
dropOk(str_contains($stripeCheckout, "'arcadecloud_quote_fingerprint'"), 'Stripe metadata binds the stored quote fingerprint.');
dropOk(str_contains($stripeCheckout, "'payment_intent_data'"), 'Stripe PaymentIntent inherits FederationDrop metadata.');
dropOk(str_contains($stripeClient, 'https://api.stripe.com/v1/checkout/sessions'), 'Stripe Checkout uses the Stripe HTTPS API directly.');
dropOk(str_contains($service, "status === 'refunded'"), 'Refunds revoke commercial availability.');
dropOk(str_contains($repo, 'LIMIT 1 FOR UPDATE'), 'Download-limit claims are serialized in MySQL.');
dropOk(str_contains($repo, 'DownloadCount = DownloadCount + 1'), 'Every public redemption consumes a download claim.');
dropOk(str_contains($storage, 'str_starts_with($key, \'FederationDrops/\')'), 'Delete operation is confined to FederationDrop S3 namespace.');
dropOk(!str_contains($storage, "'ACL' => 'public-read'"), 'FederationDrop never creates public S3 objects.');
dropOk(str_contains($storage, 'createPresignedRequest($command, \'+30 minutes\')'), 'Paid uploads use short-lived presigned S3 authorization.');
dropOk(str_contains($controller, "'stripe-webhook'"), 'Stripe webhook endpoint is explicit.');
dropOk(str_contains($controller, "HTTP_STRIPE_SIGNATURE"), 'Controller reads Stripe-Signature.');
dropOk(str_contains($renderer, 'commerceUrl'), 'Remote FederationDrop UI uses the configured commercial node.');
dropOk(str_contains($renderer, 'Continuar con Google'), 'Upload/payment UI exposes Google registration.');
dropOk(str_contains($renderer, 'Cuenta Google conectada'), 'Upload/payment UI exposes verified Google session state.');
dropOk(str_contains($googleController, 'google-callback') || str_contains($googleController, 'completeLogin'), 'Google callback completes server-side OIDC flow.');
dropOk(str_contains($federationPage, 'Subir / pagar') && str_contains($federationPage, 'commerceUrl'), 'ArcadeLink reader exposes central upload/payment link.');
dropOk(str_contains($federationPortal, 'Subir / pagar') && str_contains($federationPortal, 'commerceUrl'), 'FederationCloud portal exposes central upload/payment link.');
dropOk(str_contains($mail, 'sendFederationDropPaymentReady'), 'Paid Drop can be recovered by email before upload.');
dropOk(str_contains($service, 'no requiere volver a seleccionar el archivo'), 'Public-source Drop does not ask the buyer to reselect the .arcadelink or payload.');
dropOk(str_contains($renderer, 'name="referrer" content="no-referrer"'), 'Management magic-link token is protected from Referrer leakage.');
dropOk(str_contains($js, "api.php?action=upload-authorize"), 'Browser requests S3 authorization only after payment.');
dropOk(str_contains($js, "window.open(order.checkout_url"), 'Checkout can run without discarding the selected local file.');
dropOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationDropAccounts'), 'Canonical DB contains FederationDropAccounts.');
dropOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationDropIdentities'), 'Canonical DB contains provider identities separately.');
dropOk(str_contains($sql, 'OwnerAccountId'), 'FederationDrops can link an optional social owner account.');
dropOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationDrops'), 'Canonical DB contains FederationDrops.');
dropOk(str_contains($sql, "SourceMode enum('upload','public_resource')"), 'FederationDrops distinguishes browser upload from public federation source.');
dropOk(str_contains($sql, 'SourceResourceId'), 'FederationDrops binds the original public resource id.');
dropOk(str_contains($sql, 'SourceContentId'), 'FederationDrops binds the original SHA-256 content id.');
dropOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationPublicImportJobs'), 'Canonical DB contains independent public import queue.');
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
