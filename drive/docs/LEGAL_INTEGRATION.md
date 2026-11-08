# Archivos de ArcadeCloud Drive en Legal — fase 1

## Arquitectura e identidad

Legal (HostGator, PDO/MySQL propio) llama desde PHP por HTTPS a `api/legal.php` de Drive (EC2, mysqli y S3 propios). No hay consulta cruzada de bases, sesiones compartidas, copias de archivos ni credenciales AWS en Legal para esta integración. El navegador recibe únicamente metadatos y capacidades temporales opacas.

Se propone **aprovisionamiento explícito por cuenta administrativa**. Una credencial aleatoria de 32 bytes/64 caracteres hexadecimales vincula un `legal_admin_users.id` concreto con un `Users.id` concreto de Drive. Drive guarda su SHA-256 y Legal el secreto original en configuración privada. Los identificadores y la autorización se verifican por el operador; compartir correo no se utiliza como evidencia de identidad. Cada credencial es independiente, solo permite lectura y tiene vencimiento y revocación. No compartir una credencial entre administradores. Varias cuentas pueden autorizarse expresamente al mismo propietario si ese es el acceso acordado.

Alternativa futura: autorización interactiva delegada/OAuth con consentimiento, tokens de acceso y renovación. Requiere incorporar un servidor de autorización y su ciclo de vida, que no existe en el código inspeccionado. En esta fase la conexión inicial requiere configurar ambos servidores una vez; después el usuario trabaja desde Legal sin entrar al escritorio de Drive. El acceso heredado con token de recuperación de Legal no recibe una cuenta Drive implícita: debe usarse una cuenta administrativa personal.

En cada solicitud de API y de contenido, Drive comprueba: autorización activa/no vencida, `Users.userstatus='Activo'`, `FileS3.user_id_`, `Found=1`, bloqueo del archivo y de sus carpetas ascendientes. No se incorporan enlaces públicos, recursos compartidos de otros propietarios ni permisos derivados de `system_role`. Ni siquiera un superadministrador de Legal puede leer otro propietario sin una conexión explícita correspondiente.

En Legal se revalida la sesión administrativa existente, incluida suspensión y `session_version`. Se reutiliza `legal_admin_require_case`: consulta para explorar/abrir/descargar, `can_edit` para vincular y `can_delete` para retirar el vínculo. Se exige una combinación real de cliente/expediente. El cliente se obtiene de `legal_cases.client_id`, sin duplicar una relación que pueda contradecirse. Los formularios POST usan el CSRF administrativo existente. El servidor ignora nombres/tamaños enviados por el navegador y consulta los datos autorizados de Drive antes de guardar.

## Referencias y visibilidad

La nueva tabla `legal_drive_links` guarda expediente, identidad de instalación, `FileS3.id_`, metadatos de respaldo y autor/fecha del vínculo. `FileRecordRepository::renameVisible` y `move` conservan `id_`, por lo que los vínculos sobreviven a esos cambios. Los nombres actuales se consultan de nuevo al abrir la sección. Si Drive no responde, se muestra el nombre guardado junto al estado de verificación pendiente. Si no existe o ya no está autorizado se conserva el vínculo con un estado comprensible.

La referencia es estable dentro de esta instalación y su catálogo: no cubre restauraciones que reasignen IDs, recreación manual de registros o migración a un catálogo nuevo. No reutilizar IDs ni sustituir la identidad de instalación silenciosamente; una reconstrucción del catálogo requiere reconciliar referencias antes de activar el acceso. Conservar el `instance_id` en respaldos y restauraciones del mismo catálogo. Una instalación distinta debe tener otro ID.

**Los vínculos son administrativos y privados.** No se insertan en `legal_documents`, cuyo valor real actual de `client_visible` por defecto es 1. No se agregan al dashboard de clientes ni se alteran las visibilidades existentes de cliente, expediente o documento. Dar un vínculo a un expediente visible NO publica ese archivo. Esta fase no incorpora un control para publicarlos a clientes; esa función requerirá un flujo explícito independiente de consentimiento/visibilidad y autorización de acceso.

Quitar un vínculo solo elimina una fila de `legal_drive_links`. No llama a una operación de escritura en Drive. Una FK `ON DELETE RESTRICT` impide eliminar un expediente que aún conserve estos vínculos; retirarlos primero desde Archivos de Drive. Los documentos anteriores siguen sus reglas actuales.

## Apertura y descarga

Después de validar Legal y Drive, `access` emite una capacidad cifrada AES-256-GCM que vence en **120 segundos**, ligada al archivo, modo, autorización e instalación. No se guarda en MySQL. Legal construye la URL exclusivamente con el origen configurado y el ticket validado; no sigue redirecciones de la API ni acepta una URL de destino del navegador.

`api/legal-content.php` descifra el ticket y repite todas las comprobaciones de Drive antes de transmitir S3 por streaming con soporte de rangos. No expone keys S3 ni URLs presignadas. Revocar la credencial, suspender la cuenta o proteger/retirar el archivo impide nuevas solicitudes de contenido incluso con un ticket previamente emitido. El ticket es una capacidad de lectura temporal: quien lo posea puede usarlo durante su vigencia; no registrarlo en logs ni compartirlo. Una transferencia ya iniciada no se interrumpe por una revocación posterior. Retirar el vínculo o revocar un permiso de Legal no invalida un ticket ya emitido: su exposición máxima restante es de dos minutos.

PDF, imágenes raster, audio, video y texto plano con MIME admitido pueden abrirse en el navegador. Otros tipos, incluidos HTML/SVG y documentos Office, se descargan. No se incorpora LibreOffice/Workstation en esta fase. Caché privada `no-store`, `nosniff`, política sin referrer y sandbox de contenido. La ausencia física en S3 se comprueba al emitir acceso y al transmitir, mientras que el listado consulta el catálogo.

## HostGator — preparación y publicación manual

1. Respaldar archivos y base. Revisar y aprobar conjuntamente las dos ramas antes de publicar. No se ha desplegado ni fusionado automáticamente.
2. Confirmar PHP 8.1+ con PDO MySQL y cURL. Mantener el certificado TLS y permitir HTTPS saliente hacia `drive.esforzados.com`. No abrir MySQL de Drive al exterior.
3. En phpMyAdmin sobre **la base existente** `adbbmis1_legal`, ejecutar **solo** `sql/ADD_DRIVE_LINKS_HOSTGATOR.sql`. No volver a importar el consolidado. Es aditiva, sin ALTER ni cambios de visibilidad. Para instalación nueva, el consolidado ya incluye la tabla. Deben estar aplicadas las migraciones existentes de cuentas y permisos (`ADD_ADMIN_CASE_ACCESS_HOSTGATOR.sql`, `ADD_ADMIN_CASE_DELETE_HOSTGATOR.sql`).
4. Subir `admin-drive.php`, `assets/drive.css`, `assets/drive.js`, `src/drive-api.php`, `src/drive-links.php`, y los archivos modificados `admin-panel.php` y `src/bootstrap.php`. Conservar `database.php`, `.env` y las reglas existentes de `.htaccess`. No subir las pruebas/fixtures al área pública; las reglas actuales ya bloquean `tests`, `src`, `config` y `sql`.
5. Añadir una clave `drive` al array de configuración privada que **ya se utiliza**. No reemplazar el archivo. Se respeta el orden actual `../../database.php`, `config/database.php`, `.env`, sin mezclar fuentes. `config/drive.php.dist` contiene únicamente el fragmento de ejemplo:

```php
'drive' => [
    'base_url' => 'https://drive.esforzados.com', // sin / final; incluir /drive solo si ese es el webroot real
    'instance_id' => 'EL_MISMO_ID_ALEATORIO_DE_LA_CONFIGURACION_DE_DRIVE',
    'accounts' => [
        7 => ['token' => 'TOKEN_ALEATORIO_64_HEX_DE_ESTA_CUENTA'], // sustituir 7 por id real verificado
    ],
],
```

Si la instalación utiliza exclusivamente `.env`, las claves nuevas son `LEGAL_DRIVE_BASE_URL`, `LEGAL_DRIVE_INSTANCE_ID`, `LEGAL_DRIVE_ACCOUNTS_JSON`. La última contiene un objeto JSON privado como `{"7":{"token":"TOKEN_64_HEX"}}`, sin copiar valores reales a Git. Los archivos PHP privados tienen prioridad sobre `.env`, como antes.

6. Iniciar sesión con la cuenta personal autorizada y entrar a **Archivos de Drive** desde el menú. Seleccionar cliente y expediente, explorar/buscar, vincular y abrir. Probar también una cuenta delegada sin edición/eliminación y una sesión de cliente.

No agregar credenciales de AWS ni cambiar las existentes: este módulo solo usa su configuración `drive` y la conexión PDO actual.

## EC2 — preparación de Drive

1. Publicar, tras aprobación, `drive/api/legal.php`, `drive/api/legal-content.php`, `drive/src/Http/Controller/LegalDriveController.php` y `drive/src/Integration/*.php` en el árbol existente. No hay migración de Drive ni dependencia Composer nueva. Conservar `Config-s3.php`, `db.php` y el entorno administrado.
2. PHP necesita mysqli/mysqlnd, OpenSSL y las dependencias AWS ya usadas por Drive. La API reutiliza el composition root y los repositorios de propiedad existentes. Mantener los permisos IAM actuales de lectura S3; este cambio no modifica IAM, instancias, servicios ni reglas AWS.
3. Crear fuera del DocumentRoot `/etc/arcadecloud-drive/legal-api.json`, legible solo para el operador y PHP-FPM (por ejemplo permisos 0640 y grupo correcto). La ubicación puede cambiarse con **`ARCADECLOUD_LEGAL_API_CONFIG`**, disponible en el proceso PHP-FPM, no solo en una terminal. La configuración no se crea automáticamente desde la API. Tomar como referencia `drive/config/legal-api.json.example`.
4. Generar por consola privada un ID aleatorio de instalación (una sola vez) y, por cada cuenta, una credencial independiente. Por ejemplo `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Registrar el token original solo en la entrada de esa cuenta de Legal y SHA-256 del texto hexadecimal en Drive. Calcular el hash leyendo el token por entrada estándar, evitando argumentos/historial/logs. No pegar secretos en el chat.
5. Cada entrada `grants` tiene un ID no secreto (por ejemplo `legal-admin-7`), `user_id` **entero real verificado en Users**, `token_hash` de 64 caracteres, `enabled: true` y `expires_at` **entero Unix UTC** futuro. `expires_at: 0` y `enabled: false` de la plantilla fallan cerrados. El nombre del grant es una etiqueta; no sustituye la asociación de cuentas revisada por el operador.
6. El vhost debe permitir los dos nuevos entrypoints y transmitir `Authorization` a PHP (`fastcgi_param HTTP_AUTHORIZATION $http_authorization;` cuando sea necesario), además de establecer correctamente `HTTPS=on` bajo TLS. No confiar en una cabecera HTTPS enviada por usuarios. No habilitar CORS: el acceso API es servidor a servidor.
7. Evitar registrar query strings de `api/legal-content.php` en Nginx, proxies, CDN y APM. Nunca registrar Authorization. Aplicar límites de solicitudes a la API en el vhost según el volumen real, sin registrar secretos. No se modifica automáticamente Nginx en este cambio.

Para revocar, poner `enabled:false` o retirar el grant de la configuración privada. Para rotar, emitir token nuevo, cambiar su hash en Drive y actualizar la entrada exacta de Legal; la rotación invalida los tickets anteriores. Revisar expiración y asignaciones al suspender o cambiar de función a un administrador. Si se comparte una cuenta Drive entre administradores, cada uno debe tener su propia credencial revocable.

## Comprobaciones y límites

- `php tests/drive_api_test.php` en Legal y `php drive/tests/legal_api_config_test.php` en s3: validación de entradas, identidad, errores cerrados, cifrado, vencimiento, manipulación, rotación y revocación.
- `python3 tests/drive_integration_test.py --drive-repo /ruta/a/s3` desde Legal: prueba HTTP completa con PHP real, bases separadas en un **MySQL/MariaDB local desechable**, TLS local con verificación de certificado y S3 simulado. Requiere PHP con extensiones, Python, OpenSSL y variables `TEST_DB_PORT`, `TEST_DB_USER` (por defecto root), `TEST_DB_PASSWORD` no vacía. Crea `adbbmis1_legal` solo si no existe y una base temporal de Drive; NO ejecutar contra bases reales. La cuenta necesita CREATE/DROP DATABASE en ese servidor desechable. Las fixtures usan solo columnas confirmadas; no son migraciones.
- El flujo prueba propiedad, carpeta ajena, bloqueo de archivo y ascendientes, objetos ausentes, CSRF, cliente/expediente falsificado, permisos de consulta/edición/eliminación, suspensión, revocación, enlaces estables tras mover/renombrar, retiro sin borrar originales y aislamiento del cliente.
- La búsqueda es por nombre en todo el catálogo del propietario. No busca contenido/OCR. Se pagina a 30 archivos, 30 carpetas y 30 vínculos. El indicador «ya vinculado» se refiere al expediente seleccionado; no revela otros expedientes sin permiso. La búsqueda `LOCATE` puede costar un recorrido amplio de nombres en catálogos grandes; evaluar un índice de búsqueda si el volumen lo exige.
- No hay alta automática de conexiones, renovación automática de credenciales, publicación a clientes, archivos compartidos de terceros, modificación de originales ni reconciliación entre instalaciones distintas. El selector muestra los expedientes actualmente autorizados para cada administrador.
- Los ensayos no contactan HostGator, EC2 ni S3 real. Queda pendiente la aceptación controlada en los servidores, incluyendo certificados, reenvío de Authorization, permisos de lectura del JSON privado, estado del catálogo y streaming/tamaño/rangos sobre S3 real. No afirmar validación en producción hasta realizarla con autorización.

### Resultado de la revisión en desarrollo

Las pruebas de capacidades y el flujo HTTP entre ambos repositorios pasaron con PHP 8.3 y MariaDB 10.11 local, con almacenamiento S3 simulado. También pasaron sintaxis PHP, JavaScript, resolución de configuración privada y pruebas existentes de roles/permisos. Se confirmó que nueve pruebas estáticas fallaban ya en el baseline de Legal; se actualizó únicamente la prueba de consistencia de esquema afectada para incluir la nueva tabla y reconocer el login administrativo que ya existe. Las ocho restantes siguen pendientes y no se deben presentar como un CI global verde.

La prueba visual móvil está disponible con `TEST_UI=1`, Node, Playwright y Chromium instalados (`tests/drive_mobile_test.cjs`). Comprueba ancho de 390 px, selección de cliente/expediente, controles por permiso y apertura entre orígenes con CSP. No pudo ejecutarse en este entorno porque no estaba disponible el binario de Chromium y su descarga falló. La verificación visual en navegador/móvil queda pendiente; los controles usan diseño adaptable y objetivos de 44 px.
