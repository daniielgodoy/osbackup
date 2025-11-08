<?php
// includes/get_os.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_guard.php';   // ➜ require_tenant(), current_shop_id()
require_once __DIR__ . '/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ─── Contexto (isolamento multi-empresa/loja) ─── */
$tenant_id = require_tenant();          // lança/encerra se inválido
$shop_id   = current_shop_id();         // pode ser null se o fluxo assim permitir

/* ─── Entrada ─── */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
  exit;
}

try {
  /* ─── Consulta com isolamento ─── */
  $where = "WHERE id = ? AND tenant_id = ?";
  $types = 'ii';
  $args  = [$id, $tenant_id];

  if (!is_null($shop_id)) {
    $where .= " AND shop_id = ?";
    $types .= 'i';
    $args[] = $shop_id;
  }

  $sql = "
    SELECT
      id,
      nome, cpf, telefone, endereco,
      modelo, servico, observacao,
      data_entrada, hora_entrada,
      data_conclusao,
      valor_total,
      valor_pix,
      valor_dinheiro,
      valor_credito,
      valor_debito,
      valor_dinheiro_pix,
      valor_cartao,
      metodo_pagamento,
      pago,
      status,
      senha_padrao,
      senha_escrita,
      pdf_path
    FROM ordens_servico
    $where
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);

  // bind dinâmico
  $refs = [];
  foreach ($args as $k => &$v) { $refs[$k] = &$v; }
  $stmt->bind_param($types, ...$refs);

  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();
  $conn->close();

  if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'O.S. não encontrada']);
    exit;
  }

  // 🧹 Normalizações de texto (evita null)
  $textFields = [
    'nome','cpf','telefone','endereco','modelo','servico','observacao',
    'metodo_pagamento','status','senha_padrao','senha_escrita','pdf_path',
    'data_entrada','hora_entrada','data_conclusao'
  ];
  foreach ($textFields as $f) {
    if (!isset($row[$f]) || $row[$f] === null) $row[$f] = '';
  }

  // 🧮 Normalizações numéricas com 2 casas (string "0.00")
  $numFields = [
    'valor_total','valor_pix','valor_dinheiro',
    'valor_credito','valor_debito','valor_dinheiro_pix','valor_cartao'
  ];
  foreach ($numFields as $f) {
    $row[$f] = number_format((float)($row[$f] ?? 0), 2, '.', '');
  }

  // 🔒 Pago como inteiro 0/1
  $row['pago'] = (int)($row['pago'] ?? 0);

  echo json_encode(['ok' => true, 'data' => $row]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Erro: ' . $e->getMessage()]);
}
