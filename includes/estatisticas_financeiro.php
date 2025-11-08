<?php
// includes/estatisticas_financeiro.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$hoje  = date('Y-m-d');
$ontem = date('Y-m-d', strtotime('-1 day'));

/*
Objetivo:
- Somar valores recebidos em OS concluídas HOJE e ONTEM.
- Preferir colunas por método (valor_pix/dinheiro/credito/debito).
- Se não houver, usar metodo_pagamento|tipo_pagamento + valor_total.
- Como último fallback, usar "pago" NUMÉRICO (se existir e for valor).
- Usar data_conclusao se existir; senão, data_entrada.
*/

function temColuna(mysqli $conn, string $tabela, string $coluna): bool {
  $tabela = $conn->real_escape_string($tabela);
  $coluna = $conn->real_escape_string($coluna);
  $r = $conn->query("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
  return ($r && $r->num_rows > 0);
}
function fmt2($v){ return (float)number_format((float)$v, 2, '.', ''); }

// def. da coluna de data (preferir data_conclusao)
$usaDataConclusao = temColuna($conn, 'ordens_servico', 'data_conclusao');
$colData = $usaDataConclusao ? 'data_conclusao' : 'data_entrada';

// detecta esquema por método
$temPix      = temColuna($conn, 'ordens_servico', 'valor_pix');
$temDinheiro = temColuna($conn, 'ordens_servico', 'valor_dinheiro');
$temCredito  = temColuna($conn, 'ordens_servico', 'valor_credito');
$temDebito   = temColuna($conn, 'ordens_servico', 'valor_debito');
$temQuatro   = $temPix && $temDinheiro && $temCredito && $temDebito;

// método único + total
$temValorTotal = temColuna($conn, 'ordens_servico', 'valor_total');
$temMetodoPag  = temColuna($conn, 'ordens_servico', 'metodo_pagamento');
$temTipoPag    = temColuna($conn, 'ordens_servico', 'tipo_pagamento');
$colMetodo     = $temMetodoPag ? 'metodo_pagamento' : ($temTipoPag ? 'tipo_pagamento' : null);

// “pago” pode ser bool ou valor — só usa como último recurso
$temPago = temColuna($conn, 'ordens_servico', 'pago');

$out = [
  'total'    => ['hoje'=>0.0,'ontem'=>0.0],
  'pix'      => ['hoje'=>0.0,'ontem'=>0.0],
  'dinheiro' => ['hoje'=>0.0,'ontem'=>0.0],
  'credito'  => ['hoje'=>0.0,'ontem'=>0.0],
  'debito'   => ['hoje'=>0.0,'ontem'=>0.0],
];

try {
  if ($temQuatro) {
    // ✅ CENÁRIO A: colunas por método
    $sql = "
      SELECT
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(valor_pix,0),0) ELSE 0 END) AS pix_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(valor_pix,0),0) ELSE 0 END) AS pix_ontem,

        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(valor_dinheiro,0),0) ELSE 0 END) AS dinheiro_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(valor_dinheiro,0),0) ELSE 0 END) AS dinheiro_ontem,

        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(valor_credito,0),0) ELSE 0 END) AS credito_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(valor_credito,0),0) ELSE 0 END) AS credito_ontem,

        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(valor_debito,0),0) ELSE 0 END) AS debito_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(valor_debito,0),0) ELSE 0 END) AS debito_ontem
      FROM ordens_servico
    ";
    // bind order: h o h o h o h o (8 params)
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssss', $hoje,$ontem,$hoje,$ontem,$hoje,$ontem,$hoje,$ontem);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $out['pix']['hoje']      = fmt2($row['pix_hoje']      ?? 0);
    $out['pix']['ontem']     = fmt2($row['pix_ontem']     ?? 0);
    $out['dinheiro']['hoje'] = fmt2($row['dinheiro_hoje'] ?? 0);
    $out['dinheiro']['ontem']= fmt2($row['dinheiro_ontem']?? 0);
    $out['credito']['hoje']  = fmt2($row['credito_hoje']  ?? 0);
    $out['credito']['ontem'] = fmt2($row['credito_ontem'] ?? 0);
    $out['debito']['hoje']   = fmt2($row['debito_hoje']   ?? 0);
    $out['debito']['ontem']  = fmt2($row['debito_ontem']  ?? 0);

  } elseif ($colMetodo && $temValorTotal) {
    // ✅ CENÁRIO B: metodo/tipo_pagamento + valor_total
    $sql = "
      SELECT
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? AND $colMetodo='pix'      THEN COALESCE(NULLIF(valor_total,0),0) ELSE 0 END) AS pix_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? AND $colMetodo='pix'      THEN COALESCE(NULLIF(valor_total,0),0) ELSE 0 END) AS pix_ontem,

        SUM(CASE WHEN status='concluido' AND DATE($colData)=? AND $colMetodo='dinheiro' THEN COALESCE(NULLIF(valor_total,0),0) ELSE 0 END) AS dinheiro_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? AND $colMetodo='dinheiro' THEN COALESCE(NULLIF(valor_total,0),0) ELSE 0 END) AS dinheiro_ontem,

        SUM(CASE WHEN status='concluido' AND DATE($colData)=? AND $colMetodo='credito'  THEN COALESCE(NULLIF(valor_total,0),0) ELSE 0 END) AS credito_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? AND $colMetodo='credito'  THEN COALESCE(NULLIF(valor_total,0),0) ELSE 0 END) AS credito_ontem,

        SUM(CASE WHEN status='concluido' AND DATE($colData)=? AND $colMetodo='debito'   THEN COALESCE(NULLIF(valor_total,0),0) ELSE 0 END) AS debito_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? AND $colMetodo='debito'   THEN COALESCE(NULLIF(valor_total,0),0) ELSE 0 END) AS debito_ontem
      FROM ordens_servico
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssss', $hoje,$ontem,$hoje,$ontem,$hoje,$ontem,$hoje,$ontem);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $out['pix']['hoje']      = fmt2($row['pix_hoje']      ?? 0);
    $out['pix']['ontem']     = fmt2($row['pix_ontem']     ?? 0);
    $out['dinheiro']['hoje'] = fmt2($row['dinheiro_hoje'] ?? 0);
    $out['dinheiro']['ontem']= fmt2($row['dinheiro_ontem']?? 0);
    $out['credito']['hoje']  = fmt2($row['credito_hoje']  ?? 0);
    $out['credito']['ontem'] = fmt2($row['credito_ontem'] ?? 0);
    $out['debito']['hoje']   = fmt2($row['debito_hoje']   ?? 0);
    $out['debito']['ontem']  = fmt2($row['debito_ontem']  ?? 0);

  } elseif ($temPago) {
    // ✅ CENÁRIO C: fallback em "pago" numérico
    $sql = "
      SELECT
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(pago,0),0) ELSE 0 END) AS total_hoje,
        SUM(CASE WHEN status='concluido' AND DATE($colData)=? THEN COALESCE(NULLIF(pago,0),0) ELSE 0 END) AS total_ontem
      FROM ordens_servico
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $hoje, $ontem);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $out['total']['hoje']  = fmt2($row['total_hoje']  ?? 0);
    $out['total']['ontem'] = fmt2($row['total_ontem'] ?? 0);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
  } else {
    // nada para somar
  }

  // calcula totais a partir dos métodos (A/B)
  $out['total']['hoje']  = fmt2($out['pix']['hoje'] + $out['dinheiro']['hoje'] + $out['credito']['hoje'] + $out['debito']['hoje']);
  $out['total']['ontem'] = fmt2($out['pix']['ontem'] + $out['dinheiro']['ontem'] + $out['credito']['ontem'] + $out['debito']['ontem']);

  echo json_encode($out, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  echo json_encode([
    'error' => true,
    'message' => $e->getMessage(),
    'total'    => ['hoje'=>0.0,'ontem'=>0.0],
    'pix'      => ['hoje'=>0.0,'ontem'=>0.0],
    'dinheiro' => ['hoje'=>0.0,'ontem'=>0.0],
    'credito'  => ['hoje'=>0.0,'ontem'=>0.0],
    'debito'   => ['hoje'=>0.0,'ontem'=>0.0],
  ], JSON_UNESCAPED_UNICODE);
} finally {
  $conn->close();
}
