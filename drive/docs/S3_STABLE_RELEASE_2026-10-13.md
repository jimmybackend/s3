# ArcadeCloud S3 — cierre de versión operativa (2026-10-13)

**Estado:** versión operativa estabilizada; **no** certificación de instalación limpia ni de transferencia entre bases independientes.

## Identificación reproducible

- Repositorio: `jimmybackend/s3`.
- Referencia congelada: [`release/s3-stable-2026-10-13`](https://github.com/jimmybackend/s3/tree/release/s3-stable-2026-10-13).
- Commit exacto de la versión: `6e2e8747d6c95f5d326322c9abfc5f50fd92d698` (PR #454).
- `main` puede continuar recibiendo cambios; **no** debe utilizarse como identificador inmutable de esta versión. Para reproducirla, utilizar el SHA exacto.
- Esta referencia es una **rama de versión**, no una etiqueta Git ni un GitHub Release publicado.

## Arquitectura validada en las instalaciones existentes

- EC2 pequeña: nodo Drive principal y MariaDB compartida.
- EC2 grande: FastDrive y capacidades adicionales; consulta la misma MariaDB alojada en la pequeña.
- Identidades FederationCloud distintas (`drive` y `fastdrive`) con lectura autorizada para el usuario real del servicio.
- Los temporizadores FederationCloud completaron ejecuciones con `Result=success` y `ExecMainStatus=0` en ambas máquinas.
- La EC2 grande identificó al peer `drive` con `ok: true`, `pulled_events: 0` y `pushed_events: 0`. Esto confirma comunicación y un ciclo correcto, **no** una transferencia nueva entre catálogos separados.
- Los contadores de origen quedaron reconciliados con las secuencias máximas de los eventos en MySQL. El PR #454 incorpora una protección de código para no reutilizar números si el contador se atrasa.
- En la EC2 grande, el instalador del actualizador privilegiado reportó SHA-256 coincidente, repositorio limpio y verificación `NOPASSWD` satisfactoria para el usuario PHP-FPM real. Se comprobaron 24 pruebas del puente AWS.
- El actualizador web de ambas instancias mostró instalado/disponible el commit exacto de esta versión; los esquemas FederationCloud, Moderación y ArcadeCloud Office aparecieron preparados.

## Componentes relevantes

- Instalador principal: `drive/bin/install_arcadecloud.sh` (incluye modo `--reconcile`).
- Instalador de actualizador privilegiado: `drive/bin/install_arcadecloud_updater.sh`.
- Reconciliador de servicios: `drive/bin/reconcile_arcadecloud_services.sh`.
- Servicio de eventos federados: `drive/src/Federation/FederationEventStore.php`.
- Prueba de regresión de secuencias: `drive/tests/federation_sequence_allocator_contract.php`.
- Documentación complementaria: `drive/docs/FEDERATION_OPERATIONS.md`, `drive/docs/FEDERATION_NODE_RECOVERY.md`, `drive/docs/FEDERATION_IDENTITY_PERMISSIONS.md` y `drive/docs/FEDERATION_CLI_ENVIRONMENT.md`.

## Pendientes fuera del cierre

1. **Certificar instalación limpia** de los roles pequeño/grande en un entorno desechable; no afirmar que el instalador ya fue probado de extremo a extremo desde cero.
2. **Demostrar transferencia federada real** entre dos catálogos y bases independientes en laboratorio. En producción los nodos comparten MySQL.
3. Vigilar contadores, sincronización y permisos tras futuras actualizaciones; no asumir que una ejecución exitosa garantiza ausencia de regresiones.

## Conservación y recuperación

- Conservar respaldos privados de DB, archivos de configuración y claves **fuera de GitHub**. No publicar `federation-node.json` ni sus valores secretos.
- No clonar identidades criptográficas entre nodos: cada `node_id` debe permanecer asociado con su clave original.
- Antes de una nueva implementación, comparar el checkout contra el SHA de versión, comprobar estado Git y respaldo y utilizar el proceso de actualización/reconciliación documentado.
- Apagar la EC2 grande únicamente cuando no tenga trabajos activos y siempre conservar encendida la pequeña mientras aloje MySQL y los servicios principales.

## Alcance de este cierre

Este documento registra el **baseline de producción ya observado** y preserva una referencia para futuras mejoras. No declara auditadas todas las funcionalidades de S3 ni sustituye una campaña de pruebas de instalación limpia y recuperación ante desastres.
