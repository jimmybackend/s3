<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use mysqli;
use RuntimeException;

/**
 * Búsqueda asistida por Nova Micro sobre el catálogo MySQL del usuario.
 *
 * Invariantes:
 * - nunca lista S3;
 * - nunca descarga ni envía el archivo físico a Bedrock;
 * - sólo consulta FileS3/S3Folders filtrados por user_id_;
 * - Bedrock recibe nombres visibles, rutas visibles y un extracto corto de Metadatos.
 */
final class AiFileSearchService
{
    private const MODEL_ID = 'amazon.nova-micro-v1:0';
    private const MAX_TERMS = 8;
    private const MAX_CANDIDATES = 60;
    private const MAX_RESULTS = 20;
    private const METADATA_SNIPPET_CHARS = 500;

    public function __construct(
        private mysqli $db,
        private FolderQueryService $folders,
        private BedrockRuntimeClient $bedrock
    ) {
    }

    /** @return array{results:array<int,array<string,mixed>>,terms:array<int,string>,ai_used:bool} */
    public function search(int $userId, string $query): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para búsqueda IA.');
        }

        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('Describe lo que deseas buscar.');
        }

        if (mb_strlen($query, 'UTF-8') > 600) {
            $query = mb_substr($query, 0, 600, 'UTF-8');
        }

        $aiUsed = false;
        $terms = [];

        try {
            $terms = $this->planTerms($query);
            $aiUsed = $terms !== [];
        } catch (\Throwable) {
            // El buscador sigue funcionando de forma degradada si Bedrock no responde.
        }

        $terms = $this->mergeTerms($terms, $this->localTerms($query));
        if ($terms === []) {
            $terms = [mb_strtolower($query, 'UTF-8')];
        }

        $destinations = $this->folders->destinationsForUser($userId, true);
        $routeLabels = [];
        $folderCandidates = [];
        $matchedFolderPrefixes = [];

        foreach ($destinations as $destination) {
            $prefix = (string)($destination['value'] ?? '');
            $label = trim((string)($destination['label'] ?? ''));
            if ($prefix === '') {
                continue;
            }

            $routeLabels[$prefix] = $label !== '' ? $label : 'Inicio/';
            $matches = $this->countMatches($label, $terms);

            if ($matches > 0) {
                $matchedFolderPrefixes[$prefix] = true;
                $ref = 'd:' . sha1($prefix);
                $folderCandidates[$ref] = [
                    'ref' => $ref,
                    'tipo' => 'carpeta',
                    'nombre' => $this->folderLeafName($label),
                    'ruta' => $prefix,
                    'ruta_visible' => $label,
                    'metadata' => '',
                    'fecha' => '',
                    'match_count' => $matches,
                ];
            }
        }

        $files = $this->findFilesByTerms($userId, $terms, $routeLabels);

        foreach (array_keys($matchedFolderPrefixes) as $prefix) {
            foreach ($this->findFilesInFolder($userId, $prefix, $routeLabels) as $ref => $row) {
                if (!isset($files[$ref])) {
                    $files[$ref] = $row;
                } else {
                    $files[$ref]['match_count'] = max(
                        (int)$files[$ref]['match_count'],
                        (int)$row['match_count']
                    );
                }
            }
        }

        $candidates = array_values(array_merge($files, $folderCandidates));
        usort($candidates, static function (array $a, array $b): int {
            $cmp = ((int)($b['match_count'] ?? 0)) <=> ((int)($a['match_count'] ?? 0));
            if ($cmp !== 0) return $cmp;
            return strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? ''));
        });

        $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES);
        if ($candidates === []) {
            return ['results' => [], 'terms' => $terms, 'ai_used' => $aiUsed];
        }

        $ranked = [];
        try {
            $ranked = $this->rankCandidates($query, $terms, $candidates);
            $aiUsed = true;
        } catch (\Throwable) {
            // Fallback determinista debajo.
        }

        if ($ranked === []) {
            $ranked = $this->fallbackRank($candidates);
        }

        return [
            'results' => array_slice($ranked, 0, self::MAX_RESULTS),
            'terms' => $terms,
            'ai_used' => $aiUsed,
        ];
    }

    /** @return array<int,string> */
    private function planTerms(string $query): array
    {
        $system = <<<'TXT'
Eres un planificador de búsqueda de archivos. Convierte la descripción del usuario en pistas cortas que puedan aparecer en el nombre de un archivo, nombre de una carpeta o metadatos extraídos del archivo.
Devuelve únicamente JSON válido con esta forma:
{"terms":["pista1","pista2"]}
Reglas:
- máximo 8 pistas;
- incluye nombres propios, fechas, temas, tipos de documento y extensiones relevantes;
- puedes añadir sinónimos muy cercanos si ayudan a localizar el archivo;
- no inventes rutas, nombres de archivos concretos ni hechos no mencionados;
- no incluyas explicaciones fuera del JSON.
TXT;

        $text = $this->converseText([
            'modelId' => self::MODEL_ID,
            'system' => [['text' => $system]],
            'messages' => [[
                'role' => 'user',
                'content' => [['text' => $query]],
            ]],
            'inferenceConfig' => [
                'maxTokens' => 220,
                'temperature' => 0.0,
                'topP' => 0.2,
            ],
        ]);

        $json = $this->decodeJsonObject($text);
        $rawTerms = is_array($json['terms'] ?? null) ? $json['terms'] : [];
        $terms = [];

        foreach ($rawTerms as $term) {
            if (!is_string($term)) continue;
            $term = $this->normalizeTerm($term);
            if ($term !== '') $terms[] = $term;
        }

        return array_slice(array_values(array_unique($terms)), 0, self::MAX_TERMS);
    }

    /** @return array<string,array<string,mixed>> */
    private function findFilesByTerms(int $userId, array $terms, array $routeLabels): array
    {
        $rows = [];
        $sql = "SELECT id_, Nombre, Tamano, Metadatos, Ruta, Fecha
                FROM FileS3
                WHERE user_id_ = ?
                  AND Found = 1
                  AND (Nombre LIKE ? OR Metadatos LIKE ?)
                ORDER BY Fecha DESC
                LIMIT 50";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la búsqueda IA: ' . $this->db->error);
        }

        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $stmt->bind_param('iss', $userId, $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $id = (int)($row['id_'] ?? 0);
                if ($id <= 0) continue;
                $ref = 'f:' . $id;
                $candidate = $this->fileCandidate($row, $routeLabels, $terms);

                if (!isset($rows[$ref])) {
                    $rows[$ref] = $candidate;
                } else {
                    $rows[$ref]['match_count'] = max(
                        (int)$rows[$ref]['match_count'],
                        (int)$candidate['match_count']
                    );
                }
            }
        }

        $stmt->close();
        return $rows;
    }

    /** @return array<string,array<string,mixed>> */
    private function findFilesInFolder(int $userId, string $prefix, array $routeLabels): array
    {
        $stmt = $this->db->prepare(
            "SELECT id_, Nombre, Tamano, Metadatos, Ruta, Fecha
             FROM FileS3
             WHERE user_id_ = ? AND Found = 1 AND Ruta = ?
             ORDER BY Fecha DESC
             LIMIT 30"
        );

        if (!$stmt) return [];

        $stmt->bind_param('is', $userId, $prefix);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $id = (int)($row['id_'] ?? 0);
            if ($id <= 0) continue;
            $candidate = $this->fileCandidate($row, $routeLabels, []);
            $candidate['match_count'] = max(1, (int)$candidate['match_count']);
            $rows['f:' . $id] = $candidate;
        }

        $stmt->close();
        return $rows;
    }

    private function fileCandidate(array $row, array $routeLabels, array $terms): array
    {
        $id = (int)($row['id_'] ?? 0);
        $name = trim((string)($row['Nombre'] ?? ''));
        $route = (string)($row['Ruta'] ?? '');
        $metadata = (string)($row['Metadatos'] ?? '');
        $visibleRoute = $routeLabels[$route] ?? 'Inicio/';
        $haystack = $name . ' ' . $visibleRoute . ' ' . $metadata;

        return [
            'ref' => 'f:' . $id,
            'tipo' => 'archivo',
            'nombre' => $name !== '' ? $name : 'Archivo sin nombre',
            'ruta' => $route,
            'ruta_visible' => $visibleRoute,
            'metadata' => $this->metadataSnippet($metadata),
            'fecha' => (string)($row['Fecha'] ?? ''),
            'tamano' => max(0, (int)($row['Tamano'] ?? 0)),
            'match_count' => $this->countMatches($haystack, $terms),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function rankCandidates(string $query, array $terms, array $candidates): array
    {
        $catalog = [];
        $byRef = [];

        foreach ($candidates as $candidate) {
            $ref = (string)($candidate['ref'] ?? '');
            if ($ref === '') continue;
            $byRef[$ref] = $candidate;
            $catalog[] = [
                'ref' => $ref,
                'type' => (string)($candidate['tipo'] ?? ''),
                'name' => (string)($candidate['nombre'] ?? ''),
                'visible_path' => (string)($candidate['ruta_visible'] ?? ''),
                'metadata_excerpt' => (string)($candidate['metadata'] ?? ''),
                'date' => (string)($candidate['fecha'] ?? ''),
            ];
        }

        $system = <<<'TXT'
Eres un buscador privado de archivos. Recibirás la descripción del usuario y candidatos obtenidos de su propia base MySQL.
Clasifica únicamente los candidatos proporcionados. No inventes archivos, carpetas ni rutas.
Devuelve únicamente JSON válido:
{"results":[{"ref":"f:1","confidence":85,"reason":"motivo breve"}]}
Reglas:
- máximo 20 resultados;
- confidence es un entero de 0 a 100 y expresa sólo qué tan probable es que ese candidato sea lo buscado;
- usa nombre, ruta visible, fecha y extracto de metadatos como evidencia;
- si un candidato no parece relevante, omítelo;
- razón breve, en español, sin afirmar certeza absoluta.
TXT;

        $payload = json_encode([
            'query' => $query,
            'search_clues' => $terms,
            'candidates' => $catalog,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $text = $this->converseText([
            'modelId' => self::MODEL_ID,
            'system' => [['text' => $system]],
            'messages' => [[
                'role' => 'user',
                'content' => [['text' => $payload]],
            ]],
            'inferenceConfig' => [
                'maxTokens' => 900,
                'temperature' => 0.0,
                'topP' => 0.2,
            ],
        ]);

        $json = $this->decodeJsonObject($text);
        $rankedRaw = is_array($json['results'] ?? null) ? $json['results'] : [];
        $ranked = [];
        $used = [];

        foreach ($rankedRaw as $item) {
            if (!is_array($item)) continue;
            $ref = (string)($item['ref'] ?? '');
            if ($ref === '' || isset($used[$ref]) || !isset($byRef[$ref])) continue;

            $candidate = $byRef[$ref];
            unset($candidate['metadata'], $candidate['match_count'], $candidate['ref']);
            $candidate['confianza'] = max(0, min(100, (int)($item['confidence'] ?? 0)));
            $candidate['motivo'] = trim((string)($item['reason'] ?? 'Coincide con las pistas de búsqueda.'));
            $ranked[] = $candidate;
            $used[$ref] = true;
        }

        return $ranked;
    }

    /** @return array<int,array<string,mixed>> */
    private function fallbackRank(array $candidates): array
    {
        $results = [];
        foreach ($candidates as $candidate) {
            $matches = max(1, (int)($candidate['match_count'] ?? 1));
            unset($candidate['metadata'], $candidate['match_count'], $candidate['ref']);
            $candidate['confianza'] = min(85, 45 + ($matches * 10));
            $candidate['motivo'] = 'Coincide con una o más pistas encontradas en el catálogo.';
            $results[] = $candidate;
        }
        return $results;
    }

    private function converseText(array $params): string
    {
        $response = $this->bedrock->converse($params);
        $blocks = $response['output']['message']['content'] ?? [];
        $text = '';

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                    $text .= $block['text'];
                }
            }
        }

        return trim($text);
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(string $text): array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;

        if (preg_match('/\{.*\}/s', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) return $decoded;
        }

        throw new RuntimeException('Nova Micro no devolvió JSON válido.');
    }

    /** @return array<int,string> */
    private function localTerms(string $query): array
    {
        $normalized = mb_strtolower($query, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}._-]*/u', $normalized, $matches);
        $stop = array_flip([
            'el','la','los','las','un','una','unos','unas','de','del','y','o','en','con','por','para',
            'que','qué','donde','dónde','como','cómo','mi','mis','archivo','archivos','carpeta','carpetas',
            'busco','buscar','quiero','creo','esta','este','ese','esa','algo','sobre','tengo','tenia','tenía'
        ]);

        $terms = [];
        foreach ($matches[0] ?? [] as $term) {
            $term = $this->normalizeTerm((string)$term);
            if ($term === '' || isset($stop[$term])) continue;
            if (mb_strlen($term, 'UTF-8') < 2) continue;
            $terms[] = $term;
            if (count($terms) >= self::MAX_TERMS) break;
        }

        return array_values(array_unique($terms));
    }

    private function mergeTerms(array ...$groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group as $term) {
                if (!is_string($term)) continue;
                $term = $this->normalizeTerm($term);
                if ($term === '' || in_array($term, $out, true)) continue;
                $out[] = $term;
                if (count($out) >= self::MAX_TERMS) return $out;
            }
        }
        return $out;
    }

    private function normalizeTerm(string $term): string
    {
        $term = mb_strtolower(trim($term), 'UTF-8');
        $term = preg_replace('/[^\p{L}\p{N} ._\-]/u', ' ', $term) ?? $term;
        $term = preg_replace('/\s+/u', ' ', $term) ?? $term;
        return trim($term);
    }

    private function countMatches(string $text, array $terms): int
    {
        if ($terms === []) return 0;
        $text = mb_strtolower($text, 'UTF-8');
        $count = 0;
        foreach ($terms as $term) {
            if ($term !== '' && mb_stripos($text, $term, 0, 'UTF-8') !== false) $count++;
        }
        return $count;
    }

    private function metadataSnippet(string $metadata): string
    {
        $metadata = preg_replace('/\s+/u', ' ', trim($metadata)) ?? trim($metadata);
        if ($metadata === '') return '';
        return mb_substr($metadata, 0, self::METADATA_SNIPPET_CHARS, 'UTF-8');
    }

    private function folderLeafName(string $label): string
    {
        $parts = array_values(array_filter(explode('/', trim($label, '/')), static fn(string $part): bool => $part !== ''));
        return $parts !== [] ? (string)end($parts) : 'Inicio';
    }
}
