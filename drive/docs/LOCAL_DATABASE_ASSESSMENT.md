# Base local y recuperación — análisis, sin migración

La solicitud actual describe una MySQL externa. El repositorio no prueba el host
instalado: se analiza esa situación sin consultar secretos ni producción. Ningún
comando de este informe cambió DB_HOST, la infraestructura o la base real.

## Lo que exige la aplicación

`db.php` usa mysqli, DB_HOST/DB_PORT/DB_USER/DB_PASSWORD/DB_NAME. DriveApplication
comparte esa conexión con catálogo, colas y sesiones. FastDrive necesita alcanzar
el mismo escritor para reclamar MediaProcessingJobs y actualizar sesiones Office.
El código no contiene un router de lecturas a réplica ni promoción automática.
No dirigir cola/sesiones a una copia atrasada como sustitución transparente.

El esquema canónico contiene utf8mb4_0900_ai_ci y una columna generada
`scope_owner_key` con `VIRTUAL NOT NULL`. El importador inicial ejecuta el SQL
canónico sobre base vacía sin convertir dialectos. El ensayo MariaDB 10.11 de
algunas tablas requirió collation equivalente en fixtures; esto NO valida una
importación completa ni permite cambiar collations de producción a ciegas.
Revisar comparaciones, índices únicos, columnas generadas, JSON y fechas en una
restauración íntegra. MySQL y MariaDB no deben tratarse como motores idénticos.

## Opciones

| Opción | Ventaja para este proyecto | Riesgo/costo operativo |
| --- | --- | --- |
| Mantener externa | No comparte la RAM del nodo web; conserva operación existente | Latencia, conectividad y política real de backup/failover dependen del proveedor |
| DB local en EC2 pequeño | Baja latencia del catálogo y evita un servicio DB adicional | Web y DB caen juntos; RAM/disco/backup compiten; mantenimiento propio |
| DB dedicada/administrada | Aísla recursos; puede ofrecer backup y HA administrados | Costo adicional y configuración; no presupuestar sin cotización actual |
| Copia de backup en FastDrive | Puede facilitar un ensayo de restauración | FastDrive apagado no ofrece réplica disponible ni failover inmediato |

Recomendación para esta arquitectura y presupuesto: conservar el escritor actual
mientras se ensaya una restauración completa. DB local es viable **condicionalmente**
para carga baja, con límites medidos y backup externo probado; no se recomienda
una migración inmediata sólo porque las regresiones de unas tablas pasaron.

Para un nodo alrededor de 1 GiB, un buffer pool inicial de 128 MiB es una hipótesis
de laboratorio, no una configuración prescrita ni el consumo total. Reservar
presupuesto adicional para buffers por conexión, PHP-FPM, Nginx, kernel y caché.
Medir RSS y picos conjuntos; el instalador declara pm.max_children=12, que no prueba
el valor efectivo instalado. Acotar conexiones según concurrencia observada y
usar una reserva de memoria; swap no sustituye capacidad sostenida.

FastDrive: red privada, puerto DB restringido al origen autorizado, cuenta de
aplicación con permisos mínimos. `db.php` no configura explícitamente TLS ni
certificados; no afirmar conexión cifrada sólo por usar mysqli. Si la conexión
cruza redes no confiables, falta validar/configurar un transporte protegido.
No se abrió ningún puerto ni cambió IAM/SG.

## Réplica, backup y failover

Una réplica propaga también errores lógicos/borrados; no sustituye respaldo.
La copia periódica sirve para recuperar hasta su fecha, no para promoción
inmediata. FastDrive se apaga por diseño, por lo que no es una buena réplica de HA.
Una réplica permanente necesitaría binlog/permisos/versiones compatibles, vigilancia
de atraso, fencing del escritor anterior y un procedimiento de promoción y retorno.
No hay ese mecanismo implementado en Drive. Evitar doble escritor improvisado.

Para backup lógico de InnoDB, `mariadb-dump --single-transaction` permite una vista
consistente de tablas transaccionales, pero no debe confundirse con atomicidad
DB/S3 ni con protección frente a DDL concurrente. Conservar rutinas/eventos/triggers
según los privilegios reales; credenciales en archivo privado, nunca argumentos
con contraseña ni Git. Cifrar, verificar checksum y conservar copia fuera de la
EC2; ensayar restauración y definir RPO/RTO. Para recuperación a un instante,
evaluar además retención y restauración de binlogs. No se instaló cron.

El runbook existente [CONSOLIDATED_OPERATIONS.md](CONSOLIDATED_OPERATIONS.md)
separa código reconstruible desde Git de DB completa, runtime, identidad privada,
S3 y ediciones Office no sincronizadas. El backup de identidad ya tiene herramientas
cifradas; no se creó otro mecanismo. La automatización DB/config y el ensayo
completo permanecen pendientes hasta verificar destinos/permisos y esquema real.

RDS Multi-AZ puede proporcionar standby y failover administrados; contratarlo no
es equivalente a una réplica ordinaria. No se recomiendan tamaños ni precios sin
medir carga y cotizar región/almacenamiento/retención/transferencia. Para comparar
costos, sumar servicio actual versus cómputo/disco/backup/operación de cada opción.

Fuentes primarias consultadas el 3 de octubre de 2026:

- [Asignación de memoria MariaDB](https://mariadb.com/docs/server/ha-and-performance/mariadb-memory-allocation).
- [mariadb-dump](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).
- [Failover RDS Multi-AZ](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Concepts.MultiAZ.Failover.html).
