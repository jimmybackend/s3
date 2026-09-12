<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sync;

use mysqli;
use RuntimeException;

final class NodeSyncService
{
    public function __construct(
        private mysqli $db,
        private S3SyncService $sync,
        private SyncJobStore $jobs
    ) {
    }

    /**
     * Sincroniza un nodo completo sin listar S3 desde la raíz global.
     * Cada usuario se procesa mediante S3SyncService, que limita ListObjectsV2
     * a Data/, Data2/, DataN/... según el user_id correspondiente.
     *
     * @param callable(array<string,mixed>):void|null $progress
     * @return array<string,mixed>
     */
    public function synchronizeAll(?callable $progress = null): array
    {
        $userIds = $this->userIds();
        $summary = [
            'ok' => true,
            'users_total' => count($userIds),
            'users_completed' => 0,
            'users_skipped_busy' => 0,
            'users_failed' => 0,
            'files' => 0,
            'folders' => 0,
            'errors' => [],
        ];

        foreach ($userIds as $index => $userId) {
            $event = [
                'user_id' => $userId,
                'position' => $index + 1,
                'users_total' => count($userIds),
                'state' => 'starting',
            ];
            if ($progress !== null) {
                $progress($event);
            }

            $lock = @fopen($this->jobs->lockPath($userId), 'c+');
            if (!is_resource($lock)) {
                $summary['users_failed']++;
                $summary['errors'][] = [
                    'user_id' => $userId,
                    'error' => 'No se pudo crear el lock del usuario.',
                ];
                continue;
            }

            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                $summary['users_skipped_busy']++;
                if ($progress !== null) {
                    $progress($event + ['state' => 'busy']);
                }
                continue;
            }

            try {
                $result = $this->sync->synchronize($userId);
                $summary['users_completed']++;
                $summary['files'] += (int)($result['files_upserted'] ?? 0);
                $summary['folders'] += (int)($result['folders_upserted'] ?? 0);

                if ($progress !== null) {
                    $progress($event + [
                        'state' => 'done',
                        'files' => (int)($result['files_upserted'] ?? 0),
                        'folders' => (int)($result['folders_upserted'] ?? 0),
                    ]);
                }
            } catch (\Throwable $error) {
                $summary['users_failed']++;
                $summary['errors'][] = [
                    'user_id' => $userId,
                    'error' => $error->getMessage(),
                ];
                if ($progress !== null) {
                    $progress($event + [
                        'state' => 'error',
                        'error' => $error->getMessage(),
                    ]);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $summary['ok'] = $summary['users_failed'] === 0;
        return $summary;
    }

    /** @return array<int,int> */
    private function userIds(): array
    {
        $result = $this->db->query('SELECT id FROM Users ORDER BY id ASC');
        if (!$result) {
            throw new RuntimeException(
                'No se pudieron consultar los usuarios del nodo: ' . $this->db->error
            );
        }

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $result->free();
        return $ids;
    }
}
