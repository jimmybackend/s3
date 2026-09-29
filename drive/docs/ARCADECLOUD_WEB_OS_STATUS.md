# ArcadeCloud Web OS — estado funcional actual

Estado: **implementado y operativo como experiencia principal del Drive**.

Fecha de referencia: **28 de septiembre de 2026**.

Este documento describe lo que **ya funciona hoy** en ArcadeCloud Web OS. No es una lista de ideas ni un mockup. Para propuestas futuras de aplicaciones y nodos especializados consulta `ARCADECLOUD_WEB_OS_ROADMAP.md`.

## 1. Qué es ArcadeCloud Web OS

ArcadeCloud Web OS es la interfaz de escritorio web de ArcadeCloud Drive.

No sustituye el backend ni crea una segunda fuente de verdad. Sigue usando la arquitectura existente:

```text
Navegador
  -> so.php
      -> Controllers / Services / Repositories
          -> MySQL
          -> Amazon S3
          -> FederationCloud
          -> servicios AWS
          -> workers en background
```

Principios que no cambian:

- MySQL es la fuente de verdad para navegación y catálogo.
- S3 conserva los objetos físicos.
- la navegación normal no lista S3;
- cada operación se limita al `user_id_` autenticado;
- las raíces `Data/`, `Data2/`, ..., `DataN/` se resuelven por usuario;
- las operaciones pesadas continúan en servicios/workers del servidor;
- FederationCloud sigue siendo una capa separada de identidad, descubrimiento, acceso y réplica.

## 2. ArcadeCloud OS es ahora la entrada principal

Después de un login correcto, ArcadeCloud abre **`so.php`** como experiencia principal.

El Drive clásico **`s3.php`** no se eliminó. Continúa disponible desde el propio SO para compatibilidad, administración y cualquier flujo que todavía no se haya trasladado al escritorio.

Esto permite evolucionar el sistema sin romper las funciones históricas.

## 3. Shell de escritorio

El shell ya está implementado y funciona tanto en escritorio como en móvil.

Incluye:

- barra de tareas;
- menú del engranaje;
- launcher de aplicaciones;
- ventanas internas;
- minimizar;
- maximizar;
- restaurar;
- cerrar;
- mover ventanas cuando el dispositivo lo permite;
- comportamiento adaptado a pantalla pequeña;
- Centro de Tareas;
- reloj;
- acceso al Drive clásico.

En la barra de tareas, el menú de tres puntos refleja el estado real de la ventana:

```text
ventana abierta
  -> Minimizar
  -> Cerrar

ventana minimizada
  -> Maximizar
  -> Cerrar
```

## 4. Menú del engranaje

El menú del engranaje incluye actualmente, entre otras opciones:

- acceso al Drive clásico;
- **Actualizar**, que recarga la interfaz completa;
- **Acerca de**;
- cierre de sesión.

El panel **Acerca de / Actualizar** reutiliza el actualizador seguro existente de ArcadeCloud. No existe un segundo mecanismo de actualización para el SO.

La actualización continúa usando el flujo normal:

```text
rama
  -> PR
  -> CI
  -> merge a main
  -> Acerca de / Actualizar
```

## 5. Mis datos

**Mis datos** es el Explorador de archivos del Web OS.

La navegación sigue siendo DB-first:

```text
Mis datos
  -> FileListService / FolderQueryService
      -> FileS3 / S3Folders
```

### Navegación

Actualmente ofrece:

- ruta visible de la carpeta en la parte superior;
- subir a carpeta padre;
- paginación;
- 20 archivos por página;
- hasta tres números de página visibles;
- navegación AJAX sin recargar todo el escritorio;
- actualización de la historia del navegador;
- carpetas visibles de la ruta actual;
- miniaturas cuando el tipo lo permite.

El orden de los archivos es determinista:

```sql
Fecha DESC, id_ DESC
```

Esto permite localizar correctamente un resultado de búsqueda incluso en carpetas con muchas páginas.

### Barra de carpeta

La barra fija de la carpeta se mantiene deliberadamente compacta.

Acciones principales:

- **Subir**;
- **Información**.

Las acciones de portapapeles aparecen sólo cuando son relevantes:

- **Mover aquí** cuando hay elementos cortados;
- **Pegar aquí** cuando hay elementos copiados.

La paginación continúa en la misma zona de navegación.

Las acciones múltiples de descarga/eliminación no ocupan permanentemente esta barra; aparecen desde el contexto de la selección.

### Información de carpeta

El botón de información muestra datos calculados desde el catálogo actual, por ejemplo:

- ruta;
- cantidad de archivos;
- peso;
- subcarpetas;
- visibles;
- bloqueados;
- protegidos;
- total filtrado/paginado.

## 6. Selección múltiple y portapapeles

Los archivos pueden seleccionarse de forma múltiple.

Comportamiento:

- primer clic/tap: seleccionar o quitar selección;
- segundo clic rápido sobre el mismo archivo: abrir/reproducir;
- pulsación larga: menú contextual en dispositivos táctiles;
- la selección visual se mantiene en la página actual.

Las operaciones de copiar/cortar capturan las keys seleccionadas y sobreviven a la navegación hacia otra carpeta.

El destino se elige después con:

- **Pegar aquí** para copia;
- **Mover aquí** para corte.

El movimiento/copia se ejecuta mediante la infraestructura de jobs existente.

### Nota sobre selección entre páginas

La selección visual actual pertenece a la página cargada. No se mantiene una selección acumulada entre varias páginas del paginador.

## 7. Acciones de archivos

El menú contextual del archivo puede ofrecer, según formato, estado y permisos:

- abrir;
- reproducir audio/video;
- editar cuando existe editor compatible;
- descargar;
- copiar;
- cortar/mover;
- compartir;
- eliminar;
- acciones multimedia;
- protección/desbloqueo;
- volver a bloquear;
- quitar protección;
- abrir en Drive clásico.

Para audio y video la acción principal se presenta como **Reproducir**, no como “Abrir”.

## 8. Seguridad de archivos compartida con s3.php

La seguridad de archivo ya no tiene una implementación simplificada distinta en el SO.

Se extrajo a un módulo compartido:

```text
js/file-security.js
```

Lo usan tanto `s3.php` como `so.php`.

El panel permite:

- proteger con contraseña;
- confirmar contraseña;
- guardar una pista;
- mostrar/ocultar contraseñas;
- desbloquear temporalmente;
- bloquear de nuevo;
- quitar protección.

Endpoints reutilizados:

```text
set_file_security.php
unlock_file.php
relock_file.php
```

Si se intenta abrir un archivo bloqueado desde Mis datos, el SO inicia el mismo flujo completo de desbloqueo sin obligar al usuario a regresar al Drive clásico.

## 9. Subidas dentro del SO

`s3.php` y `so.php` comparten el mismo centro de subida.

El botón **Subir** abre un panel con las formas de carga disponibles, entre ellas:

- archivo normal;
- varios archivos / Dropzone;
- subida desde URL;
- archivo grande multipart/chunks;
- contenido del portapapeles.

### Portapapeles

El portapapeles **no se inspecciona al entrar a una carpeta**.

Se consulta sólo cuando el usuario abre **Subir** o solicita volver a revisarlo.

Si contiene:

- una imagen: puede guardarse como `screenshot-AAAAMMDD-HHMMSS-mmm.png`;
- texto: puede guardarse como `clipboard-AAAAMMDD-HHMMSS-mmm.txt`.

### Destino inmutable

Cada subida captura la carpeta de destino al comenzar.

Si el usuario inicia una subida en una carpeta y después navega a otra, el archivo continúa destinado a la carpeta original.

### Subidas grandes

El multipart conserva sesión de reanudación.

Una transferencia que está enviando bytes desde el navegador requiere mantener la pestaña abierta; el estado multipart permite reanudar cuando corresponde.

## 10. Centro de Tareas

El Centro unificado de Tareas muestra trabajos activos e históricos de distintos subsistemas.

Puede incluir:

- movimientos/copias;
- sincronización;
- multimedia;
- Transcribe;
- Polly;
- subidas del navegador;
- mantenimiento del servidor.

Funciones actuales:

- estado;
- progreso;
- origen/destino cuando aplica;
- detener/cancelar cuando el backend lo permite;
- seleccionar tareas terminales;
- eliminar seleccionadas;
- limpiar terminadas/fallidas/canceladas.

Las tareas activas no se eliminan mediante la limpieza masiva.

## 11. Buscar como aplicación

**Buscar** existe como aplicación dentro de **Aplicaciones**.

Tiene dos modos:

### Búsqueda normal

Reutiliza el buscador del Drive:

```text
buscar_archivo.php
  -> FileSearchController
      -> FileSearchService
```

Admite nombres y patrones como:

```text
factura
fact*
*.pdf
*2026*
```

### Búsqueda con IA

Reutiliza:

```text
AiFileSearchService
```

La IA trabaja sobre el catálogo privado del usuario y metadatos candidatos. No obtiene permiso para listar S3.

### Ir al resultado

Al pulsar un resultado:

1. el servidor confirma que el archivo pertenece al usuario;
2. calcula la ruta real;
3. calcula en qué página de 20 archivos se encuentra;
4. abre **Mis datos** en esa carpeta;
5. abre la página correcta;
6. resalta temporalmente el archivo.

Por tanto, un resultado puede llevar al usuario directamente a un archivo situado, por ejemplo, en la página 7 de una carpeta grande.

## 12. Compartir y FederationCloud

La acción **Compartir** del SO ya no usa prompts simples del navegador.

Abre un panel integrado con:

- vigencia;
- fecha de expiración;
- enlace directo;
- copiar enlace.

Además reutiliza el módulo de ArcadeLink/FederationCloud que ya usa el Drive clásico.

Puede conservar las opciones existentes de:

- visibilidad;
- política de descubrimiento;
- derechos;
- creación/descarga de `.arcadelink`;
- publicación FederationCloud cuando la política lo permite.

El SO no implementa un segundo sistema de sharing: reutiliza los endpoints y servicios existentes.

## 13. FederationCloud dentro del SO

FederationCloud está disponible como aplicación/ventana del escritorio.

El objetivo es mantener dentro del mismo entorno las funciones que ya existen en la federación:

- catálogo/búsqueda global;
- solicitudes;
- compartidos;
- réplicas;
- moderación;
- datos del nodo;
- nodos conectados;
- configuración autorizada.

La lógica federada continúa bajo sus Controllers/Services/Repositories y no se duplica dentro de `so.php`.

## 14. Mi nodo

**Mi nodo** muestra el estado actual del servidor que atiende la sesión.

Las métricas se vuelven a consultar al abrir/restaurar la ventana; no son sólo una fotografía tomada al cargar la página.

Información disponible:

- hostname/instancia;
- tipo de EC2 cuando puede detectarse;
- vCPU;
- RAM total;
- RAM disponible;
- disco total;
- disco usado y porcentaje;
- disco libre;
- swap;
- carga;
- rol;
- Instance ID;
- FFmpeg;
- FFprobe;
- Docker;
- GPU cuando existe.

Endpoint:

```text
node-status.php
  -> NodeStatusController
      -> NodeCapabilityService
```

## 15. Mantenimiento seguro desde Mi nodo

El superadmin dispone de herramientas de mantenimiento controladas.

No existe una shell arbitraria detrás de estos botones. Las acciones pasan por el helper administrativo allowlisted.

### Liberar memoria

La escobilla de RAM reutiliza:

```text
memory-clear
```

Hace:

- `sync`;
- liberación de page cache;
- dentries;
- inodes.

No mata procesos.

### Liberar espacio de disco

La escobilla de disco reutiliza:

```text
disk-clean
```

El helper administrativo actual declara:

```text
version >= 12
capability: disk_cleanup
```

La limpieza es por lista blanca.

Puede retirar únicamente elementos regenerables/seguros, por ejemplo:

- temporales ArcadeCloud antiguos conocidos;
- temporales FederationCloud;
- ZIP temporales;
- documentos temporales;
- descargas S3 intermedias;
- caché de costos regenerable;
- logs rotados antiguos de rutas conocidas;
- journal archivado sujeto a límites.

No elimina:

- archivos del usuario;
- objetos S3;
- base de datos;
- configuración;
- sesiones;
- uploads activos;
- colas activas;
- logs actuales;
- `/tmp` completo.

## 16. Mantenimiento programado después de las tareas

Las limpiezas de memoria/disco usan una cola persistente:

```text
ServerMaintenanceJobStore
ServerMaintenanceService
ServerTaskActivityProbe
server_maintenance_worker.php
```

Antes de ejecutar se comprueba actividad de:

- sincronización;
- movimientos;
- multimedia;
- Polly;
- Transcribe.

Si existen tareas activas:

```text
Liberar memoria / disco
  -> Esperando tareas
      -> Centro de Tareas
          -> worker espera
              -> procesos terminan
                  -> mantenimiento
```

La operación no depende de que el modal permanezca abierto.

## 17. Nodo de cómputo grande y autoapagado

ArcadeCloud conserva el concepto de nodo web pequeño y nodo de cómputo especializado.

La política implementada para el nodo de alto rendimiento usa:

- **10 minutos** de inactividad;
- **30 segundos** de aviso antes del apagado.

`s3.php` y `so.php` participan en la detección de actividad interactiva.

El aviso ofrece:

- Seguir usando;
- Apagar ahora;
- cuenta regresiva.

Una tarea multimedia activa bloquea el apagado.

El apagado interactivo por inactividad sólo se acepta durante la ventana válida de inactividad. Adicionalmente, el perfil del superadmin ofrece un botón explícito de apagado de FastDrive que vuelve a pedir la contraseña actual, conserva los registros de tareas y solicita el stop de la EC2 fija sin permitir que el navegador elija un Instance ID.

Configuración relevante:

```text
ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS >= 600
```

## 18. Aplicaciones visibles hoy

El escritorio ya puede actuar como punto de entrada para herramientas internas.

Entre las piezas ya integradas o visibles se encuentran:

- Mis datos;
- Buscar;
- FederationCloud;
- Mi nodo;
- Centro de Tareas;
- Terminal restringida a superadmin;
- Acerca de;
- Linux XFCE (escritorio remoto autenticado);
- reproductores/visores internos;
- Bloc de notas / editor de texto donde corresponde.

## 19. Aplicaciones todavía futuras

No debe confundirse el shell operativo con integraciones que siguen siendo roadmap.

Aún son futuras o están en evaluación, entre otras:

- navegador remoto embebido dentro de una ventana del Web OS (Google Chrome ya está instalado dentro del escritorio XFCE);
- Collabora / ONLYOFFICE;
- OpenCut u otro editor de video completo;
- editor de imagen avanzado;
- PDF avanzado;
- diagramas;
- aplicaciones 3D/GIS;
- Resource Mode Manager genérico para todas las aplicaciones pesadas.

El Web OS actual ya está preparado como shell para incorporar estas herramientas, pero no deben documentarse como instaladas hasta completar su integración.

## 20. Archivos principales

Shell:

```text
drive/so.php
drive/js/so.js
drive/css/so.css
```

Explorador y carpetas:

```text
drive/js/so-folders.js
drive/js/so-clipboard.js
drive/js/move-tasks.js
```

Subidas:

```text
drive/js/upload-center.js
drive/js/upload-destination.js
drive/css/upload-center.css
```

Seguridad:

```text
drive/js/file-security.js
drive/set_file_security.php
drive/unlock_file.php
drive/relock_file.php
```

Búsqueda:

```text
drive/js/so-search.js
drive/buscar_archivo.php
drive/src/Http/Controller/FileSearchController.php
drive/src/Application/FileSearchService.php
drive/src/Application/AiFileSearchService.php
```

Nodo/mantenimiento:

```text
drive/js/so-node.js
drive/node-status.php
drive/src/Http/Controller/NodeStatusController.php
drive/src/System/NodeCapabilityService.php
drive/src/Admin/ServerMaintenanceJobStore.php
drive/src/Admin/ServerMaintenanceService.php
drive/src/Admin/ServerTaskActivityProbe.php
drive/bin/server_maintenance_worker.php
```

Tareas:

```text
drive/js/background-tasks.js
drive/background_tasks.php
drive/src/Http/Controller/BackgroundTaskController.php
```

## 21. Pruebas y CI

El Web OS tiene contratos específicos bajo:

```text
drive/tests/web_os_contract_smoke.php
drive/tests/web_os_clipboard_contract_smoke.php
```

Los cambios recientes también pasan las baterías aplicables de:

- Security hardening;
- multimedia / FFmpeg;
- autenticación;
- instalador/setup;
- FederationCloud/ArcadeLink;
- actividad/costos;
- UI privada.

La regla sigue siendo: una función no se considera terminada sólo porque “se ve” en el navegador; debe mantener los contratos backend y pasar CI.

## 22. Resumen del estado

La transición desde “gestor de archivos web” hacia “ArcadeCloud Web OS” ya cruzó la etapa de prototipo.

Hoy existe un escritorio funcional donde:

```text
login
  -> ArcadeCloud OS
      -> Mis datos
      -> Buscar normal / IA
      -> compartir / FederationCloud
      -> seguridad de archivos
      -> subidas
      -> tareas
      -> Mi nodo
      -> mantenimiento
      -> actualización
```

El siguiente paso de evolución ya no es “construir el shell”. El shell existe.

El trabajo futuro consiste principalmente en **sumar aplicaciones** al entorno —Office, navegador remoto, editores y herramientas especializadas— sin romper la arquitectura DB-first, la seguridad multiusuario, el Centro de Tareas ni la separación entre nodo web y nodo de cómputo.
