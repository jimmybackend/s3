# FederationCloud: identidad del nodo y búsqueda global

Estado: el renombre administrativo seguro de `node_name` se implementa en esta fase. La búsqueda global, el índice distribuido y las solicitudes de acceso descritas aquí son roadmap y todavía no deben tratarse como funcionalidad disponible.

## 1. Separar identidad, nombre y localización

FederationCloud debe tratar como conceptos distintos:

- `node_id`: identidad criptográfica inmutable derivada de la clave pública Ed25519. Nunca se renombra.
- `node_name`: etiqueta pública firmada y legible. Puede ser un nombre como `drive.esforzados.com`, `servidor-mexico-01` o una IP pública legible como `69.6.201.239`.
- `public_url`: entrada pública humana del nodo.
- `federation_url`: endpoint HTTPS usado para protocolo nodo-a-nodo.
- dirección IP resuelta: dato de transporte observado durante una conexión; no sustituye al Node ID y no concede confianza.

El nombre del nodo no debe editarse directamente en MySQL. El flujo administrativo cambia primero la etiqueta dentro de la identidad local, vuelve a firmar el descriptor conservando las mismas claves y el mismo `node_id`, y sincroniza `FederationNodes` únicamente después de validar el descriptor firmado.

Los nodos remotos pueden anunciar un nombre nuevo siempre que conserven exactamente el mismo Node ID, clave pública y URLs verificadas. El cambio de nombre no crea una identidad nueva.

## 2. Administración desde el footer

Sólo una sesión con `Users.system_role = 'superadmin'` puede abrir el editor de identidad del nodo.

El footer muestra:

```text
Nodo: drive.esforzados.com ✎ · Nodos conectados: N · Solicitudes: N
```

El modal administrativo permite modificar únicamente `node_name`. Muestra como sólo lectura:

- Node ID criptográfico;
- Public URL;
- Federation URL.

El POST exige sesión autenticada, `superadmin` y token CSRF. No existe una operación web para reemplazar la clave privada, la clave pública, `payload_key` o el Node ID.

## 3. Nodos sin dominio

Un servidor cloud puede usar una IP pública como `node_name` para identificación humana, pero eso no convierte por sí solo la IP en un endpoint federado.

La ruta real continúa siendo `federation_url`, y FederationCloud mantiene la exigencia de HTTPS y las protecciones SSRF. No se debe desactivar TLS para aceptar nodos por IP.

Para un nodo cuyo endpoint use directamente una IP pública, el certificado HTTPS debe ser válido para ese endpoint. Otra opción es colocar un proxy/hostname TLS delante del servidor y conservar la IP únicamente como etiqueta u observación de transporte.

No se debe considerar confiable un nodo porque una IP, dominio o nombre coincida. La confianza viene de la firma Ed25519 y del Node ID verificado.

## 4. Búsqueda local y búsqueda global

### Búsqueda local

La búsqueda local sigue usando MySQL como fuente de verdad. No debe listar S3 para buscar archivos cotidianos.

```text
consulta
 -> catálogo MySQL local
 -> FileS3 / metadatos locales
 -> resultados del usuario autenticado
```

### Búsqueda global

No se deben consultar secuencialmente miles de nodos. El diseño escalable es:

```text
consulta del usuario
 -> índice federado de metadatos permitidos
 -> lista pequeña de candidatos
 -> ranking
 -> verificación paralela de nodos candidatos
 -> resultados firmados
```

La interfaz puede mostrar progreso real, por ejemplo:

```text
Consultando índice federado…
37 recursos candidatos
Verificando 8 nodos…
5 nodos respondieron
```

En una red grande, `buscando nodo 1 de 10000` sería un modelo ineficiente si realmente implicara contactar los 10,000 servidores.

## 5. Privacidad: nunca indexar privados por defecto

El usuario debe elegir expresamente qué metadatos salen de su nodo.

La política de búsqueda futura debe estar separada de la propiedad física del archivo. Propuesta:

- `local_only`: no se anuncia fuera del nodo.
- `public_metadata`: puede aparecer en búsqueda global y el recurso puede ser público según sus derechos.
- `requestable_metadata`: el archivo continúa privado, pero el propietario autoriza publicar metadatos mínimos para que otros sepan que existe y puedan solicitar acceso.

`requestable_metadata` no debe publicar:

- correo del propietario;
- nombre civil si no fue autorizado;
- ruta S3;
- bucket;
- key física;
- credenciales;
- payload privado del ArcadeLink;
- contenido del archivo.

La visibilidad de ArcadeLink (`PUBLIC`, `UNLISTED`, `PRIVATE`) y la política futura de descubrimiento deben permanecer conceptualmente separadas. Un archivo privado no debe volverse público por el simple hecho de aceptar solicitudes.

## 6. Solicitar un archivo privado

Flujo propuesto:

```text
usuario A busca globalmente
 -> encuentra metadatos solicitables en nodo B
 -> Solicitar acceso
 -> solicitud firmada nodo A -> nodo B
 -> nodo B identifica al propietario local
 -> notificación interna y/o correo SMTP
 -> propietario acepta o rechaza
 -> si acepta, nodo B crea un acceso temporal o ArcadeLink adecuado
```

El correo real del propietario nunca viaja al nodo solicitante. El envío de correo se realiza en el nodo propietario mediante la configuración SMTP local.

Debe existir rate limiting y protección contra spam de solicitudes.

## 7. Carpeta Shares

Cuando se implemente el área compartida por usuario, la carpeta lógica `Shares/` debe pertenecer al modelo DB-first:

- MySQL conserva la fuente de verdad para navegación y pertenencia;
- S3 sigue siendo almacenamiento físico;
- los archivos continúan perteneciendo a `FileS3` del usuario correspondiente;
- la publicación, derechos y localizaciones federadas deben vivir en metadatos de sharing/FederationCloud, no convirtiendo indiscriminadamente toda fila `FileS3` en pública.

`FederatedResources` es el lugar natural para mantener el descriptor público/indexable de un recurso, mientras `FederationResourceLocations` puede indicar qué nodos poseen una ubicación válida del recurso.

## 8. Ranking de nodos cercanos

"Cercano" debe significar principalmente cercanía de red y calidad de servicio, no ubicación física inferida de una IP.

Factores futuros de ranking:

1. coincidencia semántica/nombre/metadatos del recurso;
2. nodo origen o proveedor autorizado;
3. latencia observada;
4. disponibilidad reciente;
5. integridad verificada (`content_id` cuando proceda);
6. política de derechos y acceso;
7. carga/costo del nodo, si el operador decide anunciarlo.

No es necesario almacenar ciudad, domicilio o geolocalización de un nodo para elegir una ruta eficiente.

## 9. APIs futuras

Posibles endpoints, aún no implementados:

```text
GET/POST /federationcloud/search.php
POST     /federationcloud/access-request.php
GET      /federationcloud/access-requests.php
POST     /federationcloud/access-decision.php
```

Los resultados y solicitudes sensibles deben firmarse. Las búsquedas públicas deben tener límites, paginación, rate limiting y fan-out máximo.

## 10. Principio rector

FederationCloud no debe convertirse en una base central que copie la vida privada de cada usuario. Debe ser una red en la que cada nodo conserva sus datos y decide qué conocimiento anuncia.

La identidad responde **quién es el nodo**.

El directorio responde **cómo contactar al nodo**.

El índice responde **qué recursos ha decidido anunciar**.

La autorización responde **quién puede obtenerlos**.

Esas cuatro capas no deben mezclarse.

## 11. Regla de namespace `DataN` para múltiples nubes y nodos

`Data`, `Data2`, `Data3` ... `DataN` identifica el namespace local de un usuario dentro de un ArcadeCloud. **No es una identidad global de FederationCloud y no identifica por sí solo a una persona entre nodos.**

Dentro de un mismo nodo, el mismo usuario conserva el mismo namespace en todos los proveedores de almacenamiento configurados:

```text
Usuario local 1
AWS    -> Data/
Google -> Data/
Azure  -> Data/

Usuario local 2
AWS    -> Data2/
Google -> Data2/
Azure  -> Data2/
```

La interfaz puede mostrar etiquetas como `Data · AWS`, `Data · Google` y `Data · Azure` para orientar al usuario, pero la etiqueta del proveedor no debe introducirse dentro del prefijo físico `DataN`.

Cada nodo independiente reinicia su propia asignación local según su organización y orden de alta. Por ejemplo, dos nodos diferentes pueden tener ambos un `Data/` y un `Data2/`. Eso es válido porque su identidad real está separada por el nodo y por el almacenamiento.

```text
drive.esforzados.com / bucket-central
  Data/
  Data2/

ventas.esforzados.com / bucket-ventas
  Data/
  Data2/
```

**Regla de no colisión:** dos nodos independientes no pueden asignar el mismo `DataN` a usuarios distintos dentro del mismo namespace físico de almacenamiento. Si otro sector o nodo vuelve a comenzar desde `Data/`, debe usar otro bucket, container o raíz física independiente. No se permite que dos catálogos independientes interpreten el mismo `bucket + DataN/` como propietarios diferentes.

Por tanto, una localización federada debe identificarse conceptualmente por una combinación equivalente a:

```text
node_id
+ storage_id / provider
+ bucket | container | raíz física
+ user_namespace (DataN)
+ object_key
```

El mismo usuario puede ser `Data/` en un nodo y `Data3/` en otro. FederationCloud debe relacionarlo mediante identidad y permisos federados, nunca suponiendo que dos prefijos `DataN` iguales representan a la misma persona.

Para búsqueda y transferencia entre nubes, la política será de **afinidad primero y fallback después**: se intenta primero una localización compatible con el proveedor preferido del nodo solicitante; si el recurso no existe allí, se consultan las demás localizaciones autorizadas. Una transferencia entre proveedores conserva el namespace del usuario en el nodo destino, pero cambia únicamente la localización física.

Ejemplo:

```text
origen:  nodo A / Google / Data2/Trabajo/informe.pdf
destino: nodo B / AWS    / Data/Trabajo/informe.pdf
```

Los prefijos pueden ser distintos entre nodos porque son locales. Lo que debe conservarse es la identidad autorizada del recurso y del usuario, no el número de `DataN` entre instalaciones independientes.

Esta regla es de arquitectura para la futura capa multi-cloud. No implica que Google Cloud Storage o Azure Blob estén implementados actualmente.
