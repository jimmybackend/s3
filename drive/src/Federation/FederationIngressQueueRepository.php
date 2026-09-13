<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;
use Throwable;

final class FederationIngressQueueRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function enqueue(
        string $requestId,
        string $requestType,
        string $originNodeId,
        ?string $targetNodeId,
        array $documentation,
        int $priority = 100
    ): array {
        $requestId = trim($requestId);
        $requestType = trim($requestType);
        $originNodeId = trim($originNodeId);
        $targetNodeId = $targetNodeId !== null && trim($targetNodeId) !== '' ? trim($targetNodeId) : null;
        if (!preg_match('/\Afcq_[A-Za-z0-9_-]{20,80}\z/', $requestId)) {
            throw new FederationException('Request ID de aduana inválido.', 400);
        }
        if (!in_array($requestType, ['node_presence', 'shared_backend_authorization'], true)) {
            throw new FederationException('Tipo de petición FederationCloud no permitido en aduana.', 400);
        }
        if (!preg_match('/\Aacn_[A-Za-z0-9_-]{20,64}\z/', $originNodeId)) {
            throw new FederationException('Node ID de origen inválido para aduana.', 400);
        }
        if ($targetNodeId !== null && !preg_match('/\Aacn_[A-Za-z0-9_-]{20,64}\z/', $targetNodeId)) {
            throw new FederationException('Node ID destino inválido para aduana.', 400);
        }
        $priority = max(1, min(1000, $priority));
        $json = json_encode($documentation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > ArcadeLinkService::MAX_BYTES) {
            throw new FederationException('Documentación de aduana demasiado grande.', 413);
        }
        $hash = hash('sha256', $json);

        $existing = $this->findByRequestId($requestId);
        if ($existing !== null) {
            if (!hash_equals((string)$existing['PayloadHash'], $hash)
                || !hash_equals((string)$existing['RequestType'], $requestType)
                || !hash_equals((string)$existing['OriginNodeId'], $originNodeId)) {
                throw new FederationException('Request ID ya existe con otra documentación.', 409);
            }
            return $this->publicRow($existing);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO FederationIngressQueue
                (RequestId, RequestType, OriginNodeId, TargetNodeId, DocumentationJson, PayloadHash, Priority, Status, ReceivedAt, AvailableAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la cola de aduana FederationCloud.', 500);
        $stmt->bind_param('ssssssi', $requestId, $requestType, $originNodeId, $targetNodeId, $json, $hash, $priority);
        if (!$stmt->execute()) {
            $errno = $stmt->errno;
            $message = $stmt->error;
            $stmt->close();
            if ($errno === 1062) {
                $existing = $this->findByRequestId($requestId);
                if ($existing !== null) return $this->publicRow($existing);
            }
            throw new FederationException('No se pudo encolar la petición FederationCloud: ' . $message, 500);
        }
        $id = (int)$stmt->insert_id;
        $stmt->close();
        $row = $this->findById($id);
        if ($row === null) throw new FederationException('La petición encolada no pudo releerse.', 500);
        return $this->publicRow($row);
    }

    public function recoverStale(int $minutes = 15): int
    {
        $minutes = max(5, min(120, $minutes));
        $sql = "UPDATE FederationIngressQueue
                SET Status='retry', AvailableAt=UTC_TIMESTAMP(6), StartedAt=NULL,
                    LastError='Worker interrumpido; petición recuperada automáticamente.'
                WHERE Status='processing'
                  AND StartedAt < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL {$minutes} MINUTE)";
        if (!$this->db->query($sql)) {
            throw new FederationException('No se pudo recuperar la cola de aduana.', 500);
        }
        return max(0, $this->db->affected_rows);
    }

    public function claimNext(): ?array
    {
        $this->db->begin_transaction();
        try {
            $result = $this->db->query(
                "SELECT id_, RequestId, RequestType, OriginNodeId, TargetNodeId, DocumentationJson,
                        PayloadHash, Priority, Status, Attempts, ReceivedAt, AvailableAt, StartedAt, FinishedAt, LastError
                 FROM FederationIngressQueue
                 WHERE Status IN ('queued','retry') AND AvailableAt <= UTC_TIMESTAMP(6)
                 ORDER BY Priority ASC, ReceivedAt ASC, id_ ASC
                 LIMIT 1 FOR UPDATE"
            );
            if (!$result) throw new FederationException('No se pudo seleccionar la siguiente petición de aduana.', 500);
            $row = $result->fetch_assoc();
            $result->free();
            if (!is_array($row)) {
                $this->db->commit();
                return null;
            }

            $id = (int)$row['id_'];
            $stmt = $this->db->prepare(
                "UPDATE FederationIngressQueue
                 SET Status='processing', Attempts=Attempts+1, StartedAt=UTC_TIMESTAMP(6), LastError=NULL
                 WHERE id_=? AND Status IN ('queued','retry') LIMIT 1"
            );
            if (!$stmt) throw new FederationException('No se pudo preparar el claim de aduana.', 500);
            $stmt->bind_param('i', $id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $message = $stmt->error;
                $stmt->close();
                throw new FederationException('No se pudo reclamar la petición de aduana: ' . $message, 500);
            }
            $stmt->close();
            $this->db->commit();
            $row['Attempts'] = ((int)$row['Attempts']) + 1;
            $row['Status'] = 'processing';
            return $row;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function complete(int $id): void
    {
        $this->finish($id, 'done', null, 0);
    }

    public function reject(int $id, string $message): void
    {
        $this->finish($id, 'rejected', $message, 0);
    }

    public function fail(int $id, string $message): void
    {
        $this->finish($id, 'failed', $message, 0);
    }

    public function retry(int $id, string $message, int $delaySeconds): void
    {
        $delaySeconds = max(30, min(3600, $delaySeconds));
        $stmt = $this->db->prepare(
            "UPDATE FederationIngressQueue
             SET Status='retry', AvailableAt=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND),
                 StartedAt=NULL, LastError=?
             WHERE id_=? AND Status='processing' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar retry de aduana.', 500);
        $message = $this->safeError($message);
        $stmt->bind_param('isi', $delaySeconds, $message, $id);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo reprogramar petición de aduana: ' . $error, 500);
        }
        $stmt->close();
    }

    public function queuedCount(): int
    {
        $result = $this->db->query("SELECT COUNT(*) AS c FROM FederationIngressQueue WHERE Status IN ('queued','retry','processing')");
        if (!$result) return 0;
        $row = $result->fetch_assoc();
        $result->free();
        return max(0, (int)($row['c'] ?? 0));
    }

    private function finish(int $id, string $status, ?string $message, int $unused): void
    {
        unset($unused);
        $message = $message !== null ? $this->safeError($message) : null;
        $stmt = $this->db->prepare(
            "UPDATE FederationIngressQueue
             SET Status=?, FinishedAt=UTC_TIMESTAMP(6), LastError=?, StartedAt=NULL
             WHERE id_=? AND Status='processing' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar cierre de aduana.', 500);
        $stmt->bind_param('ssi', $status, $message, $id);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo cerrar petición de aduana: ' . $error, 500);
        }
        $stmt->close();
    }

    private function findByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id_, RequestId, RequestType, OriginNodeId, TargetNodeId, DocumentationJson, PayloadHash,
                    Priority, Status, Attempts, ReceivedAt, AvailableAt, StartedAt, FinishedAt, LastError
             FROM FederationIngressQueue WHERE RequestId=? LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo consultar Request ID de aduana.', 500);
        $stmt->bind_param('s', $requestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id_, RequestId, RequestType, OriginNodeId, TargetNodeId, DocumentationJson, PayloadHash,
                    Priority, Status, Attempts, ReceivedAt, AvailableAt, StartedAt, FinishedAt, LastError
             FROM FederationIngressQueue WHERE id_=? LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo consultar petición de aduana.', 500);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function publicRow(array $row): array
    {
        return [
            'queue_id' => (int)$row['id_'],
            'request_id' => (string)$row['RequestId'],
            'type' => (string)$row['RequestType'],
            'status' => (string)$row['Status'],
            'attempts' => (int)$row['Attempts'],
            'received_at' => (string)$row['ReceivedAt'],
        ];
    }

    private function safeError(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if ($message === '') return 'Error FederationCloud no especificado.';
        return function_exists('mb_substr') ? mb_substr($message, 0, 512) : substr($message, 0, 512);
    }
}
