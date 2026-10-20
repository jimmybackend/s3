# ArcadeCloud Android (APK de pruebas)

## Descargar ArcadeCloud Android 0.7.0 (versión de pruebas actual)

**[APK ArcadeCloud 0.7.0 — grabación de voz y video corregida](releases/ArcadeCloud-Android-0.7.0-debug.apk)**

Descarga directa desde `main`: https://raw.githubusercontent.com/jimmybackend/s3/main/android-app/releases/ArcadeCloud-Android-0.7.0-debug.apk

- Tamaño: **39,881 bytes**. APK Android de depuración.
- Compilación desde `main` después del PR #500. GitHub Actions workflow `39434258176`, artifact `12268434727`.
- SHA-256: `8874076b13171c8340774a7cd3bf288b395eb98fdcd9bf5b65bfb1bde9027502`.
- APK cotejada con el artefacto original antes de almacenarla en `releases/`; no contiene permisos de contactos.
- Puede ser necesario desinstalar la APK anterior si tiene una firma debug diferente. Esto borra los datos locales de la aplicación, **no** los archivos almacenados en S3.
- **Se requiere actualizar el servidor Drive web** para obtener la corrección de `so-folder-recorder.js`. Esta APK por sí sola no modifica la EC2.


## Android 0.7.0 — solución para grabaciones en Samsung/WebView

Si la APK tiene autorizados cámara y micrófono pero en una carpeta aparece el error genérico de grabación, puede deberse a un **formato multimedia no disponible en WebView** o a un fallo de reproducción de la vista previa; no necesariamente a un permiso denegado.

- El grabador de `so.php` ahora prueba los codificadores disponibles y después el codificador predeterminado de Android WebView (sin MIME prefijado). Distingue errores: permiso web bloqueado, cámara/micrófono ocupado, dispositivo ausente o formato sin compatibilidad.
- Una negativa al autoplay de la vista previa de video ya no impide iniciar MediaRecorder.
- Botón de respaldo **«Usar grabadora del teléfono»** para notas de voz y **«Usar cámara del teléfono»** para video. Se abre la aplicación nativa de grabación del dispositivo mediante `<input capture>` y el selector Android. Si no hay una app de grabación, permite seleccionar un archivo ya grabado. La grabación capturada vuelve a la misma ventana y requiere pulsar **Guardar en esta carpeta**.
- El archivo de cámara/grabadora nativa puede guardarse temporalmente en el teléfono según la aplicación que lo crea. Desde ArcadeCloud el usuario lo envía mediante las **subidas directas a S3** registradas en **Tareas**, sin escribir archivos multimedia en el disco de la EC2. Se mantiene inmutable la carpeta seleccionada.
- Para usar el respaldo nativo hacen falta **ambos**: APK Android 0.7.0 y nueva versión de `drive/so.php` y `drive/js/so-folder-recorder.js` desplegada en EC2. Un merge en GitHub no implica que el servidor se haya actualizado.
- Se conservan únicamente permisos de ubicación, cámara y micrófono; **no** se solicitan contactos. Ubicación sigue siendo requisito de acceso a Drive, no de instalación.
- La compatibilidad final de grabación nativa y WebView requiere una prueba real en Samsung; los tests unitarios y Android CI no sustituyen esa prueba. Los videos grabados dentro de WebView tienen un máximo de 5 minutos / 180 MB; la app nativa controla sus propios límites y ArcadeCloud rechaza archivos superiores a 180 MB.

**Importante:** no instalar la APK histórica 0.6.0 y esperar el respaldo nativo. Las descargas publicadas en `releases/` son APK de depuración, con posibles firmas incompatibles entre versiones.


## Descargar APK Android 0.6.0 (versión histórica)

**[ArcadeCloud Android 0.6.0 — instalador APK](releases/ArcadeCloud-Android-0.6.0-debug.apk)**

Descarga directa: https://raw.githubusercontent.com/jimmybackend/s3/main/android-app/releases/ArcadeCloud-Android-0.6.0-debug.apk

- APK de 38,881 bytes, compilado desde `main` tras el PR #498.
- GitHub Actions `39419721040`, artifact `12263317719`.
- SHA-256 del APK: `4a5c07e2c91bb43e09ab835b870e31a1cf2ff07ddffc4fed11442f94532ca2e2`.
- Se verificó el SHA-256 antes de importar el instalador al repositorio.
- Solo solicita ubicación, micrófono y cámara: **no solicita contactos**.
- Es una APK de depuración: una actualización desde versiones anteriores puede requerir desinstalar la anterior debido a firmas diferentes. Los archivos S3 no se ven afectados.


## Android 0.6.0 — únicamente ubicación, micrófono y cámara

La versión 0.6.0 **no solicita acceso a contactos**. Se eliminó `READ_CONTACTS` del manifiesto Android, la lógica de solicitudes, y los tests que permitían pedir ese permiso.

- **Ubicación (obligatoria para entrar):** al primer inicio, Android solicita ubicación aproximada o precisa. Si no se autoriza o los servicios de ubicación están apagados, ArcadeCloud muestra una pantalla de acceso pendiente en lugar de cargar `so.php`. No se puede bloquear la *instalación* de una APK por permisos; sí se restringe el *uso* hasta que estén habilitados. Es una comprobación local y **no** envía ni almacena coordenadas.
- **Micrófono y cámara:** tras habilitar ubicación, Android solicita los permisos necesarios al primer inicio. Si se deniegan, el usuario puede volver a autorizarlos al grabar voz/video o tomar fotografías desde una carpeta.
- **Contactos:** no se piden, no se consultan, no se sincronizan ni se almacenan.
- **Archivos:** se siguen seleccionando mediante el selector del sistema Android, sin acceso general al almacenamiento.

APK compilable mediante el flujo Android de GitHub Actions. Las versiones 0.5.0 y anteriores se mantienen solo por motivos históricos; para verificar la eliminación de contactos instala 0.6.0. Las firmas de depuración pueden requerir desinstalar la versión previa (esto elimina datos locales de la app, no los archivos S3).


## Descarga histórica de APK Android 0.5.0 (anterior; solicita contactos)

**[ArcadeCloud Android 0.5.0 — instalador APK](releases/ArcadeCloud-Android-0.5.0-debug.apk)**

Descarga directa: https://raw.githubusercontent.com/jimmybackend/s3/main/android-app/releases/ArcadeCloud-Android-0.5.0-debug.apk

- 39,529 bytes, APK de depuración, creado desde `main` tras PR #496.
- Workflow de GitHub Actions `39417108162`, artefacto `12261379195`.
- SHA-256: `b257eaae2c1ec6166aae190d7bc52014c6a16b8693a8bbb415271cca1ad66585`.
- Archivo importado al repositorio tras comparar el checksum contra el artefacto original.
- Las APK anteriores (0.1, 0.2, 0.3 y 0.4) se mantienen solo como versiones históricas.
- Firmas debug de distintas ejecuciones pueden ser incompatibles: quizá debas desinstalar la APK anterior antes de instalar 0.5.0. Se borrarán cookies y sesión locales; los archivos en S3 no se tocan.

## Android 0.5.0 — ubicación requerida y contactos opcionales

Esta edición solicita los permisos **al abrir la APK por primera vez**, ya que Android no admite requerirlos durante la instalación. Antes de mostrar `so.php`, el cliente comprueba:

- Que el usuario concedió ubicación **aproximada o precisa**; se acepta cualquiera de las dos.
- Que los servicios de ubicación estén encendidos en Android.

Si falta uno, la app muestra su pantalla de autorización, con opciones para pedir el permiso o ir a los Ajustes. El WebView no navega ni restaura sesión mientras no se cumplan ambos requisitos. El usuario puede **instalar** la APK pero **no acceder al escritorio** hasta autorizar ubicación. La decisión de aceptar o denegar la toma el usuario en el sistema operativo.

Una vez autorizada la ubicación, Android solicita el permiso de **contactos** una sola vez en esa instalación. Es opcional: rechazarlo permite continuar con Drive, porque la aplicación **no lee, transfiere ni sincroniza contactos todavía**. Después se solicitan cámara y micrófono como en 0.4.0; estas dos autorizaciones siguen siendo revocables y no se requieren para navegar.

**Alcance de privacidad:** este cambio verifica ubicación localmente como condición de acceso; **no obtiene ni almacena coordenadas GPS en la base de datos, ni actualiza aún `Users.os_preferences`**, y no recopila contactos ni realiza seguimiento en segundo plano. El registro de última ubicación, si se desea, debe implementarse separadamente con aviso transparente, autenticación, retención definida y protección contra accesos no autorizados. No se pidió ni se habilitó `ACCESS_BACKGROUND_LOCATION`.

La experiencia web `so.php` es la misma; no aparece el botón Android flotante de versiones viejas. El binario 0.4.0 anterior no incluye la pantalla de ubicación. La versión 0.5.0 se compila y distribuye desde GitHub Actions; **una modificación del código en Git no actualiza por sí sola una APK previamente instalada**.


## Android 0.4.0 — permisos al primer inicio

La versión 0.4.0 mantiene eliminada la superposición flotante **⋮**. Si aparece en pantalla, se está ejecutando una APK antigua (0.2.0), no la versión actual.

**Nuevo primer inicio:** cuando el usuario abre ArcadeCloud Android por primera vez, Android solicita los permisos de **Cámara** y **Micrófono** necesarios para tomar fotos, grabar voz y grabar video. La app no puede activar permisos a la fuerza durante la instalación: deben concederse en el cuadro nativo de Android. Se pregunta una sola vez al arrancar; si rechaza, la grabación puede volver a solicitarlos solamente al tocar una acción de cámara/micrófono dentro de una carpeta. Android conserva el control sobre permisos y puede permitir un único uso, denegarlos o revocarlos después.

- El primer permiso se solicita en el **primer inicio después de instalar/actualizar a 0.4.0**. Se guarda un indicador local para no repetir preguntas al abrirla o girar la pantalla.
- La versión sigue funcionando si alguien no autoriza los permisos: simplemente no podrá usar la captura correspondiente hasta concederlos.
- No se solicitan permisos de ubicación, contactos ni acceso completo a archivos. Para elegir documentos se usa el selector del sistema.
- Si Android rechaza la actualización porque la versión anterior se firmó con otra clave de depuración, puede ser necesario desinstalarla. Eso borrará las cookies/sesiones guardadas **localmente**; los archivos ya existentes en S3 no se borran.
- El web Drive debe desplegar los cambios de las grabadoras en `so.php` para mostrar las acciones dentro de carpetas. La APK no actualiza la EC2.

El código Android 0.4.0 se compila en GitHub Actions. **No instalar el APK 0.3.0 esperando que pregunte permisos al iniciarse:** ese APK solo solicita los permisos al empezar a grabar.


## Descargar APK actual 0.4.0 desde main

**[ArcadeCloud Android 0.4.0 — instalador APK](releases/ArcadeCloud-Android-0.4.0-debug.apk)**

Descarga directa: https://raw.githubusercontent.com/jimmybackend/s3/main/android-app/releases/ArcadeCloud-Android-0.4.0-debug.apk

- Tamaño: 33,701 bytes.
- Origen: compilación de `main` tras PR #494, workflow `39415246092`, artefacto `12261396213`.
- SHA-256 del APK: `f35f405090b56580aebdb683f776ff41067c40f5a05d196389206f7d510e80b3`.
- Verificado en GitHub Actions contra el artefacto oficial antes de incorporarlo a `releases/`.
- Instalador de depuración: puede necesitar desinstalar anteriores debido a firmas diferentes; no está firmado para Google Play.

## Descarga histórica 0.3.0 desde el repositorio

**[ArcadeCloud Android 0.3.0 (archivo APK)](releases/ArcadeCloud-Android-0.3.0-debug.apk)**

Descarga directa: https://raw.githubusercontent.com/jimmybackend/s3/main/android-app/releases/ArcadeCloud-Android-0.3.0-debug.apk

Archivo de 32,413 bytes, generado por GitHub Actions de `main` después del PR #492.
Origen: ejecución `39411673515`, artifact `12259099761`.
SHA-256: `7f6128b854f4d6dddbb7c05b2674f420ebe285fc7b80001526faa9c866f67441`.

Es una versión de prueba firmada en modo depuración, **no** una distribución Play Store. Puede ser necesario desinstalar 0.2.0 antes de instalar 0.3.0 si las firmas debug no coinciden. El backend web debe estar actualizado para mostrar las acciones de grabación en carpetas.

## Versión 0.3.0 — grabaciones dentro de carpetas

La versión 0.3.0 **elimina el botón flotante ⋮**, que invocaba una página `mobile_capture.php` ausente en algunos despliegues. Las acciones ahora están dentro de `so.php`: en **Mis datos** abre una carpeta y utiliza su barra superior (iconos de micrófono, video o cámara) o el menú de acciones de una carpeta. El toque en espacio vacío ofrece las acciones de la carpeta actual.

Se puede grabar nota de voz, grabar video con cámara y micrófono o tomar una fotografía. Pulsa *Comenzar* (Android pide permisos de cámara/micrófono al usar), *Detener* (voz/video) y *Guardar en esta carpeta*. La ruta de destino queda fija al abrir el grabador y **no** cambia si navegas a otra carpeta. El archivo entra a **Tareas**, donde su estado real refleja el progreso de la transferencia **directa del navegador a S3**; no se envía un video al PHP temporal de EC2. Multipart firmado para archivos de al menos 8 MiB. Grabaciones limitadas a 5 minutos y 180 MiB; no funcionan en segundo plano si Android suspende el WebView. Para poder utilizarlo, **ambos** deben estar actualizados: el APK 0.3.0 y el código `drive/so.php` del servidor web.

La APK 0.2.0 contenía el botón anterior y no debe utilizarse para probar este flujo. Antes de instalar 0.3.0, comprueba su compilación en el workflow Android de GitHub; la APK 0.3.0 no sustituye automáticamente los binarios anteriores guardados en `releases/`.


## Captura móvil 0.2.0 (funciones por autorización)
- La app muestra un menú superior «⋮» con «Notas de voz y fotos».
- Al iniciar grabación se solicita permiso del micrófono; al abrir cámara se solicita permiso de cámara. Se respetan rechazos.
- La vista `drive/mobile_capture.php` deja escoger una carpeta existente del usuario y requiere pulsar «Guardar» para subir a S3.
- Formatos admitidos: voz WebM/M4A/Ogg y foto JPG/PNG/WebP, máximo 25 MB por archivo; el servidor valida sesión, token CSRF, tamaño, tipo real y propiedad de la carpeta.
- Se mantiene la sesión web solo durante la vigencia permitida por PHP. La app persiste las cookies gestionadas por Android, sin contraseñas locales ni tokens eternos.
- El selector de archivos Android no necesita permiso amplio de almacenamiento; no se recopilan contactos indiscriminadamente.
- **No se incluye** la captura automática o periódica de ubicación: se requiere implementar consentimiento claro, revocable y una política de retención de coordenadas.
- Para el acceso a cámara y micrófono en páginas externas se deniegan solicitudes; solo orígenes Drive/FastDrive aprobados y únicamente recursos audio/video admitidos.
- Pendiente: validar cámara, micrófono, tamaño de imágenes, subida en segundo plano y sesiones en teléfonos reales. El APK 0.1.0 existente en `releases/` **no contiene** esta función; es necesario compilar e instalar el nuevo APK 0.2.0 desde Actions.



## APK actualizado 0.2.0 (voz y cámara)
**Descargar desde la rama main:** https://raw.githubusercontent.com/jimmybackend/s3/main/android-app/releases/ArcadeCloud-Android-0.2.0-debug.apk

La versión 0.2.0 se compiló y pasó pruebas de Java, Android Lint, APK y CI en el commit `e2f42e52` (workflow `39407463425`, artefacto `12257238654`).
SHA-256 del APK: `a2335b8190929ed1d86abc5a953cfdd6d53935eeb99bdc1cd5f94d363d0b6940`.
Es APK de **depuración**, con identidad de firma dependiente del runner. Si Android rechaza la actualización sobre 0.1.0 por firma diferente, desinstala 0.1.0 e instala 0.2.0; esto puede eliminar datos locales de la app, pero no los archivos del Drive guardados en S3.

El APK 0.1.0 se conserva para referencia histórica, pero 0.2.0 es el instalador de pruebas vigente. El envío de fotos/notas depende de que el backend publicado tenga `mobile_capture.php` y `mobile-capture-upload.php` desplegados. La fusión en GitHub no certifica que la EC2 haya descargado esos archivos.

## APK de prueba almacenado en GitHub (0.1.0, anterior)

El instalador compilado se conserva en
[`android-app/releases/ArcadeCloud-Android-0.1.0-debug.apk`](releases/ArcadeCloud-Android-0.1.0-debug.apk).

**Descarga directa desde main** (usar "Download raw file"):
https://github.com/jimmybackend/s3/raw/refs/heads/main/android-app/releases/ArcadeCloud-Android-0.1.0-debug.apk

Procedencia: artefacto GitHub Actions `arcadecloud-android-debug-apk`,
ejecución `39378713344`, compilación del 20 de octubre de 2026.
SHA-256: `9e3f0ed033fabd6360cc604a3176296bae87120cd0b4388acb97697f568a7b5b`.

Este APK usa una **firma de depuración**, sirve para pruebas y **no se debe publicar como release definitivo**. El código sigue siendo la fuente de verdad. Para nuevas versiones, construir el APK con CI y verificar su SHA-256 antes de sustituir el binario; no introducir credenciales ni claves privadas al repositorio.


Proyecto Android nativo de cliente ligero: HTTPS WebView sobre el ArcadeCloud existente, iniciado en https://drive.esforzados.com/so.php. No duplica backend PHP, MySQL, AWS S3 o FederationCloud y no contiene claves ni credenciales de AWS.

Estado: VERSION 0.1, PROTOTIPO. No es una aplicación móvil validada en dispositivos reales ni una publicación en Google Play.

## Incluye

- Inicio del Web OS, login PHP y cookies de primera parte.
- Hosts explícitos drive.esforzados.com y fastdrive.esforzados.com, HTTPS obligatorio.
- Enlaces externos en navegador; enlaces de s3.php vuelven a so.php.
- Selección de archivos Android para formularios web, simple y múltiple.
- Descargas a Descargas/ArcadeCloud del TELÉFONO vía streaming. Redirecciones limitadas a Drive/FastDrive o al bucket S3 autorizado. Nunca se mandan cookies de PHP a un host S3 ni se almacenan ZIP en la EC2 como consecuencia de esta app.
- Prohibición de acceso WebView a archivos del sistema, contenido mixto, cookies de terceros y JS bridges; errores TLS se rechazan.
- Retroceso, barra de progreso y reintento de conexión.
- Pruebas unitarias de allowlist de dominios; GitHub Actions compila APK debug.

## Límites relevantes

- Requiere backend y conexión. Sin modo offline de documentos ni escrituras fuera de línea.
- Uploads multipart de JS aún pueden fallar cuando Android suspende/cierra WebView, no se promete reanudación en background.
- Descargas grandes a móvil usan un executor vinculado al Activity y no sobreviven a terminación del proceso. No se ha instalado WorkManager.
- Revisión en dispositivo pendiente para Drive 3D, escritorio remoto, multimedia, Office, cámara/micrófono/videollamada, ventanas emergentes y enlaces externos especiales.
- No modifica los endpoints existentes; necesita permisos normales de sesión.
- Cada APK debug del runner puede llevar firma debug distinta. Para actualizar puede ser necesario desinstalar versión previa; para distribuir estable se requiere keystore privado y pipeline release.
- Android 10 o superior. El APK de CI es para pruebas; no equivale a aprobación de Google Play.

## Descargar APK desde GitHub

Repositorio jimmybackend/s3 -> pestaña Actions -> ArcadeCloud Android debug APK -> ejecución exitosa -> Artifacts -> arcadecloud-android-debug-apk. Descomprimir y abrir app-debug.apk en el teléfono. Descargar únicamente del repositorio propio. El archivo expira según retención del workflow (14 días).

## Compilar

JDK 17, Android SDK 35, Build Tools 35.0.0, Gradle 8.11.1. Ejecutar desde android-app:

    gradle :app:testDebugUnitTest :app:lintDebug :app:assembleDebug

Salida: android-app/app/build/outputs/apk/debug/app-debug.apk

## Antes de clientes

Probar Android teléfono/tableta con autenticación/logout/TOTP, navegación de carpetas, selección múltiple, subida multipart 132 MB con red inestable, descargas protegidas y ZIP, FederationCloud, multimedia y visores, tareas y Drive 3D. Comparar con drive/docs/MOBILE_APP_PROPOSAL.md.

Referencias Android: https://developer.android.com/develop/ui/views/layout/webapps y https://developer.android.com/privacy-and-security/risks/webview-unsafe-file-inclusion
