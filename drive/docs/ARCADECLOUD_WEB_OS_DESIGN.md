# ArcadeCloud Web OS — diseño base

Estado: **shell implementado y operativo**. La descripción actual de producción vive en `ARCADECLOUD_WEB_OS_STATUS.md`; este documento conserva las decisiones de diseño que dieron origen al shell.

## Objetivo

ArcadeCloud ya cambió la experiencia principal desde una página tradicional de gestor de archivos hacia un **escritorio web**, conservando la arquitectura backend existente.

No se reemplazan `FileS3`, `S3Folders`, S3, sesiones, permisos ni FederationCloud. El escritorio será una nueva capa de presentación y orquestación.

## Concepto visual

Pantalla principal propuesta:

```text
┌──────────────────────────────────────────────────────────────┐
│ ArcadeCloud                         Nodo: FastDrive   usuario │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  [ Mi nodo ]     [ Data / DataN ]    [ Papelera ]    │
│                                                              │
│  Escritorio                                                   │
│                                                              │
│     ventanas de aplicaciones                                 │
│                                                              │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│ ☰  Archivos  Office  Imagen  Audio  Video  Diagramas   Tareas│
└──────────────────────────────────────────────────────────────┘
```

La apariencia puede recordar a un equipo de cómputo, pero la terminología debe dejar claro cuándo se está operando sobre:

- almacenamiento del usuario;
- nodo local;
- servicio remoto/federado;
- aplicación client-side;
- aplicación server-side.

## Mi nodo

**Mi nodo** será la entrada humana al entorno del usuario autenticado.\n\nArcadeCloud resuelve internamente su raíz y jamás enumera las raíces de otros usuarios. Puede mostrar:

```text
Mi nodo\n  ├─ Mis archivos
  │   └─ DataN/
  ├─ Compartidos
  ├─ FederationCloud
  ├─ Tareas
  ├─ Aplicaciones
  └─ Mi nodo
```

**Mi nodo** será la vista de infraestructura autorizada.

Ejemplo para superadmin:

```text
FastDrive
  Estado: activo
  CPU: 4 vCPU
  RAM: 7.6 GiB
  Disco: ...
  Rol: combined
  Capacidades:
    Multimedia  READY
    Office      NOT READY / READY
    PDF         ...
    GPU         NOT AVAILABLE
```

Los valores deberán provenir del backend; nunca codificarse en la UI.



### Raíz visible del usuario

El escritorio no muestra un selector global con `Data/`, `Data2/`, ..., `DataN/`. La raíz se calcula exclusivamente desde el `user_id_` autenticado mediante `UserStoragePath::rootForUser()` y sólo esa raíz puede navegarse en la sesión. Los nombres físicos de otras raíces no se presentan al usuario.

## Explorador de archivos

El Explorador continuará navegando por MySQL.

```text
Desktop/File Explorer
  -> FileListService / FolderQueryService
      -> FileS3 / S3Folders
```

No listar S3 para dibujar carpetas.

Una carpeta podrá abrirse dentro de una ventana sin recargar todo el escritorio.

## Ventanas

Cada aplicación deberá abrirse dentro de una abstracción común de ventana.

Propiedades previstas:

```text
app_id
title
icon
file_id opcional
window_id
minimizable
maximizable
resizable
single_instance / multi_instance
capability_profile
```

Acciones:

- minimizar;
- maximizar/restaurar;
- cerrar;
- mover;
- cambiar tamaño;
- recordar posición opcionalmente;
- mostrar estado cargando/error;
- enviar evento de guardado al shell.

No permitir que una aplicación incrustada manipule directamente otra ventana.

## Barra de aplicaciones

Primera propuesta:

```text
Inicio
Explorador
Office
Imagen
Audio
Video
PDF
Diagramas
3D
Mapas
Federación
Centro de Tareas
```

La barra no debe mostrar aplicaciones que el nodo no pueda soportar como si estuvieran listas.

Estados visuales:

```text
Disponible
Preparando
En uso
No disponible en este nodo
Requiere autorización
Requiere nodo especializado
```

## Registro de aplicaciones

Debe existir un registro central, no botones hardcodeados dispersos.

Concepto:

```text
AppRegistry
  office
    extensions: docx,xlsx,pptx,odt,ods,odp
    execution: server
    capability: office
    conflicts: multimedia-heavy
  image-editor
    execution: browser
    extensions: jpg,jpeg,png,webp
  audio-editor
    execution: browser
    extensions: mp3,wav,ogg,flac
```

El registro podrá decidir qué acciones aparecen para cada archivo.

## Apertura contextual

Ejemplo:

```text
documento.docx
  -> Abrir
  -> Editar con Office
  -> Descargar
  -> Compartir
  -> Propiedades
```

```text
video.mp4
  -> Reproducir
  -> Editar video
  -> Dividir
  -> Extraer MP3
  -> Convertir
  -> Descargar
```

```text
diagrama.drawio
  -> Editar diagrama
  -> Exportar
  -> Compartir
```

Las acciones se calculan por formato + permisos + capacidades reales del nodo.

## Apps en navegador

Cuando una aplicación pueda ejecutarse enteramente en el cliente:

```text
FileS3
  -> endpoint temporal autenticado
  -> navegador
  -> aplicación
  -> resultado
  -> endpoint de guardado
  -> S3 + FileS3
```

No entregar credenciales AWS.

## Apps server-side

Cuando una aplicación requiera servidor:

```text
usuario
  -> AppLaunchService
  -> NodeCapabilityService
  -> ResourceModeManager
  -> autorización si aplica
  -> servicio/contendor
  -> archivo mediante API ArcadeCloud
```

## Nodo profesional

FastDrive puede actuar como nodo profesional sólo cuando la aplicación pasa el preflight.

No se asume que `fastdrive.esforzados.com` siempre será una `c7i.xlarge`. Si mañana cambia el tipo de instancia, la UI debe adaptarse automáticamente.

## Federación futura de capacidades

Objetivo posterior:

```text
Nodo A necesita Office
  -> no tiene capacidad
  -> FederationCloud conoce nodo autorizado con Office
  -> usuario/política permiten delegación
  -> sesión temporal
  -> edición/proceso
  -> resultado vuelve al nodo dueño
```

Esto no forma parte de la primera versión del escritorio, pero el diseño no debe bloquearlo.

## Persistencia del escritorio

Primera versión:

- estado de ventanas en navegador;
- no guardar posiciones sensibles en S3;
- al recargar puede restaurarse sólo lo necesario;
- los archivos continúan en FileS3/S3;
- las aplicaciones no se convierten en nuevas fuentes de verdad.

Más adelante podrá guardarse una preferencia de escritorio por usuario.

## Responsive

El Web OS debe seguir siendo usable en móvil.

En móvil:

- una ventana ocupa prácticamente toda la pantalla;
- barra de tareas compacta;
- launcher tipo menú;
- no depender de drag-and-drop como única interacción;
- botones grandes;
- mantener modos de alto contraste existentes.

## Primer entregable funcional de la Fase 0

La Fase 0 ya está completada como baseline funcional:

- [x] shell de escritorio;
- [x] barra de tareas;
- [x] launcher;
- [x] ventana de Explorador / Mis datos;
- [x] Centro de Tareas;
- [x] ventana Mi nodo con métricas en vivo;
- [x] integración con archivos y carpetas existentes;
- [x] acciones actuales siguen funcionando mediante servicios/endpoints existentes;
- [x] fallback explícito al Drive clásico;
- [x] sin una segunda fuente de verdad ni cambios del principio DB-first.

El shell ya es estable y es la experiencia principal después del login. Las aplicaciones nuevas se conectan sobre este contrato en lugar de crear interfaces paralelas.

Estado funcional detallado: `drive/docs/ARCADECLOUD_WEB_OS_STATUS.md`.
