<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http;

final class JsonResponse
{
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = [], int $status = 200): void
    {
        self::send(['ok' => true] + $data, $status);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::send(['ok' => false, 'error' => $message] + $extra, $status);
    }
}
