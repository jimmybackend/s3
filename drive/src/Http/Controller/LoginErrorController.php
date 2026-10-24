<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Security\LoginPageGuard;

require_once dirname(__DIR__, 2) . '/Security/LoginPageGuard.php';

final class LoginErrorController
{
    public function render(): void
    {
        try {
            $status = LoginPageGuard::context();
        } catch (\Throwable $error) {
            LoginPageGuard::unavailable($error);
        }
        $blocked = $status['blocked'];
        $retry = (int)$status['retry_after'];
        $remaining = (int)$status['remaining_attempts'];
        $hasError = !empty($_SESSION['login_credentials_error']);
        http_response_code($blocked ? 429 : ($hasError ? 401 : 200));
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: same-origin');
        if ($blocked) header('Retry-After: ' . $retry);
        $title = $blocked ? 'Acceso bloqueado temporalmente' : ($hasError ? 'No pudimos iniciar tu sesión' : 'Acceso a la plataforma');
        $message = $blocked
            ? 'Alcanzaste el límite de intentos fallidos. Por seguridad, debes esperar antes de volver a iniciar sesión, incluso si introduces la contraseña correcta.'
            : ($hasError ? 'El correo o la contraseña no son correctos, o la cuenta no está habilitada. Revisa tus datos antes de intentarlo de nuevo.' : 'Inicia sesión con tu cuenta autorizada.');
        $wait = sprintf('%02d:%02d', intdiv($retry, 60), $retry % 60);
        $action = $blocked
            ? '<div class="waiting">El acceso al inicio se habilitará cuando termine la espera.</div><noscript><p>JavaScript está desactivado. Actualiza esta página al terminar el tiempo para comprobar el acceso.</p></noscript>'
            : '<a class="button" href="login.php">Volver a intentar</a><a class="secondary" href="index.php">Ir al inicio</a>';
        $summary = $blocked
            ? '<p>Tiempo restante</p><strong id="login-countdown" role="timer">' . $wait . '</strong><p>El bloqueo se comprueba en el servidor; recargar la página no lo elimina ni reinicia la espera.</p>'
            : '<p>Intentos disponibles para esta cuenta y dirección IP</p><strong>' . $remaining . ' de 5</strong><p>El límite por dirección IP también puede reducir los intentos disponibles.</p>';
        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{$title} · Esforzados</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: #07111c; color: #e8eef6; font: 16px/1.6 system-ui, sans-serif; }
    main { width: 100%; max-width: 620px; background: #102133; border: 1px solid #35516c; border-radius: 20px; padding: clamp(24px, 5vw, 40px); box-shadow: 0 24px 70px #0006; }
    .brand { color: #76f09b; font-size: 14px; letter-spacing: .06em; margin: 0 0 20px; }
    h1 { font-size: clamp(24px, 5vw, 32px); line-height: 1.25; margin: 0 0 16px; }
    p { margin: 0 0 16px; }
    .status { margin: 24px 0; padding: 20px; border: 1px solid #6685a3; border-radius: 12px; background: #081827; text-align: center; }
    .status strong { display: block; font-size: 36px; color: #a3efbb; font-variant-numeric: tabular-nums; margin-bottom: 12px; }
    .status p:last-child { margin: 0; font-size: 14px; color: #d4deea; }
    ul { padding-left: 22px; font-size: 14px; color: #d4deea; }
    li { margin: 8px 0; }
    a { color: #a3efbb; text-underline-offset: 3px; }
    .button { display: block; padding: 12px 20px; border-radius: 9px; background: #7ce99e; color: #07111c; font-weight: 700; text-align: center; text-decoration: none; }
    .button:focus-visible, a:focus-visible { outline: 3px solid white; outline-offset: 4px; }
    .secondary { display: block; text-align: center; margin-top: 16px; }
    .waiting { text-align: center; padding: 14px; border: 1px solid #6685a3; border-radius: 9px; }
    footer { margin-top: 24px; font-size: 14px; text-align: center; color: #d4deea; overflow-wrap: anywhere; }
  </style>
  <script src="js/login-cooldown.js" defer></script>
</head>
<body>
<main data-login-retry-seconds="{$retry}">
  <p class="brand">ARCADECLOUD DRIVE · ESFORZADOS</p>
  <h1>{$title}</h1>
  <p>{$message}</p>
  <section class="status" aria-label="Estado del acceso">{$summary}</section>
  <ul>
    <li>5 fallos para la misma cuenta y dirección IP dentro de una ventana de 15 minutos: bloqueo de 15 minutos desde el quinto fallo.</li>
    <li>30 fallos acumulados por dirección IP dentro de una ventana de 15 minutos: bloqueo de todos los accesos nuevos desde esa dirección durante 15 minutos.</li>
    <li>Mientras exista un bloqueo, este navegador permanecerá en esta página al intentar volver al inicio. No se elimina tu cuenta ni tus archivos.</li>
  </ul>
  {$action}
  <footer>¿Necesitas ayuda? <a href="mailto:soporte@esforzados.com">soporte@esforzados.com</a></footer>
</main>
</body>
</html>
HTML;
    }
}
