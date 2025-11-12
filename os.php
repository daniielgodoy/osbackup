<?php
/* ===========================================================================
 * os.php — Lista, filtro, paginação AJAX e modal de Visualizar/Editar OS
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

/* Helper QS base */
if (!function_exists('build_base_qs')) {
  function build_base_qs(array $Q): string {
    $keep = $Q;
    unset($keep['p'], $keep['pp'], $keep['partial']);
    return http_build_query($keep);
  }
}
$base_qs = build_base_qs($Q);

/* ID único ou faixa */
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
/* Continua selecionando metodo_pagamento e pago para lógica interna */
$sql = "SELECT id, nome, modelo, servico, observacao, data_entrada, hora_entrada,
               valor_total, metodo_pagamento, pago, status
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
$stmt->execute();
$res  = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
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
    if (!$rows) {
      echo '<p class="vazio">Nenhuma ordem encontrada.</p>';
    } else {
      foreach($rows as $r){
        $cor = statusColor($r['status'] ?? '');
        $pagoFlag  = (float)($r['pago'] ?? 0) > 0;
        $pagoTexto = $pagoFlag ? '✅ Sim' : '❌ Não';
        ?>
        <div class="os-card"
             data-id="<?= (int)$r['id'] ?>"
             data-pago="<?= $pagoFlag ? 1 : 0 ?>"
             data-metodo="<?= htmlspecialchars($r['metodo_pagamento'] ?? '', ENT_QUOTES) ?>"
             data-valor-total="<?= htmlspecialchars($r['valor_total'] ?? 0, ENT_QUOTES) ?>">
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
              <span class="info-data"><strong>Data:</strong> <?= htmlspecialchars(date('d/m/Y', strtotime($r['data_entrada']))) ?></span>
              <span class="info-hora"><strong>Hora:</strong> <?= htmlspecialchars(substr($r['hora_entrada'],0,5)) ?></span>
              <span class="info-valor"><strong>Valor:</strong> <?= brl($r['valor_total'] ?? 0) ?></span>
              <span class="info-pago"><strong>Pago:</strong> <?= $pagoTexto ?></span>
            </div>
<div class="acoes">
  <button class="btn ver" title="Ver">
    <i class="fa-solid fa-eye"></i>
  </button>

  <button class="btn print" title="Imprimir OS">
    <i class="fa-solid fa-print"></i>
  </button>

  <button class="btn excluir" title="Excluir">
    <i class="fa-solid fa-trash"></i>
  </button>
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
          echo '<a class="pg" href="?'.$base.'p='.$p.'&pp='.$per_page.'" data-page="'.$p.'" >&raquo;</a>';
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
      if (!sel) return;
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
        'status'=>$statusArr,
        'data_ini'=>$data_ini,'data_fim'=>$data_fim,
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

  const $  = s => document.querySelector(s);
  const $$ = s => Array.from(document.querySelectorAll(s));
  const list  = $('#osList');
  const pager = $('#paginacao');

  function fmtBR(n){
    const v = parseFloat((n ?? 0));
    if (isNaN(v)) return 'R$ 0,00';
    return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(v);
  }

  // toNum robusto (corrige bug 150 -> 15000)
  function toNum(v){
    if (v == null) return 0;
    let s = String(v).trim().replace(/[R$\s]/g,'');
    if (!s) return 0;

    const hasComma = s.includes(',');
    const hasDot   = s.includes('.');

    if (hasComma && hasDot) {
      // Formato tipo 1.234,56 -> . = milhar, , = decimal
      s = s.replace(/\./g,'').replace(',','.');
    } else if (hasComma && !hasDot) {
      // Formato 150,00 -> , decimal
      s = s.replace(',','.');
    }
    // Se só tem ponto, assume decimal normal
    const n = Number(s);
    return isNaN(n) ? 0 : n;
  }

  // Atualiza card da lista após edições
  function updateCardFromField(id, campo, valor){
    const card = document.querySelector(`.os-card[data-id="${id}"]`);
    if (!card) return;
    const right = card.querySelector('.os-right');
    const left  = card.querySelector('.os-left');
    const spans = right ? right.querySelectorAll('.infos span') : [];
    const spanData  = spans[0];
    const spanHora  = spans[1];
    const spanValor = spans[2];
    const spanPago  = spans[3];

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

    if (campo === 'data_entrada' && spanData) {
      const dt = valor ? new Date(valor) : null;
      const str = dt && !isNaN(dt) ? dt.toLocaleDateString('pt-BR') : '-';
      spanData.innerHTML = '<strong>Data:</strong> ' + str;
    }

    if (campo === 'hora_entrada' && spanHora) {
      spanHora.innerHTML = '<strong>Hora:</strong> ' + (valor ? String(valor).slice(0,5) : '-');
    }

    if (campo === 'valor_total' && spanValor) {
      const num = toNum(valor);
      card.dataset.valorTotal = String(num);
      spanValor.innerHTML = '<strong>Valor:</strong> ' + fmtBR(num);
    }

    if (campo === 'pago' && spanPago) {
      const flag = Number(valor) > 0;
      card.dataset.pago = flag ? '1' : '0';
      spanPago.innerHTML = '<strong>Pago:</strong> ' + (flag ? '✅ Sim' : '❌ Não');
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
    if ($('#totalRows')) $('#totalRows').textContent = j.total;
    wireCards();
    wirePager();
    wirePP();
  }
  window.fetchList = fetchList;

  function wirePager(){
    document.querySelectorAll('#paginacao .pg').forEach(btn=>{
      btn.addEventListener('click', ev=>{
        ev.preventDefault();
        const page = btn.dataset.page || 1;
        fetchList(qsParams({p: page}));
      });
    });
  }

  function wirePP(){
    const pp = $('#pp');
    if (!pp) return;
    pp.addEventListener('change', ()=>{
      fetchList(qsParams({pp: pp.value, p: '1'}));
    });
  }

  // Abre/fecha modal de filtros
  const modalFiltros = document.getElementById('modalFiltros');
  const formFiltros  = document.getElementById('filtrosForm');

  $('#btnFiltro').addEventListener('click', ()=> {
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
  $('#btnLimpar').addEventListener('click', ()=>{
    const url = new URL(location.href);
    url.search = '';
    url.searchParams.set('p','1');
    url.searchParams.set('pp', String($('#pp')?.value || 10));
    location.href = url.toString();
  });

  // Aplicar filtros
  $('#btnAplicarFiltros').addEventListener('click', e=>{
    e.preventDefault();
    const fd = new FormData(formFiltros);
    const url = new URL(location.href);
    url.search = '';

    url.searchParams.set('p','1');
    url.searchParams.set('pp', String($('#pp')?.value || 10));

    for (const [k, v] of fd.entries()) {
      if (v === '' || v === null) continue;
      url.searchParams.append(k, v);
    }
    location.href = url.toString();
  });

  /* ========== MODAL DE PAGAMENTO (SIMPLES + MISTO) ========== */
  function openPagamentoModal(opts){
    const {
      id,
      valorInicial = null,
      statusAoConfirmar = null, // 'concluido' ou null
      dadosOS = null,
      onDone = null
    } = opts || {};

    let base = toNum(valorInicial);
    if (!base && dadosOS) {
      base = toNum(dadosOS.valor_total);
    }

    const modal = document.createElement('div');
    modal.className = 'modal-pagamento show';
    modal.innerHTML = `
      <div class="modal-pagamento-content">
        <h2>Registrar Pagamento</h2>
        <p class="desc">Escolha o método ou distribua entre várias formas.</p>

        <div class="grid-opcoes">
          <button class="opcao" data-tipo="dinheiro">💵 Dinheiro</button>
          <button class="opcao" data-tipo="pix">🟢 Pix</button>
          <button class="opcao" data-tipo="credito">💳 Crédito</button>
          <button class="opcao" data-tipo="debito">💳 Débito</button>
          <button class="opcao destaque" data-tipo="misto">🧩 Pagamento misto</button>
        </div>

        <div class="box-info-valor">
          <p class="info-atual"><strong>Valor base:</strong> <span id="pg_valorBase">${fmtBR(base || 0)}</span></p>
          <p class="info-hint">Você pode confirmar o valor base ou editar no fluxo abaixo.</p>
        </div>

        <div class="footer-acoes">
          <button class="cancel-btn">Cancelar</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    if (!document.getElementById('__modalPgCSS_os')) {
      const s = document.createElement('style');
      s.id = '__modalPgCSS_os';
      s.textContent = `
        .modal-pagamento{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);z-index:9999}
        .modal-pagamento-content{background:#232832;border:1px solid #2e343d;border-radius:12px;padding:18px;width:min(520px,92vw);color:#e8edf2;box-shadow:0 10px 30px rgba(0,0,0,.4)}
        .grid-opcoes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:10px}
        .opcao{padding:12px;border-radius:10px;border:1px solid #2e343d;background:#2b3038;color:#e8edf2;cursor:pointer;text-align:center;font-weight:600}
        .opcao:hover{outline:1px solid #4db3ff}
        .opcao.destaque{grid-column:1/-1;border-color:#4db3ff}
        .box-info-valor{margin:6px 0 4px;font-size:13px;opacity:.9}
        .footer-acoes{display:flex;justify-content:flex-end;margin-top:10px}
        .cancel-btn{padding:8px 13px;border-radius:8px;border:1px solid #e05c5c;background:#2b3038;color:#e8edf2;cursor:pointer}
        .linha{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
        .linha input{width:100%;padding:8px;border-radius:8px;border:1px solid #2e343d;background:#1f2329;color:#e8edf2}
        .resumo{margin-top:8px;text-align:center;opacity:.9;font-size:13px}
        .acoes{display:flex;justify-content:space-between;margin-top:12px}
        .confirm-btn,.back-btn{padding:8px 13px;border-radius:8px;border:1px solid #43d17c;background:#2b3038;color:#e8edf2;cursor:pointer}
        .back-btn{border-color:#2e343d}
      `;
      document.head.appendChild(s);
    }

    const close = () => {
      modal.classList.remove('show');
      setTimeout(()=> modal.remove(), 200);
    };

    modal.querySelector('.cancel-btn').onclick = () => {
      close();
      if (typeof onDone === 'function') onDone(null, null, { cancel:true });
    };
    modal.addEventListener('click', e => { if (e.target === modal) { modal.querySelector('.cancel-btn').click(); } });

    async function finalizarSimples(tipo){
      const valor = base > 0 ? base : promptValor();
      if (!valor) return;

      await salvarPagamento({
        id,
        statusAoConfirmar,
        metodo: tipo,
        valor_total: valor
      });
      close();
      if (typeof onDone === 'function') onDone(valor, tipo, { cancel:false });
    }

    function promptValor(){
      const v = prompt('Informe o valor recebido:', base ? String(base.toFixed(2)) : '');
      const n = toNum(v);
      if (!n || n <= 0) {
        alert('Valor inválido.');
        return 0;
      }
      return n;
    }

    async function finalizarMisto(){
      const box = modal.querySelector('.modal-pagamento-content');
      box.innerHTML = `
        <h2>Pagamento misto</h2>
        <p class="desc">Distribua os valores entre as formas abaixo.</p>
        <div class="linha">
          <div>
            <label style="opacity:.85">Pix</label>
            <input id="m_pix" type="number" step="0.01" min="0" placeholder="0.00">
          </div>
          <div>
            <label style="opacity:.85">Dinheiro</label>
            <input id="m_din" type="number" step="0.01" min="0" placeholder="0.00">
          </div>
        </div>
        <div class="linha">
          <div>
            <label style="opacity:.85">Crédito</label>
            <input id="m_cred" type="number" step="0.01" min="0" placeholder="0.00">
          </div>
          <div>
            <label style="opacity:.85">Débito</label>
            <input id="m_deb" type="number" step="0.01" min="0" placeholder="0.00">
          </div>
        </div>
        <div class="resumo" id="mix_resumo"></div>
        <div class="acoes">
          <button class="back-btn">Voltar</button>
          <button class="confirm-btn">Confirmar</button>
        </div>
      `;

      const $pix = box.querySelector('#m_pix');
      const $din = box.querySelector('#m_din');
      const $cred = box.querySelector('#m_cred');
      const $deb = box.querySelector('#m_deb');
      const $res = box.querySelector('#mix_resumo');

      const upd = () => {
        const total = toNum($pix.value)+toNum($din.value)+toNum($cred.value)+toNum($deb.value);
        $res.textContent = 'Total: ' + fmtBR(total);
      };
      [$pix,$din,$cred,$deb].forEach(i=> i.addEventListener('input', upd));
      upd();

      box.querySelector('.back-btn').onclick = () => {
        close();
        openPagamentoModal(opts); // reabre tela inicial
      };

      box.querySelector('.confirm-btn').onclick = async () => {
        const valores = {
          pix:      toNum($pix.value),
          dinheiro: toNum($din.value),
          credito:  toNum($cred.value),
          debito:   toNum($deb.value)
        };
        const total = valores.pix + valores.dinheiro + valores.credito + valores.debito;
        if (!total || total <= 0) {
          alert('Informe algum valor.');
          return;
        }
        await salvarPagamento({
          id,
          statusAoConfirmar,
          metodo: 'misto',
          valor_total: total,
          ...valores
        });
        close();
        if (typeof onDone === 'function') onDone(total, 'misto', { cancel:false });
      };
    }

    async function salvarPagamento(cfg){
      const {
        id,
        statusAoConfirmar,
        metodo,
        valor_total,
        pix = 0,
        dinheiro = 0,
        credito = 0,
        debito = 0
      } = cfg;

      const headers = { 'Content-Type':'application/x-www-form-urlencoded' };
      const vt = Number(valor_total || 0);
      const vtFmt = vt.toFixed(2);

      // Se está concluindo, registra via atualizar_status (log + consistência)
      if (statusAoConfirmar === 'concluido') {
        const payload = new URLSearchParams({
          id,
          status: 'concluido',
          tipo_pagamento: metodo === 'misto' ? 'misto' : (metodo || ''),
          valor_confirmado: vtFmt
        });
        if (metodo === 'misto') {
          payload.set('pix',      pix.toFixed(2));
          payload.set('dinheiro', dinheiro.toFixed(2));
          payload.set('credito',  credito.toFixed(2));
          payload.set('debito',   debito.toFixed(2));
        }
        const r = await fetch('includes/atualizar_status.php', {
          method:'POST', headers, body: payload
        });
        if (!r.ok) {
          const msg = await r.text();
          alert(msg || 'Erro ao atualizar status.');
          throw new Error(msg || 'Erro status');
        }
      }

      // Atualiza valor_total (sempre)
      await fetch('includes/atualizar_os.php', {
        method:'POST', headers,
        body: new URLSearchParams({ id, campo:'valor_total', valor: vtFmt })
      });

      // Atualiza metodo_pagamento (sempre que informar)
      await fetch('includes/atualizar_os.php', {
        method:'POST', headers,
        body: new URLSearchParams({ id, campo:'metodo_pagamento', valor: metodo || '' })
      });

      // ⚠️ Campo "pago" só deve ser alterado quando status for concluido
      if (statusAoConfirmar === 'concluido') {
        await fetch('includes/atualizar_os.php', {
          method:'POST', headers,
          body: new URLSearchParams({ id, campo:'pago', valor: 1 })
        });
        updateCardFromField(parseInt(id,10), 'pago', 1);
      }

      // Atualiza UI do valor_total
      updateCardFromField(parseInt(id,10), 'valor_total', vt);

      if (statusAoConfirmar === 'concluido') {
        updateCardFromField(parseInt(id,10), 'status', 'concluido');
        const sel = $('#viewStatusSelect');
        if (sel) sel.value = 'concluido';
      }

      const viewValor = $('#viewValorTotal');
      if (viewValor) viewValor.textContent = fmtBR(vt);
    }

    modal.querySelectorAll('.opcao').forEach(btn => {
      btn.addEventListener('click', () => {
        const tipo = btn.dataset.tipo;
        if (tipo === 'misto') {
          finalizarMisto();
        } else {
          finalizarSimples(tipo);
        }
      });
    });

    return modal;
  }
  window.openPagamentoModal = openPagamentoModal;

  // ======== VIEW / EDIÇÃO INLINE ========
  async function loadView(id) {
    try {
      const r = await fetch('includes/get_os.php?id=' + encodeURIComponent(id), {
        cache: 'no-store'
      });
      const j = await r.json();
      if (!j.ok) { alert(j.msg || 'Falha ao carregar O.S.'); return; }
      const d = j.data;

      const safe = v => (v===null||v===undefined||v==='') ? '—' : String(v);

      function fillField(map){
        const el = document.getElementById(map.id);
        if (!el) return;
        let val = d[map.campo];

        if (map.campo === 'valor_total') {
          el.textContent = fmtBR(val || 0);
        } else if (map.campo === 'data_entrada' && val) {
          el.textContent = new Date(val).toLocaleDateString('pt-BR');
        } else if (map.campo === 'hora_entrada' && val) {
          el.textContent = String(val).slice(0,5);
        } else {
          el.textContent = safe(val);
        }

        el.dataset.field = map.campo;
        el.dataset.id    = d.id;
        el.classList.add('editable');
      }

      $('#viewId').textContent = '#' + String(d.id).padStart(5, '0');

      const campos = [
        { id:'viewNome', campo:'nome' },
        { id:'viewCPF', campo:'cpf' },
        { id:'viewTelefone', campo:'telefone' },
        { id:'viewEndereco', campo:'endereco' },
        { id:'viewModelo', campo:'modelo' },
        { id:'viewServico', campo:'servico' },
        { id:'viewObs', campo:'observacao' },
        { id:'viewSenhaPadrao', campo:'senha_padrao' },
        { id:'viewSenhaEscrita', campo:'senha_escrita' },
        { id:'viewValorTotal', campo:'valor_total' },
        { id:'viewData', campo:'data_entrada' },
        { id:'viewHora', campo:'hora_entrada' },
        { id:'viewPdf', campo:'pdf_path' }
      ];
      campos.forEach(fillField);

      const viewSel = $('#viewStatusSelect');
      viewSel.dataset.id = d.id;
      viewSel.value = d.status || 'pendente';

      openModal($('#modalView'));

      // Edição inline (inclui valor_total que dispara modal de pagamento)
      $('#modalView').querySelectorAll('.editable').forEach(el => {
        el.addEventListener('click', function(){
          if (this.querySelector('input, textarea')) return;

          const campo = this.dataset.field;
          const idOS  = this.dataset.id;
          const originalText = this.textContent.trim();
          const originalVal  = originalText === '—' ? '' : originalText;

          let input;
          if (campo === 'observacao') {
            input = document.createElement('textarea');
            input.value = originalText === '—' ? '' : originalText;
          } else if (campo.includes('data')) {
            input = document.createElement('input');
            input.type = 'date';
            input.value = (d[campo] || '').slice(0,10);
          } else if (campo.includes('hora')) {
            input = document.createElement('input');
            input.type = 'time';
            input.value = (d[campo] || '').slice(0,5);
          } else if (campo === 'valor_total') {
            input = document.createElement('input');
            input.type = 'number';
            input.step = '0.01';
            const num = toNum(d.valor_total || originalText);
            input.value = num ? num.toFixed(2) : '';
          } else {
            input = document.createElement('input');
            input.type = 'text';
            input.value = originalText === '—' ? '' : originalText;
          }

          input.className = 'inline-input';
          this.textContent = '';
          this.appendChild(input);
          input.focus();

          const parent = this;

          const salvar = async () => {
            const novoBruto = input.value.trim();

            // sem mudança
            if (novoBruto === (originalVal || '')) {
              parent.textContent = originalVal || '—';
              return;
            }

            // valor_total -> abre modal de pagamento (NÃO mexe em pago se não for conclusão)
            if (campo === 'valor_total') {
              const num = toNum(novoBruto);
              if (!num || num <= 0) {
                parent.textContent = originalVal || '—';
                return;
              }

              openPagamentoModal({
                id: idOS,
                valorInicial: num,
                dadosOS: d,
                // statusAoConfirmar null -> não altera pago, apenas valor/metodo
                onDone: (valorFinal, metodo, extra) => {
                  if (extra && extra.cancel) {
                    parent.textContent = originalVal || '—';
                    return;
                  }
                  parent.textContent = fmtBR(valorFinal);
                  d.valor_total = valorFinal;
                }
              });
              return;
            }

            // demais campos: salva direto
            try {
              const resp = await fetch('includes/atualizar_os.php', {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ id:idOS, campo, valor:novoBruto })
              });
              const res = await resp.json();
              if (!res.ok) throw new Error(res.msg || 'Erro');

              d[campo] = novoBruto;
              if (campo.includes('data') && novoBruto) {
                parent.textContent = new Date(novoBruto).toLocaleDateString('pt-BR');
              } else if (campo.includes('hora') && novoBruto) {
                parent.textContent = String(novoBruto).slice(0,5);
              } else {
                parent.textContent = novoBruto || '—';
              }

              parent.classList.add('flash-success');
              setTimeout(()=> parent.classList.remove('flash-success'), 700);

              updateCardFromField(parseInt(idOS,10), campo, novoBruto);
            } catch (err) {
              console.error(err);
              parent.textContent = originalVal || '—';
              parent.classList.add('flash-error');
              setTimeout(()=> parent.classList.remove('flash-error'), 700);
            }
          };

          input.addEventListener('blur', salvar);
          input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            else if (e.key === 'Escape') { parent.textContent = originalVal || '—'; }
          });
        });
      });

      // Status -> se "concluido" abre modal de pagamento
      viewSel.onchange = function(){
        const idSel = this.dataset.id;
        const status = this.value;

        if (status === 'concluido') {
          openPagamentoModal({
            id: idSel,
            valorInicial: d.valor_total,
            statusAoConfirmar: 'concluido',
            dadosOS: d,
            onDone: (valor, metodo, extra) => {
              if (extra && extra.cancel) {
                this.value = d.status || 'pendente';
                return;
              }
              d.status = 'concluido';
              d.valor_total = valor;
            }
          });
          return;
        }

        // Outros status: só atualiza status, NÃO mexe em "pago"
        fetch('includes/atualizar_status.php', {
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({ id:idSel, status })
        })
        .then(async resp => {
          if (!resp.ok) {
            const msg = await resp.text();
            alert(msg || 'Erro ao atualizar status.');
            this.value = d.status || 'pendente';
            return;
          }
          d.status = status;
          updateCardFromField(parseInt(idSel,10), 'status', status);
        })
        .catch(err => {
          console.error(err);
          alert('Erro ao atualizar status.');
          this.value = d.status || 'pendente';
        });
      };

    } catch (err) {
      console.error(err);
      alert('Erro ao carregar detalhes da O.S.');
    }
  }
  window.loadView = loadView;
  async function gerarEImprimirOS(id){
    try {
      // Gera e salva/atualiza o PDF
      await fetch(`includes/gerar_pdf_os.php?id=${id}&save=1`, {
        cache: 'no-store'
      });

      // Abre direto para imprimir
      const url = `includes/gerar_pdf_os.php?id=${id}&print=1&_=${Date.now()}`;
      window.open(url, '_blank');
    } catch (e) {
      console.error('Erro ao gerar/imprimir OS', e);
      alert('Não foi possível gerar/imprimir a O.S.');
    }
  }

  // ======== EXCLUIR ========
  async function deleteOS(id) {
    const card = document.querySelector(`.os-card[data-id="${id}"]`);
    if (!confirm('Tem certeza que deseja excluir a O.S. #' + String(id).padStart(5,'0') + ' ?')) return;

    try {
      const r = await fetch('includes/deletar_os.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id })
      });
      const j = await r.json();
      if (!j.ok) { alert(j.msg || 'Erro ao excluir.'); return; }

      if (card) {
        card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.97)';
        setTimeout(()=> card.remove(), 300);
      }

      setTimeout(()=> fetchList(qsParams()), 400);
    } catch (err) {
      console.error(err);
      alert('Erro ao excluir O.S.');
    }
  }

  // ======== Modais genéricos ========
  function openModal(modal){
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden','false');
  }
  function closeModal(modal){
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden','true');
  }

  $$('.modal [data-close]').forEach(b=>{
    b.addEventListener('click', e=>{
      closeModal(e.target.closest('.modal'));
    });
  });
  $$('.modal').forEach(m=>{
    m.addEventListener('click', e=>{
      if (e.target === m) closeModal(m);
    });
  });

  function wireCards(){
    // Ver
    $$('.os-card .btn.ver').forEach(b => {
      b.addEventListener('click', () => {
        const id = b.closest('.os-card').dataset.id;
        loadView(id);
      });
    });

    // Imprimir
    $$('.os-card .btn.print').forEach(b => {
      b.addEventListener('click', () => {
        const id = b.closest('.os-card').dataset.id;
        gerarEImprimirOS(id);
      });
    });

    // Excluir
    $$('.os-card .btn.excluir').forEach(b => {
      b.addEventListener('click', () => {
        const id = b.closest('.os-card').dataset.id;
        deleteOS(id);
      });
    });
  }


  // Inicial
  wireCards();
  wirePager();
  wirePP();
})();
</script>
