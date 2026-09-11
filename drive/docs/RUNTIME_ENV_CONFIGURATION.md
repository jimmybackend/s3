# Configuración de entorno en ejecución

## Objetivo

ArcadeCloud Drive puede recibir configuración desde más de una fuente. Esta guía documenta cómo deben convivir esas fuentes para que el proceso PHP-FPM y el panel **Servidor** vean los mismos valores, sin guardar credenciales reales en el repositorio.

El modelo recomendado es:

```text
archivos EnvironmentFile del servidor
        ↓
systemd inicia PHP-FPM Drive
        ↓
variables del proceso PHP-FPM
        ↓
app_bootstrap.php
        ↓
runtime-env.json (si existe una clave administrada)
        ↓
aplicación ArcadeCloud Drive
```

`runtime-env.json` es una capa administrada por ArcadeCloud. No sustituye la posibilidad de mantener configuración base del servidor en archivos de entorno separados.

## Fuentes de configuración

### 1. Configuración base de Drive

Una instalación puede mantener variables generales en un archivo privado fuera del repositorio, por ejemplo:

```text
/etc/arcadecloud-drive/drive.env
```

Ese archivo puede contener variables generales necesarias antes de iniciar PHP-FPM.

### 2. Configuración SMTP

La configuración SMTP puede mantenerse en un archivo independiente:

```text
/etc/arcadecloud-drive/smtp.env
```

Ejemplo sin datos reales:

```ini
ARCADECLOUD_SMTP_HOST="smtp.example.test"
ARCADECLOUD_SMTP_PORT="587"
ARCADECLOUD_SMTP_SECURE="tls"
ARCADECLOUD_SMTP_USERNAME="mailer@example.test"
ARCADECLOUD_SMTP_PASSWORD="REEMPLAZAR_EN_EL_SERVIDOR"
ARCADECLOUD_SMTP_FROM_EMAIL="mailer@example.test"
ARCADECLOUD_SMTP_FROM_NAME="ArcadeCloud Drive"
ARCADECLOUD_SMTP_REPLY_TO="noreply@example.test"
ARCADECLOUD_SMTP_TIMEOUT="20"
ARCADECLOUD_SMTP_DEBUG="false"
```

El archivo real no se versiona.

### 3. Configuración administrada por ArcadeCloud

El helper administrativo usa:

```text
/etc/arcadecloud-drive/runtime-env.json
```

Ejemplo:

```json
{
  "ARCADECLOUD_PUBLIC_URL": "https://drive.example.test"
}
```

`drive/app_bootstrap.php` carga este archivo al principio de cada petición. Cuando una clave existe aquí, se aplica mediante `putenv()` y pasa a ser el valor administrado usado por la aplicación para esa petición.

## PHP-FPM dedicado

ArcadeCloud Drive debe usar su pool dedicado. El nombre exacto del servicio depende de la instalación, pero una unidad systemd puede cargar varios archivos `EnvironmentFile`:

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

No se debe copiar literalmente `SERVICIO_PHP_FPM_DRIVE`; primero se identifica el servicio real de la instalación.

El pool PHP-FPM debe permitir que las variables recibidas por el proceso estén disponibles para la aplicación. Una configuración típica de pool dedicado utiliza:

```ini
clear_env = no
```

También pueden existir variables `env[ARCADECLOUD_...]` declaradas directamente en el pool. Esas variables forman parte de la fuente **entorno PHP**.

## Cómo interpreta el panel Servidor el origen

El panel superadmin muestra todas las variables ArcadeCloud administrables y etiqueta el origen de cada valor.

### `runtime-env.json`

La variable existe en `/etc/arcadecloud-drive/runtime-env.json` y la capa administrada por ArcadeCloud la aplica en cada petición.

### `entorno PHP`

La variable ya estaba presente en el proceso PHP. Puede provenir de:

- un `EnvironmentFile` de systemd;
- una declaración `env[...]` del pool PHP-FPM;
- otro mecanismo de entorno configurado por el operador.

El panel no necesita conocer cuál de esos mecanismos creó la variable; sólo informa que PHP ya la recibió.

### `sin configurar`

La variable no existe ni en `runtime-env.json` ni en el entorno recibido por PHP.

Esto no siempre significa que una clase no tenga un valor por defecto interno. Por ejemplo, `SmtpConfig` puede tener valores por defecto para host, puerto, seguridad, timeout o debug. El panel describe la **configuración explícita del entorno**, no los defaults internos de cada clase.

## Precedencia

Para las variables administrables, la precedencia práctica es:

```text
runtime-env.json
    ↓ si la clave existe
valor administrado por la UI

si no existe:
entorno PHP-FPM
    ↓
valor base del servidor

si tampoco existe:
default interno de la aplicación, cuando esa variable tenga default
```

Esto permite migrar gradualmente una instalación existente. Las variables antiguas pueden seguir viniendo del entorno PHP y sólo las que el superadmin modifique desde la UI pasan a `runtime-env.json`.

## Flujo para una instalación existente

Cuando el panel muestre una variable inesperadamente como `sin configurar`:

1. confirmar qué servicio PHP-FPM atiende ArcadeCloud Drive;
2. revisar qué archivos `EnvironmentFile` carga ese servicio;
3. revisar el pool dedicado y su `clear_env` / `env[...]`;
4. confirmar que el archivo privado de configuración sigue existiendo;
5. reiniciar únicamente el servicio PHP-FPM de Drive cuando se modifique la configuración de systemd o del pool;
6. volver a abrir el panel y verificar el origen mostrado.

No se debe volver a escribir una credencial sólo porque el panel diga `sin configurar` sin antes revisar si ya existe en otro archivo privado del servidor.

## Verificación de proceso sin mostrar secretos

Para comprobar qué variables recibió el proceso master de PHP-FPM se puede inspeccionar `/proc/<PID>/environ`. Los secretos deben enmascararse antes de copiar la salida a tickets, chats o documentación.

Ejemplo genérico:

```bash
PID=$(systemctl show -p MainPID --value SERVICIO_PHP_FPM_DRIVE)

sudo sh -c "tr '\0' '\n' < /proc/$PID/environ" \
| awk -F= '
/^ARCADECLOUD_/ {
    if ($1 == "ARCADECLOUD_SMTP_PASSWORD")
        print $1 "=***CONFIGURADA***"
    else
        print
}'
```

## Qué se versiona y qué no

Se versionan:

- nombres de variables;
- ejemplos sintéticos;
- arquitectura de carga;
- rutas convencionales;
- procedimientos de diagnóstico;
- comportamiento del panel.

No se versionan:

- contraseñas SMTP reales;
- correos privados usados como credenciales;
- claves AWS/IAM;
- credenciales MySQL;
- llaves privadas FederationCloud;
- contenido real de archivos privados bajo `/etc/arcadecloud-drive/`.

## Relación con el panel superadmin

El modal **Servidor** funciona como una vista práctica de configuración efectiva:

```text
Variable | Grupo | Valor actual | Origen | Modificar
```

El objetivo es que el operador pueda responder rápidamente:

> Estas son las variables que ArcadeCloud conoce, estos son sus valores efectivos y éste es su origen. ¿Cuál deseas modificar?

Una modificación desde la UI escribe únicamente la variable seleccionada en `runtime-env.json`; las demás continúan viniendo de su fuente anterior hasta que también sean administradas desde la aplicación.
