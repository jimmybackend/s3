# Web/PWA y propuesta Android/iOS

## Evaluación actual

La Web aún no se considera consolidada para empaquetar. OS tiene CSS para teléfono/
tablet, ventanas, barra de tareas, selección y módulos compartidos. Esta revisión
no encontró manifest ni registro de service worker en el código inspeccionado de
drive; no se ha comprobado instalación PWA en dispositivos reales.

Pasaron regresiones JS de ventanas, búsqueda normal/IA, selección y operaciones.
No equivalen a pruebas visuales/táctiles. El entorno local tenía Playwright sin
binario de navegador: no se completó la revisión visual y no se cambió CSS por
suposición. Faltan aceptación de teléfono/tablet, recuperación real, papelera,
failover multinodo y coordinación completa con autoapagado.

## Puerta de entrada antes de una app

Verificar en teléfono Android, iPhone y tablet, vertical/horizontal, al menos:

- ventanas y controles accesibles con teclado virtual, barras del navegador y
  áreas seguras; taskbar/menús sin ocultar acciones;
- seleccionar sin abrir, multiselección y alternativa táctil a drag/drop;
- upload grande, suspensión/reanudación, cambio de red y errores sin falso éxito;
- visores, búsqueda que abre página/selección, copiar/mover y feedback de tareas;
- Office/Guacamole, descargas y selección de archivos del dispositivo;
- sesión/CSRF/TOTP, logout, expiración y enlaces de nodos autorizados.

No cachear respuestas autenticadas, URLs firmadas, tokens o documentos privados
como parte de un service worker genérico. Definir primero un modo offline
limitado a estado informativo; no prometer escrituras offline ni sincronización
automática de documentos que el backend no soporte.

## Propuesta condicionada

Mantener el mismo backend PHP, catálogo, autorización y servicios S3/Federation.
Una capa móvil no lleva credenciales AWS/DB ni claves de nodo. La lista de tareas
sigue consultando las fuentes actuales.

Evaluar Capacitor después de cerrar la puerta anterior: su runtime permite
compartir tecnología web entre Android/iOS y añadir capacidades nativas.
Esto no convierte so.php en un archivo estático: PHP y los fragmentos renderizados
siguen dependiendo del servidor. Haría falta definir un cliente empaquetable y
adaptadores revisados sobre los servicios existentes, sin duplicar backend.

La opción server.url de Capacitor está documentada para live reload, no como
atajo de producción. La propuesta es un shell local versionado, recursos web
compartidos y transporte autenticado revisado. Antes del prototipo hay que resolver
origen/cookies/CSRF, CORS con allowlist estricta, logout, navegación externa,
descargas y permisos mínimos. No habilitar wildcard de orígenes ni incrustar
secretos para que un prototipo parezca funcionar.

Añadir capacidades nativas sólo por necesidad concreta: selector de archivos,
compartir/recibir archivos y notificaciones, conservando los flujos existentes.
Office continúa remoto. Diseñar migración de sesión y almacenamiento seguro,
revocación y caducidad antes de guardar cualquier credencial en el dispositivo.
Validar toolchain y requisitos de tiendas cuando llegue esa fase; no se garantiza
aprobación de publicación ni se selecciona una versión sin volver a verificarla.

## Entregables futuros

1. Matriz móvil con dispositivos/resultados y correcciones comprobadas.
2. Decisión PWA/offline y política de caché.
3. Prototipo Android/iOS con login, listar, subir, buscar/localizar, descargar y
   logout sobre fixtures, sin reconstruir los servicios.
4. Ensayo de red/suspensión, seguridad del contenedor y flujo de actualización.
5. Publicación sólo tras aceptación; no se inició empaquetado en esta auditoría.

Fuentes oficiales consultadas para evaluar la propuesta (no prueban el proyecto):

- [Capacitor](https://capacitorjs.com/docs)
- [Configuración y restricciones de server.url](https://capacitorjs.com/docs/config)
- [Entorno de desarrollo](https://capacitorjs.com/docs/getting-started/environment-setup)
