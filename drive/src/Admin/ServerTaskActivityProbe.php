<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Sync\SyncJobStore;

final class ServerTaskActivityProbe
{
    public function __construct(private DriveApplication $app)
    {
    }

    public function summary(): array
    {
        $sources = [
            'sync' => (new SyncJobStore())->hasActiveJobs(),
            'move' => $this->app->moveJobStore()->hasActiveJobs(),
            'media' => (new MediaProcessingJobRepository($this->app->db()))->hasActiveJobs(),
            'office' => $this->hasActiveOfficeSessions(),
            'ai' => $this->hasActiveAiTasks(),
        ];

        return [
            'active' => count(array_filter($sources)),
            'sources' => $sources,
        ];
    }

    private function hasActiveOfficeSessions(): bool
    {
        $leases = $this->app->db()->query(
            'SELECT 1 FROM OfficeSessionLeases WHERE ExpiresAt>UTC_TIMESTAMP() LIMIT 1'
        );
        if ($leases && is_array($leases->fetch_row())) {
            $leases->free();
            return true;
        }
        if ($leases) $leases->free();

        $documents = $this->app->db()->query(
            "SELECT 1 FROM OfficeDocumentSessions "
            . "WHERE Status IN ('preparing','ready','syncing','conflict') LIMIT 1"
        );
        if (!$documents) return false;
        $active = is_array($documents->fetch_row());
        $documents->free();
        return $active;
    }

    private function hasActiveAiTasks(): bool
    {
        $result = $this->app->db()->query(
            "SELECT Status, MetadataJson
             FROM DriveActivityEvents
             WHERE CreatedAt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
               AND ((Action='polly' AND Service='Polly') OR (Action='transcribe' AND Service='Transcribe'))
             ORDER BY id_ DESC
             LIMIT 250"
        );
        if (!$result) return false;

        while ($row = $result->fetch_assoc()) {
            $meta = json_decode((string)($row['MetadataJson'] ?? ''), true);
            if (!is_array($meta)) $meta = [];
            $rowStatus = strtolower((string)($row['Status'] ?? ''));
            $phase = strtolower((string)($meta['phase'] ?? ''));
            $raw = strtolower((string)($meta['task_status'] ?? $meta['status'] ?? ''));

            if ($phase === 'cancelled' || $raw === 'cancelled') continue;
            if ($rowStatus === 'error' || $phase === 'failed' || in_array($raw, ['failed','error'], true)) continue;
            if ($phase === 'completed' || in_array($raw, ['completed','complete'], true)) continue;
            if (
                in_array($raw, ['queued','scheduled','in_progress','running','processing'], true)
                || in_array($phase, ['started','running'], true)
            ) {
                $result->free();
                return true;
            }
        }
        $result->free();
        return false;
    }
}
