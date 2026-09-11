# Configuración de entorno en ejecución

## Objetivo

ArcadeCloud Drive puede recibir configuración desde más de una fuente. Esta guía documenta cómo conviven esas fuentes para que PHP-FPM y el panel **Servidor** vean los mismos valores, sin guardar credenciales reales en el repositorio.

Modelo:

```text
EnvironmentFile / pool PHP-FPM
        ↓
variables del proceso PHP
        ↓
app_bootstrap.php
        ↓
runtime-env.json
        ↓
Config-s3.php / db.php / servicios ArcadeCloud
```

`runtime-env.json` es la capa administrada desde la aplicación. Cuando una clave existe allí, tiene precedencia para las siguientes peticiones.

## Archivos privados del servidor

Una instalación puede mantener configuración base fuera del repositorio, por ejemplo:

```text
/etc/arcadecloud-drive/drive.env
/etc/arcadecloud-drive/smtp.env
```

Los nombres son convencionales; lo importante es que el servicio PHP-FPM de Drive cargue los archivos que correspondan.

Ejemplo systemd:

```ini
[Service]
EnvironmentFile=/etc/arcadecloud-drive/drive.env
EnvironmentFile=/etc/arcadecloud-drive/smtp.env
```

Después de cambiar la unidad:

```bash
sudo systemctl daemon-reload
sudo systemctl restart SERVICIO_PHP_FPM_DRIVE
```

El pool puede usar:

```ini
clear_env = no
```

o directivas `env[...]`. En ambos casos el panel las presenta como **entorno PHP**.

## Configuración administrada por ArcadeCloud

El helper escribe:

```text
/etc/arcadecloud-drive/runtime-env.json
```

Ejemplo sintético:

```json
{
  "ARCADECLOUD_PUBLIC_URL": "https://drive.example.test",
  "AWS_REGION": "us-east-1",
  "AWS_S3_BUCKET": "arcadecloud-example-bucket"
}
```

`drive/app_bootstrap.php` carga `ManagedRuntimeEnvironment` antes de `Config-s3.php` y `db.php`. Por ello una variable guardada desde **Servidor** puede reemplazar el valor que originalmente vino de PHP-FPM sin editar esos archivos PHP.

## Origen mostrado en el panel

### `runtime-env.json`

La clave existe en `/etc/arcadecloud-drive/runtime-env.json`.

### `entorno PHP`

La clave fue recibida por PHP-FPM desde systemd, el pool u otro mecanismo externo.

### `sin configurar`

No existe una variable explícita ni en el archivo administrado ni en el proceso PHP.

Esto no excluye defaults internos. Por ejemplo, algunas opciones SMTP o AWS pueden tener valores fallback en las clases de configuración.

## Precedencia

```text
runtime-env.json
    ↓
entorno PHP-FPM
    ↓
default interno de la aplicación, si existe
```

Esto permite migrar una instalación existente de forma gradual: una variable puede seguir viniendo de `drive.env` o `smtp.env` hasta que el superadmin decida administrarla desde la UI.

## Grupos de configuración

### FederationCloud y SMTP

Se pueden modificar como variables individuales desde la tabla Servidor.

### Base de datos

La aplicación usa:

```text
DB_HOST
DB_PORT
DB_USER
DB_PASSWORD
DB_NAME
```

`db.php` lee estas variables después de que `runtime-env.json` haya sido cargado.

El panel no escribe DB una variable por vez. Abre un formulario de grupo y realiza esta secuencia:

```text
valores nuevos + valores actuales conservados
        ↓
validación
        ↓
prueba real de conexión MySQL
        ↓
si conecta: env-set-many
        ↓
una sola escritura atómica de runtime-env.json
```

Si la prueba falla, no se escribe ningún cambio.

`DB_PORT` puede omitirse y el runtime conserva el valor existente; `db.php` usa `3306` cuando no existe una configuración explícita.

### AWS

`Config-s3.php` consume:

```text
AWS_REGION
AWS_S3_BUCKET
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_SESSION_TOKEN
AWS_CONTROL_ACCESS_KEY_ID
AWS_CONTROL_SECRET_ACCESS_KEY
AWS_CONTROL_SESSION_TOKEN
```

El grupo AWS también se persiste mediante una sola operación `env-set-many`.

`AWS_SESSION_TOKEN` es opcional.

Las variables `AWS_CONTROL_*` permiten credenciales separadas para operaciones de control. Si no están configuradas, el código reutiliza las credenciales AWS generales. Si se configuran, access key y secret key deben existir juntas.

## Secretos existentes

Los secretos no se envían de vuelta al navegador. La UI muestra únicamente si están configurados.

En un formulario de grupo, dejar vacío un secreto ya configurado significa:

```text
conservar el valor actual
```

Esto permite cambiar, por ejemplo, `DB_HOST` o `AWS_S3_BUCKET` sin volver a escribir contraseñas o claves que no cambiaron.

## Helper instalado y helper del repositorio

El archivo versionado es:

```text
drive/bin/arcadecloud-drive-admin-helper.php
```

La copia ejecutada por PHP-FPM es:

```text
/usr/local/sbin/arcadecloud-drive-admin
```

Cuando el repositorio incorpora una nueva capacidad al helper, la copia instalada debe actualizarse ejecutando nuevamente:

```bash
sudo bash drive/bin/install_arcadecloud_admin_helper.sh --php-user=USUARIO_REAL_PHP_FPM
```

El instalador conserva la configuración y reemplaza el helper por la versión actual.

## Diagnóstico de una instalación existente

Si una variable aparece como `sin configurar` y se esperaba que ya existiera:

1. identificar el servicio PHP-FPM real de Drive;
2. revisar sus `EnvironmentFile`;
3. revisar el pool dedicado y `clear_env` / `env[...]`;
4. confirmar que el archivo privado correspondiente existe;
5. reiniciar sólo PHP-FPM Drive si se modificó systemd o el pool;
6. volver a abrir **Servidor**.

No se debe recrear una credencial sólo porque el panel diga `sin configurar` sin antes localizar la fuente original.

## Verificación sin mostrar secretos

Para inspeccionar el proceso PHP-FPM se puede leer `/proc/<PID>/environ`, ocultando claves sensibles antes de copiar la salida.

Ejemplo genérico:

```bash
PID=$(systemctl show -p MainPID --value SERVICIO_PHP_FPM_DRIVE)

sudo sh -c "tr '\0' '\n' < /proc/$PID/environ" \
| awk -F= '
/^(ARCADECLOUD_|DB_|AWS_)/ {
    if ($1 ~ /(PASSWORD|SECRET|ACCESS_KEY|SESSION_TOKEN)/)
        print $1 "=***CONFIGURADA***"
    else
        print
}'
```

## Qué se versiona y qué no

Se versionan:

- nombres de variables;
- validaciones;
- arquitectura de carga;
- comportamiento del panel;
- rutas convencionales;
- ejemplos sintéticos.

No se versionan:

- contraseñas SMTP;
- contraseñas MySQL;
- claves o tokens AWS;
- llaves privadas FederationCloud;
- valores reales de producción bajo `/etc/arcadecloud-drive/`.

## Vista práctica

El panel Servidor mantiene el objetivo:

```text
Variable | Grupo | Valor actual | Origen | Acción
```

Para variables normales la acción es **Modificar**. Para DB y AWS la acción es **Configurar**, porque abre y guarda el grupo completo.
