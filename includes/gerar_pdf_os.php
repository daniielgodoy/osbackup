<?php
// includes/gerar_pdf_os.php
// Fiel ao layout do modal. Suporta per_page=1 (padrão) ou 2 na mesma A4.
// Assinatura ajusta conforme modo compacto. Checkbox robusto e senha padrão numerada.
// Isolamento opcional por tenant/shop (se colunas existirem).

declare(strict_types=1);
mb_internal_encoding('UTF-8');
header_remove('X-Powered-By');

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ── Sessão / contexto ── */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
$shop_id   = isset($_SESSION['shop_id'])   ? (int)$_SESSION['shop_id']   : null;

/* ── Helpers de schema (para multi-tenant opcional) ── */
function table_has_col(mysqli $conn, string $table, string $col): bool {
  static $cache = [];
  $k = $table.'|'.$col;
  if (isset($cache[$k])) return $cache[$k];
  $q = $conn->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND COLUMN_NAME = ?
     LIMIT 1
  ");
  $q->bind_param('ss', $table, $col);
  $q->execute(); $q->store_result();
  $ok = $q->num_rows > 0; $q->close();
  return $cache[$k] = $ok;
}

/* ── Busca per_page (se tabela existir) ── */
function get_print_per_page(mysqli $conn, ?int $tenant_id, ?int $shop_id): int {
  $per = 1;
  // só busca se tabela existir
  $exists = $conn->query("SHOW TABLES LIKE 'shop_print_prefs'")->num_rows > 0;
  if (!$exists) return $per;
  $sql = "SELECT COALESCE(per_page,1) FROM shop_print_prefs WHERE tenant_id=? AND shop_id=? LIMIT 1";
  if ($tenant_id !== null && $shop_id !== null) {
    $st = $conn->prepare($sql);
    $st->bind_param('ii',$tenant_id,$shop_id);
    $st->execute(); $st->bind_result($pp); $st->fetch(); $st->close();
    if (!empty($pp)) $per = max(1, min(2, (int)$pp)); // suportamos 1 ou 2 por página
  }
  return $per;
}

$per_page = get_print_per_page($conn, $tenant_id, $shop_id);
$compact  = $per_page >= 2;

/* ── Entrada ── */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo 'ID inválido.'; exit; }

/* ── WHERE dinâmico para isolamento (se existir tenant_id/shop_id) ── */
$where = "os.id = ?";
$types = "i";
$params = [$id];

if ($tenant_id !== null && table_has_col($conn, 'ordens_servico', 'tenant_id')) {
  $where .= " AND os.tenant_id = ?";
  $types .= "i";
  $params[] = $tenant_id;
}
if ($shop_id !== null && table_has_col($conn, 'ordens_servico', 'shop_id')) {
  $where .= " AND os.shop_id = ?";
  $types .= "i";
  $params[] = $shop_id;
}

/* ── Busca (apenas colunas usadas aqui) ── */
$sql = "
  SELECT
    os.id, os.nome, os.cpf, os.telefone,
    os.modelo, os.servico, os.status,
    os.data_entrada, os.hora_entrada, os.observacao,

    COALESCE(os.valor_pix,0)           AS valor_pix,
    COALESCE(os.valor_dinheiro,0)      AS valor_dinheiro,
    COALESCE(os.valor_credito,0)       AS valor_credito,
    COALESCE(os.valor_debito,0)        AS valor_debito,
    COALESCE(os.valor_dinheiro_pix,0)  AS valor_dinheiro_pix,
    COALESCE(os.valor_cartao,0)        AS valor_cartao,
    COALESCE(os.valor_total,0)         AS valor_total,

    COALESCE(os.senha_padrao,'')       AS senha_padrao,
    COALESCE(os.senha_escrita,'')      AS senha_escrita,

    COALESCE(os.pagamento_tipo,'')     AS pagamento_tipo,
    COALESCE(os.metodo_pagamento,'')   AS metodo_pagamento,

    COALESCE(cli.cep,        '') AS cli_cep,
    COALESCE(cli.logradouro, '') AS cli_logradouro,
    COALESCE(cli.numero,     '') AS cli_numero,
    COALESCE(cli.bairro,     '') AS cli_bairro,
    COALESCE(cli.cidade,     '') AS cli_cidade,
    COALESCE(cli.uf,         '') AS cli_uf
  FROM ordens_servico os
  LEFT JOIN clientes cli
    ON REPLACE(REPLACE(REPLACE(os.cpf,'.',''),'-',''),' ','')
     = REPLACE(REPLACE(REPLACE(cli.cpf,'.',''),'-',''),' ','')
  WHERE $where
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$os = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$os) { http_response_code(404); echo 'OS não encontrada.'; exit; }

/* ── Helpers ── */
$h   = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$fmt = fn($n) => number_format((float)$n, 2, ',', '.');
$g   = fn($k) => isset($os[$k]) ? trim((string)$os[$k]) : '';

$dataBR = $g('data_entrada') ? date('d/m/Y', strtotime($g('data_entrada'))) : '';
$horaBR = $g('hora_entrada') ?: '';

/* ── Valores (compõe exatamente como no modal) ── */
$valor_dinpix = (float)$os['valor_dinheiro_pix'];
$valor_cartao = (float)$os['valor_cartao'];
if ($valor_dinpix <= 0)  $valor_dinpix = (float)$os['valor_pix'] + (float)$os['valor_dinheiro'];
if ($valor_cartao <= 0)  $valor_cartao = (float)$os['valor_credito'] + (float)$os['valor_debito'];

/* ── Senha padrão (numeração pela ordem tocada) ── */
$rawSenha = (string)$os['senha_padrao'];                 // ex: "1-5-9", "1,5,9", "159"
$seq = preg_split('/[^1-9]+/', preg_replace('/\s+/', '', $rawSenha), -1, PREG_SPLIT_NO_EMPTY);
$ordem = []; $idx = 1;
foreach ($seq as $token) {
  for ($j = 0; $j < strlen($token); $j++) {
    $p = (int)$token[$j];
    if ($p >= 1 && $p <= 9 && !isset($ordem[$p])) { $ordem[$p] = $idx++; }
  }
}
$senha_escrita = $g('senha_escrita');

/* ── Checkboxes (pagamento entrada/retirada) — hiper-tolerante + fallback ── */
$valoresPossiveis = [];
$valoresPossiveis[] = (string)($os['pagamento_tipo']   ?? '');
$valoresPossiveis[] = (string)($os['metodo_pagamento'] ?? '');

$tipoBruto = '';
foreach ($valoresPossiveis as $v) {
  $v = trim($v ?? '');
  if ($v !== '') $tipoBruto .= ' ' . $v;
}
$tipoBruto = trim($tipoBruto);

$tipo = mb_strtolower($tipoBruto, 'UTF-8');
$map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'];
$tipo = strtr($tipo, $map);
$tipo = preg_replace('/[^a-z ]+/', ' ', $tipo);
$tipo = preg_replace('/\s+/', ' ', trim($tipo));
$temEntrada  = (strpos($tipo, 'entrada')  !== false);
$temRetirada = (strpos($tipo, 'retirada') !== false);
if ($temEntrada && $temRetirada) {
  $pgtoEntrada  = strrpos($tipo, 'entrada')  > strrpos($tipo, 'retirada');
  $pgtoRetirada = !$pgtoEntrada;
} else {
  $pgtoEntrada  = $temEntrada;
  $pgtoRetirada = $temRetirada;
}
if (!$pgtoEntrada && !$pgtoRetirada && $tipoBruto !== '') { $pgtoRetirada = true; }

/* ── Função para renderizar 1 ticket (mantém layout do modal) ── */
function render_ticket(array $os, array $ordem, string $senha_escrita, bool $pgtoEntrada, bool $pgtoRetirada, callable $h, callable $g, callable $fmt, string $dataBR, string $horaBR, float $valor_dinpix, float $valor_cartao, bool $compact): void {
?>
  <div class="ticket<?= $compact ? ' compact' : '' ?>">
    <div class="modal">
      <div style="text-align:center">
        <span class="badge">Ordem Nº <?= (int)$os['id']; ?> ______</span>
      </div>
      <h1>Ordem de Serviço</h1>
      <div class="sub">
        Fica neste ato o cliente responsável civil e criminalmente pela procedência do aparelho.<br>
        Não nos responsabilizamos por cartões ou chips deixados no aparelho.
      </div>

      <div class="grid-3">
        <div class="field"><div class="lbl">Nome:</div><div class="val"><?= $h($g('nome')); ?></div></div>
        <div class="field"><div class="lbl">Telefone:</div><div class="val"><?= $h($g('telefone')); ?></div></div>
        <div class="field"><div class="lbl">CPF:</div><div class="val"><?= $h($g('cpf')); ?></div></div>
      </div>

      <div class="grid-2" style="margin-top:8px">
        <div class="field"><div class="lbl">CEP:</div><div class="val"><?= $h($g('cli_cep')); ?></div></div>
        <div class="field"><div class="lbl">Logradouro:</div><div class="val"><?= $h($g('cli_logradouro')); ?></div></div>
      </div>

      <div class="grid-2">
        <div class="field"><div class="lbl">Número:</div><div class="val"><?= $h($g('cli_numero')); ?></div></div>
        <div class="field"><div class="lbl">Bairro:</div><div class="val"><?= $h($g('cli_bairro')); ?></div></div>
      </div>

      <div class="grid-2">
        <div class="field"><div class="lbl">Cidade:</div><div class="val"><?= $h($g('cli_cidade')); ?></div></div>
        <div class="field"><div class="lbl">UF:</div><div class="val"><?= $h($g('cli_uf')); ?></div></div>
      </div>

      <div class="grid-2">
        <div class="field"><div class="lbl">Modelo do aparelho:</div><div class="val"><?= $h($g('modelo')); ?></div></div>
        <div class="field"><div class="lbl">Serviço prestado:</div><div class="val"><?= $h($g('servico')); ?></div></div>
      </div>

      <div class="grid-2">
        <div class="field"><div class="lbl">Data de entrada:</div><div class="val"><?= $h($dataBR); ?></div></div>
        <div class="field"><div class="lbl">Hora de entrada:</div><div class="val"><?= $h($horaBR); ?></div></div>
      </div>

      <div class="field" style="margin-top:8px">
        <div class="lbl">Observações:</div>
        <div class="val" style="min-height:70px"><?= nl2br($h($g('observacao'))); ?></div>
      </div>

      <div class="pay">
        <div class="field">
          <div class="lbl"><span class="tick"></span> Valor Dinheiro / Pix (R$)</div>
          <div class="val"><?= $fmt($valor_dinpix); ?></div>
        </div>
        <div class="field">
          <div class="lbl">💳 Valor Cartão (R$)</div>
          <div class="val"><?= $fmt($valor_cartao); ?></div>
        </div>
      </div>

      <div class="senha-row">
        <div class="field">
          <div class="lbl">Senha padrão:</div>
          <div class="padlock">
            <?php for ($i=1; $i<=9; $i++): ?>
              <div class="dot"><?= isset($ordem[$i]) ? '<span class="ball">'.(int)$ordem[$i].'</span>' : '' ?></div>
            <?php endfor; ?>
          </div>
        </div>
        <div class="field">
          <div class="lbl">Senha escrita (se houver)</div>
          <div class="val"><?= $h($senha_escrita); ?></div>
        </div>
        <div class="checks">
          <span class="check<?= $pgtoRetirada ? ' on' : '' ?>"></span><span class="checklbl">Pagamento na retirada</span>
          <span class="check<?= $pgtoEntrada  ? ' on' : '' ?>"></span><span class="checklbl">Pagamento na entrada</span>
        </div>
      </div>

      <div class="warn">Aparelho só será liberado mediante apresentação desta ordem de serviço.</div>
      <div class="notice">
        Caso o aparelho não seja retirado em até 30 (trinta) dias após a conclusão do serviço, será cobrada multa de 1%
        (um por cento) ao dia sobre o valor total do serviço, conforme o Artigo 408 do Código Civil Brasileiro, que
        prevê a incidência de penalidade em caso de descumprimento de obrigação por parte do devedor.
      </div>

      <div class="assin"><small>Assinatura do cliente</small></div>
    </div>
  </div>
<?php
}

/* ── HTML ── */
ob_start();
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>OS #<?= (int)$os['id']; ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    @page { size: A4; margin: 10mm; }
    *{ box-sizing:border-box; -webkit-print-color-adjust:exact; print-color-adjust:exact }
    html,body{ margin:0; padding:0; background:#f6f7fb; color:#0f172a; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif; }

    .sheet{ max-width: 190mm; margin:0 auto; }
    .sheet.two { display:grid; grid-template-rows: 1fr 1fr; gap:8mm; }
    .ticket { break-inside: avoid; }

    .modal{
      background:#fff; border:1px solid #d9e2ef; border-radius:16px;
      box-shadow:0 10px 30px rgba(0,0,0,.06);
      padding:12mm 12mm 10mm;
    }

    .badge{
      display:inline-block; padding:6px 12px; border-radius:12px;
      background:#e6edff; color:#243ea6; font-weight:700; font-size:13px;
      border:1px solid #cfd9ff; margin:0 auto 8px;
    }
    h1{ font-size:22px; font-weight:800; text-align:center; margin:4px 0 6px; }
    .sub{ text-align:center; font-size:12px; color:#475569; line-height:1.35; margin-bottom:10px; }

    .grid-3{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; }
    .field{ display:flex; flex-direction:column; gap:4px; }
    .lbl{ font-size:11px; color:#2e3a4b; }

    .val{
      border:1px solid #cfd7e6; background:#eef2f7; padding:9px 10px;
      border-radius:10px; font-size:12.5px; color:#0b1220; min-height:34px;
      display:flex; align-items:center;
    }

    .pay{ display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; }
    .pay .lbl{ display:flex; align-items:center; gap:6px; }
    .tick{ width:12px; height:12px; border-radius:3px; background:#16a34a; display:inline-block; }

    .senha-row{ display:grid; grid-template-columns:120px 1fr auto; gap:10px; align-items:end; margin-top:8px; }
    .padlock{
      width:120px; height:120px; border:1px solid #cfd7e6; background:#eef2f7; border-radius:10px;
      display:grid; grid-template-columns:repeat(3,1fr); grid-template-rows:repeat(3,1fr); gap:10px; padding:10px;
      min-width:120px; min-height:120px;
    }
    .dot{ border:1px dashed #94a3b8; border-radius:8px; position:relative; display:flex; align-items:center; justify-content:center; }
    .ball{
      width:20px; height:20px; border-radius:50%; background:#0f172a; color:#fff; font-size:12px;
      display:flex; align-items:center; justify-content:center; font-weight:700;
    }

    .checks{ display:flex; align-items:center; gap:14px; margin-bottom:6px; white-space:nowrap; }
    .check{
      width:14px; height:14px; border:1px solid #94a3b8; border-radius:4px; display:inline-block; position:relative;
      background:#fff;
    }
    .check.on{
      background:#2c4de2; border-color:#2c4de2;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .check.on::after{
      content:""; position:absolute; left:3px; top:1px; width:6px; height:10px;
      border:2px solid #0f172a;
      border-top:none; border-left:none; transform:rotate(45deg);
    }
    .checklbl{ font-size:12px; color:#0f172a; display:flex; align-items:center; gap:8px; }

    .warn{ margin-top:10px; text-align:center; color:#b91c1c; font-weight:700; font-size:12px; }
    .notice{ margin-top:6px; color:#64748b; font-size:11px; text-align:center; line-height:1.4; }

    .assin{ margin-top:16mm; text-align:center; color:#0f172a; }
    .assin::before{ content:""; display:block; border-top:1px solid #94a3b8; margin:20px auto 6px; width:65%; }
    .assin small{ color:#64748b; font-size:11px }

    /* ── Modo compacto (2 por página) ── */
    .ticket.compact .modal{ padding:9mm 9mm 8mm; }
    .ticket.compact h1{ font-size:20px; }
    .ticket.compact .sub{ font-size:11.5px; margin-bottom:8px; }
    .ticket.compact .val{ min-height:32px; font-size:12px; padding:8px 9px; }
    .ticket.compact .padlock{ width:110px; height:110px; gap:8px; padding:8px; }
    .ticket.compact .ball{ width:18px; height:18px; font-size:11px; }
    .ticket.compact .assin{ margin-top:12mm; }
    .ticket.compact .warn{ margin-top:8px; }
    .ticket.compact .notice{ margin-top:4px; }

    @media print {
      html,body{ background:#fff; }
      .modal{ box-shadow:none; border:1px solid #d9e2ef; }
    }
  </style>
</head>
<body>
  <div class="sheet<?= $compact ? ' two' : '' ?>">
    <?php
      // Sempre renderiza pelo menos 1
      render_ticket($os, $ordem, $senha_escrita, $pgtoEntrada, $pgtoRetirada, $h, $g, $fmt, $dataBR, $horaBR, $valor_dinpix, $valor_cartao, $compact);
      // Se per_page >= 2, renderiza a segunda cópia
      if ($compact) {
        render_ticket($os, $ordem, $senha_escrita, $pgtoEntrada, $pgtoRetirada, $h, $g, $fmt, $dataBR, $horaBR, $valor_dinpix, $valor_cartao, $compact);
      }
    ?>
  </div>

<?php if (isset($_GET['print'])): ?>
<script>
  window.addEventListener('load', function(){
    setTimeout(function(){ try{ window.print(); }catch(e){} }, 60);
  });
</script>
<?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

/* ── Salvar (?save=1) ── */
if (isset($_GET['save'])) {
  $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pedidos';
  if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
  if (!(is_dir($dir) && is_writable($dir))) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'msg'=>'Diretório /pedidos não é gravável.']);
    exit;
  }
  $fname = sprintf('os_%06d_%s.html', (int)$os['id'], date('Ymd_His'));
  file_put_contents($dir . DIRECTORY_SEPARATOR . $fname, $html);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'file'=>$fname,'url'=>'/os/pedidos/'.$fname]); exit;
}

/* ── Exibir ── */
header('Content-Type: text/html; charset=utf-8');
echo $html;
