# Reconciliador conservador de subidas local_put

El worker `bin/upload_task_reconcile.php` revisa cada cinco minutos (si se
instala el timer) un máximo de 20 subidas locales antiguas (más de 180 segundos)
con clave S3 generada por el servidor y guardada en UploadTaskStore.

Sólo marca una tarea `completed` cuando:
- existe una fila `FileS3` del **mismo usuario** y la **misma clave**;
- `HeadObject` confirma que el objeto existe en el bucket;
- coinciden el tamaño registrado, el tamaño S3 y, si se conoce, el tamaño de la tarea.

Nunca sube, borra, ni crea un registro FileS3; no fuerza a éxito los archivos
cuyo registro falta, ni omite el control de moderación del flujo normal.
No puede recuperar automáticamente subidas anteriores al despliegue que no
tengan `object_key` persistida, ni subidas multipart (`chunked`).

## Comprobación manual en EC2 (sin instalar timer)

```bash
cd /var/www/arcadecloud-drive
php -l drive/bin/upload_task_reconcile.php
php -l drive/src/Upload/UploadTaskReconciler.php
php drive/tests/upload_task_reconcile_contract.php
sudo -u apache php drive/bin/upload_task_reconcile.php
```

## Activación opcional del timer en EC2

Tras validar el comando anterior, copiar ambos archivos desde
`drive/deploy/systemd/` a `/etc/systemd/system/` y habilitar el timer:

```bash
sudo install -m 0644 drive/deploy/systemd/arcadecloud-upload-reconcile.service /etc/systemd/system/
sudo install -m 0644 drive/deploy/systemd/arcadecloud-upload-reconcile.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now arcadecloud-upload-reconcile.timer
systemctl list-timers --all | grep arcadecloud-upload
sudo journalctl -u arcadecloud-upload-reconcile.service -n 30 --no-pager
```

El timer se entrega como plantilla, no se instala automáticamente al hacer git pull.
Ajustar las rutas y el usuario si la instalación no coincide con la EC2 pequeña.
