# Instalador automático de ArcadeCloud Drive

Estado: **22 de septiembre de 2026**.

Este documento define el comportamiento del instalador autónomo para una máquina nueva. La automatización
de paquetes del sistema está soportada actualmente en **Amazon Linux 2023**.

## Objetivo

En una EC2 nueva el operador no debe instalar manualmente PHP, PHP-FPM, Nginx, Composer, Git o Certbot
antes de ArcadeCloud. El instalador debe detectar lo que ya existe, conservar una instalación compatible,
instalar únicamente lo que falte, crear la configuración propia de ArcadeCloud sin sobrescribir archivos
ajenos, validar PHP-FPM y Nginx, ejecutar Composer y abrir el setup web de tres pasos.

La configuración de MySQL, AWS/S3 y el primer superadmin sigue realizándose en /setup/. El instalador
genera la activación temporal y muestra directamente la URL completa lista para abrir; el operador no
debe construir manualmente `?token=...`.

## EC2 completamente vacía

Amazon Linux 2023 incluye normalmente curl. Cuando todavía no existe Git ni el repositorio, descarga
primero el bootstrap pequeño:

~~~bash
curl -fsSLo /tmp/bootstrap_arcadecloud.sh \
  https://raw.githubusercontent.com/jimmybackend/s3/main/bootstrap_arcadecloud.sh

sudo bash /tmp/bootstrap_arcadecloud.sh
~~~

Por seguridad, el operador puede revisar el archivo descargado antes de ejecutarlo.

El bootstrap:

- comprueba que corre como root/sudo;
- instala Git con DNF si falta;
- crea /var/www/arcadecloud-drive;
- clona jimmybackend/s3, rama main;
- si el checkout ya existe, rechaza un árbol con cambios locales;
- actualiza sólo mediante git pull --ff-only;
- muestra el commit que se instalará;
- entrega el control a drive/bin/install_arcadecloud.sh.

No almacena credenciales ni crea identidades FederationCloud por sí mismo.

## Cuando el repositorio ya está clonado

~~~bash
cd /var/www/arcadecloud-drive
sudo bash drive/bin/install_arcadecloud.sh
~~~

La fase normal llama automáticamente a drive/bin/install_arcadecloud_server.sh antes de exigir las
dependencias.

## Dependencias administradas

En Amazon Linux 2023 el preparador verifica primero si ya existen los comandos y sólo instala el paquete
cuando el comando falta:

~~~text
curl
git
nginx
python3
tar
unzip
Composer
Certbot              (si está disponible)
plugin Nginx Certbot (si está disponible)
~~~

Esto evita reemplazar proveedores válidos del sistema. En particular, las AMI de AL2023 incluyen
normalmente el comando `curl` mediante `curl-minimal`; ArcadeCloud lo acepta y no intenta sustituirlo
por el paquete completo `curl`.

PHP es especial en AL2023 porque los paquetes están versionados. El instalador detecta automáticamente
la familia más nueva disponible, en este orden:

~~~text
php8.5
php8.4
php8.3
php8.2
php8.1
~~~

y usa la misma familia para CLI, FPM, mysqlnd, mbstring, XML, GD, process y opcache. No mezcla módulos
de distintas ramas PHP.

No fija una versión PHP inventada. DNF resuelve la versión publicada por los repositorios configurados
del sistema.

Si Composer no existe como paquete del sistema, se descarga el instalador oficial de Composer y se
valida su SHA-384 antes de instalarlo en /usr/local/bin/composer. Las comprobaciones de Composer que
corren bajo sudo establecen COMPOSER_ALLOW_SUPERUSER=1 de forma explícita para que el instalador no
se detenga esperando una respuesta interactiva.

Para FederationCloud global, el nodo debe publicar un endpoint HTTPS verificable. Si el Certbot del
sistema ya es 5.4 o superior se reutiliza. Si es más antiguo, ArcadeCloud instala un Certbot moderno
aislado bajo `/opt/arcadecloud-certbot` y lo expone como
`/usr/local/bin/arcadecloud-certbot`, sin reemplazar el paquete del sistema.

Amazon Linux 2023 conserva `/usr/bin/python3` en Python 3.9 por compatibilidad del sistema. Como
Certbot 5.4+ requiere Python 3.10 o superior, ArcadeCloud no cambia ese intérprete del sistema: selecciona
un Python versionado compatible y, si hace falta, instala `python3.11` (y su paquete pip disponible)
en paralelo. El entorno virtual de Certbot se crea con ese intérprete aislado. Esto permite obtener
certificados HTTPS de IP pública durante la finalización cuando el nodo no tiene dominio.

## PHP-FPM

El instalador crea un servicio y pool dedicados:

~~~text
/etc/systemd/system/php-fpm-drive.service
/etc/php-fpm-drive.conf
/etc/php-fpm-drive.d/arcadecloud-drive.conf
~~~

con escucha:

~~~text
127.0.0.1:9075
~~~

El usuario del pool se decide en este orden:

1. --php-user=USUARIO, si fue indicado explícitamente;
2. configuración existente del pool ArcadeCloud;
3. usuario del pool www;
4. usuario nginx;
5. usuario apache.

Nunca acepta root.

Si 127.0.0.1:9075 ya pertenece a otro pool no administrado por ArcadeCloud, el instalador se detiene
en lugar de sobrescribirlo.

Después valida la configuración con PHP-FPM y activa php-fpm-drive.service. Si ya existe un
php-fpm-drive no administrado por el instalador, sólo lo reutiliza cuando su configuración es válida,
usa un usuario no-root y escucha en 127.0.0.1:9075; de lo contrario se detiene sin sobrescribirlo.

## Nginx

El instalador crea únicamente:

~~~text
/etc/nginx/conf.d/arcadecloud-drive.conf
~~~

y lo marca como archivo administrado por ArcadeCloud.

No sobrescribe un archivo con ese nombre si ya existe y no contiene la marca del instalador.

El vhost sirve /var/www/arcadecloud-drive/drive, escucha HTTP en puerto 80, usa la IPv4 pública como
server_name cuando puede detectarla y envía PHP al pool 127.0.0.1:9075.

También establece client_max_body_size 32m y bloquea acceso web directo a src/, bin/, vendor/, dotfiles
y archivos sensibles comunes. Antes de activar o recargar Nginx ejecuta nginx -t. Si falla, restaura
el estado anterior.

## Endpoint inicial sin dominio

En una máquina con IP pública pero todavía sin certificado, el Drive se presenta inicialmente por:

~~~text
http://IP_PUBLICA/
http://IP_PUBLICA/setup/
~~~

Ese estado ya es válido para usar ArcadeCloud Drive. La instalación básica guarda:

~~~text
ARCADECLOUD_PUBLIC_URL=http://IP_PUBLICA
ARCADECLOUD_FEDERATION_URL=http://IP_PUBLICA/federationcloud/
ARCADECLOUD_FEDERATION_ENABLED=true
~~~

La identidad FederationCloud se genera durante esta fase, pero el HTTP por IP es únicamente el endpoint
de bootstrap para completar `/setup/`. Al finalizar, ArcadeCloud obtiene HTTPS para la IP pública,
actualiza `PUBLIC_URL` y `FEDERATION_URL`, presenta el descriptor firmado al seed primario y exige
confirmación del directorio global. Si posteriormente se configura un dominio, la misma identidad
criptográfica se conserva.

## Setup básico

Después de preparar servidor, Composer, helper administrativo, updater e identidad, /setup/ solicita únicamente:

~~~text
1. MySQL
2. AWS / S3
3. Primer superadmin
~~~

SMTP, tokens AWS, credenciales de control y mirrors permanecen en Configuración avanzada.

### SQL canónico

`adbbmis1_Cloud.sql` en la raíz del repositorio es el único archivo SQL oficial. Una DB nueva puede
cargarse completa desde ese archivo. Para una DB existente, la finalización nunca ejecuta el dump
completo: `federation_catalog_migrate.php` extrae únicamente la sección
`ARCADECLOUD:FEDERATION_SCHEMA`, que es idempotente e incluye todas las tablas FederationCloud,
entre ellas `FederationEvents`.

## Finalización automática

Al completar el tercer paso de /setup/, el helper privilegiado ejecuta automáticamente una acción fija
de finalización. PHP-FPM no recibe una shell arbitraria: sólo puede solicitar al helper la operación
compilada `bootstrap-finalize`, que usa el `app_root` y usuario PHP-FPM guardados por root durante
la instalación.

La finalización instala/verifica también el helper de actualizaciones usado por **Acerca de** y completa
FederationCloud en este orden:

1. instala el reconciliador HTTPS sin ejecutarlo todavía;
2. crea/actualiza y verifica idempotentemente el esquema local FederationCloud;
3. obtiene o valida HTTPS para dominio o IP pública;
4. actualiza el endpoint firmado sin regenerar la identidad;
5. ejecuta un registro estricto contra el seed primario;
6. exige que el directorio global responda;
7. instala el timer de sincronización;
8. ejecuta una primera sincronización.

La migración ocurre antes de arrancar el reconciliador HTTPS porque ese servicio también intenta
republicar el descriptor al terminar. Así un nodo nuevo nunca intenta escribir su primer evento
`node.upsert` antes de que exista `FederationEvents`.

Por tanto, una instalación básica con endpoint público no se declara terminada sólo porque exista la
identidad local: debe haber sido presentada y confirmada por el directorio global. El helper crea
`setup.lock` y retira el supervisor temporal únicamente después de esa confirmación. Si algo falla,
el setup permanece abierto y el tercer paso puede reintentarse sin duplicar el superadmin.

La opción administrativa `--finalize` se conserva para recuperación y compatibilidad con instalaciones
anteriores que ya tengan el setup cerrado.

## Idempotencia y seguridad

El instalador está diseñado para poder reanudarse. En particular:

- no regenera una identidad FederationCloud existente;
- no sobrescribe un checkout Git con cambios locales desde el bootstrap;
- no sobrescribe un vhost Nginx ajeno;
- no roba un puerto PHP-FPM ya asignado a otro pool;
- no imprime credenciales DB/AWS;
- no guarda secretos dentro de Git;
- usa git pull --ff-only;
- valida Composer antes de instalarlo desde su instalador oficial;
- valida PHP-FPM y Nginx antes de continuar.

## Qué sigue siendo externo al instalador

El sistema operativo no puede modificar por sí mismo la configuración de red de la cuenta AWS. Antes de
probar desde Internet, el Security Group de la EC2 debe permitir deliberadamente los puertos requeridos:

~~~text
22/tcp  SSH
80/tcp  HTTP
443/tcp HTTPS
~~~

## Opciones para administradores

~~~text
--php-user=USUARIO
--skip-composer
--skip-certbot
--skip-system-bootstrap
--app-root=RUTA
--finalize
~~~

--skip-system-bootstrap existe para servidores administrados manualmente; no es la opción recomendada
para una EC2 nueva.


## Desinstalación

El repositorio incluye `drive/bin/uninstall_arcadecloud.sh` para retirar de forma segura la instalación
local. Antes de ejecutar el borrado real se recomienda:

~~~bash
sudo bash drive/bin/uninstall_arcadecloud.sh --dry-run
~~~

El desinstalador elimina servicios, timers, configuración local, helpers, runtime, identidad local,
entorno Certbot administrado y checkout cuando puede demostrar que pertenecen a ArcadeCloud. No borra
MySQL/S3 remotos ni paquetes compartidos del sistema. Consulta `drive/docs/UNINSTALLATION.md`.

## Roles de nodo y reconciliación de servicios

ArcadeCloud distingue tres roles persistidos en `runtime-env.json`:

- `web`: nodo coordinador/web. Es el valor seguro por defecto y es el recomendado para `drive.esforzados.com` si corre en una t3.micro.
- `media-worker`: nodo dedicado a FFmpeg/FFprobe. No ejecuta los reconciliadores web de Polly/Transcribe.
- `combined`: web + worker multimedia en la misma EC2, útil sólo cuando la máquina tiene capacidad suficiente.

Ejemplo para el nodo principal:

```bash
sudo bash drive/bin/install_arcadecloud.sh \
  --node-role=web \
  --media-worker-instance-id=i-0123456789abcdef0 \
  --media-worker-region=us-east-1 \
  --media-worker-hourly-usd=0.12345678 \
  --media-worker-idle-grace-seconds=300
```

Ejemplo para la EC2 dedicada:

```bash
sudo bash drive/bin/install_arcadecloud.sh \
  --node-role=media-worker \
  --federation-dynamic-ip
```

El instalador no activa FFmpeg en un nodo `web`. Sólo `media-worker` y `combined` instalan/activan `arcadecloud-media-worker.service`.

### Reconciliar una instalación existente

```bash
sudo bash drive/bin/install_arcadecloud.sh \
  --reconcile \
  --app-root=/var/www/arcadecloud-drive
```

La reconciliación es idempotente. Actualiza los helpers privilegiados, timers y servicios correspondientes al rol, reinicia `php-fpm-drive` cuando está activo y recarga Nginx sólo si `nginx -t` pasa.

### Actualizador de “Acerca de”

Las versiones nuevas de ArcadeCloud Updater hacen dos pasos:

1. `git fetch` + fast-forward seguro de `main`;
2. reconciliación de los servicios administrados con el código recién actualizado.

Si Git se actualiza pero la reconciliación de un servicio falla, el updater informa que **el código sí quedó actualizado** y muestra una advertencia de servicio, en vez de fingir que el fast-forward no ocurrió.

Para una instalación que todavía tenga el updater anterior a esta función, hay una transición única: después de recibir por primera vez esta versión se ejecuta `install_arcadecloud.sh --reconcile`. A partir de ahí las siguientes actualizaciones desde “Acerca de” hacen ambos pasos automáticamente.

## Configuración canónica

Las opciones del nodo multimedia usan exclusivamente:

```text
/etc/arcadecloud-drive/runtime-env.json
```

Variables administradas:

```text
ARCADECLOUD_NODE_ROLE
ARCADECLOUD_MEDIA_WORKER
ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID
ARCADECLOUD_MEDIA_WORKER_REGION
ARCADECLOUD_MEDIA_WORKER_HOURLY_USD
ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS
ARCADECLOUD_FEDERATION_DYNAMIC_IP
```

No debe mantenerse una segunda configuración multimedia en `drive.env`.
