# Superadmin: identidad del nodo y configuración del servidor

## Objetivo

ArcadeCloud Drive permite que un `Users.system_role = superadmin` administre desde la aplicación dos áreas que antes obligaban a entrar por SSH:

1. crear o renombrar la identidad FederationCloud;
2. modificar una lista cerrada de variables de entorno propias de ArcadeCloud, incluyendo configuración SMTP.

Esto **no convierte la aplicación en una terminal root** y no concede `sudo` general al usuario de PHP-FPM.

## Preparación root: una sola vez

Linux no permite que una aplicación web se conceda privilegios root a sí misma. Por seguridad existe un bootstrap administrativo que un operador del servidor ejecuta una sola vez:

```bash
sudo bash drive/bin/install_arcadecloud_admin_helper.sh --php-user=USUARIO_REAL_PHP_FPM
```

Ejemplos de usuario según la distribución pueden ser `apache`, `www-data` u otro pool dedicado. La aplicación detecta el usuario efectivo cuando POSIX está disponible y muestra el comando sugerido en el modal.

El instalador:

- copia un helper root-owned a `/usr/local/sbin/arcadecloud-drive-admin`;
- crea `/etc/arcadecloud-drive/admin-helper.json`;
- crea `/etc/arcadecloud-drive/runtime-env.json` si no existe;
- permite al grupo PHP leer la identidad y las variables administradas;
- instala una regla `sudoers` que autoriza **únicamente** el helper anterior;
- no permite `sudo bash`, `sudo sh`, `sudo php` genérico, editores ni comandos arbitrarios.

El helper acepta solamente:

- consultar su estado;
- escribir variables que estén en una allowlist compilada;
- crear la identidad si todavía no existe;
- renombrar únicamente `node_name` conservando `node_id` y claves.

No incluye una acción web para borrar la identidad.

## Cuando no hay helper

La creación/renombre intenta primero usar los permisos normales del proceso PHP-FPM. Si no puede, la API devuelve información accionable:

- ruta exacta de identidad, normalmente `/etc/arcadecloud-drive/federation-node.json`;
- usuario efectivo de PHP-FPM;
- si el archivo existe, es legible o escribible;
- si el directorio padre permite creación;
- si el helper privilegiado está disponible;
- comando de instalación sugerido.

Para una identidad **ya existente**, un operador puede elegir no instalar el helper y conceder escritura sólo sobre ese archivo mediante ACL:

```bash
sudo setfacl -m u:USUARIO_PHP_FPM:rw /etc/arcadecloud-drive/federation-node.json
```

No se recomienda hacer `chmod 666`, `chmod 777` ni volver escribible todo `/etc/arcadecloud-drive`.

## Creación de un nodo sin colisión

La UI de identidad sirve tanto para una instalación nueva como para un nodo existente.

Flujo de creación:

```text
superadmin escribe node_name
 -> normalización estricta
 -> consulta al seed: ¿nombre disponible?
 -> si está ocupado: NO se generan llaves
 -> si está disponible: generar Ed25519 + payload_key
 -> crear federation-node.json
 -> publicar descriptor firmado
 -> seed vuelve a validar nombre, Node ID, clave y URLs
 -> registrar FederationNodes
```

La consulta previa mejora UX, pero el registro firmado final sigue siendo la autoridad para cubrir carreras entre dos nodos que intenten tomar el mismo nombre al mismo tiempo.

Si las llaves ya fueron creadas y el registro no puede confirmarse por red, **la identidad se conserva**. Nunca se regeneran o borran automáticamente las llaves ante una respuesta incierta del seed.

## Renombre

Renombrar cambia solamente `node_name`. Antes de persistirlo también se valida disponibilidad global. El mismo `node_id`, clave Ed25519 y `payload_key` permanecen intactos.

Cuando el archivo pertenece a root, el flujo usa el helper privilegiado. Cuando PHP-FPM ya tiene permiso directo sobre el archivo, puede actualizarlo sin helper.

## Variables administradas desde la aplicación

El archivo administrado es:

```text
/etc/arcadecloud-drive/runtime-env.json
```

`drive/app_bootstrap.php` lo carga al inicio de cada petición y convierte exclusivamente las claves permitidas en variables de proceso mediante `putenv()`. Por tanto un cambio realizado desde el modal se aplica a las siguientes peticiones sin requerir reiniciar PHP-FPM.

Allowlist inicial:

### FederationCloud

- `ARCADECLOUD_PUBLIC_URL`
- `ARCADECLOUD_FEDERATION_URL`
- `ARCADECLOUD_FEDERATION_ENABLED`
- `ARCADECLOUD_FEDERATION_SEED_URL`

### SMTP

- `ARCADECLOUD_SMTP_HOST`
- `ARCADECLOUD_SMTP_PORT`
- `ARCADECLOUD_SMTP_SECURE`
- `ARCADECLOUD_SMTP_USERNAME`
- `ARCADECLOUD_SMTP_PASSWORD`
- `ARCADECLOUD_SMTP_FROM_EMAIL`
- `ARCADECLOUD_SMTP_FROM_NAME`
- `ARCADECLOUD_SMTP_REPLY_TO`
- `ARCADECLOUD_SMTP_TIMEOUT`
- `ARCADECLOUD_SMTP_DEBUG`

Una variable fuera de la allowlist es rechazada tanto por la aplicación como por el helper root. Para ampliar la lista debe existir un cambio explícito y revisable en el repositorio.

## Vista práctica del panel Servidor

El modal **Servidor** muestra todas las variables administrables en una sola tabla:

```text
Variable | Grupo | Valor actual | Origen | Modificar
```

El origen se interpreta así:

- `runtime-env.json`: la variable ya fue administrada desde ArcadeCloud;
- `entorno PHP`: el proceso PHP-FPM ya recibió esa variable desde systemd, el pool u otro mecanismo del servidor;
- `sin configurar`: no existe una configuración explícita ni en `runtime-env.json` ni en el entorno de PHP.

Esto permite conservar instalaciones existentes y migrarlas gradualmente. Una variable puede seguir viniendo de un `EnvironmentFile` del servidor hasta que el superadmin decida modificarla desde la UI; desde ese momento la clave escrita en `runtime-env.json` tiene precedencia para las siguientes peticiones.

La arquitectura completa de fuentes, precedencia, diagnóstico de `EnvironmentFile`, pools PHP-FPM y ejemplos sintéticos está documentada en:

- [`RUNTIME_ENV_CONFIGURATION.md`](RUNTIME_ENV_CONFIGURATION.md)

No deben copiarse a la documentación los valores reales de una instalación.

## Lo que deliberadamente NO puede cambiar desde este panel

La primera versión no permite modificar desde la web:

- credenciales MySQL;
- claves AWS o IAM;
- reglas de firewall;
- Nginx;
- systemd;
- sudoers;
- rutas arbitrarias del sistema;
- comandos shell;
- clave privada FederationCloud;
- `payload_key`;
- `node_id`.

Esos límites reducen el impacto si una sesión web o el propio sitio se ve comprometido.

## Confirmación del superusuario

Un POST de configuración exige simultáneamente:

1. sesión autenticada;
2. `system_role = superadmin`;
3. CSRF válido;
4. contraseña actual del superusuario verificada nuevamente contra `Users.password`.

Los secretos configurados nunca se devuelven al navegador. Para `ARCADECLOUD_SMTP_PASSWORD` la UI sólo indica si existe un valor y permite reemplazarlo.

La auditoría registra el nombre de la variable cambiada, el usuario y la IP, pero nunca el valor.

## Responsabilidad

El `superadmin` sí posee una capacidad operativa superior y debe asumirla conscientemente, pero el software conserva defensa en profundidad: autenticación, reautenticación, CSRF, allowlist, helper root limitado, archivos fuera de Git y ausencia de shell arbitrario.
