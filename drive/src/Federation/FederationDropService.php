<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Mail\SmtpEmailService;
use JsonException;

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
            'custody' => 'local_central',
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
        string $sourceDomain = ''
    ): array {
        $this->config->assertReady();
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
            'owner_email' => $email,
            'owner_token_hash' => hash('sha256', $ownerToken),
            'owner_token_ciphertext' => $this->encryptToken($ownerToken),
            'public_token_hash' => hash('sha256', $publicToken),
            'public_token_ciphertext' => $this->encryptToken($publicToken),
            'source_domain' => $sourceDomain,
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

        $checkoutUrl = $this->checkoutUrl($dropId, (int)$quote['amount_cents'], $this->config->currency);
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

    public function authorizeUpload(string $dropId, string $ownerToken): array
    {
        $row = $this->requireOwner($dropId, $ownerToken);
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
        if ((string)$row['PaymentStatus'] !== 'paid' || (string)$row['Status'] !== 'pending_upload') {
            throw new FederationException('Este FederationDrop no tiene una subida pagada pendiente.', 409);
        }
        $verified = $this->storage->verifyUploaded(
            (string)$row['S3Key'],
            (int)$row['ExpectedSizeBytes']
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
        return ['ok' => true, 'status' => 'deleted'];
    }

    public function downloadUrl(string $dropId, string $publicToken): string
    {
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
        $this->config->assertReady();
        $signature = strtolower(trim($signature));
        $expected = hash_hmac('sha256', $rawBody, $this->config->webhookSecret);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            throw new FederationException('Firma de webhook FederationDrop inválida.', 401);
        }

        try {
            $event = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('Webhook FederationDrop no contiene JSON válido.', 400);
        }
        if (!is_array($event) || array_is_list($event)) {
            throw new FederationException('Webhook FederationDrop inválido.', 400);
        }

        $eventId = $this->safeIdentifier((string)($event['event_id'] ?? ''), 96);
        $dropId = $this->safeIdentifier((string)($event['drop_id'] ?? ''), 64);
        $provider = $this->safeIdentifier((string)($event['provider'] ?? 'external'), 64);
        $reference = $this->safeIdentifier((string)($event['reference'] ?? ''), 191);
        $status = strtolower($this->safeIdentifier((string)($event['status'] ?? ''), 16));
        $amount = (int)($event['amount_cents'] ?? -1);
        $currency = strtoupper(trim((string)($event['currency'] ?? '')));

        if ($eventId === '' || $dropId === '' || $reference === '' || !in_array($status, ['paid','failed','refunded'], true)) {
            throw new FederationException('Campos de webhook FederationDrop incompletos.', 400);
        }

        $row = $this->repository->find($dropId);
        if ($row === null) throw new FederationException('FederationDrop de pago no encontrado.', 404);
        if ($amount !== (int)$row['AmountCents'] || !hash_equals((string)$row['Currency'], $currency)) {
            throw new FederationException('El importe del pago no coincide con la orden FederationDrop.', 409);
        }

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
                } catch (\Throwable $e) {
                    error_log('[FederationDrop refund cleanup] ' . $e->getMessage());
                }
            }
            return [
                'ok' => true,
                'accepted' => true,
                'duplicate' => !$inserted,
                'status' => (string)$updated['Status'],
                'payment_status' => (string)$updated['PaymentStatus'],
            ];
        }

        $updated = $this->repository->markPaid($dropId, $provider, $reference);
        if ($inserted && (string)$updated['Status'] === 'active') {
            $this->repository->createCentralPlacement($dropId, (string)$updated['CustodyNodeId']);
            $this->sendActivationEmail($updated);
        }

        return [
            'ok' => true,
            'accepted' => true,
            'duplicate' => !$inserted,
            'status' => (string)$updated['Status'],
            'payment_status' => (string)$updated['PaymentStatus'],
        ];
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
            'can_upload' => (string)$row['PaymentStatus'] === 'paid' && (string)$row['Status'] === 'pending_upload',
            'expires_at' => $row['ExpiresAt'],
            'manage_url' => $this->config->publicUrl . '/?manage=' . rawurlencode($dropId)
                . '&owner_token=' . rawurlencode($ownerToken),
            'share_url' => $this->publicDownloadUrl($dropId, $publicToken),
            'arcadelink_url' => $this->config->publicUrl . '/arcadelink.php?id=' . rawurlencode($dropId)
                . '&t=' . rawurlencode($publicToken),
        ];
    }

    private function checkoutUrl(string $dropId, int $amountCents, string $currency): string
    {
        $returnUrl = $this->config->publicUrl . '/?payment_return=' . rawurlencode($dropId);
        $webhookUrl = $this->config->publicUrl . '/api.php?action=payment-webhook';
        $canonical = implode('|', [$dropId, (string)$amountCents, $currency, $returnUrl, $webhookUrl]);
        $signature = hash_hmac('sha256', $canonical, $this->config->webhookSecret);
        $params = http_build_query([
            'drop_id' => $dropId,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'return_url' => $returnUrl,
            'webhook_url' => $webhookUrl,
            'signature' => $signature,
        ], '', '&', PHP_QUERY_RFC3986);
        return $this->config->checkoutUrl . (str_contains($this->config->checkoutUrl, '?') ? '&' : '?') . $params;
    }

    private function publicDownloadUrl(string $dropId, string $publicToken): string
    {
        return $this->config->publicUrl . '/d.php?id=' . rawurlencode($dropId)
            . '&t=' . rawurlencode($publicToken);
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
        $key = hash('sha256', 'federationdrop-token|' . $this->config->webhookSecret, true);
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
        $key = hash('sha256', 'federationdrop-token|' . $this->config->webhookSecret, true);
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
