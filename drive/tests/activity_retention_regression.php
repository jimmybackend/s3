<?php
declare(strict_types=1);

if (getenv('ARCADECLOUD_ISOLATED_TEST') !== '1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__) . '/src/Activity/ActivityRetentionService.php';
use ArcadeCloud\Drive\Activity\ActivityRetentionService;
function retentionCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', 'fixture-only');
$name = 'retention_regression_' . bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$name`"); $db->select_db($name);
$archive = sys_get_temp_dir() . '/' . $name . '.jsonl';
try {
    $schema = (string)file_get_contents(dirname(__DIR__, 2) . '/adbbmis1_Cloud.sql');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `DriveActivityEvents` \(.*?;\s/s', $schema, $match)) throw new RuntimeException('Canonical schema missing');
    $db->query($match[0]);
    $base = ['Action' => 'delete', 'Service' => 'S3', 'Status' => 'ok', 'CorrelationId' => null, 'MetadataJson' => null, 'CreatedAt' => '2020-01-01 00:00:00'];
    $fixtures = [
        $base,
        array_replace($base, ['CreatedAt' => gmdate('Y-m-d H:i:s')]),
        array_replace($base, ['Action' => 'polly', 'Service' => 'Polly', 'MetadataJson' => '{"phase":"running"}']),
        array_replace($base, ['Action' => 'transcribe', 'Service' => 'Transcribe']),
        array_replace($base, ['CorrelationId' => 'polly:keep-related-cost']),
        array_replace($base, ['MetadataJson' => '{"cleanup_pending":true}']),
        array_replace($base, ['MetadataJson' => '{"phase":"unknown"}']),
        array_replace($base, ['MetadataJson' => '{invalid']),
        array_replace($base, ['Action' => 'new-unknown-operation']),
        array_replace($base, ['Status' => 'running']),
        array_replace($base, ['Action' => 'rename', 'Service' => 'Drive', 'Status' => 'error']),
    ];
    $insert = $db->prepare("INSERT INTO DriveActivityEvents (user_id_,actor_user_id_,Action,Service,Status,CorrelationId,MetadataJson,CreatedAt,PriceSource) VALUES (2,2,?,?,?,?,?,?,'fixture')");
    foreach ($fixtures as $row) $insert->execute(array_values($row));
    $insert->close();
    $service = new ActivityRetentionService($db);
    $preview = $service->run(365, 0, 100, false, $archive);
    retentionCheck($preview['eligible'] === 2 && $preview['deleted'] === 0 && !file_exists($archive), 'dry-run selects only old synchronous telemetry without writing');
    $batch = $service->run(365, 0, 3);
    retentionCheck($batch['scanned'] === 3 && $batch['next_after_id'] === 3 && $batch['has_more'], 'keyset scan is bounded and returns continuation');
    try { $service->run(365, 0, 100, true); throw new LogicException('Missing archive accepted'); }
    catch (RuntimeException) { retentionCheck(true, 'execute requires an archive'); }
    try { $service->run(365, 0, 100, true, dirname(__DIR__) . '/retention-test.jsonl'); throw new LogicException('Public archive accepted'); }
    catch (RuntimeException) { retentionCheck(true, 'archive inside repository is rejected'); }
    $result = $service->run(365, 0, 100, true, $archive);
    retentionCheck($result['deleted'] === 2 && $result['preserved'] === 9, 'execute preserves jobs, correlated records and unknown metadata');
    $lines = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($archive, FILE_IGNORE_NEW_LINES));
    retentionCheck($lines[0]['format'] === 'arcadecloud-activity-archive-v1' && $lines[3]['archived_rows'] === 2, 'archive has header, complete rows and count');
    retentionCheck($lines[1]['row']['id_'] == 1 && $lines[2]['row']['id_'] == 11, 'full original rows are archived before deletion');
    retentionCheck((fileperms($archive) & 0777) === 0600, 'archive is private');
    try { $service->run(365, 0, 100, true, $archive); throw new LogicException('Existing archive overwritten'); }
    catch (RuntimeException) { retentionCheck(true, 'existing archive cannot be overwritten'); }
    retentionCheck((int)$db->query('SELECT COUNT(*) FROM DriveActivityEvents')->fetch_row()[0] === 9, 'failed archive attempt leaves DB intact');
    retentionCheck($service->run(365, 0, 100)['eligible'] === 0, 'repeated preview does not remove protected rows');
    $db->query("INSERT INTO DriveActivityEvents (user_id_,actor_user_id_,Action,Service,PriceSource,CreatedAt) VALUES (2,2,'delete','S3','fixture','2020-01-01')");
    $db->query("CREATE TRIGGER deny_retention BEFORE DELETE ON DriveActivityEvents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture rollback'");
    try { $service->run(365, 0, 100, true, $archive . '.rollback'); throw new LogicException('Delete failure accepted'); }
    catch (mysqli_sql_exception) { retentionCheck(true, 'delete failure is propagated'); }
    retentionCheck((int)$db->query('SELECT COUNT(*) FROM DriveActivityEvents')->fetch_row()[0] === 10, 'failed transaction preserves all rows');
    retentionCheck(is_file($archive . '.rollback'), 'archive remains available after DB rollback');
} finally {
    if (is_file($archive)) unlink($archive);
    if (is_file($archive . '.rollback')) unlink($archive . '.rollback');
    $db->query("DROP DATABASE `$name`");
}
