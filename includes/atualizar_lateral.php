<?php
// includes/atualizar_lateral.php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/mysqli.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

try {
    $tenant_id = require_tenant();
    $shop_id   = current_shop_id();
    $user_id   = (int)($_SESSION['user_id'] ?? 0);
    $role      = $_SESSION['role'] ?? 'member';

    if ($user_id <= 0) {
        http_response_code(401);
        echo json_encode([
            'ok'  => false,
            'msg' => 'Não autenticado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== WHERE base (mesma regra do index.php atual) =====
    // Todos veem todas as OS do tenant / loja selecionada.
    $whereBase  = "tenant_id = ?";
    $typesBase  = 'i';
    $paramsBase = [$tenant_id];

    if (!empty($shop_id)) {
        $whereBase  .= " AND shop_id = ?";
        $typesBase  .= 'i';
        $paramsBase[] = $shop_id;
    }

    // Helper bind dinâmico
    function stmt_bind(mysqli_stmt $stmt, string $types, array $params): void {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
    }

    $hoje = date('Y-m-d');

    // ===== Contador lateral (igual ao index.php) =====
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

    $typesCnt  = $typesBase . 'ss';
    $paramsCnt = [...$paramsBase, $hoje, $hoje];

    $stmtCnt = $conn->prepare($sqlCnt);
    stmt_bind($stmtCnt, $typesCnt, $paramsCnt);
    $stmtCnt->execute();
    $rowCnt = $stmtCnt->get_result()->fetch_assoc() ?: [];
    $stmtCnt->close();

    $qtdHoje = (int)($rowCnt['total'] ?? 0);

    // ===== Lista lateral (igual à do index.php) =====
    $sqlLista = "
      SELECT
        id,
        nome,
        modelo,
        servico,
        status,
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

    $typesLista  = $typesBase . 'sss';
    $paramsLista = [...$paramsBase, $hoje, $hoje, $hoje];

    $stmt = $conn->prepare($sqlLista);
    stmt_bind($stmt, $typesLista, $paramsLista);
    $stmt->execute();
    $result = $stmt->get_result();

    ob_start();

    if ($result && $result->num_rows > 0) {
        // Cabeçalho com coluna "Imp." (ações) — PRECISA bater com o index.php
        echo '<div class="servicos-header-list">';
        echo '  <span class="col-id">OS</span>';
        echo '  <span class="col-nome">Nome</span>';
        echo '  <span class="col-modelo">Modelo</span>';
        echo '  <span class="col-servico">Serviço</span>';
        echo '  <span class="col-status">Status</span>';
        echo '  <span class="col-acoes">Imp.</span>';
        echo '</div>';

        echo '<ul class="lista-servicos">';

        $statuses = [
            'pendente'             => 'Pendente',
            'em_andamento'         => 'Em andamento',
            'concluido'            => 'Concluído',
            'cancelado'            => 'Cancelado',
            'orcamento'            => 'Orçamento',
            'aguardando_retirada'  => 'Aguardando retirada'
        ];

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

            // Select de status
            echo '  <select class="status-select" data-id="' . (int)$row['id'] . '">';
            foreach ($statuses as $key => $label) {
                $selected = ($statusAtual === $key) ? 'selected' : '';
                echo '<option value="' . $key . '" ' . $selected . '>' . $label . '</option>';
            }
            echo '  </select>';

            // Botão de impressão (Imp.) — MESMO HTML do index.php
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
    $html = ob_get_clean();

    echo json_encode([
        'ok'       => true,
        'contador' => $qtdHoje,
        'html'     => $html,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'  => false,
        'msg' => 'Erro ao carregar serviços.',
        'err' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
