<?php
/* ===========================================================================
 * os.php — Lista, filtro, paginação AJAX e modais de Visualizar/Editar OS
 * Requisitos externos:
 *   - includes/auth_guard.php  => require_tenant(), current_shop_id()
 *   - includes/mysqli.php      => $conn (mysqli)
 *   - includes/get_os.php      => retorna { ok, data:{...} }
 *   - includes/atualizar_status.php
 *   - includes/atualizar_os.php
 *   - includes/deletar_os.php
 *   - css/os.css  e seu theme.css global
 * ======================================================================== */

// Segurança & contexto ANTES de qualquer saída
require_once __DIR__ . '/includes/auth_guard.php';
$tenant_id = require_tenant();
$shop_id   = current_shop_id(); // pode ser null

include_once __DIR__ . '/includes/header.php';
$pagina = 'os';
include_once __DIR__ . '/includes/navbar.php';
include_once __DIR__ . '/includes/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ======== CAPTAÇÃO ROBUSTA DOS PARÂMETROS + PAGINAÇÃO ======== */
$Q = $_GET;
if (empty($Q) && isset($_SERVER['QUERY_STRING'])) {
  parse_str($_SERVER['QUERY_STRING'], $Q);
}

$pp_raw   = isset($Q['pp']) ? (int)$Q['pp'] : 10;
$per_page = max(1, min(100, $pp_raw));
$p_raw    = isset($Q['p']) ? (int)$Q['p'] : 1;
$page     = max(1, $p_raw);
$offset   = ($page - 1) * $per_page;

/* ---- Inputs do modal de Filtros ---- */
$ids        = trim($Q['ids'] ?? '');
$nome       = trim($Q['nome'] ?? '');
$modelo     = trim($Q['modelo'] ?? '');
$servico    = trim($Q['servico'] ?? '');
$telefone   = trim($Q['telefone'] ?? '');
$cpf        = trim($Q['cpf'] ?? '');

/* Aceita tanto status[] quanto status */
$statusRaw  = $Q['status'] ?? ($Q['status[]'] ?? []);
if (!is_array($statusRaw)) $statusRaw = [$statusRaw];
$statusArr  = array_values(array_filter($statusRaw, fn($s)=>$s!==''));

$pago       = trim($Q['pago'] ?? '');
$metodo     = trim($Q['metodo_pagamento'] ?? '');
$data_ini   = trim($Q['data_ini'] ?? '');
$data_fim   = trim($Q['data_fim'] ?? '');
$valor_min  = trim($Q['valor_min'] ?? '');
$valor_max  = trim($Q['valor_max'] ?? '');

$where = [];
$args  = [];
$types = '';

/* ========= Isolamento obrigatório por Empresa/Loja ========= */
$where[] = "tenant_id = ?";
$types  .= 'i';
$args[]  = $tenant_id;

if (!is_null($shop_id)) {
  $where[] = "shop_id = ?";
  $types  .= 'i';
  $args[]  = $shop_id;
}

/* ID único ou faixa */
if (!function_exists('build_base_qs')) {
  function build_base_qs(array $Q): string {
    $keep = $Q;
    unset($keep['p'], $keep['pp'], $keep['partial']);
    return http_build_query($keep);
  }
}
$base_qs = build_base_qs($Q);

if ($ids !== '') {
  if (preg_match('~^\s*(\d+)\s*-\s*(\d+)\s*$~', $ids, $m)) {
    $a = (int)$m[1]; $b = (int)$m[2];
    if ($a > $b) { $t=$a; $a=$b; $b=$t; }
    $where[] = "(id BETWEEN ? AND ?)";
    $types  .= 'ii';
    array_push($args, $a, $b);
  } elseif (ctype_digit($ids)) {
    $where[] = "id = ?";
    $types  .= 'i';
    $args[]  = (int)$ids;
  }
}

/* LIKEs */
if (!function_exists('addLike')) {
  function addLike(&$where,&$types,&$args,$col,$val){
    if ($val==='') return;
    $where[] = "$col LIKE ?";
    $types  .= 's';
    $args[]  = '%'.$val.'%';
  }
}
addLike($where,$types,$args,'nome',$nome);
addLike($where,$types,$args,'modelo',$modelo);
addLike($where,$types,$args,'servico',$servico);
addLike($where,$types,$args,'telefone',$telefone);
addLike($where,$types,$args,'cpf',$cpf);

/* Status multi-select */
if (is_array($statusArr) && count($statusArr)>0) {
  $statusArr = array_values(array_filter($statusArr, fn($s)=>$s!==''));
  if ($statusArr) {
    $place = implode(',', array_fill(0, count($statusArr), '?'));
    $where[] = "status IN ($place)";
    $types  .= str_repeat('s', count($statusArr));
    $args    = array_merge($args, $statusArr);
  }
}

/* Pago: 1/0 (compatível com registros antigos) */
if ($pago === '1' || $pago === '0') {
  $where[] = "(COALESCE(pago,0) > 0) = ?";
  $types  .= 'i';
  $args[]  = (int)$pago;
}

/* Método de pagamento */
if ($metodo !== '') {
  $where[] = "COALESCE(metodo_pagamento,'') = ?";
  $types  .= 's';
  $args[]  = $metodo;
}

/* Data de entrada (intervalo) */
if ($data_ini !== '' && $data_fim !== '') {
  $where[] = "DATE(data_entrada) BETWEEN ? AND ?";
  $types  .= 'ss';
  array_push($args, $data_ini, $data_fim);
} elseif ($data_ini !== '') {
  $where[] = "DATE(data_entrada) >= ?";
  $types  .= 's';
  $args[]  = $data_ini;
} elseif ($data_fim !== '') {
  $where[] = "DATE(data_entrada) <= ?";
  $types  .= 's';
  $args[]  = $data_fim;
}

/* Valor total (intervalo) */
$valor_min_num = ($valor_min===''?null:(float)str_replace(',','.',$valor_min));
$valor_max_num = ($valor_max===''?null:(float)str_replace(',','.',$valor_max));
if ($valor_min_num!==null && $valor_max_num!==null) {
  if ($valor_min_num > $valor_max_num) { $t=$valor_min_num; $valor_min_num=$valor_max_num; $valor_max_num=$t; }
  $where[] = "COALESCE(valor_total,0) BETWEEN ? AND ?";
  $types  .= 'dd';
  array_push($args, $valor_min_num, $valor_max_num);
} elseif ($valor_min_num!==null) {
  $where[] = "COALESCE(valor_total,0) >= ?";
  $types  .= 'd';
  $args[]  = $valor_min_num;
} elseif ($valor_max_num!==null) {
  $where[] = "COALESCE(valor_total,0) <= ?";
  $types  .= 'd';
  $args[]  = $valor_max_num;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Helper para bind_param COM REFERÊNCIAS */
if (!function_exists('bind_all')) {
  function bind_all(mysqli_stmt $stmt, string $types, array &$args): void {
    if ($types === '' || empty($args)) return;
    $refs = [];
    foreach ($args as $k => &$v) { $refs[$k] = &$v; }
    $stmt->bind_param($types, ...$refs);
  }
}

/* ======== TOTAL ======== */
$sql_count = "SELECT COUNT(*) FROM ordens_servico $where_sql";
$stmt = $conn->prepare($sql_count);
bind_all($stmt, $types, $args);
$stmt->execute();
$stmt->bind_result($total_rows);
$stmt->fetch();
$stmt->close();

$total_rows  = (int)$total_rows;
$total_pages = max(1, (int)ceil($total_rows / max(1,$per_page)));
if ($offset >= $total_rows && $total_rows > 0) { $page = 1; $offset = 0; }

/* ======== CONSULTA PRINCIPAL ======== */
$sql = "SELECT id, nome, modelo, servico, observacao, data_entrada, hora_entrada, valor_total, metodo_pagamento, pago, status
        FROM ordens_servico
        $where_sql
        ORDER BY id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($types) {
  $params = $args; $types_all = $types . 'ii';
  $params[] = $per_page; $params[] = $offset;
  $stmt->bind_param($types_all, ...$params);
} else {
  $stmt->bind_param('ii', $per_page, $offset);
}
$stmt->execute(); $res = $stmt->get_result(); $rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ======== HELPERS DE RENDER ======== */
if (!function_exists('brl')) {
  function brl($n){ return 'R$ '.number_format((float)$n,2,',','.'); }
}
if (!function_exists('statusColor')) {
  function statusColor($s){
    $map = [
      'pendente'=>'#e3b341',
      'em_andamento'=>'#3a82ff',
      'concluido'=>'#43d17c',
      'cancelado'=>'#e05c5c',
      'aguardando_retirada'=>'#ff8c2b',
      'orcamento'=>'#a45bff'
    ];
    return $map[$s] ?? '#5b5b5b';
  }
}
if (!function_exists('render_list')) {
  function render_list($rows){
    ob_start();
    if(!$rows){
      echo '<p class="vazio">Nenhuma ordem encontrada.</p>';
    } else {
      foreach($rows as $r){
        $cor = statusColor($r['status'] ?? '');
        ?>
        <div class="os-card" data-id="<?= (int)$r['id'] ?>">
          <div class="os-left">
            <span class="os-id">#<?= str_pad($r['id'],5,'0',STR_PAD_LEFT) ?></span>
            <h2><?= htmlspecialchars($r['nome'] ?: 'Sem nome') ?></h2>
            <p><strong>Modelo:</strong> <?= htmlspecialchars($r['modelo'] ?: '-') ?></p>
            <p><strong>Serviço:</strong> <?= htmlspecialchars($r['servico'] ?: '-') ?></p>
            <?php if(!empty($r['observacao'])): ?>
            <p class="obs"><strong>Obs:</strong> <?= htmlspecialchars($r['observacao']) ?></p>
            <?php endif; ?>
          </div>
          <div class="os-right">
            <div class="status-tag" style="background:<?= $cor ?>;">
              <?= ucfirst(str_replace('_',' ',$r['status'])) ?>
            </div>
            <div class="infos">
              <span><strong>Data:</strong> <?= htmlspecialchars(date('d/m/Y', strtotime($r['data_entrada']))) ?></span>
              <span><strong>Hora:</strong> <?= htmlspecialchars(substr($r['hora_entrada'],0,5)) ?></span>
              <?php
                $pagoTexto = ((float)$r['pago'] > 0)
                  ? '✅ Sim (' . brl($r['valor_total']) . ')'
                  : '❌ Não';
              ?>
              <span><strong>Pago:</strong> <?= $pagoTexto ?></span>
            </div>
            <div class="acoes">
              <button class="btn ver" title="Ver"><i class="fa-solid fa-eye"></i></button>
              <button class="btn excluir" title="Excluir"><i class="fa-solid fa-trash"></i></button>
            </div>
          </div>
        </div>
        <?php
      }
    }
    return ob_get_clean();
  }
}
if (!function_exists('render_pager')) {
  function render_pager($page,$total_pages,$per_page,$base_qs){
    ob_start(); ?>
    <div class="pager">
      <div>Total: <span id="totalRows"><?= (int)($GLOBALS['total_rows'] ?? 0) ?></span></div>
      <div class="pager-pages">
        <?php
        $start = max(1,$page-2); $end = min($total_pages,$page+2);
        $base = $base_qs ? ($base_qs.'&') : '';
        if ($page>1) {
          $p = $page-1;
          echo '<a class="pg" href="?'.$base.'p='.$p.'&pp='.$per_page.'" data-page="'.$p.'">&laquo;</a>';
        }
        for($i=$start;$i<=$end;$i++){
          $cls = $i==$page ? 'pg active' : 'pg';
          echo '<a class="'.$cls.'" href="?'.$base.'p='.$i.'&pp='.$per_page.'" data-page="'.$i.'">'.$i.'</a>';
        }
        if ($page<$total_pages) {
          $p = $page+1;
          echo '<a class="pg" href="?'.$base.'p='.$p.'&pp='.$per_page.'" data-page="'.$p.'">&raquo;</a>';
        }
        ?>
      </div>
      <div>
        <label>Por página:
          <select id="pp">
            <?php foreach([5,10,25,50,100] as $n): ?>
              <option value="<?= $n ?>" <?= $n==$per_page?'selected':'' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </div>
    <script>
    (function(){
      var sel = document.getElementById('pp');
      if(!sel) return;
      sel.addEventListener('change', function(){
        const url = new URL(location.href);
        url.searchParams.set('pp', this.value);
        url.searchParams.set('p', '1');
        location.href = url.toString();
      });
    })();
    </script>
    <?php
    return ob_get_clean();
  }
}

/* ======== RESPOSTA PARCIAL (AJAX) ======== */
if (isset($Q['partial']) && $Q['partial'] == '1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok'     => true,
    'list'   => render_list($rows),
    'pager'  => render_pager($page,$total_pages,$per_page,$base_qs),
    'total'  => (int)$total_rows,
    'debug'  => [
      'received' => [
        'ids'=>$ids,'nome'=>$nome,'modelo'=>$modelo,'servico'=>$servico,'telefone'=>$telefone,'cpf'=>$cpf,
        'status'=>$statusArr,'pago'=>$pago,'metodo'=>$metodo,'data_ini'=>$data_ini,'data_fim'=>$data_fim,
        'valor_min'=>$valor_min,'valor_max'=>$valor_max,
        'p'=>$page,'pp'=>$per_page
      ],
      'where_sql' => $where ? implode(' AND ', $where) : '(sem WHERE)',
      'types'     => $types,
      'args'      => $args
    ]
  ]);
  exit;
}
?>
<link rel="stylesheet" href="css/os.css">

<div class="os-page">
  <header class="os-header">
    <h1>Ordens de Serviço</h1>
    <div class="header-actions">
      <button id="btnFiltro" class="btn primary"><i class="fa-solid fa-filter"></i> Filtros</button>
      <button id="btnLimpar" class="btn ghost" title="Limpar filtros">Limpar</button>
    </div>
  </header>

  <div id="osList" class="os-list">
    <?= render_list($rows) ?>
  </div>

  <footer class="os-footer" id="paginacao">
    <?= render_pager($page,$total_pages,$per_page,$base_qs) ?>
  </footer>
</div>

<!-- MODAL DE FILTROS -->
<div id="modalFiltros" class="modal hidden" aria-hidden="true">
  <div class="modal-body" style="max-width:780px">
    <h3>Filtros avançados</h3>
    <form id="filtrosForm" onsubmit="return false;">
      <div class="grid-3" style="gap:14px;margin-top:10px">
        <div>
          <label>OS (nº ou faixa)</label>
          <input type="text" name="ids" placeholder="123 ou 100-200">
        </div>
        <div>
          <label>Nome</label>
          <input type="text" name="nome" placeholder="Cliente...">
        </div>
        <div>
          <label>Modelo</label>
          <input type="text" name="modelo" placeholder="Ex.: iPhone 11">
        </div>
        <div>
          <label>Serviço</label>
          <input type="text" name="servico" placeholder="Ex.: Troca de tela">
        </div>
        <div>
          <label>Telefone</label>
          <input type="text" name="telefone" placeholder="(xx) xxxxx-xxxx">
        </div>
        <div>
          <label>CPF</label>
          <input type="text" name="cpf" placeholder="000.000.000-00">
        </div>
        <div>
          <label>Data (início)</label>
          <input type="date" name="data_ini">
        </div>
        <div>
          <label>Data (fim)</label>
          <input type="date" name="data_fim">
        </div>
        <div>
          <label>Pago</label>
          <select name="pago">
            <option value="">—</option>
            <option value="1">Sim</option>
            <option value="0">Não</option>
          </select>
        </div>
        <div>
          <label>Método de pagamento</label>
          <select name="metodo_pagamento">
            <option value="">—</option>
            <option value="pix">Pix</option>
            <option value="dinheiro">Dinheiro</option>
            <option value="credito">Cartão de Crédito</option>
            <option value="debito">Cartão de Débito</option>
            <option value="outro">Outro</option>
          </select>
        </div>
        <div>
          <label>Valor mín (R$)</label>
          <input type="number" step="0.01" name="valor_min" placeholder="0,00" inputmode="decimal">
        </div>
        <div>
          <label>Valor máx (R$)</label>
          <input type="number" step="0.01" name="valor_max" placeholder="0,00" inputmode="decimal">
        </div>
      </div>

      <div style="margin-top:8px">
        <label>Status</label>
        <div class="chips">
          <label><input type="checkbox" name="status[]" value="pendente"> pendente</label>
          <label><input type="checkbox" name="status[]" value="em_andamento"> em_andamento</label>
          <label><input type="checkbox" name="status[]" value="concluido"> concluido</label>
          <label><input type="checkbox" name="status[]" value="cancelado"> cancelado</label>
          <label><input type="checkbox" name="status[]" value="aguardando_retirada"> aguardando_retirada</label>
          <label><input type="checkbox" name="status[]" value="orcamento"> orcamento</label>
        </div>
      </div>

      <div class="modal-actions" style="margin-top:14px">
        <button class="btn-ghost" data-close>Cancelar</button>
        <button id="btnAplicarFiltros" type="button" class="btn primary">
          <i class="fa-solid fa-check"></i> Aplicar
        </button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL VISUALIZAR / EDITAR O.S. COMPLETA -->
<div id="modalView" class="modal hidden" aria-hidden="true">
  <div class="modal-body">
    <h3>Detalhes da O.S. <span id="viewId"></span></h3>

    <div class="grid-2" style="gap:18px; margin-top:12px;">
      <div>
        <label>Cliente</label>
        <div id="viewNome" class="editable"></div>

        <label>CPF</label>
        <div id="viewCPF" class="editable"></div>

        <label>Telefone</label>
        <div id="viewTelefone" class="editable"></div>

        <label>Endereço</label>
        <div id="viewEndereco" class="editable"></div>

        <label>Observação</label>
        <div id="viewObs" class="editable" style="white-space:pre-wrap"></div>

        <label>Senha padrão</label>
        <div id="viewSenhaPadrao" class="editable"></div>

        <label>Senha escrita</label>
        <div id="viewSenhaEscrita" class="editable"></div>

        <label>PDF</label>
        <div id="viewPdf" class="editable"></div>
      </div>

      <div>
        <label>Modelo</label>
        <div id="viewModelo" class="editable"></div>

        <label>Serviço</label>
        <div id="viewServico" class="editable" style="white-space:pre-wrap"></div>

        <label>Data de entrada</label>
        <div id="viewData" class="editable"></div>

        <label>Hora de entrada</label>
        <div id="viewHora" class="editable"></div>

        <label>💰 Valor Total</label>
        <div id="viewValorTotal" class="editable"></div>

        <label>Método de Pagamento</label>
        <select id="viewMetodoPagamento" class="editable-select">
          <option value="">—</option>
          <option value="pix">❖ Pix</option>
          <option value="dinheiro">💵 Dinheiro</option>
          <option value="credito">💳 Crédito</option>
          <option value="debito">💳 Débito</option>
          <option value="outro">🔹 Outro</option>
        </select>

        <label>✅ Pago</label>
        <label class="switch">
          <input type="checkbox" id="viewPago" data-field="pago">
          <span class="slider"></span>
        </label>

        <label>Status</label>
        <select id="viewStatusSelect" class="status-select" data-id="">
          <option value="pendente">pendente</option>
          <option value="em_andamento">em_andamento</option>
          <option value="concluido">concluido</option>
          <option value="cancelado">cancelado</option>
          <option value="aguardando_retirada">aguardando_retirada</option>
          <option value="orcamento">orcamento</option>
        </select>
      </div>
    </div>

    <div style="margin-top:18px; display:flex; justify-content:flex-end; gap:10px;">
      <button class="btn-ghost" data-close>Fechar</button>
    </div>
  </div>
</div>

<!-- MODAL EDITAR -->
<div id="modalEdit" class="modal hidden" aria-hidden="true">
  <div class="modal-body">
    <h3>Editar O.S. <span id="editId"></span></h3>
    <form id="editForm" onsubmit="return false;">
      <input type="hidden" id="editIdInput">
      <div class="grid-2">
        <div>
          <label>Nome</label><input id="f_nome" type="text">
          <label>Modelo</label><input id="f_modelo" type="text">
          <label>Serviço</label><input id="f_servico" type="text">
          <label>Observação</label><input id="f_observacao" type="text">
          <label>Pago</label><input id="f_pago" type="text" placeholder="0,00">
        </div>
        <div>
          <label>Data</label><input id="f_data" type="date">
          <label>Hora</label><input id="f_hora" type="time">
          <label>Status</label>
          <select id="f_status">
            <option value="pendente">pendente</option>
            <option value="em_andamento">em_andamento</option>
            <option value="concluido">concluido</option>
            <option value="cancelado">cancelado</option>
            <option value="aguardando_retirada">aguardando_retirada</option>
            <option value="orcamento">orcamento</option>
          </select>
        </div>
      </div>
      <div class="modal-actions">
        <button class="btn-ghost" data-close>Cancelar</button>
        <button id="btnSalvarEdit">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  // ==== Paleta de cores de status ====
  const statusColorJS = {
    pendente: '#e3b341',
    em_andamento: '#3a82ff',
    concluido: '#43d17c',
    cancelado: '#e05c5c',
    aguardando_retirada: '#ff8c2b',
    orcamento: '#a45bff'
  };

  const $ = s=>document.querySelector(s);
  const $$ = s=>Array.from(document.querySelectorAll(s));
  const list = $('#osList');
  const pager = $('#paginacao');

  function fmtBR(n){
    const v = parseFloat((n ?? 0));
    if (isNaN(v)) return 'R$ 0,00';
    return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(v);
  }

  function updateCardFromField(id, campo, valor){
    const card = document.querySelector(`.os-card[data-id="${id}"]`);
    if (!card) return;

    const left  = card.querySelector('.os-left');
    const right = card.querySelector('.os-right');

    if (campo === 'nome') {
      const h2 = left.querySelector('h2');
      if (h2) h2.textContent = valor || 'Sem nome';
    }

    if (campo === 'modelo') {
      const pModelo = Array.from(left.querySelectorAll('p'))
        .find(p => p.textContent.trim().startsWith('Modelo:'));
      if (pModelo) pModelo.innerHTML = '<strong>Modelo:</strong> ' + (valor || '-');
    }

    if (campo === 'servico') {
      const pServ = Array.from(left.querySelectorAll('p'))
        .find(p => p.textContent.trim().startsWith('Serviço:'));
      if (pServ) pServ.innerHTML = '<strong>Serviço:</strong> ' + (valor || '-');
    }

    if (campo === 'observacao') {
      let pObs = left.querySelector('.obs');
      if (valor) {
        if (!pObs) {
          pObs = document.createElement('p');
          pObs.className = 'obs';
          left.appendChild(pObs);
        }
        pObs.innerHTML = '<strong>Obs:</strong> ' + valor;
      } else if (pObs) {
        pObs.remove();
      }
    }

    // Pago / valor_total
    if (campo === 'pago' || campo === 'valor_total') {
      const spans = right.querySelectorAll('.infos span');
      const pagoSpan = spans[2];
      if (pagoSpan) {
        let isPago = false;
        let valorNum = 0;

        if (campo === 'pago') {
          const n = parseFloat(valor);
          if (!isNaN(n) && (n === 0 || n === 1)) {
            isPago = n === 1;
            const existente = pagoSpan.textContent
              .replace(/[^\d,.-]/g, '')
              .replace(/\.(?=\d{3})/g, '')
              .replace(',', '.');
            valorNum = parseFloat(existente) || (card.dataset.valorTotal ? parseFloat(card.dataset.valorTotal) : 0);
          } else {
            valorNum = isNaN(n) ? 0 : n;
            isPago = valorNum > 0;
          }
        } else {
          valorNum = parseFloat(valor) || 0;
          isPago = /Sim/.test(pagoSpan.textContent) ? true : (valorNum > 0);
        }

        card.dataset.valorTotal = String(valorNum);
        pagoSpan.innerHTML = `<strong>Pago:</strong> ${isPago ? '✅ Sim (' + fmtBR(valorNum) + ')' : '❌ Não'}`;
      }
    }

    if (campo === 'data_entrada') {
      const spans = right.querySelectorAll('.infos span');
      const dataSpan = spans[0];
      if (dataSpan) {
        const dt = valor ? new Date(valor) : null;
        const str = dt ? dt.toLocaleDateString('pt-BR') : '-';
        dataSpan.innerHTML = '<strong>Data:</strong> ' + str;
      }
    }

    if (campo === 'hora_entrada') {
      const spans = right.querySelectorAll('.infos span');
      const horaSpan = spans[1];
      if (horaSpan) horaSpan.innerHTML = '<strong>Hora:</strong> ' + (valor ? String(valor).slice(0,5) : '-');
    }

    if (campo === 'status') {
      const tag = right.querySelector('.status-tag');
      if (tag) {
        tag.textContent = (valor || '').replace('_',' ');
        tag.style.background = statusColorJS[valor] || '#5b5b5b';
      }
    }

    card.classList.add('flash-success');
    setTimeout(()=> card.classList.remove('flash-success'), 700);
  }
  window.updateCardFromField = updateCardFromField;

  function qsParams(extra = {}) {
    const current = new URLSearchParams(window.location.search);
    const out = new URLSearchParams();

    for (const [k,v] of current.entries()) out.append(k, v);

    for (const k of Object.keys(extra)) {
      out.delete(k);
      const val = extra[k];
      if (Array.isArray(val)) {
        val.forEach(v=> out.append(k, v));
      } else if (val !== undefined && val !== null && val !== '') {
        out.set(k, val);
      }
    }

    if (!out.get('p')) out.set('p','1');
    return out;
  }
  window.qsParams = qsParams;

  async function fetchList(params){
    params.set('partial','1');
    const r = await fetch('os.php?'+params.toString(), {
      headers:{'X-Requested-With':'fetch'},
      cache: 'no-store'
    });
    const j = await r.json();
    list.innerHTML = j.list;
    pager.innerHTML = j.pager;
    const p2 = new URLSearchParams(params);
    p2.delete('partial');
    history.replaceState(null,'','?'+p2.toString());
    $('#totalRows') && ($('#totalRows').textContent = j.total);
    wireCards(); wirePager(); wirePP();
  }
  window.fetchList = fetchList;

  function wirePager(){
    document.querySelectorAll('#paginacao .pg').forEach(btn=>{
      btn.addEventListener('click', (ev)=>{
        ev.preventDefault();
        const page = btn.dataset.page || 1;
        const params = qsParams({p: page});
        fetchList(params);
      });
    });
  }
  function wirePP(){
    const pp = $('#pp');
    if (pp) pp.addEventListener('change', ()=>{
      fetchList(qsParams({pp: pp.value, p: '1'}));
    });
  }

  // Abre/fecha modal de filtros
  const modalFiltros = document.getElementById('modalFiltros');
  const formFiltros  = document.getElementById('filtrosForm');
  document.getElementById('btnFiltro').addEventListener('click', ()=> {
    const p = new URLSearchParams(window.location.search);
    formFiltros.reset();

    formFiltros.querySelectorAll('input[name]:not([type="checkbox"]), select[name]').forEach(el=>{
      const name = el.getAttribute('name');
      const val  = p.get(name);
      if (val !== null) el.value = val;
    });

    const marcados = new Set(p.getAll('status[]'));
    formFiltros.querySelectorAll('input[name="status[]"]').forEach(chk=>{
      chk.checked = marcados.has(chk.value);
    });

    modalFiltros.classList.remove('hidden');
    modalFiltros.setAttribute('aria-hidden','false');
  });
  modalFiltros.addEventListener('click', e=>{
    if (e.target===modalFiltros) {
      modalFiltros.classList.add('hidden');
      modalFiltros.setAttribute('aria-hidden','true');
    }
  });
  modalFiltros.querySelector('[data-close]').addEventListener('click', ()=>{
    modalFiltros.classList.add('hidden');
    modalFiltros.setAttribute('aria-hidden','true');
  });

  // Limpar filtros
  document.getElementById('btnLimpar').addEventListener('click', ()=>{
    const url = new URL(location.href);
    url.search = '';
    const ppSel = document.getElementById('pp');
    url.searchParams.set('p','1');
    url.searchParams.set('pp', String(ppSel?.value || 10));
    location.href = url.toString();
  });

  // Aplicar filtros
  document.getElementById('btnAplicarFiltros').addEventListener('click', (e)=>{
    e.preventDefault();
    const form = document.getElementById('filtrosForm');
    const fd = new FormData(form);

    const url = new URL(location.href);
    url.search = '';

    const ppSel = document.getElementById('pp');
    url.searchParams.set('p','1');
    url.searchParams.set('pp', String(ppSel?.value || 10));

    for (const [k, v] of fd.entries()) {
      if (v === '' || v === null) continue;
      url.searchParams.append(k, v);
    }
    location.href = url.toString();
  });

  // ======== VIEW ========
  function normMetodo(v){
    if (!v) return '';
    v = String(v).trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const alias = {
      'pix':'pix','dinheiro':'dinheiro','credito':'credito','debito':'debito','outro':'outro',
      'cartao de credito':'credito','cartao de debito':'debito'
    };
    return alias[v] || v;
  }

  async function loadView(id) {
    try {
      const r = await fetch('includes/get_os.php?id=' + encodeURIComponent(id));
      const j = await r.json();
      if (!j.ok) { alert(j.msg || 'Falha ao carregar O.S.'); return; }
      const d = j.data;

      const fmtBRv = (v)=> new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(parseFloat(v||0));
      const safe   = (v)=> (v===null||v===undefined||v==='') ? '—' : String(v);

      function updateViewField(field, value) {
        const idMap = {
          valor_total: 'viewValorTotal',
          pago: 'viewPago',
          status: 'viewStatusSelect',
          data_entrada: 'viewData',
          hora_entrada: 'viewHora',
        };
        const domId = idMap[field] || `view${field.charAt(0).toUpperCase()+field.slice(1)}`;
        const el = document.getElementById(domId);
        if (!el) return;

        if (field === 'status' && el.tagName === 'SELECT') {
          el.value = value || 'pendente';
        } else if (field.match(/valor|pago/)) {
          el.textContent = fmtBRv(value);
        } else if (field === 'data_entrada') {
          el.textContent = value ? new Date(value).toLocaleDateString('pt-BR') : '—';
        } else {
          el.textContent = value || '—';
        }
        el.classList.add('flash-success');
        setTimeout(() => el.classList.remove('flash-success'), 700);
      }

      // Preenche campos de texto
      const campos = [
        { id: 'viewNome', campo: 'nome' },
        { id: 'viewCPF', campo: 'cpf' },
        { id: 'viewTelefone', campo: 'telefone' },
        { id: 'viewEndereco', campo: 'endereco' },
        { id: 'viewModelo', campo: 'modelo' },
        { id: 'viewServico', campo: 'servico' },
        { id: 'viewObs', campo: 'observacao' },
        { id: 'viewSenhaPadrao', campo: 'senha_padrao' },
        { id: 'viewSenhaEscrita', campo: 'senha_escrita' },
        { id: 'viewValorTotal', campo: 'valor_total' },
        { id: 'viewMetodoPagamento', campo: 'metodo_pagamento' },
        { id: 'viewPago', campo: 'pago' },
        { id: 'viewData', campo: 'data_entrada' },
        { id: 'viewHora', campo: 'hora_entrada' },
        { id: 'viewPdf', campo: 'pdf_path' }
      ];

      document.getElementById('viewId').textContent = '#' + String(d.id).padStart(5, '0');
      const viewSel = document.getElementById('viewStatusSelect');
      viewSel.dataset.id = d.id;
      viewSel.value = d.status || 'pendente';

      for (const c of campos) {
        const el = document.getElementById(c.id);
        if (!el) continue;
        const valor = d[c.campo];
        if (c.campo.startsWith('valor') || c.campo === 'pago') {
          el.textContent = fmtBRv(valor);
        } else if (c.campo === 'data_entrada' && valor) {
          el.textContent = new Date(valor).toLocaleDateString('pt-BR');
        } else {
          el.textContent = safe(valor);
        }
        el.dataset.field = c.campo;
        el.dataset.id = d.id;
        el.classList.add('editable');
      }

      // Checkbox de pago
      const pagoBox = document.getElementById('viewPago');
      if (pagoBox) {
        pagoBox.checked = d.pago == 1;
        pagoBox.dataset.id = d.id;

        pagoBox.addEventListener('change', async () => {
          const marcado = pagoBox.checked;
          const novoValor = marcado ? 1 : 0;

          try {
            const resp = await fetch('includes/atualizar_os.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({ id: d.id, campo: 'pago', valor: novoValor })
            });
            const jj = await resp.json();
            if (!jj.ok) throw new Error(jj.msg);

            pagoBox.classList.add('flash-success');
            setTimeout(() => pagoBox.classList.remove('flash-success'), 700);

            if (novoValor === 1) {
              let valorAtual = 0;
              const elValor = document.getElementById('viewValorTotal');
              if (elValor) {
                const txt = elValor.textContent.replace('R$','').replace(/\./g,'').replace(',','.').trim();
                const parsed = parseFloat(txt);
                if (!isNaN(parsed)) valorAtual = parsed;
              }
              updateCardFromField(parseInt(d.id), 'pago', valorAtual);
            } else {
              updateCardFromField(parseInt(d.id), 'pago', 0);
            }

            d.pago = novoValor;
          } catch (err) {
            pagoBox.classList.add('flash-error');
            setTimeout(() => pagoBox.classList.remove('flash-error'), 700);
            pagoBox.checked = !pagoBox.checked;
            alert('Erro ao atualizar status de pagamento.');
          }
        });
      }

      // Método de pagamento
      const selMetodo = document.getElementById('viewMetodoPagamento');
      if (selMetodo) {
        if (selMetodo.options.length === 0) {
          selMetodo.innerHTML = `
            <option value="">—</option>
            <option value="pix">❖ Pix</option>
            <option value="dinheiro">💵 Dinheiro</option>
            <option value="credito">💳 Crédito</option>
            <option value="debito">💳 Débito</option>
            <option value="outro">🔹 Outro</option>`;
        }
        const metodoDB = normMetodo(d.metodo_pagamento || '');
        selMetodo.value = ['pix','dinheiro','credito','debito','outro'].includes(metodoDB) ? metodoDB : '';

        selMetodo.onchange = async () => {
          const metodo = selMetodo.value;
          try {
            const resp = await fetch('includes/atualizar_os.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({ id: d.id, campo: 'metodo_pagamento', valor: metodo })
            });
            const jj = await resp.json();
            if (!jj.ok) throw new Error(jj.msg || 'Falha ao salvar método');
            d.metodo_pagamento = metodo;
            selMetodo.classList.add('flash-success');
            setTimeout(()=> selMetodo.classList.remove('flash-success'), 700);
          } catch {
            selMetodo.classList.add('flash-error');
            setTimeout(()=> selMetodo.classList.remove('flash-error'), 700);
            alert('Erro ao salvar método de pagamento.');
          }
        };
      }

      // Abre modal
      openModal($('#modalView'));

      // Edição inline simples
      document.querySelectorAll('.editable:not(.editable-select)').forEach((el) => {
        el.addEventListener('click', function () {
          if (this.querySelector('input, textarea')) return;

          const campo = this.dataset.field;
          const id = this.dataset.id;
          const valorAtual =
            this.textContent.trim() === '—'
              ? ''
              : this.textContent
                  .trim()
                  .replace('R$', '')
                  .replace(/\./g, '')
                  .replace(',', '.');

          let input;
          if (campo.includes('data')) {
            input = document.createElement('input');
            input.type = 'date';
            input.value = d[campo]?.split('T')[0] || '';
          } else if (campo.includes('hora')) {
            input = document.createElement('input');
            input.type = 'time';
            input.value = d[campo] || '';
          } else if (campo.startsWith('valor') || campo === 'pago') {
            input = document.createElement('input');
            input.type = 'number';
            input.step = '0.01';
            input.value = parseFloat(valorAtual || 0).toFixed(2);
          } else if (campo === 'observacao') {
            input = document.createElement('textarea');
            input.value = this.textContent.trim();
          } else {
            input = document.createElement('input');
            input.type = 'text';
            input.value = this.textContent.trim() === '—' ? '' : this.textContent.trim();
          }

          input.className = 'inline-input';
          this.textContent = '';
          this.appendChild(input);
          input.focus();

          const salvar = async () => {
            const novoValor = input.value.trim();
            const parent = this;
            if (novoValor === valorAtual) {
              parent.textContent = valorAtual || '—';
              return;
            }

            const params = new URLSearchParams({ id, campo, valor: novoValor });

            try {
              const resp = await fetch('includes/atualizar_os.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
              });
              const res = await resp.json();
              if (res.ok) {
                parent.textContent =
                  campo.startsWith('valor') || campo === 'pago'
                    ? fmtBR(novoValor)
                    : novoValor || '—';
                parent.classList.add('flash-success');
                setTimeout(() => parent.classList.remove('flash-success'), 800);

                updateCardFromField(parseInt(id, 10), campo, novoValor);
              } else {
                throw new Error(res.msg || 'Erro');
              }
            } catch (err) {
              parent.textContent = valorAtual || '—';
              parent.classList.add('flash-error');
              setTimeout(() => parent.classList.remove('flash-error'), 800);
            }
          };

          input.addEventListener('blur', salvar);
          input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); salvar(); }
            else if (e.key === 'Escape') { this.textContent = valorAtual || '—'; }
          });
        });
      });

      // Status + Finalização
      const select = $('#viewStatusSelect');
      select.onchange = function () {
        const id = this.dataset.id;
        const status = this.value;
        let tipo_pagamento = '';

        if (status === 'concluido') {
          const modal = document.createElement('div');
          modal.className = 'modal-pagamento show';
          modal.innerHTML = `
            <div class="modal-pagamento-content">
              <h2>Finalizar Pagamento</h2>
              <p class="desc">Escolha o método e confirme o valor recebido.</p>

              <select id="tipoPagamentoSelect" class="styled-select">
                <option value="" disabled selected>Selecione o tipo de pagamento...</option>
                <option value="dinheiro">💵 Dinheiro</option>
                <option value="pix">❖ Pix</option>
                <option value="credito">💳 Cartão de Crédito</option>
                <option value="debito">💳 Cartão de Débito</option>
              </select>

              <div id="valorBox" class="valor-box hidden">
                <p id="valorAtualTxt"></p>
                <div id="valorResumo" class="valor-resumo"></div>
                <div class="valor-acoes">
                  <button id="btnConfirmarValor" class="confirm-btn">✔ Valor correto</button>
                  <button id="btnEditarValor" class="edit-btn">✏️ Editar</button>
                </div>
                <div id="editValorBox" class="edit-box hidden">
                  <input id="novoValorInput" type="number" step="0.01" min="0" placeholder="Digite o novo valor">
                  <button id="btnSalvarValor" class="confirm-btn">Salvar valor</button>
                </div>
              </div>

              <div class="modal-buttons">
                <button id="cancelPagamento" class="cancel-btn">Cancelar</button>
              </div>
            </div>
          `;
          document.body.appendChild(modal);

          const selectMetodo = modal.querySelector('#tipoPagamentoSelect');
          const valorBox = modal.querySelector('#valorBox');
          const valorAtualTxt = modal.querySelector('#valorAtualTxt');
          const valorResumo = modal.querySelector('#valorResumo');
          const editBox = modal.querySelector('#editValorBox');
          const inputNovoValor = modal.querySelector('#novoValorInput');

          modal.querySelector('#cancelPagamento').onclick = () => {
            this.value = d.status || 'em_andamento';
            modal.classList.remove('show');
            setTimeout(() => modal.remove(), 250);
          };

          valorResumo.innerHTML = `<strong>Valor total:</strong> ${fmtBRv(d.valor_total || 0)}`;

          selectMetodo.addEventListener('change', () => {
            tipo_pagamento = selectMetodo.value;

            let valorBase = parseFloat(d['valor_total'] || 0);
            if (!valorBase || valorBase === 0) {
              const elView = document.getElementById('viewValorTotal');
              if (elView) {
                const txt = elView.textContent.replace('R$','').replace(/\./g,'').replace(',','.').trim();
                const parsed = parseFloat(txt);
                if (!isNaN(parsed) && parsed > 0) valorBase = parsed;
              }
            }

            valorAtualTxt.textContent = `Valor atual (${tipo_pagamento.toUpperCase()}): ${fmtBRv(valorBase)}`;
            valorBox.classList.remove('hidden');

            modal.querySelector('#btnConfirmarValor').onclick = () => confirmarPagamento(valorBase);
            modal.querySelector('#btnEditarValor').onclick = () => {
              editBox.classList.remove('hidden');
              inputNovoValor.focus();
            };
            modal.querySelector('#btnSalvarValor').onclick = () => {
              const novo = parseFloat(inputNovoValor.value || 0);
              if (isNaN(novo) || novo <= 0) { alert('Digite um valor válido.'); return; }
              confirmarPagamento(novo);
            };
          });

          function confirmarPagamento(valorConfirmado) {
            if (!valorConfirmado || valorConfirmado <= 0) {
              alert('O valor não pode ser zero. Edite o valor antes de confirmar.');
              return;
            }
            const valorNum = parseFloat(String(valorConfirmado).replace(',', '.'));
            if (isNaN(valorNum) || valorNum <= 0) { alert('Valor inválido.'); return; }
            const valorFmt = valorNum.toFixed(2);

            (async () => {
              try {
                await fetch('includes/atualizar_status.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: new URLSearchParams({
                    id, status: 'concluido', tipo_pagamento, valor_confirmado: valorFmt
                  })
                });

                await fetch('includes/atualizar_os.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: `id=${id}&campo=valor_total&valor=${valorFmt}`
                });

                await fetch('includes/atualizar_os.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: `id=${id}&campo=metodo_pagamento&valor=${encodeURIComponent(tipo_pagamento||'')}`
                });

                await fetch('includes/atualizar_os.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: `id=${id}&campo=pago&valor=1`
                });

                d['valor_total'] = valorFmt;
                d.metodo_pagamento = tipo_pagamento || '';
                d.pago = 1;

                updateViewField('valor_total', valorFmt);

                const chkPago = document.getElementById('viewPago');
                if (chkPago) {
                  chkPago.checked = true;
                  chkPago.classList.add('flash-success');
                  setTimeout(() => chkPago.classList.remove('flash-success'), 700);
                }

                const selMetodo = document.getElementById('viewMetodoPagamento');
                if (selMetodo) {
                  selMetodo.value = tipo_pagamento || '';
                  selMetodo.classList.add('flash-success');
                  setTimeout(() => selMetodo.classList.remove('flash-success'), 700);
                }

                const selStatus = document.getElementById('viewStatusSelect');
                if (selStatus) {
                  selStatus.value = 'concluido';
                  selStatus.classList.add('flash-success');
                  setTimeout(() => selStatus.classList.remove('flash-success'), 700);
                }

                updateCardFromField(parseInt(id), 'status', 'concluido');
                updateCardFromField(parseInt(id), 'pago', 1);
                updateCardFromField(parseInt(id), 'valor_total', valorFmt);

                valorAtualTxt.textContent = `✅ Valor confirmado: ${fmtBRv(valorFmt)}`;
                valorResumo.innerHTML = `<strong>Total pago:</strong> ${fmtBRv(valorFmt)}`;
                valorBox.classList.add('flash-success');
                setTimeout(() => valorBox.classList.remove('flash-success'), 800);

                setTimeout(() => {
                  modal.classList.remove('show');
                  setTimeout(() => modal.remove(), 250);
                }, 600);
              } catch (err) {
                console.error('Erro ao confirmar pagamento:', err);
                alert('Erro de comunicação com o servidor.');
              }
            })();
          }

          return;
        }

        // Outros status → atualiza status e zera pago visualmente
        fetch('includes/atualizar_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `id=${id}&status=${status}`
        }).then(() => {
          updateCardFromField(parseInt(id, 10), 'status', status);
          updateCardFromField(parseInt(id, 10), 'pago', 0);
          updateViewField('pago', 0);
          fetch('includes/atualizar_os.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id, campo: 'pago', valor: 0 })
          });
        });
      };
    } catch (err) {
      console.error(err);
      alert('Erro ao carregar detalhes da O.S.');
    }
  }
  window.loadView = loadView;

  // ======== EDIT ========
  async function loadEdit(id){
    const r = await fetch('includes/get_os.php?id='+encodeURIComponent(id), {headers:{'X-Requested-With':'fetch'}});
    const j = await r.json();
    if (!j.ok) { alert(j.msg||'Falha ao carregar.'); return; }
    const d = j.data;
    $('#editId').textContent = '#'+String(d.id).padStart(5,'0');
    $('#editIdInput').value = d.id;
    $('#f_nome').value = d.nome||'';
    $('#f_modelo').value = d.modelo||'';
    $('#f_servico').value = d.servico||'';
    $('#f_observacao').value = d.observacao||'';
    $('#f_pago').value = (parseFloat(d.pago||0)).toFixed(2).replace('.',',');
    $('#f_data').value = d.data_entrada||'';
    $('#f_hora').value = (d.hora_entrada||'').slice(0,5);
    $('#f_status').value = d.status||'pendente';
    openModal($('#modalEdit'));
  }

  async function postUpdate(id, campo, valor){
    const body = new URLSearchParams({id, campo, valor});
    const r = await fetch('includes/atualizar_os.php', {
      method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body
    });
    return r.json();
  }

  $('#btnSalvarEdit').addEventListener('click', async ()=>{
    const id = $('#editIdInput').value;
    const payloads = [
      ['nome', $('#f_nome').value.trim()],
      ['modelo', $('#f_modelo').value.trim()],
      ['servico', $('#f_servico').value.trim()],
      ['observacao', $('#f_observacao').value.trim()],
      ['pago', ($('#f_pago').value||'0').replace(/\./g,'').replace(',','.')],
      ['data_entrada', $('#f_data').value],
      ['hora_entrada', $('#f_hora').value],
      ['status', $('#f_status').value]
    ];
    for (const [campo, valor] of payloads) {
      const j = await postUpdate(id, campo, valor);
      if (!j.ok) { alert(j.msg||('Falha ao salvar '+campo)); return; }
    }
    closeModal($('#modalEdit'));
    fetchList(qsParams());
  });

  // ======== EXCLUIR ========
  async function deleteOS(id) {
    const card = document.querySelector(`.os-card[data-id="${id}"]`);
    if (!confirm('Tem certeza que deseja excluir a O.S. #' + String(id).padStart(5, '0') + ' ?')) return;

    try {
      const r = await fetch('includes/deletar_os.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id })
      });
      const j = await r.json();

      if (!j.ok) { alert(j.msg || 'Erro ao excluir.'); return; }

      if (card) {
        card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.97)';
        setTimeout(() => card.remove(), 300);
      }

      setTimeout(() => { fetchList(qsParams()); }, 400);
    } catch (err) {
      console.error(err);
      alert('Erro ao excluir O.S.');
    }
  }

  // ======== Modais genéricos ========
  function openModal(modal){ modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); }
  function closeModal(modal){ modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); }
  $$('.modal [data-close]').forEach(b=> b.addEventListener('click', e=> closeModal(e.target.closest('.modal'))));
  $$('.modal').forEach(m=> m.addEventListener('click', e=>{ if(e.target===m) closeModal(m); }));

  function wireCards(){
    $$('.os-card .btn.ver').forEach(b=>{
      b.addEventListener('click', ()=> loadView(b.closest('.os-card').dataset.id));
    });
    $$('.os-card .btn.excluir').forEach(b=>{
      b.addEventListener('click', ()=> deleteOS(b.closest('.os-card').dataset.id));
    });
  }

  // Inicial
  wireCards(); wirePager(); wirePP();
})();
</script>
