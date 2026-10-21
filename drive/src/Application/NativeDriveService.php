<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Application;
use ArcadeCloud\Drive\Core\DriveApplication;

final class NativeDriveService
{
    public function __construct(private DriveApplication $app) {}
    public function signedDownload(int $userId, string $key): array
    {
        $started = microtime(true);
        $result = (new FileAccessService($this->app->fileRecordLocator(), $this->app->s3(), $this->app->bucket()))->signedDownload($userId, $key);
        \ArcadeCloud\Drive\Activity\ActivityCostRecorder::fromDatabase($this->app->db())->success(
            $userId, 'download', 'S3', (int)$result['id'], ['s3.presigned_get_intent' => 1], $started,
            ['phase' => 'native_presigned', 'get_confirmed' => false, 'cost_basis' => 'intent_only']
        );
        return $result;
    }

    public function browse(int $userId, array $query): array
    {
        $route = NativeDrivePolicy::route($this->app->userStoragePath(), $userId, (string)($query['route'] ?? ''));
        // Existing catalog ownership + existence validation; no S3 list or provisioning side effects.
        $route = $this->app->uploadDestinationService()->resolve($userId, $route);
        $state = $this->app->fileListService()->load($userId, $route, [
            'pagina' => $query['page'] ?? 1, 'limite' => 30,
            'buscar' => $query['search'] ?? '', 'tipo' => $query['type'] ?? '',
        ]);
        $folderPage = max(1, (int)($query['folder_page'] ?? 1));
        $children = $this->app->folderRepository()->listChildrenPage($userId, $route, 30, ($folderPage - 1) * 30);
        return [
            'route' => $route,
            'breadcrumbs' => $this->app->folderQueryService()->breadcrumbsForUser($userId, $route),
            'folders' => array_map(static fn(array $f): array => ['route' => $f['Prefix'], 'name' => $f['Nombre']], $children),
            'folder_page' => $folderPage, 'more_folders' => count($children) === 30,
            'files' => array_map([NativeDrivePolicy::class, 'file'], $state['rows']),
            'page' => $state['page'], 'pages' => $state['pages'], 'total' => $state['total'],
        ];
    }
}
