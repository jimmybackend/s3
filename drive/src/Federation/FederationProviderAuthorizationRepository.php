<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationProviderAuthorizationRepository
{
    private const DEFAULT_AVAILABILITY_SECONDS = 900;

    public function __construct(private mysqli $db)
    {
    }

    public function request(string $originNodeId, string $providerNodeId, string $role, string $scope): string
    {
        $existing = $this->find($originNodeId, $providerNodeId);
        if ($existing !== null) {
            $status = (string)$existing['Status'];
            if ($status === 'blocked') {
                throw new FederationException('Este nodo proveedor está bloqueado por el nodo origen.', 403);
            }
            if ($status === 'active') {
                $this->touchActive($originNodeId, $providerNodeId);
                return 'active';
            }

            $stmt = $this->db->prepare(
                "UPDATE FederationNodeAuthorizations
                 SET Role = ?, Scope = ?, Status = 'pending', OriginSignature = NULL,
                     RequestedAt = UTC_TIMESTAMP(), AuthorizedAt = NULL,
                     LastSeen = UTC_TIMESTAMP(), RevokedAt = NULL
                 WHERE OriginNodeId = ? AND ProviderNodeId = ? LIMIT 1"
            );
            if (!$stmt) {
                throw new FederationException('No se pudo preparar la renovación de solicitud de proveedor.', 500);
            }
            $stmt->bind_param('ssss', $role, $scope, $originNodeId, $providerNodeId);
            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                throw new FederationException('No se pudo renovar la solicitud de proveedor: ' . $message, 500);
            }
            $stmt->close();
            return 'pending';
        }

        $stmt = $this->db->prepare(
            "INSERT INTO FederationNodeAuthorizations
                (OriginNodeId, ProviderNodeId, Role, Scope, Status, RequestedAt, LastSeen)
             VALUES (?, ?, ?, ?, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar la solicitud de proveedor FederationCloud.', 500);
        }
        $stmt->bind_param('ssss', $originNodeId, $providerNodeId, $role, $scope);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar la solicitud de proveedor: ' . $message, 500);
        }
        $stmt->close();
        return 'pending';
    }

    public function pendingForOrigin(string $originNodeId): array
    {
        return $this->listForOrigin($originNodeId, 'pending');
    }

    /**
     * Réplicas que están autorizadas Y han confirmado presencia recientemente.
     * Es el conjunto seguro para asignar trabajo físico ahora mismo.
     */
    public function activeForOrigin(string $originNodeId): array
    {
        return $this->activeAvailableForOrigin($originNodeId, self::DEFAULT_AVAILABILITY_SECONDS);
    }

    /** Autorizaciones permanentes aunque la réplica esté temporalmente apagada. */
    public function allActiveForOrigin(string $originNodeId): array
    {
        return $this->listForOrigin($originNodeId, 'active');
    }

    public function activeAvailableForOrigin(string $originNodeId, int $freshSeconds = self::DEFAULT_AVAILABILITY_SECONDS): array
    {
        $freshSeconds = max(60, min(86400, $freshSeconds));
        $stmt = $this->db->prepare(
            "SELECT a.id_, a.OriginNodeId, a.ProviderNodeId, a.Role, a.Scope, a.Status,
                    a.OriginSignature, a.RequestedAt, a.AuthorizedAt, a.LastSeen, a.RevokedAt,
                    n.NodeName, n.PublicKey, n.PublicUrl, n.FederationUrl
             FROM FederationNodeAuthorizations a
             INNER JOIN FederationNodes n ON n.NodeId = a.ProviderNodeId
             WHERE a.OriginNodeId = ? AND a.Status = 'active'
               AND a.LastSeen IS NOT NULL
               AND TIMESTAMPDIFF(SECOND, a.LastSeen, UTC_TIMESTAMP()) <= ?
             ORDER BY a.LastSeen DESC, a.id_ ASC
             LIMIT 100"
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar el listado de réplicas disponibles.', 500);
        }
        $stmt->bind_param('si', $originNodeId, $freshSeconds);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo listar réplicas disponibles: ' . $message, 500);
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        $stmt->close();
        return $rows;
    }

    public function find(string $originNodeId, string $providerNodeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id_, OriginNodeId, ProviderNodeId, Role, Scope, Status, OriginSignature, RequestedAt, AuthorizedAt, LastSeen, RevokedAt
             FROM FederationNodeAuthorizations
             WHERE OriginNodeId = ? AND ProviderNodeId = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new FederationException('No se pudo consultar la autorización de proveedor.', 500);
        }
        $stmt->bind_param('ss', $originNodeId, $providerNodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo consultar proveedores: ' . $message, 500);
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function approve(
        string $originNodeId,
        string $providerNodeId,
        string $role,
        string $scope,
        string $originSignature
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE FederationNodeAuthorizations
             SET Role = ?, Scope = ?, Status = 'active', OriginSignature = ?,
                 AuthorizedAt = UTC_TIMESTAMP(), LastSeen = UTC_TIMESTAMP(), RevokedAt = NULL
             WHERE OriginNodeId = ? AND ProviderNodeId = ? AND Status = 'pending' LIMIT 1"
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar la aprobación del proveedor.', 500);
        }
        $stmt->bind_param('sssss', $role, $scope, $originSignature, $originNodeId, $providerNodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo aprobar el proveedor: ' . $message, 500);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new FederationException('La solicitud ya no está pendiente o no existe.', 409);
        }
    }

    public function reject(string $originNodeId, string $providerNodeId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationNodeAuthorizations
             SET Status = 'revoked', OriginSignature = NULL,
                 AuthorizedAt = NULL, RevokedAt = UTC_TIMESTAMP(), LastSeen = UTC_TIMESTAMP()
             WHERE OriginNodeId = ? AND ProviderNodeId = ? AND Status = 'pending' LIMIT 1"
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar el rechazo del proveedor.', 500);
        }
        $stmt->bind_param('ss', $originNodeId, $providerNodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo rechazar el proveedor: ' . $message, 500);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new FederationException('La solicitud ya no está pendiente o no existe.', 409);
        }
    }

    public function revoke(string $originNodeId, string $providerNodeId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationNodeAuthorizations
             SET Status = 'revoked', OriginSignature = NULL,
                 RevokedAt = UTC_TIMESTAMP(), LastSeen = UTC_TIMESTAMP()
             WHERE OriginNodeId = ? AND ProviderNodeId = ? AND Status = 'active' LIMIT 1"
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar la revocación del proveedor.', 500);
        }
        $stmt->bind_param('ss', $originNodeId, $providerNodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo revocar el proveedor: ' . $message, 500);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new FederationException('El proveedor no está activo o no existe.', 409);
        }
    }

    public function touchActive(string $originNodeId, string $providerNodeId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationNodeAuthorizations
             SET LastSeen = UTC_TIMESTAMP()
             WHERE OriginNodeId = ? AND ProviderNodeId = ? AND Status = 'active' LIMIT 1"
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar la actualización de presencia de la réplica.', 500);
        }
        $stmt->bind_param('ss', $originNodeId, $providerNodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo actualizar la presencia de la réplica: ' . $message, 500);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            // MySQL puede reportar 0 si LastSeen ya tenía exactamente el mismo segundo.
            $current = $this->find($originNodeId, $providerNodeId);
            if ($current === null || (string)$current['Status'] !== 'active') {
                throw new FederationException('La réplica no tiene una autorización activa.', 409);
            }
        }
    }

    private function listForOrigin(string $originNodeId, string $status): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id_, a.OriginNodeId, a.ProviderNodeId, a.Role, a.Scope, a.Status,
                    a.OriginSignature, a.RequestedAt, a.AuthorizedAt, a.LastSeen, a.RevokedAt,
                    n.NodeName, n.PublicKey, n.PublicUrl, n.FederationUrl
             FROM FederationNodeAuthorizations a
             INNER JOIN FederationNodes n ON n.NodeId = a.ProviderNodeId
             WHERE a.OriginNodeId = ? AND a.Status = ?
             ORDER BY a.RequestedAt ASC, a.id_ ASC
             LIMIT 100'
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar el listado de proveedores.', 500);
        }
        $stmt->bind_param('ss', $originNodeId, $status);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo listar proveedores: ' . $message, 500);
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
        return $rows;
    }
}
