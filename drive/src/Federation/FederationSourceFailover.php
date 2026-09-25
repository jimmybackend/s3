<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use Throwable;

/**
 * Construye el conjunto de fuentes de un recurso y aplica failover secuencial.
 *
 * No realiza I/O ni decide autorización: el resolver conserva esas validaciones
 * y entrega un callback que intenta cada candidato.
 */
final class FederationSourceFailover
{
    public function __construct(private ?FederationLocationSelector $selector = null)
    {
        $this->selector ??= new FederationLocationSelector();
    }

    /**
     * Garantiza que el origen firmado del recurso forme parte de los candidatos,
     * aunque su location.upsert no se haya materializado todavía.
     */
    public function candidates(array $locations, array $origin): array
    {
        $originNodeId = trim((string)($origin['node_id'] ?? ''));
        $originUrl = trim((string)($origin['federation_url'] ?? ''));
        $hasOrigin = false;

        foreach ($locations as $location) {
            if (!is_array($location)) continue;
            $nodeId = trim((string)($location['node_id'] ?? $location['NodeId'] ?? ''));
            if ($originNodeId !== '' && $nodeId !== '' && hash_equals($originNodeId, $nodeId)) {
                $hasOrigin = true;
                break;
            }
        }

        if (!$hasOrigin && $originNodeId !== '' && $originUrl !== '') {
            $locations[] = [
                'node_id' => $originNodeId,
                'role' => 'origin',
                'status' => 'active',
                'federation_url' => $originUrl,
                'last_seen_at' => $origin['last_seen_at'] ?? null,
                'updated_at' => $origin['updated_at'] ?? null,
            ];
        }

        return $this->selector->ordered($locations);
    }

    /**
     * Intenta candidatos hasta reunir maxSources éxitos o agotarlos.
     * Un fallo nunca impide probar el siguiente candidato.
     *
     * @return array{sources: array<int,array>, failures: array<int,array>}
     */
    public function collect(array $candidates, int $maxSources, callable $attempt): array
    {
        $maxSources = max(1, $maxSources);
        $sources = [];
        $failures = [];
        $seenNodes = [];

        foreach ($candidates as $candidate) {
            if (count($sources) >= $maxSources) break;
            if (!is_array($candidate)) continue;

            $nodeId = trim((string)($candidate['node_id'] ?? ''));
            if ($nodeId === '' || isset($seenNodes[$nodeId])) continue;
            $seenNodes[$nodeId] = true;

            try {
                $source = $attempt($candidate);
                if (!is_array($source)) {
                    throw new FederationException('El intento de fuente no devolvió una respuesta válida.', 502);
                }
                $sources[] = $source;
            } catch (Throwable $e) {
                $failures[] = [
                    'node_id' => $nodeId,
                    'role' => (string)($candidate['role'] ?? ''),
                    'category' => $this->failureCategory($e),
                    'http_status' => $e instanceof FederationException ? $e->httpStatus() : 500,
                    'error' => substr($e->getMessage(), 0, 180),
                ];
            }
        }

        return ['sources' => $sources, 'failures' => $failures];
    }

    private function failureCategory(Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        $status = $e instanceof FederationException ? $e->httpStatus() : 500;

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) return 'timeout';
        if (str_contains($message, 'firma') || str_contains($message, 'descriptor')
            || str_contains($message, 'identidad') || str_contains($message, 'node id')) {
            return 'invalid_identity';
        }
        if ($status === 404 || str_contains($message, 'no encontrado')) return 'resource_not_found';
        if ($status === 401 || $status === 403) return 'access_rejected';
        if (str_contains($message, 'ip privada') || str_contains($message, 'reservada')
            || str_contains($message, 'url federada') || str_contains($message, 'https válido')) {
            return 'invalid_endpoint';
        }
        if (str_contains($message, 'no pudo resolver') || str_contains($message, 'no respondió')) return 'offline';
        if ($status >= 500) return 'technical_failure';
        if ($status >= 400) return 'invalid_response';
        return 'unknown_failure';
    }
}
