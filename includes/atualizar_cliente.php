<?php
/* includes/atualizar_cliente.php */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* Zera QUALQUER buffer para garantir JSON puro */
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }
@ini_set('display_errors', '0');

require_once __DIR__ . '/auth_guard.php';
$tenant_id = require_tenant();          // obrigatório
$shop_id   = current_shop_id();         // pode ser null

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* Helpers */
function v(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  return ($s === '') ? null : $s;
}
function only_digits(?string $s): ?string {
  if ($s === null) return null;
  $r = preg_replace('/\D+/', '', $s);
  return ($r === null || $r === '') ? null : $r;
}

try {
  /* Entrada básica */
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id <= 0) throw new Exception('ID inválido.');

  /* Normalizações */
  $nome       = v($_POST['nome']       ?? null);
  $cpf        = only_digits(v($_POST['cpf']        ?? null));
  $telefone   = only_digits(v($_POST['telefone']   ?? null));
  $email      = v($_POST['email']      ?? null);
  $cep        = only_digits(v($_POST['cep']        ?? null));
  $logradouro = v($_POST['logradouro'] ?? null);
  $numero     = v($_POST['numero']     ?? null);
  $bairro     = v($_POST['bairro']     ?? null);
  $cidade     = v($_POST['cidade']     ?? null);
  $uf         = v($_POST['uf']         ?? null);
  if ($uf !== null) $uf = strtoupper($uf);

  /* UPDATE fixo (10 colunas) → evita mismatch de tipos */
  $sql = "UPDATE clientes SET
            nome=?, cpf=?, telefone=?, email=?, cep=?, logradouro=?, numero=?, bairro=?, cidade=?, uf=?
          WHERE id=? AND tenant_id=? " . (is_null($shop_id) ? "AND shop_id IS NULL " : "AND shop_id=? ") . "LIMIT 1";

  $stmt = $conn->prepare($sql);

  /* Monta tipos/args garantindo correspondência exata */
  // 10 's' + id(int) + tenant(int) + (shop opcional int)
  $types = 'ssssssssssii' . (is_null($shop_id) ? '' : 'i');

  // variáveis por referência (NULL é permitido com 's')
  $id_i      = (int)$id;
  $tenant_i  = (int)$tenant_id;
  if (is_null($shop_id)) {
    $stmt->bind_param(
      $types,
      $nome, $cpf, $telefone, $email, $cep, $logradouro, $numero, $bairro, $cidade, $uf,
      $id_i, $tenant_i
    );
  } else {
    $shop_i = (int)$shop_id;
    $stmt->bind_param(
      $types,
      $nome, $cpf, $telefone, $email, $cep, $logradouro, $numero, $bairro, $cidade, $uf,
      $id_i, $tenant_i, $shop_i
    );
  }

  $stmt->execute();
  $aff = $stmt->affected_rows;
  $stmt->close();

  echo json_encode(['ok'=>true, 'affected'=>$aff], JSON_UNESCAPED_UNICODE);
  exit;

} catch (mysqli_sql_exception $e) {
  // 1062 = chave única (por exemplo uq_clientes_tenant_shop_cpf)
  if ((int)$e->getCode() === 1062) {
    echo json_encode(['ok'=>false,'msg'=>'CPF já cadastrado nesta loja/empresa.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;

} finally {
  if (isset($conn) && $conn instanceof mysqli) { @$conn->close(); }
}
