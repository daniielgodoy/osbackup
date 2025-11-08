<?php
/* includes/buscar_clientes.php */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();      // OBRIGATÓRIO
$shop_id   = current_shop_id();     // pode ser null

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ───────── helpers ───────── */
function only_digits(string $s): string { return preg_replace('/\D+/', '', $s); }
function bind_all(mysqli_stmt $stmt, string $types, array &$args): void {
  if ($types === '' || !$args) return;
  $refs = [];
  foreach ($args as $k => &$v) { $refs[$k] = &$v; }
  $stmt->bind_param($types, ...$refs);
}

try {
  $q   = trim($_GET['q']  ?? '');
  $por = ($_GET['por'] ?? 'nome');
  if ($q === '') { echo json_encode(['data'=>[]]); exit; }

  $where = [];
  $types = '';
  $args  = [];

  // Isolamento multi-tenant
  $where[] = 'tenant_id = ?';
  $types  .= 'i';
  $args[]  = $tenant_id;

  if (!is_null($shop_id)) {
    $where[] = 'shop_id = ?';
    $types  .= 'i';
    $args[]  = $shop_id;
  }

  // Critério da busca
  if ($por === 'cpf') {
    $digits = only_digits($q);
    if ($digits === '') { echo json_encode(['data'=>[]]); exit; }
    // Usa prefixo para aproveitar índice (ix_clientes_tenant_cpf)
    $where[] = 'cpf LIKE ?';
    $types  .= 's';
    $args[]  = $digits . '%';
    $orderBy = 'ORDER BY nome';
  } else { // nome (default)
    $where[] = 'nome LIKE ?';
    $types  .= 's';
    $args[]  = '%' . $q . '%';
    $orderBy = 'ORDER BY nome';
  }

  $sql = "
    SELECT
      id, nome, cpf, telefone, email,
      cep, logradouro, numero, complemento, bairro, cidade, uf, observacao
    FROM clientes
    WHERE " . implode(' AND ', $where) . "
    $orderBy
    LIMIT 10
  ";

  $stmt = $conn->prepare($sql);
  bind_all($stmt, $types, $args);
  $stmt->execute();
  $res = $stmt->get_result();

  $dados = [];
  while ($row = $res->fetch_assoc()) {
    // Endereço legível
    $endereco = trim(
      ($row['logradouro'] ?? '') .
      ((string)($row['numero'] ?? '') !== '' ? ', ' . $row['numero'] : '') .
      ((string)($row['bairro'] ?? '') !== '' ? ' - ' . $row['bairro'] : '') .
      ((string)($row['cidade'] ?? '') !== '' ? ', ' . $row['cidade'] : '') .
      ((string)($row['uf'] ?? '')     !== '' ? ' / ' . $row['uf']     : '') .
      ((string)($row['cep'] ?? '')    !== '' ? ' (CEP ' . $row['cep'] . ')' : '')
    );

    $dados[] = [
      'id'          => (int)$row['id'],
      'nome'        => (string)($row['nome'] ?? ''),
      'cpf'         => (string)($row['cpf'] ?? ''),
      'telefone'    => (string)($row['telefone'] ?? ''),
      'email'       => (string)($row['email'] ?? ''),
      'cep'         => (string)($row['cep'] ?? ''),
      'logradouro'  => (string)($row['logradouro'] ?? ''),
      'numero'      => (string)($row['numero'] ?? ''),
      'complemento' => (string)($row['complemento'] ?? ''),
      'bairro'      => (string)($row['bairro'] ?? ''),
      'cidade'      => (string)($row['cidade'] ?? ''),
      'uf'          => (string)($row['uf'] ?? ''),
      'observacao'  => (string)($row['observacao'] ?? ''),
      'endereco'    => $endereco,
    ];
  }
  $stmt->close();
  $conn->close();

  echo json_encode(['data' => $dados], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['data'=>[], 'error'=>$e->getMessage()]);
}
