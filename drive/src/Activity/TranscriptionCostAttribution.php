<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

/**
 * Deriva unidades atribuibles de Amazon Transcribe a partir del resultado
 * final devuelto por AWS. No consulta S3 ni inventa duración desde el tamaño
 * del archivo: usa exclusivamente timestamps emitidos por Transcribe.
 */
final class TranscriptionCostAttribution
{
    /**
     * La página pública de precios de Amazon Transcribe documenta facturación
     * por segundos y un mínimo de referencia de 15 segundos por solicitud.
     * El catálogo sigue siendo una referencia atribuida; Cost Explorer es la
     * fuente de reconciliación de la factura real de la cuenta.
     */
    private const MIN_REFERENCE_SECONDS = 15;

    public static function fromResult(array $transcript, array $job): array
    {
        $duration = self::durationSeconds($transcript);
        $billableSeconds = $duration > 0
            ? max(self::MIN_REFERENCE_SECONDS, (int)ceil($duration))
            : 0;

        $contentRedaction = self::hasContentRedaction($job);
        $customLanguageModel = self::hasCustomLanguageModel($job);
        $toxicityDetection = self::hasToxicityDetection($job);

        $units = [];
        if ($billableSeconds > 0) {
            $units['transcribe.standard_batch_second'] = $billableSeconds;

            if ($contentRedaction) {
                $units['transcribe.pii_redaction_second'] = $billableSeconds;
            }

            // Estos complementos se registran como unidades aunque el catálogo
            // no tenga todavía una tarifa verificada. AwsUnitPriceCatalog los
            // marcará como "partial" en vez de fingir precisión financiera.
            if ($customLanguageModel) {
                $units['transcribe.custom_language_model_second'] = $billableSeconds;
            }
            if ($toxicityDetection) {
                $units['transcribe.toxicity_detection_second'] = $billableSeconds;
            }
        } else {
            $units['transcribe.job_completed'] = 1;
        }

        return [
            'duration_seconds_observed' => round($duration, 3),
            'billable_seconds_reference' => $billableSeconds,
            'duration_source' => $duration > 0 ? 'transcribe_result_timestamps' : 'unavailable',
            'minimum_reference_seconds' => self::MIN_REFERENCE_SECONDS,
            'pricing_region_reference' => 'us-east-1',
            'units' => $units,
            'features' => [
                'content_redaction' => $contentRedaction,
                'custom_language_model' => $customLanguageModel,
                'toxicity_detection' => $toxicityDetection,
            ],
        ];
    }

    public static function durationSeconds(array $transcript): float
    {
        $results = is_array($transcript['results'] ?? null)
            ? $transcript['results']
            : [];
        $max = 0.0;

        foreach ((array)($results['audio_segments'] ?? []) as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $max = max($max, self::positiveFloat($segment['end_time'] ?? null));
        }

        foreach ((array)($results['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $max = max($max, self::positiveFloat($item['end_time'] ?? null));
        }

        return $max;
    }

    private static function hasContentRedaction(array $job): bool
    {
        return is_array($job['ContentRedaction'] ?? null)
            && $job['ContentRedaction'] !== [];
    }

    private static function hasCustomLanguageModel(array $job): bool
    {
        return trim((string)($job['ModelSettings']['LanguageModelName'] ?? '')) !== '';
    }

    private static function hasToxicityDetection(array $job): bool
    {
        return is_array($job['ToxicityDetection'] ?? null)
            && $job['ToxicityDetection'] !== [];
    }

    private static function positiveFloat(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        return max(0.0, (float)$value);
    }
}
