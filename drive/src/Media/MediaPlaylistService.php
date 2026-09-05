<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\View\FileViewHelper;

final class MediaPlaylistService
{
    private const AUDIO_EXTENSIONS = [
        'mp3', 'wav', 'ogg', 'opus', 'm4a', 'aac', 'flac', 'amr'
    ];

    private const VIDEO_EXTENSIONS = [
        'mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'ogv'
    ];

    public function __construct(
        private MediaPlaylistRepository $repository,
        private UserStoragePath $userStoragePath
    ) {
    }

    public function build(int $userId, string $route): array
    {
        $route = $this->userStoragePath->normalizeForUser($route, $userId);

        $audio = [];
        $video = [];

        foreach ($this->repository->listForRoute($userId, $route) as $row) {
            $name = (string)($row['Nombre'] ?? '');
            $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));

            $key = FileViewHelper::buildS3Key(
                (string)($row['Ruta'] ?? ''),
                (string)($row['Encriptado'] ?? '')
            );

            if ($key === '') {
                continue;
            }

            $item = [
                'key' => $key,
                'nombre' => $name,
                'src' => 'ver_archivo.php?archivo=' . rawurlencode($key),
            ];

            if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                $audio[] = $item;
            } elseif (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                $video[] = $item;
            }
        }

        return [
            'ok' => true,
            'ruta' => $route,
            'audio' => $audio,
            'video' => $video,
        ];
    }
}
