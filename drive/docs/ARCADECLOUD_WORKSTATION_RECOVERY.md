# ArcadeCloud Workstation / Guacamole — Runbook de recuperación

> Estado validado en producción: 2026-10-08  
> Rama: `main`  
> Punto de referencia probado: `a47e06c` (`Disable Guacamole RDP GFX for XRDP compatibility`)

Este documento deja registrado cómo está armada la Workstation remota de ArcadeCloud, qué persiste fuera de los contenedores, qué puertos/socket usa cada componente y cómo reconstruir o diagnosticar el servicio sin depender de imágenes manuales antiguas.

## 1. Regla principal

La fuente de verdad es el repositorio.

No se debe restaurar la Workstation a partir de una imagen Docker manual antigua. La imagen oficial se reconstruye desde:

```
drive/docker/workstation/
```

mediante:

```bash
sudo bash drive/bin/install_workstation_node.sh \
  --app-root=/var/www/arcadecloud-drive \
  --reconcile
```

Guacamole se reconcilia con:

```bash
sudo bash drive/bin/install_guacamole_node.sh
```

## 2. Host y rutas persistentes

Host validado:

- Amazon Linux 2023 x86_64.
- Docker administrado por systemd.
- Checkout de producción: `/var/www/arcadecloud-drive`.

Persistencia de Workstation:

```
/var/lib/arcadecloud-office/
├── phase1-workspace/          -> /workspace
│   └── sessions/
└── home/arcade/              -> /home/arcade
    ├── Projects/
    ├── Downloads/
    ├── .config/
    └── .gitconfig
```

La reconstrucción del contenedor NO debe borrar estas rutas.

Credenciales/configuración local:

```
/etc/arcadecloud-drive/workstation.env
/etc/arcadecloud-drive/rdp.env
```

`rdp.env` contiene `ARCADECLOUD_RDP_PASSWORD`. No imprimir ni copiar ese secreto a logs.

Persistencia de Guacamole:

```
/var/lib/arcadecloud-guacamole/guac.env
Docker volume: arcadecloud-guac-mysql
```

`guac.env` contiene las credenciales de la base MySQL de Guacamole. Si el volumen MySQL ya existe y falta este archivo, el instalador aborta deliberadamente para no regenerar credenciales incompatibles.

## 3. Contenedores e imágenes

Contenedores esperados:

```
arcadecloud-workstation
arcadecloud-guacamole
arcadecloud-guacd
arcadecloud-guac-db
```

Imágenes principales:

```
arcadecloud/workstation:phase1
guacamole/guacamole:1.6.0
guacamole/guacd:1.6.0
mysql:8.4
```

La red Docker compartida es:

```
arcadecloud-office
```

## 4. Puertos, sockets y flujo de red

### noVNC / XFCE

```
navegador
  -> Nginx/gateway Office
  -> host 127.0.0.1:6080
  -> contenedor arcadecloud-workstation:6080
  -> websockify
  -> 127.0.0.1:5901 dentro del contenedor
  -> TigerVNC display :1
  -> XFCE
```

TigerVNC se inicia con:

```
-localhost yes
-SecurityTypes None
```

El puerto VNC 5901 no se publica al host ni a Internet.

### Guacamole / RDP

```
navegador
  -> /guacamole/
  -> Nginx
  -> host 127.0.0.1:8085
  -> arcadecloud-guacamole:8080
  -> arcadecloud-guacd:4822
  -> arcadecloud-workstation:3389
  -> XRDP
  -> Xorg/xorgxrdp
  -> XFCE
```

XRDP no se publica al host. Es accesible sólo dentro de la red Docker `arcadecloud-office`.

Socket/ruta usada por XRDP para canales:

```
/run/xrdp/sockdir
```

Variables de la sesión RDP que se han observado:

```
XRDP_SOCKET_PATH=/run/xrdp/sockdir
XRDP_PULSE_SINK_SOCKET=xrdp_chansrv_audio_out_socket_<display>
XRDP_PULSE_SOURCE_SOCKET=xrdp_chansrv_audio_in_socket_<display>
```

Una sesión RDP normal suele aparecer como `:10` o superior. noVNC/TigerVNC usa `:1`.

### PipeWire / audio

Runtime del usuario dentro del contenedor:

```
/run/user/10001
```

La Workstation crea:

```
XDG_RUNTIME_DIR=/run/user/10001
PIPEWIRE_RUNTIME_DIR=/run/user/10001
```

y carga `pipewire-module-xrdp` para audio y micrófono.

## 5. Servicio systemd de Workstation

Unidad:

```
/etc/systemd/system/arcadecloud-workstation.service
```

La unidad debe quedar:

```
disabled
```

pero puede estar:

```
active (running)
```

cuando ArcadeCloud la arranca bajo demanda.

Esto es intencional. No ejecutar `systemctl enable arcadecloud-workstation.service` como reparación.

El broker/Resource Mode Manager es quien solicita el arranque cuando existe una sesión Office.

Límites y endurecimiento actuales:

```
--memory=5g
--cpus=3
--shm-size=512m
--tmpfs /tmp:rw,nosuid,nodev,mode=1777
--security-opt=no-new-privileges:true
--cap-drop=ALL
--cap-add=SETUID
--cap-add=SETGID
--cap-add=CHOWN
--cap-add=DAC_OVERRIDE
--cap-add=FOWNER
```

El `tmpfs /tmp` evita que locks X11 viejos sobrevivan a recreaciones del contenedor.

## 6. Arranque de XFCE

El entrypoint genera:

```
/home/arcade/.local/bin/arcadecloud-start-xfce
/home/arcade/.xsession
/home/arcade/.vnc/xstartup
```

El helper prepara XDG, PipeWire y limpia únicamente los archivos de caché de sesión XFCE:

```
~/.cache/sessions/xfce4-session-*
```

No elimina `~/.config/xfce4`.

Se inicia `startxfce4` y después se fuerza un intento adicional de `xfce4-panel` para evitar restaurar una sesión persistente sin panel.

## 7. Configuración Guacamole que debe reconciliarse

La conexión canónica se llama:

```
ArcadeCloud Linux
```

El instalador debe forzarla a:

```
protocol = rdp
hostname = arcadecloud-workstation
port = 3389
username = arcade
security = any
ignore-cert = true
enable-audio = true
enable-audio-input = true
resize-method = display-update
color-depth = 16
disable-gfx = true
```

`disable-gfx=true` se añadió porque Guacamole 1.6.0 intentaba el Graphics Pipeline y produjo pantalla negra/advertencias con XRDP. La conexión ya no debe quedar heredada como VNC aunque exista una fila antigua en la DB.

Comprobación rápida:

```bash
sudo bash -lc '
source /var/lib/arcadecloud-guacamole/guac.env
docker exec arcadecloud-guac-db mysql -N -B \
  -uguacamole_user -p"$GUAC_DB_PASS" guacamole_db \
  -e "
SELECT connection_id, connection_name, protocol
FROM guacamole_connection
WHERE connection_name='''ArcadeCloud Linux''';

SELECT parameter_name, parameter_value
FROM guacamole_connection_parameter
WHERE connection_id=(
  SELECT connection_id
  FROM guacamole_connection
  WHERE connection_name='''ArcadeCloud Linux'''
  ORDER BY connection_id DESC LIMIT 1
)
AND parameter_name IN (
  '''hostname''','''port''','''username''',
  '''security''','''resize-method''',
  '''color-depth''','''disable-gfx'''
)
ORDER BY parameter_name;
"
'
```

## 8. Restauración después de instalar/clonar el repo

Actualizar código:

```bash
cd /var/www/arcadecloud-drive || exit 1
git fetch origin
git reset --hard origin/main
```

Reconstruir/reconciliar Workstation:

```bash
sudo bash drive/bin/install_workstation_node.sh \
  --app-root=/var/www/arcadecloud-drive \
  --reconcile
```

Reconciliar Guacamole:

```bash
sudo bash drive/bin/install_guacamole_node.sh
```

Si la Workstation estaba apagada, el modo `--reconcile` la deja apagada. Si estaba activa, no la reinicia durante la actualización para no cortar la sesión; la nueva imagen se aplicará en la siguiente sesión.

Para una instalación/arranque explícito de prueba:

```bash
sudo systemctl start arcadecloud-workstation.service
```

No es necesario habilitarla al boot.

## 9. Qué ocurre tras reiniciar la EC2

Guacamole, guacd y MySQL usan:

```
--restart unless-stopped
```

por lo que Docker los recupera al arrancar.

La Workstation sigue `disabled` en systemd por diseño. ArcadeCloud la inicia bajo demanda. Ver `disabled` y al mismo tiempo `active (running)` es válido.

Validación posterior al reboot:

```bash
cd /var/www/arcadecloud-drive || exit 1

git status
git log -5 --oneline

sudo docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
sudo systemctl status arcadecloud-workstation.service --no-pager
```

La prueba de 2026-10-08 confirmó que los cuatro contenedores reaparecieron y que Workstation publicó correctamente noVNC y XRDP sin reconfiguración manual.

## 10. Diagnóstico rápido

Estado general:

```bash
sudo docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
sudo systemctl status arcadecloud-workstation.service --no-pager
```

Procesos XRDP:

```bash
sudo docker exec arcadecloud-workstation bash -lc '
ps -ef | grep -E "xrdp|xrdp-sesman|xrdp-chansrv|Xorg :[0-9]+" | grep -v grep
'
```

Puerto 3389 escuchando dentro del contenedor:

```bash
sudo docker exec arcadecloud-workstation bash -lc '
cat /proc/net/tcp /proc/net/tcp6 2>/dev/null |
awk '\''$2 ~ /:0D3D$/ {print}'\''
'
```

Estado `0A` significa LISTEN.

Logs Guacamole:

```bash
sudo docker logs --since 2m arcadecloud-guacd 2>&1
sudo docker logs --since 2m arcadecloud-guacamole 2>&1
```

Logs Workstation/XRDP:

```bash
sudo docker exec arcadecloud-workstation bash -lc '
tail -100 /tmp/xrdp.log 2>/dev/null || true
tail -120 /tmp/xrdp-sesman.log 2>/dev/null || true
tail -120 /home/arcade/.xsession-errors 2>/dev/null || true
'
```

Procesos XFCE:

```bash
sudo docker exec arcadecloud-workstation bash -lc '
ps -ef | grep -E "xfce4-session|xfwm4|xfsettingsd|xfce4-panel|xfdesktop|Thunar" | grep -v grep
'
```

## 11. Problema conocido al cierre de esta intervención

La ruta VNC/noVNC y el escritorio XFCE quedaron operativos y reproducibles.

Guacamole ya quedó corregido para:

- usar RDP en lugar de una conexión VNC heredada;
- llegar a `arcadecloud-workstation:3389`;
- crear una sesión Xorg/xorgxrdp;
- mantener `xfce4-session`, `xfwm4`, `xfce4-panel`, `xfdesktop` y `Thunar`;
- desactivar RDP GFX en Guacamole.

Durante el diagnóstico se observó una sesión XRDP `:10` con ventanas reales, pero se seguía investigando una pantalla negra desde Guacamole. No asumir que este punto está resuelto sin una validación visual posterior.

También se observó en Xorg:

```
rdpProbe: found DRMDevice xorg.conf value [/dev/dri/renderD128]
rdpProbe: found DRI3 xorg.conf value [1]
rdpPreInit: /dev/dri/renderD128 open failed
```

No agregar `/dev/dri` ni ampliar privilegios del contenedor a ciegas. Si la pantalla negra persiste, revisar primero xorgxrdp/DRI3 y preferir renderizado por software antes de relajar el aislamiento.

## 12. Imágenes y respaldos antiguos

Los respaldos manuales creados durante la reparación fueron eliminados después de validar un reboot real.

No depender de:

```
phase1-custom-good-20261006
phase1-custom-20261006-110537
phase1-test-20261008
```

La imagen actual se reconstruye desde Git como:

```
arcadecloud/workstation:phase1
```

Evitar en producción:

```bash
docker system prune -a
```

Si hace falta limpiar, usar borrado selectivo o `docker image prune -f` después de revisar las imágenes activas.

## 13. Commits clave de esta reparación

```
1d23c4d Fix reproducible ArcadeCloud workstation image
6451911 Keep XFCE panel alive across workstation sessions
93baf29 Fix workstation startup local bin
cd30a8a Reconcile Guacamole connection protocol to RDP
a47e06c Disable Guacamole RDP GFX for XRDP compatibility
```

Estos commits son parte de `main` y constituyen el estado de referencia de esta recuperación.
