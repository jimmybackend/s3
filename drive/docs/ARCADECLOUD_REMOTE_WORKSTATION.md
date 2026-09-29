# ArcadeCloud Remote Workstation — Fase 1

## Objetivo

Validar una estación gráfica aislada en el nodo de cómputo sin modificar el worker multimedia existente.

Arquitectura validada físicamente:

```
Amazon Linux 2023 host
  -> Docker 25
    -> Ubuntu 24.04
      -> XFCE
      -> LibreOffice
      -> Google Chrome
      -> Git + AWS CLI v2
      -> TigerVNC + noVNC/websockify
      -> XRDP + PipeWire (audio/micrófono)
  -> noVNC en 127.0.0.1:6080
  -> Guacamole en 127.0.0.1:8085/guacamole/
  -> XRDP sólo por la red Docker interna arcadecloud-office
```

Esta fase todavía no integra S3, FileS3, ArcadeCloud OS ni el broker definitivo de sesiones.

## Hallazgos de la instalación real

La prueba se realizó sobre un nodo Amazon Linux 2023 x86_64 de 4 vCPU y aproximadamente 8 GiB RAM que ya ejecutaba el worker multimedia.

Se confirmó:

- Docker puede instalarse sin detener Nginx, PHP-FPM Drive ni `arcadecloud-media-worker.service`.
- Ubuntu 24.04 puede tener ocupado el UID 1000. Workstation usa UID/GID 10001 para evitar colisiones.
- `/etc/arcadecloud-drive` pertenece al runtime de ArcadeCloud. El instalador Workstation no debe cambiar su propietario o modo ni tocar `runtime-env.json`.
- En la instalación observada PHP-FPM Drive corre como `apache`; `runtime-env.json` debe seguir siendo legible por ese usuario/grupo.
- Cambiar accidentalmente `/etc/arcadecloud-drive` a `root:root 0750` impide que PHP-FPM lea la configuración administrada y puede hacer fallar el login aunque la DB remota esté sana.
- noVNC funcionó correctamente detrás de Nginx manteniendo el binding Docker en `127.0.0.1:6080`.
- Apache Guacamole 1.6.0 + guacd + MySQL 8.4 se ejecutan en contenedores separados sobre la red Docker `arcadecloud-office`.
- XRDP usa `pipewire-module-xrdp` para salida de audio y micrófono. El perfil validado usa 16 bits de color y desactiva efectos visuales para reducir tráfico.
- El host continúa siendo Amazon Linux 2023; Ubuntu 24.04 existe únicamente dentro de la imagen Docker Workstation.
- El contenedor se validó con límite de 5 GiB RAM, 3 CPU y `--shm-size=512m`.
- El escritorio XFCE quedó accesible desde navegador y el worker multimedia permaneció activo.
- La imagen Workstation incluye Google Chrome, Git y AWS CLI v2 para uso de desarrollo.
- `/home/arcade` se monta desde `/var/lib/arcadecloud-office/home/arcade`, por lo que perfil de Chrome, configuración Git y `Projects/` sobreviven reconstrucciones del contenedor y reinicios de la EC2.

## Aislamiento

- Los puertos VNC 5900/5901 y XRDP 3389 no se publican en Internet.
- noVNC se publica exclusivamente en `127.0.0.1:6080`.
- Guacamole web se publica exclusivamente en `127.0.0.1:8085`; Nginx lo expone como `/guacamole/` sólo detrás del gateway autenticado de Office.
- El contenedor no recibe el socket Docker.
- El contenedor no recibe credenciales AWS automáticamente. AWS CLI está instalado, pero la autorización S3 debe configurarse de forma explícita y con privilegios mínimos.
- El contenedor corre como usuario no-root UID/GID 10001 y con capabilities eliminadas.
- El workspace está separado de `/var/lib/arcadecloud-media`.
- El instalador y desinstalador no modifican `arcadecloud-media-worker.service`.
- Workstation queda deshabilitada al boot en Fase 1. El futuro broker la inicia sólo cuando existe una sesión Office.

## Instalación

Desde el checkout de la rama de prueba:

```bash
sudo bash drive/bin/install_workstation_node.sh /var/www/arcadecloud-drive
```

El instalador valida Amazon Linux 2023, x86_64, mínimo 4 vCPU y 7 GB de RAM visibles; instala Docker sólo si falta, construye la imagen y crea `arcadecloud-workstation.service`.

No altera los permisos de `/etc/arcadecloud-drive` ni de `runtime-env.json`.

## Acceso web

La arquitectura definitiva será:

```
navegador
  -> HTTPS
  -> office.esforzados.com/session/<token>
  -> Nginx / broker autenticado
  -> 127.0.0.1:6080
  -> noVNC
  -> TigerVNC
  -> XFCE / LibreOffice
```

El acceso temporal por IP utilizado durante la prueba física no forma parte del diseño final.

XFCE puede abrirse directamente desde **Aplicaciones -> Linux XFCE** en ArcadeCloud OS. Ese acceso reutiliza el mismo launcher autenticado de `office.esforzados.com`; no publica VNC.

LibreOffice ya no solicita una segunda contraseña VNC. TigerVNC usa `SecurityTypes None`, pero permanece encerrado en localhost y el acceso público a `vnc.html`/`websockify` está protegido por la sesión temporal de ArcadeCloud mediante `auth_request`. Entrar directamente a esas rutas sin una sesión Office activa devuelve 401.

## Workspace

Los documentos de prueba viven en:

```
/var/lib/arcadecloud-office/phase1-workspace/
```

Desde LibreOffice se ven como `/workspace`.

## Health

```bash
php drive/bin/workstation_health.php
```

Debe devolver `"ok": true`.

## Validación manual de Fase 1

1. Confirmar que el contenedor Workstation está activo.
2. Entrar mediante el proxy web temporal o el broker.
3. Confirmar escritorio XFCE en navegador.
4. Abrir Writer y guardar un ODT/DOCX en `/workspace`.
5. Abrir Calc y guardar un ODS/XLSX.
6. Abrir Impress y guardar un ODP/PPTX.
7. Reiniciar sólo `arcadecloud-workstation.service` y comprobar que los archivos siguen en el workspace.
8. Confirmar que `arcadecloud-media-worker.service` continúa activo.
9. Medir CPU/RAM antes de continuar a Fase 2.

## Desinstalación

```bash
sudo bash drive/bin/uninstall_workstation_node.sh
```

Conserva workspace y credenciales locales. `--purge` los elimina. Docker y el worker multimedia nunca se desinstalan.

## Siguiente fase

La Fase 2 añadirá el broker de sesiones temporales, autenticación web, sincronización controlada de archivos y coordinación de recursos. No se expondrá el Docker socket a PHP y no se publicará VNC directamente a Internet.


## Fase 2 — lanzamiento desde ArcadeCloud OS

El acceso de usuario se inicia desde el mismo `so.php` de Drive o FastDrive:

```
Aplicaciones -> Office
        |
        v
drive/office-launch.php
        |
        | token aleatorio de un solo uso, 120 s
        v
https://office.esforzados.com/?launch=...
```

`OfficeLaunchTokenRepository` persiste únicamente SHA-256 del token en MySQL. El token se consume una sola vez y permite que el subdominio Office conozca el usuario que inició la sesión sin compartir cookies entre subdominios.

Si la EC2 grande está apagada, el gateway Office reutiliza `FastDriveWakeService`: muestra la autorización de superadministrador, valida la contraseña actual, aplica el mismo límite de intentos y solicita `StartInstances` únicamente sobre la instancia configurada.

Si la EC2 ya está encendida, no se solicita una segunda autorización para arrancar el contenedor Office.

## Dos gateways, una sola EC2

### EC2 pequeño — gateway público

`drive/bin/install_office_gateway.sh` prepara:

```
Internet
  -> https://office.esforzados.com
  -> EC2 pequeño / Nginx / TLS
  -> office-gateway.php
  -> red privada
  -> 172.31.14.35
```

El EC2 pequeño mantiene el certificado Let's Encrypt y nunca expone VNC.

### EC2 grande — gateway privado

`drive/bin/install_workstation_internal_gateway.sh` prepara el vhost interno:

```
172.31.83.240
  -> EC2 grande :80
  -> /__arcadecloud_workstation
       -> PHP-FPM
       -> helper privilegiado
       -> systemctl start arcadecloud-workstation.service

  -> /vnc.html + /websockify
       -> 127.0.0.1:6080

  -> /guacamole/
       -> 127.0.0.1:8085/guacamole/
       -> guacd
       -> XRDP :3389 en la red Docker
       -> XFCE + PipeWire
```

El endpoint interno de Workstation sólo permite `status` y `start`; no expone una parada directa del contenedor al navegador. Además del autoapagado por inactividad, el panel administrativo de FastDrive puede solicitar `StopInstances` sobre la EC2 fija cuando un superadmin se reautentica y no existen tareas ni sesiones Office activas.

## Autenticación del escritorio

La identidad del usuario vive en ArcadeCloud, no en VNC.

Flujo:

```
Drive/FastDrive autenticado
  -> token Office de un solo uso
  -> sesión en office.esforzados.com
  -> Nginx auth_request
  -> valida usuario + dueño activo de la sesión
  -> vnc.html / WebSocket
  -> TigerVNC sin prompt adicional
```

Esto elimina la contraseña VNC visible sin exponer el escritorio. El EC2 grande sigue aceptando tráfico Office sólo desde el gateway pequeño por red privada, y no publica 5900/5901/6080 a Internet.

## Concurrencia inicial

La primera versión mantiene `MAX_OFFICE_SESSIONS=1`. Antes de mostrar el escritorio, Office consulta la sesión activa del nodo. Si pertenece a otro usuario, el segundo lanzamiento queda esperando y nunca comparte el mismo XFCE/LibreOffice.

## Inactividad de Office y apagado

Office reutiliza `MediaWorkerNodeService` y `MediaWorkerNodeSessions`.

La pestaña Office observa actividad real de:

- teclado;
- click/toque;
- movimiento de puntero;
- rueda/scroll;
- regreso de la pestaña al primer plano.

Los heartbeats se limitan a uno cada 20 segundos. Un heartbeat llama `touchInteractiveActivity()` y limpia el inicio de inactividad.

Si no hay actividad:

1. el worker marca la sesión como idle;
2. se esperan al menos 600 segundos;
3. la pestaña muestra una advertencia de 30 segundos;
4. si no se reanuda la actividad y no existen trabajos multimedia, el mecanismo existente solicita apagar la EC2;
5. si hay FFmpeg/multimedia activo, el apagado queda bloqueado.

El umbral sigue controlado por `ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS`, cuyo mínimo en código es 600 segundos.

## Guardado de documentos

La inactividad del navegador **no sustituye guardar el documento**.

En esta fase:

- LibreOffice trabaja sobre `/workspace`;
- un guardado explícito de LibreOffice persiste en el volumen del host;
- reiniciar sólo el contenedor conserva ese workspace;
- todavía no se sincroniza automáticamente un documento de `FileS3` de regreso a S3.

La fase de archivos implementará:

```
FileS3/S3 -> workspace temporal por sesión
              |
              +-> guardado explícito/checkpoint
              |
              +-> control de versión/ETag
              |
              +-> sincronización segura a S3 + FileS3
```

Antes de apagar una sesión Office se debe realizar el checkpoint/sync final. Nunca se debe sobrescribir silenciosamente una versión que cambió en otra sesión.

## Instalación del gateway público

En el EC2 pequeño, después de que DNS y Certbot hayan preparado `office.esforzados.com`:

```bash
cd /var/www/arcadecloud-drive
sudo bash drive/bin/install_office_gateway.sh
```

Valores por defecto validados:

- dominio: `office.esforzados.com`;
- upstream privado: `172.31.14.35`;
- PHP-FPM Drive: `127.0.0.1:9075`.

## Instalación del gateway privado

En el EC2 grande:

```bash
cd /var/www/arcadecloud-drive
sudo bash drive/bin/install_workstation_internal_gateway.sh --gateway-ip=172.31.83.240
```

No abrir 5900, 5901 ni 6080 en el Security Group.


## Fase 3 — documentos reales de FileS3/S3

ArcadeCloud Office puede abrir un archivo concreto desde **Mis datos**. El navegador nunca envía una key S3 arbitraria al subdominio Office: envía el `FileS3.id_` al launcher autenticado, el launcher vuelve a validar `user_id_`, `Found=1`, seguridad y extensión. El token mantiene en MySQL sólo su hash y el usuario; cuando se abre un documento, el `file_id` viaja como contexto del token y vuelve a validarse contra `FileS3` al crear la sesión documental. Así no se requiere `ALTER TABLE` durante una petición web.

Extensiones iniciales:

- Writer: `.doc`, `.docx`, `.odt`, `.rtf`;
- Calc: `.xls`, `.xlsx`, `.ods`;
- Impress: `.ppt`, `.pptx`, `.odp`.

Flujo:

```
Mis datos
  -> doble clic / Abrir con Office
  -> office-launch.php?file_id=<id>
  -> token de un solo uso (hash + usuario en MySQL; file_id como contexto revalidado)
  -> office.esforzados.com
  -> lease de escritorio
  -> OfficeDocumentSession
  -> nodo grande / agente privado
  -> HEAD + GET del objeto S3
  -> /var/lib/arcadecloud-office/phase1-workspace/sessions/<session>/Nombre.docx
  -> docker exec allowlisted
  -> LibreOffice abre /workspace/sessions/<session>/Nombre.docx
```

### Separación por archivo y usuario

`OfficeDocumentSessions` liga de forma explícita:

- `SessionId`;
- `UserId`;
- `FileId`;
- `InstanceId`;
- key S3 original;
- nombre visible;
- ruta relativa del workspace;
- ETag esperado;
- mtime/tamaño local;
- estado y posible copia de conflicto.

El token interno del agente no se guarda en claro: MySQL conserva sólo `ControlTokenHash = SHA-256(token)`.

### Guardado y sincronización

La pestaña Office solicita un checkpoint cada 60 segundos. El agente del nodo compara `mtime + size`; si LibreOffice no escribió cambios, no hace ningún PUT.

Si el workspace cambió:

1. vuelve a localizar `FileS3` por `file_id + user_id_`;
2. comprueba que `Found=1` y que la key física no cambió;
3. hace `HEAD` del objeto actual;
4. compara el ETag con el último ETag conocido;
5. sólo si coincide, sube el archivo a la misma key;
6. confirma el nuevo ETag;
7. actualiza `FileS3.Tamano` y `Fecha`;
8. registra el checkpoint en `OfficeDocumentSessions`.

Al cerrar la pestaña se usa `sendBeacon` hacia `/__office_document_close`; el servidor ejecuta un sync final y sólo después libera el lease del escritorio.

### Conflictos

ArcadeCloud nunca sobrescribe silenciosamente un objeto que cambió mientras estaba abierto en Office. Si la key o el ETag remoto ya no coinciden, el contenido del workspace se guarda como un archivo nuevo:

```
Documento.docx
Documento (conflicto Office YYYY-MM-DD HH-mm-ss).docx
```

La copia física recibe una nueva key mediante `StorageObjectNameCodec` y se registra como un nuevo `FileS3`. Después de crearla, esa copia se convierte en el destino activo de la sesión para que los siguientes guardados continúen sobre el mismo archivo de conflicto y no creen copias repetidas.

### Seguridad del nodo

El endpoint documental del EC2 grande:

```
/__arcadecloud_office_document
```

sólo acepta `prepare`, `sync` y `close`, sólo por POST y sólo desde el gateway pequeño permitido por Nginx. Cada llamada requiere `SessionId + control token`.

El contenedor no recibe:

- credenciales AWS;
- Docker socket;
- bucket completo;
- rutas de otros usuarios.

PHP-FPM y el usuario del contenedor comparten únicamente `phase1-workspace/sessions` mediante un grupo suplementario y permisos `2770`.

### Limpieza

Las sesiones cerradas permanecen temporalmente para permitir el guardado final y diagnósticos. En aperturas posteriores, los workspaces cerrados con más de 30 minutos se eliminan de forma segura y su fila documental se purga.


## Esquema de base de datos Office

Las tablas de Office forman parte del SQL canónico `adbbmis1_Cloud.sql` dentro de la sección idempotente:

```
-- ARCADECLOUD:OFFICE_SCHEMA:BEGIN
-- ARCADECLOUD:OFFICE_SCHEMA:END
```

Tablas obligatorias:

- `OfficeLaunchTokens`: tokens de lanzamiento de un solo uso;
- `OfficeSessionLeases`: propietario y expiración del escritorio interactivo;
- `OfficeDocumentSessions`: relación entre usuario, `FileS3.id_`, instancia, key S3, ETag y workspace.

`OfficeSchemaMigrationService` comprueba y crea las tablas faltantes usando únicamente esa sección del SQL canónico. El updater web muestra el estado **ArcadeCloud Office DB** y reconcilia tablas faltantes con la conexión MySQL activa. `office-launch.php` también ejecuta una comprobación idempotente antes de emitir el token, para impedir que una instalación parcialmente actualizada avance hacia Office con un esquema incompleto.

Una base nueva recibe las tres tablas al importar el SQL canónico completo.


### Archivo catalogado pero ausente en S3

Si `FileS3` conserva `Found=1` pero la key física ya no existe, Office no abre un Writer vacío. La sesión documental pasa a `failed` y el gateway muestra **Documento no preparado** con una instrucción de sincronizar desde S3 la carpeta real del archivo.

Este caso suele indicar que MySQL y S3 quedaron desalineados después de mover/copiar objetos fuera del flujo normal o antes de completar una sincronización. La reparación correcta es usar la sincronización S3 existente de ArcadeCloud; Office no recorre ni adivina keys del bucket.

Las sesiones documentales `failed` se consideran cerradas para limpieza y pueden purgarse después del periodo de retención local.


## Workstation personal de desarrollo

La Workstation también puede usarse como escritorio Linux personal sin abrir un documento Office concreto.

Persistencia:

```text
/var/lib/arcadecloud-office/home/arcade
  -> /home/arcade
      -> Projects/
      -> Downloads/
      -> .config/   (incluido perfil de Chrome)
      -> .gitconfig
```

El código de los repositorios debe trabajar sobre el filesystem local/EBS y usar Git para versionado. S3 continúa siendo almacenamiento de objetos y respaldo; no se trata como filesystem POSIX para un checkout Git.

El apagado automático por inactividad permanece activo. El superadmin también dispone de un botón de apagado explícito en el perfil del Web OS. Ese control conserva los registros/colas existentes y solicita un StopInstances normal a AWS; una tarea que estuviera ejecutándose puede requerir reintento al volver a arrancar.


## Audio y micrófono por Guacamole

El escritorio noVNC se conserva como acceso compatible. Para audio y micrófono se usa la ruta RDP:

```
office.esforzados.com/guacamole/
  -> Apache Guacamole
  -> guacd
  -> arcadecloud-workstation:3389
  -> XRDP/Xorg
  -> PipeWire
  -> xrdp-sink / xrdp-source
```

La imagen Workstation instala `pipewire`, `pipewire-pulse`, `wireplumber`, `pipewire-module-xrdp`, `pulseaudio-utils`, `xrdp` y `xorgxrdp`. El módulo XRDP usa un quantum de 1024 en la imagen validada para reducir latencia.

El instalador `install_workstation_node.sh` crea y conserva `/etc/arcadecloud-drive/rdp.env`, conecta Workstation a la red `arcadecloud-office` y aplica sólo las capabilities requeridas para iniciar XRDP y cambiar al usuario `arcade`. `install_guacamole_node.sh` conserva la base MySQL y no cambia una contraseña administrativa de Guacamole que el usuario ya haya modificado.
