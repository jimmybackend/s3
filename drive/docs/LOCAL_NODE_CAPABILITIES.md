# Capacidades de Mi nodo

`NodeStatusController` continúa entregando exclusivamente el nodo que ejecuta la
aplicación. `NodeRuntimeStatusService` conserva identidad local, programas del
host y servicios systemd. `LocalContainerCapabilityService` añade evidencia de
contenedores del mismo host, sin consultar FederationCloud ni FastDrive remoto.

## Detección

El helper administrativo v16 expone una acción sin parámetros:
`local-container-programs`. Sólo realiza GET de estado y HEAD de archivos sobre
`/var/run/docker.sock`; no usa SSH, URLs configurables, Docker remoto, shell,
`docker exec`, arranque, instalación ni reconstrucción de contenedores.

La allowlist inicial corresponde al contenedor `arcadecloud-workstation` y los
programas de la instalación existente: LibreOffice, TigerVNC, Git, Python, AWS CLI,
FFmpeg/FFprobe si realmente están presentes y los archivos noVNC. No infiere su
instalación porque exista otro nodo ni porque una imagen tenga un nombre concreto.
Otros contenedores no se inspeccionan en esta fase; guacd conserva su diagnóstico
del host y los servicios mantienen su propio panel.

La comprobación usa metadata del filesystem del contenedor, no ejecuta los
programas ni certifica su funcionamiento completo. Los ejecutables requieren
permisos de ejecución y los directorios se rechazan. NoVNC se reconoce por su
archivo web. No se recopilan versiones dentro del contenedor.

## Estados

| Estado | Significado |
| --- | --- |
| `host` | Ejecutable encontrado en PATH local; `installed` conserva su significado histórico |
| `container` | Archivo del programa confirmado y contenedor local ejecutándose |
| `container_stopped` | Archivo confirmado, contenedor detenido, pausado o reiniciándose |
| `service_stopped` | Contenedor ausente y unidad workstation local detenida; programa sin verificar |
| `unavailable` | Docker confirmó ausencia del contenedor o programa |
| `unknown` | Helper antiguo/no accesible, socket inaccesible, error o metadata inválida |

`host_installed`, `container_installed`, `container_available` y `available`
separan las evidencias. Si existe en ambos sitios, la UI muestra ambos.

## Seguridad y operación

El nombre de contenedor y los paths están compilados en el helper. La interfaz
web no puede modificarlos. El transporte fija socket Unix, deshabilita proxies y
redirecciones y limita cada consulta a 600 ms y la respuesta a 64 KiB. Sólo sale
el inventario normalizado: no se retornan Config/Env, mounts, errores Docker ni
secretos. Se cachea durante 30 segundos cuando APCu está disponible; el inventario
del host conserva su caché anterior. El polling sigue ligado a Mi nodo abierto.

El helper instalado fuera del repositorio debe actualizarse mediante el
procedimiento administrativo ya existente. Hasta entonces la interfaz muestra
estado no verificable; actualizar sólo código web no instala privilegios nuevos.
No se instaló el helper ni se tocaron servicios de producción durante esta fase.

Pruebas: `php drive/tests/local_container_capabilities_regression.php` ejecuta
las reglas reales con respuestas Docker simuladas, incluidas ausencia, detenido,
pausado, errores, metadata malformada, programas faltantes y aislamiento de secretos.
No requiere root ni un daemon Docker.

Referencia de lectura de metadata:
https://docs.docker.com/reference/api/engine/version/v1.40/#tag/Container/operation/ContainerArchiveInfo
