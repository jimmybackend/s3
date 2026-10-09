# ArcadeCloud operator automático (GitHub -> AWS SSM)

## Estado

El propietario de AWS conectó el rol OIDC
`ArcadeCloudGitHubBridgeReadOnly` a su repositorio `jimmybackend/s3` y
asoció `AdministratorAccess` (verificado mediante el workflow
`ArcadeCloud AWS - verify IAM permissions`). Aunque el nombre del rol diga
ReadOnly, **YA ES UN ROL ADMINISTRADOR DE AWS**.

El workflow `.github/workflows/arcadecloud-aws-auto.yml` utiliza esa
identidad preexistente para ejecutar operaciones **sin pedir aprobación por
cada tarea**. Las solicitudes pueden enviarse a `main` mediante
`tools/arcadecloud-aws-bridge/requests/auto.json` o con
`workflow_dispatch`. El archivo no se incluye en esta rama para evitar un
arranque automático durante el merge.

### Primera comprobación sugerida

```json
{
  "request_id": "auto-ssm-small-uptime-001",
  "alias": "small",
  "operation": "diagnose",
  "argument": "uptime",
  "confirm": "READ_DIAGNOSTIC"
}
```

El agente de Systems Manager debe figurar `Online`.
Solo se devuelve el estado de la acción; el texto del uptime permanece en
Systems Manager y no se publica en GitHub.

### Acciones autorizadas por el programa actual

- `diagnose`: `memory`, `disk`, `uptime`, `repo-status`,
  `nginx-status`, `php-fpm-status`, `arcadecloud-services`,
  `arcadecloud-timers`, `media-worker-status`, `docker-summary`.
- `service`: `media-worker:start|stop|restart`,
  `workstation:start|stop|restart`,
  `federation-sync:run-now`, `federation-https:run-now`,
  `polly-reconcile:run-now`, `transcribe-reconcile:run-now`.
- `power-start`: **solamente** FastDrive (EC2 grande) y sin parámetros.

La ejecución se limita a ambas instancias ya configuradas. `operator.py`
rechaza alias, argumentos y comandos fuera de la lista. Si los servicios no
existen o el helper del nodo no está actualizado, devuelve error. La
instalación del helper es independiente del checkout Git.

### Cómo extenderlo para instalar software y Docker

Para añadir una nueva operación (instalación de lector PDF, cambio Docker,
reparación concreta), definir la operación y sus scripts en el código Python
versionado y añadir pruebas; después ejecutar una solicitud con parámetros
exactos. No aceptar scripts remotos arbitrarios escritos como texto en JSON.
El repositorio es **público**: logs GitHub NO deben mostrar stdout, stderr,
contenidos de ficheros, credenciales, datos personales ni configuraciones de
los nodos. Hay que utilizar un medio privado para resultados detallados.

### Advertencia de seguridad importante

La autorización `AdministratorAccess` sobre el rol OIDC que confía en
`repo:jimmybackend/s3:ref:refs/heads/main` permite a cualquier workflow
capaz de emitir un token OIDC desde esa rama tener **control total de AWS**,
incluido IAM, eliminación de EC2, S3 y servicios facturables. Restringir
`operator.py` NO impide que otro workflow comprometido invoque AWS por
fuera del programa. Un colaborador que pueda modificar workflows en
`main` puede aprovechar ese poder.

Para proteger la cuenta:
1. Activar reglas de rama y revisión para cambios a
   `.github/workflows/**` y al código del puente.
2. Habilitar 2FA/passkeys en GitHub y revisar accesos de las apps.
3. Migrar pronto a un rol SSM operativo separado con acciones IAM limitadas
   a las dos instancias. Mantener el rol de consulta realmente de lectura.
4. No guardar secretos en Git o salidas de workflows. Preferir SSM Parameter
   Store/Secrets Manager con controles de acceso cuando una operación lo
   requiera.

Este modo automatizado **no depende** del environment
`arcadecloud-ops`, que queda reservado para la variante con aprobación.
No modifica `ec2.php`, `so.php`, Nginx ni configuración de Docker.

## Costos

La consulta SSM y ejecución Run Command no equivalen a crear una instancia.
**Encender FastDrive sí reanuda su facturación**. Operaciones futuras
de instalación pueden descargar datos, crear volúmenes o consumir recursos
si explícitamente se programan. Ninguna de las operaciones actuales crea
instancias ni termina EC2.
