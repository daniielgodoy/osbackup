<?php
// config.php — página de configurações (isolada por Empresa/Loja)
// Requisitos externos:
// - includes/auth_guard.php  -> require_tenant(), current_shop_id() (garantem tenant/shop na sessão)
// - includes/mysqli.php      -> $conn (mysqli)
// - includes/config_api.php  -> endpoints chamados via fetch (get_all, set_msg_templates, wa_status, etc.)
// - css/config.css e css/theme.css (theme por último)

declare(strict_types=1);

/* ===== Segurança/contexto ANTES de qualquer saída ===== */
require_once __DIR__ . '/includes/auth_guard.php';
$tenant_id = require_tenant();          // lança/redirect se não houver tenant
$shop_id   = current_shop_id();         // pode ser null se você permitir "todas as lojas" (ajuste no back)

/* ===== DB ===== */
include_once __DIR__ . '/includes/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ===== Sessão ===== */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$user_id = $_SESSION['user_id'] ?? 1; // ajuste para seu auth real
?>
<?php include_once __DIR__ . '/includes/header.php'; ?>
<body>
<?php
  $pagina = 'config';
  include_once __DIR__ . '/includes/navbar.php';
?>

<!-- CSS da página -->
<link rel="stylesheet" href="css/config.css">
<!-- IMPORTANTE: theme.css por ÚLTIMO para garantir overrides globais -->
<link rel="stylesheet" href="css/theme.css">

<div class="config-page" data-tenant="<?= htmlspecialchars((string)$tenant_id) ?>" data-shop="<?= htmlspecialchars((string)($shop_id ?? '')) ?>">
  <header class="config-header">
    <h1>Configurações</h1>
    <div class="header-actions">
      <button id="btnSaveAll" class="btn primary">
        <i class="fa-solid fa-floppy-disk"></i> Salvar tudo
      </button>
      <button id="btnExportar" class="btn outline">
        <i class="fa-solid fa-download"></i> Exportar dados
      </button>
    </div>
  </header>

  <!-- GRID PRINCIPAL -->
  <section class="config-grid">

    <!-- ⚙️ Preferências -->
    <section class="card">
      <h2>⚙️ Preferências</h2>
      <div class="row between align-center">
        <div>
          <label class="label-strong">Enviar mensagens automáticas ao cliente</label>
          <div class="muted small">Quando marcar OS como <b>Concluído</b> ou <b>Aguardando retirada</b> (WhatsApp da loja atual)</div>
        </div>
        <label class="switch">
          <input type="checkbox" id="toggleNotify">
          <span class="slider"></span>
        </label>
      </div>
    </section>

    <!-- 📱 WhatsApp -->
    <section class="card">
      <h2>📱 WhatsApp</h2>

      <div class="row between align-center mb-10">
        <div>
          <div class="muted">Estado da conexão</div>
          <div id="waStatusPill" class="pill">Carregando…</div>
          <div class="small muted" id="waMe"></div>
          <?php if ($shop_id === null): ?>
            <div class="small muted" style="margin-top:6px">
              <i class="fa-solid fa-circle-exclamation"></i>
              Selecione uma Loja para vincular o WhatsApp.
            </div>
          <?php endif; ?>
        </div>
        <div class="actions">
          <button id="btnWARefresh" class="btn outline"><i class="fa-solid fa-rotate"></i> Atualizar</button>
          <button id="btnWAReconnect" class="btn outline"><i class="fa-solid fa-plug"></i> Reconectar</button>
          <button id="btnWALogout" class="btn danger"><i class="fa-solid fa-right-from-bracket"></i> Desconectar</button>
        </div>
      </div>

      <div id="waQrBox" class="wa-qr-box" style="display:none">
        <div class="muted small mb-6">
          No seu celular: WhatsApp → <b>Aparelhos conectados</b> → <b>Conectar um aparelho</b> e escaneie o QR abaixo.
        </div>
        <div class="wa-qr-wrap" style="display:flex;align-items:center;justify-content:center;min-height:300px;border:1px dashed var(--div);border-radius:14px;">
          <div id="waQrLoading" class="muted">Preparando QR…</div>
          <img id="waQrImg" alt="QR Code" style="display:none;max-width:300px;width:100%;image-rendering:pixelated;" />
          <div id="waQrCanvas" style="display:none;"></div>
        </div>
        <div class="row gap mt-10">
          <button id="btnWAGenerate" class="btn primary"><i class="fa-solid fa-qrcode"></i> Gerar novo QR</button>
        </div>
      </div>
    </section>

    <!-- 💬 Mensagens por Status (WhatsApp) -->
    <section class="card">
      <h2>💬 Mensagens por Status (WhatsApp)</h2>

      <div class="muted small">
        Deixe <b>vazio</b> para <b>não enviar</b> mensagem nesse status.<br>
        Variáveis: <code>{NOME}</code>, <code>{MODELO}</code>, <code>{VALOR}</code>, <code>{ID}</code>, <code>{HORA}</code>.
      </div>

      <div class="grid-2 mt-12">
        <!-- Editor -->
        <div>
          <div class="mt-6">
            <label class="label-strong">Pendente</label>
            <textarea id="tpl_pendente" class="input" rows="3" placeholder="(vazio = não envia)"></textarea>
          </div>

          <div class="mt-10">
            <label class="label-strong">Em andamento</label>
            <textarea id="tpl_em_andamento" class="input" rows="3" placeholder="(vazio = não envia)"></textarea>
          </div>

          <div class="mt-10">
            <label class="label-strong">Orçamento</label>
            <textarea id="tpl_orcamento" class="input" rows="3" placeholder="(vazio = não envia)"></textarea>
          </div>

          <div class="mt-10">
            <label class="label-strong">Aguardando retirada</label>
            <textarea id="tpl_aguardando_retirada" class="input" rows="5" placeholder="(vazio = não envia)"></textarea>
          </div>

          <div class="mt-10">
            <label class="label-strong">Concluído (retirado)</label>
            <textarea id="tpl_concluido" class="input" rows="3" placeholder="(vazio = não envia)"></textarea>
          </div>

          <div class="actions mt-12">
            <button id="btnSalvarTemplates" class="btn primary">
              <i class="fa-solid fa-floppy-disk"></i> Salvar mensagens
            </button>
          </div>
        </div>

        <!-- Manual -->
        <aside class="manual-box">
          <div class="manual-title">🔤 Variáveis disponíveis</div>
          <p class="muted small" style="margin-top:6px">
            Clique para inserir no campo focado ou digite manualmente (ex.: <code>{NOME}</code>).
          </p>

          <div class="manual-subtitle" style="margin-top:12px">Inserir rapidamente</div>
          <div class="chip-row" style="margin-bottom:8px">
            <button class="chip" data-insert="{NOME}">{NOME}</button>
            <button class="chip" data-insert="{MODELO}">{MODELO}</button>
            <button class="chip" data-insert="{VALOR}">{VALOR}</button>
            <button class="chip" data-insert="{ID}">{ID}</button>
            <button class="chip" data-insert="{HORA}">{HORA}</button>
          </div>

          <div class="manual-subtitle">O que cada variável vira</div>
          <ul class="manual-list">
            <li><code>{NOME}</code> → Nome do cliente cadastrado na OS.</li>
            <li><code>{MODELO}</code> → Modelo do aparelho.</li>
            <li><code>{VALOR}</code> → Valor total confirmado da OS.</li>
            <li><code>{ID}</code> → Número da OS.</li>
            <li><code>{HORA}</code> → Hora do envio (ex.: 14:32).</li>
          </ul>

          <div class="manual-subtitle">Exemplo</div>
          <div class="manual-code">
<pre>Olá {NOME}! Seu {MODELO} está pronto.
Total: {VALOR}. OS #{ID} — enviado às {HORA}.</pre>
          </div>

          <p class="muted small">Dica: campo vazio = não envia naquele status.</p>
        </aside>
      </div>
    </section>

    <!-- 🔐 Conta e Segurança -->
    <section class="card">
      <h2>🔐 Conta e Segurança</h2>
      <div class="grid-2">
        <div>
          <label>Alterar senha</label>
          <div class="row gap">
            <input type="password" id="pwdAtual" placeholder="Senha atual">
            <input type="password" id="pwdNova" placeholder="Nova senha (mín. 6)">
            <input type="password" id="pwdConf" placeholder="Confirmar nova senha">
          </div>
          <div class="actions">
            <button id="btnSenha" class="btn primary"><i class="fa-solid fa-key"></i> Atualizar senha</button>
          </div>
        </div>

        <div>
          <label>E-mail e telefone vinculados</label>
          <div class="row gap">
            <input type="email" id="email" placeholder="email@exemplo.com">
            <input type="tel" id="telefone" placeholder="(11) 99999-9999">
          </div>
          <div class="help">Usado para recuperação de conta e notificações.</div>
          <div class="actions">
            <button id="btnContatos" class="btn outline"><i class="fa-solid fa-envelope"></i> Atualizar contatos</button>
          </div>
        </div>
      </div>

      <div>
        <label>Sessões ativas / dispositivos</label>
        <div id="sessList" class="list compact">
          <div class="row"><span class="muted">Carregando…</span></div>
        </div>
        <div class="actions">
          <button id="btnLogoutAll" class="btn danger"><i class="fa-solid fa-right-from-bracket"></i> Encerrar todas as sessões</button>
        </div>
      </div>
    </section>

    <!-- 👤 Perfil do Usuário -->
    <section class="card">
      <h2>👤 Perfil do Usuário</h2>
      <div class="grid-2">
        <div>
          <label>Nome</label>
          <input type="text" id="nome" placeholder="Seu nome">
          <label style="margin-top:10px">@handle</label>
          <div class="row">
            <span class="at-prefix">@</span>
            <input type="text" id="handle" placeholder="apelido-unico">
          </div>
        </div>
        <div>
          <label>Foto</label>
          <div class="avatar-row">
            <img id="avatarPreview" class="avatar" src="https://via.placeholder.com/96x96?text=Foto" alt="avatar">
            <div class="actions vertical">
              <input type="file" id="avatarFile" accept="image/*">
              <button id="btnAvatar" class="btn outline"><i class="fa-solid fa-upload"></i> Enviar foto</button>
            </div>
          </div>
        </div>
      </div>
      <div class="actions">
        <button id="btnPerfil" class="btn primary"><i class="fa-solid fa-user-pen"></i> Atualizar perfil</button>
      </div>
    </section>

    <!-- 💳 Pagamentos e Assinaturas -->
    <section class="card">
      <h2>💳 Pagamentos e Assinaturas</h2>
      <div class="grid-2">
        <div>
          <h3>Formas de pagamento</h3>
          <div id="payMethods" class="list small">
            <div class="row"><span class="muted">Carregando…</span></div>
          </div>
          <div class="actions">
            <button id="btnAddMethod" class="btn outline"><i class="fa-solid fa-plus"></i> Adicionar cartão</button>
          </div>
        </div>
        <div>
          <h3>Plano de assinatura</h3>
          <div id="planBox" class="kit">
            <div class="row between">
              <div>
                <div class="muted">Plano atual</div>
                <div id="planName" class="big">—</div>
              </div>
              <div>
                <button id="btnChangePlan" class="btn outline">Alterar plano</button>
              </div>
            </div>
            <div class="muted" id="planRenova">Renovação: —</div>
          </div>
          <div class="actions">
            <button id="btnCancelAuto" class="btn danger"><i class="fa-solid fa-ban"></i> Cancelar renovação automática</button>
          </div>
        </div>
      </div>

      <div class="mt-14">
        <h3>Histórico de pagamentos e faturas</h3>
        <div id="invoices" class="list small">
          <div class="row"><span class="muted">Carregando…</span></div>
        </div>
      </div>
    </section>

  </section>
</div>

<!-- Estilos leves para o manual (mantidos minimalistas para não interferir no theme) -->
<style>
  .manual-box{
    background: var(--card-2, #1f2329);
    border: 1px dashed var(--div, #2e343d);
    border-radius: 14px;
    padding: 14px 16px;
    position: sticky;
    top: 12px;
    align-self: start;
  }
  .manual-title{ font-weight:600; margin-bottom:8px; }
  .manual-subtitle{ font-weight:600; margin:14px 0 8px; }
  .manual-list{ margin: 6px 0 10px 18px; }
  .chip-row{ display:flex; flex-wrap:wrap; gap:8px; }
  .chip{
    background: var(--div, #2e343d);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 12px;
    cursor: pointer;
  }
  .manual-code{
    background: var(--div, #2e343d);
    border-radius: 10px;
    padding: 10px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px;
    margin-bottom: 8px;
    overflow:auto;
  }
  .manual-code pre{ margin:0; white-space:pre-wrap; }
  @media (max-width: 900px){
    .manual-box{ position: static; }
  }
</style>

<!-- Lib para renderizar QR (fallback via texto) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>
/* ==== Helpers ==== */
function toast(msg, ok=true){ alert((ok?'✅ ':'⚠️ ')+msg); }
async function api(action, body, isFormData=false){
  // O backend (includes/config_api.php) deve validar tenant_id/shop_id via sessão (auth_guard)
  const url = 'includes/config_api.php?action='+encodeURIComponent(action);
  let opt = {};
  if(isFormData){ opt = { method: 'POST', body }; }
  else if(body){ opt = { method: 'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) }; }
  const r = await fetch(url, opt);
  let data = {};
  try { data = await r.json(); } catch(_){}
  if(!r.ok || data.ok===false) throw new Error((data && (data.error||data.detail)) || ('Erro API '+action));
  return data;
}
function maskCard(n){ if(!n) return '—'; const s=String(n).replace(/\s+/g,''); return '•••• •••• •••• '+s.slice(-4); }

/* ===== Inserção de variáveis no textarea focado ===== */
let LAST_FOCUS_TA = null;
document.addEventListener('focusin', (e)=>{
  if(e.target && e.target.tagName === 'TEXTAREA' && /^tpl_/.test(e.target.id)){
    LAST_FOCUS_TA = e.target;
  }
});
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-insert]');
  if(!btn) return;
  const token = btn.getAttribute('data-insert');
  const ta = LAST_FOCUS_TA || document.querySelector('textarea#tpl_aguardando_retirada') || document.querySelector('textarea');
  if(!ta) return;
  insertAtCursor(ta, token);
  ta.focus();
});
function insertAtCursor(textarea, text){
  const start = textarea.selectionStart ?? textarea.value.length;
  const end   = textarea.selectionEnd ?? textarea.value.length;
  const before = textarea.value.slice(0, start);
  const after  = textarea.value.slice(end);
  textarea.value = before + text + after;
  const pos = start + text.length;
  textarea.setSelectionRange(pos, pos);
}

/* ==== Boot (único) ==== */
(async function boot(){
  try{
    const r = await api('get_all', {}); // deve considerar tenant/shop de sessão

    // contatos
    email.value    = r.user?.email    || '';
    telefone.value = r.user?.telefone || '';

    // perfil
    nome.value   = r.user?.nome   || '';
    handle.value = r.user?.handle || '';
    if(r.user?.avatar_url) avatarPreview.src = r.user.avatar_url;

    // preferências
    const prefNotify = (r.prefs?.notify_auto ?? 1) === 1;
    const tn = document.getElementById('toggleNotify');
    if (tn) tn.checked = prefNotify;

    // sessões / pagamentos / plano / faturas
    renderSessions(r.sessions || []);
    renderPayMethods(r.pay_methods || []);
    planName.textContent = r.plan?.name || 'Gratuito';
    planRenova.textContent = 'Renovação: ' + (r.plan?.renova_em || '—');
    renderInvoices(r.invoices || []);

    // mensagens por status
    const mt = r.msg_templates || {};
    document.getElementById('tpl_pendente').value             = mt.pendente            ?? '';
    document.getElementById('tpl_em_andamento').value         = mt.em_andamento        ?? '';
    document.getElementById('tpl_orcamento').value            = mt.orcamento           ?? '';
    document.getElementById('tpl_aguardando_retirada').value  = mt.aguardando_retirada ?? '';
    document.getElementById('tpl_concluido').value            = mt.concluido           ?? '';

    // WhatsApp status inicial (por loja)
    await loadWAStatus();

    // Revalidar status a cada 5s enquanto não estiver conectado
    setInterval(async ()=>{
      const pillText = el.pill?.textContent || '';
      if(!/Conectado/i.test(pillText)){
        await loadWAStatus();
      }
    }, 5000);

  }catch(e){ console.error(e); toast(e.message,false); }
})();

/* ==== Conta e Segurança ==== */
function renderSessions(list){
  const box = document.getElementById('sessList');
  if(!list.length){ box.innerHTML = '<div class="row"><span class="muted">Nenhuma sessão.</span></div>'; return; }
  box.innerHTML = list.map(s=>`
    <div class="row between" data-id="${s.id}">
      <div>
        <div><b>${s.device||'Dispositivo'}</b> <span class="muted">(${s.ip || '—'})</span></div>
        <div class="muted small">Último acesso: ${s.last_seen || '—'}</div>
      </div>
      <div class="actions">
        <button class="btn danger" data-logout="${s.id}"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
  `).join('');
}
document.getElementById('sessList')?.addEventListener('click', async (e)=>{
  const btn = e.target.closest('[data-logout]'); if(!btn) return;
  const id = Number(btn.dataset.logout);
  try{
    await api('logout_session', { id });
    const r = await api('list_sessions', {}); renderSessions(r.items||[]);
    toast('Sessão encerrada.');
  }catch(err){ toast(err.message,false); }
});
btnLogoutAll?.addEventListener('click', async ()=>{
  if(!confirm('Encerrar TODAS as sessões?')) return;
  try{ await api('logout_all', {}); renderSessions([]); toast('Todas as sessões foram encerradas.'); }
  catch(err){ toast(err.message,false); }
});

btnSenha?.addEventListener('click', async ()=>{
  const atual = pwdAtual.value, nova = pwdNova.value, conf = pwdConf.value;
  if(!nova || nova.length<6) return toast('Nova senha deve ter pelo menos 6 caracteres.', false);
  if(nova!==conf) return toast('Confirmação não confere.', false);
  try{
    await api('change_password', { atual, nova });
    pwdAtual.value=''; pwdNova.value=''; pwdConf.value='';
    toast('Senha atualizada.');
  }catch(err){ toast(err.message,false); }
});
btnContatos?.addEventListener('click', async ()=>{
  try{
    await api('update_contacts', { email: email.value.trim(), telefone: telefone.value.trim() });
    toast('Contatos atualizados.');
  }catch(err){ toast(err.message,false); }
});

/* ==== Perfil ==== */
btnPerfil?.addEventListener('click', async ()=>{
  try{
    await api('update_profile', { nome: nome.value.trim(), handle: handle.value.trim() });
    toast('Perfil atualizado.');
  }catch(err){ toast(err.message,false); }
});
btnAvatar?.addEventListener('click', async ()=>{
  const f = avatarFile.files[0]; if(!f) return toast('Selecione uma imagem.', false);
  const fd = new FormData(); fd.append('file', f);
  try{
    const r = await api('upload_avatar', fd, true);
    if(r.url) avatarPreview.src = r.url;
    toast('Foto atualizada.');
  }catch(err){ toast(err.message,false); }
});

/* ==== Pagamentos/Assinaturas (mock funcional) ==== */
function renderPayMethods(items){
  const box = document.getElementById('payMethods');
  if(!items.length){ box.innerHTML = '<div class="row"><span class="muted">Nenhum método cadastrado.</span></div>'; return; }
  box.innerHTML = items.map(m=>`
    <div class="row between" data-id="${m.id}">
      <div>${m.brand||'Cartão'} — ${maskCard(m.last4||'0000')}</div>
      <div class="actions">
        <button class="btn outline" data-def="${m.id}">Tornar padrão</button>
        <button class="btn danger" data-del="${m.id}"><i class="fa-solid fa-trash"></i></button>
      </div>
    </div>
  `).join('');
}
function renderInvoices(items){
  const box = document.getElementById('invoices');
  if(!items.length){ box.innerHTML = '<div class="row"><span class="muted">Sem faturas.</span></div>'; return; }
  box.innerHTML = items.map(i=>`
    <div class="row between">
      <div>
        <div><b>${i.descricao||'Fatura'}</b></div>
        <div class="muted small">${i.data||'—'}</div>
      </div>
      <div><b>${i.total||'R$ 0,00'}</b></div>
    </div>
  `).join('');
}
document.getElementById('payMethods')?.addEventListener('click', async (e)=>{
  const del = e.target.closest('[data-del]'); const def = e.target.closest('[data-def]');
  if(del){
    const id = Number(del.dataset.del);
    if(!confirm('Remover este cartão?')) return;
    try{
      await api('delete_pay_method', { id });
      const r = await api('list_pay', {}); renderPayMethods(r.items||[]);
      toast('Removido.');
    }catch(err){ toast(err.message,false); }
  }
  if(def){
    const id = Number(def.dataset.def);
    try{ await api('set_default_pay', { id }); toast('Definido como padrão.'); }
    catch(err){ toast(err.message,false); }
  }
});
btnAddMethod?.addEventListener('click', async ()=>{
  try{
    await api('add_pay_method', { brand:'VISA', last4:String(Math.floor(1000+Math.random()*8999)) });
    const r = await api('list_pay', {}); renderPayMethods(r.items||[]);
    toast('Cartão adicionado (mock).');
  }catch(err){ toast(err.message,false); }
});
btnChangePlan?.addEventListener('click', async ()=>{
  try{
    await api('change_plan', { plan:'pro' });
    const r = await api('get_all', {});
    planName.textContent = r.plan?.name || '—';
    planRenova.textContent = 'Renovação: ' + (r.plan?.renova_em || '—');
    toast('Plano alterado (mock).');
  }catch(err){ toast(err.message,false); }
});
btnCancelAuto?.addEventListener('click', async ()=>{
  if(!confirm('Cancelar renovação automática?')) return;
  try{
    await api('cancel_auto', {}); toast('Renovação automática cancelada.');
  }catch(err){ toast(err.message,false); }
});

/* ==== Export (topo) ==== */
btnExportar?.addEventListener('click', async ()=>{
  try{
    const r = await api('export_data', {});
    const blob = new Blob([JSON.stringify(r.data,null,2)], {type:'application/json'});
    const url  = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href=url; a.download='export_config.json'; a.click();
    URL.revokeObjectURL(url);
  }catch(err){ toast(err.message,false); }
});

/* ==== Salvar tudo (inclui preferências + templates) ==== */
btnSaveAll?.addEventListener('click', async ()=>{
  try{
    await api('update_contacts', { email:email.value.trim(), telefone:telefone.value.trim() });
    await api('update_profile',  { nome:nome.value.trim(), handle:handle.value.trim() });
    await api('set_notify_auto', { enabled: document.getElementById('toggleNotify')?.checked ? 1 : 0 });

    await api('set_msg_templates', {
      pendente:             document.getElementById('tpl_pendente').value,
      em_andamento:         document.getElementById('tpl_em_andamento').value,
      orcamento:            document.getElementById('tpl_orcamento').value,
      aguardando_retirada:  document.getElementById('tpl_aguardando_retirada').value,
      concluido:            document.getElementById('tpl_concluido').value
    });

    toast('Tudo salvo.');
  }catch(err){ toast(err.message,false); }
});

/* Salvar apenas os templates (botão do card) */
document.getElementById('btnSalvarTemplates')?.addEventListener('click', async ()=>{
  try{
    await api('set_msg_templates', {
      pendente:             document.getElementById('tpl_pendente').value,
      em_andamento:         document.getElementById('tpl_em_andamento').value,
      orcamento:            document.getElementById('tpl_orcamento').value,
      aguardando_retirada:  document.getElementById('tpl_aguardando_retirada').value,
      concluido:            document.getElementById('tpl_concluido').value
    });
    toast('Mensagens por status salvas.');
  }catch(e){ toast(e.message,false); }
});

/* (Opcional) salvar imediato ao alternar o switch */
document.getElementById('toggleNotify')?.addEventListener('change', async (e)=>{
  try{
    await api('set_notify_auto', { enabled: e.target.checked ? 1 : 0 });
    toast(e.target.checked ? 'Mensagens automáticas: ligadas.' : 'Mensagens automáticas: desligadas.');
  }catch(err){
    toast(err.message, false);
    e.target.checked = !e.target.checked; // reverte se falhar
  }
});

/* ===== WhatsApp (QR/Status) — por loja ===== */
const el = {
  pill: document.getElementById('waStatusPill'),
  me: document.getElementById('waMe'),
  qrBox: document.getElementById('waQrBox'),
  qrImg: document.getElementById('waQrImg'),
  qrLoading: document.getElementById('waQrLoading'),
  btnRefresh: document.getElementById('btnWARefresh'),
  btnReconnect: document.getElementById('btnWAReconnect'),
  btnLogout: document.getElementById('btnWALogout'),
  btnGen: document.getElementById('btnWAGenerate'),
};

function setPill(state){
  el.pill.classList.remove('ok','warn','bad');
  if(state==='connected'){ el.pill.textContent='Conectado'; el.pill.classList.add('ok'); }
  else if(state==='pairing'){ el.pill.textContent='Aguardando leitura do QR'; el.pill.classList.add('warn'); }
  else if(state==='connecting'){ el.pill.textContent='Conectando…'; el.pill.classList.add('warn'); }
  else { el.pill.textContent='Desconectado'; el.pill.classList.add('bad'); }
}

async function loadWAStatus(){
  try{
    const r = await api('wa_status');   // backend deve resolver por shop atual
    setPill(r.state || 'disconnected');
    el.me.textContent = r.me ? ('Conectado como: '+r.me) : '';
    el.qrBox.style.display = (r.state==='connected') ? 'none' : 'block';
    if(r.state!=='connected'){
      await generateWAQr({ retries: 24, intervalMs: 1000 });
    }
  }catch(e){
    setPill('disconnected');
    el.me.textContent = '';
    el.qrBox.style.display = 'block';
  }
}

/* QR: tenta PNG (wa_qr_img) e cai para texto (wa_qr_text + QRCode.js) */
async function generateWAQr(opts={}){
  const retries = opts.retries ?? 18;
  const intervalMs = opts.intervalMs ?? 1200;

  const qrCanvas = document.getElementById('waQrCanvas');

  // Reset UI
  el.qrImg.style.display='none';
  el.qrImg.removeAttribute('src');
  if(qrCanvas){ qrCanvas.style.display='none'; qrCanvas.innerHTML=''; }
  el.qrLoading.style.display='block';
  el.qrLoading.textContent = 'Gerando QR…';

  // 1) PNG direto
  const tryPngOnce = () => new Promise((resolve) => {
    const src = 'includes/config_api.php?action=wa_qr_img&ts=' + Date.now();
    el.qrImg.onload = () => {
      el.qrImg.style.display='block';
      el.qrLoading.style.display='none';
      resolve(true);
    };
    el.qrImg.onerror = () => resolve(false);
    el.qrImg.src = src;
  });

  for(let i=0;i<Math.min(retries,3);i++){
    const ok = await tryPngOnce();
    if(ok) return; // PNG funcionou
    el.qrLoading.textContent = 'Aguardando QR do serviço…';
    await new Promise(r=>setTimeout(r, intervalMs));
  }

  // 2) Fallback texto
  for(let i=0;i<retries;i++){
    try{
      const resp = await fetch('includes/config_api.php?action=wa_qr_text&ts='+Date.now());
      if (resp.status === 204) {
        // já conectado
        el.qrLoading.style.display='none';
        el.qrImg.style.display='none';
        if(qrCanvas) qrCanvas.style.display='none';
        return;
      }
      if (resp.ok) {
        const data = await resp.json();
        if (data && data.qr) {
          if (qrCanvas) {
            qrCanvas.style.display='block';
            el.qrLoading.style.display='none';
            new QRCode(qrCanvas, { text: data.qr, width: 300, height: 300, correctLevel: QRCode.CorrectLevel.M });
            return;
          }
        }
      }
    }catch(_e){ /* ignora e repete */ }

    el.qrLoading.textContent = 'Aguardando QR do serviço…';
    await new Promise(r=>setTimeout(r, intervalMs));
  }

  el.qrLoading.textContent = 'QR ainda indisponível. Use “Reconectar” ou “Desconectar” para reiniciar.';
}

el.btnRefresh?.addEventListener('click', loadWAStatus);

el.btnReconnect?.addEventListener('click', async ()=>{
  try{
    await api('wa_reconnect');
    toast('Reiniciando sessão…');
    setPill('connecting'); el.me.textContent=''; el.qrBox.style.display='block';
    await generateWAQr({ retries: 24, intervalMs: 1000 });
  }catch(e){ toast(e.message,false); }
});

el.btnLogout?.addEventListener('click', async ()=>{
  if(!confirm('Desconectar este WhatsApp e gerar um novo QR?')) return;
  try{
    await api('wa_reset_auth');
    toast('Sessão zerada. Gerando novo QR…');
    setPill('connecting'); el.me.textContent=''; el.qrBox.style.display='block';
    await generateWAQr({ retries: 30, intervalMs: 1000 });
  }catch(e){ toast(e.message,false); }
});

el.btnGen?.addEventListener('click', ()=>{
  generateWAQr({ retries: 24, intervalMs: 1000 });
});
</script>
</body>
</html>
