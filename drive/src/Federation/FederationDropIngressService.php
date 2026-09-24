<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use JsonException;

final class FederationDropIngressService
{
    private FederationDropConfig $dropConfig;
    private FederationConfig $federationConfig;
    private NodeIdentityService $identity;
    private FederationDropRepository $drops;
    private FederationDropIngressRepository $ingress;
    private FederationDropIngressCodec $codec;
    private FederationHttpClient $http;

    public function __construct(private DriveApplication $app)
    {
        $this->dropConfig = FederationDropConfig::fromEnvironment();
        $this->federationConfig = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->federationConfig->identityPath());
        $this->drops = new FederationDropRepository($this->app->db());
        $this->ingress = new FederationDropIngressRepository($this->app->db());
        $this->codec = new FederationDropIngressCodec();
        $this->http = new FederationHttpClient();
    }

    public function candidates(string $dropId, string $ownerToken): array
    {
        $this->dropConfig->assertReady();
        $row = $this->requirePaidUpload($dropId, $ownerToken);
        $providers = $this->ingress->eligibleProviders(
            $this->identity->nodeId(),
            (int)$row['ExpectedSizeBytes'],
            8
        );

        $candidates = [];
        foreach ($providers as $provider) {
            $federationUrl = rtrim((string)$provider['federation_url'], '/') . '/';
            $candidates[] = [
                'node_id' => (string)$provider['node_id'],
                'domain' => (string)$provider['domain'],
                'region' => $provider['region'],
                'commission_bps' => (int)$provider['commission_bps'],
                'probe_url' => $federationUrl . 'drop-ingress.php?action=probe',
            ];
        }

        return [
            'ok' => true,
            'drop_id' => (string)$row['DropId'],
            'candidates' => $candidates,
            'fallback' => 'central',
        ];
    }

    public function authorizeCentral(string $dropId, string $ownerToken, string $targetNodeId): array
    {
        $this->dropConfig->assertReady();
        $row = $this->requirePaidUpload($dropId, $ownerToken);
        $targetNodeId = trim($targetNodeId);
        $eligible = null;
        foreach ($this->ingress->eligibleProviders(
            $this->identity->nodeId(),
            (int)$row['ExpectedSizeBytes'],
            20
        ) as $provider) {
            if (hash_equals((string)$provider['node_id'], $targetNodeId)) {
                $eligible = $provider;
                break;
            }
        }
        if ($eligible === null) {
            throw new FederationException('El nodo elegido ya no es un ingress comercial elegible.', 409);
        }

        $ingressId = 'fdi_' . FederationCodec::base64UrlEncode(random_bytes(18));
        $grant = $this->codec->createGrant(
            $this->identity,
            $this->federationConfig->federationUrl(),
            $targetNodeId,
            (string)$row['DropId'],
            $ingressId,
            (string)$row['OriginalName'],
            (string)$row['MimeType'],
            (int)$row['ExpectedSizeBytes']
        );
        $grantJson = FederationCodec::canonicalJson($grant);
        $federationUrl = rtrim((string)$eligible['federation_url'], '/') . '/';

        $this->ingress->storeAuthorization([
            'ingress_id' => $ingressId,
            'drop_id' => (string)$row['DropId'],
            'commerce_node_id' => $this->identity->nodeId(),
            'ingress_node_id' => $targetNodeId,
            'ingress_federation_url' => $federationUrl,
            's3_key' => null,
            'expected_size_bytes' => (int)$row['ExpectedSizeBytes'],
            'mime_type' => (string)$row['MimeType'],
            'grant_json' => $grantJson,
        ]);

        return [
            'ok' => true,
            'mode' => 'remote_ingress',
            'ingress_id' => $ingressId,
            'node_id' => $targetNodeId,
            'endpoint' => $federationUrl . 'drop-ingress.php',
            'grant' => $grant,
        ];
    }

    public function registerUploaded(string $dropId, string $ownerToken, string $ingressId): array
    {
        $row = $this->requirePaidUpload($dropId, $ownerToken);
        $ingress = $this->ingress->find(trim($ingressId));
        if ($ingress === null
            || !hash_equals((string)$row['DropId'], (string)$ingress['DropId'])
            || !hash_equals($this->identity->nodeId(), (string)$ingress['CommerceNodeId'])) {
            throw new FederationException('Ingress FederationDrop no pertenece a esta orden.', 404);
        }
        $this->ingress->markRemoteUploaded((string)$ingress['IngressId']);
        return [
            'ok' => true,
            'drop_id' => (string)$row['DropId'],
            'ingress_id' => (string)$ingress['IngressId'],
            'status' => 'migrating_to_central',
        ];
    }

    public function remoteProbe(): array
    {
        if (!$this->federationConfig->enabled()) {
            throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
        }
        return [
            'ok' => true,
            'node_id' => $this->identity->nodeId(),
            'role' => 'temporary_ingress',
        ];
    }

    public function remoteAuthorize(array $grant): array
    {
        $grant = $this->verifiedRemoteGrant($grant);
        $key = $this->ingressKey($grant);

        $this->ingress->storeAuthorization([
            'ingress_id' => (string)$grant['ingress_id'],
            'drop_id' => (string)$grant['drop_id'],
            'commerce_node_id' => (string)$grant['commerce_node_id'],
            'ingress_node_id' => $this->identity->nodeId(),
            'ingress_federation_url' => $this->federationConfig->federationUrl(),
            's3_key' => $key,
            'expected_size_bytes' => (int)$grant['expected_size_bytes'],
            'mime_type' => (string)$grant['mime_type'],
            'grant_json' => FederationCodec::canonicalJson($grant),
        ]);

        $command = $this->app->s3()->getCommand('PutObject', [
            'Bucket' => $this->app->bucket(),
            'Key' => $key,
            'ContentType' => (string)$grant['mime_type'],
        ]);
        $request = $this->app->s3()->createPresignedRequest($command, '+30 minutes');

        return [
            'ok' => true,
            'ingress_id' => (string)$grant['ingress_id'],
            'upload' => [
                'url' => (string)$request->getUri(),
                'headers' => ['Content-Type' => (string)$grant['mime_type']],
                'expires_in_seconds' => 1800,
            ],
        ];
    }

    public function remoteComplete(array $grant): array
    {
        $grant = $this->verifiedRemoteGrant($grant);
        $row = $this->requireMatchingLocalIngress($grant);

        try {
            $head = $this->app->s3()->headObject([
                'Bucket' => $this->app->bucket(),
                'Key' => (string)$row['S3Key'],
            ]);
        } catch (\Throwable) {
            throw new FederationException('El objeto ingress todavía no está disponible.', 409);
        }

        $actual = (int)($head['ContentLength'] ?? -1);
        if ($actual !== (int)$grant['expected_size_bytes']) {
            throw new FederationException('El tamaño del ingress no coincide con la orden pagada.', 409);
        }
        $etag = trim((string)($head['ETag'] ?? ''), '"');
        $this->ingress->markUploaded((string)$row['IngressId'], $etag);

        return [
            'ok' => true,
            'ingress_id' => (string)$row['IngressId'],
            'size_bytes' => $actual,
            'etag' => $etag,
            'status' => 'uploaded',
        ];
    }

    public function remoteSource(array $grant): array
    {
        $grant = $this->verifiedRemoteGrant($grant);
        $row = $this->requireMatchingLocalIngress($grant);
        if (!in_array((string)$row['Status'], ['uploaded','pulling'], true)) {
            throw new FederationException('El ingress todavía no está listo para migración.', 409);
        }

        $command = $this->app->s3()->getCommand('GetObject', [
            'Bucket' => $this->app->bucket(),
            'Key' => (string)$row['S3Key'],
        ]);
        $request = $this->app->s3()->createPresignedRequest($command, '+15 minutes');

        return [
            'ok' => true,
            'ingress_id' => (string)$row['IngressId'],
            'size_bytes' => (int)$row['ExpectedSizeBytes'],
            'source_url' => (string)$request->getUri(),
            'expires_in_seconds' => 900,
        ];
    }

    public function remoteDelete(array $grant): array
    {
        $grant = $this->verifiedRemoteGrant($grant);
        $row = $this->requireMatchingLocalIngress($grant);
        if (is_string($row['S3Key'] ?? null) && $row['S3Key'] !== '') {
            try {
                $this->app->s3()->deleteObject([
                    'Bucket' => $this->app->bucket(),
                    'Key' => (string)$row['S3Key'],
                ]);
            } catch (\Throwable $e) {
                throw new FederationException('No se pudo retirar el objeto ingress temporal.', 502);
            }
        }
        $this->ingress->markDeleted((string)$row['IngressId']);
        return ['ok' => true, 'status' => 'deleted'];
    }

    public function dueCentral(int $limit = 2): array
    {
        return $this->ingress->dueCentral($limit);
    }

    public function claimCentral(string $ingressId): bool
    {
        return $this->ingress->markPulling($ingressId);
    }

    public function sourceForCentral(array $row): array
    {
        $grant = $this->decodeGrant((string)$row['GrantJson']);
        return $this->http->postJson(
            (string)$row['IngressFederationUrl'],
            'drop-ingress.php',
            ['action' => 'source', 'grant' => $grant]
        );
    }

    public function deleteRemoteAfterCentral(array $row): void
    {
        $grant = $this->decodeGrant((string)$row['GrantJson']);
        $this->http->postJson(
            (string)$row['IngressFederationUrl'],
            'drop-ingress.php',
            ['action' => 'delete', 'grant' => $grant]
        );
    }

    public function markCentralized(string $ingressId): void
    {
        $this->ingress->markCentralized($ingressId);
    }

    public function markCentralRetry(string $ingressId, string $error): string
    {
        return $this->ingress->markRetry($ingressId, $error);
    }

    public function activeForDrop(string $dropId): ?array
    {
        return $this->ingress->activeForDrop($dropId);
    }

    private function requirePaidUpload(string $dropId, string $ownerToken): array
    {
        $row = $this->drops->find(trim($dropId));
        if ($row === null || !hash_equals((string)$row['OwnerTokenHash'], hash('sha256', trim($ownerToken)))) {
            throw new FederationException('Token de propietario FederationDrop inválido.', 404);
        }
        if ((string)($row['SourceMode'] ?? 'upload') !== 'upload') {
            throw new FederationException('Este FederationDrop no usa subida de archivo nuevo.', 409);
        }
        if ((string)$row['PaymentStatus'] !== 'paid' || (string)$row['Status'] !== 'pending_upload' || !empty($row['UploadedAt'])) {
            throw new FederationException('FederationDrop no tiene una subida pagada pendiente.', 409);
        }
        return $row;
    }

    private function verifiedRemoteGrant(array $grant): array
    {
        $commerceUrl = trim((string)($grant['commerce_federation_url'] ?? ''));
        $commerceHost = strtolower((string)(parse_url($commerceUrl, PHP_URL_HOST) ?: ''));
        $expectedHost = strtolower((string)(parse_url($this->dropConfig->commerceUrl, PHP_URL_HOST) ?: ''));
        if ($commerceHost === '' || $expectedHost === '' || !hash_equals($expectedHost, $commerceHost)) {
            throw new FederationException('El grant ingress no pertenece al portal comercial configurado.', 403);
        }

        $descriptor = (new FederationNodeDescriptorValidator())->validate(
            $this->http->getJson($commerceUrl, 'node.php')
        );
        $grant = $this->codec->verifyGrant($grant, $descriptor);
        if (!hash_equals($this->identity->nodeId(), (string)$grant['target_node_id'])) {
            throw new FederationException('El grant ingress está dirigido a otro nodo.', 409);
        }
        return $grant;
    }

    private function requireMatchingLocalIngress(array $grant): array
    {
        $row = $this->ingress->find((string)$grant['ingress_id']);
        if ($row === null
            || !hash_equals((string)$grant['drop_id'], (string)$row['DropId'])
            || !hash_equals((string)$grant['commerce_node_id'], (string)$row['CommerceNodeId'])
            || !hash_equals($this->identity->nodeId(), (string)$row['IngressNodeId'])
            || (int)$grant['expected_size_bytes'] !== (int)$row['ExpectedSizeBytes']) {
            throw new FederationException('Ingress local no coincide con el grant firmado.', 409);
        }
        return $row;
    }

    private function ingressKey(array $grant): string
    {
        $name = preg_replace('/[^\pL\pN._-]+/u', '-', basename((string)$grant['filename'])) ?? 'archivo';
        $name = trim($name, '.-_');
        if ($name === '') $name = 'archivo';
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 120);
        else $name = substr($name, 0, 120);

        return 'FederationDropIngress/'
            . (string)$grant['commerce_node_id'] . '/'
            . (string)$grant['drop_id'] . '/'
            . (string)$grant['ingress_id'] . '/'
            . $name;
    }

    private function decodeGrant(string $json): array
    {
        try {
            $grant = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('Grant ingress almacenado inválido.', 500);
        }
        if (!is_array($grant) || array_is_list($grant)) {
            throw new FederationException('Grant ingress almacenado inválido.', 500);
        }
        return $grant;
    }
}
