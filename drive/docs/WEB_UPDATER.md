# ArcadeCloud Web Updater

## Qué actualiza

El actualizador de **Acerca de -> Actualizaciones** trabaja sobre el checkout Git instalado en el nodo, normalmente:

```text
/var/www/arcadecloud-drive
```

La configuración de producción permanece fuera del repositorio, principalmente en:

```text
/etc/arcadecloud-drive/
```

Por tanto, un fast-forward de `main` no debe reemplazar `runtime-env.json`, la identidad FederationCloud, credenciales, Nginx ni otros secretos administrados fuera de Git.

## Por qué puede aparecer "Revisión manual requerida"

El updater compara tres estados distintos:

1. `origin/main`: versión disponible en GitHub.
2. `HEAD`: commit instalado en la EC2.
3. working tree: archivos locales modificados o no rastreados dentro del checkout.

Un repositorio remoto sano puede coexistir con una EC2 cuyo working tree esté sucio. En ese caso el bloqueo procede del **checkout local de la EC2**, no de GitHub.

El modo normal sigue siendo conservador: sólo hace `git merge --ff-only origin/main` cuando:

- la rama es `main`;
- no hay commits locales por delante;
- el working tree está limpio;
- existen commits remotos pendientes.

## Actualización con cambios locales

Si `main` tiene cambios locales pero no tiene commits locales por delante, la interfaz ofrece:

```text
Guardar cambios y actualizar
```

El flujo requiere la contraseña actual del superusuario y una confirmación explícita. El helper ejecuta:

```text
git stash push --include-untracked -m arcadecloud-web-update-...
git merge --ff-only origin/main
```

Los cambios guardados **no se restauran automáticamente**. Esto evita volver a ensuciar el checkout o reintroducir un hotfix incompatible después de actualizar. El stash queda únicamente en el repositorio local de la EC2 para revisión o eliminación posterior.

La pantalla muestra hasta 25 entradas de `git status --porcelain` para que el operador pueda identificar qué está ensuciando el checkout.

Si existen commits locales por delante de `origin/main`, el updater continúa rechazando la operación: esa situación requiere revisión manual y no se resuelve con stash.

## Commits documentales generados por GitHub Actions

Los commits con mensajes como:

```text
Document OOP architecture inventory
Document active Drive runtime endpoints
```

pueden aparecer después de una actualización porque GitHub Actions regenera inventarios documentales tras cambios de código. Son commits remotos normales. No significan que la EC2 esté dañada ni son, por sí solos, la causa de un working tree sucio.

## Limpieza de respaldos manuales

Durante una reparación pueden existir respaldos creados fuera del repositorio, por ejemplo:

```text
/var/backups/arcadecloud-fastdrive-*
/etc/arcadecloud-drive.backup-*
/tmp/fastdrive-local-*.patch
```

Antes de borrarlos confirma:

```bash
cd /var/www/arcadecloud-drive
git status --short
git stash list
sudo ls -ld /var/backups/arcadecloud-fastdrive-* /etc/arcadecloud-drive.backup-* 2>/dev/null || true
ls -l /tmp/fastdrive-local-*.patch 2>/dev/null || true
```

Cuando la instalación esté verificada y esos respaldos ya no sean necesarios, pueden eliminarse de forma explícita:

```bash
sudo rm -rf /var/backups/arcadecloud-fastdrive-*
sudo rm -rf /etc/arcadecloud-drive.backup-*
rm -f /tmp/fastdrive-local-*.patch
```

Los stashes se revisan por separado. No usar `git stash clear` sin comprobar antes la lista. Para eliminar un stash concreto:

```bash
git stash list
git stash drop 'stash@{N}'
```

Los stashes creados por el updater web se identifican con el prefijo `arcadecloud-web-update-`.
