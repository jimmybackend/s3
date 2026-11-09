# Enlaces del escritorio por días desde el puente de Legal

Esta modalidad responde al flujo de copiar un enlace de Compartir de Drive y pegarlo en Legal, automatizando ambos pasos. El propietario escoge los días (365 por defecto) en Legal; la API crea el enlace con el ShareLinkService y ShareTokenStore existentes y Legal llena su registro documental privado. No crea credenciales AWS, no consulta la base de Legal y no copia archivos.

## Código y datos

Actualizar juntos `src/Integration/LegalDriveService.php`, `src/Http/Controller/LegalDriveController.php`, `PublicShareController.php` y los cinco archivos de `src/Sharing`: `ShareLinkService`, `ShareTokenStore`, `ShareAccessService`, `ShareFileRepository` y `ShareObjectStorage`.

No hay SQL nuevo en Drive: se reutiliza **tokens.json** en su ruta actual, mediante bloqueo del archivo en creación, sustitución y revocación. No borrarlo, sustituirlo por un JSON vacío ni incluirlo en Git. PHP conserva su permiso de lectura/escritura existente. El resto de configuración privada y grants personales no cambia.

Los tokens nuevos del puente conservan la forma aleatoria de 32 caracteres hexadecimales y las rutas `token_texto.php`, `token_audio.php` y `token_video.php`. Usan la misma regla del calendario del escritorio, de 1 a 3650 días: vencimiento a las 23:59:59 en America/Merida, contando hoy como primer día.

Una marca `legal_bridge` permite que estos enlaces resuelvan el **file_id estable** al usarse y vuelvan a comprobar propietario activo, archivo, bloqueos y ancestros. Mover/renombrar el original no cambia la URL. Los tokens de escritorio anteriores mantienen su comportamiento y no se migran automáticamente.

La URL es una capacidad para quien la posea mientras esté vigente; **no queda limitada al expediente si alguien la copia fuera de Legal**. Legal exige consentimiento informado al generarla y conserva su registro como privado. Los lectores autorizados de Legal no necesitan cuenta de Drive ni el grant `case-service` para esta modalidad. La caducidad de la credencial personal afecta generar/renovar, no el plazo de los enlaces ya emitidos. La suspensión de su propietario sí bloquea el uso de los nuevos enlaces del puente.

## API

| Acción | Método | Autorización y campos |
|---|---|---|
| timed_share | POST | Bearer personal del propietario; id, days (1–3650; 365 por defecto), previous opcional para renovar |
| timed_revoke | POST | Bearer personal; token del puente con propiedad verificada |
| timed_status | GET | token de la URL, únicamente para tokens del puente; devuelve nombre/tipo/tamaño/vencimiento sin rutas ni claves S3 |

`timed_share` devuelve token, endpoint relativo validable, días, fecha ISO y metadatos. El origen HTTPS se obtiene de la configuración privada de Legal, nunca de un Host o URL elegidos en el navegador. Los días inválidos se rechazan. El servicio `case-service` no puede generar ni retirar enlaces del propietario.

Renovar sustituye el token anterior bajo un único bloqueo del almacén, verifica que pertenezca al mismo propietario/archivo y no toca otros enlaces. Retirar elimina solo ese token. La escritura inválida de JSON existente se rechaza sin sustituir el almacén.

El controlador público valida el token en cada solicitud y produce una URL de S3 corta al abrir/descargar. Para los tokens nuevos, su vigencia es de hasta 10 minutos y no supera el vencimiento del enlace. `download` genera disposición attachment. Una URL corta de S3 ya emitida puede seguir funcionando hasta ese límite tras revocar; las copias descargadas no pueden retirarse. Las páginas compartidas envían no-store, no-referrer y noindex.

## HostGator / Legal

Aplicar una sola vez `sql/ADD_DRIVE_TIMED_LINKS_HOSTGATOR.sql` en Legal, después de la migración documental de referencias si falta. Añade días, vencimiento y retiro a `legal_documents`; guarda la URL generada en la columna existente `document_url`, junto con ID estable, nombre, tipo y tamaño. Los registros manuales anteriores conservan valores y visibilidad. No requiere credencial nueva para esta modalidad; conservar las cuentas personales actuales.

Guía y archivos de Legal: [DRIVE_TIMED_LINKS.md](https://github.com/jimmybackend/legal/blob/main/docs/DRIVE_TIMED_LINKS.md). No reimportar dumps completos ni sobrescribir archivos privados. Las delegaciones privadas previas siguen siendo compatibles y conservan su servicio cuando se usen.

## Verificación

`php drive/tests/legal_timed_token_store_test.php` comprueba sustitución atómica, límites de propietario/archivo, conservación de enlaces normales y rechazo de almacenes dañados. El flujo HTTP del repositorio Legal prueba los controladores y servicios reales con bases separadas y TLS verificado, y S3 simulado; incluye creación, vencimiento, renovación, revocación, renombre/movimiento, bloqueos y usuarios sin Drive o sin permiso. Con `TEST_DOCUMENTS_UI=1` verifica los controles reales y móvil. No contacta AWS ni servidores de producción.

La fusión no ejecuta migraciones, despliegues ni cambios de infraestructura. Una escritura fallida entre servidores se compensa retirando el token nuevo cuando es posible; no se puede prometer una transacción distribuida. Si una renovación retiró el enlace previo pero no llegó a guardar el nuevo en Legal, repetirla con el propietario permite recuperar el registro.
