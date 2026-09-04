<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function send_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $data = [], int $code = 200): void {
    send_json(['ok' => true] + $data, $code);
}

function json_error(string $msg, int $code = 400, array $extra = []): void {
    send_json(['ok' => false, 'error' => $msg] + $extra, $code);
}

function ensure_db(mysqli $db): void {
    if ($db->connect_errno) {
        json_error('Error BD: ' . $db->connect_error, 500);
    }
    $db->set_charset('utf8mb4');
}

function current_user_id(): int {
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
        return (int)$_SESSION['user_id'];
    }
    return defined('Config::DEFAULT_USER_ID') ? (int)Config::DEFAULT_USER_ID : 0;
}

function default_receiver_id(): int {
    return defined('Config::DEFAULT_USER_ID') ? (int)Config::DEFAULT_USER_ID : 1;
}

function normalize_from(?string $v): string {
    $v = trim((string)$v);
    return $v === '' ? 'Desconocido' : mb_substr($v, 0, 80);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function get_call_row(mysqli $db, int $id): ?array {
    $sql = "SELECT id, `from`, `to`, user_id_, from_user_id, status, created_at,
                   accepted_at, rejected_at, ended_at, accepted_by, rejected_by
            FROM calls
            WHERE id = ?
            LIMIT 1";
    if (!$stmt = $db->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db->error]);
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('Error ejecutando query', 500, ['sql_error' => $err]);
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $row['from'] = normalize_from($row['from'] ?? null);
    }

    return $row ?: null;
}

const CALL_RING_TIMEOUT_SECONDS = 10;

function expire_stale_ringing(mysqli $db, int $seconds = CALL_RING_TIMEOUT_SECONDS): void {
    $sql = "UPDATE calls
            SET status = 'ended',
                ended_at = NOW()
            WHERE status = 'ringing'
              AND TIMESTAMPDIFF(SECOND, created_at, NOW()) >= ?";
    if (!$stmt = $db->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db->error]);
    }
    $stmt->bind_param('i', $seconds);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('Error expirando llamadas', 500, ['sql_error' => $err]);
    }
    $stmt->close();
}

ensure_db($db_connection);

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * CREATE
 * POST /calls.php?action=create
 * Body JSON:
 * {
 *   "from": "Jimmy",
 *   "to": "Recepción",
 *   "to_user_id": 1
 * }
 */
if ($action === 'create' && $method === 'POST') {
    $j = read_json_body();

    $from = normalize_from(isset($j['from']) ? (string)$j['from'] : '');
    $toUserId = isset($j['to_user_id']) ? (int)$j['to_user_id'] : default_receiver_id();
    if ($toUserId <= 0) {
        $toUserId = default_receiver_id();
    }

    $to = isset($j['to']) ? trim((string)$j['to']) : '';
    if ($to === '') {
        $to = 'Usuario ' . $toUserId;
    }
    $to = mb_substr($to, 0, 80);

    $fromUserId = current_user_id();

    $sql = "INSERT INTO calls (`from`, `to`, user_id_, from_user_id, status, created_at)
            VALUES (?, ?, ?, ?, 'ringing', NOW())";
    if (!$stmt = $db_connection->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db_connection->error]);
    }
    $stmt->bind_param('ssii', $from, $to, $toUserId, $fromUserId);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('No se pudo crear la llamada', 500, ['sql_error' => $err]);
    }

    $id = (int)$stmt->insert_id;
    $stmt->close();

    $row = get_call_row($db_connection, $id);
    json_ok(['call' => $row]);
}

/**
 * STATUS
 * GET /calls.php?action=status&id=123
 */
if ($action === 'status' && $method === 'GET') {
    expire_stale_ringing($db_connection);
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        json_error('ID requerido', 422);
    }

    $row = get_call_row($db_connection, $id);
    if (!$row) {
        json_error('Llamada no encontrada', 404);
    }

    json_ok(['call' => $row, 'status' => $row['status']]);
}

/**
 * INCOMING
 * GET /calls.php?action=incoming[&mine=1][&only_unassigned=1]
 */
if ($action === 'incoming' && $method === 'GET') {
    expire_stale_ringing($db_connection);
    $mine = isset($_GET['mine']) ? (int)$_GET['mine'] : 0;
    $onlyUnassigned = isset($_GET['only_unassigned']) ? (int)$_GET['only_unassigned'] : 0;
    $me = current_user_id();

    $clauses = ["status = 'ringing'"];
    $types = '';
    $params = [];

    if ($mine === 1) {
        $clauses[] = 'user_id_ = ?';
        $types .= 'i';
        $params[] = $me;
    }

    if ($onlyUnassigned === 1) {
        $clauses[] = 'user_id_ IS NULL';
    }

    $where = 'WHERE ' . implode(' AND ', $clauses);

    $sql = "SELECT id, `from`, `to`, user_id_, from_user_id, status, created_at
            FROM calls
            $where
            ORDER BY created_at DESC
            LIMIT 1";

    if (!$stmt = $db_connection->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db_connection->error]);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('Error ejecutando query', 500, ['sql_error' => $err]);
    }

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        json_ok(['call' => null]);
    }

    $row['from'] = normalize_from($row['from'] ?? null);
    json_ok(['call' => $row]);
}

/**
 * ACCEPT
 * POST /calls.php?action=accept
 * Body JSON: { id }
 */
if ($action === 'accept' && $method === 'POST') {
    $j = read_json_body();
    $id = isset($j['id']) ? (int)$j['id'] : 0;
    if ($id <= 0) {
        json_error('ID requerido', 422);
    }

    $userId = current_user_id();

    $sql = "UPDATE calls
            SET status = 'accepted',
                accepted_at = NOW(),
                accepted_by = ?,
                user_id_ = IFNULL(user_id_, ?)
            WHERE id = ?
              AND status = 'ringing'
              AND TIMESTAMPDIFF(SECOND, created_at, NOW()) < 10
            LIMIT 1";
    if (!$stmt = $db_connection->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db_connection->error]);
    }

    $stmt->bind_param('iii', $userId, $userId, $id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('No se pudo aceptar', 500, ['sql_error' => $err]);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        json_error('No se pudo aceptar (posible carrera o ya no está sonando)', 409);
    }

    $row = get_call_row($db_connection, $id);
    json_ok(['id' => $id, 'status' => 'accepted', 'call' => $row]);
}

/**
 * REJECT
 * POST /calls.php?action=reject
 * Body JSON: { id }
 */
if ($action === 'reject' && $method === 'POST') {
    expire_stale_ringing($db_connection);
    $j = read_json_body();
    $id = isset($j['id']) ? (int)$j['id'] : 0;
    if ($id <= 0) {
        json_error('ID requerido', 422);
    }

    $userId = current_user_id();

    $sql = "UPDATE calls
            SET status = 'rejected',
                rejected_at = NOW(),
                rejected_by = ?
            WHERE id = ?
              AND status = 'ringing'
              AND TIMESTAMPDIFF(SECOND, created_at, NOW()) < 10
            LIMIT 1";
    if (!$stmt = $db_connection->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db_connection->error]);
    }

    $stmt->bind_param('ii', $userId, $id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('No se pudo rechazar', 500, ['sql_error' => $err]);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        json_error('No se pudo rechazar (posible carrera o ya no está sonando)', 409);
    }

    $row = get_call_row($db_connection, $id);
    json_ok(['id' => $id, 'status' => 'rejected', 'call' => $row]);
}

/**
 * VOICEMAIL
 * POST /calls.php?action=voicemail
 * Body JSON: { id }
 */
if ($action === 'voicemail' && $method === 'POST') {
    expire_stale_ringing($db_connection);

    $j = read_json_body();
    $id = isset($j['id']) ? (int)$j['id'] : 0;
    if ($id <= 0) {
        json_error('ID requerido', 422);
    }

    $sql = "UPDATE calls
            SET status = 'ended',
                ended_at = NOW()
            WHERE id = ? AND status = 'ringing'
            LIMIT 1";
    if (!$stmt = $db_connection->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db_connection->error]);
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('No se pudo enviar al buzón', 500, ['sql_error' => $err]);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        json_error('No se pudo enviar al buzón (estado no válido o no existe)', 409);
    }

    $row = get_call_row($db_connection, $id);
    json_ok(['id' => $id, 'status' => 'ended', 'call' => $row]);
}

/**
 * END
 * POST /calls.php?action=end
 * Body JSON: { id }
 */
if ($action === 'end' && $method === 'POST') {
    $j = read_json_body();
    $id = isset($j['id']) ? (int)$j['id'] : 0;
    if ($id <= 0) {
        json_error('ID requerido', 422);
    }

    $sql = "UPDATE calls
            SET status = 'ended',
                ended_at = NOW()
            WHERE id = ? AND status IN ('accepted', 'ringing')
            LIMIT 1";
    if (!$stmt = $db_connection->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db_connection->error]);
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('No se pudo finalizar la llamada', 500, ['sql_error' => $err]);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        json_error('No se pudo finalizar (estado no válido o no existe)', 409);
    }

    $row = get_call_row($db_connection, $id);
    json_ok(['id' => $id, 'status' => 'ended', 'call' => $row]);
}

/**
 * LIST
 * GET /calls.php?action=list[&limit=50][&format=html|json][&status=ringing|accepted|rejected|ended|all]
 */
if ($action === 'list' && $method === 'GET') {
    expire_stale_ringing($db_connection);
    $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
    $format = isset($_GET['format']) ? (string)$_GET['format'] : 'json';
    $status = isset($_GET['status']) ? (string)$_GET['status'] : 'all';

    $where = '';
    $types = '';
    $params = [];

    if (in_array($status, ['ringing', 'accepted', 'rejected', 'ended'], true)) {
        $where = "WHERE status = ?";
        $types = 's';
        $params[] = $status;
    }

    $sql = "SELECT id, `from`, `to`, status, user_id_, from_user_id,
                   created_at, accepted_at, rejected_at, ended_at
            FROM calls
            $where
            ORDER BY created_at DESC
            LIMIT ?";

    if (!$stmt = $db_connection->prepare($sql)) {
        json_error('Error SQL', 500, ['sql_error' => $db_connection->error]);
    }

    if ($types === '') {
        $stmt->bind_param('i', $limit);
    } else {
        $types .= 'i';
        $params[] = $limit;
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('Error ejecutando query', 500, ['sql_error' => $err]);
    }

    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['from'] = normalize_from($r['from'] ?? null);
        $rows[] = $r;
    }
    $stmt->close();

    if ($format === 'html') {
        ob_start();

        if (!$rows) {
            echo '<div class="text-muted">No hay registros aún.</div>';
        } else {
            echo '<div class="table-responsive">';
            echo '<table class="table table-sm align-middle mb-0">';
            echo '<thead><tr>';
            echo '<th>ID</th><th>De</th><th>Para</th><th>Estado</th><th>Creada</th><th>Aceptada</th><th>Rechazada</th><th>Terminada</th>';
            echo '</tr></thead><tbody>';

            foreach ($rows as $r) {
                $id   = (int)$r['id'];
                $from = htmlspecialchars($r['from'] ?? 'Desconocido', ENT_QUOTES, 'UTF-8');
                $to   = htmlspecialchars((string)($r['to'] ?? ''), ENT_QUOTES, 'UTF-8');
                $st   = htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                $c    = htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
                $a    = htmlspecialchars((string)($r['accepted_at'] ?? ''), ENT_QUOTES, 'UTF-8');
                $rj   = htmlspecialchars((string)($r['rejected_at'] ?? ''), ENT_QUOTES, 'UTF-8');
                $e    = htmlspecialchars((string)($r['ended_at'] ?? ''), ENT_QUOTES, 'UTF-8');

                $badge = 'secondary';
                if ($st === 'ringing')  $badge = 'warning';
                if ($st === 'accepted') $badge = 'success';
                if ($st === 'rejected') $badge = 'danger';
                if ($st === 'ended')    $badge = 'secondary';

                echo "<tr>";
                echo "<td>{$id}</td>";
                echo "<td>{$from}</td>";
                echo "<td>{$to}</td>";
                echo "<td><span class=\"badge text-bg-{$badge}\">{$st}</span></td>";
                echo "<td>{$c}</td>";
                echo "<td>{$a}</td>";
                echo "<td>{$rj}</td>";
                echo "<td>{$e}</td>";
                echo "</tr>";
            }

            echo '</tbody></table></div>';
        }

        $html = ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $html;
        exit;
    }

    json_ok(['calls' => $rows]);
}

json_error('Acción inválida', 404); 