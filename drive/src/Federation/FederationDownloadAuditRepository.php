<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

/**
 * Vista histórica de SOLO LECTURA de datos federados existentes.
 * Nunca presenta las entregas entre nodos como descargas finales.
 * Todas las búsquedas filtran OwnerUserId EN SQL (no sólo en la interfaz).
 */
final class FederationDownloadAuditRepository
{
    public function __construct(private mysqli $db) {}

    /** @return array<string,mixed>|null */
    public function ownedResource(int $userId, string $resourceId): ?array
    {
        if ($userId <= 0 || !preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT ResourceId, OriginNodeId, OwnerUserId, ResourceType, Title, MediaType, SizeBytes, '
            . 'Visibility, DiscoveryPolicy, Rights, UpdatedAt, Tombstoned '
            . 'FROM FederatedResources WHERE ResourceId=? AND OwnerUserId=? LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar auditoría del recurso.', 500);
        $stmt->bind_param('si', $resourceId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) return null;

        $delivery = new FederationResourceDeliveryRepository($this->db);
        $summary = $delivery->summary($resourceId);
        return [
            'resource_id' => (string)$row['ResourceId'],
            'title' => (string)$row['Title'],
            'media_type' => (string)$row['MediaType'],
            'resource_type' => (string)$row['ResourceType'],
            'size_bytes' => (int)$row['SizeBytes'],
            'origin_node_id' => (string)$row['OriginNodeId'],
            'owner_user_id' => (int)$row['OwnerUserId'],
            'visibility' => (string)$row['Visibility'],
            'discovery_policy' => (string)$row['DiscoveryPolicy'],
            'rights' => (string)$row['Rights'],
            'updated_at_utc' => (string)$row['UpdatedAt'],
            'tombstoned' => (bool)$row['Tombstoned'],
            'federated_delivery_count' => $summary['delivery_count'],
            'federated_bytes_delivered' => $summary['bytes_delivered'],
            'last_federated_delivery_at_utc' => $summary['last_delivered_at'],
            'source_nodes' => $summary['by_source_node'],
            'verified_final_downloads' => null, // No derivable de estos registros.
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function ownedResources(int $userId, int $limit = 50, int $offset = 0): array
    {
        if ($userId <= 0) return [];
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10000000, $offset));
        $stmt = $this->db->prepare(
            'SELECT ResourceId, Title, MediaType, SizeBytes, OriginNodeId, Visibility, '
            . 'Rights, UpdatedAt, Tombstoned FROM FederatedResources '
            . 'WHERE OwnerUserId=? ORDER BY UpdatedAt DESC, ResourceId DESC '
            . 'LIMIT ? OFFSET ?'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar listado de auditoría.', 500);
        $stmt->bind_param('iii', $userId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'resource_id' => (string)$row['ResourceId'],
                'title' => (string)$row['Title'],
                'media_type' => (string)$row['MediaType'],
                'size_bytes' => (int)$row['SizeBytes'],
                'origin_node_id' => (string)$row['OriginNodeId'],
                'visibility' => (string)$row['Visibility'],
                'rights' => (string)$row['Rights'],
                'updated_at_utc' => (string)$row['UpdatedAt'],
                'tombstoned' => (bool)$row['Tombstoned'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function ownedDeliveryHistory(int $userId, string $resourceId, int $limit = 50): array
    {
        // Comprobar propietario ANTES de leer registros de entrega.
        if ($this->ownedResource($userId, $resourceId) === null) return [];
        return (new FederationResourceDeliveryRepository($this->db))->history($resourceId, $limit);
    }
}
