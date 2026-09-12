<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationShareDriveService
{
    private FederationShareDriveRepository $shares;
    private FederationShareDownloader $downloader;

    public function __construct(private DriveApplication $app)
    {
        $this->shares = new FederationShareDriveRepository($this->app->db());
        $this->downloader = new FederationShareDownloader();
    }

    public function state(int $userId): array
    {
        if ($userId <= 0) throw new FederationException('Usuario local inválido.', 401);
        return [
            'ok' => true,
            'received' => $this->shares->receivedForUser($userId),
        ];
    }

    public function import(int $userId, string $shareId): array
    {
        if ($userId <= 0) throw new FederationException('Usuario local inválido.', 401);
        if (!preg_match('/\Afar_[A-Za-z0-9_-]{16,80}\z/', $shareId)) {
            throw new FederationException('Share ID inválido.', 400);
        }

        $share = $this->shares->receivedShare($userId, $shareId);
        if ($share === null) throw new FederationException('Archivo compartido no encontrado.', 404);
        if ((string)$share['Status'] !== 'active') throw new FederationException('El Share ya no está activo.', 409);
        if ($share['ExpiresAt'] !== null && strtotime((string)$share['ExpiresAt']) <= time()) {
            throw new FederationException('El Share ya expiró.', 409);
        }
        $accessUrl = trim((string)($share['AccessUrl'] ?? ''));
        if ($accessUrl === '') throw new FederationException('El Share no tiene acceso descargable.', 409);

        $resourceUpdatedAt = is_string($share['ResourceUpdatedAt'] ?? null) ? (string)$share['ResourceUpdatedAt'] : null;
        $importedAtVersion = is_string($share['ImportedResourceUpdatedAt'] ?? null)
            ? (string)$share['ImportedResourceUpdatedAt'] : null;
        $existingFound = (int)($share['LocalFileFound'] ?? 0) === 1 && (int)($share['LocalFileId'] ?? 0) > 0;

        if ($existingFound && ($resourceUpdatedAt === null || $importedAtVersion === null || strtotime($resourceUpdatedAt) <= strtotime($importedAtVersion))) {
            return [
                'ok' => true,
                'already_imported' => true,
                'file_id' => (int)$share['LocalFileId'],
                'message' => 'Este Share ya tiene una copia vigente en Mi Drive.',
            ];
        }

        $download = $this->downloader->download($accessUrl);
        $tmp = (string)$download['path'];
        try {
            $title = $this->safeFileName((string)$share['Title'], (string)$share['MediaType']);
            $mimeType = trim((string)$share['MediaType']);
            if ($mimeType === '') $mimeType = (string)($download['content_type'] ?? 'application/octet-stream');
            $root = $this->app->userStoragePath()->rootForUser($userId);
            $result = $this->app->singleUploadService()->upload(
                $tmp,
                $title,
                $root,
                $userId,
                $mimeType,
                (int)$download['size_bytes'],
                'FederationCloud',
                'FederationCloud Share import'
            );

            $fileId = (int)$result['id'];
            $s3Key = (string)$result['key_s3'];
            $this->shares->attachProvenance(
                $userId,
                $fileId,
                $shareId,
                (string)$share['ResourceId'],
                (string)$share['RemoteNodeId']
            );
            $this->shares->markImported($userId, $shareId, $fileId, $s3Key, $resourceUpdatedAt);

            return [
                'ok' => true,
                'already_imported' => false,
                'file_id' => $fileId,
                'key_s3' => $s3Key,
                'route' => (string)$result['ruta'],
                'name' => (string)$result['nombre_original'],
                'size_bytes' => (int)$download['size_bytes'],
                'sha256' => $download['sha256'] ?? null,
                'message' => $existingFound
                    ? 'La versión nueva se agregó a Mi Drive; la copia anterior se conservó.'
                    : 'El archivo compartido se agregó a Mi Drive.',
            ];
        } catch (Throwable $e) {
            if ($e instanceof FederationException) throw $e;
            throw new FederationException('No se pudo agregar el Share a Mi Drive: ' . $e->getMessage(), 500);
        } finally {
            @unlink($tmp);
        }
    }

    private function safeFileName(string $name, string $mediaType): string
    {
        $name = trim(str_replace(['\\', '/'], '-', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? $name;
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') $name = 'archivo-compartido';
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 220);
        else $name = substr($name, 0, 220);

        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $extension = $this->extensionForMime($mediaType);
            if ($extension !== '') $name .= '.' . $extension;
        }
        return $name;
    }

    private function extensionForMime(string $mediaType): string
    {
        return match (strtolower(trim($mediaType))) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'application/zip' => 'zip',
            default => '',
        };
    }
}
