<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();
$shop_id   = current_shop_id();

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

date_default_timezone_set('America/Sao_Paulo');
$hoje = date('Y-m-d');

/*
 Suporte a dois esquemas:
  - Novo:  valor_pix, valor_dinheiro, valor_credito, valor_debito, valor_total
  - Legado: valor_dinheiro_pix, valor_cartao (somamos como “pix/dinheiro” e “cartão”)
*/
$baseWhere = "WHERE tenant_id=? AND DATE(COALESCE(atualizado_em, data_entrada))=?";
$params    = [$tenant_id, $hoje];
$types     = 'is';
if ($shop_id !== null) { $baseWhere .= " AND shop_id=?"; $types .= 'i'; $params[] = $shop_id; }

$sqlNovo = "
 SELECT
   COALESCE(SUM(valor_pix),0)      AS pix,
   COALESCE(SUM(valor_dinheiro),0) AS dinheiro,
   COALESCE(SUM(valor_credito),0)  AS credito,
   COALESCE(SUM(valor_debito),0)   AS debito,
   COALESCE(SUM(valor_total),0)    AS total
 FROM ordens_servico
 $baseWhere
";

$sqlLegado = "
 SELECT
   COALESCE(SUM(valor_dinheiro_pix),0) AS dinpix,
   COALESCE(SUM(valor_cartao),0)       AS cartao
 FROM ordens_servico
 $baseWhere
";

// Tenta novo esquema; se colunas não existirem, cai no legado
function colExists(mysqli $c, string $col): bool {
  $r = @$c->query("SHOW COLUMNS FROM `ordens_servico` LIKE '{$col}'");
  return $r && $r->num_rows > 0;
}

$temNovo = colExists($conn,'valor_pix') || colExists($conn,'valor_total');

header('Content-Type: application/json; charset=utf-8');

if ($temNovo) {
  $st = $conn->prepare($sqlNovo);
  $st->bind_param($types, ...$params);
  $st->execute();
  $R = $st->get_result()->fetch_assoc() ?: [];
  $st->close();

  echo json_encode([
    'ok'             => true,
    'total_pix'      => number_format((float)($R['pix']??0), 2, ',', '.'),
    'total_dinheiro' => number_format((float)($R['dinheiro']??0), 2, ',', '.'),
    'total_credito'  => number_format((float)($R['credito']??0), 2, ',', '.'),
    'total_debito'   => number_format((float)($R['debito']??0), 2, ',', '.'),
    'total_pago'     => number_format((float)($R['total']??0), 2, ',', '.'),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// Legado
$st = $conn->prepare($sqlLegado);
$st->bind_param($types, ...$params);
$st->execute();
$R = $st->get_result()->fetch_assoc() ?: [];
$st->close();

$pixDin = (float)($R['dinpix'] ?? 0);
$cartao = (float)($R['cartao'] ?? 0);

echo json_encode([
  'ok'             => true,
  'total_pix'      => number_format($pixDin, 2, ',', '.'),
  'total_dinheiro' => number_format(0, 2, ',', '.'),
  'total_credito'  => number_format($cartao, 2, ',', '.'),
  'total_debito'   => number_format(0, 2, ',', '.'),
  'total_pago'     => number_format($pixDin + $cartao, 2, ',', '.'),
], JSON_UNESCAPED_UNICODE);
