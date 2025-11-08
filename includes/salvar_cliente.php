<?php
declare(strict_types=1);

/**
 * includes/salvar_cliente.php
 * Entrada: POST com campos de cliente.
 * Saída: JSON { ok: bool, id?: int, msg?: string }
 *
 * Requisitos:
 * - auth_guard.php  => require_tenant(), current_shop_id()
 * - mysqli.php      => $conn (mysqli)
 *
 * Observação sobre unicidade de CPF:
 *   Recomendado ter um índice único por (tenant_id, shop_id, cpf):
 *   ALTER TABLE clientes 
 *     DROP INDEX IF EXISTS cpf,
 *     DROP INDEX IF EXISTS uq_clientes_cpf,
 *     ADD UNIQUE KEY uq_clientes_tenant_shop_cpf (tenant_id, shop_id, cpf);
 */

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();          // obrigatório
$shop_id   = current_shop_id();         // pode ser null

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* limpa buffers antes de responder JSON */
if (function_exists('ob_get_level') && ob_get_level()) { @ob_clean(); }

/* Helpers de saneamento */
function only_digits(string $s): string {
  $r = preg_replace('/\D+/', '', $s);
  return $r === null ? '' : $r;
}
function null_if_empty(?string $s): ?string {
  $s = trim((string)($s ?? ''));
  return ($s === '') ? null : $s;
}

try {
  // --------- Captura & normalização ---------
  $nome       = trim($_POST['nome'] ?? '');
  $cpf_raw    = trim($_POST['cpf'] ?? '');
  $tel_raw    = trim($_POST['telefone'] ?? '');
  $email      = trim($_POST['email'] ?? '');
  $cep_raw    = trim($_POST['cep'] ?? '');
  $logradouro = trim($_POST['logradouro'] ?? '');
  $numero     = trim($_POST['numero'] ?? '');
  $bairro     = trim($_POST['bairro'] ?? '');
  $cidade     = trim($_POST['cidade'] ?? '');
  $uf         = strtoupper(trim($_POST['uf'] ?? ''));
  // opcionais não presentes no form
  $complemento = null_if_empty($_POST['complemento'] ?? null);
  $observacao  = null_if_empty($_POST['observacao']  ?? null);

  if ($nome === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'msg'=>'Nome é obrigatório']);
    exit;
  }

  // Normalizações leves
  $cpf      = only_digits($cpf_raw);         // deixa vazio se não vier
  $telefone = only_digits($tel_raw);
  $cep      = only_digits($cep_raw);
  $uf       = $uf !== '' ? $uf : null;

  // --------- Pré-checagem de duplicidade no MESMO tenant/loja ---------
  if ($cpf !== '') {
    $sqlDup = "SELECT id FROM clientes
               WHERE tenant_id = ?
                 AND ".(is_null($shop_id) ? "shop_id IS NULL" : "shop_id = ?")."
                 AND cpf = ?
               LIMIT 1";
    $stmtDup = $conn->prepare($sqlDup);
    if (is_null($shop_id)) {
      $stmtDup->bind_param('is', $tenant_id, $cpf);
    } else {
      $stmtDup->bind_param('iis', $tenant_id, $shop_id, $cpf);
    }
    $stmtDup->execute();
    $resDup = $stmtDup->get_result();
    if ($row = $resDup->fetch_assoc()) {
      $stmtDup->close();
      echo json_encode([
        'ok'  => false,
        'msg' => 'Já existe um cliente com esse CPF nesta loja.',
        'id'  => (int)$row['id']
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $stmtDup->close();
  }

  // --------- Insert (campos compatíveis com sua tabela) ---------
  // Vamos inserir NULL para complemento/observacao se não enviados.
  $sql = "INSERT INTO clientes (
            tenant_id, shop_id, nome, cpf, telefone, email, cep,
            logradouro, numero, complemento, bairro, cidade, uf, observacao, data_cadastro
          ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, NOW()
          )";

  // Tipos:
  // i (tenant) | i (shop) | s s s s s | s s s s s s s
  // Total: 14 variáveis
  $stmt = $conn->prepare($sql);

  // Atenção: para permitir NULL, as variáveis DEVEM existir (por referência).
  $tenant_id_i = (int)$tenant_id;
  // $shop_id pode ser null; bind_param aceita null normalmente.
  $shop_id_i   = $shop_id; // int|null

  $stmt->bind_param(
    'iissssssssssss',
    $tenant_id_i, $shop_id_i,
    $nome, $cpf, $telefone, $email, $cep,
    $logradouro, $numero, $complemento, $bairro, $cidade, $uf, $observacao
  );

  $stmt->execute();
  $novo_id = (int)$stmt->insert_id;
  $stmt->close();

  echo json_encode(['ok'=>true, 'id'=>$novo_id], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  // Tratamento específico para chave duplicada (1062)
  // Pode acontecer se existir um índice único apenas em CPF no banco.
  if (method_exists($e, 'getCode') && (int)$e->getCode() === 1062) {
    http_response_code(409);
    echo json_encode([
      'ok'=>false,
      'msg'=>'CPF já cadastrado (possível conflito de índice global). Recomenda-se chave única por (tenant_id, shop_id, cpf).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  http_response_code(500);
  echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
} finally {
  if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
}
