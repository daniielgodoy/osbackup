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

/* Checa se uma coluna existe (para migração gradual) */
function table_has_col(mysqli $conn, string $table, string $col): bool {
  static $cache = [];
  $key = $table.'|'.$col;
  if (isset($cache[$key])) return $cache[$key];
  $stmt = $conn->prepare("
    SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND COLUMN_NAME = ?
     LIMIT 1
  ");
  $stmt->bind_param('ss', $table, $col);
  $stmt->execute(); $stmt->store_result();
  $exists = $stmt->num_rows > 0;
  $stmt->close();
  return $cache[$key] = $exists;
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

/* ───────── NOVAS TABELAS: Preferências de Impressão & Equipe ───────── */
function ensure_shop_print_prefs_table(mysqli $conn): void {
  // cria se não existir
  $conn->query("
    CREATE TABLE IF NOT EXISTS `shop_print_prefs` (
      `tenant_id` INT NOT NULL,
      `shop_id`   INT NOT NULL,
      `print_type` VARCHAR(24) NOT NULL DEFAULT 'A4',
      `per_page` INT NULL,
      `custom_w_mm` INT NULL,
      `custom_h_mm` INT NULL,
      `margin_top_mm` INT NULL,
      `margin_side_mm` INT NULL,
      `auto_print` TINYINT(1) NOT NULL DEFAULT 0,
      `auto_save_pdf` TINYINT(1) NOT NULL DEFAULT 0,
      `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`tenant_id`,`shop_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // adiciona colunas que porventura faltem (migração suave)
  if (!table_has_col($conn,'shop_print_prefs','print_type')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `print_type` VARCHAR(24) NOT NULL DEFAULT 'A4'");
  }
  if (!table_has_col($conn,'shop_print_prefs','per_page')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `per_page` INT NULL");
  }
  if (!table_has_col($conn,'shop_print_prefs','custom_w_mm')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `custom_w_mm` INT NULL");
  }
  if (!table_has_col($conn,'shop_print_prefs','custom_h_mm')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `custom_h_mm` INT NULL");
  }
  if (!table_has_col($conn,'shop_print_prefs','margin_top_mm')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `margin_top_mm` INT NULL");
  }
  if (!table_has_col($conn,'shop_print_prefs','margin_side_mm')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `margin_side_mm` INT NULL");
  }
  if (!table_has_col($conn,'shop_print_prefs','auto_print')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `auto_print` TINYINT(1) NOT NULL DEFAULT 0");
  }
  if (!table_has_col($conn,'shop_print_prefs','auto_save_pdf')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `auto_save_pdf` TINYINT(1) NOT NULL DEFAULT 0");
  }
  if (!table_has_col($conn,'shop_print_prefs','updated_at')) {
    $conn->query("ALTER TABLE `shop_print_prefs` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
  }
}

function ensure_shop_print_row(mysqli $conn, int $tenant_id, int $shop_id): void {
  ensure_shop_print_prefs_table($conn);
  $q = $conn->prepare("SELECT 1 FROM shop_print_prefs WHERE tenant_id=? AND shop_id=?");
  $q->bind_param('ii',$tenant_id,$shop_id);
  $q->execute(); $q->store_result();
  $exists = $q->num_rows>0; $q->close();
  if(!$exists){
    $st = $conn->prepare("INSERT INTO shop_print_prefs (tenant_id, shop_id) VALUES (?,?)");
    $st->bind_param('ii',$tenant_id,$shop_id);
    $st->execute(); $st->close();
  }
}

function ensure_shop_team_prefs_table(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS `shop_team_prefs` (
      `tenant_id` INT NOT NULL,
      `shop_id`   INT NOT NULL,
      `copy_panel_updates` TINYINT(1) NOT NULL DEFAULT 0,
      `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`tenant_id`,`shop_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
function ensure_shop_team_row(mysqli $conn, int $tenant_id, int $shop_id): void {
  ensure_shop_team_prefs_table($conn);
  $q = $conn->prepare("SELECT 1 FROM shop_team_prefs WHERE tenant_id=? AND shop_id=?");
  $q->bind_param('ii',$tenant_id,$shop_id);
  $q->execute(); $q->store_result();
  $exists = $q->num_rows>0; $q->close();
  if(!$exists){
    $st = $conn->prepare("INSERT INTO shop_team_prefs (tenant_id, shop_id) VALUES (?,?)");
    $st->bind_param('ii',$tenant_id,$shop_id);
    $st->execute(); $st->close();
  }
}

/* ───────── NOVA: mural de atividades da página Funcionários ───────── */
function ensure_staff_updates_table(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS `staff_updates` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `tenant_id` INT NOT NULL,
      `shop_id`   INT NOT NULL,
      `os_id`     INT NOT NULL,
      `title`     VARCHAR(255) NOT NULL,
      `message`   TEXT NULL,
      `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_tenant_shop_created` (`tenant_id`,`shop_id`,`created_at`),
      UNIQUE KEY `u_dedupe` (`tenant_id`,`shop_id`,`os_id`,`title`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

/* Cria (se precisar) um device padrão para a loja e opcionalmente ativa */
function ensure_default_device(mysqli $conn, int $tenant_id, int $shop_id, string $baseDefault): array {
  ensure_wa_devices_table($conn);

  $q = $conn->prepare("SELECT device_key, COALESCE(base_url,'') as base_url, active FROM wa_devices WHERE tenant_id=? AND shop_id=? AND active=1 ORDER BY id ASC LIMIT 1");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute();
  $a = $q->get_result()->fetch_assoc(); $q->close();
  if ($a) return ['device_key'=>$a['device_key'], 'base_url'=>$a['base_url'] ?: $baseDefault, 'active'=>1];

  $q = $conn->prepare("SELECT device_key, COALESCE(base_url,'') as base_url, active FROM wa_devices WHERE tenant_id=? AND shop_id=? ORDER BY id ASC LIMIT 1");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute();
  $r = $q->get_result()->fetch_assoc(); $q->close();
  if ($r) {
    $st = $conn->prepare("UPDATE wa_devices SET active=1 WHERE tenant_id=? AND shop_id=? AND device_key=?");
    $st->bind_param('iis', $tenant_id, $shop_id, $r['device_key']);
    $st->execute(); $st->close();

    $st2 = $conn->prepare("UPDATE wa_devices SET active=0 WHERE tenant_id=? AND shop_id=? AND device_key<>?");
    $st2->bind_param('iis', $tenant_id, $shop_id, $r['device_key']);
    $st2->execute(); $st2->close();

    return ['device_key'=>$r['device_key'], 'base_url'=>$r['base_url'] ?: $baseDefault, 'active'=>1];
  }

  $defaultKey = "shop{$tenant_id}_{$shop_id}_default";
  $st = $conn->prepare("INSERT IGNORE INTO wa_devices (tenant_id, shop_id, device_key, base_url, active) VALUES (?,?,?,?,1)");
  $st->bind_param('iiss', $tenant_id, $shop_id, $defaultKey, $baseDefault);
  $st->execute(); $st->close();

  return ['device_key'=>$defaultKey, 'base_url'=>$baseDefault, 'active'=>1];
}

/* Busca device solicitado/ativo; se nada existir, cria um padrão */
function resolve_device(mysqli $conn, int $tenant_id, int $shop_id, string $baseDefault): array {
  ensure_wa_devices_table($conn);
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
    $st = $conn->prepare("INSERT INTO wa_devices (tenant_id, shop_id, device_key, base_url, active) VALUES (?,?,?,?,1)");
    $st->bind_param('iiss', $tenant_id, $shop_id, $deviceParam, $baseDefault);
    $st->execute(); $st->close();
    return ['device_key'=>$deviceParam, 'base_url'=>$baseDefault, 'active'=>1];
  }
  return ensure_default_device($conn, $tenant_id, $shop_id, $baseDefault);
}

/* HTTP helpers para proxiar o serviço WA local */
function http_get_json_scoped(string $base, ?string $deviceKey, string $path, int $timeout=6): ?array {
  $url = rtrim($base, '/').$path;
  if ($deviceKey) $url .= (strpos($url,'?')===false ? '?' : '&').'device='.rawurlencode($deviceKey);
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>2]);
    $out = curl_exec($ch); $err = curl_errno($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($err || $out===false || $code>=400) return null;
  } else {
    $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true]]);
    $out = @file_get_contents($url, false, $ctx); if ($out === false) return null;
    $code = 200; if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~',$http_response_header[0],$m)) $code=(int)$m[1];
    if ($code>=400) return null;
  }
  $j = json_decode($out, true);
  return is_array($j) ? $j : null;
}
function http_post_json_scoped(string $base, ?string $deviceKey, string $path, array $payload=[], int $timeout=8): ?array {
  $url = rtrim($base, '/').$path;
  if ($deviceKey) $url .= (strpos($url,'?')===false ? '?' : '&').'device='.rawurlencode($deviceKey);
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
    $out = curl_exec($ch); $err = curl_errno($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($err || $out===false || $code>=400) return null;
  } else {
    $opts = ['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>json_encode($payload, JSON_UNESCAPED_UNICODE),'timeout'=>$timeout,'ignore_errors'=>true]];
    $ctx = stream_context_create($opts);
    $out = @file_get_contents($url, false, $ctx); if ($out === false) return null;
    $code = 200; if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~',$http_response_header[0],$m)) $code=(int)$m[1];
    if ($code>=400) return null;
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
  $q = $conn->prepare("
    SELECT id,email,telefone,nome,handle,avatar_url,
           COALESCE(notify_email,1),COALESCE(notify_push,0),COALESCE(notify_sms,0),
           COALESCE(tema,'auto'),COALESCE(idioma,'pt-BR'),COALESCE(moeda,'BRL')
      FROM login WHERE id=? LIMIT 1
  ");
  $q->bind_param('i', $user_id);
  $q->execute();
  $q->bind_result($uid,$email,$tel,$nome,$handle,$avatar,$n_email,$n_push,$n_sms,$tema,$idioma,$moeda);
  $ok = $q->fetch(); $q->close();

  $user = [
    'id'=>$uid ?? $user_id,
    'email'=>$email ?? '',
    'telefone'=>$tel ?? '',
    'nome'=>$nome ?? '',
    'handle'=>$handle ?? '',
    'avatar_url'=>$avatar ?? null
  ];
  $prefs_user = [
    'tema'         => $tema   ?? 'auto',
    'idioma'       => $idioma ?? 'pt-BR',
    'moeda'        => $moeda  ?? 'BRL',
    'notify_email' => (int)($n_email ?? 1),
    'notify_push'  => (int)($n_push  ?? 0),
    'notify_sms'   => (int)($n_sms   ?? 0),
  ];
  $prefs_shop = get_shop_prefs($conn, $tenant_id, $shop_id);

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

  $dev = ensure_default_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);

  ensure_shop_print_row($conn, $tenant_id, $shop_id);
  $pq = $conn->prepare("SELECT print_type, COALESCE(per_page,NULL), COALESCE(custom_w_mm,NULL), COALESCE(custom_h_mm,NULL), COALESCE(margin_top_mm,NULL), COALESCE(margin_side_mm,NULL), COALESCE(auto_print,0), COALESCE(auto_save_pdf,0) FROM shop_print_prefs WHERE tenant_id=? AND shop_id=? LIMIT 1");
  $pq->bind_param('ii', $tenant_id, $shop_id);
  $pq->execute();
  $pq->bind_result($pt,$pp,$cw,$ch,$mt,$ms,$ap,$as);
  $pq->fetch(); $pq->close();

  ensure_shop_team_row($conn, $tenant_id, $shop_id);
  $eq = $conn->prepare("SELECT COALESCE(copy_panel_updates,0) FROM shop_team_prefs WHERE tenant_id=? AND shop_id=? LIMIT 1");
  $eq->bind_param('ii',$tenant_id,$shop_id);
  $eq->execute(); $eq->bind_result($copyPanel); $eq->fetch(); $eq->close();

  jok([
    'user' => $user,
    'prefs' => $prefs_user + ['notify_auto' => (int)$prefs_shop['notify_auto']],
    'msg_templates' => $tpls,
    'print_prefs' => [
      'print_type'   => $pt ?: 'A4',
      'per_page'     => $pp,
      'custom'       => ['w'=>$cw,'h'=>$ch,'m_top'=>$mt,'m_side'=>$ms],
      'auto_print'   => (int)$ap,
      'auto_save_pdf'=> (int)$as
    ],
    'team_prefs' => ['copy_panel_updates' => (int)$copyPanel],
    'sessions'=>[], 'pay_methods'=>[], 'invoices'=>[],
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
  $res = $q->get_result(); $row = $res? $res->fetch_row() : null; $h = $row[0] ?? null; $q->close();
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
  if ($handle && !preg_match('~^[a-z0-9][a-z0-9\-_.]{2,30}$~i', $handle)) jerr("Handle inválido");
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
if ($action === 'get_msg_templates') {
  ensure_shop_templates_table($conn);
  ensure_shop_templates_row($conn, $tenant_id, $shop_id);
  $q = $conn->prepare("
    SELECT COALESCE(pendente,''), COALESCE(em_andamento,''), COALESCE(orcamento,''),
           COALESCE(aguardando_retirada,''), COALESCE(concluido,''), COALESCE(notify_auto,1)
      FROM shop_msg_templates
     WHERE tenant_id=? AND shop_id=? LIMIT 1
  ");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute(); $q->bind_result($p,$e,$o,$ar,$c,$na); $q->fetch(); $q->close();
  jok(['templates'=>['pendente'=>$p,'em_andamento'=>$e,'orcamento'=>$o,'aguardando_retirada'=>$ar,'concluido'=>$c],'notify_auto'=>(int)($na ?? 1)]);
}
if ($action === 'set_msg_templates') {
  ensure_shop_templates_table($conn);
  ensure_shop_templates_row($conn, $tenant_id, $shop_id);
  $b = body_json();
  $p  = (string)($b['pendente']            ?? '');
  $e  = (string)($b['em_andamento']        ?? '');
  $o  = (string)($b['orcamento']           ?? '');
  $ar = (string)($b['aguardando_retirada'] ?? '');
  $c  = (string)($b['concluido']           ?? '');
  $st = $conn->prepare("
    UPDATE shop_msg_templates
       SET pendente=?, em_andamento=?, orcamento=?, aguardando_retirada=?, concluido=?, updated_at=NOW()
     WHERE tenant_id=? AND shop_id=?
  ");
  $st->bind_param('sssssii', $p,$e,$o,$ar,$c,$tenant_id,$shop_id);
  $st->execute(); $st->close();
  jok();
}

/* ================= EXPORT DATA ================= */
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
  jok(['data'=>['user'=>$u,'tenant_id'=>$tenant_id,'shop_id'=>$shop_id,'shop_prefs'=>$prefs_shop]]);
}

/* ===================== DEVICES ===================== */
if ($action === 'wa_devices_list') {
  ensure_wa_devices_table($conn);
  $q = $conn->prepare("SELECT id, device_key, COALESCE(base_url,'') as base_url, active FROM wa_devices WHERE tenant_id=? AND shop_id=? ORDER BY id ASC");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute();
  $res = $q->get_result(); $items = [];
  while($row = $res->fetch_assoc()) $items[] = $row;
  $q->close();
  jok(['items'=>$items]);
}
if ($action === 'wa_bind_device') {
  ensure_wa_devices_table($conn);
  $b = body_json();
  $device_key = trim((string)($b['device_key'] ?? ''));
  $base_url   = trim((string)($b['base_url']   ?? ''));
  $make_active = to_bool01($b['active'] ?? 1);
  if ($device_key === '') $device_key = 'shop'.$tenant_id.'_'.$shop_id.'_'.substr(bin2hex(random_bytes(3)),0,6);
  if ($base_url === '') $base_url = $GLOBALS['WA_BASE_DEFAULT'];
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

/* ============ WA PROXY: estado / QR / reconnect / logout / reset ============ */
if ($action === 'wa_status') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  $r = http_get_json_scoped($base, $dev['device_key'], '/status');
  if(!$r || ($r['ok'] ?? null) === false) jerr('Serviço WA offline', 503);
  $state = (!empty($r['connected'])) ? 'connected' : (!empty($r['hasQR']) ? 'pairing' : 'connecting');
  jok(['state'=>$state,'me'=>$r['me'] ?? null,'device_key'=>$dev['device_key']]);
}
if ($action === 'wa_qr_img') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  header_remove('Content-Type');
  $st = http_get_json_scoped($base, $dev['device_key'], '/status');
  if ($st && !empty($st['connected'])) { http_response_code(204); exit; }
  $r = http_get_json_scoped($base, $dev['device_key'], '/qr/png');
  if(!$r || ($r['ok'] ?? false) !== true || empty($r['dataUrl'])){ http_response_code(404); exit; }
  $dataUrl = $r['dataUrl']; $pos = strpos($dataUrl, ','); if ($pos === false) { http_response_code(500); exit; }
  $b64 = substr($dataUrl, $pos + 1); $bin = base64_decode($b64, true); if ($bin === false) { http_response_code(500); exit; }
  header('Content-Type: image/png'); header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); echo $bin; exit;
}
if ($action === 'wa_qr_text') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  $st = http_get_json_scoped($base, $dev['device_key'], '/status');
  if ($st && !empty($st['connected'])) { http_response_code(204); exit; }
  $r = http_get_json_scoped($base, $dev['device_key'], '/qr');
  if (!$r || ($r['ok'] ?? false) !== true || empty($r['qr'])) { http_response_code(404); exit; }
  jok(['qr'=>$r['qr'], 'device_key'=>$dev['device_key']]);
}
if ($action === 'wa_reconnect') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  $r = http_post_json_scoped($base, $dev['device_key'], '/reconnect', []);
  if(!$r || ($r['ok'] ?? false) !== true) jerr('Falha ao reconectar', 503);
  jok($r + ['device_key'=>$dev['device_key']]);
}
if ($action === 'wa_logout') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  $r = http_post_json_scoped($base, $dev['device_key'], '/logout', []);
  if(!$r || ($r['ok'] ?? false) !== true) jerr('Falha ao desconectar', 503);
  jok($r + ['device_key'=>$dev['device_key']]);
}
if ($action === 'wa_reset_auth') {
  $dev = resolve_device($conn, $tenant_id, $shop_id, $WA_BASE_DEFAULT);
  $base = $dev['base_url'] ?: $GLOBALS['WA_BASE_DEFAULT'];
  $r = http_post_json_scoped($base, $dev['device_key'], '/reset-auth', []);
  if(!$r || ($r['ok'] ?? false) !== true) jerr('Falha ao resetar auth', 503);
  jok($r + ['device_key'=>$dev['device_key']]);
}

/* ====================== IMPRESSÃO (por Loja) ====================== */
/* Normalização de tipos vindos do front e retorno para o front */
function map_front_to_db(string $v): string {
  $v = strtoupper(trim($v));
  $map = [
    'A4' => 'A4',
    'A5' => 'A5',
    'A4_HALF'   => 'HALF',
    'THERMAL_80'=> 'THERMAL80',
    'THERMAL_58'=> 'THERMAL58',
    'CUSTOM'    => 'CUSTOM',
  ];
  return $map[$v] ?? 'A4';
}
function map_db_to_front(string $v): string {
  $v = strtoupper(trim($v));
  $map = [
    'A4' => 'A4',
    'A5' => 'A5',
    'HALF' => 'A4_HALF',
    'THERMAL80' => 'THERMAL_80',
    'THERMAL58' => 'THERMAL_58',
    'CUSTOM' => 'CUSTOM',
  ];
  return $map[$v] ?? 'A4';
}

if ($action === 'get_print_prefs') {
  ensure_shop_print_row($conn, $tenant_id, $shop_id);
  $q = $conn->prepare("SELECT print_type, per_page, custom_w_mm, custom_h_mm, margin_top_mm, margin_side_mm, COALESCE(auto_print,0), COALESCE(auto_save_pdf,0) FROM shop_print_prefs WHERE tenant_id=? AND shop_id=? LIMIT 1");
  $q->bind_param('ii',$tenant_id,$shop_id);
  $q->execute(); $q->bind_result($pt,$pp,$cw,$ch,$mt,$ms,$ap,$as); $q->fetch(); $q->close();

  jok([
    'print_type'      => map_db_to_front($pt ?: 'A4'),
    'items_per_page'  => $pp,
    'per_page'        => $pp, // compat
    'custom'          => ['w'=>$cw,'h'=>$ch,'m_top'=>$mt,'m_side'=>$ms],
    'auto_print'      => (int)$ap,
    'auto_save_pdf'   => (int)$as
  ]);
}

if ($action === 'set_print_prefs') {
  $b = body_json();
  $print_type   = map_front_to_db((string)($b['print_type'] ?? 'A4'));
  $auto_print   = to_bool01($b['auto_print']   ?? 0);
  $auto_save_pdf= to_bool01($b['auto_save_pdf']?? 0);
  $per_page     = isset($b['per_page']) ? max(1, min(100, (int)$b['per_page'])) : null;

  $cw=$ch=$mt=$ms=null;
  if (!empty($b['custom']) && is_array($b['custom'])) {
    $cw = isset($b['custom']['w']) ? (int)$b['custom']['w'] : null;
    $ch = isset($b['custom']['h']) ? (int)$b['custom']['h'] : null;
    $mt = isset($b['custom']['m_top']) ? (int)$b['custom']['m_top'] : null;
    $ms = isset($b['custom']['m_side'])? (int)$b['custom']['m_side']: null;
  }

  ensure_shop_print_row($conn, $tenant_id, $shop_id);
  $st = $conn->prepare("
    INSERT INTO shop_print_prefs (tenant_id,shop_id,print_type,per_page,custom_w_mm,custom_h_mm,margin_top_mm,margin_side_mm,auto_print,auto_save_pdf)
    VALUES (?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      print_type=VALUES(print_type),
      per_page=VALUES(per_page),
      custom_w_mm=VALUES(custom_w_mm),
      custom_h_mm=VALUES(custom_h_mm),
      margin_top_mm=VALUES(margin_top_mm),
      margin_side_mm=VALUES(margin_side_mm),
      auto_print=VALUES(auto_print),
      auto_save_pdf=VALUES(auto_save_pdf),
      updated_at=NOW()
  ");
  $st->bind_param('iisiiiiiii', $tenant_id,$shop_id,$print_type,$per_page,$cw,$ch,$mt,$ms,$auto_print,$auto_save_pdf);
  $st->execute(); $st->close();
  jok([
    'print_type'=>$print_type,
    'per_page'=>$per_page,
    'custom'=>['w'=>$cw,'h'=>$ch,'m_top'=>$mt,'m_side'=>$ms],
    'auto_print'=>$auto_print,
    'auto_save_pdf'=>$auto_save_pdf
  ]);
}

/* === Aliases (compat com teu front atual) === */
if ($action === 'print_get') {
  ensure_shop_print_row($conn, $tenant_id, $shop_id);
  $q = $conn->prepare("SELECT print_type, per_page, custom_w_mm, custom_h_mm, margin_top_mm, margin_side_mm, COALESCE(auto_print,0), COALESCE(auto_save_pdf,0) FROM shop_print_prefs WHERE tenant_id=? AND shop_id=? LIMIT 1");
  $q->bind_param('ii',$tenant_id,$shop_id);
  $q->execute(); $q->bind_result($pt,$pp,$cw,$ch,$mt,$ms,$ap,$as); $q->fetch(); $q->close();
  jok([
    'tipo'=> map_db_to_front($pt ?: 'A4'),
    'per_page'=>$pp,
    'custom'=> ['w'=>$cw,'h'=>$ch,'m_top'=>$mt,'m_side'=>$ms],
    'auto_print'=>(int)$ap,
    'auto_pdf'=>(int)$as
  ]);
}
if ($action === 'print_set') {
  $b = body_json();
  $print_type = map_front_to_db((string)($b['tipo'] ?? ($b['print_type'] ?? 'A4')));
  $auto_print = to_bool01($b['auto_print'] ?? ($b['auto'] ?? 0));
  $auto_pdf   = to_bool01($b['auto_pdf']   ?? ($b['auto_save_pdf'] ?? 0));
  $per_page   = isset($b['per_page']) ? max(1, min(100, (int)$b['per_page'])) : null;

  $cw=$ch=$mt=$ms=null;
  if (!empty($b['custom']) && is_array($b['custom'])) {
    $cw = isset($b['custom']['w']) ? (int)$b['custom']['w'] : null;
    $ch = isset($b['custom']['h']) ? (int)$b['custom']['h'] : null;
    $mt = isset($b['custom']['m_top']) ? (int)$b['custom']['m_top'] : null;
    $ms = isset($b['custom']['m_side'])? (int)$b['custom']['m_side']: null;
  }

  ensure_shop_print_row($conn, $tenant_id, $shop_id);
  $st = $conn->prepare("
    INSERT INTO shop_print_prefs (tenant_id,shop_id,print_type,per_page,custom_w_mm,custom_h_mm,margin_top_mm,margin_side_mm,auto_print,auto_save_pdf)
    VALUES (?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      print_type=VALUES(print_type),
      per_page=VALUES(per_page),
      custom_w_mm=VALUES(custom_w_mm),
      custom_h_mm=VALUES(custom_h_mm),
      margin_top_mm=VALUES(margin_top_mm),
      margin_side_mm=VALUES(margin_side_mm),
      auto_print=VALUES(auto_print),
      auto_save_pdf=VALUES(auto_save_pdf),
      updated_at=NOW()
  ");
  $st->bind_param('iisiiiiiii', $tenant_id,$shop_id,$print_type,$per_page,$cw,$ch,$mt,$ms,$auto_print,$auto_pdf);
  $st->execute(); $st->close();
  jok([
    'tipo'=> map_db_to_front($print_type),
    'per_page'=>$per_page,
    'custom'=>['w'=>$cw,'h'=>$ch,'m_top'=>$mt,'m_side'=>$ms],
    'auto_print'=>$auto_print,
    'auto_pdf'=>$auto_pdf
  ]);
}

/* ====================== EQUIPE: Cópia do Painel ====================== */
if ($action === 'get_team_prefs') {
  ensure_shop_team_row($conn, $tenant_id, $shop_id);
  $q = $conn->prepare("SELECT COALESCE(copy_panel_updates,0) FROM shop_team_prefs WHERE tenant_id=? AND shop_id=? LIMIT 1");
  $q->bind_param('ii',$tenant_id,$shop_id);
  $q->execute(); $q->bind_result($c); $q->fetch(); $q->close();
  jok(['copy_panel_updates'=>(int)$c]);
}
if ($action === 'set_team_prefs') {
  $b = body_json();
  $copy = to_bool01($b['copy_panel_updates'] ?? 0);
  ensure_shop_team_row($conn, $tenant_id, $shop_id);
  $st = $conn->prepare("UPDATE shop_team_prefs SET copy_panel_updates=? WHERE tenant_id=? AND shop_id=?");
  $st->bind_param('iii',$copy,$tenant_id,$shop_id);
  $st->execute(); $st->close();
  jok(['copy_panel_updates'=>$copy]);
}

/* Lista atualizações recentes do painel */
if ($action === 'team_updates_recent') {
  $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
  $cols = "id, client_id, nome, modelo, status, atualizado_em";
  $table = "ordens_servico";
  $where = "1=1"; $params=[]; $types='';
  if (table_has_col($conn,$table,'tenant_id')) { $where.=" AND tenant_id=?"; $types.='i'; $params[]=$tenant_id; }
  if (table_has_col($conn,$table,'shop_id'))   { $where.=" AND shop_id=?";   $types.='i'; $params[]=$shop_id; }
  $sql = "SELECT $cols FROM $table WHERE $where ORDER BY atualizado_em DESC, id DESC LIMIT ?";
  $types.='i'; $params[]=$limit;
  $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params);
  $stmt->execute(); $res = $stmt->get_result();
  $items = [];
  while($row = $res->fetch_assoc()){
    $items[] = [
      'id'=>(int)$row['id'],
      'client_id'=>isset($row['client_id'])?(int)$row['client_id']:null,
      'nome'=>$row['nome'] ?? '',
      'modelo'=>$row['modelo'] ?? '',
      'status'=>$row['status'] ?? '',
      'atualizado_em'=>$row['atualizado_em'] ?? null,
    ];
  }
  $stmt->close();
  jok(['items'=>$items]);
}

/* Copia para o mural da página Funcionários */
if ($action === 'staff_copy_panel') {
  $b = body_json();
  $days = max(1, min(60, (int)($b['days'] ?? 7)));
  ensure_staff_updates_table($conn);
  $table = "ordens_servico";
  $dtCol = 'atualizado_em';
  if (!table_has_col($conn,$table,$dtCol)) $dtCol = table_has_col($conn,$table,'updated_at') ? 'updated_at' : (table_has_col($conn,$table,'data_conclusao') ? 'data_conclusao' : null);
  $where = "1=1"; $types=''; $params=[];
  if (table_has_col($conn,$table,'tenant_id')) { $where.=" AND tenant_id=?"; $types.='i'; $params[]=$tenant_id; }
  if (table_has_col($conn,$table,'shop_id'))   { $where.=" AND shop_id=?";   $types.='i'; $params[]=$shop_id; }
  if ($dtCol) { $where.=" AND $dtCol >= DATE_SUB(NOW(), INTERVAL ? DAY)"; $types.='i'; $params[]=$days; }
  $cols = "id, nome, modelo, status";
  if (table_has_col($conn,$table,'valor_total')) $cols .= ", valor_total";
  $sql = "SELECT $cols FROM $table WHERE $where ORDER BY ".($dtCol?$dtCol:'id')." DESC LIMIT 200";
  $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params);
  $stmt->execute(); $res = $stmt->get_result();
  $copiados = 0;
  $ins = $conn->prepare("INSERT IGNORE INTO staff_updates (tenant_id,shop_id,os_id,title,message) VALUES (?,?,?,?,?)");
  while($row = $res->fetch_assoc()){
    $osId = (int)$row['id'];
    $nome = trim($row['nome'] ?? '');
    $modelo = trim($row['modelo'] ?? '');
    $status = strtoupper(trim($row['status'] ?? ''));
    $valor = $row['valor_total'] ?? null;
    $title = "OS #$osId — $status";
    $msgParts = [];
    if ($nome)   $msgParts[] = "Cliente: $nome";
    if ($modelo) $msgParts[] = "Modelo: $modelo";
    if ($valor !== null && $valor !== '') $msgParts[] = "Valor: $valor";
    $message = implode(" | ", $msgParts);
    $ins->bind_param('iiiss', $tenant_id,$shop_id,$osId,$title,$message);
    $ins->execute();
    if ($ins->affected_rows > 0) $copiados++;
  }
  $ins->close(); $stmt->close();
  jok(['copiados'=>$copiados]);
}

/* ====================== MOCKS SESSÕES & PAGAMENTOS ====================== */
if ($action === 'list_sessions') {
  $_SESSION['__mock_sessions'] = $_SESSION['__mock_sessions'] ?? [
    ['id'=>1,'device'=>'Chrome Windows','ip'=>'127.0.0.1','last_seen'=>date('Y-m-d H:i')],
  ];
  jok(['items'=>$_SESSION['__mock_sessions']]);
}
if ($action === 'logout_session') {
  $b = body_json(); $id = (int)($b['id'] ?? 0);
  $_SESSION['__mock_sessions'] = array_values(array_filter($_SESSION['__mock_sessions'] ?? [], fn($s)=> (int)$s['id'] !== $id));
  jok();
}
if ($action === 'logout_all') { $_SESSION['__mock_sessions'] = []; jok(); }

if ($action === 'list_pay') { $_SESSION['__mock_pay'] = $_SESSION['__mock_pay'] ?? []; jok(['items'=>$_SESSION['__mock_pay']]); }
if ($action === 'add_pay_method') {
  $b = body_json();
  $brand = strtoupper(trim((string)($b['brand'] ?? 'VISA')));
  $last4 = preg_replace('~\D+~','', (string)($b['last4'] ?? '0000')); if (strlen($last4) > 4) $last4 = substr($last4,-4);
  $list = $_SESSION['__mock_pay'] ?? []; $id = count($list) ? (max(array_column($list,'id'))+1) : 1;
  $list[] = ['id'=>$id,'brand'=>$brand,'last4'=>$last4]; $_SESSION['__mock_pay'] = $list; jok();
}
if ($action === 'delete_pay_method') {
  $b = body_json(); $id = (int)($b['id'] ?? 0);
  $_SESSION['__mock_pay'] = array_values(array_filter($_SESSION['__mock_pay'] ?? [], fn($m)=> (int)$m['id'] !== $id));
  jok();
}
if ($action === 'set_default_pay') { jok(); }
if ($action === 'change_plan') {
  $b = body_json(); $plan = ($b['plan'] ?? 'free');
  $_SESSION['__mock_plan'] = ['name'=>$plan==='pro'?'Pro':'Free','renova_em'=>date('Y-m-d', strtotime('+30 days'))];
  jok(['plan'=>$_SESSION['__mock_plan']]);
}
if ($action === 'cancel_auto') {
  $_SESSION['__mock_plan'] = $_SESSION['__mock_plan'] ?? ['name'=>'Free','renova_em'=>'—'];
  $_SESSION['__mock_plan']['renova_em'] = '—';
  jok();
}

/* fallback */
jerr("Ação inválida");
