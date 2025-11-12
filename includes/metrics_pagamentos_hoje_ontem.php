<?php
// includes/metrics_pagamentos_hoje_ontem.php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Contexto
$tenant_id = require_tenant();
$shop_id   = current_shop_id();
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role      = $_SESSION['role'] ?? 'member';

$isAdmin  = ($role === 'admin');
$isMember = ($role === 'member');

if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado.']);
    exit;
}

$hoje  = date('Y-m-d');
$ontem = date('Y-m-d', strtotime('-1 day'));

/**
 * Regra:
 * - Sempre: tenant_id [+ shop_id se tiver]
 * - Apenas status = 'concluido'
 * - Datas consideradas: ontem e hoje (por data_conclusao / atualizado_em / data_entrada)
 * - ADMIN: tudo
 * - MEMBER: apenas responsavel_id = user_id
 */

$whereBase  = "tenant_id = ?";
$typesBase  = 'i';
$paramsBase = [$tenant_id];

if (!empty($shop_id)) {
    $whereBase  .= " AND shop_id = ?";
    $typesBase  .= 'i';
    $paramsBase[] = $shop_id;
}

if ($isMember) {
    // 🔐 member enxerga apenas as próprias OS nos gráficos
    $whereBase  .= " AND responsavel_id = ?";
    $typesBase  .= 'i';
    $paramsBase[] = $user_id;
}

// Helper
function stmt_bind(mysqli_stmt $stmt, string $types, array $params): void {
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
}

// Detecta colunas
function hasCol(mysqli $c, string $tab, string $col): bool {
    $r = $c->query("SHOW COLUMNS FROM `$tab` LIKE '$col'");
    return ($r && $r->num_rows > 0);
}

$has_pix      = hasCol($conn, 'ordens_servico', 'valor_pix');
$has_dinheiro = hasCol($conn, 'ordens_servico', 'valor_dinheiro');
$has_credito  = hasCol($conn, 'ordens_servico', 'valor_credito');
$has_debito   = hasCol($conn, 'ordens_servico', 'valor_debito');

$sel_pix = $has_pix
    ? "ROUND(SUM(COALESCE(valor_pix,0)),2)"
    : "0";
$sel_din = $has_dinheiro
    ? "ROUND(SUM(COALESCE(valor_dinheiro,0)),2)"
    : "0";
$sel_cred = $has_credito
    ? "ROUND(SUM(COALESCE(valor_credito,0)),2)"
    : "0";
$sel_deb = $has_debito
    ? "ROUND(SUM(COALESCE(valor_debito,0)),2)"
    : "0";

$sql = "
  SELECT
    DATE(COALESCE(data_conclusao, atualizado_em, data_entrada)) AS dia,
    $sel_pix      AS total_pix,
    $sel_din      AS total_dinheiro,
    $sel_cred     AS total_credito,
    $sel_deb      AS total_debito
  FROM ordens_servico
  WHERE $whereBase
    AND status = 'concluido'
    AND DATE(COALESCE(data_conclusao, atualizado_em, data_entrada)) IN (?,?)
  GROUP BY dia
";

$types  = $typesBase . 'ss';
$params = [...$paramsBase, $ontem, $hoje];

$stmt = $conn->prepare($sql);
stmt_bind($stmt, $types, $params);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$data = [
    'pix' => [
        'ontem' => 0.00,
        'hoje'  => 0.00,
    ],
    'dinheiro' => [
        'ontem' => 0.00,
        'hoje'  => 0.00,
    ],
    'credito' => [
        'ontem' => 0.00,
        'hoje'  => 0.00,
    ],
    'debito' => [
        'ontem' => 0.00,
        'hoje'  => 0.00,
    ],
];

while ($row = $res->fetch_assoc()) {
    $dia = $row['dia'];

    $isOntem = ($dia === $ontem);
    $isHoje  = ($dia === $hoje);

    if (!$isOntem && !$isHoje) {
        continue;
    }

    $k = $isOntem ? 'ontem' : 'hoje';

    $data['pix'][$k]       = (float)($row['total_pix']      ?? 0);
    $data['dinheiro'][$k]  = (float)($row['total_dinheiro'] ?? 0);
    $data['credito'][$k]   = (float)($row['total_credito']  ?? 0);
    $data['debito'][$k]    = (float)($row['total_debito']   ?? 0);
}

echo json_encode($data);
