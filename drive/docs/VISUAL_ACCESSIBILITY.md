# Accesibilidad visual de ArcadeCloud Drive

ArcadeCloud Drive incluye perfiles visuales orientados a mejorar la legibilidad de la interfaz. Estos perfiles no simulan una deficiencia visual ni sustituyen una correccion optica; ajustan presentacion, contraste, espaciado y tamano de controles.

## Miopia - lectura clara

El perfil `vision-myopia` elimina el desenfoque que antes se utilizaba para representar visualmente la miopia. En su lugar prioriza texto nitido, contraste, espaciado y una tipografia de sistema mas legible.

## Vista cansada

El perfil `vision-presbyopia` aumenta moderadamente el tamano del texto, la altura de linea, el contraste y el area tactil de botones y controles. En pantallas moviles aplica un incremento adicional para facilitar la lectura a corta distancia.

## Persistencia

La seleccion continua guardandose en `localStorage` mediante `ui-theme-state`, igual que los otros perfiles de vision.

## Implementacion

- `drive/js/estilo.js`: registra `vision-presbyopia`, agrega la opcion de interfaz y carga la hoja de estilos accesible.
- `drive/css/vision-accessibility.css`: contiene los ajustes asistivos para miopia y vista cansada.

Los perfiles de color existentes (`protanopia`, `deuteranopia`, `tritanopia`) se mantienen sin cambios en esta iteracion y deben revisarse por separado antes de modificar su comportamiento.
