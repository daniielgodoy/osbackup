<?php
// includes/auth_guard.php
declare(strict_types=1);

/* ───── Sessão segura ───── */
function init_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
    // Cookies de sessão mais seguros (HTTP only e mesmo site)
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
  return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
function wants_json(): bool {
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  return stripos($accept, 'application/json') !== false;
}
function is_api_path(): bool {
  // qualquer coisa dentro de /includes que seja chamada diretamente tende a ser endpoint
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $file   = basename($script);
  return (strpos($script, '/includes/') !== false)
         || str_ends_with($file, '_api.php')
         || str_starts_with($file, 'api_');
}
function is_api_request(): bool {
  return is_ajax() || wants_json() || is_api_path();
}

/* ───── Para gerar caminho correto do login (../login.php quando está em /includes) ───── */
function login_location(): string {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  return (strpos($script, '/includes/') !== false) ? '../login.php' : 'login.php';
}

/* ───── Helpers de sessão ───── */
function establish_session(int $user_id, int $tenant_id, ?int $shop_id, array $profile = []): void {
  init_session();
  session_regenerate_id(true);
  $_SESSION['user_id']   = $user_id;
  $_SESSION['tenant_id'] = $tenant_id;
  $_SESSION['shop_id']   = $shop_id; // pode ser null

  // preferências básicas
  if (!empty($profile)) {
    foreach (['nome','email','tema','idioma'] as $k) {
      if (array_key_exists($k, $profile)) {
        $_SESSION['profile'][$k] = $profile[$k];
      }
    }
  }
  $_SESSION['last_activity'] = time();
}

function destroy_session(): void {
  init_session();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', 
      isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
  }
  session_destroy();
}

function session_user_id(): int {
  init_session();
  return (int)($_SESSION['user_id'] ?? 0);
}

/* ───── Guardas ───── */
function require_auth(): int {
  init_session();
  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid > 0) return $uid;

  if (is_api_request()) {
    http_response_code(401);
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Sessão expirada ou ausente'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  header('Location: ' . login_location());
  exit;
}

function require_tenant(): int {
  init_session();
  $tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
  if ($tenant_id > 0) return $tenant_id;

  // Sem tenant: tratar como não logado para páginas
  if (is_api_request()) {
    http_response_code(401);
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Sessão sem tenant'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  header('Location: ' . login_location());
  exit;
}

function current_shop_id(): ?int {
  init_session();
  if (!array_key_exists('shop_id', $_SESSION)) return null;
  $v = $_SESSION['shop_id'];
  return $v === null ? null : (int)$v;
}

function set_current_shop_id(?int $shop_id): void {
  init_session();
  $_SESSION['shop_id'] = $shop_id;
}
