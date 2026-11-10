<?php
declare(strict_types=1);
require __DIR__ . '/../src/View/TranscriptReader.php';
require __DIR__ . '/../src/View/SharePageRenderer.php';
use ArcadeCloud\Drive\View\TranscriptReader;
use ArcadeCloud\Drive\View\SharePageRenderer;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$reader = new TranscriptReader();
$source = json_encode(['results' => ['audio_segments' => [['transcript' => '<script>alert(1)</script>', 'start_time' => '1.34', 'end_time' => '23.799', 'speaker_label' => 'spk_1']]]]);
$parsed = $reader->parse($source);
check(count($parsed['segments']) === 1 && $parsed['segments'][0]['start'] === 1.34, 'AWS audio segments');
check(TranscriptReader::timestamp(3661.5) === '01:01:01', 'Hour timestamps');
$legacy = $reader->parse(json_encode(['results' => ['items' => [
 ['start_time' => '0', 'end_time' => '1', 'speaker_label' => 'spk_0', 'alternatives' => [['content' => 'Hola']]],
 ['type' => 'punctuation', 'alternatives' => [['content' => '.']]],
 ['start_time' => '2', 'end_time' => '3', 'speaker_label' => 'spk_1', 'alternatives' => [['content' => 'Sí']]]
]]]));
check(count($legacy['segments']) === 2 && $legacy['segments'][0]['text'] === 'Hola.', 'Legacy punctuation and speaker boundaries');
check($reader->parse('invalid')['text'] === 'invalid', 'Invalid JSON fallback');
check($reader->parse('{"results":{"transcripts":[{"transcript":"Texto completo"}]}}')['text'] === 'Texto completo', 'Untimed transcript fallback');
$renderer = new SharePageRenderer();
$share = ['key' => 'sample.json', 'url' => 'https://example.org/file', 'nombre' => 'sample.json'];
$html = $renderer->render('text', $share, $source, true);
check(str_contains($html, '00:01–00:23'), 'Rendered timestamps');
check(str_contains($html, 'Hablante 1') && !str_contains($html, '<script>alert'), 'Speaker and HTML escaping');
check(!str_starts_with($html, '<!doctype html>\\n'), 'No literal newline');
check(str_contains($html, 'css/styles.css'), 'Shared application styles');
check(!str_contains($renderer->render('text', $share, $source), 'reader-segment'), 'Normal JSON remains normal text');
foreach (['audio', 'video', 'image', 'file'] as $kind) check(str_contains($renderer->render($kind, $share), 'Abrir original'), 'Existing media viewer');
echo "Shared transcript reader: OK\n";
