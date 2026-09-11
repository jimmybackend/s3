<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;
use mysqli;
use Throwable;

final class FederationEventStore
{
    public function __construct(
        private mysqli $db,
        private FederatedCatalogRepository $catalog,
        private FederationEventCodec $codec
    ) {
    }

    public function emit(NodeIdentityService $identity, string $eventType, string $entityId, array $payload): array
    {
        $origin = $identity->nodeId();
        $this->db->begin_transaction();
        try {
            $this->ensureCounter($origin);
            $stmt = $this->db->prepare('SELECT LastSequence FROM FederationOriginCounters WHERE OriginNodeId = ? FOR UPDATE');
            if (!$stmt) throw new FederationException('No se pudo bloquear contador federado.', 500);
            $stmt->bind_param('s', $origin);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $next = max(0, (int)($row['LastSequence'] ?? 0)) + 1;

            $event = $this->codec->create($identity, $next, $eventType, $entityId, $payload);
            $this->insertEvent($event);
            $this->catalog->applyEvent($event);

            $counter = $this->db->prepare('UPDATE FederationOriginCounters SET LastSequence = ? WHERE OriginNodeId = ?');
            if (!$counter) throw new FederationException('No se pudo actualizar contador federado.', 500);
            $counter->bind_param('is', $next, $origin);
            $counter->execute();
            $counter->close();

            $this->ensureClock($origin);
            $this->advanceContiguousClock($origin);
            $this->db->commit();
            return $event;
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof FederationException) throw $e;
            throw new FederationException('No se pudo emitir evento federado.', 500);
        }
    }

    public function ingestMany(array $events): array
    {
        if (count($events) > 50) throw new FederationException('Lote federado demasiado grande.', 413);
        $verified = [];
        foreach ($events as $event) {
            if (!is_array($event) || array_is_list($event)) throw new FederationException('Evento federado de lote inválido.', 400);
            $verified[] = $this->codec->verify($event);
        }

        $inserted = 0;
        $duplicates = 0;
        foreach ($verified as $event) {
            $origin = (string)$event['origin_node_id'];
            $sequence = (int)$event['origin_sequence'];
            $existing = $this->eventAt($origin, $sequence);
            if ($existing !== null) {
                if (!hash_equals((string)$existing['EventId'], (string)$event['event_id'])) {
                    throw new FederationException('Conflicto de secuencia federada firmado.', 409);
                }
                $duplicates++;
                continue;
            }

            $this->db->begin_transaction();
            try {
                $this->insertEvent($event);
                $this->catalog->applyEvent($event);
                $this->ensureClock($origin);
                $this->advanceContiguousClock($origin);
                $this->db->commit();
                $inserted++;
            } catch (Throwable $e) {
                $this->db->rollback();
                if ($e instanceof FederationException) throw $e;
                throw new FederationException('No se pudo aplicar evento federado remoto.', 500);
            }
        }
        return ['inserted' => $inserted, 'duplicates' => $duplicates, 'clock' => $this->clock()];
    }

    public function clock(): array
    {
        $result = $this->db->query('SELECT OriginNodeId, ContiguousSequence FROM FederationClocks ORDER BY OriginNodeId ASC');
        if (!$result) throw new FederationException('No se pudo consultar reloj federado.', 500);
        $clock = [];
        while ($row = $result->fetch_assoc()) {
            $clock[(string)$row['OriginNodeId']] = (int)$row['ContiguousSequence'];
        }
        $result->free();
        return $clock;
    }

    public function exportMissing(array $remoteClock, int $limit = 25): array
    {
        $limit = max(1, min(50, $limit));
        $cleanClock = [];
        foreach ($remoteClock as $nodeId => $sequence) {
            if (!is_string($nodeId) || !preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $nodeId)) continue;
            $cleanClock[$nodeId] = max(0, (int)$sequence);
        }

        $origins = $this->db->query('SELECT OriginNodeId FROM FederationClocks ORDER BY OriginNodeId ASC');
        if (!$origins) throw new FederationException('No se pudieron enumerar orígenes federados.', 500);
        $events = [];
        while (($originRow = $origins->fetch_assoc()) && count($events) < $limit) {
            $origin = (string)$originRow['OriginNodeId'];
            $after = (int)($cleanClock[$origin] ?? 0);
            $remaining = $limit - count($events);
            $sql = "SELECT EventId, OriginNodeId, OriginSequence, EventType, EntityId, PayloadJson, IssuedAt, PublicKey, Signature
                    FROM FederationEvents
                    WHERE OriginNodeId = ? AND OriginSequence > ?
                    ORDER BY OriginSequence ASC LIMIT {$remaining}";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                $origins->free();
                throw new FederationException('No se pudo preparar exportación federada.', 500);
            }
            $stmt->bind_param('si', $origin, $after);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $events[] = $this->rowToEvent($row);
            }
            $stmt->close();
        }
        $origins->free();
        return $events;
    }

    public function latestPayloadHash(string $originNodeId, string $eventType, string $entityId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT PayloadJson FROM FederationEvents WHERE OriginNodeId = ? AND EventType = ? AND EntityId = ? ORDER BY OriginSequence DESC LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo consultar último evento federado.', 500);
        $stmt->bind_param('sss', $originNodeId, $eventType, $entityId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) return null;
        try {
            $payload = json_decode((string)$row['PayloadJson'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($payload) || array_is_list($payload)) return null;
        return hash('sha256', FederationCodec::canonicalJson($payload));
    }

    public function status(): array
    {
        $events = $this->db->query('SELECT COUNT(*) AS c FROM FederationEvents');
        $resources = $this->db->query("SELECT COUNT(*) AS c FROM FederatedResources WHERE Tombstoned = 0 AND DiscoveryPolicy <> 'local_only'");
        $locations = $this->db->query("SELECT COUNT(*) AS c FROM FederationResourceLocations WHERE Status <> 'revoked'");
        if (!$events || !$resources || !$locations) throw new FederationException('No se pudo consultar estado del catálogo federado.', 500);
        $out = [
            'events' => (int)($events->fetch_assoc()['c'] ?? 0),
            'resources' => (int)($resources->fetch_assoc()['c'] ?? 0),
            'locations' => (int)($locations->fetch_assoc()['c'] ?? 0),
            'clock' => $this->clock(),
        ];
        $events->free();
        $resources->free();
        $locations->free();
        return $out;
    }

    private function ensureCounter(string $origin): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO FederationOriginCounters (OriginNodeId, LastSequence) VALUES (?, 0)');
        if (!$stmt) throw new FederationException('No se pudo preparar contador federado.', 500);
        $stmt->bind_param('s', $origin);
        $stmt->execute();
        $stmt->close();
    }

    private function ensureClock(string $origin): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO FederationClocks (OriginNodeId, ContiguousSequence) VALUES (?, 0)');
        if (!$stmt) throw new FederationException('No se pudo preparar reloj federado.', 500);
        $stmt->bind_param('s', $origin);
        $stmt->execute();
        $stmt->close();
    }

    private function advanceContiguousClock(string $origin): void
    {
        $stmt = $this->db->prepare('SELECT ContiguousSequence FROM FederationClocks WHERE OriginNodeId = ? FOR UPDATE');
        if (!$stmt) throw new FederationException('No se pudo bloquear reloj federado.', 500);
        $stmt->bind_param('s', $origin);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $clock = max(0, (int)($row['ContiguousSequence'] ?? 0));

        $lookup = $this->db->prepare('SELECT EventId FROM FederationEvents WHERE OriginNodeId = ? AND OriginSequence = ? LIMIT 1');
        if (!$lookup) throw new FederationException('No se pudo avanzar reloj federado.', 500);
        for ($steps = 0; $steps < 1000; $steps++) {
            $next = $clock + 1;
            $lookup->bind_param('si', $origin, $next);
            $lookup->execute();
            $exists = $lookup->get_result()->fetch_assoc();
            if (!is_array($exists)) break;
            $clock = $next;
        }
        $lookup->close();
        $update = $this->db->prepare('UPDATE FederationClocks SET ContiguousSequence = ? WHERE OriginNodeId = ?');
        if (!$update) throw new FederationException('No se pudo persistir reloj federado.', 500);
        $update->bind_param('is', $clock, $origin);
        $update->execute();
        $update->close();
    }

    private function insertEvent(array $event): void
    {
        $payloadJson = json_encode($event['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($payloadJson) > 65536) throw new FederationException('Payload de evento federado demasiado grande.', 413);
        $issuedAt = (string)$event['issued_at'];
        $stmt = $this->db->prepare(
            'INSERT INTO FederationEvents (EventId, OriginNodeId, OriginSequence, EventType, EntityId, PayloadJson, IssuedAt, PublicKey, Signature, ReceivedAt) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar evento federado.', 500);
        $eventId = (string)$event['event_id'];
        $origin = (string)$event['origin_node_id'];
        $sequence = (int)$event['origin_sequence'];
        $eventType = (string)$event['event_type'];
        $entityId = (string)$event['entity_id'];
        $publicKey = (string)$event['public_key'];
        $signature = (string)$event['signature'];
        $stmt->bind_param('ssissssss', $eventId, $origin, $sequence, $eventType, $entityId, $payloadJson, $issuedAt, $publicKey, $signature);
        if (!$stmt->execute()) {
            $errno = $stmt->errno;
            $message = $stmt->error;
            $stmt->close();
            if ($errno === 1062) throw new FederationException('Evento o secuencia federada duplicada.', 409);
            throw new FederationException('No se pudo insertar evento federado: ' . $message, 500);
        }
        $stmt->close();
    }

    private function eventAt(string $origin, int $sequence): ?array
    {
        $stmt = $this->db->prepare('SELECT EventId FROM FederationEvents WHERE OriginNodeId = ? AND OriginSequence = ? LIMIT 1');
        if (!$stmt) throw new FederationException('No se pudo consultar secuencia federada.', 500);
        $stmt->bind_param('si', $origin, $sequence);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function rowToEvent(array $row): array
    {
        try {
            $payload = json_decode((string)$row['PayloadJson'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('Evento almacenado contiene payload inválido.', 500);
        }
        if (!is_array($payload) || array_is_list($payload)) throw new FederationException('Payload federado almacenado inválido.', 500);
        return [
            'version' => FederationEventCodec::VERSION,
            'origin_node_id' => (string)$row['OriginNodeId'],
            'origin_sequence' => (int)$row['OriginSequence'],
            'event_type' => (string)$row['EventType'],
            'entity_id' => (string)$row['EntityId'],
            'payload' => $payload,
            'issued_at' => (string)$row['IssuedAt'],
            'public_key' => (string)$row['PublicKey'],
            'event_id' => (string)$row['EventId'],
            'signature' => (string)$row['Signature'],
        ];
    }
}
