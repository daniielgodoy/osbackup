<?php
// includes/gerar_pdf_os.php
// Fiel ao layout do modal. 1 página A4. Assinatura ~16mm abaixo.
// Corrige: checkbox pagamento (robusto + fallback) e senha padrão numerada.

declare(strict_types=1);
mb_internal_encoding('UTF-8');
header_remove('X-Powered-By');

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// ── Entrada
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo 'ID inválido.'; exit; }

// ── Busca (apenas colunas existentes)
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
  WHERE os.id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$os = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$os) { http_response_code(404); echo 'OS não encontrada.'; exit; }

// ── Helpers
$h   = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$fmt = fn($n) => number_format((float)$n, 2, ',', '.');
$g   = fn($k) => isset($os[$k]) ? trim((string)$os[$k]) : '';

$dataBR = $g('data_entrada') ? date('d/m/Y', strtotime($g('data_entrada'))) : '';
$horaBR = $g('hora_entrada') ?: '';

// Valores (compõe exatamente como no modal)
$valor_dinpix = (float)$os['valor_dinheiro_pix'];
$valor_cartao = (float)$os['valor_cartao'];
if ($valor_dinpix <= 0)  $valor_dinpix = (float)$os['valor_pix'] + (float)$os['valor_dinheiro'];
if ($valor_cartao <= 0)  $valor_cartao = (float)$os['valor_credito'] + (float)$os['valor_debito'];

// ── Senha padrão (numeração pela ordem tocada)
// Aceita: "1-5-9", "1,5,9", "159"
$rawSenha = (string)$os['senha_padrao'];
$seq = preg_split('/[^1-9]+/', preg_replace('/\s+/', '', $rawSenha), -1, PREG_SPLIT_NO_EMPTY);
$ordem = [];
$idx = 1;
foreach ($seq as $token) {
  for ($j = 0; $j < strlen($token); $j++) {
    $p = (int)$token[$j];
    if ($p >= 1 && $p <= 9 && !isset($ordem[$p])) { $ordem[$p] = $idx++; }
  }
}
$senha_escrita = $g('senha_escrita');

// ── Checkboxes (pagamento entrada/retirada) — hiper-tolerante + fallback
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
// fallback: se há algum valor mas sem palavras-chave, assume retirada
if (!$pgtoEntrada && !$pgtoRetirada && $tipoBruto !== '') {
  $pgtoRetirada = true;
}

// ── HTML
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

    .wrap{ max-width:180mm; margin:0 auto; }
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

    /* Senha padrão (grade sempre visível) */
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
      border:2px solid #0f172a; /* preto → visível mesmo se o fundo não imprimir */
      border-top:none; border-left:none; transform:rotate(45deg);
    }
    .checklbl{ font-size:12px; color:#0f172a; display:flex; align-items:center; gap:8px; }

    .warn{ margin-top:10px; text-align:center; color:#b91c1c; font-weight:700; font-size:12px; }
    .notice{ margin-top:6px; color:#64748b; font-size:11px; text-align:center; line-height:1.4; }

    /* Assinatura mais baixa (~16mm) */
    .assin{ margin-top:16mm; text-align:center; color:#0f172a; }
    .assin::before{ content:""; display:block; border-top:1px solid #94a3b8; margin:20px auto 6px; width:65%; }
    .assin small{ color:#64748b; font-size:11px }

    @media print {
      html,body{ background:#fff; }
      .modal{ box-shadow:none; border:1px solid #d9e2ef; padding:10mm; }
    }
  </style>
</head>
<body>
  <div class="wrap">
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
            <?php
              // grade 3x3 sempre visível; se houver sequência, numera
              for ($i=1; $i<=9; $i++) {
                echo '<div class="dot">';
                if (isset($ordem[$i])) echo '<span class="ball">'.(int)$ordem[$i].'</span>';
                echo '</div>';
              }
            ?>
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

// ── Salvar (?save=1)
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

header('Content-Type: text/html; charset=utf-8');
echo $html;
