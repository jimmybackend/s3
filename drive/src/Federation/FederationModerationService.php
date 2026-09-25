<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\View\FileViewHelper;
use Throwable;

final class FederationModerationService
{
    private FederationModerationRepository $repository;
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederationEventStore $events;

    public function __construct(private DriveApplication $app)
    {
        $this->repository = new FederationModerationRepository($app->db());
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $catalog = new FederatedCatalogRepository($app->db());
        $this->events = new FederationEventStore($app->db(), $catalog, new FederationEventCodec());
    }

    public function rememberFingerprint(
        string $contentId,
        string $subjectType,
        string $subjectId,
        ?string $s3Key = null,
        ?string $authorityNodeId = null
    ): void {
        $this->repository->rememberFingerprint(
            $this->normalizeContentId($contentId),
            $subjectType,
            $subjectId,
            $authorityNodeId ?: $this->identity->nodeId(),
            $s3Key
        );
    }

    public function assertAllowed(string $contentId): void
    {
        $contentId = $this->normalizeContentId($contentId);
        if ($this->repository->isBlocked($contentId)) {
            throw new FederationException(
                'Este contenido coincide con una huella digital bloqueada por moderación y no puede almacenarse ni compartirse.',
                451
            );
        }
    }

    public function submitReport(
        string $targetType,
        string $targetId,
        string $category,
        string $details,
        string $reporterEmail = ''
    ): array {
        $this->ensureModerationSchema();

        $targetType = strtolower(trim($targetType));
        $targetId = trim($targetId);
        $category = strtolower(trim($category));
        $details = trim($details);
        if ($targetId === '' || strlen($targetId) > 128) throw new FederationException('Identificador reportado inválido.', 400);
        if ($details === '' || strlen($details) < 5 || strlen($details) > 2000) {
            throw new FederationException('Describe el problema entre 5 y 2000 caracteres.', 400);
        }
        $reporterEmail = strtolower(trim($reporterEmail));
        $email = $reporterEmail === '' ? null : $reporterEmail;
        if ($email !== null && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320)) {
            throw new FederationException('Correo de contacto inválido.', 400);
        }

        $target = $this->repository->resolveTarget($targetType, $targetId);
        if ($target === null) throw new FederationException('El recurso reportado no existe en este nodo.', 404);
        $targetNodeId = (string)$target['TargetNodeId'];
        if (!hash_equals($this->identity->nodeId(), $targetNodeId)) {
            throw new FederationException(
                'El reporte debe presentarse ante el nodo responsable del recurso. Abre el recurso desde su nodo de origen/custodia y usa Reportar abuso.',
                409
            );
        }

        $contentId = is_string($target['ContentId'] ?? null) && $target['ContentId'] !== ''
            ? $this->normalizeContentId((string)$target['ContentId'])
            : null;
        $reportId = 'far_' . FederationCodec::base64UrlEncode(random_bytes(18));
        $db = $this->app->db();
        $db->begin_transaction();
        try {
            $row = $this->repository->createReport(
                $reportId,
                $targetType,
                $targetId,
                $contentId,
                $targetNodeId,
                $category,
                $details,
                $email
            );
            $this->audit('report.created', $contentId ?? '', $reportId, null, [
                'target_type' => $targetType,
                'target_id' => $targetId,
                'category' => $category,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            if ($e instanceof FederationException) throw $e;
            throw new FederationException('No se pudo registrar el reporte de forma completa.', 500);
        }
        return [
            'ok' => true,
            'report_id' => $reportId,
            'status' => 'pending',
            'message' => 'Reporte recibido. El superusuario del nodo responsable debe revisarlo antes de bloquear o eliminar contenido.',
        ];
    }

    public function adminState(): array
    {
        $this->ensureModerationSchema();
        $nodeId = $this->identity->nodeId();
        return [
            'ok' => true,
            'node_id' => $nodeId,
            'reports' => $this->repository->pendingReports(),
            'active_blocks' => $this->repository->activeBlocks($nodeId),
        ];
    }

    public function decide(string $reportId, string $decision, int $userId, string $reason): array
    {
        $decision = strtolower(trim($decision));
        $reason = trim($reason);
        if ($userId <= 0) throw new FederationException('Superusuario inválido.', 403);
        if ($reason === '' || strlen($reason) > 1000) throw new FederationException('Indica el motivo de la decisión.', 400);

        $before = $this->repository->report($reportId);
        if ($before === null) throw new FederationException('Reporte no encontrado.', 404);

        if ($decision === 'reject') {
            $row = $this->repository->decideReport($reportId, 'rejected', $userId, $reason);
            $this->audit('report.reject', (string)($row['ContentId'] ?? ''), $reportId, $userId, ['reason' => $reason]);
            return ['ok' => true, 'report' => $row, 'blocked' => false];
        }
        if ($decision !== 'confirm') throw new FederationException('Decisión no soportada.', 400);

        $contentId = is_string($before['ContentId'] ?? null) ? (string)$before['ContentId'] : '';
        if ($contentId === '') {
            throw new FederationException('El recurso todavía no tiene una huella SHA-256 verificable; no puede bloquearse globalmente por hash.', 409);
        }
        $contentId = $this->normalizeContentId($contentId);

        $payload = [
            'content_id' => $contentId,
            'responsible_node_id' => $this->identity->nodeId(),
            'reason_code' => (string)$before['Category'],
            'report_id' => $reportId,
            'decision' => 'confirmed',
        ];
        $event = $this->events->emit(
            $this->identity,
            'moderation.block',
            substr($contentId, 7),
            $payload
        );

        // Primero bloqueamos y retiramos las copias administradas. Sólo después
        // el reporte sale de pendientes.
        $cleanup = $this->cleanupContent($contentId);
        $row = $this->repository->decideReport($reportId, 'confirmed', $userId, $reason);
        $this->audit('content.block', $contentId, $reportId, $userId, [
            'reason' => $reason,
            'event_id' => (string)$event['event_id'],
            'cleanup' => $cleanup,
        ]);

        return [
            'ok' => true,
            'report' => $row,
            'blocked' => true,
            'content_id' => $contentId,
            'event_id' => (string)$event['event_id'],
            'cleanup' => $cleanup,
            'refund_policy' => 'no_automatic_refund_for_confirmed_prohibited_content',
        ];
    }

    public function unblock(string $contentId, int $userId, string $reason): array
    {
        $contentId = $this->normalizeContentId($contentId);
        $reason = trim($reason);
        if ($userId <= 0) throw new FederationException('Superusuario inválido.', 403);
        if ($reason === '' || strlen($reason) > 1000) {
            throw new FederationException('Indica el motivo para revocar el bloqueo.', 400);
        }

        $nodeId = $this->identity->nodeId();
        $block = $this->repository->activeBlock($contentId, $nodeId);
        if ($block === null) {
            throw new FederationException('El nodo actual no tiene un bloqueo activo propio para esa huella.', 404);
        }

        $reportId = is_string($block['ReportId'] ?? null) && $block['ReportId'] !== ''
            ? (string)$block['ReportId']
            : null;
        $event = $this->events->emit(
            $this->identity,
            'moderation.unblock',
            substr($contentId, 7),
            [
                'content_id' => $contentId,
                'responsible_node_id' => $nodeId,
                'report_id' => $reportId,
                'reason' => $reason,
            ]
        );
        $this->audit('content.unblock', $contentId, $reportId, $userId, [
            'reason' => $reason,
            'event_id' => (string)$event['event_id'],
            'restores_deleted_bytes' => false,
        ]);

        return [
            'ok' => true,
            'unblocked' => true,
            'content_id' => $contentId,
            'event_id' => (string)$event['event_id'],
            'restored' => false,
            'message' => 'Huella desbloqueada. Los archivos ya eliminados no se restauran; pueden volver a subirse.',
        ];
    }

    public function cleanupBlockedLocalContent(int $limit = 100): array
    {
        $processed = $deleted = $errors = 0;
        foreach ($this->repository->blockedContentIds($limit) as $contentId) {
            $processed++;
            try {
                $result = $this->cleanupContent($contentId);
                $deleted += (int)$result['deleted_objects'];
            } catch (\Throwable $e) {
                $errors++;
                error_log('[Federation moderation cleanup] ' . $e->getMessage());
            }
        }
        return ['processed' => $processed, 'deleted_objects' => $deleted, 'errors' => $errors];
    }

    public function cleanupContent(string $contentId): array
    {
        $contentId = $this->normalizeContentId($contentId);
        $deleted = 0;
        $seenKeys = [];

        foreach ($this->repository->fingerprintSubjects($contentId) as $subject) {
            $key = trim((string)($subject['S3Key'] ?? ''));
            if ($key !== '') $deleted += $this->deleteKeyOnce($key, $seenKeys);
            if ((string)$subject['SubjectType'] === 'drop') {
                $dropId = (string)$subject['SubjectId'];
                $stmt = $this->app->db()->prepare(
                    "UPDATE FederationDrops SET Status='deleted', DeletedAt=UTC_TIMESTAMP(6)
                     WHERE DropId=? AND Status<>'deleted'"
                );
                if ($stmt) {
                    $stmt->bind_param('s', $dropId);
                    $stmt->execute();
                    $stmt->close();
                }
                $placement = $this->app->db()->prepare(
                    "UPDATE FederationDropPlacements SET Status='deleted', UpdatedAt=UTC_TIMESTAMP(6)
                     WHERE DropId=?"
                );
                if ($placement) {
                    $placement->bind_param('s', $dropId);
                    $placement->execute();
                    $placement->close();
                }
            }
        }

        $stmt = $this->app->db()->prepare(
            "SELECT ResourceId, S3Key FROM FederationReplicaObjects WHERE ContentId=? AND Status<>'revoked'"
        );
        if ($stmt) {
            $stmt->bind_param('s', $contentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $resourceIds = [];
            while ($row = $result->fetch_assoc()) {
                $deleted += $this->deleteKeyOnce((string)$row['S3Key'], $seenKeys);
                $resourceIds[] = (string)$row['ResourceId'];
            }
            $stmt->close();
            foreach ($resourceIds as $resourceId) {
                $up = $this->app->db()->prepare(
                    "UPDATE FederationReplicaObjects SET Status='revoked', UpdatedAt=UTC_TIMESTAMP(6) WHERE ResourceId=?"
                );
                if ($up) {
                    $up->bind_param('s', $resourceId);
                    $up->execute();
                    $up->close();
                }
            }
        }

        $hex = substr($contentId, 7);
        $files = $this->app->db()->prepare(
            "SELECT id_, Ruta, Encriptado FROM FileS3
             WHERE Found=1
               AND LOWER(COALESCE(
                 JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(Metadatos), Metadatos, '{}'),'$.hash_sha256')),
                 JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(Metadatos), Metadatos, '{}'),'$.sha256')),
                 JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(Metadatos), Metadatos, '{}'),'$.checksum_sha256')),
                 ''
               )) IN (?, ?)"
        );
        if ($files) {
            $prefixed = 'sha256:' . $hex;
            $files->bind_param('ss', $hex, $prefixed);
            $files->execute();
            $result = $files->get_result();
            $ids = [];
            while ($row = $result->fetch_assoc()) {
                $key = FileViewHelper::buildS3Key((string)$row['Ruta'], (string)$row['Encriptado']);
                $deleted += $this->deleteKeyOnce($key, $seenKeys);
                $ids[] = (int)$row['id_'];
            }
            $files->close();
            foreach ($ids as $id) {
                $up = $this->app->db()->prepare('UPDATE FileS3 SET Found=0 WHERE id_=? LIMIT 1');
                if ($up) {
                    $up->bind_param('i', $id);
                    $up->execute();
                    $up->close();
                }
            }
        }

        return ['content_id' => $contentId, 'deleted_objects' => $deleted];
    }

    private function ensureModerationSchema(): void
    {
        if ($this->repository->schemaReady()) return;

        try {
            (new FederationModerationSchemaService($this->app->db()))->ensure();
        } catch (FederationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FederationException(
                'No se pudo preparar el esquema de moderación FederationCloud: ' . $e->getMessage(),
                503
            );
        }

        if (!$this->repository->schemaReady()) {
            throw new FederationException(
                'La migración de moderación terminó sin dejar disponibles todas las tablas requeridas.',
                503
            );
        }
    }

    private function deleteKeyOnce(string $key, array &$seen): int
    {
        $key = ltrim(trim($key), '/');
        if ($key === '' || isset($seen[$key])) return 0;
        $seen[$key] = true;
        try {
            $this->app->s3()->deleteObject(['Bucket' => $this->app->bucket(), 'Key' => $key]);
            return 1;
        } catch (\Throwable $e) {
            error_log('[Federation moderation delete] ' . $e->getMessage());
            return 0;
        }
    }

    private function audit(string $action, string $contentId, ?string $reportId, ?int $userId, array $details): void
    {
        if ($contentId === '') $contentId = 'sha256:' . str_repeat('0', 64);
        $this->repository->audit(
            'fma_' . FederationCodec::base64UrlEncode(random_bytes(18)),
            $action,
            $contentId,
            $reportId,
            $userId,
            $this->identity->nodeId(),
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    private function normalizeContentId(string $contentId): string
    {
        $contentId = strtolower(trim($contentId));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            throw new FederationException('Content ID SHA-256 inválido.', 400);
        }
        return $contentId;
    }
}
