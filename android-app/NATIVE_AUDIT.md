# Auditoría y entrega inicial — 21 de octubre de 2026

## Base inspeccionada

`jimmybackend/s3`, `main` en `ea79751796d7e36501289174e7d78854b291bbc8`. Trabajo separado en `feature/android-native-client`. Sin merge, despliegue, migración SQL ni acciones AWS.

Se leyeron MainActivity.java, AndroidManifest.xml, ambos build.gradle, README, so-folder-recorder.js, upload-center.js, so.php, workflow Android, AuthController/AuthenticationService/AuthenticationRepository/SessionManager, FileListService, FolderRepository/FolderQueryService, FileAccessService/FileRecordLocator, controladores de mutaciones y protección, UploadController y los drivers local_put/chunked. Se revisaron los puntos de entrada de ZIP/ZIP Lambda y las tareas unificadas.

Hallazgos:

- MainActivity.java era un contenedor WebView con cookies, selectores y puente de permisos web. Que Android concediera un permiso no garantizaba disponibilidad del codificador web ni compatibilidad de getUserMedia/MediaRecorder. La nueva app elimina esa dependencia; la compatibilidad de hardware sigue pendiente de prueba física.
- build.gradle declaraba 0.8.0/code 8; README encabezaba 0.7.0 y contenía varios bloques históricos llamados «actual». Se archivó íntegramente como LEGACY_RELEASES y se definió una sola versión nueva: 0.9.0-native-preview/code 9. No se reetiquetaron APK antiguas.
- AuthenticationService verifica las credenciales reales, status Activo, migra credenciales heredadas si corresponde, regenera ID de sesión y registra acceso. No se encontró un segundo factor global en ese servicio: los controles adicionales de archivos/herramientas son específicos. La app no implementa acceso a herramientas TOTP ni omite sus controles.
- El catálogo real usa FileS3 y S3Folders. FileListService filtra user_id_, Ruta y Found y pagina SQL. Su resultado interno contiene PasswordHash: nunca se devuelve directamente desde la API nueva. NativeDrivePolicy emite una lista explícita de campos; oculta metadatos de archivos bloqueados.
- La raíz se deriva de UserStoragePath, no del teléfono: Data/ para usuario 1 según Config, DataN/ para los demás. La API nativa rechaza rutas ajenas/traversal en vez de reinterpretarlas como la raíz. Los nombres físicos no se usan como etiquetas de carpeta.
- local_put mantiene un upload_token ligado a sesión; completa verificando el objeto y registrando FileS3. chunked crea una sesión S3, firma partes, consulta ListParts para recuperar y finalmente registra el archivo.
- La finalización chunked no es idempotente frente a una respuesta perdida. El cliente guarda la fase completing antes de enviarla y, en recuperación, consulta el catálogo por la clave exacta. Si no puede verificarla, conserva el estado y muestra fallo: no crea otra subida automáticamente. Endurecer reconciliación servidor es trabajo pendiente.
- upload-center tiene watchdog de 90 s sin avance y umbral 32 KB/s sostenido 90 s para blobs >= 1 MiB. Android reproduce esos límites, con comprobación cada 5 s, más timeouts de red. No se midió ancho de banda real de los nodos ni se atribuye la lentitud a una causa concreta sin evidencias.
- El cliente viejo imponía una puerta local de ubicación que no enviaba coordenadas al servidor. La preview conserva esa condición mediante RequireLocation nativo: permiso aproximado o preciso y servicios habilitados. No obtiene coordenadas ni pide contactos. Esa condición local es independiente de AuthenticationService.

## Módulos y responsabilidades

| Ruta | Responsabilidad |
| --- | --- |
| app/src/main/.../ui | Material 3, Navigation Compose, ViewModel/StateFlow, explorador, login, tareas, preferencias |
| app/src/main/.../domain | DTO de catálogo, políticas HTTPS/nodos, cálculo multipart/ETA |
| app/src/main/.../data | Repositorio HTTP y cookies/secreto de sesión AES-GCM con Android Keystore |
| app/src/main/.../media | CameraX foto/video, MediaRecorder Android AAC/M4A, Media3, revisión previa |
| app/src/main/.../transfer | WorkManager, checkpoints cifrados atómicos, subidas S3 y descargas sin cookies hacia S3 |
| legacy-webview | Cliente y tests anteriores íntegros, excluidos del APK nativo |
| drive/api/native.php | Entrada JSON nueva |
| NativeDriveController/Service/Policy | Autenticación adaptada, sesión, catálogo paginado, DTO y descargas autorizadas |
| FolderRepository | Nueva consulta de hijos con user_id_ + ParentPrefix + Found y LIMIT/OFFSET |
| SessionManager | Caducidad solo para sesiones marcadas como nativas; los clientes web existentes no cambian |
| .github/workflows/arcadecloud-android.yml | Tests PHP/Android, lint, APK, procedencia, emulador Android 14 |

La app no consulta MySQL ni AWS con credenciales propias. HTTPS valida certificados normales; no hay trust-all, bypass TLS ni logging de cookies/contraseñas/URLs firmadas. El cliente de objetos S3 es distinto del cliente PHP y no sigue redirects. Nodos permitidos: Drive y FastDrive; no hay cambio de nodo con sesión activa.

## Contratos HTTP

**Nuevo `api/native.php`:**

| Método / action | Función / autorización |
| --- | --- |
| GET bootstrap | Cookie de sesión prelogin y token upload_csrf; no datos de usuario |
| POST login | Form email/password; X-Drive-CSRF, LoginRateLimiter y AuthenticationService reales |
| GET session | Usuario real, alias, raíz y CSRF de la sesión; valida estado e identidad |
| POST logout | Sesión + CSRF; SessionManager::destroy |
| GET browse | Sesión; route/page/folder_page/search/type; catálogo paginado por propietario |
| GET file | Sesión; key; FileRecordLocator autoriza lectura antes de devolver DTO; usado para verificar finalización |
| GET download | Sesión; key; FileAccessService firma GET y registra intención de descarga en actividad/costos |

**Reutilizados sin duplicar servicios:**

- `api/upload.php?mode=local_put&action=init/complete`: nombre, ruta_objetivo, task_id y tamano/upload_token conforme al contrato existente.
- `api/upload.php?mode=chunked&action=init/part/complete`: filename/filesize/mime, stateId/uploadId/key, step=resume, partNumber/contentLength y etags.
- `api/upload.php?mode=task&action=progress/fail`: bytes, velocidad, ETA y estado; el backend conserva el control de finalización.
- `background_tasks.php`: tareas del servidor del usuario; se muestran además de las transferencias locales.
- `crear_carpeta.php`, `renombrar_carpeta.php`, `renombrar_archivo.php`, `mover_archivo.php`, `eliminar_archivo.php`, `unlock_file.php`: POST de formularios y X-Drive-CSRF existentes. Los servicios actuales vuelven a verificar propiedad/protección.

Antes de usar endpoints antiguos, DriveRepository consulta la sesión nativa. Además, el guard compartido SessionManager valida estado de cuenta y huella de credencial en el servidor para todas las sesiones nativas, aunque se invoque un endpoint antiguo directamente sin pasar por la app. DriveApplication inyecta esa validación usando AuthenticationRepository; un SessionManager sin verificador rechaza sesiones nativas de forma cerrada. Sesión nativa: máximo 12 horas, inactividad 30 minutos; también puede vencer por la política de almacenamiento PHP. NativeDriveController detecta cambio de contraseña o cuenta inactiva. No se cambia el sistema de roles ni se crea una tabla de tokens. Una URL S3 ya firmada mantiene su vigencia hasta su expiración: no se promete revocación inmediata de una URL emitida.

## Funcionalidad implementada y límites

- Login/logout nativos; restauración cifrada de cookie de sesión. Sin guardar contraseña. Caducidad y errores de servidor visibles. Contratos reales implementados; login de producción todavía no verificado porque el endpoint nuevo no está desplegado.
- Navegación, breadcrumbs y paginación independiente de archivos/carpetas; creación/renombrado de carpeta; propiedades, búsqueda por nombre y filtro por extensión. Orden alfabético **solo de la página cargada**; el backend conserva su orden por fecha. Selección múltiple de la página, mover y eliminar con confirmación; renombrar archivo y desbloquear mediante contraseña existente. Bloqueados no se descargan por decisión local solamente: el backend los vuelve a validar.
- Foto con CameraX y EXIF del archivo capturado, frontal/trasera, vista previa/repetir; video SD con audio para reducir carga en gama básica; MP4 hasta 5 min/180 MiB; pausa/detención; audio Android AAC en contenedor M4A con pausa/detención. No se usan APIs multimedia del navegador.
- La cámara solo abre tras permiso solicitado mediante botón. La grabación exige un toque independiente. Preview no inicia reproducción automática. Audio/video se detienen al ON_STOP; no hay grabación en segundo plano. Fallos de permiso, cámara ausente/ocupada, encoder y espacio se reportan por sus rutas respectivas; falta probar códigos específicos en Samsung. Llamadas e interrupciones reales pendientes.
- Galería mediante Photo Picker y documentos mediante SAF; no se pide almacenamiento general. Copia estable en disco privado para reintento de providers no seekables: puede requerir espacio adicional. Nunca se carga un archivo grande completo en RAM.
- Subida directa HTTPS a S3: PUT pequeño y multipart de 8 MiB a partir de 8 MiB. El video no pasa por un temporal EC2 en el cliente nuevo. Se conservan las comprobaciones/moderación existentes del servidor, que pueden leer el objeto al completar.
- WorkManager persiste el trabajo; checkpoints cifrados guardan destino, propietario, nodo, generación de sesión, clave/uploadId y fase. Progreso refleja bytes escritos al socket; completado solo tras respuesta de complete o verificación de catálogo. Velocidad es promedio de la parte activa, ETA aproximada y tiempo transcurrido incluye esperas desde el primer arranque. No es confirmación de recepción remota byte a byte.
- La recuperación multipart consulta partes reales de S3 a través del backend; los PUT pequeños reinician su mismo objeto pendiente. Pérdida de respuesta de init se trata como incierta y no genera otro objeto automáticamente. El usuario ve el fallo y debe revisar las tareas antes de volver a seleccionar.
- Se puede pausar/reintentar/cancelar localmente. Cancelar detiene el trabajo Android y señaliza la tarea, pero no aborta ni borra por sí mismo un multipart S3: aplica la limpieza existente del servidor. El archivo fuente/checkpoint de una tarea fallida se conserva. Las capturas temporales se limpian al confirmar su subida; las descargas completadas quedan privadas para el visor.
- Los trabajos se ligan a la generación de sesión para impedir que otra cuenta o un login distinto herede una tarea. Tras logout/relogin no se retoman automáticamente las sesiones anteriores; falta una recuperación explícita tras reautenticación. Se puede volver a seleccionar el original previa revisión de la tarea del servidor.
- Descargas directas firmadas a disco privado, progreso y apertura con Media3, imagen o app Android mediante FileProvider. Reintentos de descargas reinician el GET; **no hay resume por Range validado con ETag**. No hay exportación SAF de la descarga en esta preview.
- Android puede suspender trabajos por batería, cuotas, red o force-stop. Las transferencias usan foreground dataSync y notificaciones cuando Android las permite; la autorización de notificaciones es opcional. No se promete continuidad tras force-stop ni después de borrar datos. Sin prueba física de ahorro energético Samsung.

## Etapas

1. Base nativa: implementada para validación; integración real pendiente de despliegue autorizado y cuenta de pruebas.
2. Multimedia: implementada; validación física y comportamiento bajo todas las interrupciones pendientes.
3. Transferencias: primera implementación; pruebas reales grandes/red y recuperación después de reautenticación pendientes.
4. Avanzadas: **pendientes** ZIP/Lambda individual, descarga de carpetas, ArcadeLink, FederationCloud/nodos federados, biblioteca 3D, preferencias de nodo y equivalencia de todas las acciones web. Ninguna se simula con WebView. Herramientas que requieren TOTP no están expuestas.
5. Validación: tests automatizados, lint, APK y CI; auditoría de seguridad completa y aceptación física pendientes. No es una declaración de que toda la migración está terminada.

## Verificación y aceptación pendiente

Tests PHP ejecutables sin DB/AWS: `native_session_revocation_regression.php` (9 aserciones sobre el guard compartido), `native_drive_policy_regression.php` (33 aserciones) y `native_api_authorization_regression.php` (18 escenarios de controlador con colaboradores aislados). Los colaboradores de pruebas son dobles declarados expresamente: no prueban MySQL/S3 reales. Pruebas Android unitarias: límites multipart, ETA, URLs/nodos. Pruebas instrumentadas: pantalla Compose y almacén Android Keystore. CI publica los reportes correspondientes; compilar el test APK no equivale a ejecutar el emulador.

Antes de distribuir ampliamente, en Samsung Android 14 básico y otra marca:

- Login real/logout/expiración/revocación; dos usuarios distintos y archivos bloqueados, sin acceso cruzado.
- Fotos frontal/trasera y EXIF en horizontal/vertical; video con audio, pausa y límite; notas de voz cortas/largas; revisar antes de guardar.
- Permisos denegados/permanentes/revocados, cámara ocupada, almacenamiento lleno, codificador no disponible y llamada entrante.
- Rotar antes/durante/después de capturar, minimizar y restaurar, cerrar proceso y volver a abrir; confirmar detención segura y ausencia de grabación oculta.
- Subir archivo pequeño y uno >132 MB con red buena/lenta; comparar bytes reales, tareas servidor y registro final; pérdida de red a mitad de parte y después de complete.
- Pausa/cancelación desde app/notificación, reinicio de proceso, force-stop y restricciones de batería. Confirmar que una tarea no se marca completa al encolarla.
- Pantalla pequeña, tablet, fuente Android ampliada y lector de pantalla; comprobar controles de grabación y formularios con teclado.

No se usaron credenciales de usuario, no se eliminó ningún objeto S3 de prueba, no se probó hardware físico y no se actualizó producción. Las pruebas reales de extremo a extremo requieren primero revisión y autorización del despliegue backend.

## Resultado local confirmado

JDK 17, Gradle 8.13, compileSdk 36: `testDebugUnitTest`, `lintDebug`, `assembleDebug` y `assembleDebugAndroidTest` terminaron correctamente. Lint: **0 errores, 32 advertencias** (principalmente versiones disponibles más recientes, recursos históricos y APIs objetivo). No se ha ejecutado emulador local: no hay /dev/kvm; la ejecución instrumentada se solicita en CI. Ninguna advertencia se silenció mediante baseline o desactivación global de lint.

La primera ejecución CI falló durante setup-android, antes de compilar, porque su configuración predeterminada intentaba instalar el paquete SDK obsoleto `tools`. Se fijaron commandline-tools y `platform-tools` explícitos.

Dependencias fijadas a una matriz estable compatible, no a versiones dinámicas: AGP 8.13.2, Kotlin/Compose compiler 2.2.21, BOM 2025.10.01, Activity 1.11.0, Lifecycle 2.9.4, Navigation 2.9.5, WorkManager 2.10.5, CameraX 1.5.3 y Media3 1.8.0. **Hay versiones estables más nuevas; renovar toda la matriz es pendiente antes de release.** Se verificaron las notas oficiales y la compatibilidad compilando esta matriz: [AGP](https://developer.android.com/build/releases/agp-8-13-0-release-notes), [Compose/compiler](https://developer.android.com/jetpack/androidx/releases/compose-kotlin), [BOM](https://developer.android.com/develop/ui/compose/bom), [CameraX](https://developer.android.com/jetpack/androidx/releases/camera), [WorkManager](https://developer.android.com/jetpack/androidx/releases/work), [Navigation](https://developer.android.com/jetpack/androidx/releases/navigation), [Media3](https://developer.android.com/jetpack/androidx/releases/media3).

La auditoría OOP global falla también en el commit base `ea79751796d7e36501289174e7d78854b291bbc8`: el inventario marca `drive/js/so-device-location.js` como script sin clase (`js_needs_migration = 1`). Se reprodujo en un worktree aislado del commit base; este archivo no se modifica en el PR. No se silencia ni se presenta esa auditoría global como verde.

Se rechaza en el teléfono una segunda subida activa/pausada con el mismo nombre y destino; el protocolo chunked existente deriva stateId de usuario/ruta/nombre/tamaño. No se ha demostrado exclusión global contra otra subida web simultánea de ese mismo archivo. Cancelar durante una finalización ya aceptada por el servidor no revierte el archivo guardado. La expiración del PUT pequeño exige revisar la tarea; no se promete renovar la URL preservando el token si el servidor ya descartó esa sesión.


## Revisión de acceso 0.9.1 (2026-10-21)

El usuario confirma que las mismas credenciales funcionan en la web. La API pública
`https://drive.esforzados.com/api/native.php?action=bootstrap` respondió 200 JSON;
FastDrive respondió HTML, por lo que esa comprobación no acredita disponibilidad de
su API nativa. No se enviaron credenciales ni se consultó el hash de ninguna cuenta.

NativeDriveController ya delega en AuthenticationService, exactamente como AuthController.
El servicio usa PasswordCredentialVerifier::verify(), que llama a password_verify().
Android usa FormBody y PHP postRawString('password'): no debe aplicar trim, hash local,
cambio de mayúsculas ni normalización Unicode a la contraseña.

Se corrigió una omisión del formulario Android: faltaba configurar el teclado de
contraseña y desactivar autocorrección. Se añadió mostrar/ocultar contraseña,
conservación en memoria para reintentar tras rechazo y versión visible antes de entrar.
La contraseña sigue fuera de rememberSaveable y de SessionVault; desaparece al salir
de la pantalla. No se cambia el verificador, el hash de cuentas ni la base de datos.
Esta omisión puede afectar la entrada con teclados del dispositivo; no se ha demostrado
que explique por sí sola el rechazo reportado.

Pruebas nuevas: AuthenticationService real con repositorio aislado, bcrypt y Argon2id
cuando está disponible, POST codificado, contraseña con espacios significativos,
caracteres especiales/Unicode, rechazo de contraseña errónea y del hash como contraseña,
y cuenta inactiva. Prueba instrumentada del formulario con visualización, envío exacto
y reintento. Falta repetir el login real en el Samsung con la APK corregida; no se
presenta la prueba aislada como validación de una cuenta de producción.
