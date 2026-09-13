<?php
declare(strict_types=1);

function httpsContractOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$root = dirname(__DIR__);
$reconcile = (string)file_get_contents($root . '/bin/federation_https_reconcile.php');
$installer = (string)file_get_contents($root . '/bin/install_federation_https_service.sh');
$refresh = (string)file_get_contents($root . '/bin/federation_endpoint_refresh.php');

httpsContractOk(str_contains($reconcile, '169.254.169.254/latest/api/token'), 'usa EC2 IMDSv2 para detectar IPv4 pública');
httpsContractOk(str_contains($reconcile, 'X-aws-ec2-metadata-token-ttl-seconds'), 'solicita token IMDSv2');
httpsContractOk(str_contains($reconcile, "'--preferred-profile', 'shortlived'"), 'certificado IP usa perfil shortlived');
httpsContractOk(str_contains($reconcile, "'--ip-address', \$host"), 'Certbot recibe IP como identificador IP');
httpsContractOk(str_contains($reconcile, "'--webroot-path', \$webroot"), 'modo IP usa webroot sin detener Nginx');
httpsContractOk(str_contains($reconcile, 'Certbot 5.4 o superior'), 'modo IP exige versión compatible de Certbot');
httpsContractOk(str_contains($reconcile, "\$runtime['ARCADECLOUD_PUBLIC_URL']"), 'actualiza public_url administrada');
httpsContractOk(str_contains($reconcile, "\$runtime['ARCADECLOUD_FEDERATION_URL']"), 'actualiza federation_url administrada');
httpsContractOk(str_contains($reconcile, 'federation_endpoint_refresh.php'), 'republica descriptor después de reconciliar endpoint');
httpsContractOk(str_contains($reconcile, 'proxy_pass http://127.0.0.1:80'), 'modo IP sólo hace proxy hacia backend HTTP local');
httpsContractOk(!str_contains($reconcile, 'shell_exec('), 'no usa shell_exec');
httpsContractOk(!preg_match('/\bexec\s*\(/', $reconcile), 'no usa exec arbitrario');
httpsContractOk(str_contains($reconcile, "['bypass_shell' => true]"), 'proc_open evita interpretación de shell para comandos externos');

httpsContractOk(str_contains($installer, 'After=network-online.target nginx.service'), 'servicio espera red y Nginx');
httpsContractOk(str_contains($installer, 'OnBootSec=45s'), 'reconciliación se agenda al arrancar');
httpsContractOk(str_contains($installer, 'OnUnitActiveSec=${INTERVAL_HOURS}h'), 'timer mantiene renovación periódica');
httpsContractOk(!preg_match('/^\s*systemctl enable /m', $installer), 'instalador no habilita timer antes de la primera prueba');
httpsContractOk(str_contains($installer, 'sudo systemctl enable --now arcadecloud-federation-https.timer'), 'documenta activación explícita después de probar');
httpsContractOk(str_contains($installer, 'NO ejecutó Certbot ni habilitó el timer'), 'activación inicial requiere paso explícito del operador');

httpsContractOk(str_contains($refresh, 'FederationDirectoryService'), 'refresh reutiliza servicio FederationCloud existente');
httpsContractOk(str_contains($refresh, 'SKIP: el nodo aún no tiene identidad'), 'nodo nuevo puede preparar HTTPS antes de crear identidad');
httpsContractOk(!str_contains($refresh, 'secret_key'), 'refresh no lee ni imprime clave privada directamente');
httpsContractOk(!str_contains($refresh, 'payload_key'), 'refresh no expone payload key');

fwrite(STDOUT, "Federation HTTPS contract smoke: OK\n");
