<?php
// equipe.php
declare(strict_types=1);

// 1) Autenticação / contexto
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

init_session();

// Garante login e tenant
if (!function_exists('require_auth') || !function_exists('require_tenant')) {
  http_response_code(500);
  echo 'auth_guard.php incompleto.';
  exit;
}

$user_id   = require_auth();
$tenant_id = require_tenant();
$shop_id   = current_shop_id();

// Carrega usuário logado do banco
$stmt = $conn->prepare("
  SELECT id, tenant_id, shop_id, role, nome, email, handle, is_active
  FROM login
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$currentUser = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (
  !$currentUser ||
  (int)$currentUser['tenant_id'] !== (int)$tenant_id ||
  !(int)$currentUser['is_active']
) {
  // Sessão inválida pro tenant atual
  destroy_session();
  header('Location: login.php');
  exit;
}

// Só ADMIN acessa essa página
if (($currentUser['role'] ?? '') !== 'admin') {
  if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
  ) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado. Apenas administradores.'], JSON_UNESCAPED_UNICODE);
  } else {
    http_response_code(403);
    echo 'Acesso negado. Apenas administradores.';
  }
  exit;
}

/* ───────────────────────── Helpers locais ───────────────────────── */

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function jok_json(array $arr): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * Valida e normaliza o handle (username):
 * - login via "@handle" (sem @ salvo)
 * - 3-30 chars, [a-z0-9._-], começa com letra/número
 * - globalmente único (checado antes de inserir)
 */
function validar_handle(string $handle): string {
  $h = trim($handle);

  // Aceita com @ digitado
  if ($h !== '' && $h[0] === '@') {
    $h = substr($h, 1);
  }

  $h = strtolower($h);

  if (!preg_match('~^[a-z0-9][a-z0-9._-]{2,30}$~', $h)) {
    throw new RuntimeException('Usuário deve ter de 3 a 30 caracteres, começar com letra/número e pode usar . _ -');
  }

  $reservados = ['admin','root','system','suporte','support','owner'];
  if (in_array($h, $reservados, true)) {
    throw new RuntimeException('Este nome de usuário não está disponível.');
  }

  return $h;
}

/* ───────────────────────── Ações POST (AJAX) ───────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // Todas as ações aqui são só para admin (já validado acima, mas garantimos)
  if (($currentUser['role'] ?? '') !== 'admin') {
    jok_json(['ok' => false, 'error' => 'Acesso negado.']);
  }

  // Criar membro
  if ($action === 'create_member') {
    try {
      $nome      = trim((string)($_POST['nome'] ?? ''));
      $handleRaw = trim((string)($_POST['handle'] ?? ''));
      $senha     = (string)($_POST['senha'] ?? '');
      $senha2    = (string)($_POST['senha2'] ?? '');
      $tel       = trim((string)($_POST['telefone'] ?? ''));

      if ($nome === '' || $handleRaw === '' || $senha === '') {
        throw new RuntimeException('Preencha nome, usuário e senha.');
      }
      if ($senha !== $senha2) {
        throw new RuntimeException('As senhas não conferem.');
      }
      if (strlen($senha) < 4) {
        throw new RuntimeException('Senha muito curta (mínimo 4 caracteres).');
      }

      $handle = validar_handle($handleRaw);

      // Garante unicidade global do handle
      $st = $conn->prepare("SELECT id FROM login WHERE handle = ? LIMIT 1");
      $st->bind_param('s', $handle);
      $st->execute();
      $st->store_result();
      if ($st->num_rows > 0) {
        $st->close();
        throw new RuntimeException('Este nome de usuário já está em uso.');
      }
      $st->close();

      $hash = password_hash($senha, PASSWORD_BCRYPT);

      // Admin quer membros sem login por e-mail (somente @usuario)
      $email = null;

      $st = $conn->prepare("
        INSERT INTO login (tenant_id, shop_id, role, nome, email, handle, telefone, password_hash, is_active)
        VALUES (?, ?, 'member', ?, ?, ?, ?, ?, 1)
      ");
      $st->bind_param(
        'iisssss',
        $tenant_id,
        $shop_id,
        $nome,
        $email,
        $handle,
        $tel,
        $hash
      );
      $st->execute();
      $st->close();

      jok_json(['ok' => true, 'msg' => 'Membro criado com sucesso.']);
    } catch (Throwable $e) {
      jok_json(['ok' => false, 'error' => $e->getMessage()]);
    }
  }

  // Ativar / desativar membro
  if ($action === 'toggle_active') {
    try {
      $id  = (int)($_POST['id'] ?? 0);
      $val = (int)($_POST['value'] ?? 0);
      $val = $val ? 1 : 0;

      if ($id <= 0) throw new RuntimeException('ID inválido.');

      $st = $conn->prepare("
        UPDATE login
           SET is_active = ?
         WHERE id = ?
           AND tenant_id = ?
           AND role = 'member'
      ");
      $st->bind_param('iii', $val, $id, $tenant_id);
      $st->execute();
      if ($st->affected_rows === 0) {
        $st->close();
        throw new RuntimeException('Membro não encontrado ou não pertence ao seu tenant.');
      }
      $st->close();

      jok_json(['ok' => true, 'value' => $val]);
    } catch (Throwable $e) {
      jok_json(['ok' => false, 'error' => $e->getMessage()]);
    }
  }

  // Reset de senha
  if ($action === 'reset_password') {
    try {
      $id    = (int)($_POST['id'] ?? 0);
      $senha = (string)($_POST['senha'] ?? '');
      $conf  = (string)($_POST['senha2'] ?? '');

      if ($id <= 0 || $senha === '' || $conf === '') {
        throw new RuntimeException('Informe ID e senha.');
      }
      if ($senha !== $conf) {
        throw new RuntimeException('As senhas não conferem.');
      }
      if (strlen($senha) < 4) {
        throw new RuntimeException('Senha muito curta (mínimo 4 caracteres).');
      }

      $hash = password_hash($senha, PASSWORD_BCRYPT);

      $st = $conn->prepare("
        UPDATE login
           SET password_hash = ?
         WHERE id = ?
           AND tenant_id = ?
           AND role = 'member'
      ");
      $st->bind_param('sii', $hash, $id, $tenant_id);
      $st->execute();
      if ($st->affected_rows === 0) {
        $st->close();
        throw new RuntimeException('Membro não encontrado ou não pertence ao seu tenant.');
      }
      $st->close();

      jok_json(['ok' => true, 'msg' => 'Senha atualizada.']);
    } catch (Throwable $e) {
      jok_json(['ok' => false, 'error' => $e->getMessage()]);
    }
  }

  jok_json(['ok' => false, 'error' => 'Ação inválida.']);
}

/* ───────────────────────── Listagem dos membros ───────────────────────── */

// Lista só membros do tenant atual
$st = $conn->prepare("
  SELECT id, nome, handle, telefone,
         COALESCE(is_active,1) AS is_active,
         COALESCE(shop_id, 0)  AS shop_id
    FROM login
   WHERE tenant_id = ?
     AND role = 'member'
   ORDER BY is_active DESC, nome ASC, id ASC
");
$st->bind_param('i', $tenant_id);
$st->execute();
$res = $st->get_result();
$members = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$st->close();

// Layout
$pagina = 'equipe';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="css/equipe.css">
<body>
<?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="equipe-container">
  <div class="equipe-header">
    <div class="equipe-titulos">
      <h2>Equipe / Membros</h2>
      <p>Gerencie os usuários vinculados ao seu tenant. Somente administradores alteram WhatsApp, templates e financeiro.</p>
    </div>
    <button id="btnNovoMembro" class="btn-novo-membro">
      <span class="plus">+</span>
      <span>Novo membro</span>
    </button>
  </div>

  <div class="card lista-membros">
    <div class="lista-header">
      <h3>Membros cadastrados</h3>
      <p class="hint">Membros fazem login apenas com <strong>@usuário</strong>. Admin faz login com e-mail.</p>
    </div>

    <?php if (empty($members)): ?>
      <p class="vazio">Nenhum membro cadastrado ainda. Clique em “Novo membro” para adicionar.</p>
    <?php else: ?>
      <div class="tabela-membros">
        <div class="t-head">
          <div>ID</div>
          <div>Nome</div>
          <div>Usuário</div>
          <div>Telefone</div>
          <div>Status</div>
          <div>Ações</div>
        </div>
        <?php foreach ($members as $m): ?>
          <div class="t-row" data-id="<?= (int)$m['id'] ?>">
            <div>#<?= (int)$m['id'] ?></div>
            <div><?= e($m['nome'] ?: '-') ?></div>
            <div class="user-handle">@<?= e($m['handle'] ?: '') ?></div>
            <div><?= e($m['telefone'] ?: '-') ?></div>
            <div>
              <span class="badge <?= $m['is_active'] ? 'badge-ativo' : 'badge-inativo' ?>">
                <?= $m['is_active'] ? 'Ativo' : 'Inativo' ?>
              </span>
            </div>
            <div class="acoes">
              <button class="btn-mini btn-toggle"
                      data-active="<?= (int)$m['is_active'] ?>">
                <?= $m['is_active'] ? 'Desativar' : 'Ativar' ?>
              </button>
              <button class="btn-mini btn-reset">Resetar senha</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- MODAL NOVO MEMBRO -->
<div id="modalNovoMembro" class="modal-overlay" style="display:none;">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Novo membro</h3>
      <button class="modal-close" id="fecharModalNovo">&times;</button>
    </div>
    <p class="modal-subtitle">
      O membro fará login usando apenas <strong>@usuário</strong>. 
      O WhatsApp e configurações permanecem do tenant (não são alterados por membros).
    </p>

    <form id="formNovoMembro" autocomplete="off">
      <div class="linha">
        <div class="campo">
          <label>Nome completo</label>
          <input type="text" name="nome" placeholder="Ex: João da Silva" required>
        </div>
      </div>

      <div class="linha">
        <div class="campo">
          <label>Usuário (@login)</label>
          <input type="text" name="handle" placeholder="ex: joaoloja1" required>
          <small>Deve ser único em todo o sistema. Não use espaços.</small>
        </div>
      </div>

      <div class="linha">
        <div class="campo">
          <label>Telefone (opcional)</label>
          <input type="text" name="telefone" placeholder="(00) 00000-0000">
        </div>
      </div>

      <div class="linha">
        <div class="campo">
          <label>Senha</label>
          <input type="password" name="senha" minlength="4" required>
        </div>
        <div class="campo">
          <label>Confirmar senha</label>
          <input type="password" name="senha2" minlength="4" required>
        </div>
      </div>

      <div id="novoMembroMsg" class="msg-retorno" hidden></div>

      <div class="modal-actions">
        <button type="button" class="btn-cancelar" id="cancelarNovoMembro">Cancelar</button>
        <button type="submit" class="btn-primario">Salvar membro</button>
      </div>
    </form>
  </div>
</div>

<script>
// --- Modal Novo Membro ---
const btnNovo = document.getElementById('btnNovoMembro');
const modalNovo = document.getElementById('modalNovoMembro');
const fecharModalNovo = document.getElementById('fecharModalNovo');
const cancelarNovoMembro = document.getElementById('cancelarNovoMembro');
const formNovo = document.getElementById('formNovoMembro');
const msgNovo  = document.getElementById('novoMembroMsg');

function openModalNovo() {
  if (!modalNovo) return;
  modalNovo.style.display = 'flex';
}
function closeModalNovo() {
  if (!modalNovo) return;
  modalNovo.style.display = 'none';
  if (formNovo) formNovo.reset();
  if (msgNovo) { msgNovo.hidden = true; msgNovo.textContent = ''; }
}

btnNovo?.addEventListener('click', openModalNovo);
fecharModalNovo?.addEventListener('click', closeModalNovo);
cancelarNovoMembro?.addEventListener('click', closeModalNovo);
modalNovo?.addEventListener('click', (e) => {
  if (e.target === modalNovo) closeModalNovo();
});

function showMsg(elem, ok, text) {
  if (!elem) return;
  elem.textContent = text;
  elem.className = 'msg-retorno ' + (ok ? 'ok' : 'erro');
  elem.hidden = false;
}

// Criação de membro (AJAX)
formNovo?.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!msgNovo) return;

  msgNovo.hidden = true;

  const fd = new FormData(formNovo);
  fd.append('action', 'create_member');

  try {
    const res = await fetch('equipe.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const j = await res.json();
    if (!j.ok) {
      showMsg(msgNovo, false, j.error || 'Erro ao criar membro.');
      return;
    }
    showMsg(msgNovo, true, j.msg || 'Membro criado.');
    setTimeout(() => { window.location.reload(); }, 700);
  } catch (err) {
    console.error(err);
    showMsg(msgNovo, false, 'Falha na requisição.');
  }
});

// Toggle ativo/inativo
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-toggle');
  if (!btn) return;

  const row = btn.closest('.t-row');
  if (!row) return;
  const id = row.dataset.id;

  const current = parseInt(btn.dataset.active || '0', 10);
  const next = current ? 0 : 1;

  if (!confirm(`Você deseja ${next ? 'ativar' : 'desativar'} este membro?`)) {
    return;
  }

  const fd = new FormData();
  fd.append('action', 'toggle_active');
  fd.append('id', id);
  fd.append('value', String(next));

  try {
    const res = await fetch('equipe.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const j = await res.json();
    if (!j.ok) {
      alert(j.error || 'Erro ao atualizar status.');
      return;
    }

    btn.dataset.active = String(next);
    btn.textContent = next ? 'Desativar' : 'Ativar';

    const badge = row.querySelector('.badge');
    if (badge) {
      badge.textContent = next ? 'Ativo' : 'Inativo';
      badge.classList.toggle('badge-ativo', !!next);
      badge.classList.toggle('badge-inativo', !next);
    }
  } catch (err) {
    console.error(err);
    alert('Falha na requisição.');
  }
});

// Reset senha
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-reset');
  if (!btn) return;

  const row = btn.closest('.t-row');
  if (!row) return;
  const id = row.dataset.id;
  const handle = row.querySelector('.user-handle')?.textContent || '';

  const nova = prompt(`Nova senha para ${handle}:\n(mínimo 4 caracteres)`);
  if (nova === null) return;
  if (nova.length < 4) {
    alert('Senha muito curta.');
    return;
  }
  const conf = prompt('Confirme a nova senha:');
  if (conf === null || conf !== nova) {
    alert('As senhas não conferem.');
    return;
  }

  const fd = new FormData();
  fd.append('action', 'reset_password');
  fd.append('id', id);
  fd.append('senha', nova);
  fd.append('senha2', conf);

  try {
    const res = await fetch('equipe.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const j = await res.json();
    if (!j.ok) {
      alert(j.error || 'Erro ao alterar senha.');
      return;
    }
    alert('Senha atualizada com sucesso.');
  } catch (err) {
    console.error(err);
    alert('Falha ao comunicar com o servidor.');
  }
});
</script>

</body>
</html>
