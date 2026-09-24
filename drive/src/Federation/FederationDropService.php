<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Mail\SmtpEmailService;

final class FederationDropService
{
    private FederationDropConfig $config;
    private FederationDropRepository $repository;
    private FederationDropStorageService $storage;
    private FederationConfig $federationConfig;
    private NodeIdentityService $identity;
    private ArcadeLinkService $arcadeLinks;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationDropConfig::fromEnvironment();
        $this->repository = new FederationDropRepository($this->app->db());
        $this->storage = new FederationDropStorageService($this->app->s3(), $this->app->bucket(), $this->config);
        $this->federationConfig = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->federationConfig->identityPath());
        $this->arcadeLinks = new ArcadeLinkService($this->federationConfig, $this->identity);
    }

    public function publicState(): array
    {
        $ready = true;
        $error = null;
        try {
            $this->config->assertReady();
        } catch (FederationException $e) {
            $ready = false;
            $error = $e->getMessage();
        }
        return [
            'enabled' => $this->config->enabled,
            'ready' => $ready,
            'error' => $error,
            'currency' => $this->config->currency,
            'max_days' => $this->config->maxDays,
            'max_downloads' => $this->config->maxDownloads,
            'max_file_bytes' => $this->config->maxFileBytes,
            'public_url' => $this->config->publicUrl,
            'commerce_url' => $this->config->commerceUrl,
            'commerce_node' => $this->config->isCommerceNode(),
            'node_id' => $this->identity->nodeId(),
            'custody' => $this->config->isCommerceNode() ? 'local_central' : 'commerce_redirect',
        ];
    }

    public function publicResourceState(string $resourceId): ?array
    {
        $resourceId = trim($resourceId);
        if ($resourceId === '') return null;
        if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            return ['error' => 'Resource ID FederationCloud inválido.'];
        }
        $resource = (new FederatedCatalogRepository($this->app->db()))->find($resourceId);
        if ($resource === null) return ['error' => 'El recurso todavía no aparece en el catálogo FederationCloud de este nodo.'];
        if ((string)$resource['Visibility'] !== 'PUBLIC' || (string)$resource['Rights'] !== 'copy_allowed') {
            return ['error' => 'El recurso no es PUBLIC + copy_allowed; requiere autorización del propietario antes de cualquier custodia comercial.'];
        }
        $contentId = strtolower(trim((string)($resource['ContentId'] ?? '')));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            return ['error' => 'El recurso público no tiene Content ID SHA-256 verificable.'];
        }
        return [
            'resource_id' => $resourceId,
            'title' => (string)$resource['Title'],
            'media_type' => (string)$resource['MediaType'],
            'size_bytes' => (int)$resource['SizeBytes'],
            'content_id' => $contentId,
            'origin_node_id' => (string)$resource['OriginNodeId'],
        ];
    }

    public function quote(int $sizeBytes, int $days, int $downloads): array
    {
        $this->config->assertReady();
        $this->validatePlan($sizeBytes, $days, $downloads);
        $gib = max(1, intdiv($sizeBytes + 1073741823, 1073741824));
        $storage = $gib * $days * $this->config->storageGbDayCents;
        $egress = $gib * $downloads * $this->config->egressGbCents;
        $total = $this->config->baseFeeCents + $storage + $egress;
        if ($total <= 0) throw new FederationException('La tarifa FederationDrop no produce un importe válido.', 503);

        return [
            'currency' => $this->config->currency,
            'amount_cents' => $total,
            'base_fee_cents' => $this->config->baseFeeCents,
            'storage_cents' => $storage,
            'egress_cents' => $egress,
            'billable_gib' => $gib,
            'days' => $days,
            'downloads' => $downloads,
        ];
    }

    public function createOrder(
        string $email,
        string $filename,
        int $sizeBytes,
        string $mimeType,
        int $days,
        int $downloads,
        string $sourceDomain = '',
        ?string $ownerAccountId = null
    ): array {
        $this->config->assertReady();
        $ownerAccountId = $this->normalizeOwnerAccountId($ownerAccountId);
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320) {
            throw new FederationException('Escribe un correo válido para administrar el FederationDrop.', 400);
        }
        $filename = $this->safeFilename($filename);
        $mimeType = $this->safeMime($mimeType);
        $sourceDomain = $this->normalizeSourceDomain($sourceDomain);
        $quote = $this->quote($sizeBytes, $days, $downloads);

        $dropId = 'fdp_' . FederationCodec::base64UrlEncode(random_bytes(18));
        $ownerToken = FederationCodec::base64UrlEncode(random_bytes(32));
        $publicToken = FederationCodec::base64UrlEncode(random_bytes(32));
        $key = $this->storage->objectKey($dropId, $filename);
        $custodyNodeId = $this->identity->nodeId();

        $this->repository->create([
            'drop_id' => $dropId,
            'owner_account_id' => $ownerAccountId,
            'owner_email' => $email,
            'owner_token_hash' => hash('sha256', $ownerToken),
            'owner_token_ciphertext' => $this->encryptToken($ownerToken),
            'public_token_hash' => hash('sha256', $publicToken),
            'public_token_ciphertext' => $this->encryptToken($publicToken),
            'source_domain' => $sourceDomain,
            'source_mode' => 'upload',
            'source_resource_id' => null,
            'source_content_id' => null,
            'original_name' => $filename,
            's3_key' => $key,
            'mime_type' => $mimeType,
            'expected_size_bytes' => $sizeBytes,
            'retention_days' => $days,
            'max_downloads' => $downloads,
            'amount_cents' => (int)$quote['amount_cents'],
            'currency' => $this->config->currency,
            'custody_node_id' => $custodyNodeId,
        ]);

        $createdRow = $this->repository->find($dropId);
        if ($createdRow === null) {
            throw new FederationException('No se pudo recuperar la orden FederationDrop para Stripe.', 500);
        }
        $checkout = $this->stripe()->createCheckout($createdRow);
        $checkoutUrl = (string)$checkout['url'];
        $this->repository->setCheckoutUrl($dropId, $checkoutUrl);

        return [
            'ok' => true,
            'drop_id' => $dropId,
            'owner_token' => $ownerToken,
            'quote' => $quote,
            'checkout_url' => $checkoutUrl,
            'status_url' => $this->config->publicUrl . '/?manage=' . rawurlencode($dropId),
            'pending_expires_hours' => $this->config->pendingHours,
            'custody_node_id' => $custodyNodeId,
        ];
    }

    public function createPublicResourceOrder(
        string $email,
        string $resourceId,
        int $days,
        int $downloads,
        string $sourceDomain = '',
        ?string $ownerAccountId = null
    ): array {
        $this->config->assertReady();
        $ownerAccountId = $this->normalizeOwnerAccountId($ownerAccountId);
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320) {
            throw new FederationException('Escribe un correo válido para administrar el FederationDrop.', 400);
        }
        $resourceId = trim($resourceId);
        if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            throw new FederationException('Resource ID FederationCloud inválido.', 400);
        }

        $resource = (new FederatedCatalogRepository($this->app->db()))->find($resourceId);
        if ($resource === null) throw new FederationException('El recurso público todavía no aparece en el catálogo de este nodo.', 404);
        if ((string)$resource['Visibility'] !== 'PUBLIC' || (string)$resource['Rights'] !== 'copy_allowed') {
            throw new FederationException('Sólo PUBLIC + copy_allowed puede convertirse en FederationDrop sin autorización privada.', 403);
        }
        $contentId = strtolower(trim((string)($resource['ContentId'] ?? '')));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            throw new FederationException('El recurso público requiere Content ID SHA-256 para custodia comercial.', 409);
        }

        $sizeBytes = (int)$resource['SizeBytes'];
        $this->validatePlan($sizeBytes, $days, $downloads);
        $filename = $this->safeFilename((string)$resource['Title']);
        $mimeType = $this->safeMime((string)$resource['MediaType']);
        $sourceDomain = $this->normalizeSourceDomain($sourceDomain);
        $quote = $this->quote($sizeBytes, $days, $downloads);

        $dropId = 'fdp_' . FederationCodec::base64UrlEncode(random_bytes(18));
        $ownerToken = FederationCodec::base64UrlEncode(random_bytes(32));
        $publicToken = FederationCodec::base64UrlEncode(random_bytes(32));
        $key = $this->storage->objectKey($dropId, $filename);
        $custodyNodeId = $this->identity->nodeId();

        $this->repository->create([
            'drop_id' => $dropId,
            'owner_account_id' => $ownerAccountId,
            'owner_email' => $email,
            'owner_token_hash' => hash('sha256', $ownerToken),
            'owner_token_ciphertext' => $this->encryptToken($ownerToken),
            'public_token_hash' => hash('sha256', $publicToken),
            'public_token_ciphertext' => $this->encryptToken($publicToken),
            'source_domain' => $sourceDomain,
            'source_mode' => 'public_resource',
            'source_resource_id' => $resourceId,
            'source_content_id' => $contentId,
            'original_name' => $filename,
            's3_key' => $key,
            'mime_type' => $mimeType,
            'expected_size_bytes' => $sizeBytes,
            'retention_days' => $days,
            'max_downloads' => $downloads,
            'amount_cents' => (int)$quote['amount_cents'],
            'currency' => $this->config->currency,
            'custody_node_id' => $custodyNodeId,
        ]);

        $createdRow = $this->repository->find($dropId);
        if ($createdRow === null) throw new FederationException('No se pudo recuperar la orden pública FederationDrop.', 500);
        $checkout = $this->stripe()->createCheckout($createdRow);
        $checkoutUrl = (string)$checkout['url'];
        $this->repository->setCheckoutUrl($dropId, $checkoutUrl);

        return [
            'ok' => true,
            'drop_id' => $dropId,
            'owner_token' => $ownerToken,
            'source_mode' => 'public_resource',
            'resource_id' => $resourceId,
            'quote' => $quote,
            'checkout_url' => $checkoutUrl,
            'status_url' => $this->config->publicUrl . '/?manage=' . rawurlencode($dropId),
            'pending_expires_hours' => $this->config->pendingHours,
            'custody_node_id' => $custodyNodeId,
        ];
    }

    public function ingressCandidates(string $dropId, string $ownerToken): array
    {
        return (new FederationDropIngressService($this->app))->candidates($dropId, $ownerToken);
    }

    public function authorizeIngress(string $dropId, string $ownerToken, string $ingressNodeId): array
    {
        return (new FederationDropIngressService($this->app))->authorizeCentral(
            $dropId,
            $ownerToken,
            $ingressNodeId
        );
    }

    public function registerIngressUploaded(string $dropId, string $ownerToken, string $ingressId): array
    {
        $result = (new FederationDropIngressService($this->app))->registerUploaded(
            $dropId,
            $ownerToken,
            $ingressId
        );
        return $result + ['owner' => $this->ownerStatus($dropId, $ownerToken)];
    }

    public function authorizeUpload(string $dropId, string $ownerToken): array
    {
        $row = $this->requireOwner($dropId, $ownerToken);
        if ((string)($row['SourceMode'] ?? 'upload') !== 'upload') {
            throw new FederationException('Este FederationDrop se materializa directamente desde FederationCloud; no requiere volver a seleccionar el archivo.', 409);
        }
        if ((string)$row['PaymentStatus'] !== 'paid' || (string)$row['Status'] !== 'pending_upload') {
            throw new FederationException('La subida FederationDrop se habilita únicamente después del pago confirmado.', 409);
        }
        if (!empty($row['UploadedAt'])) {
            throw new FederationException('Este FederationDrop ya tiene un objeto subido.', 409);
        }

        return [
            'ok' => true,
            'drop_id' => $dropId,
            'upload' => $this->storage->presignedUpload(
                (string)$row['S3Key'],
                (string)$row['MimeType'],
                (int)$row['ExpectedSizeBytes']
            ),
        ];
    }

    public function completeUpload(string $dropId, string $ownerToken): array
    {
        $row = $this->requireOwner($dropId, $ownerToken);
        if ((string)($row['SourceMode'] ?? 'upload') !== 'upload') {
            throw new FederationException('Este FederationDrop se materializa desde FederationCloud y no acepta upload-complete.', 409);
        }
        if ((string)$row['PaymentStatus'] !== 'paid' || (string)$row['Status'] !== 'pending_upload') {
            throw new FederationException('Este FederationDrop no tiene una subida pagada pendiente.', 409);
        }
        $verified = $this->storage->verifyUploaded(
            (string)$row['S3Key'],
            (int)$row['ExpectedSizeBytes']
        );
        $contentId = $this->storage->contentId(
            (string)$row['S3Key'],
            (int)$verified['size_bytes']
        );
        $moderation = new FederationModerationService($this->app);
        try {
            $moderation->assertAllowed($contentId);
        } catch (FederationException $e) {
            try {
                $this->storage->delete((string)$row['S3Key']);
                $this->repository->markDeleted($dropId);
                $this->repository->setPlacementStatus($dropId, 'deleted');
            } catch (\Throwable $cleanupError) {
                error_log('[FederationDrop blocked upload cleanup] ' . $cleanupError->getMessage());
            }
            throw $e;
        }
        $moderation->rememberFingerprint(
            $contentId,
            'drop',
            $dropId,
            (string)$row['S3Key'],
            (string)$row['CustodyNodeId']
        );
        $updated = $this->repository->markUploaded(
            $dropId,
            (int)$verified['size_bytes'],
            (string)$verified['etag'],
            (string)$verified['mime_type']
        );
        if ((string)$updated['Status'] === 'active') {
            $this->repository->createCentralPlacement($dropId, (string)$updated['CustodyNodeId']);
            $this->sendActivationEmail($updated);
        }
        return $this->ownerView($updated, $ownerToken);
    }

    public function ownerStatus(string $dropId, string $ownerToken): array
    {
        return $this->ownerView($this->requireOwner($dropId, $ownerToken), $ownerToken);
    }

    public function deleteOwned(string $dropId, string $ownerToken): array
    {
        $row = $this->requireOwner($dropId, $ownerToken);
        if ((string)$row['Status'] === 'deleted') {
            return ['ok' => true, 'status' => 'deleted'];
        }
        if ((string)$row['S3Key'] !== '') {
            try {
                $this->storage->delete((string)$row['S3Key']);
            } catch (\Throwable $e) {
                error_log('[FederationDrop delete] ' . $e->getMessage());
            }
        }
        $this->repository->markDeleted($dropId);
        $this->repository->setPlacementStatus($dropId, 'deleted');
        return ['ok' => true, 'status' => 'deleted'];
    }

    public function downloadUrl(string $dropId, string $publicToken): string
    {
        $moderation = new FederationModerationService($this->app);
        $contentId = (new FederationModerationRepository($this->app->db()))->contentIdForDrop($dropId);
        if ($contentId !== null) $moderation->assertAllowed($contentId);
        $row = $this->repository->claimDownload($dropId, hash('sha256', trim($publicToken)));
        return $this->storage->presignedDownload((string)$row['S3Key'], (string)$row['OriginalName']);
    }

    public function arcadeLink(string $dropId, string $publicToken): array
    {
        $row = $this->repository->find($dropId);
        if ($row === null || !hash_equals((string)$row['PublicTokenHash'], hash('sha256', trim($publicToken)))) {
            throw new FederationException('FederationDrop no encontrado.', 404);
        }
        if ((string)$row['PaymentStatus'] !== 'paid' || (string)$row['Status'] !== 'active') {
            throw new FederationException('FederationDrop no disponible para ArcadeLink.', 410);
        }
        $expires = (string)($row['ExpiresAt'] ?? '');
        if ($expires === '' || strtotime($expires . ' UTC') <= time()) {
            throw new FederationException('FederationDrop vencido.', 410);
        }

        $downloadUrl = $this->publicDownloadUrl($dropId, $publicToken);
        $document = $this->arcadeLinks->createDrop([
            'drop_id' => $dropId,
            'title' => (string)$row['OriginalName'],
            'size_bytes' => (int)$row['ActualSizeBytes'],
            'media_type' => (string)$row['MimeType'],
            'download_url' => $downloadUrl,
            'expires_at' => gmdate(DATE_ATOM, (int)strtotime($expires . ' UTC')),
        ]);
        return [
            'document' => $document,
            'content' => $this->arcadeLinks->encode($document),
            'filename' => $this->arcadeLinks->suggestedFilename($document),
        ];
    }

    public function receivePaymentWebhook(string $rawBody, string $signature): array
    {
        $payment = $this->stripe()->handleWebhook($rawBody, $signature);
        if (empty($payment['processed'])) {
            return ['ok' => true] + $payment;
        }

        $eventId = (string)$payment['event_id'];
        $dropId = (string)$payment['drop_id'];
        $provider = (string)$payment['provider'];
        $reference = (string)$payment['reference'];
        $status = (string)$payment['status'];
        $amount = (int)$payment['amount_cents'];
        $currency = (string)$payment['currency'];

        $inserted = $this->repository->recordPaymentEvent(
            $eventId,
            $dropId,
            $provider,
            $reference,
            $amount,
            $currency,
            $status,
            hash('sha256', $rawBody)
        );

        if ($status !== 'paid') {
            $updated = $this->repository->markPaymentState($dropId, $status, $provider, $reference);
            if ($status === 'refunded' && $inserted) {
                try {
                    if (!empty($updated['UploadedAt'])) $this->storage->delete((string)$updated['S3Key']);
                    $this->repository->setPlacementStatus($dropId, 'revoked');
                } catch (\Throwable $e) {
                    error_log('[FederationDrop Stripe refund cleanup] ' . $e->getMessage());
                }
            }
            return [
                'ok' => true,
                'accepted' => true,
                'processed' => true,
                'duplicate' => !$inserted,
                'event_id' => $eventId,
                'event_type' => (string)($payment['event_type'] ?? ''),
                'status' => (string)$updated['Status'],
                'payment_status' => (string)$updated['PaymentStatus'],
            ];
        }

        $updated = $this->repository->markPaid($dropId, $provider, $reference);
        if ($inserted && empty($payment['was_paid'])) {
            $this->sendPaymentReadyEmail($updated);
            if ((string)$updated['Status'] === 'active') {
                $this->repository->createCentralPlacement($dropId, (string)$updated['CustodyNodeId']);
                $this->sendActivationEmail($updated);
            }
        }

        return [
            'ok' => true,
            'accepted' => true,
            'processed' => true,
            'duplicate' => !$inserted,
            'event_id' => $eventId,
            'event_type' => (string)($payment['event_type'] ?? ''),
            'status' => (string)$updated['Status'],
            'payment_status' => (string)$updated['PaymentStatus'],
        ];
    }

    public function syncPaidPublicSources(int $limit = 2): array
    {
        if (!$this->config->enabled || !$this->config->isCommerceNode()) {
            return ['processed' => 0, 'completed' => 0, 'errors' => 0];
        }

        $processed = $completed = $errors = 0;
        $catalog = new FederatedCatalogRepository($this->app->db());
        $resolver = new FederationReplicaResolverService($this->app);

        foreach ($this->repository->paidPublicSourceCandidates($limit) as $row) {
            $processed++;
            $tmp = '';
            try {
                $resourceId = (string)($row['SourceResourceId'] ?? '');
                $resource = $catalog->find($resourceId);
                if ($resource === null
                    || (string)$resource['Visibility'] !== 'PUBLIC'
                    || (string)$resource['Rights'] !== 'copy_allowed') {
                    throw new FederationException('La fuente FederationCloud dejó de estar disponible como recurso público.', 409);
                }
                if ((int)$resource['SizeBytes'] !== (int)$row['ExpectedSizeBytes']
                    || !hash_equals(
                        strtolower((string)($row['SourceContentId'] ?? '')),
                        strtolower((string)($resource['ContentId'] ?? ''))
                    )) {
                    throw new FederationException('La fuente FederationCloud cambió después del pago; no se activará un contenido distinto.', 409);
                }

                $resolved = $resolver->publicSources($resourceId, FederationMultiSourceDownloader::MAX_SOURCES);
                $urls = [];
                foreach ((array)($resolved['sources'] ?? []) as $source) {
                    if (is_array($source) && is_string($source['url'] ?? null) && $source['url'] !== '') {
                        $urls[] = (string)$source['url'];
                    }
                }
                $moderation = new FederationModerationService($this->app);
                $moderation->assertAllowed((string)$row['SourceContentId']);
                $download = (new FederationMultiSourceDownloader())->download(
                    $urls,
                    (int)$row['ExpectedSizeBytes'],
                    (string)$row['SourceContentId']
                );
                $tmp = (string)$download['path'];
                $stored = $this->storage->storeFromLocalFile(
                    (string)$row['S3Key'],
                    $tmp,
                    (string)$row['MimeType'],
                    (int)$row['ExpectedSizeBytes'],
                    [
                        'federation-resource-id' => $resourceId,
                        'federation-content-id' => substr((string)$row['SourceContentId'], 7),
                        'federation-sources-used' => (string)max(1, (int)($download['sources_used'] ?? 1)),
                    ]
                );
                $moderation->rememberFingerprint(
                    (string)$row['SourceContentId'],
                    'drop',
                    (string)$row['DropId'],
                    (string)$row['S3Key'],
                    (string)$row['CustodyNodeId']
                );
                $updated = $this->repository->markUploaded(
                    (string)$row['DropId'],
                    (int)$stored['size_bytes'],
                    (string)$stored['etag'],
                    (string)$stored['mime_type']
                );
                $this->repository->createCentralPlacement((string)$row['DropId'], (string)$updated['CustodyNodeId']);
                $this->sendActivationEmail($updated);
                $completed++;
            } catch (Throwable $e) {
                $errors++;
                error_log('[FederationDrop public materialization] ' . $e->getMessage());
            } finally {
                if ($tmp !== '') @unlink($tmp);
            }
        }

        return compact('processed', 'completed', 'errors');
    }

    public function syncPaidIngressSources(int $limit = 2): array
    {
        if (!$this->config->enabled || !$this->config->isCommerceNode()) {
            return ['processed' => 0, 'completed' => 0, 'retry' => 0, 'failed' => 0];
        }

        $ingress = new FederationDropIngressService($this->app);
        $processed = $completed = $retry = $failed = 0;

        foreach ($ingress->dueCentral($limit) as $job) {
            $ingressId = (string)$job['IngressId'];
            if (!$ingress->claimCentral($ingressId)) continue;
            $processed++;
            $tmp = '';
            try {
                $row = $this->repository->find((string)$job['DropId']);
                if ($row === null
                    || (string)$row['PaymentStatus'] !== 'paid'
                    || (string)$row['Status'] !== 'pending_upload'
                    || (string)($row['SourceMode'] ?? 'upload') !== 'upload') {
                    throw new FederationException('La orden pagada ya no admite migración ingress.', 409);
                }

                $source = $ingress->sourceForCentral($job);
                $url = trim((string)($source['source_url'] ?? ''));
                if ($url === '' || (int)($source['size_bytes'] ?? -1) !== (int)$row['ExpectedSizeBytes']) {
                    throw new FederationException('El nodo ingress no devolvió una fuente válida.', 502);
                }

                $download = (new FederationDropIngressDownloader())->download(
                    $url,
                    (int)$row['ExpectedSizeBytes']
                );
                $tmp = (string)$download['path'];

                $moderation = new FederationModerationService($this->app);
                $moderation->assertAllowed((string)$download['content_id']);

                $stored = $this->storage->storeFromLocalFile(
                    (string)$row['S3Key'],
                    $tmp,
                    (string)$row['MimeType'],
                    (int)$row['ExpectedSizeBytes'],
                    [
                        'federation-ingress-node' => (string)$job['IngressNodeId'],
                        'federation-content-id' => substr((string)$download['content_id'], 7),
                    ]
                );
                $this->repository->setSourceContentId((string)$row['DropId'], (string)$download['content_id']);
                $moderation->rememberFingerprint(
                    (string)$download['content_id'],
                    'drop',
                    (string)$row['DropId'],
                    (string)$row['S3Key'],
                    (string)$row['CustodyNodeId']
                );
                $updated = $this->repository->markUploaded(
                    (string)$row['DropId'],
                    (int)$stored['size_bytes'],
                    (string)$stored['etag'],
                    (string)$stored['mime_type']
                );
                $this->repository->createCentralPlacement((string)$row['DropId'], (string)$updated['CustodyNodeId']);
                $ingress->markCentralized($ingressId);
                $this->sendActivationEmail($updated);
                $completed++;

                try {
                    $ingress->deleteRemoteAfterCentral($job);
                } catch (\Throwable $cleanupError) {
                    error_log('[FederationDrop ingress remote cleanup] ' . $cleanupError->getMessage());
                }
            } catch (\Throwable $e) {
                $state = $ingress->markCentralRetry($ingressId, $e->getMessage());
                if ($state === 'failed') $failed++;
                else $retry++;
                error_log('[FederationDrop ingress migration] ' . $e->getMessage());
            } finally {
                if ($tmp !== '') @unlink($tmp);
            }
        }

        return compact('processed', 'completed', 'retry', 'failed');
    }

    public function cleanup(int $limit = 100): array
    {
        $rows = $this->repository->cleanupCandidates($this->config->pendingHours, $limit);
        $deleted = 0;
        $errors = 0;
        foreach ($rows as $row) {
            try {
                $key = (string)($row['S3Key'] ?? '');
                if ($key !== '') $this->storage->delete($key);
                $this->repository->markExpired((string)$row['DropId']);
                $this->repository->setPlacementStatus((string)$row['DropId'], 'deleted');
                $deleted++;
            } catch (\Throwable $e) {
                $errors++;
                error_log('[FederationDrop cleanup] ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'processed' => count($rows), 'expired' => $deleted, 'errors' => $errors];
    }

    private function requireOwner(string $dropId, string $ownerToken): array
    {
        $row = $this->repository->find(trim($dropId));
        if ($row === null || !hash_equals((string)$row['OwnerTokenHash'], hash('sha256', trim($ownerToken)))) {
            throw new FederationException('Token de propietario FederationDrop inválido.', 404);
        }
        return $row;
    }

    private function ownerView(array $row, string $ownerToken): array
    {
        $publicToken = $this->decryptToken((string)($row['PublicTokenCiphertext'] ?? ''));
        $dropId = (string)$row['DropId'];
        $activeIngress = null;
        if ((string)($row['SourceMode'] ?? 'upload') === 'upload'
            && (string)$row['PaymentStatus'] === 'paid'
            && (string)$row['Status'] === 'pending_upload') {
            try {
                $activeIngress = (new FederationDropIngressService($this->app))->activeForDrop($dropId);
            } catch (\Throwable) {
                $activeIngress = null;
            }
        }
        return [
            'ok' => true,
            'drop_id' => (string)$row['DropId'],
            'filename' => (string)$row['OriginalName'],
            'size_bytes' => (int)($row['ActualSizeBytes'] ?? $row['ExpectedSizeBytes']),
            'payment_status' => (string)$row['PaymentStatus'],
            'status' => (string)$row['Status'],
            'amount_cents' => (int)$row['AmountCents'],
            'currency' => (string)$row['Currency'],
            'retention_days' => (int)$row['RetentionDays'],
            'max_downloads' => (int)$row['MaxDownloads'],
            'download_count' => (int)$row['DownloadCount'],
            'checkout_url' => (string)($row['CheckoutUrl'] ?? ''),
            'source_mode' => (string)($row['SourceMode'] ?? 'upload'),
            'source_resource_id' => is_string($row['SourceResourceId'] ?? null) ? (string)$row['SourceResourceId'] : null,
            'can_upload' => (string)($row['SourceMode'] ?? 'upload') === 'upload'
                && (string)$row['PaymentStatus'] === 'paid'
                && (string)$row['Status'] === 'pending_upload'
                && (!is_array($activeIngress) || (string)$activeIngress['Status'] === 'authorized'),
            'ingress_status' => is_array($activeIngress) ? (string)$activeIngress['Status'] : null,
            'ingress_node_id' => is_array($activeIngress) ? (string)$activeIngress['IngressNodeId'] : null,
            'materializing' => (
                    (string)($row['SourceMode'] ?? 'upload') === 'public_resource'
                    || (is_array($activeIngress) && in_array((string)$activeIngress['Status'], ['uploaded','pulling'], true))
                )
                && (string)$row['PaymentStatus'] === 'paid'
                && (string)$row['Status'] === 'pending_upload',
            'expires_at' => $row['ExpiresAt'],
            'manage_url' => $this->config->publicUrl . '/?manage=' . rawurlencode($dropId)
                . '&owner_token=' . rawurlencode($ownerToken),
            'share_url' => $this->publicDownloadUrl($dropId, $publicToken),
            'arcadelink_url' => $this->config->publicUrl . '/arcadelink.php?id=' . rawurlencode($dropId)
                . '&t=' . rawurlencode($publicToken),
        ];
    }

    private function stripe(): FederationDropStripeCheckoutService
    {
        return new FederationDropStripeCheckoutService(
            $this->config,
            $this->repository,
            new FederationDropStripeClient($this->config->stripeSecretKey),
            new FederationDropStripeWebhookVerifier($this->config->stripeWebhookSecret)
        );
    }

    private function publicDownloadUrl(string $dropId, string $publicToken): string
    {
        return $this->config->publicUrl . '/d.php?id=' . rawurlencode($dropId)
            . '&t=' . rawurlencode($publicToken);
    }

    private function sendPaymentReadyEmail(array $row): void
    {
        try {
            $ownerToken = $this->decryptToken((string)($row['OwnerTokenCiphertext'] ?? ''));
            $dropId = (string)$row['DropId'];
            $manageUrl = $this->config->publicUrl . '/?manage=' . rawurlencode($dropId)
                . '&owner_token=' . rawurlencode($ownerToken);

            SmtpEmailService::fromEnvironment()->sendFederationDropPaymentReady(
                (string)$row['OwnerEmail'],
                (string)$row['OriginalName'],
                $manageUrl,
                (int)$row['AmountCents'],
                (string)$row['Currency'],
                (string)($row['SourceMode'] ?? 'upload') === 'public_resource'
            );
        } catch (\Throwable $e) {
            error_log('[FederationDrop payment mail] ' . $e->getMessage());
        }
    }

    private function sendActivationEmail(array $row): void
    {
        try {
            $ownerToken = $this->decryptToken((string)($row['OwnerTokenCiphertext'] ?? ''));
            $publicToken = $this->decryptToken((string)($row['PublicTokenCiphertext'] ?? ''));
            $dropId = (string)$row['DropId'];
            $manageUrl = $this->config->publicUrl . '/?manage=' . rawurlencode($dropId)
                . '&owner_token=' . rawurlencode($ownerToken);
            $shareUrl = $this->publicDownloadUrl($dropId, $publicToken);
            $arcadeLinkUrl = $this->config->publicUrl . '/arcadelink.php?id=' . rawurlencode($dropId)
                . '&t=' . rawurlencode($publicToken);

            SmtpEmailService::fromEnvironment()->sendFederationDropAccess(
                (string)$row['OwnerEmail'],
                (string)$row['OriginalName'],
                $shareUrl,
                $manageUrl,
                $arcadeLinkUrl,
                (string)($row['ExpiresAt'] ?? ''),
                (int)$row['MaxDownloads']
            );
        } catch (\Throwable $e) {
            error_log('[FederationDrop mail] ' . $e->getMessage());
        }
    }

    private function encryptToken(string $token): string
    {
        $key = hash_hmac('sha256', 'federationdrop-token-v1', $this->identity->payloadKey(), true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($token, $nonce, $key);
        return FederationCodec::base64UrlEncode($nonce . $ciphertext);
    }

    private function decryptToken(string $encoded): string
    {
        $raw = FederationCodec::base64UrlDecode($encoded);
        if (strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new FederationException('Token FederationDrop cifrado inválido.', 500);
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = hash_hmac('sha256', 'federationdrop-token-v1', $this->identity->payloadKey(), true);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if (!is_string($plain) || $plain === '') {
            throw new FederationException('No se pudo recuperar un token FederationDrop.', 500);
        }
        return $plain;
    }

    private function validatePlan(int $sizeBytes, int $days, int $downloads): void
    {
        if ($sizeBytes <= 0 || $sizeBytes > $this->config->maxFileBytes) {
            throw new FederationException('El archivo excede el tamaño permitido por FederationDrop.', 413);
        }
        if ($days < 1 || $days > $this->config->maxDays) {
            throw new FederationException('La duración FederationDrop no es válida.', 400);
        }
        if ($downloads < 1 || $downloads > $this->config->maxDownloads) {
            throw new FederationException('El límite de descargas FederationDrop no es válido.', 400);
        }
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace("\0", '', trim($filename)));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
        if ($filename === '') throw new FederationException('Nombre de archivo FederationDrop inválido.', 400);
        return function_exists('mb_substr') ? mb_substr($filename, 0, 255) : substr($filename, 0, 255);
    }

    private function safeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if ($mime === '' || strlen($mime) > 128 || !preg_match('/\A[a-z0-9!#$&^_.+\/-]+\z/i', $mime)) {
            return 'application/octet-stream';
        }
        return $mime;
    }

    private function normalizeOwnerAccountId(?string $accountId): ?string
    {
        if ($accountId === null || trim($accountId) === '') return null;
        $accountId = trim($accountId);
        if (!preg_match('/\Afda_[a-f0-9]{48}\z/', $accountId)) {
            throw new FederationException('Cuenta FederationDrop inválida.', 400);
        }
        return $accountId;
    }

    private function normalizeSourceDomain(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === '') return '';
        if (str_contains($source, '://')) {
            $host = parse_url($source, PHP_URL_HOST);
            $source = is_string($host) ? strtolower($host) : '';
        }
        $source = trim($source, '.');
        if ($source === '' || strlen($source) > 255 || !preg_match('/\A[a-z0-9.-]+\z/', $source)) return '';
        return $source;
    }

    private function safeIdentifier(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x20\x7F]/', $value)) return '';
        return $value;
    }
}
