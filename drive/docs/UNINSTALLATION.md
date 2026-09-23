# Desinstalación de ArcadeCloud Drive

Este documento describe el desinstalador local oficial:

```bash
sudo bash drive/bin/uninstall_arcadecloud.sh
```

## Objetivo

El desinstalador retira únicamente recursos locales administrados por ArcadeCloud. Está diseñado como
contraparte del instalador automático y evita borrar infraestructura externa o software compartido.

Antes de borrar nada puede ejecutarse:

```bash
sudo bash drive/bin/uninstall_arcadecloud.sh --dry-run
```

El modo real exige escribir `DESINSTALAR`, salvo que se use explícitamente `--yes`.

## Qué elimina

Cuando el recurso existe y puede demostrarse que pertenece a ArcadeCloud, elimina:

- servicios/timers FederationCloud;
- servicios opcionales de Polly, Transcribe y node-sync;
- `php-fpm-drive.service` sólo si contiene la marca del instalador;
- configuración PHP-FPM administrada por ArcadeCloud;
- vhosts Nginx administrados por ArcadeCloud;
- helper administrativo y updater bajo `/usr/local/sbin`;
- reglas sudoers específicas de ArcadeCloud;
- `/etc/arcadecloud-drive`, salvo `--keep-config`;
- `/var/lib/arcadecloud-drive`;
- logs PHP-FPM dedicados;
- entorno aislado Certbot de ArcadeCloud bajo `/opt/arcadecloud-certbot`;
- enlace `/usr/local/bin/arcadecloud-certbot` cuando es realmente un symlink;
- caches temporales conocidas de ArcadeCloud;
- el checkout Git local, salvo `--keep-repository`.

Si FederationCloud creó un certificado para una IP pública y existe el estado administrado que lo
demuestra, intenta eliminar ese certificado mediante Certbot. Los certificados de dominio se conservan
por defecto porque podrían ser compartidos con otra aplicación. `--keep-ip-certificate` conserva
también el certificado IP.

## Qué nunca elimina

El desinstalador no borra:

- bases de datos o tablas MySQL remotas;
- usuarios MySQL;
- buckets S3;
- objetos S3;
- claves o usuarios IAM;
- DNS;
- Security Groups;
- instancias EC2;
- paquetes compartidos como PHP, Nginx, Git, curl, Composer o Python;
- certificados de dominio no demostrablemente exclusivos de ArcadeCloud.

La razón es simple: estos recursos pueden ser compartidos por otras aplicaciones y el instalador no
puede demostrar que ArcadeCloud sea su único propietario.

## FederationCloud después de desinstalar

Al detenerse el nodo deja de actualizar `LastSeen`. El directorio activo usa una ventana de presencia,
por lo que deja de mostrarse como nodo activo cuando esa ventana vence. El registro histórico puede
seguir existiendo en el seed; no contiene secretos.

## Opciones

```text
--dry-run
--yes
--app-root=RUTA
--keep-repository
--keep-config
--keep-ip-certificate
```

Ejemplo para una EC2 desechable:

```bash
cd /var/www/arcadecloud-drive
sudo bash drive/bin/uninstall_arcadecloud.sh --dry-run
sudo bash drive/bin/uninstall_arcadecloud.sh --yes
```

Después del segundo comando, el checkout puede haber sido eliminado, por lo que no debe ejecutarse desde
esa ruta ningún comando posterior que dependa de sus archivos.

## Salvaguardas

- rechaza `APP_ROOT` peligrosamente amplio;
- sólo elimina Nginx/PHP-FPM cuando encuentra la marca de ArcadeCloud;
- sólo elimina unidades systemd cuyos archivos se identifican como ArcadeCloud;
- no usa comodines para borrar configuración sensible;
- valida Nginx antes de recargarlo después de retirar los vhosts;
- `--dry-run` permite inspeccionar todas las acciones antes de ejecutarlas.
