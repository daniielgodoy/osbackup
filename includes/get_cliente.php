<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

/* Isolamento de contexto */
require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();          // OBRIGATÓRIO
$shop_id   = current_shop_id();         // pode ser null

/* DB */
require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* aceita id por GET ou POST */
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
  $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
}
if (!$id || $id <= 0) {
  echo json_encode(['ok'=>false, 'msg'=>'ID inválido']);
  exit;
}

try {
  // Monta WHERE com isolamento
  $where = ["c.id = ?", "c.tenant_id = ?"];
  $types = "ii";
  $args  = [$id, $tenant_id];

  // Se a loja estiver definida na sessão, isola por loja também
  if (!is_null($shop_id)) {
    $where[] = "c.shop_id = ?";
    $types  .= "i";
    $args[]  = $shop_id;
  }

  $sql = "SELECT
            c.id, c.nome, c.cpf, c.telefone, c.email,
            c.cep, c.logradouro, c.numero, c.bairro, c.cidade, c.uf
          FROM clientes c
          WHERE " . implode(' AND ', $where) . "
          LIMIT 1";

  $stmt = $conn->prepare($sql);

  // bind_param exige variáveis por referência — nunca passe expressões diretas
  // Gera refs
  $bindParams = [];
  $bindParams[] = $types;
  foreach ($args as $k => $v) { $bindParams[] = &$args[$k]; }
  call_user_func_array([$stmt, 'bind_param'], $bindParams);

  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  if (!$row) {
    echo json_encode(['ok'=>false, 'msg'=>'Cliente não encontrado neste contexto']);
    exit;
  }

  // normaliza null -> ''
  foreach ($row as $k => $v) {
    if ($v === null) $row[$k] = '';
  }

  echo json_encode(['ok'=>true, 'data'=>$row], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
} finally {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
}
