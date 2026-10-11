# ArcadeCloud AWS Operator — cambios administrativos con aprobación

Este módulo complementa el puente de consulta existente. **No modifica**
`drive/ec2.php`, `so.php`, el wake gateway de FastDrive ni las credenciales
del usuario IAM `drive`. Tampoco despliega nada automáticamente en EC2.

## Estado del sistema

- El puente `ArcadeCloudGitHubBridgeReadOnly` ya está funcionando.
- El rol `ArcadeCloudSSMNodeRole` está asociado a ambos nodos.
- Se ha comprobado SSM `Online` en la pequeña; para la grande se comprobará
  cuando vuelva a estar encendida.
- **Operator se instala sólo al crear su nuevo rol OIDC y activar la protección
  del environment en GitHub.** Fusionar este PR no activa permisos por sí solo.

## POR QUÉ OTRO ROL EN VEZ DE VOLVER ADMIN AL READONLY

Un workflow automático de consulta puede asumir el rol read-only
sin revisión. Si ese mismo rol recibe `ssm:SendCommand`, cualquier workflow
que pueda asumirlo podría emitir comandos en las EC2 **sin aprobación**.
Por eso crear un **rol adicional** `ArcadeCloudGitHubOperator` que exige OIDC
con el `sub` exclusivo:

```
repo:jimmybackend/s3:environment:arcadecloud-ops
```

El workflow operativo usa `environment: arcadecloud-ops` y requiere un reviewer
en GitHub. Los workflows automáticos de consultas siguen utilizando el rol
original con su `sub` de `main`.

## 1. Configurar environment protegido (GitHub)

En `jimmybackend/s3` → Settings → Environments:
1. Crear `arcadecloud-ops`.
2. Configurar **Required reviewers** → usuario **`jimmybackend`**.
3. Permitir despliegues **solamente desde `main`** (Deployment branches and tags).
4. NO activar `Prevent self-review` si se pretenden ejecutar manualmente
   workflows iniciados por el propio owner: impediría su aprobación.
5. Preferir desmarcar `Allow administrators to bypass configured protection rules`.

La fase de ejecución incluye una comprobación que falla si GitHub no informa
de la regla required_reviewers para `jimmybackend`. Si falta un permiso para
consultar el environment, falla cerrado antes de asumir el rol AWS.

**Advertencia:** la protección de GitHub y la cuenta administradora del
repositorio forman parte del límite de seguridad. En un repo público jamás
colocar una clave AWS en archivos, variables públicas, workflows o logs.

## 2. Crear rol AWS independiente

IAM → Roles → Crear rol → Identidad web →
`token.actions.githubusercontent.com`, audiencia `sts.amazonaws.com`.

El subject debe estar asociado al **environment**, no al branch.
Copiar exactamente el JSON de:
`tools/arcadecloud-aws-bridge/iam/operator-trust.json`
a **Relaciones de confianza** del nuevo rol
`ArcadeCloudGitHubOperator`. La cuenta utilizada en el ejemplo del proyecto
es `084375544416`; confirmar siempre que sea la cuenta activa.

Añadir la política de permisos JSON
`tools/arcadecloud-aws-bridge/iam/operator-permissions.json` como
política insertada. Esta política permite únicamente:

- Ver estados EC2 y conectividad SSM.
- Ejecutar `AWS-RunShellScript` únicamente en los dos Instance ID fijos.
- Recuperar el resultado por `ssm:GetCommandInvocation`.
- Encender **únicamente FastDrive** (`i-01b1f1077d7471070`).
- **NO** permite detener instancias, terminarlas, lanzar nuevas instancias,
  crear volúmenes, cambiar IAM, abrir Security Groups, actualizar S3 ni RDS.

El permiso `ssm:SendCommand` permite técnicamente shell root con
`AWS-RunShellScript` dentro de ambas EC2. Los límites de acciones concretas
los imponen el código versionado y la aprobación manual de GitHub;
el rol IAM por sí solo **NO restringe el texto del script enviado**.
Quien pueda modificar `main` y obtener aprobación de despliegue podría
alterar el workflow; proteger las ramas y el entorno es indispensable.
Para un límite IAM más estricto, en una fase posterior crear un documento
SSM **personalizado** con una allowlist de comandos interna.

`GetCommandInvocation` usa Resource `*` porque AWS no expone una
restricción específica por EC2 para ese permiso.

Documentación AWS: https://docs.aws.amazon.com/systems-manager/latest/userguide/run-command-setting-up.html

## 3. Variable de activación

En GitHub → Settings → Secrets and variables → Actions → Variables:

| Variable | Valor |
| --- | --- |
| `ARCADECLOUD_AWS_OPERATOR_ROLE_ARN` | ARN real del nuevo rol AWS `ArcadeCloudGitHubOperator` |
| `ARCADECLOUD_AWS_OPERATOR_ENABLED` | `YES_PROTECTED` (solo tras revisar aprobación) |

Las cuatro variables de lectura ya existentes siguen sin cambios.
No modificar `ARCADECLOUD_AWS_BRIDGE_ROLE_ARN`.

## 4. Operaciones soportadas

`operation = diagnose`, `argument`:

- `memory`, `disk`, `uptime`, `arcadecloud-services`,
  `arcadecloud-timers`, `nginx-status`, `php-fpm-status`,
  `repo-status`, `media-worker-status`: invocan el mismo
  `/usr/local/sbin/arcadecloud-drive-admin server-console <id>` de ArcadeCloud.
- `docker-summary`: comprueba Docker y devuelve sólo un conteo numérico de
  contenedores ejecutándose; no filtra variables ni nombres de contenedores.

`operation = service`, `argument`:

- `media-worker:start|stop|restart`
- `workstation:start|stop|restart`
- `federation-sync:run-now`, `federation-https:run-now`
- `polly-reconcile:run-now`, `transcribe-reconcile:run-now`

Se ejecutan por el helper `node-service` del nodo; el helper realiza su
segunda comprobación local. No permite `nginx:stop` ni shell arbitraria.

`operation = power-start` solamente para `alias=large` y
`argument` vacío. **No hay `power-stop`**: el apagado de FastDrive sigue
pasando por las guardas de actividad/colas implementadas en ArcadeCloud.

Confirmaciones exactas:
- `diagnose` → `READ_DIAGNOSTIC`
- `service` → `SERVICE_CHANGE`
- `power-start` → `POWER_LARGE`

## 5. Cómo ejecutar

Se permite el botón manual `workflow_dispatch` en GitHub Actions o el
archivo `tools/arcadecloud-aws-bridge/requests/operator.json` en `main`.
Ejemplo de consulta con aprobación obligatoria:

```json
{
  "request_id": "operator-small-memory-0001",
  "alias": "small",
  "operation": "diagnose",
  "argument": "memory",
  "confirm": "READ_DIAGNOSTIC"
}
```

El archivo **no se crea dentro de este PR**; debe enviarse sólo después
de crear el rol y el environment con required reviewers. Cambiar
`request_id` activa cada nueva ejecución.

Al arrancar el job de operador, GitHub lo retiene en estado `Waiting`
hasta que un reviewer permitido aprueba el environment `arcadecloud-ops`.
Sólo después el workflow obtiene el token OIDC de GitHub y asume el rol
administrativo. Cada acción se registra mediante la ejecución GitHub.

**No publicar resultados completos de SSM**: GitHub `s3` es público y
los logs de comandos pueden contener rutas, nombres de clientes o secretos.
El workflow imprime solamente el tipo de acción, estado, código de retorno
y, para Docker, conteo normalizado. Para inspección avanzada de logs o salida
completa necesitaremos un canal privado/cifrado y acceso autenticado.

## 6. Diagnóstico de errores

- `Check environment ...`: revisar required reviewers y acceso de
  `GITHUB_TOKEN` a la API de entornos.
- `No OpenIDConnect provider found`: revisar el proveedor IAM OIDC.
- `Not authorized to perform sts:AssumeRoleWithWebIdentity`: comprobar el
  `sub` de environment en la trust policy, no el `sub` de main.
- `AccessDenied ssm:SendCommand`: revisar autorización **al documento y a
  la instancia**; ambas son necesarias.
- `SSM not Online`: verificar SSM Agent, rol del nodo y conectividad.
- `CommandFailed`: comprobar resultados en Systems Manager desde la
  consola de AWS; el workflow no revela stderr.
- Instalar/actualizar el helper privilegiado **en cada EC2** si
  `/usr/local/sbin/arcadecloud-drive-admin` falta o es antiguo. No asumir
  que actualizar el checkout Git lo reinstala.

Pruebas offline:

```bash
python3 -m unittest discover -s tools/arcadecloud-aws-bridge/tests -v
```

**Costos:** configurar roles/IAM no tiene costo directo.
Encender FastDrive reanuda su facturación EC2/EBS habitual.
Los comandos básicos de SSM no requieren por sí solos una suscripción
adicional, pero CloudWatch Logs, S3, redes privadas y otras funciones
opcionales pueden facturarse. La automatización no garantiza gasto cero.

## Diagnósticos directos de terminal (propuesta)

Dentro de `operation=diagnose` están disponibles también:
- `terminal-kernel`: ejecuta `uname -srm`.
- `terminal-load`: ejecuta `cat /proc/loadavg`.
- `terminal-root-usage`: ejecuta `df -P /`.

Los comandos son literales, sin parámetros ni shell arbitraria. Se ejecutan por
SSM únicamente sobre el alias de instancia configurado. Por privacidad, la
salida del comando no se publica en logs del repositorio público, sólo el estado.
El acceso está sujeto a la protección del environment `arcadecloud-ops` en
el workflow de operador; su configuración debe verificarse en GitHub. No existe
una identidad criptográfica que identifique exclusivamente a ChatGPT ante AWS:
el límite de confianza es la autenticación de GitHub, sus autorizaciones y las
políticas IAM. No confiar en rutas secretas como autenticación.
