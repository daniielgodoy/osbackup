<?php
// includes/logout.php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';

// Encerra a sessão com segurança
destroy_session();

// Se for requisição AJAX, responda JSON; senão, redirecione para login
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true]);
  exit;
}

header('Location: ../login.php');
exit;
