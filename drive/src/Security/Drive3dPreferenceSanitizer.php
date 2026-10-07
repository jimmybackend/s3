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
            'cameraDistance' => 0.0,
            'cameraLateral' => max(-1.0, min(1.0, (float)($drive3d['cameraLateral'] ?? 0))),
            'cameraForward' => max(0.0, min(1.0, (float)($drive3d['cameraForward'] ?? 0))),
            'cameraModel' => 'player-v3',
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
            'spatialImages' => $this->spatialImages($drive3d['spatialImages'] ?? []),
        ];
    }

    /** @return list<array{id:string,name:string,path:string,openHref:string,world:list<float>}> */
    private function spatialImages(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $images = [];
        foreach (array_slice(array_values($value), 0, 12) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($entry['id'] ?? '')) ?? '';
            $id = substr($id, 0, 80);
            $openHref = $this->localViewerHref($entry['openHref'] ?? '');
            $world = $this->worldPosition($entry['world'] ?? null);
            if ($id === '' || $openHref === '' || $world === null) {
                continue;
            }

            $images[] = [
                'id' => $id,
                'name' => mb_substr(trim((string)($entry['name'] ?? 'Imagen')), 0, 255),
                'path' => mb_substr(str_replace(["\r", "\n", "\0"], '', (string)($entry['path'] ?? '')), 0, 1024),
                'openHref' => $openHref,
                'world' => $world,
            ];
        }

        return $images;
    }

    /** @return list<float>|null */
    private function worldPosition(mixed $value): ?array
    {
        if (!is_array($value) || count($value) !== 3) {
            return null;
        }

        $position = [];
        foreach (array_values($value) as $coordinate) {
            if (!is_numeric($coordinate)) {
                return null;
            }
            $number = (float)$coordinate;
            if (!is_finite($number)) {
                return null;
            }
            $position[] = max(-100.0, min(100.0, $number));
        }

        return $position;
    }

    private function localViewerHref(mixed $value): string
    {
        $href = trim((string)$value);
        if ($href === '' || strlen($href) > 4096 || str_contains($href, "\r") || str_contains($href, "\n")) {
            return '';
        }

        $parts = parse_url($href);
        if (!is_array($parts) || ($parts['path'] ?? '') !== 'ver_archivo.php' || isset($parts['scheme']) || isset($parts['host'])) {
            return '';
        }

        parse_str((string)($parts['query'] ?? ''), $query);
        if (!isset($query['archivo']) || trim((string)$query['archivo']) === '') {
            return '';
        }

        return $href;
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
