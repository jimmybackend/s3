# FederationDrop: almacenamiento temporal pagado sobre FederationCloud

FederationDrop añade una capa comercial opcional a ArcadeCloud Drive sin convertir FederationCloud en un servicio central obligatorio.

La regla de custodia es deliberadamente estricta:

> **el nodo que cobra por la disponibilidad de un FederationDrop debe conservar una copia física garantizada del objeto durante el periodo vendido.**

Cuando `drive.esforzados.com` cobra, la **custodia final garantizada** queda en el S3 configurado por ese mismo Drive. Un nodo comercial externo puede actuar como **ingress temporal** para acelerar la primera subida, pero el Drop no se activa ni empieza su retención hasta que `drive.esforzados.com` haya migrado y verificado su propia copia. El nodo ingress nunca se convierte por ese hecho en la única custodia pagada.

## Qué problema resuelve

FederationDrop está pensado para compartir un archivo sin:

- adjuntarlo por correo;
- crear una cuenta completa en el Drive;
- entregar acceso a una carpeta personal;
- mantenerlo almacenado indefinidamente;
- conocer en qué bucket o infraestructura vive físicamente.

El cliente compra una combinación de:

- tamaño del archivo;
- días de retención;
- cantidad máxima de accesos de descarga.

Cuando el periodo termina, el objeto se elimina por el worker de limpieza.

## Flujo público

```text
sitio.example
     |
     | badge / enlace
     v
drive.esforzados.com/federationdrop/
     |
     +-- correo del propietario
     +-- archivo local
     +-- días de retención
     +-- máximo de descargas
     |
     v
crear orden
     |
     v
Stripe Checkout
     |
     | Stripe-Signature verificada
     v
autorizar PUT temporal
     |
     | navegador -> S3 privado
     v
verificar objeto
     |
     v
FederationDrop activo
     |
     +-- URL pública de descarga
     +-- .arcadelink firmado
     +-- URL privada de administración/eliminación
```

### Ingress temporal por nodo cercano

Después del pago, un archivo nuevo puede seguir dos caminos:

```text
sin proveedor cercano
navegador -> S3 drive.esforzados.com

proveedor comercial cercano disponible
navegador -> S3 ingress temporal
           -> worker servidor-a-servidor
           -> S3 drive.esforzados.com
           -> activar Drop
```

Sólo aparecen como candidatos nodos presentes en `FederationCommercialProviders` con estado `active`, identidad FederationCloud activa y capacidad suficiente para el tamaño de la orden.

El navegador mide latencia contra los endpoints `drop-ingress.php?action=probe` y elige el candidato que responde más rápido. Si ninguno responde, si CORS falla o si el PUT remoto no se completa, el cliente conserva el archivo seleccionado y cae a la subida central.

La autorización ingress:

- se crea únicamente después de `PaymentStatus=paid`;
- está firmada Ed25519 por el nodo comercial;
- liga `drop_id`, `ingress_id`, tamaño, MIME y Node ID destino;
- expira;
- sólo es aceptada por el Node ID firmado;
- se valida además contra el descriptor público del portal comercial configurado.

El objeto remoto vive bajo:

```text
FederationDropIngress/<commerce_node_id>/<drop_id>/<ingress_id>/...
```

Cuando el nodo remoto confirma el tamaño, el navegador registra el ingress en el nodo comercial. El worker de `drive.esforzados.com` obtiene una URL S3 temporal servidor-a-servidor, descarga en streaming, calcula SHA-256 durante la migración, almacena la copia central y sólo entonces:

1. marca el Drop `active`;
2. crea el placement `primary`;
3. inicia `ExpiresAt`;
4. envía el correo final;
5. ordena borrar el objeto ingress temporal.

Intentos abandonados se limpian automáticamente después de siete días.

### Pago antes de almacenar

Una orden sin pagar **no recibe una URL de subida a S3**.

Esto impide utilizar el nodo como almacenamiento gratuito creando muchas órdenes que nunca se pagan. Sólo cuando `PaymentStatus=paid` el propietario autenticado por su token puede solicitar una autorización de subida de 30 minutos.

La subida es navegador -> S3 mediante URL prefirmada. PHP-FPM no transporta los bytes del archivo.

## Enlaces y tokens

Cada Drop genera dos bearer tokens criptográficamente aleatorios:

1. **owner token**: permite consultar estado y eliminar el Drop;
2. **public token**: permite canjear descargas públicas y obtener el ArcadeLink.

MySQL conserva:

- SHA-256 de ambos tokens para validación;
- una copia cifrada con Sodium `secretbox` para poder reconstruir los enlaces que se envían al correo después de activar el servicio.

La clave de ese cifrado se deriva de la identidad estable del nodo FederationCloud con separación de dominio; no depende de las claves de Stripe. Por eso se pueden rotar `ARCADECLOUD_STRIPE_SECRET_KEY` y `ARCADECLOUD_STRIPE_WEBHOOK_SECRET` sin invalidar los tokens cifrados de Drops existentes.

Los tokens no se almacenan en texto plano.

La página de administración declara `Referrer-Policy: no-referrer` para evitar que un owner token de magic-link viaje como Referer a otro sitio.

## ArcadeLink público, privado y FederationDrop

El lector no debe tratar igual un recurso público que uno privado.

Para un ArcadeLink v1 con:

```text
visibility = PUBLIC
rights = copy_allowed
```

el lector ofrece descarga directa mediante el resolver de réplicas y **no exige sesión ni grant privado**. También ofrece:

- **Copiar a Mi Drive**, que usa la cola `FederationPublicImportJobs`;
- **FederationDrop temporal**, que envía el `resource_id` al portal comercial.

En ese segundo caso el cliente no vuelve a seleccionar el archivo. Stripe cobra el servicio de retención/transferencia/descargas, y después del pago `drive.esforzados.com` materializa el recurso desde las copias FederationCloud disponibles verificando tamaño y Content ID SHA-256.

Para un recurso `PRIVATE` o `requestable_metadata`, el lector no intenta reutilizar el flujo público. Muestra **Solicitar clave / acceso** y usa el protocolo existente de solicitud/aprobación del propietario.

## ArcadeLink v3

Los recursos temporales usan ArcadeLink v3:

```json
{
  "format": "arcadelink",
  "version": 3,
  "resource_type": "drop",
  "resource_id": "arl_...",
  "origin_node_id": "acn_...",
  "title": "archivo.pdf",
  "visibility": "UNLISTED",
  "rights": "copy_allowed",
  "download_url": "https://.../federationdrop/d.php?id=...&t=...",
  "expires_at": "...",
  "signature": {
    "alg": "Ed25519"
  }
}
```

El mismo lector de FederationCloud que abre ArcadeLinks v1 y colecciones v2 valida también v3.

Modificar el enlace de descarga, la fecha de vencimiento o cualquier otro campo firmado invalida la firma Ed25519.

## Precio

FederationDrop no contiene una tarifa comercial codificada en PHP.

La cotización se obtiene con:

```text
billable_gib = ceil(size_bytes / 1 GiB), mínimo 1

total =
    base_fee
  + billable_gib * retention_days * storage_gb_day_rate
  + billable_gib * max_downloads * egress_gb_rate
```

Todos los importes internos usan unidades mínimas de moneda (por ejemplo centavos).

Variables:

```text
ARCADECLOUD_DROP_CURRENCY
ARCADECLOUD_DROP_BASE_FEE_CENTS
ARCADECLOUD_DROP_STORAGE_GB_DAY_CENTS
ARCADECLOUD_DROP_EGRESS_GB_CENTS
```

Así la tarifa final se puede cambiar sin modificar código y puede incorporar tanto costo real como el margen/donativo de mantenimiento de la herramienta.

## Stripe

FederationDrop usa Stripe Checkout directamente, siguiendo la técnica ya probada en MCMA:

- la clave secreta Stripe sólo existe en el nodo comercial;
- la orden y el precio se calculan primero en MySQL;
- el navegador nunca decide el importe;
- `client_reference_id` se liga a `drop_id`;
- la metadata lleva `arcadecloud_drop_id`, tamaño, retención, descargas y una huella SHA-256 de la cotización;
- la misma metadata se copia al PaymentIntent;
- el webhook se valida con `Stripe-Signature`, HMAC-SHA256 y tolerancia de 300 segundos;
- `livemode` debe coincidir con la clave configurada;
- Stripe debe devolver exactamente el importe y la moneda guardados en `FederationDrops`;
- `event_id` se registra en `FederationDropPaymentEvents`, por lo que los reintentos son idempotentes.

FederationDrop procesa:

- `checkout.session.completed`;
- `checkout.session.async_payment_succeeded`;
- `checkout.session.async_payment_failed`;
- `charge.refunded` para reembolso total.

Un `failed` tardío no degrada una orden ya pagada. Un reembolso total bloquea el Drop y retira el objeto físico cuando existe.

El endpoint que debe configurarse en Stripe es:

```text
https://drive.esforzados.com/federationdrop/api.php?action=stripe-webhook
```

También se conserva `action=payment-webhook` como alias de transición, pero ambos exigen la cabecera nativa `Stripe-Signature`.

### Portal comercial canónico

Todos los nodos FederationCloud conocen:

```text
ARCADECLOUD_DROP_COMMERCE_URL=https://drive.esforzados.com/federationdrop
```

Ese valor tiene un default explícito a `drive.esforzados.com`.

En un nodo externo, el botón **Subir / pagar** del portal FederationCloud y del lector ArcadeLink envía al usuario al portal comercial central y añade `?source=<dominio-del-nodo>`.

El nodo externo:

- no crea la orden;
- no recibe el dinero;
- no conoce claves Stripe;
- no recibe credenciales AWS comerciales;
- no custodia el archivo pagado en fase 1.

Sólo el nodo cuya `ARCADECLOUD_DROP_PUBLIC_URL` coincide con `ARCADECLOUD_DROP_COMMERCE_URL` puede habilitar ventas y procesar webhooks comerciales.

### Configuración del nodo comercial

FederationDrop comienza desactivado. `ARCADECLOUD_DROP_ENABLED=false` detiene nuevas órdenes, pero el nodo comercial sigue aceptando webhooks Stripe válidos de pagos ya iniciados mientras las claves Stripe continúen configuradas.

Ejemplo de configuración:

```text
ARCADECLOUD_DROP_ENABLED=true
ARCADECLOUD_DROP_PUBLIC_URL=https://drive.esforzados.com/federationdrop
ARCADECLOUD_DROP_COMMERCE_URL=https://drive.esforzados.com/federationdrop

ARCADECLOUD_STRIPE_SECRET_KEY=<CLAVE_SECRETA_STRIPE>
ARCADECLOUD_STRIPE_WEBHOOK_SECRET=<SECRETO_ENDPOINT_WEBHOOK_STRIPE>

ARCADECLOUD_DROP_CURRENCY=MXN
ARCADECLOUD_DROP_BASE_FEE_CENTS=VALOR
ARCADECLOUD_DROP_STORAGE_GB_DAY_CENTS=VALOR
ARCADECLOUD_DROP_EGRESS_GB_CENTS=VALOR

ARCADECLOUD_DROP_PENDING_HOURS=2
ARCADECLOUD_DROP_MAX_DAYS=30
ARCADECLOUD_DROP_MAX_DOWNLOADS=1000
ARCADECLOUD_DROP_MAX_FILE_BYTES=5368709120
```

Las claves Stripe están marcadas como secretos en `ManagedRuntimeEnvironment`: la UI administrativa informa si están configuradas, pero no devuelve su contenido al navegador.

### Configuración de un nodo federado normal

No necesita Stripe. Basta con conocer el portal comercial:

```text
ARCADECLOUD_DROP_ENABLED=false
ARCADECLOUD_DROP_COMMERCE_URL=https://drive.esforzados.com/federationdrop
```

El enlace comercial sigue funcionando aunque el nodo tenga otro dominio.

## S3 y CORS

Los objetos permanecen privados. FederationDrop no usa `public-read`.

Para que el navegador pueda ejecutar el PUT prefirmado directamente contra S3, el bucket central y los buckets de nodos comerciales que acepten ingress temporal deben permitir CORS desde el origen web del nodo comercial.

Ejemplo conceptual para `drive.esforzados.com`:

```json
[
  {
    "AllowedOrigins": ["https://drive.esforzados.com"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["Content-Type"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 300
  }
]
```

Ajustar esa política al dominio real. No utilizar `*` cuando el nodo comercial conoce su origen HTTPS.

La autorización PUT dura 30 minutos. La URL GET final dura dos minutos y se obtiene después de canjear el enlace público.

### Qué cuenta como una descarga

`DownloadCount` cuenta cada **canje válido de `d.php`**. El canje está serializado con `SELECT ... FOR UPDATE`, por lo que solicitudes simultáneas no saltan el límite.

Después del canje se entrega una URL S3 prefirmada de dos minutos. Durante esa ventana el mismo URL podría ser reutilizado por el mismo receptor; por tanto este contador representa canjes autorizados, no cada request HTTP que S3 reciba internamente. Una futura capa CDN/edge con tickets de un solo uso puede hacer el conteo físico aún más estricto sin pasar gigabytes por PHP.

## Expiración y eliminación

El worker:

```text
drive/bin/federation_drop_cleanup.php
```

elimina:

- Drops activos cuyo `ExpiresAt` venció;
- órdenes **no pagadas** abandonadas después de `ARCADECLOUD_DROP_PENDING_HOURS`.

Una orden con pago confirmado y archivo todavía pendiente no se elimina por ese reloj: no consume almacenamiento y debe seguir recuperable para que el cliente pueda completar la subida posteriormente.

El timer systemd se instala con:

```bash
sudo bash drive/bin/install_federation_drop_cleanup_timer.sh \
  --run-user=nginx \
  --app-root=/var/www/arcadecloud-drive
```

El instalador principal lo habilita junto con FederationCloud. El desinstalador también elimina sus unidades systemd.

El propietario puede retirar su Drop antes del vencimiento usando el enlace privado de administración.

## Correo

Cuando Stripe confirma el pago, SMTP envía primero un correo con el enlace privado para completar la subida. Así el cliente puede cerrar la pestaña del checkout y recuperar después su orden pagada.

Cuando la subida queda verificada y el Drop pasa a activo, SMTP envía otro correo con:

- URL pública;
- URL para descargar el `.arcadelink`;
- URL privada para administrar/eliminar;
- vencimiento;
- máximo de descargas.

El correo del propietario no forma parte del ArcadeLink público.

## Badge para cualquier dominio

El repositorio incluye:

```text
drive/federationdrop/badge.svg
```

Un dominio externo puede publicar:

```html
<a href="https://drive.esforzados.com/federationdrop/?source=mi-dominio.example">
  <img
    src="https://drive.esforzados.com/federationdrop/badge.svg"
    alt="Compartir con FederationDrop">
</a>
```

`source` se registra como atribución del dominio que originó al cliente, pero no concede permisos sobre el objeto.

Ese dominio no recibe credenciales AWS ni tiene que almacenar el archivo.

## Esquema de datos

FederationDrop vive dentro de la sección canónica FederationCloud de `adbbmis1_Cloud.sql`. No existe un segundo SQL.

Tablas:

### FederationDrops

Contrato comercial y estado del objeto:

- propietario;
- tokens;
- S3 key;
- tamaño;
- plan;
- importe;
- pago;
- descargas;
- vencimiento;
- nodo custodio.

### FederationDropPaymentEvents

Ledger idempotente de callbacks del procesador.

### FederationCommercialProviders

Prepara la fase comercial distribuida:

- `NodeId`;
- dominio fijo;
- estado;
- capacidad ofrecida;
- máximo por archivo;
- región;
- correo de liquidación;
- `CommissionBps`;
- modo de garantía.

El porcentaje usa basis points:

```text
10000 = 100 %
2500  = 25 %
500   = 5 %
```

### FederationDropIngressObjects

Cola/estado de objetos de ingreso temporal:

- `IngressId`;
- Drop y nodo comercial;
- nodo ingress y Federation URL;
- key S3 sólo en el nodo que recibe temporalmente;
- tamaño/MIME esperado;
- grant Ed25519;
- estados `authorized/uploaded/pulling/centralized/deleted/failed`;
- intentos y backoff.

Esta tabla existe en todos los nodos, pero una instalación sólo tendrá `S3Key` para los ingress que ella haya recibido físicamente.

### FederationDropPlacements

Registra dónde existe una copia pagada y con qué papel:

- `primary`;
- `guarantee`;
- `provider`.

## Proveedores comerciales: fase 2

Un nodo FederationCloud normal **no se convierte por instalarse en proveedor de archivos pagados**.

Para participar comercialmente deberá cumplir como mínimo:

1. identidad FederationCloud válida;
2. dominio HTTPS fijo;
3. aprobación comercial;
4. capacidad declarada;
5. porcentaje acordado;
6. política de disponibilidad;
7. mecanismo de liquidación;
8. copia de garantía.

Los modos previstos son:

### central_copy

```text
drive.esforzados.com
        |
        +-- cobra
        +-- conserva copia garantizada
        |
        +--> proveedor comercial
             recibe copia operativa
             obtiene CommissionBps
```

### dual_replica

```text
                 contrato/pago
                      |
              +-------+-------+
              |               |
        proveedor A       proveedor B
          operativo        garantía
```

La colocación de **custodia final** en proveedores externos sigue deshabilitada. Un proveedor `active` sí puede utilizarse como **ingress temporal** después del pago, pero el Drop continúa pendiente hasta que exista la copia central verificada. Tener una fila `FederationCommercialProviders` nunca autoriza a que un proveedor se quede como única copia pagada.

Sólo deberá activarse el reparto cuando el código pueda demostrar que existe la garantía requerida. Esto evita cobrar por una disponibilidad que dependa de un tercero que pueda apagar su nube.

## Relación con FederationCloud normal

FederationCloud libre conserva su comportamiento:

- nodos autónomos;
- catálogo federado;
- Shares;
- réplicas;
- ArcadeLinks propios.

FederationDrop es una capa adicional:

```text
FederationCloud
      +
ArcadeLink
      +
FederationDrop
      +
Commercial Providers (fase 2)
```

No convierte a `drive.esforzados.com` en nodo matriz para el protocolo normal. Sólo es central respecto del contrato comercial que él mismo cobre.

## Seguridad y abuso

Al cobrar y custodiar archivos, el operador debe mantener mecanismos de abuso y retiro. El código ya proporciona borrado por propietario, expiración, estado `blocked` y aislamiento en el prefijo `FederationDrops/`.

Antes de abrir el servicio al público a gran escala conviene añadir/operar:

- rate limiting por IP/correo;
- validación/antimalware cuando aplique;
- proceso de reporte de abuso;
- bloqueo administrativo;
- reglas de contenido y términos del servicio;
- registro mínimo necesario para disputas de pago;
- política de privacidad y retención.

La arquitectura no debe prometer que la descentralización elimina las obligaciones del nodo que cobra y custodia.

## Estado de implementación

Implementado en esta fase:

- formulario público sin cuenta de Drive;
- cotización configurable;
- orden comercial;
- pago antes de almacenamiento;
- Stripe Checkout real con precio dinámico calculado en servidor;
- webhook Stripe firmado, con tolerancia temporal e idempotencia;
- portal comercial canónico `drive.esforzados.com` para todos los nodos;
- upload directo a S3 privado;
- selección opcional por latencia de ingress comercial cercano;
- migración servidor-a-servidor del ingress a custodia central;
- grant Ed25519 específico por Drop/nodo/tamaño;
- limpieza de ingress temporales abandonados;
- validación de tamaño del objeto;
- enlaces públicos;
- límite transaccional de canjes;
- borrado;
- expiración automática;
- correo;
- ArcadeLink v3;
- badge para dominios externos;
- esquema de proveedores/porcentajes/garantías;
- FederationDrop directo desde un `resource_id` PUBLIC sin volver a seleccionar el archivo.

Pendiente para la fase comercial distribuida:

- liquidación automática a proveedores comerciales;
- asignación automática de objetos pagados a proveedores externos;
- réplica comercial dual automática.

Estas funciones no deben activarse antes de tener la garantía de disponibilidad implementada. Stripe ya está integrado para el nodo comercial central.
