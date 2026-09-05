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

        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Acceso privado</title>'
            . $this->style()
            . '</head><body><main class="card"><h1>' . self::e($message) . '</h1>';

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

        echo '<p><a href="index.php">Volver</a></p></main></body></html>';
        exit;
    }

    public function forbidden(): never
    {
        http_response_code(403);
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Acceso restringido</title>'
            . $this->style()
            . '</head><body><main class="card"><h1>Acceso restringido</h1>'
            . '<p>Esta herramienta personal no está disponible para esta cuenta.</p>'
            . '<p><a href="s3.php">Volver al Drive</a></p></main></body></html>';
        exit;
    }

    /**
     * @param array<int,array{id:string,label:string}> $accounts
     * @param array{account_id:string,label:string,code:string,remaining:int,note:string}|null $result
     */
    public function tool(array $accounts, ?array $result = null, string $error = ''): never
    {
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Herramienta AWS personal</title>'
            . $this->style()
            . '</head><body><main class="card wide">'
            . '<nav><a href="https://aws.amazon.com/es/console/" target="_blank" rel="noopener noreferrer">AWS</a>'
            . ' · <a href="https://console.aws.amazon.com/console/home" target="_blank" rel="noopener noreferrer">Consola</a>'
            . ' · <a href="s3.php">Drive</a></nav>'
            . '<h1>Generador TOTP personal</h1>'
            . '<p class="muted">Las semillas permanecen en el servidor y no se envían al navegador.</p>';

        if ($error !== '') {
            echo '<p class="error">' . self::e($error) . '</p>';
        }

        if ($result !== null) {
            echo '<section class="result"><div class="muted">' . self::e($result['label']) . '</div>'
                . '<div class="otp" id="otp-code">' . self::e($result['code']) . '</div>'
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

        echo '</main>';
        if ($result !== null) {
            echo '<script>(function(){let n=' . (int)$result['remaining'] . ';const el=document.getElementById("timer");'
                . 'const id=setInterval(function(){n--;if(el){el.textContent=Math.max(0,n);}if(n<=0){clearInterval(id);}},1000);})();</script>';
        }
        echo '</body></html>';
        exit;
    }

    private function style(): string
    {
        return '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b1020;color:#e5e7eb;margin:0;padding:24px}'
            . '.card{max-width:420px;margin:8vh auto;background:#111827;border:1px solid #263244;border-radius:16px;padding:24px;box-shadow:0 18px 50px rgba(0,0,0,.35)}'
            . '.wide{max-width:700px}.muted{color:#9ca3af}.error{color:#fca5a5}.result{margin:22px 0;padding:18px;border:1px solid #334155;border-radius:14px;background:#0f172a}'
            . '.otp{font:700 36px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;margin:10px 0}.note{margin-top:14px;padding:10px;border-radius:8px;background:#1f2937}'
            . 'label{display:block;margin:14px 0 8px}input,select,button{width:100%;box-sizing:border-box;padding:12px;border-radius:9px;font-size:16px}'
            . 'input,select{background:#0b1220;color:#e5e7eb;border:1px solid #374151}button{margin-top:14px;border:0;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}'
            . 'a{color:#93c5fd}</style>';
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
