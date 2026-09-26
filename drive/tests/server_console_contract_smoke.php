<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$files = [
    'ec2' => $root . '/ec2.php',
    'endpoint' => $root . '/server-console.php',
    'controller' => $root . '/src/Http/Controller/ServerConsoleController.php',
    'service' => $root . '/src/Admin/ServerConsoleService.php',
    'helper_client' => $root . '/src/Admin/PrivilegedServerHelper.php',
    'helper' => $root . '/bin/arcadecloud-drive-admin-helper.php',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Falta archivo de contrato: {$name}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$requiredCommands = [
    'help',
    'clear',
    'pwd',
    'ls -lah',
    'git status',
    'git log -10 --oneline',
    'du -sh .',
    'uname -a',
    'free -h',
    'df -h',
    'uptime',
    'ps aux --sort=-%mem',
    'systemctl status nginx',
    'systemctl status php-fpm-drive',
    'arcadecloud services',
    'arcadecloud timers',
    'logs drive',
    'logs nginx',
    'logs federation',
    'logs polly',
    'logs transcribe',
    'logs drop',
    'memory-clear',
];

foreach ($requiredCommands as $command) {
    if (!str_contains($files['service'], "'{$command}'")) {
        fwrite(STDERR, "Falta comando permitido: {$command}\n");
        exit(1);
    }
}

$contracts = [
    [$files['controller'], 'isSuperAdmin()', 'control superadmin'],
    [$files['controller'], "session->get('csrf'", 'CSRF'],
    [$files['service'], 'password_verify', 'revalidación memory-clear'],
    [$files['service'], "if (\$command === 'help')", 'help local'],
    [$files['service'], "'client_action' => 'clear'", 'clear local'],
    [$files['helper_client'], "version'] ?? 0) >= 10", 'helper v10'],
    [$files['helper_client'], "run(['server-console', \$commandId])", 'ID interno helper'],
    [$files['helper'], "'version' => 10", 'helper versión 10'],
    [$files['helper'], "'repo-pwd'", 'pwd local'],
    [$files['helper'], "'repo-status'", 'git status local'],
    [$files['helper'], "'arcadecloud-services'", 'servicios locales'],
    [$files['helper'], "'arcadecloud-timers'", 'timers locales'],
    [$files['helper'], "'logs-federation'", 'logs federation'],
    [$files['helper'], "'memory-clear'", 'drop caches allowlisted'],
    [$files['helper'], "safe.directory=", 'git safe directory local'],
    [$files['helper'], "['bypass_shell' => true]", 'bypass shell'],
    [$files['helper'], '/proc/sys/vm/drop_caches', 'drop_caches'],
    [$files['helper'], '/var/log/php-fpm-drive/error.log', 'log php-fpm local'],
    [$files['helper'], '/var/log/nginx/error.log', 'log nginx local'],
    [$files['ec2'], '$isServerConsoleSuperAdmin', 'visibilidad superadmin'],
    [$files['ec2'], 'data-console-command="clear"', 'botón clear'],
    [$files['ec2'], 'data-console-command="git status"', 'botón git status'],
    [$files['ec2'], 'data-console-command="arcadecloud timers"', 'botón timers'],
    [$files['ec2'], 'data-console-command="logs federation"', 'botón logs federation'],
    [$files['endpoint'], 'personal_aws_bootstrap.php', 'bootstrap sin DB'],
];

foreach ($contracts as [$haystack, $needle, $label]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "Contrato ausente: {$label}\n");
        exit(1);
    }
}

if (
    str_contains($files['helper'], '$argv[2] ??')
    && !str_contains($files['helper'], 'runServerConsoleCommand($commandId, $appRoot)')
) {
    fwrite(STDERR, "El comando web no está encapsulado por la lista blanca local.\n");
    exit(1);
}

foreach (['shell_exec(', 'exec(', 'system(', 'passthru(', 'popen('] as $forbidden) {
    if (str_contains($files['helper'], $forbidden)) {
        fwrite(STDERR, "Helper contiene ejecución de shell prohibida: {$forbidden}\n");
        exit(1);
    }
}

echo "SERVER_CONSOLE_CONTRACT_OK\n";
