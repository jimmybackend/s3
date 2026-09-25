# Validación de producción de ArcadeLink

Fecha de corte: **24 de septiembre de 2026**.

Este documento separa lo que ya fue probado manualmente en producción de lo que sólo está cubierto por CI o sigue pendiente de validación entre nodos.

## Contrato vigente

ArcadeLink es un archivo nativo de ArcadeCloud:

```text
Extensión:  .arcadelink
Media type: application/vnd.arcadecloud.arcadelink
```

Sólo se aceptan archivos cuyo nombre termina en `.arcadelink`. Otros sufijos no forman parte del contrato y deben regenerarse desde **Compartir**.

Un único ArcadeLink puede representar:

- **v1**: un recurso;
- **v2**: una colección con uno o varios recursos firmados;
- **v3**: un FederationDrop temporal firmado.

La serialización interna firmada es un detalle del protocolo y no cambia la identidad externa del archivo.

## Validado manualmente en producción

Sobre el nodo de producción se comprobó el flujo completo navegador -> ArcadeLink -> lector FederationCloud -> descarga:

- [x] **Un archivo**: Compartir genera un único `.arcadelink`.
- [x] El lector acepta ese `.arcadelink`, verifica la firma y presenta el recurso.
- [x] El recurso individual se descarga correctamente.
- [x] **Tres archivos**: Compartir genera un único `.arcadelink` de colección.
- [x] El lector presenta los tres recursos de la colección.
- [x] Los tres recursos pueden descargarse correctamente desde el mismo ArcadeLink.
- [x] El origen puede ser la única ubicación disponible; no se exige quorum ni un número mínimo de nodos.
- [x] La descarga pública del origen reconstruye la key S3 canónica desde MySQL/FileS3 y no usa directamente el `storage_ref` lógico.
- [x] Compartir entrega el archivo con extensión `.arcadelink` y media type propio de ArcadeCloud.

## Cubierto por CI

Las pruebas automáticas verifican, entre otras cosas:

- [x] firma Ed25519;
- [x] payload privado XChaCha20-Poly1305;
- [x] alteración de metadata invalida la firma;
- [x] alteración de un recurso dentro de una colección invalida la colección;
- [x] colección v2 contiene recursos firmados;
- [x] límite explícito de 500 recursos;
- [x] límite total de 4 MiB por ArcadeLink;
- [x] el lector recorre todos los elementos de una colección;
- [x] no se usa ZIP para representar colecciones;
- [x] sólo `.arcadelink` es la extensión aceptada;
- [x] single, collection y FederationDrop comparten `application/vnd.arcadecloud.arcadelink`;
- [x] no se listan buckets/objetos S3 para resolver un ArcadeLink;
- [x] failover de ubicaciones no requiere quorum.

## Pendiente de validación manual

### Prioridad alta

- [ ] **Dos nodos reales, origen + mirror/provider**: abrir un ArcadeLink desde el segundo nodo y descargar el recurso servido por el origen.
- [ ] **Failover real de descarga**: publicar el mismo recurso en dos ubicaciones, apagar la preferida y comprobar que la siguiente ubicación responda sin intervención manual.
- [ ] **Colección con orígenes distintos**: un mismo `.arcadelink` que contenga recursos cuyo `origin_node_id` pertenezca a nodos diferentes.
- [ ] **Recurso PUBLIC + copy_allowed con réplica física**: confirmar descarga desde la réplica y luego desde origen al retirar la réplica.
- [ ] **Nodo que vuelve tarde**: apagar un nodo, volverlo a encender y comprobar presencia, catálogo y disponibilidad sin recrear el ArcadeLink.

### Prioridad media

- [ ] Probar `UNLISTED + link_only` desde un navegador/sesión que no sea del propietario.
- [ ] Probar `PRIVATE` y confirmar que no ofrece una ruta pública incompatible.
- [ ] Probar un `FileS3.AccessType=secure` y confirmar que sólo abre bajo la política local permitida.
- [ ] Mover o renombrar el archivo original después de generar el ArcadeLink y confirmar el comportamiento esperado de la referencia estable.
- [ ] Eliminar/tombstonear un recurso después de compartirlo y comprobar que no pueda revivirse mediante una ubicación antigua.
- [ ] Seleccionar repetidamente el mismo archivo dentro de una colección y confirmar la deduplicación.
- [ ] Probar una colección cercana al límite operativo para observar tamaño y UX, sin asumir que 500 elementos caben siempre bajo el límite global de 4 MiB.

## Criterio de cierre

ArcadeLink puede considerarse cerrado para operación **local/origen único** con los casos de uno y varios archivos ya probados en producción.

Para considerar cerrado el comportamiento **federado mult nodo**, deben completarse al menos los cinco casos de prioridad alta anteriores.

Cuando una prueba manual se complete, actualiza este documento indicando fecha, topología y resultado. No marques como probado en producción un caso que sólo esté cubierto por CI.
