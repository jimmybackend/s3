<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Aws\FileMetadataRepository;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\PollyFileService;
use ArcadeCloud\Drive\Aws\RekognitionFileService;
use ArcadeCloud\Drive\Aws\TextractFileService;
use ArcadeCloud\Drive\Aws\TranslateFileService;
use ArcadeCloud\Drive\Http\JsonResponse;

final class AwsFileController extends AbstractJsonController
{
    public function textract(): never
    {
        $this->run('textract', 'Textract', function (int $userId): array {
            $key = $this->first('archivo', 'archivoTextract');
            $result = $this->textractService()->extract($userId, $key);
            $pages = max(1, (int)($result['page_count'] ?? 1));
            return [
                $result,
                (int)($result['file_id'] ?? 0),
                [['service' => 'Textract', 'units' => ['textract.detect_document_text_page' => $pages]]],
                ['pages' => $pages],
            ];
        });
    }

    public function translate(): never
    {
        $this->run('translate', 'Translate', function (int $userId): array {
            $key = $this->request->postString('archivo');
            $result = $this->translateService()->translate(
                $userId,
                $key,
                $this->request->postString('target', 'es'),
                $this->request->postString('source', 'auto')
            );

            $components = [[
                'service' => 'Translate',
                'units' => ['translate.standard_character' => max(0, (int)($result['characters_input'] ?? 0))],
            ]];

            if (($result['source_s3_get'] ?? false) === true) {
                $components[] = [
                    'service' => 'S3',
                    'units' => [
                        's3.get_request' => 1,
                        's3.transfer_bytes' => max(0, (int)($result['input_bytes'] ?? 0)),
                    ],
                ];
            }

            $pages = max(0, (int)($result['textract_pages'] ?? 0));
            if ($pages > 0) {
                $components[] = [
                    'service' => 'Textract',
                    'units' => ['textract.detect_document_text_page' => $pages],
                ];
            }

            return [
                $result,
                (int)($result['file_id'] ?? $this->fileId($userId, $key) ?? 0),
                $components,
                ['translate_requests' => max(0, (int)($result['requests'] ?? 0))],
            ];
        });
    }

    public function rekognition(): never
    {
        $this->run('rekognition', 'Rekognition', function (int $userId): array {
            $key = $this->first('archivo', 'key');
            $result = $this->rekognitionService()->analyze(
                $userId,
                $key,
                (float)$this->request->postString('min_conf', '70'),
                (int)$this->request->postString('max_labels', '50')
            );
            return [
                $result,
                $this->fileId($userId, $key),
                [['service' => 'Rekognition', 'units' => ['rekognition.group2_image' => 2]]],
                ['api_calls' => 2],
            ];
        });
    }

    public function pollyVoices(): never
    {
        $this->run(
            'polly_voices',
            'Polly',
            fn(int $userId): array => [
                $this->pollyService()->voices($userId, $this->request->queryString('language')),
                null,
                [],
                [],
            ],
            false,
            false
        );
    }

    public function pollyText(): never
    {
        $this->run('polly_load_text', 'S3', function (int $userId): array {
            $key = $this->request->postString('archivo');
            $result = $this->pollyService()->loadText($userId, $key);
            return [
                $result,
                (int)($result['file_id'] ?? $this->fileId($userId, $key) ?? 0),
                [[
                    'service' => 'S3',
                    'units' => [
                        's3.get_request' => 1,
                        's3.transfer_bytes' => max(0, (int)($result['bytes'] ?? 0)),
                    ],
                ]],
                [],
            ];
        });
    }

    public function pollyTts(): never
    {
        $this->run('polly', 'Polly', function (int $userId): array {
            $result = $this->pollyService()->synthesize($userId, $this->request->allPost());
            $engine = strtolower((string)($result['engine_used'] ?? 'standard'));
            $unit = match ($engine) {
                'neural' => 'polly.neural_character',
                'long-form' => 'polly.long-form_character',
                'generative' => 'polly.generative_character',
                default => 'polly.standard_character',
            };

            $components = [[
                'service' => 'Polly',
                'units' => [$unit => max(0, (int)($result['characters_input'] ?? 0))],
            ]];

            if (($result['mode'] ?? '') === 's3') {
                $components[] = [
                    'service' => 'S3',
                    'units' => [
                        's3.put_request' => 1,
                        's3.storage_bytes_delta' => max(0, (int)($result['output_bytes'] ?? 0)),
                    ],
                ];
            }

            return [
                $result,
                (int)($result['source_file_id'] ?? 0),
                $components,
                ['engine' => $engine],
            ];
        });
    }

    public function comprehend(): never
    {
        $this->run('comprehend', 'Comprehend', function (int $userId): array {
            $key = $this->first('key', 'archivo');
            $service = new \ArcadeCloud\Drive\Aws\ComprehendFileService(
                $this->app->db(),
                $this->app->s3(),
                \Config::getComprehend(),
                $this->app->bucket()
            );
            $analysis = $service->analyze($userId, $key);

            $components = [[
                'service' => 'Comprehend',
                'units' => ['comprehend.nlp_unit' => max(0, (int)($analysis['billing_units'] ?? 0))],
            ], [
                'service' => 'S3',
                'units' => [
                    's3.get_request' => 1,
                    's3.transfer_bytes' => max(0, (int)($analysis['bytes_original'] ?? 0)),
                ],
            ]];

            return [
                ['ok' => true, 'analysis' => $analysis],
                (int)($analysis['file_id'] ?? $this->fileId($userId, $key) ?? 0),
                $components,
                [
                    'billable_requests' => max(0, (int)($analysis['billable_requests'] ?? 0)),
                    'billing_units' => max(0, (int)($analysis['billing_units'] ?? 0)),
                ],
            ];
        });
    }

    private function run(
        string $action,
        string $primaryService,
        callable $callback,
        bool $post = true,
        bool $record = true
    ): never {
        $userId = 0;
        $started = microtime(true);
        try {
            if ($post) $this->requirePost();
            $userId = $this->guardAuthenticated();
            [$payload, $fileId, $components, $metadata] = $callback($userId);

            if ($record) {
                $correlation = $this->newCorrelation($action);
                foreach ((array)$components as $component) {
                    if (!is_array($component)) continue;
                    $service = trim((string)($component['service'] ?? $primaryService));
                    $units = is_array($component['units'] ?? null) ? $component['units'] : [];
                    $this->activity()->success(
                        $userId,
                        $action,
                        $service !== '' ? $service : $primaryService,
                        is_int($fileId) && $fileId > 0 ? $fileId : null,
                        $units,
                        $started,
                        is_array($metadata) ? $metadata : [],
                        $correlation
                    );
                }
            }

            JsonResponse::send($payload);
        } catch (\Throwable $e) {
            if ($userId > 0 && $record) {
                $this->activity()->failure($userId, $action, $primaryService, $started);
            }
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function newCorrelation(string $kind): ?string
    {
        try {
            return ActivityCostRecorder::correlation($kind, bin2hex(random_bytes(16)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function locator(): FileRecordLocator
    {
        return new FileRecordLocator($this->app->db());
    }

    private function fileId(int $userId, string $key): ?int
    {
        if ($key === '') return null;
        try {
            $id = (int)($this->locator()->requireReadableByKey($userId, $key)['id_'] ?? 0);
            return $id > 0 ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }

    private function textractService(): TextractFileService
    {
        return new TextractFileService(
            $this->locator(),
            new FileMetadataRepository($this->app->db()),
            \Config::getTextract(),
            $this->app->bucket()
        );
    }

    private function translateService(): TranslateFileService
    {
        return new TranslateFileService(
            $this->locator(),
            $this->app->s3(),
            $this->app->bucket(),
            $this->textractService(),
            \Config::getTranslate()
        );
    }

    private function rekognitionService(): RekognitionFileService
    {
        return new RekognitionFileService(
            $this->locator(),
            new FileMetadataRepository($this->app->db()),
            \Config::getRekognition(),
            $this->app->bucket()
        );
    }

    private function pollyService(): PollyFileService
    {
        return new PollyFileService(
            $this->locator(),
            new GeneratedFileRepository($this->app->db()),
            $this->app->s3(),
            \Config::getPolly(),
            $this->app->bucket()
        );
    }

    private function first(string ...$names): string
    {
        foreach ($names as $name) {
            $value = $this->request->postString($name);
            if ($value !== '') return $value;
        }
        return '';
    }
}
