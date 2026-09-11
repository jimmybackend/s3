<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationAccessService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederationAccessMessageCodec $codec;
    private FederationAccessRepository $requests;
    private FederatedCatalogRepository $catalog;
    private FederatedResourceRepository $localFiles;
    private ArcadeLinkService $links;
    private FederationHttpClient $http;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        if (!$this->config->enabled()) throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->codec = new FederationAccessMessageCodec();
        $this->requests = new FederationAccessRepository($this->app->db());
        $this->catalog = new FederatedCatalogRepository($this->app->db());
        $this->localFiles = new FederatedResourceRepository($this->app->db());
        $this->links = new ArcadeLinkService($this->config, $this->identity);
        $this->http = new FederationHttpClient();
    }

    public function requestAccess(int $userId, string $resourceId): array
    {
        if ($userId <= 0) throw new FederationException('Usuario local inválido.', 401);
        $resource = $this->catalog->find($resourceId);
        if ($resource === null) throw new FederationException('Recurso global no encontrado.', 404);
        if ((string)$resource['DiscoveryPolicy'] !== 'requestable_metadata') {
            throw new FederationException('Este recurso no admite solicitudes de acceso.', 409);
        }
        $originNodeId = (string)$resource['OriginNodeId'];
        if (hash_equals($originNodeId, $this->identity->nodeId())) {
            throw new FederationException('El recurso pertenece a este mismo nodo.', 409);
        }
        $remoteUrl = (string)$resource['FederationUrl'];
        $request = $this->codec->createRequest(
            $this->identity,
            $resourceId,
            $this->config->federationUrl()
        );
        $this->requests->storeOutgoing($userId, $request, $originNodeId, $remoteUrl);

        $delivered = false;
        try {
            $response = $this->http->postJson($remoteUrl, 'access-request.php', ['request' => $request]);
            if (($response['ok'] ?? null) !== true) throw new FederationException('El nodo origen rechazó la solicitud.', 502);
            $this->requests->markDelivered((string)$request['request_id']);
            $delivered = true;
        } catch (Throwable $e) {
            $this->requests->markRetry((string)$request['request_id'], $e->getMessage());
        }

        return [
            'ok' => true,
            'request_id' => (string)$request['request_id'],
            'resource_id' => $resourceId,
            'status' => $delivered ? 'pending' : 'queued',
            'message' => $delivered
                ? 'Solicitud entregada al nodo propietario.'
                : 'El nodo propietario no está disponible; la solicitud quedó en cola para reintento automático.',
        ];
    }

    public function receiveRequest(array $document): array
    {
        $request = $this->codec->verifyRequest($document);
        if (hash_equals((string)$request['requester_node_id'], $this->identity->nodeId())) {
            throw new FederationException('Un nodo no puede solicitarse acceso a sí mismo.', 409);
        }
        $resource = $this->catalog->find((string)$request['resource_id']);
        if ($resource === null
            || !hash_equals((string)$resource['OriginNodeId'], $this->identity->nodeId())
            || (string)$resource['DiscoveryPolicy'] !== 'requestable_metadata') {
            throw new FederationException('El recurso no admite solicitudes en este nodo.', 404);
        }
        $ownerUserId = (int)($resource['OwnerUserId'] ?? 0);
        if ($ownerUserId <= 0) throw new FederationException('El propietario local del recurso no está disponible.', 409);
        $this->requests->storeIncoming($ownerUserId, $request);
        $existing = $this->requests->find((string)$request['request_id']);
        return [
            'ok' => true,
            'request_id' => (string)$request['request_id'],
            'status' => is_array($existing) ? (string)$existing['Status'] : 'pending',
        ];
    }

    public function requestStatus(array $document): array
    {
        $request = $this->codec->verifyRequest($document);
        $row = $this->requests->find((string)$request['request_id']);
        if ($row === null || (string)$row['Direction'] !== 'incoming'
            || !hash_equals((string)$row['RemoteNodeId'], (string)$request['requester_node_id'])
            || !hash_equals((string)$row['ResourceId'], (string)$request['resource_id'])) {
            throw new FederationException('Solicitud FederationCloud no encontrada.', 404);
        }
        $status = (string)$row['Status'];
        $response = ['ok' => true, 'request_id' => (string)$row['RequestId'], 'status' => $status];
        if (in_array($status, ['approved','rejected'], true)) {
            $decision = $this->requests->decodeDecision($row);
            if ($decision !== null) $response['decision'] = $decision;
        }
        return $response;
    }

    public function decide(int $ownerUserId, string $requestId, bool $approve, int $days = 7): array
    {
        $row = $this->requests->find($requestId);
        if ($row === null || (string)$row['Direction'] !== 'incoming' || (int)$row['LocalUserId'] !== $ownerUserId) {
            throw new FederationException('Solicitud de acceso no encontrada para este usuario.', 404);
        }
        if (in_array((string)$row['Status'], ['approved','rejected','expired'], true)) {
            throw new FederationException('La solicitud ya fue resuelta.', 409);
        }
        $request = $this->requests->decodeRequest($row);
        $resource = $this->catalog->find((string)$row['ResourceId']);
        if ($resource === null || !hash_equals((string)$resource['OriginNodeId'], $this->identity->nodeId())) {
            throw new FederationException('Recurso local de la solicitud no disponible.', 404);
        }
        $title = (string)$resource['Title'];
        $mediaType = (string)$resource['MediaType'];

        if (!$approve) {
            $decision = $this->codec->createDecision($this->identity, $request, 'rejected');
            $this->requests->decideIncoming($requestId, $ownerUserId, $decision, $title, $mediaType);
            return ['ok' => true, 'request_id' => $requestId, 'status' => 'rejected'];
        }

        $days = max(1, min(30, $days));
        $arcadeLinkJson = (string)($resource['ArcadeLinkJson'] ?? '');
        if ($arcadeLinkJson === '') throw new FederationException('El recurso no conserva ArcadeLink local para conceder acceso.', 409);
        $document = $this->links->parse($arcadeLinkJson);
        $payload = $this->links->decryptLocalPayload($document);
        if ((int)$payload['user_id'] !== $ownerUserId) throw new FederationException('El usuario no es propietario del recurso.', 403);

        if (is_string($payload['storage_ref'] ?? null) && trim((string)$payload['storage_ref']) !== '') {
            $file = $this->localFiles->findOwnedFileByStorageRef($ownerUserId, (string)$payload['storage_ref']);
            if ($file === null) throw new FederationException('Archivo local no encontrado.', 404);
        } else {
            $file = $this->localFiles->requireOwnedFile($ownerUserId, (int)$payload['file_id']);
        }
        $key = $this->localFiles->storageKey($file);
        if ($key === '') throw new FederationException('El recurso no tiene referencia de almacenamiento válida.', 500);
        $share = $this->app->shareLinkService()->create($ownerUserId, $key, $this->shareType($mediaType), $days);
        $accessUrl = $this->config->publicUrl() . '/' . ltrim((string)$share['endpoint'], '/')
            . '?t=' . rawurlencode((string)$share['token']);
        $decision = $this->codec->createDecision(
            $this->identity,
            $request,
            'approved',
            $accessUrl,
            (string)$share['expira_iso']
        );
        $this->requests->decideIncoming($requestId, $ownerUserId, $decision, $title, $mediaType);
        return [
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'approved',
            'expires_at' => (string)$share['expira_iso'],
        ];
    }

    public function inbox(int $userId): array
    {
        return $this->requests->incomingForUser($userId);
    }

    public function outbox(int $userId): array
    {
        return $this->requests->outgoingForUser($userId);
    }

    public function shares(int $userId): array
    {
        $this->requests->expireOld();
        return $this->requests->sharesForUser($userId);
    }

    public function syncPending(int $limit = 5): array
    {
        $expired = $this->requests->expireOld();
        $processed = 0;
        $completed = 0;
        $queued = 0;
        foreach ($this->requests->pendingOutgoing($limit) as $row) {
            $processed++;
            $requestId = (string)$row['RequestId'];
            $remoteUrl = (string)$row['RemoteFederationUrl'];
            try {
                $request = $this->requests->decodeRequest($row);
                if ((string)$row['Status'] === 'queued') {
                    $response = $this->http->postJson($remoteUrl, 'access-request.php', ['request' => $request]);
                    if (($response['ok'] ?? null) !== true) throw new FederationException('El nodo remoto no aceptó la solicitud.', 502);
                    $this->requests->markDelivered($requestId);
                }

                $status = $this->http->postJson($remoteUrl, 'access-status.php', ['request' => $request]);
                $remoteStatus = (string)($status['status'] ?? 'pending');
                if (in_array($remoteStatus, ['approved','rejected'], true) && is_array($status['decision'] ?? null)) {
                    $decision = $this->codec->verifyDecision($status['decision'], $request);
                    $resource = $this->catalog->find((string)$row['ResourceId']);
                    $this->requests->completeOutgoing(
                        $requestId,
                        $decision,
                        is_array($resource) ? (string)$resource['Title'] : 'Recurso FederationCloud',
                        is_array($resource) ? (string)$resource['MediaType'] : 'application/octet-stream'
                    );
                    $completed++;
                }
            } catch (Throwable $e) {
                $this->requests->markRetry($requestId, $e->getMessage());
                $queued++;
            }
        }
        return [
            'processed' => $processed,
            'completed' => $completed,
            'queued_after_error' => $queued,
            'expired' => $expired,
        ];
    }

    private function shareType(string $mediaType): string
    {
        $mediaType = strtolower($mediaType);
        if (str_starts_with($mediaType, 'audio/')) return 'audio';
        if (str_starts_with($mediaType, 'video/')) return 'video';
        if (str_starts_with($mediaType, 'image/')) return 'imagen';
        if (str_starts_with($mediaType, 'text/')) return 'texto';
        return 'otro';
    }
}
