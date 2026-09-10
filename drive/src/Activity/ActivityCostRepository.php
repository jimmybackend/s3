<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

use mysqli;
use RuntimeException;

final class ActivityCostRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function record(array $event): void
    {
        $sql = "INSERT INTO `DriveActivityEvents`
            (`user_id_`, `actor_user_id_`, `Action`, `Service`, `FileId`, `UnitsJson`, `EstimatedCost`,
             `Currency`, `PriceSource`, `PricingState`, `Status`, `DurationMs`, `CorrelationId`, `MetadataJson`, `CreatedAt`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                `FileId` = VALUES(`FileId`),
                `UnitsJson` = VALUES(`UnitsJson`),
                `EstimatedCost` = VALUES(`EstimatedCost`),
                `Currency` = VALUES(`Currency`),
                `PriceSource` = VALUES(`PriceSource`),
                `PricingState` = VALUES(`PricingState`),
                `Status` = VALUES(`Status`),
                `DurationMs` = VALUES(`DurationMs`),
                `MetadataJson` = VALUES(`MetadataJson`)";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar DriveActivityEvents: ' . $this->db->error);
        }

        $params = [
            (int)$event['user_id'],
            (int)$event['actor_user_id'],
            (string)$event['action'],
            (string)$event['service'],
            $event['file_id'],
            $event['units_json'],
            $event['estimated_cost'],
            (string)$event['currency'],
            (string)$event['price_source'],
            (string)$event['pricing_state'],
            (string)$event['status'],
            $event['duration_ms'],
            $event['correlation_id'],
            $event['metadata_json'],
            (string)$event['created_at'],
        ];

        if (!$stmt->execute($params)) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo registrar actividad: ' . $error);
        }
        $stmt->close();
    }

    public function dashboard(int $userId, string $start, string $end, ?string $service, ?string $action): array
    {
        [$where, $params] = $this->where($userId, $start, $end, $service, $action);

        $totals = $this->one(
            "SELECT COUNT(*) AS operations,
                    COALESCE(SUM(`EstimatedCost`), 0) AS estimated_cost,
                    SUM(CASE WHEN `PricingState` = 'partial' THEN 1 ELSE 0 END) AS partial_count,
                    SUM(CASE WHEN `PricingState` = 'unpriced' THEN 1 ELSE 0 END) AS unpriced_count,
                    SUM(CASE WHEN `Status` = 'error' THEN 1 ELSE 0 END) AS error_count
             FROM `DriveActivityEvents` e WHERE $where",
            $params
        );

        $byService = $this->all(
            "SELECT `Service` AS service, COUNT(*) AS operations,
                    COALESCE(SUM(`EstimatedCost`), 0) AS estimated_cost
             FROM `DriveActivityEvents` e WHERE $where
             GROUP BY `Service` ORDER BY estimated_cost DESC, operations DESC, `Service` ASC",
            $params
        );

        $byAction = $this->all(
            "SELECT `Action` AS action, COUNT(*) AS operations,
                    COALESCE(SUM(`EstimatedCost`), 0) AS estimated_cost
             FROM `DriveActivityEvents` e WHERE $where
             GROUP BY `Action` ORDER BY estimated_cost DESC, operations DESC, `Action` ASC",
            $params
        );

        $daily = $this->all(
            "SELECT DATE(`CreatedAt`) AS day, COUNT(*) AS operations,
                    COALESCE(SUM(`EstimatedCost`), 0) AS estimated_cost
             FROM `DriveActivityEvents` e WHERE $where
             GROUP BY DATE(`CreatedAt`) ORDER BY day DESC",
            $params
        );

        $recent = $this->all(
            "SELECT e.id_, e.`CreatedAt`, e.`Action`, e.`Service`, e.`FileId`, e.`UnitsJson`,
                    e.`EstimatedCost`, e.`Currency`, e.`PricingState`, e.`Status`, e.`DurationMs`,
                    e.`actor_user_id_`, f.Nombre AS file_name
             FROM `DriveActivityEvents` e
             LEFT JOIN FileS3 f ON f.id_ = e.`FileId` AND f.user_id_ = e.`user_id_`
             WHERE $where
             ORDER BY e.`CreatedAt` DESC, e.id_ DESC LIMIT 100",
            $params
        );

        return [
            'totals' => $totals,
            'by_service' => $byService,
            'by_action' => $byAction,
            'daily' => $daily,
            'recent' => $recent,
            'filters' => $this->availableFilters($userId),
        ];
    }

    private function availableFilters(int $userId): array
    {
        return [
            'services' => array_column($this->all(
                'SELECT DISTINCT `Service` AS value FROM `DriveActivityEvents` WHERE `user_id_` = ? ORDER BY `Service`',
                [$userId]
            ), 'value'),
            'actions' => array_column($this->all(
                'SELECT DISTINCT `Action` AS value FROM `DriveActivityEvents` WHERE `user_id_` = ? ORDER BY `Action`',
                [$userId]
            ), 'value'),
        ];
    }

    private function where(int $userId, string $start, string $end, ?string $service, ?string $action): array
    {
        $where = 'e.`user_id_` = ? AND e.`CreatedAt` >= ? AND e.`CreatedAt` < ?';
        $params = [$userId, $start, $end];
        if ($service !== null) {
            $where .= ' AND e.`Service` = ?';
            $params[] = $service;
        }
        if ($action !== null) {
            $where .= ' AND e.`Action` = ?';
            $params[] = $action;
        }
        return [$where, $params];
    }

    private function one(string $sql, array $params): array
    {
        return $this->all($sql, $params)[0] ?? [];
    }

    private function all(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar consulta de actividad: ' . $this->db->error);
        }
        if (!$stmt->execute($params)) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo consultar actividad: ' . $error);
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}
