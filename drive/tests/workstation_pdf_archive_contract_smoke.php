<?php
declare(strict_types=1);
$base = dirname(__DIR__);
$docker = file_get_contents($base . '/docker/workstation/Dockerfile');
$installer = file_get_contents($base . '/bin/install_workstation_node.sh');
$guac = file_get_contents($base . '/bin/install_guacamole_node.sh');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
};
$assert(is_string($docker), 'Dockerfile accesible');
foreach (['atril', 'rar', 'unrar', 'zip', 'unzip', 'file-roller', 'p7zip-full'] as $package) {
    $assert((bool)preg_match('/(?<![a-z0-9-])' . preg_quote($package, '/') . '(?![a-z0-9-])/', $docker), "Paquete requerido: $package");
}
$assert(str_contains($docker, 'ubuntu:24.04'), 'Ubuntu 24.04 conservado');
$assert(str_contains($installer, '--publish 127.0.0.1:6080:6080'), 'noVNC privado conservado');
$assert(str_contains($installer, '--memory=5g --cpus=3'), 'Límites de recursos conservados');
$assert(str_contains($guac, '-p 127.0.0.1:8085:8080'), 'Guacamole privado conservado');
$assert(str_contains($guac, "(@CID,'port','3389')"), 'XRDP interno 3389 conservado');
$assert(str_contains($guac, "(@CID,'disable-gfx','true')"), 'Reparación de pantalla negra conservada');
echo "Workstation PDF/archives: contratos correctos\n";
