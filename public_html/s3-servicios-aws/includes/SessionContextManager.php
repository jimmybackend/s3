<?php
/**
 * SessionContextManager.php
 * Gestor de la Fase 1: Metacognición básica del chat
 * 
 * Compatible con PHP 7.x
 * 
 * Responsabilidades:
 *  - Guardar mensajes en ChatMessages
 *  - Marcar respuestas como "primordiales" (verdad absoluta)
 *  - Crear bloques en SessionContextBlocks (nivel 0 crudo)
 *  - Detectar cuándo disparar la compresión automática (cada 5 Q&A)
 */

class SessionContextManager
{
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $userId;

    public function __construct(PDO $pdo, int $userId)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }

    // =====================================================================
    // 1. GUARDAR MENSAJE EN ChatMessages
    // =====================================================================

    /**
     * Guarda un mensaje (pregunta o respuesta) en la tabla ChatMessages
     *
     * @param int    $sessionId
     * @param string $role        'user' | 'assistant' | 'system' | 'tool'
     * @param string $content
     * @param string $contentType 'text' | 'image' | 'video' | 'audio' | 'file'
     * @param array  $extra       Campos opcionales (model_id, prompt_tokens, etc.)
     * @return int                ID del mensaje recién creado
     */
    public function saveMessage(
        int $sessionId,
        string $role,
        string $content,
        string $contentType = 'text',
        array $extra = []
    ): int {
        $sql = "INSERT INTO ChatMessages 
                (session_id_, user_id_, role, content_type, content, 
                 s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
                 model_id, stop_reason, prompt_tokens, completion_tokens, 
                 latency_ms, meta, phase, parent_msg_id)
                VALUES 
                (:session_id, :user_id, :role, :content_type, :content,
                 :s3_key, :mime_type, :size_bytes, :thumb_s3_key, :duration_ms,
                 :model_id, :stop_reason, :prompt_tokens, :completion_tokens,
                 :latency_ms, :meta, :phase, :parent_msg_id)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id'        => $sessionId,
            ':user_id'           => $this->userId,
            ':role'              => $role,
            ':content_type'      => $contentType,
            ':content'           => $content,
            ':s3_key'            => $extra['s3_key'] ?? null,
            ':mime_type'         => $extra['mime_type'] ?? null,
            ':size_bytes'        => $extra['size_bytes'] ?? null,
            ':thumb_s3_key'      => $extra['thumb_s3_key'] ?? null,
            ':duration_ms'       => $extra['duration_ms'] ?? null,
            ':model_id'          => $extra['model_id'] ?? null,
            ':stop_reason'       => $extra['stop_reason'] ?? null,
            ':prompt_tokens'     => $extra['prompt_tokens'] ?? null,
            ':completion_tokens' => $extra['completion_tokens'] ?? null,
            ':latency_ms'        => $extra['latency_ms'] ?? null,
            ':meta'              => isset($extra['meta']) ? json_encode($extra['meta']) : null,
            ':phase'             => $extra['phase'] ?? 'respond',
            ':parent_msg_id'     => $extra['parent_msg_id'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // =====================================================================
    // 2. MARCAR MENSAJE COMO PRIMORDIAL
    // =====================================================================

    /**
     * Marca una respuesta como "primordial" (verdad absoluta que no se comprime)
     * y crea/actualiza el bloque correspondiente en SessionContextBlocks
     *
     * @param int $messageId ID del mensaje (respuesta) a marcar
     * @return array         Resultado con el block_id creado
     */
    public function markAsPrimordial(int $messageId): array
    {
        // 1. Verificar que el mensaje existe y pertenece al usuario
        $stmt = $this->pdo->prepare("
            SELECT id_, session_id_, role, content, created_at
            FROM ChatMessages
            WHERE id_ = :msg_id AND user_id_ = :user_id
            LIMIT 1
        ");
        $stmt->execute([':msg_id' => $messageId, ':user_id' => $this->userId]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$msg) {
            return ['ok' => false, 'error' => 'Mensaje no encontrado o no pertenece al usuario'];
        }

        if ($msg['role'] !== 'assistant') {
            return ['ok' => false, 'error' => 'Solo las respuestas del asistente pueden ser primordiales'];
        }

        // 2. Actualizar el flag is_primordial en ChatMessages
        $this->pdo->prepare("
            UPDATE ChatMessages 
            SET is_primordial = 1 
            WHERE id_ = :msg_id
        ")->execute([':msg_id' => $messageId]);

        // 3. Buscar si ya existe un bloque primordial para este mensaje
        $stmt = $this->pdo->prepare("
            SELECT id_ FROM SessionContextBlocks
            WHERE answer_msg_id = :msg_id AND block_type = 'primordial'
            LIMIT 1
        ");
        $stmt->execute([':msg_id' => $messageId]);
        $existingBlock = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingBlock) {
            // Ya existe, solo asegurar que está bloqueado
            $this->pdo->prepare("
                UPDATE SessionContextBlocks 
                SET is_locked = 1 
                WHERE id_ = :block_id
            ")->execute([':block_id' => $existingBlock['id_']]);

            return [
                'ok' => true,
                'block_id' => (int) $existingBlock['id_'],
                'message' => 'Bloque primordial ya existía, actualizado'
            ];
        }

        // 4. Buscar la pregunta asociada (el mensaje de usuario anterior en la misma sesión)
        $stmt = $this->pdo->prepare("
            SELECT id_, content 
            FROM ChatMessages
            WHERE session_id_ = :session_id 
              AND role = 'user'
              AND id_ < :msg_id
            ORDER BY id_ DESC
            LIMIT 1
        ");
        $stmt->execute([':session_id' => $msg['session_id_'], ':msg_id' => $messageId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);

        // 5. Crear el bloque primordial en SessionContextBlocks
        $preview = mb_substr($msg['content'], 0, 300);
        $tokenCount = $this->estimateTokens($msg['content']);

        $stmt = $this->pdo->prepare("
            INSERT INTO SessionContextBlocks 
            (session_id_, block_type, question_msg_id, answer_msg_id, 
             content_preview, is_locked, token_count)
            VALUES 
            (:session_id, 'primordial', :q_msg_id, :a_msg_id,
             :preview, 1, :token_count)
        ");
        $stmt->execute([
            ':session_id'  => $msg['session_id_'],
            ':q_msg_id'    => $question ? $question['id_'] : null,
            ':a_msg_id'    => $messageId,
            ':preview'     => $preview,
            ':token_count' => $tokenCount,
        ]);

        $blockId = (int) $this->pdo->lastInsertId();

        return [
            'ok' => true,
            'block_id' => $blockId,
            'message' => 'Respuesta marcada como primordial exitosamente'
        ];
    }

    // =====================================================================
    // 3. DESMARCAR PRIMORDIAL (por si el usuario cambia de opinión)
    // =====================================================================

    /**
     * Quita el flag primordial de un mensaje y elimina el bloque asociado
     *
     * @param int $messageId
     * @return array
     */
    public function unmarkPrimordial(int $messageId): array
    {
        $this->pdo->prepare("
            UPDATE ChatMessages 
            SET is_primordial = 0 
            WHERE id_ = :msg_id AND user_id_ = :user_id
        ")->execute([':msg_id' => $messageId, ':user_id' => $this->userId]);

        $this->pdo->prepare("
            DELETE FROM SessionContextBlocks
            WHERE answer_msg_id = :msg_id AND block_type = 'primordial'
        ")->execute([':msg_id' => $messageId]);

        return ['ok' => true, 'message' => 'Primordial removido'];
    }

    // =====================================================================
    // 4. CREAR BLOQUE DE CONTEXTO NIVEL 0 (Q&A crudo automático)
    // =====================================================================

    /**
     * Después de cada respuesta, crea automáticamente un bloque nivel_0
     * Esto alimenta la memoria de la sesión sin intervención del usuario
     *
     * @param int      $sessionId
     * @param int      $questionMsgId
     * @param int      $answerMsgId
     * @param string   $answerContent
     * @return int     ID del bloque creado
     */
    public function createLevel0Block(
        int $sessionId,
        int $questionMsgId,
        int $answerMsgId,
        string $answerContent
    ): int {
        $preview = mb_substr($answerContent, 0, 300);
        $tokenCount = $this->estimateTokens($answerContent);

        $stmt = $this->pdo->prepare("
            INSERT INTO SessionContextBlocks 
            (session_id_, block_type, question_msg_id, answer_msg_id, 
             content_preview, is_locked, token_count)
            VALUES 
            (:session_id, 'level_0', :q_msg_id, :a_msg_id,
             :preview, 0, :token_count)
        ");
        $stmt->execute([
            ':session_id'  => $sessionId,
            ':q_msg_id'    => $questionMsgId,
            ':a_msg_id'    => $answerMsgId,
            ':preview'     => $preview,
            ':token_count' => $tokenCount,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // =====================================================================
    // 5. VERIFICAR SI DEBE DISPARAR COMPRESIÓN
    // =====================================================================

    /**
     * Cuenta los bloques nivel_0 sin comprimir de una sesión.
     * Si hay >= 5, retorna true (el sistema debe lanzar la compresión nivel 0→1)
     *
     * @param int $sessionId
     * @return bool
     */
    public function shouldTriggerCompression(int $sessionId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt
            FROM SessionContextBlocks
            WHERE session_id_ = :session_id 
              AND block_type = 'level_0'
        ");
        $stmt->execute([':session_id' => $sessionId]);
        $count = (int) $stmt->fetchColumn();

        return $count >= 5;
    }

    // =====================================================================
    // 6. OBTENER CONTEXTO DE SESIÓN (para enviar a la IA compiladora)
    // =====================================================================

    /**
     * Obtiene el contexto completo de una sesión organizado por prioridad:
     *  1. Primordiales (siempre incluidos)
     *  2. Últimos N bloques nivel_0 (recencia)
     *  3. Resúmenes de nivel superior (si existen)
     *
     * @param int $sessionId
     * @param int $recentLimit  Cuántos bloques recientes incluir (default 5)
     * @return array
     */
    public function getSessionContext(int $sessionId, int $recentLimit = 5): array
    {
        $context = [
            'primordial' => [],
            'recent_raw' => [],
            'summaries'  => [],
        ];

        // 1. Primordiales (siempre entran)
        $stmt = $this->pdo->prepare("
            SELECT scb.id_, scb.content_preview, scb.token_count,
                   q.content AS question_text,
                   a.content AS answer_text,
                   a.id_ AS answer_msg_id
            FROM SessionContextBlocks scb
            LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
            LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
            WHERE scb.session_id_ = :session_id 
              AND scb.block_type = 'primordial'
            ORDER BY scb.created_at ASC
        ");
        $stmt->execute([':session_id' => $sessionId]);
        $context['primordial'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Últimos N bloques nivel_0 (recencia)
        $stmt = $this->pdo->prepare("
            SELECT scb.id_, scb.content_preview, scb.token_count,
                   q.content AS question_text,
                   a.content AS answer_text
            FROM SessionContextBlocks scb
            LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
            LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
            WHERE scb.session_id_ = :session_id 
              AND scb.block_type = 'level_0'
            ORDER BY scb.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $recentLimit, PDO::PARAM_INT);
        $stmt->execute();
        $context['recent_raw'] = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

        // 3. Resúmenes de nivel superior (1, 2, 3)
        $stmt = $this->pdo->prepare("
            SELECT id_, block_type, content_preview, source_ids, token_count
            FROM SessionContextBlocks
            WHERE session_id_ = :session_id 
              AND block_type IN ('level_1', 'level_2', 'level_3')
            ORDER BY created_at ASC
        ");
        $stmt->execute([':session_id' => $sessionId]);
        $context['summaries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $context;
    }

    // =====================================================================
    // UTILIDAD: Estimación rápida de tokens
    // =====================================================================

    /**
     * Estimación aproximada de tokens (1 token ≈ 4 caracteres en español/inglés)
     * Para precisión real, usar el conteo que devuelve la API del modelo
     *
     * @param string $text
     * @return int
     */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}