<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Application\AiFileSearchService;
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

                JsonResponse::send([
                    'estado' => 'ok',
                    'modo' => 'ai',
                    'modelo' => 'amazon.nova-micro-v1:0',
                    'termino' => $term,
                    'pistas' => $search['terms'],
                    'ia_usada' => $search['ai_used'],
                    'total' => count($search['results']),
                    'resultados' => $search['results'],
                ]);
            }

            $results = (new FileSearchService($this->app->db()))
                ->search($userId, $term, 200);

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
}
