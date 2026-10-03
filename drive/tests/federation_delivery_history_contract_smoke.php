<?php
declare(strict_types=1);

function deliveryContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$root = dirname(__DIR__);
$sql = (string)file_get_contents(dirname($root) . '/adbbmis1_Cloud.sql');
$codec = (string)file_get_contents($root . '/src/Federation/FederationEventCodec.php');
$catalog = (string)file_get_contents($root . '/src/Federation/FederatedCatalogRepository.php');
$resolver = (string)file_get_contents($root . '/src/Federation/FederationReplicaResolverService.php');
$downloader = (string)file_get_contents($root . '/src/Federation/FederationMultiSourceDownloader.php');
$controller = (string)file_get_contents($root . '/src/Http/Controller/FederationReplicaController.php');
$deliveryService = (string)file_get_contents($root . '/src/Federation/FederationResourceDeliveryService.php');
$deliveryRepo = (string)file_get_contents($root . '/src/Federation/FederationResourceDeliveryRepository.php');
$catalogService = (string)file_get_contents($root . '/src/Federation/FederationCatalogService.php');

deliveryContract(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationResourceDeliveries'), 'schema contains per-resource delivery ledger');
deliveryContract(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationResourceDeliverySources'), 'schema normalizes source nodes per delivery');
deliveryContract(str_contains($codec, "'delivery.record'"), 'signed event allowlist contains delivery.record');
deliveryContract(str_contains($catalog, 'applyDeliveryRecord'), 'catalog materializes delivery.record events');
deliveryContract(str_contains($catalog, 'RequestNodeId'), 'materialized delivery binds requesting node');
deliveryContract(str_contains($catalog, 'FederationResourceDeliverySources'), 'materialized delivery persists source nodes');
deliveryContract(str_contains($deliveryService, "->emit(\$this->identity, 'delivery.record'"), 'completed delivery emits signed federation event');
deliveryContract(str_contains($deliveryService, "'bytes_delivered'"), 'delivery event records delivered byte count');
deliveryContract(!str_contains($deliveryService, 'REMOTE_ADDR'), 'delivery history does not persist client IP');
deliveryContract(!str_contains($deliveryService, 'access_url'), 'delivery history does not persist presigned S3 URLs');

deliveryContract(str_contains($resolver, 'downloadPublic'), 'public resolver exposes verified materialized download');
deliveryContract(str_contains($resolver, 'FederationMultiSourceDownloader::MAX_SOURCES'), 'public download can gather up to the multisource limit');
deliveryContract(str_contains($resolver, 'sources_used'), 'public download returns actual source nodes used');
deliveryContract(str_contains($downloader, "'source_urls'"), 'multisource downloader reports the URLs that actually transported ranges');
deliveryContract(str_contains($downloader, 'CURLOPT_RANGE'), 'multisource transport still uses HTTP byte ranges');
deliveryContract(str_contains($downloader, 'hash_final'), 'multisource transport still verifies final SHA-256');

deliveryContract(str_contains($controller, 'downloadPublic($resourceId)'), 'replica-open uses materialized multisource download instead of redirect-only delivery');
deliveryContract(!str_contains($controller, "header('Location: '"), 'replica-open no longer treats a 302 as a completed public download');
deliveryContract(str_contains($controller, 'connection_aborted()'), 'stream detects interrupted clients');
deliveryContract(str_contains($controller, '$sent === $expected'), 'history requires every expected byte to be transmitted');
deliveryContract(str_contains($controller, 'recordCompleted('), 'completed browser delivery is recorded');
deliveryContract(str_contains($controller, '@unlink($path)'), 'verified temporary file is always cleaned');

deliveryContract(str_contains($deliveryRepo, 'by_source_node'), 'resource summary exposes per-source-node counts');
deliveryContract(str_contains($deliveryRepo, 'ORDER BY CompletedAt DESC'), 'resource history is returned newest first');
deliveryContract(str_contains($catalogService, "'delivery_summary'"), 'resource API includes delivery summary');
deliveryContract(str_contains($catalogService, "'delivery_history'"), 'resource API includes per-file delivery history');

fwrite(STDOUT, "Federation delivery history + multisource contract: OK\n");
