<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class PersonalAwsPageRenderer
{
    public function locked(bool $configured, string $error = ''): never
    {
        $message = $configured
            ? 'Acceso privado'
            : 'La clave privada todavía no está configurada en el servidor.';

        echo $this->head('Acceso privado');
        echo '<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on personal-tool-page">';
        echo $this->navbar('Herramienta privada');
        echo '<main class="personal-tool-shell"><section class="personal-card">'
            . '<h1>' . self::e($message) . '</h1>';

        if ($error !== '') {
            echo '<p class="error">' . self::e($error) . '</p>';
        }

        if ($configured) {
            echo '<form method="post" autocomplete="off">'
                . '<input type="hidden" name="action" value="unlock">'
                . '<label>Contraseña</label>'
                . '<input type="password" name="access_password" required autofocus autocomplete="current-password">'
                . '<button type="submit">Entrar</button>'
                . '</form>';
        }

        echo '<p class="mt-3 mb-0"><a href="s3.php">Volver al Drive</a></p>'
            . '</section></main></body></html>';
        exit;
    }

    public function forbidden(): never
    {
        http_response_code(403);
        echo $this->head('Acceso restringido');
        echo '<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on personal-tool-page">';
        echo $this->navbar('Herramienta privada');
        echo '<main class="personal-tool-shell"><section class="personal-card">'
            . '<h1>Acceso restringido</h1>'
            . '<p>Esta herramienta personal no está disponible para esta cuenta.</p>'
            . '<p class="mb-0"><a href="s3.php">Volver al Drive</a></p>'
            . '</section></main></body></html>';
        exit;
    }

    /**
     * @param array<int,array{id:string,label:string}> $accounts
     * @param array{account_id:string,label:string,code:string,remaining:int,note:string}|null $result
     */
    public function tool(array $accounts, ?array $result = null, string $error = ''): never
    {
        echo $this->head('Herramienta AWS personal');
        echo '<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on personal-tool-page">';
        echo $this->navbar('AWS personal');
        echo '<main class="personal-tool-shell"><section class="personal-card wide">'
            . '<nav class="mb-3">'
            . '<a href="https://aws.amazon.com/es/console/" target="_blank" rel="noopener noreferrer">AWS</a>'
            . ' · <a href="https://console.aws.amazon.com/console/home" target="_blank" rel="noopener noreferrer">Consola</a>'
            . ' · <a href="ec2.php">EC2 / RDS</a>'
            . ' · <a href="s3.php">Drive</a>'
            . '</nav>'
            . '<h1>Generador TOTP personal</h1>'
            . '<p class="muted">Las semillas permanecen en el servidor y no se envían al navegador.</p>';

        if ($error !== '') {
            echo '<p class="error">' . self::e($error) . '</p>';
        }

        if ($result !== null) {
            echo '<section class="result"><div class="muted">' . self::e($result['label']) . '</div>'
                . '<div class="otp" id="otp-code" role="button" tabindex="0" aria-label="Copiar código TOTP">' . self::e($result['code']) . '</div>'
                . '<div class="copy-hint" id="copy-status" aria-live="polite">Toca el código para copiar</div>'
                . '<div>Válido aproximadamente <span id="timer">' . (int)$result['remaining'] . '</span> s</div>';
            if ($result['note'] !== '') {
                echo '<div class="note">' . nl2br(self::e($result['note'])) . '</div>';
            }
            echo '</section>';
        }

        if ($accounts === []) {
            echo '<p class="error">No hay cuentas TOTP configuradas en el archivo privado del servidor.</p>';
        } else {
            echo '<form method="post" autocomplete="off">'
                . '<input type="hidden" name="action" value="generate">'
                . '<label for="account_id">Cuenta</label>'
                . '<select id="account_id" name="account_id" required>'
                . '<option value="" disabled selected>Selecciona una cuenta</option>';
            foreach ($accounts as $account) {
                echo '<option value="' . self::e($account['id']) . '">' . self::e($account['label']) . '</option>';
            }
            echo '</select><button type="submit">Generar código</button></form>';
        }

        echo '</section></main>';
        if ($result !== null) {
            echo '<script>(function(){'
                . 'let n=' . (int)$result['remaining'] . ';'
                . 'const timer=document.getElementById("timer");'
                . 'const code=document.getElementById("otp-code");'
                . 'const status=document.getElementById("copy-status");'
                . 'const id=setInterval(function(){n--;if(timer){timer.textContent=Math.max(0,n);}if(n<=0){clearInterval(id);}},1000);'
                . 'async function copyCode(){if(!code){return;}const value=(code.textContent||"").trim();if(!value){return;}'
                . 'let copied=false;'
                . 'try{if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(value);copied=true;}}catch(e){}'
                . 'if(!copied){const area=document.createElement("textarea");area.value=value;area.setAttribute("readonly","");area.style.position="fixed";area.style.opacity="0";document.body.appendChild(area);area.select();try{copied=document.execCommand("copy");}catch(e){copied=false;}document.body.removeChild(area);}'
                . 'if(status){const original="Toca el código para copiar";status.textContent=copied?"✓ Copiado":"No se pudo copiar";status.classList.toggle("copied",copied);window.setTimeout(function(){status.textContent=original;status.classList.remove("copied");},1400);}'
                . '}'
                . 'if(code){code.addEventListener("click",copyCode);code.addEventListener("keydown",function(e){if(e.key==="Enter"||e.key===" "){e.preventDefault();copyCode();}});}'
                . '})();</script>';
        }
        echo '</body></html>';
        exit;
    }

    private function head(string $title): string
    {
        $stylesVersion = $this->assetVersion('css/styles.css');
        $responsiveVersion = $this->assetVersion('css/responsive.css');
        $toolVersion = $this->assetVersion('css/personal-tools.css');
        $themeBridgeVersion = $this->assetVersion('js/theme-state-bridge.js');

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>' . self::e($title) . ' · ArcadeCloud Drive</title>'
            . '<link rel="icon" href="ellogo.png" type="image/png">'
            . '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">'
            . '<link rel="stylesheet" href="css/styles.css?v=' . $stylesVersion . '">'
            . '<link rel="stylesheet" href="css/responsive.css?v=' . $responsiveVersion . '">'
            . '<link rel="stylesheet" href="css/personal-tools.css?v=' . $toolVersion . '">'
            . '<script defer src="js/theme-state-bridge.js?v=' . $themeBridgeVersion . '"></script>'
            . '<style>'
            . '.personal-card label{display:block;margin:14px 0 8px}'
            . '.personal-card input,.personal-card select,.personal-card button{width:100%;box-sizing:border-box;padding:12px;border-radius:9px;font-size:16px}'
            . '.personal-card button{margin-top:14px;cursor:pointer}'
            . '.result{margin:22px 0;padding:18px;border:1px solid var(--border);border-radius:14px}'
            . '.otp{font:700 36px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;margin:10px 0;cursor:pointer;user-select:none;touch-action:manipulation;display:inline-block;border-radius:8px;padding:4px 2px}'
            . '.otp:focus{outline:2px solid var(--accent);outline-offset:5px}.otp:active{transform:scale(.98)}'
            . '.copy-hint{font-size:13px;margin:-2px 0 10px}.note{margin-top:14px;padding:10px;border-radius:8px;background:var(--panel-bg2)}'
            . '</style></head>';
    }

    private function navbar(string $label): string
    {
        return '<nav class="navbar navbar-expand-lg navbar-dark px-3 drive-navbar">'
            . '<a class="navbar-brand d-flex align-items-center" href="s3.php" title="Volver al Drive">'
            . '<img src="ellogo.png" width="48" height="38" class="drive-brand-logo mr-2" alt="Logo">Cloud Drive</a>'
            . '<div class="ml-auto d-flex align-items-center flex-wrap">'
            . '<span class="small text-muted mr-2">' . self::e($label) . '</span>'
            . '<a class="btn btn-outline-light btn-sm" href="s3.php"><i class="fas fa-arrow-left mr-1"></i>Drive</a>'
            . '</div></nav>';
    }

    private function assetVersion(string $relative): int
    {
        $path = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
        return is_file($path) ? (int)filemtime($path) : 1;
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
