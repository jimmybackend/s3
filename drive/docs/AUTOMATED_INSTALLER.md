# Instalador automático de ArcadeCloud Drive

Estado: **22 de septiembre de 2026**.

Este documento define el comportamiento del instalador autónomo para una máquina nueva. La automatización
de paquetes del sistema está soportada actualmente en **Amazon Linux 2023**.

## Objetivo

En una EC2 nueva el operador no debe instalar manualmente PHP, PHP-FPM, Nginx, Composer, Git o Certbot
antes de ArcadeCloud. El instalador debe detectar lo que ya existe, conservar una instalación compatible,
instalar únicamente lo que falte, crear la configuración propia de ArcadeCloud sin sobrescribir archivos
ajenos, validar PHP-FPM y Nginx, ejecutar Composer y abrir el setup web de tres pasos.

La configuración de MySQL, AWS/S3 y el primer superadmin sigue realizándose en /setup/.

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

En Amazon Linux 2023 el preparador verifica e instala, cuando falten:

~~~text
curl
git
nginx
php
php-cli
php-fpm
php-mysqlnd
php-mbstring
php-xml
php-gd
php-process
python3
tar
unzip
php-opcache          (si está disponible)
Composer
Certbot              (si está disponible)
plugin Nginx Certbot (si está disponible)
~~~

No fija una versión PHP inventada. DNF resuelve la versión publicada por los repositorios configurados
del sistema.

Si Composer no existe como paquete del sistema, se descarga el instalador oficial de Composer y se
valida su SHA-384 antes de instalarlo en /usr/local/bin/composer.

Si Certbot no está disponible, la instalación básica del Drive no falla. HTTPS FederationCloud queda
pendiente para la fase de finalización.

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

FederationCloud conserva como objetivo:

~~~text
https://IP_PUBLICA
https://IP_PUBLICA/federationcloud/
~~~

El instalador no desactiva verificación TLS, no crea certificados autofirmados para fingir éxito y no
declara el endpoint FederationCloud listo hasta que el reconciliador HTTPS lo valide.

## Setup básico

Después de preparar servidor, Composer, helper e identidad, /setup/ solicita únicamente:

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

La finalización vuelve a ejecutar el preflight de forma idempotente y después instala/verifica workers,
timers y reconciliación HTTPS.

Si HTTPS no puede obtenerse o validarse:

~~~text
Drive: instalado
Identidad FederationCloud: conservada
Endpoint FederationCloud: pendiente HTTPS
~~~

Eso no destruye la instalación básica.

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
