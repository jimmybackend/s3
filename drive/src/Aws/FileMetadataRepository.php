<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use mysqli;
use RuntimeException;

final class FileMetadataRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Fusiona una sección de análisis dentro de FileS3.Metadatos y verifica
     * mediante una lectura posterior que quedó realmente persistida.
     *
     * @return array<string,mixed> sección finalmente almacenada
     */
    public function merge(int $userId, int $fileId, string $section, array $payload): array
    {
        if ($userId <= 0 || $fileId <= 0) {
            throw new RuntimeException('Usuario o archivo inválido para guardar metadatos.');
        }

        $section = trim($section);
        if ($section === '') {
            throw new RuntimeException('La sección de metadatos no puede estar vacía.');
        }

        $meta = $this->readAll($userId, $fileId);

        $payload['ts'] = $payload['ts'] ?? date('c');
        $meta[$section] = $payload;

        $json = json_encode(
            $meta,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($json)) {
            throw new RuntimeException('No se pudieron serializar metadatos.');
        }

        $stmt = $this->db->prepare(
            'UPDATE FileS3 SET Metadatos=? WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo actualizar metadatos: ' . $this->db->error);
        }

        $stmt->bind_param('sii', $json, $fileId, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudieron guardar metadatos: ' . $error);
        }
        $stmt->close();

        // No confiamos únicamente en execute(): releemos MySQL y comprobamos
        // que la sección esperada existe. Así saved=true significa persistido.
        $verified = $this->readAll($userId, $fileId);
        if (!isset($verified[$section]) || !is_array($verified[$section])) {
            throw new RuntimeException(
                'MySQL no confirmó la persistencia de la sección ' . $section . '.'
            );
        }

        $stored = $verified[$section];
        if (($stored['ts'] ?? null) !== $payload['ts']) {
            throw new RuntimeException(
                'MySQL devolvió una versión distinta de los metadatos guardados.'
            );
        }

        return $stored;
    }

    /** @return array<string,mixed> */
    public function readAll(int $userId, int $fileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT Metadatos FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo leer metadatos: ' . $this->db->error);
        }

        $stmt->bind_param('ii', $fileId, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudieron leer metadatos: ' . $error);
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Archivo no encontrado para guardar metadatos.');
        }

        $raw = trim((string)($row['Metadatos'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Un valor legado no JSON no debe bloquear los nuevos análisis.
            return ['Legacy' => ['raw' => $raw]];
        }

        return $decoded;
    }
}
