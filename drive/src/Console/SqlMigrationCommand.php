<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Console;

use ArcadeCloud\Drive\Core\DriveApplication;
use RuntimeException;

final class SqlMigrationCommand
{
    public function __construct(private DriveApplication $app)
    {
    }

    public function run(array $argv): int
    {
        try {
            $directory = dirname(__DIR__, 2) . '/database/migrations';
            $requested = trim((string)($argv[1] ?? ''));
            $files = $requested !== ''
                ? [$this->resolveOne($directory, $requested)]
                : (glob($directory . '/*.sql') ?: []);
            sort($files, SORT_STRING);

            if ($files === []) {
                throw new RuntimeException('No hay migraciones SQL para aplicar.');
            }

            foreach ($files as $file) {
                $this->apply($file);
                echo 'OK ' . basename($file) . PHP_EOL;
            }
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function resolveOne(string $directory, string $requested): string
    {
        $name = basename($requested);
        if ($name !== $requested || preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name) !== 1) {
            throw new RuntimeException('Nombre de migración inválido.');
        }
        $path = $directory . '/' . $name;
        if (!is_file($path)) throw new RuntimeException('Migración no encontrada: ' . $name);
        return $path;
    }

    private function apply(string $file): void
    {
        $sql = trim((string)file_get_contents($file));
        if ($sql === '') throw new RuntimeException('Migración vacía: ' . basename($file));

        $db = $this->app->db();
        if (!$db->multi_query($sql)) {
            throw new RuntimeException('Falló ' . basename($file) . ': ' . $db->error);
        }
        do {
            if ($result = $db->store_result()) $result->free();
            if (!$db->more_results()) break;
        } while ($db->next_result());

        if ($db->errno) {
            throw new RuntimeException('Falló ' . basename($file) . ': ' . $db->error);
        }
    }
}
