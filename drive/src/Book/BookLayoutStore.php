<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Book;
use RuntimeException;

/** Node-local cache and atomic ceilings shared by every public token on this node. */
final class BookLayoutStore
{
    public function __construct(private ?string $directory = null)
    {
        $this->directory ??= rtrim(sys_get_temp_dir(), '/') . '/arcadecloud-book-layout-v1';
    }

    public function remember(string $hash, string $token, string $ip, \Closure $generate): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new RuntimeException('Caché IA no disponible.');
        $lock = @fopen($this->directory . '/budget.lock', 'c+');
        if (!$lock) throw new RuntimeException('Control IA no disponible.');
        try {
            // Do not accumulate waiting PHP workers while another page is being formatted.
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('IA ocupada; se conserva la vista original.');
            $path = $this->directory . '/' . hash('sha256', $hash) . '.json';
            $cache = $this->read($path);
            if (isset($cache['styles'], $cache['usage'], $cache['correlation'])) return $cache + ['cached' => true];
            $statePath = $this->directory . '/budget.json';
            $state = $this->read($statePath);
            $day = gmdate('Y-m-d'); $hour = gmdate('Y-m-d-H');
            if (($state['day'] ?? '') !== $day) $state = ['day' => $day, 'count' => 0, 'hour' => $hour, 'tokens' => [], 'ips' => []];
            if (($state['hour'] ?? '') !== $hour) { $state['hour'] = $hour; $state['tokens'] = []; $state['ips'] = []; }
            $tk = hash('sha256', $token); $ik = hash('sha256', $ip);
            $limit = max(0, min(200, (int)(getenv('ARCADECLOUD_BOOK_AI_DAILY_LIMIT') !== false ? getenv('ARCADECLOUD_BOOK_AI_DAILY_LIMIT') : 200)));
            if ($state['count'] >= $limit || ($state['tokens'][$tk] ?? 0) >= 40 || ($state['ips'][$ik] ?? 0) >= 60) throw new RuntimeException('Límite de formato IA alcanzado; se conserva la vista original.');
            $state['count']++; $state['tokens'][$tk] = ($state['tokens'][$tk] ?? 0) + 1; $state['ips'][$ik] = ($state['ips'][$ik] ?? 0) + 1;
            $this->write($statePath, $state); // Reserve before any paid call, including failures.
            $result = $generate();
            $this->write($path, $result); // Usage journal survives a subsequent accounting failure.
            return $result + ['cached' => false];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function read(string $path): array
    {
        if (!is_file($path)) return [];
        $value = json_decode((string)file_get_contents($path), true);
        if (!is_array($value)) throw new RuntimeException('Estado IA no válido.');
        return $value;
    }

    private function write(string $path, array $value): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($tmp, $path)) throw new RuntimeException('No se pudo guardar el control IA.');
        @chmod($path, 0600);
    }
}
