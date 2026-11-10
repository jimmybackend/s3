# Registro y recuperación de fusiones — 2026-11-09

Repositorio: `jimmybackend/s3`; destino: `main`.

## Operaciones realizadas

| PR | Contenido | SHA resultante del squash merge |
|---|---|---|
| [#576](https://github.com/jimmybackend/s3/pull/576) | Documentación inventario OOP: `drive/docs/OOP_AUDIT.md`, `drive/docs/oop_inventory.json` | `0ad265ef442bae2d43a81ca23b0d653b0687c0e4` |
| [#577](https://github.com/jimmybackend/s3/pull/577) | Documentación mapa de endpoints: `drive/docs/RUNTIME_ENDPOINTS.md`, `drive/docs/runtime_endpoints.json` | `0a86b545cc5edd09c374cdc55ae810562e963a02` |

Baseline de `main` anterior a las fusiones:
`b723d00048b6b2d3d9b1151157caa58386a90de5`.

Rama de respaldo creada antes de fusionar:
`backup/pre-doc-merges-20261109`, apuntando al baseline anterior.

PR #582 quedó **sin fusionar** (cambia `composer.lock`; las auditorías OOP fallaron).

## Evaluación de riesgo

Los PR #576 y #577 cambian únicamente cuatro archivos en `drive/docs/`, sin modificar código ejecutable PHP/JS, configuración de base de datos, IAM ni infraestructura. Los workflows inspeccionados de AWS GitOps, despliegue Workstation y operador automático requieren cambios en archivos concretos `tools/arcadecloud-aws-bridge/requests/*.json` para dispararse con `push`, que no fueron afectados.

El estado de los workflows de los PR de documentación era `action_required`; no equivale a validación CI satisfactoria. No se garantiza impacto cero en producción ni se ha validado si un proceso externo consume los inventarios.

No se lanzaron comandos sobre EC2 ni despliegues manuales en esta intervención.

## Comprobaciones posteriores

1. Verificar historial de `main` y que los cambios entre el baseline y los merges sean los cuatro archivos especificados.
2. Revisar GitHub Actions posteriores por fallos y ejecuciones de despliegue inesperadas.
3. Comprobar disponibilidad y comportamiento de producción antes de dar el cambio por cerrado.
4. Conservar esta rama de respaldo hasta validar producción.

## Reversión segura y auditada

**No** hacer force-push, `git reset --hard` sobre producción ni reiniciar EC2. Crear una rama de rollback actualizada desde `main`, revertir los commits en orden inverso y enviar un PR:

```bash
git fetch origin main backup/pre-doc-merges-20261109
git switch -c rollback/docs-20261109 origin/main
git revert --no-edit 0a86b545cc5edd09c374cdc55ae810562e963a02
git revert --no-edit 0ad265ef442bae2d43a81ca23b0d653b0687c0e4
git push -u origin rollback/docs-20261109
```

Revisar el diff y las verificaciones de ese PR antes de fusionarlo. Si únicamente falla #577, revertir solo `0a86b545cc5edd09c374cdc55ae810562e963a02`. Si únicamente falla #576, revertir solo `0ad265ef442bae2d43a81ca23b0d653b0687c0e4`. Preservar commits posteriores de terceros.

La rama `backup/pre-doc-merges-20261109` es una referencia histórica, **no** una orden de restablecer `main` a la fuerza.
