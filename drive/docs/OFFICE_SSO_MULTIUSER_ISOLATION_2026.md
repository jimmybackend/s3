# ArcadeCloud Office — acceso único y aislamiento por usuario (diseño, no desplegado)

Fecha de decisión: 2026-10-29. Repositorio `jimmybackend/s3`.

## Diagnóstico verificado en código

* `office-launch.php` ya exige sesión autenticada de ArcadeCloud OS, identifica
  `$session->userId()` y emite un token aleatorio de **un solo uso, 120 s**.
  `OfficeLaunchTokenRepository` sólo almacena SHA-256 del token, bloquea su
  consumo concurrente y devuelve el ID interno del usuario.
* `office-gateway.php` valida la sesión con
  `OfficeGatewayService::assertOfficeSession`; Nginx aplica
  `auth_request /__office_auth` a rutas proxied.
* `OfficeSessionLeaseRepository` actualmente limita una única sesión por
  **InstanceId** (no por UserId). `install_workstation_node.sh` crea sólo
  `arcadecloud-workstation` con un único `/home/arcade` y
  `/var/lib/arcadecloud-office/phase1-workspace`.
* `install_guacamole_node.sh` configura una única conexión XRDP con usuario
  Linux `arcade` y contraseña global. KDE/noVNC usa un servidor VNC local
  con `-SecurityTypes None`, protegido por la pasarela pública. La segunda
  pantalla de contraseña de KDE es **KScreenLocker/PAM**, no el login de OS.
* La validación de contraseña correcta en XRDP no prueba que PAM de
  KScreenLocker funcione en Docker: revisar `kcheckpass`, servicio
  `/etc/pam.d/kde` y `no-new-privileges` mediante el diagnóstico de sólo lectura.

## Decisión de autenticación (sin copiar contraseñas al navegador)

1. Mantener la sesión existente de ArcadeCloud OS (cookie
   `HttpOnly`, `Secure`, `SameSite` adecuada al salto de dominio,
   protección CSRF donde corresponda).
2. Reutilizar `OfficeLaunchTokenRepository` como **intercambio SSO de
   un solo uso** de dos minutos. El token no autoriza por sí solo un desktop
   de otro usuario: al consumirlo, el gateway resuelve el `UserId` del
   servidor y exige una concesión/lease activa para ese UserId.
3. Cada sesión de Office usa un ID aleatorio de sesión en servidor,
   expiración absoluta, actividad y revocación. No almacenar contraseña
   Linux, hash de contraseña reutilizable ni clave de cifrado en
   `localStorage`, `sessionStorage` o JavaScript.
4. Una clave por usuario puede cifrar **datos en reposo**, gestionada en el
   servidor mediante AWS KMS y políticas IAM apropiadas; **no sustituye**
   identidad ni permisos de acceso.
5. Enviar `Cache-Control: no-store`, usar TLS, validar `Origin` de
   WebSocket en el proxy y cortar canales activos ante revocación/timeout,
   no sólo negar el próximo handshake.
6. La contraseña interna VNC/RDP, de existir, debe ser aleatoria por
   contenedor y permanecer del lado del broker; el usuario del navegador
   no la introduce ni la conoce.

## Aislamiento de contenedores y archivos

El futuro broker (no implementado todavía) obtendrá el `UserId`
exclusivamente del contexto autenticado, nunca de `?user=` o headers
controlables por cliente. `OfficeUserContainerIdentity` prepara
identificadores estables como:

| Usuario OS | Contenedor | Home persistente | Workspace |
|---|---|---|---|
| ID 1 | `arcadecloud-office-u1` | `/var/lib/arcadecloud-office/users/u1/home` | `.../users/u1/workspace` |
| ID 23 | `arcadecloud-office-u23` | `/var/lib/arcadecloud-office/users/u23/home` | `.../users/u23/workspace` |

Los directorios se crearán con propietario UID apropiado y modo privado,
sin directorios compartidos escribibles entre usuarios. Docker crea una
**red aislada** por sesión/usuario (o un proxy privado que autorice el
destino con identidad verificada). Nunca publicar puertos VNC sin auth
a interfaces públicas ni permitir elegir el contenedor con una URL.

El broker debe separar el acceso documental por UserId y las sesiones de
documentos; evitar que archivos temporales, perfiles Chrome, cookies, history,
clipboard y wallpaper de un usuario aparezcan en otro. El contenedor
compartido anterior queda en modo **legacy de un único usuario**, sin
migración automática de perfiles ni borrado de datos.

## Ciclo de vida, recursos y apagado

* Antes de arrancar: autorización OS y limitación de recursos por usuario.
* Arranque: validar la concesión, adquirir mutex por (nodo, usuario),
  buscar/crear sólo la Workstation autorizada y montar sólo sus volúmenes.
* Puerta de entrada: `office.esforzados.com` envía tráfico al contenedor
  resuelto por **servidor**, no al nombre recibido del navegador.
* Sesión: heartbeat por lease + cortes de WebSockets al terminar o cambiar
  sesión; RDP/noVNC no deben exponer desktops entre leases diferentes.
* Fin: guardar, cerrar procesos, desconectar streams, revocar token y
  liberar recursos; mantener datos persistentes en su volumen privado.
* `c7i.xlarge` (4 vCPU, 8 GiB) hoy reserva hasta 5.5 GiB para
  **una** Workstation. Se requiere cola/capacidad para impedir
  varios escritorios completos simultáneos y superar la RAM.
* Preservar autoapagado de FastDrive tras 20 minutos de inactividad
  global según el scheduler ya existente; no encender Workstation al boot.
* At-rest: EBS cifrado y snapshots con política de retención; si se desea,
  claves por usuario y cifrado de objetos con KMS gestionado en backend.

## Bloqueo de KDE y alcance del cambio actual

NO desactivar `kscreenlocker`, cambiar PAM, suprimir contraseña de bloqueo
ni cerrar a la fuerza el proceso de autenticación en el contenedor
**compartido** de producción. Primero diagnosticar con
`sudo bash drive/bin/office_kde_unlock_diagnostic.sh`.

Una vez que el broker esté aislado por usuario, el modo remoto SSO podrá
optar por desactivar **sólo** `Autolock` y `LockOnResume` en ese home,
siempre que la pasarela termine realmente todas las sesiones web expiradas.
El bloqueo manual debe seguir siendo seguro o reemplazarse por un bloqueo
de sesión de gateway; no permitir continuar visualizando un escritorio
después del logout de OS.

## Fases de implementación / criterios de aceptación

1. **Preparación (esta PR):** diseño, mapeo puro de IDs/rutas y test de
   independencia, diagnóstico de KDE. **No activa multitenancy.**
2. **Broker y esquema de datos:** lease por (InstanceId, UserId) y
   SessionId; límites de simultaneidad, selección de destino en backend,
   bloqueo atómico y GC, migraciones reversibles.
3. **Runtime:** contenedores, volúmenes, sockets y redes aisladas;
   Guacamole conexión por usuario; proxy por sesión autenticada.
4. **SSO sin segundo login:** acceso web sin contraseña de escritorio,
   revocación y corte de WebSocket, cierre correcto de sesiones KDE,
   pruebas de ataque cross-user y sesión caducada.
5. **Rollout:** prueba con dos usuarios ficticios distintos, A nunca
   puede acceder a archivos/cookies de B, rollback y recuperación EBS.
   Sólo tras pasar esas pruebas migrar usuarios reales.

No confundir **la preparación técnica de esta PR** con un sistema
multiusuario listo para producción.
