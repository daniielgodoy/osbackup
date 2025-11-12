<?php
/* ===========================================================================
 * relatorios.php — Dashboard de relatórios (multi-tenant)
 * ======================================================================== */

require_once __DIR__ . '/includes/auth_guard.php';
$role = $_SESSION['role'] ?? 'member';
if ($role !== 'admin') {
    http_response_code(403);
    exit('Acesso restrito ao administrador.');
}

$tenant_id = require_tenant();
$shop_id   = current_shop_id(); // pode ser null

include_once __DIR__ . '/includes/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* Datas padrão: últimos 7 dias */
$hoje       = date('Y-m-d');
$iniDefault = date('Y-m-d', strtotime('-6 days', strtotime($hoje)));

/* ===== Lista de funcionários (para filtro) ===== */
$funcionarios = [];
try {
    $sqlFunc = "SELECT id, nome FROM login WHERE tenant_id = ? AND (ativo = 1 OR ativo IS NULL)";
    $types = 'i';
    $params = [$tenant_id];

    $hasShopCol = false;
    $resCols = $conn->query("SHOW COLUMNS FROM login LIKE 'shop_id'");
    if ($resCols && $resCols->num_rows > 0) $hasShopCol = true;

    if (!empty($shop_id) && $hasShopCol) {
        $sqlFunc .= " AND (shop_id = ? OR shop_id IS NULL OR shop_id = 0)";
        $types   .= 'i';
        $params[] = $shop_id;
    }

    $sqlFunc .= " ORDER BY nome ASC";
    $stmtF = $conn->prepare($sqlFunc);
    if (!empty($params)) $stmtF->bind_param($types, ...$params);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    while ($row = $resF->fetch_assoc()) $funcionarios[] = $row;
    $stmtF->close();
} catch (Throwable $e) {
    $funcionarios = [];
}
?>
<?php include_once __DIR__ . '/includes/header.php'; ?>
<body>
<?php
  $pagina = 'relatorios';
  include_once __DIR__ . '/includes/navbar.php';
?>
<link rel="stylesheet" href="css/relatorios.css">
<link rel="stylesheet" href="css/theme.css"><!-- theme por último -->

<div class="relatorios-page">
<header class="relatorios-header">
  <h1>Relatórios</h1>

  <!-- TOOLBAR PRINCIPAL -->
  <div class="filters-toolbar">
    <div class="left">
      <div class="presets">
        <button class="btn preset" data-preset="7d">7 dias</button>
        <button class="btn preset" data-preset="30d">30 dias</button>
        <button class="btn preset" data-preset="mtd">Mês atual</button>
        <button class="btn preset" data-preset="ytd">Ano atual</button>
      </div>

      <div class="date-pickers">
        <div class="input">
          <i class="fa-regular fa-calendar"></i>
          <input type="date" id="dtIni" value="<?= htmlspecialchars($iniDefault) ?>" />
        </div>
        <span class="sep">—</span>
        <div class="input">
          <i class="fa-regular fa-calendar"></i>
          <input type="date" id="dtFim" value="<?= htmlspecialchars($hoje) ?>" />
        </div>
      </div>
    </div>

    <div class="right">
      <button id="btnAplicar" class="btn primary">
        <i class="fa-solid fa-check"></i><span>Aplicar</span>
      </button>

      <div class="split"></div>

      <button class="btn ghost" data-dl-canvas="lineFaturamento" title="Baixar PNG Faturamento">
        <i class="fa-solid fa-image"></i><span>PNG</span>
      </button>
      <button class="btn ghost" id="btnCSV" title="Exportar CSV (Resumo diário)">
        <i class="fa-solid fa-file-csv"></i><span>CSV</span>
      </button>

      <button id="btnToggleAvancados" class="btn outline">
        <i class="fa-solid fa-sliders"></i><span>Filtros avançados</span>
        <i class="fa-solid fa-angle-down caret"></i>
      </button>
    </div>
  </div>

  <!-- FILTROS AVANÇADOS -->
  <div id="filtrosAvancados" class="filters-advanced collapsed">
    <div class="grid">
      <div class="field">
        <label>Status</label>
        <div class="chip-group" id="chipStatus">
          <?php
            $sts = ['pendente','orcamento','em_andamento','aguardando_retirada','concluido'];
            foreach($sts as $s){
              echo '<button class="chip" data-value="'.$s.'">'.ucwords(str_replace('_',' ',$s)).'</button>';
            }
          ?>
          <button class="chip chip-clear" id="chipStatusClear" title="Limpar">Limpar</button>
        </div>
      </div>

      <div class="field">
        <label>Métodos</label>
        <div class="checks">
          <label><input type="checkbox" name="mtd" value="dinheiro" checked> Dinheiro</label>
          <label><input type="checkbox" name="mtd" value="pix" checked> Pix</label>
          <label><input type="checkbox" name="mtd" value="credito" checked> Crédito</label>
          <label><input type="checkbox" name="mtd" value="debito" checked> Débito</label>
        </div>
      </div>

      <div class="field">
        <label>Pago</label>
        <select id="selPago">
          <option value="">Todos</option>
          <option value="1">Somente pagos</option>
          <option value="0">Não pagos</option>
        </select>
      </div>

      <div class="field">
        <label>Agrupar por</label>
        <select id="selGroup">
          <option value="dia">Dia</option>
          <option value="semana">Semana</option>
          <option value="mes">Mês</option>
        </select>
        <label class="inline" style="margin-top:8px">
          <input type="checkbox" id="cmpPrev"> Comparar com período anterior
        </label>
      </div>

      <div class="field">
        <label>Buscar por serviço</label>
        <input type="text" id="qServico" placeholder="(contém)">
      </div>

      <div class="field">
        <label>Buscar por nome</label>
        <input type="text" id="qNome" placeholder="(contém)">
      </div>

      <div class="field two">
        <label>Faixa de valores</label>
        <div class="row">
          <input type="number" id="vMin" placeholder="R$ mín." step="0.01">
          <span class="sep">—</span>
          <input type="number" id="vMax" placeholder="R$ máx." step="0.01">
        </div>
      </div>

      <!-- Filtro Funcionários (chips no canto, alinhado com o título via CSS da página) -->
      <?php if (!empty($funcionarios)): ?>
      <div class="field two">
        <label>Funcionários</label>
        <div class="chip-group" id="chipFuncs">
          <?php foreach ($funcionarios as $f): ?>
            <button class="chip" data-id="<?= (int)$f['id'] ?>">
              <i class="fa-solid fa-user"></i>
              <span><?= htmlspecialchars($f['nome'] ?: 'Sem nome', ENT_QUOTES, 'UTF-8') ?></span>
            </button>
          <?php endforeach; ?>
          <button class="chip chip-clear" id="chipFuncsClear" title="Limpar">Limpar</button>
        </div>
        <small class="hint">Filtra e gera estatísticas por responsável (quando informado nas O.S.).</small>
      </div>
      <?php endif; ?>
    </div>
  </div>
</header>

  <!-- TILES -->
  <section class="report-tiles" id="reportTiles">
    <button class="tile active" data-view="geral">
      <i class="fa-solid fa-gauge"></i><span>Visão Geral</span>
    </button>
    <button class="tile" data-view="faturamento">
      <i class="fa-solid fa-chart-line"></i><span>Faturamento</span>
    </button>
    <button class="tile" data-view="meios">
      <i class="fa-solid fa-chart-pie"></i><span>Meios de pagamento</span>
    </button>
    <button class="tile" data-view="topserv">
      <i class="fa-solid fa-ranking-star"></i><span>Top serviços</span>
    </button>
    <button class="tile" data-view="status">
      <i class="fa-solid fa-clipboard-list"></i><span>OS por status</span>
    </button>
    <button class="tile" data-view="funcionarios">
      <i class="fa-solid fa-users"></i><span>Funcionários</span>
    </button>
  </section>

  <!-- VIEW: VISÃO GERAL -->
  <div class="view" data-view="geral">
    <section class="kpis">
      <div class="kpi-card"><span class="kpi-label">OS criadas</span><span id="kpiCriadas" class="kpi-value">0</span></div>
      <div class="kpi-card"><span class="kpi-label">OS concluídas</span><span id="kpiConcluidas" class="kpi-value">0</span></div>
      <div class="kpi-card"><span class="kpi-label">Faturamento (período)</span><span id="kpiFaturamento" class="kpi-value">R$ 0,00</span></div>
      <div class="kpi-card"><span class="kpi-label">Ticket médio</span><span id="kpiTicket" class="kpi-value">R$ 0,00</span></div>
    </section>

    <section class="charts">
      <div class="chart-box"><h3>Evolução diária • Faturamento</h3><canvas id="lineFaturamento"></canvas></div>
      <div class="chart-box"><h3>Meios de pagamento (somatório)</h3><canvas id="pieMeios"></canvas></div>
    </section>

    <section class="tables two-col">
      <div class="tbl-wrap">
        <div class="tbl-head"><h3>Top serviços (por quantidade)</h3></div>
        <table class="tbl">
          <thead><tr><th>Serviço</th><th>Quantidade</th><th>Faturado</th></tr></thead>
          <tbody id="tbTopServicos"><tr><td colspan="3" class="vazio">Carregando…</td></tr></tbody>
        </table>
      </div>

      <div class="tbl-wrap">
        <div class="tbl-head"><h3>Top clientes (por faturamento)</h3></div>
        <table class="tbl">
          <thead><tr><th>Cliente</th><th>OS</th><th>Faturado</th></tr></thead>
          <tbody id="tbTopClientes"><tr><td colspan="3" class="vazio">Carregando…</td></tr></tbody>
        </table>
      </div>
    </section>

    <section class="tables">
      <div class="tbl-wrap">
        <div class="tbl-head"><h3>Resumo diário</h3></div>
        <table class="tbl">
          <thead>
            <tr>
              <th>Período</th><th>Criadas</th><th>Concluídas</th><th>Dinheiro</th><th>Pix</th><th>Crédito</th><th>Débito</th><th>Faturado</th><th>Ticket</th><th>Tempo (h)</th>
            </tr>
          </thead>
          <tbody id="tbResumoDiario"><tr><td colspan="10" class="vazio">Carregando…</td></tr></tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- VIEW: FATURAMENTO -->
  <div class="view hidden" data-view="faturamento">
    <section class="charts">
      <div class="chart-box" style="grid-column:1/-1; min-height:380px">
        <h3>Evolução diária • Faturamento (comparativo opcional)</h3>
        <canvas id="lineFaturamento_only"></canvas>
      </div>
      <div class="chart-box" style="grid-column:1/-1; min-height:380px">
        <h3>Faturamento por método (empilhado)</h3>
        <canvas id="stackedMetodo"></canvas>
      </div>
    </section>
  </div>

  <!-- VIEW: MEIOS -->
  <div class="view hidden" data-view="meios">
    <section class="charts">
      <div class="chart-box" style="grid-column:1/-1; min-height:380px">
        <h3>Meios de pagamento (somatório)</h3>
        <canvas id="pieMeios_only"></canvas>
      </div>
    </section>
  </div>

  <!-- VIEW: TOP SERVIÇOS -->
  <div class="view hidden" data-view="topserv">
    <section class="tables">
      <div class="tbl-wrap">
        <div class="tbl-head"><h3>Top serviços (por quantidade)</h3></div>
        <table class="tbl">
          <thead><tr><th>Serviço</th><th>Quantidade</th><th>Faturado</th></tr></thead>
          <tbody id="tbTopServicos_only"><tr><td colspan="3" class="vazio">Carregando…</td></tr></tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- VIEW: STATUS -->
  <div class="view hidden" data-view="status">
    <section class="charts">
      <div class="chart-box" style="grid-column:1/-1; min-height:360px">
        <h3>OS por status (no período)</h3>
        <canvas id="barStatus"></canvas>
      </div>
    </section>
  </div>

  <!-- VIEW: FUNCIONÁRIOS (corrigida) -->
  <div class="view hidden" data-view="funcionarios">
    <section class="kpis kpis-func">
      <div class="kpi-card">
        <span class="kpi-label">Funcionários ativos no período</span>
        <span id="kpiFuncQtd" class="kpi-value">0</span>
      </div>
      <div class="kpi-card">
        <span class="kpi-label">Mais OS (responsável)</span>
        <span id="kpiFuncMaisOS" class="kpi-value-mini">—</span>
      </div>
      <div class="kpi-card">
        <span class="kpi-label">Maior faturamento</span>
        <span id="kpiFuncMaisFat" class="kpi-value-mini">—</span>
      </div>
      <div class="kpi-card">
        <span class="kpi-label">Melhor ticket médio</span>
        <span id="kpiFuncMelhorTicket" class="kpi-value-mini">—</span>
      </div>
    </section>

    <section class="tables two-col">
      <div class="tbl-wrap">
        <div class="tbl-head"><h3>Mais OS por funcionário</h3></div>
        <table class="tbl">
          <thead><tr><th>Funcionário</th><th>OS</th><th>Faturado</th><th>Ticket médio</th></tr></thead>
          <tbody id="tbFuncMaisOS"><tr><td colspan="4" class="vazio">Sem dados.</td></tr></tbody>
        </table>
      </div>

      <div class="tbl-wrap">
        <div class="tbl-head"><h3>Ticket médio por funcionário</h3></div>
        <table class="tbl">
          <thead><tr><th>Funcionário</th><th>OS</th><th>Faturado</th><th>Ticket médio</th></tr></thead>
          <tbody id="tbFuncTicket"><tr><td colspan="4" class="vazio">Sem dados.</td></tr></tbody>
        </table>
      </div>
    </section>

    <section class="charts">
      <div class="chart-box" style="grid-column:1/-1; min-height:360px">
        <h3>OS por status (por funcionário)</h3>
        <canvas id="barFuncStatus"></canvas>
      </div>
    </section>
  </div>

</div>

<script>
/* ====== Tiles ====== */
(function tiles(){
  const cont = document.getElementById('reportTiles');
  function setView(name){
    document.querySelectorAll('.view').forEach(v=>{
      if (v.dataset.view === name) v.classList.remove('hidden'); else v.classList.add('hidden');
    });
    document.querySelectorAll('.tile').forEach(t=>{
      if (t.dataset.view === name) t.classList.add('active'); else t.classList.remove('active');
    });
  }
  cont?.addEventListener('click', (e)=>{
    const btn = e.target.closest('.tile'); if (!btn) return;
    setView(btn.dataset.view);
    drawAll();
  });
})();

/* ====== Chart.js ====== */
function loadScript(src){return new Promise(r=>{const s=document.createElement('script');s.src=src;s.onload=r;document.head.appendChild(s);});}
async function ensureChart(){ if(!window.Chart){ await loadScript('https://cdn.jsdelivr.net/npm/chart.js'); } }

/* ====== Helpers ====== */
const fmtBR = (n)=> Number(n||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
const byId  = (s)=> document.getElementById(s);
function cssVar(name){
  const page = document.querySelector('.relatorios-page');
  const fromPage = page ? getComputedStyle(page).getPropertyValue(name).trim() : '';
  if (fromPage) return fromPage;
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || name;
}

/* ====== Chart refs ====== */
const CH = { line:null, pie:null, lineOnly:null, pieOnly:null, barStatus:null, stacked:null, barFuncStatus:null };
function destroyIf(c){ try{ c && c.destroy && c.destroy(); }catch(_){ } }

/* ====== Draw base ====== */
function drawKPIs(d){
  byId('kpiCriadas').textContent     = d?.kpis?.os_criadas ?? 0;
  byId('kpiConcluidas').textContent  = d?.kpis?.os_concluidas ?? 0;
  byId('kpiFaturamento').textContent = fmtBR(d?.kpis?.faturamento ?? 0);
  byId('kpiTicket').textContent      = fmtBR(d?.kpis?.ticket_medio ?? 0);
}

/* ==== FUNC: KPIs de funcionários (usando func_mais_os + func_ticket) ==== */
function drawFuncKPIs(d){
  const maisOSArr   = Array.isArray(d?.func_mais_os) ? d.func_mais_os : [];
  const ticketArr   = Array.isArray(d?.func_ticket)  ? d.func_ticket  : [];
  const idsAtivos   = new Set([
    ...maisOSArr.map(x=>x.id).filter(Boolean),
    ...ticketArr.map(x=>x.id).filter(Boolean)
  ]);
  byId('kpiFuncQtd').textContent = idsAtivos.size || 0;

  // Computa melhores
  const maisOS = maisOSArr.slice().sort((a,b)=>(b.os||0)-(a.os||0))[0] || null;
  const maisFat = ticketArr.slice().sort((a,b)=>(b.faturado||0)-(a.faturado||0))[0] || null;
  const melhorTicket = ticketArr.slice().sort((a,b)=>(b.ticket||0)-(a.ticket||0))[0] || null;

  byId('kpiFuncMaisOS').textContent =
    maisOS ? ((maisOS.nome||'—') + ' (' + (maisOS.os||0) + ' OS)') : '—';
  byId('kpiFuncMaisFat').textContent =
    maisFat ? ((maisFat.nome||'—') + ' (' + fmtBR(maisFat.faturado||0) + ')') : '—';
  byId('kpiFuncMelhorTicket').textContent =
    melhorTicket ? ((melhorTicket.nome||'—') + ' (' + fmtBR(melhorTicket.ticket||0) + ')') : '—';
}

/* ==== linhas, pizza, barras ==== */
function drawLine(id, labels, values){
  const el = byId(id); if (!el) return null;
  return new Chart(el.getContext('2d'), {
    type:'line',
    data:{ labels, datasets:[{ label:'Faturamento', data: values, tension:.25, fill:false }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:(c)=> fmtBR(c.parsed.y) } } }, interaction:{ mode:'index', intersect:false }, scales:{ y:{ ticks:{ callback:(v)=> fmtBR(v) } } } }
  });
}
function drawPie(id, labels, values){
  const el = byId(id); if (!el) return null;
  const colors = [cssVar('--donut-dinheiro'),cssVar('--donut-pix'),cssVar('--donut-credito'),cssVar('--donut-debito')];
  return new Chart(el.getContext('2d'), {
    type:'doughnut',
    data:{ labels, datasets:[{ data: values, backgroundColor: colors, borderWidth:0 }] },
    options:{ cutout:'68%', responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' }, tooltip:{ callbacks:{ label:(c)=> `${c.label}: ${fmtBR(c.parsed)}` } } } }
  });
}
function drawBar(id, labels, values){
  const el = byId(id); if (!el) return null;
  return new Chart(el.getContext('2d'), {
    type:'bar',
    data:{ labels, datasets:[{ label:'OS', data: values }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, precision:0 } } }
  });
}

/* ==== barras empilhadas por funcionário / status (transformando o formato do endpoint) ==== */
function drawBarFuncStatus(canvasId, d){
  const el = byId(canvasId); if (!el) return null;
  const labelsStatus = Array.isArray(d?.func_status?.labels) ? d.func_status.labels : [];
  const seriesFunc   = Array.isArray(d?.func_status?.series) ? d.func_status.series : [];
  if (!labelsStatus.length || !seriesFunc.length) return null;

  // Labels do eixo X = nomes dos funcionários (na ordem recebida)
  const labelsFuncs = seriesFunc.map(s => s.nome || ('#'+(s.id||'')));

  // Construir datasets por STATUS (um dataset por status, dados por funcionário)
  const datasets = labelsStatus.map((statusLabel, sIdx) => ({
    label: statusLabel.replace('_',' '),
    data: seriesFunc.map(funcSerie => Number(funcSerie.data?.[sIdx] || 0)),
    stack: 'st'
  }));

  return new Chart(el.getContext('2d'), {
    type:'bar',
    data:{ labels: labelsFuncs, datasets },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ position:'bottom' } },
      scales:{ x:{ stacked:true }, y:{ stacked:true, beginAtZero:true, ticks:{ precision:0 } } }
    }
  });
}

function drawStacked(canvasId, labels, series){
  const el = byId(canvasId); if (!el) return null;
  return new Chart(el.getContext('2d'), {
    type:'bar',
    data:{
      labels,
      datasets:[
        {label:'Dinheiro', data:(series.dinheiro||[]).map(Number), stack:'m'},
        {label:'Pix',      data:(series.pix||[]).map(Number),      stack:'m'},
        {label:'Crédito',  data:(series.credito||[]).map(Number),  stack:'m'},
        {label:'Débito',   data:(series.debito||[]).map(Number),   stack:'m'},
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ position:'bottom' } },
      scales:{ x:{ stacked:true }, y:{ stacked:true, beginAtZero:true, ticks:{ callback:(v)=> fmtBR(v) } } }
    }
  });
}
function drawLineComparativo(id, baseLabels, baseValues, prevValues){
  const el = byId(id); if(!el) return null;
  const ds = [{ label:'Período', data: baseValues, tension:.25, fill:false }];
  if (Array.isArray(prevValues) && prevValues.length === baseLabels.length)
    ds.push({ label:'Anterior', data: prevValues, tension:.25, fill:false, borderDash:[6,4] });
  return new Chart(el.getContext('2d'), {
    type:'line',
    data:{ labels: baseLabels, datasets: ds },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' }, tooltip:{ callbacks:{ label:(c)=> fmtBR(c.parsed.y) } } }, interaction:{ mode:'index', intersect:false }, scales:{ y:{ ticks:{ callback:(v)=> fmtBR(v) } } } }
  });
}

/* ====== Tabelas gerais ====== */
function drawTables(d){
  const rows = (d?.top_servicos || []);
  const make = (arr)=> arr.length
    ? arr.map(r=>`<tr><td>${r.servico ?? '—'}</td><td>${r.qtd ?? 0}</td><td>${fmtBR(r.faturado ?? 0)}</td></tr>`).join('')
    : `<tr><td colspan="3" class="vazio">Sem dados no período.</td></tr>`;
  const html = make(rows);
  const el1 = byId('tbTopServicos');       if (el1) el1.innerHTML = html;
  const el2 = byId('tbTopServicos_only');  if (el2) el2.innerHTML = html;
}
function fillTopClientes(rows){
  const tbody = byId('tbTopClientes'); if (!tbody) return;
  if(!rows?.length){ tbody.innerHTML = '<tr><td colspan="3" class="vazio">Sem dados.</td></tr>'; return; }
  tbody.innerHTML = rows.map(r=>(`
    <tr><td>${r.nome ?? '—'}</td><td>${r.os ?? 0}</td><td>${fmtBR(r.faturado ?? 0)}</td></tr>
  `)).join('');
}
function fillResumoDiario(rows){
  const tbody = byId('tbResumoDiario'); if (!tbody) return;
  if(!rows?.length){ tbody.innerHTML = '<tr><td colspan="10" class="vazio">Sem dados.</td></tr>'; return; }
  tbody.innerHTML = rows.map(r=>(`
    <tr>
      <td>${r.periodo}</td><td>${r.criadas}</td><td>${r.concluidas}</td>
      <td>${fmtBR(r.dinheiro)}</td><td>${fmtBR(r.pix)}</td>
      <td>${fmtBR(r.credito)}</td><td>${fmtBR(r.debito)}</td>
      <td>${fmtBR(r.faturado)}</td><td>${fmtBR(r.ticket)}</td><td>${(Number(r.tempo_h)||0).toFixed(1)}</td>
    </tr>`)).join('');
}

/* ==== Tabelas de Funcionários (corrigidas) ==== */
function fillFuncTables(d){
  const maisOSArr = Array.isArray(d?.func_mais_os) ? d.func_mais_os : [];
  const ticketArr = Array.isArray(d?.func_ticket)  ? d.func_ticket  : [];

  // map por id para merge
  const mapTicket = new Map(ticketArr.map(r=>[String(r.id), r]));

  // Monta linhas “Mais OS” juntando faturado/ticket quando houver
  const rowsMais = maisOSArr.map(r=>{
    const t = mapTicket.get(String(r.id)) || {};
    return {
      nome: r.nome || t.nome || '—',
      os:   r.os   ?? t.concluidas ?? 0,
      faturado: Number(t.faturado || 0),
      ticket:   Number(t.ticket   || 0),
    };
  });

  // Se algum funcionário só aparece no ticketArr (e não no maisOS), inclui também
  ticketArr.forEach(t=>{
    const key = String(t.id);
    if (!maisOSArr.find(m=>String(m.id)===key)) {
      rowsMais.push({
        nome: t.nome || '—',
        os:   Number(t.concluidas || 0),
        faturado: Number(t.faturado || 0),
        ticket:   Number(t.ticket   || 0),
      });
    }
  });

  // Preenche as duas tabelas
  const tbMais = byId('tbFuncMaisOS');
  const tbTic  = byId('tbFuncTicket');

  if (!rowsMais.length){
    if (tbMais) tbMais.innerHTML = '<tr><td colspan="4" class="vazio">Sem dados.</td></tr>';
    if (tbTic)  tbTic.innerHTML  = '<tr><td colspan="4" class="vazio">Sem dados.</td></tr>';
    return;
  }

  // “Mais OS por funcionário” (ordena por OS desc)
  const ordemMais = rowsMais.slice().sort((a,b)=>(b.os||0)-(a.os||0));
  if (tbMais) {
    tbMais.innerHTML = ordemMais.map(r=>`
      <tr>
        <td>${r.nome}</td><td>${r.os}</td><td>${fmtBR(r.faturado)}</td><td>${fmtBR(r.ticket)}</td>
      </tr>`).join('');
  }

  // “Ticket médio por funcionário” (ordena por ticket desc)
  const ordemTicket = rowsMais.slice().sort((a,b)=>(b.ticket||0)-(a.ticket||0));
  if (tbTic) {
    tbTic.innerHTML = ordemTicket.map(r=>`
      <tr>
        <td>${r.nome}</td><td>${r.os}</td><td>${fmtBR(r.faturado)}</td><td>${fmtBR(r.ticket)}</td>
      </tr>`).join('');
  }
}

/* ====== Coleta filtros + fetch ====== */
const selStatus = new Set();
const selFuncs  = new Set();

function getSelectedMethods(){
  return Array.from(document.querySelectorAll('input[name="mtd"]:checked')).map(i=>i.value);
}

async function fetchData(){
  const p = new URLSearchParams();
  const ini = byId('dtIni').value || '';
  const fim = byId('dtFim').value || '';
  if (ini) p.set('ini', ini);
  if (fim) p.set('fim', fim);

  if (selStatus.size) p.set('status', Array.from(selStatus).join(','));
  const m = getSelectedMethods(); if (m.length) p.set('metodos', m.join(','));
  const pago = byId('selPago').value; if (pago !== '') p.set('pago', pago);
  const grp  = byId('selGroup').value || 'dia'; p.set('group', grp);
  if (byId('cmpPrev').checked) p.set('cmp', '1');

  const qServ = byId('qServico').value.trim(); if (qServ) p.set('q_servico', qServ);
  const qNome = byId('qNome').value.trim();     if (qNome) p.set('q_nome', qNome);
  const vMin  = byId('vMin').value;             if (vMin)  p.set('vmin', vMin);
  const vMax  = byId('vMax').value;             if (vMax)  p.set('vmax', vMax);

  // 🔶 CORRIGIDO: parâmetro correto para o backend é "resp"
  if (selFuncs.size) p.set('resp', Array.from(selFuncs).join(','));

  const url = new URL('includes/relatorios_data.php', window.location);
  url.search = p.toString();

  const r = await fetch(url, {cache:'no-store', headers:{'X-Requested-With':'fetch'}});
  if (!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}

/* ====== Ciclo principal ====== */
async function drawAll(){
  await ensureChart();
  Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#e0e0e0';

  try{
    const data = await fetchData();

    // KPIs gerais
    drawKPIs(data);

    // LINE principal
    destroyIf(CH.line);
    const l = (data?.faturamento_diario || []);
    const labelsBase = l.map(x=>x.periodo);
    const valsBase   = l.map(x=>Number(x.total || 0));
    CH.line = drawLine('lineFaturamento', labelsBase, valsBase);

    // LINE comparativo
    destroyIf(CH.lineOnly);
    const prevVals = (data?.comparativo?.anterior || []).map(x=>Number(x||0));
    CH.lineOnly = drawLineComparativo('lineFaturamento_only', labelsBase, valsBase, prevVals.length ? prevVals : null);

    // STACKED método
    destroyIf(CH.stacked);
    const st = data?.faturamento_por_metodo || {labels:[], dinheiro:[], pix:[], credito:[], debito:[]};
    CH.stacked = drawStacked('stackedMetodo', st.labels || [], {
      dinheiro:(st.dinheiro||[]),
      pix:(st.pix||[]),
      credito:(st.credito||[]),
      debito:(st.debito||[])
    });

    // PIE meios
    destroyIf(CH.pie); destroyIf(CH.pieOnly);
    const m = data?.meios || { dinheiro:0, pix:0, credito:0, debito:0 };
    CH.pie     = drawPie('pieMeios',      ['Dinheiro','Pix','Crédito','Débito'], [m.dinheiro||0, m.pix||0, m.credito||0, m.debito||0]);
    CH.pieOnly = drawPie('pieMeios_only', ['Dinheiro','Pix','Crédito','Débito'], [m.dinheiro||0, m.pix||0, m.credito||0, m.debito||0]);

    // BAR status (criadas)
    destroyIf(CH.barStatus);
    const sc = data?.status_counts || [];
    CH.barStatus = drawBar('barStatus', sc.map(x=>x.status), sc.map(x=>Number(x.qtd||0)));

    // Tabelas gerais
    drawTables(data);
    fillTopClientes(data?.top_clientes);
    fillResumoDiario(data?.resumo_diario);

    // FUNCIONÁRIOS (corrigido)
    drawFuncKPIs(data);
    fillFuncTables(data);

    destroyIf(CH.barFuncStatus);
    CH.barFuncStatus = drawBarFuncStatus('barFuncStatus', data);

  } catch(e){
    console.error('Erro ao desenhar relatórios:', e);
  }
}

/* ====== Interações ====== */
// Chips de status
document.getElementById('chipStatus')?.addEventListener('click', (e)=>{
  const btn = e.target.closest('.chip'); if(!btn) return;
  if(btn.id === 'chipStatusClear'){
    selStatus.clear();
    document.querySelectorAll('#chipStatus .chip').forEach(c=>c.classList.remove('on'));
    return;
  }
  const v = btn.dataset.value; if(!v) return;
  if(selStatus.has(v)){ selStatus.delete(v); btn.classList.remove('on'); }
  else { selStatus.add(v); btn.classList.add('on'); }
});

// Chips de funcionários (param resp=)
document.getElementById('chipFuncs')?.addEventListener('click', (e)=>{
  const btn = e.target.closest('.chip'); if(!btn) return;
  if(btn.id === 'chipFuncsClear'){
    selFuncs.clear();
    document.querySelectorAll('#chipFuncs .chip').forEach(c=>c.classList.remove('on'));
    return;
  }
  const id = btn.dataset.id;
  if(!id) return;
  if(selFuncs.has(id)){ selFuncs.delete(id); btn.classList.remove('on'); }
  else { selFuncs.add(id); btn.classList.add('on'); }
});

// Presets de data
document.querySelectorAll('[data-preset]')?.forEach(b=>{
  b.addEventListener('click', ()=>{
    const today = new Date();
    const pad = (n)=> String(n).padStart(2,'0');
    const y=today.getFullYear(), m=today.getMonth(), d=today.getDate();
    let ini='', fim=`${y}-${pad(m+1)}-${pad(d)}`;
    const t = b.dataset.preset;
    if(t==='7d'){ const dt=new Date(today); dt.setDate(d-6);  ini=`${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}`; }
    if(t==='30d'){const dt=new Date(today); dt.setDate(d-29); ini=`${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}`; }
    if(t==='mtd'){ ini=`${y}-${pad(m+1)}-01`; }
    if(t==='ytd'){ ini=`${y}-01-01`; }
    byId('dtIni').value = ini; byId('dtFim').value = fim;
  });
});

// Baixar PNG
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-dl-canvas]'); if(!btn) return;
  const id = btn.dataset.dlCanvas; const cv = byId(id); if(!cv) return;
  const a = document.createElement('a'); a.href = cv.toDataURL('image/png'); a.download = id + '.png'; a.click();
});

// Export CSV (Resumo diário)
document.getElementById('btnCSV')?.addEventListener('click', ()=>{
  try{
    const tbody = byId('tbResumoDiario'); if(!tbody) return;
    const header = ['Periodo','Criadas','Concluidas','Dinheiro','Pix','Credito','Debito','Faturado','Ticket','Tempo_h'];
    const rows = [header];
    tbody.querySelectorAll('tr').forEach(tr=>{
      const tds = Array.from(tr.querySelectorAll('td'));
      if (tds.length===10){
        rows.push(tds.map(td=> td.innerText.replace(/\u00A0/g,' ').trim()));
      }
    });
    const csv = rows.map(r=> r.map(v=>(/[",;\n]/.test(v))?`"${v.replace(/"/g,'""')}"`:v).join(';')).join('\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = 'resumo_diario.csv'; a.click(); URL.revokeObjectURL(url);
  }catch(err){ console.error('CSV error', err); }
});

// Toggle filtros avançados + persistência
(function(){
  const panel = byId('filtrosAvancados');
  const btn   = byId('btnToggleAvancados');
  const KEY   = 'relatorios_avancados_open';
  function setOpen(v){
    if(!panel) return;
    panel.classList.toggle('collapsed', !v);
    localStorage.setItem(KEY, v ? '1' : '0');
    btn?.querySelector('.caret')?.classList.toggle('fa-angle-up', v);
    btn?.querySelector('.caret')?.classList.toggle('fa-angle-down', !v);
  }
  setOpen(localStorage.getItem(KEY) === '1');
  btn?.addEventListener('click', ()=> setOpen(panel.classList.contains('collapsed')) );
})();

/* Boot */
document.getElementById('btnAplicar')?.addEventListener('click', drawAll);
window.addEventListener('load', drawAll);
</script>
</body>
</html>
