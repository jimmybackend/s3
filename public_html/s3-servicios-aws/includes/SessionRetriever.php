<?php
/**
 * SessionRetriever.php
 * 
 * Implementa el Retrieval Híbrido para el contexto de sesión con soporte Cross-Session:
 *  1. PRIMORDIALES: Siempre incluidos (busca en TODO el proyecto si hay project_id)
 *  2. RESÚMENES CROSS-SESSION: Niveles 2 y 3 de sesiones anteriores del mismo proyecto
 *  3. SEMÁNTICO: Búsqueda por similitud coseno con embedding (enfocado en la sesión actual)
 *  4. RECENCIA: Últimos N bloques level_0 de la sesión actual
 * 
 * Compatible con PHP 7.x+ y mysqli
 */

class SessionRetriever
{
    /** @var mysqli */
    private $db;
    
    /** @var Aws\BedrockRuntime\BedrockRuntimeClient|null */
    private $bedrock;
    
    /** @var array Configuración por defecto */
    private $config = [
        'top_k_semantic'      => 5,
        'relative_margin'     => 0.15,
        'recency_window'      => 5,
        'min_similarity'      => 0.35,
        'embedding_model'     => 'amazon.titan-embed-text-v2:0',
        'embedding_dimensions'=> 1024,
        'threshold_by_level'  => [
            'primordial' => 0.0,
            'level_0'    => 0.40,
            'level_1'    => 0.35,
            'level_2'    => 0.30,
            'level_3'    => 0.25,
        ],
    ];
    
    public function __construct(mysqli $db, $bedrock = null, array $overrides = [])
    {
        $this->db = $db;
        $this->bedrock = $bedrock;
        $this->config = array_merge($this->config, $overrides);
    }
    
    // =====================================================================
    // FUNCIÓN PRINCIPAL: Retrieval Híbrido (Con soporte Cross-Session)
    // =====================================================================
    
    /**
     * Obtiene el contexto relevante para una pregunta dada
     * 
     * @param int    $sessionId      ID de la sesión activa
     * @param string $userQuestion   Texto de la pregunta actual
     * @param int    $projectId      ID del proyecto (0 si es chat libre). Si > 0, busca primordiales y resúmenes en todo el proyecto.
     * @return array                 Estructura con los grupos de contexto
     */
    public function retrieve(int $sessionId, string $userQuestion, int $projectId = 0): array
    {
        $result = [
            'primordial'            => [],
            'cross_session_summaries'=> [], // NUEVO: Resúmenes de alto nivel de otras sesiones del proyecto
            'semantic_matches'      => [],
            'recent_raw'            => [],
            'total_blocks'          => 0,
            'query_embedding'       => null,
        ];
        
        // 1. Obtener PRIMORDIALES (Si hay projectId, busca en todo el proyecto. Si no, solo en la sesión)
        $result['primordial'] = $this->getPrimordialBlocks($sessionId, $projectId);
        
        // 2. Obtener RESÚMENES CROSS-SESSION (Solo si hay un proyecto activo)
        if ($projectId > 0) {
            $result['cross_session_summaries'] = $this->getCrossSessionSummaries($projectId, $sessionId);
        }
        
        // 3. Obtener RECIENTES (Siempre limitado a la sesión actual para mantener el foco)
        $result['recent_raw'] = $this->getRecentBlocks($sessionId, $this->config['recency_window']);
        
        // 4. Búsqueda SEMÁNTICA (Enfocada en la sesión actual para no saturar tokens con todo el historial del proyecto)
        if (trim($userQuestion) !== '') {
            $queryEmbedding = $this->generateQueryEmbedding($userQuestion);
            $result['query_embedding'] = $queryEmbedding;
            
            if (!empty($queryEmbedding)) {
                $result['semantic_matches'] = $this->getSemanticMatches(
                    $sessionId,
                    $queryEmbedding,
                    $result['primordial'],
                    $result['recent_raw']
                );
            }
        }
        
        // 5. Contar total de bloques recuperados
        $result['total_blocks'] = count($result['primordial']) 
                                + count($result['cross_session_summaries'])
                                + count($result['semantic_matches']) 
                                + count($result['recent_raw']);
        
        return $result;
    }
    
    // =====================================================================
    // 1. PRIMORDIALES (Con soporte Cross-Session)
    // =====================================================================
    
    private function getPrimordialBlocks(int $sessionId, int $projectId = 0): array
    {
        if ($projectId > 0) {
            // Buscar primordiales en TODAS las sesiones del proyecto
            $sql = "
                SELECT 
                    scb.id_ AS block_id, scb.block_type, scb.content_preview, scb.token_count, 
                    scb.is_locked, scb.source_ids, scb.embedding_model,
                    q.id_ AS question_msg_id, q.content AS question_text,
                    a.id_ AS answer_msg_id, a.content AS answer_text, a.is_primordial,
                    scb.created_at, cs.title AS session_title
                FROM SessionContextBlocks scb
                JOIN ChatSessions cs ON scb.session_id_ = cs.id_
                LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
                LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
                WHERE cs.project_id_ = ?
                  AND scb.block_type = 'primordial'
                  AND scb.is_locked = 1
                ORDER BY scb.created_at ASC
            ";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) return [];
            $stmt->bind_param('i', $projectId);
        } else {
            // Fallback: Buscar solo en la sesión actual (chat libre)
            $sql = "
                SELECT 
                    scb.id_ AS block_id, scb.block_type, scb.content_preview, scb.token_count, 
                    scb.is_locked, scb.source_ids, scb.embedding_model,
                    q.id_ AS question_msg_id, q.content AS question_text,
                    a.id_ AS answer_msg_id, a.content AS answer_text, a.is_primordial,
                    scb.created_at, cs.title AS session_title
                FROM SessionContextBlocks scb
                JOIN ChatSessions cs ON scb.session_id_ = cs.id_
                LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
                LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
                WHERE scb.session_id_ = ?
                  AND scb.block_type = 'primordial'
                  AND scb.is_locked = 1
                ORDER BY scb.created_at ASC
            ";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) return [];
            $stmt->bind_param('i', $sessionId);
        }
        
        $stmt->execute();
        $res = $stmt->get_result();
        
        $blocks = [];
        while ($row = $res->fetch_assoc()) {
            $blocks[] = [
                'block_id'        => (int)$row['block_id'],
                'type'            => 'primordial',
                'session_title'   => $row['session_title'] ?? 'Sesión actual',
                'question_id'     => $row['question_msg_id'] ? (int)$row['question_msg_id'] : null,
                'answer_id'       => $row['answer_msg_id'] ? (int)$row['answer_msg_id'] : null,
                'question_text'   => $row['question_text'] ?: '',
                'answer_text'     => $row['answer_text'] ?: '',
                'preview'         => $row['content_preview'] ?: '',
                'token_count'     => (int)$row['token_count'],
                'is_locked'       => (bool)$row['is_locked'],
                'source_ids'      => $row['source_ids'] ? json_decode($row['source_ids'], true) : null,
                'created_at'      => $row['created_at'],
            ];
        }
        $stmt->close();
        
        return $blocks;
    }
    
    // =====================================================================
    // 1.5 RESÚMENES CROSS-SESSION (Nuevos: Nivel 2 y 3 de otras sesiones)
    // =====================================================================
    
    /**
     * Obtiene resúmenes de alto nivel (Nivel 2 y 3) de sesiones anteriores del mismo proyecto.
     * Esto da contexto histórico sin saturar con detalles crudos.
     */
    private function getCrossSessionSummaries(int $projectId, int $currentSessionId): array
    {
        $sql = "
            SELECT 
                scb.id_ AS block_id, scb.block_type, scb.content_preview, scb.token_count, 
                scb.source_ids, cs.title AS session_title, cs.id_ AS source_session_id
            FROM SessionContextBlocks scb
            JOIN ChatSessions cs ON scb.session_id_ = cs.id_
            WHERE cs.project_id_ = ?
              AND scb.session_id_ != ? 
              AND scb.block_type IN ('level_2', 'level_3')
            ORDER BY scb.created_at ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        
        $stmt->bind_param('ii', $projectId, $currentSessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $blocks = [];
        while ($row = $res->fetch_assoc()) {
            $blocks[] = [
                'block_id'        => (int)$row['block_id'],
                'type'            => $row['block_type'],
                'session_title'   => $row['session_title'] ?? 'Sesión anterior',
                'source_session_id'=> (int)$row['source_session_id'],
                'preview'         => $row['content_preview'] ?: '',
                'token_count'     => (int)$row['token_count'],
                'source_ids'      => $row['source_ids'] ? json_decode($row['source_ids'], true) : null,
            ];
        }
        $stmt->close();
        
        return $blocks;
    }
    
    // =====================================================================
    // 2. RECENCIA (últimos N bloques level_0 de la sesión ACTUAL)
    // =====================================================================
    
    private function getRecentBlocks(int $sessionId, int $limit = 5): array
    {
        $sql = "
            SELECT 
                scb.id_ AS block_id, scb.block_type, scb.content_preview, scb.token_count,
                q.id_ AS question_msg_id, q.content AS question_text,
                a.id_ AS answer_msg_id, a.content AS answer_text,
                scb.created_at
            FROM SessionContextBlocks scb
            LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
            LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
            WHERE scb.session_id_ = ?
              AND scb.block_type = 'level_0'
            ORDER BY scb.created_at DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        
        $stmt->bind_param('ii', $sessionId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $blocks = [];
        while ($row = $res->fetch_assoc()) {
            $blocks[] = [
                'block_id'      => (int)$row['block_id'],
                'type'          => 'level_0',
                'question_id'   => $row['question_msg_id'] ? (int)$row['question_msg_id'] : null,
                'answer_id'     => $row['answer_msg_id'] ? (int)$row['answer_msg_id'] : null,
                'question_text' => $row['question_text'] ?: '',
                'answer_text'   => $row['answer_text'] ?: '',
                'preview'       => $row['content_preview'] ?: '',
                'token_count'   => (int)$row['token_count'],
                'created_at'    => $row['created_at'],
            ];
        }
        $stmt->close();
        
        return array_reverse($blocks);
    }
    
    // =====================================================================
    // 3. BÚSQUEDA SEMÁNTICA (top-k con corte relativo, enfocado en sesión actual)
    // =====================================================================
    
    private function getSemanticMatches(
        int $sessionId, 
        array $queryEmbedding, 
        array $primordialBlocks, 
        array $recentBlocks
    ): array {
        $sql = "
            SELECT 
                scb.id_ AS block_id, scb.block_type, scb.content_preview, scb.token_count,
                scb.embedding, scb.embedding_json, scb.embedding_model,
                q.id_ AS question_msg_id, q.content AS question_text,
                a.id_ AS answer_msg_id, a.content AS answer_text,
                scb.created_at
            FROM SessionContextBlocks scb
            LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
            LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
            WHERE scb.session_id_ = ?
              AND (scb.embedding IS NOT NULL OR scb.embedding_json IS NOT NULL)
              AND scb.block_type != 'primordial'
            ORDER BY scb.created_at DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $scoredCandidates = [];
        while ($row = $res->fetch_assoc()) {
            $blockVector = null;
            
            if (!empty($row['embedding'])) {
                $blockVector = $this->binaryBlobToFloats($row['embedding']);
            } elseif (!empty($row['embedding_json'])) {
                $blockVector = json_decode($row['embedding_json'], true);
            }
            
            if (empty($blockVector) || !is_array($blockVector)) {
                continue;
            }
            
            $similarity = $this->cosineSimilarity($queryEmbedding, $blockVector);
            $blockType = $row['block_type'] ?? 'level_0';
            $minThreshold = $this->config['threshold_by_level'][$blockType] ?? 0.35;
            
            if ($similarity < $minThreshold) {
                continue;
            }
            
            $scoredCandidates[] = [
                'block_id'      => (int)$row['block_id'],
                'type'          => $blockType,
                'question_id'   => $row['question_msg_id'] ? (int)$row['question_msg_id'] : null,
                'answer_id'     => $row['answer_msg_id'] ? (int)$row['answer_msg_id'] : null,
                'question_text' => $row['question_text'] ?: '',
                'answer_text'   => $row['answer_text'] ?: '',
                'preview'       => $row['content_preview'] ?: '',
                'token_count'   => (int)$row['token_count'],
                'similarity'    => round($similarity, 4),
                'created_at'    => $row['created_at'],
            ];
        }
        $stmt->close();
        
        usort($scoredCandidates, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        $semanticMatches = [];
        if (!empty($scoredCandidates)) {
            $bestScore = $scoredCandidates[0]['similarity'];
            $cutoff = $bestScore - $this->config['relative_margin'];
            
            foreach ($scoredCandidates as $candidate) {
                if ($candidate['similarity'] < $cutoff) break;
                if (count($semanticMatches) >= $this->config['top_k_semantic']) break;
                $semanticMatches[] = $candidate;
            }
        }
        
        $excludeIds = [];
        foreach ($primordialBlocks as $b) $excludeIds[$b['block_id']] = true;
        foreach ($recentBlocks as $b) $excludeIds[$b['block_id']] = true;
        
        $semanticMatches = array_values(array_filter($semanticMatches, function($b) use ($excludeIds) {
            return !isset($excludeIds[$b['block_id']]);
        }));
        
        return $semanticMatches;
    }
    
    // =====================================================================
    // GENERAR EMBEDDING DE LA PREGUNTA
    // =====================================================================
    
    private function generateQueryEmbedding(string $question): ?array
    {
        if ($this->bedrock === null) {
            try {
                $this->bedrock = $this->initBedrockClient();
            } catch (Throwable $e) {
                error_log('SessionRetriever: No se pudo inicializar Bedrock: ' . $e->getMessage());
                return null;
            }
        }
        
        try {
            $inputText = mb_substr($question, 0, 8000);
            
            $response = $this->bedrock->invokeModel([
                'modelId'     => $this->config['embedding_model'],
                'contentType' => 'application/json',
                'accept'      => 'application/json',
                'body'        => json_encode([
                    'inputText'  => $inputText,
                    'dimensions' => $this->config['embedding_dimensions'],
                    'normalize'  => true,
                ]),
            ]);
            
            $data = json_decode((string)$response['body'], true);
            $embedding = $data['embedding'] ?? null;
            
            if (empty($embedding) || !is_array($embedding)) {
                error_log('SessionRetriever: Bedrock no devolvió embedding válido');
                return null;
            }
            
            return $embedding;
            
        } catch (Throwable $e) {
            error_log('SessionRetriever: Error generando embedding: ' . $e->getMessage());
            return null;
        }
    }
    
    private function initBedrockClient()
    {
        if (!class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient')) {
            throw new RuntimeException('AWS SDK no cargado');
        }
        
        $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) 
                  ? Config::REGION 
                  : 'us-east-1';
        
        $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
        $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');
        
        if (empty($ak) || empty($sk)) {
            throw new RuntimeException('Faltan credenciales AWS');
        }
        
        return new Aws\BedrockRuntime\BedrockRuntimeClient([
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => ['key' => $ak, 'secret' => $sk],
            'http'        => ['connect_timeout' => 10, 'timeout' => 30],
        ]);
    }
    
    // =====================================================================
    // UTILIDADES: Conversión de vectores y similitud coseno
    // =====================================================================
    
    private function binaryBlobToFloats(string $binary): array
    {
        $floats = [];
        $len = strlen($binary);
        for ($i = 0; $i < $len; $i += 4) {
            if ($i + 4 > $len) break;
            $packed = substr($binary, $i, 4);
            $unpacked = unpack('g', $packed);
            if ($unpacked !== false && isset($unpacked[1])) {
                $floats[] = (float)$unpacked[1];
            }
        }
        return $floats;
    }
    
    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0; $normA = 0.0; $normB = 0.0;
        $count = min(count($vecA), count($vecB));
        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }
        if ($normA == 0.0 || $normB == 0.0) return 0.0;
        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
    
    // =====================================================================
    // FORMATEO: Preparar contexto para el Prompt Compiler
    // =====================================================================
    
    public function formatContextForCompiler(array $retrievalResult): string
    {
        $parts = [];
        
        // 1. PRIMORDIALES (Ahora pueden ser de otras sesiones del proyecto)
        if (!empty($retrievalResult['primordial'])) {
            $parts[] = "=== CONTEXTO PRIMORDIAL (Verdad absoluta del usuario, posiblemente de sesiones anteriores) ===";
            foreach ($retrievalResult['primordial'] as $idx => $block) {
                $sessionInfo = $block['session_title'] ? " (Sesión: '{$block['session_title']}')" : "";
                $parts[] = "--- Primordial #" . ($idx + 1) . "{$sessionInfo} ---";
                if (!empty($block['question_text'])) $parts[] = "Pregunta: " . $block['question_text'];
                if (!empty($block['answer_text'])) $parts[] = "Respuesta: " . $block['answer_text'];
                $parts[] = "";
            }
        }
        
        // 1.5 RESÚMENES CROSS-SESSION (NUEVO)
        if (!empty($retrievalResult['cross_session_summaries'])) {
            $parts[] = "=== RESÚMENES DE SESIONES ANTERIORES DEL PROYECTO (Contexto histórico de alto nivel) ===";
            foreach ($retrievalResult['cross_session_summaries'] as $idx => $block) {
                $parts[] = "--- Resumen {$block['type']} de la sesión: '{$block['session_title']}' ---";
                $parts[] = $block['preview'];
                $parts[] = "";
            }
        }
        
        // 2. SEMÁNTICO
        if (!empty($retrievalResult['semantic_matches'])) {
            $parts[] = "=== CONTEXTO SEMÁNTICO (Bloques relevantes de la sesión actual por similitud) ===";
            foreach ($retrievalResult['semantic_matches'] as $idx => $block) {
                $simPercent = round($block['similarity'] * 100, 1);
                $parts[] = "--- Semántico #" . ($idx + 1) . " (Similitud: {$simPercent}%, Tipo: {$block['type']}) ---";
                if (!empty($block['question_text'])) $parts[] = "Pregunta: " . $block['question_text'];
                if (!empty($block['answer_text'])) $parts[] = "Respuesta: " . $block['answer_text'];
                $parts[] = "";
            }
        }
        
        // 3. RECENCIA
        if (!empty($retrievalResult['recent_raw'])) {
            $parts[] = "=== CONTEXTO RECIENTE (Últimas interacciones de esta sesión) ===";
            foreach ($retrievalResult['recent_raw'] as $idx => $block) {
                $parts[] = "--- Reciente #" . ($idx + 1) . " ---";
                if (!empty($block['question_text'])) $parts[] = "Pregunta: " . $block['question_text'];
                if (!empty($block['answer_text'])) $parts[] = "Respuesta: " . $block['answer_text'];
                $parts[] = "";
            }
        }
        
        return implode("\n", $parts);
    }
}