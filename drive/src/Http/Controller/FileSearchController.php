<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Application\AiFileSearchService;
use ArcadeCloud\Drive\Application\FileListService;
use ArcadeCloud\Drive\Application\FileSearchService;
use ArcadeCloud\Drive\Http\JsonResponse;
use Throwable;

final class FileSearchController extends AbstractJsonController
{
    public function search(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $locateId = (int)$this->request->postString('localizar_id', '0');
            if ($locateId > 0) {
                $location = (new FileSearchService($this->app->db()))
                    ->locate($userId, $locateId, FileListService::WEB_OS_PAGE_SIZE);

                JsonResponse::send([
                    'estado' => 'ok',
                    'modo' => 'localizar',
                    'archivo' => $location,
                ]);
            }

            $term = $this->request->postString('termino');
            $mode = strtolower($this->request->postString('modo', 'normal'));

            if ($term === '') {
                JsonResponse::send([
                    'estado' => 'error',
                    'mensaje' => 'Escribe un nombre, patrón o descripción para buscar.',
                ], 400);
            }

            if ($mode === 'ai') {
                $search = (new AiFileSearchService(
                    $this->app->db(),
                    $this->app->folderQueryService(),
                    \Config::getBedrockRuntime()
                ))->search($userId, $term);

                $results = $this->withVisibleRoutes($userId, $search['results']);

                JsonResponse::send([
                    'estado' => 'ok',
                    'modo' => 'ai',
                    'modelo' => 'amazon.nova-micro-v1:0',
                    'termino' => $term,
                    'pistas' => $search['terms'],
                    'ia_usada' => $search['ai_used'],
                    'total' => count($results),
                    'resultados' => $results,
                ]);
            }

            $results = (new FileSearchService($this->app->db()))
                ->search($userId, $term, 200);
            $results = $this->withVisibleRoutes($userId, $results);

            JsonResponse::send([
                'estado' => 'ok',
                'modo' => 'normal',
                'termino' => $term,
                'total' => count($results),
                'resultados' => $results,
            ]);
        } catch (Throwable $e) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Conserva "ruta" como Prefix físico para navegación/backend y publica
     * "ruta_visible" sólo para la interfaz.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withVisibleRoutes(int $userId, array $rows): array
    {
        foreach ($rows as &$row) {
            $route = trim((string)($row['ruta'] ?? ''));
            $row['ruta_visible'] = $route === ''
                ? 'Mi Drive/'
                : $this->app->folderQueryService()->displayPathForUser($userId, $route);
        }
        unset($row);
        return $rows;
    }

}
