# FederationCloud — operación, Aduana, HTTPS y réplicas

Estado operativo documentado: 13-Sep-2026.

Este documento reúne las decisiones e implementación operativa de FederationCloud para que un nodo pueda arrancar, publicar su endpoint HTTPS, anunciar presencia, entrar a la federación, solicitar confianza privilegiada únicamente cuando corresponde y reactivarse después de estar apagado sin perder su identidad ni pedir aprobación humana otra vez.

## Principios

1. La identidad de un nodo es su `Node ID` derivado de su clave pública Ed25519. Una IP o un dominio son únicamente endpoints de red.
2. Cambiar IP o dominio no crea un nodo nuevo si `Node ID` y clave pública siguen siendo los mismos.
3. Un nodo independiente puede anunciar su presencia automáticamente. Registrarse no concede acceso a archivos de otros nodos.
4. Una copia que comparte backend con un origen usa `relationship=shared_backend` y requiere aprobación del superadmin sólo la primera vez.
5. `authorized` y `available` son estados distintos: una réplica apagada conserva autorización, pero deja de estar disponible.
6. El nodo origen no sondea a sus réplicas. La réplica es responsable de anunciar que volvió a estar en línea.
7. MySQL es la fuente de verdad de las colas y autorizaciones; S3 sigue siendo almacenamiento físico.
8. Los endpoints públicos permanecen delgados. Los trabajos pesados se ejecutan fuera de PHP-FPM.

## HTTPS: dominio primero, IP pública como alternativa

El reconciliador HTTPS usa esta prioridad:

```text
¿hay dominio configurado?
  sí -> DOMAIN -> certificado del dominio
  no -> ¿hay IPv4 pública EC2?
          sí -> DYNAMIC_IP -> certificado IP short-lived
          no -> nodo no publicable
```

En modo IP dinámica, la IPv4 actual se obtiene mediante EC2 IMDSv2. El cambio de IP actualiza `ARCADECLOUD_PUBLIC_URL` y `ARCADECLOUD_FEDERATION_URL`, pero no modifica la identidad Ed25519 del nodo.

El servicio instalado es:

```text
arcadecloud-federation-https.service
arcadecloud-federation-https.timer
```

El timer usa:

```ini
OnActiveSec=45s
OnUnitInactiveSec=12h
RandomizedDelaySec=10m
Persistent=true
```

`OnActiveSec` garantiza una primera ejecución aunque el timer se instale o active después de la ventana del boot. `OnUnitInactiveSec` es apropiado para el servicio `oneshot`: programa la siguiente reconciliación después de que el servicio termina.

El reconciliador no debe ejecutarse como root para la lógica de aplicación. systemd carga los mismos `EnvironmentFile` del Drive y ejecuta el refresh FederationCloud con el usuario real del pool PHP-FPM.

## Identidad criptográfica

Archivo por defecto:

```text
/etc/arcadecloud-drive/federation-node.json
```

Nunca debe copiarse la clave privada entre nodos. Una réplica debe tener una identidad FederationCloud distinta de su origen.

El archivo contiene material privado. No debe mostrarse con `cat`, publicarse en logs ni enviarse por FederationCloud. Los workers reciben sólo el permiso mínimo necesario para leerlo.

## Presencia de nodos independientes

Los nodos independientes no requieren superadmin.

```text
nodo nuevo
  -> descriptor firmado
  -> register.php
  -> FederationCustomsService
  -> FederationIngressQueue
  -> worker único
  -> valida node.php HTTPS en vivo
  -> FederationNodes
  -> node.upsert
  -> gossip
```

La identidad y la clave pública permanecen vinculadas. Un mismo `Node ID` puede actualizar `public_url` y `federation_url` cuando presenta un descriptor nuevo firmado y el `node.php` en vivo coincide.

## Aduana FederationCloud

`FederationIngressQueue` persiste la entrada en MySQL. `RequestId` es idempotente.

Estados:

```text
queued
processing
retry
done
rejected
failed
```

El worker `drive/bin/federation_sync.php` mantiene un lock exclusivo y toma como máximo una petición externa de Aduana por ciclo. De esta forma el servidor HTTP puede aceptar varias llegadas sin ejecutar en paralelo los procesadores que no son multiproceso.

Orden simplificado de un ciclo:

```text
1. presencia de réplica local, si este nodo es una copia configurada
2. una petición de Aduana
3. gossip
4. solicitudes privadas
5. réplicas físicas
6. importaciones de Compartidos
```

El timer de sync usa una primera ejecución 45 segundos después de activarse y luego `OnUnitInactiveSec` con el intervalo configurado. En producción se usa un intervalo corto con jitter para evitar que todos los nodos trabajen al mismo instante.

## Dos relaciones que no deben confundirse

### Nodo independiente

Sólo dice: "ésta es mi identidad y éste es mi endpoint actual".

No entra en `Solicitudes`, no recibe acceso privilegiado y no necesita aprobación humana.

### Copia / réplica con backend compartido

Declara explícitamente:

```text
relationship=shared_backend
```

La primera vez entra por:

```text
provider-request.php
  -> Aduana
  -> verificación criptográfica + node.php en vivo
  -> FederationNodeAuthorizations = pending
  -> Solicitudes
  -> superadmin approve/reject
```

No se intenta demostrar backend compartido enviando hosts de DB, buckets S3, contraseñas, cookies, sesiones o claves. La relación es una decisión administrativa del nodo origen.

## Autorización permanente vs disponibilidad temporal

Una autorización `active` no desaparece cuando la réplica se apaga.

Ejemplo:

```text
flashdrive apagada
  authorized = true
  available  = false

flashdrive encendida
  authorized = true
  available  = true
```

La disponibilidad se considera reciente dentro de una ventana de 15 minutos a partir de `LastSeen`.

Las listas administrativas muestran todas las autorizaciones activas, incluso si una copia está offline. En cambio, la asignación de nuevo trabajo físico selecciona sólo autorizaciones activas con presencia reciente.

## Puerta rápida de reactivación

Una réplica ya aprobada usa:

```text
POST /federationcloud/provider-presence.php
```

El payload contiene únicamente:

- `origin_node_id`;
- `provider_descriptor` firmado;
- `relationship=shared_backend`.

La puerta rápida NO concede autorización y NO modifica `role` ni `scope`.

El origen hace estas comprobaciones:

1. el request está dirigido a su propio `Node ID`;
2. existe `FederationNodeAuthorizations` para ese proveedor;
3. el estado es `active`;
4. el descriptor Ed25519 es válido;
5. `node.php` HTTPS está accesible y coincide;
6. la clave pública sigue vinculada al mismo `Node ID`;
7. sólo entonces actualiza endpoint y `LastSeen`.

Resultado:

```text
status=active
available=true
requires_superadmin=false
```

Si la relación no existe:

```text
status=authorization_required
```

La copia cae automáticamente al alta inicial por `provider-request.php`, que sí entra a Aduana y luego a `Solicitudes`.

Si la relación está `pending`, `revoked` o `blocked`, la copia no crea una nueva autorización de forma automática.

## La réplica se presenta; el origen no la busca

Ejemplo de producción:

```text
drive.esforzados.com       = origen
flashdrive.esforzados.com  = réplica/provider
```

El comportamiento correcto es:

```text
flashdrive arranca
  -> resuelve su HTTPS
  -> firma su descriptor actual
  -> anuncia su presencia al origen
  -> si ACTIVE: fast path, LastSeen/endpoint actualizados
  -> si no existe relación: Aduana + Solicitudes
  -> emite su node.upsert local
  -> gossip propaga disponibilidad
```

`drive.esforzados.com` no tiene un cron que pregunte continuamente si `flashdrive.esforzados.com` encendió.

## Configuración de una réplica

Sólo la copia configura el origen:

```env
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL=https://drive.esforzados.com/federationcloud/
ARCADECLOUD_FEDERATION_REPLICA_ROLE=mirror
ARCADECLOUD_FEDERATION_REPLICA_SCOPE=all_allowed_resources
```

El nodo origen no debe definir `ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL`.

Valores permitidos:

```text
role:  provider | mirror
scope: all_allowed_resources | selected_resources
```

El anuncio automático se ejecuta desde:

- `drive/bin/federation_endpoint_refresh.php` al arrancar/reconciliar endpoint;
- `drive/bin/federation_sync.php` en los ciclos periódicos.

Por eso una EC2 que estuvo apagada y vuelve después de que pasó cualquier ventana anterior se vuelve a presentar por iniciativa propia.

## Primera autorización de una copia

También puede iniciarse manualmente:

```bash
sudo -u apache php drive/bin/federation_provider_request.php \
  --origin=https://drive.esforzados.com/federationcloud/ \
  --public-url=https://flashdrive.esforzados.com \
  --federation-url=https://flashdrive.esforzados.com/federationcloud/ \
  --role=mirror \
  --scope=all_allowed_resources
```

Después el superadmin del origen revisa `Solicitudes` y aprueba o rechaza.

Tras `approve`, los futuros reinicios usan `provider-presence.php`; no vuelven a llenar `Solicitudes`.

## Gossip y disponibilidad

Cada nodo emite un `node.upsert` firmado con heartbeat discreto. El evento sólo puede pertenecer al nodo firmante.

Una réplica aprende el descriptor del origen durante la reactivación y lo persiste como peer conocido. Después `federation_sync.php` puede empujar al origen el `node.upsert` firmado de la copia y el gossip lo replica al resto de la red.

La IP/dominio no es identidad. La firma Ed25519 sí lo es.

## Seguridad

Nunca se transmiten como prueba de relación:

- credenciales AWS;
- claves DB;
- cookies o sesiones;
- clave privada Ed25519;
- `payload_key` de ArcadeLink;
- URLs S3 permanentes.

`FederationHttpClient` sólo permite una lista cerrada de endpoints, obliga HTTPS, desactiva redirects, limita tamaños, fija el DNS resuelto con `CURLOPT_RESOLVE` y rechaza IPv4 privadas/reservadas para reducir SSRF y DNS rebinding.

`provider-presence.php` es una puerta de estado, no una puerta de privilegios: una autorización inexistente, pendiente, revocada o bloqueada nunca se convierte en `active` por esa ruta.

## Observabilidad

Comprobar Aduana/sync:

```bash
systemctl is-enabled arcadecloud-federation-sync.timer
systemctl is-active arcadecloud-federation-sync.timer
systemctl list-timers --all arcadecloud-federation-sync.timer --no-pager
sudo journalctl -u arcadecloud-federation-sync.service -n 100 --no-pager
```

Comprobar HTTPS:

```bash
systemctl is-enabled arcadecloud-federation-https.timer
systemctl is-active arcadecloud-federation-https.timer
systemctl list-timers --all arcadecloud-federation-https.timer --no-pager
sudo nginx -t
```

En la salida JSON de `federation_sync.php` aparece `replica_presence`. En un nodo origen normal debe informar que no está configurado como réplica. En una copia autorizada debe evolucionar a `status=active` y `available=true`.

## Archivos principales

```text
drive/src/Federation/FederationEndpointResolver.php
drive/bin/federation_https_reconcile.php
drive/bin/federation_endpoint_refresh.php
drive/bin/install_federation_https_service.sh

drive/src/Federation/FederationCustomsService.php
drive/src/Federation/FederationIngressQueueRepository.php
drive/sql/federation_ingress_queue.sql
drive/bin/federation_sync.php
drive/bin/install_federation_sync_timer.sh

drive/src/Federation/FederationProviderAuthorizationService.php
drive/src/Federation/FederationProviderAuthorizationRepository.php
drive/src/Federation/FederationReplicaPresenceService.php
drive/federationcloud/provider-request.php
drive/federationcloud/provider-presence.php
```

## Regla operativa final

```text
identidad permanente = Node ID + clave Ed25519
ubicación cambiante   = IP o dominio
confianza permanente  = autorización active
presencia temporal    = LastSeen / available
primera confianza     = Aduana + superadmin
reconexión posterior  = fast path firmado
replicación global    = node.upsert + gossip
```
