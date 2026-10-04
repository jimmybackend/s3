<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use ArcadeCloud\Drive\Activity\AwsUnitPriceCatalog;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class FolderTextractService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'tif', 'tiff'];

    public function __construct(
        private mysqli $db,
        private TextractFileService $textract,
        private GeneratedFileRepository $generated,
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $paths
    ) {
    }

    public function extract(int $userId, string $requestedRoute, string $visibleFolderName = '', ?callable $progress = null): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $route = $this->paths->normalizeForUser($requestedRoute, $userId);
        $root = $this->paths->rootForUser($userId);
        if ($route === $root) {
            throw new RuntimeException('Selecciona una carpeta de documentos, no la raíz del usuario.');
        }

        $rows = $this->orderedImageRows($userId, $route);
        if ($rows === []) {
            throw new RuntimeException('La carpeta no contiene imágenes compatibles con Textract.');
        }

        $documents = [];
        $billablePages = 0;
        $sheet = 0;
        $total = count($rows);

        if ($progress !== null) {
            $progress(0, $total, '');
        }

        foreach ($rows as $row) {
            $sheet++;
            $physicalKey = $this->buildPhysicalKey(
                (string)($row['Ruta'] ?? $route),
                (string)($row['Encriptado'] ?? '')
            );

            $result = $this->textract->extract($userId, $physicalKey);
            $pages = max(1, (int)($result['page_count'] ?? 1));
            $billablePages += $pages;

            $documents[] = [
                'hoja' => $sheet,
                'archivo' => (string)($row['Nombre'] ?? ''),
                'file_id' => (int)($row['id_'] ?? 0),
                'fecha_creacion' => (string)($row['Fecha'] ?? ''),
                'texto' => (string)($result['textoJ'] ?? ''),
            ];

            if ($progress !== null) {
                $progress($sheet, $total, (string)($row['Nombre'] ?? ''));
            }
        }

        $folderName = trim($visibleFolderName) !== '' ? trim($visibleFolderName) : basename(rtrim($route, '/'));
        $parentRoute = $this->parentRoute($route, $root);
        $generatedAt = new \DateTimeImmutable('now');

        $cost = AwsUnitPriceCatalog::default()->estimate([
            'textract.detect_document_text_page' => $billablePages,
        ]);

        $payload = [
            'tipo' => 'extraccion_texto_carpeta',
            'carpeta' => $folderName,
            'ruta_carpeta' => $route,
            'numero_paginas' => count($documents),
            'paginas_facturables_textract' => $billablePages,
            'orden' => 'fecha_creacion_asc_id_asc',
            'generado_en' => $generatedAt->format(DATE_ATOM),
            'zona_horaria' => $generatedAt->getTimezone()->getName(),
            'costo_transcripcion' => [
                'servicio' => 'Amazon Textract',
                'unidad' => 'textract.detect_document_text_page',
                'unidades' => $billablePages,
                'costo_estimado' => $cost['amount'],
                'moneda' => $cost['currency'],
                'estado_precio' => $cost['state'],
                'fuente_precio' => $cost['source'],
            ],
            'documentos' => $documents,
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo generar el JSON de extracción.');
        }

        $visibleName = sprintf(
            'extraccion-%s-%d-paginas-%s.json',
            $this->safeName($folderName),
            count($documents),
            $generatedAt->format('Ymd-His')
        );

        $objectName = (new StorageObjectNameCodec())->createFileObjectName($visibleName);
        $key = $this->buildPhysicalKey($parentRoute, $objectName);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $json,
            'ContentType' => 'application/json; charset=UTF-8',
        ]);

        $catalogStatus = $this->generated->upsert(
            $userId,
            $visibleName,
            $objectName,
            strlen($json),
            [
                'tipo' => 'application/json',
                'servicio' => 'Amazon Textract',
                'generated_by' => 'folder_textract',
                'source_folder' => $folderName,
                'source_route' => $route,
                'page_count' => count($documents),
                'billable_pages' => $billablePages,
            ],
            $parentRoute
        );

        return [
            'ok' => true,
            'archivo' => $visibleName,
            'ruta' => $parentRoute,
            'paginas' => count($documents),
            'billable_pages' => $billablePages,
            'bytes' => strlen($json),
            'catalog_status' => $catalogStatus,
            'estimated_cost' => $cost['amount'],
            'currency' => $cost['currency'],
        ];
    }

    private function orderedImageRows(int $userId, string $route): array
    {
        $stmt = $this->db->prepare(
            'SELECT id_, Nombre, Encriptado, Ruta, Fecha '
            . 'FROM FileS3 '
            . 'WHERE user_id_ = ? AND Ruta = ? AND Found = 1 '
            . 'ORDER BY Fecha ASC, id_ ASC'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudieron consultar los archivos de la carpeta.');
        }

        $stmt->bind_param('is', $userId, $route);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $name = (string)($row['Nombre'] ?? '');
            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
                continue;
            }
            if (trim((string)($row['Encriptado'] ?? '')) === '') {
                continue;
            }
            $rows[] = $row;
        }

        $stmt->close();
        return $rows;
    }

    private function parentRoute(string $route, string $root): string
    {
        $clean = rtrim($route, '/');
        $pos = strrpos($clean, '/');
        if ($pos === false) {
            return $root;
        }

        $parent = substr($clean, 0, $pos + 1);
        return str_starts_with($parent, $root) ? $parent : $root;
    }

    private function buildPhysicalKey(string $route, string $objectName): string
    {
        $route = trim(str_replace('\\', '/', $route), '/');
        $name = ltrim(str_replace('\\', '/', trim($objectName)), '/');
        if ($name === '') {
            throw new RuntimeException('El archivo físico no tiene nombre.');
        }
        return ($route === '' ? '' : $route . '/') . $name;
    }

    private function safeName(string $value): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }

        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? 'carpeta';
        $value = trim($value, '.-_');
        return substr($value !== '' ? $value : 'carpeta', 0, 120);
    }
}
