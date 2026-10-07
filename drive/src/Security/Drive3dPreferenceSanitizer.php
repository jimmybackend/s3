<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

final class Drive3dPreferenceSanitizer
{
    /** @return array<string,mixed> */
    public function sanitize(array $drive3d): array
    {
        $allowedEnvironment = ['future', 'mountain', 'prehistoric', 'ocean'];
        $allowedFurniture = ['default'];
        $allowedWindows = ['panoramic'];
        $allowedPlants = ['orchids'];

        return [
            'environment' => in_array(($drive3d['environment'] ?? ''), $allowedEnvironment, true)
                ? (string)$drive3d['environment']
                : 'future',
            'glassBackground' => $this->surfacePath($drive3d['glassBackground'] ?? ''),
            'floorBackground' => $this->surfacePath($drive3d['floorBackground'] ?? ''),
            'ceilingBackground' => $this->surfacePath($drive3d['ceilingBackground'] ?? ''),
            'cameraYaw' => max(-180.0, min(180.0, (float)($drive3d['cameraYaw'] ?? 0))),
            'cameraPitch' => max(-28.0, min(28.0, (float)($drive3d['cameraPitch'] ?? 0))),
            'cameraDistance' => max(0.0, min(1.0, (float)($drive3d['cameraDistance'] ?? 0))),
            'cameraTarget' => mb_substr((string)($drive3d['cameraTarget'] ?? ''), 0, 255),
            'furniturePreset' => in_array(($drive3d['furniturePreset'] ?? ''), $allowedFurniture, true)
                ? (string)$drive3d['furniturePreset']
                : 'default',
            'windowPreset' => in_array(($drive3d['windowPreset'] ?? ''), $allowedWindows, true)
                ? (string)$drive3d['windowPreset']
                : 'panoramic',
            'plantsPreset' => in_array(($drive3d['plantsPreset'] ?? ''), $allowedPlants, true)
                ? (string)$drive3d['plantsPreset']
                : 'orchids',
        ];
    }

    private function surfacePath(mixed $value): string
    {
        $path = str_replace('\\', '/', trim((string)$value));
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }

        return mb_substr(ltrim($path, '/'), 0, 1024);
    }
}
