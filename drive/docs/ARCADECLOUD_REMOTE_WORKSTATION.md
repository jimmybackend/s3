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
      -> TigerVNC (sólo localhost dentro del contenedor)
      -> noVNC/websockify
  -> 127.0.0.1:6080 únicamente
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
- El contenedor se validó con límite de 5 GiB RAM, 3 CPU y `--shm-size=512m`.
- El escritorio XFCE quedó accesible desde navegador y el worker multimedia permaneció activo.

## Aislamiento

- El puerto VNC 5900/5901 no se publica en el host.
- noVNC se publica exclusivamente en `127.0.0.1:6080`.
- El contenedor no recibe el socket Docker.
- El contenedor no recibe credenciales AWS.
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

La contraseña VNC se genera localmente en `/etc/arcadecloud-drive/workstation.env` con modo 0600 y no se guarda en Git.

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
