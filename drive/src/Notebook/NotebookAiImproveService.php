<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Notebook;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\DriveApplication;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use RuntimeException;

final class NotebookAiImproveService
{
    private const MODEL_ID = 'amazon.nova-pro-v1:0';
    private const MAX_IMAGE_BYTES = 12_582_912;
    private const ALLOWED_FONTS = ['caveat','dancing','shadows','sans','serif','mono','rounded'];
    private const ALLOWED_ALIGNMENTS = ['left','right','justify'];

    public function __construct(
        private DriveApplication $app,
        private BedrockRuntimeClient $bedrock,
        private ActivityCostRecorder $activity
    ) {
    }

    /** @return array<string,mixed> */
    public function improve(int $userId, array $input): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $png = (string)($input['png'] ?? '');
        $json = (string)($input['json'] ?? '');
        $page = max(1, min(2000, (int)($input['page'] ?? 1)));
        $notebook = trim((string)($input['notebook'] ?? ''));

        if (!str_starts_with($png, 'data:image/png;base64,')) {
            throw new RuntimeException('Imagen de hoja inválida.');
        }

        $bytes = base64_decode(substr($png, 22), true);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('No se pudo decodificar la hoja.');
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('La hoja es demasiado grande para mejorar con IA.');
        }

        $state = json_decode($json, true);
        if (!is_array($state) || !is_array($state['objects'] ?? null)) {
            throw new RuntimeException('Estado editable inválido.');
        }

        $objects = $this->summarizeObjects($state['objects']);
        $payload = json_encode([
            'page' => $page,
            'paper' => (string)($state['paper'] ?? ''),
            'orientation' => (string)($state['orientation'] ?? ''),
            'background' => (string)($state['background'] ?? ''),
            'objects' => $objects,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $system = <<<'TXT'
Eres un editor visual de una libreta digital. Recibirás una imagen PNG de la hoja y una lista resumida de sus objetos editables.
Tu tarea es mejorar la hoja sin cambiar la intención del usuario:
- corrige ortografía, puntuación y errores evidentes de texto;
- mejora legibilidad, tamaño y posición cuando sea claramente necesario;
- conserva el contenido, imágenes, dibujos y significado;
- NO inventes datos, nombres, fechas ni frases;
- si hay escritura manual claramente legible que todavía está representada sólo por trazos, puedes convertirla a texto;
- sólo elimina trazos cuando estés seguro de que corresponden exactamente a escritura que estás convirtiendo; no elimines dibujos;
- no elimines imágenes.

Devuelve ÚNICAMENTE JSON válido con esta forma:
{
  "updates":[{"id":"id","text":"texto","x":0,"y":0,"size":28,"font":"caveat","alignment":"left","onLine":true}],
  "delete_ids":["id"],
  "add_texts":[{"text":"texto","x":0,"y":0,"size":28,"font":"caveat","alignment":"left","onLine":true}],
  "summary":"descripción breve"
}
Todos los campos de cada update son opcionales salvo id.
Fuentes permitidas: caveat,dancing,shadows,sans,serif,mono,rounded.
Alineaciones permitidas: left,right,justify.
No devuelvas markdown.
TXT;

        $started = microtime(true);
        $correlation = ActivityCostRecorder::correlation(
            'notebook-ai',
            $userId . '|' . $notebook . '|' . $page . '|' . hash('sha256', $json)
        );

        try {
            $response = $this->bedrock->converse([
                'modelId' => self::MODEL_ID,
                'system' => [['text' => $system]],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['image' => ['format' => 'png', 'source' => ['bytes' => $bytes]]],
                        ['text' => $payload],
                    ],
                ]],
                'inferenceConfig' => [
                    'maxTokens' => 3500,
                    'temperature' => 0.1,
                    'topP' => 0.5,
                ],
            ]);

            $text = $this->responseText($response);
            $instructions = $this->decodeJsonObject($text);
            $improved = $this->applyInstructions($state, $instructions);

            $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            $inputTokens = max(0, (int)($usage['inputTokens'] ?? 0));
            $outputTokens = max(0, (int)($usage['outputTokens'] ?? 0));

            $this->activity->success(
                $userId,
                'notebook_ai_improve',
                'Bedrock',
                null,
                [
                    'bedrock.nova_pro_input_token' => $inputTokens,
                    'bedrock.nova_pro_output_token' => $outputTokens,
                ],
                $started,
                [
                    'model' => self::MODEL_ID,
                    'page' => $page,
                    'objects_before' => count($state['objects']),
                    'objects_after' => count($improved['objects'] ?? []),
                ],
                $correlation
            );

            return [
                'ok' => true,
                'json' => json_encode($improved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'summary' => trim((string)($instructions['summary'] ?? 'Hoja mejorada con IA.')),
                'model' => self::MODEL_ID,
                'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
            ];
        } catch (\Throwable $error) {
            $this->activity->failure(
                $userId,
                'notebook_ai_improve',
                'Bedrock',
                $started,
                ['model' => self::MODEL_ID, 'page' => $page],
                $correlation
            );
            throw $error;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function summarizeObjects(array $objects): array
    {
        $out = [];
        foreach ($objects as $object) {
            if (!is_array($object)) continue;
            $kind = (string)($object['kind'] ?? '');
            $id = trim((string)($object['id'] ?? ''));
            if ($id === '') continue;

            if ($kind === 'text') {
                $out[] = [
                    'id' => $id,
                    'kind' => 'text',
                    'text' => mb_substr((string)($object['text'] ?? ''), 0, 2000, 'UTF-8'),
                    'x' => (float)($object['x'] ?? 0),
                    'y' => (float)($object['y'] ?? 0),
                    'size' => (float)($object['size'] ?? 28),
                    'font' => (string)($object['font'] ?? 'caveat'),
                    'alignment' => (string)($object['alignment'] ?? 'left'),
                    'onLine' => ($object['onLine'] ?? false) === true,
                ];
                continue;
            }

            if ($kind === 'image') {
                $out[] = [
                    'id' => $id,
                    'kind' => 'image',
                    'x' => (float)($object['x'] ?? 0),
                    'y' => (float)($object['y'] ?? 0),
                    'width' => (float)($object['width'] ?? 0),
                    'height' => (float)($object['height'] ?? 0),
                    'rotation' => (float)($object['rotation'] ?? 0),
                ];
                continue;
            }

            if ($kind === 'stroke' && is_array($object['points'] ?? null) && $object['points'] !== []) {
                $xs = [];
                $ys = [];
                foreach ($object['points'] as $point) {
                    if (!is_array($point)) continue;
                    $xs[] = (float)($point['x'] ?? 0);
                    $ys[] = (float)($point['y'] ?? 0);
                }
                if ($xs === [] || $ys === []) continue;
                $out[] = [
                    'id' => $id,
                    'kind' => 'stroke',
                    'bbox' => [
                        'x' => min($xs), 'y' => min($ys),
                        'width' => max($xs) - min($xs),
                        'height' => max($ys) - min($ys),
                    ],
                    'tool' => (string)($object['tool'] ?? 'pencil'),
                    'onLine' => ($object['onLine'] ?? false) === true,
                    'baseline' => isset($object['baseline']) ? (float)$object['baseline'] : null,
                ];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function applyInstructions(array $state, array $instructions): array
    {
        $objects = is_array($state['objects'] ?? null) ? $state['objects'] : [];
        $byId = [];
        foreach ($objects as $index => $object) {
            if (!is_array($object)) continue;
            $id = trim((string)($object['id'] ?? ''));
            if ($id !== '') $byId[$id] = $index;
        }

        foreach ((array)($instructions['updates'] ?? []) as $update) {
            if (!is_array($update)) continue;
            $id = trim((string)($update['id'] ?? ''));
            if ($id === '' || !isset($byId[$id])) continue;
            $index = $byId[$id];
            if (($objects[$index]['kind'] ?? '') !== 'text') continue;

            if (isset($update['text']) && is_string($update['text'])) {
                $objects[$index]['text'] = mb_substr(trim($update['text']), 0, 5000, 'UTF-8');
            }
            foreach (['x','y'] as $field) {
                if (isset($update[$field]) && is_numeric($update[$field])) {
                    $objects[$index][$field] = max(0, (float)$update[$field]);
                }
            }
            if (isset($update['size']) && is_numeric($update['size'])) {
                $objects[$index]['size'] = max(12, min(96, (float)$update['size']));
            }
            if (isset($update['font']) && in_array((string)$update['font'], self::ALLOWED_FONTS, true)) {
                $objects[$index]['font'] = (string)$update['font'];
            }
            if (isset($update['alignment']) && in_array((string)$update['alignment'], self::ALLOWED_ALIGNMENTS, true)) {
                $objects[$index]['alignment'] = (string)$update['alignment'];
            }
            if (array_key_exists('onLine', $update)) {
                $objects[$index]['onLine'] = $update['onLine'] === true;
            }
        }

        $deleteIds = [];
        foreach ((array)($instructions['delete_ids'] ?? []) as $id) {
            if (is_string($id) && $id !== '') $deleteIds[$id] = true;
        }
        if ($deleteIds !== []) {
            $objects = array_values(array_filter($objects, static function ($object) use ($deleteIds): bool {
                if (!is_array($object)) return false;
                $id = (string)($object['id'] ?? '');
                if (!isset($deleteIds[$id])) return true;
                return (string)($object['kind'] ?? '') === 'image';
            }));
        }

        foreach ((array)($instructions['add_texts'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $text = trim((string)($item['text'] ?? ''));
            if ($text === '') continue;
            $objects[] = [
                'kind' => 'text',
                'id' => 'ai_' . bin2hex(random_bytes(8)),
                'text' => mb_substr($text, 0, 5000, 'UTF-8'),
                'x' => max(0, (float)($item['x'] ?? 76)),
                'y' => max(0, (float)($item['y'] ?? 64)),
                'size' => max(12, min(96, (float)($item['size'] ?? 28))),
                'font' => in_array((string)($item['font'] ?? ''), self::ALLOWED_FONTS, true) ? (string)$item['font'] : 'caveat',
                'alignment' => in_array((string)($item['alignment'] ?? ''), self::ALLOWED_ALIGNMENTS, true) ? (string)$item['alignment'] : 'left',
                'onLine' => ($item['onLine'] ?? true) === true,
                'color' => '#111111',
                'improvedByAi' => true,
            ];
        }

        $state['objects'] = $objects;
        $state['version'] = max(7, (int)($state['version'] ?? 0));
        $state['ai_improved_at'] = gmdate(DATE_ATOM);
        $state['ai_model'] = self::MODEL_ID;
        return $state;
    }

    private function responseText(mixed $response): string
    {
        $blocks = $response['output']['message']['content'] ?? [];
        $text = '';
        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                    $text .= $block['text'];
                }
            }
        }
        return trim($text);
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(string $text): array
    {
        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) return $decoded;
        if (preg_match('/\{.*\}/s', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) return $decoded;
        }
        throw new RuntimeException('Bedrock no devolvió una corrección válida.');
    }
}
