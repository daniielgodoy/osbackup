<?php
/* includes/excluir_cliente.php */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();      // obrigatório
$shop_id   = current_shop_id();     // pode ser null

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* limpa buffers antes de responder JSON */
if (function_exists('ob_get_level') && ob_get_level()) { @ob_clean(); }

/**
 * Modo de operação:
 * - padrão: deletar
 * - "anonymize": em caso de FK (erro 1451), sanitiza os dados em vez de excluir
 */
$mode = strtolower(trim($_POST['mode'] ?? 'delete')); // 'delete' | 'anonymize'

/* aceita ID por POST (preferido) ou GET */
$id = 0;
if (isset($_POST['id'])) $id = (int)$_POST['id'];
elseif (isset($_GET['id'])) $id = (int)$_GET['id'];

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'msg'=>'ID inválido']);
  exit;
}

/* monta cláusula shop isolada */
$shopClause = is_null($shop_id) ? 'shop_id IS NULL' : 'shop_id = ?';

try {
  // 1) Confere se existe e pertence ao tenant/loja atuais
  $sqlCheck = "SELECT id FROM clientes WHERE id = ? AND tenant_id = ? AND $shopClause LIMIT 1";
  $stmt = $conn->prepare($sqlCheck);
  if (is_null($shop_id)) {
    $stmt->bind_param('ii', $id, $tenant_id);
  } else {
    $stmt->bind_param('iii', $id, $tenant_id, $shop_id);
  }
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    http_response_code(404);
    echo json_encode(['ok'=>false, 'msg'=>'Cliente não encontrado para esta loja/empresa']);
    exit;
  }

  // 2) Tenta excluir
  $sqlDel = "DELETE FROM clientes WHERE id = ? AND tenant_id = ? AND $shopClause";
  $stmt = $conn->prepare($sqlDel);
  if (is_null($shop_id)) {
    $stmt->bind_param('ii', $id, $tenant_id);
  } else {
    $stmt->bind_param('iii', $id, $tenant_id, $shop_id);
  }

  try {
    $stmt->execute();
    $aff = $stmt->affected_rows ?? 0;
    $stmt->close();

    if ($aff > 0) {
      echo json_encode(['ok'=>true]);
      exit;
    }

    // Se não afetou linhas, é porque não corresponde ao escopo (muito raro aqui)
    echo json_encode(['ok'=>false,'msg'=>'Nada para excluir (escopo diferente)']);
    exit;

  } catch (mysqli_sql_exception $e) {
    // 1451 = Cannot delete or update a parent row: a foreign key constraint fails
    if ((int)$e->getCode() === 1451 || stripos($e->getMessage(), 'foreign key constraint') !== false) {
      $stmt->close();

      if ($mode === 'anonymize') {
        // 3) Alternativa: anonimizar o cliente para manter integridade
        $sqlAnon = "
          UPDATE clientes
             SET nome = CONCAT('[EXCLUIDO #', id, ']'),
                 cpf = NULL,
                 telefone = NULL,
                 email = NULL,
                 cep = NULL,
                 logradouro = NULL,
                 numero = NULL,
                 complemento = NULL,
                 bairro = NULL,
                 cidade = NULL,
                 uf = NULL,
                 observacao = CONCAT(COALESCE(observacao,''),' [anonimizado em ', NOW(), ']')
           WHERE id = ? AND tenant_id = ? AND $shopClause
        ";
        $stmt2 = $conn->prepare($sqlAnon);
        if (is_null($shop_id)) {
          $stmt2->bind_param('ii', $id, $tenant_id);
        } else {
          $stmt2->bind_param('iii', $id, $tenant_id, $shop_id);
        }
        $stmt2->execute();
        $aff2 = $stmt2->affected_rows ?? 0;
        $stmt2->close();

        if ($aff2 > 0) {
          echo json_encode(['ok'=>true, 'anonimized'=>true]);
          exit;
        }

        echo json_encode(['ok'=>false, 'msg'=>'Não foi possível anonimizar o cliente.']);
        exit;
      }

      // Retorna dica para usar o modo anonymize
      http_response_code(409);
      echo json_encode([
        'ok'  => false,
        'msg' => 'Cliente possui vínculos (ex.: OS). Não é possível excluir. Envie mode=anonymize para anonimizar.'
      ]);
      exit;
    }

    // Outros erros SQL
    throw $e;
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
} finally {
  if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
}
