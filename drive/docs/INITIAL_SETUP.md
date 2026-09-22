# Instalación inicial de ArcadeCloud Drive

Antes de comenzar, usa `INSTALLATION_PREPARATION.md` para reunir MySQL, AWS/S3 y los datos del
primer superadmin. La instalación básica ya no exige SMTP, tokens AWS ni configuración de mirror.

## Objetivo

Una instalación nueva sólo necesita configurar MySQL y AWS/S3 **antes** de que exista un usuario normal en `Users`. Por eso `/setup/` usa un supervisor temporal separado del login del Drive.

Este supervisor:

- no pertenece a la tabla `Users`;
- no puede abrir archivos, S3, chat, FederationCloud ni otras funciones del Drive;
- durante el setup básico sólo administra Base de datos y las cuatro variables principales de AWS/S3;
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

Mientras existe `bootstrap-auth.json` y todavía no existe `setup.lock`, la entrada normal `/` redirige
a `/setup/`. El supervisor `arcadecloud` nunca se autentica en el login normal por correo y no se añade
ninguna excepción al campo `type="email"`. Al cerrar correctamente el setup, se elimina
`bootstrap-auth.json`, se crea `setup.lock` y el login normal vuelve a quedar disponible.

El operador abre el endpoint disponible. En una EC2 nueva sin dominio puede ser:

```text
http://IP_PUBLICA/setup/?token=TOKEN_GENERADO
```

Si después configura un dominio con HTTPS, puede usar:

```text
https://TU-DOMINIO/setup/?token=TOKEN_GENERADO
```

Si el token coincide, el servidor lo asocia a la sesión PHP y redirige inmediatamente a `/setup/` sin dejar el token en la URL. Después se solicita `arcadecloud / arcadecloud`.

El instalador autónomo no obliga al operador a manipular el token. Genera o rota la activación mientras
el setup siga abierto y muestra directamente una línea `URL DE SETUP` con la dirección completa lista
para copiar al navegador. Si el instalador se ejecuta nuevamente antes de terminar, entrega una URL
nueva y la anterior deja de ser la activación vigente.

`bootstrap-reset` sigue disponible como herramienta administrativa de bajo nivel y no funciona después
de cerrar la instalación.

## Flujo web

El asistente muestra tres bloques:

```text
1. Base de datos
2. AWS / S3
3. Primer superadmin
```

Después de entrar a ArcadeCloud, el superadmin dispone de **Configuración básica** y
**Configuración avanzada**. SMTP, tokens AWS, credenciales AWS de control y mirrors pertenecen a la
configuración avanzada.

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

Durante el setup básico no se solicitan tokens ni credenciales de control. Esas opciones permanecen
sin configurar hasta que el superadmin las habilite desde Configuración avanzada.

Las claves ya configuradas nunca se devuelven al navegador. Un campo secreto vacío conserva el valor existente.

### Primer superadmin

Cuando MySQL ya funciona, el setup solicita:

- nombre;
- apellido;
- correo;
- contraseña del superadmin.

El backend crea un usuario `Activo`, con `role = 'Administración'` y
`system_role = 'superadmin'`, contraseña mediante `password_hash()` y campos de perfil mínimos
compatibles con la tabla `Users` actual. Después del INSERT vuelve a consultar MySQL para comprobar que
ese mismo usuario quedó persistido. Sólo entonces elimina la credencial temporal `arcadecloud`.

`arcadecloud` nunca se inserta en la tabla `Users`: sólo existe en el archivo temporal de bootstrap.

Si ya existe un superadmin, no se crea otro: el setup simplemente completa el cierre del supervisor temporal.



## Preparador automático del servidor

Para una instalación nueva existe:

```bash
sudo bash drive/bin/install_arcadecloud.sh
```

La fase inicial:

1. en Amazon Linux 2023 detecta e instala automáticamente las dependencias del sistema que falten;
2. configura un pool PHP-FPM dedicado para ArcadeCloud en `127.0.0.1:9075` cuando sea necesario;
3. configura y valida Nginx para servir el Drive por HTTP;
4. detecta el usuario PHP-FPM;
5. instala dependencias Composer;
6. instala el helper y abre el bootstrap temporal;
7. genera una identidad FederationCloud si no existe;
8. genera automáticamente un `node_name`;
9. detecta la IPv4 pública cuando es posible;
10. guarda la IP pública como endpoint HTTP del Drive;
11. genera y conserva la identidad FederationCloud, pero la deja desactivada hasta tener un endpoint HTTPS explícito;
12. deja al operador únicamente los tres pasos web.

En una EC2 que todavía no tenga Git puede usarse el bootstrap de raíz
`bootstrap_arcadecloud.sh`. Consulta `AUTOMATED_INSTALLER.md` para el contrato completo,
idempotencia y archivos del sistema administrados.

Después de completar los tres pasos:

```bash
sudo bash drive/bin/install_arcadecloud.sh --finalize
```

La finalización no exige dominio ni HTTPS. Si FederationCloud sigue desactivado, el Drive queda
finalizado y utilizable por HTTP/IP sin instalar timers ni solicitar certificados. FederationCloud y
HTTPS se activan después sólo cuando el superadmin los configure explícitamente.

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
