<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\View\FileViewHelper;
use Aws\Exception\AwsException;
use RuntimeException;
use Throwable;

final class OfficeDocumentStorageService
{
    private const ALLOWED_EXTENSIONS = [
        'doc','docx','odt','rtf',
        'xls','xlsx','ods',
        'ppt','pptx','odp',
    ];

    private string $workspaceRoot;
    private OfficeDocumentSessionRepository $sessions;

    public function __construct(private DriveApplication $app)
    {
        $this->workspaceRoot = rtrim(
            trim((string)(getenv('ARCADECLOUD_OFFICE_WORKSPACE_ROOT')
                ?: '/var/lib/arcadecloud-office/phase1-workspace')),
            '/'
        );
        $this->sessions = new OfficeDocumentSessionRepository($app->db());
    }

    /** @return array<string,mixed> */
    public function prepare(string $sessionId, string $controlToken): array
    {
        $session = $this->sessions->requireAuthorized($sessionId, $controlToken);
        $userId = (int)$session['user_id'];
        $fileId = (int)$session['file_id'];
        $key = '';

        try {
            $row = $this->app->fileRecordRepository()->requireByRef($userId, $fileId, true);
            $this->assertEditableOfficeFile($row);

            $key = (string)($row['_key'] ?? '');
            if ($key === '' || !hash_equals((string)$session['original_key'], $key)) {
                throw new RuntimeException('El archivo cambió de ubicación antes de abrir Office.');
            }

            $visibleName = $this->safeWorkspaceName((string)($row['Nombre'] ?? ''), $fileId);
            $relative = 'sessions/' . $sessionId . '/' . $visibleName;
            $directory = $this->workspaceRoot . '/sessions/' . $sessionId;
            $target = $this->workspaceRoot . '/' . $relative;

            $this->ensureWorkspace($directory);

            $head = $this->app->s3()->headObject([
                'Bucket' => $this->app->bucket(),
                'Key' => $key,
            ]);
            $etag = $this->normalizeEtag((string)($head['ETag'] ?? ''));
            if ($etag === '') {
                throw new RuntimeException('S3 no devolvió ETag para el documento.');
            }

            $tmp = $target . '.download-' . bin2hex(random_bytes(6));
            try {
                $this->app->s3()->getObject([
                    'Bucket' => $this->app->bucket(),
                    'Key' => $key,
                    'SaveAs' => $tmp,
                    'IfMatch' => '"' . $etag . '"',
                ]);
                if (!is_file($tmp)) {
                    throw new RuntimeException('S3 no entregó el archivo temporal de Office.');
                }
                @chmod($tmp, 0660);
                if (!rename($tmp, $target)) {
                    throw new RuntimeException('No se pudo instalar el archivo en el workspace Office.');
                }
            } catch (Throwable $e) {
                @unlink($tmp);
                throw $e;
            }

            clearstatcache(true, $target);
            $mtime = (int)(@filemtime($target) ?: time());
            $size = (int)(@filesize($target) ?: 0);

            $this->writeSyncedDigest($target, $this->workspaceDigest($target));
            $this->sessions->markPrepared($sessionId, $relative, $etag, $mtime, $size);

            $helper = new PrivilegedServerHelper();
            if (!$helper->supportsWorkstationDocumentOpen()) {
                throw new RuntimeException(
                    'El helper del nodo necesita actualizarse para abrir documentos Office.'
                );
            }
            $helper->openWorkstationDocument($relative);

            $this->cleanupClosedWorkspaces();

            return [
                'ok' => true,
                'session_id' => $sessionId,
                'file_id' => $fileId,
                'name' => (string)($row['Nombre'] ?? $visibleName),
                'workspace_relative' => $relative,
                'etag' => $etag,
                'size' => $size,
                'status' => 'ready',
            ];
        } catch (Throwable $e) {
            $this->sessions->markFailed($sessionId);

            if ($this->isMissingS3Object($e)) {
                $safeKey = $key !== '' ? $key : '(key desconocida)';
                throw new RuntimeException(
                    'El catálogo FileS3 apunta a un objeto que ya no existe en S3: '
                    . $safeKey
                    . '. Sincroniza desde S3 la carpeta donde está el archivo '
                    . 'y vuelve a abrirlo con Office.'
                );
            }

            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function sync(string $sessionId, string $controlToken, bool $close = false): array
    {
        $session = $this->sessions->requireAuthorized($sessionId, $controlToken);
        $relative = trim((string)$session['workspace_relative']);
        if ($relative === '') {
            throw new RuntimeException('El documento Office todavía no está preparado.');
        }

        $target = $this->workspacePath($relative);
        if (!is_file($target)) {
            throw new RuntimeException('El archivo temporal de Office ya no existe.');
        }

        $lock = fopen(dirname($target) . '/.arcadecloud-office-sync.lock', 'c');
        if (!is_resource($lock)) throw new RuntimeException('No se pudo bloquear el documento Office.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Documento Office ocupado.');
            // Another sync may have committed while this request waited.
            $session = $this->sessions->requireAuthorized($sessionId, $controlToken);
            return $this->syncLocked($session, $target, $close);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function syncLocked(array $session, string $target, bool $close): array
    {
        $sessionId = (string)$session['session_id'];
        $relative = (string)$session['workspace_relative'];
        if (!is_readable($target)) {
            $helper = new PrivilegedServerHelper();
            if (!$helper->supportsWorkstationDocumentAccessRepair()) {
                throw new RuntimeException(
                    'El helper del nodo necesita actualizarse para reparar acceso al documento Office.'
                );
            }
            $helper->repairWorkstationDocumentAccess($relative);
            clearstatcache(true, $target);
        }

        $digest = $this->workspaceDigest($target);
        $lastDigest = trim((string)@file_get_contents(dirname($target) . '/.arcadecloud-office-synced-sha256'));
        clearstatcache(true, $target);
        $mtime = (int)(@filemtime($target) ?: 0);
        $size = (int)(@filesize($target) ?: 0);
        $previousMtime = (int)$session['last_workspace_mtime'];
        $previousSize = (int)$session['last_workspace_size'];

        if ($mtime === $previousMtime && $size === $previousSize
            && $lastDigest !== '' && hash_equals($lastDigest, $digest)) {
            if ($close) {
                $this->sessions->markClosed($sessionId);
            } elseif ((string)$session['status'] === 'syncing') {
                $this->sessions->recoverSyncFailure($sessionId);
            }
            return [
                'ok' => true,
                'changed' => false,
                'closed' => $close,
                'status' => $close ? 'closed' : 'ready',
            ];
        }

        $userId = (int)$session['user_id'];
        $fileId = (int)$session['file_id'];
        $row = $this->app->fileRecordRepository()->requireByRef($userId, $fileId, true);
        $this->assertEditableOfficeFile($row);

        $currentKey = (string)($row['_key'] ?? '');
        $originalKey = (string)$session['original_key'];
        if ($currentKey === '' || !hash_equals($originalKey, $currentKey)) {
            return $this->saveConflict($session, $row, $target, $mtime, $size, $close, $digest);
        }

        $head = $this->app->s3()->headObject([
            'Bucket' => $this->app->bucket(),
            'Key' => $originalKey,
        ]);
        $remoteEtag = $this->normalizeEtag((string)($head['ETag'] ?? ''));
        $expectedEtag = $this->normalizeEtag((string)$session['expected_etag']);

        if ($expectedEtag === '' || $remoteEtag === '' || !hash_equals($expectedEtag, $remoteEtag)) {
            return $this->saveConflict($session, $row, $target, $mtime, $size, $close, $digest);
        }

        $this->sessions->markSyncing($sessionId);

        try {
            $stream = $this->stableWorkspaceStream($target, $digest);
            if (!is_resource($stream)) {
                throw new RuntimeException('No se pudo leer el archivo temporal de Office.');
            }

            try {
                $put = [
                'Bucket' => $this->app->bucket(),
                'Key' => $originalKey,
                'Body' => $stream,
                // HEAD is advisory; S3 must enforce the expected version atomically.
                'IfMatch' => '"' . $expectedEtag . '"',
            ];
            foreach (['ContentType','CacheControl','ContentDisposition','ContentEncoding','ContentLanguage'] as $field) {
                if (isset($head[$field]) && trim((string)$head[$field]) !== '') {
                    $put[$field] = (string)$head[$field];
                }
            }
            if (isset($head['Metadata']) && is_array($head['Metadata'])) {
                $put['Metadata'] = $head['Metadata'];
            }
                $saved = $this->app->s3()->putObject($put);
            } catch (AwsException $error) {
                if (in_array($error->getStatusCode(), [404, 409, 412], true)
                    || in_array($error->getAwsErrorCode(), ['NoSuchKey', 'PreconditionFailed', 'ConditionalRequestConflict'], true)) {
                    return $this->saveConflict($session, $row, $target, $mtime, $size, $close, $digest);
                }
                throw $error;
            } finally {
                fclose($stream);
            }

            // A later HEAD could observe another writer and adopt its ETag as ours.
            $newEtag = $this->normalizeEtag((string)($saved['ETag'] ?? ''));
            if ($newEtag === '') {
                throw new RuntimeException('S3 no confirmó el nuevo ETag del documento.');
            }

            $this->writeSyncedDigest($target, $digest);
            $this->updateFileRecordAfterSave($userId, $fileId, $size);
            $this->sessions->markSynced($sessionId, $newEtag, $mtime, $size);
            if ($close) {
                $this->sessions->markClosed($sessionId);
            }

            return [
                'ok' => true,
                'changed' => true,
                'closed' => $close,
                'status' => $close ? 'closed' : 'ready',
                'etag' => $newEtag,
                'size' => $size,
            ];
        } catch (Throwable $error) {
            $this->sessions->recoverSyncFailure($sessionId);
            throw $error;
        }
    }

    private function saveConflict(
        array $session,
        array $row,
        string $target,
        int $mtime,
        int $size,
        bool $close,
        string $digest
    ): array {
        $userId = (int)$session['user_id'];
        $fileId = (int)$session['file_id'];
        $originalName = trim((string)($row['Nombre'] ?? 'documento'));
        $conflictName = $this->conflictName($originalName);
        $route = $this->app->fileRecordRepository()->normalizePrefix((string)($row['Ruta'] ?? ''));
        $encrypted = $this->app->storageObjectNameCodec()->createFileObjectName($conflictName);
        $conflictKey = $route . $encrypted;

        $stream = $this->stableWorkspaceStream($target, $digest);
        if (!is_resource($stream)) {
            throw new RuntimeException('No se pudo leer la copia de conflicto Office.');
        }

        try {
            $conflictSaved = $this->app->s3()->putObject([
                'Bucket' => $this->app->bucket(),
                'Key' => $conflictKey,
                'Body' => $stream,
            ]);
        } finally {
            fclose($stream);
        }

        try {
            $conflictFileId = $this->app->fileRecordRepository()->duplicateFrom(
                $userId,
                $fileId,
                $conflictName,
                $route,
                $encrypted
            );
            $this->updateFileRecordAfterSave($userId, $conflictFileId, $size);
        } catch (Throwable $e) {
            try {
                $this->app->s3()->deleteObject([
                    'Bucket' => $this->app->bucket(),
                    'Key' => $conflictKey,
                ]);
            } catch (Throwable) {
            }
            throw $e;
        }

        $conflictEtag = $this->normalizeEtag((string)($conflictSaved['ETag'] ?? ''));
        if ($conflictEtag === '') {
            throw new RuntimeException('S3 no confirmó el ETag de la copia de conflicto Office.');
        }

        $this->writeSyncedDigest($target, $digest);
        $this->sessions->adoptConflict(
            (string)$session['session_id'],
            $conflictFileId,
            $conflictKey,
            $conflictName,
            $conflictEtag,
            $mtime,
            $size
        );
        if ($close) {
            $this->sessions->markClosed((string)$session['session_id']);
        }

        return [
            'ok' => true,
            'changed' => true,
            'conflict' => true,
            'conflict_file_id' => $conflictFileId,
            'conflict_name' => $conflictName,
            'closed' => $close,
            'status' => $close ? 'closed' : 'ready',
            'etag' => $conflictEtag,
            'mtime' => $mtime,
            'size' => $size,
        ];
    }

    private function updateFileRecordAfterSave(int $userId, int $fileId, int $size): void
    {
        $stmt = $this->app->db()->prepare(
            'UPDATE FileS3 SET Tamano=?,Fecha=NOW() WHERE id_=? AND user_id_=? AND Found=1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo actualizar FileS3 después del guardado Office.');
        }
        $stmt->bind_param('iii', $size, $fileId, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo actualizar FileS3: ' . $error);
        }
        $stmt->close();
    }

    private function assertEditableOfficeFile(array $row): void
    {
        if (FileViewHelper::isLocked($row)) {
            throw new RuntimeException('Desbloquea el archivo antes de abrirlo con Office.');
        }
        $name = trim((string)($row['Nombre'] ?? ''));
        $ext = FileViewHelper::extension($name);
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Este tipo de archivo no se puede editar con Office.');
        }
    }

    private function ensureWorkspace(string $directory): void
    {
        $sessionsRoot = $this->workspaceRoot . '/sessions';
        if (!is_dir($sessionsRoot)) {
            throw new RuntimeException(
                'El workspace documental no está preparado. Reinstala Workstation en el nodo grande.'
            );
        }

        $realRoot = realpath($sessionsRoot);
        if ($realRoot === false) {
            throw new RuntimeException('No se pudo resolver el workspace documental.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el workspace de la sesión Office.');
        }
        @chmod($directory, 02770);

        $realDirectory = realpath($directory);
        if (
            $realDirectory === false
            || !str_starts_with($realDirectory, rtrim($realRoot, '/') . '/')
        ) {
            throw new RuntimeException('Workspace Office fuera de la raíz permitida.');
        }
    }

    private function workspacePath(string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative));
        if (!preg_match('/\Asessions\/[a-f0-9]{32}\/[^\/\x00-\x1F\x7F]{1,220}\z/u', $relative)) {
            throw new RuntimeException('Ruta temporal Office inválida.');
        }

        $path = $this->workspaceRoot . '/' . $relative;
        $realRoot = realpath($this->workspaceRoot . '/sessions');
        $realPath = realpath($path);
        if (
            $realRoot === false
            || $realPath === false
            || !str_starts_with($realPath, rtrim($realRoot, '/') . '/')
        ) {
            throw new RuntimeException('Archivo temporal Office fuera del workspace.');
        }
        return $realPath;
    }

    private function safeWorkspaceName(string $visibleName, int $fileId): string
    {
        $visibleName = basename(str_replace('\\', '/', trim($visibleName)));
        $visibleName = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $visibleName) ?? '';
        $visibleName = trim($visibleName, " .\t\n\r\0\x0B");
        if ($visibleName === '') {
            $visibleName = 'documento-' . $fileId;
        }
        if (function_exists('mb_substr')) {
            $visibleName = mb_substr($visibleName, 0, 200, 'UTF-8');
        } else {
            $visibleName = substr($visibleName, 0, 200);
        }
        return $visibleName;
    }

    private function conflictName(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $suffix = ' (conflicto Office ' . gmdate('Y-m-d H-i-s') . ')';
        $result = trim($base) . $suffix;
        if ($extension !== '') {
            $result .= '.' . $extension;
        }
        return $result;
    }

    private function normalizeEtag(string $etag): string
    {
        return trim(trim($etag), '"');
    }

    private function isMissingS3Object(Throwable $error): bool
    {
        if ($error instanceof AwsException) {
            $status = $error->getStatusCode();
            $code = strtolower((string)$error->getAwsErrorCode());
            return $status === 404 || in_array($code, ['nosuchkey', 'notfound', 'nosuchobject'], true);
        }

        $message = strtolower($error->getMessage());
        return str_contains($message, 'nosuchkey')
            || str_contains($message, 'specified key does not exist')
            || str_contains($message, 'not found');
    }

    /** @return resource Stable bytes for the S3 request even if LibreOffice saves again. */
    private function stableWorkspaceStream(string $target, string $digest)
    {
        $source = fopen($target, 'rb');
        $snapshot = tmpfile();
        if (!is_resource($source) || !is_resource($snapshot)) {
            if (is_resource($source)) fclose($source);
            if (is_resource($snapshot)) fclose($snapshot);
            throw new RuntimeException('No se pudo preparar la copia segura Office.');
        }
        try {
            if (stream_copy_to_stream($source, $snapshot) === false) {
                throw new RuntimeException('No se pudo copiar el documento Office.');
            }
            $uri = stream_get_meta_data($snapshot)['uri'];
            if (!hash_equals($digest, $this->workspaceDigest($uri))) {
                throw new RuntimeException('El documento cambió durante la preparación del guardado.');
            }
            rewind($snapshot);
            return $snapshot;
        } catch (Throwable $error) {
            fclose($snapshot);
            throw $error;
        } finally {
            fclose($source);
        }
    }

    private function workspaceDigest(string $target): string
    {
        $digest = @hash_file('sha256', $target);
        if (!is_string($digest)) throw new RuntimeException('No se pudo verificar el contenido del workspace Office.');
        return $digest;
    }

    private function writeSyncedDigest(string $target, string $digest): void
    {
        // Never certify bytes that changed while S3 was receiving the document.
        if (!hash_equals($digest, $this->workspaceDigest($target))) {
            throw new RuntimeException('El documento cambió durante el guardado; conserva cambios pendientes.');
        }
        $marker = dirname($target) . '/.arcadecloud-office-synced-sha256';
        $tmp = $marker . '.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $digest, LOCK_EX) === false || !rename($tmp, $marker)) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo registrar la verificación del documento Office.');
        }
        @chmod($marker, 0660);
    }

    private function cleanupClosedWorkspaces(): void
    {
        foreach ($this->sessions->cleanupCandidates(30) as $candidate) {
            $sessionId = (string)($candidate['session_id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) continue;

            $relative = trim((string)($candidate['workspace_relative'] ?? ''));
            if ($relative === '' || !is_file($this->workspaceRoot . '/' . $relative)) continue;
            if ($relative !== '') {
                $target = $this->workspaceRoot . '/' . $relative;
                if (is_file($target)) {
                    clearstatcache(true, $target);
                    $mtime = (int)(@filemtime($target) ?: 0);
                    $fileSize = @filesize($target);
                    $size = $fileSize === false ? -1 : (int)$fileSize;
                    $digest = trim((string)@file_get_contents(dirname($target) . '/.arcadecloud-office-synced-sha256'));
                    if (
                        $digest === '' || !hash_equals($digest, $this->workspaceDigest($target))
                        || $mtime !== (int)($candidate['last_workspace_mtime'] ?? 0)
                        || $size !== (int)($candidate['last_workspace_size'] ?? -1)
                    ) {
                        error_log(
                            '[ArcadeCloud Office] workspace cerrado conserva cambios no sincronizados: '
                            . $sessionId
                        );
                        continue;
                    }
                }
            }

            $directory = $this->workspaceRoot . '/sessions/' . $sessionId;
            if (is_file($directory . '/.arcadecloud-office-active')) {
                continue;
            }
            $this->deleteTree($directory);
            $this->sessions->deleteClosed($sessionId);
        }
    }

    private function deleteTree(string $directory): void
    {
        $root = realpath($this->workspaceRoot . '/sessions');
        $real = realpath($directory);
        if (
            $root === false
            || $real === false
            || !str_starts_with($real, rtrim($root, '/') . '/')
            || !is_dir($real)
        ) {
            return;
        }

        $items = scandir($real);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $real . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($real);
    }
}
