<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'endpoint' => (string)file_get_contents($root . '/src/Http/Controller/NodeStatusController.php'),
    'service' => (string)file_get_contents($root . '/src/System/NodeRuntimeStatusService.php'),
    'js' => (string)file_get_contents($root . '/js/so-node.js'),
    'view' => (string)file_get_contents($root . '/so.php'),
    'css' => (string)file_get_contents($root . '/css/so.css'),
];

function nodeContract(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "OK: {$message}\n");
}

nodeContract(str_contains($files['endpoint'], "'node' => \$local"), 'node-status conserva el payload node compatible');
nodeContract(str_contains($files['endpoint'], "'scope' => 'local'") && str_contains($files['endpoint'], "'local' => \$local") && !str_contains($files['endpoint'], "'fastdrive' =>"), 'node-status entrega únicamente el runtime local');
nodeContract(str_contains($files['service'], 'SERVICE_WHITELIST') && !str_contains($files['service'], "queryString('service"), 'systemd usa whitelist fija y no acepta unidades del request');
nodeContract(str_contains($files['service'], "['systemctl', 'show', \$unit"), 'systemctl se ejecuta como argv sin shell');
nodeContract(!preg_match('/AWS_SECRET_ACCESS_KEY|AWS_ACCESS_KEY_ID|DB_PASSWORD/', substr($files['service'], strpos($files['service'], "return array_merge(\$this->legacy"))), 'respuesta no construye campos secretos');
nodeContract(str_contains($files['service'], "SHOW GLOBAL STATUS") && str_contains($files['service'], 'advanced_available'), 'MySQL avanzado degrada con permisos actuales');
nodeContract(str_contains($files['service'], 'MemAvailable') || str_contains((string)file_get_contents($root . '/src/System/NodeCapabilityService.php'), 'MemAvailable'), 'RAM disponible usa MemAvailable');
nodeContract(str_contains($files['service'], "'online_available' => false"), 'sesiones no inventan presencia en línea');
nodeContract(!str_contains($files['service'], 'OfficeSessionLeases') && !str_contains($files['service'], 'MediaWorkerNodeSessions'), 'sesiones web no reutilizan sesiones Office o Media Worker');
nodeContract(!str_contains($files['service'], 'FROM FederationNodes') && str_contains($files['service'], "'scope' => 'local'"), 'Mi nodo no mezcla inventario de peers FederationCloud');
nodeContract(str_contains($files['service'], 'localIdentity') && str_contains($files['service'], 'NodeIdentityService'), 'identidad visible se resuelve desde la identidad local confiable');
nodeContract(str_contains($files['service'], 'NextElapseUSecRealtime') && str_contains($files['service'], 'LastTriggerUSec'), 'timers consultan próxima y última ejecución reales de systemd');
nodeContract(str_contains($files['js'], 'acumulado desde arranque') && str_contains($files['js'], 'desde último refresh'), 'MySQL etiqueta acumulados y calcula deltas en frontend');
nodeContract(str_contains($files['js'], "button.disabled = (action === 'start'") && str_contains($files['js'], "action === 'disable'"), 'botones de servicio reflejan el estado actual');
nodeContract(str_contains($files['js'], 'Estado del servicio Nginx') && str_contains($files['js'], 'Validación de configuración'), 'Nginx separa servicio y validación');
$completeSections = ['Servicios ArcadeCloud', 'Mayor consumo actual', 'PHP / Nginx', 'MySQL / Base de datos', 'Usuarios / Sesiones', 'FederationCloud', 'Actividad', 'Programas / Capacidades', 'Red / EC2', 'Autoapagado'];
nodeContract(array_reduce($completeSections, static fn(bool $found, string $section): bool => $found && str_contains($files['js'], $section), true), 'superadmin conserva simultáneamente todas las secciones del diagnóstico completo');
nodeContract(str_contains($files['js'], 'data-node-memory-clear') && str_contains($files['js'], 'data-node-disk-clean') && str_contains($files['js'], 'this.renderLocal(root, node)'), 'diagnóstico moderno integra ambas escobillas');
nodeContract(str_contains($files['view'], 'if (!$isSuperAdmin)') && !str_contains($files['view'], 'class="os-node-broom"'), 'superadmin no recibe panel legacy duplicado y usuario normal no recibe escobillas');
nodeContract(str_contains($files['js'], "data?.scope !== 'local'") && str_contains($files['js'], 'this.renderSelected()'), 'frontend rechaza respuestas que no declaren alcance local');
nodeContract(str_contains($files['js'], 'setInterval(() => { if (this.isOpen()) this.refresh();') && str_contains($files['js'], '30000'), 'polling ocurre cada 30 segundos sólo con Mi nodo abierto');
nodeContract(str_contains($files['js'], 'this.stopPolling()') && str_contains($files['js'], '#nodeWindow [data-window-close]'), 'cerrar Mi nodo detiene polling');
nodeContract(substr_count($files['js'], 'this.refresh();') < 10 && str_contains($files['js'], 'this.idleRemaining = Math.max(0, this.idleRemaining - 1)'), 'countdown local no consulta cada segundo');
nodeContract(!str_contains($files['service'], 'public function fastDrive()') && !str_contains($files['js'], 'renderFastDrive'), 'Mi nodo no contiene una ruta de diagnóstico remoto FastDrive');
nodeContract(!str_contains($files['view'], 'data-node-tab=') && !str_contains($files['js'], 'data-node-tab'), 'vista no permite seleccionar otro nodo desde Mi nodo');
nodeContract(str_contains($files['js'], 'Identidad local') && str_contains($files['js'], 'Servicios ArcadeCloud') && str_contains($files['js'], 'Programas / Capacidades'), 'panel muestra identidad, servicios y programas del servidor local');
nodeContract(
    str_contains($files['service'], "'operating_label' => \$operatingLabel")
    && str_contains($files['service'], "'fastdrive'")
    && str_contains($files['service'], "'Drive principal'")
    && str_contains($files['js'], 'Servidor que estás operando')
    && str_contains($files['js'], 'renderOperatingNode')
    && str_contains($files['view'], 'data-node-local-label'),
    'Mi nodo identifica visualmente si el servidor local operado es Drive principal o FastDrive'
);
nodeContract(str_contains($files['css'], '@media(max-width:800px)') && str_contains($files['css'], 'grid-template-columns:1fr'), 'panel se adapta a una columna móvil');
nodeContract(str_contains($files['css'], '--os-surface:') && str_contains($files['css'], 'background:var(--os-surface)'), 'tarjetas y ventanas usan superficies temáticas');
nodeContract(str_contains($files['css'], '.status-ok') && str_contains($files['css'], '.status-neutral'), 'estados visuales compartidos funcionan con tokens');

fwrite(STDOUT, "Node diagnostics contract smoke passed.\n");
