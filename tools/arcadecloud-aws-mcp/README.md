# ArcadeCloud AWS · puente MCP (EC2 pequeña + EC2 grande)

**Estado:** código del conector listo para instalar, **NO instalado ni conectado todavía**.
Es un módulo aislado en `tools/`; no cambia `drive/ec2.php`, sesiones,
`Config-s3.php`, cron de autoapagado, PHP-FPM ni servicios de producción.

## Qué reutiliza del repo

- `drive/ec2.php` / `drive/src/Aws/Ec2Gateway.php`: referencias para inventario,
  encendido y apagado; este MCP no reutiliza las sesiones web ni llama los
  endpoints de `ec2.php`.
- `Config-s3.php::getAwsControlClientConfig()`: mismos nombres opcionales
  `AWS_CONTROL_ACCESS_KEY_ID`, `AWS_CONTROL_SECRET_ACCESS_KEY`,
  `AWS_CONTROL_SESSION_TOKEN`. No se duplican credenciales en el repositorio.
- `ARCADECLOUD_FASTDRIVE_INSTANCE_ID` y `ARCADECLOUD_FASTDRIVE_REGION`:
  fallback para la EC2 grande cuando estén presentes **en el entorno del MCP**.
- `AWS_REGION` / `AWS_DEFAULT_REGION`: región base. Se permite una región
  específica por instancia.
- Systems Manager: funciones adicionales para consultar Docker y ejecutar
  mantenimiento remoto. Control EC2 existente no permite por sí solo entrar
  al sistema operativo remoto.

## Topología

```text
ChatGPT -> conexión MCP privada / Secure MCP Tunnel
        -> EC2 pequeña (siempre encendida)
           127.0.0.1:8765/mcp
           AWS SDK: rol IAM de EC2 o AWS_CONTROL_* existentes
               -> EC2 API para encender/apagar la grande
               -> Systems Manager Run Command -> EC2 grande / Docker
               -> Systems Manager Run Command -> EC2 pequeña
```

La **pequeña** debe permanecer encendida y su apagado se bloquea por defecto.
No asumir que la instancia `mailit-click` marcada en `ec2.php` sea la pequeña;
obtener los Instance ID reales desde el panel EC2 o consola AWS.

## 1. Verificar AWS antes de instalar (sin secretos)

En la EC2 pequeña, comprobar en consola o con AWS CLI:

```bash
aws sts get-caller-identity
aws ec2 describe-instances --region us-east-1 --query 'Reservations[].Instances[].[InstanceId,InstanceType,State.Name,Tags]' --output table
aws ssm describe-instance-information --region us-east-1 --query 'InstanceInformationList[].[InstanceId,PingStatus]' --output table
```

Sustituir `us-east-1` por la región real. Si AWS CLI usa credenciales distintas
a PHP-FPM, verificar también el rol de instancia y la cadena de credenciales.
Nunca pegar `~/.aws/credentials` ni la salida de variables secretas en ChatGPT.

Para ejecutar comandos por SSM, **ambas** EC2 deben aparecer como nodos
administrados online y cumplir requisitos de agente, IAM y conectividad.
`ec2:StartInstances` funciona con la EC2 grande apagada; SSM sólo funcionará
cuando esté encendida y el agente esté conectado.

## 2. Configurar dos targets fijos

Crear un EnvironmentFile PRIVADO en la pequeña, por ejemplo
`/etc/arcadecloud-drive/mcp-aws.env` con permisos `0600 root:root`:

```ini
ARCADECLOUD_MCP_SMALL_INSTANCE_ID=i-REEMPLAZAR_CON_ID_REAL
ARCADECLOUD_MCP_LARGE_INSTANCE_ID=i-REEMPLAZAR_CON_ID_REAL
ARCADECLOUD_MCP_SMALL_REGION=us-east-1
ARCADECLOUD_MCP_LARGE_REGION=us-east-1
ARCADECLOUD_MCP_ENABLE_POWER=0
ARCADECLOUD_MCP_ENABLE_SHELL=0
ARCADECLOUD_MCP_ALLOW_STOP_SMALL=0
```

**No copiar estos valores ficticios literalmente**: primero sustituir los dos IDs
con instancias que muestre `ec2.php` o la consola AWS.

Autenticación AWS del servicio:

1. Preferido: asociar un rol IAM a la EC2 pequeña. Boto3 obtiene credenciales
   temporales automáticamente, sin almacenar claves.
2. Si se necesitan las **credenciales de control ya configuradas**, añadir al
   proceso systemd `EnvironmentFile=` del archivo privado que contiene
   `AWS_CONTROL_*`. También puede usar `AWS_ACCESS_KEY_ID` y
   `AWS_SECRET_ACCESS_KEY` estándar. Si sólo están en
   `/etc/arcadecloud-drive/runtime-env.json` (formato JSON), **NO se cargan
   automáticamente en Python**; hay que preparar un mecanismo privado de
   inyección de entorno. Nunca publicar ese JSON ni subirlo a Git.

El permiso IAM efectivo determina qué operaciones funcionarán. Recomendamos
alcance a los dos Instance ID y al documento SSM `AWS-RunShellScript`;
revisar `ec2:DescribeInstances`, `ec2:StartInstances`,
`ec2:StopInstances`, `ssm:SendCommand`,
`ssm:GetCommandInvocation`, `sts:GetCallerIdentity`.
Restringir instancias y documento SSM por ARN/política donde AWS lo permita.
Revisar por separado los permisos necesarios del agente SSM en cada EC2.

## 3. Instalación aislada en EC2 pequeña

En una copia actualizada del repositorio:

```bash
cd tools/arcadecloud-aws-mcp
python3 -m venv .venv
.venv/bin/python -m pip install -r requirements.txt
.venv/bin/python -m unittest discover -s tests -v
```

Ejecutar el servicio con usuario dedicado, directorio de trabajo
`tools/arcadecloud-aws-mcp`, EnvironmentFile privado y
`ExecStart=/RUTA/DEL/REPO/tools/arcadecloud-aws-mcp/.venv/bin/python server.py`.
Proteger `/etc/arcadecloud-drive/mcp-aws.env` como root-owned.
Para iniciar con systemd utilizar `User=arcadecloud-mcp` sin acceso a otras
aplicaciones, `Restart=on-failure`, `NoNewPrivileges=yes` y permisos
necesarios sólo para la conexión a AWS; no se requiere Docker local para
consultar la grande vía SSM.

El servidor se liga **únicamente a `127.0.0.1:8765`**. No crear una regla
de Security Group para ese puerto, ni ponerlo en Nginx público sin
autenticación/OAuth adecuados.

## 4. Conectar ChatGPT

Configurar un **Secure MCP Tunnel** entre ChatGPT y el MCP local
`http://127.0.0.1:8765/mcp` (siguiendo los pasos actuales de OpenAI).
La identidad de usuario y autorización se gestionan en la conexión privada,
no mediante `ec2.php` y su cookie PHP. El código en GitHub por sí mismo
**NO instala ni conecta** este servidor a ChatGPT; el propietario debe
autorizar la conexión de la aplicación.

Documentación:
- https://developers.openai.com/api/docs/guides/tools-connectors-mcp
- https://docs.aws.amazon.com/systems-manager/latest/userguide/run-command.html

## 5. Herramientas MCP

| Herramienta | Efecto |
| --- | --- |
| `list_ec2`, `describe_ec2` | Inventario restringido a small/large |
| `aws_identity` | Cuenta/ARN usados; no credenciales |
| `diagnose_ec2(alias,report)` | Informe `host`, `docker` o `services` vía SSM |
| `command_status(alias,command_id)` | Resultado de Run Command |
| `power_ec2(alias,action)` | Start/stop: requiere ENABLE_POWER=1 |
| `execute_shell(alias,command,reason)` | Shell arbitraria remota: requiere ENABLE_SHELL=1 |

Los comandos SSM pueden devolver resultados asíncronos: primero se recibe
`command_id` y después se consulta con `command_status`. La salida se
recorta para evitar respuestas masivas. Las operaciones SSM requieren la
instancia **running** y el agente online.

**Seguridad:** no habilitar Shell remoto hasta vincular y probar autenticación
del túnel. `execute_shell` puede cambiar/eliminar datos y filtrar secretos si
se le pide hacerlo: operar bajo permisos IAM mínimos, auditoría CloudTrail
y confirmación explícita del operador. `power_ec2` y `execute_shell`
están deshabilitados por defecto. El protocolo/herramientas de MCP no
sustituyen la autenticación en la capa de transporte.

## 6. Verificación / retirada

- Tests offline (sin red ni AWS): `python3 -m unittest discover -s tests -v`.
- Verificar EC2 pequeña y grande por alias, luego SSM, luego Docker.
- Habilitar acciones de escritura solo después de comprobar cuenta y IDs.
- Si el MCP se comporta mal, detener solo su servicio systemd y quitar la
  conexión MCP de ChatGPT: `ec2.php` y FastDrive continúan funcionando.

`main` no debe desplegar el puente automáticamente sin una instalación
explícita en la pequeña.
