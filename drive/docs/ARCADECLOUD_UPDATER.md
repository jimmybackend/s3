# ArcadeCloud Updater

ArcadeCloud Drive puede comprobar y aplicar actualizaciones del repositorio desde **Acerca de**, únicamente para una sesión `superadmin`.

## Principio

El updater es manual. No instala timers, no hace polling y no consulta GitHub al abrir el Drive.

Flujo:

```text
Acerca de
  -> Buscar actualizaciones
  -> una sola consulta remota
  -> mostrar commit instalado, commit disponible y descripción de cambios
  -> el superadmin decide
  -> Actualizar ahora
  -> reautenticación
  -> fast-forward seguro de main
```

La acción de comprobar no instala nada. La acción de actualizar es independiente y requiere confirmación del superusuario.

## Seguridad

La aplicación web no recibe un shell privilegiado. Se instala un helper root mínimo:

```text
/usr/local/sbin/arcadecloud-drive-updater
```

El `sudoers` permite al usuario real de PHP-FPM ejecutar únicamente ese helper. El helper acepta sólo:

```text
status
check
update <sha-remoto-esperado>
```

No acepta comandos, rutas, ramas ni URLs procedentes del navegador.

Antes de actualizar exige:

- checkout en la rama estable configurada (`main` por defecto);
- working tree limpio;
- cero commits locales por delante del remoto;
- SHA remoto idéntico al que el superadmin revisó;
- actualización posible mediante `git merge --ff-only`.

Si alguna condición falla, no toca el checkout.

## Consulta remota

Primero intenta el `origin` ya configurado en el servidor, ejecutando Git como el propietario del checkout. Así conserva SSH keys o credenciales ya existentes del nodo.

Si `origin` no puede consultarse, usa como fallback público:

```text
https://github.com/jimmybackend/s3.git
```

Esto permite a nodos sin credenciales privadas comprobar y actualizar una instalación pública de ArcadeCloud.

## Instalación inicial

Después de desplegar la versión que introduce ArcadeCloud Updater hay que instalar el helper privilegiado una sola vez:

```bash
cd /var/www/arcadecloud-drive
sudo bash drive/bin/install_arcadecloud_updater.sh --php-service=php-fpm-drive
```

El instalador intenta reutilizar automáticamente `php_user` desde:

```text
/etc/arcadecloud-drive/admin-helper.json
```

Si no puede detectarlo:

```bash
sudo bash drive/bin/install_arcadecloud_updater.sh \
  --php-user=nginx \
  --php-service=php-fpm-drive
```

Opciones disponibles:

```text
--repo-root=/ruta/del/checkout
--repo-user=usuario-propietario-git
--php-user=usuario-php-fpm
--branch=main
--public-url=https://github.com/jimmybackend/s3.git
--php-service=php-fpm-drive
```

Por defecto `repo-root` se obtiene a partir de la ubicación del instalador y `repo-user` del propietario del checkout.

## Reinicio de PHP

Tras un update exitoso, si `php_service` está configurado y `systemd-run` está disponible, el helper programa un reinicio de PHP-FPM con unos segundos de retraso para permitir que la respuesta HTTP llegue al navegador.

No reinicia Nginx ni ejecuta migraciones de base de datos automáticamente.

## Interfaz

La opción aparece dentro de **Acerca de** sólo para `superadmin`:

```text
Actualizaciones de ArcadeCloud
No se han buscado actualizaciones en esta sesión.

[ Buscar actualizaciones ]
```

Si hay cambios:

```text
Instalado: e353121 · ...
Disponible: abc1234 · ...
Cambios encontrados:
- ...
- ...

Contraseña actual de superusuario
[ Actualizar ahora ]
```

No existe consulta automática al repositorio.
