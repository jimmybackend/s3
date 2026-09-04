<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use mysqli;
use RuntimeException;

final class UploadDestinationService
{
    public function __construct(
        private mysqli $db,
        private UserStoragePath $paths
    ) {
    }

    public function resolve(int $userId, string $requestedRoute): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para establecer el destino de subida.');
        }

        $requestedRoute = trim($requestedRoute);
        if ($requestedRoute === '') {
            throw new RuntimeException('Falta la ruta objetivo de la subida.');
        }

        $route = $this->paths->normalizeForUser($requestedRoute, $userId);
        $root = $this->paths->rootForUser($userId);

        if ($route === $root) {
            return $route;
        }

        $stmt = $this->db->prepare(
            'SELECT 1 FROM S3Folders WHERE user_id_ = ? AND Prefix = ? AND Found = 1 LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar la carpeta destino: ' . $this->db->error);
        }

        $stmt->bind_param('is', $userId, $route);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            throw new RuntimeException('La carpeta destino ya no existe o no pertenece al usuario.');
        }

        return $route;
    }
}
