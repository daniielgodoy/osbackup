<?php
// index.php
declare(strict_types=1);

// 1) Segurança & sessão (NADA de HTML antes)
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");

// Garante sessão iniciada
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Contexto da sessão
$tenant_id = require_tenant();          // sempre precisa
$shop_id   = current_shop_id();         // pode ser null
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role      = $_SESSION['role'] ?? 'member';

$isAdmin  = ($role === 'admin');
$isMember = ($role === 'member');

if ($user_id <= 0) {
  header('Location: login.php');
  exit;
}

/**
 * WHERE base para todas as consultas:
 * - Sempre filtra tenant
 * - Se tiver shop_id, filtra também
 * - Se for MEMBER:
 *      vê TUDO que está 'pendente' (independente do responsável)
 *      + vê apenas OS onde responsavel_id = ele
 *   → (status='pendente' OR responsavel_id = :user_id)
 * - Se for ADMIN:
 *      vê tudo (dentro do tenant/shop)
 */
// Agora TODOS veem todas as OS da loja/tenant.
// Nenhum filtro por usuário, apenas tenant (+ loja se selecionada).
$whereBase = "tenant_id = ?";
$typesBase = 'i';
$paramsBase = [$tenant_id];

if (!empty($shop_id)) {
  $whereBase .= " AND shop_id = ?";
  $typesBase .= 'i';
  $paramsBase[] = $shop_id;
}


// Helper para bind dinâmico
function stmt_bind(mysqli_stmt $stmt, string $types, array $params): void {
  if ($params) {
    $stmt->bind_param($types, ...$params);
  }
}

// 3) Contexto da página (antes da navbar para destacar menu ativo)
$pagina = 'painel';

// 4) Agora pode emitir HTML
include_once __DIR__ . '/includes/header.php';
?>
<body>
<?php include_once __DIR__ . '/includes/navbar.php'; ?>

<div class="content">
<div class="dashboard-header">
  <h2>Dashboard</h2>

</div>


  <div class="grid-layout">
    <!-- COLUNA PRINCIPAL -->
    <div class="main-column">
      <div class="cards-grid">
<?php
// Data atual
$hoje = date('Y-m-d');

/*
  Regras:

  Base (WHERE):
    - Sempre: tenant_id [+ shop_id se tiver]
    - Se MEMBER: (status='pendente' OR responsavel_id = user_id)
    - Se ADMIN: tudo do tenant[/shop]

  Cards:
    - andamento, pendente, orcamento → seguem base
    - aguardando & concluidos → apenas se (data_entrada = hoje OU atualizado_em = hoje), respeitando base
*/

$sqlResumo = "
  SELECT
    SUM(status = 'em_andamento') AS andamento,
    SUM(status = 'pendente')     AS pendente,
    SUM(status = 'orcamento')    AS orcamento,

    SUM(
      status = 'aguardando_retirada'
      AND (DATE(data_entrada) = ? OR DATE(COALESCE(atualizado_em,'0000-00-00')) = ?)
    ) AS aguardando,

    SUM(
      status = 'concluido'
      AND (DATE(data_entrada) = ? OR DATE(COALESCE(atualizado_em,'0000-00-00')) = ?)
    ) AS concluidos,

    COUNT(*) AS total
  FROM ordens_servico
  WHERE $whereBase
";

$types = 'ssss' . $typesBase;
$params = [$hoje, $hoje, $hoje, $hoje, ...$paramsBase];

$stmt = $conn->prepare($sqlResumo);
stmt_bind($stmt, $types, $params);
$stmt->execute();
$resumo = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$andamento  = (int)($resumo['andamento']  ?? 0);
$concluidos = (int)($resumo['concluidos'] ?? 0);
$pendente   = (int)($resumo['pendente']   ?? 0);
$orcamento  = (int)($resumo['orcamento']  ?? 0);
$aguardando = (int)($resumo['aguardando'] ?? 0);
$total      = (int)($resumo['total']      ?? 0);
?>
        <div class="card yellow" id="card-pendente">
          <h4>Pendentes</h4>
          <h2><?= $pendente ?></h2>
          <p><?= $isMember ? 'Compartilhados na loja' : 'Hoje' ?></p>
          <i class="fa fa-times-circle icon"></i>
        </div>

        <div class="card orange" id="card-andamento">
          <h4>Serviços</h4>
          <h2><?= $andamento ?></h2>
          <p>Em andamento</p>
          <i class="fa fa-hourglass-half icon"></i>
        </div>

        <div class="card cyan" id="card-orcamento">
          <h4>Aguardando</h4>
          <h2><?= $orcamento ?></h2>
          <p>Orçamento</p>
          <i class="fa fa-check icon"></i>
        </div>

        <div class="card green" id="card-aguardando">
          <h4>Serviços</h4>
          <h2><?= $aguardando ?></h2>
          <p>Aguardando retirada (hoje)</p>
          <i class="fa fa-hourglass icon"></i>
        </div>

        <div class="card white" id="card-concluidos">
          <h4>Serviços</h4>
          <h2><?= $concluidos ?></h2>
          <p>Concluídos (hoje)</p>
          <i class="fa fa-check icon"></i>
        </div>

      </div>
<?php if ($isMember): ?>

<?php
// Feed de últimas atualizações para colaboradores (apenas desta loja/tenant)

// verifica se a tabela de logs existe
$hasLogs = false;
if ($res = $conn->query("SHOW TABLES LIKE 'ordens_servico_logs'")) {
    $hasLogs = $res->num_rows > 0;
    $res->free();
}

$feedRows = [];

if ($hasLogs) {
$sqlFeed = "
  SELECT
    l.os_id,
    l.status_novo,
    l.metodo_pagamento,
    l.valor_total,
    l.criado_em,
    u.nome AS usuario_nome
  FROM ordens_servico_logs l
  LEFT JOIN login u ON u.id = l.user_id
  WHERE l.tenant_id = ?
    AND l.acao = 'atualizar_status'
    AND DATE(l.criado_em) = CURDATE()
";


    $typesFeed  = 'i';
    $paramsFeed = [$tenant_id];

    if (!empty($shop_id)) {
        $sqlFeed   .= " AND l.shop_id = ?";
        $typesFeed .= 'i';
        $paramsFeed[] = $shop_id;
    }

    $sqlFeed .= " ORDER BY l.criado_em DESC LIMIT 30";

    $stmtFeed = $conn->prepare($sqlFeed);
    stmt_bind($stmtFeed, $typesFeed, $paramsFeed);
    $stmtFeed->execute();
    $feed = $stmtFeed->get_result();

    while ($row = $feed->fetch_assoc()) {
        $feedRows[] = $row;
    }

    $stmtFeed->close();
}

// label bonitinho pro status
function status_label(string $s): string {
    $map = [
        'pendente'            => 'Pendente',
        'em_andamento'        => 'Em andamento',
        'concluido'           => 'Concluído',
        'cancelado'           => 'Cancelado',
        'orcamento'           => 'Orçamento',
        'aguardando_retirada' => 'Aguardando retirada',
    ];
    return $map[$s] ?? ucfirst(str_replace('_',' ',$s));
}
?>

<div class="card feed-card">
  <h4>
    <i class="fa fa-history"></i>
    Últimas atualizações de hoje
  </h4>

  <ul class="feed-list">
    <?php if ($hasLogs && !empty($feedRows)): ?>
      <?php foreach ($feedRows as $f):
        $hora   = date('H:i', strtotime($f['criado_em']));
        $status = status_label((string)$f['status_novo']);
        $user   = $f['usuario_nome'] ?: 'Sistema';
        $osId   = (int)$f['os_id'];
      ?>
        <li>
          <span class="bullet"></span>
          <div class="feed-text">
            <?= htmlspecialchars("OS {$osId} mudou para {$status} por {$user} às {$hora}", ENT_QUOTES, 'UTF-8') ?>
          </div>
        </li>
      <?php endforeach; ?>
    <?php else: ?>
      <li class="feed-empty">
        Sem movimentações hoje ainda. Assim que alguém alterar o status de uma OS, aparece aqui. 🙂
      </li>
    <?php endif; ?>
  </ul>
</div>

<?php endif; // $isMember ?>

<?php if ($isAdmin): ?>
  <div class="charts-four full-width" id="chartsMeiosPagamento">
    <div class="chart-box">
      <h3>PIX — Ontem x Hoje</h3>
      <canvas id="piePix"></canvas>
    </div>
    <div class="chart-box">
      <h3>Dinheiro — Ontem x Hoje</h3>
      <canvas id="pieDinheiroPg"></canvas>
    </div>
    <div class="chart-box">
      <h3>Crédito — Ontem x Hoje</h3>
      <canvas id="pieCredito"></canvas>
    </div>
      <div class="chart-box">
      <h3>Débito — Ontem x Hoje</h3>
      <canvas id="pieDebito"></canvas>
    </div>
  </div>
<?php endif; ?>

    </div>

    <!-- COLUNA LATERAL -->
    <div class="side-column">
      <div class="servicos-card">
        <div class="servicos-header">
          <h3>
            <i class="fa fa-wrench icon"></i> Serviços Diários —
<?php
// Contador lateral com mesmo filtro base
$sqlCnt = "
  SELECT COUNT(*) AS total
  FROM ordens_servico
  WHERE $whereBase
    AND (
      status IN ('em_andamento','orcamento','pendente')   -- ativos (todas as datas)
      OR DATE(data_entrada) = ?                           -- demais, só hoje
      OR DATE(COALESCE(atualizado_em,'0000-00-00')) = ?
    )
";
$typesCnt = $typesBase . 'ss';
$paramsCnt = [...$paramsBase, $hoje, $hoje];

$stmtCnt = $conn->prepare($sqlCnt);
stmt_bind($stmtCnt, $typesCnt, $paramsCnt);
$stmtCnt->execute();
$rowCnt = $stmtCnt->get_result()->fetch_assoc() ?: [];
$stmtCnt->close();

$qtdHoje = (int)($rowCnt['total'] ?? 0);
echo "<span class='contador-diario'>{$qtdHoje}</span>";
?>
          </h3>
          <button class="add-servico-btn" id="addServicoBtn">
            <i class="fa fa-plus"></i>
          </button>
        </div>

        <div id="servicos" class="servicos-scroll">
<?php
// Lista lateral: aplica base + mesmas regras de datas
$sqlLista = "
  SELECT
    id, nome, modelo, servico, status,
    metodo_pagamento,
    valor_dinheiro_pix,
    valor_cartao,
    valor_total
  FROM ordens_servico
  WHERE $whereBase
    AND (
      status IN ('em_andamento','orcamento','pendente')   -- sempre entram
      OR DATE(data_entrada) = ?                           -- demais só hoje
      OR DATE(COALESCE(atualizado_em,'0000-00-00')) = ?
    )
  ORDER BY
    (DATE(COALESCE(atualizado_em,'0000-00-00')) = ?) DESC,
    COALESCE(atualizado_em, data_entrada) DESC,
    id DESC
";

$typesLista = $typesBase . 'sss';
$paramsLista = [...$paramsBase, $hoje, $hoje, $hoje];

$stmt = $conn->prepare($sqlLista);
stmt_bind($stmt, $typesLista, $paramsLista);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
echo '<div class="servicos-header-list">';
echo '  <span class="col-id">OS</span>';
echo '  <span class="col-nome">Nome</span>';
echo '  <span class="col-modelo">Modelo</span>';
echo '  <span class="col-servico">Serviço</span>';
echo '  <span class="col-status">Status</span>';
echo '  <span class="col-acoes">Imp.</span>';
echo '</div>';


  echo '<ul class="lista-servicos">';
  while ($row = $result->fetch_assoc()) {
    $valDin = (float)($row['valor_dinheiro_pix'] ?? 0);
    $valCar = (float)($row['valor_cartao'] ?? 0);
    $valTot = (float)($row['valor_total'] ?? 0);

    if ($valDin > 0 && $valCar == 0) {
      $valorTotal = $valDin;
    } elseif ($valCar > 0 && $valDin == 0) {
      $valorTotal = $valCar;
    } elseif ($valCar > 0 && $valDin > 0) {
      $valorTotal = $valDin + $valCar;
    } else {
      $valorTotal = $valTot;
    }

    $statusAtual = $row['status'] ?? 'pendente';
    switch ($statusAtual) {
      case 'pendente':             $classeStatus = 'status-pendente';  break;
      case 'em_andamento':         $classeStatus = 'status-andamento'; break;
      case 'concluido':            $classeStatus = 'status-concluido'; break;
      case 'cancelado':            $classeStatus = 'status-cancelado'; break;
      case 'orcamento':            $classeStatus = 'status-orcamento'; break;
      case 'aguardando_retirada':  $classeStatus = 'status-retirada';  break;
      default:                     $classeStatus = 'status-desconhecido';
    }

echo '<li class="' . $classeStatus . '" 
  data-id="' . (int)$row['id'] . '" 
  data-valor_dinheiro_pix="' . htmlspecialchars((string)$valDin, ENT_QUOTES, 'UTF-8') . '" 
  data-valor_cartao="' . htmlspecialchars((string)$valCar, ENT_QUOTES, 'UTF-8') . '">';

echo '  <span class="os-id">' . (int)$row['id'] . '</span>';
echo '  <span class="os-nome">' . htmlspecialchars($row['nome'] ?? '', ENT_QUOTES, 'UTF-8') . '</span>';
echo '  <span class="os-modelo">' . htmlspecialchars($row['modelo'] ?? '', ENT_QUOTES, 'UTF-8') . '</span>';
echo '  <span class="os-servico">' . htmlspecialchars($row['servico'] ?? '', ENT_QUOTES, 'UTF-8') . '</span>';

echo '  <select class="status-select" data-id="' . (int)$row['id'] . '">';
$statuses = [
  'pendente'             => 'Pendente',
  'em_andamento'         => 'Em andamento',
  'concluido'            => 'Concluído',
  'cancelado'            => 'Cancelado',
  'orcamento'            => 'Orçamento',
  'aguardando_retirada'  => 'Aguardando retirada'
];
foreach ($statuses as $key => $label) {
  $selected = ($statusAtual === $key) ? 'selected' : '';
  echo "<option value=\"{$key}\" {$selected}>{$label}</option>";
}
echo '  </select>';

// 🔹 Botão de impressão ao lado da OS
echo '  <button type="button" class="os-print-btn" title="Imprimir OS">';
echo '    <i class="fa fa-print"></i>';
echo '  </button>';

echo '</li>';

  }
  echo '</ul>';
} else {
  echo '<p>Nenhum serviço listado.</p>';
}
$stmt->close();
?>
        </div>
      </div>

<?php if ($isAdmin): ?>
  <div class="card financeiro" id="resumoFinanceiro">
    <h4><i class="fa fa-wallet"></i>
      Resumo Financeiro (Diário)
    </h4>
    <h2 id="totalPago">R$ 0,00</h2>
    <div class="sub-valores">
      <div class="campo">
        <span class="label"><i class="fa-brands fa-pix"></i> PIX</span>
        <span id="valorPix">R$ 0,00</span>
      </div>
      <div class="campo">
        <span class="label">💵 Dinheiro</span>
        <span id="valorDinheiro">R$ 0,00</span>
      </div>
      <div class="campo">
        <span class="label">💳 Crédito</span>
        <span id="valorCredito">R$ 0,00</span>
      </div>
      <div class="campo">
        <span class="label">💳 Débito</span>
        <span id="valorDebito">R$ 0,00</span>
      </div>
    </div>
  </div>
<?php endif; ?>

    </div>

  </div>

  <!-- MODAL ORDEM DE SERVIÇO (mantido, mas salvar_os.php deve setar responsavel_id = $user_id) -->
  <div id="modalAddServico" class="modal">
    <div class="modal-content">
      <span class="close-btn" id="closeModal">&times;</span>

      <div class="os-header">
        <span class="os-numero">Ordem Nº ______</span>
        <h2>Ordem de Serviço</h2>
        <p>Fica neste ato o cliente responsável civil e criminalmente pela procedência do aparelho.</p>
        <p>Não nos responsabilizamos por cartões ou chips deixados no aparelho.</p>
      </div>

      <form id="formAddServico" method="POST" action="includes/salvar_os.php">
        <input type="hidden" name="client_id" id="client_id">
        <!-- (todo o formulário igual ao seu atual, não alterei campos) -->
        <!-- ... [mantido exatamente como você já tem acima] ... -->


        <div class="form-row">
          <!-- NOME com autocomplete -->
          <div class="form-group" style="position:relative">
            <label>Nome:</label>
            <input type="text" name="nome" id="cli_nome" autocomplete="off" placeholder="Digite o nome" required>
            <div class="auto-list" id="auto_nome" hidden></div>
          </div>

          <div class="form-group">
            <label>Telefone:</label>
            <input type="text" name="telefone" id="cli_telefone" placeholder="(XX) XXXXX-XXXX" required>
          </div>

          <!-- CPF com autocomplete -->
          <div class="form-group" style="position:relative">
            <label>CPF:</label>
            <input type="text" name="cpf" id="cli_cpf" autocomplete="off" placeholder="000.000.000-00">
            <div class="auto-list" id="auto_cpf" hidden></div>
          </div>
        </div>

        <!-- 🧩 CAMPOS DE ENDEREÇO DETALHADOS -->
        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label>CEP:</label>
            <input type="text" name="cep" id="cli_cep" maxlength="9" placeholder="00000-000">
          </div>
          <div class="form-group" style="flex:2">
            <label>Logradouro:</label>
            <input type="text" name="logradouro" id="cli_logradouro" placeholder="Rua / Avenida">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label>Número:</label>
            <input type="text" name="numero" id="cli_numero" placeholder="Nº">
          </div>
          <div class="form-group" style="flex:2">
            <label>Bairro:</label>
            <input type="text" name="bairro" id="cli_bairro" placeholder="Bairro">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:2">
            <label>Cidade:</label>
            <input type="text" name="cidade" id="cli_cidade" placeholder="Cidade">
          </div>
          <div class="form-group" style="flex:1">
            <label>UF:</label>
            <input type="text" name="uf" id="cli_uf" maxlength="2" placeholder="UF">
          </div>
        </div>

        <!-- Campo oculto que vai armazenar o endereço completo montado automaticamente -->
        <input type="hidden" name="endereco" id="cli_endereco">

        <div class="form-row">
          <div class="form-group">
            <label>Modelo do aparelho:</label>
            <input type="text" name="modelo" placeholder="Ex: Galaxy A32, iPhone 11" required>
          </div>
          <div class="form-group">
            <label>Serviço prestado:</label>
            <input type="text" name="servico" placeholder="Ex: Troca de tela, bateria, etc." required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Data de entrada:</label>
            <input type="date" name="data" required>
          </div>
          <div class="form-group">
            <label>Hora de entrada:</label>
            <input type="time" name="hora" required>
          </div>
        </div>

        <div class="form-group">
          <label>Observações:</label>
          <textarea name="observacao" rows="3" placeholder="Detalhes adicionais..."></textarea>
        </div>

        <!-- 💰 Campos de valor separados -->
        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label>💵 Valor Dinheiro / Pix (R$)</label>
            <input type="number" name="valor_dinheiro_pix" step="0.01" placeholder="0.00" inputmode="decimal">
          </div>
          <div class="form-group" style="flex:1">
            <label>💳 Valor Cartão (R$)</label>
            <input type="number" name="valor_cartao" step="0.01" placeholder="0.00" inputmode="decimal">
          </div>
        </div>

        <!-- Ocultos: valor real e tipo -->
        <input type="hidden" name="valor_total" id="valor_total_hidden">
        <input type="hidden" name="metodo_pagamento" id="metodo_pagamento_hidden">

        <!-- CAMPO SENHA: PADRÃO + SENHA ESCRITA + CHECKBOXES -->
        <div class="form-group senha-linha">
          <div class="senha-row">
            <label for="senhaEscrita">Senha:</label>
            <canvas id="patternCanvas" width="90" height="90"></canvas>
            <input type="text" id="senhaEscrita" name="senha_escrita" placeholder="Senha escrita (se houver)">

            <!-- Checkboxes elegantes -->
            <div class="senha-checks">
              <label class="check-elegante">
                <input type="checkbox" name="pagamento" value="retirada" checked>
                <span class="checkmark"></span>
                Pagamento na retirada
              </label>
              <label class="check-elegante">
                <input type="checkbox" name="pagamento" value="entrada">
                <span class="checkmark"></span>
                Pagamento na entrada
              </label>
            </div>
          </div>

          <input type="hidden" name="senha_padrao" id="senhaPadrao">
        </div>

        <p class="aviso-vermelho">
          Aparelho só será liberado mediante apresentação desta ordem de serviço.
        </p>

        <p class="aviso-multa">
          Caso o aparelho não seja retirado em até 30 (trinta) dias após a conclusão do serviço, será cobrada multa de 1% (um por cento) ao dia sobre o valor total do serviço, conforme o Artigo 408 do Código Civil Brasileiro, que prevê a incidência de penalidade em caso de descumprimento de obrigação por parte do devedor.
        </p>

        <p class="assinatura">
          _______________________________________<br>
          <small>Assinatura do cliente</small>
        </p>

        <button type="submit" class="btn-submit">Salvar OS</button>
      </form>

<script>
// Apenas define o método de pagamento e não soma nada
const campoDinPix = document.querySelector('[name="valor_dinheiro_pix"]');
const campoCartao = document.querySelector('[name="valor_cartao"]');
const campoTotal  = document.getElementById('valor_total_hidden');
const campoMetodo = document.getElementById('metodo_pagamento_hidden');

function definirMetodoPagamento() {
  const vDin = parseFloat(campoDinPix.value) || 0;
  const vCar = parseFloat(campoCartao.value) || 0;

  if (vDin > 0 && vCar === 0) {
    campoTotal.value = vDin.toFixed(2);
  } else if (vCar > 0 && vDin === 0) {
    campoTotal.value = vCar.toFixed(2);
  } else if (vDin > 0 && vCar > 0) {
    campoTotal.value = (vDin + vCar).toFixed(2);
  } else {
    campoTotal.value = '';
  }
  campoMetodo.value = '';
}

[campoDinPix, campoCartao].forEach(c =>
  c.addEventListener('input', definirMetodoPagamento)
);
</script>

    </div>
  </div>

<script>
  const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
  // Permite ativar apenas um checkbox
  const pagamentoCheckboxes = document.querySelectorAll('input[name="pagamento"]');
  pagamentoCheckboxes.forEach(chk => {
    chk.addEventListener('change', () => {
      if (chk.checked) {
        pagamentoCheckboxes.forEach(c => {
          if (c !== chk) c.checked = false;
        });
      }
    });
  });

  // Modal
  const modal = document.getElementById('modalAddServico');
  const openBtn = document.getElementById('addServicoBtn');
  const closeBtn = document.getElementById('closeModal');
  openBtn.addEventListener('click', () => modal.style.display = 'flex');
  closeBtn.addEventListener('click', () => modal.style.display = 'none');
  window.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });

  // Canvas 90x90 com bolinhas menores
  const canvas = document.getElementById("patternCanvas");
  const ctx = canvas.getContext("2d");
  const radius = 4;
  const gridSize = 3;
  const spacing = 30;
  const offset = 15;
  let points = [];
  let path = [];
  let isDrawing = false;

  for (let row = 0; row < gridSize; row++) {
    for (let col = 0; col < gridSize; col++) {
      points.push({
        x: offset + col * spacing,
        y: offset + row * spacing,
        id: row * gridSize + col + 1
      });
    }
  }

  function drawGrid() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.lineWidth = 2;
    ctx.strokeStyle = "#1976d2";
    ctx.beginPath();
    for (let i = 0; i < path.length - 1; i++) {
      const p1 = path[i];
      const p2 = path[i + 1];
      ctx.moveTo(p1.x, p1.y);
      ctx.lineTo(p2.x, p2.y);
    }
    ctx.stroke();

    for (const p of points) {
      ctx.beginPath();
      ctx.arc(p.x, p.y, radius, 0, Math.PI * 2);
      ctx.fillStyle = path.includes(p) ? "#1976d2" : "#888";
      ctx.fill();
    }
  }

  function getPoint(x, y) {
    for (const p of points) {
      const dx = p.x - x;
      const dy = p.y - y;
      if (Math.sqrt(dx * dx + dy * dy) < radius * 3) return p;
    }
    return null;
  }

  canvas.addEventListener("mousedown", e => {
    isDrawing = true;
    path = [];
    const rect = canvas.getBoundingClientRect();
    const p = getPoint(e.clientX - rect.left, e.clientY - rect.top);
    if (p && !path.includes(p)) path.push(p);
    drawGrid();
  });

  canvas.addEventListener("mousemove", e => {
    if (!isDrawing) return;
    const rect = canvas.getBoundingClientRect();
    const p = getPoint(e.clientX - rect.left, e.clientY - rect.top);
    if (p && !path.includes(p)) path.push(p);
    drawGrid();
  });

  canvas.addEventListener("mouseup", () => {
    isDrawing = false;
    document.getElementById("senhaPadrao").value = path.map(p => p.id).join("-");
  });

  drawGrid();

  function abrirModalPagamento({ id, onCancel, onConfirm }) {
    const toNum = v => {
      if (v == null) return 0;
      const s = String(v).trim().replace(/[R$\s]/g,'').replace(/\./g,'').replace(',', '.');
      const n = Number(s);
      return isNaN(n) ? 0 : n;
    };
    const fmtBR = n => new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(n||0));
    const label = n => (Number(n) > 0 ? fmtBR(n) : '—');

    const li = document.querySelector(`li[data-id="${id}"]`) || document.querySelector(`.lista-servicos [data-id="${id}"]`)?.closest('li');
    if (!li) { alert('Não encontrei a OS na lista.'); return; }

    const dinPix = toNum(li.getAttribute('data-valor_dinheiro_pix'));
    const cartao = toNum(li.getAttribute('data-valor_cartao'));

    document.querySelectorAll('.modal-pagamento').forEach(m => m.remove());
    const modal = document.createElement('div');
    modal.className = 'modal-pagamento show';
    modal.innerHTML = `
      <div class="modal-pagamento-content">
        <h2>Finalizar Pagamento</h2>

        <div class="grid-opcoes">
          <button class="opcao" data-tipo="dinheiro">💵 Dinheiro<br><small>${label(dinPix)}</small></button>
          <button class="opcao" data-tipo="pix">🟢 Pix<br><small>${label(dinPix)}</small></button>
          <button class="opcao" data-tipo="credito">💳 Crédito<br><small>${label(cartao)}</small></button>
          <button class="opcao" data-tipo="debito">💳 Débito<br><small>${label(cartao)}</small></button>
          <button class="opcao destaque" data-tipo="misto">🧩 Pagamento misto</button>
        </div>

        <div class="footer-acoes">
          <button class="cancel-btn">Cancelar</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    if (!document.getElementById('__modalPgCSS_v2')) {
      const s = document.createElement('style');
      s.id = '__modalPgCSS_v2';
      s.textContent = `
        .modal-pagamento{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);z-index:9999}
        .modal-pagamento-content{background:#232832;border:1px solid #2e343d;border-radius:12px;padding:18px;width:min(520px,92vw);color:#e8edf2;box-shadow:0 10px 30px rgba(0,0,0,.4)}
        .desc{opacity:.85;margin:6px 0 12px}
        .grid-opcoes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .opcao{padding:14px 12px;border-radius:12px;border:1px solid #2e343d;background:#2b3038;color:#e8edf2;cursor:pointer;text-align:center;font-weight:600}
        .opcao small{display:block;opacity:.8;font-weight:500;margin-top:4px}
        .opcao:hover{outline:1px solid #4db3ff}
        .opcao.destaque{grid-column:1/-1;border-color:#4db3ff}
        .footer-acoes{display:flex;justify-content:flex-end;margin-top:14px}
        .cancel-btn{padding:10px 14px;border-radius:10px;border:1px solid #e05c5c;background:#2b3038;color:#e8edf2;cursor:pointer}
        .linha{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
        .linha input{width:100%;padding:10px;border-radius:10px;border:1px solid #2e343d;background:#1f2329;color:#e8edf2}
        .resumo{margin-top:8px;text-align:center;opacity:.9}
        .acoes{display:flex;justify-content:space-between;margin-top:12px}
        .confirm-btn{padding:10px 14px;border-radius:10px;border:1px solid #43d17c;background:#2b3038;color:#e8edf2;cursor:pointer}
        .back-btn{padding:10px 14px;border-radius:10px;border:1px solid #2e343d;background:#2b3038;color:#e8edf2;cursor:pointer}
      `;
      document.head.appendChild(s);
    }

    const post = (url, data) =>
      fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(data) });

const close = () => {
  modal.remove();
  onCancel && onCancel();
  location.reload(); // ← força recarregar a página ao fechar/cancelar
};
    modal.querySelector('.cancel-btn').onclick = close;
    modal.addEventListener('click', (e)=>{ if (e.target === modal) close(); });

    async function finalizar(tipo, valores = {}) {
      try {
        let metodo = '';
        let valor_total = 0;

        if (tipo === 'dinheiro') {
          if (dinPix <= 0) return alert('Dinheiro/Pix está zerado para esta OS.');
          metodo = 'dinheiro'; valor_total = dinPix;

        } else if (tipo === 'pix') {
          if (dinPix <= 0) return alert('Dinheiro/Pix está zerado para esta OS.');
          metodo = 'pix'; valor_total = dinPix;

        } else if (tipo === 'credito') {
          if (cartao <= 0) return alert('Cartão está zerado para esta OS.');
          metodo = 'credito'; valor_total = cartao;

        } else if (tipo === 'debito') {
          if (cartao <= 0) return alert('Cartão está zerado para esta OS.');
          metodo = 'debito'; valor_total = cartao;

        } else if (tipo === 'misto') {
          const to = v => toNum(v);
          const { pix=0, dinheiro=0, credito=0, debito=0 } = valores;
          const p = to(pix), d = to(dinheiro), c = to(credito), b = to(debito);
          valor_total = +(p + d + c + b).toFixed(2);
          if (valor_total <= 0) return alert('Informe algum valor.');
          metodo = 'misto';
        } else {
          return alert('Tipo inválido.');
        }

        const payload = new URLSearchParams({
          id,
          status: 'concluido',
          tipo_pagamento: tipo === 'misto' ? '' : metodo,
          valor_confirmado: String(valor_total.toFixed(2))
        });

        if (tipo === 'misto') {
          payload.set('pix',      valores.pix      || 0);
          payload.set('dinheiro', valores.dinheiro || 0);
          payload.set('credito',  valores.credito  || 0);
          payload.set('debito',   valores.debito   || 0);
          payload.set('tipo_pagamento', 'misto');
        }

        const resp = await fetch('includes/atualizar_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload
        });
        if (!resp.ok) {
          throw new Error(await resp.text());
        }

        try {
          const dados = await fetch(`includes/get_os.php?id=${id}`, { cache: 'no-store' }).then(r => r.json());
          if (dados?.ok && dados.data) {
            const d = dados.data;
            const li = document.querySelector(`li[data-id="${id}"]`);
            if (li) {
              li.setAttribute('data-valor_dinheiro_pix', ((+d.valor_pix || 0) + (+d.valor_dinheiro || 0)).toFixed(2));
              li.setAttribute('data-valor_cartao', ((+d.valor_credito || 0) + (+d.valor_debito || 0)).toFixed(2));
              const elVal = li.querySelector('.os-valor');
              const novoTotal = parseFloat(d.valor_total || 0);
              if (elVal) {
                elVal.textContent = 'R$ ' + novoTotal.toLocaleString('pt-BR', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                });
              }
            }
          }
        } catch (err) {
          console.error('Erro ao recarregar OS:', err);
        }

        if (tipo === 'misto') {
          const { pix=0, dinheiro=0, credito=0, debito=0 } = valores;
          const sumDinPix = +(toNum(pix)+toNum(dinheiro)).toFixed(2);
          const sumCartao = +(toNum(credito)+toNum(debito)).toFixed(2);
          li.setAttribute('data-valor_dinheiro_pix', sumDinPix.toFixed(2));
          li.setAttribute('data-valor_cartao',       sumCartao.toFixed(2));
        } else if (tipo === 'dinheiro' || tipo === 'pix') {
          li.setAttribute('data-valor_dinheiro_pix', valor_total.toFixed(2));
        } else {
          li.setAttribute('data-valor_cartao', valor_total.toFixed(2));
        }

        const elVal = li.querySelector('.os-valor');
        if (elVal) elVal.textContent = fmtBR(valor_total);
        const select = li.querySelector('.status-select');
        if (select) { select.value = 'concluido'; select.classList.add('flash-success'); setTimeout(()=>select.classList.remove('flash-success'), 600); }
        setTimeout(()=>{ window.atualizarCards?.(); window.atualizarFinanceiro?.(); }, 400);

        modal.remove();
        onConfirm && onConfirm({ tipo_pagamento: metodo, valor_confirmado: valor_total.toFixed(2) });
      } catch (e) {
        console.error(e);
        alert('Erro ao salvar pagamento.');
      }
    }

    modal.querySelectorAll('.opcao').forEach(btn => {
      btn.addEventListener('click', () => {
        const tipo = btn.dataset.tipo;
        if (tipo !== 'misto') return finalizar(tipo);

        const box = modal.querySelector('.modal-pagamento-content');
        box.innerHTML = `
          <h2>Pagamento misto</h2>
          <p class="desc">Distribua os valores:</p>
          <div class="linha">
            <div><label style="opacity:.85">Pix</label><input id="m_pix" type="number" step="0.01" min="0" placeholder="0.00"></div>
            <div><label style="opacity:.85">Dinheiro</label><input id="m_din" type="number" step="0.01" min="0" placeholder="0.00"></div>
          </div>
          <div class="linha">
            <div><label style="opacity:.85">Crédito</label><input id="m_cred" type="number" step="0.01" min="0" placeholder="0.00"></div>
            <div><label style="opacity:.85">Débito</label><input id="m_deb" type="number" step="0.01" min="0" placeholder="0.00"></div>
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
        const upd = () => $res.textContent = `Total: ${fmtBR(toNum($pix.value)+toNum($din.value)+toNum($cred.value)+toNum($deb.value))}`;
        [$pix,$din,$cred,$deb].forEach(i=>i.addEventListener('input',upd)); upd();

        box.querySelector('.back-btn').onclick = () => { modal.remove(); abrirModalPagamento({ id, onCancel, onConfirm }); };
        box.querySelector('.confirm-btn').onclick = () => finalizar('misto', { pix:$pix.value, dinheiro:$din.value, credito:$cred.value, debito:$deb.value });
      });
    });
  }

  // Atualiza cor lateral imediatamente
  document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', function() {
      const li = this.closest('li');
      li.classList.remove('status-pendente', 'status-andamento', 'status-concluido','status-orcamento', 'status-cancelado', 'status-retirada', 'status-desconhecido');
      switch (this.value) {
        case 'pendente': li.classList.add('status-pendente'); break;
        case 'em_andamento': li.classList.add('status-andamento'); break;
        case 'concluido': li.classList.add('status-concluido'); break;
        case 'orcamento': li.classList.add('status-orcamento'); break;
        case 'cancelado': li.classList.add('status-cancelado'); break;
        case 'aguardando_retirada': li.classList.add('status-retirada'); break;
        default: li.classList.add('status-desconhecido');
      }
    });
  });
function atualizarFeed() {
  const ul = document.querySelector('.feed-card .feed-list');
  if (!ul) return;

  fetch('includes/atualizar_feed.php', {
    cache: 'no-store',
    headers: { 'X-Requested-With': 'fetch' }
  })
    .then(res => res.json())
    .then(data => {
      if (!data || !data.ok || typeof data.html !== 'string') return;
      const novo = data.html.trim();
      const atual = ul.innerHTML.trim();
      if (novo && novo !== atual) {
        ul.innerHTML = novo;
      }
    })
    .catch(err => console.error('Erro ao atualizar feed:', err));
}

function atualizarLateral() {
  fetch('includes/atualizar_lateral.php', {
    cache: 'no-store',
    headers: { 'X-Requested-With': 'fetch' }
  })
    .then(res => res.json())
    .then(data => {
      if (!data || !data.ok) return;

      // Atualiza contador "Serviços Diários — X"
      if (typeof data.contador !== 'undefined') {
        const span = document.querySelector('.contador-diario');
        if (span && span.textContent != String(data.contador)) {
          span.textContent = data.contador;
          span.classList.add('flash');
          setTimeout(() => span.classList.remove('flash'), 400);
        }
      }

      // Atualiza a lista da lateral
      if (typeof data.html === 'string') {
        const wrap = document.getElementById('servicos');
        if (wrap) {
          const old = wrap.innerHTML.trim();
          const next = data.html.trim();

          // só troca se realmente mudou, pra não perder seleção à toa
          if (old !== next) {
            wrap.innerHTML = next;
          }
        }
      }
    })
    .catch(err => {
      console.error('Erro ao atualizar lateral:', err);
    });
}

  function atualizarCards() {
    fetch('includes/atualizar_cards.php')
      .then(res => res.json())
      .then(data => {
        const map = {
          'card-pendente': data.pendente,
          'card-andamento': data.andamento,
          'card-orcamento': data.orcamento,
          'card-aguardando': data.aguardando,
          'card-concluidos': data.concluidos
        };
        for (const id in map) {
          const h2 = document.querySelector(`#${id} h2`);
          if (h2 && h2.textContent != map[id]) {
            h2.textContent = map[id];
            h2.classList.add('flash');
            setTimeout(() => h2.classList.remove('flash'), 400);
          }
        }
      })
      .catch(err => console.error('Erro ao atualizar cards:', err));
  }

  document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', () => {
      setTimeout(atualizarCards, 800);
    });
  });

  function atualizarFinanceiro() {
    if (!IS_ADMIN) return;
    fetch('includes/atualizar_financeiro.php')
      .then(res => res.json())
      .then(data => {
        const setVal = (id, val) => {
          const el = document.getElementById(id);
          if (!el) return;
          const novo = `R$ ${val}`;
          if (el.textContent !== novo) {
            el.textContent = novo;
            el.classList.add('flash');
            setTimeout(() => el.classList.remove('flash'), 400);
          }
        };

        const total = document.getElementById('totalPago');
        if (total) {
          const novoTotal = `R$ ${data.total_pago}`;
          if (total.textContent !== novoTotal) {
            total.textContent = novoTotal;
            total.classList.add('flash');
            setTimeout(() => total.classList.remove('flash'), 400);
          }
        }

        setVal('valorPix',      data.total_pix);
        setVal('valorCredito',  data.total_credito);
        setVal('valorDebito',   data.total_debito);
        setVal('valorDinheiro', data.total_dinheiro);
      })
      .catch(err => console.error('Erro ao atualizar financeiro:', err));
  }

setInterval(() => {
  atualizarCards();
  atualizarLateral();
  atualizarFeed();          // <-- adiciona aqui
  if (IS_ADMIN) atualizarFinanceiro();
}, 30000);


window.addEventListener('load', () => {
  atualizarLateral();
  atualizarFeed();          // <-- adiciona aqui
  if (IS_ADMIN) atualizarFinanceiro();
});


<?php if ($isAdmin): ?>
  // =============== GRÁFICOS: HOJE x ONTEM por meio de pagamento (ROSQUINHA) ===============
  (function(){
    if (window.__charts4PagamentosInit) return;
    window.__charts4PagamentosInit = true;

    async function ensureChart(cb){
      if(!window.Chart){
        await (new Promise(r=>{
          const s=document.createElement('script');
          s.src='https://cdn.jsdelivr.net/npm/chart.js';
          s.onload=r; document.head.appendChild(s);
        }));
      }
      const pal = chartPalette();
      Chart.defaults.color = pal.text;
      Chart.defaults.font.size = 12;
      Chart.defaults.borderColor = pal.grid;
      cb && cb();
    }

    const CenterText = {
      id: 'centerText',
      afterDraw(chart, args, opts){
        try{
          const ds = chart.config.data?.datasets?.[0];
          if(!ds) return;
          const {ctx, chartArea} = chart;
          const x = chartArea.left + (chartArea.right - chartArea.left)/2;
          const y = chartArea.top  + (chartArea.bottom - chartArea.top)/2;

          ctx.save();
          ctx.font = (opts?.font) || '600 13px system-ui, -apple-system, "Segoe UI", Roboto';
          ctx.fillStyle = (opts?.color) || chartPalette().text;
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          if (opts?.text) ctx.fillText(opts.text, x, y);
          ctx.restore();
        }catch(e){}
      }
    };

    function getTheme(){
      return (document.documentElement.getAttribute('data-theme') || 'dark');
    }

    function chartPalette(){
      const light = getTheme() === 'light';
      return light ? {
        text:      getComputedStyle(document.documentElement).getPropertyValue('--chart-text').trim() || '#0f172a',
        grid:      getComputedStyle(document.documentElement).getPropertyValue('--chart-grid').trim() || '#d6dce7',
        ontemFill: getComputedStyle(document.documentElement).getPropertyValue('--chart-ontem-fill').trim() || 'rgba(44,120,255,.22)',
        ontemBorder:getComputedStyle(document.documentElement).getPropertyValue('--chart-ontem-border').trim() || '#2c78ff',
        hojeFill:  getComputedStyle(document.documentElement).getPropertyValue('--chart-hoje-fill').trim() || 'rgba(16,185,129,.22)',
        hojeBorder:getComputedStyle(document.documentElement).getPropertyValue('--chart-hoje-border').trim() || '#10b981'
      } : {
        text:'#e8edf2', grid:'#2a2f36',
        ontemFill:'rgba(125,195,255,0.35)', ontemBorder:'#7dc3ff',
        hojeFill:'rgba(110,231,183,0.35)',  hojeBorder:'#6ee7b7'
      };
    }

    const refs = { piePix:null, pieDinheiroPg:null, pieCredito:null, pieDebito:null };
    const fmtBR = (v)=> Number(v||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL', minimumFractionDigits:2});
    const toNum = (v)=> (typeof v==='string')
      ? Number(v.replace(/[R$\s]/g,'').replace(/\./g,'').replace(',', '.')) || 0
      : Number(v||0);

    function destroyIfExists(id){
      const el = document.getElementById(id);
      if(!el) return;
      const inst = (window.Chart && Chart.getChart) ? Chart.getChart(el) : null;
      if (inst) inst.destroy();
      if (refs[id]) { try{ refs[id].destroy(); }catch(_){} refs[id]=null; }
    }

    function renderPie(id, ontem, hoje){
      const pal = chartPalette();
      const el = document.getElementById(id);
      if (!el) return;

      el.style.height = '190px';
      destroyIfExists(id);

      const ontemN = toNum(ontem);
      const hojeN  = toNum(hoje);

      refs[id] = new Chart(el.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: ['Ontem','Hoje'],
          datasets: [{
            data: [ontemN, hojeN],
            backgroundColor: [pal.ontemFill, pal.hojeFill],
            borderColor:     [pal.ontemBorder, pal.hojeBorder],
            borderWidth: 1.2,
            hoverOffset: 3
          }]
        },
        options: {
          responsive:true,
          maintainAspectRatio:false,
          cutout:'72%',
          rotation:-90,
          circumference:360,
          animation:false,
          plugins:{
            legend:{
              position:'bottom',
              labels:{
                boxWidth:10, boxHeight:10, usePointStyle:true, pointStyle:'circle', padding:8,
                font:{size:11, weight:'500'},
                color: pal.text
              }
            },
            tooltip:{ callbacks:{ label:(ctx)=> `${ctx.label}: ${fmtBR(ctx.parsed||0)}` } },
            centerText: { text: `Hoje: ${fmtBR(hojeN)}`, color: pal.text }
          },
          layout:{ padding:{ top:2, right:2, bottom:2, left:2 } }
        },
        plugins:[CenterText]
      });
    }

    async function fetchPagamentos(){
      const url = 'includes/metrics_pagamentos_hoje_ontem.php?t=' + Date.now();
      const res = await fetch(url, { cache:'no-store' });
      if (!res.ok) {
        console.error('[charts] HTTP', res.status, 'em', url);
        throw new Error('HTTP '+res.status);
      }
      const json = await res.json();
      return json;
    }

    async function drawAll(){
      try{
        const d = await fetchPagamentos();
        renderPie('piePix',        d?.pix?.ontem,      d?.pix?.hoje);
        renderPie('pieDinheiroPg', d?.dinheiro?.ontem, d?.dinheiro?.hoje);
        renderPie('pieCredito',    d?.credito?.ontem,  d?.credito?.hoje);
        renderPie('pieDebito',     d?.debito?.ontem,   d?.debito?.hoje);
      }catch(e){
        console.error('[charts] Erro desenhando:', e);
        renderPie('piePix',0,0);
        renderPie('pieDinheiroPg',0,0);
        renderPie('pieCredito',0,0);
        renderPie('pieDebito',0,0);
      }
    }

    const chartsWrap = document.getElementById('chartsMeiosPagamento');
    if (window.ResizeObserver && chartsWrap) {
      const ro = new ResizeObserver(()=> { drawAll(); });
      ro.observe(chartsWrap);
    }

    window.addEventListener('load', function(){ ensureChart(drawAll); });

    if (window.__charts4PagamentosInterval) clearInterval(window.__charts4PagamentosInterval);
    window.__charts4PagamentosInterval = setInterval(drawAll, 20000);

    if (!window.__charts4PagamentosBoundChange) {
      window.__charts4PagamentosBoundChange = true;
      document.addEventListener('change', function(e){
        if (e.target?.classList?.contains('status-select')) setTimeout(drawAll, 600);
      });
    }

    if (!window.__charts4PagamentosPatchedFetch) {
      window.__charts4PagamentosPatchedFetch = true;
      const _fetch = window.fetch;
      window.fetch = function(input, init){
        return _fetch(input, init).then((resp)=>{
          try{
            const url=(typeof input==='string')?input:(input&&input.url)||'';
            if(url.includes('includes/atualizar_status.php') || url.includes('includes/atualizar_os.php')) {
              setTimeout(drawAll, 600);
            }
          }catch(_){}
          return resp;
        });
      };
    }
    const __themeObs = new MutationObserver(() => ensureChart(drawAll));
    __themeObs.observe(document.documentElement, { attributes:true, attributeFilter:['data-theme'] });
  })();
<?php endif; ?>
  /* ========= LISTENER DELEGADO ÚNICO PARA .status-select ========= */
  (function bindStatusSelectOnce(){
    if (window.__boundStatusSelectDashboard) return;
    window.__boundStatusSelectDashboard = true;

    document.addEventListener('change', async function(e){
      const select = e.target?.closest?.('.status-select');
      if (!select) return;

      const id = select.dataset.id;
      const status = select.value;
      const li = select.closest('li');

      if (li) {
        li.classList.remove(
          'status-pendente','status-andamento','status-concluido',
          'status-orcamento','status-cancelado','status-retirada','status-desconhecido'
        );
        li.classList.add(
          status==='pendente'?'status-pendente':
          status==='em_andamento'?'status-andamento':
          status==='concluido'?'status-concluido':
          status==='orcamento'?'status-orcamento':
          status==='cancelado'?'status-cancelado':
          status==='aguardando_retirada'?'status-retirada':'status-desconhecido'
        );
      }

      if (status === 'concluido') {
        abrirModalPagamento({
          id,
          onCancel: () => {
            select.value = 'em_andamento';
          },
          onConfirm: ({ tipo_pagamento, valor_confirmado }) => {
            select.classList.add('flash-success');
            setTimeout(()=> select.classList.remove('flash-success'), 600);

            const elVal = li?.querySelector('.os-valor');
            if (elVal) {
              elVal.textContent = 'R$ ' + Number(valor_confirmado)
                .toLocaleString('pt-BR',{minimumFractionDigits:2, maximumFractionDigits:2});
            }
            setTimeout(()=>{ atualizarCards(); atualizarFinanceiro(); }, 400);
          }
        });
        return;
      }

      try {
        const resp = await fetch('includes/atualizar_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ id, status })
        });
if (!resp.ok) {
  const msg = await resp.text();
  if (msg.includes('atribuída a outro membro') || msg.includes('foi atualizada por outro usuário')) {
    alert(msg);
    location.reload(); // força sincronizar com o estado real
    return;
  }
  throw new Error(msg || 'Erro ao atualizar status.');
}
        select.classList.add('flash-success');
        setTimeout(() => select.classList.remove('flash-success'), 600);

        const dados = await fetch(`includes/get_os.php?id=${id}`, { cache: 'no-store' }).then(r => r.json());
        if (dados?.ok && dados.data && li) {
          const d = dados.data;
          li.setAttribute('data-valor_dinheiro_pix', ((+d.valor_pix || 0) + (+d.valor_dinheiro || 0)).toFixed(2));
          li.setAttribute('data-valor_cartao', ((+d.valor_credito || 0) + (+d.valor_debito || 0)).toFixed(2));

          const elVal = li.querySelector('.os-valor');
          const novoTotal = parseFloat(d.valor_total || 0);
          if (elVal) {
            elVal.textContent = 'R$ ' + novoTotal.toLocaleString('pt-BR', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2
            });
          }
        }

setTimeout(() => {
  atualizarCards();
  atualizarFeed();      // <-- aqui também
  atualizarFinanceiro();
}, 400);

      } catch (err) {
        console.error('❌ Erro ao atualizar status:', err);
        alert('Erro ao atualizar status da OS.');
      }
    }, true);
  })();

  (function(){
    const byId = s => document.getElementById(s);

    const $id   = byId('client_id');
    const $nome = byId('cli_nome');
    const $cpf  = byId('cli_cpf');
    const $tel  = byId('cli_telefone');
    const $end  = byId('cli_endereco');
    const $listCPF = byId('auto_cpf');

    function debounce(fn, ms=220){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; }
    const onlyDigits = s => (s||'').replace(/\D+/g,'');

    async function buscarClientes(q){
      if (!q) return [];
      const url = new URL('./includes/buscar_clientes.php', window.location);
      url.searchParams.set('q', q);
      url.searchParams.set('por', 'cpf');
      const r = await fetch(url, {cache:'no-store', headers:{'X-Requested-With':'fetch'}});
      if(!r.ok) return [];
      const j = await r.json();
      return Array.isArray(j?.data) ? j.data : [];
    }

    function renderLista($box, itens, onPick){
      if (!itens.length){
        $box.innerHTML = '<div class="auto-item auto-muted">Nenhum cliente encontrado</div>';
        $box.hidden = false;
        return;
      }
      $box.innerHTML = itens.map(c => `
        <div class="auto-item" data-id="${c.id}">
          <div><strong>${c.nome||'-'}</strong></div>
          <div class="auto-muted">${c.cpf||''} • ${c.telefone||''}</div>
          ${c.endereco ? `<div class="auto-muted">${c.endereco}</div>`:''}
        </div>
      `).join('');
      $box.hidden = false;

      $box.querySelectorAll('.auto-item').forEach(el=>{
        el.addEventListener('click', ()=>{
          const id = Number(el.dataset.id||0);
          const i  = itens.find(x => Number(x.id)===id);
          if(i){ preencherCliente(i); }
          $box.hidden = true;
        });
      });
    }

    function preencherCliente(c) {
      if (!c) return;
      const get = id => document.getElementById(id);
      const byName = sel => document.querySelector(sel);

      if (get('client_id'))   get('client_id').value   = c.id || '';
      if (get('cli_nome'))    get('cli_nome').value    = c.nome || '';
      if (get('cli_telefone'))get('cli_telefone').value= c.telefone || '';
      if (get('cli_cpf'))     get('cli_cpf').value     = c.cpf || '';
      if (get('cli_email'))   get('cli_email').value   = c.email || '';

      if (get('cli_cep'))         get('cli_cep').value         = c.cep || '';
      if (get('cli_logradouro'))  get('cli_logradouro').value  = c.logradouro || '';
      if (get('cli_numero'))      get('cli_numero').value      = c.numero || '';
      if (get('cli_complemento')) get('cli_complemento').value = c.complemento || '';
      if (get('cli_bairro'))      get('cli_bairro').value      = c.bairro || '';
      if (get('cli_cidade'))      get('cli_cidade').value      = c.cidade || '';
      if (get('cli_uf'))          get('cli_uf').value          = c.uf || '';

      if (byName('[name="observacao"]')) 
          byName('[name="observacao"]').value = c.observacao || '';

      if (get('cli_endereco')) {
        get('cli_endereco').value = [
          c.logradouro || '',
          c.numero ? ', ' + c.numero : '',
          c.bairro ? ' — ' + c.bairro : '',
          c.cidade ? ' — ' + c.cidade : '',
          c.uf ? '/' + c.uf : '',
          c.cep ? ' — CEP ' + c.cep : ''
        ].join('').trim();
      }
    }

    $cpf.addEventListener('input', ()=>{ $id.value = ''; });

    const buscarCPF = debounce(async ()=>{
      const raw = ($cpf.value||'').trim();
      const dig = onlyDigits(raw);
      if(dig.length < 3){ $listCPF.hidden = true; return; }
      const itens = await buscarClientes(dig);
      renderLista($listCPF, itens, (cli)=> preencherCliente(cli));
    }, 250);

    $cpf.addEventListener('input', buscarCPF);
    $cpf.addEventListener('focus', buscarCPF);
    document.addEventListener('click', e=>{
      if(!($listCPF.contains(e.target) || $cpf.contains(e.target))) $listCPF.hidden = true;
    });
  })();

  // ====== AUTO PREENCHIMENTO E MONTAGEM DO ENDEREÇO (campo oculto) ======
  const camposEndereco = {
    cep: document.getElementById('cli_cep'),
    logradouro: document.getElementById('cli_logradouro'),
    numero: document.getElementById('cli_numero'),
    bairro: document.getElementById('cli_bairro'),
    cidade: document.getElementById('cli_cidade'),
    uf: document.getElementById('cli_uf'),
    endereco: document.getElementById('cli_endereco')
  };

  camposEndereco.cep.addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g, '').slice(0, 8);
    if (v.length > 5) v = v.slice(0, 5) + '-' + v.slice(5);
    e.target.value = v;
  });

  async function buscarCEP(cep) {
    try {
      const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
      const data = await res.json();

      if (data.erro) {
        alert('❌ CEP não encontrado.');
        return;
      }

      camposEndereco.logradouro.value = data.logradouro || '';
      camposEndereco.bairro.value = data.bairro || '';
      camposEndereco.cidade.value = data.localidade || '';
      camposEndereco.uf.value = data.uf || '';

      montarEnderecoCompleto();
    } catch (err) {
      console.error('Erro ao buscar CEP:', err);
    }
  }

  camposEndereco.cep.addEventListener('input', () => {
    const cepNum = camposEndereco.cep.value.replace(/\D/g, '');
    if (cepNum.length === 8) buscarCEP(cepNum);
  });

  function montarEnderecoCompleto() {
    const { logradouro, numero, bairro, cidade, uf, cep } = camposEndereco;
    const partes = [
      logradouro.value,
      numero.value ? ', ' + numero.value : '',
      bairro.value ? ' — ' + bairro.value : '',
      cidade.value ? ' — ' + cidade.value : '',
      uf.value ? '/' + uf.value : '',
      cep.value ? ' — CEP ' + cep.value : ''
    ];
    camposEndereco.endereco.value = partes.join('').trim();
  }

  ['input', 'change'].forEach(evt => {
    for (const key of ['logradouro', 'numero', 'bairro', 'cidade', 'uf', 'cep']) {
      camposEndereco[key].addEventListener(evt, montarEnderecoCompleto);
    }
  });

  function ajustarEscalaModal() {
    const modal = document.querySelector('.modal-content');
    if (!modal) return;
    const modalHeight = modal.scrollHeight;
    const viewportHeight = window.innerHeight * 0.9;
    const modalWidth = modal.offsetWidth;
    const viewportWidth = window.innerWidth * 0.9;
    const scaleH = viewportHeight / modalHeight;
    const scaleW = viewportWidth / modalWidth;
    const scale = Math.min(scaleH, scaleW, 1);
    modal.style.transform = `scale(${scale})`;
    modal.style.margin = "0";
  }

  window.addEventListener('resize', ajustarEscalaModal);
  window.addEventListener('load', ajustarEscalaModal);

  const observer = new MutationObserver(() => ajustarEscalaModal());
  observer.observe(document.body, { attributes: true, childList: true, subtree: true });

  openBtn.addEventListener('click', () => {
    const agora = new Date();
    const campoData = document.querySelector('#modalAddServico input[name="data"]');
    const campoHora = document.querySelector('#modalAddServico input[name="hora"]');
    if (campoData) campoData.value = agora.toISOString().slice(0, 10);
    if (campoHora) {
      const h = String(agora.getHours()).padStart(2, '0');
      const m = String(agora.getMinutes()).padStart(2, '0');
      campoHora.value = `${h}:${m}`;
    }
    modal.style.display = 'flex';
  });

  async function gerarEImprimirOS(id){
    await fetch(`includes/gerar_pdf_os.php?id=${id}&save=1`, { cache:'no-store' });
    const url = `includes/gerar_pdf_os.php?id=${id}&print=1&_=${Date.now()}`;
    window.open(url, '_blank');
  }
// Clique no ícone de impressão da barra lateral
document.addEventListener('click', function(e){
  const btn = e.target.closest('.os-print-btn');
  if (!btn) return;

  const li = btn.closest('li[data-id]');
  if (!li) return;

  const id = li.getAttribute('data-id');
  if (!id) return;

  gerarEImprimirOS(id);
});

  // ====== SUBMIT: verificar/criar cliente -> salvar_os via AJAX -> imprimir ======
  document.getElementById('formAddServico').addEventListener('submit', async function(e){
    e.preventDefault();

    const form = this;
    const nome = form.querySelector('[name="nome"]').value.trim();
    const telefone = form.querySelector('[name="telefone"]').value.trim();
    const cpf = form.querySelector('[name="cpf"]').value.trim();
    if (!cpf){ alert('Informe o CPF do cliente.'); return; }

    try {
      // 1) verifica ou cria cliente
      const rCli = await fetch('includes/verificar_ou_inserir_cliente.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({
          nome, telefone, cpf,
          endereco: document.getElementById('cli_endereco').value,
          cep: document.getElementById('cli_cep').value,
          logradouro: document.getElementById('cli_logradouro').value,
          numero: document.getElementById('cli_numero').value,
          bairro: document.getElementById('cli_bairro').value,
          cidade: document.getElementById('cli_cidade').value,
          uf: document.getElementById('cli_uf').value
        })
      });
      const c = await rCli.json();
      if (!c?.success) { alert(c?.message||'Erro ao verificar cliente'); return; }
      document.getElementById('client_id').value = c.id;

      // 2) salva OS via AJAX
      const fd = new FormData(form);
      const rOS = await fetch('includes/salvar_os.php', { method:'POST', body: fd });
      const jOS = await rOS.json();
      if (!jOS?.ok) throw new Error(jOS?.msg||'Erro ao salvar OS');

      const novoId = jOS.id;

      // 3) gerar PDF no layout do modal e imprimir imediatamente
      await gerarEImprimirOS(novoId);

      // 4) UI: fechar modal, limpar e atualizar cards/listas
      document.getElementById('modalAddServico').style.display = 'none';
      form.reset();
      setTimeout(()=>{ window.atualizarCards?.(); window.atualizarFinanceiro?.(); location.reload(); }, 300);
    } catch (err){
      console.error(err);
      alert('Não foi possível salvar e imprimir a OS.');
    }
  });
</script>

</body>
</html>
