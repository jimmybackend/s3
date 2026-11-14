<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\Upload\SingleUploadService;
use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

/**
 * Genera detalle.json exclusivamente con archivos directos de una carpeta.
 * La ruta visible se reconstruye de S3Folders; jamás se publica Encriptado.
 */
final class FolderDetailCatalogService
{
    private const FILE_NAME = 'detalle.json';
    private const MARKER = 'arcadecloud_folder_detail_v1';
    private const MAX_BYTES = 10_000_000;

    public function __construct(
        private mysqli $db,
        private UserStoragePath $paths,
        private SingleUploadService $uploads,
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function generate(int $userId, string $rawRoute): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }
        $route = $this->paths->normalizeForUser($rawRoute, $userId);
        $visiblePath = $this->visiblePath($userId, $route);

        $stmt = $this->db->prepare(
            'SELECT id_, Nombre, Tamano, Metadatos, Encriptado FROM FileS3
             WHERE user_id_ = ? AND Ruta = ? AND Found = 1 ORDER BY Nombre ASC, id_ ASC'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar el catálogo de archivos.');
        }
        $stmt->bind_param('is', $userId, $route);
        $stmt->execute();
        $result = $stmt->get_result();
        $files = [];
        $existing = null;
        while ($row = $result->fetch_assoc()) {
            $name = (string)($row['Nombre'] ?? '');
            if (strcasecmp($name, self::FILE_NAME) === 0) {
                if ($name !== self::FILE_NAME || $existing !== null) {
                    $stmt->close();
                    throw new RuntimeException('Hay archivos con el nombre detalle.json en esta carpeta. Resuelve el duplicado antes de continuar.');
                }
                $existing = $row;
                continue; // El índice nunca se incluye a sí mismo.
            }
            $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            $files[] = [
                'nombre' => $name,
                'tipo' => $extension !== '' ? $extension : 'sin extensión',
                'extension' => $extension,
                'tamano_bytes' => max(0, (int)($row['Tamano'] ?? 0)),
            ];
        }
        $stmt->close();

        usort($files, static function (array $a, array $b): int {
            $nameOrder = strnatcasecmp($a['nombre'], $b['nombre']);
            return $nameOrder !== 0 ? $nameOrder : strcmp($a['tipo'], $b['tipo']);
        });
        $payload = json_encode([
            'formato' => self::MARKER,
            'carpeta' => basename(rtrim($visiblePath, '/')),
            'ruta_visible' => $visiblePath,
            'fecha_actualizacion' => date(DATE_ATOM),
            'orden' => 'nombre ascendente, tipo ascendente',
            'total_archivos' => count($files),
            'archivos' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($payload) > self::MAX_BYTES) {
            throw new RuntimeException('El detalle excede el límite de 10 MB. No se ha modificado el archivo.');
        }
        $payload .= "\n";
        $size = strlen($payload);

        if ($existing !== null) {
            $metadata = json_decode((string)($existing['Metadatos'] ?? ''), true);
            if (!is_array($metadata) || ($metadata['generated_by'] ?? '') !== self::MARKER) {
                throw new RuntimeException('Ya existe un detalle.json creado por el usuario. No se sobrescribirá.');
            }
            $key = (string)($existing['Encriptado'] ?? '');
            if ($key === '' || !str_starts_with($key, $route)) {
                throw new RuntimeException('La referencia física del catálogo no es válida.');
            }
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $payload,
                'ContentType' => 'application/json; charset=utf-8',
                'ACL' => 'private',
            ]);
            $metadata['fecha_actualizacion'] = date(DATE_ATOM);
            $metadata['hash_sha256'] = hash('sha256', $payload);
            $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $id = (int)$existing['id_'];
            $update = $this->db->prepare(
                'UPDATE FileS3 SET Tamano = ?, Metadatos = ?, Fecha = NOW()
                 WHERE id_ = ? AND user_id_ = ? AND Ruta = ? AND Found = 1'
            );
            if (!$update) {
                throw new RuntimeException('No se pudo actualizar el registro del catálogo.');
            }
            $update->bind_param('isiis', $size, $metaJson, $id, $userId, $route);
            $update->execute();
            $updated = $update->affected_rows;
            $update->close();
            if ($updated < 1) {
                throw new RuntimeException('El archivo se escribió en S3, pero no se pudo actualizar su registro. Reintenta la operación.');
            }
            $action = 'actualizado';
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'arcade-detail-');
            if ($tmp === false) {
                throw new RuntimeException('No se pudo preparar el catálogo.');
            }
            try {
                if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
                    throw new RuntimeException('No se pudo escribir el catálogo temporal.');
                }
                $uploaded = $this->uploads->upload(
                    $tmp, self::FILE_NAME, $route, $userId,
                    'application/json; charset=utf-8', $size
                );
            } finally {
                @unlink($tmp);
            }
            $id = (int)$uploaded['id'];
            $metadata = [
                'generated_by' => self::MARKER,
                'fecha_actualizacion' => date(DATE_ATOM),
                'hash_sha256' => hash('sha256', $payload),
            ];
            $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $update = $this->db->prepare('UPDATE FileS3 SET Metadatos = ? WHERE id_ = ? AND user_id_ = ?');
            if (!$update) {
                throw new RuntimeException('No se pudo marcar el catálogo generado.');
            }
            $update->bind_param('sii', $metaJson, $id, $userId);
            $update->execute();
            $update->close();
            $action = 'creado';
        }

        return [
            'nombre' => self::FILE_NAME,
            'accion' => $action,
            'ruta_visible' => $visiblePath,
            'total_archivos' => count($files),
            'tamano' => $size,
        ];
    }

    private function visiblePath(int $userId, string $route): string
    {
        $root = $this->paths->rootForUser($userId);
        if ($route === $root) {
            return 'Mis datos/';
        }
        $stmt = $this->db->prepare(
            'SELECT Prefix, Nombre FROM S3Folders WHERE user_id_ = ? AND Found = 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar la ruta visible.');
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result();
        $names = [];
        while ($row = $rows->fetch_assoc()) {
            $names[(string)$row['Prefix']] = trim((string)$row['Nombre']);
        }
        $stmt->close();

        if (!isset($names[$route])) {
            throw new RuntimeException('La carpeta no existe o no pertenece al usuario.');
        }
        $relative = substr($route, strlen($root));
        $parts = array_values(array_filter(explode('/', trim($relative, '/')), 'strlen'));
        $prefix = $root;
        $visible = ['Mis datos'];
        foreach ($parts as $part) {
            $prefix .= $part . '/';
            if (!isset($names[$prefix])) {
                throw new RuntimeException('No se pudo verificar la jerarquía visible de la carpeta.');
            }
            $visible[] = $names[$prefix] !== '' ? $names[$prefix] : $part;
        }
        return implode('/', $visible) . '/';
    }
}
