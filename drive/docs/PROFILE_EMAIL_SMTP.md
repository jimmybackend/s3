# Correo SMTP del perfil de ArcadeCloud Drive

El cambio de contraseña del perfil usa un código de verificación de seis dígitos enviado al correo registrado del usuario.

## Arquitectura

El envío de correo ya no depende de Amazon SES. La implementación real vive en:

- `drive/src/Mail/SmtpConfig.php`: carga y valida configuración desde variables de entorno.
- `drive/src/Mail/SmtpEmailService.php`: cliente SMTP OOP con AUTH LOGIN, STARTTLS/SSL, multipart texto/HTML, timeout y logging opcional.
- `drive/src/Aws/SesEmailService.php`: adaptador temporal para conservar compatibilidad con el ensamblado actual de `DriveApplication`. No usa AWS SES.

Las credenciales SMTP nunca deben guardarse en GitHub.

## Variables de entorno

| Variable | Ejemplo | Uso |
| --- | --- | --- |
| `ARCADECLOUD_SMTP_HOST` | `smtp.example.test` | Host SMTP |
| `ARCADECLOUD_SMTP_PORT` | `587` | Puerto SMTP |
| `ARCADECLOUD_SMTP_SECURE` | `tls` | `tls`, `ssl` o vacío |
| `ARCADECLOUD_SMTP_USERNAME` | `mailer@example.test` | Usuario SMTP |
| `ARCADECLOUD_SMTP_PASSWORD` | `***` | Contraseña SMTP; obligatoria y secreta |
| `ARCADECLOUD_SMTP_FROM_EMAIL` | `mailer@example.test` | Remitente visible |
| `ARCADECLOUD_SMTP_FROM_NAME` | `ArcadeCloud Drive` | Nombre visible del remitente |
| `ARCADECLOUD_SMTP_REPLY_TO` | `noreply@example.test` | Reply-To |
| `ARCADECLOUD_SMTP_TIMEOUT` | `20` | Timeout entre 1 y 120 segundos |
| `ARCADECLOUD_SMTP_DEBUG` | `false` | Logging técnico; nunca muestra usuario/contraseña AUTH |

Existe un template sin secretos en `drive/config/smtp.env.example`.

## Ejemplo temporal en una shell

Esto sirve para validar la configuración en una sesión de shell. No hace persistentes las variables para PHP-FPM:

```bash
export ARCADECLOUD_SMTP_HOST='smtp.example.test'
export ARCADECLOUD_SMTP_PORT='587'
export ARCADECLOUD_SMTP_SECURE='tls'
export ARCADECLOUD_SMTP_USERNAME='mailer@example.test'
export ARCADECLOUD_SMTP_PASSWORD='TU_PASSWORD_REAL'
export ARCADECLOUD_SMTP_FROM_EMAIL='mailer@example.test'
export ARCADECLOUD_SMTP_FROM_NAME='ArcadeCloud Drive'
export ARCADECLOUD_SMTP_REPLY_TO='noreply@example.test'
export ARCADECLOUD_SMTP_TIMEOUT='20'
export ARCADECLOUD_SMTP_DEBUG='false'
```

## Producción con PHP-FPM

Las mismas claves deben llegar al proceso PHP-FPM dedicado de Drive.

Una instalación puede mantener SMTP separado de la configuración general del servicio:

```text
/etc/arcadecloud-drive/drive.env
/etc/arcadecloud-drive/smtp.env
```

La unidad systemd puede cargar ambos archivos:

```ini
[Service]
EnvironmentFile=/etc/arcadecloud-drive/drive.env
EnvironmentFile=/etc/arcadecloud-drive/smtp.env
```

El pool PHP-FPM dedicado debe permitir que esas variables lleguen a la aplicación; una configuración típica utiliza:

```ini
clear_env = no
```

También puede haber variables `env[ARCADECLOUD_...]` definidas directamente en el pool.

Después de modificar la unidad o el pool, primero se identifica el servicio PHP-FPM real de la instalación y sólo entonces se recarga/reinicia ese servicio.

Si el archivo SMTP existe pero no está incluido por systemd ni por el pool, la aplicación no recibirá esas variables y el panel **Servidor** las mostrará como `sin configurar`. Antes de volver a escribir credenciales, se debe comprobar cómo está siendo cargado el entorno.

ArcadeCloud además tiene una capa administrada en:

```text
/etc/arcadecloud-drive/runtime-env.json
```

Cuando el superadmin modifica una variable SMTP desde el panel, esa clave pasa a `runtime-env.json` y tiene precedencia para las siguientes peticiones. Las demás variables pueden continuar viniendo del entorno PHP hasta que también sean administradas desde la UI.

La arquitectura completa de fuentes, precedencia y diagnóstico está en:

- [`RUNTIME_ENV_CONFIGURATION.md`](RUNTIME_ENV_CONFIGURATION.md)

Nunca coloques la contraseña SMTP dentro de `Config-s3.php`, `s3.php`, JavaScript, HTML, README público ni archivos versionados.

## Flujo de seguridad de contraseña

1. El usuario solicita el código desde `Mi perfil`.
2. El backend obtiene el correo real desde `Users`; no lo expone completo al navegador.
3. Se genera un código aleatorio de 6 dígitos.
4. SMTP lo envía al correo registrado.
5. La sesión guarda únicamente un hash del código durante 10 minutos.
6. Hay espera de 60 segundos entre reenvíos y máximo 5 intentos.
7. La contraseña nueva se almacena usando `password_hash()`.

## Avatar y estado visual

Durante `Cambiar imagen` la interfaz muestra spinner y el mensaje `Subiendo fotografía…`, deshabilita temporalmente selector y botones de avatar, y muestra éxito o error al finalizar. Cerrar solamente el modal no cancela necesariamente la petición; cerrar/recargar la página sí puede interrumpirla.

## Verificación local/CI

```bash
php -l drive/src/Mail/SmtpConfig.php
php -l drive/src/Mail/SmtpEmailService.php
php -l drive/src/Aws/SesEmailService.php
php drive/tests/smtp_config_smoke.php
node --check drive/js/profile.js
```

El smoke test usa únicamente valores sintéticos y no realiza conexiones SMTP reales.
