# GitHub Actions -> AWS (ArcadeCloud EC2 bridge)

Estado: **código de preparación; no hay acceso AWS activado**.
No se han configurado roles IAM ni variables en GitHub. La EC2 pequeña y
FastDrive no han sido modificadas. Esta solución no instala un servidor web,
no requiere SSH público y **no depende del permiso de git push de la pequeña**.

## Alcance de esta primera etapa (modo lectura)

```text
ChatGPT (GitHub conectado) -> commit de solicitud permitida en main
  -> GitHub Actions (runner hospedado por GitHub)
    -> token temporal por OIDC (IAM AWS con permisos de lectura)
      -> DescribeInstances para alias small/large
      -> DescribeInstanceInformation (SSM Online?)
        -> resultado resumido en logs de GitHub Actions
```

**Importante:** `jimmybackend/s3` es un repositorio **PÚBLICO**.
Por tanto el workflow **solo imprime** alias, región, estado, tipo de instancia
y estado del agente SSM. No publica logs de Docker, variables de entorno,
archivos, secretos, IPs, credenciales ni resultados de terminal. Cualquier
función futura de ejecución remota debe tener salida privada/autenticada.

Para obtener acceso interactivo pleno a Docker, SSM requerirá `SendCommand`,
`GetCommandInvocation`, agente SSM online e IAM adicional. **No están
activados** en esta etapa: el diagnóstico solamente consulta AWS.

## Configurar una vez en AWS

1. En AWS, comprobar que las dos EC2 estén en la cuenta/región prevista.
   No suponer que el ID protegido `mailit-click` equivale a la EC2 pequeña.
   Según `drive/docs/FASTDRIVE_WAKE_GATEWAY.md`, la EC2 grande FastDrive
   es `i-01b1f1077d7471070` en `us-east-1`; verificarlo en la consola.
2. Crear o reutilizar proveedor OIDC:
   `token.actions.githubusercontent.com`, audience `sts.amazonaws.com`.
3. Crear un rol dedicado llamado, por ejemplo, `ArcadeCloudGitHubBridgeReadOnly`
   con esta relación de confianza, sustituyendo `AWS_ACCOUNT_ID`:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": {
      "Federated": "arn:aws:iam::AWS_ACCOUNT_ID:oidc-provider/token.actions.githubusercontent.com"
    },
    "Action": "sts:AssumeRoleWithWebIdentity",
    "Condition": {"StringEquals": {
      "token.actions.githubusercontent.com:aud": "sts.amazonaws.com",
      "token.actions.githubusercontent.com:sub": "repo:jimmybackend/s3:ref:refs/heads/main"
    }}
  }]
}
```

4. Adjuntar política de **solo lectura**, sin operaciones de shell:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": [
      "ec2:DescribeInstances",
      "ssm:DescribeInstanceInformation"
    ],
    "Resource": "*"
  }]
}
```

La API `DescribeInstances` requiere `Resource: "*"` en esta política.
Este acceso puede ver metadatos EC2 a nivel API, aunque el workflow solo
consulta los dos IDs preconfigurados y muestra un resumen reducido.

## Configurar una vez en GitHub

En `Settings -> Secrets and variables -> Actions -> Variables` de
`jimmybackend/s3`:

| Nombre de variable | Valor |
| --- | --- |
| `ARCADECLOUD_AWS_BRIDGE_ROLE_ARN` | ARN del rol dedicado OIDC |
| `ARCADECLOUD_AWS_REGION` | Región real, por ejemplo `us-east-1` |
| `ARCADECLOUD_AWS_SMALL_ID` | Instance ID real del EC2 pequeño |
| `ARCADECLOUD_AWS_LARGE_ID` | Instance ID real de FastDrive |

No introducir AWS access key, secret, contraseña, tokens o materiales SSH en
GitHub Actions. El runner obtiene credenciales **temporales** del rol AWS OIDC.
Esto no reutiliza directamente la configuración privada de PHP ni exige abrir
puertos en Security Groups.

## Ejecutar y revisar resultado

**Vía manual:** GitHub -> Actions -> `ArcadeCloud AWS bridge - EC2 status` ->
Run workflow -> `small` o `large`; `status`. Ejecutar siempre desde `main`.

**Vía GitHub conectado con ChatGPT:** después de haber configurado IAM y
variables y fusionado este PR, crear/actualizar en `main`:

`tools/arcadecloud-aws-bridge/requests/current.json`

```json
{
  "request_id": "check-large-20261008-01",
  "alias": "large",
  "report": "status"
}
```

Cada nueva solicitud debe modificar `request_id` para crear un commit real;
al cambiar ese archivo en `main`, GitHub Actions se ejecuta automáticamente.
ChatGPT podrá buscar la ejecución asociada al commit y leer logs del job a
través de su conexión GitHub. Esto requiere que GitHub permita esos eventos y
el conector tenga acceso a los logs. Las solicitudes quedan registradas
públicamente en el historial, por lo que nunca deben incluir datos privados.

**No editar `current.json` en una rama de PR esperando ejecución real:**
el workflow sólo actúa en `main`. No crear `current.json` antes de configurar
AWS: de lo contrario el primer disparo fallará sin identidad AWS.

## Cómo probar offline

```bash
python3 -m unittest discover -s tools/arcadecloud-aws-bridge/tests -v
```

Los tests usan clientes AWS ficticios: no consumen infraestructura.

## Lo que falta para administrar Docker/ambas máquinas

- Confirmar que SSM está `Online` en ambas instancias cuando están encendidas.
- Diseñar un canal **privado** para respuestas detalladas y diagnósticos.
- Añadir IAM SSM `SendCommand` y `GetCommandInvocation` con límites claros
  por instancia y documento; preferentemente una cuenta/rol dedicados.
- Separar operaciones de lectura, mantenimiento y apagado; conservar siempre
  el gateway FastDrive y el apagado automático existentes.

No habilitar `ssm:SendCommand` en el rol de esta primera etapa, no exponer
un endpoint ejecutor de comandos en Nginx y no almacenar credenciales en Git.
