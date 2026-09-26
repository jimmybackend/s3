# Terminal restringida del servidor

La sección **Terminal del servidor · superusuario** vive dentro de `drive/ec2.php` y se muestra únicamente cuando la sesión normal del Drive está autenticada como `superadmin`.

Entrar a `ec2.php` sólo con la contraseña privada de emergencia no habilita esta terminal.

## Alcance local

La terminal nunca administra otro nodo por red. Todos los comandos se ejecutan en el servidor donde está instalado el helper y donde se abrió `ec2.php`.

Los comandos de repositorio usan exclusivamente el `app_root` guardado en `/etc/arcadecloud-drive/admin-helper.json`. No aceptan rutas escritas por el usuario.

## Límite de seguridad

No es una shell Linux arbitraria. El texto escrito por el navegador se compara contra una lista exacta y se transforma en un identificador interno. El helper privilegiado vuelve a validar ese identificador y ejecuta únicamente argv compilado con `proc_open(..., ['bypass_shell' => true])`.

No se admiten pipes, redirecciones, `;`, `&&`, rutas libres, descargas, scripts ni ejecutables indicados por el usuario.

## Comandos disponibles

### Terminal y repositorio

- `help`
- `clear` — sólo limpia la pantalla del navegador.
- `pwd` — muestra el `app_root` local.
- `ls -lah` — lista únicamente la raíz del repositorio instalado.
- `git status`
- `git log -10 --oneline`
- `du -sh .`

Los comandos Git fuerzan `safe.directory` únicamente al `app_root` configurado por el instalador.

### Servidor

- `uname -a`
- `free -h`
- `df -h`
- `uptime`
- `ps aux --sort=-%mem`
- `systemctl status nginx`
- `systemctl status php-fpm-drive`

### ArcadeCloud y tareas

- `arcadecloud services` — resume servicios locales `arcadecloud-*`, Nginx y PHP-FPM Drive.
- `arcadecloud timers` — muestra timers/tareas locales `arcadecloud-*`.

Entre las unidades que pueden aparecer están Federation sync/HTTPS, FederationDrop cleanup, Polly y Transcribe, según lo que realmente esté instalado en ese servidor.

### Logs

- `logs drive` — últimas 80 líneas de `/var/log/php-fpm-drive/error.log`.
- `logs nginx` — últimas 80 líneas de `/var/log/nginx/error.log`.
- `logs federation` — journal de `arcadecloud-federation-sync.service`.
- `logs polly` — journal de `arcadecloud-polly-reconcile.service`.
- `logs transcribe` — journal de `arcadecloud-transcribe-reconcile.service`.
- `logs drop` — journal de `arcadecloud-federation-drop-cleanup.service`.

Si un servicio o log no existe en el nodo actual, la terminal lo informa y no intenta consultar otro servidor.

### Mantenimiento

- `memory-clear`

`memory-clear` vuelve a pedir la contraseña privada de las herramientas AWS, ejecuta `sync` y escribe `3` en `/proc/sys/vm/drop_caches`. No termina procesos. Libera page cache, dentries e inodes, por lo que puede aumentar temporalmente las lecturas de disco después de ejecutarse.

No debe programarse como mantenimiento periódico. Linux utiliza la RAM libre como caché y normalmente la recupera automáticamente cuando una aplicación la necesita.

## Helper privilegiado

La consola ampliada requiere helper versión 10 o posterior. Después de actualizar el repositorio, si la página indica que el helper está desactualizado, ejecuta el comando de reinstalación que la propia terminal muestra. El instalador copia el helper actual a:

```text
/usr/local/sbin/arcadecloud-drive-admin
```

El usuario PHP-FPM sólo conserva `sudo NOPASSWD` hacia ese helper concreto, no hacia una shell general.
