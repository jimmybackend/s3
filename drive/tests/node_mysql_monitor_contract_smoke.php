<?php
declare(strict_types=1);

// Static contract for the existing Web OS node monitor. No DB/S3 access needed.
$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/System/NodeRuntimeStatusService.php');
$js = file_get_contents($root . '/js/so-node.js');
$controller = file_get_contents($root . '/src/Http/Controller/NodeStatusController.php');
foreach ([
    'Max_used_connections', 'innodb_buffer_pool_size', 'key_buffer_size',
    'Created_tmp_disk_tables', 'peak_connections_percent', 'max_used_connections',
] as $field) {
    if (!str_contains((string)$service, $field)) {
        throw new RuntimeException('Falta diagnóstico MySQL: ' . $field);
    }
}
foreach (['Conexiones libres ahora', 'Pico desde arranque', 'Memoria procesos MariaDB/MySQL'] as $label) {
    if (!str_contains((string)$js, $label)) {
        throw new RuntimeException('Falta indicador de Mi nodo: ' . $label);
    }
}
if (!str_contains((string)$controller, "if (!$this->app->session()->isSuperAdmin())")
    || !str_contains((string)$controller, '$local = $this->publicSnapshot($local);')) {
    throw new RuntimeException('Las métricas privadas deben permanecer sólo para superadmin.');
}
echo "OK: diagnóstico MySQL del nodo y límites de acceso presentes.\n";
