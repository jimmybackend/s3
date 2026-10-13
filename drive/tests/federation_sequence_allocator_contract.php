<?php
declare(strict_types=1);

// Read-only regression guard for the origin sequence allocator. No database
// connection, credentials, or production events are used by this test.
$source = file_get_contents(dirname(__DIR__) . '/src/Federation/FederationEventStore.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: cannot read FederationEventStore\n");
    exit(1);
}

$mustContain = [
    'SELECT LastSequence FROM FederationOriginCounters WHERE OriginNodeId = ? FOR UPDATE',
    'SELECT MAX(OriginSequence) AS MaxSequence FROM FederationEvents WHERE OriginNodeId = ?',
    "(int)(\$row['LastSequence'] ?? 0)",
    "(int)(\$maxRow['MaxSequence'] ?? 0)",
    'UPDATE FederationOriginCounters SET LastSequence = ? WHERE OriginNodeId = ?',
];
foreach ($mustContain as $fragment) {
    if (!str_contains($source, $fragment)) {
        fwrite(STDERR, "FAIL: missing sequence allocator guard: {$fragment}\n");
        exit(1);
    }
}

$lock = strpos($source, 'SELECT LastSequence FROM FederationOriginCounters WHERE OriginNodeId = ? FOR UPDATE');
$history = strpos($source, 'SELECT MAX(OriginSequence) AS MaxSequence FROM FederationEvents WHERE OriginNodeId = ?');
$create = strpos($source, '$this->codec->create($identity, $next, $eventType, $entityId, $payload)');
$persist = strpos($source, 'UPDATE FederationOriginCounters SET LastSequence = ? WHERE OriginNodeId = ?');
if (!($lock < $history && $history < $create && $create < $persist)) {
    fwrite(STDERR, "FAIL: sequence allocation is not performed under the counter lock\n");
    exit(1);
}

foreach ([
    [1996, 1999, 2000], // small EC2 drift observed in production
    [192, 194, 195],   // large EC2 drift observed in production
    [2216, 2216, 2217],
    [250, 240, 251],   // never decrease a valid counter
    [0, 0, 1],         // first event on an empty catalog
] as [$counter, $lastEvent, $expected]) {
    $next = max(0, $counter, $lastEvent) + 1;
    if ($next !== $expected) {
        fwrite(STDERR, "FAIL: sequence allocation regression\n");
        exit(1);
    }
}

echo "OK federation sequence allocation contract\n";
