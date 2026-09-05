# Herramienta AWS personal

`drive/aws.php` es una herramienta personal y temporal. No forma parte de las funciones compartidas del Drive familiar.

## Política de acceso

- Si existe una sesión autenticada del Drive, únicamente `user_id = 1` puede usar la herramienta.
- Una sesión autenticada de cualquier otro usuario recibe HTTP 403 y no puede usar la contraseña alternativa.
- Sin sesión del Drive, puede utilizarse la contraseña privada configurada fuera del repositorio.
- La contraseña y las semillas TOTP nunca deben almacenarse en GitHub ni dentro del webroot.

## Arquitectura

```text
aws.php
  -> PersonalAwsController
      -> PersonalToolAccessService
      -> PersonalTotpService
          -> PersonalAwsConfig
      -> PersonalAwsPageRenderer
```

Las semillas TOTP permanecen en el servidor. El navegador recibe únicamente un identificador de cuenta, la etiqueta y, después de solicitarlo, el código TOTP generado.

## Archivo privado

Por defecto se lee:

```text
/etc/arcadecloud-drive/personal-aws.json
```

Puede cambiarse mediante:

```text
ARCADECLOUD_PERSONAL_AWS_CONFIG=/ruta/privada/personal-aws.json
```

Formato esperado, usando únicamente valores de ejemplo:

```json
{
  "password_hash": "$2y$...HASH_GENERADO_EN_EL_SERVIDOR...",
  "issuer": "ArcadeCloud",
  "accounts": {
    "aws-main": {
      "label": "AWS principal",
      "secret": "TOTP_SECRET_AQUI",
      "note": ""
    },
    "correo-personal": {
      "label": "Correo personal",
      "secret": "TOTP_SECRET_AQUI",
      "note": "Nota privada opcional"
    }
  }
}
```

Permisos recomendados:

```text
root:nginx 0640
```

El hash de la contraseña debe generarse en el servidor con `password_hash()`; nunca se documenta la contraseña real.

## Seguridad

Versiones históricas de `aws.php` contenían material TOTP dentro del código. Eliminarlo del HEAD evita nuevas exposiciones, pero no lo elimina del historial Git. Las semillas históricamente versionadas deben considerarse conocidas por cualquier actor con acceso al repositorio y conviene rotarlas cuando sea posible.
