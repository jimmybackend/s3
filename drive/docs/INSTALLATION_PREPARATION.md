# Preparación para instalar ArcadeCloud Drive

Estado: **21 de septiembre de 2026**.

Este documento existe para que el administrador tenga **toda la información preparada antes de
ejecutar el instalador de ArcadeCloud Drive**. El preflight automático para Amazon Linux 2023 ya está
implementado y se documenta en `AUTOMATED_INSTALLER.md`.

La idea es sencilla: el instalador debe avanzar por etapas y, cuando solicite un dato, el operador ya
debe tenerlo listo para pegar. No debe obligar a detener la instalación para ir a crear un bucket,
buscar una contraseña MySQL, configurar DNS o averiguar qué correo utilizará el superadmin.

> No guardes una copia rellena de este documento dentro del repositorio. Si lo usas como hoja de
> trabajo, guárdalo únicamente en una ubicación privada y segura.

## 1. Qué hará el instalador y qué debe aportar el operador

El instalador debe distinguir tres clases de información:

### A. Datos que el operador debe tener preparados

- dominio o decisión de usar IP pública;
- acceso MySQL;
- nombre de la base de datos;
- bucket S3;
- credenciales AWS;
- configuración SMTP;
- datos del primer superadmin;
- nombre público del nodo FederationCloud;
- si será réplica, URL FederationCloud del nodo origen.

### B. Datos que el instalador debe detectar

Siempre que sea posible, el instalador no debe preguntar cosas que pueda descubrir de forma segura:

- distribución Linux;
- arquitectura CPU;
- ruta de PHP;
- versión PHP;
- usuario real de PHP-FPM;
- presencia de Nginx;
- presencia de Composer;
- presencia de Certbot;
- IP pública EC2 cuando aplique;
- estado de systemd;
- estado del repositorio Git.

Si una detección es ambigua, entonces sí debe preguntar y mostrar lo detectado como opción sugerida.

### C. Datos que ArcadeCloud debe generar

Nunca deben pedirse al usuario para copiar/pegar:

- clave privada Ed25519 FederationCloud;
- `node_id`;
- `payload_key`;
- token aleatorio de activación del setup;
- hashes de contraseña;
- firmas FederationCloud;
- Request IDs;
- claves internas de trabajo.

Esos valores los genera el sistema.

---

# 2. Resumen de etapas

El flujo objetivo del instalador debe ser:

```text
ETAPA 0  Preflight del servidor
ETAPA 1  Dominio / endpoint público
ETAPA 2  Repositorio + dependencias
ETAPA 3  Bootstrap temporal de /setup/
ETAPA 4  Base de datos
ETAPA 5  AWS / S3
ETAPA 6  Primer superadmin
ETAPA 7  FederationCloud básico automático
ETAPA 8  Configuración básica / avanzada dentro de ArcadeCloud
ETAPA 9  Workers / timers / HTTPS
ETAPA 10 Validación final
```

El asistente web `/setup/` queda reducido a:

```text
Base de datos -> AWS/S3 -> primer superadmin
```

La configuración básica deja el Drive utilizable por HTTP/IP aunque no exista dominio y, cuando se
detecta una IPv4 pública, activa FederationCloud/ArcadeLink sobre esa IP. Un dominio posterior exige
HTTPS. Todo lo demás que sea opcional permanece en No/no configurado.

---

# 3. ETAPA 0 — Preflight del servidor

## Qué debe tener listo el operador

No son datos para pegar, pero sí condiciones necesarias:

- acceso SSH al servidor;
- cuenta con `sudo` o acceso root;
- servidor con salida a Internet;
- espacio de disco suficiente para aplicación, temporales y Composer;
- puertos HTTP/HTTPS accesibles cuando el nodo será público.

## Qué debe hacer el instalador

Debe comprobar automáticamente —e instalar cuando falte en Amazon Linux 2023—:

```text
Linux compatible
systemd
Nginx
PHP-FPM
Composer
Git
curl
Python 3
Certbot cuando esté disponible para HTTPS administrado
```

La ausencia de Certbot no debe afectar la instalación básica. Certbot sólo es necesario cuando el
administrador decide habilitar un endpoint HTTPS.

También debe detectar el usuario del pool PHP-FPM.

Hoy puede verificarse manualmente con:

```bash
ps -eo user=,comm=,args= | grep '[p]hp-fpm'
```

El instalador debe mostrar algo como:

```text
PHP-FPM detectado:
  usuario: nginx
  grupo: nginx

¿Usar esta configuración? [S/n]
```

---

# 4. ETAPA 1 — Endpoint público automático

La instalación básica no pregunta dominio ni IP. El instalador intenta detectar automáticamente una
IPv4 pública global, primero mediante EC2 IMDSv2 y luego mediante una consulta externa de respaldo.

Cuando la obtiene, deriva:

```text
ARCADECLOUD_PUBLIC_URL=http://IP_PUBLICA
ARCADECLOUD_FEDERATION_URL=http://IP_PUBLICA/federationcloud/
ARCADECLOUD_FEDERATION_ENABLED=true
```

La IP HTTP es un endpoint válido y suficiente para dejar ArcadeCloud Drive y FederationCloud básico
operativos. HTTP para FederationCloud se admite únicamente con una IP literal. Si no puede detectar una
IP pública válida, **no bloquea el Drive**: lo deja preparado localmente y FederationCloud queda pendiente.

Un dominio propio pertenece a Configuración avanzada. Cambiar posteriormente de IP a dominio no
regenera `node_id`, Ed25519 ni `payload_key`.

---

# 5. ETAPA 2 — Repositorio y dependencias

## El instalador no debería pedir datos adicionales

Debe:

1. clonar o actualizar el repositorio;
2. dejar la rama estable seleccionada;
3. ejecutar Composer;
4. comprobar permisos;
5. instalar/configurar los helpers requeridos.

Dependencias PHP:

```bash
composer install --no-dev --optimize-autoloader
```

`vendor/` no forma parte del repositorio y está ignorado por Git.

El updater actual hace fast-forward de Git, pero no ejecuta Composer automáticamente. El instalador
nuevo debe considerar esto explícitamente.

---

# 6. ETAPA 3 — Supervisor temporal de instalación

El setup inicial ya existe.

El helper se instala actualmente con:

```bash
sudo bash drive/bin/install_arcadecloud_admin_helper.sh \
  --php-user=USUARIO_REAL_PHP_FPM \
  --bootstrap-setup
```

El sistema genera un token aleatorio de activación.

## El operador NO necesita preparar

- token;
- usuario temporal;
- hash;
- archivo bootstrap.

El sistema muestra el token y, sin dominio, el operador abre:

```text
http://IP_PUBLICA/setup/?token=TOKEN_GENERADO
```

Si posteriormente existe un dominio con HTTPS también puede usarse ese dominio.

Las credenciales temporales actuales son:

```text
usuario: arcadecloud
contraseña inicial: arcadecloud
```

Por sí solas no bastan: también se necesita el token aleatorio generado por el servidor.

---

# 7. ETAPA 4 — Base de datos MySQL

Esta es la primera información que el administrador debe tener realmente lista para pegar en
`/setup/`.

## Preparar antes de empezar

```text
DB_HOST:
________________________________________

DB_PORT:
________________________________________
Normalmente: 3306

DB_USER:
________________________________________

DB_PASSWORD:
________________________________________

DB_NAME:
________________________________________
```

Obligatorios actualmente:

- `DB_HOST`;
- `DB_USER`;
- `DB_PASSWORD`;
- `DB_NAME`.

`DB_PORT` usa `3306` si no se proporciona otro valor.

## Condición importante

El repositorio tiene **un solo SQL canónico**:

```text
adbbmis1_Cloud.sql
```

Una base nueva y vacía para ArcadeCloud se crea/importa únicamente desde ese archivo. El dump contiene
el esquema base y la sección completa FederationCloud, incluida `FederationEvents`.

Para poder crear el primer superadmin debe existir la tabla:

```text
Users
```

El setup web **prueba la conexión MySQL**, pero no reimporta automáticamente el dump completo. Esto es
deliberado porque `adbbmis1_Cloud.sql` contiene `DROP TABLE IF EXISTS` para recrear una base limpia.

En una base ArcadeCloud ya existente nunca se ejecuta el dump completo. Las actualizaciones
FederationCloud usan `drive/bin/federation_catalog_migrate.php`, que extrae exclusivamente la sección
idempotente delimitada por:

```text
-- ARCADECLOUD:FEDERATION_SCHEMA:BEGIN
-- ARCADECLOUD:FEDERATION_SCHEMA:END
```

Así el repositorio conserva una sola fuente SQL sin borrar datos al actualizar un nodo existente.

El archivo canónico se mantiene alineado con el esquema funcional de producción, pero se sanea para instalación limpia: no incluye filas ligadas a un usuario existente (por ejemplo `UserPipelineFeatures`, `UserPreferences` o overrides `voice_main` de usuario). Sí incluye tablas runtime necesarias como `S3SyncSeen` y el índice vigente `uq_files3_user_path_key` de `FileS3`.

## Qué valida ArcadeCloud

Antes de guardar el bloque DB intenta una conexión real. Si falla, no guarda el cambio.

---

# 8. ETAPA 5 — AWS y Amazon S3

## Tener preparado

```text
AWS_REGION:
________________________________________
Ejemplo frecuente: us-east-1

AWS_S3_BUCKET:
________________________________________

AWS_ACCESS_KEY_ID:
________________________________________

AWS_SECRET_ACCESS_KEY:
________________________________________
```

Estos cuatro valores son obligatorios en el setup actual.

## Opcionales

Sólo preparar si tu infraestructura los usa:

```text
AWS_SESSION_TOKEN:
________________________________________

AWS_CONTROL_ACCESS_KEY_ID:
________________________________________

AWS_CONTROL_SECRET_ACCESS_KEY:
________________________________________

AWS_CONTROL_SESSION_TOKEN:
________________________________________
```

Las credenciales `AWS_CONTROL_*` son opcionales. Si se configura una access key de control, también
debe configurarse su secret.

## Antes de instalar

El operador debe decidir si:

- usará un bucket existente; o
- creará uno nuevo antes del setup.

El bucket debe existir y las credenciales AWS deben tener los permisos requeridos por las funciones
que el operador quiera utilizar.

FederationCloud no envía ni distribuye estas credenciales.

---

# 9. SMTP — configuración avanzada posterior

SMTP ya no bloquea la instalación básica. Estos datos sólo se preparan si el administrador decide
activar correo desde **Servidor -> Configuración avanzada**.

## Tener preparado

```text
ARCADECLOUD_SMTP_HOST:
________________________________________

ARCADECLOUD_SMTP_PORT:
________________________________________

ARCADECLOUD_SMTP_SECURE:
________________________________________
Ejemplos admitidos por configuración: tls / ssl según proveedor

ARCADECLOUD_SMTP_USERNAME:
________________________________________

ARCADECLOUD_SMTP_PASSWORD:
________________________________________

ARCADECLOUD_SMTP_FROM_EMAIL:
________________________________________

ARCADECLOUD_SMTP_FROM_NAME:
________________________________________

ARCADECLOUD_SMTP_REPLY_TO:
________________________________________

ARCADECLOUD_SMTP_TIMEOUT:
________________________________________

ARCADECLOUD_SMTP_DEBUG:
________________________________________
```

Opcional:

```text
ARCADECLOUD_SMTP_BCC:
________________________________________
```

La UI actual propone algunos valores iniciales:

```text
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_FROM_NAME=ArcadeCloud Drive
SMTP_TIMEOUT=20
SMTP_DEBUG=false
```

El host, usuario, contraseña y correos deben corresponder al proveedor real del administrador.

`FROM_EMAIL` y `REPLY_TO` deben ser correos válidos. `BCC`, si se configura, también.

---

# 10. ETAPA 6 — Primer superadmin

## Tener preparado

```text
Nombre:
________________________________________

Apellido:
________________________________________

Correo:
________________________________________

Contraseña:
________________________________________
```

Reglas actuales:

- nombre obligatorio;
- apellido obligatorio;
- correo válido y no duplicado;
- contraseña de al menos 10 caracteres.

El usuario se crea con:

```text
system_role = superadmin
userstatus = Activo
```

Después de crear o detectar el primer superadmin, el bootstrap temporal se cierra y se crea:

```text
/etc/arcadecloud-drive/setup.lock
```

El instalador debe advertir claramente antes de este paso:

```text
Al completar el primer superadmin se cerrará el supervisor temporal de instalación.
```

---

# 11. ETAPA 7 — Identidad FederationCloud preparada

Esta etapa se prepara automáticamente sin convertir FederationCloud en requisito del Drive.

## En modo básico el instalador **no pregunta** si se desea FederationCloud ni solicita `node_name`.

El comportamiento predeterminado es:

```text
detectar IPv4 pública
-> generar node_name automáticamente
-> generar identidad Ed25519
-> PUBLIC_URL=http://IP
-> ARCADECLOUD_FEDERATION_URL=http://IP/federationcloud/
-> ARCADECLOUD_FEDERATION_ENABLED=true
-> conservar seed predeterminado
```

El HTTP por IP sirve para completar el setup inicial. Durante la finalización, FederationCloud convierte
ese endpoint a HTTPS, presenta automáticamente el descriptor firmado al seed primario y valida la
respuesta del directorio global. Si después se usa un dominio, el endpoint puede migrarse sin regenerar
`node_id` ni llaves.

El `node_name` automático puede cambiarse posteriormente sin cambiar `node_id` ni las llaves.

## Identidad generada

Ejemplo:

```text
oficina-mexico
```

El instalador genera automáticamente:

- identidad Ed25519;
- `node_id`;
- `payload_key`;
- clave pública/privada.

Nunca debe pedir al usuario una clave privada FederationCloud existente, excepto dentro de un flujo
explícito de **restauración de identidad**.

## Seed FederationCloud

La instalación básica usa automáticamente el primer seed configurado en:

```text
drive/config/federation-seeds.json
```

No pregunta por un seed. Un seed personalizado se configura posteriormente desde Configuración
avanzada y debe terminar en `/federationcloud/`.

---

# 12. ETAPA 8 — Configuración básica y avanzada


Después del primer acceso, el panel **Servidor** presenta dos modos:

```text
Configuración básica
  - MySQL
  - AWS/S3 principal
  - FederationCloud esencial

Configuración avanzada
  - SMTP
  - AWS_SESSION_TOKEN
  - AWS_CONTROL_*
  - mirror/provider
  - origin URL
  - role/scope
  - ajustes especiales
```

La configuración básica no pregunta por opciones avanzadas: equivale a responder **No** a todas ellas.

### Mirror

Sólo al entrar en Configuración avanzada y decidir usar un mirror se necesitan los datos siguientes.

## Si será mirror, tener preparado

```text
URL FederationCloud del origen:
________________________________________
Ejemplo:
https://origin.example.com/federationcloud/
```

El instalador puede construir:

```env
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL=https://origin.example.com/federationcloud/
ARCADECLOUD_FEDERATION_REPLICA_ROLE=mirror
ARCADECLOUD_FEDERATION_REPLICA_SCOPE=all_allowed_resources
```

Para el mirror automático actual:

```text
role  = mirror
scope = all_allowed_resources
```

## Si compartirá MySQL o S3 con el origen

Eso es una decisión de infraestructura.

El instalador puede preguntar:

```text
¿Esta réplica comparte backend con el origen?

[ ] MySQL compartida
[ ] S3 compartido
[ ] ambos
[ ] ninguno
```

Esta información sirve para diagnóstico y validación, pero FederationCloud **no debe pedir ni enviar
las credenciales del origen como prueba de relación**.

La autorización privilegiada usa:

```text
relationship=shared_backend
```

y requiere aprobación del superadmin del origen.

---

# 13. ETAPA 9 — Workers, timers y HTTPS

Aquí el instalador debería actuar casi completamente solo.

Debe instalar/verificar:

```text
arcadecloud-federation-migrate.service
arcadecloud-federation-sync.service
arcadecloud-federation-sync.timer
```

Y cuando aplique:

```text
arcadecloud-federation-https.service
arcadecloud-federation-https.timer
```

Debe comprobar:

```text
timer enabled
timer active
migración SUCCESS
Nginx válido
HTTPS válido
endpoint FederationCloud accesible
registro automático en seed confirmado
directorio global accesible
```

El operador no debe interpretar como error que un servicio `oneshot` quede:

```text
inactive (dead)
```

después de terminar correctamente.

---

# 14. ETAPA 10 — Validación final

Antes de declarar la instalación terminada, el instalador debe ejecutar un resumen:

```text
[OK] Nginx
[OK] PHP-FPM
[OK] Composer dependencies
[OK] MySQL connection
[OK] ArcadeCloud schema
[OK] S3 configuration
[OK] superadmin
[OK] HTTPS
[OK] Federation identity        (si se habilitó)
[OK] Federation endpoint        (si se habilitó)
[OK] registro en seed            (si se habilitó)
[OK] directorio global           (si se habilitó)
[OK] sync timer                  (si se habilitó)
[OK] mirror authorization       (si aplica)
```

Si algo queda pendiente debe decir exactamente qué falta, sin destruir la configuración ya válida.

---

# 15. Hoja de preparación rápida

Esta sección puede copiarse a una nota privada antes de iniciar.

```text
========================================================
ARCADECLOUD DRIVE — DATOS PARA INSTALACIÓN
========================================================

SERVIDOR
-------
Acceso sudo/root disponible:  Sí / No
Dominio o IP pública:
________________________________________

MYSQL
-----
DB_HOST:
________________________________________
DB_PORT:
________________________________________
DB_USER:
________________________________________
DB_PASSWORD:
________________________________________
DB_NAME:
________________________________________
Base nueva o existente:
________________________________________

AWS / S3
--------
AWS_REGION:
________________________________________
AWS_S3_BUCKET:
________________________________________
AWS_ACCESS_KEY_ID:
________________________________________
AWS_SECRET_ACCESS_KEY:
________________________________________

PRIMER SUPERADMIN
-----------------
Nombre:
________________________________________
Apellido:
________________________________________
Correo:
________________________________________
Contraseña preparada: Sí / No

FEDERATIONCLOUD BÁSICO
----------------------
No requiere datos: IP, node_name, identidad y seed se resuelven automáticamente cuando es posible.

DATOS AVANZADOS
---------------
No son necesarios para instalar. SMTP, tokens AWS, dominio propio y mirror se configuran después.
```

**No guardes una hoja rellenada con secretos dentro del repositorio, correo no cifrado o un chat
público.**

---

# 16. Información que NO debe pedir el instalador

Aunque el usuario la tenga, no debe solicitarse como entrada ordinaria:

```text
clave privada Ed25519 FederationCloud
payload_key
node_id inventado manualmente
hash de contraseña
token de activación elegido por el usuario
Request ID FederationCloud
firma de autorización
cookies de sesión
rutas privadas S3 permanentes
```

Esos valores son generados o administrados por ArcadeCloud.

---

# 17. Información que puede configurarse después

No todo debe bloquear la instalación inicial.

Puede diferirse:

- FederationCloud completo, si sólo se quiere usar Drive local al principio;
- creación de mirrors adicionales;
- credenciales AWS de control separadas;
- `AWS_SESSION_TOKEN` si no se usan credenciales temporales;
- SMTP BCC;
- automatización HTTPS avanzada para IP dinámica;
- replicas adicionales.

En cambio, para que el setup actual termine correctamente deben quedar listos:

```text
MySQL
AWS/S3
primer superadmin
```

---


# 18. Dónde se guarda cada dato

No todos los datos deben terminar en el mismo archivo. La separación recomendada es:

- **configuración editable de ejecución** -> un solo archivo administrado;
- **identidad criptográfica** -> archivo privado separado;
- **usuarios** -> MySQL;
- **estado temporal del instalador** -> archivos de control separados;
- **Nginx/Certbot/systemd** -> configuración propia del sistema operativo.

## Archivo principal recomendado

Para instalaciones nuevas, la configuración normal de ArcadeCloud debe concentrarse en:

```text
/etc/arcadecloud-drive/runtime-env.json
```

Este archivo ya es la fuente administrada por ArcadeCloud y tiene precedencia sobre variables recibidas
por PHP-FPM.

Aquí deben quedar:

```text
MYSQL
DB_HOST
DB_PORT
DB_USER
DB_PASSWORD
DB_NAME

AWS / S3
AWS_REGION
AWS_S3_BUCKET
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_SESSION_TOKEN                     (opcional)
AWS_CONTROL_ACCESS_KEY_ID             (opcional)
AWS_CONTROL_SECRET_ACCESS_KEY         (opcional)
AWS_CONTROL_SESSION_TOKEN             (opcional)

SMTP
ARCADECLOUD_SMTP_HOST
ARCADECLOUD_SMTP_PORT
ARCADECLOUD_SMTP_SECURE
ARCADECLOUD_SMTP_USERNAME
ARCADECLOUD_SMTP_PASSWORD
ARCADECLOUD_SMTP_FROM_EMAIL
ARCADECLOUD_SMTP_FROM_NAME
ARCADECLOUD_SMTP_REPLY_TO
ARCADECLOUD_SMTP_BCC                  (opcional)
ARCADECLOUD_SMTP_TIMEOUT
ARCADECLOUD_SMTP_DEBUG

FEDERATIONCLOUD BASE
ARCADECLOUD_PUBLIC_URL
ARCADECLOUD_FEDERATION_URL
ARCADECLOUD_FEDERATION_ENABLED
ARCADECLOUD_FEDERATION_SEED_URL
```

Ejemplo **sintético** de estructura:

```json
{
  "DB_HOST": "db.example.internal",
  "DB_PORT": "3306",
  "DB_USER": "arcadecloud",
  "DB_PASSWORD": "***",
  "DB_NAME": "arcadecloud",
  "AWS_REGION": "us-east-1",
  "AWS_S3_BUCKET": "example-bucket",
  "AWS_ACCESS_KEY_ID": "***",
  "AWS_SECRET_ACCESS_KEY": "***",
  "ARCADECLOUD_SMTP_HOST": "smtp.example.com",
  "ARCADECLOUD_SMTP_PORT": "587",
  "ARCADECLOUD_SMTP_SECURE": "tls",
  "ARCADECLOUD_SMTP_USERNAME": "noreply@example.com",
  "ARCADECLOUD_SMTP_PASSWORD": "***",
  "ARCADECLOUD_SMTP_FROM_EMAIL": "noreply@example.com",
  "ARCADECLOUD_SMTP_FROM_NAME": "ArcadeCloud Drive",
  "ARCADECLOUD_SMTP_REPLY_TO": "support@example.com",
  "ARCADECLOUD_SMTP_TIMEOUT": "20",
  "ARCADECLOUD_SMTP_DEBUG": "false",
  "ARCADECLOUD_PUBLIC_URL": "https://drive.example.com",
  "ARCADECLOUD_FEDERATION_URL": "https://drive.example.com/federationcloud/",
  "ARCADECLOUD_FEDERATION_ENABLED": "true",
  "ARCADECLOUD_FEDERATION_SEED_URL": "https://seed.example.com/federationcloud/"
}
```

El ejemplo usa valores ficticios. El archivo real nunca se versiona.

## Excepción actual: configuración específica de mirror

Las variables específicas de réplica ya forman parte de la configuración administrada. Para
instalaciones nuevas también se guardan en:

```text
/etc/arcadecloud-drive/runtime-env.json
```

`federation.env` se conserva sólo como compatibilidad para instalaciones existentes.

Contenido mínimo de un mirror:

```env
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL=https://origin.example.com/federationcloud/
ARCADECLOUD_FEDERATION_REPLICA_ROLE=mirror
ARCADECLOUD_FEDERATION_REPLICA_SCOPE=all_allowed_resources
```

Para instalaciones nuevas no es necesario duplicar en `federation.env` las variables que ya estén en
`runtime-env.json`.

### Objetivo futuro de unificación

El futuro instalador debería ampliar la configuración administrada para que también acepte:

```text
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL
ARCADECLOUD_FEDERATION_REPLICA_ROLE
ARCADECLOUD_FEDERATION_REPLICA_SCOPE
```

Cuando eso exista, una instalación nueva podrá usar **un único archivo de configuración runtime**:

```text
/etc/arcadecloud-drive/runtime-env.json
```

y `federation.env` quedará sólo como compatibilidad para instalaciones antiguas.

## Identidad FederationCloud

No debe mezclarse con `runtime-env.json`.

Ruta:

```text
/etc/arcadecloud-drive/federation-node.json
```

Contiene la identidad criptográfica generada por ArcadeCloud:

```text
node_name
node_id
public_key
private_key
payload_key
```

Este archivo debe permanecer separado porque contiene material criptográfico privado y tiene permisos
más restrictivos.

El usuario sólo proporciona:

```text
node_name
```

ArcadeCloud genera el resto.

## Primer superadmin

Los datos del superadmin **no se guardan en un archivo de configuración**.

Destino:

```text
MySQL
tabla: Users
```

Se almacenan, entre otros:

```text
firstname
lastname
email
password = hash
system_role = superadmin
userstatus = Activo
```

La contraseña en texto plano nunca se guarda en un archivo.

## Tipo de nodo

`Normal / origen / mirror` no necesita un archivo independiente.

El runtime lo determina por su configuración:

```text
normal/origen:
  no existe ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL

mirror:
  ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL configurada
  ARCADECLOUD_FEDERATION_REPLICA_ROLE=mirror
  ARCADECLOUD_FEDERATION_REPLICA_SCOPE=all_allowed_resources
```

## “Comparte MySQL” y “Comparte S3”

Estas respuestas describen la topología de infraestructura; no son credenciales ni variables necesarias
para que la aplicación funcione.

Actualmente **no deben escribirse dentro de `runtime-env.json`**.

El instalador futuro puede conservarlas como metadatos no secretos de instalación en:

```text
/etc/arcadecloud-drive/install-state.json
```

por ejemplo:

```json
{
  "node_role": "mirror",
  "shared_mysql": true,
  "shared_s3": true
}
```

Ese archivo sería informativo para diagnóstico; no debe convertirse en fuente de credenciales ni de
autorización FederationCloud.

## Dominio y HTTPS

El dominio se refleja en:

```text
/etc/arcadecloud-drive/runtime-env.json
  ARCADECLOUD_PUBLIC_URL
  ARCADECLOUD_FEDERATION_URL
```

La configuración web pertenece a Nginx, normalmente bajo:

```text
/etc/nginx/conf.d/
```

El nombre exacto del vhost puede derivarse del dominio durante la instalación.

Los certificados administrados por Certbot quedan fuera de ArcadeCloud, normalmente bajo:

```text
/etc/letsencrypt/live/<dominio>/
```

El DNS no se guarda en ArcadeCloud; pertenece al proveedor DNS del administrador.

## Archivos temporales/de control del setup

### Supervisor temporal

```text
/etc/arcadecloud-drive/bootstrap-auth.json
```

Se elimina al completar el primer superadmin.

### Marca de setup terminado

```text
/etc/arcadecloud-drive/setup.lock
```

Evita reabrir accidentalmente el bootstrap inicial.

### Configuración del helper privilegiado

```text
/etc/arcadecloud-drive/admin-helper.json
```

No contiene las credenciales DB/AWS/SMTP; describe rutas y usuario/grupo permitidos.

### Configuración del updater

```text
/etc/arcadecloud-drive/updater.json
```

Describe el repositorio y el usuario que puede actualizarlo; no debe contener secretos runtime.

## Resumen dato -> destino

| Información | Destino |
|---|---|
| MySQL | `/etc/arcadecloud-drive/runtime-env.json` |
| AWS/S3 | `/etc/arcadecloud-drive/runtime-env.json` |
| SMTP | `/etc/arcadecloud-drive/runtime-env.json` |
| URL pública FederationCloud | `/etc/arcadecloud-drive/runtime-env.json` |
| Seed FederationCloud | `/etc/arcadecloud-drive/runtime-env.json` |
| Mirror origin/role/scope | `/etc/arcadecloud-drive/runtime-env.json` |
| `node_name` + identidad Ed25519 | `/etc/arcadecloud-drive/federation-node.json` |
| Primer superadmin | MySQL, tabla `Users` |
| Tipo de nodo | derivado de la configuración FederationCloud |
| Comparte MySQL/S3 | no es runtime; opcionalmente `install-state.json` futuro |
| Token bootstrap | `bootstrap-auth.json`, temporal |
| Setup terminado | `setup.lock` |
| Helper administrativo | `admin-helper.json` |
| Updater | `updater.json` |
| Vhost del dominio | `/etc/nginx/conf.d/` |
| Certificados | `/etc/letsencrypt/live/<dominio>/` |

## Recomendación

Para una instalación nueva, evitar repartir DB/AWS/SMTP entre `drive.env`, `smtp.env` y otros
EnvironmentFile salvo que una instalación heredada ya los use.

El modelo recomendado es:

```text
/etc/arcadecloud-drive/
├── runtime-env.json          <- configuración runtime principal
├── federation-node.json      <- identidad privada, separada
├── admin-helper.json         <- configuración del helper
├── updater.json              <- configuración del updater
├── bootstrap-auth.json       <- temporal durante setup
└── setup.lock                <- marca de instalación terminada
```

La aplicación mantiene compatibilidad con EnvironmentFile antiguos, pero las instalaciones nuevas
deberían tener una sola fuente administrada para la configuración normal para reducir errores de
precedencia, duplicación y diagnóstico.

---

# 19. Relación con el instalador futuro

Este documento debe considerarse el **contrato de entrada** del futuro
`install-arcadecloud.sh`.

El instalador deberá:

1. mostrar al inicio una lista de datos que se pedirán;
2. permitir cancelar antes de modificar el servidor;
3. validar cada bloque antes de continuar;
4. no imprimir secretos completos después de recibirlos;
5. guardar secretos únicamente en las rutas privadas admitidas;
6. poder reanudar una instalación incompleta sin regenerar identidades ni romper datos válidos;
7. distinguir instalación nueva de actualización;
8. distinguir base nueva de base existente;
9. no sobrescribir una identidad FederationCloud existente;
10. producir al final un reporte de estado sin secretos.

La guía técnica de instalación y mirrors está en
`FEDERATION_NODE_REPLICA_INSTALL.md`, mientras que el setup web actual está documentado en
`INITIAL_SETUP.md`.
