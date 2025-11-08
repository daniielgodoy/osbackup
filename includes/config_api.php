<?php
/* includes/config_api.php */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ───────────────────────── Sessão / Contexto ───────────────────────── */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* Se tiver seu guard, ele pode popular tenant/shop/user na sessão */
$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
$shop_id   = isset($_SESSION['shop_id'])   ? (int)$_SESSION['shop_id']   : 1;
$user_id   = isset($_SESSION['user_id'])   ? (int)$_SESSION['user_id']   : 1;

/* ================== CONFIG LOCAIS (Node WA) ================== */
/* Se houver base_url em wa_devices, ela é priorizada; senão cai no default. */
$WA_BASE_DEFAULT = 'http://127.0.0.1:3001';

/* ───────── helpers ───────── */
function jok(array $arr = []): never {
  echo json_encode(['ok'=>true] + $arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function jerr(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function body_json(): array {
  $r = file_get_contents('php://input');
  $j = $r ? json_decode($r, true) : [];
  return is_array($j) ? $j : [];
}
function to_bool01($v): int {
  if (is_bool($v)) return $v ? 1 : 0;
  $s = is_string($v) ? strtolower(trim($v)) : $v;
  if ($s === 1 || $s === '1') return 1;
  if ($s === 0 || $s === '0') return 0;
  if (in_array($s, ['true','on','yes','sim'], true)) return 1;
  if (in_array($s, ['false','off','no','nao','não'], true)) return 0;
  return $v ? 1 : 0;
}

/* ───────── Infra: tabelas por loja / devices ───────── */
function ensure_shop_templates_table(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS `shop_msg_templates` (
      `tenant_id` INT NOT NULL,
      `shop_id`   INT NOT NULL,
      `pendente`            TEXT NULL,
      `em_andamento`        TEXT NULL,
      `orcamento`           TEXT NULL,
      `aguardando_retirada` TEXT NULL,
      `concluido`           TEXT NULL,
      `notify_auto` TINYINT(1) NOT NULL DEFAULT 1,
      `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`tenant_id`,`shop_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
function ensure_shop_templates_row(mysqli $conn, int $tenant_id, int $shop_id): void {
  $q = $conn->prepare("SELECT 1 FROM shop_msg_templates WHERE tenant_id=? AND shop_id=?");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute(); $q->store_result();
  $exists = $q->num_rows > 0; $q->close();
  if(!$exists){
    $st = $conn->prepare("INSERT INTO shop_msg_templates (tenant_id, shop_id) VALUES (?,?)");
    $st->bind_param('ii', $tenant_id, $shop_id);
    $st->execute(); $st->close();
  }
}

/* Tabela de devices (se ainda não existir) */
function ensure_wa_devices_table(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS `wa_devices` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `tenant_id` INT NOT NULL,
      `shop_id`   INT NOT NULL,
      `device_key` VARCHAR(128) NOT NULL,
      `base_url`   VARCHAR(255) NULL,
      `active`     TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `u_tenant_shop_key` (`tenant_id`,`shop_id`,`device_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

/* Cria (se precisar) um device padrão para a loja e opcionalmente ativa */
function ensure_default_device(mysqli $conn, int $tenant_id, int $shop_id, string $baseDefault): array {
  ensure_wa_devices_table($conn);

  // Existe ativo?
  $q = $conn->prepare("SELECT device_key, COALESCE(base_url,'') as base_url, active FROM wa_devices WHERE tenant_id=? AND shop_id=? AND active=1 ORDER BY id ASC LIMIT 1");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute();
  $a = $q->get_result()->fetch_assoc(); $q->close();
  if ($a) return ['device_key'=>$a['device_key'], 'base_url'=>$a['base_url'] ?: $baseDefault, 'active'=>1];

  // Existe algum?
  $q = $conn->prepare("SELECT device_key, COALESCE(base_url,'') as base_url, active FROM wa_devices WHERE tenant_id=? AND shop_id=? ORDER BY id ASC LIMIT 1");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute();
  $r = $q->get_result()->fetch_assoc(); $q->close();
  if ($r) {
    // Marca como ativo se não houver ativo
    $st = $conn->prepare("UPDATE wa_devices SET active=1 WHERE tenant_id=? AND shop_id=? AND device_key=?");
    $st->bind_param('iis', $tenant_id, $shop_id, $r['device_key']);
    $st->execute(); $st->close();
    return ['device_key'=>$r['device_key'], 'base_url'=>$r['base_url'] ?: $baseDefault, 'active'=>1];
  }

  // Cria um device padrão
  $defaultKey = "shop{$tenant_id}_{$shop_id}_default";
  $st = $conn->prepare("INSERT IGNORE INTO wa_devices (tenant_id, shop_id, device_key, base_url, active) VALUES (?,?,?,?,1)");
  $st->bind_param('iiss', $tenant_id, $shop_id, $defaultKey, $baseDefault);
  $st->execute(); $st->close();

  return ['device_key'=>$defaultKey, 'base_url'=>$baseDefault, 'active'=>1];
}

/* Busca device solicitado/ativo; se nada existir, cria um padrão */
function resolve_device(mysqli $conn, int $tenant_id, int $shop_id, string $baseDefault): array {
  ensure_wa_devices_table($conn);

  // 1) device explicitamente na query (ou corpo)
  $deviceParam = $_GET['device'] ?? null;
  if ($deviceParam === null) {
    $b = body_json();
    if (isset($b['device'])) $deviceParam = (string)$b['device'];
  }

  if ($deviceParam !== null && $deviceParam !== '') {
    $q = $conn->prepare("SELECT device_key, COALESCE(base_url,'') as base_url, active FROM wa_devices WHERE tenant_id=? AND shop_id=? AND device_key=? LIMIT 1");
    $q->bind_param('iis', $tenant_id, $shop_id, $deviceParam);
    $q->execute();
    $r = $q->get_result()->fetch_assoc(); $q->close();
    if ($r) return ['device_key'=>$r['device_key'], 'base_url'=>$r['base_url'] ?: $baseDefault, 'active'=>(int)$r['active']];
    // se pediu um device que não existe, cria-o já ativo apontando pro base default
    $st = $conn->prepare("INSERT INTO wa_devices (tenant_id, shop_id, device_key, base_url, active) VALUES (?,?,?,?,1)");
    $st->bind_param('iiss', $tenant_id, $shop_id, $deviceParam, $baseDefault);
    $st->execute(); $st->close();
    return ['device_key'=>$deviceParam, 'base_url'=>$baseDefault, 'active'=>1];
  }

  // 2) sem param: devolve ativo ou cria padrão
  return ensure_default_device($conn, $tenant_id, $shop_id, $baseDefault);
}

/* HTTP helpers para proxiar o serviço WA local (com ?device=) */
function http_get_json_scoped(string $base, ?string $deviceKey, string $path, int $timeout=6): ?array {
  $url = rtrim($base, '/').$path;
  if ($deviceKey) {
    $url .= (strpos($url,'?')===false ? '?' : '&') . 'device=' . rawurlencode($deviceKey);
  }
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_TIMEOUT=>$timeout,
      CURLOPT_CONNECTTIMEOUT=>2,
    ]);
    $out = curl_exec($ch);
    $err = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($err || $out===false || $code >= 400) return null;
  } else {
    $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) return null;
    $code = 200;
    if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) $code = (int)$m[1];
    if ($code >= 400) return null;
  }
  $j = json_decode($out, true);
  return is_array($j) ? $j : null;
}
function http_post_json_scoped(string $base, ?string $deviceKey, string $path, array $payload=[], int $timeout=8): ?array {
  $url = rtrim($base, '/').$path;
  if ($deviceKey) {
    $url .= (strpos($url,'?')===false ? '?' : '&') . 'device=' . rawurlencode($deviceKey);
  }
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_POST=>true,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
      CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_TIMEOUT=>$timeout,
      CURLOPT_CONNECTTIMEOUT=>2,
    ]);
    $out = curl_exec($ch);
    $err = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($err || $out===false || $code >= 400) return null;
  } else {
    $opts = [
      'http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: application/json\r\n",
        'content'=>json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout'=>$timeout,
        'ignore_errors'=>true
      ]
    ];
    $ctx = stream_context_create($opts);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) return null;
    $code = 200;
    if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) $code = (int)$m[1];
    if ($code >= 400) return null;
  }
  $j = json_decode($out, true);
  return is_array($j) ? $j : null;
}

/* ───────── Util: ler/gravar prefs loja (notify_auto) ───────── */
function get_shop_prefs(mysqli $conn, int $tenant_id, int $shop_id): array {
  ensure_shop_templates_table($conn);
  ensure_shop_templates_row($conn, $tenant_id, $shop_id);

  $q = $conn->prepare("SELECT notify_auto FROM shop_msg_templates WHERE tenant_id=? AND shop_id=? LIMIT 1");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute(); $q->bind_result($na); $q->fetch(); $q->close();
  $notify_auto = ($na === null) ? 1 : (int)$na;

  return ['notify_auto'=>$notify_auto];
}
function set_shop_notify_auto(mysqli $conn, int $tenant_id, int $shop_id, int $enabled): void {
  ensure_shop_templates_table($conn);
  ensure_shop_templates_row($conn, $tenant_id, $shop_id);
  $st = $conn->prepare("UPDATE shop_msg_templates SET notify_auto=? WHERE tenant_id=? AND shop_id=?");
  $st->bind_param('iii', $enabled, $tenant_id, $shop_id);
  $st->execute(); $st->close();
}

/* ───────── Ação ───────── */
$action = $_GET['action'] ?? '';

/* ================= GET ALL (User + Loja) ================= */
if ($action === 'get_all') {
  // user
  $q = $conn->prepare("
    SELECT id,email,telefone,nome,handle,avatar_url,
           COALESCE(notify_email,1),COALESCE(notify_push,0),COALESCE(notify_sms,0),
           COALESCE(tema,'auto'),COALESCE(idioma,'pt-BR'),COALESCE(moeda,'BRL')
      FROM login WHERE id=? LIMIT 1
  ");
  $q->bind_param('i', $user_id);
  $q->execute();
  $q->bind_result($uid,$email,$tel,$nome,$handle,$avatar,
                  $n_email,$n_push,$n_sms,$tema,$idioma,$moeda);
  $ok = $q->fetch(); $q->close();

  $user = [
    'id'=>$uid ?? $user_id,
    'email'=>$email ?? '',
    'telefone'=>$tel ?? '',
    'nome'=>$nome ?? '',
    'handle'=>$handle ?? '',
    'avatar_url'=>$avatar ?? null
  ];

  // prefs do usuário
  $prefs_user = [
    'tema'         => $tema   ?? 'auto',
    'idioma'       => $idioma ?? 'pt-BR',
    'moeda'        => $moeda  ?? 'BRL',
    'notify_email' => (int)($n_email ?? 1),
    'notify_push'  => (int)($n_push  ?? 0),
    'notify_sms'   => (int)($n_sms   ?? 0),
  ];

  // prefs da loja
  $prefs_shop = get_shop_prefs($conn, $tenant_id, $shop_id);

  // templates da loja
  ensure_shop_templates_table($conn);
  ensure_shop_templates_row($conn, $tenant_id, $shop_id);
  $tq = $conn->prepare("
    SELECT COALESCE(pendente,''), COALESCE(em_andamento,''), COALESCE(orcamento,''),
           COALESCE(aguardando_retirada,''), COALESCE(concluido,'')
      FROM shop_msg_templates
     WHERE tenant_id=? AND shop_id=? LIMIT 1
  ");
  $tq->bind_param('ii', $tenant_id, $shop_id);
  $tq->execute();
  $tq->bind_result($p,$e,$o,$ar,$c);
  $tq->fetch(); $tq->close();
  $tpls = [
    'pendente'            => $p ?? '',
    'em_andamento'        => $e ?? '',
    'orcamento'           => $o ?? '',
    'aguardando_retirada' => $ar ?? '',
    'concluido'           => $c ?? '',
  ];

  /* também já garanta pelo menos um device (para QR funcionar sem erro) */
  $dev = ensure_default_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);

  jok([
    'user' => $user,
    'prefs' => $prefs_user + ['notify_auto' => (int)$prefs_shop['notify_auto']],
    'msg_templates' => $tpls,
    'sessions'=>[],
    'pay_methods'=>[],
    'invoices'=>[],
    'plan'=>['name'=>'free','renova_em'=>'—'],
    'context'=>[
      'tenant_id'=>$tenant_id,
      'shop_id'=>$shop_id,
      'device_key_default'=>$dev['device_key']
    ]
  ]);
}

/* ================= CHANGE PASSWORD ================= */
if ($action === 'change_password') {
  $b = body_json(); $a = $b['atual'] ?? ''; $n = $b['nova'] ?? '';
  if (strlen($n) < 6) jerr("Senha curta");

  $q = $conn->prepare("SELECT password_hash FROM login WHERE id=?");
  $q->bind_param('i', $user_id); $q->execute();
  $res = $q->get_result(); $h = $res ? $res->fetch_column() : null; $q->close();
  if (!$h || !password_verify($a, $h)) jerr("Senha atual incorreta");

  $nh = password_hash($n, PASSWORD_BCRYPT);
  $st = $conn->prepare("UPDATE login SET password_hash=? WHERE id=?");
  $st->bind_param('si', $nh, $user_id);
  $st->execute(); $st->close();
  jok();
}

/* ================= UPDATE CONTACTS ================= */
if ($action === 'update_contacts') {
  $b = body_json();
  $email = $b['email'] ?? '';
  $tel   = $b['telefone'] ?? '';
  $st = $conn->prepare("UPDATE login SET email=?, telefone=? WHERE id=?");
  $st->bind_param('ssi', $email, $tel, $user_id);
  $st->execute(); $st->close();
  jok();
}

/* ================= UPDATE PROFILE ================= */
if ($action === 'update_profile') {
  $b = body_json();
  $nome   = $b['nome']   ?? '';
  $handle = $b['handle'] ?? '';
  if ($handle && !preg_match('~^[a-z0-9][a-z0-9\-_.]{2,30}$~i', $handle))
    jerr("Handle inválido");

  $st = $conn->prepare("UPDATE login SET nome=?, handle=? WHERE id=?");
  $st->bind_param('ssi', $nome, $handle, $user_id);
  $st->execute(); $st->close();
  jok();
}

/* ================= UPLOAD AVATAR ================= */
if ($action === 'upload_avatar') {
  if (!isset($_FILES['file'])) jerr("Envie imagem");
  $f = $_FILES['file'];
  if ($f['error'] !== UPLOAD_ERR_OK) jerr("Erro upload");
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, ['png','jpg','jpeg','webp'])) jerr("Formato inválido");

  $dir = __DIR__ . '/../uploads/avatars';
  if (!is_dir($dir)) mkdir($dir, 0777, true);
  $name = "u{$user_id}_" . time() . ".$ext";
  move_uploaded_file($f['tmp_name'], "$dir/$name");
  $url = "uploads/avatars/$name";

  $st = $conn->prepare("UPDATE login SET avatar_url=? WHERE id=?");
  $st->bind_param('si', $url, $user_id);
  $st->execute(); $st->close();
  jok(['url'=>$url]);
}

/* ================= NOTIFICATIONS (email/push/sms) ================= */
if ($action === 'update_notifications') {
  $b = body_json();
  $email = to_bool01($b['email'] ?? 0);
  $push  = to_bool01($b['push']  ?? 0);
  $sms   = to_bool01($b['sms']   ?? 0);
  $st = $conn->prepare("UPDATE login SET notify_email=?, notify_push=?, notify_sms=? WHERE id=?");
  $st->bind_param('iiii', $email, $push, $sms, $user_id);
  $st->execute(); $st->close();
  jok();
}

/* ================= PREFS (tema/idioma/moeda) ================= */
if ($action === 'update_prefs') {
  $b = body_json();
  $tema   = in_array(($b['tema'] ?? 'auto'), ['dark','light','auto'], true) ? $b['tema'] : 'auto';
  $idioma = substr((string)($b['idioma'] ?? 'pt-BR'), 0, 10);
  $moeda  = substr((string)($b['moeda']  ?? 'BRL'),   0, 10);

  $st = $conn->prepare("UPDATE login SET tema=?, idioma=?, moeda=? WHERE id=?");
  $st->bind_param('sssi', $tema, $idioma, $moeda, $user_id);
  $st->execute(); $st->close();
  jok();
}

/* ================= SET NOTIFY AUTO (POR LOJA) ================= */
if ($action === 'set_notify_auto') {
  $b = body_json();
  $enabled = to_bool01($b['enabled'] ?? 1);
  set_shop_notify_auto($conn, $tenant_id, $shop_id, $enabled);
  jok(['notify_auto'=>$enabled]);
}

/* ================= MSG TEMPLATES (POR LOJA) ================= */
/* GET */
if ($action === 'get_msg_templates') {
  ensure_shop_templates_table($conn);
  ensure_shop_templates_row($conn, $tenant_id, $shop_id);
  $q = $conn->prepare("
    SELECT COALESCE(pendente,''), COALESCE(em_andamento,''), COALESCE(orcamento,''),
           COALESCE(aguardando_retirada,''), COALESCE(concluido,''),
           COALESCE(notify_auto,1)
      FROM shop_msg_templates
     WHERE tenant_id=? AND shop_id=? LIMIT 1
  ");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute();
  $q->bind_result($p,$e,$o,$ar,$c,$na);
  $q->fetch(); $q->close();

  jok([
    'templates'=>[
      'pendente'=>$p,'em_andamento'=>$e,'orcamento'=>$o,
      'aguardando_retirada'=>$ar,'concluido'=>$c
    ],
    'notify_auto'=>(int)($na ?? 1)
  ]);
}
/* SET */
if ($action === 'set_msg_templates') {
  ensure_shop_templates_table($conn);
  ensure_shop_templates_row($conn, $tenant_id, $shop_id);
  $b  = body_json();

  $p  = (string)($b['pendente']            ?? '');
  $e  = (string)($b['em_andamento']        ?? '');
  $o  = (string)($b['orcamento']           ?? '');
  $ar = (string)($b['aguardando_retirada'] ?? '');
  $c  = (string)($b['concluido']           ?? '');

  $st = $conn->prepare("
    UPDATE shop_msg_templates
       SET pendente=?,
           em_andamento=?,
           orcamento=?,
           aguardando_retirada=?,
           concluido=?,
           updated_at=NOW()
     WHERE tenant_id=? AND shop_id=?
  ");
  $st->bind_param('sssssii', $p,$e,$o,$ar,$c,$tenant_id,$shop_id);
  $st->execute(); $st->close();
  jok();
}

/* ================= EXPORT DATA (User + Loja minimal) ================= */
if ($action === 'export_data') {
  $q = $conn->prepare("
    SELECT id,email,telefone,nome,handle,avatar_url,
           COALESCE(notify_email,1),COALESCE(notify_push,0),COALESCE(notify_sms,0),
           COALESCE(tema,'auto'),COALESCE(idioma,'pt-BR'),COALESCE(moeda,'BRL'),
           created_at,updated_at
      FROM login WHERE id=? LIMIT 1
  ");
  $q->bind_param('i', $user_id); $q->execute();
  $res = $q->get_result(); $u = $res? $res->fetch_assoc() : [];
  $q->close();

  $prefs_shop = get_shop_prefs($conn, $tenant_id, $shop_id);

  jok(['data'=>[
    'user'=>$u,
    'tenant_id'=>$tenant_id,
    'shop_id'=>$shop_id,
    'shop_prefs'=>$prefs_shop
  ]]);
}

/* ===================== DEVICES (multi-sessão por loja) ===================== */
/* Lista devices da loja */
if ($action === 'wa_devices_list') {
  ensure_wa_devices_table($conn);
  $q = $conn->prepare("SELECT id, device_key, COALESCE(base_url,'') as base_url, active FROM wa_devices WHERE tenant_id=? AND shop_id=? ORDER BY id ASC");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute();
  $res = $q->get_result();
  $items = [];
  while($row = $res->fetch_assoc()) $items[] = $row;
  $q->close();
  jok(['items'=>$items]);
}

/* Vincula/cria device (se já existe, atualiza base_url e pode ativar) */
if ($action === 'wa_bind_device') {
  ensure_wa_devices_table($conn);
  $b = body_json();
  $device_key = trim((string)($b['device_key'] ?? ''));
  $base_url   = trim((string)($b['base_url']   ?? ''));
  $make_active = to_bool01($b['active'] ?? 1);

  if ($device_key === '') {
    // gera um key amigável
    $device_key = 'shop'.$tenant_id.'_'.$shop_id.'_'.substr(bin2hex(random_bytes(3)),0,6);
  }
  if ($base_url === '') $base_url = $GLOBALS['WA_BASE_DEFAULT'];

  // upsert simples
  $st = $conn->prepare("
    INSERT INTO wa_devices (tenant_id, shop_id, device_key, base_url, active)
    VALUES (?,?,?,?,?)
    ON DUPLICATE KEY UPDATE base_url=VALUES(base_url), updated_at=NOW()
  ");
  $st->bind_param('iissi', $tenant_id, $shop_id, $device_key, $base_url, $make_active);
  $st->execute(); $st->close();

  if ($make_active === 1) {
    $st2 = $conn->prepare("UPDATE wa_devices SET active=0 WHERE tenant_id=? AND shop_id=? AND device_key<>?");
    $st2->bind_param('iis', $tenant_id, $shop_id, $device_key);
    $st2->execute(); $st2->close();

    $st3 = $conn->prepare("UPDATE wa_devices SET active=1 WHERE tenant_id=? AND shop_id=? AND device_key=?");
    $st3->bind_param('iis', $tenant_id, $shop_id, $device_key);
    $st3->execute(); $st3->close();
  }

  jok(['device_key'=>$device_key, 'base_url'=>$base_url, 'active'=>$make_active]);
}

/* Define um device como ativo (desativa os demais) */
if ($action === 'wa_set_active') {
  ensure_wa_devices_table($conn);
  $b = body_json();
  $device_key = trim((string)($b['device_key'] ?? ''));
  if ($device_key === '') jerr('device_key obrigatório');

  $st1 = $conn->prepare("UPDATE wa_devices SET active=0 WHERE tenant_id=? AND shop_id=?");
  $st1->bind_param('ii', $tenant_id, $shop_id);
  $st1->execute(); $st1->close();

  $st2 = $conn->prepare("UPDATE wa_devices SET active=1 WHERE tenant_id=? AND shop_id=? AND device_key=?");
  $st2->bind_param('iis', $tenant_id, $shop_id, $device_key);
  $st2->execute(); $st2->close();

  jok(['active_device'=>$device_key]);
}

/* Remove um device da loja */
if ($action === 'wa_unbind_device') {
  ensure_wa_devices_table($conn);
  $b = body_json();
  $device_key = trim((string)($b['device_key'] ?? ''));
  if ($device_key === '') jerr('device_key obrigatório');

  $st = $conn->prepare("DELETE FROM wa_devices WHERE tenant_id=? AND shop_id=? AND device_key=?");
  $st->bind_param('iis', $tenant_id, $shop_id, $device_key);
  $st->execute(); $st->close();

  jok();
}

/* ============ WA PROXY: estado / QR (PNG/TEXTO) / reconnect / logout / reset ============ */

/* Status (resolve device automaticamente) */
if ($action === 'wa_status') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];

  $r = http_get_json_scoped($base, $dev['device_key'], '/status');
  if(!$r || ($r['ok'] ?? null) === false) jerr('Serviço WA offline', 503);

  $state = (!empty($r['connected']))
            ? 'connected'
            : (!empty($r['hasQR']) ? 'pairing' : 'connecting');

  jok([
    'state' => $state,
    'me'    => $r['me'] ?? null,
    'device_key' => $dev['device_key']
  ]);
}

/* QR como IMAGEM PNG (com fallback natural do seu front para texto) */
if ($action === 'wa_qr_img') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];

  header_remove('Content-Type'); // imagem
  $st = http_get_json_scoped($base, $dev['device_key'], '/status');
  if ($st && !empty($st['connected'])) { http_response_code(204); exit; }

  $r = http_get_json_scoped($base, $dev['device_key'], '/qr/png');
  if(!$r || ($r['ok'] ?? false) !== true || empty($r['dataUrl'])){
    // 404 para o <img> disparar onerror e o front fazer fallback para wa_qr_text
    http_response_code(404); exit;
  }

  $dataUrl = $r['dataUrl'];
  $pos = strpos($dataUrl, ',');
  if ($pos === false) { http_response_code(500); exit; }
  $b64 = substr($dataUrl, $pos + 1);
  $bin = base64_decode($b64, true);
  if ($bin === false) { http_response_code(500); exit; }

  header('Content-Type: image/png');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo $bin;
  exit;
}

/* QR como TEXTO (para o front desenhar com QRCode.js) */
if ($action === 'wa_qr_text') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];

  $st = http_get_json_scoped($base, $dev['device_key'], '/status');
  if ($st && !empty($st['connected'])) { http_response_code(204); exit; }

  $r = http_get_json_scoped($base, $dev['device_key'], '/qr');
  if (!$r || ($r['ok'] ?? false) !== true || empty($r['qr'])) { http_response_code(404); exit; }
  jok(['qr'=>$r['qr'], 'device_key'=>$dev['device_key']]);
}

/* Reconnect (fecha e reabre sem apagar auth) */
if ($action === 'wa_reconnect') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  $r = http_post_json_scoped($base, $dev['device_key'], '/reconnect', []);
  if(!$r || ($r['ok'] ?? false) !== true) jerr('Falha ao reconectar', 503);
  jok($r + ['device_key'=>$dev['device_key']]);
}

/* Logout (apaga auth e reinicia limpo) */
if ($action === 'wa_logout') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  $r = http_post_json_scoped($base, $dev['device_key'], '/logout', []);
  if(!$r || ($r['ok'] ?? false) !== true) jerr('Falha ao desconectar', 503);
  jok($r + ['device_key'=>$dev['device_key']]);
}

/* Reset auth (alias de logout) */
if ($action === 'wa_reset_auth') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  $r = http_post_json_scoped($base, $dev['device_key'], '/reset-auth', []);
  if(!$r || ($r['ok'] ?? false) !== true) jerr('Falha ao resetar auth', 503);
  jok($r + ['device_key'=>$dev['device_key']]);
}

/* fallback */
jerr("Ação inválida");
