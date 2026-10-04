<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string)file_get_contents($root . '/css/so.css');
$appearance = (string)file_get_contents($root . '/js/so-appearance.js');
$view = (string)file_get_contents($root . '/so.php');
$preferences = (string)file_get_contents($root . '/src/Security/UserOsPreferencesRepository.php');
$nodeResolver = (string)file_get_contents($root . '/src/Security/OsPreferenceNodeResolver.php');

function themeContract(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "OK: {$message}\n");
}

foreach (['--os-bg:', '--os-surface:', '--os-surface-2:', '--os-border:', '--os-text:', '--os-soft:', '--os-accent:', '--os-ok:', '--os-warning:', '--os-danger:', '--os-button-bg:', '--os-button-text:', '--os-input-bg:', '--os-shadow:'] as $token) {
    themeContract(str_contains($css, $token), "existe el token de tema {$token}");
}
themeContract(str_contains($css, 'body.arcade-os.os-theme-light{'), 'el tema claro redefine los tokens compartidos');
themeContract(str_contains($css, '.os-node-card{') && str_contains($css, 'background:var(--os-surface)'), 'Mi nodo consume la superficie compartida');
themeContract(str_contains($css, '.arcade-os .modal-content') && str_contains($css, 'background:var(--os-surface)!important'), 'los modales consumen la superficie compartida');
themeContract(str_contains($css, '.arcade-os input') && str_contains($css, 'background:var(--os-input-bg)'), 'inputs y selects consumen el fondo temático');
themeContract(str_contains($css, ':focus-visible') && str_contains($css, '--os-focus:'), 'los controles mantienen foco visible');
themeContract(str_contains($appearance, "classList.toggle('os-theme-light'") && str_contains($appearance, "classList.toggle('os-theme-dark'"), 'claro y oscuro se aplican desde Appearance');
themeContract(str_contains($appearance, 'this.remote.preferences') && str_contains($view, 'ARCADECLOUD_OS_APPEARANCE'), 'Appearance recibe las preferencias remotas existentes');
themeContract(str_contains($preferences, 'os_preferences'), 'Users.os_preferences sigue siendo la persistencia remota');
themeContract(str_contains($preferences, "'nodes' => []") && str_contains($preferences, "'default' => \$stored"), 'preferencias remotas conservan compatibilidad y se separan por nodo');
themeContract(str_contains($nodeResolver, 'NodeIdentityService') && str_contains($nodeResolver, "return 'host:'"), 'clave de preferencias usa node_id y tiene fallback por host');
themeContract(str_contains($appearance, 'arcadecloud-os-appearance-v2:') && str_contains($appearance, 'this.remote.nodeKey'), 'localStorage también queda aislado por nodo');

fwrite(STDOUT, "Web OS theme contract smoke passed.\n");
