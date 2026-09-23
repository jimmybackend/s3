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

Certbot puede estar disponible en el sistema, pero la instalación básica no depende de él. Sin dominio
ni endpoint HTTPS explícito, ArcadeCloud no intenta emitir certificados durante la finalización.

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

La identidad FederationCloud se genera y FederationCloud/ArcadeLink queda activo desde la instalación
básica cuando existe una IPv4 pública. HTTP sólo se admite en este modo cuando el host es una IP literal.
Si posteriormente se configura un dominio, FederationCloud exige HTTPS y conserva la misma identidad
criptográfica del nodo.

## Setup básico

Después de preparar servidor, Composer, helper administrativo, updater e identidad, /setup/ solicita únicamente:

~~~text
1. MySQL
2. AWS / S3
3. Primer superadmin
~~~

SMTP, tokens AWS, credenciales de control y mirrors permanecen en Configuración avanzada.

## Finalización

Después de cerrar correctamente /setup/:

~~~bash
sudo bash drive/bin/install_arcadecloud.sh --finalize
~~~

La finalización vuelve a ejecutar el preflight de forma idempotente, instala/verifica también el helper
de actualizaciones usado por **Acerca de** y cierra la instalación básica. Si FederationCloud está activo
sobre una IP literal HTTP, instala su timer de sincronización pero no intenta Certbot. Si el endpoint
FederationCloud usa HTTPS, además ejecuta la reconciliación HTTPS.

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
