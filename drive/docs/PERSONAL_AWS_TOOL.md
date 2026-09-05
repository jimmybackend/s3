# Herramienta AWS personal

`drive/aws.php` es una herramienta privada separada de las funciones familiares del Drive.

## Política de acceso

- Con sesión autenticada del Drive, únicamente `user_id = 1` puede usarla.
- Cualquier otro usuario autenticado recibe HTTP 403.
- Sin sesión del Drive, se requiere la contraseña privada configurada fuera del repositorio.
- La contraseña y las semillas TOTP no se almacenan en Git ni dentro del webroot.

## Arquitectura

```text
aws.php
  -> PersonalAwsController
     -> PersonalToolAccessService
     -> PersonalTotpService
        -> PersonalAwsConfig
     -> PersonalAwsPageRenderer
```

Las semillas TOTP permanecen en el servidor. El navegador recibe identificadores y etiquetas de cuenta y, cuando se solicita, el código TOTP generado por el servidor.

## Configuración privada

Ruta predeterminada:

```text
/etc/arcadecloud-drive/personal-aws.json
```

Ruta alternativa:

```text
ARCADECLOUD_PERSONAL_AWS_CONFIG=/ruta/privada/personal-aws.json
```

Formato:

```json
{
  "password_hash": "$2y$...HASH_GENERADO_EN_EL_SERVIDOR...",
  "issuer": "ArcadeCloud",
  "accounts": {
    "aws-main": {
      "label": "AWS principal",
      "secret": "TOTP_SECRET_AQUI",
      "note": ""
    }
  }
}
```

Permisos recomendados:

```text
root:nginx 0640
```

El hash de contraseña se genera en el servidor mediante `password_hash()` y la contraseña real no se documenta.
