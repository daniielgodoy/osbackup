<?php
// includes/atualizar_feed.php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$tenant_id = require_tenant();
$shop_id   = current_shop_id();
$user_id   = (int)($_SESSION['user_id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Não autenticado']);
    exit;
}

// Verifica se tabela de logs existe
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

    $types  = 'i';
    $params = [$tenant_id];

    if (!empty($shop_id)) {
        $sqlFeed   .= " AND l.shop_id = ?";
        $types     .= 'i';
        $params[]   = $shop_id;
    }

    $sqlFeed .= " ORDER BY l.criado_em DESC LIMIT 30";

    $stmt = $conn->prepare($sqlFeed);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $feedRows[] = $row;
    }

    $stmt->close();
}

function status_label(string $s): string {
    $map = [
        'pendente'            => 'Pendente',
        'em_andamento'        => 'Em andamento',
        'concluido'           => 'Concluído',
        'cancelado'           => 'Cancelado',
        'orcamento'           => 'Orçamento',
        'aguardando_retirada' => 'Aguardando retirada',
    ];
    return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
}

// Monta somente o HTML da <ul> para o feed
ob_start();
if ($hasLogs && !empty($feedRows)):
    foreach ($feedRows as $f):
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
    <?php
    endforeach;
else:
    ?>
    <li class="feed-empty">
      Sem movimentações hoje ainda. Assim que alguém alterar o status de uma OS, aparece aqui. 🙂
    </li>
<?php
endif;

$html = ob_get_clean();

echo json_encode([
    'ok'   => true,
    'html' => $html
], JSON_UNESCAPED_UNICODE);

$conn->close();
