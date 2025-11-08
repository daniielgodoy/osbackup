<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();
$shop_id   = current_shop_id();

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

date_default_timezone_set('America/Sao_Paulo');
$hoje   = date('Y-m-d');
$ontem  = date('Y-m-d', strtotime('-1 day'));

function somaDia(mysqli $conn, int $tenant_id, ?int $shop_id, string $dia): array {
  $where = "WHERE tenant_id=? AND DATE(COALESCE(atualizado_em, data_entrada))=?";
  $types = 'is';
  $pars  = [$tenant_id, $dia];
  if ($shop_id !== null) { $where .= " AND shop_id=?"; $types .= 'i'; $pars[] = $shop_id; }

  $has = function(string $c) use ($conn) {
    $r = @$conn->query("SHOW COLUMNS FROM `ordens_servico` LIKE '{$c}'");
    return $r && $r->num_rows > 0;
  };

  if ($has('valor_pix') || $has('valor_total')) {
    $sql = "SELECT
              COALESCE(SUM(valor_pix),0)      AS pix,
              COALESCE(SUM(valor_dinheiro),0) AS dinheiro,
              COALESCE(SUM(valor_credito),0)  AS credito,
              COALESCE(SUM(valor_debito),0)   AS debito
            FROM ordens_servico $where";
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$pars);
    $st->execute();
    $R = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    return [
      'pix'      => (float)($R['pix'] ?? 0),
      'dinheiro' => (float)($R['dinheiro'] ?? 0),
      'credito'  => (float)($R['credito'] ?? 0),
      'debito'   => (float)($R['debito'] ?? 0),
    ];
  }

  // Legado
  $sql = "SELECT COALESCE(SUM(valor_dinheiro_pix),0) AS dinpix,
                 COALESCE(SUM(valor_cartao),0)       AS cartao
          FROM ordens_servico $where";
  $st = $conn->prepare($sql);
  $st->bind_param($types, ...$pars);
  $st->execute();
  $R = $st->get_result()->fetch_assoc() ?: [];
  $st->close();
  return [
    'pix'      => (float)($R['dinpix'] ?? 0),
    'dinheiro' => 0.0,
    'credito'  => (float)($R['cartao'] ?? 0),
    'debito'   => 0.0,
  ];
}

$Dhoje  = somaDia($conn, $tenant_id, $shop_id, $hoje);
$Dontem = somaDia($conn, $tenant_id, $shop_id, $ontem);

$fmt = fn($n)=> number_format((float)$n, 2, ',', '.');

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'pix' =>      ['ontem'=>$fmt($Dontem['pix']),     'hoje'=>$fmt($Dhoje['pix'])],
  'dinheiro' => ['ontem'=>$fmt($Dontem['dinheiro']),'hoje'=>$fmt($Dhoje['dinheiro'])],
  'credito' =>  ['ontem'=>$fmt($Dontem['credito']), 'hoje'=>$fmt($Dhoje['credito'])],
  'debito' =>   ['ontem'=>$fmt($Dontem['debito']),  'hoje'=>$fmt($Dhoje['debito'])],
], JSON_UNESCAPED_UNICODE);
