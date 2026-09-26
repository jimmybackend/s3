# FastDrive wake gateway

## Objetivo

`fastdrive.esforzados.com` permanece apuntando al EC2 pequeño. El pequeño conserva TLS y actúa como proxy hacia la IPv4 privada de FastDrive.

- Gateway público: `fastdrive.esforzados.com`
- EC2 pequeño: `50.17.162.57`
- FastDrive privado: `172.31.14.35`
- FastDrive EC2: `i-01b1f1077d7471070`
- Región: `us-east-1`

Cuando FastDrive responde, Nginx pasa el tráfico normalmente al upstream privado.

Cuando el upstream no acepta conexión o expira (`502`/`504`), Nginx hace un fallback **interno** hacia `drive/fastdrive-wake.php` en el EC2 pequeño. El navegador permanece en `https://fastdrive.esforzados.com/`; no se redirige a `drive.esforzados.com`.

La pantalla local:

1. sólo es ejecutable desde el vhost FastDrive mediante un parámetro FastCGI interno;
2. usa CSRF;
3. acepta únicamente la contraseña de un usuario `Activo` con `system_role=superadmin`;
4. limita a cinco intentos fallidos por IP durante 15 minutos;
5. nunca registra la contraseña;
6. lee el Instance ID exclusivamente del entorno del servidor;
7. sólo permite `StartInstances`.

Después de que AWS acepte el arranque, la misma URL se reintenta automáticamente. Mientras FastDrive no responda, el pequeño sigue mostrando el estado de arranque. En cuanto `172.31.14.35` vuelve a responder, Nginx entrega el `index.php` del FastDrive grande sin cambiar de dominio.

## Instalación en el EC2 pequeño

Prerequisitos:

- DNS de `fastdrive.esforzados.com` apuntando al EC2 pequeño;
- certificado existente en `/etc/letsencrypt/live/fastdrive.esforzados.com/`;
- `ARCADECLOUD_FASTDRIVE_INSTANCE_ID` y `ARCADECLOUD_FASTDRIVE_REGION` configurados;
- conectividad privada entre el pequeño y `172.31.14.35`.

Ejecutar:

```bash
cd /var/www/arcadecloud-drive
sudo bash drive/bin/install_fastdrive_gateway.sh
```

El instalador:

- hace respaldo del vhost anterior;
- conserva HTTPS;
- mantiene el proxy hacia `172.31.14.35`;
- intercepta únicamente `502` y `504` mediante una ubicación Nginx interna;
- sirve la autorización localmente con PHP-FPM `127.0.0.1:9075`;
- ejecuta `nginx -t`;
- restaura el vhost anterior si la validación falla;
- recarga Nginx únicamente después de una configuración válida.

## Política de DNS

No cambiar el DNS a la IP pública de FastDrive cuando arranque. El dominio debe seguir apuntando al EC2 pequeño.

Esto evita depender de una IPv4 pública cambiante y mantiene una sola puerta de entrada TLS.
