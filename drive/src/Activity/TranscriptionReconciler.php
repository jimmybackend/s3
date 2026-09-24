<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

use ArcadeCloud\Drive\Aws\TranscriptionFileService;
use Aws\Exception\AwsException;
use Aws\TranscribeService\TranscribeServiceClient;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Reconcilia trabajos Amazon Transcribe sin depender del navegador.
 *
 * Para trabajos nuevos guarda job_name y las claves S3 esperadas en el evento.
 * Para trabajos históricos conserva el escaneo por CorrelationId. Como última
 * red de seguridad consulta únicamente las claves S3 esperadas (nunca lista el
 * bucket): si el resultado existe, lo registra en FileS3 y cierra la tarea.
 */
final class TranscriptionReconciler
{
    private const AWS_STATUSES = ['QUEUED', 'IN_PROGRESS', 'COMPLETED', 'FAILED'];
    private const MAX_AWS_SCAN = 2000;

    public function __construct(
        private mysqli $db,
        private TranscriptionFileService $files,
        private TranscribeServiceClient $transcribe,
        private ActivityCostRecorder $activity
    ) {
    }

    public function run(int $limit = 250): array
    {
        $limit = max(1, min(1000, $limit));
        $pending = $this->pendingEvents($limit);
        if ($pending === []) {
            return [
                'ok' => true,
                'pending' => 0,
                'matched' => 0,
                'completed' => 0,
                'failed' => 0,
                'in_progress' => 0,
                'recovered_from_s3' => 0,
                'errors' => [],
            ];
        }

        $byCorrelation = [];
        foreach ($pending as $event) {
            $correlation = trim((string)($event['CorrelationId'] ?? ''));
            if ($correlation !== '') {
                $byCorrelation[$correlation] = $event;
            }
        }

        $stats = [
            'ok' => true,
            'pending' => count($byCorrelation),
            'matched' => 0,
            'completed' => 0,
            'failed' => 0,
            'in_progress' => 0,
            'recovered_from_s3' => 0,
            'errors' => [],
        ];

        $handled = [];
        $scanned = 0;

        // Camino rápido para trabajos nuevos: el job_name ya quedó persistido.
        foreach ($byCorrelation as $correlation => $event) {
            $meta = $this->eventMetadata($event);
            $jobName = trim((string)($meta['job_name'] ?? ''));
            if ($jobName === '') continue;

            try {
                $result = $this->transcribe->getTranscriptionJob([
                    'TranscriptionJobName' => $jobName,
                ]);
                $job = is_array($result['TranscriptionJob'] ?? null)
                    ? $result['TranscriptionJob']
                    : [];
                $awsStatus = (string)($job['TranscriptionJobStatus'] ?? 'UNKNOWN');

                $this->reconcileOne($event, $jobName, $awsStatus);
                $handled[$correlation] = true;
                $stats['matched']++;
                $this->countStatus($stats, $awsStatus);
            } catch (Throwable $e) {
                // Si AWS ya no conserva el job o el resultado final no pudo
                // recuperarse por URI, la clave conocida de S3 puede bastar.
                try {
                    if ($this->recoverOneFromS3($event, $jobName)) {
                        $handled[$correlation] = true;
                        $stats['matched']++;
                        $stats['completed']++;
                        $stats['recovered_from_s3']++;
                        continue;
                    }
                } catch (Throwable $recoveryError) {
                    $stats['errors'][] = [
                        'event_id' => (int)($event['id_'] ?? 0),
                        'job_name' => $jobName,
                        'message' => substr($recoveryError->getMessage(), 0, 300),
                    ];
                    $handled[$correlation] = true;
                    continue;
                }

                // ResourceNotFound es normal para jobs antiguos: se deja para
                // el escaneo histórico/fallback. Otros fallos AWS también se
                // reintentan en el ciclo siguiente sin convertir la tarea en error.
                if ($e instanceof AwsException
                    && (string)$e->getAwsErrorCode() === 'BadRequestException') {
                    continue;
                }
            }
        }

        // Compatibilidad con eventos antiguos que no guardaron job_name.
        if (count($handled) < count($byCorrelation)) {
            foreach (self::AWS_STATUSES as $awsStatus) {
                $nextToken = null;
                do {
                    $params = [
                        'Status' => $awsStatus,
                        'MaxResults' => 100,
                    ];
                    if (is_string($nextToken) && $nextToken !== '') {
                        $params['NextToken'] = $nextToken;
                    }

                    $page = $this->transcribe->listTranscriptionJobs($params);
                    foreach ((array)($page['TranscriptionJobSummaries'] ?? []) as $summary) {
                        if (!is_array($summary)) continue;

                        $scanned++;
                        if ($scanned > self::MAX_AWS_SCAN) {
                            break 3;
                        }

                        $jobName = trim((string)($summary['TranscriptionJobName'] ?? ''));
                        if ($jobName === '') continue;

                        $correlation = ActivityCostRecorder::correlation('transcribe', $jobName);
                        if ($correlation === null
                            || !isset($byCorrelation[$correlation])
                            || isset($handled[$correlation])) {
                            continue;
                        }

                        $event = $byCorrelation[$correlation];
                        try {
                            $this->reconcileOne($event, $jobName, $awsStatus);
                            $handled[$correlation] = true;
                            $stats['matched']++;
                            $this->countStatus($stats, $awsStatus);
                        } catch (Throwable $e) {
                            try {
                                if ($this->recoverOneFromS3($event, $jobName)) {
                                    $handled[$correlation] = true;
                                    $stats['matched']++;
                                    $stats['completed']++;
                                    $stats['recovered_from_s3']++;
                                } else {
                                    throw $e;
                                }
                            } catch (Throwable $finalError) {
                                $handled[$correlation] = true;
                                $stats['errors'][] = [
                                    'event_id' => (int)($event['id_'] ?? 0),
                                    'job_name' => $jobName,
                                    'message' => substr($finalError->getMessage(), 0, 300),
                                ];
                            }
                        }

                        if (count($handled) >= count($byCorrelation)) {
                            break 3;
                        }
                    }

                    $nextToken = isset($page['NextToken']) ? (string)$page['NextToken'] : null;
                } while ($nextToken !== null && $nextToken !== '');
            }
        }

        // Última red: el job puede haber desaparecido de AWS pero su salida
        // esperada seguir en S3. Se consulta la clave concreta, nunca ListObjects.
        foreach ($byCorrelation as $correlation => $event) {
            if (isset($handled[$correlation])) continue;

            try {
                if ($this->recoverOneFromS3($event, '')) {
                    $handled[$correlation] = true;
                    $stats['matched']++;
                    $stats['completed']++;
                    $stats['recovered_from_s3']++;
                }
            } catch (Throwable $e) {
                $stats['errors'][] = [
                    'event_id' => (int)($event['id_'] ?? 0),
                    'job_name' => (string)($this->eventMetadata($event)['job_name'] ?? ''),
                    'message' => substr($e->getMessage(), 0, 300),
                ];
            }
        }

        $stats['unmatched'] = max(0, count($byCorrelation) - count($handled));
        $stats['aws_jobs_scanned'] = $scanned;
        $stats['ok'] = $stats['errors'] === [];
        return $stats;
    }

    private function reconcileOne(array $event, string $jobName, string $awsStatus): void
    {
        $userId = (int)($event['user_id_'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Evento Transcribe sin user_id válido.');
        }

        $correlation = (string)$event['CorrelationId'];
        $fileId = (int)($event['FileId'] ?? 0);
        $fileKey = trim((string)($event['file_key'] ?? ''));
        $meta = $this->eventMetadata($event);
        $started = microtime(true);

        if (!in_array($awsStatus, ['COMPLETED', 'FAILED'], true)) {
            $this->activity->success(
                $userId,
                'transcribe',
                'Transcribe',
                $fileId > 0 ? $fileId : null,
                ['transcribe.job_started' => 1],
                $started,
                array_merge($meta, [
                    'phase' => 'started',
                    'status' => $awsStatus,
                    'job_name' => $jobName,
                    'reconciled_by' => 'server_timer_aws',
                    'task_center_updated_at' => gmdate('c'),
                ]),
                $correlation
            );
            return;
        }

        if ($awsStatus === 'FAILED') {
            $this->markFailed($event, $jobName);
            return;
        }

        $result = $this->files->status($userId, $jobName, $fileKey);
        if ((string)($result['status'] ?? '') !== 'COMPLETED') {
            throw new RuntimeException('AWS devolvió un estado distinto de COMPLETED al reconciliar.');
        }

        $this->markCompletedFromResult($event, $jobName, $result, 'server_timer_aws');
    }

    private function recoverOneFromS3(array $event, string $jobName): bool
    {
        $userId = (int)($event['user_id_'] ?? 0);
        $fileKey = trim((string)($event['file_key'] ?? ''));
        if ($userId <= 0 || $fileKey === '') {
            return false;
        }

        $meta = $this->eventMetadata($event);
        if ($jobName === '') {
            $jobName = trim((string)($meta['job_name'] ?? ''));
        }

        $subtitleFormats = is_array($meta['subtitle_formats'] ?? null)
            ? $meta['subtitle_formats']
            : [];

        $result = $this->files->recoverCompletedFromS3(
            $userId,
            $jobName,
            $fileKey,
            trim((string)($meta['aws_output_key'] ?? '')),
            $subtitleFormats,
            (string)($event['CreatedAt'] ?? '')
        );
        if ($result === null) {
            return false;
        }

        $this->markCompletedFromResult($event, $jobName, $result, 's3_expected_object');
        return true;
    }

    private function markCompletedFromResult(
        array $event,
        string $jobName,
        array $result,
        string $reconciledBy
    ): void {
        $userId = (int)($event['user_id_'] ?? 0);
        $fileId = (int)($event['FileId'] ?? 0);
        $correlation = (string)($event['CorrelationId'] ?? '');
        $meta = $this->eventMetadata($event);
        $started = microtime(true);

        $attribution = is_array($result['cost_attribution'] ?? null)
            ? $result['cost_attribution']
            : [];
        $units = is_array($attribution['units'] ?? null)
            ? $attribution['units']
            : ['transcribe.job_completed' => 1];
        $features = is_array($attribution['features'] ?? null)
            ? $attribution['features']
            : [];

        $outputName = trim((string)($result['json_nombre'] ?? $meta['output_name'] ?? ''));
        $outputRoute = (string)($meta['output_route'] ?? $result['ruta'] ?? '');

        $this->activity->success(
            $userId,
            'transcribe',
            'Transcribe',
            $fileId > 0 ? $fileId : null,
            $units,
            $started,
            array_merge($meta, [
                'phase' => 'completed',
                'status' => 'COMPLETED',
                'job_name' => $jobName,
                'output_name' => $outputName,
                'output_route' => $outputRoute,
                'reconciled_by' => $reconciledBy,
                'recovered_from_s3' => (bool)($result['recovered_from_s3'] ?? false),
                'duration_seconds_observed' => (float)($attribution['duration_seconds_observed'] ?? 0),
                'billable_seconds_reference' => (int)($attribution['billable_seconds_reference'] ?? 0),
                'duration_source' => (string)($attribution['duration_source'] ?? 'unavailable'),
                'pricing_region_reference' => (string)($attribution['pricing_region_reference'] ?? 'us-east-1'),
                'content_redaction' => (bool)($features['content_redaction'] ?? false),
                'custom_language_model' => (bool)($features['custom_language_model'] ?? false),
                'toxicity_detection' => (bool)($features['toxicity_detection'] ?? false),
                'task_center_updated_at' => gmdate('c'),
            ]),
            $correlation
        );

        $this->recordGeneratedS3Cost(
            $userId,
            $fileId,
            $result,
            $started,
            $correlation,
            $reconciledBy
        );
    }

    private function pendingEvents(int $limit): array
    {
        $sql = "SELECT e.id_, e.user_id_, e.FileId, e.CorrelationId, e.MetadataJson, e.CreatedAt,
                       f.Encriptado AS file_key
                FROM DriveActivityEvents e
                LEFT JOIN FileS3 f
                  ON f.id_ = e.FileId AND f.user_id_ = e.user_id_
                WHERE e.Action = 'transcribe'
                  AND e.Service = 'Transcribe'
                  AND e.PricingState = 'unpriced'
                  AND e.Status = 'ok'
                  AND e.CorrelationId IS NOT NULL
                  AND e.CorrelationId LIKE 'transcribe:%'
                  AND (
                       e.MetadataJson IS NULL
                       OR e.MetadataJson NOT LIKE '%\"phase\":\"cancelled\"%'
                  )
                ORDER BY e.CreatedAt ASC, e.id_ ASC
                LIMIT " . (int)$limit;

        $result = $this->db->query($sql);
        if (!$result) {
            throw new RuntimeException('No se pudieron consultar jobs Transcribe pendientes: ' . $this->db->error);
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private function markFailed(array $event, string $jobName): void
    {
        $metadata = json_encode(array_merge($this->eventMetadata($event), [
            'phase' => 'failed',
            'status' => 'FAILED',
            'job_name' => $jobName,
            'reconciled_by' => 'server_timer_aws',
            'task_center_updated_at' => gmdate('c'),
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($metadata)) {
            throw new RuntimeException('No se pudo serializar el fallo Transcribe.');
        }

        $stmt = $this->db->prepare(
            "UPDATE DriveActivityEvents
             SET Status = 'error', MetadataJson = ?
             WHERE id_ = ? AND PricingState = 'unpriced'"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar actualización de job fallido: ' . $this->db->error);
        }
        $eventId = (int)($event['id_'] ?? 0);
        if (!$stmt->execute([$metadata, $eventId])) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo marcar job Transcribe fallido: ' . $error);
        }
        $stmt->close();
    }

    private function recordGeneratedS3Cost(
        int $userId,
        int $fileId,
        array $result,
        float $started,
        string $correlation,
        string $reconciledBy
    ): void {
        $saved = is_array($result['guardados'] ?? null) ? $result['guardados'] : [];
        if ($saved === []) return;

        $puts = 0;
        $bytes = 0;
        foreach ($saved as $variant) {
            if (!is_array($variant) || ($variant['s3_put'] ?? true) === false) continue;
            $puts++;
            $bytes += max(0, (int)($variant['tamano'] ?? 0));
        }
        if ($puts <= 0) return;

        $this->activity->success(
            $userId,
            'transcribe',
            'S3',
            $fileId > 0 ? $fileId : null,
            [
                's3.put_request' => $puts,
                's3.storage_bytes_delta' => $bytes,
            ],
            $started,
            [
                'phase' => 'generated_outputs',
                'generated_files' => $puts,
                'storage_bytes_delta' => $bytes,
                'reconciled_by' => $reconciledBy,
            ],
            $correlation
        );
    }

    private function eventMetadata(array $event): array
    {
        $raw = (string)($event['MetadataJson'] ?? '');
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function countStatus(array &$stats, string $awsStatus): void
    {
        if ($awsStatus === 'COMPLETED') {
            $stats['completed']++;
        } elseif ($awsStatus === 'FAILED') {
            $stats['failed']++;
        } else {
            $stats['in_progress']++;
        }
    }
}
