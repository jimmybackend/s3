# ArcadeLink portable ZIP

Al compartir un recurso desde ArcadeCloud Drive, FederationCloud entrega ahora un paquete ZIP portable en lugar de descargar el `.arcadelink` aislado.

Para un recurso, el ZIP contiene exactamente:

```text
ArcadeLink-portable.zip
├── ABRIR-FEDERATIONCLOUD-WINDOWS-LINUX-MAC.html
└── <recurso>.arcadelink
```

`ABRIR-FEDERATIONCLOUD-WINDOWS-LINUX-MAC.html` es un archivo HTML estándar y por eso puede abrirse con el navegador predeterminado en Windows, Linux o macOS. El HTML apunta a la `federation_url` firmada del nodo que emitió el ArcadeLink y explica que el receptor debe seleccionar o depositar el `.arcadelink` en FederationCloud.

El `.arcadelink` conserva exactamente el mismo contrato criptográfico: firma Ed25519, payload privado XChaCha20-Poly1305, `resource_id`, nodo origen, visibilidad y derechos. El ZIP no contiene credenciales AWS, cookies, sesiones, claves privadas ni una URL S3 permanente.

## Portabilidad de varios ArcadeLinks

`ArcadeLinkBundleService` acepta una colección de ArcadeLinks. Esto permite que una futura acción de compartir varios recursos genere un solo ZIP con un único lanzador HTML y tantos `.arcadelink` como recursos se incluyan:

```text
ArcadeLinks-portables.zip
├── ABRIR-FEDERATIONCLOUD-WINDOWS-LINUX-MAC.html
├── audiencia-1.mp4.arcadelink
├── audiencia-2.mp4.arcadelink
└── sentencia.pdf.arcadelink
```

Si dos recursos producen el mismo nombre de archivo, el empaquetador añade un sufijo numérico para impedir que uno sobrescriba al otro dentro del ZIP.

## Flujo de usuario

```text
Drive
  -> Compartir
  -> ArcadeLink FederationCloud
  -> elegir visibilidad / descubrimiento / derechos
  -> Descargar ArcadeLink ZIP

Receptor
  -> descomprimir ZIP
  -> abrir ABRIR-FEDERATIONCLOUD-WINDOWS-LINUX-MAC.html
  -> abrir FederationCloud en el navegador
  -> seleccionar o depositar el .arcadelink
  -> validar firma
  -> resolver / solicitar acceso / abrir según política
```

El archivo real continúa fuera del ZIP. FederationCloud resuelve el recurso mediante su ArcadeLink y aplica las políticas de acceso existentes.
