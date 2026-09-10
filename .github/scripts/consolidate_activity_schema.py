from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCHEMA = ROOT / 'adbbmis1_Cloud.sql'
README = ROOT / 'README.md'
DOC = ROOT / 'drive/docs/ACTIVITY_COSTS.md'
WORKFLOW = ROOT / '.github/workflows/activity-costs.yml'

OBSOLETE = [
    ROOT / 'drive/database/migrations/20260910_activity_costs.sql',
    ROOT / 'drive/bin/db_migrate.php',
    ROOT / 'drive/src/Console/SqlMigrationCommand.php',
]

TABLE_BLOCK = r'''--
-- Estructura de tabla para la tabla `DriveActivityEvents`
--

DROP TABLE IF EXISTS `DriveActivityEvents`;
CREATE TABLE IF NOT EXISTS `DriveActivityEvents` (
  `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id_` int NOT NULL,
  `actor_user_id_` int NOT NULL,
  `Action` varchar(64) NOT NULL,
  `Service` varchar(64) NOT NULL,
  `FileId` int DEFAULT NULL,
  `UnitsJson` text DEFAULT NULL,
  `EstimatedCost` decimal(20,10) DEFAULT NULL,
  `Currency` char(3) NOT NULL DEFAULT 'USD',
  `PriceSource` varchar(255) NOT NULL,
  `PricingState` varchar(16) NOT NULL DEFAULT 'unpriced',
  `Status` varchar(16) NOT NULL DEFAULT 'ok',
  `DurationMs` int UNSIGNED DEFAULT NULL,
  `CorrelationId` varchar(96) DEFAULT NULL,
  `MetadataJson` text DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_`),
  KEY `idx_drive_activity_user_date` (`user_id_`,`CreatedAt`),
  KEY `idx_drive_activity_user_service_date` (`user_id_`,`Service`,`CreatedAt`),
  KEY `idx_drive_activity_user_action_date` (`user_id_`,`Action`,`CreatedAt`),
  KEY `idx_drive_activity_actor_date` (`actor_user_id_`,`CreatedAt`),
  UNIQUE KEY `uq_drive_activity_correlation` (`user_id_`,`Action`,`Service`,`CorrelationId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

'''


def update_schema() -> None:
    text = SCHEMA.read_text(encoding='utf-8')
    if 'CREATE TABLE IF NOT EXISTS `DriveActivityEvents`' not in text:
        marker = '--\n-- Estructura de tabla para la tabla `FileVersions`\n--'
        if marker not in text:
            raise SystemExit('No se encontró el punto de inserción antes de FileVersions.')
        text = text.replace(marker, TABLE_BLOCK + marker, 1)
        SCHEMA.write_text(text, encoding='utf-8')


def update_readme() -> None:
    text = README.read_text(encoding='utf-8')
    note = 'Consulta `drive/docs/ACTIVITY_COSTS.md`.\n'
    replacement = (
        'La tabla `DriveActivityEvents` forma parte del esquema base `adbbmis1_Cloud.sql`; '
        'una instalación nueva crea el módulo de actividad junto con el resto de la base de datos, '
        'sin ejecutar una migración incremental separada.\n\n'
        'Consulta `drive/docs/ACTIVITY_COSTS.md`.\n'
    )
    if replacement not in text:
        if note not in text:
            raise SystemExit('No se encontró el ancla de Actividad y costos en README.')
        text = text.replace(note, replacement, 1)
    text = text.replace('    │   ├── db_migrate.php\n', '')
    text = text.replace('    ├── database/\n    │   └── migrations/\n', '')
    README.write_text(text, encoding='utf-8')


def update_doc() -> None:
    text = DOC.read_text(encoding='utf-8')
    start = text.find('## Base de datos\n')
    end = text.find('## Multiusuario y autorización\n')
    if start < 0 or end < 0 or end <= start:
        raise SystemExit('No se encontró la sección Base de datos de ACTIVITY_COSTS.md.')
    new_section = '''## Base de datos

`DriveActivityEvents` forma parte del esquema maestro:

```text
adbbmis1_Cloud.sql
```

Una instalación nueva de ArcadeCloud Drive crea esta tabla al cargar el SQL general, junto con el resto del esquema. No existe un paso de migración incremental separado para Actividad y costos.

Los campos almacenan identidad de usuario/actor, operación, servicio, referencia opcional a `FileS3.id_`, unidades de consumo, costo estimado, moneda, origen de precio, estado de tasación, estado de la operación, duración opcional, correlación hash y metadatos mínimos.

Índices principales:

- `user_id_ + CreatedAt`;
- `user_id_ + Service + CreatedAt`;
- `user_id_ + Action + CreatedAt`;
- `actor_user_id_ + CreatedAt`.

La tabla no contiene la key física S3, presigned URLs, tokens, credenciales, contraseñas, TOTP ni IDs de sesión.

Para una instalación limpia se importa `adbbmis1_Cloud.sql` una sola vez. Las instalaciones existentes que ya tengan `DriveActivityEvents` no necesitan volver a crearla.

'''
    text = text[:start] + new_section + text[end:]
    DOC.write_text(text, encoding='utf-8')


def update_workflow() -> None:
    text = WORKFLOW.read_text(encoding='utf-8')
    drop_tokens = (
        'drive/bin/db_migrate.php',
        'drive/database/migrations',
        'drive/src/Console/SqlMigrationCommand.php',
    )
    text = ''.join(
        line for line in text.splitlines(keepends=True)
        if not any(token in line for token in drop_tokens)
    )

    push_anchor = "      - '.github/workflows/activity-costs.yml'\n"
    first_workflow = text.find(push_anchor)
    if first_workflow < 0:
        raise SystemExit('No se encontró ancla push de activity-costs.yml.')
    push_prefix = text[:first_workflow]
    if "      - 'adbbmis1_Cloud.sql'\n" not in push_prefix:
        text = text[:first_workflow] + "      - 'adbbmis1_Cloud.sql'\n" + text[first_workflow:]

    old_pr = "  pull_request:\n    paths:\n      - 'drive/**'\n      - '.github/workflows/activity-costs.yml'\n"
    new_pr = "  pull_request:\n    paths:\n      - 'drive/**'\n      - 'adbbmis1_Cloud.sql'\n      - '.github/workflows/activity-costs.yml'\n"
    if old_pr in text:
        text = text.replace(old_pr, new_pr, 1)
    elif new_pr not in text:
        raise SystemExit('No se encontró bloque pull_request esperado.')

    old_guard = "          grep -q 'CREATE TABLE IF NOT EXISTS `DriveActivityEvents`' drive/database/migrations/20260910_activity_costs.sql\n"
    new_guard = (
        "          grep -q 'CREATE TABLE IF NOT EXISTS `DriveActivityEvents`' adbbmis1_Cloud.sql\n"
        "          grep -q 'DROP TABLE IF EXISTS `DriveActivityEvents`' adbbmis1_Cloud.sql\n"
    )
    if old_guard in text:
        text = text.replace(old_guard, new_guard, 1)
    elif "CREATE TABLE IF NOT EXISTS `DriveActivityEvents`' adbbmis1_Cloud.sql" not in text:
        anchor = "          grep -q 'Cost Explorer' drive/src/Activity/ActivityCostService.php\n"
        if anchor not in text:
            raise SystemExit('No se encontró ancla para guard del esquema maestro.')
        text = text.replace(anchor, anchor + new_guard, 1)

    WORKFLOW.write_text(text, encoding='utf-8')


def remove_obsolete() -> None:
    for path in OBSOLETE:
        if path.exists():
            path.unlink()


update_schema()
update_readme()
update_doc()
update_workflow()
remove_obsolete()

schema_text = SCHEMA.read_text(encoding='utf-8')
if schema_text.count('CREATE TABLE IF NOT EXISTS `DriveActivityEvents`') != 1:
    raise SystemExit('DriveActivityEvents debe existir exactamente una vez en el esquema maestro.')
if 'DROP TABLE IF EXISTS `DriveActivityEvents`' not in schema_text:
    raise SystemExit('Falta DROP TABLE de DriveActivityEvents en el esquema maestro.')

for path in OBSOLETE:
    if path.exists():
        raise SystemExit(f'Archivo obsoleto aún existe: {path.relative_to(ROOT)}')

print('Activity cost schema consolidated into adbbmis1_Cloud.sql')
