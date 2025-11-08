<?php
declare(strict_types=1);

// 1) Guard de sessão/tenant/loja
require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();             // trava tenant na sessão
$shop_id   = current_shop_id();            // pode ser null

// 2) DB
require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// 3) Datas base
date_default_timezone_set('America/Sao_Paulo');
$hoje = date('Y-m-d');

// 4) SQL com escopo (tenant + loja opcional)
$sql = "
  SELECT
    SUM(status='em_andamento') AS andamento,
    SUM(status='pendente')     AS pendente,
    SUM(status='orcamento')    AS orcamento,
    SUM(status='aguardando_retirada'
        AND (DATE(data_entrada)=? OR DATE(COALESCE(atualizado_em,'0000-00-00'))=?)
    ) AS aguardando,
    SUM(status='concluido'
        AND (DATE(data_entrada)=? OR DATE(COALESCE(atualizado_em,'0000-00-00'))=?)
    ) AS concluidos
  FROM ordens_servico
  WHERE tenant_id = ?
";
if ($shop_id !== null) { $sql .= " AND shop_id = ?"; }

$stmt = $conn->prepare($sql);
if ($shop_id !== null) {
  $stmt->bind_param('ssssii', $hoje,$hoje,$hoje,$hoje,$tenant_id,$shop_id);
} else {
  $stmt->bind_param('ssss i', $hoje,$hoje,$hoje,$hoje,$tenant_id);
}
$stmt->execute();
$R = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok'         => true,
  'andamento'  => (int)($R['andamento']  ?? 0),
  'pendente'   => (int)($R['pendente']   ?? 0),
  'orcamento'  => (int)($R['orcamento']  ?? 0),
  'aguardando' => (int)($R['aguardando'] ?? 0),
  'concluidos' => (int)($R['concluidos'] ?? 0),
], JSON_UNESCAPED_UNICODE);
