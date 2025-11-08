<?php
// includes/auth_api.php
declare(strict_types=1);

// ───── Cabeçalhos básicos + OPTIONS ─────
header_remove('Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/auth_guard.php'; // precisa ter establish_session(...)

// ───── Helpers ─────
function read_json_or_form(): array {
  $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?? '', true);
    return is_array($j) ? $j : [];
  }
  return $_POST ? (array)$_POST : [];
}

function norm_handle(string $s): string {
  $s = trim(ltrim($s, '@'));
  return preg_replace('~[^a-zA-Z0-9_.]+~', '', $s) ?? '';
}

function slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('~[^\pL\d]+~u', '-', $s);
  $s = trim($s, '-');
  $s = preg_replace('~[^-\w]+~', '', $s);
  return $s === '' ? 'tenant' : substr($s, 0, 60);
}

/** Retorna um set (array flipado) das colunas existentes em uma tabela. Cache simples por request. */
function table_columns(mysqli $conn, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $cols = [];
  $rs = $conn->query("SHOW COLUMNS FROM `$table`");
  while ($row = $rs->fetch_assoc()) $cols[$row['Field']] = true;
  return $cache[$table] = $cols;
}
function has_col(mysqli $conn, string $table, string $col): bool {
  $cols = table_columns($conn, $table);
  return isset($cols[$col]);
}

$action = $_GET['action'] ?? '';

try {
  if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Método não permitido. Esperado POST.']);
    exit;
  }

  $body = read_json_or_form();

  // ─────────────────────────────
  // LOGIN
  // ─────────────────────────────
  if ($action === 'login') {
    $identifier = trim((string)($body['identifier'] ?? '')); // email ou usuário
    $password   = (string)($body['password'] ?? '');
    $remember   = (int)($body['remember'] ?? 0);

    if ($identifier === '' || $password === '') {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'Informe usuário/e-mail e senha.']);
      exit;
    }

    // Monta SELECT dinâmico para só pegar colunas que existem
    $loginCols = table_columns($conn, 'login');
    $selectFields = ['id','tenant_id','shop_id','email','handle','password_hash','nome'];
    $maybe = ['tema','idioma']; // opcionais
    foreach ($maybe as $c) if (isset($loginCols[$c])) $selectFields[] = $c;

    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    $where = $isEmail ? 'email = ?' : 'handle = ?';
    $value = $isEmail ? $identifier : norm_handle($identifier);

    $sql = "SELECT ".implode(', ', $selectFields)." FROM login WHERE $where LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
      http_response_code(401);
      echo json_encode(['ok'=>false,'error'=>'Credenciais inválidas.']);
      exit;
    }

    // Atualiza last_login_* somente se as colunas existirem
    if (has_col($conn,'login','last_login_at') || has_col($conn,'login','last_login_ip')) {
      $sets = []; $types=''; $vals=[];
      if (has_col($conn,'login','last_login_at')) { $sets[]='last_login_at = NOW()'; }
      if (has_col($conn,'login','last_login_ip')) { $sets[]='last_login_ip = ?'; $types.='s'; $vals[] = ($_SERVER['REMOTE_ADDR'] ?? ''); }
      if ($sets) {
        $sqlU = "UPDATE login SET ".implode(', ',$sets)." WHERE id = ?";
        $types.='i'; $vals[] = (int)$user['id'];
        $upd = $conn->prepare($sqlU);
        $upd->bind_param($types, ...$vals);
        $upd->execute();
        $upd->close();
      }
    }

    $tenant_id = (int)($user['tenant_id'] ?? 0);
    $shop_id   = isset($user['shop_id']) ? (int)$user['shop_id'] : null;
    if ($tenant_id <= 0) {
      http_response_code(409);
      echo json_encode(['ok'=>false,'error'=>'Usuário sem tenant vinculado.']);
      exit;
    }

    // Abre sessão
    establish_session(
      (int)$user['id'],
      $tenant_id,
      $shop_id,
      [
        'nome'   => $user['nome']   ?? null,
        'email'  => $user['email']  ?? null,
        'tema'   => $user['tema']   ?? 'dark',
        'idioma' => $user['idioma'] ?? 'pt-BR',
      ]
    );

    // Lembrar por 30 dias
    if ($remember === 1) {
      $lifetime = 60*60*24*30;
      $params = session_get_cookie_params();
      setcookie(session_name(), session_id(), [
        'expires'  => time() + $lifetime,
        'path'     => $params['path'] ?? '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
      ]);
    }

    echo json_encode(['ok'=>true,'user'=>[
      'id'=>(int)$user['id'],
      'tenant_id'=>$tenant_id,
      'shop_id'=>$shop_id,
      'nome'=>$user['nome'] ?? null,
      'email'=>$user['email'] ?? null,
      'handle'=>$user['handle'] ?? null,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ─────────────────────────────
  // REGISTER
  // ─────────────────────────────
  if ($action === 'register') {
    $nome     = trim((string)($body['nome'] ?? ''));
    $handleIn = trim((string)($body['handle'] ?? ''));
    $email    = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($nome === '' || $email === '' || $password === '') {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'Preencha nome, e-mail e senha.']);
      exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'E-mail inválido.']);
      exit;
    }
    $handle = norm_handle($handleIn !== '' ? $handleIn : strtok($email, '@'));
    if (strlen($handle) < 3) $handle .= substr(sha1((string)mt_rand()), 0, 4);

    // Evita duplicidade
    $stmt = $conn->prepare("SELECT 1 FROM login WHERE email = ? OR handle = ? LIMIT 1");
    $stmt->bind_param('ss', $email, $handle);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($dup) {
      http_response_code(409);
      echo json_encode(['ok'=>false,'error'=>'E-mail ou usuário já cadastrados.']);
      exit;
    }

    $conn->begin_transaction();
    try {
      // tenants
      $tenant_name = $nome !== '' ? $nome : 'Minha Empresa';
      $slug = slugify($tenant_name);
      // resolve slug duplicado simples
      $try = $slug; $n=1;
      while (true) {
        $q = $conn->prepare("SELECT 1 FROM tenants WHERE slug = ? LIMIT 1");
        $q->bind_param('s', $try); $q->execute();
        $exists = $q->get_result()->fetch_row(); $q->close();
        if (!$exists) { $slug = $try; break; }
        $n++; $try = $slug . '-' . $n;
        if ($n > 50) { $slug .= '-' . substr(sha1(uniqid('', true)),0,4); break; }
      }

      $insT = $conn->prepare("INSERT INTO tenants (nome, slug, status, created_at, updated_at) VALUES (?, ?, 'ativo', NOW(), NOW())");
      $insT->bind_param('ss', $tenant_name, $slug);
      $insT->execute();
      $tenant_id = (int)$insT->insert_id;
      $insT->close();

      // shops (loja padrão)
      $shop_name = 'Loja 1';
      $insS = $conn->prepare("INSERT INTO shops (tenant_id, nome, status, created_at, updated_at) VALUES (?, ?, 'ativa', NOW(), NOW())");
      $insS->bind_param('is', $tenant_id, $shop_name);
      $insS->execute();
      $shop_id = (int)$insS->insert_id;
      $insS->close();

      // login (usuário) -> monta INSERT conforme colunas existentes
      $loginCols = table_columns($conn, 'login');
      $cols = ['tenant_id','shop_id','email','password_hash','nome','handle','created_at','updated_at'];
      $vals = [$tenant_id, $shop_id, $email, password_hash($password, PASSWORD_DEFAULT), $nome, $handle];
      $types = 'iissss';

      // opcionalmente adiciona tema/idioma/moeda/notify se existirem
      if (isset($loginCols['tema']))   { $cols[]='tema';   $vals[]='dark';  $types.='s'; }
      if (isset($loginCols['idioma'])) { $cols[]='idioma'; $vals[]='pt-BR'; $types.='s'; }
      if (isset($loginCols['moeda']))  { $cols[]='moeda';  $vals[]='BRL';   $types.='s'; }
      if (isset($loginCols['notify_auto'])) { $cols[]='notify_auto'; $vals[]=1; $types.='i'; }
      if (isset($loginCols['notify_email'])){ $cols[]='notify_email';$vals[]=1; $types.='i'; }
      if (isset($loginCols['notify_push'])) { $cols[]='notify_push'; $vals[]=0; $types.='i'; }
      if (isset($loginCols['notify_sms']))  { $cols[]='notify_sms';  $vals[]=0; $types.='i'; }

      // created_at e updated_at com NOW() se existirem, senão usa placeholders
      $place = [];
      foreach ($cols as $c) {
        if (($c === 'created_at' || $c === 'updated_at') && isset($loginCols[$c])) {
          $place[] = 'NOW()';
        } else if ($c === 'created_at' || $c === 'updated_at') {
          // coluna nem existe -> remove do array
          array_pop($cols); // remove o c adicionado
          continue;
        } else {
          $place[] = '?';
        }
      }

      $sqlU = "INSERT INTO login (".implode(',', $cols).") VALUES (".implode(',', $place).")";
      $insU = $conn->prepare($sqlU);

      // Recalcula types/vals após possível remoção de created_at/updated_at
      $bindTypes=''; $bindVals=[];
      $i=0;
      foreach ($cols as $c) {
        if ($c==='created_at' || $c==='updated_at') continue; // NOW()
        // usa os mesmos $vals/$types na ordem
        $bindVals[] = $vals[$i];
        $i++;
      }
      // Recria $bindTypes baseado em $bindVals
      foreach ($bindVals as $v) $bindTypes .= is_int($v) ? 'i' : 's';

      if ($bindTypes !== '') $insU->bind_param($bindTypes, ...$bindVals);
      $insU->execute();
      $user_id = (int)$insU->insert_id;
      $insU->close();

      $conn->commit();
    } catch (\Throwable $e) {
      $conn->rollback();
      throw $e;
    }

    // Abre sessão
    establish_session($user_id, $tenant_id, $shop_id, [
      'nome'=>$nome, 'email'=>$email, 'tema'=>'dark', 'idioma'=>'pt-BR'
    ]);

    echo json_encode(['ok'=>true,'user'=>[
      'id'=>$user_id, 'tenant_id'=>$tenant_id, 'shop_id'=>$shop_id,
      'email'=>$email, 'handle'=>$handle, 'nome'=>$nome
    ]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  http_response_code(404);
  echo json_encode(['ok'=>false,'error'=>'Ação não encontrada.']);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Erro interno','detail'=>$e->getMessage()]);
}
