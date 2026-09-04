<?php
declare(strict_types=1);

use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class LocalPresignedPutUploader implements UploaderInterface
{
    private const PENDING_KEY = 'drive_pending_local_uploads';
    private const TTL_SECONDS = 7200;

    private function db(): mysqli
    {
        global $db_connection;

        if (
            !isset($db_connection) ||
            !($db_connection instanceof mysqli)
        ) {
            throw new RuntimeException(
                'DB no disponible ($db_connection).'
            );
        }

        return $db_connection;
    }

    private function s3(): S3Client
    {
        return Config::getS3();
    }

    private function bucket(): string
    {
        return Config::getBucket();
    }

    private function userId(array $req): int
    {
        return (int)(
            $req['_user_id'] ??
            $_SESSION['user_id'] ??
            0
        );
    }

    private function cleanupPending(): void
    {
        $now = time();

        $pending =
            $_SESSION[self::PENDING_KEY] ?? [];

        if (!is_array($pending)) {
            $_SESSION[self::PENDING_KEY] = [];
            return;
        }

        foreach ($pending as $token => $row) {
            $created =
                (int)($row['created_at'] ?? 0);

            if (
                $created <= 0 ||
                ($now - $created) >
                    self::TTL_SECONDS
            ) {
                unset($pending[$token]);
            }
        }

        $_SESSION[self::PENDING_KEY] =
            $pending;
    }

    private function existingFileId(
        int $userId,
        string $key
    ): int {
        $stmt = $this->db()->prepare(
            'SELECT id_
             FROM FileS3
             WHERE user_id_ = ?
               AND Encriptado = ?
             LIMIT 1'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo comprobar FileS3: ' .
                $this->db()->error
            );
        }

        $stmt->bind_param(
            'is',
            $userId,
            $key
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            throw new RuntimeException(
                'No se pudo comprobar FileS3: ' .
                $error
            );
        }

        $stmt->bind_result($id);

        $found =
            $stmt->fetch();

        $stmt->close();

        return $found
            ? (int)$id
            : 0;
    }

    public function init(array $req): array
    {
        $this->cleanupPending();

        $nombreOriginal =
            trim(
                (string)($req['nombre'] ?? '')
            );

        $rutaObjetivo =
            rtrim(
                (string)(
                    $req['ruta_objetivo'] ?? ''
                ),
                '/'
            ) . '/';

        $userId =
            $this->userId($req);

        if (
            $nombreOriginal === '' ||
            $rutaObjetivo === '/' ||
            $userId <= 0
        ) {
            throw new RuntimeException(
                'Datos incompletos para iniciar la subida.'
            );
        }

        $nombreEncriptado =
            (
                new \ArcadeCloud\Drive\Storage\StorageObjectNameCodec()
            )->createFileObjectName(
                $nombreOriginal
            );

        $key =
            $rutaObjetivo .
            $nombreEncriptado;

        /*
         * No agregamos ACL al request firmado.
         *
         * El bucket/objeto ya es privado por defecto y así
         * el navegador no necesita reproducir x-amz-acl.
         */
        $cmd =
            $this->s3()->getCommand(
                'PutObject',
                [
                    'Bucket' =>
                        $this->bucket(),

                    'Key' =>
                        $key,
                ]
            );

        $request =
            $this->s3()
                ->createPresignedRequest(
                    $cmd,
                    '+1 hour'
                );

        $metadatos =
            json_encode(
                [
                    'ip_origen' =>
                        $_SERVER['REMOTE_ADDR']
                            ?? '127.0.0.1',

                    'user_agent' =>
                        $_SERVER['HTTP_USER_AGENT']
                            ?? 'desconocido',

                    'referer' =>
                        $_SERVER['HTTP_REFERER']
                            ?? 'ninguno',

                    'fecha_servidor' =>
                        date('Y-m-d'),

                    'hora_servidor' =>
                        date('H:i:s'),

                    'usuario_envio' =>
                        (string)(
                            $req['_usuario']
                            ?? 'usuario'
                        ),
                ],
                JSON_UNESCAPED_UNICODE
            );

        $token =
            bin2hex(
                random_bytes(18)
            );

        $_SESSION[
            self::PENDING_KEY
        ][$token] = [
            'created_at' =>
                time(),

            'user_id' =>
                $userId,

            'Nombre' =>
                $nombreOriginal,

            'Encriptado' =>
                $nombreEncriptado,

            'Metadatos' =>
                $metadatos,

            'Ruta' =>
                $rutaObjetivo,

            'key' =>
                $key,

            'completed_file_id' =>
                0,
        ];

        return [
            'url' =>
                (string)$request->getUri(),

            'key' =>
                $key,

            'ruta_objetivo' =>
                $rutaObjetivo,

            'nombreOriginal' =>
                $nombreOriginal,

            'nombreEncriptado' =>
                $nombreEncriptado,

            'upload_token' =>
                $token,
        ];
    }

    /*
     * action=part se utiliza únicamente como cancelación
     * para local_put.
     */
    public function part(array $req): array
    {
        $this->cleanupPending();

        $cancel =
            filter_var(
                $req['cancel'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

        if (!$cancel) {
            return [
                'ok' => true
            ];
        }

        $token =
            trim(
                (string)(
                    $req['upload_token'] ?? ''
                )
            );

        $userId =
            $this->userId($req);

        if ($token === '') {
            return [
                'ok' => true,
                'cancelled' => true,
                'already_gone' => true,
            ];
        }

        $pending =
            $_SESSION[
                self::PENDING_KEY
            ][$token] ?? null;

        if (!is_array($pending)) {
            return [
                'ok' => true,
                'cancelled' => true,
                'already_gone' => true,
            ];
        }

        if (
            (int)($pending['user_id'] ?? 0)
            !== $userId
        ) {
            throw new RuntimeException(
                'La subida no pertenece al usuario actual.'
            );
        }

        /*
         * MUY IMPORTANTE:
         *
         * Si FileS3 ya fue confirmado, jamás eliminamos S3.
         */
        if (
            (int)(
                $pending[
                    'completed_file_id'
                ] ?? 0
            ) > 0
        ) {
            return [
                'ok' => true,
                'cancelled' => false,
                'already_completed' => true,
                'file_id' =>
                    (int)$pending[
                        'completed_file_id'
                    ],
            ];
        }

        $key =
            (string)(
                $pending['key'] ?? ''
            );

        /*
         * Protección extra:
         * quizá MySQL ya insertó y solo se perdió
         * la respuesta HTTP.
         */
        $existingId =
            $key !== ''
                ? $this->existingFileId(
                    $userId,
                    $key
                )
                : 0;

        if ($existingId > 0) {
            $_SESSION[
                self::PENDING_KEY
            ][$token][
                'completed_file_id'
            ] = $existingId;

            return [
                'ok' => true,
                'cancelled' => false,
                'already_completed' => true,
                'file_id' =>
                    $existingId,
            ];
        }

        if ($key !== '') {
            $this->s3()->deleteObject([
                'Bucket' =>
                    $this->bucket(),

                'Key' =>
                    $key,
            ]);
        }

        unset(
            $_SESSION[
                self::PENDING_KEY
            ][$token]
        );

        return [
            'ok' => true,
            'cancelled' => true,
            'key' => $key,
        ];
    }

    public function complete(array $req): array
    {
        $this->cleanupPending();

        $token =
            trim(
                (string)(
                    $req['upload_token'] ?? ''
                )
            );

        $requestedSize =
            max(
                0,
                (int)(
                    $req['tamano'] ?? 0
                )
            );

        $userId =
            $this->userId($req);

        $pending =
            $token !== ''
                ? (
                    $_SESSION[
                        self::PENDING_KEY
                    ][$token] ?? null
                )
                : null;

        if (!is_array($pending)) {
            throw new RuntimeException(
                'La sesión de subida expiró o no existe.'
            );
        }

        if (
            (int)($pending['user_id'] ?? 0)
            !== $userId
        ) {
            throw new RuntimeException(
                'La subida no pertenece al usuario actual.'
            );
        }

        /*
         * COMPLETE IDEMPOTENTE.
         *
         * Si el navegador repite complete porque perdió
         * una respuesta, devolvemos el mismo file_id.
         */
        $completedId =
            (int)(
                $pending[
                    'completed_file_id'
                ] ?? 0
            );

        if ($completedId > 0) {
            return [
                'ok' => true,
                'file_id' =>
                    $completedId,

                'key' =>
                    (string)$pending['key'],

                'ruta_objetivo' =>
                    (string)$pending['Ruta'],

                'tamano' =>
                    (int)(
                        $pending[
                            'completed_size'
                        ] ?? $requestedSize
                    ),

                'idempotent' =>
                    true,
            ];
        }

        $key =
            (string)$pending['key'];

        /*
         * S3 debe confirmar que el objeto realmente existe.
         */
        $head =
            $this->s3()->headObject([
                'Bucket' =>
                    $this->bucket(),

                'Key' =>
                    $key,
            ]);

        $realSize =
            (int)(
                $head[
                    'ContentLength'
                ] ?? 0
            );

        if (
            $requestedSize > 0 &&
            $realSize !==
                $requestedSize
        ) {
            throw new RuntimeException(
                'El tamaño recibido en S3 no coincide con el archivo original.'
            );
        }

        /*
         * Si una petición anterior alcanzó a insertar en BD
         * pero se perdió antes de actualizar la sesión,
         * recuperamos ese mismo registro.
         */
        $existingId =
            $this->existingFileId(
                $userId,
                $key
            );

        if ($existingId > 0) {
            $_SESSION[
                self::PENDING_KEY
            ][$token][
                'completed_file_id'
            ] = $existingId;

            $_SESSION[
                self::PENDING_KEY
            ][$token][
                'completed_size'
            ] = $realSize;

            return [
                'ok' => true,
                'file_id' =>
                    $existingId,

                'key' =>
                    $key,

                'ruta_objetivo' =>
                    (string)$pending['Ruta'],

                'tamano' =>
                    $realSize,

                'idempotent' =>
                    true,
            ];
        }

        $repo =
            new FileS3Repository(
                $this->db()
            );

        $fileId =
            $repo->insertFile([
                'Nombre' =>
                    (string)$pending['Nombre'],

                'Encriptado' =>
                    (string)$pending['Encriptado'],

                'Tamano' =>
                    $realSize,

                'Metadatos' =>
                    $pending['Metadatos']
                        ?? null,

                'Ruta' =>
                    (string)$pending['Ruta'],

                'Found' =>
                    1,

                'AccessType' =>
                    'normal',

                'Fecha' =>
                    date(
                        'Y-m-d H:i:s'
                    ),

                'user_id_' =>
                    $userId,
            ]);

        /*
         * NO eliminamos el token al completar.
         * Lo conservamos temporalmente para que repetir
         * complete sea seguro.
         */
        $_SESSION[
            self::PENDING_KEY
        ][$token][
            'completed_file_id'
        ] = $fileId;

        $_SESSION[
            self::PENDING_KEY
        ][$token][
            'completed_size'
        ] = $realSize;

        $_SESSION[
            self::PENDING_KEY
        ][$token][
            'completed_at'
        ] = time();

        return [
            'ok' => true,
            'file_id' =>
                $fileId,

            'key' =>
                $key,

            'ruta_objetivo' =>
                (string)$pending['Ruta'],

            'tamano' =>
                $realSize,

            'idempotent' =>
                false,
        ];
    }
}
