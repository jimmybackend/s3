# Superadmin: identidad del nodo y configuración del servidor

## Objetivo

ArcadeCloud Drive permite que un `Users.system_role = superadmin` administre desde la aplicación:

1. crear o renombrar la identidad FederationCloud;
2. configurar FederationCloud y SMTP;
3. actualizar la conexión MySQL usada por Drive;
4. configurar las credenciales y parámetros AWS usados por Drive.

La configuración efectiva se guarda fuera del repositorio y nunca requiere escribir secretos dentro del código PHP.

## Preparación administrativa: una sola vez

El servidor instala un helper limitado con:

```bash
sudo bash drive/bin/install_arcadecloud_admin_helper.sh --php-user=USUARIO_REAL_PHP_FPM
```

El instalador:

- copia `/usr/local/sbin/arcadecloud-drive-admin`;
- crea `/etc/arcadecloud-drive/admin-helper.json`;
- crea `/etc/arcadecloud-drive/runtime-env.json` si no existe;
- permite al grupo PHP leer la configuración administrada;
- instala una regla `sudoers` para invocar únicamente ese helper.

Cuando el código del helper cambia, se vuelve a ejecutar el mismo instalador para reemplazar la copia de `/usr/local/sbin/arcadecloud-drive-admin` por la versión actual del repositorio.

## Identidad FederationCloud

La UI de identidad sirve para una instalación nueva o un nodo existente.

Flujo de creación:

```text
superadmin escribe node_name
 -> normalización
 -> seed comprueba disponibilidad
 -> si está libre se generan llaves
 -> federation-node.json
 -> descriptor firmado
 -> registro final en FederationNodes
```

Renombrar modifica únicamente `node_name`; `node_id`, Ed25519 y `payload_key` permanecen iguales.

## Archivo de variables administradas

El archivo administrado es:

```text
/etc/arcadecloud-drive/runtime-env.json
```

`drive/app_bootstrap.php` lo carga antes de `Config-s3.php` y `db.php`. Por ello los valores guardados desde **Servidor** se aplican a las siguientes peticiones sin editar código PHP.

La arquitectura de fuentes y precedencia se documenta en:

- [`RUNTIME_ENV_CONFIGURATION.md`](RUNTIME_ENV_CONFIGURATION.md)

## Variables visibles en Servidor

### FederationCloud

- `ARCADECLOUD_PUBLIC_URL`
- `ARCADECLOUD_FEDERATION_URL`
- `ARCADECLOUD_FEDERATION_ENABLED`
- `ARCADECLOUD_FEDERATION_SEED_URL`

### SMTP

- `ARCADECLOUD_SMTP_HOST`
- `ARCADECLOUD_SMTP_PORT`
- `ARCADECLOUD_SMTP_SECURE`
- `ARCADECLOUD_SMTP_USERNAME`
- `ARCADECLOUD_SMTP_PASSWORD`
- `ARCADECLOUD_SMTP_FROM_EMAIL`
- `ARCADECLOUD_SMTP_FROM_NAME`
- `ARCADECLOUD_SMTP_REPLY_TO`
- `ARCADECLOUD_SMTP_TIMEOUT`
- `ARCADECLOUD_SMTP_DEBUG`

### Base de datos

Estas variables se editan y guardan como un único grupo `database`:

- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASSWORD`
- `DB_NAME`

Antes de escribir el grupo, el backend intenta abrir una conexión MySQL con la configuración resultante. Si la conexión falla, `runtime-env.json` no se modifica.

Un campo secreto ya configurado puede dejarse vacío en el formulario para conservar su valor actual.

### AWS

Estas variables se editan y guardan como un único grupo `aws`:

- `AWS_REGION`
- `AWS_S3_BUCKET`
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_SESSION_TOKEN`
- `AWS_CONTROL_ACCESS_KEY_ID`
- `AWS_CONTROL_SECRET_ACCESS_KEY`
- `AWS_CONTROL_SESSION_TOKEN`

`AWS_SESSION_TOKEN` es opcional para credenciales temporales.

Las credenciales `AWS_CONTROL_*` son opcionales. Cuando se usan, `AWS_CONTROL_ACCESS_KEY_ID` y `AWS_CONTROL_SECRET_ACCESS_KEY` deben existir juntas; de lo contrario el plano de control reutiliza las credenciales AWS generales según `Config-s3.php`.

## Escritura atómica por grupo

La base de datos y AWS no se escriben variable por variable.

La UI envía todas las variables del bloque al backend y el helper ejecuta una sola actualización `env-set-many` sobre `runtime-env.json`.

Esto evita estados intermedios como:

```text
DB_HOST nuevo
DB_USER antiguo
DB_PASSWORD antiguo
DB_NAME antiguo
```

Para DB el flujo es:

```text
formulario completo
 -> validar valores
 -> combinar secretos existentes no reemplazados
 -> probar conexión MySQL
 -> si conecta: escribir grupo de una sola vez
 -> siguientes peticiones usan la nueva DB
```

## Vista práctica del panel Servidor

El modal muestra:

```text
Variable | Grupo | Valor actual | Origen | Acción
```

Los orígenes significan:

- `runtime-env.json`: administrada desde ArcadeCloud;
- `entorno PHP`: recibida de systemd/PHP-FPM u otra configuración base;
- `sin configurar`: no existe una variable explícita en esas dos fuentes.

Para Base de datos y AWS, cualquier fila del grupo abre el formulario completo del bloque.

## Secretos

La tabla nunca devuelve el valor actual de:

- `ARCADECLOUD_SMTP_PASSWORD`;
- `DB_PASSWORD`;
- `AWS_ACCESS_KEY_ID`;
- `AWS_SECRET_ACCESS_KEY`;
- tokens de sesión AWS;
- credenciales `AWS_CONTROL_*`.

La interfaz sólo indica `configurada`. Si el campo secreto queda vacío al guardar un grupo y ya existía un valor, se conserva.

## Confirmación del superusuario y elevación temporal

Toda modificación sensible exige:

1. sesión autenticada;
2. `system_role = superadmin`;
3. CSRF válido;
4. reautenticación reciente del superusuario.

La primera modificación después de abrir/expirar la autorización solicita la contraseña actual. Si es correcta, el servidor guarda únicamente en la sesión:

```text
superadmin_reauth_user_id
superadmin_reauth_expires_at
```

No guarda la contraseña ni su hash adicional. La elevación dura **10 minutos** (`600` segundos). Durante ese intervalo el superadmin puede guardar más cambios desde el panel Servidor sin volver a escribir la contraseña.

La elevación queda inválida automáticamente cuando:

- vence el TTL;
- la sesión deja de estar autenticada;
- el usuario de sesión cambia;
- el usuario deja de tener `system_role = superadmin`;
- se cierra sesión y se destruye la sesión PHP.

La interfaz recibe sólo `reauth_required`, `reauth_expires_in` y `reauth_ttl_seconds`; nunca recibe ni conserva la contraseña. No se usa `localStorage` ni `sessionStorage` para esta autorización.

La auditoría registra los nombres de las variables modificadas, pero no sus valores ni la contraseña.

## Lo que no administra este panel

Aunque el superadmin puede cambiar la configuración de aplicación necesaria para operar Drive, el panel no edita directamente:

- Nginx;
- unidades systemd;
- firewall;
- sudoers;
- rutas arbitrarias del sistema;
- comandos shell;
- clave privada FederationCloud;
- `payload_key`;
- `node_id`.

Esas piezas siguen siendo infraestructura del servidor y no configuración de ejecución de ArcadeCloud.
