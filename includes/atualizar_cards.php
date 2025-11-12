<?php
declare(strict_types=1);

// ================== Contexto / Sessão ==================
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$tenant_id = require_tenant();          // obrigatório
$shop_id   = current_shop_id();         // pode ser null
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role      = $_SESSION['role'] ?? 'member';

if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok'    => false,
        'error' => 'Sessão expirada.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== Data base ==================
date_default_timezone_set('America/Sao_Paulo');
$hoje = date('Y-m-d');

// ================== WHERE base (igual index.php atualizado) ==================
// Todos veem todas as OS do tenant / loja selecionada.
// Nada de filtro por usuário/role aqui.
$whereBase  = "tenant_id = ?";
$typesBase  = 'i';
$paramsBase = [$tenant_id];

if (!empty($shop_id)) {
    $whereBase  .= " AND shop_id = ?";
    $typesBase  .= 'i';
    $paramsBase[] = $shop_id;
}

// ================== Query de Resumo ==================
/*
  Regras:
    - andamento, pendente, orcamento → seguem $whereBase (sem recorte de data)
    - aguardando_retirada, concluido → contam se (data_entrada = hoje OU atualizado_em = hoje),
      também respeitando $whereBase.
*/
$sql = "
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
    ) AS concluidos
  FROM ordens_servico
  WHERE $whereBase
";

$types  = 'ssss' . $typesBase;
$params = [$hoje, $hoje, $hoje, $hoje, ...$paramsBase];

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$R = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

// ================== Resposta ==================
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'         => true,
    'andamento'  => (int)($R['andamento']  ?? 0),
    'pendente'   => (int)($R['pendente']   ?? 0),
    'orcamento'  => (int)($R['orcamento']  ?? 0),
    'aguardando' => (int)($R['aguardando'] ?? 0),
    'concluidos' => (int)($R['concluidos'] ?? 0),
], JSON_UNESCAPED_UNICODE);
