<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use JsonException;
use Throwable;

final class FederationCustomsService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederationNodeDescriptorValidator $validator;
    private FederationNodeRepository $nodes;
    private FederationIngressQueueRepository $queue;
    private FederationHttpClient $http;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->validator = new FederationNodeDescriptorValidator();
        $this->nodes = new FederationNodeRepository($this->app->db());
        $this->queue = new FederationIngressQueueRepository($this->app->db());
        $this->http = new FederationHttpClient();
    }

    public function enqueueNodePresence(array $descriptor, ?string $requestId = null): array
    {
        $this->ensureEnabled();
        $candidate = $this->validator->validate($descriptor);
        $originNodeId = (string)$candidate['node_id'];
        $requestId = $this->requestId(
            $requestId,
            'presence|' . $originNodeId . '|' . FederationCodec::canonicalJson($candidate),
            true
        );
        $row = $this->queue->enqueue(
            $requestId,
            'node_presence',
            $originNodeId,
            $this->identity->nodeId(),
            [
                'version' => 1,
                'kind' => 'independent_node_presence',
                'descriptor' => $candidate,
                'received_via' => 'federationcloud/register.php',
            ],
            20
        );
        return [
            'ok' => true,
            'accepted' => true,
            'automatic' => true,
            'requires_superadmin' => false,
            'request_id' => $row['request_id'],
            'queue_status' => $row['status'],
            'queue_depth' => $this->queue->queuedCount(),
            'node_id' => $originNodeId,
        ];
    }

    public function enqueueSharedBackendAuthorization(
        string $originNodeId,
        array $providerDescriptor,
        string $role,
        string $scope,
        ?string $requestId = null
    ): array {
        $this->ensureEnabled();
        $candidate = $this->validator->validate($providerDescriptor);
        $role = FederationProviderGrant::normalizeRole($role);
        $scope = FederationProviderGrant::normalizeScope($scope);
        $originNodeId = trim($originNodeId);
        if (!preg_match('/\Aacn_[A-Za-z0-9_-]{20,64}\z/', $originNodeId)) {
            throw new FederationException('Node ID origen inválido.', 400);
        }
        if (!hash_equals($this->identity->nodeId(), $originNodeId)) {
            throw new FederationException('La solicitud de backend compartido no está dirigida a este nodo.', 409);
        }
        if (hash_equals($originNodeId, (string)$candidate['node_id'])) {
            throw new FederationException('Una copia FederationCloud debe usar una identidad de nodo distinta.', 409);
        }
        $requestId = $this->requestId(
            $requestId,
            'shared|' . $originNodeId . '|' . (string)$candidate['node_id'] . '|' . $role . '|' . $scope,
            false
        );
        $row = $this->queue->enqueue(
            $requestId,
            'shared_backend_authorization',
            (string)$candidate['node_id'],
            $originNodeId,
            [
                'version' => 1,
                'kind' => 'shared_backend_replica',
                'origin_node_id' => $originNodeId,
                'provider_descriptor' => $candidate,
                'role' => $role,
                'scope' => $scope,
                'requires_superadmin' => true,
            ],
            40
        );
        return [
            'ok' => true,
            'accepted' => true,
            'automatic' => false,
            'requires_superadmin' => true,
            'request_id' => $row['request_id'],
            'queue_status' => $row['status'],
            'queue_depth' => $this->queue->queuedCount(),
            'provider_node_id' => (string)$candidate['node_id'],
            'message' => 'La copia con backend compartido fue recibida por Aduana; al procesarse quedará en Solicitudes para el superadmin.',
        ];
    }

    /** Procesa como máximo UNA petición. El worker externo mantiene además un lock exclusivo. */
    public function processNext(): array
    {
        $this->ensureEnabled();
        $recovered = $this->queue->recoverStale();
        $row = $this->queue->claimNext();
        if ($row === null) {
            return ['ok' => true, 'processed' => 0, 'recovered' => $recovered, 'queue_depth' => 0];
        }

        $id = (int)$row['id_'];
        $attempts = (int)$row['Attempts'];
        try {
            $documentation = json_decode((string)$row['DocumentationJson'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($documentation) || array_is_list($documentation)) {
                throw new FederationException('Documentación de aduana inválida.', 400);
            }

            $result = match ((string)$row['RequestType']) {
                'node_presence' => $this->processNodePresence($documentation),
                'shared_backend_authorization' => $this->processSharedBackendAuthorization($documentation),
                default => throw new FederationException('Tipo de petición de aduana desconocido.', 400),
            };
            $this->queue->complete($id);
            return [
                'ok' => true,
                'processed' => 1,
                'request_id' => (string)$row['RequestId'],
                'type' => (string)$row['RequestType'],
                'result' => $result,
                'recovered' => $recovered,
                'queue_depth' => $this->queue->queuedCount(),
            ];
        } catch (JsonException $e) {
            $this->queue->reject($id, 'Documentación JSON inválida.');
            return ['ok' => false, 'processed' => 1, 'request_id' => (string)$row['RequestId'], 'status' => 'rejected'];
        } catch (FederationException $e) {
            if ($e->httpStatus() >= 500 && $attempts < 5) {
                $this->queue->retry($id, $e->getMessage(), min(3600, 30 * (2 ** max(0, $attempts - 1))));
                return ['ok' => false, 'processed' => 1, 'request_id' => (string)$row['RequestId'], 'status' => 'retry'];
            }
            if ($e->httpStatus() >= 500) {
                $this->queue->fail($id, $e->getMessage());
                return ['ok' => false, 'processed' => 1, 'request_id' => (string)$row['RequestId'], 'status' => 'failed'];
            }
            $this->queue->reject($id, $e->getMessage());
            return ['ok' => false, 'processed' => 1, 'request_id' => (string)$row['RequestId'], 'status' => 'rejected'];
        } catch (Throwable $e) {
            if ($attempts < 5) {
                $this->queue->retry($id, 'Error transitorio de Aduana.', min(3600, 30 * (2 ** max(0, $attempts - 1))));
                return ['ok' => false, 'processed' => 1, 'request_id' => (string)$row['RequestId'], 'status' => 'retry'];
            }
            $this->queue->fail($id, 'Error no recuperable de Aduana.');
            return ['ok' => false, 'processed' => 1, 'request_id' => (string)$row['RequestId'], 'status' => 'failed'];
        }
    }

    private function processNodePresence(array $documentation): array
    {
        $submitted = $documentation['descriptor'] ?? null;
        if (!is_array($submitted) || array_is_list($submitted)) {
            throw new FederationException('Aduana no recibió descriptor de presencia.', 400);
        }
        $candidate = $this->validator->validate($submitted);
        $live = $this->validator->validate(
            $this->http->getJson((string)$candidate['federation_url'], 'node.php')
        );
        foreach (['node_id', 'public_key', 'public_url', 'federation_url'] as $field) {
            if (!hash_equals((string)$candidate[$field], (string)$live[$field])) {
                throw new FederationException('El nodo anunciado no coincide con su descriptor HTTPS en vivo.', 409);
            }
        }
        $candidateName = (string)($candidate['node_name'] ?? '');
        $liveName = (string)($live['node_name'] ?? '');
        if (!hash_equals($candidateName, $liveName)) {
            throw new FederationException('El nombre firmado del nodo no coincide con el descriptor en vivo.', 409);
        }
        $this->nodes->upsertVerified($live);
        return [
            'status' => 'active',
            'node_id' => (string)$live['node_id'],
            'public_url' => (string)$live['public_url'],
            'federation_url' => (string)$live['federation_url'],
            'requires_superadmin' => false,
        ];
    }

    private function processSharedBackendAuthorization(array $documentation): array
    {
        $descriptor = $documentation['provider_descriptor'] ?? null;
        if (!is_array($descriptor) || array_is_list($descriptor)) {
            throw new FederationException('Aduana no recibió descriptor de copia compartida.', 400);
        }
        $result = (new FederationProviderAuthorizationService($this->app))->receiveRequest(
            (string)($documentation['origin_node_id'] ?? ''),
            $descriptor,
            (string)($documentation['role'] ?? 'mirror'),
            (string)($documentation['scope'] ?? 'all_allowed_resources')
        );
        $result['requires_superadmin'] = true;
        $result['relationship'] = 'shared_backend_replica';
        return $result;
    }

    private function requestId(?string $provided, string $material, bool $timeBucket): string
    {
        $provided = trim((string)$provided);
        if ($provided !== '') {
            if (!preg_match('/\Afcq_[A-Za-z0-9_-]{20,80}\z/', $provided)) {
                throw new FederationException('Request ID FederationCloud inválido.', 400);
            }
            return $provided;
        }
        if ($timeBucket) {
            $material .= '|' . (string)floor(time() / 60);
        }
        return 'fcq_' . FederationCodec::base64UrlEncode(
            sodium_crypto_generichash($material, '', 24)
        );
    }

    private function ensureEnabled(): void
    {
        if (!$this->config->enabled()) {
            throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
        }
    }
}
