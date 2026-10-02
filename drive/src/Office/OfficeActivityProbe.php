<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use mysqli;
use RuntimeException;
use Throwable;

/** Read-only guard. An unavailable schema/connection must never mean idle. */
final class OfficeActivityProbe
{
    public function __construct(private mysqli $db) {}

    public function hasActiveSessions(?string $instanceId = null): bool
    {
        if ($instanceId !== null && trim($instanceId) === '') {
            throw new RuntimeException('Falta la instancia para comprobar Office.');
        }
        $scope = $instanceId === null ? '' : 'InstanceId=? AND ';
        // A desktop lease reserves the single-user workstation but is not proof
        // of current human activity. Likewise, a prepared "ready" document may
        // remain open while the user is away. Real keyboard/pointer/touch input
        // updates MediaWorkerNodeSessions through /__office_activity.
        //
        // Only transitional/unsafe document states block shutdown absolutely.
        $sql = "SELECT 1 FROM OfficeDocumentSessions WHERE " . $scope
            . "Status IN ('preparing','syncing','conflict') LIMIT 1";

        $stmt = null;
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) throw new RuntimeException('Office query unavailable.');
            if ($instanceId !== null) $stmt->bind_param('s', $instanceId);
            if (!$stmt->execute()) throw new RuntimeException('Office query failed.');
            $result = $stmt->get_result();
            if (!$result) throw new RuntimeException('Office result unavailable.');
            $active = is_array($result->fetch_row());
            $result->free();
            return $active;
        } catch (Throwable) {
            // Avoid propagating SQL/connection details to AJAX responses.
            throw new RuntimeException('No se pudo comprobar Office; el apagado queda bloqueado.');
        } finally {
            if ($stmt) $stmt->close();
        }
    }
}
