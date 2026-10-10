# ArcadeCloud Drive 3D — recursos visuales reales

El motor `drive/js/drive3d-scene.js` busca los assets bajo `drive/three-lab/assets/environments/` y utiliza el renderizador procedural anterior cuando un archivo todavía no existe.

## Panoramas equirectangulares 2:1

- panoramas/alpine-spring.jpg
- panoramas/alpine-summer.jpg
- panoramas/alpine-autumn.jpg
- panoramas/alpine-winter.jpg
- panoramas/sunset.jpg
- panoramas/night.jpg
- panoramas/prehistoric.jpg
- panoramas/future.jpg

## Materiales del suelo 1:1

- floors/water.jpg
- floors/grass.jpg
- floors/clouds.jpg
- floors/sand.jpg
- floors/snow.jpg

Los panoramas generados miden 1774 × 887 píxeles y las texturas miden 1254 × 1254 píxeles. Los formatos son JPEG progresivos, con compresión para navegador móvil.

Los 13 JPEG están incluidos físicamente en el repositorio. El panorama alpino
original y el mármol siguen disponibles como opciones predeterminadas. Los
archivos se conservan sin recortar, invertir ni recomprimir respecto al ZIP.
