# Terminal restringida del servidor

La sección **Terminal del servidor · superusuario** vive dentro de `drive/ec2.php` y se muestra únicamente cuando la sesión normal del Drive está autenticada como `superadmin`.

Entrar a `ec2.php` sólo con la contraseña privada de emergencia no habilita esta terminal.

## Límite de seguridad

No es una shell Linux arbitraria. El texto escrito por el navegador se compara contra una lista exacta y se transforma en un identificador interno. El helper privilegiado vuelve a validar ese identificador y ejecuta únicamente argv compilado con `proc_open(..., ['bypass_shell' => true])`.

No se admiten pipes, redirecciones, `;`, `&&`, rutas libres, descargas, scripts ni ejecutables indicados por el usuario.

## Comandos disponibles

- `help`
- `free -h`
- `df -h`
- `uptime`
- `ps aux --sort=-%mem`
- `systemctl status nginx`
- `systemctl status php-fpm-drive`
- `memory-clear`

`memory-clear` es una acción especial: vuelve a pedir la contraseña privada de las herramientas AWS, ejecuta `sync` y escribe `3` en `/proc/sys/vm/drop_caches`. No termina procesos. Libera page cache, dentries e inodes, por lo que puede aumentar temporalmente las lecturas de disco después de ejecutarse.

No debe programarse como mantenimiento periódico. Linux utiliza la RAM libre como caché y normalmente la recupera automáticamente cuando una aplicación la necesita.

## Helper privilegiado

La capacidad requiere helper versión 9 o posterior. Después de actualizar el repositorio, si la página indica que el helper está desactualizado, ejecuta el comando de reinstalación que la propia terminal muestra. El instalador copia el helper actual a:

```text
/usr/local/sbin/arcadecloud-drive-admin
```

El usuario PHP-FPM sólo conserva `sudo NOPASSWD` hacia ese helper concreto, no hacia una shell general.
