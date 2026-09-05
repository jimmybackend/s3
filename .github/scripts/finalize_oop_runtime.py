from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one match, got {count}")
    return text.replace(old, new, 1)


# ------------------------------------------------------------------
# s3.php: quitar la última consulta de carpetas vía S3Manager.
# ------------------------------------------------------------------
s3_path = Path('drive/s3.php')
s3 = s3_path.read_text(encoding='utf-8')
s3_old = """$todasLasCarpetas = $app->s3Manager()->listarCarpetasDesdeDb(
    $userId,
    $userRoot,
    true
);"""
s3_new = "$todasLasCarpetas = $app->folderQueryService()->allForUser($userId, true);"
s3 = replace_once(s3, s3_old, s3_new, 's3 folder query')
s3_path.write_text(s3, encoding='utf-8')


# ------------------------------------------------------------------
# up.php: UI se conserva; SQL/S3 de orquestación sale al servicio.
# ------------------------------------------------------------------
up_path = Path('drive/up.php')
up = up_path.read_text(encoding='utf-8')
start = "$db = $app->db();\n"
marker = "// ========================== UI ==========================\n"
if start not in up or marker not in up:
    raise SystemExit('up.php markers not found')

prefix, remainder = up.split(start, 1)
_old_logic, suffix = remainder.split(marker, 1)
new_logic = r'''$targetUserId = (int)(
    $_POST['target_user_id']
    ?? $_GET['target_user_id']
    ?? 0
);

$adminUpload = $app->adminMultipartUploadService();
$targetUser = $targetUserId > 0
    ? $adminUpload->targetUser($targetUserId)
    : null;
$users = $adminUpload->users();

$action = trim((string)($_POST['action'] ?? ''));
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($targetUser === null) {
            throw new RuntimeException('Debes seleccionar el usuario destino.');
        }

        $result = $adminUpload->handle(
            $actorUserId,
            $targetUserId,
            $action,
            $_POST,
            $_FILES
        );

        echo json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(
            ['error' => $error->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
    exit;
}

'''
up = prefix + new_logic + marker + suffix
up_path.write_text(up, encoding='utf-8')


# ------------------------------------------------------------------
# ec2.php: quitar clientes SDK/clases internas; usar gateways OOP.
# ------------------------------------------------------------------
ec2_path = Path('drive/ec2.php')
ec2 = ec2_path.read_text(encoding='utf-8')

import_anchor = "use Aws\\Exception\\AwsException;\n"
imports = (
    "use ArcadeCloud\\Drive\\Aws\\Ec2Gateway;\n"
    "use ArcadeCloud\\Drive\\Aws\\RdsGateway;\n"
)
if imports not in ec2:
    ec2 = replace_once(ec2, import_anchor, imports + import_anchor, 'ec2 imports')

clients_marker = "// ===================== Clientes AWS =====================\n"
helpers_marker = "// ===================== Funciones auxiliares =====================\n"
if clients_marker not in ec2 or helpers_marker not in ec2:
    raise SystemExit('ec2 client markers not found')

before, rest = ec2.split(clients_marker, 1)
_old_clients, after = rest.split(helpers_marker, 1)
ec2 = before + clients_marker + helpers_marker + after

ec2 = ec2.replace('new EC2Panel($region)', '$app->ec2Gateway($region)')
ec2 = ec2.replace('new RDSPanel($region)', '$app->rdsGateway($region)')

if 'new EC2Panel(' in ec2 or 'new RDSPanel(' in ec2:
    raise SystemExit('ec2 legacy panel constructors remain')

# Usar el gateway para normalización/estado de RDS en los bloques AJAX.
ec2 = ec2.replace(
    "echo json_encode(['ok'=>true] + normalize_database_target($id, $target));",
    "echo json_encode(['ok'=>true] + $panel->normalizeTarget($id, $target));"
)
ec2 = ec2.replace(
    "$status = database_status_from_target($target);",
    "$status = $panel->statusFromTarget($target);"
)
ec2 = ec2.replace(
    "if (!is_database_startable($status)) {",
    "if (!$panel->isStartable($status)) {"
)
ec2 = ec2.replace(
    "if (!is_database_stoppable($status)) {",
    "if (!$panel->isStoppable($status)) {"
)

ec2_path.write_text(ec2, encoding='utf-8')

print('FINAL_RUNTIME_REFACTOR_OK')
