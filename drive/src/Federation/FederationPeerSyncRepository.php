<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationPeerSyncRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function candidates(string $localNodeId, int $scanLimit = 50): array
    {
        $scanLimit = max(1, min(100, $scanLimit));
        $sql = "SELECT n.NodeId, n.NodeName, n.FederationUrl, n.Status, n.LastSeen,
                       s.LastSuccessAt, s.ConsecutiveFailures, s.NextAttemptAt
                FROM FederationNodes n
                LEFT JOIN FederationPeerSyncState s ON s.PeerNodeId = n.NodeId
                WHERE n.NodeId <> ?
                  AND n.Status <> 'blocked'
                  AND (s.NextAttemptAt IS NULL OR s.NextAttemptAt <= UTC_TIMESTAMP(6))
                ORDER BY COALESCE(s.LastSuccessAt, '1970-01-01 00:00:00') ASC, n.LastSeen DESC
                LIMIT {$scanLimit}";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new FederationException('No se pudo preparar selección de peers federados.', 500);
        $stmt->bind_param('s', $localNodeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'node_id' => (string)$row['NodeId'],
                'node_name' => is_string($row['NodeName'] ?? null) && $row['NodeName'] !== '' ? (string)$row['NodeName'] : null,
                'federation_url' => (string)$row['FederationUrl'],
                'status' => (string)$row['Status'],
                'last_seen' => (string)$row['LastSeen'],
                'last_success_at' => $row['LastSuccessAt'] !== null ? (string)$row['LastSuccessAt'] : null,
                'failures' => (int)($row['ConsecutiveFailures'] ?? 0),
            ];
        }
        $stmt->close();
        return $rows;
    }

    public function success(string $peerNodeId, int $pulled, int $pushed): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO FederationPeerSyncState
                (PeerNodeId, LastAttemptAt, LastSuccessAt, ConsecutiveFailures, NextAttemptAt, LastError, LastPulledEvents, LastPushedEvents)
             VALUES (?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), 0, NULL, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE LastAttemptAt=UTC_TIMESTAMP(6), LastSuccessAt=UTC_TIMESTAMP(6),
                 ConsecutiveFailures=0, NextAttemptAt=NULL, LastError=NULL,
                 LastPulledEvents=VALUES(LastPulledEvents), LastPushedEvents=VALUES(LastPushedEvents)"
        );
        if (!$stmt) throw new FederationException('No se pudo guardar éxito de sincronización.', 500);
        $stmt->bind_param('sii', $peerNodeId, $pulled, $pushed);
        $stmt->execute();
        $stmt->close();

        $seen = $this->db->prepare("UPDATE FederationNodes SET Status='active', LastSeen=UTC_TIMESTAMP() WHERE NodeId=? LIMIT 1");
        if ($seen) {
            $seen->bind_param('s', $peerNodeId);
            $seen->execute();
            $seen->close();
        }
    }

    public function failure(string $peerNodeId, string $error): void
    {
        $current = $this->failureCount($peerNodeId) + 1;
        $delay = min(3600, 30 * (2 ** min(7, $current - 1)));
        $next = gmdate('Y-m-d H:i:s', time() + $delay);
        $error = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error);
        $error = function_exists('mb_substr') ? mb_substr($error, 0, 512) : substr($error, 0, 512);
        $stmt = $this->db->prepare(
            "INSERT INTO FederationPeerSyncState
                (PeerNodeId, LastAttemptAt, ConsecutiveFailures, NextAttemptAt, LastError)
             VALUES (?, UTC_TIMESTAMP(6), ?, ?, ?)
             ON DUPLICATE KEY UPDATE LastAttemptAt=UTC_TIMESTAMP(6), ConsecutiveFailures=VALUES(ConsecutiveFailures),
                 NextAttemptAt=VALUES(NextAttemptAt), LastError=VALUES(LastError)"
        );
        if (!$stmt) return;
        $stmt->bind_param('siss', $peerNodeId, $current, $next, $error);
        $stmt->execute();
        $stmt->close();
    }

    public function state(): array
    {
        $result = $this->db->query(
            'SELECT PeerNodeId, LastAttemptAt, LastSuccessAt, ConsecutiveFailures, NextAttemptAt, LastError, LastPulledEvents, LastPushedEvents '
            . 'FROM FederationPeerSyncState ORDER BY COALESCE(LastSuccessAt, LastAttemptAt) DESC, PeerNodeId ASC LIMIT 100'
        );
        if (!$result) throw new FederationException('No se pudo consultar estado de peers federados.', 500);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'peer_node_id' => (string)$row['PeerNodeId'],
                'last_attempt_at' => $row['LastAttemptAt'] !== null ? (string)$row['LastAttemptAt'] : null,
                'last_success_at' => $row['LastSuccessAt'] !== null ? (string)$row['LastSuccessAt'] : null,
                'consecutive_failures' => (int)$row['ConsecutiveFailures'],
                'next_attempt_at' => $row['NextAttemptAt'] !== null ? (string)$row['NextAttemptAt'] : null,
                'last_error' => $row['LastError'] !== null ? (string)$row['LastError'] : null,
                'last_pulled_events' => (int)$row['LastPulledEvents'],
                'last_pushed_events' => (int)$row['LastPushedEvents'],
            ];
        }
        $result->free();
        return $rows;
    }

    private function failureCount(string $peerNodeId): int
    {
        $stmt = $this->db->prepare('SELECT ConsecutiveFailures FROM FederationPeerSyncState WHERE PeerNodeId=? LIMIT 1');
        if (!$stmt) return 0;
        $stmt->bind_param('s', $peerNodeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? (int)$row['ConsecutiveFailures'] : 0;
    }
}
