<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\View;

/** Reads AWS Transcribe output without changing the original transcript. */
final class TranscriptReader
{
    public function parse(string $content): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['segments' => [], 'text' => $content, 'notice' => 'El archivo no contiene una transcripción JSON válida.'];
        }
        $results = is_array($data) ? ($data['results'] ?? []) : [];
        $segments = [];
        foreach (($results['audio_segments'] ?? []) as $segment) {
            if (!is_array($segment) || !is_string($segment['transcript'] ?? null)) continue;
            $segments[] = $this->segment($segment, $segment['transcript']);
        }
        // Older AWS output has word timestamps but no audio_segments.
        if (!$segments) {
            $current = null;
            foreach (($results['items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $word = $item['alternatives'][0]['content'] ?? '';
                if (!is_string($word) || $word === '') continue;
                $punctuation = ($item['type'] ?? '') === 'punctuation';
                $speaker = (string)($item['speaker_label'] ?? ($current['speaker_label'] ?? ''));
                $start = is_numeric($item['start_time'] ?? null) ? (float)$item['start_time'] : null;
                if (!$punctuation && $current !== null && ($speaker !== $current['speaker_label'] || ($start !== null && $current['start_time'] !== null && $start - $current['start_time'] >= 30))) {
                    $segments[] = $this->segment($current, $current['text']);
                    $current = null;
                }
                if ($current === null) $current = ['start_time' => $start, 'end_time' => null, 'speaker_label' => $speaker, 'text' => ''];
                $current['text'] .= ($punctuation || $current['text'] === '' ? '' : ' ') . $word;
                if (is_numeric($item['end_time'] ?? null)) $current['end_time'] = (float)$item['end_time'];
            }
            if ($current !== null) $segments[] = $this->segment($current, $current['text']);
        }
        $text = $results['transcripts'][0]['transcript'] ?? null;
        if (!is_string($text)) $text = $segments ? implode("\n\n", array_column($segments, 'text')) : $content;
        return ['segments' => $segments, 'text' => $text, 'notice' => $segments ? '' : 'No hay segmentos con tiempos disponibles; se muestra el texto completo.'];
    }

    private function segment(array $source, string $text): array
    {
        return ['text' => $text, 'start' => $this->time($source['start_time'] ?? null), 'end' => $this->time($source['end_time'] ?? null), 'speaker' => (string)($source['speaker_label'] ?? '')];
    }

    private function time(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float)$value) && (float)$value >= 0 ? (float)$value : null;
    }

    public static function timestamp(?float $seconds): string
    {
        if ($seconds === null) return 'Sin tiempo';
        $s = (int)$seconds;
        return $s >= 3600 ? sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }
}
