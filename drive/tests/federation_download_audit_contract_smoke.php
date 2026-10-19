<?php
declare(strict_types=1);

// Static security contract; does not require database credentials or modify production.
function auditCheck(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/src/Federation/FederationDownloadAuditRepository.php');
$sql = (string)file_get_contents(dirname($root) . '/adbbmis1_Cloud.sql');
auditCheck(str_contains($source, "WHERE ResourceId=? AND OwnerUserId=? LIMIT 1"), 'owner check in SQL');
auditCheck(str_contains($source, "WHERE OwnerUserId=? ORDER BY UpdatedAt"), 'owner-filtered listing');
auditCheck(str_contains($source, 'if ($userId <= 0'), 'reject unauthenticated owner');
auditCheck(str_contains($source, "preg_match('/\\Aarl_"), 'resource ID validation');
auditCheck(str_contains($source, "SELECT 1 FROM FederatedResources"), 'ownership lookup avoids summary aggregates');
auditCheck(str_contains($source, 'if (!$owned) return [];'), 'deny missing or other owner');
auditCheck(str_contains($source, "'verified_final_downloads' => null"), 'unknown downloads remain unknown');
auditCheck(!str_contains($source, 'AccessUrl') && !str_contains($source, 'DecisionJson'), 'no sensitive share columns');
auditCheck(!str_contains($source, 'INSERT ') && !str_contains($source, 'UPDATE ') && !str_contains($source, 'DELETE '), 'read-only SQL');
auditCheck(str_contains($sql, 'OwnerUserId int DEFAULT NULL'), 'versioned schema supports ownership');
auditCheck(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationResourceDeliveries'), 'versioned delivery ledger available');
fwrite(STDOUT, "Federation owner audit static contract: OK\n");
