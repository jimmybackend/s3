<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationEventCodec
{
    public const VERSION = 1;
    private const TYPES = [
        'node.upsert',
        'resource.upsert',
        'resource.tombstone',
        'location.upsert',
        'location.tombstone',
    ];

    public function create(
        NodeIdentityService $identity,
        int $sequence,
        string $eventType,
        string $entityId,
        array $payload,
        ?string $issuedAt = null
    ): array {
        if ($sequence <= 0) throw new FederationException('Secuencia federada inválida.', 500);
        $eventType = trim($eventType);
        $entityId = trim($entityId);
        if (!in_array($eventType, self::TYPES, true)) throw new FederationException('Tipo de evento federado no permitido.', 400);
        if ($entityId === '' || strlen($entityId) > 128 || preg_match('/[\x00-\x1F\x7F]/', $entityId)) {
            throw new FederationException('Entidad federada inválida.', 400);
        }

        $unsigned = [
            'version' => self::VERSION,
            'origin_node_id' => $identity->nodeId(),
            'origin_sequence' => $sequence,
            'event_type' => $eventType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'issued_at' => $issuedAt ?: gmdate(DATE_ATOM),
            'public_key' => $identity->publicKeyEncoded(),
        ];
        $eventId = 'fge_' . FederationCodec::base64UrlEncode(
            hash('sha256', FederationCodec::canonicalJson($unsigned), true)
        );
        $signed = $unsigned;
        $signed['event_id'] = $eventId;
        $signed['signature'] = FederationCodec::base64UrlEncode(
            $identity->sign(FederationCodec::canonicalJson($signed))
        );
        return $signed;
    }

    public function verify(array $event): array
    {
        if ((int)($event['version'] ?? 0) !== self::VERSION) throw new FederationException('Versión de evento federado no soportada.', 400);
        $origin = (string)($event['origin_node_id'] ?? '');
        $sequence = (int)($event['origin_sequence'] ?? 0);
        $type = (string)($event['event_type'] ?? '');
        $entityId = (string)($event['entity_id'] ?? '');
        $issuedAt = (string)($event['issued_at'] ?? '');
        $publicKeyEncoded = (string)($event['public_key'] ?? '');
        $eventId = (string)($event['event_id'] ?? '');
        $signatureEncoded = (string)($event['signature'] ?? '');
        $payload = $event['payload'] ?? null;

        if (!preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $origin)
            || $sequence <= 0
            || !in_array($type, self::TYPES, true)
            || $entityId === ''
            || strlen($entityId) > 128
            || !is_array($payload)
            || array_is_list($payload)
            || $issuedAt === ''
            || strlen($issuedAt) > 64
            || !preg_match('/\Afge_[A-Za-z0-9_-]{32,80}\z/', $eventId)) {
            throw new FederationException('Evento federado incompleto o inválido.', 400);
        }
        $timestamp = strtotime($issuedAt);
        if ($timestamp === false || $timestamp > time() + 300) throw new FederationException('Fecha de evento federado inválida.', 400);

        $publicKey = FederationCodec::base64UrlDecode($publicKeyEncoded);
        $signature = FederationCodec::base64UrlDecode($signatureEncoded);
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new FederationException('Firma de evento federado inválida.', 400);
        }
        $expectedNodeId = NodeIdentityService::nodeIdFromPublicKey($publicKey);
        if (!hash_equals($expectedNodeId, $origin)) throw new FederationException('El evento no pertenece a la clave pública anunciada.', 409);

        $unsigned = [
            'version' => self::VERSION,
            'origin_node_id' => $origin,
            'origin_sequence' => $sequence,
            'event_type' => $type,
            'entity_id' => $entityId,
            'payload' => $payload,
            'issued_at' => $issuedAt,
            'public_key' => $publicKeyEncoded,
        ];
        $expectedEventId = 'fge_' . FederationCodec::base64UrlEncode(
            hash('sha256', FederationCodec::canonicalJson($unsigned), true)
        );
        if (!hash_equals($expectedEventId, $eventId)) throw new FederationException('Event ID federado inconsistente.', 409);

        $signed = $unsigned;
        $signed['event_id'] = $eventId;
        if (!NodeIdentityService::verify(FederationCodec::canonicalJson($signed), $signature, $publicKey)) {
            throw new FederationException('Firma Ed25519 de evento federado inválida.', 409);
        }
        $signed['signature'] = $signatureEncoded;
        return $signed;
    }
}
