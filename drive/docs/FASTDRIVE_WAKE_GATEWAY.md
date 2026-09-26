# FastDrive wake gateway

## Objetivo

`fastdrive.esforzados.com` permanece apuntando al EC2 pequeño. El pequeño conserva TLS y actúa como proxy hacia la IPv4 privada de FastDrive.

- Gateway público: `fastdrive.esforzados.com`
- EC2 pequeño: `50.17.162.57`
- FastDrive privado: `172.31.14.35`
- FastDrive EC2: `i-01b1f1077d7471070`
- Región: `us-east-1`

Cuando FastDrive responde, Nginx pasa el tráfico normalmente al upstream privado.

Cuando el upstream no acepta conexión o expira (`502`/`504`), el gateway redirige al control administrativo del EC2 pequeño:

`https://drive.esforzados.com/fastdrive-control.php?gateway=1`

Ese control conserva las defensas existentes:

1. sesión autenticada;
2. `system_role=superadmin`;
3. CSRF;
4. contraseña actual del superadmin en cada intento;
5. Instance ID leído exclusivamente del entorno del servidor;
6. sólo se permite `StartInstances`, nunca seleccionar otro ID desde el navegador.

Después de que AWS acepte el arranque, el modo gateway reintenta automáticamente `https://fastdrive.esforzados.com/`. En cuanto el upstream privado vuelve a responder, Nginx entrega el `index.php` de FastDrive.

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
- intercepta únicamente `502` y `504`;
- ejecuta `nginx -t`;
- restaura el vhost anterior si la validación falla;
- recarga Nginx únicamente después de una configuración válida.

## Política de DNS

No cambiar el DNS a la IP pública de FastDrive cuando arranque. El dominio debe seguir apuntando al EC2 pequeño.

Esto evita depender de una IPv4 pública cambiante y mantiene una sola puerta de entrada TLS.
