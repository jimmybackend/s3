# ArcadeCloud Remote Workstation — Fase 1

## Objetivo

Validar una estación gráfica aislada en el nodo de cómputo sin modificar el worker multimedia existente.

Arquitectura de esta fase:

```
Amazon Linux 2023 host
  -> Docker
    -> Ubuntu 24.04
      -> XFCE
      -> LibreOffice
      -> TigerVNC (sólo localhost dentro del contenedor)
      -> noVNC/websockify
  -> 127.0.0.1:6080 únicamente
```

Esta fase no integra todavía S3, FileS3, ArcadeCloud OS ni el broker de sesiones.

## Aislamiento

- El puerto VNC 5900/5901 no se publica en el host.
- noVNC se publica exclusivamente en `127.0.0.1:6080`.
- El contenedor no recibe el socket Docker.
- El contenedor no recibe credenciales AWS.
- El contenedor corre como usuario no-root y con capabilities eliminadas.
- El workspace está separado de `/var/lib/arcadecloud-media`.
- El instalador y desinstalador no modifican `arcadecloud-media-worker.service`.

## Instalación

Desde el checkout de la rama de prueba:

```bash
sudo bash drive/bin/install_workstation_node.sh /var/www/arcadecloud-drive
```

El instalador valida Amazon Linux 2023, x86_64, mínimo 4 vCPU y 7 GB de RAM visibles; instala Docker sólo si falta, construye la imagen y crea `arcadecloud-workstation.service`.

## Acceso de prueba

Durante Fase 1 no existe endpoint público. Para probar desde una PC autorizada se usa un túnel SSH:

```bash
ssh -L 6080:127.0.0.1:6080 ec2-user@HOST
```

Luego abrir `http://127.0.0.1:6080/vnc.html`.

La contraseña temporal se genera localmente en `/etc/arcadecloud-drive/workstation.env` con modo 0600 y no se guarda en Git.

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

1. Abrir noVNC mediante túnel SSH.
2. Confirmar escritorio XFCE.
3. Abrir Writer y guardar un ODT/DOCX en `/workspace`.
4. Abrir Calc y guardar un ODS/XLSX.
5. Abrir Impress y guardar un ODP/PPTX.
6. Reiniciar sólo `arcadecloud-workstation.service` y comprobar que los archivos siguen en el workspace.
7. Confirmar que `arcadecloud-media-worker.service` continúa activo.
8. Medir CPU/RAM antes de continuar a Fase 2.

## Desinstalación

```bash
sudo bash drive/bin/uninstall_workstation_node.sh
```

Conserva workspace y credenciales locales. `--purge` los elimina. Docker y el worker multimedia nunca se desinstalan.

## Siguiente fase

La Fase 2 añadirá el broker de sesiones temporales y coordinación de recursos. No se expondrá el Docker socket a PHP y no se publicará VNC directamente a Internet.
