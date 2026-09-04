<?php
/**
 * Archivo: Config-s3.php
 * Descripción:
 * Centraliza configuración AWS y creación de clientes del SDK.
 *
 * Prioridad:
 *   1. Variables de entorno.
 *   2. Constantes locales como fallback no secreto.
 */
declare(strict_types=1);

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Comprehend\ComprehendClient;
use Aws\Rekognition\RekognitionClient;
use Aws\Textract\TextractClient;
use Aws\Polly\PollyClient;
use Aws\Translate\TranslateClient;
use Aws\TranscribeService\TranscribeServiceClient;
use Aws\S3\S3Client;

final class Config
{
    /*
     * Fallbacks NO secretos.
     *
     * REGION puede tener un valor seguro por defecto.
     * BUCKET se conserva temporalmente por compatibilidad con código
     * antiguo que todavía será auditado, pero el runtime nuevo debe
     * utilizar siempre getBucket().
     */
    public const REGION = 'us-east-1';
    public const BUCKET = 's3nubeaws';

    /*
     * Nunca guardar credenciales reales en Git.
     */
    public const ACCESS_KEY = '';
    public const SECRET_KEY = '';

    public const DEFAULT_USER_ID = 1;

    public const RUTA_RAIZ       = 'Data/';
    public const RUTA_COMPARTIDA = 'Data/Compartidos/';

    /**
     * Evita búsquedas innecesarias de credenciales vía IMDS.
     */
    public static function bootAwsEnv(): void
    {
        putenv('AWS_EC2_METADATA_DISABLED=true');
    }

    /**
     * Obtiene una variable de entorno limpia.
     */
    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value)
            ? trim($value)
            : '';
    }

    /**
     * Región AWS.
     *
     * Prioridad:
     * AWS_REGION -> Config::REGION.
     */
    public static function getRegion(): string
    {
        $region = self::env('AWS_REGION');

        if ($region === '') {
            $region = trim(self::REGION);
        }

        if ($region === '') {
            throw new RuntimeException(
                'Falta AWS_REGION y no existe una región fallback válida.'
            );
        }

        return $region;
    }

    /**
     * Bucket S3.
     *
     * AWS_S3_BUCKET debe contener exclusivamente el nombre del bucket:
     *
     * correcto:
     *   nombre-del-bucket
     *
     * incorrecto:
     *   s3://nombre-del-bucket
     *   https://...
     *   nombre-del-bucket.s3.amazonaws.com
     */
    public static function getBucket(): string
    {
        $bucket = self::env('AWS_S3_BUCKET');

        if ($bucket === '') {
            $bucket = trim(self::BUCKET);
        }

        if ($bucket === '') {
            throw new RuntimeException(
                'Falta AWS_S3_BUCKET y no existe un bucket fallback válido.'
            );
        }

        if (
            str_contains($bucket, '://')
            || str_contains($bucket, '/')
            || str_contains($bucket, '\\')
            || preg_match('/\s/', $bucket)
        ) {
            throw new RuntimeException(
                'AWS_S3_BUCKET debe contener únicamente el nombre del bucket.'
            );
        }

        return $bucket;
    }

    /**
     * Credenciales AWS.
     *
     * Prioridad:
     * ENV -> constantes locales.
     *
     * No imprime ni registra las credenciales.
     */
    public static function getAwsCredentials(): array
    {
        self::bootAwsEnv();

        $key = self::env('AWS_ACCESS_KEY_ID');
        $secret = self::env('AWS_SECRET_ACCESS_KEY');

        if ($key === '') {
            $key = trim(self::ACCESS_KEY);
        }

        if ($secret === '') {
            $secret = trim(self::SECRET_KEY);
        }

        if ($key === '' || $secret === '') {
            throw new RuntimeException(
                'Faltan credenciales AWS. Define AWS_ACCESS_KEY_ID y AWS_SECRET_ACCESS_KEY.'
            );
        }

        $credentials = [
            'key'    => $key,
            'secret' => $secret,
        ];

        /*
         * Compatible también con credenciales temporales.
         */
        $token = self::env('AWS_SESSION_TOKEN');

        if ($token !== '') {
            $credentials['token'] = $token;
        }

        return $credentials;
    }

    /**
     * Configuración común para clientes AWS SDK.
     */
    public static function getAwsClientConfig(array $overrides = []): array
    {
        $config = [
            'region'      => self::getRegion(),
            'version'     => 'latest',
            'credentials' => self::getAwsCredentials(),
        ];

        return array_replace_recursive($config, $overrides);
    }

    public static function getS3(): S3Client
    {
        return new S3Client(
            self::getAwsClientConfig()
        );
    }

    public static function getBedrockRuntime(): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient(
            self::getAwsClientConfig([
                'http' => [
                    'connect_timeout' => 20,
                    'timeout' => 240,
                ],
            ])
        );
    }

    public static function getTextract(): TextractClient
    {
        return new TextractClient(
            self::getAwsClientConfig([
                'http' => [
                    'connect_timeout' => 15,
                    'timeout' => 120,
                ],
            ])
        );
    }

    public static function getComprehend(): ComprehendClient
    {
        return new ComprehendClient(
            self::getAwsClientConfig([
                'http' => [
                    'connect_timeout' => 15,
                    'timeout' => 120,
                ],
            ])
        );
    }

    public static function getRekognition(): RekognitionClient
    {
        return new RekognitionClient(
            self::getAwsClientConfig([
                'http' => [
                    'connect_timeout' => 15,
                    'timeout' => 120,
                ],
            ])
        );
    }

    public static function getPolly(): PollyClient
    {
        return new PollyClient(
            self::getAwsClientConfig([
                'http' => [
                    'connect_timeout' => 15,
                    'timeout' => 120,
                ],
            ])
        );
    }

    public static function getTranslate(): TranslateClient
    {
        return new TranslateClient(
            self::getAwsClientConfig([
                'http' => [
                    'connect_timeout' => 15,
                    'timeout' => 120,
                ],
            ])
        );
    }

    public static function getTranscribe(): TranscribeServiceClient
    {
        return new TranscribeServiceClient(
            self::getAwsClientConfig([
                'http' => [
                    'connect_timeout' => 15,
                    'timeout' => 120,
                ],
            ])
        );
    }
}
