<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationNodeAdminService
{
    private FederationConfig $config;
    private FederationSeedConfig $seeds;
    private NodeIdentityService $identity;
    private FederationNodeDescriptorValidator $validator;
    private FederationNodeRepository $nodes;
    private FederationHttpClient $http;
    private PrivilegedServerHelper $helper;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->seeds = FederationSeedConfig::fromProjectConfig();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->validator = new FederationNodeDescriptorValidator();
        $this->nodes = new FederationNodeRepository($this->app->db());
        $this->http = new FederationHttpClient();
        $this->helper = new PrivilegedServerHelper();
    }

    public function state(): array
    {
        $path = $this->config->identityPath();
        $diagnostics = $this->diagnostics();
        if (!is_file($path)) {
            return [
                'ok' => true,
                'configured' => false,
                'enabled' => $this->config->enabled(),
                'identity_path' => $path,
                'public_url' => $this->config->publicUrl(),
                'federation_url' => $this->config->federationUrl(),
                'diagnostics' => $diagnostics,
            ];
        }
        if (!is_readable($path)) {
            return [
                'ok' => true,
                'configured' => true,
                'ready' => false,
                'enabled' => $this->config->enabled(),
                'identity_path' => $path,
                'error' => 'La identidad existe pero el proceso PHP no puede leerla.',
                'diagnostics' => $diagnostics,
            ];
        }

        $descriptor = $this->validator->validate($this->identity->signedDescriptor($this->config));
        return [
            'ok' => true,
            'configured' => true,
            'ready' => true,
            'enabled' => $this->config->enabled(),
            'identity_path' => $path,
            'node' => $this->summary($descriptor),
            'diagnostics' => $diagnostics,
        ];
    }

    public function createNode(string $requestedName): array
    {
        $this->ensureEnabled();
        $path = $this->config->identityPath();
        if (is_file($path)) {
            throw new FederationException('La identidad del nodo ya existe. Usa Renombrar en lugar de Crear.', 409);
        }
        $name = NodeIdentityService::normalizeNodeName($requestedName);
        $this->assertGloballyAvailable($name);

        $createdNodeId = '';
        $usedHelper = false;
        try {
            try {
                $created = NodeIdentityService::initialize($path, false, $name);
                $createdNodeId = (string)($created['node_id'] ?? '');
            } catch (FederationException $directError) {
                if (!$this->helper->available()) {
                    throw $this->withPermissionGuidance($directError, true);
                }
                $created = $this->helper->createIdentity($name);
                $createdNodeId = (string)($created['node_id'] ?? '');
                $usedHelper = true;
            }

            $this->identity = new NodeIdentityService($path);
            $descriptor = $this->validator->validate($this->identity->signedDescriptor($this->config));
            $createdNodeId = (string)$descriptor['node_id'];
            $this->registerDescriptor($descriptor);

            return [
                'ok' => true,
                'configured' => true,
                'message' => 'Nodo FederationCloud creado y registrado con un nombre global disponible.',
                'node' => $this->summary($descriptor),
                'used_privileged_helper' => $usedHelper,
            ];
        } catch (Throwable $e) {
            if ($createdNodeId !== '') {
                $this->rollbackNewIdentity($createdNodeId);
            }
            throw $e;
        }
    }

    public function renameNode(string $requestedName): array
    {
        $this->ensureEnabled();
        $name = NodeIdentityService::normalizeNodeName($requestedName);
        $nodeId = $this->identity->nodeId();
        $oldName = $this->identity->nodeName();

        if ($oldName !== null && hash_equals($oldName, $name)) {
            return $this->state() + ['message' => 'El nodo ya usa ese nombre.'];
        }

        $this->assertGloballyAvailable($name);
        $this->persistNameWithFallback($name);

        try {
            $this->identity = new NodeIdentityService($this->config->identityPath());
            $descriptor = $this->validator->validate($this->identity->signedDescriptor($this->config));
            if (!hash_equals((string)$descriptor['node_id'], $nodeId)) {
                throw new FederationException('El Node ID cambió durante el renombre; operación cancelada.', 500);
            }
            $this->registerDescriptor($descriptor);
        } catch (Throwable $e) {
            if ($oldName !== null && $oldName !== '') {
                try {
                    $this->persistNameWithFallback($oldName);
                    $this->identity = new NodeIdentityService($this->config->identityPath());
                } catch (Throwable) {
                    // El error original es más útil; state() mostrará la inconsistencia si persiste.
                }
            }
            throw $e;
        }

        return [
            'ok' => true,
            'message' => 'Nombre del nodo actualizado sin cambiar su identidad criptográfica.',
            'node' => $this->summary($descriptor),
        ];
    }

    private function assertGloballyAvailable(string $name): void
    {
        if ($this->seeds->isSeed($this->config->federationUrl())) {
            if (!$this->nodes->isNodeNameAvailable($name, '')) {
                throw new FederationException('Ese nombre FederationCloud ya está registrado por otro nodo.', 409);
            }
            return;
        }

        $response = $this->http->postJson($this->seeds->primary(), 'name-availability.php', ['node_name' => $name]);
        if (($response['ok'] ?? null) !== true || !array_key_exists('available', $response)) {
            throw new FederationException('El seed no pudo confirmar la disponibilidad del nombre del nodo.', 502);
        }
        if (($response['available'] ?? false) !== true) {
            throw new FederationException('Ese nombre FederationCloud ya está registrado por otro nodo.', 409);
        }
    }

    private function registerDescriptor(array $descriptor): void
    {
        if ($this->seeds->isSeed($this->config->federationUrl())) {
            $this->nodes->upsertVerified($descriptor);
            return;
        }
        $response = $this->http->postJson($this->seeds->primary(), 'register.php', ['descriptor' => $descriptor]);
        if (($response['ok'] ?? null) !== true) {
            throw new FederationException('El seed no confirmó el registro del nodo.', 502);
        }
    }

    private function persistNameWithFallback(string $name): void
    {
        try {
            $this->identity->renameNodeName($name);
            return;
        } catch (FederationException $directError) {
            if (!$this->helper->available()) {
                throw $this->withPermissionGuidance($directError, false);
            }
        }
        try {
            $this->helper->renameIdentity($name);
        } catch (Throwable $helperError) {
            throw new FederationException(
                'No se pudo escribir la identidad mediante el helper privilegiado: ' . $helperError->getMessage(),
                500
            );
        }
    }

    private function rollbackNewIdentity(string $nodeId): void
    {
        $path = $this->config->identityPath();
        if ($this->helper->available()) {
            try {
                $this->helper->deleteIdentityIfId($nodeId);
                return;
            } catch (Throwable) {
                // Se intenta también eliminación local cuando el directorio lo permite.
            }
        }
        if (is_file($path)) @unlink($path);
    }

    private function withPermissionGuidance(FederationException $error, bool $creating): FederationException
    {
        $path = $this->config->identityPath();
        $runtime = $this->runtimeUser();
        $driveRoot = dirname(__DIR__, 2);
        $installer = $driveRoot . '/bin/install_arcadecloud_admin_helper.sh';
        $operation = $creating ? 'crear' : 'actualizar';
        return new FederationException(
            'No se pudo ' . $operation . ' la identidad en ' . $path . '. ' .
            'El proceso PHP-FPM (' . $runtime . ') no tiene permisos suficientes y el helper privilegiado no está instalado. ' .
            'Instálalo una sola vez con: sudo bash ' . $installer . ' --php-user=' . $runtime . '. ' .
            'Alternativamente, para una identidad existente puedes conceder escritura sólo al archivo con ACL. Detalle: ' . $error->getMessage(),
            500
        );
    }

    private function diagnostics(): array
    {
        $path = $this->config->identityPath();
        $runtime = $this->runtimeUser();
        $driveRoot = dirname(__DIR__, 2);
        return [
            'runtime_user' => $runtime,
            'identity_exists' => is_file($path),
            'identity_readable' => is_readable($path),
            'identity_writable' => is_writable($path),
            'parent_writable' => is_writable(dirname($path)),
            'privileged_helper' => $this->helper->available(),
            'helper_path' => PrivilegedServerHelper::HELPER_PATH,
            'install_helper_command' => 'sudo bash ' . $driveRoot . '/bin/install_arcadecloud_admin_helper.sh --php-user=' . $runtime,
            'existing_file_acl_command' => 'sudo setfacl -m u:' . $runtime . ':rw ' . $path,
        ];
    }

    private function runtimeUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && is_string($info['name'] ?? null) && $info['name'] !== '') return $info['name'];
        }
        return 'USUARIO_PHP_FPM';
    }

    private function summary(array $descriptor): array
    {
        return [
            'node_id' => (string)$descriptor['node_id'],
            'node_name' => is_string($descriptor['node_name'] ?? null) ? (string)$descriptor['node_name'] : null,
            'public_url' => (string)$descriptor['public_url'],
            'federation_url' => (string)$descriptor['federation_url'],
        ];
    }

    private function ensureEnabled(): void
    {
        if (!$this->config->enabled()) {
            throw new FederationException('FederationCloud está desactivado. Un superusuario puede activar ARCADECLOUD_FEDERATION_ENABLED desde Configuración del servidor.', 503);
        }
    }
}
