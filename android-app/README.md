# ArcadeCloud Android Native — 0.9.1 preview

Cliente Android Kotlin/Compose. El módulo `app` no incluye WebView, Custom Tabs ni HTML. Conserva `com.arcadecloud.drive`, sube `versionCode` de 8 a 10 y utiliza el backend PHP existente.

**Esta es una primera implementación, no paridad completa con el Drive web ni una versión certificada para Samsung. Requiere desplegar los cambios PHP de esta rama antes de iniciar sesión. No se ha desplegado producción.**

## Compilar

JDK 17 completo, Gradle 8.13, Android SDK 36 y Build Tools 35.0.0:

```sh
cd android-app
gradle :app:testDebugUnitTest :app:lintDebug :app:assembleDebug :app:assembleDebugAndroidTest
# Con emulador/dispositivo autorizado conectado:
gradle :app:connectedDebugAndroidTest
```

El workflow **ArcadeCloud Android Native** entrega un artefacto `arcadecloud-native-0.9.1-debug-N` con APK, `build-info.json`, `SHA256SUMS` y certificado de firma. El JSON contiene el commit realmente compilado. Las pruebas instrumentadas Android 14 tienen su propio job y resultados; no son pruebas físicas.

## Instalar

1. Obtener el artefacto de una ejecución verde del PR; verificar SHA-256 contra `SHA256SUMS`.
2. Instalar `ArcadeCloud-Android-0.9.1-native-preview-debug.apk` en Android 10 o superior.
3. Autorizar ubicación aproximada o precisa y activar el servicio, como exigía la APK anterior; la app no consulta coordenadas. Elegir Drive o FastDrive e iniciar sesión en la pantalla nativa. FastDrive no se enciende desde esta app.
4. Abrir una carpeta y usar los botones Subir, Galería o Cámara. La captura exige una acción explícita y luego **Guardar en esta carpeta**.
5. Revisar transferencias en Tareas; abrir una descarga completada para verla o enviarla a un visor Android mediante FileProvider.

**Firma:** el applicationId se mantiene; la continuidad de actualización requiere la misma clave. Las claves debug generadas en runners diferentes pueden ser distintas. Android puede rechazar la actualización por firma incompatible. No se garantiza conservar la sesión del cliente WebView: el cliente nuevo usa su almacén cifrado propio. Desinstalar elimina los datos locales, incluidas capturas pendientes; primero completar/exportar lo necesario. No elimina archivos del Drive.

## Firma release

`app/build.gradle` lee `ARCADECLOUD_KEYSTORE`, `ARCADECLOUD_STORE_PASSWORD`, `ARCADECLOUD_KEY_ALIAS` y `ARCADECLOUD_KEY_PASSWORD` del entorno. La clave debe provisionarse fuera del repositorio en un entorno protegido. CI no genera ni publica claves release y no distribuye release automáticamente. Nunca guardar estos valores ni archivos keystore en Git. Definir y custodiar una identidad estable antes de distribuir actualizaciones a usuarios.

## Recuperación

El cliente original completo está en `legacy-webview/`, excluido de `settings.gradle`. Para reconstruirlo en una copia aislada, usar el commit base `ea79751796d7e36501289174e7d78854b291bbc8` o restaurar ese módulo como `app` junto con su `build.gradle` raíz. No bajar versiones sobre una instalación sin entender el efecto de la firma y los datos locales. Historial de APK anteriores: [LEGACY_RELEASES.md](LEGACY_RELEASES.md), exclusivamente histórico; sus enlaces no son la APK nativa nueva.

Auditoría, contratos, alcance y pruebas físicas pendientes: [NATIVE_AUDIT.md](NATIVE_AUDIT.md).
