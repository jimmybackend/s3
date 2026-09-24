<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationPublicImportService
{
    private FederatedCatalogRepository $catalog;
    private FederationPublicImportRepository $jobs;
    private FederationReplicaResolverService $resolver;

    public function __construct(private DriveApplication $app)
    {
        $this->catalog = new FederatedCatalogRepository($this->app->db());
        $this->jobs = new FederationPublicImportRepository($this->app->db());
        $this->resolver = new FederationReplicaResolverService($this->app);
    }

    public function queue(int $userId, string $resourceId): array
    {
        $resource = $this->requirePublicResource($userId, $resourceId);
        $versionKey = hash('sha256', $resourceId . '|' . strtolower((string)$resource['ContentId']));
        $job = $this->jobs->queue($userId, $resourceId, $versionKey);
        return [
            'ok' => true,
            'resource_id' => $resourceId,
            'job' => $job,
            'message' => (string)$job['status'] === 'completed'
                ? 'Este recurso público ya fue agregado a Mi Drive.'
                : 'La copia pública quedó en cola; FederationCloud usará varias réplicas cuando estén disponibles.',
        ];
    }

    public function state(int $userId, string $resourceId): array
    {
        if ($userId <= 0) throw new FederationException('Usuario local inválido.', 401);
        return [
            'ok' => true,
            'resource_id' => $resourceId,
            'job' => $this->jobs->latest($userId, $resourceId),
        ];
    }

    public function syncPending(int $limit = 1): array
    {
        $processed = $completed = $retry = $failed = 0;
        foreach ($this->jobs->due($limit) as $job) {
            $importId = (string)$job['ImportId'];
            if (!$this->jobs->markProcessing($importId)) continue;
            $processed++;
            try {
                $fileId = $this->perform(
                    (int)$job['UserId'],
                    (string)$job['ResourceId'],
                    $importId
                );
                if ($fileId > 0) $completed++;
            } catch (FederationException $e) {
                $state = $this->jobs->markRetry($importId, $e->getMessage());
                if ($state === 'failed') $failed++;
                else $retry++;
            } catch (Throwable $e) {
                error_log('[FederationCloud public import] ' . $e->getMessage());
                $state = $this->jobs->markRetry($importId, 'Error interno al importar recurso público.');
                if ($state === 'failed') $failed++;
                else $retry++;
            }
        }
        return compact('processed', 'completed', 'retry', 'failed');
    }

    private function perform(int $userId, string $resourceId, string $importId): int
    {
        $resource = $this->requirePublicResource($userId, $resourceId);
        $resolved = $this->resolver->publicSources(
            $resourceId,
            FederationMultiSourceDownloader::MAX_SOURCES
        );
        $urls = [];
        foreach ((array)($resolved['sources'] ?? []) as $source) {
            if (is_array($source) && is_string($source['url'] ?? null) && $source['url'] !== '') {
                $urls[] = (string)$source['url'];
            }
        }

        $download = (new FederationMultiSourceDownloader())->download(
            $urls,
            (int)$resource['SizeBytes'],
            (string)$resource['ContentId']
        );
        $tmp = (string)$download['path'];
        $createdFileId = 0;
        try {
            $title = $this->safeFileName((string)$resource['Title'], (string)$resource['MediaType']);
            $root = $this->app->userStoragePath()->rootForUser($userId);
            $result = $this->app->singleUploadService()->upload(
                $tmp,
                $title,
                $root,
                $userId,
                (string)$resource['MediaType'],
                (int)$download['bytes'],
                'FederationCloud',
                'FederationCloud public multi-source import'
            );
            $createdFileId = (int)$result['id'];
            $sourcesUsed = max(1, (int)($download['sources_used'] ?? 1));
            $this->jobs->attachProvenance(
                $userId,
                $createdFileId,
                $resourceId,
                (string)$resource['OriginNodeId'],
                $sourcesUsed
            );
            $this->jobs->markCompleted($importId, $createdFileId, $sourcesUsed);
            return $createdFileId;
        } catch (Throwable $e) {
            if ($createdFileId > 0) {
                try {
                    $this->app->fileMutationService()->delete($userId, $createdFileId);
                } catch (Throwable $cleanupError) {
                    error_log('[FederationCloud public import cleanup] ' . $cleanupError->getMessage());
                }
            }
            if ($e instanceof FederationException) throw $e;
            throw new FederationException('No se pudo agregar el recurso público a Mi Drive.', 500);
        } finally {
            @unlink($tmp);
        }
    }

    private function requirePublicResource(int $userId, string $resourceId): array
    {
        if ($userId <= 0) throw new FederationException('Usuario local inválido.', 401);
        if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            throw new FederationException('Recurso público inválido.', 400);
        }
        $resource = $this->catalog->find($resourceId);
        if ($resource === null) throw new FederationException('Recurso público no encontrado.', 404);
        if ((string)$resource['Visibility'] !== 'PUBLIC' || (string)$resource['Rights'] !== 'copy_allowed') {
            throw new FederationException('Sólo PUBLIC + copy_allowed puede copiarse sin grant privado.', 403);
        }
        $contentId = strtolower(trim((string)($resource['ContentId'] ?? '')));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            throw new FederationException('La copia pública requiere Content ID SHA-256.', 409);
        }
        $size = (int)$resource['SizeBytes'];
        if ($size < 0 || $size > FederationReplicaDownloader::MAX_BYTES) {
            throw new FederationException('El recurso público excede el límite de copia de 5 GiB.', 413);
        }
        return $resource;
    }

    private function safeFileName(string $name, string $mediaType): string
    {
        $name = trim(str_replace(['\\', '/'], '-', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? $name;
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') $name = 'archivo-publico';
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 220);
        else $name = substr($name, 0, 220);

        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $extension = match (strtolower(trim($mediaType))) {
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'text/plain' => 'txt',
                'application/json' => 'json',
                'audio/mpeg' => 'mp3',
                'video/mp4' => 'mp4',
                'application/zip' => 'zip',
                default => '',
            };
            if ($extension !== '') $name .= '.' . $extension;
        }
        return $name;
    }
}
