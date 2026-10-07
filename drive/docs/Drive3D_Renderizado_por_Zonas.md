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
