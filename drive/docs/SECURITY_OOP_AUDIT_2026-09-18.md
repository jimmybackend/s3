# Auditoría de seguridad y OOP — 18-Sep-2026

## Alcance

Revisión estática y endurecimiento del runtime de ArcadeCloud Drive sobre la rama `security/oop-hardening-2026-09-18`, iniciada desde `main` en `bd7a3bd0eee05e7abf415a2f013d74ec95165273`.

La auditoría no afirma que exista riesgo cero. El objetivo es eliminar los hallazgos conocidos, reducir superficie heredada y dejar controles automáticos que detecten regresiones.

## Cambios de seguridad

### Subidas desde URL

`RemoteUrlUploader` ya no sigue URLs arbitrarias sin validar.

- sólo HTTPS en puerto 443;
- DNS fijado a IPv4 pública mediante `CURLOPT_RESOLVE`;
- bloqueo de rangos privados y reservados;
- bloqueo explícito de `169.254.169.254`;
- redirecciones manuales y revalidadas, máximo 5;
- no se usa `CURLOPT_FOLLOWLOCATION`;
- máximo 5 GiB por descarga remota;
- tiempo máximo de streaming;
- URL guardada en metadatos sin query string.

Esto reduce el riesgo de SSRF hacia localhost, VPC, servicios internos y metadata EC2.

### CSRF y métodos HTTP

El API principal de subidas:

- exige sesión autenticada;
- exige `POST`;
- exige `X-Drive-CSRF` con token de sesión;
- eliminó el fallback mutante por GET.

Los clientes `subir.js`, `subir-dropzone.js`, `subir-chunked.js` y `soportesMediaTypes.js` envían el token.

`up.php` también exige CSRF para la subida multipart administrativa.

Los endpoints heredados `upload.php` y `subir_archivo.php` conservan compatibilidad, pero ahora requieren CSRF y respuestas de error controladas.

### Grabaciones de audio

`upload_audio_recording.php` dejó de contener lógica S3 procedural.

Ahora delega en `AudioRecordingUploadController`, que:

- requiere usuario autenticado;
- requiere POST + CSRF;
- valida que el temporal sea un upload HTTP real;
- limita a 100 MiB;
- valida MIME del archivo en servidor;
- normaliza el destino al `user_id` autenticado;
- reutiliza `SingleUploadService`;
- registra el archivo en MySQL además de S3;
- no expone excepciones AWS al navegador.

### Sesiones

`SessionManager` aplica para tráfico web:

- `session.use_strict_mode=1`;
- cookie `HttpOnly`;
- `SameSite=Lax`;
- `Secure` cuando la petición es HTTPS;
- regeneración de ID al autenticar ya existente.

### Login

Se añadió `Security\LoginRateLimiter`.

- 5 fallos por cuenta + IP dentro de 15 minutos;
- límite adicional por IP;
- persistencia en archivos temporales con permisos restrictivos;
- borrar cookies no reinicia el límite;
- un login correcto limpia el contador de la cuenta;
- no se volvió a imponer complejidad de caracteres a las contraseñas familiares.

### Errores de producción

- `s3.php` desactiva `display_errors`;
- los 5xx enviados mediante `AbstractJsonController::fail()` registran el detalle en el servidor y devuelven un mensaje neutro;
- los uploads principales y heredados no devuelven `Exception::getMessage()` en errores internos.

### Secretos

`.gitignore` ahora cubre:

- `.env` y variantes;
- llaves privadas y certificados;
- configuración privada;
- backups/dumps potencialmente sensibles.

El workflow de seguridad falla si detecta patrones obvios de AWS access key o private key comprometidos fuera de fixtures/documentación.

## Dependencias

Composer fue resuelto de nuevo en GitHub Actions y `composer audit --locked` terminó sin avisos conocidos.

Versiones resultantes relevantes:

- `aws/aws-sdk-php 3.395.6`;
- `guzzlehttp/guzzle 8.2.0`;
- `guzzlehttp/psr7 3.1.0`;
- `spomky-labs/otphp 11.5.0`.

Dependabot queda configurado semanalmente para Composer y GitHub Actions.

## Arquitectura OOP

Se reforzó el criterio:

```text
HTTP entrypoint -> Controller -> Application Service -> Repository / Infrastructure
```

Cambios concretos:

- `setup/api.php` -> `SetupApiController`;
- `upload_audio_recording.php` -> `AudioRecordingUploadController` -> `SingleUploadService`;
- `SyncController` delega el lanzamiento CLI a `BackgroundWorkerLauncher`;
- `move_job_worker.php` -> `Console\\MoveJobWorkerCommand`;
- `sync_worker.php` -> `Console\\SyncWorkerCommand`;
- `arcadecloud-drive-updater.php` quedó como ejecutable OOP autocontenido porque se instala en `/usr/local/sbin`;
- `arcadecloud-drive-admin-helper.php` quedó como ejecutable OOP autocontenido, conservando allowlists y límites privilegiados;
- `federation_https_reconcile.php` quedó encapsulado en `FederationHttpsReconciler`, conservando IMDSv2, Certbot y Nginx;
- los cuatro módulos JavaScript heredados fueron envueltos en clases sin reescribir su lógica interna;
- el inventario reconoce `readonly class`;
- tests quedan separados de la deuda OOP de runtime;
- wrappers CLI delgados se distinguen de lógica procedural;
- funciones JavaScript dentro de vistas PHP ya no se confunden con funciones PHP;
- un endpoint delgado que toca DB o AWS directamente vuelve a marcarse como deuda.

No se exige convertir HTML, bootstrap o tests en clases artificiales. Los wrappers CLI mínimos pueden seguir siendo entrypoints; la lógica que ejecutan debe vivir en clases, salvo herramientas privilegiadas instaladas como archivos autocontenidos, que encapsulan su comportamiento en una clase dentro del propio ejecutable.

Resultado del gate sobre la rama:

- PHP analizados: **368**;
- módulos PHP con clases/interfaces: **220**;
- deuda OOP PHP de runtime: **0**;
- tests PHP separados del criterio de runtime: **26**;
- JavaScript analizados: **46**;
- JavaScript con clases: **46**;
- deuda OOP JavaScript: **0**;
- fachadas `window` OOP conservadas por compatibilidad: **7**.

El workflow falla si vuelve a aparecer deuda OOP PHP o JavaScript.

## Gate automático

`.github/workflows/security-hardening.yml` ejecuta:

1. `composer install`;
2. `composer audit --locked`;
3. sintaxis de todos los PHP;
4. sintaxis de los JavaScript;
5. inventario OOP;
6. smoke tests de login throttle y SSRF;
7. contrato FederationCloud HTTPS;
8. smoke test del bootstrap inicial;
9. contratos de CSRF/SSRF y helpers privilegiados;
10. escaneo básico de secretos;
11. fallo obligatorio ante cualquier nueva deuda OOP de runtime.

## Pendiente fuera del repositorio

La auditoría del código queda sin deuda OOP de runtime conocida bajo el criterio anterior y sin avisos conocidos en `composer audit --locked`. Esto no equivale a riesgo cero.

Después de fusionar este PR, debe validarse el EC2 desplegado: configuración Nginx/PHP-FPM, permisos de archivos, systemd, sudoers, variables privadas, cabeceras de proxy y ejecución real de los helpers instalados en `/usr/local/sbin`. Esas capas no pueden certificarse únicamente desde GitHub.
