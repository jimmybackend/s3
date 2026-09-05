<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sharing;

use DateTimeImmutable;
use DateTimeZone;

final class ShareLinkService
{
    private DateTimeZone $timezone;

    public function __construct(
        private ShareFileRepository $files,
        private ShareTokenStore $tokens
    ) {
        $this->timezone = new DateTimeZone('America/Merida');
    }

    public function create(int $userId, string $requestedKey, string $type, int $days): array
    {
        $file = $this->files->requireOwnedByKey($userId, $requestedKey);
        $key = (string)$file['_key'];
        $type = $this->normalizeType($type);
        $days = max(1, min($days, 3650));

        $now = new DateTimeImmutable('now', $this->timezone);
        $expires = $now->setTime(23, 59, 59);
        if ($days > 1) {
            $expires = $expires->modify('+' . ($days - 1) . ' day');
        }

        $calendar = [];
        $cursor = $now;
        for ($i = 0; $i < $days; $i++) {
            $calendar[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        $token = $this->tokens->create([
            'archivo_key' => $key,
            'tipo' => $type,
            'dias' => $days,
            'calendario' => $calendar,
            'creado' => $now->format(DATE_ATOM),
            'expira' => $expires->format(DATE_ATOM),
            'user_id' => $userId,
            'file_id' => (int)($file['id_'] ?? 0),
            'nombre' => (string)($file['Nombre'] ?? basename($key)),
        ]);

        return [
            'token' => $token,
            'endpoint' => $this->endpointForType($type),
            'expira_iso' => $expires->format(DATE_ATOM),
            'dias' => $days,
            'calendario' => $calendar,
        ];
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['audio', 'video', 'imagen', 'texto', 'otro'], true)
            ? $type
            : 'otro';
    }

    private function endpointForType(string $type): string
    {
        return match ($type) {
            'audio' => 'token_audio.php',
            'video' => 'token_video.php',
            default => 'token_texto.php',
        };
    }
}
