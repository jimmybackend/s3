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
httpsContractOk(str_contains($installer, 'EnvironmentFile=-$DRIVE_ENV'), 'servicio carga drive.env como los demás workers CLI');
httpsContractOk(str_contains($installer, 'EnvironmentFile=-$FEDERATION_ENV'), 'servicio carga federation.env como los demás workers CLI');
httpsContractOk(str_contains($installer, '--drive-env=*'), 'instalador permite cambiar drive.env');
httpsContractOk(str_contains($installer, '--federation-env=*'), 'instalador permite cambiar federation.env');
httpsContractOk(str_contains($installer, 'php-fpm'), 'instalador advierte si run-user no coincide con un worker PHP-FPM');
httpsContractOk(!str_contains($installer, 'chown root:"$RUN_GROUP" "$RUNTIME_ENV"'), 'no cambia ownership de runtime-env existente');
httpsContractOk(str_contains($installer, 'test -r "$RUNTIME_ENV"'), 'verifica lectura de runtime-env por el usuario elegido');
httpsContractOk(str_contains($installer, 'OnBootSec=45s'), 'reconciliación se agenda al arrancar');
httpsContractOk(str_contains($installer, 'OnUnitInactiveSec=${INTERVAL_HOURS}h'), 'timer oneshot se reprograma después de completar el servicio');
httpsContractOk(!str_contains($installer, 'OnUnitActiveSec=${INTERVAL_HOURS}h'), 'timer no depende del estado activo de un servicio oneshot');
httpsContractOk(!preg_match('/^\s*systemctl enable /m', $installer), 'instalador no habilita timer antes de la primera prueba');
httpsContractOk(str_contains($installer, 'sudo systemctl enable --now arcadecloud-federation-https.timer'), 'documenta activación explícita después de probar');
httpsContractOk(str_contains($installer, 'NO ejecutó Certbot ni habilitó el timer'), 'activación inicial requiere paso explícito del operador');

httpsContractOk(str_contains($refresh, 'FederationDirectoryService'), 'refresh reutiliza servicio FederationCloud existente');
httpsContractOk(str_contains($refresh, 'SKIP: el nodo aún no tiene identidad'), 'nodo nuevo puede preparar HTTPS antes de crear identidad');
httpsContractOk(!str_contains($refresh, "use Throwable;"), 'refresh no emite warning por importar Throwable global');
httpsContractOk(!str_contains($refresh, 'secret_key'), 'refresh no lee ni imprime clave privada directamente');
httpsContractOk(!str_contains($refresh, 'payload_key'), 'refresh no expone payload key');

fwrite(STDOUT, "Federation HTTPS contract smoke: OK\n");
