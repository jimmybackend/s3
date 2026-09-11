# Instalación inicial de ArcadeCloud Drive

## Objetivo

Una instalación nueva puede necesitar configurar MySQL, AWS y SMTP **antes** de que exista un usuario normal en `Users`. Por eso `/setup/` usa un supervisor temporal separado del login del Drive.

Este supervisor:

- no pertenece a la tabla `Users`;
- no puede abrir archivos, S3, chat, FederationCloud ni otras funciones del Drive;
- sólo puede administrar las variables permitidas de Base de datos, AWS y SMTP;
- sólo existe mientras la instalación no tenga `setup.lock`;
- desaparece al registrar el primer `Users.system_role = 'superadmin'`.

## Crear el supervisor temporal

En una instalación nueva se instala el helper con la bandera explícita `--bootstrap-setup`:

```bash
sudo bash drive/bin/install_arcadecloud_admin_helper.sh \
  --php-user=USUARIO_REAL_PHP_FPM \
  --bootstrap-setup
```

La bandera es intencional. Reinstalar el helper sin ella **no abre `/setup/`** en una instalación existente.

El helper genera:

```text
/etc/arcadecloud-drive/bootstrap-auth.json
```

El archivo contiene únicamente:

- nombre del supervisor temporal;
- hash de contraseña;
- hash SHA-256 del token de activación;
- fecha de creación.

No contiene la contraseña ni el token en texto plano.

## Credenciales iniciales

El supervisor tiene estas credenciales conocidas:

```text
usuario: arcadecloud
contraseña inicial: arcadecloud
```

Por sí solas **no permiten entrar**. También es obligatorio el token aleatorio de 256 bits que el helper muestra una sola vez al crear el bootstrap.

El operador abre:

```text
https://TU-DOMINIO/setup/?token=TOKEN_GENERADO
```

Si el token coincide, el servidor lo asocia a la sesión PHP y redirige inmediatamente a `/setup/` sin dejar el token en la URL. Después se solicita `arcadecloud / arcadecloud`.

El token sigue siendo válido para activar una nueva sesión mientras el setup siga abierto. Si se pierde antes de completar la instalación, root puede generar uno nuevo:

```bash
sudo /usr/local/sbin/arcadecloud-drive-admin bootstrap-reset
```

`bootstrap-reset` no funciona después de cerrar la instalación.

## Flujo web

El asistente muestra cuatro bloques:

```text
1. Base de datos
2. AWS
3. SMTP
4. Primer superadmin
```

### Base de datos

Variables:

- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASSWORD`
- `DB_NAME`

Antes de escribirlas en `runtime-env.json`, ArcadeCloud intenta una conexión MySQL real. Si no conecta, no guarda el bloque.

El setup no importa automáticamente un dump SQL porque un archivo SQL puede representar una base existente y no debe ejecutarse implícitamente. La base seleccionada debe contener el esquema de ArcadeCloud; para crear el primer superadmin debe existir la tabla `Users`.

### AWS

Variables principales:

- `AWS_REGION`
- `AWS_S3_BUCKET`
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`

Variables opcionales:

- `AWS_SESSION_TOKEN`
- `AWS_CONTROL_ACCESS_KEY_ID`
- `AWS_CONTROL_SECRET_ACCESS_KEY`
- `AWS_CONTROL_SESSION_TOKEN`

Las claves ya configuradas nunca se devuelven al navegador. Un campo secreto vacío conserva el valor existente.

### SMTP

El mismo asistente puede preparar las variables `ARCADECLOUD_SMTP_*` que usa `SmtpConfig`. Los secretos tampoco se devuelven al navegador.

### Primer superadmin

Cuando MySQL ya funciona, el setup solicita:

- nombre;
- apellido;
- correo;
- contraseña del superadmin.

El backend crea un usuario `Activo`, con `system_role = 'superadmin'`, contraseña mediante `password_hash()` y campos de perfil mínimos compatibles con la tabla `Users` actual.

Si ya existe un superadmin, no se crea otro: el setup simplemente completa el cierre del supervisor temporal.

## Cierre irreversible del bootstrap

Después de crear o detectar el primer superadmin, el helper:

1. crea `/etc/arcadecloud-drive/setup.lock`;
2. elimina `/etc/arcadecloud-drive/bootstrap-auth.json`;
3. invalida la sesión temporal de setup.

A partir de ese momento `/setup/` sólo informa que la instalación está cerrada.

El operador también puede cerrarlo manualmente desde root:

```bash
sudo /usr/local/sbin/arcadecloud-drive-admin bootstrap-disable
```

El helper no reactiva automáticamente un setup que ya tiene `setup.lock`.

## Separación respecto al Drive normal

`drive/setup/api.php` **no carga `app_bootstrap.php` ni `db.php`** al abrir el asistente. Esto es necesario porque MySQL puede no estar configurado todavía.

El flujo es:

```text
/setup
  -> BootstrapSetupAuth
  -> SetupConfigurationService
  -> ManagedRuntimeEnvironment
  -> helper privilegiado limitado
  -> /etc/arcadecloud-drive/runtime-env.json

cuando DB funciona:
  -> SuperAdminBootstrapService
  -> Users.system_role = superadmin
  -> bootstrap-complete
  -> setup.lock
```

La aplicación normal continúa usando:

```text
app_bootstrap.php
  -> runtime-env.json
  -> Config-s3.php
  -> db.php
  -> Drive
```

No existe una segunda fuente de configuración.

## Archivos que nunca se versionan

No deben entrar a Git:

- `bootstrap-auth.json` real;
- `setup.lock` real;
- contenido real de `runtime-env.json`;
- contraseñas MySQL/SMTP;
- claves AWS;
- token de activación;
- identidad privada FederationCloud.

Los tests y la documentación usan sólo valores sintéticos.
