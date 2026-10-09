# Drive 3D: renderizado por zona visible y nivel de acercamiento

## Objetivo de Jimmy

La biblioteca debe funcionar como un mapa que muestra una ciudad por niveles: desde lejos se ve su organización general; al acercarse se carga la calle observada, no todos sus edificios. En Drive 3D, el mapa general representa carpetas; los libreros representan esas carpetas; sus libros y miniaturas se cargan únicamente cuando se necesitan.

## Comportamiento implementado en dataword3d.php

1. **Catálogo ligero:** se conservan nombres, rutas autorizadas y ángulos de las carpetas del nivel actual. No se descargan los archivos de todas las carpetas. Una plantilla compartida construye la estructura del librero visible.
2. **Vista general:** se montan como máximo siete libreros dentro de un sector de ±85° respecto a la mirada. Los demás quedan fuera del DOM y sin hijos, imágenes ni detalles. No se solicitan previews de archivos en esta vista.
3. **Acercamiento:** al seleccionar un librero o avanzar al menos el 45% del recorrido disponible, se permite detalle en un máximo de tres libreros del sector observado. El librero seleccionado tiene prioridad. Se conserva el significado de abrir archivo/carpeta y los permisos existentes.
4. **Respuesta acotada:** cada preview devuelve como máximo seis subcarpetas y veinte archivos, conservando los conteos totales. Es una vista previa, no un listado exhaustivo; abrir la carpeta permite acceder a su contenido mediante el flujo existente.
5. **Movimiento:** girar, avanzar, retroceder o desplazarse lateralmente vuelve a evaluar el sector. Se esperan 160 ms de estabilidad antes de iniciar las peticiones automáticas para evitar descargar durante un arrastre continuo.
6. **Descarga de recursos:** salir del sector elimina los hijos del librero y sus miniaturas, borra su preview de la caché y cancela la petición pendiente mediante AbortController. Las respuestas antiguas no pueden volver a insertar una zona que ya salió de la vista. La caché tiene un máximo de tres previews.
7. **Mapa de orientación:** el radar y la escena usan los mismos ángulos. Los marcadores se distribuyen por el perímetro; tocar uno orienta la cámara hacia ese librero.

## Corrección de composición

Se retira la cúpula visual superpuesta que nublaba el panorama. La escena establece capas independientes para que el fondo y sus ventanas queden detrás de los libreros completos. El panel izquierdo es flotante y contiene únicamente Entorno y el usuario. Las acciones de la barra superior permanecen allí.

## Límites y siguiente integración

Esta entrega aplica virtualización y carga diferida a la vista CSS de producción `dataword3d.php`; no sustituye silenciosamente esa vista por el laboratorio Three.js. El catálogo de carpetas del nivel actual permanece en memoria porque alimenta el mapa de orientación, y el servidor conserva su consulta de jerarquía autorizada existente. No se afirma que ya exista paginación espacial de toda la jerarquía en la base de datos ni streaming por bytes de cada archivo.

El cono angular es un filtro conservador de visibilidad; no equivale a una prueba de oclusión exacta por cada píxel. Los documentos que el usuario abre explícitamente en el visor mantienen su propio ciclo de vida. El navegador decide cuándo libera su caché interna de imágenes; la aplicación elimina sus referencias.

Para trasladar esta política a Three.js: usar Frustum para seleccionar celdas visibles, distancia para LOD, un presupuesto de objetos/texturas por dispositivo y dispose() para geometrías/materiales/texturas sin referencias compartidas. Mantener una capa ligera del mapa, cargar contenidos solo al observarlos y cancelar trabajo al cambiar de zona. El nivel de detalle nunca debe eludir autenticación ni permisos por usuario.

## Verificación

Prueba Chromium con 24 carpetas: cero peticiones de detalle en vista general; máximo siete libreros montados; máximo tres previews; eliminación de hijos fuera de vista; cancelación y rechazo de respuestas antiguas durante giros rápidos; marcadores distribuidos por el círculo; cúpula oculta; panel flotante en móvil. Capturas de escritorio y móvil se conservan en el workflow «Drive 3D visible zones». La fixture prueba frontend con respuestas controladas; no sustituye la comprobación autenticada contra S3 y DB en la instalación.

## Integración WebGL en dataword3d.php — 7 octubre 2026

La vista de producción usa ahora `Drive3DScene` (Three.js local) mediante
`drive3d-production.js`. El laboratorio comparte ese motor. La antigua escenografía
CSS queda oculta: no se superpone cristal ni se deforma una imagen de mobiliario.
Cámara PerspectiveCamera, muebles BoxGeometry, mesa con cilindros, piso circular
reflectante, costillas estructurales y panorama esférico fijo comparten coordenadas.
La cámara inicia a distancia de los muebles para mostrar piso, ventanas y techo.

Los títulos proceden de los descriptores de carpetas autenticados existentes. El
raycasting selecciona el librero real y usa el controlador existente para cargar
subcarpetas/archivos y ejecutar Abrir, Descargar y visores. Los libros decorativos
no se presentan como archivos concretos: los elementos reales aparecen al seleccionar
en una bandeja de contenido con nombres y acciones. No se modifican permisos ni DB.

El presupuesto del motor sustituye el límite anterior de siete nodos CSS por
**nueve libreros de geometría**, escogidos por frustum y proximidad. Fuera de esa
selección se liberan instancias, geometrías, materiales y texturas de títulos;
los materiales compartidos permanecen. Los metadatos de ubicación son ligeros.
Hasta tres zonas tienen previews de datos, con los límites de API anteriores,
AbortController y rechazo de respuestas obsoletas. La mesa y arquitectura son
recursos compartidos permanentes; no se descarga todo el catálogo de archivos.
El minimapa dibuja posiciones/orientaciones reales, mesa, lámpara y cámara.

Arrastrar mira; WASD camina con colisiones; flechas giran; controles táctiles del
radar desplazan la cámara. Seleccionar orienta sin teletransportarse a través de
muebles. Centro devuelve la vista inicial. Sin WebGL se muestra un error con acceso
a Vista clásica; no se muestra la composición CSS como supuesto 3D real.

Validación automatizada: `drive/tests/drive3d_production_browser.cjs`, con markup
real y catálogo/API simulados, comprueba WebGL, selección por rayo, acciones de datos,
liberación de texturas, giro/panorama fijo, movimiento y capturas móvil/escritorio.
No sustituye una prueba autenticada con S3/DB en el servidor de producción.

## Correcciones de 9 octubre 2026: libreros, archivos y fondos

- Cuando el nivel tiene subcarpetas, los archivos de la carpeta **actual** se muestran como tarjetas en el espacio libre por encima de los libreros; en niveles sin subcarpetas mantienen la presentación frontal. La distribución deja separación real entre tarjetas.
- El preview de un librero puede mostrar hasta tres fotografías de su propia carpeta, suspendidas justo encima de su corona de madera. Las miniaturas se cargan de forma diferida desde `thumb.php`; al salir de la zona se descartan recursos gráficos y respuestas tardías.
- Los archivos no fotográficos muestran su representación tipológica a partir de `FileIconResolver`/extensión (PDF, Office, texto, código, comprimidos, audio, video y archivos protegidos), con una marca vectorial legible incluso sin la fuente Font Awesome.
- El clic deja de seleccionar el elemento cercano por proximidad 2D; ahora compara el toque con el polígono proyectado de la tarjeta en WebGL. Los gestos de rotación no producen una segunda selección sintética.
- La carpeta de fondos no puede deducirse como `Data.../Imagenes/fondos3D/` porque los prefijos físicos de `S3Folders` son opacos. `Drive3dBackgroundFolderService` busca hijas directas por `Nombre`: `Imagenes` bajo raíz del usuario y `fondos3D` bajo la primera. Para subir, sólo crea una carpeta si falta mediante `FolderMutationService`; nunca actualiza otra carpeta homónima ni inventa su prefijo. Si existen duplicados en el mismo nivel, se informa un conflicto.
- La textura panorámica de los cristales ahora se proyecta justo por detrás de las costillas de la cúpula; su costura longitudinal se sitúa bajo el meridiano de madera a +90°, evitando que se vea como una raya al recorrer la habitación. El fondo del piso conserva su propio material y ajustes.
- Pruebas: `drive/tests/drive3d_background_folder_test.php` verifica resolución del catálogo sin conexión AWS; `drive/tests/drive3d_production_browser.cjs` valida capturas, colocación, vista previa y selección con pulsaciones reales.

La prueba automatizada de navegador utiliza datos de muestra. Después de fusionar debe validarse visualmente con la carpeta y fotografías privadas reales de un usuario en la instalación de producción.

## 9 de octubre de 2026 — galería aérea y superficies seleccionables

La miniatura de una imagen de una **subcarpeta** forma parte de la vista previa de ese librero (carga diferida, como máximo tres miniaturas). Su borde inferior queda **90 cm por encima** de la parte superior del mueble, con separación física y sin invadir los libros. La galería de archivos del **nivel actual** conserva las tarjetas elevadas alrededor del anillo cuando hay libreros. Si no hay libreros, los archivos siguen apareciendo enfrente del usuario.

En una imagen abierta con `Traer al escritorio`, el selector de la barra permite:

- **Cuadro libre**: la ventana flotante clásica, se desplaza/redimensiona y conserva su posición.
- **Tapete en piso**: seleccionar el modo y tocar el piso. El motor dibuja una malla plana a 6,5 cm de altura con la textura autenticada; el botón *Mover* permite tocar otro lugar y `+`/`−` cambia su tamaño.
- **Techo del domo**: mirar arriba y tocar un sector superior; la imagen se curva sobre la cara interna del techo, contenida por los meridianos de madera.
- **Ventana del domo**: mirar un cristal entre dos costillas y tocarlo; se identifica el sector y la banda, se crea una malla curvada que no atraviesa los marcos estructurales. *Mover* pide tocar otra ventana; `+`/`−` redimensiona dentro de los límites del panel.

El toque se convierte en rayo desde la cámara real. Para piso se calcula la intersección con el plano horizontal; para techo/cristales se calcula la intersección con la esfera interior y se identifica una celda de 16 segmentos angulares, con tres bandas de ventana y un tramo superior de techo. Al situar una imagen, su ventana deja de cubrirla y pasa a ser una pequeña barra de control, que sigue la proyección de la imagen en la sala. Tocar una malla anclada selecciona su barra.

Una selección no se guarda hasta que se toca efectivamente una superficie válida. Los datos que se guardan en `spatialImages` son `mode`, `panelId`, `surfaceScale`, `world`, más identificador, nombre, ruta y tamaño ya existentes. `Drive3dPreferenceSanitizer` rechaza URLs externas, modos o sectores inválidos. Las entradas de preferencias anteriores siguen siendo cuadros libres. La imagen se lee desde `ver_archivo.php` autenticado, sin nuevas rutas públicas, tablas ni operaciones extra sobre S3.

El límite de cuadros simultáneos se mantiene en doce; las mallas se destruyen al quitar su imagen y las texturas asíncronas fuera de uso se liberan. El visor de imágenes clásico de `so.php` conserva por separado el ajuste proporcional implementado en `ArcadeCloudImageWindowFit`.

**Comprobación**: `drive/tests/drive3d_surface_picture_test.php` prueba persistencia y validación de sectores, además de la integración WebGL/Chromium de `drive/tests/drive3d_production_browser.cjs`. Sigue siendo necesaria la revisión visual del funcionamiento en un móvil Android con fotos privadas reales tras desplegar a producción.
