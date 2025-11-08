<?php
// includes/atualizar_os.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ok'=>false,'msg'=>'Método inválido.']); exit;
  }

  // Auth/contexto: se você usa guard, mantenha. Se não, pode comentar temporariamente p/ testar.
  $tenant_id = null;
  $shop_id   = null;
  if (is_file(__DIR__ . '/auth_guard.php')) {
    require_once __DIR__ . '/auth_guard.php';
    if (function_exists('require_tenant')) $tenant_id = require_tenant();
    if (function_exists('current_shop_id')) $shop_id = current_shop_id();
  }

  require_once __DIR__ . '/mysqli.php';
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $conn->set_charset('utf8mb4');

  $dbg = isset($_GET['dbg']) ? 1 : 0;

  $id    = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $campo = $_POST['campo'] ?? '';
  $valor = $_POST['valor'] ?? '';

  if ($id <= 0 || $campo === '') {
    echo json_encode(['ok'=>false,'msg'=>'Parâmetros inválidos (id/campo).']); exit;
  }

  /* ---------- Whitelist ---------- */
  $permitidos = [
    'nome','cpf','telefone','endereco','modelo','servico','observacao',
    'data_entrada','hora_entrada','status','senha_padrao','senha_escrita',
    'valor_total','metodo_pagamento','pago','pdf_path'
  ];
  if (!in_array($campo, $permitidos, true)) {
    echo json_encode(['ok'=>false,'msg'=>'Campo não permitido.']); exit;
  }

  /* ---------- Utilidades ---------- */
  $hasColumn = static function(mysqli $c, string $table, string $col): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = $c->prepare($sql);
    $st->bind_param('ss', $table, $col);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
  };

  $tbl = 'ordens_servico';
  $hasTenant   = $hasColumn($conn, $tbl, 'tenant_id');
  $hasShop     = $hasColumn($conn, $tbl, 'shop_id');
  $hasAtualiza = $hasColumn($conn, $tbl, 'atualizado_em');

  $status_validos  = ['pendente','em_andamento','concluido','cancelado','aguardando_retirada','orcamento'];
  $metodos_validos = ['pix','dinheiro','credito','debito','outro',''];

  $normaliza_brl = static function($txt): string {
    if ($txt === '' || $txt === null) return '0.00';
    $txt = trim((string)$txt);
    $txt = preg_replace('/[^0-9,\.\-]/', '', $txt);
    $temVirg = strpos($txt, ',') !== false;
    $temPonto = strpos($txt, '.') !== false;
    if ($temVirg && $temPonto) {
      $txt = str_replace('.', '', $txt);
      $txt = str_replace(',', '.', $txt);
    } elseif ($temVirg) {
      $txt = str_replace(',', '.', $txt);
    }
    $num = floatval($txt);
    return number_format($num, 2, '.', '');
  };

  $norm_status = static function($s) use ($status_validos): string {
    $s = strtolower(trim((string)$s));
    return in_array($s, $status_validos, true) ? $s : 'pendente';
  };

  $norm_metodo = static function($m) use ($metodos_validos): string {
    $m = strtolower(trim((string)$m));
    $aliases = [
      'cartao de credito' => 'credito',
      'cartao credito'    => 'credito',
      'cartao de débito'  => 'debito',
      'cartao debito'     => 'debito'
    ];
    $m = $aliases[$m] ?? $m;
    return in_array($m, $metodos_validos, true) ? $m : '';
  };

  /* ---------- Bind do valor ---------- */
  $bindType = null;
  $bindVal  = null;
  $retornoValor = null;

  if ($campo === 'pago') {
    $bindType = 'i';
    $bindVal  = (int)!empty($valor);
    $retornoValor = $bindVal;

  } elseif ($campo === 'valor_total') {
    $valorNorm = $normaliza_brl($valor);
    $bindType = 'd';
    $bindVal  = (float)$valorNorm;
    $retornoValor = $valorNorm;

  } elseif ($campo === 'status') {
    $v = $norm_status($valor);
    $bindType = 's'; $bindVal = $v; $retornoValor = $v;

  } elseif ($campo === 'metodo_pagamento') {
    $v = $norm_metodo($valor);
    if ($v === '') {
      $bindType = null; $bindVal = null; $retornoValor = null;
    } else {
      $bindType = 's'; $bindVal = $v; $retornoValor = $v;
    }

  } else {
    // texto/data/etc. com suporte a NULL em alguns
    $nullable_fields = ['observacao','pdf_path','senha_escrita','senha_padrao','endereco'];
    $v = trim((string)$valor);
    if (in_array($campo, $nullable_fields, true) && $v === '') {
      $bindType = null; $bindVal = null; $retornoValor = null;
    } else {
      $bindType = 's'; $bindVal = $v; $retornoValor = $v;
    }
  }

  /* ---------- WHERE dinâmico (id + tenant/shop se existirem) ---------- */
  $where = "id = ?";
  $typesWhere = 'i';
  $argsWhere  = [$id];

  if ($hasTenant && $tenant_id !== null) {
    $where .= " AND tenant_id = ?";
    $typesWhere .= 'i';
    $argsWhere[] = (int)$tenant_id;
  }
  if ($hasShop && $shop_id !== null) {
    $where .= " AND shop_id = ?";
    $typesWhere .= 'i';
    $argsWhere[] = (int)$shop_id;
  }

  /* ---------- UPDATE ---------- */
  if ($bindType === null) {
    $sql = "UPDATE {$tbl} SET `{$campo}` = NULL"
         . ($hasAtualiza ? ", atualizado_em = NOW()" : "")
         . " WHERE {$where}";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($typesWhere, ...$argsWhere);
  } else {
    $sql = "UPDATE {$tbl} SET `{$campo}` = ?"
         . ($hasAtualiza ? ", atualizado_em = NOW()" : "")
         . " WHERE {$where}";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($bindType . $typesWhere, $bindVal, ...$argsWhere);
  }

  $okExec = $stmt->execute();
  $errNo  = $stmt->errno;
  $errStr = $stmt->error;
  $aff    = $stmt->affected_rows;
  $stmt->close();

  // Sucesso mesmo se valor já era o mesmo (affected_rows pode vir 0)
  if (!$okExec || $errNo) {
    echo json_encode(['ok'=>false,'msg'=>'Falha no UPDATE','sql_error'=>$errStr]); exit;
  }

  /* ---------- Snapshot atualizado para sincronizar o front ---------- */
  $sel = "SELECT 
            id, status, pago, metodo_pagamento,
            COALESCE(valor_total,0)      AS valor_total,
            COALESCE(valor_pix,0)        AS valor_pix,
            COALESCE(valor_dinheiro,0)   AS valor_dinheiro,
            COALESCE(valor_credito,0)    AS valor_credito,
            COALESCE(valor_debito,0)     AS valor_debito,
            COALESCE(valor_dinheiro_pix,0) AS valor_dinheiro_pix,
            COALESCE(valor_cartao,0)     AS valor_cartao,
            COALESCE(pdf_path,'')        AS pdf_path"
         . ($hasAtualiza ? ", atualizado_em" : "")
         . " FROM {$tbl} WHERE {$where} LIMIT 1";
  $stmt2 = $conn->prepare($sel);
  $stmt2->bind_param($typesWhere, ...$argsWhere);
  $stmt2->execute();
  $rs = $stmt2->get_result();
  $row = $rs->fetch_assoc() ?: [];
  $stmt2->close();
  $conn->close();

  foreach (['valor_total','valor_pix','valor_dinheiro','valor_credito','valor_debito','valor_dinheiro_pix','valor_cartao'] as $k) {
    if (array_key_exists($k, $row)) {
      $row[$k] = number_format((float)$row[$k], 2, '.', '');
    }
  }
  if (isset($row['pago'])) $row['pago'] = (int)$row['pago'];

  $resp = ['ok'=>true,'campo'=>$campo,'valor'=>$retornoValor,'data'=>$row];
  if ($dbg) {
    $resp['debug'] = [
      'id'=>$id,'campo'=>$campo,'posted_val'=>$valor,
      'where'=>$where,'where_types'=>$typesWhere,'where_args'=>$argsWhere,
      'hasTenant'=>$hasTenant,'hasShop'=>$hasShop,'hasAtualizadoEm'=>$hasAtualiza,
      'affected_rows'=>$aff
    ];
  }
  echo json_encode($resp, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'msg'=>'Erro: '.$e->getMessage()]);
}
