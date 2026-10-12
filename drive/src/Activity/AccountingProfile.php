<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

/**
 * Display-only accounting profile. Neither profile creates charges or invoices.
 * Family is the safe default; business remains a preparation preview.
 */
final class AccountingProfile
{
    public static function fromEnvironment(): array
    {
        $requested = trim((string)(getenv('ARCADECLOUD_ACCOUNTING_PROFILE') ?: 'family'));
        return self::describe($requested);
    }

    public static function describe(string $requested): array
    {
        $business = $requested === 'business_preview';
        return [
            'mode' => $business ? 'business_preview' : 'family',
            'label' => $business ? 'Preparación empresarial (sin cobros)' : 'Familiar · gastos compartidos',
            'charges_enabled' => false,
            'payer' => 'Actualmente cubiertos por el propietario',
            'service_catalog' => [
                ['code' => 'legal_document_digitization', 'label' => 'Digitalización y cotejo documental', 'unit' => 'página'],
                ['code' => 'hearing_video_transcription', 'label' => 'Transcripción de audiencias y videos', 'unit' => 'minuto de audio/video'],
                ['code' => 'audio_transcription_review', 'label' => 'Revisión y corrección de transcripciones', 'unit' => 'minuto revisado'],
                ['code' => 'legal_document_organization', 'label' => 'Organización e índice de expedientes', 'unit' => 'expediente'],
            ],
        ];
    }
}
