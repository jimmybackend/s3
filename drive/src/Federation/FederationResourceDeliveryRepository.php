<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationResourceDeliveryRepository
{
    public function __construct(private mysqli $db) {}

    public function summary(string $resourceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS DeliveryCount, COALESCE(SUM(BytesDelivered),0) AS BytesDelivered, MAX(CompletedAt) AS LastDeliveredAt '
            . 'FROM FederationResourceDeliveries WHERE ResourceId=?'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar resumen de entregas federadas.', 500);
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $nodes = [];
        $byNode = $this->db->prepare(
            'SELECT s.NodeId, s.Role, COUNT(*) AS DeliveryCount '
            . 'FROM FederationResourceDeliverySources s '
            . 'INNER JOIN FederationResourceDeliveries d ON d.DeliveryId=s.DeliveryId '
            . 'WHERE d.ResourceId=? GROUP BY s.NodeId, s.Role ORDER BY DeliveryCount DESC, s.NodeId ASC'
        );
        if (!$byNode) throw new FederationException('No se pudo preparar resumen por nodo federado.', 500);
        $byNode->bind_param('s', $resourceId);
        $byNode->execute();
        $result = $byNode->get_result();
        while ($node = $result->fetch_assoc()) {
            $nodes[] = [
                'node_id' => (string)$node['NodeId'],
                'role' => (string)$node['Role'],
                'delivery_count' => (int)$node['DeliveryCount'],
            ];
        }
        $byNode->close();

        return [
            'delivery_count' => (int)($row['DeliveryCount'] ?? 0),
            'bytes_delivered' => (int)($row['BytesDelivered'] ?? 0),
            'last_delivered_at' => is_string($row['LastDeliveredAt'] ?? null) ? (string)$row['LastDeliveredAt'] : null,
            'by_source_node' => $nodes,
        ];
    }

    public function history(string $resourceId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            "SELECT DeliveryId, RequestNodeId, SourceCount, BytesDelivered, Transport, CompletedAt, EventId
             FROM FederationResourceDeliveries WHERE ResourceId=?
             ORDER BY CompletedAt DESC, DeliveryId DESC LIMIT {$limit}"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar historial de entregas federadas.', 500);
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $id = (string)$row['DeliveryId'];
            $ids[] = $id;
            $rows[$id] = [
                'delivery_id' => $id,
                'request_node_id' => (string)$row['RequestNodeId'],
                'source_count' => (int)$row['SourceCount'],
                'bytes_delivered' => (int)$row['BytesDelivered'],
                'transport' => (string)$row['Transport'],
                'completed_at' => (string)$row['CompletedAt'],
                'event_id' => (string)$row['EventId'],
                'sources' => [],
            ];
        }
        $stmt->close();

        if ($ids !== []) {
            $source = $this->db->prepare(
                'SELECT NodeId, Role FROM FederationResourceDeliverySources WHERE DeliveryId=? ORDER BY NodeId ASC'
            );
            if (!$source) throw new FederationException('No se pudieron preparar fuentes del historial federado.', 500);
            foreach ($ids as $id) {
                $source->bind_param('s', $id);
                $source->execute();
                $sr = $source->get_result();
                while ($item = $sr->fetch_assoc()) {
                    $rows[$id]['sources'][] = [
                        'node_id' => (string)$item['NodeId'],
                        'role' => (string)$item['Role'],
                    ];
                }
            }
            $source->close();
        }

        return array_values($rows);
    }
}
