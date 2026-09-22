# Instalación inicial de ArcadeCloud Drive

Estado del flujo: **configuración básica de tres pasos**.

Antes de comenzar, usa `INSTALLATION_PREPARATION.md` para reunir únicamente los datos que el
setup básico necesita:

1. MySQL;
2. AWS / S3 principal;
3. datos del primer superadmin.

SMTP, credenciales AWS temporales/control, mirror y demás opciones se configuran después desde
**Servidor → Configuración avanzada**.

## Instalador de aplicación

Con el repositorio ya clonado y los prerrequisitos del servidor disponibles:

```bash
sudo bash drive/bin/install_arcadecloud.sh
```

El script:

- detecta el usuario de PHP-FPM cuando es posible;
- ejecuta Composer;
- instala el helper administrativo;
- abre el supervisor temporal de `/setup/`;
- instala el updater;
- detecta la IPv4 pública de EC2 mediante IMDSv2 cuando existe;
- genera automáticamente una identidad FederationCloud si todavía no existe;
- genera automáticamente un `node_name`;
- intenta preparar HTTPS y FederationCloud básico sobre la IP pública;
- deja un watcher systemd que finaliza los workers después de cerrar el setup web.

Si no puede obtener HTTPS válido para la IP, **no bloquea el Drive**. FederationCloud queda desactivado
y pendiente de terminar desde configuración avanzada.

Opciones operativas:

```text
--app-root=/ruta/al/repo
--php-user=USUARIO
--repo-user=USUARIO
--no-federation
```

## Supervisor temporal

El helper mantiene el modelo existente de bootstrap:

```text
/etc/arcadecloud-drive/bootstrap-auth.json
```

El supervisor temporal:

- no pertenece a `Users`;
- no puede abrir el Drive;
- sólo puede usar el setup inicial;
- requiere el token aleatorio generado por el servidor;
- desaparece al crear/detectar el primer superadmin.

Credenciales bootstrap actuales:

```text
usuario: arcadecloud
contraseña inicial: arcadecloud
```

Estas credenciales por sí solas no permiten entrar: también se necesita el token de activación.

## Flujo web básico

`/setup/` muestra sólo:

```text
1. Base de datos
2. AWS / S3
3. Primer superadmin
```

### 1. Base de datos

Solicita:

- `DB_HOST`;
- `DB_PORT` (3306 por defecto);
- `DB_USER`;
- `DB_PASSWORD`;
- `DB_NAME`.

Antes de guardar, ArcadeCloud prueba una conexión MySQL real.

La base seleccionada debe contener el esquema de ArcadeCloud. El setup no reimporta automáticamente
un dump sobre una base existente. Para crear el primer superadmin debe existir la tabla `Users`.

### 2. AWS / S3

El setup básico sólo solicita:

- `AWS_REGION`;
- `AWS_S3_BUCKET`;
- `AWS_ACCESS_KEY_ID`;
- `AWS_SECRET_ACCESS_KEY`.

No pregunta:

- `AWS_SESSION_TOKEN`;
- `AWS_CONTROL_ACCESS_KEY_ID`;
- `AWS_CONTROL_SECRET_ACCESS_KEY`;
- `AWS_CONTROL_SESSION_TOKEN`.

Esos campos quedan para configuración avanzada.

### 3. Primer superadmin

Solicita:

- nombre;
- apellido;
- correo;
- contraseña;
- confirmación de contraseña.

La contraseña debe tener al menos 10 caracteres.

El backend no permite cerrar el setup si MySQL y AWS/S3 básico no están configurados.

El usuario se crea con:

```text
system_role = superadmin
userstatus = Activo
```

La contraseña se almacena mediante `password_hash()`; nunca se persiste en texto plano.

## Cierre del setup

Al completar el primer superadmin:

1. se crea `/etc/arcadecloud-drive/setup.lock`;
2. se elimina `bootstrap-auth.json`;
3. se invalida la sesión temporal;
4. `arcadecloud-install-finalize.path` detecta el lock;
5. si FederationCloud básico quedó habilitado, se instalan/migran sus workers y timer;
6. se registra el resultado en:

```text
/var/lib/arcadecloud-drive/install-finalized.json
```

## FederationCloud básico

El instalador intenta detectar la IPv4 pública EC2 automáticamente.

Si puede preparar HTTPS válido, guarda en:

```text
/etc/arcadecloud-drive/runtime-env.json
```

valores equivalentes a:

```text
ARCADECLOUD_PUBLIC_URL=https://IP_PUBLICA
ARCADECLOUD_FEDERATION_URL=https://IP_PUBLICA/federationcloud/
ARCADECLOUD_FEDERATION_ENABLED=true
```

La identidad se guarda separada en:

```text
/etc/arcadecloud-drive/federation-node.json
```

Cambiar posteriormente de IP a dominio no debe regenerar `node_id`, Ed25519 ni `payload_key`.

Si HTTPS sobre IP no puede quedar listo, el instalador conserva el Drive básico y deja:

```text
ARCADECLOUD_FEDERATION_ENABLED=false
```

hasta que el superadmin configure un endpoint válido.

## Configuración básica y avanzada dentro de ArcadeCloud

El modal **Servidor** abre por defecto en **Configuración básica**.

Básica muestra:

- MySQL;
- AWS/S3 principal;
- URL pública;
- Federation URL;
- FederationCloud enabled;
- seed.

Avanzada añade:

- SMTP;
- AWS session token;
- credenciales AWS de control;
- tokens de control;
- configuración mirror.

Las variables mirror nuevas se administran también desde `runtime-env.json`:

```text
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL
ARCADECLOUD_FEDERATION_REPLICA_ROLE
ARCADECLOUD_FEDERATION_REPLICA_SCOPE
```

Por tanto, las instalaciones nuevas ya no necesitan repartir la configuración mirror entre varios
archivos. `federation.env` se conserva únicamente como compatibilidad para instalaciones anteriores.

## Archivos privados

No se versionan:

- `runtime-env.json` real;
- `federation-node.json`;
- `bootstrap-auth.json`;
- `setup.lock`;
- contraseñas MySQL;
- claves AWS;
- tokens;
- secretos SMTP.

La documentación y los tests sólo usan valores sintéticos.
