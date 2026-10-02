<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Console;

use ArcadeCloud\Drive\Activity\ActivityRetentionService;
use mysqli;
use RuntimeException;
use Throwable;

final class ActivityRetentionCommand
{
    public function __construct(private mysqli $db) {}

    public function run(array $argv): int
    {
        try {
            $options = ['days' => 365, 'after-id' => 0, 'limit' => 500];
            $execute = false; $archive = null;
            foreach (array_slice($argv, 1) as $argument) {
                if ($argument === '--execute') { $execute = true; continue; }
                if (preg_match('/\A--(days|after-id|limit)=(\d+)\z/', $argument, $match)) {
                    $options[$match[1]] = (int)$match[2]; continue;
                }
                if (str_starts_with($argument, '--archive=')) { $archive = substr($argument, 10); continue; }
                throw new RuntimeException('Opción desconocida. Usa --days=N --after-id=N --limit=N [--execute --archive=/ruta/nueva.jsonl].');
            }
            $report = (new ActivityRetentionService($this->db))->run($options['days'], $options['after-id'], $options['limit'], $execute, $archive);
            echo json_encode(['ok' => true, 'report' => $report], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            return 0;
        } catch (Throwable) {
            // DB and file errors may contain private connection or path details.
            fwrite(STDERR, "No se completó la retención. Verifica opciones, archivo nuevo fuera del repositorio y permisos. Si quedó un archivo parcial, consérvalo para revisión; no confirma un DELETE.\n");
            return 1;
        }
    }
}
