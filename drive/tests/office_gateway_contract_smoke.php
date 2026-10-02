<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);

function officeGatewayContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$so = (string)file_get_contents($repo . '/drive/so.php');
$launch = (string)file_get_contents($repo . '/drive/office-launch.php');
$tokens = (string)file_get_contents($repo . '/drive/src/Office/OfficeLaunchTokenRepository.php');
$officeSchema = (string)file_get_contents($repo . '/drive/src/Office/OfficeSchemaMigrationService.php');
$gateway = (string)file_get_contents($repo . '/drive/office-gateway.php');
$gatewayService = (string)file_get_contents($repo . '/drive/src/Office/OfficeGatewayService.php');
$leaseRepo = (string)file_get_contents($repo . '/drive/src/Office/OfficeSessionLeaseRepository.php');
$documentRepo = (string)file_get_contents($repo . '/drive/src/Office/OfficeDocumentSessionRepository.php');
$documentStorage = (string)file_get_contents($repo . '/drive/src/Office/OfficeDocumentStorageService.php');
$workstationDocument = (string)file_get_contents($repo . '/drive/workstation-document.php');
$documentController = (string)file_get_contents($repo . '/drive/src/Http/Controller/OfficeDocumentController.php');
$workstationClient = (string)file_get_contents($repo . '/drive/src/Office/OfficeWorkstationClient.php');
$control = (string)file_get_contents($repo . '/drive/workstation-control.php');
$helperClient = (string)file_get_contents($repo . '/drive/src/Admin/PrivilegedServerHelper.php');
$helper = (string)file_get_contents($repo . '/drive/bin/arcadecloud-drive-admin-helper.php');
$officeInstaller = (string)file_get_contents($repo . '/drive/bin/install_office_gateway.sh');
$internalInstaller = (string)file_get_contents($repo . '/drive/bin/install_workstation_internal_gateway.sh');
$node = (string)file_get_contents($repo . '/drive/src/Media/MediaWorkerNodeService.php');

officeGatewayContract(
    str_contains($so, 'href="office-launch.php"')
    && str_contains($so, 'target="_blank"')
    && str_contains($so, '<strong>Office</strong><span>Disponible</span>'),
    'ArcadeCloud OS abre Office disponible en una pestaña nueva'
);

officeGatewayContract(
    str_contains($launch, "requireAuthenticated('index.php')")
    && str_contains($launch, 'OfficeLaunchTokenRepository')
    && str_contains($launch, 'OfficeSchemaMigrationService')
    && str_contains($launch, '[Office launch] schema/token repository error:')
    && str_contains($launch, '[Office launch] token issue error:')
    && str_contains($launch, 'launch='),
    'launcher exige sesión Drive, controla fallos y emite token temporal'
);

officeGatewayContract(
    str_contains($officeSchema, 'OfficeLaunchTokens')
    && str_contains($officeSchema, 'OfficeSessionLeases')
    && str_contains($officeSchema, 'OfficeDocumentSessions')
    && str_contains($officeSchema, 'ARCADECLOUD:OFFICE_SCHEMA:BEGIN'),
    'launcher y updater disponen de migración canónica para las tres tablas Office'
);

officeGatewayContract(
    str_contains($tokens, 'random_bytes(32)')
    && str_contains($tokens, "hash('sha256', \$token)")
    && str_contains($tokens, 'ConsumedAt IS NULL')
    && str_contains($tokens, 'LIMIT 1 FOR UPDATE'),
    'token Office es aleatorio, hash-only y de un solo uso'
);

officeGatewayContract(
    str_contains($gateway, "header('Location: /guacamole/#/', true, 302)")
    && str_contains($gateway, "\$officeDesktopTarget === 'guacamole'")
    && str_contains($gateway, "\$officeFileId === 0"),
    'launcher Guacamole redirige al frontend nativo /guacamole/#/ cuando la sesión ya está lista'
);

officeGatewayContract(
    str_contains($gatewayService, 'FastDriveWakeService')
    && str_contains($gatewayService, 'authorizeAndStart')
    && str_contains($gateway, 'current_password'),
    'gateway Office reutiliza autorización superadmin para encender la EC2'
);

officeGatewayContract(
    str_contains($gatewayService, 'MediaWorkerNodeService')
    && str_contains($gatewayService, 'touchInteractiveActivity')
    && str_contains($gatewayService, 'handleIdle')
    && str_contains($gatewayService, 'MediaProcessingJobRepository'),
    'Office reutiliza actividad e inactividad segura del nodo de cómputo'
);

officeGatewayContract(
    str_contains($gateway, "['pointerdown','pointermove','keydown','touchstart','wheel']")
    && str_contains($gateway, '/__office_activity')
    && str_contains($gateway, '/__office_idle')
    && str_contains($gateway, 'Han pasado 10 minutos sin actividad'),
    'pestaña Office detecta interacción real y muestra aviso de inactividad'
);

officeGatewayContract(
    str_contains($gatewayService, 'claimOfficeSession')
    && str_contains($gatewayService, 'assertOfficeSession')
    && str_contains($gatewayService, 'touchOfficeSession')
    && !str_contains($gatewayService, 'MediaWorkerNodeSessionRepository')
    && str_contains($gateway, "mode === 'office-busy'"),
    'MAX_OFFICE_SESSIONS=1 usa lease Office dedicado y no una sesión multimedia'
);

officeGatewayContract(
    str_contains($leaseRepo, 'OfficeSessionLeases')
    && str_contains($leaseRepo, "hash('sha256', \$sessionKey)")
    && str_contains($leaseRepo, 'GET_LOCK')
    && str_contains($leaseRepo, 'ExpiresAt>UTC_TIMESTAMP()')
    && !str_contains($leaseRepo, 'SessionKey VARCHAR'),
    'lease Office guarda sólo hash, serializa reclamos y expira por inactividad'
);

officeGatewayContract(
    str_contains($gateway, "\$action === 'auth'")
    && str_contains($gateway, 'assertOfficeSession')
    && str_contains($gateway, "office_session_key")
    && str_contains($gateway, "office_instance_id")
    && str_contains($officeInstaller, 'location = /__office_auth')
    && str_contains($officeInstaller, 'internal;')
    && str_contains($officeInstaller, 'auth_request /__office_auth;')
    && substr_count($officeInstaller, 'fastcgi_param HTTP_COOKIE \\$http_cookie;') >= 5,
    'noVNC y WebSocket requieren lease Office del mismo navegador y cookie FastCGI'
);

officeGatewayContract(
    str_contains($so, 'data-office-url=')
    && str_contains($so, 'office-launch.php?file_id=')
    && str_contains($so, 'Abrir con Office'),
    'Mis datos enlaza archivos Office por FileS3.id_'
);

officeGatewayContract(
    str_contains($tokens, 'issueForFile')
    && str_contains($tokens, "return \$this->issue(\$userId, \$ttlSeconds) . '.' . \$fileId;")
    && str_contains($tokens, "SELECT UserId FROM OfficeLaunchTokens")
    && !str_contains($tokens, 'ALTER TABLE OfficeLaunchTokens')
    && !str_contains($tokens, '(TokenHash,UserId,FileId,CreatedAt,ExpiresAt)'),
    'launcher evita ALTER en runtime y transporta file_id en contexto revalidado'
);

officeGatewayContract(
    str_contains($documentRepo, 'OfficeDocumentSessions')
    && str_contains($documentRepo, 'ControlTokenHash')
    && str_contains($documentRepo, "hash('sha256', \$controlToken)")
    && !str_contains($documentRepo, 'ControlToken VARCHAR'),
    'sesión documental guarda hash del token interno'
);

officeGatewayContract(
    str_contains($helperClient, '[^\\/\\x00-\\x1F\\x7F]{1,220}')
    && !str_contains($helperClient, '[A-Za-z0-9._ ()\\[\\]-]{1,220}'),
    'cliente del helper permite nombres Unicode seguros en documentos Workstation'
);

officeGatewayContract(
    str_contains($officeInstaller, 'chgrp "$PHP_GROUP" "$STATE_ROOT"')
    && str_contains($officeInstaller, 'chmod 0750 "$STATE_ROOT"'),
    'instalador permite al grupo PHP-FPM atravesar el directorio padre de Office'
);

officeGatewayContract(
    str_contains($documentStorage, 'headObject')
    && str_contains($documentStorage, 'getObject')
    && str_contains($documentStorage, 'putObject')
    && str_contains($documentStorage, 'expected_etag')
    && str_contains($documentStorage, 'saveConflict')
    && str_contains($documentStorage, 'duplicateFrom')
    && str_contains($documentStorage, 'adoptConflict')
    && str_contains($documentRepo, 'adoptConflict'),
    'documento Office baja de S3, valida ETag y continúa sobre una copia si hay conflicto'
);

officeGatewayContract(
    str_contains($documentStorage, 'isMissingS3Object')
    && str_contains($documentStorage, 'Sincroniza desde S3 la carpeta donde está el archivo')
    && str_contains($documentRepo, 'markFailed')
    && str_contains($documentRepo, "Status='failed'"),
    'preparación Office marca failed y explica cuando FileS3 apunta a una key S3 inexistente'
);

officeGatewayContract(
    str_contains($workstationDocument, 'OfficeDocumentController')
    && str_contains($documentController, "['prepare', 'sync', 'close']")
    && str_contains($documentController, 'OfficeDocumentStorageService')
    && str_contains($internalInstaller, '/__arcadecloud_office_document')
    && str_contains($workstationClient, '/__arcadecloud_office_document'),
    'agente documental usa Controller OOP y sólo opera prepare/sync/close por red privada'
);

officeGatewayContract(
    str_contains($gateway, 'Ya hay un documento abierto en esta sesión Office')
    && str_contains($gateway, 'existingDocumentFileId')
    && str_contains($gateway, 'existingDocumentSessionId'),
    'una sesión Office no mezcla dos documentos distintos entre pestañas'
);

officeGatewayContract(
    str_contains($gateway, 'office_document_ready')
    && str_contains($gateway, "mode = 'document-error'")
    && str_contains($gateway, 'Documento no preparado')
    && str_contains($gateway, 'Sincronizar desde S3'),
    'gateway no muestra Writer listo cuando la preparación documental falló'
);

officeGatewayContract(
    str_contains($gateway, '/__office_document_sync')
    && str_contains($gateway, '/__office_document_close')
    && str_contains($gateway, 'setInterval(syncDocument, 60000)')
    && str_contains($gateway, "navigator.sendBeacon('/__office_document_close'"),
    'pestaña Office sincroniza periódicamente y al cerrar'
);

officeGatewayContract(
    str_contains($gatewayService, 'hasActiveJobs()')
    && str_contains($gateway, 'Hay una tarea multimedia activa'),
    'Office espera si FFmpeg/multimedia ya está trabajando'
);

officeGatewayContract(
    str_contains($workstationClient, 'stream_socket_client')
    && str_contains($workstationClient, 'HTTP/1.0')
    && str_contains($workstationClient, '/__arcadecloud_workstation')
    && str_contains($workstationClient, "['status', 'start']"),
    'cliente Workstation sólo llama al endpoint privado fijo'
);

officeGatewayContract(
    str_contains($control, "ARCADECLOUD_WORKSTATION_GATE")
    && str_contains($control, "['status', 'start']")
    && !str_contains($control, "['status', 'start', 'stop']"),
    'endpoint interno sólo permite estado e inicio de Workstation'
);

officeGatewayContract(
    str_contains($helperClient, 'supportsWorkstationControl')
    && str_contains($helperClient, "workstationControl('workstation-start')"),
    'cliente del helper expone arranque Workstation allowlisted'
);

officeGatewayContract(
    str_contains($helper, "'version' => 16")
    && str_contains($helper, "'workstation_control' => true")
    && str_contains($helper, "'workstation_document_open' => true")
    && str_contains($helper, "if (\$action === 'workstation-open-document')")
    && str_contains($helper, "['workstation-status', 'workstation-start']")
    && !str_contains($helper, "if (\$action === 'workstation-stop')"),
    'helper privilegiado abre documentos allowlisted y no expone una acción directa workstation-stop'
);

officeGatewayContract(
    str_contains($officeInstaller, 'UPSTREAM="172.31.14.35"')
    && str_contains($officeInstaller, 'office.esforzados.com')
    && str_contains($officeInstaller, 'error_page 502 504 = @office_gate')
    && str_contains($officeInstaller, '/__office_activity'),
    'gateway público usa EC2 pequeña y upstream privado con fallback de encendido'
);

officeGatewayContract(
    str_contains($internalInstaller, 'GATEWAY_IP="172.31.83.240"')
    && str_contains($internalInstaller, 'allow ${GATEWAY_IP};')
    && str_contains($internalInstaller, 'proxy_pass http://127.0.0.1:6080')
    && str_contains($internalInstaller, '/__arcadecloud_workstation'),
    'gateway grande sólo acepta control plane privado y conserva noVNC en loopback'
);

officeGatewayContract(
    str_contains($node, 'max(')
    && str_contains($node, '600')
    && str_contains($node, 'ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS')
    && str_contains($node, 'hasActiveJobs()'),
    'autoapagado existente conserva mínimo de 10 minutos y protege trabajos multimedia'
);

officeGatewayContract(
    str_contains($node, "getenv('ARCADECLOUD_FASTDRIVE_INSTANCE_ID')")
    && str_contains($node, "getenv('ARCADECLOUD_FASTDRIVE_REGION')")
    && str_contains($node, "!in_array(\$role, ['media-worker', 'combined'], true)"),
    'gateway Office reutiliza el target FastDrive administrado para tocar el mismo contador de inactividad'
);

fwrite(STDOUT, "Office gateway contract: OK\n");
