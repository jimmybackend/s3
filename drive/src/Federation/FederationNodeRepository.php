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

    public function isNodeNameAvailable(string $nodeName, string $nodeId = ''): bool
    {
        $stmt = $this->db->prepare(
            'SELECT NodeId FROM FederationNodes WHERE NodeName = ? AND NodeId <> ? LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo validar la disponibilidad del nombre FederationCloud.', 500);
        $stmt->bind_param('ss', $nodeName, $nodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo validar el nombre FederationCloud: ' . $message, 500);
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !is_array($row);
    }

    public function assertNodeNameAvailable(string $nodeName, string $nodeId): void
    {
        if (!$this->isNodeNameAvailable($nodeName, $nodeId)) {
            throw new FederationException('Ese nombre FederationCloud ya pertenece a otro Node ID.', 409);
        }
    }

    /**
     * El binding criptográfico es NodeId -> PublicKey y nunca rota automáticamente.
     * public_url/federation_url sí son direcciones de red mutables: un descriptor
     * Ed25519 válido del mismo Node ID puede actualizarlas cuando cambia la IP.
     */
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
            if (!hash_equals((string)$existing['PublicKey'], $publicKey)) {
                throw new FederationException(
                    'La identidad FederationCloud intentó cambiar su clave pública; requiere recuperación administrativa explícita.',
                    409
                );
            }
            if ($nodeName !== null) $this->assertNodeNameAvailable($nodeName, $nodeId);

            $stmt = $this->db->prepare(
                "UPDATE FederationNodes
                 SET NodeName = COALESCE(?, NodeName), PublicUrl = ?, FederationUrl = ?,
                     Status = 'active', LastSeen = UTC_TIMESTAMP()
                 WHERE NodeId = ? LIMIT 1"
            );
            if (!$stmt) throw new FederationException('No se pudo preparar la actualización FederationNodes.', 500);
            $stmt->bind_param('ssss', $nodeName, $publicUrl, $federationUrl, $nodeId);
            if (!$stmt->execute()) {
                $errno = $stmt->errno;
                $message = $stmt->error;
                $stmt->close();
                if ($errno === 1062) throw new FederationException('Ese nombre FederationCloud ya pertenece a otro Node ID.', 409);
                throw new FederationException('No se pudo actualizar el nodo FederationCloud: ' . $message, 500);
            }
            $stmt->close();
            return;
        }

        if ($nodeName !== null) $this->assertNodeNameAvailable($nodeName, $nodeId);
        $stmt = $this->db->prepare(
            "INSERT INTO FederationNodes
                (NodeId, NodeName, PublicKey, PublicUrl, FederationUrl, Status, FirstSeen, LastSeen)
             VALUES (?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar el registro FederationNodes.', 500);
        $stmt->bind_param('sssss', $nodeId, $nodeName, $publicKey, $publicUrl, $federationUrl);
        if (!$stmt->execute()) {
            $errno = $stmt->errno;
            $message = $stmt->error;
            $stmt->close();
            if ($errno === 1062) throw new FederationException('El Node ID o nombre FederationCloud ya está registrado.', 409);
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
        if (!$result) throw new FederationException('No se pudo consultar el directorio FederationCloud.', 500);
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
        if (!$stmt) throw new FederationException('No se pudo consultar la identidad FederationCloud registrada.', 500);
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
