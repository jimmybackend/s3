<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Setup;

use ArcadeCloud\Drive\Admin\ManagedRuntimeEnvironment;
use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use mysqli;
use RuntimeException;
use Throwable;

final class SuperAdminBootstrapService
{
    private PrivilegedServerHelper $helper;

    public function __construct(?PrivilegedServerHelper $helper = null)
    {
        ManagedRuntimeEnvironment::loadIntoProcess();
        $this->helper = $helper ?? new PrivilegedServerHelper();
    }

    public function create(array $input): array
    {
        $this->assertBasicConfigurationReady();
        $db = $this->connect();
        try {
            $this->assertUsersTable($db);

            $existing = $this->findExistingSuperadmin($db);
            if ($existing !== null) {
                $this->helper->completeBootstrapSetup();
                return [
                    'ok' => true,
                    'completed' => true,
                    'existing_superadmin' => true,
                    'user_id' => (int)$existing['id'],
                    'message' => 'Ya existía un superadmin. El supervisor temporal fue retirado y el setup quedó cerrado.',
                ];
            }

            $firstName = trim((string)($input['firstname'] ?? ''));
            $lastName = trim((string)($input['lastname'] ?? ''));
            $curp = trim((string)($input['curp'] ?? ''));
            $gender = trim((string)($input['gender'] ?? ''));
            $birthdate = trim((string)($input['birthdate'] ?? ''));
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $password = (string)($input['password'] ?? '');
            $address = trim((string)($input['address'] ?? ''));
            $neighborhood = trim((string)($input['neighborhood'] ?? ''));
            $postalcode = trim((string)($input['postalcode'] ?? ''));
            $state = trim((string)($input['state'] ?? ''));
            $country = trim((string)($input['country'] ?? ''));
            $homephone = trim((string)($input['homephone'] ?? ''));
            $mobilephone = trim((string)($input['mobilephone'] ?? ''));

            if ($firstName === '' || strlen($firstName) > 255) throw new RuntimeException('Indica un nombre válido para el superadmin.');
            if ($lastName === '' || strlen($lastName) > 255) throw new RuntimeException('Indica un apellido válido para el superadmin.');
            if ($curp === '' || strlen($curp) > 18) throw new RuntimeException('Indica un identificador válido de hasta 18 caracteres.');
            if (!in_array($gender, ['Masculino', 'Femenino', 'Otro'], true)) throw new RuntimeException('Selecciona un sexo válido.');
            $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);
            if (!$birth || $birth->format('Y-m-d') !== $birthdate) throw new RuntimeException('Indica una fecha de nacimiento válida.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) throw new RuntimeException('Indica un correo válido para el superadmin.');
            if (strlen($password) < 10 || strlen($password) > 4096) throw new RuntimeException('La contraseña del superadmin debe tener al menos 10 caracteres.');
            foreach ([
                'dirección' => [$address, 255],
                'colonia' => [$neighborhood, 255],
                'código postal' => [$postalcode, 10],
                'estado' => [$state, 255],
                'país' => [$country, 255],
                'teléfono de casa' => [$homephone, 15],
                'teléfono móvil' => [$mobilephone, 15],
            ] as $label => [$value, $max]) {
                if ($value === '' || strlen($value) > $max) {
                    throw new RuntimeException('Indica un ' . $label . ' válido.');
                }
            }

            $dup = $db->prepare('SELECT id FROM Users WHERE email = ? LIMIT 1');
            if (!$dup) throw new RuntimeException('No se pudo verificar el correo del superadmin.');
            $dup->bind_param('s', $email);
            $dup->execute();
            $duplicate = $dup->get_result()->fetch_assoc();
            $dup->close();
            if (is_array($duplicate)) throw new RuntimeException('Ese correo ya pertenece a un usuario existente.');

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hash) || $hash === '') throw new RuntimeException('No se pudo proteger la contraseña del superadmin.');

            $role = 'Administración';
            $systemRole = 'superadmin';
            $chat = 1;
            $status = 'Activo';

            $db->begin_transaction();
            try {
                $stmt = $db->prepare(
                    'INSERT INTO Users '
                    . '(firstname, lastname, curp, gender, email, password, role, system_role, chat, userstatus) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) throw new RuntimeException('No se pudo preparar la creación del superadmin.');
                $stmt->bind_param(
                    'ssssssssis',
                    $firstName,
                    $lastName,
                    $curp,
                    $gender,
                    $email,
                    $hash,
                    $role,
                    $systemRole,
                    $chat,
                    $status
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('No se pudo registrar el superadmin.');
                }
                $userId = (int)$stmt->insert_id;
                $stmt->close();

                $profile = $db->prepare(
                    'UPDATE Users SET birthdate = ?, address = ?, neighborhood = ?, postalcode = ?, '
                    . 'state = ?, country = ?, homephone = ?, mobilephone = ? WHERE id = ? LIMIT 1'
                );
                if (!$profile) throw new RuntimeException('No se pudo preparar el perfil del superadmin.');
                $profile->bind_param(
                    'ssssssssi',
                    $birthdate,
                    $address,
                    $neighborhood,
                    $postalcode,
                    $state,
                    $country,
                    $homephone,
                    $mobilephone,
                    $userId
                );
                if (!$profile->execute() || $profile->affected_rows !== 1) {
                    $profile->close();
                    throw new RuntimeException('No se pudo completar el perfil del superadmin.');
                }
                $profile->close();

                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                if ($e instanceof RuntimeException) throw $e;
                throw new RuntimeException('No se pudo crear el superadmin.');
            }

            $this->assertPersistedSuperadmin($db, $userId, $email);

            // Sólo después de comprobar que el usuario real existe en MySQL se retira
            // el supervisor temporal del setup.
            $this->helper->completeBootstrapSetup();

            return [
                'ok' => true,
                'completed' => true,
                'existing_superadmin' => false,
                'user_id' => $userId,
                'message' => 'Superadmin creado. El supervisor temporal fue eliminado y /setup quedó cerrado.',
            ];
        } finally {
            $db->close();
        }
    }

    private function assertBasicConfigurationReady(): void
    {
        $required = [
            'DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME',
            'AWS_REGION', 'AWS_S3_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
        ];
        $missing = [];
        foreach ($required as $name) {
            $value = getenv($name);
            if ($value === false || trim((string)$value) === '') $missing[] = $name;
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Completa primero Base de datos y AWS/S3. Faltan: ' . implode(', ', $missing)
            );
        }
    }

    private function connect(): mysqli
    {
        $required = ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'];
        foreach ($required as $name) {
            $value = getenv($name);
            if ($value === false || trim((string)$value) === '') {
                throw new RuntimeException('Configura y verifica la base de datos antes de crear el superadmin.');
            }
        }

        $port = filter_var(
            (string)(getenv('DB_PORT') ?: '3306'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );
        if ($port === false) throw new RuntimeException('DB_PORT no es válido.');

        $db = mysqli_init();
        if (!$db) throw new RuntimeException('No se pudo inicializar MySQL.');
        mysqli_options($db, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        if (!@mysqli_real_connect(
            $db,
            (string)getenv('DB_HOST'),
            (string)getenv('DB_USER'),
            (string)getenv('DB_PASSWORD'),
            (string)getenv('DB_NAME'),
            (int)$port
        )) {
            $db->close();
            throw new RuntimeException('No fue posible conectar con la base de datos configurada.');
        }
        if (!mysqli_set_charset($db, 'utf8mb4')) {
            $db->close();
            throw new RuntimeException('No se pudo configurar utf8mb4 en MySQL.');
        }
        return $db;
    }

    private function assertUsersTable(mysqli $db): void
    {
        $result = @$db->query('SELECT 1 FROM Users LIMIT 1');
        if ($result === false) {
            throw new RuntimeException('La conexión funciona, pero falta la tabla Users. Importa primero el esquema SQL de ArcadeCloud.');
        }
        $result->free();
    }

    private function assertPersistedSuperadmin(mysqli $db, int $userId, string $email): void
    {
        $stmt = $db->prepare(
            "SELECT id FROM Users WHERE id = ? AND email = ? AND role = 'Administración' "
            . "AND system_role = 'superadmin' AND userstatus = 'Activo' LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo verificar el superadmin recién creado.');
        }

        $stmt->bind_param('is', $userId, $email);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo verificar el superadmin recién creado.');
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($row)) {
            throw new RuntimeException(
                'El superadmin no quedó persistido correctamente en MySQL; el supervisor temporal se conserva.'
            );
        }
    }

    private function findExistingSuperadmin(mysqli $db): ?array
    {
        $result = $db->query("SELECT id, email FROM Users WHERE system_role = 'superadmin' LIMIT 1");
        if ($result === false) throw new RuntimeException('No se pudo verificar si ya existe un superadmin.');
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }
}
