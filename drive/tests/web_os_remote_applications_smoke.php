<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/so.php');
$shell = (string) file_get_contents($root . '/js/desktop-shell.js');
$manager = (string) file_get_contents($root . '/js/os-window-manager.js');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
};

$appsStart = strpos($page, '<section class="os-window" id="appsWindow"');
$appsEnd = $appsStart === false ? false : strpos($page, '<section class="os-window os-search-window"', $appsStart);
$linksStart = strpos($page, '<section class="os-window os-links-window"');
$linksEnd = $linksStart === false ? false : strpos($page, '</main>', $linksStart);
$apps = ($appsStart !== false && $appsEnd !== false) ? substr($page, $appsStart, $appsEnd - $appsStart) : '';
$links = ($linksStart !== false && $linksEnd !== false) ? substr($page, $linksStart, $linksEnd - $linksStart) : '';

$assert($apps !== '' && $links !== '', 'las superficies Aplicaciones y Enlaces se pueden auditar por separado');
$remoteApps = [
    'office' => ['Office', 'office-launch.php'],
    'linux-xfce' => ['Linux XFCE', 'office-launch.php'],
    'guacamole' => ['Guacamole', 'office-launch.php?target=guacamole'],
    'kde-plasma' => ['KDE Plasma', 'office-launch.php?target=kde'],
];
foreach ($remoteApps as $id => [$label, $href]) {
    $assert(str_contains($apps, 'data-launcher-app="' . $id . '"'), "{$label} está declarado para el Application Registry");
    $assert(str_contains($apps, 'href="' . $href . '"'), "{$label} conserva su destino real");
    $assert(!str_contains($links, '<strong>' . $label . '</strong>'), "{$label} ya no aparece en Enlaces");
}
$assert(str_contains($apps, '<strong>Consola servidor</strong>'), 'Consola servidor está en Aplicaciones');
$assert(str_contains($apps, '<?php if ($isSuperAdmin): ?>'), 'Consola servidor conserva el guard de render superadmin');
$assert(!str_contains($links, 'Consola servidor') && !str_contains($links, 'Consola del servidor'), 'Consola servidor ya no aparece en Enlaces');
$assert(str_contains($links, '<strong>Actividad y costos</strong>') && str_contains($links, '<strong>AWS y códigos TOTP</strong>') && str_contains($links, '<strong>Gestión EC2</strong>') && str_contains($links, '<strong>FederationCloud</strong>'), 'Enlaces conserva todos los accesos no movidos');

$assert(str_contains($shell, "querySelectorAll('[data-launcher-app]')"), 'DesktopShell incorpora las aplicaciones declarativas al registro oficial');
$assert(str_contains($shell, "lifecycle: 'external', launchable: true"), 'las herramientas remotas se registran como aplicaciones externas lanzables');
$assert(str_contains($shell, 'launch: () => this.window.open(href, target'), 'el acceso remoto sólo ocurre al ejecutar explícitamente la aplicación');
$assert(strpos($shell, 'this.registerDeclarativeApplications(appsWindow);') < strpos($shell, "body.innerHTML = '<label class=\"os-app-search\""), 'el registro captura los destinos reales antes de renderizar el launcher');
$assert(str_contains($manager, "['terminal',false,'persistent','Consola servidor','fa-terminal']"), 'Consola servidor conserva semántica singleton persistente');
$assert(!str_contains($manager, "'office-launch.php'") && !str_contains($manager, "'target=guacamole'") && !str_contains($manager, "'kde.esforzados.com'"), 'WindowManager y restore no contienen acciones remotas autoejecutables');
$assert(str_contains($page, 'id="terminalWindow"') && str_contains($page, 'server-console.php'), 'ventana y endpoint existentes de Consola servidor permanecen intactos');

fwrite(STDOUT, "Web OS remote applications smoke passed.\n");
