<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

final class FederationReplicaResolverService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederatedCatalogRepository $catalog;
    private FederationReplicaRepository $replicas;
    private FederationSourceFailover $failover;
    private FederationHttpClient $http;
    private FederationOriginStorageResolver $originStorage;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->catalog = new FederatedCatalogRepository($app->db());
        $this->replicas = new FederationReplicaRepository($app->db());
        $this->failover = new FederationSourceFailover();
        $this->http = new FederationHttpClient();
        $this->originStorage = new FederationOriginStorageResolver($app, $this->config, $this->identity);
    }

    /** Devuelve acceso temporal desde el original local o desde una réplica local. */
    public function publicLocation(string $resourceId): array
    {
        $resource = $this->requirePublicCopyable($resourceId);
        $localNodeId = $this->identity->nodeId();

        if (hash_equals($localNodeId, (string)$resource['OriginNodeId'])) {
            $storageKey = $this->originStorage->storageKey($resource);
            return [
                'ok' => true,
                'resource_id' => $resourceId,
                'node_id' => $localNodeId,
                'role' => 'origin',
                'content_id' => (string)$resource['ContentId'],
                'size_bytes' => (int)$resource['SizeBytes'],
                'access_url' => $this->app->shareObjectStorage()->presignedUrl($storageKey, '+5 minutes'),
                'expires_in' => 300,
            ];
        }

        $object = $this->replicas->object($resourceId);
        if ($object === null || (string)$object['Status'] !== 'active'
            || !$this->hasActiveLocalReplicaLocation($resourceId, (string)$object['Role'])) {
            throw new FederationException('Este nodo no tiene una copia activa del recurso.', 404);
        }
        if (!hash_equals((string)$resource['ContentId'], (string)$object['ContentId'])
            || (int)$resource['SizeBytes'] !== (int)$object['SizeBytes']) {
            throw new FederationException('La réplica local no coincide con el catálogo global.', 409);
        }
        return [
            'ok' => true,
            'resource_id' => $resourceId,
            'node_id' => $localNodeId,
            'role' => (string)$object['Role'],
            'access_url' => $this->app->shareObjectStorage()->presignedUrl((string)$object['S3Key'], '+5 minutes'),
            'expires_in' => 300,
        ];
    }

    /**
     * Obtiene varias fuentes PUBLIC + copy_allowed, las prueba contra S3 y
     * descarta ubicaciones con credenciales inválidas antes de entregarlas.
     */
    public function publicSources(string $resourceId, int $maxSources = 4): array
    {
        $resource = $this->requirePublicCopyable($resourceId);
        $maxSources = max(1, min(FederationMultiSourceDownloader::MAX_SOURCES, $maxSources));
        $locations = $this->failover->candidates(
            $this->catalog->locations($resourceId),
            [
                'node_id' => (string)$resource['OriginNodeId'],
                'federation_url' => (string)$resource['FederationUrl'],
                'updated_at' => $resource['UpdatedAt'] ?? null,
            ]
        );

        if ($locations === []) {
            $this->logResolverEvent('zero_candidates', $resourceId);
            throw new FederationException('No existe ningún proveedor FederationCloud válido para este recurso.', 503);
        }

        $probe = new FederationReplicaDownloader();
        $resolved = $this->failover->collect(
            $locations,
            $maxSources,
            function (array $location) use ($resourceId, $resource, $probe): array {
                $nodeId = trim((string)($location['node_id'] ?? ''));
                if ($nodeId === '') throw new FederationException('Candidato FederationCloud sin Node ID.', 400);

                if (hash_equals($this->identity->nodeId(), $nodeId)) {
                    $result = $this->publicLocation($resourceId);
                } else {
                    $federationUrl = trim((string)($location['federation_url'] ?? ''));
                    if ($federationUrl === '') throw new FederationException('Ubicación sin Federation URL.', 503);
                    $result = $this->http->postJson(
                        $federationUrl,
                        'replica-resolve.php',
                        ['resource_id' => $resourceId]
                    );
                }

                $returnedNodeId = trim((string)($result['node_id'] ?? ''));
                if ($returnedNodeId === '' || !hash_equals($nodeId, $returnedNodeId)) {
                    throw new FederationException(
                        'La identidad del nodo que respondió no coincide con el candidato firmado.',
                        409
                    );
                }

                $url = trim((string)($result['access_url'] ?? ''));
                $parts = parse_url($url);
                if (empty($result['ok']) || !is_array($parts)
                    || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                    || !isset($parts['host'])) {
                    throw new FederationException('La ubicación no devolvió acceso HTTPS válido.', 502);
                }

                $remoteSize = isset($result['size_bytes']) ? (int)$result['size_bytes'] : (int)$resource['SizeBytes'];
                $remoteContent = trim((string)($result['content_id'] ?? (string)$resource['ContentId']));
                if ($remoteSize !== (int)$resource['SizeBytes']
                    || !hash_equals(strtolower((string)$resource['ContentId']), strtolower($remoteContent))) {
                    throw new FederationException('La ubicación no coincide con el contenido global.', 409);
                }

                // Un URL S3 firmado con una credencial inválida se detecta aquí y
                // se continúa con otra réplica en lugar de enviar el error al usuario.
                $probe->probe($url, (int)$resource['SizeBytes']);

                return [
                    'node_id' => $nodeId,
                    'role' => (string)($location['role'] ?? $result['role'] ?? 'origin'),
                    'url' => $url,
                ];
            }
        );

        foreach ($resolved['failures'] as $failure) {
            $this->logResolverEvent('candidate_failed', $resourceId, is_array($failure) ? $failure : []);
        }

        if ($resolved['sources'] === []) {
            $this->logResolverEvent('all_candidates_failed', $resourceId, [
                'attempts' => count($resolved['failures']),
            ]);
            throw new FederationException(
                'No fue posible obtener el archivo desde los nodos disponibles. Diagnóstico FederationCloud: '
                . $this->publicFailureDiagnostic($resolved['failures']) . '.',
                503
            );
        }

        return [
            'ok' => true,
            'resource_id' => $resourceId,
            'content_id' => (string)$resource['ContentId'],
            'size_bytes' => (int)$resource['SizeBytes'],
            'title' => (string)$resource['Title'],
            'media_type' => (string)$resource['MediaType'],
            'sources' => $resolved['sources'],
            'failures' => $resolved['failures'],
        ];
    }

    /**
     * Para una descarga simple usa la primera fuente que ya superó una prueba
     * real de rango S3; si una clave AWS es inválida, se salta automáticamente.
     */
    public function openPreferred(string $resourceId): array
    {
        $resolved = $this->publicSources($resourceId, 1);
        $source = $resolved['sources'][0];
        return [
            'ok' => true,
            'resource_id' => $resourceId,
            'content_id' => (string)$resolved['content_id'],
            'size_bytes' => (int)$resolved['size_bytes'],
            'access_url' => (string)$source['url'],
            'preferred_location' => [
                'node_id' => (string)$source['node_id'],
                'role' => (string)$source['role'],
                'status' => 'active',
            ],
            'failover_attempts' => count($resolved['failures']),
        ];
    }

    private function hasActiveLocalReplicaLocation(string $resourceId, string $role): bool
    {
        $localNodeId = $this->identity->nodeId();
        foreach ($this->catalog->locations($resourceId) as $location) {
            if (hash_equals($localNodeId, (string)($location['node_id'] ?? ''))
                && (string)($location['status'] ?? '') === 'active'
                && hash_equals($role, (string)($location['role'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    private function requirePublicCopyable(string $resourceId): array
    {
        if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            throw new FederationException('Resource ID inválido.', 400);
        }
        $resource = $this->catalog->find($resourceId);
        if ($resource === null || (int)($resource['Tombstoned'] ?? 0) !== 0) {
            throw new FederationException('Recurso global no encontrado.', 404);
        }
        if ((string)$resource['Visibility'] !== 'PUBLIC' || (string)$resource['Rights'] !== 'copy_allowed') {
            throw new FederationException('El failover directo sólo está disponible para recursos PUBLIC + copy_allowed.', 403);
        }
        return $resource;
    }

    private function decodeArcadeLink(string $json): array
    {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) throw new FederationException('ArcadeLink del recurso inválido.', 500);
        return $decoded;
    }

    /**
     * Produce un diagnóstico público acotado: nunca incluye URLs prefirmadas,
     * claves S3, credenciales ni el mensaje cURL completo.
     */
    private function publicFailureDiagnostic(array $failures): string
    {
        $failure = $failures !== [] ? $failures[array_key_last($failures)] : [];
        if (!is_array($failure)) $failure = [];

        $role = strtolower(trim((string)($failure['role'] ?? 'unknown')));
        if (!in_array($role, ['origin', 'provider', 'mirror'], true)) $role = 'unknown';

        $category = strtolower(trim((string)($failure['category'] ?? 'unknown_failure')));
        if (!preg_match('/\A[a-z0-9_]{1,40}\z/', $category)) $category = 'unknown_failure';

        $resolverStatus = (int)($failure['http_status'] ?? 500);
        if ($resolverStatus < 400 || $resolverStatus > 599) $resolverStatus = 500;

        $parts = [
            'role=' . $role,
            'category=' . $category,
            'resolver_http=' . $resolverStatus,
        ];

        $error = (string)($failure['error'] ?? '');
        if (preg_match('/\bS3 HTTP ([0-9]{1,3}); cURL ([0-9]{1,3})\b/', $error, $match) === 1) {
            $s3Status = (int)$match[1];
            $curlCode = (int)$match[2];
            if ($s3Status >= 0 && $s3Status <= 599) $parts[] = 's3_http=' . $s3Status;
            if ($curlCode >= 0 && $curlCode <= 99) $parts[] = 'curl=' . $curlCode;
        }

        return implode(', ', $parts);
    }

    private function logResolverEvent(string $event, string $resourceId, array $details = []): void
    {
        $payload = [
            'event' => $event,
            'resource_id' => $resourceId,
            'node_id' => $details['node_id'] ?? null,
            'role' => $details['role'] ?? null,
            'category' => $details['category'] ?? null,
            'http_status' => $details['http_status'] ?? null,
            'attempts' => $details['attempts'] ?? null,
        ];
        error_log(
            '[ArcadeCloud Federation] '
            . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );
    }
}
