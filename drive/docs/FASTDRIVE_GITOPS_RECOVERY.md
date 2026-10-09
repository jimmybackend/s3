# FastDrive / EC2 grande — código versionado y recuperación segura

## Fuente de verdad: GitHub

Toda modificación de PHP, Dockerfile, scripts de Guacamole/XRDP y configuración
versionable se desarrolla y prueba en `jimmybackend/s3`. Se integra a `main`
**antes** de actualizar la EC2 grande. Nunca se utiliza la EC2 de producción
como repositorio de desarrollo ni se realizan `git reset --hard` o pushes
automáticos desde ella. Secrets, credenciales y datos personales permanecen
fuera del repositorio público.

`tools/arcadecloud-aws-bridge/fastdrive_gitops.py` usa AWS SSM para:

1. Comprobar que el checkout de producción es `main`, sin modificaciones
   versionadas ni archivos fuente nuevos sin seguimiento.
2. `git fetch origin main` y `git merge --ff-only origin/main`.
3. Verificar que el commit del workflow forma parte de la historia de `main`
   desplegada (se permite un descendiente más reciente cuando `main` avanza
   durante el workflow) y pasar `php -l`.
4. Reconciliar **solo el worker multimedia** cuando no hay actividad, mediante
   `drive/bin/fastdrive_safe_worker_reload.php`.
5. Confirmar que siguen activos los contenedores Guacamole, guacd y MySQL y
   que el puerto web solo escucha en `127.0.0.1:8085`.

Se activa exclusivamente mediante la petición versionada en
`tools/arcadecloud-aws-bridge/requests/fastdrive-gitops.json`
(`action=fastdrive-code-sync`) o `workflow_dispatch` si ya existe la
petición fija. No acepta comandos libres ni rutas arbitrarias.

Si detecta código escrito en el servidor y no guardado en Git, **aborta sin
sobrescribirlo**. Su reconciliación no toca los volúmenes
`/var/lib/arcadecloud-office` ni las credenciales de
`/etc/arcadecloud-drive` y `/var/lib/arcadecloud-guacamole`.
No reinicia el escritorio ni Guacamole.

## Autoapagado después de encendido externo

El origen del problema era que `handleIdle()` retornaba sin hacer nada
cuando `MediaWorkerNodeSessions` no tenía una sesión activa. Un
`StartInstances` desde la consola AWS o GitHub Actions no registraba nada
en esa tabla y podía dejar FastDrive encendida indefinidamente.

Ahora, en la EC2 grande exclusivamente, el worker verifica su
identidad física con IMDSv2. Si está encendida pero no tiene sesión,
reconcilia una sesión con usuario de sistema `0` y activa el contador
de **20 minutos + 30 segundos de advertencia**. Un gateway remoto no puede
fabricar esa sesión. Si la sesión antigua está en `stopping`, exige
una fecha de arranque AWS posterior a la orden de apagado para descartarla.

Operaciones transitorias de host detectadas (FFmpeg, ZIP/RAR, Git,
Docker CLI y utilidades CLI de compresión/compilación) frenan el apagado;
se mantienen además las protecciones de documentos Office y trabajos
multimedia, y el candado compartido `ComputeNodeAdmissionLock`.

**Detalle crítico de Docker/Workstation:** la unidad systemd inicia el
escritorio con un cliente persistente `docker run --name
arcadecloud-workstation`. Ese cliente **no cuenta como trabajo de fondo**:
el contador de inactividad debe seguir avanzando cuando XFCE está abierto
pero el usuario lleva 20 minutos sin interacción. El worker identifica
estrictamente el cliente de esa unidad por sus argumentos locales, sin
registrar argumentos ni contraseñas. `docker build` y contenedores ajenos
siguen protegidos mientras su proceso cliente esté activo.

**Límite:** ninguna inspección de procesos puede garantizar protección
para todo proceso de terceros ni para una descarga silenciosa de Chrome.
Descargas y tareas nuevas deben integrarse al registro de trabajos o a una
reserva explícita antes de delegarlas al autoapagado. No se modificó el
comportamiento del apagado forzado por superadmin.

## Validación

- CI: `office-document-regression.yml` con MySQL de prueba, simulación EC2
  y tests de reinicio externo, sesión antigua, ámbito local/remoto.
- CI: `fastdrive-gitops-contract.yml`: sintaxis PHP y Bash, sin conexión AWS.
- Producción: solo registros numéricos/estados en logs GitHub; el resultado
  bruto de SSM no se publica porque puede contener secretos.

### Guacamole

Conservar `drive/docs/ARCADECLOUD_WORKSTATION_RECOVERY.md`.
noVNC en `127.0.0.1:6080`, Guacamole web en
`127.0.0.1:8085`, guacd en `4822` y XRDP en `3389` por
la red privada `arcadecloud-office`. No tocar las credenciales RDP
ni cambiar la conexión RDP a VNC.

### Aplicación bajo demanda

Tras fusionar este cambio, el primer despliegue se ejecuta publicando
la solicitud fija en `main`. Si hay trabajos o una sesión Office activa,
el sincronizador actualiza código, pero **aplaza** el reinicio del worker.
En ese caso la reparación no está activa hasta que el worker se reinicie
en una ventana segura o se reinicie la EC2 más tarde.
