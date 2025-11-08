<?php
// includes/relatorios_data.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* —— NUNCA vazar HTML antes do JSON —— */
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }

require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();      // OBRIGATÓRIO
$shop_id   = current_shop_id();     // pode ser NULL

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* Helpers de bind */
function bind_all(mysqli_stmt $stmt, string $types, array &$args): void {
  if ($types === '' || !$args) return;
  $refs = [];
  foreach ($args as $k => &$v) { $refs[$k] = &$v; }
  $stmt->bind_param($types, ...$refs);
}

try {
  /* ========= PARÂMETROS ========= */
  $today      = new DateTime('today');
  $iniDefault = (clone $today)->modify('-6 days')->format('Y-m-d');
  $fimDefault = $today->format('Y-m-d');

  $ini = (isset($_GET['ini']) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['ini'])) ? $_GET['ini'] : $iniDefault;
  $fim = (isset($_GET['fim']) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['fim'])) ? $_GET['fim'] : $fimDefault;
  if (strtotime($ini) > strtotime($fim)) { $t=$ini; $ini=$fim; $fim=$t; }

  $statuses = [];
  if (!empty($_GET['status'])) {
    $statuses = array_values(array_filter(array_map('trim', explode(',', $_GET['status']))));
  }

  $metodos = [];
  if (!empty($_GET['metodos'])) {
    $all = ['dinheiro','pix','credito','debito'];
    foreach (explode(',', $_GET['metodos']) as $m) {
      $m = trim($m);
      if (in_array($m, $all, true)) $metodos[] = $m;
    }
    $metodos = array_values(array_unique($metodos));
  }

  $pago  = (isset($_GET['pago']) && $_GET['pago'] !== '') ? (int)$_GET['pago'] : null;
  $group = in_array(($_GET['group'] ?? 'dia'), ['dia','semana','mes'], true) ? $_GET['group'] : 'dia';
  $cmp   = !empty($_GET['cmp']);

  $q_servico = trim($_GET['q_servico'] ?? '');
  $q_nome    = trim($_GET['q_nome'] ?? '');
  $vmin      = (isset($_GET['vmin']) && $_GET['vmin'] !== '') ? (float)$_GET['vmin'] : null;
  $vmax      = (isset($_GET['vmax']) && $_GET['vmax'] !== '') ? (float)$_GET['vmax'] : null;

  // Data de conclusão (prioriza atualizado_em, senão data_entrada)
  $dtConclusao = "DATE(COALESCE(atualizado_em, data_entrada))";

  // Expressões de soma por método
  $SUMS = [
    'dinheiro' => "COALESCE(valor_dinheiro,0)",
    'pix'      => "COALESCE(valor_pix,0)",
    'credito'  => "COALESCE(valor_credito,0)",
    'debito'   => "COALESCE(valor_debito,0)",
  ];
  $SUM_TOTAL = implode('+', $SUMS);

  // Soma dinâmica conforme métodos selecionados
  $SUM_SELECTED = $SUM_TOTAL;
  if ($metodos) {
    $parts = [];
    foreach ($metodos as $m) { $parts[] = $SUMS[$m]; }
    $SUM_SELECTED = implode('+', $parts);
  }

  /* ========= WHERE base: tenant/loja ========= */
  $WHERE_TENANT = "tenant_id = ?";
  $typesTenant  = 'i';
  $bindTenant   = [ $tenant_id ];

  if ($shop_id === null) {
    $WHERE_TENANT .= " AND shop_id IS NULL";
  } else {
    $WHERE_TENANT .= " AND shop_id = ?";
    $typesTenant  .= 'i';
    $bindTenant[]  = $shop_id;
  }

  /* ========= WHERE common (filtros dinâmicos) ========= */
  $whereCommon = [];
  $typesCommon = '';
  $bindCommon  = [];

  if ($statuses) {
    $place = implode(',', array_fill(0, count($statuses), '?'));
    $whereCommon[] = "status IN ($place)";
    foreach ($statuses as $s) { $typesCommon .= 's'; $bindCommon[] = $s; }
  }
  if ($pago !== null)     { $whereCommon[] = "pago = ?";           $typesCommon .= 'i'; $bindCommon[] = $pago; }
  if ($q_servico !== '')  { $whereCommon[] = "servico LIKE ?";     $typesCommon .= 's'; $bindCommon[] = "%$q_servico%"; }
  if ($q_nome !== '')     { $whereCommon[] = "nome LIKE ?";        $typesCommon .= 's'; $bindCommon[] = "%$q_nome%"; }
  if ($vmin !== null)     { $whereCommon[] = "($SUM_SELECTED) >= ?"; $typesCommon .= 'd'; $bindCommon[] = $vmin; }
  if ($vmax !== null)     { $whereCommon[] = "($SUM_SELECTED) <= ?"; $typesCommon .= 'd'; $bindCommon[] = $vmax; }
  if ($metodos)           { $whereCommon[] = "($SUM_SELECTED) > 0"; }

  $WHERE_COMMON = $whereCommon ? (' AND '.implode(' AND ', $whereCommon)) : '';

  // Montagens completas (criadas / concluídas)
  $WHERE_CRIADAS = "WHERE $WHERE_TENANT AND DATE(data_entrada) BETWEEN ? AND ? $WHERE_COMMON";
  $typesCriadas  = $typesTenant . 'ss' . $typesCommon;
  $bindCriadas   = array_merge($bindTenant, [ $ini, $fim ], $bindCommon);

  $WHERE_CONC = "WHERE $WHERE_TENANT AND status='concluido' AND $dtConclusao BETWEEN ? AND ? $WHERE_COMMON";
  $typesConc  = $typesTenant . 'ss' . $typesCommon;
  $bindConc   = array_merge($bindTenant, [ $ini, $fim ], $bindCommon);

  /* ========= AGRUPAMENTO ========= */
  switch ($group) {
    case 'semana':
      $PER            = "CONCAT(YEARWEEK($dtConclusao, 3))";
      $LABEL          = "DATE_FORMAT($dtConclusao, '%x-W%v')";
      $PER_CRIADAS    = "CONCAT(YEARWEEK(DATE(data_entrada), 3))";
      $LABEL_CRIADAS  = "DATE_FORMAT(DATE(data_entrada), '%x-W%v')";
      break;
    case 'mes':
      $PER            = "DATE_FORMAT($dtConclusao, '%Y-%m')";
      $LABEL          = $PER;
      $PER_CRIADAS    = "DATE_FORMAT(DATE(data_entrada), '%Y-%m')";
      $LABEL_CRIADAS  = $PER_CRIADAS;
      break;
    default:
      $PER            = $dtConclusao;
      $LABEL          = $PER;
      $PER_CRIADAS    = "DATE(data_entrada)";
      $LABEL_CRIADAS  = $PER_CRIADAS;
  }

  /* ========= KPIs ========= */
  $stmt = $conn->prepare("SELECT COUNT(*) FROM ordens_servico $WHERE_CRIADAS");
  bind_all($stmt, $typesCriadas, $bindCriadas);
  $stmt->execute(); $stmt->bind_result($os_criadas); $stmt->fetch(); $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) FROM ordens_servico $WHERE_CONC");
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute(); $stmt->bind_result($os_concluidas); $stmt->fetch(); $stmt->close();

  $stmt = $conn->prepare("SELECT SUM($SUM_SELECTED) FROM ordens_servico $WHERE_CONC");
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute(); $stmt->bind_result($fat_total); $stmt->fetch(); $stmt->close();
  $fat_total    = (float)($fat_total ?? 0);
  $ticket_medio = $os_concluidas > 0 ? ($fat_total / $os_concluidas) : 0.0;

  /* ========= Faturamento (série base) ========= */
  $sql = "
    SELECT $LABEL AS periodo, SUM($SUM_SELECTED) AS total
    FROM ordens_servico
    $WHERE_CONC
    GROUP BY $PER
    ORDER BY MIN($dtConclusao) ASC
  ";
  $stmt = $conn->prepare($sql);
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute();
  $res  = $stmt->get_result();
  $serie_base = [];
  while ($r = $res->fetch_assoc()) {
    $serie_base[] = ['periodo'=>$r['periodo'], 'total'=>(float)$r['total']];
  }
  $stmt->close();

  // labels contínuos
  $labels = [];
  $start = new DateTime($ini);
  $end   = new DateTime($fim);
  if ($group === 'mes') {
    $d = new DateTime($start->format('Y-m-01'));
    while ($d <= $end) { $labels[] = $d->format('Y-m'); $d->modify('+1 month'); }
  } elseif ($group === 'semana') {
    $d = clone $start;
    $w = (int)$d->format('N'); if ($w > 1) { $d->modify('-'.($w-1).' days'); } // até segunda
    while ($d <= $end) {
      $labels[] = sprintf('%s-W%02d', $d->format('o'), (int)$d->format('W'));
      $d->modify('+7 days');
    }
  } else {
    $d = clone $start; while ($d <= $end) { $labels[] = $d->format('Y-m-d'); $d->modify('+1 day'); }
  }
  $map = [];
  foreach ($serie_base as $row) $map[$row['periodo']] = (float)$row['total'];
  $faturamento_diario = [];
  foreach ($labels as $lab) $faturamento_diario[] = ['periodo'=>$lab, 'total'=>($map[$lab] ?? 0.0)];

  /* ========= Comparativo (opcional) ========= */
  $comparativo = null;
  if ($cmp) {
    if ($group === 'mes') {
      $first  = new DateTime(reset($labels) ?: $ini);
      $last   = new DateTime(end($labels)   ?: $fim);
      $diff   = $first->diff($last);
      $months = $diff->y * 12 + $diff->m + 1;
      $from   = (clone $first)->modify("-$months months")->format('Y-m-01');
      $to     = (clone $last)->modify("-$months months")->format('Y-m-t');
      $LABEL_PREV = "DATE_FORMAT($dtConclusao, '%Y-%m')";
      $PER_PREV   = $LABEL_PREV;
    } elseif ($group === 'semana') {
      $weeks = count($labels);
      $from  = (new DateTime($ini))->modify("-$weeks week")->format('Y-m-d');
      $to    = (new DateTime($fim))->modify("-$weeks week")->format('Y-m-d');
      $LABEL_PREV = "DATE_FORMAT($dtConclusao, '%x-W%v')";
      $PER_PREV   = "CONCAT(YEARWEEK($dtConclusao, 3))";
    } else {
      $days  = count($labels);
      $from  = (new DateTime($ini))->modify("-$days day")->format('Y-m-d');
      $to    = (new DateTime($fim))->modify("-$days day")->format('Y-m-d');
      $LABEL_PREV = "DATE($dtConclusao)";
      $PER_PREV   = $LABEL_PREV;
    }

    $WHERE_PREV = "WHERE $WHERE_TENANT AND status='concluido' AND $dtConclusao BETWEEN ? AND ? $WHERE_COMMON";
    $typesPrev  = $typesTenant . 'ss' . $typesCommon;
    $bindPrev   = array_merge($bindTenant, [ $from, $to ], $bindCommon);

    $stmt = $conn->prepare("
      SELECT $LABEL_PREV AS periodo, SUM($SUM_SELECTED) AS total
      FROM ordens_servico
      $WHERE_PREV
      GROUP BY $PER_PREV
      ORDER BY MIN($dtConclusao) ASC
    ");
    bind_all($stmt, $typesPrev, $bindPrev);
    $stmt->execute();
    $res = $stmt->get_result();
    $mapPrev = [];
    while ($r = $res->fetch_assoc()) $mapPrev[$r['periodo']] = (float)$r['total'];
    $stmt->close();

    $prevVals = [];
    foreach ($labels as $lab) $prevVals[] = $mapPrev[$lab] ?? 0.0;
    $comparativo = ['anterior' => $prevVals];
  }

  /* ========= Empilhado por método ========= */
  $stmt = $conn->prepare("
    SELECT $LABEL AS periodo,
           SUM({$SUMS['dinheiro']}) AS dinheiro,
           SUM({$SUMS['pix']})      AS pix,
           SUM({$SUMS['credito']})  AS credito,
           SUM({$SUMS['debito']})   AS debito
    FROM ordens_servico
    $WHERE_CONC
    GROUP BY $PER
    ORDER BY MIN($dtConclusao) ASC
  ");
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute();
  $res = $stmt->get_result();
  $mapDin=$mapPix=$mapCred=$mapDeb=[];
  while ($r = $res->fetch_assoc()) {
    $k = $r['periodo'];
    $mapDin[$k]=(float)$r['dinheiro']; $mapPix[$k]=(float)$r['pix'];
    $mapCred[$k]=(float)$r['credito']; $mapDeb[$k]=(float)$r['debito'];
  }
  $stmt->close();
  $fat_m_labels = $labels;
  $fat_m = [
    'labels'   => $fat_m_labels,
    'dinheiro' => array_map(fn($k)=>$mapDin[$k] ?? 0.0, $fat_m_labels),
    'pix'      => array_map(fn($k)=>$mapPix[$k] ?? 0.0, $fat_m_labels),
    'credito'  => array_map(fn($k)=>$mapCred[$k] ?? 0.0, $fat_m_labels),
    'debito'   => array_map(fn($k)=>$mapDeb[$k] ?? 0.0, $fat_m_labels),
  ];

  /* ========= Meios (somatório) ========= */
  $stmt = $conn->prepare("
    SELECT
      SUM({$SUMS['dinheiro']}) AS dinheiro,
      SUM({$SUMS['pix']})      AS pix,
      SUM({$SUMS['credito']})  AS credito,
      SUM({$SUMS['debito']})   AS debito
    FROM ordens_servico
    $WHERE_CONC
  ");
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute();
  $meios = $stmt->get_result()->fetch_assoc() ?: ['dinheiro'=>0,'pix'=>0,'credito'=>0,'debito'=>0];
  $stmt->close();
  foreach ($meios as $k=>$v) $meios[$k]=(float)$v;

  /* ========= Status (criadas) ========= */
  $stmt = $conn->prepare("
    SELECT status, COUNT(*) AS qtd
    FROM ordens_servico
    $WHERE_CRIADAS
    GROUP BY status
    ORDER BY qtd DESC
  ");
  bind_all($stmt, $typesCriadas, $bindCriadas);
  $stmt->execute();
  $res = $stmt->get_result();
  $status_counts = [];
  while ($r = $res->fetch_assoc()) {
    $status_counts[] = ['status'=>($r['status'] ?? 'indefinido'), 'qtd'=>(int)$r['qtd']];
  }
  $stmt->close();

  /* ========= Top serviços ========= */
  $stmt = $conn->prepare("
    SELECT TRIM(COALESCE(servico,'')) AS srv,
           COUNT(*) AS qtd,
           SUM($SUM_SELECTED) AS fat
    FROM ordens_servico
    $WHERE_CONC
    GROUP BY TRIM(COALESCE(servico,''))
    ORDER BY qtd DESC, fat DESC
    LIMIT 10
  ");
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute();
  $res = $stmt->get_result();
  $top_servicos = [];
  while ($r = $res->fetch_assoc()) {
    $top_servicos[] = [
      'servico'  => ($r['srv'] !== '' ? $r['srv'] : '(sem descrição)'),
      'qtd'      => (int)$r['qtd'],
      'faturado' => (float)$r['fat']
    ];
  }
  $stmt->close();

  /* ========= Top clientes ========= */
  $stmt = $conn->prepare("
    SELECT TRIM(COALESCE(nome,'')) AS nome,
           COUNT(*) AS os,
           SUM($SUM_SELECTED) AS fat
    FROM ordens_servico
    $WHERE_CONC
    GROUP BY TRIM(COALESCE(nome,''))
    ORDER BY fat DESC, os DESC
    LIMIT 10
  ");
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute();
  $res = $stmt->get_result();
  $top_clientes = [];
  while ($r = $res->fetch_assoc()) {
    $top_clientes[] = [
      'nome'      => ($r['nome'] !== '' ? $r['nome'] : '(sem nome)'),
      'os'        => (int)$r['os'],
      'faturado'  => (float)$r['fat']
    ];
  }
  $stmt->close();

  /* ========= Resumo por bucket ========= */
  // concluidas + faturado + ticket
  $stmt = $conn->prepare("
    SELECT
      $LABEL AS periodo,
      COUNT(*) AS concluidas,
      SUM($SUM_SELECTED) AS faturado,
      (CASE WHEN COUNT(*)>0 THEN SUM($SUM_SELECTED)/COUNT(*) ELSE 0 END) AS ticket
    FROM ordens_servico
    $WHERE_CONC
    GROUP BY $PER
    ORDER BY MIN($dtConclusao) ASC
  ");
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute();
  $res = $stmt->get_result();
  $mapResumo = [];
  while ($r = $res->fetch_assoc()) {
    $mapResumo[$r['periodo']] = [
      'concluidas' => (int)$r['concluidas'],
      'faturado'   => (float)$r['faturado'],
      'ticket'     => (float)$r['ticket'],
    ];
  }
  $stmt->close();

  // criadas
  $stmt = $conn->prepare("
    SELECT $LABEL_CRIADAS AS periodo, COUNT(*) AS criadas
    FROM ordens_servico
    $WHERE_CRIADAS
    GROUP BY $PER_CRIADAS
    ORDER BY MIN(DATE(data_entrada)) ASC
  ");
  bind_all($stmt, $typesCriadas, $bindCriadas);
  $stmt->execute();
  $res = $stmt->get_result();
  $mapCriadas = [];
  while ($r = $res->fetch_assoc()) { $mapCriadas[$r['periodo']] = (int)$r['criadas']; }
  $stmt->close();

  // breakdown por método (mesmo bucket)
  $stmt = $conn->prepare("
    SELECT $LABEL AS periodo,
           SUM({$SUMS['dinheiro']}) AS dinheiro,
           SUM({$SUMS['pix']})      AS pix,
           SUM({$SUMS['credito']})  AS credito,
           SUM({$SUMS['debito']})   AS debito
    FROM ordens_servico
    $WHERE_CONC
    GROUP BY $PER
    ORDER BY MIN($dtConclusao) ASC
  ");
  bind_all($stmt, $typesConc, $bindConc);
  $stmt->execute();
  $res = $stmt->get_result();
  $mapMet = [];
  while ($r = $res->fetch_assoc()) {
    $mapMet[$r['periodo']] = [
      'dinheiro' => (float)$r['dinheiro'],
      'pix'      => (float)$r['pix'],
      'credito'  => (float)$r['credito'],
      'debito'   => (float)$r['debito'],
    ];
  }
  $stmt->close();

  $resumo_diario = [];
  foreach ($labels as $lab) {
    $met = $mapMet[$lab] ?? ['dinheiro'=>0,'pix'=>0,'credito'=>0,'debito'=>0];
    $resumo_diario[] = [
      'periodo'    => $lab,
      'criadas'    => $mapCriadas[$lab]            ?? 0,
      'concluidas' => $mapResumo[$lab]['concluidas'] ?? 0,
      'dinheiro'   => $met['dinheiro'],
      'pix'        => $met['pix'],
      'credito'    => $met['credito'],
      'debito'     => $met['debito'],
      'faturado'   => $mapResumo[$lab]['faturado'] ?? 0.0,
      'ticket'     => $mapResumo[$lab]['ticket']   ?? 0.0,
      'tempo_h'    => 0.0
    ];
  }

  echo json_encode([
    'ok' => true,
    'range' => ['ini'=>$ini, 'fim'=>$fim],
    'kpis' => [
      'os_criadas'    => (int)$os_criadas,
      'os_concluidas' => (int)$os_concluidas,
      'faturamento'   => $fat_total,
      'ticket_medio'  => $ticket_medio,
    ],
    'faturamento_diario'     => $faturamento_diario,
    'comparativo'            => $comparativo,
    'faturamento_por_metodo' => $fat_m,
    'meios'                  => $meios,
    'status_counts'          => $status_counts,
    'top_servicos'           => $top_servicos,
    'top_clientes'           => $top_clientes,
    'resumo_diario'          => $resumo_diario,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'Falha ao gerar relatórios.',
    'detail' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
