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

    /** @return list<array{id:string,name:string,path:string,openHref:string,world:list<float>,size:?list<float>}|null> */
    private function spatialImages(mixed $value): array
    {
        $slots = array_fill(0, 12, null);
        if (!is_array($value)) {
            return $slots;
        }

        foreach (array_slice(array_values($value), 0, 12) as $index => $entry) {
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

            $mode = in_array(($entry['mode'] ?? 'free'), ['free', 'floor', 'ceiling', 'window'], true)
                ? (string)$entry['mode']
                : 'free';
            $panelId = trim((string)($entry['panelId'] ?? ''));
            if (($mode === 'window' && !preg_match('/^w-(?:[0-9]|1[0-5])-[0-2]$/', $panelId))
                || ($mode === 'ceiling' && !preg_match('/^c-(?:[0-9]|1[0-5])-0$/', $panelId))) {
                $mode = 'free';
                $panelId = '';
            }
            if ($mode === 'floor' || $mode === 'free') {
                $panelId = '';
            }
            $rawScale = $entry['surfaceScale'] ?? .70;
            $scale = is_numeric($rawScale) && is_finite((float)$rawScale) ? (float)$rawScale : .70;
            $scale = max(.30, min($mode === 'floor' ? 2.6 : .94, $scale));

            $slots[$index] = [
                'id' => $id,
                'name' => mb_substr(trim((string)($entry['name'] ?? 'Imagen')), 0, 255),
                'path' => mb_substr(str_replace(["\r", "\n", "\0"], '', (string)($entry['path'] ?? '')), 0, 1024),
                'openHref' => $openHref,
                'world' => $world,
                'size' => $this->pictureSize($entry['size'] ?? null),
                'mode' => $mode,
                'panelId' => $panelId,
                'surfaceScale' => $scale,
            ];
        }

        return $slots;
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

    /** @return list<float>|null */
    private function pictureSize(mixed $value): ?array
    {
        if (!is_array($value) || count($value) !== 2) {
            return null;
        }

        $width = $value[0] ?? null;
        $height = $value[1] ?? null;
        if (!is_numeric($width) || !is_numeric($height)) {
            return null;
        }

        $width = (float)$width;
        $height = (float)$height;
        if (!is_finite($width) || !is_finite($height)) {
            return null;
        }

        return [
            max(160.0, min(1200.0, $width)),
            max(100.0, min(900.0, $height)),
        ];
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
