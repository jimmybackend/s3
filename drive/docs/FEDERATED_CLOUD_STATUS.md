# Estado actual: ArcadeCloud Drive + FederationCloud

Fecha: **10 de septiembre de 2026**.

ArcadeCloud Drive evolucionó de un gestor web para Amazon S3 a una **plataforma de almacenamiento con capa de cloud federado**.

El almacenamiento local de cada instalación sigue respetando los principios originales:

- MySQL es la fuente de verdad para navegación y metadatos;
- Amazon S3 conserva los objetos físicos;
- cada operación local permanece aislada por `user_id_`;
- S3 no se lista durante la navegación normal;
- la lógica de aplicación sigue la arquitectura `Entrypoint -> Controller -> Service -> Repository / Infrastructure`.

Sobre esa base ahora existe **FederationCloud**, que permite que varias instalaciones de ArcadeCloud Drive tengan identidad propia y se reconozcan entre sí sin compartir claves privadas ni publicar rutas físicas de S3.

## Qué está funcionando

### Identidad de nodo

Cada instalación puede generar una identidad Ed25519 independiente:

```text
node_name
node_id = acn_...
public_key
public_url
federation_url
```

La identidad privada permanece fuera del repositorio y fuera del DocumentRoot.

### Descubrimiento y confianza entre nodos

Los nodos publican un descriptor firmado mediante `node.php`. Un nodo puede registrarse ante un seed y otro nodo puede verificarlo en vivo antes de persistirlo.

La autorización de proveedor está separada de la identidad. Un nodo registrado no se convierte automáticamente en proveedor.

Los proveedores solicitan acceso mediante un descriptor firmado y el nodo origen valida:

- Node ID;
- clave pública;
- firma Ed25519;
- nombre del nodo;
- URL pública y Federation URL;
- respuesta HTTPS del `node.php` anunciado.

La aprobación final pertenece a un `superadmin` del nodo origen.

### ArcadeLink

`.arcadelink` es el pasaporte portable y firmado de un recurso.

Actualmente puede:

1. crearse directamente desde el botón **Compartir** de un archivo del Drive;
2. conservar `resource_id`, procedencia, visibilidad y derechos;
3. proteger su referencia local dentro de un payload XChaCha20-Poly1305;
4. firmarse con Ed25519;
5. descargarse como archivo portable;
6. cargarse mediante el dropzone de `/federationcloud/`;
7. verificarse criptográficamente;
8. resolverse contra el nodo origen;
9. abrirse mediante el mecanismo temporal de sharing del Drive cuando la política lo permite.

La interfaz de FederationCloud usa un flujo simple: dropzone -> validación automática -> recurso resuelto -> **Abrir**. El usuario puede seleccionar **Validar otro ArcadeLink** para reiniciar el flujo.

### Proveedor real probado

La arquitectura ya fue probada con dos instalaciones distintas:

```text
Nodo origen
  drive.esforzados.com
  node_name = jimmybackend

Nodo proveedor de prueba
  fastdrive.esforzados.com
  node_name = fastdrive
```

El segundo nodo generó su propia identidad, publicó su `node.php` por HTTPS, solicitó autorización y fue aprobado desde el nodo origen.

Esto demuestra la capa de identidad, descubrimiento, verificación y autorización entre nodos.

## Qué significa “cloud federado” en esta etapa

ArcadeCloud ya no depende conceptualmente de una sola instalación como única identidad del sistema. Varias instalaciones pueden operar como nodos independientes y establecer relaciones verificables entre sí.

La federación actual cubre:

```text
identidad criptográfica
  + descubrimiento de nodos
  + autorización origen -> proveedor
  + ArcadeLink portable
  + resolución entre nodos
```

Todavía **no** significa:

- P2P o BitTorrent;
- replicación automática de objetos;
- escritura remota sobre el S3 de otro nodo;
- buscador global de todos los recursos;
- selección automática del mejor proveedor por recurso;
- mirror automático por SHA-256.

Esas capacidades pertenecen a fases posteriores.

## Frontera de seguridad

FederationCloud no debe convertir la federación en acceso compartido a secretos.

Nunca se publica en un ArcadeLink ni en el directorio federado:

- `AWS_ACCESS_KEY_ID`;
- `AWS_SECRET_ACCESS_KEY`;
- contraseñas MySQL;
- cookies o sesiones;
- claves privadas Ed25519;
- `payload_key`;
- rutas privadas permanentes de S3.

Un proveedor autorizado tampoco obtiene por ese hecho permiso de escritura sobre MySQL o S3.

## Arquitectura conceptual actual

```text
                         ArcadeLink
                            |
                            v
+------------------+   HTTPS firmado   +------------------+
| Nodo ArcadeCloud | <---------------> | Nodo ArcadeCloud |
|                  |                   |                  |
| Identidad        |                   | Identidad        |
| Ed25519          |                   | Ed25519          |
+--------+---------+                   +---------+--------+
         |                                       |
         v                                       v
    MySQL + S3                              MySQL + S3
    locales                                 locales
```

Cada nodo conserva autonomía local; FederationCloud añade identidad, confianza y resolución entre instalaciones.

## Próxima etapa

El siguiente salto es convertir la autorización de proveedor en una ruta de entrega efectiva por recurso:

1. persistir recursos federados en `FederatedResources`;
2. anunciar ubicaciones en `FederationResourceLocations`;
3. seleccionar origen/proveedor para servir un recurso;
4. habilitar búsqueda de mirrors por `content_id` cuando exista;
5. permitir `Guardar en mi Drive` sólo cuando los derechos lo autoricen;
6. soportar revocación de ubicaciones y autorizaciones sin invalidar la identidad histórica del recurso.

Hasta entonces, `FileS3` continúa siendo la fuente de verdad local y FederationCloud permanece como una capa federada sobre ArcadeCloud Drive, no como sustituto de MySQL o S3.
