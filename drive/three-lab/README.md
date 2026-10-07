# Laboratorio espacial de Drive 3D

Acceso con sesión de Drive: `/drive/three-lab/drive3d-lab.php` (o `/three-lab/drive3d-lab.php` cuando el document root es `drive`). No se agrega enlace ni se cambia el flujo de producción. Datos exclusivamente de demostración; sin escrituras a DB, S3 o preferencias.

## Geometría y controles

- Three.js 0.180.0 incluido localmente, licencia MIT en `vendor/LICENSE-three.txt`. Los dos módulos provienen de la distribución npm de esa versión; no hay solicitudes a CDN durante el uso.
- Unidades en metros; Y vertical. Domo hemisférico de radio 10 m, piso circular, 16 costillas y tres anillos. Cristal transparente sin máscara ni recorte CSS.
- Siete libreros rectangulares de 2.05 × 4.2 × 0.6 m sobre radio 7.25 m. Separación angular calculada desde las esquinas interiores, no desde el centro del mueble. Todos tienen la misma distancia al origen y miran hacia él. La cubierta deja más de 2 m libres sobre la esquina exterior superior.
- Panorama alpino generado como textura 2:1 sobre esfera invertida fija de radio horizontal 65 m, comprimida verticalmente para encuadrar el paisaje. También alimenta los reflejos de los materiales. Es una aproximación visual a la referencia; no garantiza una costura panorámica perfecta ni reproduce la ilustración píxel por píxel. No sigue la cámara ni los muebles. Puede sustituirse por una imagen equirectangular 2:1 con licencia adecuada; la imagen de referencia completa incluye muebles e interfaz y no es un panorama utilizable directamente.
- Lámpara geométrica a la derecha del último librero. Piso con anillos concéntricos que hacen visible el desplazamiento.
- Arrastrar: yaw/pitch con amortiguación. WASD: desplazamiento a nivel de ojos; flechas: rotación. Botones mantenidos para caminar en móvil. Los controles se liberan al perder foco, cancelar el gesto o cambiar de pestaña. Colisiones con libreros, lámpara, mesa central y límite del domo.
- Minimap de planta dibujado con las coordenadas y orientaciones reales de la escena, con cámara, cono de visión, muebles y lámpara. Los botones Frente / Extremos / Centrar permiten comparar puntos de vista.
- WebGL 2 requerido, resolución limitada a DPR 1.5; sin sombras dinámicas ni postprocesado. Reflejo planar real de 256 px en móvil y 512 px en escritorio. Piezas repetidas agrupadas con InstancedMesh. Mensaje visible ante fallo de carga o pérdida de contexto. Validar rendimiento en el teléfono físico antes de integrar en producción.

## Verificación

`node --input-type=module --check < drive/js/drive3d-three-lab.js`

`php -l drive/three-lab/drive3d-lab.php`

Con Playwright instalado: `PLAYWRIGHT_MODULE=/ruta/node_modules/playwright SCREENSHOT_DIR=/tmp/drive3d-shots node drive/three-lab/verify.cjs`.

El verificador sirve una **fixture HTML local sin ejecutar PHP**: verifica render WebGL, todas las esquinas bajo el domo, separación de cajas con SAT, inmovilidad de muebles y panorama, presets, arrastre, desplazamiento, botones móviles, minimapa y ausencia de errores JavaScript. No prueba sesión real ni conectividad DB. El workflow específico ejecuta también lint PHP y conserva capturas de frente, ambos extremos y móvil. Antes de migrar hay que abrir con y sin sesión en una instalación real y revisar el resultado en móvil físico.

## Migración posterior a dataword3d.php

1. Mantener intacta la autenticación, resolución de rutas, filtros por usuario, CSRF y servicios actuales del PHP. Nunca obtener archivos ajenos desde el cliente.
2. Ampliar `Drive3DThreeLab` como clase de escena con métodos `mount`, `setFolders`, `resize` y `dispose`. Desregistrar listeners, cancelar render loop y liberar geometrías, materiales, texturas y renderer al cerrar. Este laboratorio tiene ciclo de vida de página completa.
3. Sustituir `titles` por el modelo de carpetas **ya autorizado** que entrega `dataword3d.php`. Mapear cada `Group.userData` a su identificador/ruta validada; la etiqueta debe seguir dibujándose como texto. Añadir raycasting para delegar la selección/apertura a los manejadores existentes.
4. Mantener esta geometría y cámara como un único mundo: no rotar estantes para simular el giro. Para más carpetas, paginar grupos de siete; no comprimirlos hasta solapar ni agregar una segunda fila accidental.
5. Separar HUD HTML de la escena WebGL, conservar foco/teclado y comportamiento móvil. No conectar todavía subir, borrar, compartir ni APIs de modificación desde el laboratorio.
6. Sustituir el panorama generado por otro 2:1 si se requiere más fidelidad, con límites de resolución, manejo de error y permisos para imágenes privadas. No estirar la captura de referencia como fondo 360.
7. Persistir cámara únicamente en preferencias del usuario/nodo existentes, con límites de posición válidos. Comparar planta, giro, extremos y colisiones antes de activar por opción experimental.
8. Introducir la escena con feature flag y conservar temporalmente el renderer actual como fallback. Cambiar el flujo principal solo después de aceptación visual y prueba de rendimiento. Retirar el laboratorio sería simplemente eliminar estos archivos y su workflow.

## Acabado inspirado en la referencia

Libreros elevados con veta procedural de madera, molduras de latón, fondos cálidos por compartimento, lomos oscuros con detalles dorados y pequeños globos ornamentales. Mesa central de mármol y latón con esfera azul geométrica, retícula y puntos luminosos. Piso con mapa de mármol, líneas radiales y reflejo planar real mediante Reflector de Three.js 0.180.0. Plantas geométricas laterales. HUD reducido para dar espacio a la biblioteca.

`assets/alpine-panorama.jpg` fue generado con la herramienta integrada de imágenes y convertido a JPEG para distribución local. Prompt: “Equirectangular 360 degree 2:1 panorama, photorealistic alpine lake, snow capped mountains, wooded islands, distant fantasy castle and waterfall, warm late afternoon light, horizon at middle, continuous edges, outdoor scenery only, no room, furniture, UI or text.” No imágenes privadas ni recursos externos al cargar la página.

`vendor/Reflector.js` procede de `three@0.180.0/examples/jsm/objects/Reflector.js` bajo la licencia MIT incluida. Único ajuste: import local de Three.js. El diagnóstico añade `table` y `environmentReady` para probar colisión y carga de la textura. Este acabado sigue siendo un laboratorio con datos ficticios; no se han activado acciones sobre carpetas reales.
