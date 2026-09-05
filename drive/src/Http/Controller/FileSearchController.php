<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

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

            if ($term === '') {
                JsonResponse::send([
                    'estado' => 'error',
                    'mensaje' => 'Escribe un nombre o patrón para buscar.',
                ], 400);
            }

            $results = (new FileSearchService($this->app->db()))
                ->search($userId, $term, 200);

            JsonResponse::send([
                'estado' => 'ok',
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
