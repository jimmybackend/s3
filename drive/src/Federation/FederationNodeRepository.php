<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationNodeRepository
{
    public const ACTIVE_WINDOW_MINUTES = 15;
    private const MAX_DIRECTORY_NODES = 100;

    public function __construct(private mysqli $db)
    {
    }

    public function upsertVerified(array $descriptor): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO FederationNodes
                (NodeId, PublicKey, PublicUrl, FederationUrl, Status, FirstSeen, LastSeen)
             VALUES (?, ?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                PublicKey = VALUES(PublicKey),
                PublicUrl = VALUES(PublicUrl),
                FederationUrl = VALUES(FederationUrl),
                LastSeen = UTC_TIMESTAMP()"
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar el registro FederationNodes.', 500);
        }

        $nodeId = (string)$descriptor['node_id'];
        $publicKey = (string)$descriptor['public_key'];
        $publicUrl = (string)$descriptor['public_url'];
        $federationUrl = (string)$descriptor['federation_url'];
        $stmt->bind_param('ssss', $nodeId, $publicKey, $publicUrl, $federationUrl);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo registrar el nodo FederationCloud: ' . $message, 500);
        }
        $stmt->close();
    }

    public function activeDirectory(): array
    {
        $sql = "SELECT NodeId, PublicUrl, FederationUrl, FirstSeen, LastSeen
                FROM FederationNodes
                WHERE Status = 'active'
                  AND LastSeen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . self::ACTIVE_WINDOW_MINUTES . " MINUTE)
                ORDER BY FirstSeen ASC, NodeId ASC
                LIMIT " . self::MAX_DIRECTORY_NODES;
        $result = $this->db->query($sql);
        if (!$result) {
            throw new FederationException('No se pudo consultar el directorio FederationCloud.', 500);
        }

        $nodes = [];
        while ($row = $result->fetch_assoc()) {
            $nodes[] = [
                'node_id' => (string)$row['NodeId'],
                'public_url' => (string)$row['PublicUrl'],
                'federation_url' => (string)$row['FederationUrl'],
                'first_seen' => (string)$row['FirstSeen'],
                'last_seen' => (string)$row['LastSeen'],
            ];
        }
        $result->free();
        return $nodes;
    }
}
