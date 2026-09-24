# FederationDrop: almacenamiento temporal pagado sobre FederationCloud

FederationDrop añade una capa comercial opcional a ArcadeCloud Drive sin convertir FederationCloud en un servicio central obligatorio.

La regla de custodia es deliberadamente estricta:

> **el nodo que cobra por la disponibilidad de un FederationDrop debe conservar una copia física garantizada del objeto durante el periodo vendido.**

En la fase 1, cuando `drive.esforzados.com` cobra, el objeto queda almacenado en el S3 configurado por ese mismo Drive. Un nodo federado externo no puede convertirse automáticamente en la única copia de un recurso pagado.

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
pago externo
     |
     | webhook HMAC confirmado
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

La clave de ese cifrado se deriva de la identidad estable del nodo FederationCloud con separación de dominio; no depende del secreto del procesador de pagos. Por eso se puede rotar `ARCADECLOUD_DROP_WEBHOOK_SECRET` sin invalidar los tokens cifrados de Drops existentes.

Los tokens no se almacenan en texto plano.

La página de administración declara `Referrer-Policy: no-referrer` para evitar que un owner token de magic-link viaje como Referer a otro sitio.

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

## Pasarela de pago

El repositorio **no selecciona ni simula una pasarela de pago**. Actualmente no existe Stripe, PayPal, Mercado Pago u otro SDK de cobro en el proyecto.

FederationDrop define un adaptador neutral mediante:

```text
ARCADECLOUD_DROP_CHECKOUT_URL
ARCADECLOUD_DROP_WEBHOOK_SECRET
```

El checkout recibe:

```text
drop_id
amount_cents
currency
return_url
webhook_url
signature
```

La firma del checkout cubre:

```text
drop_id | amount_cents | currency | return_url | webhook_url
```

con HMAC-SHA256 usando `ARCADECLOUD_DROP_WEBHOOK_SECRET`.

El adaptador de pago debe comprobar esa firma antes de iniciar el cobro. No debe aceptar valores sustituidos por el navegador.

Después de confirmar el pago, el adaptador envía un JSON al `webhook_url`:

```json
{
  "event_id": "evt_unique",
  "drop_id": "fdp_...",
  "provider": "nombre-del-proveedor",
  "reference": "referencia-del-cobro",
  "status": "paid",
  "amount_cents": 12345,
  "currency": "MXN"
}
```

Estados soportados:

- `paid`
- `failed`
- `refunded`

El cuerpo JSON **exacto** se firma:

```text
hex(HMAC-SHA256(raw_http_body, ARCADECLOUD_DROP_WEBHOOK_SECRET))
```

y se envía en:

```text
X-ArcadeCloud-Drop-Signature: <hex>
```

FederationDrop verifica además que monto y moneda sean exactamente los almacenados en la orden.

`event_id` es único, por lo que los reintentos del procesador son idempotentes.

Un evento `failed` tardío no puede degradar una orden ya pagada. Un `refunded` bloquea el Drop y retira su objeto físico cuando existe.

## Configuración

FederationDrop comienza desactivado.

Ejemplo de valores **de referencia**, no una tarifa recomendada:

```text
ARCADECLOUD_DROP_ENABLED=true
ARCADECLOUD_DROP_PUBLIC_URL=https://drive.esforzados.com/federationdrop
ARCADECLOUD_DROP_CHECKOUT_URL=https://PAGO-EJEMPLO/checkout/federationdrop
ARCADECLOUD_DROP_WEBHOOK_SECRET=GENERAR_UN_SECRETO_ALEATORIO_DE_AL_MENOS_32_CARACTERES
ARCADECLOUD_DROP_CURRENCY=MXN

ARCADECLOUD_DROP_BASE_FEE_CENTS=VALOR
ARCADECLOUD_DROP_STORAGE_GB_DAY_CENTS=VALOR
ARCADECLOUD_DROP_EGRESS_GB_CENTS=VALOR

ARCADECLOUD_DROP_PENDING_HOURS=2
ARCADECLOUD_DROP_MAX_DAYS=30
ARCADECLOUD_DROP_MAX_DOWNLOADS=1000
ARCADECLOUD_DROP_MAX_FILE_BYTES=5368709120
```

Estas variables están incluidas en `ManagedRuntimeEnvironment` y en la allowlist del helper administrativo; por tanto pueden administrarse desde Configuración avanzada del servidor.

## S3 y CORS

Los objetos permanecen privados. FederationDrop no usa `public-read`.

Para que el navegador pueda ejecutar el PUT prefirmado directamente contra S3, el bucket debe permitir CORS desde el origen web del nodo comercial.

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

Cuando pago y subida han quedado confirmados, SMTP envía al propietario:

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

La colocación automática en proveedores externos **está deshabilitada en la fase 1**. Tener una fila `FederationCommercialProviders` no autoriza a que un proveedor se lleve la única copia.

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
- contrato de checkout neutral;
- webhook firmado e idempotente;
- upload directo a S3 privado;
- validación de tamaño del objeto;
- enlaces públicos;
- límite transaccional de canjes;
- borrado;
- expiración automática;
- correo;
- ArcadeLink v3;
- badge para dominios externos;
- esquema de proveedores/porcentajes/garantías.

Pendiente deliberadamente de seleccionar proveedor:

- integración concreta con Stripe, PayPal, Mercado Pago u otra pasarela;
- liquidación automática a proveedores comerciales;
- asignación automática de objetos pagados a proveedores externos;
- réplica comercial dual automática.

Esas tres últimas funciones no deben activarse antes de tener la garantía de disponibilidad implementada.
