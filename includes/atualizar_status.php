<?php
// includes/atualizar_status.php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/mysqli.php';

date_default_timezone_set('America/Sao_Paulo');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '-03:00'");

/* ───────── Sessão / Contexto ───────── */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$tenant_id = require_tenant();
$shop_id   = current_shop_id();              // pode ser null
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role      = (string)($_SESSION['role'] ?? 'member');
$is_admin  = ($role === 'admin');

if ($user_id <= 0) {
    http_response_code(403);
    echo 'ERRO: Não autenticado.';
    exit;
}

/* ================== CONFIG LOCAIS (Node WA) ================== */
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

/* ───────── Templates por loja ───────── */
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
    $q->execute();
    $q->store_result();
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
      SELECT
        COALESCE(pendente,''),
        COALESCE(em_andamento,''),
        COALESCE(orcamento,''),
        COALESCE(aguardando_retirada,''),
        COALESCE(concluido,''),
        COALESCE(notify_auto,1)
      FROM shop_msg_templates
      WHERE tenant_id=? AND shop_id=?
      LIMIT 1
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

/* ───────── Disparo WhatsApp por loja (wa_devices) ───────── */
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
        $r = $q->get_result()->fetch_assoc() ?: [];
        $q->close();
        return [
            'device_key' => $r['device_key'] ?? null,
            'base_url'   => $r['base_url']   ?? null,
            'active'     => (int)($r['active'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['device_key'=>null,'base_url'=>null,'active'=>0];
    }
}

function wa_post_json_scoped(string $base, ?string $deviceKey, string $path, array $payload = [], int $timeout = 2500): bool {
    $url = rtrim($base, '/') . $path;
    if ($deviceKey) {
        $url .= (strpos($url,'?') === false ? '?' : '&') . 'device=' . rawurlencode($deviceKey);
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

function normalize_phone(?string $raw): ?string {
    $d = preg_replace('/\D+/', '', (string)$raw);
    if (!$d) return null;
    if (strpos($d, '55') === 0) return $d;
    return '55' . $d;
}

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

    if (!$dev['active'] || empty($base)) return;

    wa_post_json_scoped($base, $dev['device_key'], '/send', [
        'phone'   => $norm,
        'message' => $mensagem,
    ]);
}

/* Carrega OS no escopo do tenant (e loja, se houver) */
function carregar_os(mysqli $conn, int $id, int $tenant_id, ?int $shop_id): ?array {
    $sql = "
      SELECT
        id,
        tenant_id,
        shop_id,
        responsavel_id,
        nome,
        telefone,
        modelo,
        servico,
        status,
        COALESCE(valor_total,0)       AS valor_total,
        COALESCE(metodo_pagamento,'') AS metodo_pagamento
      FROM ordens_servico
      WHERE id = ? AND tenant_id = ?
    ";
    $types = 'ii';
    $params = [$id, $tenant_id];

    if (!empty($shop_id)) {
        $sql .= " AND shop_id = ?";
        $types .= 'i';
        $params[] = $shop_id;
    }

    $sql .= " LIMIT 1";

    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $r = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    return $r;
}

/* Template vars */
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

/* ===== LOG de alterações de OS ===== */
function insert_os_log(mysqli $conn, array $log): void {
    try {
        $r = $conn->query("SHOW TABLES LIKE 'ordens_servico_logs'");
        if (!$r || $r->num_rows === 0) return;
    } catch (Throwable $e) {
        return;
    }

    $tenant_id  = (int)($log['tenant_id'] ?? 0);
    $shop_id    = isset($log['shop_id']) && $log['shop_id'] !== null
                    ? (int)$log['shop_id'] : 0;
    $os_id      = (int)($log['os_id'] ?? 0);
    $user_id    = (int)($log['user_id'] ?? 0);
    $acao       = (string)($log['acao'] ?? 'atualizar_status');
    $st_antigo  = (string)($log['status_antigo'] ?? '');
    $st_novo    = (string)($log['status_novo'] ?? '');
    $metodo_pg  = (string)($log['metodo_pagamento'] ?? '');
    $valor_tot  = ($log['valor_total'] !== null)
                    ? (float)$log['valor_total'] : 0.0;

    if ($os_id <= 0 || $tenant_id <= 0) return;

    $sql = "
      INSERT INTO ordens_servico_logs
        (tenant_id, shop_id, os_id, user_id, acao,
         status_antigo, status_novo, metodo_pagamento, valor_total, criado_em)
      VALUES (?,?,?,?,?,?,?,?,?, NOW())
    ";

    $st = $conn->prepare($sql);
    $st->bind_param(
        'iiiissssd',
        $tenant_id,
        $shop_id,
        $os_id,
        $user_id,
        $acao,
        $st_antigo,
        $st_novo,
        $metodo_pg,
        $valor_tot
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

/* Carrega OS atual (para validar + log) */
$os_row = carregar_os($conn, $id, $tenant_id, $shop_id);
if (!$os_row) {
    http_response_code(404);
    echo 'ERRO: OS não encontrada neste tenant/loja.';
    exit;
}

$resp_id    = (int)($os_row['responsavel_id'] ?? 0);
$old_status = (string)$os_row['status'];

/* Todos do tenant podem atualizar (dentro do contexto da loja/tenant) */

/* ───────── Normalizações de valores ───────── */
$pix  = to_num($pixPost);
$din  = to_num($dinPost);
$cred = to_num($crePost);
$deb  = to_num($debPost);

$temMisto = ($pix + $din + $cred + $deb) > 0;

$valor_confirmado = $temMisto
    ? round($pix + $din + $cred + $deb, 2)
    : to_num($_POST['valor_confirmado'] ?? 0);

/* Helper para cláusula opcional de shop */
function shop_clause(?int $shop_id, string &$types, array &$params): string {
    if (!empty($shop_id)) {
        $types  .= 'i';
        $params[] = $shop_id;
        return " AND shop_id = ?";
    }
    return "";
}

/* ───────── Atualização principal ───────── */
try {
    $conn->begin_transaction();

    $has_pix   = hasCol($conn, 'ordens_servico', 'valor_pix');
    $has_din   = hasCol($conn, 'ordens_servico', 'valor_dinheiro');
    $has_cred  = hasCol($conn, 'ordens_servico', 'valor_credito');
    $has_deb   = hasCol($conn, 'ordens_servico', 'valor_debito');
    $has_total = hasCol($conn, 'ordens_servico', 'valor_total');

    $claim_responsavel = ($status !== 'pendente' && ($resp_id === 0));

    if ($status === 'concluido') {
        // define método pagamento
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

        // --- Update status/pagamento base ---
        $types = 'sii';
        $params = [$metodo, $id, $tenant_id];
        $shopSql = shop_clause($shop_id, $types, $params);

        $sql = "
          UPDATE ordens_servico
             SET status='concluido',
                 pago=1,
                 metodo_pagamento=?,
                 data_conclusao=NOW(),
                 atualizado_em=NOW()"
                 .($claim_responsavel
                    ? ", responsavel_id = IF(responsavel_id IS NULL OR responsavel_id=0, {$user_id}, responsavel_id)"
                    : "")."
          WHERE id=? AND tenant_id=?{$shopSql}
        ";

        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        if ($st->affected_rows === 0) {
            $st->close();
            throw new Exception('OS não encontrada ou já atualizada.');
        }
        $st->close();

        // --- Update valores ---
        $sets  = [];
        $types = '';
        $vals  = [];

        if ($has_total) {
            $sets[] = "valor_total=?";
            $types .= 'd';
            $vals[] = $valor_confirmado;
        }

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
            $typesVals = $types . 'ii';
            $params2   = [...$vals, $id, $tenant_id];
            $shopSql2  = shop_clause($shop_id, $typesVals, $params2);

            $q = "
              UPDATE ordens_servico
                 SET ".implode(', ', $sets).",
                     atualizado_em=NOW()
               WHERE id=? AND tenant_id=?{$shopSql2}
            ";
            $st2 = $conn->prepare($q);
            $st2->bind_param($typesVals, ...$params2);
            $st2->execute();
            $st2->close();
        }

    } else {
        // Outros status: atualiza status e zera pagamento
        $types = 'sii';
        $params = [$status, $id, $tenant_id];
        $shopSql = shop_clause($shop_id, $types, $params);

        $sql = "
          UPDATE ordens_servico
             SET status=?,
                 pago=0,
                 metodo_pagamento=NULL,
                 data_conclusao=NULL,
                 atualizado_em=NOW()"
                 .($claim_responsavel
                    ? ", responsavel_id = IF(responsavel_id IS NULL OR responsavel_id=0, {$user_id}, responsavel_id)"
                    : "")."
          WHERE id=? AND tenant_id=?{$shopSql}
        ";

        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        if ($st->affected_rows === 0) {
            $st->close();
            throw new Exception('OS não encontrada ou já atualizada.');
        }
        $st->close();

        // Zera valores se existirem
        $sets = [];
        if ($has_total) $sets[] = "valor_total=0";
        if ($has_pix)   $sets[] = "valor_pix=0";
        if ($has_din)   $sets[] = "valor_dinheiro=0";
        if ($has_cred)  $sets[] = "valor_credito=0";
        if ($has_deb)   $sets[] = "valor_debito=0";

        if (!empty($sets)) {
            $types2 = 'ii';
            $params2 = [$id, $tenant_id];
            $shopSql2 = shop_clause($shop_id, $types2, $params2);

            $q = "
              UPDATE ordens_servico
                 SET ".implode(', ', $sets).",
                     atualizado_em=NOW()
               WHERE id=? AND tenant_id=?{$shopSql2}
            ";
            $st2 = $conn->prepare($q);
            $st2->bind_param($types2, ...$params2);
            $st2->execute();
            $st2->close();
        }
    }

    // ===== LOG da alteração =====
    insert_os_log($conn, [
        'tenant_id'        => $os_row['tenant_id'],
        'shop_id'          => $os_row['shop_id'] ?? null,
        'os_id'            => $os_row['id'],
        'user_id'          => $user_id,
        'acao'             => 'atualizar_status',
        'status_antigo'    => $old_status,
        'status_novo'      => $status,
        'valor_total'      => ($status === 'concluido' ? $valor_confirmado : null),
        'metodo_pagamento' => ($status === 'concluido' ? $metodo : null),
    ]);

    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo 'ERRO: ' . $e->getMessage();
    $conn->close();
    exit;
}

/* ───────── Notificação WhatsApp ───────── */
try {
    $shop_for_tpl = $shop_id ?: 0;
    $conf = load_shop_templates($conn, $tenant_id, $shop_for_tpl);
    $notify_auto = (int)($conf['notify_auto'] ?? 1) === 1;

    if ($notify_auto) {
        $os = carregar_os($conn, $id, $tenant_id, $shop_id);
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
                notificar_whatsapp_loja($conn, $tenant_id, $shop_for_tpl, $tel, $msg);
            }
        }
    }
} catch (Throwable $e) {
    // silencioso
}

$conn->close();
echo 'OK';
