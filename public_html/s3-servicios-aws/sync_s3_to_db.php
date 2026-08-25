<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

ignore_user_abort(true);
@set_time_limit(0);

/* ====== Validar sesión estricta ====== */
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    $_SESSION = [];
    if (session_id() !== '') { session_unset(); session_destroy(); }
    header("Location: index.php");
    exit;
}
$userId = (int)$_SESSION['user_id'];

/* ====== Includes ====== */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';
require_once __DIR__ . '/utils/helpers.php';


/* ====== DB helpers ====== */
function db_exec(mysqli $db, string $sql, array $bind = [], string $types = ''): void {
    $st = $db->prepare($sql);
    if (!$st) throw new Exception("SQL prepare failed: {$db->error} | {$sql}");

    if (!empty($bind)) {
        if ($types === '') {
            $types = '';
            foreach ($bind as $v) {
                if (is_int($v)) $types .= 'i';
                elseif (is_float($v)) $types .= 'd';
                else $types .= 's';
            }
        }
        $st->bind_param($types, ...$bind);
    }

    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        throw new Exception("SQL exec failed: {$err}");
    }
    $st->close();
}

function db_fetch_one(mysqli $db, string $sql, array $bind = [], string $types = ''): ?array {
    $st = $db->prepare($sql);
    if (!$st) throw new Exception("SQL prepare failed: {$db->error} | {$sql}");

    if (!empty($bind)) {
        if ($types === '') {
            $types = '';
            foreach ($bind as $v) {
                if (is_int($v)) $types .= 'i';
                elseif (is_float($v)) $types .= 'd';
                else $types .= 's';
            }
        }
        $st->bind_param($types, ...$bind);
    }

    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        throw new Exception("SQL exec failed: {$err}");
    }

    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function db_fetch_all(mysqli $db, string $sql, array $bind = [], string $types = ''): array {
    $st = $db->prepare($sql);
    if (!$st) throw new Exception("SQL prepare failed: {$db->error} | {$sql}");

    if (!empty($bind)) {
        if ($types === '') {
            $types = '';
            foreach ($bind as $v) {
                if (is_int($v)) $types .= 'i';
                elseif (is_float($v)) $types .= 'd';
                else $types .= 's';
            }
        }
        $st->bind_param($types, ...$bind);
    }

    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        throw new Exception("SQL exec failed: {$err}");
    }

    $res = $st->get_result();
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $st->close();
    return $rows;
}


function db_like_escape(string $value): string {
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}

/* ====== S3 ====== */
try {
    $s3 = Config::getS3();
    $manager = new S3Manager();
    $bucket  = $manager->getBucket();
    if (!$bucket) throw new Exception("Bucket no definido desde S3Manager::getBucket()");
    $s3->headBucket(['Bucket'=>$bucket]); // valida acceso real
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"S3 error: ".$e->getMessage()]);
    exit;
}

/* ====== BasePrefix ======
   Prioridad: ruta_actual > Config::RUTA_RAIZ
*/
try {
    // Siempre sincronizar desde la raíz del usuario (ignorar ruta_actual)
    if ($userId === 1) {
        $scanBasePrefix = 'Data/';
    } else {
        $scanBasePrefix = 'Data' . $userId . '/';
    }

    // Normalizar
    $scanBasePrefix = rtrim($scanBasePrefix, '/') . '/';

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"Config basePrefix inválida: ".$e->getMessage()]);
    exit;
}

/* ====== Throttling (opcional) ====== */
$S3_DELAY_US = 150000;  // 0.15s entre páginas (ajústalo si quieres)
$PROC_DELAY_US = 0;     // 0 = sin pausa en DB

/* ====== Reset Found ====== */
try {
    db_exec($db_connection, "UPDATE FileS3 SET Found=0 WHERE user_id_=?", [$userId], 'i');
    db_exec($db_connection, "UPDATE S3Folders SET Found=0 WHERE user_id_=?", [$userId], 'i');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"step"=>"reset_found","error"=>$e->getMessage()]);
    exit;
}

/* ====== Colección de folders detectadas ====== */
$folders = []; // prefix => [Nombre, ParentPrefix]

function add_folder(array &$folders, string $prefix): void {
    $prefix = rtrim($prefix, '/') . '/';
    if ($prefix === './') return;

    // Nombre (último segmento)
    $trim = rtrim($prefix, '/');
    $pos = strrpos($trim, '/');
    $name = ($pos === false) ? $trim : substr($trim, $pos + 1);
    if ($name === '') $name = $prefix;

    // Parent
    $parent = null;
    if ($pos !== false) {
        $parent = substr($trim, 0, $pos + 1);
        $parent = $parent !== '' ? rtrim($parent, '/') . '/' : null;
    }

    $folders[$prefix] = [
        'Nombre' => $name,
        'ParentPrefix' => $parent
    ];
}

/* ====== Upsert folder ====== */
function upsert_folder(mysqli $db, int $userId, string $prefix, string $name, ?string $parent): void {
    $row = db_fetch_one($db,
        "SELECT id_, Nombre, ParentPrefix FROM S3Folders WHERE user_id_=? AND Prefix=? LIMIT 1",
        [$userId, $prefix],
        'is'
    );
    

    if ($row) {
        db_exec($db,
          "UPDATE S3Folders
           SET Found=1,
               Nombre=IF(Nombre<>?, ?, Nombre),
               ParentPrefix=IF( (ParentPrefix IS NULL AND ? IS NOT NULL) OR (ParentPrefix IS NOT NULL AND ParentPrefix<>?), ?, ParentPrefix)
           WHERE id_=?",
          [$name, $name, $parent, $parent, $parent, (int)$row['id_']],
          'sssssi' // <- SOLO ssss s i (todo minúscula)
        );
        return;
    }

    db_exec($db,
        "INSERT INTO S3Folders (user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, CreatedAt, UpdatedAt)
         VALUES (?, ?, ?, ?, 1, 'normal', NOW(), NOW())",
        [$userId, $prefix, $name, $parent],
        'isss'
    );
}

/* ====== Upsert file (Encriptado = KEY COMPLETO) ======
   Regla principal:
   - Encriptado SIEMPRE debe ser el Key real completo de S3.
   - Nombre es el nombre visible/amigable y NO se sobrescribe si ya existe.
   - Si el registro venía de una versión anterior con Encriptado incompleto
     pero está en la misma Ruta y su Encriptado termina con el basename real
     de S3, se actualiza ese mismo registro en vez de insertar otro.
*/
function upsert_file(mysqli $db, int $userId, string $s3Key, int $size): void {
    $dir = '';
    $pos = strrpos($s3Key, '/');
    if ($pos !== false) $dir = substr($s3Key, 0, $pos + 1);
    $dir = ($dir !== '') ? rtrim($dir, '/') . '/' : '';

    $basename = ($pos !== false) ? substr($s3Key, $pos + 1) : $s3Key;

    // 1) Caso normal/correcto: el Encriptado ya es el Key completo real de S3.
    $row = db_fetch_one($db,
        "SELECT id_, Nombre, Encriptado, Tamano, Ruta
         FROM FileS3
         WHERE user_id_=? AND Encriptado=?
         LIMIT 1",
        [$userId, $s3Key],
        'is'
    );

    // 2) Caso legado: antes se guardó solo el nombre real o un Encriptado incompleto.
    //    Solo se toma si hay UN candidato claro en la misma carpeta.
    if (!$row) {
        $legacyRows = db_fetch_all($db,
            "SELECT id_, Nombre, Encriptado, Tamano, Ruta
             FROM FileS3
             WHERE user_id_=?
               AND Ruta=?
               AND Found=0
               AND (Encriptado=? OR Encriptado LIKE ? ESCAPE '!')
             ORDER BY id_ ASC
             LIMIT 2",
            [$userId, $dir, $basename, '%/' . db_like_escape($basename)],
            'isss'
        );

        if (count($legacyRows) === 1) {
            $row = $legacyRows[0];
        }
    }

    if ($row) {
        // Actualizar solo datos técnicos. Nombre se conserva; solo se llena si estaba vacío.
        db_exec($db,
            "UPDATE FileS3
             SET Encriptado = IF(Encriptado<>?, ?, Encriptado),
                 Tamano     = IF(Tamano<>?, ?, Tamano),
                 Ruta       = IF(Ruta<>?, ?, Ruta),
                 Nombre     = IF(Nombre IS NULL OR Nombre='', ?, Nombre),
                 Found      = 1
             WHERE id_=?",
            [$s3Key, $s3Key, $size, $size, $dir, $dir, $basename, (int)$row['id_']],
            'ssiisssi'
        );
        return;
    }

    // Archivo nuevo real: aquí sí el Nombre visible nace del nombre real en S3.
    db_exec($db,
        "INSERT INTO FileS3 (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, Fecha, user_id_)
         VALUES (?, ?, ?, NULL, ?, 1, 'normal', NOW(), ?)",
        [$basename, $s3Key, $size, $dir, $userId],
        'ssisi'
    );
}

/* ====== Scan S3 (paginado) ====== */
$totalFiles = 0;
$totalFolders = 0;

try {
    // Asegurar carpeta raíz
    add_folder($folders, $scanBasePrefix);

    $params = [
        'Bucket'  => $bucket,
        'Prefix'  => $scanBasePrefix,
        'MaxKeys' => 1000
    ];

    do {
        $res = $s3->listObjectsV2($params);

        if (!empty($res['Contents'])) {
            foreach ($res['Contents'] as $o) {
                if (empty($o['Key'])) continue;
                $key = (string)$o['Key'];

                // Si es placeholder de "carpeta"
                if (substr($key, -1) === '/') {
                    add_folder($folders, $key);
                    continue;
                }

                // Archivo
                $size = isset($o['Size']) ? (int)$o['Size'] : 0;

                // Registrar todas las carpetas padres
                $dir = dirname($key);
                if ($dir !== '.' && $dir !== '') {
                    $parts = explode('/', $dir);
                    $acc = '';
                    foreach ($parts as $p) {
                        if ($p === '') continue;
                        $acc .= $p . '/';
                        add_folder($folders, $acc);
                    }
                }

                upsert_file($db_connection, $userId, $key, $size);
                $totalFiles++;
                if ($PROC_DELAY_US > 0) usleep($PROC_DELAY_US);
            }
        }

        if (!empty($res['IsTruncated']) && !empty($res['NextContinuationToken'])) {
            $params['ContinuationToken'] = $res['NextContinuationToken'];
        } else {
            unset($params['ContinuationToken']);
        }

        if ($S3_DELAY_US > 0) usleep($S3_DELAY_US);
    } while (!empty($res['IsTruncated']));

    // Guardar folders detectadas
    foreach ($folders as $prefix => $info) {
        upsert_folder($db_connection, $userId, $prefix, $info['Nombre'], $info['ParentPrefix']);
        $totalFolders++;
        if ($PROC_DELAY_US > 0) usleep($PROC_DELAY_US);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"step"=>"scan_s3","error"=>$e->getMessage()]);
    exit;
}

/* ====== Purga Found=0 ====== */
try {
    db_exec($db_connection, "DELETE FROM FileS3 WHERE user_id_=? AND Found=0", [$userId], 'i');
    db_exec($db_connection, "DELETE FROM S3Folders WHERE user_id_=? AND Found=0", [$userId], 'i');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"step"=>"purge_found0","error"=>$e->getMessage()]);
    exit;
}

echo json_encode([
    "ok" => true,
    "user_id" => $userId,
    "bucket" => $bucket,
    "base" => $scanBasePrefix,
    "files_upserted" => $totalFiles,
    "folders_upserted" => $totalFolders
]);