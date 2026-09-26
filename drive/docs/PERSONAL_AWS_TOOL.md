# Herramientas AWS personales

`drive/aws.php` y `drive/ec2.php` son herramientas privadas separadas de las funciones familiares del Drive.

## Política de acceso

- Con sesión autenticada del Drive, únicamente `user_id = 1` puede utilizarlas.
- Cualquier otro usuario autenticado recibe HTTP 403.
- Sin sesión del Drive, se requiere la contraseña privada configurada fuera del repositorio.
- `ec2.php` vuelve a pedir la misma contraseña privada para autorizar acciones de encendido o apagado.
- Las contraseñas y las semillas TOTP no se almacenan en Git ni dentro del webroot.

## Arquitectura de acceso y TOTP

`aws.php` y `ec2.php` usan un bootstrap mínimo independiente de MySQL. Así, la contraseña privada y las funciones AWS personales siguen disponibles aunque la base de datos del Drive esté desconectada. Si existe una sesión normal del Drive, el control de propietario (`user_id = 1`) continúa aplicándose desde la sesión PHP.

```text
aws.php
  -> PersonalAwsController
     -> PersonalToolAccessService
     -> PersonalTotpService
        -> PersonalAwsConfig
     -> PersonalAwsPageRenderer

ec2.php
  -> PersonalToolAccessService
  -> PersonalAwsConfig
  -> panel EC2
```

Las semillas TOTP permanecen en el servidor. El navegador recibe identificadores y etiquetas de cuenta y, cuando se solicita, el código TOTP generado por el servidor.

La misma `password_hash` privada que desbloquea estas herramientas se verifica con `password_verify()` antes de ejecutar `start` o `stop` en `ec2.php`.

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
  "password_hash": "$2y$...HASH_DE_ACCESO...",
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

Los hashes se generan en el servidor mediante `password_hash()` y las contraseñas reales no se documentan.
