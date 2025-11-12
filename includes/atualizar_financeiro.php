<?php
// includes/atualizar_financeiro.php
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

$hoje = date('Y-m-d');

/**
 * Regra:
 * - Sempre: tenant_id [+ shop_id se tiver]
 * - Apenas OS CONCLUÍDAS no dia (data_conclusao OU atualizado_em OU data_entrada = hoje)
 * - ADMIN: vê tudo do tenant[/shop]
 * - MEMBER: vê SOMENTE OS dele (responsavel_id = user_id)
 */

$where  = "tenant_id = ? 
           AND status = 'concluido'
           AND DATE(COALESCE(data_conclusao, atualizado_em, data_entrada)) = ?";
$types  = 'is';
$params = [$tenant_id, $hoje];

if (!empty($shop_id)) {
    $where  .= " AND shop_id = ?";
    $types  .= 'i';
    $params[] = $shop_id;
}

if ($isMember) {
    // 🔐 financeiro exclusivo do colaborador
    $where  .= " AND responsavel_id = ?";
    $types  .= 'i';
    $params[] = $user_id;
}

// Helper
function stmt_bind(mysqli_stmt $stmt, string $types, array $params): void {
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
}

// Detecta colunas (compatibilidade)
function hasCol(mysqli $c, string $tab, string $col): bool {
    $r = $c->query("SHOW COLUMNS FROM `$tab` LIKE '$col'");
    return ($r && $r->num_rows > 0);
}

$has_pix      = hasCol($conn, 'ordens_servico', 'valor_pix');
$has_dinheiro = hasCol($conn, 'ordens_servico', 'valor_dinheiro');
$has_credito  = hasCol($conn, 'ordens_servico', 'valor_credito');
$has_debito   = hasCol($conn, 'ordens_servico', 'valor_debito');
$has_total    = hasCol($conn, 'ordens_servico', 'valor_total');

// Monta SELECT de forma segura
$select = [];

$select[] = $has_pix
    ? "ROUND(SUM(COALESCE(valor_pix,0)),2) AS total_pix"
    : "0 AS total_pix";

$select[] = $has_dinheiro
    ? "ROUND(SUM(COALESCE(valor_dinheiro,0)),2) AS total_dinheiro"
    : "0 AS total_dinheiro";

$select[] = $has_credito
    ? "ROUND(SUM(COALESCE(valor_credito,0)),2) AS total_credito"
    : "0 AS total_credito";

$select[] = $has_debito
    ? "ROUND(SUM(COALESCE(valor_debito,0)),2) AS total_debito"
    : "0 AS total_debito";

$select[] = $has_total
    ? "ROUND(SUM(COALESCE(valor_total,0)),2) AS total"
    : "ROUND(
         SUM(
           COALESCE(" . ($has_pix ? "valor_pix" : "0") . ",0) +
           COALESCE(" . ($has_dinheiro ? "valor_dinheiro" : "0") . ",0) +
           COALESCE(" . ($has_credito ? "valor_credito" : "0") . ",0) +
           COALESCE(" . ($has_debito ? "valor_debito" : "0") . ",0)
         ),2
       ) AS total";

$sql = "SELECT " . implode(",\n       ", $select) . "
        FROM ordens_servico
        WHERE $where";

$stmt = $conn->prepare($sql);
stmt_bind($stmt, $types, $params);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$total_pix      = (float)($row['total_pix']      ?? 0);
$total_dinheiro = (float)($row['total_dinheiro'] ?? 0);
$total_credito  = (float)($row['total_credito']  ?? 0);
$total_debito   = (float)($row['total_debito']   ?? 0);
$total          = (float)($row['total']          ?? 0);

$fmt = fn(float $v): string => number_format($v, 2, ',', '.');

echo json_encode([
    'total_pix'      => $fmt($total_pix),
    'total_dinheiro' => $fmt($total_dinheiro),
    'total_credito'  => $fmt($total_credito),
    'total_debito'   => $fmt($total_debito),
    'total_pago'     => $fmt($total),
]);
