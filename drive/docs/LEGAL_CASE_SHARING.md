> Modalidad actual por días: [LEGAL_TIMED_LINKS.md](LEGAL_TIMED_LINKS.md). Genera la URL habitual del escritorio y registra automáticamente el documento en Legal; 365 días por defecto. Las delegaciones privadas previas se conservan.

# Delegación expresa de archivos para expedientes de Legal

La API personal sigue restringida al propietario de Drive configurado. El propietario puede ahora autorizar por POST un archivo para un expediente concreto y una audiencia de servidor `case-service`. Esa autorización no cambia archivos, carpetas, visibilidad pública, propietario ni permisos del escritorio de Drive.

## Datos y seguridad

Aplicar manualmente `drive/migrations/20261109_legal_file_grants.sql` en la base existente de Drive, también en instalaciones nuevas que necesiten la función. No importar el dump completo. La tabla nueva `legal_file_grants` guarda IDs estables de archivo/propietario, ID del grant personal autorizante, audiencia de servicio, ID externo del expediente, referencia aleatoria de 32 bytes y fechas. No se conecta a la base de Legal ni usa claves foráneas entre aplicaciones. El repositorio no crea tablas en peticiones HTTP.

El scope predeterminado sigue siendo `owner`, para compatibilidad con las cuentas actuales. `case-service` no acepta `user_id` y no puede explorar, consultar archivos por ID arbitrario, autorizar ni revocar. Solo admite `shared_file`, `shared_files` y `shared_access` con su referencia de autorización y expediente exacto. Las referencias también necesitan el bearer del servicio: no son enlaces públicos.

Drive revalida scope, audiencia, expediente, referencia activa, grant personal autorizante y propietario activo, archivo y ancestros desbloqueados en cada solicitud y canje. Los tickets están cifrados y vinculados al archivo, expediente, referencia y credencial de servicio; duran 120 segundos. Una revocación bloquea tickets pendientes. Reautorizar rota la referencia para no reactivar tickets antiguos. El servicio no permite escritura sobre los originales.

Legal es el servidor de confianza que comprueba su sesión, CSRF y autorización actual por expediente. Drive no puede consultar los permisos de otra base. No compartir el token de servicio con navegadores ni usarlo como token personal. Los superadmins de Legal pueden consumir archivos compartidos, pero no consentir por otro propietario. Los clientes no reciben acceso con esta función.

## Configuración de EC2

1. Actualizar `src/Integration/LegalApiConfig.php`, `LegalDriveService.php`, **nuevo** `LegalFileGrantRepository.php` y `src/Http/Controller/LegalDriveController.php` dentro de `drive`.
2. Respaldar la base y ejecutar la migración con la conexión local real. No cambiar la conexión ni las tablas de archivos/usuarios.
3. Desde la raíz del checkout:

```bash
sudo python3 drive/bin/provision_legal_case_service.py
sudo -u nginx test -r /etc/arcadecloud-drive/legal-api.json && echo 'Configuración legible por PHP'
```

Si se está dentro de `/var/www/arcadecloud-drive/drive`, usar `bin/provision_legal_case_service.py`. Si utiliza otra ruta en `ARCADECLOUD_LEGAL_API_CONFIG`, pasar `--config /ruta/privada/legal-api.json`. La utilidad exige configuración existente válida, conserva identidad y grants personales, crea respaldo 600 y escribe atómicamente con permisos 640 y propietario/grupo existentes. No modifica servicios ni infraestructura.

Añade `legal-case-service` con scope `case-service`, hash de un secreto nuevo y vencimiento de 365 días. El secreto impreso debe incorporarse **solo** a `drive.service` del archivo privado activo de Legal en HostGator. No guardar esa salida en el repo, tickets, issues o registros públicos.

Para renovar: ejecutar el mismo comando con `--rotate`, conservar el ID del grant y actualizar únicamente el token del servicio en HostGator. La rotación invalida los tickets anteriores. Las autorizaciones por archivo siguen vinculadas al mismo ID de servicio; no se borran ni se necesitan compartir otra vez por una simple rotación. Los grants personales de los propietarios deben seguir activos y vigentes, con su ID estable; su renovación sigue el procedimiento de `LEGAL_INTEGRATION.md`.

## HostGator y uso

En el repositorio Legal, aplicar las migraciones `sql/ADD_DRIVE_DOCUMENTS_HOSTGATOR.sql` si falta y `sql/ADD_DRIVE_CASE_SHARES_HOSTGATOR.sql`. Actualizar sus archivos sin sobrescribir el archivo privado de base/configuración. Añadir la línea `service` impresa dentro del bloque `drive`, conservando `base_url`, `instance_id` y todas las cuentas personales. Para `.env` activo, usar `LEGAL_DRIVE_SERVICE_JSON`.

El propietario necesita una conexión personal y permiso de edición del expediente para marcar el consentimiento y pulsar **Compartir con el expediente**. Los otros administradores con consulta pueden abrir/descargar sin Drive; con edición pueden registrar el documento. **Retirar permiso compartido** requiere la conexión propietaria y consulta del expediente. Funciona desde Archivos vinculados o Documentos, y afecta ambos módulos. No publica el documento para clientes, no crea cuenta de Drive para los lectores y no comparte el inventario completo.

Guía completa de Legal: [docs/DRIVE_SHARING.md](https://github.com/jimmybackend/legal/blob/main/docs/DRIVE_SHARING.md). La fusión no ejecuta migraciones ni despliega los servidores. Los vínculos existentes requieren consentimiento del propietario. No se comparten vínculos generales de cliente: deben asignarse a un expediente.

## Operaciones de API

| Scope | Método | Acción | Campos |
|---|---|---|---|
| owner | POST | share | id del archivo, case (>0), service (ID de grant case-service) |
| owner | POST | revoke_share | share (referencia autorizada, propiedad verificada) |
| case-service | GET | shared_file | share, case exacto |
| case-service | GET | shared_files | shares (hasta 30 referencias separadas por coma), case exacto |
| case-service | GET | shared_access | share, case exacto, mode open/download |

Las operaciones POST usan cuerpo form-urlencoded y bearer, sin sesión de navegador. Los endpoints de contenido siguen siendo GET con ticket opaco. No se admiten URLs o claves S3 elegidas por el cliente.

Pruebas: `php drive/tests/legal_api_config_test.php`, `python3 drive/tests/legal_service_provision_test.py` y el flujo HTTP entre repositorios en `legal/tests/drive_integration_test.py`. El ensayo completo utiliza bases locales desechables y bytes S3 simulados; nunca llama a AWS.
