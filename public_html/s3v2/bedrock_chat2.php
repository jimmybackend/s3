<?php
putenv('AWS_EC2_METADATA_DISABLED=true'); // <- evita IMDS (169.254.169.254)

// bedrock_chat2.php — Chat directo a Amazon Bedrock (Converse)
// con soporte de adjuntos: lee .txt y hace OCR (Textract) de imágenes
// y mete ese texto en el prompt aunque no se escriba input.

// ===== Salida JSON y sesión =====
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

// ===== Timeouts PHP =====
@ini_set('max_execution_time', '600');
@set_time_limit(600);
@ini_set('default_socket_timeout', '240');
@ignore_user_abort(true);

// ===== Acumulador de notas/errores =====
$errors = [];

// ===== Helpers =====
function jexit($arr, $code=200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

function next_id(mysqli $db, $table, $col){
  $table = preg_replace('/[^A-Za-z0-9_]+/','',$table);
  $col   = preg_replace('/[^A-Za-z0-9_]+/','',$col);
  $rs = $db->query("SELECT IFNULL(MAX($col),0)+1 AS nxt FROM $table");
  if(!$rs) return 1;
  $row = $rs->fetch_assoc();
  return (int)($row['nxt'] ?? 1);
}

function detect_content_type_from_mime($mime){
  $m = strtolower((string)$mime);
  if (strpos($m,'image/') === 0) return 'image';
  if (strpos($m,'video/') === 0) return 'video';
  if (strpos($m,'audio/') === 0) return 'audio';
  if (strpos($m,'text/')  === 0) return 'text';
  return 'file';
}

function safe_filename($name){
  $b = basename((string)$name);
  return preg_replace('/[^A-Za-z0-9._-]+/','_',$b);
}

function resolve_root_candidates(): array {
  $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
  $rootFromDoc = $docRoot !== '' ? realpath($docRoot . '/..') : false;

  $candidates = [];
  foreach ([
    $rootFromDoc,
    realpath(__DIR__ . '/../../'),
    realpath(__DIR__ . '/../..'),
    realpath(__DIR__ . '/../../../'),
    realpath(__DIR__ . '/../'),
    realpath(__DIR__),
  ] as $p) {
    if ($p && is_dir($p)) $candidates[$p] = true;
  }
  return array_keys($candidates);
}

function find_file_in_candidates(string $filename, array $bases, array $subfolders): ?string {
  $filename = ltrim($filename, '/');
  foreach ($bases as $base) {
    foreach ($subfolders as $sub) {
      $sub = ($sub === '' ? '' : '/' . trim($sub,'/'));
      $try = rtrim($base,'/') . $sub . '/' . $filename;
      if (is_file($try)) return $try;
    }
  }
  return null;
}

/** ✅ NUEVO: credenciales AWS (ENV -> Config) + validación */
function aws_credentials_or_throw(): array {
  $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
  $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');
  $ak = is_string($ak) ? trim($ak) : '';
  $sk = is_string($sk) ? trim($sk) : '';
  if ($ak === '' || $sk === '') {
    throw new RuntimeException('Faltan credenciales AWS. Define AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY o Config::ACCESS_KEY/Config::SECRET_KEY.');
  }
  return ['key'=>$ak,'secret'=>$sk];
}

// ===== Cargar bootstrap (autoload + Config + db) =====
try {
  $bootstrap = __DIR__ . '/app_bootstrap.php';
  if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
  if (!is_file($bootstrap)) {
    $bases = resolve_root_candidates();
    $bootstrap = find_file_in_candidates('app_bootstrap.php', $bases, ['', 'public_html', 'api', 'app', 'www']);
  }
  if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado.');
  require_once $bootstrap;
} catch (Throwable $e) {
  $errors[] = 'bootstrap: ' . $e->getMessage();
}

// ===== S3Manager =====
$have_s3 = false;
try {
  $s3Path = __DIR__ . '/S3Manager.php';
  if (!is_file($s3Path)) $s3Path = __DIR__ . '/../S3Manager.php';
  if (!is_file($s3Path)) {
    $bases = resolve_root_candidates();
    $s3Path = find_file_in_candidates('S3Manager.php', $bases, ['', 'bd', 'config', 'app', 'includes', 'lib']);
  }
  if ($s3Path && is_file($s3Path)) {
    require_once $s3Path;
    $have_s3 = true;
  } else {
    $errors[] = 'S3Manager.php no encontrado.';
  }
} catch (Throwable $e) {
  $errors[] = 'S3Manager: ' . $e->getMessage();
}

// ===== Validar DB =====
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB no disponible','details'=>$errors], JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== AWS SDK cargado? =====
$aws_sdk_loaded = class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient') || class_exists('Aws\\Textract\\TextractClient');
if (!$aws_sdk_loaded) $errors[] = 'AWS SDK no está cargado (vendor/autoload.php). Revisa app_bootstrap.php';

// ===== Parámetros =====
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
if ($session_id <= 0) jexit(['ok'=>false,'error'=>'session_id inválido'], 400);

$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) $user_id = (int)$_SESSION['user_id'];
if (!$user_id && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) $user_id = (int)$_POST['user_id'];
if (!$user_id && class_exists('Config') && defined('Config::DEFAULT_USER_ID')) $user_id = (int)Config::DEFAULT_USER_ID;
if (!$user_id) $user_id = 1;

$text = isset($_POST['text']) ? trim((string)$_POST['text']) : '';
$auto = (isset($_POST['auto']) && (string)$_POST['auto'] === '1');

$model_id = isset($_POST['model']) ? trim((string)$_POST['model']) : '';
if ($model_id === '') jexit(['ok'=>false,'error'=>'Falta parámetro model'], 400);

$temperature = isset($_POST['temperature']) ? (float)$_POST['temperature'] : 0.7;
$max_tokens  = isset($_POST['max_tokens']) ? max(1,(int)$_POST['max_tokens']) : 1200;
$top_p       = isset($_POST['top_p']) ? (float)$_POST['top_p'] : 0.9;

// ===== Verificar sesión =====
$stmtS = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE id_=?");
if(!$stmtS) jexit(['ok'=>false,'error'=>'Error preparando SELECT sesión: '.$db_connection->error],500);
$stmtS->bind_param('i', $session_id);
if(!$stmtS->execute()){ $e=$stmtS->error; $stmtS->close(); jexit(['ok'=>false,'error'=>'Error ejecutando SELECT sesión: '.$e],500); }
$resS = $stmtS->get_result();
if(!$resS || !$resS->num_rows){ $stmtS->close(); jexit(['ok'=>false,'error'=>'Sesión no encontrada'],404); }
$stmtS->close();

// ===== Guardar mensaje de usuario (texto) =====
$saved_user_text_id = null;
$file_ids = [];
$contextTexts = [];
$ocrItems     = [];

if ($text !== '') {
  $idM = next_id($db_connection, 'ChatMessages', 'id_');

  $role_user   = 'user';
  $ctype       = 'text';
  $content     = $text;
  $s3_key      = null; $mime = null; $size_bytes = null; $thumb_key=null; $duration_ms=null;
  $model_msg   = null; $stop_reason=null; $prompt_tok=null; $compl_tok=null; $latency_ms=null; $meta=null;

  $sqlI = "INSERT INTO ChatMessages (
    id_, session_id_, user_id_, role, content_type, content,
    s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
    model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta
  ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
  $stmtI = $db_connection->prepare($sqlI);
  if(!$stmtI) jexit(['ok'=>false,'error'=>'Error preparando INSERT texto: '.$db_connection->error],500);
  $types="iiisssssisissiiis";
  $stmtI->bind_param($types, $idM,$session_id,$user_id,$role_user,$ctype,$content,
    $s3_key,$mime,$size_bytes,$thumb_key,$duration_ms,$model_msg,$stop_reason,$prompt_tok,$compl_tok,$latency_ms,$meta);
  if(!$stmtI->execute()){ $e=$stmtI->error; $stmtI->close(); jexit(['ok'=>false,'error'=>'Error insertando texto: '.$e],500); }
  $stmtI->close();
  $saved_user_text_id = $idM;
}

// ===== Adjuntos =====
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
  $s3 = null; $bucket = null;

  if ($have_s3 && class_exists('S3Manager') && class_exists('Config')) {
    try {
      $manager = new S3Manager();
      $bucket = $manager->getBucket();
      $s3 = Config::getS3();
    } catch(Throwable $e){
      $errors[]='S3 init: '.$e->getMessage();
    }
  }

  $count = count($_FILES['files']['name']);
  for ($i=0; $i<$count; $i++) {
    if (!isset($_FILES['files']['error'][$i]) || $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
      $errors[]='Archivo '.$i.' no recibido'; continue;
    }

    $tmp  = $_FILES['files']['tmp_name'][$i];
    $name = safe_filename($_FILES['files']['name'][$i]);
    $mime = (string)($_FILES['files']['type'][$i] ?? '');
    $size = (int)($_FILES['files']['size'][$i] ?? 0);
    $ctype = detect_content_type_from_mime($mime);
    $s3_key = null; $thumb_key = null;

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ((strpos($mime,'text/')===0) || in_array($ext, ['txt','md','csv','json','xml','log'])) {
      $raw = @file_get_contents($tmp);
      if ($raw !== false) {
        $txt = @mb_convert_encoding($raw, 'UTF-8', 'auto');
        $txt = trim(mb_substr($txt ?? '', 0, 50000));
        if ($txt !== '') $contextTexts[] = "Archivo $name:\n".$txt;
      }
    }

    if ($s3 && $bucket) {
      $prefix = 'Chat/Uploads/'.$session_id.'/';
      if (defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ) $prefix = rtrim(Config::RUTA_RAIZ,'/').'/'.$prefix;

      $key = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $name;
      try {
        $s3->putObject([
          'Bucket'      => $bucket,
          'Key'         => $key,
          'SourceFile'  => $tmp,
          'ContentType' => ($mime ?: 'application/octet-stream'),
          'ACL'         => 'private'
        ]);
        $s3_key = $key;
      } catch (Throwable $e) {
        $errors[] = 'S3 putObject: '.$e->getMessage();
      }

      if ($s3_key && strpos($mime,'image/') === 0) $ocrItems[] = ['name'=>$name,'s3_key'=>$s3_key];
    }

    $idF = next_id($db_connection, 'ChatMessages', 'id_');
    $role_user   = 'user';
    $content     = $name;
    $duration_ms = null; $model_msg = null; $stop_reason=null;
    $prompt_tok = null; $compl_tok=null; $latency_ms=null; $meta=null;

    $sqlF = "INSERT INTO ChatMessages (
      id_, session_id_, user_id_, role, content_type, content,
      s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
      model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmtF = $db_connection->prepare($sqlF);
    if(!$stmtF) jexit(['ok'=>false,'error'=>'Error preparando INSERT file: '.$db_connection->error],500);
    $types="iiisssssisissiiis";
    $stmtF->bind_param($types, $idF,$session_id,$user_id,$role_user,$ctype,$content,
      $s3_key,$mime,$size,$thumb_key,$duration_ms,$model_msg,$stop_reason,$prompt_tok,$compl_tok,$latency_ms,$meta);
    if(!$stmtF->execute()){ $e=$stmtF->error; $stmtF->close(); jexit(['ok'=>false,'error'=>'Error insertando file: '.$e],500); }
    $stmtF->close();
    $file_ids[] = $idF;
  }
}

// ===== OCR Textract (✅ MODIFICADO: fuerza credenciales) =====
if (!empty($ocrItems) && $have_s3) {
  try {
    if (!$aws_sdk_loaded || !class_exists('Aws\\Textract\\TextractClient')) {
      throw new RuntimeException('AWS Textract no disponible (vendor/autoload.php).');
    }

    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    $creds  = aws_credentials_or_throw(); // ✅

    $textract = new Aws\Textract\TextractClient([
      'region'      => $region,
      'version'     => 'latest',
      'credentials' => $creds, // ✅
      'http'        => ['connect_timeout' => 15, 'timeout' => 120],
    ]);

    $bucket = null;
    if (class_exists('S3Manager')) {
      try { $bucket = (new S3Manager())->getBucket(); }
      catch(Throwable $e){ $errors[]='S3 bucket: '.$e->getMessage(); }
    }

    foreach ($ocrItems as $it) {
      try {
        if (empty($bucket)) continue;
        $res = $textract->detectDocumentText([
          'Document' => [ 'S3Object' => ['Bucket' => $bucket, 'Name' => $it['s3_key']] ]
        ]);
        $lines = [];
        foreach (($res['Blocks'] ?? []) as $b) {
          if (($b['BlockType'] ?? '') === 'LINE' && !empty($b['Text'])) $lines[] = $b['Text'];
        }
        $ocr = trim(implode("\n", $lines));
        if ($ocr !== '') {
          $ocr = mb_substr($ocr, 0, 50000);
          $contextTexts[] = "Texto OCR de {$it['name']}:\n".$ocr;
        }
      } catch (Throwable $e) {
        $errors[] = 'Textract '.$it['name'].': '.$e->getMessage();
      }
    }
  } catch (Throwable $e) {
    $errors[] = 'Textract init: '.$e->getMessage();
  }
}

// ===== Auto-router mínimo =====
$action = null;
$router = ['improved_prompt'=>$text, 'decided'=>'text'];
if ($auto && $text !== '') {
  $t = mb_strtolower($text,'UTF-8');
  if (preg_match('/\b(img|image|imagen|dibuja|ilustra|pintar)\b/u',$t)) { $action='gen_image'; $router['decided']='image'; }
  elseif (preg_match('/\b(video|clip|animaci[oó]n|reel)\b/u',$t)) { $action='gen_video'; $router['decided']='video'; }
}

// ===== Llamada a Bedrock (✅ MODIFICADO: fuerza credenciales) =====
$reply_text = null; $assistant_id=null; $usage = ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0];

if ( ($text !== '' || !empty($contextTexts)) && $action === null) {
  try {
    if (!$aws_sdk_loaded || !class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient')) {
      throw new RuntimeException('AWS SDK no cargado (vendor/autoload.php).');
    }

    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    $creds  = aws_credentials_or_throw(); // ✅

    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
      'region'      => $region,
      'version'     => 'latest',
      'credentials' => $creds, // ✅
      'http'        => ['connect_timeout' => 20, 'timeout' => 240],
    ]);

    $userParts = [];
    if (($router['improved_prompt'] ?: $text) !== '') {
      $userParts[] = ['text' => ($router['improved_prompt'] ?: $text)];
    } elseif (!empty($contextTexts)) {
      $userParts[] = ['text' => 'Analiza los archivos adjuntos y respóndeme en español. Si hay instrucciones dentro, síguelas.'];
    }
    foreach ($contextTexts as $ctx) $userParts[] = ['text' => $ctx];

    $messages = [[ 'role' => 'user', 'content' => $userParts ]];
    $inferBase = ['maxTokens'=>$max_tokens, 'temperature'=>$temperature, 'topP'=>$top_p];

    $invoke = function(array $msgs, array $infer) use ($bedrock, $model_id) {
      $attempts = 0; $maxA = 2;
      while ($attempts <= $maxA) {
        try { return $bedrock->converse(['modelId'=>$model_id, 'messages'=>$msgs, 'inferenceConfig'=>$infer]); }
        catch (Throwable $e) { $attempts++; if ($attempts > $maxA) throw $e; usleep(250000); }
      }
      throw new RuntimeException('invoke() no retornó');
    };

    $extract = function($res){
      $out = ['text'=>'','stop'=>null,'usage'=>['input'=>0,'output'=>0,'total'=>0]];
      $cont = $res['output']['message']['content'] ?? [];
      if (is_array($cont)) foreach ($cont as $p) if (isset($p['text'])) $out['text'] .= (string)$p['text'];
      $out['stop'] = $res['stopReason'] ?? ($res['output']['stopReason'] ?? null);
      $u = $res['usage'] ?? [];
      $out['usage']['input']  = (int)($u['inputTokens'] ?? 0);
      $out['usage']['output'] = (int)($u['outputTokens'] ?? 0);
      $out['usage']['total']  = (int)($u['totalTokens'] ?? ($out['usage']['input'] + $out['usage']['output']));
      return $out;
    };

    $full = ''; $stopReason = null;

    $res = $invoke($messages, $inferBase);
    $part = $extract($res);
    $full .= $part['text']; $stopReason = $part['stop'];
    $usage['prompt_tokens']     += $part['usage']['input'];
    $usage['completion_tokens'] += $part['usage']['output'];
    $usage['total_tokens']      += $part['usage']['total'];

    $rounds = 0;
    while (($stopReason === 'max_tokens' || $stopReason === 'length') && $rounds < 2) {
      $rounds++;
      $messages[] = ['role'=>'assistant','content'=>[['text'=>$part['text']]]];
      $messages[] = ['role'=>'user','content'=>[['text'=>'Continúa.']]];
      $res = $invoke($messages, $inferBase);
      $part = $extract($res);
      $full .= ( $full!=='' ? "\n" : '' ) . $part['text'];
      $stopReason = $part['stop'];
      $usage['prompt_tokens']     += $part['usage']['input'];
      $usage['completion_tokens'] += $part['usage']['output'];
      $usage['total_tokens']      += $part['usage']['total'];
    }

    $reply_text = ($full !== '' ? $full : 'No obtuve respuesta.');
  } catch (Throwable $e) {
    $reply_text = '✔️ Recibido. (No pude contactar Bedrock: '.$e->getMessage().')';
  }

  // Guardar respuesta del asistente
  $assistant_id = next_id($db_connection,'ChatMessages','id_');
  $role_assistant = 'assistant'; $ctypeA='text'; $contentA=$reply_text;
  $s3_key=null; $mime=null; $size_bytes=null; $thumb_key=null; $duration_ms=null;
  $model_msg=$model_id; $stop_reason=null; $prompt_tok=$usage['prompt_tokens']; $compl_tok=$usage['completion_tokens']; $latency_ms=null; $meta=null;

  $sqlA = "INSERT INTO ChatMessages (
    id_, session_id_, user_id_, role, content_type, content,
    s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
    model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta
  ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
  $stmtA=$db_connection->prepare($sqlA);
  if($stmtA){
    $types="iiisssssisissiiis";
    $stmtA->bind_param($types, $assistant_id,$session_id,$user_id,$role_assistant,$ctypeA,$contentA,
      $s3_key,$mime,$size_bytes,$thumb_key,$duration_ms,$model_msg,$stop_reason,$prompt_tok,$compl_tok,$latency_ms,$meta);
    $stmtA->execute();
    $stmtA->close();
  }
}

// ===== Salida =====
$out = [
  'ok'         => true,
  'saved'      => ['user_text_id'=>$saved_user_text_id, 'file_ids'=>$file_ids, 'assistant_id'=>$assistant_id],
  'reply'      => $reply_text,
  'usage'      => $usage,
  'action'     => $action,
  'router'     => $router
];
if (!empty($errors)) $out['notes'] = $errors;
jexit($out);