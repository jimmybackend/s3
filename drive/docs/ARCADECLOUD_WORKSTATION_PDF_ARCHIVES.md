# Workstation: PDF y archivos ZIP/RAR

## Cambio específico (2026-10-08)

Ampliación de la **misma imagen** `arcadecloud/workstation:phase1` sin instalar
un segundo entorno gráfico. Se modifica `drive/docker/workstation/Dockerfile`
y el límite de recursos de la unidad Workstation generado por
`drive/bin/install_workstation_node.sh`:

- **Atril** (`atril`) para abrir documentos PDF desde XFCE.
- **RAR** (`rar`) para crear archivos RAR. Es software propietario distribuido
  por Ubuntu multiverse; verificar requisitos de licencia antes del uso comercial.
- Se **conservan** `zip`, `unzip`, `unrar`, `p7zip-full` y `file-roller`.
  File Roller proporciona la interfaz gráfica de compresión/extracción.
- LibreOffice, Chrome, XRDP, PipeWire, XFCE y Guacamole permanecen intactos.

Ubuntu 24.04 incluye `atril` en universe y `rar`/`unrar` en multiverse.
El Dockerfile ya instala `unrar` y no desactiva multiverse.

## Lo que NO se cambia

El trabajo previo sobre Guacamole está documentado en
`drive/docs/ARCADECLOUD_WORKSTATION_RECOVERY.md`, que sigue siendo la
fuente de verdad. En particular:

- noVNC: sólo `127.0.0.1:6080` (host grande).
- Guacamole: sólo `127.0.0.1:8085` (host grande).
- guacd: `4822` y XRDP: `3389` sólo en red interna `arcadecloud-office`.
- Conexión Guacamole `ArcadeCloud Linux`: RDP, 16 bits, `disable-gfx=true`.
- Credenciales persistentes de `/etc/arcadecloud-drive/rdp.env` y
  `/var/lib/arcadecloud-guacamole/guac.env` no se leen ni reescriben.
- Persistencia del escritorio: `/var/lib/arcadecloud-office`.

No ejecutar `docker system prune -a`, no regenerar secretos, no alterar
permisos de `/etc/arcadecloud-drive` y no modificar el broker Office.

## Despliegue seguro

Antes del despliegue comprobar el servidor remoto con el workflow
`ArcadeCloud Workstation preflight (read-only)` de GitHub Actions y
verificar que la EC2 grande tiene SSM `Online`.

El cambio persiste cuando se reconstruye la imagen desde el repo:

```bash
cd /var/www/arcadecloud-drive
# Primero comprobar git status y que la rama es main.
git fetch origin main
git pull --ff-only origin main
sudo docker build -t arcadecloud/workstation:phase1 drive/docker/workstation
```

**Sólo reconstruir la imagen no reinicia el contenedor ya existente.**
Si `arcadecloud-workstation` está en uso, no detenerlo por la instalación
de herramientas. La imagen nueva se aplicará cuando el servicio se inicie
en la siguiente sesión después de cerrar la anterior. Si no hay sesiones
activas, usar el control de servicios del nodo para un reinicio controlado
únicamente si hace falta, previa revisión del estado de las colas Office.

No hace falta ejecutar `install_guacamole_node.sh`. Como también se amplían los
límites de CPU/RAM, el workflow de despliegue actualiza **únicamente el
segmento de recursos de la unidad systemd** cuando Workstation está inactiva;
realiza respaldo de esa unidad y ejecuta `systemctl daemon-reload` **sin
reiniciar servicios**. No cambia los puertos ni las credenciales.

## Pruebas desde el Docker preparado

```bash
docker run --rm --network none --entrypoint /bin/sh \
  arcadecloud/workstation:phase1 -ec \
  'command -v atril; command -v rar; command -v unrar; command -v zip; command -v unzip; command -v file-roller'
```

Desde XFCE, abrir un PDF con **Atril**, comprimir un conjunto de archivos
con **File Roller** a ZIP, descomprimir ZIP/RAR y crear un archivo RAR
(si la licencia de RAR aplica). No asumir que la validación por CLI demuestra
la funcionalidad gráfica: validar desde el escritorio tras iniciar una sesión.

## Riesgos y costos

- La instalación agrega paquetes al tamaño de la imagen y consume CPU/disco
  durante `docker build`, sin cambiar el tamaño de EC2.
- La EC2 grande encendida sigue facturando hasta que vuelva a detenerse.
- Reconstruir sobre un tag usado en producción no debe borrar la imagen
  anterior ni limpiar volúmenes; nunca aplicar `docker image prune -a`
  durante esta operación.
- Los flujos AWS están en un repositorio público; no mostrar contraseñas,
  token RDP, claves ni contenidos de archivos en logs de GitHub Actions.


## Dimensionamiento de FastDrive y estabilidad de Chrome

Auditoría SSM de producción del 8 de octubre de 2026:

| Capacidad EC2 grande | Medición |
| --- | --- |
| CPU disponibles | 4 vCPU |
| RAM visible a Linux | 7.60 GiB aprox. |
| RAM disponible con Workstation inactiva | 6.41 GiB aprox. |
| Swap | 0 |
| Disco raíz total | 39.9 GiB aprox. |
| Espacio libre en disco | 27.2 GiB aprox. |
| Home persistente de arcade | 2.29 GiB aprox. |

**Nuevos límites de Workstation**:

- `--memory=5632m` (5.5 GiB) en lugar de 5 GiB.
- `--cpus=3.25` en lugar de 3.
- `--shm-size=1g` en lugar de 512 MiB.
- Continúa sin publicarse el socket Docker y con capabilities restringidas.

No entregar a Workstation el 100 % de CPU/RAM: FastDrive aloja Nginx,
PHP-FPM, Media Worker y tres contenedores Guacamole/MySQL/guacd en el
mismo host. El límite de memoria actúa como **máximo del cgroup**, no como
una reserva de RAM preasignada. Un host sin swap puede terminar procesos
por falta de memoria si se permite que todos los servicios la agoten.

`arcadecloud-chrome` conserva el ajuste comprobado
`--disable-dev-shm-usage` para no introducir regresiones del navegador;
la mayor `/dev/shm` beneficia a otros procesos y deja margen para ensayos
posteriores. Los datos de Chrome y las cuentas web siguen persistiendo en
`/var/lib/arcadecloud-office/home/arcade`.

**Red:** no cambiar los puertos ni el proxy RDP de Guacamole. Ambos
gateways ya configuran WebSocket/Upgrade, `proxy_buffering off` y
`proxy_read_timeout 86400`, sin un límite de ancho de banda artificial.
La fluidez del escritorio remoto también dependerá de la latencia y
calidad de la conexión del navegador local; no se puede garantizar cero
cortes por incrementar CPU/RAM.

**Disco:** Docker y la home de arcade comparten el EBS existente. No hay
necesidad de ampliar volumen ni crear un EBS nuevo para estos paquetes.
Conservar margen libre para capas de imagen, descargas, caches y los
archivos comprimidos. No limpiar las carpetas persistentes de sesión.

La configuración ajustada se aplicará cuando el contenedor se inicie de
nuevo; no interrumpir escritorios activos ni habilitar la unidad al boot.
