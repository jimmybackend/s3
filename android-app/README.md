# ArcadeCloud Android (APK de pruebas)

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
