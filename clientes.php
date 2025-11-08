<?php
/* ===========================================================================
 * clientes.php — Lista + filtros + paginação AJAX e modal Add/Edit
 * Requisitos externos:
 *   - includes/auth_guard.php  => require_tenant(), current_shop_id()
 *   - includes/mysqli.php      => $conn (mysqli)
 *   - includes/get_cliente.php, includes/salvar_cliente.php,
 *     includes/atualizar_cliente.php, includes/excluir_cliente.php
 *   - css/clientes.css  e theme.css (theme POR ÚLTIMO)
 * ======================================================================== */

/* ===== Segurança & contexto ANTES de qualquer saída ===== */
require_once __DIR__ . '/includes/auth_guard.php';
$tenant_id = require_tenant();
$shop_id   = current_shop_id(); // pode ser null

/* DB (antes de qualquer echo) */
include_once __DIR__ . '/includes/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ===== CAPTAÇÃO DOS PARÂMETROS + PAGINAÇÃO ===== */
$Q = $_GET;
if (empty($Q) && isset($_SERVER['QUERY_STRING'])) parse_str($_SERVER['QUERY_STRING'], $Q);

$pp_raw   = isset($Q['pp']) ? (int)$Q['pp'] : 25;
$per_page = max(5, min(100, $pp_raw));
$p_raw    = isset($Q['p']) ? (int)$Q['p'] : 1;
$page     = max(1, $p_raw);
$offset   = ($page - 1) * $per_page;

/* ===== Filtros ===== */
$ids      = trim($Q['ids'] ?? '');
$nome     = trim($Q['nome'] ?? '');
$cpf      = trim($Q['cpf'] ?? '');
$telefone = trim($Q['telefone'] ?? '');
$cidade   = trim($Q['cidade'] ?? '');
$uf       = strtoupper(trim($Q['uf'] ?? ''));
$cep      = trim($Q['cep'] ?? '');
$data_ini = trim($Q['data_ini'] ?? '');
$data_fim = trim($Q['data_fim'] ?? '');

$where = [];
$types = '';
$args  = [];

/* ===== Isolamento obrigatório por Empresa/Loja ===== */
$where[] = "tenant_id = ?";
$types  .= 'i';
$args[]  = $tenant_id;

if (!is_null($shop_id)) {
  $where[] = "shop_id = ?";
  $types  .= 'i';
  $args[]  = $shop_id;
}

/* Helpers */
if (!function_exists('addLike')) {
  function addLike(&$where,&$types,&$args,$col,$val){
    if ($val==='') return;
    $where[] = "$col LIKE ?";
    $types  .= 's';
    $args[]  = '%'.$val.'%';
  }
}
if (!function_exists('bind_all')) {
  function bind_all(mysqli_stmt $stmt, string $types, array &$args): void {
    if ($types==='' || empty($args)) return;
    $refs=[]; foreach($args as $k=>&$v){ $refs[$k]=&$v; }
    $stmt->bind_param($types, ...$refs);
  }
}

/* id único / faixa */
if ($ids !== '') {
  if (preg_match('~^\s*(\d+)\s*-\s*(\d+)\s*$~', $ids, $m)) {
    $a=(int)$m[1]; $b=(int)$m[2];
    if ($a>$b){ $t=$a; $a=$b; $b=$t; }
    $where[]="(id BETWEEN ? AND ?)"; $types.='ii'; array_push($args,$a,$b);
  } elseif (ctype_digit($ids)) {
    $where[]="id = ?"; $types.='i'; $args[]=(int)$ids;
  }
}

addLike($where,$types,$args,'nome',$nome);
addLike($where,$types,$args,'cpf',$cpf);
addLike($where,$types,$args,'telefone',$telefone);
addLike($where,$types,$args,'cidade',$cidade);
addLike($where,$types,$args,'cep',$cep);

if ($uf !== '') { $where[]="uf = ?"; $types.='s'; $args[]=$uf; }

/* Datas por data_cadastro */
if ($data_ini !== '' && $data_fim !== '') {
  $where[] = "DATE(data_cadastro) BETWEEN ? AND ?";
  $types  .= 'ss'; array_push($args,$data_ini,$data_fim);
} elseif ($data_ini !== '') {
  $where[] = "DATE(data_cadastro) >= ?"; $types.='s'; $args[]=$data_ini;
} elseif ($data_fim !== '') {
  $where[] = "DATE(data_cadastro) <= ?"; $types.='s'; $args[]=$data_fim;
}

$where_sql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* ===== TOTAL ===== */
$sql_count = "SELECT COUNT(*) FROM clientes $where_sql";
$stmt = $conn->prepare($sql_count);
bind_all($stmt,$types,$args);
$stmt->execute(); $stmt->bind_result($total_rows); $stmt->fetch(); $stmt->close();

$total_rows  = (int)$total_rows;
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($offset >= $total_rows && $total_rows>0) { $page=1; $offset=0; }

/* ===== CONSULTA PRINCIPAL ===== */
$sql = "SELECT
          id, nome, cpf, telefone,
          CONCAT(
            COALESCE(logradouro,''), 
            IF(COALESCE(logradouro,'')='','', ' '),
            COALESCE(numero,'')
          ) AS endereco_compacto,
          cep, cidade, uf
        FROM clientes
        $where_sql
        ORDER BY id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$types_main = $types . 'ii';
$args_main  = $args;
$args_main[] = (int)$per_page;
$args_main[] = (int)$offset; // <-- com $!
bind_all($stmt,$types_main,$args_main);
$stmt->execute(); $res=$stmt->get_result(); $rows=$res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ===== RENDER (HTML fragmentos) ===== */
function render_table($rows){
  ob_start(); ?>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th style="min-width:220px">Nome</th>
          <th style="min-width:140px">CPF</th>
          <th style="min-width:140px">Telefone</th>
          <th style="min-width:280px">Endereço</th>
          <th style="min-width:110px">CEP</th>
          <th style="min-width:160px">Cidade</th>
          <th style="min-width:70px">UF</th>
          <th style="min-width:160px; text-align:center;">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="8" class="vazio">Nenhum cliente encontrado.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr data-id="<?= (int)$r['id'] ?>">
            <td class="c-nome"><?= htmlspecialchars($r['nome'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['cpf'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['telefone'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['endereco_compacto'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['cep'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['cidade'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['uf'] ?: '—') ?></td>
            <td class="acoes">
              <button class="btnAcao editar" title="Editar" data-id="<?= (int)$r['id'] ?>">
                <i class="fa-solid fa-pen"></i>
              </button>
              <button class="btnAcao excluir" title="Excluir" data-id="<?= (int)$r['id'] ?>">
                <i class="fa-solid fa-trash"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php return ob_get_clean();
}

function render_pager($page,$total_pages,$per_page){
  $total_rows = (int)($GLOBALS['total_rows'] ?? 0);
  ob_start(); ?>
  <div class="pager">
    <div>Total: <span id="totalRows"><?= $total_rows ?></span></div>
    <div class="pager-pages">
      <?php
      $start=max(1,$page-2); $end=min($total_pages,$page+2);
      if($page>1) echo '<button class="pg" data-page="'.($page-1).'">&laquo;</button>';
      for($i=$start;$i<=$end;$i++){
        $cls=$i==$page?'pg active':'pg';
        echo '<button class="'.$cls.'" data-page="'.$i.'">'.$i.'</button>';
      }
      if($page<$total_pages) echo '<button class="pg" data-page="'.($page+1).'">&raquo;</button>';
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
  <?php return ob_get_clean();
}

/* ===== RESPOSTA PARCIAL (AJAX) – SEM QUALQUER HTML ANTES! ===== */
if (isset($Q['partial']) && $Q['partial']=='1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok'    => true,
    'table' => render_table($rows),
    'pager' => render_pager($page,$total_pages,$per_page),
    'total' => (int)$total_rows
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== A PARTIR DAQUI pode renderizar a página inteira ===== */
include_once __DIR__ . '/includes/header.php';
$pagina = 'clientes';
include_once __DIR__ . '/includes/navbar.php';
?>
<link rel="stylesheet" href="css/clientes.css">
<link rel="stylesheet" href="css/theme.css"><!-- theme por último -->

<div class="clientes-page">
  <header class="clientes-header">
    <h1>Cadastro de clientes</h1>
    <div class="header-actions">
      <button id="btnAdicionar" class="btn success"><i class="fa-solid fa-plus"></i> Adicionar</button>
      <button id="btnFiltro" class="btn primary"><i class="fa-solid fa-filter"></i> Filtros</button>
      <button id="btnLimpar" class="btn ghost" title="Limpar filtros">Limpar</button>
    </div>
  </header>

  <section id="tblContainer">
    <?= render_table($rows) ?>
  </section>

  <footer class="clientes-footer" id="paginacao">
    <?= render_pager($page,$total_pages,$per_page) ?>
  </footer>
</div>

<!-- MODAL FILTROS -->
<div id="modalFiltros" class="modal hidden" aria-hidden="true">
  <div class="modal-body" style="max-width:860px">
    <h3>Filtros</h3>
    <form id="filtrosForm" onsubmit="return false;">
      <div class="grid-4" style="gap:12px;margin-top:8px">
        <div><label>Cliente/ID (nº ou faixa)</label><input type="text" name="ids" placeholder="123 ou 100-200"></div>
        <div><label>Nome</label><input type="text" name="nome" placeholder="Ex.: Maria"></div>
        <div><label>CPF</label><input type="text" name="cpf" placeholder="000.000.000-00"></div>
        <div><label>Telefone</label><input type="text" name="telefone" placeholder="(xx) xxxxx-xxxx"></div>
        <div><label>CEP</label><input type="text" name="cep" placeholder="00000-000"></div>
        <div><label>Cidade</label><input type="text" name="cidade" placeholder="Guaratinguetá"></div>
        <div><label>UF</label><input type="text" name="uf" maxlength="2" placeholder="SP" style="text-transform:uppercase"></div>
        <div><label>Data (início)</label><input type="date" name="data_ini"></div>
        <div><label>Data (fim)</label><input type="date" name="data_fim"></div>
      </div>
      <div class="modal-actions" style="margin-top:12px">
        <button class="btn-ghost" data-close>Cancelar</button>
        <button id="btnAplicarFiltros" type="button" class="btn primary"><i class="fa-solid fa-check"></i> Aplicar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL ADICIONAR/EDITAR -->
<div id="modalAdicionar" class="modal hidden" aria-hidden="true">
  <div class="modal-body" style="max-width:600px">
    <h3>Adicionar Cliente</h3>
    <form id="formAdicionar" onsubmit="return false;">
      <div class="grid-2" style="gap:12px;margin-top:8px">
        <div><label>Nome</label><input type="text" name="nome" required></div>
        <div><label>CPF</label><input type="text" name="cpf"></div>
        <div><label>Telefone</label><input type="text" name="telefone"></div>
        <div><label>Email</label><input type="email" name="email"></div>
        <div><label>CEP</label><input type="text" name="cep"></div>
        <div><label>Logradouro</label><input type="text" name="logradouro"></div>
        <div><label>Número</label><input type="text" name="numero"></div>
        <div><label>Bairro</label><input type="text" name="bairro"></div>
        <div><label>Cidade</label><input type="text" name="cidade"></div>
        <div><label>UF</label><input type="text" name="uf" maxlength="2" style="text-transform:uppercase"></div>
      </div>
      <div class="modal-actions" style="margin-top:12px">
        <button class="btn-ghost" data-close>Cancelar</button>
        <button id="btnSalvarCliente" class="btn primary"><i class="fa-solid fa-check"></i> Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
(function () {
  const $  = s => document.querySelector(s);
  const $$ = s => Array.from(document.querySelectorAll(s));

  // ===== Helpers =====
  const showModal = (el) => {
    const m = (typeof el === 'string') ? $(el) : el;
    if (!m) return;
    m.classList.remove('hidden');
    m.setAttribute('aria-hidden', 'false');
  };
  const hideModal = (el) => {
    const m = (typeof el === 'string') ? $(el) : el;
    if (!m) return;
    m.classList.add('hidden');
    m.setAttribute('aria-hidden', 'true');
  };
  const toast = (msg, err=false) => {
    const t = document.createElement('div');
    t.className = 'toast ' + (err?'erro':'ok');
    t.textContent = msg;
    Object.assign(t.style, {
      position:'fixed', bottom:'20px', right:'20px', background: err?'#e74c3c':'#2ecc71',
      color:'#fff', padding:'10px 18px', borderRadius:'8px', boxShadow:'0 4px 12px rgba(0,0,0,.3)',
      opacity:'0', transform:'translateY(20px)', transition:'.3s', zIndex:'10000', fontSize:'14px'
    });
    document.body.appendChild(t);
    requestAnimationFrame(()=>{ t.style.opacity='1'; t.style.transform='translateY(0)'; });
    setTimeout(()=>{ t.style.opacity='0'; t.style.transform='translateY(20px)'; 
      setTimeout(()=> t.remove(), 300);
    }, 2600);
  };

  // ===== Query params e atualização parcial =====
  function qsParams(extra = {}) {
    const cur = new URLSearchParams(window.location.search);
    const out = new URLSearchParams(cur);
    for (const k in extra) {
      out.delete(k);
      const v = extra[k];
      if (v !== '' && v !== undefined && v !== null) out.set(k, v);
    }
    if (!out.get('p')) out.set('p', '1');
    return out;
  }

  async function fetchTable(params) {
    try {
      params.set('partial', '1');
      const r = await fetch('clientes.php?' + params.toString(), {
        headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store'
      });
      const j = await r.json();
      if (!j || j.ok !== true) throw new Error('Resposta inválida');
      const tbl = $('#tblContainer'); if (tbl) tbl.innerHTML = j.table;
      const pag = $('#paginacao');   if (pag) pag.innerHTML = j.pager;
      wirePager();
      wirePP();
      wireRowActions();
    } catch(e) {
      console.error('fetchTable erro:', e);
      toast('Falha ao atualizar a tabela.', true);
    }
  }

  function wirePager() {
    $$('#paginacao .pg').forEach(btn => {
      btn.addEventListener('click', () => fetchTable(qsParams({ p: btn.dataset.page })));
    });
  }
  function wirePP() {
    const pp = $('#pp');
    if (pp) pp.addEventListener('change', () => fetchTable(qsParams({ pp: pp.value, p: '1' })));
  }

  // ===== Botões do header =====
  $('#btnAdicionar')?.addEventListener('click', () => {
    const f = $('#formAdicionar'); if (f) f.reset();
    const modalAdd = $('#modalAdicionar'); if (modalAdd) modalAdd.dataset.editId = '';
    const h = $('#modalAdicionar h3'); if (h) h.textContent = 'Adicionar Cliente';
    showModal('#modalAdicionar');
  });
  $('#btnFiltro')?.addEventListener('click', () => showModal('#modalFiltros'));
  $('#btnLimpar')?.addEventListener('click', () => {
    const params = new URLSearchParams({ p: '1', pp: String($('#pp')?.value || 25) });
    fetchTable(params);
  });

  // ===== Fechar modais =====
  $('#modalFiltros [data-close]')?.addEventListener('click', () => hideModal('#modalFiltros'));
  $('#modalAdicionar [data-close]')?.addEventListener('click', () => hideModal('#modalAdicionar'));
  $('#modalFiltros')?.addEventListener('click', (e)=>{ if (e.target.id === 'modalFiltros') hideModal(e.currentTarget); });
  $('#modalAdicionar')?.addEventListener('click', (e)=>{ if (e.target.id === 'modalAdicionar') hideModal(e.currentTarget); }); // <-- fix .id

  // ===== Aplicar filtros =====
  $('#btnAplicarFiltros')?.addEventListener('click', () => {
    const form = $('#filtrosForm');
    if (!form) return;
    const fd = new FormData(form);
    const params = new URLSearchParams();
    params.set('p', '1');
    params.set('pp', String($('#pp')?.value || 25));
    for (const [k, v] of fd.entries()) if (String(v).trim() !== '') params.append(k, v);
    fetchTable(params);
    hideModal('#modalFiltros');
  });

  // ===== Salvar (adicionar/editar) =====
  $('#btnSalvarCliente')?.addEventListener('click', async () => {
    const f = $('#formAdicionar');
    if (!f) return;
    const fd = new FormData(f);
    const modalAdd = $('#modalAdicionar');
    const editId = modalAdd?.dataset.editId || '';
    if (editId) fd.append('id', editId);

    const url = editId ? 'includes/atualizar_cliente.php' : 'includes/salvar_cliente.php';
    try {
const r = await fetch(url, { method:'POST', body:fd, cache:'no-store' });
let j;
try {
  j = await r.clone().json();
} catch(_) {
  const txt = await r.text();
  try {
    j = JSON.parse(txt.trim());
  } catch(e2) {
    console.error('Resposta não-JSON:', txt);
    throw new Error('Resposta inválida do servidor');
  }
}

      if (j.ok) {
        hideModal('#modalAdicionar');
        fetchTable(qsParams());
        toast(editId ? 'Cliente atualizado!' : 'Cliente adicionado!');
        if (modalAdd) { modalAdd.dataset.editId = ''; }
        const h = $('#modalAdicionar h3'); if (h) h.textContent = 'Adicionar Cliente';
      } else {
        toast('Erro: ' + (j.msg || 'Falha ao salvar.'), true);
      }
    } catch(e) {
      console.error('Salvar erro:', e);
      toast('Falha na comunicação ao salvar.', true);
    }
  });

  // ===== Ações nas linhas =====
  function wireRowActions() {
    // Excluir
    $$('.btnAcao.excluir').forEach(btn => {
      btn.onclick = async () => {
        const id = btn.dataset.id;
        if (!id) return;
        if (!confirm('Excluir cliente #' + id + '?')) return;
        try {
          const r = await fetch('includes/excluir_cliente.php', {
            method: 'POST',
            body: new URLSearchParams({ id })
          });
          const j = await r.json();
          if (j.ok) {
            fetchTable(qsParams());
            toast('Cliente excluído.');
          } else {
            toast('Erro: ' + (j.msg || 'Falha ao excluir.'), true);
          }
        } catch(e) {
          console.error('Excluir erro:', e);
          toast('Falha na comunicação ao excluir.', true);
        }
      };
    });

    // Editar
    $$('.btnAcao.editar').forEach(btn => {
      btn.onclick = async () => {
        const id = btn.dataset.id;
        if (!id) return;
        try {
          const r = await fetch('includes/get_cliente.php?id=' + id, { cache:'no-store' });
          const j = await r.json();
          if (!j.ok || !j.data) return toast('Erro ao carregar cliente.', true);

          const f = $('#formAdicionar');
          if (f) {
            for (const [k, v] of Object.entries(j.data)) {
              if (k in f) f[k].value = (v ?? '');
            }
          }
          const h = $('#modalAdicionar h3'); if (h) h.textContent = 'Editar Cliente #' + id;
          const modalAdd = $('#modalAdicionar'); if (modalAdd) modalAdd.dataset.editId = id;
          showModal('#modalAdicionar');
        } catch(e) {
          console.error('Editar erro:', e);
          toast('Falha na comunicação ao buscar cliente.', true);
        }
      };
    });
  }

  // ===== Autocomplete CEP com caret estável =====
  function onlyDigits(s){ return (s||'').replace(/\D/g,''); }
  async function buscaCEP(cep){
    const limpo = onlyDigits(cep);
    if (limpo.length !== 8) return null;
    try{
      const r = await fetch(`https://viacep.com.br/ws/${limpo}/json/`);
      if(!r.ok) return null;
      const j = await r.json();
      if (j.erro) return null;
      return j;
    }catch{ return null; }
  }
  function preencherEndereco(form, dados){
    form.logradouro && (form.logradouro.value = dados?.logradouro || '');
    form.bairro     && (form.bairro.value     = dados?.bairro     || '');
    form.cidade     && (form.cidade.value     = dados?.localidade || '');
    form.uf         && (form.uf.value         = dados?.uf         || '');
    if (dados && form.numero && !form.numero.value) form.numero.focus();
  }
  function placeCaretByDigitIndex(el, masked, digitIndex){
    let count = 0, pos = masked.length;
    for (let i = 0; i < masked.length; i++){
      if (/\d/.test(masked[i])) count++;
      if (count >= digitIndex){ pos = i + 1; break; }
    }
    el.setSelectionRange(pos, pos);
  }
  function formatCepKeepingCaret(el){
    const oldVal   = el.value;
    const oldCaret = el.selectionStart ?? oldVal.length;
    const digitsBeforeCaret = (oldVal.slice(0, oldCaret).match(/\d/g) || []).length;
    const digits = onlyDigits(oldVal).slice(0,8);
    const masked = digits.length > 5 ? digits.slice(0,5) + '-' + digits.slice(5) : digits;
    if (el.value !== masked){
      el.value = masked;
      placeCaretByDigitIndex(el, masked, digitsBeforeCaret);
    }
  }
  function wireCepAutocomplete(){
    const form = document.querySelector('#formAdicionar');
    if (!form || !form.cep) return;
    let ultimoCEPConsultado = '';
    let debounce;
    form.cep.addEventListener('input', ()=>{
      formatCepKeepingCaret(form.cep);
      clearTimeout(debounce);
      if (onlyDigits(form.cep.value).length === 8){
        debounce = setTimeout(tentarBuscar, 220);
      }
    });
    form.cep.addEventListener('blur', tentarBuscar);
    async function tentarBuscar(){
      const cepAtual = onlyDigits(form.cep.value);
      if (cepAtual.length !== 8 || cepAtual === ultimoCEPConsultado) return;
      ultimoCEPConsultado = cepAtual;
      form.cep.style.opacity = '0.75';
      const dados = await buscaCEP(cepAtual);
      form.cep.style.opacity = '1';
      if (dados) preencherEndereco(form, dados);
    }
  }
  wireCepAutocomplete();
  document.getElementById('btnAdicionar')?.addEventListener('click', wireCepAutocomplete);

  // ===== Inicialização =====
  wirePager();
  wirePP();
  wireRowActions();
})();
});
</script>
</body>
</html>
