# Preparación para instalar ArcadeCloud Drive

Estado: **22 de septiembre de 2026**.

ArcadeCloud adopta un modelo de instalación simple:

```text
Servidor preparado
   -> ejecutar install_arcadecloud.sh
   -> 1. MySQL
   -> 2. AWS / S3
   -> 3. Superadmin
   -> Drive listo
```

Todo lo demás se administra después desde la plataforma mediante **Configuración básica** o
**Configuración avanzada**.

## Qué debe tener preparado el operador

### MySQL

```text
DB_HOST:
DB_PORT: 3306 si no usa otro
DB_USER:
DB_PASSWORD:
DB_NAME:
```

La base debe contener el esquema de ArcadeCloud y la tabla `Users`.

### AWS / S3

```text
AWS_REGION:
AWS_S3_BUCKET:
AWS_ACCESS_KEY_ID:
AWS_SECRET_ACCESS_KEY:
```

El bucket debe existir y las credenciales deben tener los permisos requeridos.

### Primer superadmin

```text
Nombre:
Apellido:
Correo:
Contraseña:
```

La contraseña debe tener al menos 10 caracteres y se pedirá dos veces.

## Lo que NO necesita preparar para la instalación básica

No hace falta tener listos:

- SMTP;
- `AWS_SESSION_TOKEN`;
- credenciales `AWS_CONTROL_*`;
- datos de mirror;
- nombre de nodo FederationCloud;
- Node ID;
- Ed25519;
- `payload_key`;
- seed personalizado;
- dominio propio.

Esos elementos son avanzados o los genera/detecta ArcadeCloud.

## Qué detecta/genera automáticamente el instalador

Cuando sea posible:

- usuario PHP-FPM;
- usuario propietario del repo;
- dependencias Composer;
- IPv4 pública EC2 mediante IMDSv2;
- nombre único del nodo;
- identidad Ed25519;
- `node_id`;
- `payload_key`;
- URL pública basada en IP;
- Federation URL basada en IP;
- helper administrativo;
- updater;
- watcher de finalización systemd;
- HTTPS FederationCloud si la plataforma/certbot lo soporta.

Si HTTPS sobre IP no puede quedar válido, la instalación básica **no falla**. Drive queda listo y
FederationCloud queda pendiente para configuración posterior.

## Configuración básica posterior

Dentro de **Servidor → Configuración básica** se muestran solamente:

```text
MYSQL
DB_HOST
DB_PORT
DB_USER
DB_PASSWORD
DB_NAME

AWS/S3 PRINCIPAL
AWS_REGION
AWS_S3_BUCKET
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY

FEDERATIONCLOUD BASE
ARCADECLOUD_PUBLIC_URL
ARCADECLOUD_FEDERATION_URL
ARCADECLOUD_FEDERATION_ENABLED
ARCADECLOUD_FEDERATION_SEED_URL
```

## Configuración avanzada posterior

Al cambiar a **Servidor → Configuración avanzada** aparecen además:

### SMTP

```text
ARCADECLOUD_SMTP_HOST
ARCADECLOUD_SMTP_PORT
ARCADECLOUD_SMTP_SECURE
ARCADECLOUD_SMTP_USERNAME
ARCADECLOUD_SMTP_PASSWORD
ARCADECLOUD_SMTP_FROM_EMAIL
ARCADECLOUD_SMTP_FROM_NAME
ARCADECLOUD_SMTP_REPLY_TO
ARCADECLOUD_SMTP_BCC
ARCADECLOUD_SMTP_TIMEOUT
ARCADECLOUD_SMTP_DEBUG
```

### AWS avanzado

```text
AWS_SESSION_TOKEN
AWS_CONTROL_ACCESS_KEY_ID
AWS_CONTROL_SECRET_ACCESS_KEY
AWS_CONTROL_SESSION_TOKEN
```

### Mirror

```text
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL
ARCADECLOUD_FEDERATION_REPLICA_ROLE
ARCADECLOUD_FEDERATION_REPLICA_SCOPE
```

Valores normales de una copia:

```text
ROLE=mirror
SCOPE=all_allowed_resources
```

## Dónde se guarda cada dato

### Archivo principal de configuración

```text
/etc/arcadecloud-drive/runtime-env.json
```

Aquí viven las variables de:

- MySQL;
- AWS/S3;
- SMTP;
- FederationCloud base;
- mirror.

Las instalaciones nuevas deben usar este archivo como fuente administrada principal.

### Identidad FederationCloud

```text
/etc/arcadecloud-drive/federation-node.json
```

Permanece separada porque contiene material criptográfico privado:

- `node_name`;
- `node_id`;
- clave pública;
- clave privada;
- `payload_key`.

El instalador genera estos datos. Cambiar IP o dominio no reemplaza esta identidad.

### Primer superadmin

No se guarda en un archivo.

Destino:

```text
MySQL
tabla: Users
```

La contraseña sólo se conserva como hash.

### Bootstrap temporal

```text
/etc/arcadecloud-drive/bootstrap-auth.json
```

Se elimina al terminar el setup.

### Setup terminado

```text
/etc/arcadecloud-drive/setup.lock
```

### Helper administrativo

```text
/etc/arcadecloud-drive/admin-helper.json
/usr/local/sbin/arcadecloud-drive-admin
```

### Updater

```text
/etc/arcadecloud-drive/updater.json
/usr/local/sbin/arcadecloud-drive-updater
```

### Estado de finalización

```text
/var/lib/arcadecloud-drive/install-finalized.json
```

### Nginx

```text
/etc/nginx/conf.d/
```

### Certificados

```text
/etc/letsencrypt/live/<host>/
```

## Compatibilidad con instalaciones existentes

Archivos como:

```text
/etc/arcadecloud-drive/drive.env
/etc/arcadecloud-drive/smtp.env
/etc/arcadecloud-drive/federation.env
```

siguen siendo compatibles como `EnvironmentFile`, pero **no son la recomendación para instalaciones
nuevas**.

La precedencia sigue siendo:

```text
runtime-env.json
   -> entorno PHP-FPM/systemd
   -> defaults internos
```

Esto permite actualizar nodos existentes sin mover sus secretos de inmediato.

## Ejecutar el instalador

Desde un clon del repo:

```bash
sudo bash drive/bin/install_arcadecloud.sh
```

Si no puede detectar el usuario PHP-FPM:

```bash
sudo bash drive/bin/install_arcadecloud.sh --php-user=nginx
```

También puede desactivarse el bootstrap automático de FederationCloud:

```bash
sudo bash drive/bin/install_arcadecloud.sh --no-federation
```

## Regla de diseño

El instalador sigue estas reglas:

```text
Si puede detectarse -> no preguntar.
Si puede generarse -> no preguntar.
Si es avanzado -> no pedir durante setup.
Si es secreto -> no volver a mostrarlo después.
Si falla FederationCloud opcional -> no bloquear Drive.
Si existe identidad -> conservarla.
Si existe configuración -> no destruirla.
```

## Checklist antes de empezar

```text
[ ] servidor con sudo/root
[ ] PHP-FPM y Nginx disponibles
[ ] Git, PHP, Composer, Python 3 y curl
[ ] Certbot si se desea FederationCloud/HTTPS automático
[ ] MySQL accesible y con esquema ArcadeCloud
[ ] bucket S3 existente
[ ] credenciales AWS
[ ] correo y contraseña del primer superadmin
```

No guardes una copia con secretos reales dentro del repositorio.
