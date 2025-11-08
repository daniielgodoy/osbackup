<?php
// includes/verificar_ou_inserir_cliente.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/auth_guard.php';   // sessão + helpers
$tenant_id = require_tenant();               // trava tenant
$shop_id   = current_shop_id();              // pode ser null

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ───────── helpers ───────── */
function onlyDigits(string $s): string { return preg_replace('/\D+/', '', $s); }
function hasTable(mysqli $c, string $tab): bool {
  $t = $c->real_escape_string($tab);
  $r = $c->query("SHOW TABLES LIKE '{$t}'");
  return ($r && $r->num_rows > 0);
}
function hasCol(mysqli $c, string $tab, string $col): bool {
  $t = $c->real_escape_string($tab);
  $c2 = $c->real_escape_string($col);
  $r = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c2}'");
  return ($r && $r->num_rows > 0);
}
function json_exit(array $p, int $code=200){ http_response_code($code); echo json_encode($p, JSON_UNESCAPED_UNICODE); exit; }

/* ───────── entrada ───────── */
$nome         = trim($_POST['nome'] ?? '');
$telefone     = trim($_POST['telefone'] ?? '');
$cpf          = onlyDigits((string)($_POST['cpf'] ?? ''));
$email        = trim($_POST['email'] ?? '');
$cep          = trim($_POST['cep'] ?? '');
$logradouro   = trim($_POST['logradouro'] ?? '');
$numero       = trim($_POST['numero'] ?? '');
$complemento  = trim($_POST['complemento'] ?? '');
$bairro       = trim($_POST['bairro'] ?? '');
$cidade       = trim($_POST['cidade'] ?? '');
$uf           = strtoupper(trim($_POST['uf'] ?? ''));
$observacao   = trim($_POST['observacao'] ?? '');

if (!hasTable($conn, 'clientes')) {
  json_exit(['success'=>false,'message'=>"Tabela 'clientes' não encontrada."], 500);
}

if ($cpf === '') {
  json_exit(['success'=>false,'message'=>'CPF obrigatório.'], 400);
}
// se quiser exigir tamanho mínimo do CPF, descomente abaixo
// if (strlen($cpf) < 11) { json_exit(['success'=>false,'message'=>'CPF inválido.'], 400); }

/* ───────── descobrir colunas multi-tenant ───────── */
$cli_has_tenant = hasCol($conn, 'clientes', 'tenant_id');
$cli_has_shop   = hasCol($conn, 'clientes', 'shop_id');

/* ───────── verificar existência (CPF) dentro do contexto ───────── */
$where = "cpf = ?";
$types = 's';
$vals  = [$cpf];

if ($cli_has_tenant) { $where .= " AND tenant_id = ?"; $types .= 'i'; $vals[] = $tenant_id; }
if ($cli_has_shop && $shop_id !== null) { $where .= " AND shop_id = ?"; $types .= 'i'; $vals[] = (int)$shop_id; }

$sql = "SELECT id FROM clientes WHERE {$where} LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$vals);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
  $cliente = $res->fetch_assoc();
  $stmt->close();
  json_exit(['success'=>true, 'id'=>(int)$cliente['id'], 'exists'=>true]);
}
$stmt->close();

/* ───────── inserir (no contexto atual) ───────── */
$data_cadastro = date('Y-m-d H:i:s');

// monta "endereco" se existir a coluna e puder ser útil
$endereco = '';
if (hasCol($conn, 'clientes', 'endereco')) {
  $endereco = trim(implode('', [
    $logradouro,
    $numero ? ", {$numero}" : '',
    $bairro ? " — {$bairro}" : '',
    $cidade ? " — {$cidade}" : '',
    $uf ? "/{$uf}" : '',
    $cep ? " — CEP {$cep}" : '',
  ]));
}

// construção dinâmica respeitando colunas existentes
$cols  = [];
$ph    = [];
$types = '';
$vals  = [];

// multi-tenant primeiro
if ($cli_has_tenant) { $cols[]='`tenant_id`'; $ph[]='?'; $types.='i'; $vals[]=$tenant_id; }
if ($cli_has_shop)   { $cols[]='`shop_id`';   $ph[]='?'; $types.='i'; $vals[]=(int)$shop_id; }

// mapeamento de campos opcionais
$map = [
  'nome'        => $nome,
  'cpf'         => $cpf,
  'telefone'    => $telefone,
  'email'       => $email,
  'cep'         => $cep,
  'logradouro'  => $logradouro,
  'numero'      => $numero,
  'complemento' => $complemento,
  'bairro'      => $bairro,
  'cidade'      => $cidade,
  'uf'          => $uf,
  'observacao'  => $observacao,
  'endereco'    => $endereco,
  'data_cadastro' => $data_cadastro,
];

foreach ($map as $col => $val) {
  if (!hasCol($conn, 'clientes', $col)) continue;
  $cols[] = "`{$col}`";
  $ph[]   = '?';
  $types .= 's';
  $vals[] = (string)$val;
}

if (empty($cols)) {
  json_exit(['success'=>false,'message'=>"Nenhuma coluna compatível para inserir em 'clientes'."], 500);
}

$sql = "INSERT INTO `clientes` (".implode(',', $cols).") VALUES (".implode(',', $ph).")";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$vals);

if ($stmt->execute()) {
  $id = (int)$stmt->insert_id;
  $stmt->close();
  json_exit(['success'=>true, 'id'=>$id, 'exists'=>false]);
}

$err = $stmt->error;
$stmt->close();
json_exit(['success'=>false, 'message'=>'Erro ao inserir cliente: '.$err], 500);
