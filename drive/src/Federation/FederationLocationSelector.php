<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationLocationSelector
{
    /**
     * Provider/mirror activos se prefieren al origen para repartir carga.
     * La elección es determinista y no hace probes remotos síncronos.
     */
    public function preferred(array $locations): ?array
    {
        $clean = [];
        foreach ($locations as $row) {
            if (!is_array($row)) continue;
            $nodeId = trim((string)($row['node_id'] ?? $row['NodeId'] ?? ''));
            $role = strtolower(trim((string)($row['role'] ?? $row['LocationRole'] ?? 'origin')));
            $status = strtolower(trim((string)($row['status'] ?? $row['Status'] ?? 'stale')));
            $url = trim((string)($row['federation_url'] ?? $row['FederationUrl'] ?? ''));
            if (!preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $nodeId)) continue;
            if (!in_array($role, ['origin','provider','mirror'], true)) continue;
            if (!in_array($status, ['active','stale','revoked'], true) || $status === 'revoked') continue;
            $clean[] = [
                'node_id' => $nodeId,
                'role' => $role,
                'status' => $status,
                'federation_url' => $url,
                'last_seen_at' => $row['last_seen_at'] ?? $row['LastSeenAt'] ?? null,
                'updated_at' => $row['updated_at'] ?? $row['UpdatedAt'] ?? null,
            ];
        }
        if ($clean === []) return null;

        usort($clean, function (array $a, array $b): int {
            $rankA = $this->rank($a);
            $rankB = $this->rank($b);
            if ($rankA !== $rankB) return $rankA <=> $rankB;

            $freshA = strtotime((string)($a['last_seen_at'] ?? $a['updated_at'] ?? '')) ?: 0;
            $freshB = strtotime((string)($b['last_seen_at'] ?? $b['updated_at'] ?? '')) ?: 0;
            if ($freshA !== $freshB) return $freshB <=> $freshA;
            return strcmp((string)$a['node_id'], (string)$b['node_id']);
        });

        return $clean[0];
    }

    private function rank(array $row): int
    {
        $statusBase = (string)$row['status'] === 'active' ? 0 : 10;
        $roleRank = match ((string)$row['role']) {
            'mirror' => 0,
            'provider' => 1,
            default => 2,
        };
        return $statusBase + $roleRank;
    }
}
