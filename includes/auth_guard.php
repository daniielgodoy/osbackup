<?php
// includes/auth_guard.php
declare(strict_types=1);

/**
 * Guard de autenticação/unidade multi-tenant + roles (admin/member)
 *
 * - NÃO abre conexão sozinho na inclusão (evita conflito com index/mysqli.php).
 * - Quando precisa de dados frescos do usuário, carrega via mysqli.php (lazy).
 * - Usa login.role (admin|member), login.is_active, login.tenant_id, login.shop_id.
 */

/* ───── Sessão segura ───── */
function init_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
    $params = session_get_cookie_params();
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => $params['path'] ?? '/',
      'domain'   => $params['domain'] ?? '',
      'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_start();
  }
}
init_session();

/* ───── Util: detectar se é API/AJAX (responder JSON) ou página (redirecionar) ───── */
function is_ajax(): bool {
  return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
function wants_json(): bool {
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  return stripos($accept, 'application/json') !== false;
}
function is_api_path(): bool {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $file   = basename($script);
  return (strpos($script, '/includes/') !== false)
      || str_ends_with($file, '_api.php')
      || str_starts_with($file, 'api_');
}
function is_api_request(): bool {
  return is_ajax() || wants_json() || is_api_path();
}

/* ───── Caminho correto do login (../login.php quando está em /includes) ───── */
function login_location(): string {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  return (strpos($script, '/includes/') !== false) ? '../login.php' : 'login.php';
}

/* ───── Helpers de sessão básicos ───── */
function establish_session(int $user_id, int $tenant_id, ?int $shop_id, array $profile = []): void {
  init_session();
  session_regenerate_id(true);

  $_SESSION['user_id']   = $user_id;
  $_SESSION['tenant_id'] = $tenant_id;
  $_SESSION['shop_id']   = $shop_id; // pode ser null

  if (!empty($profile)) {
    foreach (['nome','email','tema','idioma','role'] as $k) {
      if (array_key_exists($k, $profile)) {
        $_SESSION['profile'][$k] = $profile[$k];
      }
    }
    if (isset($profile['role'])) {
      $_SESSION['role'] = $profile['role'];
    }
  }

  $_SESSION['last_activity'] = time();
}

function destroy_session(): void {
  init_session();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params['path'] ?? '/',
      $params['domain'] ?? '',
      isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
      true
    );
  }
  session_destroy();
}

function session_user_id(): int {
  init_session();
  return (int)($_SESSION['user_id'] ?? 0);
}

/* ───── DB lazy (só quando precisa) ───── */
/**
 * Carrega mysqli.php apenas quando for necessário dentro do guard.
 * Não interfere em páginas que já incluem mysqli.php manualmente.
 */
function ag_db(): ?mysqli {
  static $loaded = false;

  if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
    return $GLOBALS['conn'];
  }

  if (!$loaded) {
    $loaded = true;
    $file = __DIR__ . '/mysqli.php';
    if (is_file($file)) {
      require_once $file;
    }
  }

  if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
    try {
      $GLOBALS['conn']->set_charset('utf8mb4');
    } catch (Throwable $e) {
      // se der erro aqui, seguimos sem derrubar o app
    }
    return $GLOBALS['conn'];
  }

  return null;
}

/* ───── Sincronizar usuário da sessão com o banco (tenant/role/is_active) ───── */
function ensure_user_session_fresh(): void {
  init_session();

  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) {
    return;
  }

  // Se já temos tenant + role + ativo, não consulta toda hora
  if (isset($_SESSION['tenant_id'], $_SESSION['role'], $_SESSION['is_active'])) {
    // Se marcado inativo na sessão, derruba
    if ((int)$_SESSION['is_active'] !== 1) {
      destroy_session();
    }
    return;
  }

  $db = ag_db();
  if (!$db) {
    // se não conseguir DB aqui, deixa seguir; require_auth/tenant vão barrar depois
    return;
  }

  $st = $db->prepare("
    SELECT tenant_id, shop_id, role, COALESCE(is_active,1) AS is_active
      FROM login
     WHERE id=?
     LIMIT 1
  ");
  $st->bind_param('i', $uid);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();

  if (!$row || (int)$row['is_active'] !== 1) {
    // usuário não existe mais ou desativado
    destroy_session();
    return;
  }

  // Preenche gaps na sessão
  if (!isset($_SESSION['tenant_id'])) $_SESSION['tenant_id'] = (int)($row['tenant_id'] ?? 0);
  if (!array_key_exists('shop_id', $_SESSION)) $_SESSION['shop_id'] = $row['shop_id'] !== null ? (int)$row['shop_id'] : null;
  $_SESSION['role']      = $row['role'] ?? 'member';
  $_SESSION['is_active'] = (int)$row['is_active'];
}

/* ───── Role helpers ───── */
function current_role(): string {
  init_session();
  ensure_user_session_fresh();
  return (string)($_SESSION['role'] ?? 'member');
}

function is_admin(): bool {
  return current_role() === 'admin';
}

/* ───── Guardas principais ───── */
function require_auth(): int {
  init_session();
  ensure_user_session_fresh();

  $uid = (int)($_SESSION['user_id'] ?? 0);
  $active = (int)($_SESSION['is_active'] ?? 1);

  if ($uid > 0 && $active === 1) {
    return $uid;
  }

  // sessão inválida ou usuário inativo
  if (is_api_request()) {
    http_response_code(401);
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'Sessão expirada, inválida ou usuário inativo'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  destroy_session();
  header('Location: ' . login_location());
  exit;
}

function require_tenant(): int {
  init_session();
  ensure_user_session_fresh();

  $tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
  if ($tenant_id > 0) {
    return $tenant_id;
  }

  // Sem tenant: trata como não autorizado
  if (is_api_request()) {
    http_response_code(401);
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'Sessão sem tenant'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  destroy_session();
  header('Location: ' . login_location());
  exit;
}

/**
 * Apenas admins.
 * Use em páginas como equipe.php
 */
function require_admin(): void {
  init_session();
  ensure_user_session_fresh();

  if (is_admin()) {
    return;
  }

  if (is_api_request()) {
    http_response_code(403);
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'Apenas administradores'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Página normal
  http_response_code(403);
  echo 'Acesso negado. Apenas administradores.';
  exit;
}

/* ───── Shop helpers ───── */
function current_shop_id(): ?int {
  init_session();
  ensure_user_session_fresh();

  if (!array_key_exists('shop_id', $_SESSION)) {
    return null;
  }
  $v = $_SESSION['shop_id'];
  return $v === null ? null : (int)$v;
}

function set_current_shop_id(?int $shop_id): void {
  init_session();
  $_SESSION['shop_id'] = $shop_id === null ? null : (int)$shop_id;
}
