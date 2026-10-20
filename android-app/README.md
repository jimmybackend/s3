# ArcadeCloud Android (APK de pruebas)

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
