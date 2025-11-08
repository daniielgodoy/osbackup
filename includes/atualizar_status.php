<?php
// includes/atualizar_status.php
declare(strict_types=1);

include_once __DIR__ . '/mysqli.php';

date_default_timezone_set('America/Sao_Paulo');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '-03:00'");

/* ───────── Sessão / Contexto ───────── */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$shop_id   = (int)($_SESSION['shop_id']   ?? 0);
$user_id   = (int)($_SESSION['user_id']   ?? 0);

if ($tenant_id <= 0 || $shop_id <= 0) {
  http_response_code(403);
  echo 'ERRO: Contexto inválido (tenant/loja).';
  exit;
}

/* ================== CONFIG LOCAIS (Node WA) ================== */
/* Se houver base_url em wa_devices, ela será usada; caso contrário, cai neste: */
const WA_BASE_DEFAULT = 'http://127.0.0.1:3001';

/* ───────── Helpers gerais ───────── */
function hasCol(mysqli $c, string $tab, string $col): bool {
  $r = $c->query("SHOW COLUMNS FROM `$tab` LIKE '$col'");
  return ($r && $r->num_rows > 0);
}

/* Normaliza string moeda "1.234,56"/"1234.56" → float */
function to_num($v): float {
  if ($v === null) return 0.0;
  $s = trim((string)$v);
  if ($s === '') return 0.0;
  $s = preg_replace('/[^\d,.\-]/', '', $s);
  if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    $s = str_replace(',', '.', $s);
  }
  return (float)$s;
}

function fmt_brl(float $v): string {
  return 'R$ ' . number_format($v, 2, ',', '.');
}

/* ───────── Estruturas por LOJA (templates e notify_auto) ───────── */
function ensure_shop_templates_table(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS `shop_msg_templates` (
      `tenant_id` INT NOT NULL,
      `shop_id`   INT NOT NULL,
      `pendente`            TEXT NULL,
      `em_andamento`        TEXT NULL,
      `orcamento`           TEXT NULL,
      `aguardando_retirada` TEXT NULL,
      `concluido`           TEXT NULL,
      `notify_auto` TINYINT(1) NOT NULL DEFAULT 1,
      `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`tenant_id`,`shop_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
function ensure_shop_templates_row(mysqli $conn, int $tenant_id, int $shop_id): void {
  $q = $conn->prepare("SELECT 1 FROM shop_msg_templates WHERE tenant_id=? AND shop_id=?");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute(); $q->store_result();
  $exists = $q->num_rows > 0;
  $q->close();
  if (!$exists) {
    $st = $conn->prepare("INSERT INTO shop_msg_templates (tenant_id, shop_id) VALUES (?,?)");
    $st->bind_param('ii', $tenant_id, $shop_id);
    $st->execute();
    $st->close();
  }
}

function load_shop_templates(mysqli $conn, int $tenant_id, int $shop_id): array {
  ensure_shop_templates_table($conn);
  ensure_shop_templates_row($conn, $tenant_id, $shop_id);
  $q = $conn->prepare("
    SELECT COALESCE(pendente,''), COALESCE(em_andamento,''), COALESCE(orcamento,''),
           COALESCE(aguardando_retirada,''), COALESCE(concluido,''), COALESCE(notify_auto,1)
      FROM shop_msg_templates
     WHERE tenant_id=? AND shop_id=? LIMIT 1
  ");
  $q->bind_param('ii', $tenant_id, $shop_id);
  $q->execute();
  $q->bind_result($p,$e,$o,$ar,$c,$na);
  $q->fetch();
  $q->close();

  return [
    'templates' => [
      'pendente'            => (string)($p ?? ''),
      'em_andamento'        => (string)($e ?? ''),
      'orcamento'           => (string)($o ?? ''),
      'aguardando_retirada' => (string)($ar ?? ''),
      'concluido'           => (string)($c ?? ''),
    ],
    'notify_auto' => (int)($na ?? 1),
  ];
}

/* ───────── Disparo WhatsApp por LOJA (usa wa_devices) ───────── */
function get_shop_device(mysqli $conn, int $tenant_id, int $shop_id): array {
  try {
    $q = $conn->prepare("
      SELECT device_key, base_url, COALESCE(active,1) AS active
        FROM wa_devices
       WHERE tenant_id=? AND shop_id=? AND COALESCE(active,1)=1
       ORDER BY id ASC
       LIMIT 1
    ");
    $q->bind_param('ii', $tenant_id, $shop_id);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    return [
      'device_key' => $r['device_key'] ?? null,
      'base_url'   => $r['base_url']   ?: null,
      'active'     => (int)($r['active'] ?? 0),
    ];
  } catch (Throwable $e) {
    return ['device_key'=>null,'base_url'=>null,'active'=>0];
  }
}

/* POST JSON para /send com ?device= */
function wa_post_json_scoped(string $base, ?string $deviceKey, string $path, array $payload=[], int $timeout=2500): bool {
  $url = rtrim($base, '/').$path;
  if ($deviceKey) {
    $url .= (strpos($url,'?')===false ? '?' : '&').'device='.rawurlencode($deviceKey);
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT_MS     => $timeout,
  ]);
  curl_exec($ch);
  $err  = curl_errno($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $err === 0 && $code < 400;
}

/* Normaliza para BR (DDI 55) */
function normalize_phone(?string $raw): ?string {
  $d = preg_replace('/\D+/', '', (string)$raw);
  if (!$d) return null;
  if (strpos($d, '55') === 0) return $d;
  return '55'.$d;
}

/* Envia WhatsApp (silencioso se não houver device/telefone) */
function notificar_whatsapp_loja(
  mysqli $conn,
  int $tenant_id,
  int $shop_id,
  ?string $telefone,
  string $mensagem
): void {
  $norm = normalize_phone($telefone);
  if (!$norm || $mensagem === '') return;

  $dev  = get_shop_device($conn, $tenant_id, $shop_id);
  $base = $dev['base_url'] ?: WA_BASE_DEFAULT;

  if (!$dev['active'] || empty($base)) return; // sem device ativo/base — não quebra

  wa_post_json_scoped($base, $dev['device_key'], '/send', [
    'phone'   => $norm,
    'message' => $mensagem,
  ]);
}

/* Carrega OS no escopo tenant/loja */
function carregar_os(mysqli $conn, int $id, int $tenant_id, int $shop_id): ?array {
  $st = $conn->prepare("
    SELECT id, tenant_id, shop_id, nome, telefone, modelo, servico, status,
           COALESCE(valor_total,0) AS valor_total,
           COALESCE(metodo_pagamento,'') AS metodo_pagamento
      FROM ordens_servico
     WHERE id=? AND tenant_id=? AND shop_id=?
     LIMIT 1
  ");
  $st->bind_param('iii', $id, $tenant_id, $shop_id);
  $st->execute();
  $r = $st->get_result()->fetch_assoc();
  $st->close();
  return $r ?: null;
}

/* Preenche variáveis no template */
function fill_template(string $tpl, array $ctx): string {
  $map = [
    '{NOME}'   => (string)($ctx['NOME']   ?? ''),
    '{MODELO}' => (string)($ctx['MODELO'] ?? ''),
    '{VALOR}'  => (string)($ctx['VALOR']  ?? ''),
    '{ID}'     => (string)($ctx['ID']     ?? ''),
    '{HORA}'   => (string)($ctx['HORA']   ?? ''),
  ];
  return strtr($tpl, $map);
}

/* LOG opcional: ordens_servico_logs */
function insert_log_if_possible(mysqli $conn, array $row, array $opts): void {
  try {
    $r = @$conn->query("SHOW TABLES LIKE 'ordens_servico_logs'");
    if (!$r || $r->num_rows === 0) return;
  } catch (Throwable $e) { return; }

  $sql = "INSERT INTO ordens_servico_logs
            (os_id, tenant_id, shop_id, user_id, acao, status_novo, valor_total, metodo_pagamento, criado_em)
          VALUES (?,?,?,?,?,?,?, ?, NOW())";
  $st = $conn->prepare($sql);
  $valor_total = (float)($opts['valor_total'] ?? 0);
  $metodo_pag  = (string)($opts['metodo_pagamento'] ?? '');
  $acao        = (string)($opts['acao'] ?? 'atualizar_status');
  $status_novo = (string)($opts['status'] ?? '');

  $st->bind_param(
    'iiiissds',
    $row['id'], $row['tenant_id'], $row['shop_id'],
    $opts['user_id'], $acao, $status_novo, $valor_total, $metodo_pag
  );
  $st->execute();
  $st->close();
}

/* ───────── Entrada ───────── */
$id       = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status   = strtolower(trim((string)($_POST['status'] ?? '')));
$metodo   = strtolower(trim((string)($_POST['tipo_pagamento'] ?? ''))); // 'pix','dinheiro','credito','debito','cartao','misto'
$pixPost  = $_POST['pix']      ?? '0';
$dinPost  = $_POST['dinheiro'] ?? '0';
$crePost  = $_POST['credito']  ?? '0';
$debPost  = $_POST['debito']   ?? '0';

if ($id <= 0 || $status === '') {
  http_response_code(400);
  echo 'ERRO: Parâmetros inválidos.';
  exit;
}

/* Checagem de posse */
$os_row = carregar_os($conn, $id, $tenant_id, $shop_id);
if (!$os_row) {
  http_response_code(404);
  echo 'ERRO: OS não encontrada para esta loja.';
  exit;
}

/* Normalizações de valores */
$pix  = to_num($pixPost);
$din  = to_num($dinPost);
$cred = to_num($crePost);
$deb  = to_num($debPost);

$temMisto = ($pix + $din + $cred + $deb) > 0;

$valor_confirmado = $temMisto
  ? round($pix + $din + $cred + $deb, 2)
  : to_num($_POST['valor_confirmado'] ?? 0);

/* ───────── Atualização + Pagamento ───────── */
try {
  $conn->begin_transaction();

  $has_pix   = hasCol($conn, 'ordens_servico', 'valor_pix');
  $has_din   = hasCol($conn, 'ordens_servico', 'valor_dinheiro');
  $has_cred  = hasCol($conn, 'ordens_servico', 'valor_credito');
  $has_deb   = hasCol($conn, 'ordens_servico', 'valor_debito');
  $has_total = hasCol($conn, 'ordens_servico', 'valor_total');

  if ($status === 'concluido') {
    /* método de pagamento */
    if ($temMisto) {
      $usados = [];
      if ($pix  > 0) $usados[] = 'pix';
      if ($din  > 0) $usados[] = 'dinheiro';
      if ($cred > 0) $usados[] = 'credito';
      if ($deb  > 0) $usados[] = 'debito';
      $metodo = !empty($usados) ? implode('+', $usados) : 'misto';
    } elseif ($metodo === 'cartao') {
      $metodo = 'credito';
    } elseif (!in_array($metodo, ['pix','dinheiro','credito','debito'], true)) {
      $metodo = 'desconhecido';
    }

    if ($valor_confirmado <= 0) {
      throw new Exception('Valor total não informado.');
    }

    /* status + flags base */
    $st = $conn->prepare("
      UPDATE ordens_servico
         SET status='concluido',
             pago=1,
             metodo_pagamento=?,
             data_conclusao=NOW(),
             atualizado_em=NOW()
       WHERE id=? AND tenant_id=? AND shop_id=?
    ");
    $st->bind_param('siii', $metodo, $id, $tenant_id, $shop_id);
    $st->execute();
    if ($st->affected_rows === 0) throw new Exception('OS não pertence a esta loja.');
    $st->close();

    /* valores */
    $sets  = [];
    $types = '';
    $vals  = [];

    if ($has_total) { $sets[]="valor_total=?"; $types.='d'; $vals[]=$valor_confirmado; }

    if ($temMisto) {
      if ($has_pix)  { $sets[]="valor_pix=?";      $types.='d'; $vals[]=$pix; }
      if ($has_din)  { $sets[]="valor_dinheiro=?"; $types.='d'; $vals[]=$din; }
      if ($has_cred) { $sets[]="valor_credito=?";  $types.='d'; $vals[]=$cred; }
      if ($has_deb)  { $sets[]="valor_debito=?";   $types.='d'; $vals[]=$deb; }
    } else {
      if ($has_pix)  { $sets[]="valor_pix=?";      $types.='d'; $vals[] = ($metodo==='pix'      ? $valor_confirmado : 0); }
      if ($has_din)  { $sets[]="valor_dinheiro=?"; $types.='d'; $vals[] = ($metodo==='dinheiro' ? $valor_confirmado : 0); }
      if ($has_cred) { $sets[]="valor_credito=?";  $types.='d'; $vals[] = ($metodo==='credito'  ? $valor_confirmado : 0); }
      if ($has_deb)  { $sets[]="valor_debito=?";   $types.='d'; $vals[] = ($metodo==='debito'   ? $valor_confirmado : 0); }
    }

    if (!empty($sets)) {
      $q = "UPDATE ordens_servico SET ".implode(', ', $sets).", atualizado_em=NOW() WHERE id=? AND tenant_id=? AND shop_id=?";
      $types .= 'iii';
      $vals[]  = $id; $vals[] = $tenant_id; $vals[] = $shop_id;
      $st2 = $conn->prepare($q);
      $st2->bind_param($types, ...$vals);
      $st2->execute();
      if ($st2->affected_rows === 0) throw new Exception('Falha ao gravar valores.');
      $st2->close();
    }

  } else {
    /* outros status */
    $st = $conn->prepare("
      UPDATE ordens_servico
         SET status=?,
             pago=0,
             metodo_pagamento=NULL,
             data_conclusao=NULL,
             atualizado_em=NOW()
       WHERE id=? AND tenant_id=? AND shop_id=?
    ");
    $st->bind_param('siii', $status, $id, $tenant_id, $shop_id);
    $st->execute();
    if ($st->affected_rows === 0) throw new Exception('OS não pertence a esta loja.');
    $st->close();

    /* zera consolidados */
    $sets = [];
    if ($has_total) $sets[] = "valor_total=0";
    if ($has_pix)   $sets[] = "valor_pix=0";
    if ($has_din)   $sets[] = "valor_dinheiro=0";
    if ($has_cred)  $sets[] = "valor_credito=0";
    if ($has_deb)   $sets[] = "valor_debito=0";
    if (!empty($sets)) {
      $q = "UPDATE ordens_servico SET ".implode(', ', $sets).", atualizado_em=NOW() WHERE id=? AND tenant_id=? AND shop_id=?";
      $st2 = $conn->prepare($q);
      $st2->bind_param('iii', $id, $tenant_id, $shop_id);
      $st2->execute();
      $st2->close();
    }
  }

  /* LOG opcional */
  insert_log_if_possible($conn, $os_row, [
    'user_id'          => $user_id,
    'acao'             => 'atualizar_status',
    'status'           => $status,
    'valor_total'      => $valor_confirmado,
    'metodo_pagamento' => ($status === 'concluido' ? $metodo : ''),
  ]);

  $conn->commit();

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo 'ERRO: '.$e->getMessage();
  $conn->close();
  exit;
}

/* ───────── Notificação WhatsApp (pós-commit) ─────────
   Usa templates/notify_auto da LOJA (shop_msg_templates) */
try {
  $conf = load_shop_templates($conn, $tenant_id, $shop_id);
  $notify_auto = (int)($conf['notify_auto'] ?? 1) === 1;

  if ($notify_auto) {
    $os = carregar_os($conn, $id, $tenant_id, $shop_id); // recarrega total
    if ($os) {
      $nome   = trim((string)($os['nome'] ?? ''));
      $tel    = trim((string)($os['telefone'] ?? ''));
      $modelo = trim((string)($os['modelo'] ?? ''));
      $valor  = (float)($os['valor_total'] ?? 0);

      $keyByStatus = [
        'pendente'            => 'pendente',
        'em_andamento'        => 'em_andamento',
        'orcamento'           => 'orcamento',
        'aguardando_retirada' => 'aguardando_retirada',
        'concluido'           => 'concluido',
      ];
      $k = $keyByStatus[$status] ?? null;

      if ($k && !empty($conf['templates'][$k])) {
        $tpl = (string)$conf['templates'][$k];
        $msg = fill_template($tpl, [
          'NOME'   => $nome,
          'MODELO' => $modelo,
          'VALOR'  => fmt_brl($valor),
          'ID'     => (string)$id,
          'HORA'   => date('H:i'),
        ]);
        notificar_whatsapp_loja($conn, $tenant_id, $shop_id, $tel, $msg);
      }
    }
  }
} catch (Throwable $e) {
  // silencioso: não derruba a resposta por falha de notificação
}

$conn->close();
echo 'OK';
