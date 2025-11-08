<?php
// includes/deletar_os.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new Exception('Método inválido.');
  }

  require_once __DIR__ . '/auth_guard.php'; // ➜ require_tenant(), current_shop_id()
  require_once __DIR__ . '/mysqli.php';

  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $conn->set_charset('utf8mb4');

  $tenant_id = require_tenant();
  $shop_id   = current_shop_id(); // pode ser null dependendo do fluxo

  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) throw new Exception('ID inválido.');

  // Monta WHERE com isolamento
  $where = "id = ? AND tenant_id = ?";
  $types = 'ii';
  $args  = [$id, $tenant_id];
  if (!is_null($shop_id)) {
    $where .= " AND shop_id = ?";
    $types .= 'i';
    $args[] = $shop_id;
  }

  // Busca para validar existência e capturar pdf_path
  $sqlSel = "SELECT id, COALESCE(pdf_path,'') AS pdf_path FROM ordens_servico WHERE $where LIMIT 1";
  $stmt = $conn->prepare($sqlSel);
  // bind dinâmico por referência
  $refs = [];
  foreach ($args as $k => &$v) { $refs[$k] = &$v; }
  $stmt->bind_param($types, ...$refs);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  if (!$row) {
    throw new Exception('O.S. não encontrada no contexto atual.');
  }

  $pdf_path = trim($row['pdf_path'] ?? '');

  // Transação para garantir atomicidade
  $conn->begin_transaction();

  $sqlDel = "DELETE FROM ordens_servico WHERE $where";
  $stmt = $conn->prepare($sqlDel);
  // reusar mesmas refs
  $stmt->bind_param($types, ...$refs);
  $stmt->execute();
  $stmt->close();

  $conn->commit();

  // Tenta apagar o PDF (não falha a operação caso não consiga)
  $warn = null;
  if ($pdf_path !== '') {
    try {
      // Normaliza caminhos e restringe deleção a PDFs dentro do projeto
      $projectRoot = realpath(dirname(__DIR__)); // raiz do projeto (pasta acima de /includes)
      $full = $pdf_path;

      // Se for relativo, resolve contra a raiz do projeto
      if (!preg_match('~^(?:[a-zA-Z]:\\\\|/|\\\\)~', $full)) {
        $full = $projectRoot . DIRECTORY_SEPARATOR . ltrim($full, DIRECTORY_SEPARATOR);
      }

      $real = realpath($full);
      $isPdf = preg_match('~\.pdf$~i', $real ?? '');
      $insideProject = $real && $projectRoot && strncmp($real, $projectRoot, strlen($projectRoot)) === 0;

      if ($real && $isPdf && $insideProject && file_exists($real)) {
        @unlink($real);
      }
    } catch (Throwable $e) {
      $warn = 'PDF não pôde ser removido.';
    }
  }

  echo json_encode(['ok' => true, 'warn' => $warn], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  // Se algo deu errado, tenta rollback se a transação estiver ativa
  if (isset($conn) && $conn instanceof mysqli) {
    try { if ($conn->errno === 0) { /* noop */ } } catch (Throwable $_) {}
    try { $conn->rollback(); } catch (Throwable $_) {}
  }
  http_response_code(200);
  echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
