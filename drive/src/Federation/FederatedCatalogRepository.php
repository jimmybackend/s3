<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederatedCatalogRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function applyEvent(array $event): void
    {
        $type = (string)$event['event_type'];
        if ($type === 'node.upsert') {
            $this->applyNodeUpsert($event);
            return;
        }
        if ($type === 'resource.upsert') {
            $this->applyResourceUpsert($event);
            return;
        }
        if ($type === 'resource.tombstone') {
            $this->applyResourceTombstone($event);
            return;
        }
        if ($type === 'location.upsert') {
            $this->applyLocationUpsert($event);
            return;
        }
        if ($type === 'location.tombstone') {
            $this->applyLocationTombstone($event);
            return;
        }
        throw new FederationException('Evento federado no materializable.', 400);
    }

    public function setLocalOwner(string $resourceId, string $originNodeId, int $userId): void
    {
        if ($userId <= 0) return;
        $stmt = $this->db->prepare(
            'UPDATE FederatedResources SET OwnerUserId = ? WHERE ResourceId = ? AND OriginNodeId = ? LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar owner local federado.', 500);
        $stmt->bind_param('iss', $userId, $resourceId, $originNodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo conservar owner local federado: ' . $message, 500);
        }
        $stmt->close();
    }

    public function find(string $resourceId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ResourceId, OriginNodeId, OwnerUserId, ResourceType, Title, MediaType, SizeBytes, ContentId, '
            . 'Visibility, DiscoveryPolicy, Rights, OriginUrl, FederationUrl, ArcadeLinkJson, LastOriginSequence, UpdatedAt '
            . 'FROM FederatedResources WHERE ResourceId = ? AND Tombstoned = 0 LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo consultar recurso federado.', 500);
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);
        $limit = max(1, min(50, $limit));
        if ($query === '') return [];
        $like = '%' . $query . '%';
        $sql = "SELECT ResourceId, OriginNodeId, ResourceType, Title, MediaType, SizeBytes, ContentId,
                       Visibility, DiscoveryPolicy, Rights, OriginUrl, FederationUrl, ArcadeLinkJson, UpdatedAt
                FROM FederatedResources
                WHERE Tombstoned = 0
                  AND DiscoveryPolicy IN ('public_metadata','requestable_metadata')
                  AND (Title LIKE ? OR MediaType LIKE ? OR ContentId = ?)
                ORDER BY UpdatedAt DESC, ResourceId ASC
                LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new FederationException('No se pudo preparar búsqueda federada global.', 500);
        $stmt->bind_param('sss', $like, $like, $query);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo buscar el catálogo federado: ' . $message, 500);
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $resourceId = (string)$row['ResourceId'];
            $rows[] = [
                'resource_id' => $resourceId,
                'origin_node_id' => (string)$row['OriginNodeId'],
                'resource_type' => (string)$row['ResourceType'],
                'title' => (string)$row['Title'],
                'media_type' => (string)$row['MediaType'],
                'size_bytes' => (int)$row['SizeBytes'],
                'content_id' => is_string($row['ContentId'] ?? null) && $row['ContentId'] !== '' ? (string)$row['ContentId'] : null,
                'visibility' => (string)$row['Visibility'],
                'discovery_policy' => (string)$row['DiscoveryPolicy'],
                'rights' => (string)$row['Rights'],
                'origin_url' => (string)$row['OriginUrl'],
                'federation_url' => (string)$row['FederationUrl'],
                'updated_at' => (string)$row['UpdatedAt'],
                'locations' => $this->locations($resourceId),
                'arcadelink' => $this->decodeArcadeLink($row['ArcadeLinkJson'] ?? null),
            ];
        }
        $stmt->close();
        return $rows;
    }

    public function locations(string $resourceId): array
    {
        $stmt = $this->db->prepare(
            "SELECT NodeId, LocationRole, Status, FederationUrl, LastSeenAt, UpdatedAt
             FROM FederationResourceLocations
             WHERE ResourceId = ? AND Status <> 'revoked'
             ORDER BY FIELD(Status,'active','stale','revoked'), FIELD(LocationRole,'origin','provider','mirror'), UpdatedAt DESC"
        );
        if (!$stmt) throw new FederationException('No se pudieron consultar ubicaciones federadas.', 500);
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'node_id' => (string)$row['NodeId'],
                'role' => (string)$row['LocationRole'],
                'status' => (string)$row['Status'],
                'federation_url' => (string)$row['FederationUrl'],
                'last_seen_at' => $row['LastSeenAt'] !== null ? (string)$row['LastSeenAt'] : null,
                'updated_at' => (string)$row['UpdatedAt'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    private function applyNodeUpsert(array $event): void
    {
        $payload = $event['payload'];
        $nodeId = (string)$event['origin_node_id'];
        if (!hash_equals($nodeId, (string)($payload['node_id'] ?? ''))) {
            throw new FederationException('Evento node.upsert no pertenece al nodo firmante.', 409);
        }
        $publicKey = (string)($event['public_key'] ?? '');
        $nodeName = trim((string)($payload['node_name'] ?? ''));
        if ($nodeName !== '') $nodeName = NodeIdentityService::normalizeNodeName($nodeName);
        $publicUrl = $this->requiredHttpsUrl((string)($payload['public_url'] ?? ''), 'public_url');
        $federationUrl = $this->requiredHttpsUrl((string)($payload['federation_url'] ?? ''), 'federation_url');

        if ($nodeName !== '') {
            $check = $this->db->prepare('SELECT NodeId FROM FederationNodes WHERE NodeName = ? AND NodeId <> ? LIMIT 1');
            if (!$check) throw new FederationException('No se pudo comprobar nombre federado replicado.', 500);
            $check->bind_param('ss', $nodeName, $nodeId);
            $check->execute();
            $collision = $check->get_result()->fetch_assoc();
            $check->close();
            if (is_array($collision)) $nodeName = '';
        }

        $existing = $this->db->prepare('SELECT PublicKey FROM FederationNodes WHERE NodeId = ? LIMIT 1');
        if (!$existing) throw new FederationException('No se pudo consultar binding de nodo federado.', 500);
        $existing->bind_param('s', $nodeId);
        $existing->execute();
        $row = $existing->get_result()->fetch_assoc();
        $existing->close();
        if (is_array($row) && !hash_equals((string)$row['PublicKey'], $publicKey)) {
            throw new FederationException('El Node ID replicado cambió de clave pública.', 409);
        }

        $nullableName = $nodeName !== '' ? $nodeName : null;
        $stmt = $this->db->prepare(
            "INSERT INTO FederationNodes (NodeId, NodeName, PublicKey, PublicUrl, FederationUrl, Status, FirstSeen, LastSeen)
             VALUES (?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE NodeName = COALESCE(VALUES(NodeName), NodeName), PublicUrl = VALUES(PublicUrl),
                 FederationUrl = VALUES(FederationUrl), Status = 'active', LastSeen = UTC_TIMESTAMP()"
        );
        if (!$stmt) throw new FederationException('No se pudo materializar nodo federado.', 500);
        $stmt->bind_param('sssss', $nodeId, $nullableName, $publicKey, $publicUrl, $federationUrl);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo aplicar node.upsert: ' . $message, 500);
        }
        $stmt->close();
    }

    private function applyResourceUpsert(array $event): void
    {
        $payload = $event['payload'];
        $resourceId = (string)$event['entity_id'];
        $originNodeId = (string)$event['origin_node_id'];
        $sequence = (int)$event['origin_sequence'];
        if (!hash_equals($resourceId, (string)($payload['resource_id'] ?? ''))
            || !hash_equals($originNodeId, (string)($payload['origin_node_id'] ?? ''))) {
            throw new FederationException('resource.upsert no pertenece a su origen.', 409);
        }
        $policy = (string)($payload['discovery_policy'] ?? 'local_only');
        if (!in_array($policy, ['public_metadata', 'requestable_metadata'], true)) {
            throw new FederationException('Un recurso replicado debe ser indexable.', 409);
        }
        $visibility = strtoupper((string)($payload['visibility'] ?? 'UNLISTED'));
        if (!in_array($visibility, ['PUBLIC','UNLISTED','PRIVATE'], true)) throw new FederationException('Visibilidad federada inválida.', 400);
        $title = $this->safeText((string)($payload['title'] ?? ''), 255, 'Recurso');
        $mediaType = $this->safeText((string)($payload['media_type'] ?? ''), 128, 'application/octet-stream');
        $rights = $this->safeText((string)($payload['rights'] ?? 'link_only'), 64, 'link_only');
        $resourceType = $this->safeText((string)($payload['resource_type'] ?? 'file'), 32, 'file');
        $size = max(0, (int)($payload['size_bytes'] ?? 0));
        $contentId = is_string($payload['content_id'] ?? null) && $payload['content_id'] !== '' ? strtolower((string)$payload['content_id']) : null;
        if ($contentId !== null && !preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) throw new FederationException('Content ID federado inválido.', 400);
        $originUrl = $this->requiredHttpsUrl((string)($payload['origin_url'] ?? ''), 'origin_url');
        $federationUrl = $this->requiredHttpsUrl((string)($payload['federation_url'] ?? ''), 'federation_url');
        $arcadeLink = $payload['arcadelink'] ?? null;
        if (!is_array($arcadeLink) || array_is_list($arcadeLink)) throw new FederationException('ArcadeLink replicado ausente.', 400);
        $arcadeLinkJson = json_encode($arcadeLink, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($arcadeLinkJson) > ArcadeLinkService::MAX_BYTES) throw new FederationException('ArcadeLink replicado demasiado grande.', 400);

        $current = $this->resourceBinding($resourceId);
        if ($current !== null) {
            if (!hash_equals((string)$current['OriginNodeId'], $originNodeId)) throw new FederationException('Resource ID ya pertenece a otro nodo.', 409);
            if ($sequence <= (int)$current['LastOriginSequence']) return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO FederatedResources
                (ResourceId, OriginNodeId, OwnerUserId, ResourceType, Title, MediaType, SizeBytes, ContentId, Visibility,
                 DiscoveryPolicy, Rights, OriginUrl, FederationUrl, ArcadeLinkJson, LastOriginSequence, UpdatedAt, Tombstoned)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), 0)
             ON DUPLICATE KEY UPDATE ResourceType=VALUES(ResourceType), Title=VALUES(Title), MediaType=VALUES(MediaType),
                 SizeBytes=VALUES(SizeBytes), ContentId=VALUES(ContentId), Visibility=VALUES(Visibility),
                 DiscoveryPolicy=VALUES(DiscoveryPolicy), Rights=VALUES(Rights), OriginUrl=VALUES(OriginUrl),
                 FederationUrl=VALUES(FederationUrl), ArcadeLinkJson=VALUES(ArcadeLinkJson),
                 LastOriginSequence=VALUES(LastOriginSequence), UpdatedAt=UTC_TIMESTAMP(6), Tombstoned=0"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar recurso federado global.', 500);
        $stmt->bind_param(
            'sssssisssssssi',
            $resourceId, $originNodeId, $resourceType, $title, $mediaType, $size, $contentId,
            $visibility, $policy, $rights, $originUrl, $federationUrl, $arcadeLinkJson, $sequence
        );
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo materializar recurso federado: ' . $message, 500);
        }
        $stmt->close();
    }

    private function applyResourceTombstone(array $event): void
    {
        $resourceId = (string)$event['entity_id'];
        $originNodeId = (string)$event['origin_node_id'];
        $sequence = (int)$event['origin_sequence'];
        $current = $this->resourceBinding($resourceId);
        if ($current === null) return;
        if (!hash_equals((string)$current['OriginNodeId'], $originNodeId)) throw new FederationException('Tombstone de recurso no pertenece al origen.', 409);
        if ($sequence <= (int)$current['LastOriginSequence']) return;
        $stmt = $this->db->prepare(
            'UPDATE FederatedResources SET Tombstoned=1, DiscoveryPolicy=\'local_only\', ArcadeLinkJson=NULL, LastOriginSequence=?, UpdatedAt=UTC_TIMESTAMP(6) WHERE ResourceId=? AND OriginNodeId=?'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar tombstone federado.', 500);
        $stmt->bind_param('iss', $sequence, $resourceId, $originNodeId);
        $stmt->execute();
        $stmt->close();
    }

    private function applyLocationUpsert(array $event): void
    {
        $payload = $event['payload'];
        $resourceId = (string)($payload['resource_id'] ?? '');
        $nodeId = (string)($payload['node_id'] ?? '');
        if ($resourceId === '' || !hash_equals($nodeId, (string)$event['origin_node_id'])) {
            throw new FederationException('Ubicación federada debe estar firmada por el nodo que la anuncia.', 409);
        }
        $role = (string)($payload['role'] ?? 'origin');
        if (!in_array($role, ['origin','provider','mirror'], true)) throw new FederationException('Rol de ubicación federada inválido.', 400);
        $status = (string)($payload['status'] ?? 'active');
        if (!in_array($status, ['active','stale','revoked'], true)) throw new FederationException('Estado de ubicación federada inválido.', 400);
        $url = $this->requiredHttpsUrl((string)($payload['federation_url'] ?? ''), 'federation_url');
        $sequence = (int)$event['origin_sequence'];

        $stmt = $this->db->prepare(
            "INSERT INTO FederationResourceLocations
                (ResourceId, NodeId, LocationRole, Status, FederationUrl, LastOriginSequence, LastSeenAt, UpdatedAt)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
               LocationRole = IF(VALUES(LastOriginSequence) > LastOriginSequence, VALUES(LocationRole), LocationRole),
               Status = IF(VALUES(LastOriginSequence) > LastOriginSequence, VALUES(Status), Status),
               FederationUrl = IF(VALUES(LastOriginSequence) > LastOriginSequence, VALUES(FederationUrl), FederationUrl),
               LastSeenAt = IF(VALUES(LastOriginSequence) > LastOriginSequence, UTC_TIMESTAMP(6), LastSeenAt),
               UpdatedAt = IF(VALUES(LastOriginSequence) > LastOriginSequence, UTC_TIMESTAMP(6), UpdatedAt),
               LastOriginSequence = GREATEST(LastOriginSequence, VALUES(LastOriginSequence))"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar ubicación federada.', 500);
        $stmt->bind_param('sssssi', $resourceId, $nodeId, $role, $status, $url, $sequence);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo materializar ubicación federada: ' . $message, 500);
        }
        $stmt->close();
    }

    private function applyLocationTombstone(array $event): void
    {
        $payload = $event['payload'];
        $resourceId = (string)($payload['resource_id'] ?? '');
        $nodeId = (string)($payload['node_id'] ?? '');
        if ($resourceId === '' || !hash_equals($nodeId, (string)$event['origin_node_id'])) {
            throw new FederationException('Tombstone de ubicación inválido.', 409);
        }
        $sequence = (int)$event['origin_sequence'];
        $stmt = $this->db->prepare(
            "UPDATE FederationResourceLocations SET Status='revoked', LastOriginSequence=?, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE ResourceId=? AND NodeId=? AND LastOriginSequence < ?"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar baja de ubicación federada.', 500);
        $stmt->bind_param('issi', $sequence, $resourceId, $nodeId, $sequence);
        $stmt->execute();
        $stmt->close();
    }

    private function resourceBinding(string $resourceId): ?array
    {
        $stmt = $this->db->prepare('SELECT OriginNodeId, LastOriginSequence FROM FederatedResources WHERE ResourceId = ? LIMIT 1');
        if (!$stmt) throw new FederationException('No se pudo consultar binding de recurso global.', 500);
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function decodeArcadeLink(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    private function requiredHttpsUrl(string $url, string $field): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new FederationException('URL federada inválida: ' . $field . '.', 400);
        }
        return $url;
    }

    private function safeText(string $value, int $max, string $fallback): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value);
        if ($value === '') $value = $fallback;
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
