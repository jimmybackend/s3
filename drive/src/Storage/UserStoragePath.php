<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

final class UserStoragePath
{
    public function rootForUser(int $userId): string
    {
        if ($userId <= 1) {
            return \Config::RUTA_RAIZ;
        }

        return 'Data' . $userId . '/';
    }

    public function normalizeForUser(string $path, int $userId): string
    {
        $root = $this->rootForUser($userId);
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('~/+~', '/', $path) ?? '';
        $path = ltrim($path, '/');

        if ($path === '' || $path === '/') {
            return $root;
        }

        if (preg_match('~(^|/)\.\.(/|$)~', $path)) {
            return $root;
        }

        // Compatibilidad con sesiones creadas cuando Config::RUTA_RAIZ era "Datos/".
        if ($path === 'Datos' || strpos($path, 'Datos/') === 0) {
            $suffix = $path === 'Datos' ? '' : substr($path, strlen('Datos/'));
            return $suffix === '' ? $root : $root . trim($suffix, '/') . '/';
        }

        if (strpos($path, $root) === 0) {
            return rtrim($path, '/') . '/';
        }

        // Nunca permitimos saltar a la raíz de otro usuario.
        if (preg_match('~^Data\d*/~i', $path) || strpos($path, 'Data/') === 0) {
            return $root;
        }

        return $root . trim($path, '/') . '/';
    }
}
