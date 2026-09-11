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
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $password = (string)($input['password'] ?? '');

            if ($firstName === '' || strlen($firstName) > 255) throw new RuntimeException('Indica un nombre válido para el superadmin.');
            if ($lastName === '' || strlen($lastName) > 255) throw new RuntimeException('Indica un apellido válido para el superadmin.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) throw new RuntimeException('Indica un correo válido para el superadmin.');
            if (strlen($password) < 10 || strlen($password) > 4096) throw new RuntimeException('La contraseña del superadmin debe tener al menos 10 caracteres.');

            $dup = $db->prepare('SELECT id FROM Users WHERE email = ? LIMIT 1');
            if (!$dup) throw new RuntimeException('No se pudo verificar el correo del superadmin.');
            $dup->bind_param('s', $email);
            $dup->execute();
            $duplicate = $dup->get_result()->fetch_assoc();
            $dup->close();
            if (is_array($duplicate)) throw new RuntimeException('Ese correo ya pertenece a un usuario existente.');

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hash) || $hash === '') throw new RuntimeException('No se pudo proteger la contraseña del superadmin.');

            $curp = '';
            $gender = 'Otro';
            $role = 'Otros';
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
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                if ($e instanceof RuntimeException) throw $e;
                throw new RuntimeException('No se pudo crear el superadmin.');
            }

            // Si este paso llegara a fallar, el superadmin ya existe. Un reintento
            // entra por findExistingSuperadmin() y sólo completa el cierre.
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

    private function findExistingSuperadmin(mysqli $db): ?array
    {
        $result = $db->query("SELECT id, email FROM Users WHERE system_role = 'superadmin' LIMIT 1");
        if ($result === false) throw new RuntimeException('No se pudo verificar si ya existe un superadmin.');
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }
}
