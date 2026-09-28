# ArcadeCloud Web OS — roadmap de integración

Estado: **roadmap activo; shell Web OS implementado y en producción**.

Este documento define el rumbo para continuar evolucionando ArcadeCloud Drive como **entorno de trabajo web federado**. El shell de ArcadeCloud Web OS ya está implementado y es la experiencia principal después del login. El estado verificable de lo que funciona hoy se mantiene en `ARCADECLOUD_WEB_OS_STATUS.md`.

## Idea central

ArcadeCloud Web OS será una interfaz de escritorio en navegador sobre la infraestructura existente:

```text
Usuario
  -> ArcadeCloud Web OS
      -> Explorador de archivos (FileS3 / S3Folders)
      -> aplicaciones web libres
      -> servicios pesados locales o remotos
      -> FederationCloud
      -> S3 privado
```

La interfaz podrá presentar **Mi nodo** y la raíz lógica del usuario:

```text
user_id = 1 -> Data/
user_id = 2 -> Data2/
user_id = N -> DataN/
```

No es un kernel ni un SO local. Es una estación de trabajo web que orquesta almacenamiento, aplicaciones web, nodos de cómputo y servicios federados.

## Regla obligatoria: capacidad real antes de ejecutar

Toda aplicación que pueda consumir recursos del servidor deberá declarar un perfil de requisitos.

ArcadeCloud no debe asumir que un nodo puede ejecutar una herramienta por llamarse `combined`, `worker`, `office` o similar. Debe comprobar la máquina real antes de arrancar o aceptar trabajo.

El patrón existente del worker multimedia es la referencia: actualmente se comprueban capacidad local, CPU, memoria, dependencias, espacio temporal y disponibilidad antes de reclamar trabajo.

El diseño general debe evolucionar hacia un servicio reutilizable, por ejemplo:

```text
NodeCapabilityService
  -> IMDSv2 cuando exista
  -> vCPU
  -> RAM total/disponible
  -> swap
  -> disco libre
  -> carga
  -> Docker
  -> GPU cuando aplique
  -> dependencias
  -> procesos/tareas incompatibles
```

Cada programa deberá poder responder al menos:

```text
available
ready
insufficient_capacity
dependency_missing
busy_conflict
remote_fallback
authorization_required
```

Si el nodo local no cumple, la herramienta no se ejecuta allí. Puede ofrecerse un nodo especializado configurado como fallback.

## Roles funcionales previstos

### drive.esforzados.com

Nodo coordinador y básico.

Responsabilidades previstas:

- autenticación;
- catálogo MySQL;
- navegación DB-first;
- S3;
- permisos;
- FederationCloud;
- herramientas ligeras;
- UI del Web OS;
- coordinación de nodos especializados;
- autorización de encendido cuando corresponda.

### fastdrive.esforzados.com

Nodo profesional/de cómputo.

Responsabilidades previstas:

- procesamiento multimedia;
- Office si pasa preflight;
- PDF pesado;
- conversiones pesadas;
- aplicaciones Docker compatibles;
- futuros trabajos profesionales que cumplan capacidad.

El mismo nodo podrá ofrecer varias funciones sólo cuando ArcadeCloud pueda impedir competencia peligrosa entre tareas incompatibles.

## Estado actual

### Shell Web OS ya implementado

La etapa de shell/prototipo dejó de ser pendiente. Actualmente ya existen:

- [x] `so.php` como experiencia principal después del login;
- [x] escritorio, launcher, barra de tareas y ventanas;
- [x] Mis datos con navegación DB-first y paginación;
- [x] copiar/cortar/pegar/mover entre carpetas;
- [x] centro unificado de subida;
- [x] Centro de Tareas;
- [x] Mi nodo con métricas en vivo;
- [x] mantenimiento de memoria/disco encolado;
- [x] autoapagado del nodo de cómputo por inactividad;
- [x] seguridad de archivos compartida con el Drive clásico;
- [x] compartir con ArcadeLink/FederationCloud;
- [x] Buscar como aplicación, en modo normal e IA;
- [x] salto desde resultados a la carpeta/página real del archivo;
- [x] Acerca de / Actualizar dentro del SO;
- [x] acceso explícito al Drive clásico.

El shell ya no es el siguiente entregable: es la base sobre la que se integrarán Office, navegador remoto, edición de video y otras aplicaciones.


### Carpetas y Bloc de notas

Las carpetas también son objetos operables dentro del Web OS. No deben quedar reducidas a un simple enlace de navegación.

Acciones previstas sobre una carpeta, reutilizando los servicios/endpoints actuales siempre que existan:

- [ ] abrir carpeta en una ventana del Explorador;
- [ ] sincronizar carpeta;
- [ ] renombrar/editar nombre;
- [ ] mover carpeta;
- [ ] eliminar carpeta con confirmación;
- [ ] crear archivo de texto dentro de la carpeta;
- [ ] refrescar únicamente la ventana/carpeta afectada después de una operación;
- [ ] mostrar tareas largas en el Centro unificado de Tareas;
- [ ] respetar `user_id_` y `UserStoragePath` en todas las operaciones.

El Web OS tendrá un **Bloc de notas de ArcadeCloud** para crear texto sin salir del escritorio:

```text
Carpeta
  -> Nuevo
      -> Documento de texto
          -> ventana Bloc de notas
              -> pegar/escribir texto
              -> indicar nombre
              -> elegir extensión permitida
              -> Guardar / Cancelar
```

Requisitos del Bloc de notas:

- [ ] se abre dentro de una ventana movible/minimizable/maximizable del SO;
- [ ] el destino queda ligado a la carpeta desde la que se creó;
- [ ] nombre obligatorio y validado;
- [ ] extensiones iniciales seguras: `.txt`, `.md`, `.json`, `.csv`, `.log`;
- [ ] no aceptar `../`, rutas absolutas ni separadores que permitan traversal;
- [ ] guardar mediante servicio backend de ArcadeCloud, nunca con credenciales S3 en navegador;
- [ ] objeto S3 privado;
- [ ] registrar inmediatamente el nuevo archivo en `FileS3`;
- [ ] respetar nombre visible / nombre físico;
- [ ] actualizar sólo la ventana del Explorador afectada;
- [ ] ofrecer **Guardar** y **Cancelar**;
- [ ] si el nombre ya existe, pedir decisión explícita antes de sobrescribir o crear copia.

### Base de ArcadeCloud

- [x] Arquitectura Controller -> Service -> Repository.
- [x] MySQL como fuente de verdad para navegación.
- [x] S3 como almacenamiento físico.
- [x] `FileS3` como catálogo de archivos.
- [x] separación nombre lógico / key física.
- [x] aislamiento por `user_id_`.
- [x] raíces `Data/`, `Data2/`, ..., `DataN/`.
- [x] objetos privados en S3.
- [x] subida de archivos.
- [x] descarga autenticada.
- [x] mover, renombrar y eliminar.
- [x] editor de texto.
- [x] protección de archivos.
- [x] búsqueda.
- [x] actualización parcial del bloque de archivos por AJAX.
- [x] Centro de Tareas.
- [x] web updater con flujo branch -> PR -> main -> actualización web.

### FederationCloud

- [x] identidad por nodo.
- [x] ArcadeLink.
- [x] descubrimiento.
- [x] autorización provider/mirror.
- [x] catálogo federado.
- [x] réplicas.
- [x] failover por ubicación.
- [x] separación de secretos y configuración fuera del repositorio.

### Multimedia

- [x] FFmpeg/FFprobe.
- [x] dividir video.
- [x] dividir audio.
- [x] extraer MP3.
- [x] trabajos en background.
- [x] resultados registrados en `FileS3`.
- [x] conservación del original.
- [x] preflight real de CPU/RAM/capacidad.
- [x] espacio temporal validado antes de procesar.
- [x] fallback hacia EC2 multimedia configurada.
- [x] encendido bajo demanda.
- [x] autorización antes de encender un nodo pagado.
- [x] apagado por inactividad.
- [ ] convertir el preflight multimedia en un sistema genérico de capacidades para todas las aplicaciones.
- [ ] exclusión mutua formal entre Office, FFmpeg y otros trabajos pesados.

## Aplicaciones previstas

Los nombres siguientes son **candidatos de integración**, no dependencias definitivas. Licencia, mantenimiento, requisitos y compatibilidad deberán revalidarse antes de implementar cada una.

### Oficina

- [ ] selector técnico Collabora Online CODE vs ONLYOFFICE Docs Community.
- [ ] edición inicial: DOCX.
- [ ] edición inicial: XLSX.
- [ ] edición inicial: PPTX.
- [ ] edición inicial: ODT.
- [ ] edición inicial: ODS.
- [ ] edición inicial: ODP.
- [ ] integración privada con ArcadeCloud.
- [ ] locks/concurrencia.
- [ ] actualización de tamaño/fecha en `FileS3`.
- [ ] historial/versionado posterior.
- [ ] autorización superadmin para arrancar el servicio Office cuando esté detenido.
- [ ] impedir Office cuando haya procesamiento multimedia incompatible.

Candidato preferente inicial para pruebas: **Collabora + WOPI**.

### Video

- [x] dividir video con FFmpeg.
- [x] extraer MP3.
- [ ] conversión de formatos.
- [ ] edición de video ligera en navegador.
- [ ] evaluar OpenCut como editor cliente.
- [ ] exportación de edición hacia ArcadeCloud.
- [ ] thumbnails/timeline avanzados.
- [ ] procesamiento pesado delegado al worker.
- [ ] transcodificación de video.
- [ ] generación de GIF/video corto.
- [ ] presets de resolución/bitrate.

### Audio

- [x] reproducción.
- [x] división con FFmpeg.
- [x] extracción MP3 desde video.
- [ ] evaluar AudioMass.
- [ ] cortar/unir.
- [ ] normalizar volumen.
- [ ] fade in/out.
- [ ] convertir WAV/MP3/FLAC/OGG/AAC.
- [ ] mezcla multipista ligera.
- [ ] guardar resultado como nuevo `FileS3`.

### Conversión de formatos

- [ ] `ffmpeg.wasm` para conversiones pequeñas en navegador.
- [ ] FFmpeg server-side para archivos grandes.
- [ ] selector automático cliente/servidor según tamaño y capacidad.
- [ ] formatos de imagen.
- [ ] formatos de audio.
- [ ] formatos de video.
- [ ] documentos mediante suite Office cuando corresponda.

### Imágenes

- [ ] evaluar miniPaint.
- [ ] recortar.
- [ ] redimensionar.
- [ ] rotar.
- [ ] capas.
- [ ] filtros.
- [ ] JPG/PNG/WebP.
- [ ] guardar como copia o reemplazo controlado.

### Vectorial

- [ ] evaluar SVG-Edit.
- [ ] abrir SVG.
- [ ] editar SVG.
- [ ] crear SVG.
- [ ] guardar en ArcadeCloud.

### PDF

- [ ] evaluar Stirling-PDF.
- [ ] unir PDF.
- [ ] dividir PDF.
- [ ] rotar/reordenar.
- [ ] comprimir.
- [ ] convertir.
- [ ] operaciones pesadas sujetas al preflight del nodo.

### Diagramas

- [ ] evaluar diagrams.net / draw.io.
- [ ] diagramas de flujo.
- [ ] UML.
- [ ] arquitectura AWS.
- [ ] diagramas de red.
- [ ] organigramas.
- [ ] evaluar Excalidraw como pizarra.
- [ ] evaluar Mermaid para diagramas como código.
- [ ] guardar fuentes y exportaciones dentro del Drive.

### 3D

- [ ] evaluar JSCAD para CAD paramétrico ligero.
- [ ] visor GLTF/GLB.
- [ ] visor STL.
- [ ] exportación STL.
- [ ] operaciones WebGL preferentemente en el navegador.
- [ ] Blender/render profesional únicamente en nodo con capacidad suficiente/GPU.
- [ ] detector de GPU para aplicaciones que la requieran.

### Mapas / Tierra

- [ ] evaluar MapLibre GL JS.
- [ ] abrir GeoJSON.
- [ ] abrir GPX/KML cuando se implemente conversión segura.
- [ ] marcadores/capas.
- [ ] evaluar CesiumJS para globo 3D.
- [ ] evaluar NASA GIBS para capas satelitales.
- [ ] política de proveedores de tiles.
- [ ] evitar convertir la EC2 web en servidor GIS pesado sin preflight.
- [ ] nodo GIS separado si datasets/raster superan capacidad.

### Radio y señales

- [ ] evaluar OpenWebRX+.
- [ ] interfaz de espectro/waterfall.
- [ ] recepción SDR remota.
- [ ] definir arquitectura con receptor físico externo.
- [ ] no asumir que una EC2 AWS puede recibir RF sin hardware.
- [ ] control de permisos por usuario.
- [ ] documentar límites legales/operativos antes de habilitar transmisión.

### ADS-B / aeronaves

- [ ] evaluar readsb/tar1090 u otra pila libre.
- [ ] receptor físico externo.
- [ ] mapa de aeronaves.
- [ ] ingestión autenticada hacia ArcadeCloud.
- [ ] no depender de hardware de radio inexistente en EC2.

## Aplicaciones client-side y server-side

Preferencia general:

### Ejecutar en navegador cuando sea razonable

- edición ligera de imagen;
- SVG;
- diagramas;
- pizarras;
- mapas;
- visualización 3D;
- conversiones pequeñas;
- edición ligera multimedia.

Ventaja: CPU/GPU/RAM provienen del dispositivo del usuario y no del EC2.

### Ejecutar en servidor cuando sea necesario

- FFmpeg pesado;
- Office server;
- PDF pesado;
- conversiones grandes;
- GIS pesado;
- render 3D/GPU;
- procesos que requieran acceso seguro a archivos privados sin transferir todo al cliente.

Estas tareas deben pasar siempre por el sistema de capacidades.

## Resource Mode Manager

Pendiente de implementación.

Objetivo: evitar que varias aplicaciones pesadas derriben un nodo compartido.

Estados conceptuales:

```text
normal
office
multimedia
pdf-heavy
gis-heavy
gpu-job
```

Ejemplo inicial para FastDrive:

```text
office activo
  -> no iniciar FFmpeg pesado

FFmpeg activo
  -> no iniciar Office

trabajo incompatible solicitado
  -> encolar / informar / ofrecer cancelación
```

No debe depender de la memoria del operador.

## Seguridad transversal

Toda aplicación integrada deberá respetar:

- `user_id_` en cada apertura/lectura/escritura;
- no listar S3 para navegación normal;
- no exponer credenciales AWS al navegador ni a contenedores que no las requieran;
- no hacer públicos objetos S3;
- no aceptar rutas arbitrarias;
- protección contra traversal;
- protección SSRF donde exista red saliente controlada por usuario;
- tokens temporales;
- CSRF para operaciones autenticadas;
- límites de tamaño;
- MIME/extensión permitidos;
- locks cuando exista edición concurrente;
- secretos bajo `/etc/arcadecloud-drive/`;
- Docker sin puertos administrativos expuestos innecesariamente;
- logs auditables por superadmin.

## Orden de implementación propuesto

- [x] **Fase 0 — diseño y shell funcional del ArcadeCloud Web OS.**
- [x] Fase 0.1 — acciones principales de archivos/carpetas y herramientas base dentro del Web OS.
- [x] Fase 1 base — `NodeCapabilityService` y Mi nodo con capacidad/métricas reales.
- [ ] Fase 1.1 — ampliar perfiles de capacidad a todas las aplicaciones futuras.
- [ ] Fase 2 — `ResourceModeManager`.
- [ ] Fase 3 — nuevo shell/escritorio y ventanas.
- [ ] Fase 4 — Office.
- [ ] Fase 5 — diagramas + imagen + SVG.
- [ ] Fase 6 — audio + conversiones.
- [ ] Fase 7 — editor de video cliente.
- [ ] Fase 8 — PDF.
- [ ] Fase 9 — 3D.
- [ ] Fase 10 — mapas/satélite.
- [ ] Fase 11 — radio/ADS-B con hardware externo.
- [ ] Fase 12 — nodos GPU/GIS bajo demanda cuando hagan falta.

## Criterio de aceptación de cada nueva herramienta

No se considera integrada hasta que exista:

- [ ] launcher/entrada en el Web OS;
- [ ] permiso de usuario;
- [ ] validación `user_id_`;
- [ ] apertura segura del archivo;
- [ ] guardado seguro hacia ArcadeCloud;
- [ ] actualización `FileS3`;
- [ ] perfil de capacidad;
- [ ] conflicto de recursos definido;
- [ ] healthcheck;
- [ ] logs;
- [ ] instalación;
- [ ] actualización;
- [ ] recuperación;
- [ ] desinstalación;
- [ ] pruebas;
- [ ] documentación;
- [ ] CI verde;
- [ ] PR;
- [ ] merge a `main`;
- [ ] actualización de producción desde **Acerca de**.

## Regla de producción

No hacer hotfixes manuales en `/var/www/arcadecloud-drive` salvo emergencia explícita.

Flujo obligatorio:

```text
main limpio
  -> rama
  -> cambios
  -> pruebas
  -> PR
  -> CI verde
  -> merge
  -> Acerca de -> Actualizar
```
