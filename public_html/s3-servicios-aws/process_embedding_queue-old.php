<?php
/**
 * process_embedding_queue.php
 * 
 * Procesa la cola de EmbeddingJobs:
 *  1. Lee trabajos pendientes de EmbeddingJobs
 *  2. Obtiene el contenido según target_type (session_block, source_chunk, project_context)
 *  3. Llama a AWS Bedrock (Titan Embed V2) para generar el vector
 *  4. Guarda el vector en la tabla correspondiente
 *  5. Marca el job como completado o fallido
 * 
 * ✅ SMART MEMORY v2.0:
 *  - Para session_block: Nova Micro resume la conversación ANTES de vectorizar.
 *  - Detecta si es el mismo tema (similitud coseno > 75%) y FUSIONA el resumen.
 *  - Si es tema diverso, CREA un nuevo resumen conciso.
 *  - Sobrescribe los 8000 caracteres crudos con el resumen inteligente.
 *  - Registra costos de Nova Micro + Titan en TokenUsage.
 * 
 * Uso:
 *  - Desde navegador: process_embedding_queue.php?batch=10&key=TU_SECRET
 *  - Desde cron: php process_embedding_queue.php --batch=10 --secret=TU_SECRET
 * 
 * Compatible con PHP 7.x+
 */

// ===== Configuración =====
define('EMBEDDING_SECRET', 'Z1!xC6@vB3#nM8$kL4*jH9^gF2&dS7');
define('DEFAULT_BATCH_SIZE', 10);
define('MAX_EXECUTION_TIME', 300); // 5 minutos máximo
define('EMBEDDING_MODEL', 'amazon.titan-embed-text-v2:0');
define('EMBEDDING_DIMENSIONS', 1024);

// ✅ SMART MEMORY: Configuración de compresión inteligente
define('SMART_MEMORY_MODEL', 'amazon.nova-micro-v1:0'); // Ultra-rápido y barato para resumir
define('SIMILARITY_THRESHOLD', 0.75); // 75% de similitud = mismo tema → FUSIONAR
define('SMART_MEMORY_MIN_Q_LENGTH', 15); // Mínimo de caracteres en pregunta para activar IA
define('SMART_MEMORY_MIN_A_LENGTH', 50); // Mínimo de caracteres en respuesta para activar IA

// ===== Timeouts =====
@ini_set('max_execution_time', MAX_EXECUTION_TIME);
@set_time_limit(MAX_EXECUTION_TIME);
@ini_set('default_socket_timeout', '60');
@ignore_user_abort(true);

// ===== Detectar si es CLI o Web =====
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    
    // Validar secret en modo web
    $secret = isset($_GET['key']) ? trim($_GET['key']) : '';
    if ($secret !== EMBEDDING_SECRET) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Clave inválida']);
        exit;
    }
    
    $batchSize = isset($_GET['batch']) ? max(1, min(50, (int)$_GET['batch'])) : DEFAULT_BATCH_SIZE;
} else {
    // Parsear argumentos CLI
    $opts = getopt('', ['batch:', 'secret:']);
    $secret = isset($opts['secret']) ? trim($opts['secret']) : '';
    if ($secret !== EMBEDDING_SECRET) {
        fwrite(STDERR, "Error: clave inválida. Usa --secret=TU_SECRET\n");
        exit(1);
    }
    $batchSize = isset($opts['batch']) ? max(1, min(50, (int)$opts['batch'])) : DEFAULT_BATCH_SIZE;
}

// ===== Resultados =====
$results = [
    'ok' => true,
    'processed' => 0,
    'succeeded' => 0,
    'failed' => 0,
    'skipped' => 0,
    'details' => [],
    'duration_ms' => 0,
];
$startTime = microtime(true);

// ===== Helper: responder y salir =====
function finish($results, $isCli, $startTime) {
    $results['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
    if ($isCli) {
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo json_encode($results, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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
    $results['error'] = 'bootstrap: ' . $e->getMessage();
    finish($results, $isCli, $startTime);
}

// ===== Validar DB =====
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    $results['ok'] = false;
    $results['error'] = 'DB no disponible';
    finish($results, $isCli, $startTime);
}

// ===== Validar AWS SDK =====
if (!class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient')) {
    $results['ok'] = false;
    $results['error'] = 'AWS SDK no cargado (vendor/autoload.php)';
    finish($results, $isCli, $startTime);
}

// ===== Locking: evitar ejecución concurrente =====
// Usamos un archivo de lock simple
$lockFile = sys_get_temp_dir() . '/embedding_queue.lock';
$lockFp = fopen($lockFile, 'w');
if (!$lockFp) {
    $results['ok'] = false;
    $results['error'] = 'No se pudo crear archivo de lock';
    finish($results, $isCli, $startTime);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    $results['ok'] = true;
    $results['message'] = 'Otro proceso ya está ejecutando la cola de embeddings';
    $results['skipped'] = -1;
    finish($results, $isCli, $startTime);
}

// ===== Inicializar cliente Bedrock =====
try {
    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    
    $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
    $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');
    
    if (empty($ak) || empty($sk)) {
        throw new RuntimeException('Faltan credenciales AWS');
    }
    
    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => ['key' => $ak, 'secret' => $sk],
        'http'        => ['connect_timeout' => 10, 'timeout' => 60],
    ]);
} catch (Throwable $e) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $results['ok'] = false;
    $results['error'] = 'Bedrock init: ' . $e->getMessage();
    finish($results, $isCli, $startTime);
}

// ===== Obtener trabajos pendientes =====
$db_connection->begin_transaction();

$stmt = $db_connection->prepare("
    SELECT id_, target_type, target_id, model_id, attempts
    FROM EmbeddingJobs
    WHERE status = 'pending' AND attempts < 3
    ORDER BY created_at ASC
    LIMIT ?
");
$batchSizeParam = $batchSize;
$stmt->bind_param('i', $batchSizeParam);
$stmt->execute();
$res = $stmt->get_result();
$jobs = [];
while ($row = $res->fetch_assoc()) {
    $jobs[] = $row;
}
$stmt->close();

// Marcar como processing (para que otro proceso no los tome)
if (!empty($jobs)) {
    $ids = array_column($jobs, 'id_');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    
    $upd = $db_connection->prepare("
        UPDATE EmbeddingJobs 
        SET status = 'processing', updated_at = NOW()
        WHERE id_ IN ($placeholders)
    ");
    $upd->bind_param($types, ...$ids);
    $upd->execute();
    $upd->close();
}

$db_connection->commit();

if (empty($jobs)) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $results['message'] = 'No hay trabajos pendientes';
    finish($results, $isCli, $startTime);
}

$results['processed'] = count($jobs);

// ===== Función: convertir array de floats a blob binario (float32 little-endian) =====
function floatsToBinaryBlob(array $floats): string {
    $binary = '';
    foreach ($floats as $f) {
        $binary .= pack('g', (float)$f); // 'g' = float32 little-endian
    }
    return $binary;
}

// =====================================================================
// ✅ SMART MEMORY: Función de Similitud Coseno
// Compara dos vectores para determinar si dos Q&A tratan el MISMO TEMA.
// Retorna un valor entre 0.0 (nada similar) y 1.0 (idéntico).
// =====================================================================
function cosineSimilarity(array $vecA, array $vecB): float {
    $dotProduct = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    $count = min(count($vecA), count($vecB));
    for ($i = 0; $i < $count; $i++) {
        $dotProduct += $vecA[$i] * $vecB[$i];
        $normA += $vecA[$i] * $vecA[$i];
        $normB += $vecB[$i] * $vecB[$i];
    }
    if ($normA == 0 || $normB == 0) return 0.0;
    return $dotProduct / (sqrt($normA) * sqrt($normB));
}

// =====================================================================
// ✅ SMART MEMORY: Generar Embedding con Titan (reutilizable)
// Evita duplicar código en múltiples lugares.
// Retorna: ['embedding' => [...], 'inputTokens' => int]
// =====================================================================
function generateTitanEmbedding($bedrock, string $text, string $modelId): array {
    $embedRes = $bedrock->invokeModel([
        'modelId' => $modelId,
        'contentType' => 'application/json',
        'accept' => 'application/json',
        'body' => json_encode([
            'inputText' => mb_substr($text, 0, 8000),
            'dimensions' => EMBEDDING_DIMENSIONS,
            'normalize' => true,
        ]),
    ]);
    $embedData = json_decode((string)$embedRes['body'], true);
    return [
        'embedding' => $embedData['embedding'] ?? [],
        'inputTokens' => (int)($embedData['inputTextTokenCount'] ?? 0),
    ];
}

// =====================================================================
// ✅ SMART MEMORY: IA de Compresión con Modelo Dinámico
// Detecta si hay código para usar Haiku (precisión quirúrgica) o 
// Nova Micro (máximo ahorro) para texto normal.
// Retorna: ['text' => '...', 'inputTokens' => int, 'outputTokens' => int, 'model' => string]
// =====================================================================
function generateSmartSummary($bedrock, string $question, string $answer, ?string $existingSummary = null): array {
    // 1. Detectar si el contenido es técnico/código
    $isCode = preg_match('/\b(function|class|const|let|var|import|export|return|if\s*\(|echo|print|<\?php|=>|<div|<script|error|bug|c[oó]digo|archivo|file|script|variable|array|json|php|js|html|css|sql|query|database|bd|api|endpoint)\b/i', $question . ' ' . $answer);
    
    // 2. Seleccionar modelo: Haiku para código, Nova Micro para texto general
    $modelId = $isCode ? 'anthropic.claude-3-5-haiku-20241022-v1:0' : 'amazon.nova-micro-v1:0';

    if ($existingSummary !== null && trim($existingSummary) !== '') {
        // 🧠 MISMO TEMA: Fusionar resumen existente + nueva información
        $systemPrompt = "Eres un motor de memoria inteligente para un asistente de programación. Tu tarea es FUSIONAR un resumen existente con nueva información del mismo tema.
REGLAS:
1. Mantén los datos técnicos exactos (nombres de funciones, variables, rutas, decisiones).
2. REGLA CRÍTICA: NUNCA omitas valores de variables, rutas de archivos, puertos, IPs o credenciales mencionadas. Preserva los datos técnicos exactos intactos.
3. Elimina redundancias y actualiza el contexto si hay cambios.
4. El resultado debe ser un solo bloque de texto cohesivo, máximo 300 palabras.
5. No uses markdown, solo texto plano.
6. Responde en el mismo idioma que el contenido original.";
        
        $userPrompt = "RESUMEN EXISTENTE:\n{$existingSummary}\n\nNUEVA PREGUNTA:\n{$question}\n\nNUEVA RESPUESTA:\n{$answer}\n\nGenera el resumen fusionado actualizado:";
    } else {
        // 🧠 TEMA DIVERSO: Crear resumen nuevo desde cero
        $systemPrompt = "Eres un motor de memoria inteligente. Tu tarea es CREAR un resumen conciso de una interacción.
REGLAS:
1. Extrae el objetivo, la solución técnica y archivos/funciones clave.
2. REGLA CRÍTICA: NUNCA omitas valores de variables, rutas de archivos, puertos, IPs o credenciales mencionadas. Preserva los datos técnicos exactos intactos.
3. Máximo 250 palabras.
4. No uses markdown, solo texto plano.
5. Responde en el mismo idioma que el contenido original.";
        
        $userPrompt = "PREGUNTA:\n{$question}\n\nRESPUESTA:\n{$answer}\n\nGenera el resumen:";
    }

    $res = $bedrock->converse([
        'modelId' => $modelId, // ✅ MODELO DINÁMICO
        'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
        'system' => [['text' => $systemPrompt]],
        'inferenceConfig' => ['maxTokens' => 800, 'temperature' => 0.2, 'topP' => 0.9] // Temp 0.2 para máxima fidelidad a los datos
    ]);

    $text = '';
    foreach (($res['output']['message']['content'] ?? []) as $block) {
        if (isset($block['text'])) $text .= $block['text'];
    }

    return [
        'text' => trim($text),
        'inputTokens' => (int)($res['usage']['inputTokens'] ?? 0),
        'outputTokens' => (int)($res['usage']['outputTokens'] ?? 0),
        'model' => $modelId // ✅ Devolvemos el modelo real para el cálculo de costos
    ];
}

// ===== Función: obtener contenido para generar embedding =====
function getContentForJob(mysqli $db, string $targetType, int $targetId): ?string {
    switch ($targetType) {
        case 'session_block':
            // Unir pregunta + respuesta de SessionContextBlocks + ChatMessages
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(q.content, '') AS question_text,
                    COALESCE(a.content, '') AS answer_text,
                    COALESCE(scb.content_preview, '') AS preview
                FROM SessionContextBlocks scb
                LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
                LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
                WHERE scb.id_ = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            
            if (!$row) return null;
            
            // Combinar pregunta + respuesta para un embedding más rico
            $text = '';
            if (!empty($row['question_text'])) $text .= 'Pregunta: ' . $row['question_text'] . "\n\n";
            if (!empty($row['answer_text'])) $text .= 'Respuesta: ' . $row['answer_text'];
            if (empty($text) && !empty($row['preview'])) $text = $row['preview'];
            
            return $text ?: null;
            
        case 'source_chunk':
            $stmt = $db->prepare("
                SELECT content, name 
                FROM SourceChunks 
                WHERE id_ = ? 
                LIMIT 1
            ");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            
            if (!$row) return null;
            
            // Incluir nombre del chunk + contenido para mejor contexto semántico
            $text = '';
            if (!empty($row['name'])) $text .= '[' . $row['name'] . "]\n";
            $text .= $row['content'];
            
            return $text ?: null;
            
        case 'project_context':
            $stmt = $db->prepare("
                SELECT type, title, content 
                FROM ProjectContext 
                WHERE id_ = ? 
                LIMIT 1
            ");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            
            if (!$row) return null;
            
            $text = '';
            if (!empty($row['type'])) $text .= '[' . $row['type'] . '] ';
            if (!empty($row['title'])) $text .= $row['title'] . ': ';
            $text .= $row['content'];
            
            return $text ?: null;
            
        default:
            return null;
    }
}

// ===== Función: guardar embedding en la tabla correspondiente =====
function saveEmbedding(mysqli $db, string $targetType, int $targetId, array $embedding, string $modelId): bool {
    $binary = floatsToBinaryBlob($embedding);
    $json = json_encode($embedding);
    $dimensions = count($embedding);
    
    switch ($targetType) {
        case 'session_block':
            // Guardar directamente en SessionContextBlocks (campos que agregamos con ALTER)
            $stmt = $db->prepare("
                UPDATE SessionContextBlocks 
                SET embedding = ?, embedding_json = ?, embedding_model = ?
                WHERE id_ = ?
            ");
            $stmt->bind_param('bssi', $null, $json, $modelId, $targetId);
            
            // bind_param con 'b' necesita variable por referencia
            $null = $binary;
            $stmt->send_long_data(0, $binary);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected > 0;
            
        case 'source_chunk':
            // Guardar en ChunkEmbeddings (tabla existente)
            // Primero verificar si ya existe un embedding para este chunk + modelo
            $stmt = $db->prepare("
                SELECT id_ FROM ChunkEmbeddings 
                WHERE chunk_id_ = ? AND model_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('is', $targetId, $modelId);
            $stmt->execute();
            $res = $stmt->get_result();
            $existing = $res->fetch_assoc();
            $stmt->close();
            
            if ($existing) {
                // Actualizar
                $stmt = $db->prepare("
                    UPDATE ChunkEmbeddings 
                    SET embedding = ?, embedding_json = ?, dimensions = ?
                    WHERE id_ = ?
                ");
                $null = $binary;
                $stmt->bind_param('bsii', $null, $json, $dimensions, $existing['id_']);
                $stmt->send_long_data(0, $binary);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                return $affected >= 0; // 0 si no cambió, pero está OK
            } else {
                // Insertar nuevo
                $nextId = 0;
                $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM ChunkEmbeddings");
                if ($rs) {
                    $row = $rs->fetch_assoc();
                    $nextId = (int)($row['nxt'] ?? 1);
                    $rs->free();
                }
                
                $stmt = $db->prepare("
                    INSERT INTO ChunkEmbeddings 
                    (id_, chunk_id_, model_id, dimensions, embedding, embedding_json)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $null = $binary;
                $stmt->bind_param('iisiis', $nextId, $targetId, $modelId, $dimensions, $null, $json);
                $stmt->send_long_data(4, $binary);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                return $affected > 0;
            }
            
        case 'project_context':
            // Guardar en ProjectContext (campo embedding como longtext JSON)
            $stmt = $db->prepare("
                UPDATE ProjectContext 
                SET embedding = ?
                WHERE id_ = ?
            ");
            $stmt->bind_param('si', $json, $targetId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected > 0;
            
        default:
            return false;
    }
}

// =====================================================================
// ✅ SMART MEMORY: Función auxiliar para registrar en TokenUsage
// Centraliza la lógica de registro para evitar duplicación.
// Usa la MISMA estructura que tu código original.
// =====================================================================
function logSmartMemoryTokenUsage(
    mysqli $db, 
    int $sessionIdForLog, 
    $tcMsgId, 
    string $tcPhase, 
    string $tcModel, 
    int $inputTokens, 
    int $outputTokens, 
    float $tcCost
): void {
    if ($inputTokens == 0 && $outputTokens == 0) return;
    if (!$sessionIdForLog) return;
    
    $tcId = 0;
    $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM TokenUsage");
    if ($rs) { 
        $tcId = (int)($rs->fetch_assoc()['nxt'] ?? 1); 
        $rs->free(); 
    }
    $tcDuration = 0;
    
    $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtTC = $db->prepare($sqlTC);
    if ($stmtTC) {
        $stmtTC->bind_param("iiissiddi", $tcId, $sessionIdForLog, $tcMsgId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $tcDuration);
        $stmtTC->execute();
        $stmtTC->close();
    }
}

// ===== Procesar cada job =====
foreach ($jobs as $job) {
    $jobId = (int)$job['id_'];
    $targetType = $job['target_type'];
    $targetId = (int)$job['target_id'];
    $modelId = $job['model_id'] ?: EMBEDDING_MODEL;
    $attempts = (int)$job['attempts'] + 1;
    
    $detail = [
        'job_id' => $jobId,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'status' => 'unknown',
    ];
    
    try {
        // =================================================================
        // ✅ SMART MEMORY: FLUJO ESPECIAL PARA session_block
        // En lugar de vectorizar el texto crudo de 8000 chars,
        // primero Nova Micro lo resume, luego se vectoriza el resumen.
        // =================================================================
        // En tu script, para session_block:
if ($targetType === 'session_block') {
    // ============================================================
    // 1. OBTENER DATOS DEL BLOQUE
    // ============================================================
    $stmtData = $db_connection->prepare("
        SELECT 
            scb.session_id_, 
            scb.answer_msg_id,
            scb.content_preview,
            COALESCE(q.content, '') AS question_text, 
            COALESCE(a.content, '') AS answer_text
        FROM SessionContextBlocks scb
        LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
        LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
        WHERE scb.id_ = ?
        LIMIT 1
    ");
    $stmtData->bind_param('i', $targetId);
    $stmtData->execute();
    $rowData = $stmtData->get_result()->fetch_assoc();
    $stmtData->close();

    if (!$rowData) throw new RuntimeException('Bloque de sesión #' . $targetId . ' no encontrado');

    $sessionId = (int)$rowData['session_id_'];
    $msgId = $rowData['answer_msg_id']; // Puede ser null
    $question = (string)$rowData['question_text'];
    $answer = (string)$rowData['answer_text'];

    // ============================================================
    // 2. OBTENER message_id_ REAL (para TokenUsage)
    // ============================================================
    $tcMsgId = $msgId; // Si el bloque ya tiene answer_msg_id, úsalo
    if ($tcMsgId === null) {
        // Si no, buscar el último mensaje de asistente en la sesión
        $stmtAsst = $db_connection->prepare("
            SELECT id_ FROM ChatMessages 
            WHERE session_id_ = ? AND role = 'assistant'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmtAsst->bind_param('i', $sessionId);
        $stmtAsst->execute();
        $resAsst = $stmtAsst->get_result();
        if ($rowAsst = $resAsst->fetch_assoc()) {
            $tcMsgId = (int)$rowAsst['id_'];
        }
        $stmtAsst->close();
    }
    if ($tcMsgId === null) {
        // Fallback: usar mensaje de usuario
        $stmtUser = $db_connection->prepare("
            SELECT id_ FROM ChatMessages 
            WHERE session_id_ = ? AND role = 'user'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmtUser->bind_param('i', $sessionId);
        $stmtUser->execute();
        $resUser = $stmtUser->get_result();
        if ($rowUser = $resUser->fetch_assoc()) {
            $tcMsgId = (int)$rowUser['id_'];
        }
        $stmtUser->close();
    }
    // Si aún es null, lo dejamos null (SQL NULL) y no forzamos 0.

    // ============================================================
    // 3. FILTRO DE TRIVIALIDAD
    // ============================================================
    $isTrivial = (mb_strlen($question) < SMART_MEMORY_MIN_Q_LENGTH && mb_strlen($answer) < SMART_MEMORY_MIN_A_LENGTH);

    if ($isTrivial) {
        // Flujo trivial: solo vectorizar sin resumen
        $trivialText = "P: $question\nR: $answer";
        $trivialText = mb_substr($trivialText, 0, 30000);
        $embData = generateTitanEmbedding($bedrock, $trivialText, $modelId);
        $embedding = $embData['embedding'];
        if (empty($embedding)) throw new RuntimeException('Embedding trivial vacío');

        saveEmbedding($db_connection, 'session_block', $targetId, $embedding, $modelId);

        // Actualizar content_preview con versión corta
        $shortPreview = mb_substr($trivialText, 0, 300);
        $tokenCount = (int)ceil(mb_strlen($trivialText) / 4);
        $stmtUpd = $db_connection->prepare("UPDATE SessionContextBlocks SET content_preview = ?, token_count = ? WHERE id_ = ?");
        $stmtUpd->bind_param('sii', $shortPreview, $tokenCount, $targetId);
        $stmtUpd->execute();
        $stmtUpd->close();

        // Marcar job completado
        $upd = $db_connection->prepare("UPDATE EmbeddingJobs SET status = 'completed', attempts = ?, updated_at = NOW() WHERE id_ = ?");
        $upd->bind_param('ii', $attempts, $jobId);
        $upd->execute();
        $upd->close();

        // Registrar solo el embedding trivial
        if ($sessionId && $tcMsgId !== null) {
            logSmartMemoryTokenUsage($db_connection, $sessionId, $tcMsgId, 'embedding', $modelId, $embData['inputTokens'], 0, ($embData['inputTokens']/1000)*0.0001);
        }

        $detail['status'] = 'completed_trivial';
        $detail['dimensions'] = count($embedding);
        $results['succeeded']++;
        $results['details'][] = $detail;
        continue; // Saltar al siguiente job
    }

    // ============================================================
    // 4. SMART MEMORY COMPLETO (NO TRIVIAL)
    // ============================================================

    // 4a. Generar embedding del Q&A actual para comparar similitud
    $qaText = "Pregunta: $question\nRespuesta: $answer";
    $qaText = mb_substr($qaText, 0, 30000);
    $qaEmbData = generateTitanEmbedding($bedrock, $qaText, $modelId);
    $qaVector = $qaEmbData['embedding'];

    // 4b. Buscar resúmenes previos de la misma sesión
    $existingSummary = null;
    $existingBlockId = null;
    $maxSim = 0.0;

    $stmtPrev = $db_connection->prepare("
        SELECT id_, content_preview, embedding_json 
        FROM SessionContextBlocks 
        WHERE session_id_ = ? AND id_ != ? 
          AND embedding_json IS NOT NULL 
          AND block_type IN ('level_0', 'level_1')
        ORDER BY created_at DESC LIMIT 50
    ");
    $stmtPrev->bind_param('ii', $sessionId, $targetId);
    $stmtPrev->execute();
    $resPrev = $stmtPrev->get_result();
    while ($rowPrev = $resPrev->fetch_assoc()) {
        $prevVector = json_decode($rowPrev['embedding_json'], true);
        if (is_array($prevVector) && count($prevVector) > 0 && !empty($qaVector)) {
            $sim = cosineSimilarity($qaVector, $prevVector);
            if ($sim > $maxSim && $sim >= SIMILARITY_THRESHOLD) {
                $maxSim = $sim;
                $existingSummary = (string)$rowPrev['content_preview'];
                $existingBlockId = (int)$rowPrev['id_'];
            }
        }
    }
    $stmtPrev->close();

    // 4c. Llamar a Nova Micro para resumir o fusionar
    $smartData = generateSmartSummary($bedrock, $question, $answer, $existingSummary);
    $finalText = $smartData['text'];
    if (empty($finalText)) throw new RuntimeException('Nova Micro no devolvió resumen');

    // 4d. Generar embedding del resumen inteligente
    $finalEmbData = generateTitanEmbedding($bedrock, $finalText, $modelId);
    $finalEmbedding = $finalEmbData['embedding'];
    if (empty($finalEmbedding)) throw new RuntimeException('Embedding del resumen vacío');

    $binary = floatsToBinaryBlob($finalEmbedding);
    $json = json_encode($finalEmbedding);
    $tokenCount = (int)ceil(mb_strlen($finalText) / 4);

    // 4e. Guardar en BD (fusionar o crear nuevo)
    if ($existingBlockId && $existingSummary) {
        // Mismo tema: actualizar bloque existente y eliminar el actual
        $stmtUpd = $db_connection->prepare("
            UPDATE SessionContextBlocks 
            SET content_preview = ?, embedding = ?, embedding_json = ?, embedding_model = ?, token_count = ?
            WHERE id_ = ?
        ");
        $null = $binary;
        $stmtUpd->bind_param('sbssii', $finalText, $null, $json, $modelId, $tokenCount, $existingBlockId);
        $stmtUpd->send_long_data(1, $binary);
        $stmtUpd->execute();
        $stmtUpd->close();

        $stmtDel = $db_connection->prepare("DELETE FROM SessionContextBlocks WHERE id_ = ?");
        $stmtDel->bind_param('i', $targetId);
        $stmtDel->execute();
        $stmtDel->close();

        $detail['action'] = 'merged_into_block_' . $existingBlockId;
        $detail['similarity'] = round($maxSim, 3);
    } else {
        // Tema diverso: sobrescribir el bloque actual
        $stmtUpd = $db_connection->prepare("
            UPDATE SessionContextBlocks 
            SET content_preview = ?, embedding = ?, embedding_json = ?, embedding_model = ?, token_count = ?
            WHERE id_ = ?
        ");
        $null = $binary;
        $stmtUpd->bind_param('sbssii', $finalText, $null, $json, $modelId, $tokenCount, $targetId);
        $stmtUpd->send_long_data(1, $binary);
        $stmtUpd->execute();
        $stmtUpd->close();

        $detail['action'] = 'new_summary_created';
    }

    // 4f. Marcar job completado
    $upd = $db_connection->prepare("UPDATE EmbeddingJobs SET status = 'completed', attempts = ?, updated_at = NOW() WHERE id_ = ?");
    $upd->bind_param('ii', $attempts, $jobId);
    $upd->execute();
    $upd->close();

    $detail['status'] = 'completed_smart';
    $detail['dimensions'] = count($finalEmbedding);
    $results['succeeded']++;

    // ============================================================
    // 5. REGISTRAR COSTOS EN TokenUsage (¡AQUÍ ESTÁN LOS output_tokens!)
    // ============================================================
    if ($sessionId && $tcMsgId !== null) {
        
        // Paso A: Embedding inicial del Q&A crudo (Titan)
        $costA = ($qaEmbData['inputTokens'] / 1000) * 0.0001;
        logSmartMemoryTokenUsage($db_connection, $sessionId, $tcMsgId, 'embedding', $modelId, $qaEmbData['inputTokens'], 0, $costA);
        
        // Paso B: Resumen con IA (Dinámico: Haiku o Nova Micro)
        $usedModel = $smartData['model'] ?? 'amazon.nova-micro-v1:0';
        $isHaiku = stripos($usedModel, 'haiku') !== false;
        
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
        
        // ✅ AQUÍ SE REGISTRA EL MODELO REAL ($usedModel), NO LA CONSTANTE FIJA
        logSmartMemoryTokenUsage($db_connection, $sessionId, $tcMsgId, 'compile', $usedModel, $smartData['inputTokens'], $smartData['outputTokens'], $costB);
        
        // Paso C: Embedding final del resumen inteligente (Titan)
        $costC = ($finalEmbData['inputTokens'] / 1000) * 0.0001;
        logSmartMemoryTokenUsage($db_connection, $sessionId, $tcMsgId, 'embedding', $modelId, $finalEmbData['inputTokens'], 0, $costC);
    }

    // ============================================================
    // 6. ACTUALIZAR NIVEL DE CONTEXTO DE LA SESIÓN
    // ============================================================
    if ($sessionId && !$isTrivial) {
        // Solo actualizar si no es trivial (porque en trivial no se generó resumen)
        $stmtUpdSess = $db_connection->prepare("
            UPDATE ChatSessions 
            SET context_level = 1, 
                last_compressed_at = NOW()
            WHERE id_ = ? 
              AND context_level < 1
        ");
        $stmtUpdSess->bind_param('i', $sessionId);
        $stmtUpdSess->execute();
        $stmtUpdSess->close();
        
        // Opcional: también podrías actualizar context_summary con el resumen generado
        // pero ya está en el bloque, así que no es necesario.
    }


    $results['details'][] = $detail;
    continue; // ✅ ¡IMPORTANTE! Saltar al siguiente job (evita duplicación)
}

        // =================================================================
        // FLUJO ORIGINAL INTACTO: Para source_chunk y project_context
        // (Sin cambios, exactamente como tu código)
        // =================================================================
        
        // 1. Obtener contenido
        $content = getContentForJob($db_connection, $targetType, $targetId);
        
        if (empty($content)) {
            $errMsg = 'Contenido no encontrado para ' . $targetType . ' #' . $targetId;
            $upd = $db_connection->prepare("
                UPDATE EmbeddingJobs 
                SET status = 'failed', error_message = ?, attempts = ?, updated_at = NOW()
                WHERE id_ = ?
            ");
            $upd->bind_param('sii', $errMsg, $attempts, $jobId);
            $upd->execute();
            $upd->close();
            
            $detail['status'] = 'failed';
            $detail['error'] = $errMsg;
            $results['failed']++;
            $results['details'][] = $detail;
            continue;
        }
        
        // 2. Truncar contenido si es muy largo (Titan V2 soporta hasta 8192 tokens, ~32000 chars)
        $content = mb_substr($content, 0, 30000);
        
        // 3. Llamar a Bedrock para generar embedding
        $embedRes = $bedrock->invokeModel([
            'modelId' => $modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'inputText' => $content,
                'dimensions' => EMBEDDING_DIMENSIONS,
                'normalize' => true,
            ]),
        ]);
        
        $embedData = json_decode((string)$embedRes['body'], true);
        $embedding = $embedData['embedding'] ?? [];
        
        if (empty($embedding) || !is_array($embedding)) {
            throw new RuntimeException('Bedrock no devolvió embedding válido');
        }
        
        // 4. Guardar embedding en la tabla correspondiente
        $saved = saveEmbedding($db_connection, $targetType, $targetId, $embedding, $modelId);
        
        if (!$saved) {
            throw new RuntimeException('No se pudo guardar el embedding en la BD');
        }
        
        // 5. Marcar job como completado
        $upd = $db_connection->prepare("
            UPDATE EmbeddingJobs 
            SET status = 'completed', attempts = ?, updated_at = NOW()
            WHERE id_ = ?
        ");
        $upd->bind_param('ii', $attempts, $jobId);
        $upd->execute();
        $upd->close();
        
        $detail['status'] = 'completed';
        $detail['dimensions'] = count($embedding);
        $results['succeeded']++;

        $inputTokens = (int)($embedData['inputTextTokenCount'] ?? 0);
        $outputTokens = 0;

        try {
            // ==========================================
            // 1. OBTENER EL session_id_ CORRECTO
            // ==========================================
            $sessionIdForLog = null;

            if ($targetType === 'source_chunk') {
                $stmtProj = $db_connection->prepare("SELECT project_id_ FROM SourceChunks WHERE id_ = ? LIMIT 1");
                $stmtProj->bind_param('i', $targetId);
                $stmtProj->execute();
                $resProj = $stmtProj->get_result();
                if ($rowProj = $resProj->fetch_assoc()) {
                    $stmtSess2 = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE project_id_ = ? ORDER BY id_ DESC LIMIT 1");
                    $stmtSess2->bind_param('i', $rowProj['project_id_']);
                    $stmtSess2->execute();
                    $resSess2 = $stmtSess2->get_result();
                    if ($rowSess2 = $resSess2->fetch_assoc()) {
                        $sessionIdForLog = (int)$rowSess2['id_'];
                    }
                    $stmtSess2->close();
                }
                $stmtProj->close();
            } elseif ($targetType === 'project_context') {
                $stmtPC = $db_connection->prepare("SELECT project_id_ FROM ProjectContext WHERE id_ = ? LIMIT 1");
                $stmtPC->bind_param('i', $targetId);
                $stmtPC->execute();
                $resPC = $stmtPC->get_result();
                if ($rowPC = $resPC->fetch_assoc()) {
                    $stmtSess3 = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE project_id_ = ? ORDER BY id_ DESC LIMIT 1");
                    $stmtSess3->bind_param('i', $rowPC['project_id_']);
                    $stmtSess3->execute();
                    $resSess3 = $stmtSess3->get_result();
                    if ($rowSess3 = $resSess3->fetch_assoc()) {
                        $sessionIdForLog = (int)$rowSess3['id_'];
                    }
                    $stmtSess3->close();
                }
                $stmtPC->close();
            }

            // Fallback de seguridad
            if (!$sessionIdForLog) {
                $fallbackSess = $db_connection->query("SELECT id_ FROM ChatSessions LIMIT 1");
                if ($fallbackSess && $rowFallback = $fallbackSess->fetch_assoc()) {
                    $sessionIdForLog = (int)$rowFallback['id_'];
                }
                if ($fallbackSess) $fallbackSess->free();
            }

            
            // ==========================================
            // 2. OBTENER EL message_id_ REAL (Tu requerimiento)
            // ==========================================
            // Iniciamos como null (nulo de PHP, que se traduce a SQL NULL). 
            // NUNCA como 0, porque 0 viola la clave foránea.
            $tcMsgId = null; 

            if ($targetType === 'session_block') {
                // Aquí es donde se contraen los mensajes. Obtenemos el ID del mensaje de respuesta de la IA.
                $stmtMsg = $db_connection->prepare("SELECT answer_msg_id FROM SessionContextBlocks WHERE id_ = ? LIMIT 1");
                $stmtMsg->bind_param('i', $targetId);
                $stmtMsg->execute();
                $resMsg = $stmtMsg->get_result();
                if ($rowMsg = $resMsg->fetch_assoc()) {
                    $tcMsgId = $rowMsg['answer_msg_id'] ?? null; // ¡Aquí está el ID real del mensaje procesado!
                }
                $stmtMsg->close();
            } elseif ($targetType === 'source_chunk' || $targetType === 'project_context') {
                // Para indexación de proyectos, no hay un mensaje de chat directo, pero podemos 
                // vincularlo al ÚLTIMO mensaje generado en la sesión de ese proyecto para mantener trazabilidad.
                $projectId = 0;
                if ($targetType === 'source_chunk') {
                    $stmtP = $db_connection->prepare("SELECT project_id_ FROM SourceChunks WHERE id_ = ? LIMIT 1");
                    $stmtP->bind_param('i', $targetId);
                    $stmtP->execute();
                    $resP = $stmtP->get_result();
                    if ($rowP = $resP->fetch_assoc()) $projectId = (int)$rowP['project_id_'];
                    $stmtP->close();
                } else {
                    $stmtP = $db_connection->prepare("SELECT project_id_ FROM ProjectContext WHERE id_ = ? LIMIT 1");
                    $stmtP->bind_param('i', $targetId);
                    $stmtP->execute();
                    $resP = $stmtP->get_result();
                    if ($rowP = $resP->fetch_assoc()) $projectId = (int)$rowP['project_id_'];
                    $stmtP->close();
                }

                if ($projectId > 0 && $sessionIdForLog) {
                    $stmtLastMsg = $db_connection->prepare("
                        SELECT m.id_ 
                        FROM ChatMessages m
                        WHERE m.session_id_ = ?
                        ORDER BY m.id_ DESC 
                        LIMIT 1
                    ");
                    $stmtLastMsg->bind_param('i', $sessionIdForLog);
                    $stmtLastMsg->execute();
                    $resLastMsg = $stmtLastMsg->get_result();
                    if ($rowLastMsg = $resLastMsg->fetch_assoc()) {
                        $tcMsgId = (int)$rowLastMsg['id_'];
                    }
                    $stmtLastMsg->close();
                }
            }

            // ==========================================
            // 3. REGISTRAR EN TokenUsage
            // ==========================================
            // Solo registramos si tenemos un session_id_ válido
            if ($sessionIdForLog) {
                $tcId = 0;
                $rs = $db_connection->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM TokenUsage");
                if ($rs) { 
                    $tcId = (int)($rs->fetch_assoc()['nxt'] ?? 1); 
                    $rs->free(); 
                }
                
                $tcPhase = 'embedding';
                $tcModel = $modelId;
                $tcCost = ($inputTokens / 1000) * 0.0001; // Costo aprox Titan Embed V2
                $tcDuration = 0;
                
                // NOTA: $tcMsgId aquí será el ID real que encontramos arriba, 
                // o null (SQL NULL) solo si genuinamente no existe ningún mensaje vinculado.
                
                $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmtTC = $db_connection->prepare($sqlTC);
                if ($stmtTC) {
                    // Tipos: id_(i), session_id_(i), message_id_(i), phase(s), model_id(s), input(i), output(i), cost(d), duration(i)
                    $stmtTC->bind_param("iiissiddi", $tcId, $sessionIdForLog, $tcMsgId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $tcDuration);
                    $stmtTC->execute();
                    $stmtTC->close();
                }
            }
        } catch (Throwable $e) {
            $logMsg = "[" . date('Y-m-d H:i:s') . "] " . basename(__FILE__) . " (Job $jobId) | TokenUsage: " . $e->getMessage() . "\n";
            @file_put_contents(__DIR__ . '/token_usage_debug.log', $logMsg, FILE_APPEND | LOCK_EX);
        }
        
    } catch (Throwable $e) {
    $errMsg = mb_substr($e->getMessage(), 0, 500);
    
    // ✅ NUEVO: Si el bloque no existe, marcar como failed inmediatamente
    $isBlockNotFound = (strpos($errMsg, 'no encontrado') !== false || strpos($errMsg, 'not found') !== false);
    
    if ($isBlockNotFound) {
        // No reintentar, marcar como failed de inmediato
        $upd = $db_connection->prepare("
            UPDATE EmbeddingJobs
            SET status = 'failed', error_message = ?, attempts = ?, updated_at = NOW()
            WHERE id_ = ?
        ");
        $upd->bind_param('sii', $errMsg, $attempts, $jobId);
        $upd->execute();
        $upd->close();
        
        $detail['status'] = 'failed';
        $detail['error'] = $errMsg;
        $results['failed']++;
    } else {
        // Comportamiento normal: reintentar hasta 3 veces
        $newStatus = ($attempts >= 3) ? 'failed' : 'pending';
        $upd = $db_connection->prepare("
            UPDATE EmbeddingJobs
            SET status = ?, error_message = ?, attempts = ?, updated_at = NOW()
            WHERE id_ = ?
        ");
        $upd->bind_param('ssii', $newStatus, $errMsg, $attempts, $jobId);
        $upd->execute();
        $upd->close();
        
        $detail['status'] = ($newStatus === 'failed') ? 'failed' : 'retry_later';
        $detail['error'] = $errMsg;
        if ($newStatus === 'failed') {
            $results['failed']++;
        } else {
            $results['skipped']++;
        }
    }
}
    
    $results['details'][] = $detail;
}


// ===== Liberar lock =====
flock($lockFp, LOCK_UN);
fclose($lockFp);

// ===== Responder =====
finish($results, $isCli, $startTime);