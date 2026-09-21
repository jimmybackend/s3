# Preparación para instalar ArcadeCloud Drive

Estado: **21 de septiembre de 2026**.

Este documento existe para que el administrador tenga **toda la información preparada antes de
ejecutar el futuro instalador de ArcadeCloud Drive**.

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
ETAPA 6  SMTP
ETAPA 7  Primer superadmin
ETAPA 8  FederationCloud
ETAPA 9  Nodo normal o mirror
ETAPA 10 Workers / timers / HTTPS
ETAPA 11 Validación final
```

Las etapas 4, 5, 6 y 7 ya corresponden al orden actual del asistente web `/setup/`:

```text
Base de datos -> AWS -> SMTP -> primer superadmin
```

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

Debe comprobar automáticamente:

```text
Linux compatible
systemd
Nginx
PHP-FPM
Composer
Git
curl
Python 3
Certbot cuando se vaya a usar HTTPS administrado
```

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

# 4. ETAPA 1 — Dominio o IP pública

## El instalador debe preguntar

```text
¿Cómo será accesible este ArcadeCloud?

1. Dominio
2. IP pública
```

## Si se usará dominio, tener preparado

```text
Dominio completo:
________________________________________
```

Ejemplo:

```text
drive.example.com
```

Antes de solicitar el certificado, el DNS debe apuntar al servidor correcto.

Preparar:

```text
Tipo de registro DNS: A / AAAA / CNAME según infraestructura
Hostname:
________________________________________

Destino actual:
________________________________________
```

El instalador debe verificar resolución DNS antes de ejecutar Certbot.

## Si se usará IP pública

No debe pedir al operador una IP si puede detectarla de forma confiable. En EC2, el reconciliador
actual puede detectar IPv4 pública mediante IMDSv2.

Para certificados IP, el código actual requiere Certbot compatible con certificados IP short-lived.

## URLs que se derivan

Con dominio:

```text
ARCADECLOUD_PUBLIC_URL=https://drive.example.com
ARCADECLOUD_FEDERATION_URL=https://drive.example.com/federationcloud/
```

El usuario no debería tener que escribir ambas si el instalador puede construirlas desde el dominio.

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

El sistema muestra el token y el operador abre:

```text
https://TU-DOMINIO/setup/?token=TOKEN_GENERADO
```

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

La base seleccionada debe contener el esquema ArcadeCloud. Para poder crear el primer superadmin debe
existir la tabla:

```text
Users
```

El setup actual **prueba la conexión MySQL**, pero no importa automáticamente el dump SQL.

Por seguridad, el futuro instalador debe preguntar explícitamente:

```text
¿Qué tipo de base usarás?

1. Base nueva vacía para ArcadeCloud
2. Base ArcadeCloud ya existente
```

Si es nueva, el instalador puede ofrecer instalar/migrar el esquema.

Si es existente, nunca debe reimportar ciegamente el dump maestro ni sobrescribir tablas.

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

# 9. ETAPA 6 — SMTP

El setup actual trata como obligatoria la configuración principal SMTP.

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

# 10. ETAPA 7 — Primer superadmin

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

# 11. ETAPA 8 — FederationCloud

Esta etapa debe ejecutarse después de que el Drive local básico funcione.

## El instalador debe preguntar primero

```text
¿Quieres habilitar FederationCloud?

1. Sí
2. No, configurar después
```

Si el usuario responde no, el Drive local puede quedar instalado sin completar esta etapa.

## Si responde sí, tener preparado

```text
Nombre público único del nodo (node_name):
________________________________________
```

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

El instalador debe preguntar:

```text
¿Usar el seed predeterminado de ArcadeCloud?

1. Sí
2. No, indicar otro seed
```

Si se indica otro:

```text
ARCADECLOUD_FEDERATION_SEED_URL:
________________________________________
```

La URL debe incluir:

```text
/federationcloud/
```

Ejemplo correcto:

```text
https://drive.example.com/federationcloud/
```

---

# 12. ETAPA 9 — Tipo de nodo: independiente u origen/mirror

Después de crear la identidad, el instalador debe preguntar:

```text
¿Qué función tendrá este nodo?

1. Nodo normal / independiente
2. Nodo origen
3. Réplica mirror de otro nodo
```

Un nodo normal u origen no configura `ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL`.

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

# 13. ETAPA 10 — Workers, timers y HTTPS

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
```

El operador no debe interpretar como error que un servicio `oneshot` quede:

```text
inactive (dead)
```

después de terminar correctamente.

---

# 14. ETAPA 11 — Validación final

Antes de declarar la instalación terminada, el instalador debe ejecutar un resumen:

```text
[OK] Nginx
[OK] PHP-FPM
[OK] Composer dependencies
[OK] MySQL connection
[OK] ArcadeCloud schema
[OK] S3 configuration
[OK] SMTP configuration
[OK] superadmin
[OK] HTTPS
[OK] Federation identity        (si se habilitó)
[OK] Federation endpoint        (si se habilitó)
[OK] sync timer                 (si se habilitó)
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

DOMINIO / DNS
-------------
Hostname:
________________________________________
DNS ya apunta al servidor:  Sí / No

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

AWS_SESSION_TOKEN (opcional):
________________________________________
AWS_CONTROL_ACCESS_KEY_ID (opcional):
________________________________________
AWS_CONTROL_SECRET_ACCESS_KEY (opcional):
________________________________________
AWS_CONTROL_SESSION_TOKEN (opcional):
________________________________________

SMTP
----
SMTP_HOST:
________________________________________
SMTP_PORT:
________________________________________
SMTP_SECURE:
________________________________________
SMTP_USERNAME:
________________________________________
SMTP_PASSWORD:
________________________________________
SMTP_FROM_EMAIL:
________________________________________
SMTP_FROM_NAME:
________________________________________
SMTP_REPLY_TO:
________________________________________
SMTP_BCC (opcional):
________________________________________
SMTP_TIMEOUT:
________________________________________
SMTP_DEBUG:
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

FEDERATIONCLOUD
---------------
Habilitar FederationCloud: Sí / No
node_name:
________________________________________
Seed personalizado (si aplica):
________________________________________

TIPO DE NODO
------------
Normal / origen / mirror:
________________________________________

SI ES MIRROR
------------
Federation URL del origen:
________________________________________
Comparte MySQL: Sí / No
Comparte S3: Sí / No
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
SMTP
primer superadmin
```

---

# 18. Relación con el instalador futuro

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
