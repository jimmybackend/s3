# FederationCloud HTTPS automático: dominio o IP pública

FederationCloud separa la **identidad criptográfica del nodo** de su dirección de red. El `node_id` y las claves Ed25519/XChaCha20-Poly1305 permanecen estables aunque cambie la IP pública o el nodo migre después a un dominio.

## Regla de selección

`FederationEndpointResolver` usa esta prioridad:

1. si `ARCADECLOUD_FEDERATION_URL` o `ARCADECLOUD_PUBLIC_URL` contiene un hostname DNS, el nodo trabaja en modo **domain**;
2. si no existe dominio, intenta obtener la IPv4 pública actual de EC2 mediante **IMDSv2**;
3. si IMDSv2 no está disponible, una IPv4 pública ya configurada se usa únicamente como fallback;
4. nunca se publica una IP privada o reservada como endpoint FederationCloud.

Resultado canónico:

```text
domain:
  ARCADECLOUD_PUBLIC_URL=https://nodo.example.com
  ARCADECLOUD_FEDERATION_URL=https://nodo.example.com/federationcloud/

dynamic_ip:
  ARCADECLOUD_PUBLIC_URL=https://18.222.10.20
  ARCADECLOUD_FEDERATION_URL=https://18.222.10.20/federationcloud/
```

Un dominio siempre tiene prioridad sobre una IP detectada. Para cambiar deliberadamente un nodo de dominio a IP dinámica deben retirarse primero los hostnames DNS de ambas URLs administradas.

## Certificados

### Modo domain

Se reutiliza Certbot con el plugin oficial de Nginx. El reconciliador solicita/renueva el certificado del hostname y deja que Certbot instale el certificado en el vhost Nginx existente.

### Modo dynamic_ip

Los certificados para IP requieren:

- Certbot 5.4 o superior;
- `--preferred-profile shortlived`;
- `--ip-address <IPv4 pública>`;
- autenticación `webroot`;
- puertos 80 y 443 alcanzables desde Internet.

El certificado IP no se instala automáticamente mediante el plugin Nginx. ArcadeCloud crea únicamente el vhost gestionado:

```text
/etc/nginx/conf.d/arcadecloud-federation-ip.conf
```

Ese vhost:

- sirve `/.well-known/acme-challenge/` desde el webroot del Drive en puerto 80;
- redirige el resto de HTTP a HTTPS;
- carga el certificado desde `/etc/letsencrypt/live/<IP>/`;
- recibe HTTPS en la IP pública;
- hace proxy únicamente al backend HTTP local `127.0.0.1:80`;
- nunca coloca credenciales AWS, DB, sesiones ni claves FederationCloud en Nginx.

El `Host` usado hacia el backend local es configurable durante la instalación mediante `--backend-host=`. El valor por defecto es `localhost` para evitar que la petición HTTPS vuelva a caer en el mismo vhost IP y produzca un bucle.

## EC2 con IP variable

Al detener una instancia EC2 sin Elastic IP, AWS puede asignar otra IPv4 pública en el siguiente arranque. El servicio:

```text
arcadecloud-federation-https.service
```

se ejecuta después de `network-online.target` y Nginx. Consulta IMDSv2, compara la dirección actual y, cuando corresponde:

```text
IP antigua
  -> detectar IP nueva
  -> emitir certificado para IP nueva
  -> actualizar vhost Nginx gestionado
  -> actualizar ARCADECLOUD_PUBLIC_URL
  -> actualizar ARCADECLOUD_FEDERATION_URL
  -> firmar descriptor con la misma identidad del nodo
  -> volver a anunciar el nodo a FederationCloud
```

El `node_id` no cambia. Sólo cambia el endpoint firmado.

## Registro FederationCloud y entorno CLI

Después de actualizar el endpoint, el reconciliador ejecuta `drive/bin/federation_endpoint_refresh.php` con el **mismo usuario real del pool PHP-FPM del Drive**. El comando reutiliza `FederationDirectoryService` y la identidad existente.

No debe suponerse que Nginx y PHP-FPM usan el mismo usuario. Por ejemplo, en Amazon Linux Nginx puede ejecutar como `nginx` mientras el pool PHP-FPM `www` ejecuta sus workers como `apache`. Antes de instalar comprueba el usuario real:

```bash
ps -eo user=,comm=,args= | grep '[p]hp-fpm'
```

El servicio systemd también carga los mismos EnvironmentFile opcionales que los demás workers FederationCloud:

```text
/etc/arcadecloud-drive/drive.env
/etc/arcadecloud-drive/federation.env
```

Esto es necesario porque un proceso CLI no hereda automáticamente el entorno privado de PHP-FPM. Sin esos archivos puede aparecer un error de `DB_HOST`, `DB_USER`, `DB_PASSWORD` o `DB_NAME` aunque el Drive web funcione correctamente.

Los paths pueden cambiarse al instalar:

```text
--drive-env=/ruta/privada/drive.env
--federation-env=/ruta/privada/federation.env
```

`runtime-env.json` mantiene su precedencia normal a través de `app_bootstrap.php`. Si el archivo ya existe, el instalador **no cambia automáticamente su propietario ni grupo**: verifica que el usuario elegido pueda leerlo y falla de forma segura si no puede. Así un `--run-user` equivocado no rompe el pool PHP-FPM existente.

Si la identidad todavía no existe, el proceso termina con `SKIP` y HTTPS queda preparado para que posteriormente se cree el nodo. Si el directorio remoto está temporalmente inaccesible, el endpoint local permanece válido y el timer vuelve a intentarlo.

## systemd

Primero identifica el usuario PHP-FPM real. Ejemplo para un pool `www` que corre como `apache`:

```bash
sudo bash drive/bin/install_federation_https_service.sh \
  --run-user=apache \
  --app-root=/var/www/arcadecloud-drive
```

En instalaciones cuyo pool realmente corre como `nginx`, usa `--run-user=nginx`.

El instalador crea:

```text
/etc/systemd/system/arcadecloud-federation-https.service
/etc/systemd/system/arcadecloud-federation-https.timer
```

Por seguridad, el instalador **no ejecuta Certbot y tampoco habilita el timer**. Primero valida sintaxis PHP y `nginx -t`. Después el operador hace una primera reconciliación explícita:

```bash
sudo systemctl start arcadecloud-federation-https.service
sudo systemctl status arcadecloud-federation-https.service --no-pager
```

Sólo cuando esa prueba termina correctamente se habilita la automatización:

```bash
sudo systemctl enable --now arcadecloud-federation-https.timer
systemctl list-timers arcadecloud-federation-https.timer --no-pager
```

El timer usa `OnBootSec=45s` para reconciliar poco después del arranque y `OnUnitInactiveSec=12h` para programar la siguiente ejecución aproximadamente 12 horas después de que finaliza el servicio `oneshot`. Se usa `RandomizedDelaySec=10m`, por lo que la hora exacta puede desplazarse dentro de esa ventana. Esta forma evita que un `oneshot` quede en estado `elapsed` sin una próxima ejecución. Certbot decide si un certificado existente necesita renovación.

## Estado observable

El último resultado no sensible queda en:

```text
/var/lib/arcadecloud-drive/federation-https-state.json
```

Contiene solamente modo, host, URLs públicas, IP detectada, ruta pública del certificado y fecha de actualización. No contiene claves privadas, payload keys, credenciales AWS, contraseñas ni sesiones.

## Requisitos operativos

- Nginx debe estar instalado y activo.
- Para `domain`, el hostname debe resolver al servidor y el plugin Nginx de Certbot debe estar disponible.
- Para `dynamic_ip`, Certbot debe ser 5.4+ y el webroot indicado debe corresponder al contenido servido por HTTP.
- El Security Group debe permitir TCP 80 para HTTP-01 y TCP 443 para HTTPS.
- El backend local en `127.0.0.1:80` debe servir ArcadeCloud para el `Host` configurado con `--backend-host`.
- Los nodos que actúan como seeds deberían preferir un dominio o endpoint estable, porque otros nodos necesitan una dirección confiable para descubrir el seed inicial.

## Límite deliberado

La automatización actual cubre IPv4 pública de EC2. FederationCloud puede seguir validando URLs HTTPS, pero el reconciliador no intenta todavía administrar certificados IPv6 ni modificar Security Groups, Route 53, firewalld o reglas de red.
