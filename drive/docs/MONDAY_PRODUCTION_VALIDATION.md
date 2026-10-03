# Validación de producción pendiente para el lunes

Fecha de preparación: **3 de octubre de 2026**.

Este checklist separa lo que puede quedar validado en el repositorio de lo que requiere entrar a los servidores reales Drive/FastDrive. No sustituye las pruebas manuales de producción.

## Antes de tocar producción

En cada nodo:

```bash
cd /var/www/arcadecloud-drive
git status --short
git rev-parse HEAD
```

No actualizar si hay cambios locales inesperados. Usar el actualizador normal de ArcadeCloud o `git pull --ff-only origin main` únicamente si el árbol está limpio.

## Preflight de solo lectura

Ejecutar con el mismo entorno efectivo del Drive. En la instalación estándar:

```bash
cd /var/www/arcadecloud-drive
sudo -u apache env ARCADECLOUD_RUNTIME_ENV=/etc/arcadecloud-drive/runtime-env.json \
  php drive/bin/production_preflight.php
```

El comando **no modifica** base de datos, identidad, archivos, servicios ni S3. Revisa conexión DB, configuración e identidad FederationCloud, presencia de tablas esenciales, archivos críticos del runtime y alertas básicas de disco/RAM. No imprime contraseñas, tokens ni claves.

Un resultado `status: ready` permite seguir. `warning` requiere revisar la advertencia. `fail` debe resolverse antes de una prueba federada.

## Pruebas manuales obligatorias

### 1. ArcadeCloud OS

- [ ] Abrir **Mi nodo** en escritorio y móvil.
- [ ] Crear respaldo DB: confirmar que aparecen dos ventanas internas de ArcadeCloud, no `confirm()`/`prompt()`.
- [ ] Confirmar que el campo de contraseña permite al navegador ofrecer la credencial guardada.
- [ ] Confirmar que el dump termina en `Data/Backup/` y reporta inventario verificado.
- [ ] Abrir acciones de un archivo y comprobar **Detalles**: nombre, tipo, peso y fecha.
- [ ] En móvil, verificar navegación del menú en grupos de 10 acciones con flechas arriba/abajo.
- [ ] Abrir una imagen y comprobar que **Descargar** queda en el pie y no estorba al botón cerrar.

### 2. Dump real de base de datos

- [ ] Descargar un dump recién creado.
- [ ] Verificar que contiene `-- Status: COMPLETE`.
- [ ] Restaurarlo únicamente en una base desechable fuera de producción.
- [ ] Comparar tablas, filas, vistas, procedimientos, funciones, triggers y eventos.
- [ ] No restaurar encima de la base productiva para probarlo.

### 3. FederationCloud Drive ↔ FastDrive

- [ ] Ambos nodos aparecen con identidad distinta y estado activo.
- [ ] Ejecutar un ciclo de sincronización y confirmar presencia/catálogo.
- [ ] Compartir un recurso desde Drive y resolverlo desde FastDrive.
- [ ] Descargarlo desde el segundo nodo y comprobar historial de entrega.
- [ ] Verificar `FederationResourceDeliveries` y `FederationResourceDeliverySources` sin datos incoherentes.
- [ ] Crear o usar una réplica real y descargar desde ella.
- [ ] Probar failover apagando temporalmente la ubicación preferida.
- [ ] Probar multisource con más de una ubicación activa.
- [ ] Confirmar que el historial identifica nodo solicitante y nodos fuente.
- [ ] Probar ArcadeLink de colección con recursos de más de un origen cuando haya datos adecuados.
- [ ] Probar recuperación/re-resolución después de apagar y volver a iniciar FastDrive.
- [ ] Probar reporte/moderación y confirmar que un bloqueo impide servir el contenido esperado.

### 4. Capacidad del nodo pequeño

- [ ] Antes de una descarga multisource grande, comprobar disco libre.
- [ ] No usar un archivo cuyo ensamblaje temporal pueda consumir el espacio disponible del nodo Drive.
- [ ] Observar uso de disco durante el ensamblaje y confirmar limpieza del temporal después de éxito y error.

### 5. Office / KDE / Guacamole / autoapagado

- [ ] Abrir y guardar al menos DOCX, XLSX y PPTX/ODP compatibles.
- [ ] Confirmar sincronización de vuelta a S3.
- [ ] Abrir KDE/Guacamole y verificar que actividad real bloquea el autoapagado.
- [ ] Dejar el nodo realmente ocioso y comprobar apagado a los 20 minutos configurados.
- [ ] Verificar que el aviso previo no provoca recarga completa del OS móvil.

## Criterio de cierre

El proyecto puede pasar a mantenimiento cuando:

1. el preflight sea `ready` o sólo tenga advertencias conocidas;
2. el dump real se restaure correctamente fuera de producción;
3. Drive ↔ FastDrive complete descarga, réplica, failover, multisource e historial;
4. móvil no tenga acciones inaccesibles;
5. Office/Guacamole/KDE y autoapagado funcionen con actividad real.

No marcar un punto como validado en producción únicamente porque CI esté verde.
