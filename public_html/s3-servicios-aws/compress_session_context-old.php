<?php
/**
 * compress_session_context.php
 *
 * Compresión Jerárquica de Contexto + EXTRACCIÓN DE CONTEXTO DE PROYECTO
 *
 * Niveles de compresión:
 *  - Nivel 0: Q&A crudo (últimos 5 se mantienen sin comprimir)
 *  - Nivel 1: Resumen de cada 5 Q&A (generado por Haiku)
 *  - Nivel 2: Resumen de 4 bloques nivel 1 (~20 Q&A)
 *  - Nivel 3: Resumen de 4 bloques nivel 2 (~80 Q&A)
 *
 * EXTRACCIÓN DE PROYECTO:
 *  - Sincroniza mensajes "primordiales" a ProjectContext.
 *  - Usa Haiku para extraer reglas/decisiones/hechos de los bloques level_1.
 *
 * Uso:
 *  - Cron: * * * * * php compress_session_context.php --secret=TU_SECRET
 *  - Web: compress_session_context.php?key=TU_SECRET&session_id=123
 */
/*
    $compiler_model = 'meta.llama3-1-70b-instruct-v1:0';
    $compiler_model = 'amazon.nova-pro-v1:0'; //  amazon.nova-lite-v1:0   amazon.nova-micro-v1:0
    $compiler_model = 'anthropic.claude-sonnet-4-5-20250929-v1:0';
    // ✅ Compilador experto en seguir instrucciones (rápido y preciso)
    $compiler_model = 'anthropic.claude-haiku-4-5-20251001-v1:0';
*/

define('COMPRESSION_SECRET', 'Z1!xC6@vB3#nM8$kL4*jH9^gF2&dS7');
//define('COMPILER_MODEL', 'anthropic.claude-3-haiku-20240307-v1:0');
define('COMPILER_MODEL', 'amazon.nova-micro-v1:0');
define('MAX_SESSIONS_PER_RUN', 10);
define('RECENT_WINDOW', 5); // Últimos N bloques level_0 que NO se comprimen

@ini_set('max_execution_time', '600');
@set_time_limit(600);
@ignore_user_abort(true);

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $secret = isset($_GET['key']) ? trim($_GET['key']) : '';
    $specificSessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
} else {
    $opts = getopt('', ['secret:', 'session_id:']);
    $secret = isset($opts['secret']) ? trim($opts['secret']) : '';
    $specificSessionId = isset($opts['session_id']) ? (int)$opts['session_id'] : 0;
}

if ($secret !== COMPRESSION_SECRET) {
    if ($isCli) {
        fwrite(STDERR, "Error: clave inválida\n");
        exit(1);
    } else {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Clave inválida']);
        exit;
    }
}

$results = [
    'ok' => true,
    'sessions_processed' => 0,
    'level_0_to_1' => 0,
    'level_1_to_2' => 0,
    'level_2_to_3' => 0,
    'synced_primordial' => 0,
    'extracted_knowledge' => 0,
    'errors' => [],
    'duration_ms' => 0,
];

$startTime = microtime(true);

// ===== Cargar bootstrap =====
try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
    if (!is_file($bootstrap)) {
        $bases = [
            realpath(__DIR__ . '/../../'),
            realpath(__DIR__ . '/../..'),
            realpath(__DIR__ . '/../../../'),
            realpath(__DIR__ . '/../'),
        ];
        foreach ($bases as $b) {
            if ($b && is_file($b . '/app_bootstrap.php')) {
                $bootstrap = $b . '/app_bootstrap.php';
                break;
            }
        }
    }
    if (!is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado.');
    require_once $bootstrap;
} catch (Throwable $e) {
    $results['ok'] = false;
    $results['errors'][] = 'bootstrap: ' . $e->getMessage();
    finish($results, $isCli, $startTime);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    $results['ok'] = false;
    $results['errors'][] = 'DB no disponible';
    finish($results, $isCli, $startTime);
}

// ✅ CORRECCIÓN CRÍTICA: Forzar utf8mb4 para evitar errores de collation
if (!$db_connection->set_charset('utf8mb4')) {
    $results['errors'][] = 'Error charset utf8mb4: ' . $db_connection->error;
    finish($results, $isCli, $startTime);
}

if (!class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient')) {
    $results['ok'] = false;
    $results['errors'][] = 'AWS SDK no cargado';
    finish($results, $isCli, $startTime);
}

// ===== Locking =====
$lockFile = sys_get_temp_dir() . '/compression_queue.lock';
$lockFp = fopen($lockFile, 'w');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $results['message'] = 'Otro proceso ya está ejecutando la compresión';
    finish($results, $isCli, $startTime);
}

// ===== Inicializar Bedrock =====
try {
    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
    $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');
    if (empty($ak) || empty($sk)) throw new RuntimeException('Faltan credenciales AWS');
    
    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => ['key' => $ak, 'secret' => $sk],
        'http'        => ['connect_timeout' => 20, 'timeout' => 120],
    ]);
} catch (Throwable $e) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $results['ok'] = false;
    $results['errors'][] = 'Bedrock init: ' . $e->getMessage();
    finish($results, $isCli, $startTime);
}

// ===== Función: Generar resumen fusionando los content_preview existentes =====
function compressWithHaiku($bedrock, array $blocks, string $targetLevel, mysqli $db, int $sessionId): string {
    // 1. ✅ DETECCIÓN DINÁMICA DE CONTENIDO
    // Concatenamos todo el texto de los bloques para analizar si hay código o temas técnicos
    $allText = '';
    foreach ($blocks as $block) {
        $allText .= ($block['content_preview'] ?? '') . ' ' . ($block['question_text'] ?? '') . ' ' . ($block['answer_text'] ?? '');
    }
    
    // Heurística robusta para detectar código, configuraciones o temas técnicos
    $isCode = preg_match('/\b(function|class|const|let|var|import|export|return|if\s*\(|echo|print|<\?php|=>|<div|<script|error|bug|c[oó]digo|archivo|file|script|variable|array|json|php|js|html|css|sql|query|database|bd|api|endpoint|config|route|controller|model)\b/i', $allText);
    
    // Seleccionar el modelo: Haiku para código (precisión quirúrgica), Nova Micro para texto general (ahorro)
    $modelId = $isCode ? 'anthropic.claude-3-5-haiku-20241022-v1:0' : 'amazon.nova-micro-v1:0';

    // 2. ✅ PROMPT MEJORADO: Reglas críticas en mayúsculas para forzar la atención del modelo
    $content = "Tu tarea es fusionar los siguientes bloques de conversación (que ya están pre-resumidos) en un solo resumen coherente, fluido y conciso de la sesión. No repitas información.
    
REGLAS CRÍTICAS DE PRESERVACIÓN:
1. Preserva términos técnicos, nombres, fechas y decisiones de arquitectura.
2. REGLA CRÍTICA: NUNCA omitas valores de variables, rutas de archivos, puertos, IPs o credenciales mencionadas. Preserva los datos técnicos exactos (strings, números, rutas) intactos.
3. Si hay código o comandos, mantén la sintaxis exacta.

A continuación, los bloques a fusionar:\n";

    foreach ($blocks as $idx => $block) {
        $content .= "--- Bloque " . ($idx + 1) . " ---\n";
        if (!empty($block['content_preview'])) {
            $content .= "Resumen pre-existente: " . $block['content_preview'] . "\n";
        } else {
            if (!empty($block['question_text'])) $content .= "Pregunta: " . $block['question_text'] . "\n";
            if (!empty($block['answer_text'])) $content .= "Respuesta: " . $block['answer_text'] . "\n";
        }
        $content .= "\n";
    }
    
    $content .= "\nGenera el resumen final unificado de la sesión en el mismo idioma que el contenido original, aplicando estrictamente las reglas críticas:";
    
    // 3. ✅ LLAMADA A BEDROCK CON EL MODELO DINÁMICO
    $res = $bedrock->converse([
        'modelId' => $modelId,
        'messages' => [['role' => 'user', 'content' => [['text' => $content]]]],
        'inferenceConfig' => ['maxTokens' => 1500, 'temperature' => 0.2, 'topP' => 0.9] // Temp 0.2 para máxima precisión factual
    ]);
    
    $summary = '';
    foreach (($res['output']['message']['content'] ?? []) as $block) {
        if (isset($block['text'])) $summary .= $block['text'];
    }
    
    $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
    $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

    try {
        $tcId = 0;
        $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM TokenUsage");
        if ($rs) { $tcId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }
        
        $tcPhase = 'compile';
        $tcModel = $modelId; // ✅ USAR EL MODELO REAL DETECTADO (no el constante COMPILER_MODEL) 
        
        // ✅ CALCULAR COSTO SEGÚN EL MODELO REAL USADO
        $isHaiku = stripos($tcModel, 'haiku') !== false;
        if ($isHaiku) {
            // IMPORTANTE: Estos precios son para "Claude 3 Haiku" (NO 3.5 Haiku)
            // Model ID: anthropic.claude-3-haiku-20240307-v1:0
            // Precio: $0.25 por 1M input | $1.25 por 1M output
            $tcCost = ($inputTokens / 1000000 * 0.25) + ($outputTokens / 1000000 * 1.25);
            
            /* 
            NOTA: Si en el futuro usas "Claude 3.5 Haiku" (anthropic.claude-3-5-haiku-20241022-v1:0), 
            los precios cambian a: $0.80 input / $4.00 output.
            La fórmula sería: ($inputTokens / 1000000 * 0.80) + ($outputTokens / 1000000 * 4.00);
            */
        } else {
            // Precios Amazon Nova Micro
            // Model ID: amazon.nova-micro-v1:0
            // Precio: $0.035 por 1M input | $0.14 por 1M output
            $tcCost = ($inputTokens / 1000000 * 0.035) + ($outputTokens / 1000000 * 0.14);
        }
        // Opcional: Redondear a 6 decimales para evitar problemas de punto flotante en PHP
        $tcCost = round($tcCost, 6);
        
        $tcDuration = 0;
        $tcMsgId = null; // NULL real de PHP

        // ✅ 9 signos de interrogación
        $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtTC = $db->prepare($sqlTC);
        if ($stmtTC) {
            // ✅ 9 tipos ("iiissiidi") y 9 variables exactamente
            $stmtTC->bind_param("iiissiidi", $tcId, $sessionId, $tcMsgId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $tcDuration);
            $stmtTC->execute();
            $stmtTC->close();
        }
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/token_usage_debug.log', "[" . date('Y-m-d H:i:s') . "] " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
    
    return trim($summary);
}


// ===== Función: Obtener sesiones que necesitan compresión =====
// ✅ ANTI-CICLO: ahora se apoya en el flag ChatSessions.pending_summary, que
// bedrock_chat2.php enciende cuando una sesión acumula más de RECENT_WINDOW
// bloques level_0 nuevos. Se evita el JOIN + GROUP BY + COUNT sobre todas las
// sesiones abiertas, y una sesión sale de la cola en cuanto el cron la procesa
// y apaga el flag (ver el foreach principal más abajo).
function getSessionsNeedingCompression(mysqli $db, int $limit): array {
    $sql = "
        SELECT cs.id_, cs.context_level, cs.last_compressed_at
        FROM ChatSessions cs
        WHERE cs.status = 'open'
        AND cs.pending_summary = 1
        ORDER BY cs.last_compressed_at ASC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $sessions = [];
    while ($row = $res->fetch_assoc()) $sessions[] = $row;
    $stmt->close();
    return $sessions;
}

// ===== Función: Comprimir nivel 0 → nivel 1 =====
// ===== Función: Comprimir nivel 0 → nivel 1 =====
function compressLevel0ToLevel1(mysqli $db, $bedrock, int $sessionId): int {
    $sql = "
        SELECT scb.id_, scb.content_preview,
        q.content AS question_text, a.content AS answer_text
        FROM SessionContextBlocks scb
        LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
        LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
        WHERE scb.session_id_ = ? 
          AND scb.block_type = 'level_0' 
          AND scb.is_locked = 0
          AND scb.embedding_json IS NOT NULL -- ✅ CRÍTICO: Solo comprimir bloques YA procesados por la cola de embeddings
        ORDER BY scb.created_at ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $allBlocks = [];
    while ($row = $res->fetch_assoc()) $allBlocks[] = $row;
    $stmt->close();

    if (count($allBlocks) <= RECENT_WINDOW) return 0;

    $blocksToCompress = array_slice($allBlocks, 0, count($allBlocks) - RECENT_WINDOW);
    if (empty($blocksToCompress)) return 0;

    $chunks = array_chunk($blocksToCompress, 5);
    $compressedCount = 0;

    foreach ($chunks as $chunk) {
        $summary = compressWithHaiku($bedrock, $chunk, 'level_1', $db, $sessionId);
        if (empty($summary)) continue;

        $sourceIds = array_map(function($b) { return 'q' . ($b['id_'] ?? '?') . '_a' . ($b['id_'] ?? '?'); }, $chunk);
        $tokenCount = (int)ceil(mb_strlen($summary) / 4);
        
        $fullSummary = $summary; 
        $sourceIdsJson = json_encode($sourceIds);

        $nextId = 0;
        $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM SessionContextBlocks");
        if ($rs) { $nextId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }

        $stmtInsert = $db->prepare("INSERT INTO SessionContextBlocks (id_, session_id_, block_type, content_preview, source_ids, token_count, is_locked) VALUES (?, ?, 'level_1', ?, ?, ?, 0)");
        $stmtInsert->bind_param('iissi', $nextId, $sessionId, $fullSummary, $sourceIdsJson, $tokenCount);
        $stmtInsert->execute();
        $stmtInsert->close();

        $idsToDelete = array_column($chunk, 'id_');
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $types = str_repeat('i', count($idsToDelete));
            $stmtDel = $db->prepare("DELETE FROM SessionContextBlocks WHERE id_ IN ($placeholders)");
            $stmtDel->bind_param($types, ...$idsToDelete);
            $stmtDel->execute();
            $stmtDel->close();
        }
        $compressedCount++;
    }
    return $compressedCount;
}

// ===== Función: Comprimir nivel 1 → nivel 2 =====
function compressLevel1ToLevel2(mysqli $db, $bedrock, int $sessionId): int {
    $sql = "SELECT id_, content_preview, source_ids FROM SessionContextBlocks WHERE session_id_ = ? AND block_type = 'level_1' ORDER BY created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $blocks = [];
    while ($row = $res->fetch_assoc()) $blocks[] = $row;
    $stmt->close();

    if (count($blocks) < 4) return 0;

    $chunk = array_slice($blocks, 0, 4);
    $contentForHaiku = [];
    foreach ($chunk as $idx => $block) {
        $contentForHaiku[] = ['id_' => $block['id_'], 'question_text' => "Summary block " . ($idx + 1), 'answer_text' => $block['content_preview']];
    }

    $summary = compressWithHaiku($bedrock, $contentForHaiku, 'level_2', $db, $sessionId);
    if (empty($summary)) return 0;

    $allSourceIds = [];
    foreach ($chunk as $block) {
        $ids = json_decode($block['source_ids'], true);
        if (is_array($ids)) $allSourceIds = array_merge($allSourceIds, $ids);
    }

    $tokenCount = (int)ceil(mb_strlen($summary) / 4);
    
    // ✅ CAMBIO: Guardamos el resumen COMPLETO, no truncado a 300 caracteres
    $fullSummary = $summary;
    $sourceIdsJson = json_encode($allSourceIds);

    $nextId = 0;
    $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM SessionContextBlocks");
    if ($rs) { $nextId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }

    $stmtInsert = $db->prepare("INSERT INTO SessionContextBlocks (id_, session_id_, block_type, content_preview, source_ids, token_count, is_locked) VALUES (?, ?, 'level_2', ?, ?, ?, 0)");
    // ✅ CAMBIO: Pasamos $fullSummary en lugar de un preview truncado
    $stmtInsert->bind_param('iissi', $nextId, $sessionId, $fullSummary, $sourceIdsJson, $tokenCount);
    $stmtInsert->execute();
    $stmtInsert->close();

    $idsToDelete = array_column($chunk, 'id_');
    $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
    $types = str_repeat('i', count($idsToDelete));
    $stmtDel = $db->prepare("DELETE FROM SessionContextBlocks WHERE id_ IN ($placeholders)");
    $stmtDel->bind_param($types, ...$idsToDelete);
    $stmtDel->execute();
    $stmtDel->close();

    return 1;
}

// ===== Función: Comprimir nivel 2 → nivel 3 =====
function compressLevel2ToLevel3(mysqli $db, $bedrock, int $sessionId): int {
    $sql = "SELECT id_, content_preview, source_ids FROM SessionContextBlocks WHERE session_id_ = ? AND block_type = 'level_2' ORDER BY created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $blocks = [];
    while ($row = $res->fetch_assoc()) $blocks[] = $row;
    $stmt->close();

    if (count($blocks) < 4) return 0;

    $chunk = array_slice($blocks, 0, 4);
    $contentForHaiku = [];
    foreach ($chunk as $idx => $block) {
        $contentForHaiku[] = ['id_' => $block['id_'], 'question_text' => "Macro summary " . ($idx + 1), 'answer_text' => $block['content_preview']];
    }

    $summary = compressWithHaiku($bedrock, $contentForHaiku, 'level_3', $db, $sessionId);
    if (empty($summary)) return 0;

    $allSourceIds = [];
    foreach ($chunk as $block) {
        $ids = json_decode($block['source_ids'], true);
        if (is_array($ids)) $allSourceIds = array_merge($allSourceIds, $ids);
    }

    $tokenCount = (int)ceil(mb_strlen($summary) / 4);
    
    // ✅ CAMBIO: Guardamos el resumen COMPLETO, no truncado a 300 caracteres
    $fullSummary = $summary;
    $sourceIdsJson = json_encode($allSourceIds);

    $nextId = 0;
    $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM SessionContextBlocks");
    if ($rs) { $nextId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }

    $stmtInsert = $db->prepare("INSERT INTO SessionContextBlocks (id_, session_id_, block_type, content_preview, source_ids, token_count, is_locked) VALUES (?, ?, 'level_3', ?, ?, ?, 0)");
    // ✅ CAMBIO: Pasamos $fullSummary en lugar de un preview truncado
    $stmtInsert->bind_param('iissi', $nextId, $sessionId, $fullSummary, $sourceIdsJson, $tokenCount);
    $stmtInsert->execute();
    $stmtInsert->close();

    $idsToDelete = array_column($chunk, 'id_');
    $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
    $types = str_repeat('i', count($idsToDelete));
    $stmtDel = $db->prepare("DELETE FROM SessionContextBlocks WHERE id_ IN ($placeholders)");
    $stmtDel->bind_param($types, ...$idsToDelete);
    $stmtDel->execute();
    $stmtDel->close();

    return 1;
}

// =======================================================================
// ✅ NUEVAS FUNCIONES: EXTRACCIÓN DE CONTEXTO DE PROYECTO
// =======================================================================

/**
 * Sincroniza los mensajes marcados como "primordiales" (estrella dorada) 
 * directamente a la tabla ProjectContext como reglas absolutas.
 */
function syncPrimordialRules(mysqli $db): int {
    $sql = "
        SELECT cm.id_, cm.content, cm.created_at, cs.project_id_
        FROM ChatMessages cm
        JOIN ChatSessions cs ON cm.session_id_ = cs.id_
        WHERE cm.is_primordial = 1 AND cm.role = 'assistant' AND cs.project_id_ IS NOT NULL AND cs.status = 'open'
        AND NOT EXISTS (
            SELECT 1 FROM ProjectContext pc 
            WHERE pc.project_id_ = cs.project_id_ AND pc.source_chunk_id = cm.id_
        )
    ";
    $res = $db->query($sql);
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $projectId = (int)$row['project_id_'];
        $msgId = (int)$row['id_'];
        $content = trim($row['content']);
        if (empty($content)) continue;

        $title = mb_substr($content, 0, 50) . (mb_strlen($content) > 50 ? '...' : '');
        $type = 'rule'; // Los primordiales son reglas absolutas

$stmtIns = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, ?, ?, ?, ?)");
if ($stmtIns) {
    $stmtIns->bind_param('isssi', $projectId, $type, $title, $content, $msgId);
    if ($stmtIns->execute()) {
        $count++;
        // ✅ NUEVO: Obtener el ID del registro recién insertado
        $newProjectContextId = $stmtIns->insert_id;
        
        // ✅ NUEVO: Encolar trabajo de embedding para este nuevo contexto
        $jobId = 0;
        $rsJob = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM EmbeddingJobs");
        if ($rsJob) { $jobId = (int)($rsJob->fetch_assoc()['nxt'] ?? 1); $rsJob->free(); }
        
        $stmtJob = $db->prepare("INSERT INTO EmbeddingJobs (id_, target_type, target_id, model_id, status, attempts) VALUES (?, 'project_context', ?, 'amazon.titan-embed-text-v2:0', 'pending', 0)");
        if ($stmtJob) {
            $stmtJob->bind_param('ii', $jobId, $newProjectContextId);
            $stmtJob->execute();
            $stmtJob->close();
        }
    }
    $stmtIns->close();
}
    }
    $res->free();
    return $count;
}

/**
 * Extrae conocimiento tácito (reglas, decisiones, hechos, tareas) 
 * de los bloques de sesión (level_0 y level_1) usando el modelo compilador.
 */
/**
 * Extrae conocimiento tácito (reglas, decisiones, hechos, tareas)
 * de los bloques de sesión comprimidos usando el modelo compilador.
 */
function extractKnowledgeFromSessions(mysqli $db, $bedrock): int {
    // ✅ CRÍTICO: Solo extraer de bloques comprimidos (level_1+) 
    // Y que NO hayan sido procesados antes (NOT EXISTS).
    $sql = "
        SELECT scb.id_, scb.session_id_, scb.answer_msg_id, scb.content_preview, cs.project_id_
        FROM SessionContextBlocks scb
        JOIN ChatSessions cs ON scb.session_id_ = cs.id_
        WHERE scb.block_type IN ('level_1', 'level_2', 'level_3') 
          AND cs.project_id_ IS NOT NULL 
          AND cs.status = 'open'
          AND NOT EXISTS (
              SELECT 1 FROM ProjectContext pc 
              WHERE pc.project_id_ = cs.project_id_ AND pc.source_chunk_id = scb.id_
          )
        ORDER BY scb.created_at DESC
        LIMIT 30
    ";
    
    $res = $db->query($sql);
    $blocksByProject = [];
    while ($row = $res->fetch_assoc()) {
        $pid = (int)$row['project_id_'];
        if (!isset($blocksByProject[$pid])) $blocksByProject[$pid] = [];
        $blocksByProject[$pid][] = $row;
    }
    $res->free();
    
    $totalExtracted = 0;
    
    foreach ($blocksByProject as $projectId => $blocks) {
        if (empty($blocks)) continue;
        
        $textForModel = "";
        $blockIds = [];
        foreach ($blocks as $idx => $b) {
            $textForModel .= "--- Bloque " . ($idx + 1) . " (ID: " . $b['id_'] . ") ---\n" . $b['content_preview'] . "\n";
            $blockIds[] = (int)$b['id_'];
        }

        $systemPrompt = "Eres un extractor de conocimiento técnico experto. Analiza los siguientes bloques de conversaciones de un proyecto de software.
REGLAS ESTRICTAS:
1. Extrae SOLO información valiosa y reutilizable: reglas de negocio, decisiones de arquitectura, hechos técnicos importantes.
2. IGNORA mensajes genéricos de confirmación, saludos, errores de conexión o bloques <thinking>.
3. NO extraigas código completo, solo describe qué hace y por qué es importante.
4. Si no hay nada relevante, devuelve exactamente esto: []
Devuelve ÚNICAMENTE un array JSON válido. No incluyas explicaciones, ni markdown, ni texto antes o después del array.
Formato de cada objeto:
- \"type\": 'rule', 'decision', 'fact', 'todo'
- \"title\": título corto (máx 50 caracteres)
- \"content\": descripción detallada (máx 500 caracteres)";

        $userPrompt = "Bloques a analizar:\n" . $textForModel;

        try {
            $aiRes = $bedrock->converse([
                'modelId' => COMPILER_MODEL,
                'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
                'system' => [['text' => $systemPrompt]],
                'inferenceConfig' => ['maxTokens' => 1500, 'temperature' => 0.2, 'topP' => 0.9]
            ]);
            
            $aiText = '';
            foreach (($aiRes['output']['message']['content'] ?? []) as $block) {
                if (isset($block['text'])) $aiText .= $block['text'];
            }

            // ✅ REGISTRO DE TOKENS
            try {
                $inputTokens = (int)($aiRes['usage']['inputTokens'] ?? 0);
                $outputTokens = (int)($aiRes['usage']['outputTokens'] ?? 0);
                
                if ($inputTokens > 0 || $outputTokens > 0) {
                    $tcId = 0;
                    $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM TokenUsage");
                    if ($rs) { 
                        $tcId = (int)($rs->fetch_assoc()['nxt'] ?? 1); 
                        $rs->free(); 
                    }
                    
                    $tcPhase = 'compile';
                    $tcModel = COMPILER_MODEL;
                    $tcCost = ($inputTokens / 1000 * 0.000035) + ($outputTokens / 1000 * 0.00014);
                    $tcDuration = 0;
                    
                    $rawMsgId = $blocks[0]['answer_msg_id'] ?? null;
                    $tcMsgId = ($rawMsgId !== null && $rawMsgId > 0) ? (int)$rawMsgId : null;
                    $logSessionId = (int)($blocks[0]['session_id_'] ?? 0);

                    $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmtTC = $db->prepare($sqlTC);
                    if ($stmtTC) {
                        $stmtTC->bind_param("iiissiidi", $tcId, $logSessionId, $tcMsgId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $tcDuration);
                        $stmtTC->execute();
                        $stmtTC->close();
                    }
                }
            } catch (Throwable $e) {
                @file_put_contents(__DIR__ . '/token_usage_debug.log', "[" . date('Y-m-d H:i:s') . "] TokenUsage (Extract Knowledge): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            }

            // ✅ LIMPIEZA DE JSON ROBUSTA
            $jsonStr = trim($aiText);
            if (preg_match('/\[[\s\S]*\]/', $jsonStr, $matches)) {
                $jsonStr = $matches[0];
            }
            $jsonStr = preg_replace('/^```json\s*/i', '', $jsonStr);
            $jsonStr = preg_replace('/\s*```$/i', '', $jsonStr);
            
            $items = json_decode($jsonStr, true);

            if ($items === null) {
                // ❌ Error de JSON: Marcamos como procesados para no volver a intentarlo con el mismo error
                foreach ($blockIds as $bId) {
                    $stmtMark = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, 'note', 'Error de procesamiento', 'El bloque no pudo ser analizado correctamente.', ?)");
                    if ($stmtMark) {
                        $stmtMark->bind_param('ii', $projectId, $bId);
                        $stmtMark->execute();
                        $stmtMark->close();
                    }
                }
            } else {
                // ✅ Ya sea array vacío o con elementos, procesamos la extracción
                if (is_array($items) && count($items) > 0) {
                    foreach ($items as $item) {
                        $type = $item['type'] ?? 'fact';
                        if (!in_array($type, ['rule', 'decision', 'fact', 'style', 'todo', 'note'])) {
                            $type = 'fact';
                        }
                        
                        $title = trim($item['title'] ?? 'Sin título');
                        $content = trim($item['content'] ?? '');
                        if (empty($content) || mb_strlen($content) < 20) continue;

                        $checkStmt = $db->prepare("SELECT id_ FROM ProjectContext WHERE project_id_ = ? AND content = ? LIMIT 1");
                        $checkStmt->bind_param('is', $projectId, $content);
                        $checkStmt->execute();
                        $exists = $checkStmt->get_result()->fetch_assoc();
                        $checkStmt->close();
                        if ($exists) continue;

                        $sourceId = $blockIds[0] ?? null;
                        $stmtIns = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, ?, ?, ?, ?)");
                        if ($stmtIns) {
                            $stmtIns->bind_param('isssi', $projectId, $type, $title, $content, $sourceId);
                            if ($stmtIns->execute()) {
                                $totalExtracted++;
                                $newProjectContextId = $stmtIns->insert_id;
                                
                                $jobId = 0;
                                $rsJob = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM EmbeddingJobs");
                                if ($rsJob) { 
                                    $jobId = (int)($rsJob->fetch_assoc()['nxt'] ?? 1); 
                                    $rsJob->free(); 
                                }
                                
                                $stmtJob = $db->prepare("INSERT INTO EmbeddingJobs (id_, target_type, target_id, model_id, status, attempts) VALUES (?, 'project_context', ?, 'amazon.titan-embed-text-v2:0', 'pending', 0)");
                                if ($stmtJob) {
                                    $stmtJob->bind_param('ii', $jobId, $newProjectContextId);
                                    $stmtJob->execute();
                                    $stmtJob->close();
                                }
                            }
                            $stmtIns->close();
                        }
                    }
                }
                
                // ✅ CRÍTICO: Marcar TODOS los bloques del lote como "ya analizados" 
                // para que la condición NOT EXISTS los ignore en el futuro y se rompa el bucle.
                foreach ($blockIds as $bId) {
                    $stmtMark = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, 'note', 'Analizado', 'Bloque ya procesado por el extractor de conocimiento.', ?)");
                    if ($stmtMark) {
                        $stmtMark->bind_param('ii', $projectId, $bId);
                        $stmtMark->execute();
                        $stmtMark->close();
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("Error extrayendo conocimiento para proyecto $projectId: " . $e->getMessage());
        }
    }
    
    return $totalExtracted;
}

// =======================================================================
// ✅ NUEVA FUNCIÓN: GENERAR RESUMEN MAESTRO (META-RESUMEN) CON IA
// =======================================================================
/**
 * Genera un resumen maestro y coherente de toda la sesión a partir de los bloques.
 * Registra el uso de tokens y costos en TokenUsage.
 */
function generateSessionMetaSummary(mysqli $db, $bedrock, int $sessionId, array $blocks, string $previousSummary = ''): string {
    if (empty($blocks)) return '';
    
    $content = "Tu tarea es crear un resumen maestro, coherente y fluido de toda la sesión de conversación.\n";
    
    // ✅ NUEVA LÓGICA: Si ya existe un resumen, lo usamos como base y pedimos a la IA que lo fusione
    if (!empty($previousSummary)) {
        $content .= "RESUMEN ANTERIOR (Ya existe, debes integrarlo y actualizarlo con la nueva información, no lo repitas tal cual, fusionalo en una sola narrativa):\n" . $previousSummary . "\n\n";
    }
    
    $content .= "NUEVOS BLOQUES DE CONVERSACIÓN (Integra esta nueva información al resumen maestro de forma fluida):\n";
    foreach ($blocks as $idx => $block) {
        $content .= "--- Bloque " . ($idx + 1) . " (" . strtoupper($block['block_type']) . ") ---\n";
        $content .= ($block['content_preview'] ?: 'Sin contenido') . "\n";
    }
    $content .= "\nGenera el resumen maestro final unificado en el mismo idioma que el contenido original. Debe ser un texto fluido y bien redactado, NO una lista de viñetas.";

    try {
        $res = $bedrock->converse([
            'modelId' => COMPILER_MODEL,
            'messages' => [['role' => 'user', 'content' => [['text' => $content]]]],
            'inferenceConfig' => ['maxTokens' => 2000, 'temperature' => 0.3, 'topP' => 0.9]
        ]);
        
        $summary = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $summary .= $block['text'];
        }
        
        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);
        $isNovaMicro = stripos(COMPILER_MODEL, 'nova-micro') !== false;
        $tcCost = $isNovaMicro ? ($inputTokens / 1000 * 0.000035) + ($outputTokens / 1000 * 0.00014) : ($inputTokens / 1000 * 0.00025) + ($outputTokens / 1000 * 0.00125);
        
        $tcId = 0;
        $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM TokenUsage");
        if ($rs) { $tcId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }
        
        $modelIdVar = COMPILER_MODEL;
        $msgIdVar = null; 
        $phaseVar = 'compile'; 
        $durationVar = 0;

        // ✅ 9 signos de interrogación
        $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtTC = $db->prepare($sqlTC);
        if ($stmtTC) {
            // ✅ 9 tipos ("iisssiiidi") y 9 variables exactamente
            $stmtTC->bind_param("iiissiidi", $tcId, $sessionId, $msgIdVar, $phaseVar, $modelIdVar, $inputTokens, $outputTokens, $tcCost, $durationVar);
            $stmtTC->execute();
            $stmtTC->close();
        }
        return trim($summary);
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/token_usage_debug.log', "[" . date('Y-m-d H:i:s') . "] MetaSummary Error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return '';
    }
}


// ===== Procesar sesiones (Compresión) =====
$sessions = getSessionsNeedingCompression($db_connection, MAX_SESSIONS_PER_RUN);
if (empty($sessions)) {
    $results['message'] = 'No hay sesiones que necesiten compresión (Recuerda: se requieren más de ' . RECENT_WINDOW . ' bloques level_0 para activar la compresión y guardar los últimos ' . RECENT_WINDOW . ' en crudo).';
} else {
    foreach ($sessions as $session) {
        $sessionId = (int)$session['id_'];
        try {
            // 1. Ejecutar compresiones jerárquicas
            $compressedL1 = compressLevel0ToLevel1($db_connection, $bedrock, $sessionId);
            $compressedL2 = compressLevel1ToLevel2($db_connection, $bedrock, $sessionId);
            $compressedL3 = compressLevel2ToLevel3($db_connection, $bedrock, $sessionId);
            
            $results['level_0_to_1'] += $compressedL1;
            $results['level_1_to_2'] += $compressedL2;
            $results['level_2_to_3'] += $compressedL3;
            
            $newLevel = (int)$session['context_level'];
            if ($compressedL3 > 0) $newLevel = 3;
            elseif ($compressedL2 > 0) $newLevel = 2;
            elseif ($compressedL1 > 0) $newLevel = 1;
            
            // 2. Leer todos los bloques actuales
            $stmtSummary = $db_connection->prepare("SELECT block_type, content_preview, token_count FROM SessionContextBlocks WHERE session_id_ = ? ORDER BY created_at ASC LIMIT 30");
            $stmtSummary->bind_param('i', $sessionId);
            $stmtSummary->execute();
            $resSummary = $stmtSummary->get_result();
            $allBlocksForMeta = [];
            $totalTokens = 0;
            while ($block = $resSummary->fetch_assoc()) {
                $allBlocksForMeta[] = $block;
                $totalTokens += (int)$block['token_count'];
            }
            $stmtSummary->close();
            
            // 3. ✅ NUEVO: Obtener el resumen previo existente en ChatSessions
            $prevSummary = '';
            $stmtPrev = $db_connection->prepare("SELECT context_summary FROM ChatSessions WHERE id_ = ?");
            $stmtPrev->bind_param('i', $sessionId);
            $stmtPrev->execute();
            $resPrev = $stmtPrev->get_result();
            if ($rowPrev = $resPrev->fetch_assoc()) {
                $prevSummary = $rowPrev['context_summary'] ?? '';
            }
            $stmtPrev->close();

            // 3b. ✅ ANTI-CICLO: ¿Hay bloques realmente nuevos desde la última compresión?
            // Sin esto, una sesión estancada en el límite de RECENT_WINDOW nunca sale
            // de la cola: se regenera el mismo resumen con Bedrock en cada corrida del
            // cron y el UPDATE de last_compressed_at solo la reordena, no la libera.
            $hayBloquesNuevos = false;
            if ($session['last_compressed_at'] === null) {
                // Nunca se comprimió: si existe al menos un bloque, es "nuevo" para el resumen.
                $hayBloquesNuevos = !empty($allBlocksForMeta);
            } else {
                $stmtCheck = $db_connection->prepare(
                    "SELECT COUNT(*) AS c FROM SessionContextBlocks WHERE session_id_ = ? AND created_at > ?"
                );
                $stmtCheck->bind_param('is', $sessionId, $session['last_compressed_at']);
                $stmtCheck->execute();
                $rowCheck = $stmtCheck->get_result()->fetch_assoc();
                $hayBloquesNuevos = ((int)($rowCheck['c'] ?? 0)) > 0;
                $stmtCheck->close();
            }

            $huboCompresion = ($compressedL1 + $compressedL2 + $compressedL3) > 0;

            if (!$huboCompresion && !$hayBloquesNuevos) {
                // Nada nuevo que resumir: no llamar a Bedrock, no tocar last_compressed_at.
                // Así la sesión no vuelve a la cabeza de la cola (ORDER BY last_compressed_at ASC)
                // solo porque le tocó turno; queda esperando contenido genuinamente nuevo.
                continue;
            }

            // 4. Generar resumen maestro (Fusionando previo + nuevos bloques)
            $metaSummary = '';
            if (!empty($allBlocksForMeta)) {
                $metaSummary = generateSessionMetaSummary($db_connection, $bedrock, $sessionId, $allBlocksForMeta, $prevSummary);
            }
            
            // 5. Actualizar ChatSessions
            if (!empty($metaSummary)) {
                $contextSummary = "🧠 Memoria Consolidada (~{$totalTokens} tokens):\n" . $metaSummary;
                $contextSummary = mb_substr($contextSummary, 0, 15000);
                $finalLevel = max($newLevel, 1);
                
                $stmtUpdSummary = $db_connection->prepare("UPDATE ChatSessions SET context_summary = ?, context_level = ?, last_compressed_at = NOW() WHERE id_ = ?");
                $stmtUpdSummary->bind_param('sii', $contextSummary, $finalLevel, $sessionId);
                $stmtUpdSummary->execute();
                $stmtUpdSummary->close();
            }
        } catch (Throwable $e) {
            $results['errors'][] = "Procesamiento sesión $sessionId: " . $e->getMessage();
        }
    }
}

// =======================================================================
// ✅ EJECUTAR EXTRACCIÓN DE CONTEXTO DE PROYECTO
// =======================================================================
try {
    $results['synced_primordial'] = syncPrimordialRules($db_connection);
    $results['extracted_knowledge'] = extractKnowledgeFromSessions($db_connection, $bedrock);
} catch (Throwable $e) {
    $results['errors'][] = 'Extracción contexto proyecto: ' . $e->getMessage();
}

// ===== Liberar lock =====
flock($lockFp, LOCK_UN);
fclose($lockFp);

// ===== Responder =====
finish($results, $isCli, $startTime);

function finish($results, $isCli, $startTime) {
    $results['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
    if ($isCli) {
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo json_encode($results, JSON_UNESCAPED_UNICODE);
    }
    exit;
}