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
        $nodeId = (string)$descriptor['node_id'];
        $nodeName = is_string($descriptor['node_name'] ?? null) && $descriptor['node_name'] !== ''
            ? (string)$descriptor['node_name']
            : null;
        $publicKey = (string)$descriptor['public_key'];
        $publicUrl = (string)$descriptor['public_url'];
        $federationUrl = (string)$descriptor['federation_url'];

        $existing = $this->findBinding($nodeId);
        if ($existing !== null) {
            foreach ([
                'PublicKey' => $publicKey,
                'PublicUrl' => $publicUrl,
                'FederationUrl' => $federationUrl,
            ] as $column => $expected) {
                if (!hash_equals((string)$existing[$column], $expected)) {
                    throw new FederationException(
                        'La identidad FederationCloud ya está ligada a otra clave o URL; requiere recuperación administrativa explícita.',
                        409
                    );
                }
            }

            $existingName = is_string($existing['NodeName'] ?? null) && $existing['NodeName'] !== ''
                ? (string)$existing['NodeName']
                : null;
            if ($existingName !== null && $nodeName !== null && !hash_equals($existingName, $nodeName)) {
                throw new FederationException('El Node ID ya está ligado a otro nombre de nodo.', 409);
            }

            $stmt = $this->db->prepare(
                "UPDATE FederationNodes
                 SET NodeName = COALESCE(NodeName, ?), Status = 'active', LastSeen = UTC_TIMESTAMP()
                 WHERE NodeId = ? LIMIT 1"
            );
            if (!$stmt) {
                throw new FederationException('No se pudo preparar la actualización FederationNodes.', 500);
            }
            $stmt->bind_param('ss', $nodeName, $nodeId);
            if (!$stmt->execute()) {
                $errno = $stmt->errno;
                $message = $stmt->error;
                $stmt->close();
                if ($errno === 1062) {
                    throw new FederationException('Ese nombre FederationCloud ya pertenece a otro Node ID.', 409);
                }
                throw new FederationException('No se pudo actualizar el nodo FederationCloud: ' . $message, 500);
            }
            $stmt->close();
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO FederationNodes
                (NodeId, NodeName, PublicKey, PublicUrl, FederationUrl, Status, FirstSeen, LastSeen)
             VALUES (?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar el registro FederationNodes.', 500);
        }
        $stmt->bind_param('sssss', $nodeId, $nodeName, $publicKey, $publicUrl, $federationUrl);
        if (!$stmt->execute()) {
            $errno = $stmt->errno;
            $message = $stmt->error;
            $stmt->close();
            if ($errno === 1062) {
                throw new FederationException('El Node ID o nombre FederationCloud ya está registrado.', 409);
            }
            throw new FederationException('No se pudo registrar el nodo FederationCloud: ' . $message, 500);
        }
        $stmt->close();
    }

    public function activeDirectory(): array
    {
        $sql = "SELECT NodeId, NodeName, PublicUrl, FederationUrl, FirstSeen, LastSeen
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
                'node_name' => is_string($row['NodeName'] ?? null) && $row['NodeName'] !== '' ? (string)$row['NodeName'] : null,
                'public_url' => (string)$row['PublicUrl'],
                'federation_url' => (string)$row['FederationUrl'],
                'first_seen' => (string)$row['FirstSeen'],
                'last_seen' => (string)$row['LastSeen'],
            ];
        }
        $result->free();
        return $nodes;
    }

    private function findBinding(string $nodeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT NodeId, NodeName, PublicKey, PublicUrl, FederationUrl FROM FederationNodes WHERE NodeId = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new FederationException('No se pudo consultar la identidad FederationCloud registrada.', 500);
        }
        $stmt->bind_param('s', $nodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo consultar la identidad FederationCloud: ' . $message, 500);
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}
