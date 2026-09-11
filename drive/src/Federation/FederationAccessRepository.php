<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;
use mysqli;

final class FederationAccessRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function storeOutgoing(int $userId, array $request, string $remoteNodeId, string $remoteUrl): void
    {
        $this->insertRequest('outgoing', $userId, $request, $remoteNodeId, $remoteUrl, 'queued');
    }

    public function storeIncoming(int $ownerUserId, array $request): void
    {
        $this->insertRequest(
            'incoming',
            $ownerUserId,
            $request,
            (string)$request['requester_node_id'],
            (string)$request['requester_federation_url'],
            'pending'
        );
    }

    public function find(string $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT RequestId, Direction, ResourceId, LocalUserId, RemoteNodeId, RemoteFederationUrl, Status, RequestJson, DecisionJson, '
            . 'RequestedAt, ExpiresAt, LastAttemptAt, NextAttemptAt, Attempts, LastError, UpdatedAt '
            . 'FROM FederationAccessRequests WHERE RequestId=? LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo consultar solicitud FederationCloud.', 500);
        $stmt->bind_param('s', $requestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function markDelivered(string $requestId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationAccessRequests SET Status='pending', LastAttemptAt=UTC_TIMESTAMP(6), NextAttemptAt=NULL, Attempts=Attempts+1, LastError=NULL WHERE RequestId=? AND Direction='outgoing' AND Status IN ('queued','pending','failed')"
        );
        if ($stmt) {
            $stmt->bind_param('s', $requestId);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function markRetry(string $requestId, string $error): void
    {
        $row = $this->find($requestId);
        if ($row === null) return;
        $attempts = (int)$row['Attempts'] + 1;
        $delay = min(3600, 30 * (2 ** min(7, max(0, $attempts - 1))));
        $next = gmdate('Y-m-d H:i:s', time() + $delay);
        $error = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error);
        $error = function_exists('mb_substr') ? mb_substr($error, 0, 512) : substr($error, 0, 512);
        $stmt = $this->db->prepare(
            "UPDATE FederationAccessRequests SET Status='queued', LastAttemptAt=UTC_TIMESTAMP(6), NextAttemptAt=?, Attempts=?, LastError=? WHERE RequestId=? AND Direction='outgoing' AND Status NOT IN ('approved','rejected','expired')"
        );
        if (!$stmt) return;
        $stmt->bind_param('siss', $next, $attempts, $error, $requestId);
        $stmt->execute();
        $stmt->close();
    }

    public function completeOutgoing(string $requestId, array $decision, string $title, string $mediaType): void
    {
        $decisionJson = json_encode($decision, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $status = (string)$decision['decision'];
        $stmt = $this->db->prepare(
            'UPDATE FederationAccessRequests SET Status=?, DecisionJson=?, LastAttemptAt=UTC_TIMESTAMP(6), NextAttemptAt=NULL, LastError=NULL WHERE RequestId=? AND Direction=\'outgoing\''
        );
        if (!$stmt) throw new FederationException('No se pudo cerrar solicitud saliente.', 500);
        $stmt->bind_param('sss', $status, $decisionJson, $requestId);
        $stmt->execute();
        $stmt->close();

        if ($status !== 'approved') return;
        $row = $this->find($requestId);
        if ($row === null) return;
        $this->upsertShare([
            'share_id' => $requestId,
            'user_id' => (int)$row['LocalUserId'],
            'direction' => 'received',
            'resource_id' => (string)$row['ResourceId'],
            'remote_node_id' => (string)$row['RemoteNodeId'],
            'title' => $title,
            'media_type' => $mediaType,
            'access_url' => (string)($decision['access_url'] ?? ''),
            'decision_json' => $decisionJson,
            'expires_at' => (string)($decision['expires_at'] ?? ''),
        ]);
    }

    public function decideIncoming(string $requestId, int $ownerUserId, array $decision, string $title, string $mediaType): void
    {
        $decisionJson = json_encode($decision, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $status = (string)$decision['decision'];
        $stmt = $this->db->prepare(
            'UPDATE FederationAccessRequests SET Status=?, DecisionJson=?, UpdatedAt=UTC_TIMESTAMP(6) WHERE RequestId=? AND Direction=\'incoming\' AND LocalUserId=?'
        );
        if (!$stmt) throw new FederationException('No se pudo guardar decisión FederationCloud.', 500);
        $stmt->bind_param('sssi', $status, $decisionJson, $requestId, $ownerUserId);
        if (!$stmt->execute() || $stmt->affected_rows < 1) {
            $stmt->close();
            throw new FederationException('Solicitud de acceso no encontrada para este usuario.', 404);
        }
        $stmt->close();

        if ($status !== 'approved') return;
        $row = $this->find($requestId);
        if ($row === null) return;
        $this->upsertShare([
            'share_id' => $requestId,
            'user_id' => $ownerUserId,
            'direction' => 'sent',
            'resource_id' => (string)$row['ResourceId'],
            'remote_node_id' => (string)$row['RemoteNodeId'],
            'title' => $title,
            'media_type' => $mediaType,
            'access_url' => null,
            'decision_json' => $decisionJson,
            'expires_at' => (string)($decision['expires_at'] ?? ''),
        ]);
    }

    public function incomingForUser(int $userId): array
    {
        return $this->requestsForUser($userId, 'incoming');
    }

    public function outgoingForUser(int $userId): array
    {
        return $this->requestsForUser($userId, 'outgoing');
    }

    public function sharesForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ShareId, Direction, ResourceId, RemoteNodeId, Title, MediaType, Status, AccessUrl, ExpiresAt, CreatedAt, UpdatedAt
             FROM FederationShares WHERE UserId=? ORDER BY UpdatedAt DESC, ShareId ASC LIMIT 200"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar Shares FederationCloud.', 500);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $expired = $row['ExpiresAt'] !== null && strtotime((string)$row['ExpiresAt']) < time();
            $rows[] = [
                'share_id' => (string)$row['ShareId'],
                'direction' => (string)$row['Direction'],
                'resource_id' => (string)$row['ResourceId'],
                'remote_node_id' => (string)$row['RemoteNodeId'],
                'title' => (string)$row['Title'],
                'media_type' => (string)$row['MediaType'],
                'status' => $expired && (string)$row['Status'] === 'active' ? 'expired' : (string)$row['Status'],
                'access_url' => (string)$row['Direction'] === 'received' && !$expired && is_string($row['AccessUrl'] ?? null) ? (string)$row['AccessUrl'] : null,
                'expires_at' => $row['ExpiresAt'] !== null ? (string)$row['ExpiresAt'] : null,
                'created_at' => (string)$row['CreatedAt'],
                'updated_at' => (string)$row['UpdatedAt'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    public function pendingOutgoing(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $sql = "SELECT RequestId, ResourceId, LocalUserId, RemoteNodeId, RemoteFederationUrl, Status, RequestJson, Attempts
                FROM FederationAccessRequests
                WHERE Direction='outgoing'
                  AND Status IN ('queued','pending')
                  AND ExpiresAt > UTC_TIMESTAMP(6)
                  AND (NextAttemptAt IS NULL OR NextAttemptAt <= UTC_TIMESTAMP(6))
                ORDER BY UpdatedAt ASC LIMIT {$limit}";
        $result = $this->db->query($sql);
        if (!$result) throw new FederationException('No se pudieron consultar solicitudes pendientes.', 500);
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public function expireOld(): int
    {
        $this->db->query("UPDATE FederationAccessRequests SET Status='expired' WHERE Status IN ('queued','pending') AND ExpiresAt <= UTC_TIMESTAMP(6)");
        $changed = max(0, $this->db->affected_rows);
        $this->db->query("UPDATE FederationShares SET Status='expired' WHERE Status='active' AND ExpiresAt IS NOT NULL AND ExpiresAt <= UTC_TIMESTAMP(6)");
        return $changed;
    }

    public function decodeRequest(array $row): array
    {
        return $this->decodeJson((string)($row['RequestJson'] ?? ''), 'solicitud');
    }

    public function decodeDecision(array $row): ?array
    {
        $json = (string)($row['DecisionJson'] ?? '');
        return $json === '' ? null : $this->decodeJson($json, 'decisión');
    }

    private function insertRequest(string $direction, int $userId, array $request, string $remoteNodeId, string $remoteUrl, string $status): void
    {
        $requestId = (string)$request['request_id'];
        $resourceId = (string)$request['resource_id'];
        $requestJson = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $requestedAt = gmdate('Y-m-d H:i:s', strtotime((string)$request['requested_at']) ?: time());
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime((string)$request['expires_at']) ?: time());
        $stmt = $this->db->prepare(
            'INSERT INTO FederationAccessRequests '
            . '(RequestId, Direction, ResourceId, LocalUserId, RemoteNodeId, RemoteFederationUrl, Status, RequestJson, RequestedAt, ExpiresAt) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE RequestJson=IF(RequestJson=VALUES(RequestJson), RequestJson, RequestJson), UpdatedAt=UTC_TIMESTAMP(6)'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar solicitud FederationCloud.', 500);
        $stmt->bind_param('sssissssss', $requestId, $direction, $resourceId, $userId, $remoteNodeId, $remoteUrl, $status, $requestJson, $requestedAt, $expiresAt);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar solicitud FederationCloud: ' . $message, 500);
        }
        $stmt->close();
    }

    private function requestsForUser(int $userId, string $direction): array
    {
        $stmt = $this->db->prepare(
            'SELECT RequestId, ResourceId, RemoteNodeId, RemoteFederationUrl, Status, RequestedAt, ExpiresAt, LastError, UpdatedAt '
            . 'FROM FederationAccessRequests WHERE LocalUserId=? AND Direction=? ORDER BY UpdatedAt DESC LIMIT 200'
        );
        if (!$stmt) throw new FederationException('No se pudieron consultar solicitudes FederationCloud.', 500);
        $stmt->bind_param('is', $userId, $direction);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'request_id' => (string)$row['RequestId'],
                'resource_id' => (string)$row['ResourceId'],
                'remote_node_id' => (string)$row['RemoteNodeId'],
                'remote_federation_url' => (string)$row['RemoteFederationUrl'],
                'status' => (string)$row['Status'],
                'requested_at' => (string)$row['RequestedAt'],
                'expires_at' => (string)$row['ExpiresAt'],
                'last_error' => $row['LastError'] !== null ? (string)$row['LastError'] : null,
                'updated_at' => (string)$row['UpdatedAt'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    private function upsertShare(array $share): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO FederationShares
                (ShareId, UserId, Direction, ResourceId, RemoteNodeId, Title, MediaType, Status, AccessUrl, DecisionJson, ExpiresAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)
             ON DUPLICATE KEY UPDATE Title=VALUES(Title), MediaType=VALUES(MediaType), Status='active',
                 AccessUrl=VALUES(AccessUrl), DecisionJson=VALUES(DecisionJson), ExpiresAt=VALUES(ExpiresAt), UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar Share FederationCloud.', 500);
        $shareId = (string)$share['share_id'];
        $userId = (int)$share['user_id'];
        $direction = (string)$share['direction'];
        $resourceId = (string)$share['resource_id'];
        $remoteNodeId = (string)$share['remote_node_id'];
        $title = (string)$share['title'];
        $mediaType = (string)$share['media_type'];
        $accessUrl = $share['access_url'] !== null ? (string)$share['access_url'] : null;
        $decisionJson = (string)$share['decision_json'];
        $expiresAt = trim((string)$share['expires_at']);
        $expiresAt = $expiresAt !== '' ? gmdate('Y-m-d H:i:s', strtotime($expiresAt) ?: time()) : null;
        $stmt->bind_param('sissssssss', $shareId, $userId, $direction, $resourceId, $remoteNodeId, $title, $mediaType, $accessUrl, $decisionJson, $expiresAt);
        $stmt->execute();
        $stmt->close();
    }

    private function decodeJson(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('JSON de ' . $label . ' FederationCloud inválido.', 500);
        }
        if (!is_array($decoded) || array_is_list($decoded)) throw new FederationException('Documento de ' . $label . ' inválido.', 500);
        return $decoded;
    }
}
