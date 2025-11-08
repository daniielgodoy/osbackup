<?php
// includes/salvar_os.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/auth_guard.php';   // sessão + tenant/shop helpers
$tenant_id = require_tenant();               // trava tenant
$shop_id   = current_shop_id();              // pode ser null

require_once __DIR__ . '/mysqli.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ========= Helpers ========= */
function json_exit(array $payload, int $http = 200): void {
  http_response_code($http);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function post(string $k, $default = null) {
  return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $default;
}
function onlyDigits(string $s): string { return preg_replace('/\D+/', '', $s); }
function hasTable(mysqli $c, string $tab): bool {
  $tabEsc = $c->real_escape_string($tab);
  $r = $c->query("SHOW TABLES LIKE '{$tabEsc}'");
  return ($r && $r->num_rows > 0);
}
function hasCol(mysqli $c, string $tab, string $col): bool {
  $tabEsc = $c->real_escape_string($tab);
  $colEsc = $c->real_escape_string($col);
  $r = $c->query("SHOW COLUMNS FROM `{$tabEsc}` LIKE '{$colEsc}'");
  return ($r && $r->num_rows > 0);
}

/* ========= Entrada (campos do formulário) ========= */
// Cliente
$client_id   = (int) post('client_id', 0);
$nome        = (string) post('nome', '');
$telefone    = (string) post('telefone', '');
$cpf         = (string) post('cpf', '');
$email       = (string) post('email', '');

// Endereço detalhado
$cep         = (string) post('cep', '');
$logradouro  = (string) post('logradouro', '');
$numero      = (string) post('numero', '');
$bairro      = (string) post('bairro', '');
$cidade      = (string) post('cidade', '');
$uf          = strtoupper((string) post('uf', ''));
$endereco    = (string) post('endereco', ''); // montado no front

// OS
$modelo        = (string) post('modelo', '');
$servico       = (string) post('servico', '');
$data          = (string) post('data', ''); // YYYY-MM-DD
$hora          = (string) post('hora', ''); // HH:MM
$observacao    = (string) post('observacao', '');
$senha_padrao  = (string) post('senha_padrao', '');
$senha_escrita = (string) post('senha_escrita', '');

// Valores do formulário (apenas dois campos na criação)
$valor_dinheiro_pix_form = (float) post('valor_dinheiro_pix', 0);
$valor_cartao_form       = (float) post('valor_cartao', 0);

/* ========= Validações mínimas ========= */
if ($nome === '' || $telefone === '' || $modelo === '' || $servico === '' || $data === '' || $hora === '') {
  json_exit(['ok' => false, 'msg' => 'Campos obrigatórios ausentes. Preencha Nome, Telefone, Modelo, Serviço, Data e Hora.'], 400);
}

// Monta "endereco" se veio detalhado mas o oculto não
if ($endereco === '' && ($logradouro || $numero || $bairro || $cidade || $uf || $cep)) {
  $partes = [
    $logradouro,
    $numero ? ", {$numero}" : "",
    $bairro ? " — {$bairro}" : "",
    $cidade ? " — {$cidade}" : "",
    $uf ? "/{$uf}" : "",
    $cep ? " — CEP {$cep}" : "",
  ];
  $endereco = trim(implode('', $partes));
}

/* ========= Tabelas ========= */
$TAB_CLIENTES = 'clientes';
$TAB_OS       = 'ordens_servico';

if (!hasTable($conn, $TAB_OS)) {
  json_exit(['ok'=>false,'msg'=>"Tabela '{$TAB_OS}' não encontrada."], 500);
}

/* ========= Detecta colunas multi-tenant ========= */
$clients_has_tenant = hasCol($conn, $TAB_CLIENTES, 'tenant_id');
$clients_has_shop   = hasCol($conn, $TAB_CLIENTES, 'shop_id');
$os_has_tenant      = hasCol($conn, $TAB_OS, 'tenant_id');
$os_has_shop        = hasCol($conn, $TAB_OS, 'shop_id');

/* ========= Detecta esquema financeiro ========= */
// Prioriza 2 colunas (valor_dinheiro_pix + valor_cartao)
$os_has_vdp = hasCol($conn, $TAB_OS, 'valor_dinheiro_pix');
$os_has_vc  = hasCol($conn, $TAB_OS, 'valor_cartao');
$prefer_2cols = $os_has_vdp && $os_has_vc;

// Fallback 4 colunas
$os_has_pix      = hasCol($conn, $TAB_OS, 'valor_pix');
$os_has_dinheiro = hasCol($conn, $TAB_OS, 'valor_dinheiro');
$os_has_credito  = hasCol($conn, $TAB_OS, 'valor_credito');
$os_has_debito   = hasCol($conn, $TAB_OS, 'valor_debito');

/* ========= Transação ========= */
$conn->begin_transaction();
try {
  /* ----- Cliente (auto-criar se necessário) ----- */
  if (hasTable($conn, $TAB_CLIENTES)) {

    // 1) Tentativa por CPF dentro do contexto
    if ($client_id <= 0) {
      $cpfDigits = onlyDigits($cpf);
      if ($cpfDigits !== '' && strlen($cpfDigits) >= 11) {
        $where = "REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','') = ?";
        $types = 's';
        $vals  = [$cpfDigits];
        if ($clients_has_tenant) { $where .= " AND tenant_id = ?"; $types .= 'i'; $vals[] = $tenant_id; }
        if ($clients_has_shop && $shop_id !== null) { $where .= " AND shop_id = ?"; $types .= 'i'; $vals[] = (int)$shop_id; }

        $sql = "SELECT id FROM {$TAB_CLIENTES} WHERE {$where} LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $stmt->bind_result($foundId);
        if ($stmt->fetch()) $client_id = (int)$foundId;
        $stmt->close();
      }
    }

    // 2) Se ainda não há client_id, cria um novo (no contexto atual)
    if ($client_id <= 0 && ($nome !== '' || onlyDigits($cpf) !== '' || $telefone !== '')) {
      if ($cpf !== '') {
        $where = "cpf = ?";
        $types = 's';
        $vals  = [$cpf];
        if ($clients_has_tenant) { $where .= " AND tenant_id = ?"; $types .= 'i'; $vals[] = $tenant_id; }
        if ($clients_has_shop && $shop_id !== null) { $where .= " AND shop_id = ?"; $types .= 'i'; $vals[] = (int)$shop_id; }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM {$TAB_CLIENTES} WHERE {$where}");
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $stmt->bind_result($jaExiste);
        $stmt->fetch();
        $stmt->close();

        if ((int)$jaExiste === 0) {
          $cliCols = []; $ph = []; $typesI = ''; $valsI = [];
          if ($clients_has_tenant) { $cliCols[]='`tenant_id`'; $ph[]='?'; $typesI.='i'; $valsI[]=$tenant_id; }
          if ($clients_has_shop)   { $cliCols[]='`shop_id`';   $ph[]='?'; $typesI.='i'; $valsI[]=(int)$shop_id; }

          $map = [
            'nome'=>$nome,'cpf'=>$cpf,'telefone'=>$telefone,'email'=>$email,
            'cep'=>$cep,'logradouro'=>$logradouro,'numero'=>$numero,'bairro'=>$bairro,
            'cidade'=>$cidade,'uf'=>$uf,'endereco'=>$endereco,'data_cadastro'=>date('Y-m-d H:i:s'),
          ];
          foreach ($map as $col=>$val) {
            if (!hasCol($conn, $TAB_CLIENTES, $col)) continue;
            $cliCols[]="`{$col}`"; $ph[]='?'; $typesI.='s'; $valsI[]=(string)$val;
          }
          if ($cliCols) {
            $sql = "INSERT INTO `{$TAB_CLIENTES}` (".implode(',', $cliCols).") VALUES (".implode(',', $ph).")";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($typesI, ...$valsI);
            $stmt->execute();
            $client_id = (int)$stmt->insert_id;
            $stmt->close();
          }
        }
      } else {
        $cliCols = []; $ph = []; $typesI = ''; $valsI = [];
        if ($clients_has_tenant) { $cliCols[]='`tenant_id`'; $ph[]='?'; $typesI.='i'; $valsI[]=$tenant_id; }
        if ($clients_has_shop)   { $cliCols[]='`shop_id`';   $ph[]='?'; $typesI.='i'; $valsI[]=(int)$shop_id; }

        $map = [
          'nome'=>$nome,'telefone'=>$telefone,'email'=>$email,'endereco'=>$endereco,
          'cep'=>$cep,'logradouro'=>$logradouro,'numero'=>$numero,'bairro'=>$bairro,
          'cidade'=>$cidade,'uf'=>$uf,'data_cadastro'=>date('Y-m-d H:i:s'),
        ];
        foreach ($map as $col=>$val) {
          if (!hasCol($conn, $TAB_CLIENTES, $col)) continue;
          $cliCols[]="`{$col}`"; $ph[]='?'; $typesI.='s'; $valsI[]=(string)$val;
        }
        if ($cliCols) {
          $sql = "INSERT INTO `{$TAB_CLIENTES}` (".implode(',', $cliCols).") VALUES (".implode(',', $ph).")";
          $stmt = $conn->prepare($sql);
          $stmt->bind_param($typesI, ...$valsI);
          $stmt->execute();
          $client_id = (int)$stmt->insert_id;
          $stmt->close();
        }
      }
    }
  }

  /* ----- Conversão de valores para a OS (prioriza 2 colunas) ----- */
  // Inicializa todos como 0
  $valor_dinheiro_pix = 0.00;
  $valor_cartao       = 0.00;
  $valor_pix          = 0.00;
  $valor_dinheiro     = 0.00;
  $valor_credito      = 0.00;
  $valor_debito       = 0.00;

  if ($prefer_2cols) {
    // ✅ Esquema 2 colunas (o que você quer)
    $valor_dinheiro_pix = max(0.0, $valor_dinheiro_pix_form);
    $valor_cartao       = max(0.0, $valor_cartao_form);
    $valor_total        = $valor_dinheiro_pix + $valor_cartao;
  } else {
    // 🔁 Fallback 4 colunas
    $valor_dinheiro = max(0.0, $valor_dinheiro_pix_form); // entra como dinheiro
    $valor_credito  = max(0.0, $valor_cartao_form);       // entra como crédito
    $valor_total    = $valor_pix + $valor_dinheiro + $valor_credito + $valor_debito;
  }

  /* ----- Monta INSERT da OS (dinâmico) ----- */
  $osCols = []; $ph = []; $typesO = ''; $valsO = [];

  // Multi-tenant primeiro
  if ($os_has_tenant) { $osCols[]='`tenant_id`'; $ph[]='?'; $typesO.='i'; $valsO[]=$tenant_id; }
  if ($os_has_shop)   { $osCols[]='`shop_id`';   $ph[]='?'; $typesO.='i'; $valsO[]=(int)$shop_id; }

  $mapOS = [
    'client_id'    => ($client_id > 0 ? $client_id : null),
    'nome'         => $nome,
    'telefone'     => $telefone,
    'cpf'          => $cpf,
    'endereco'     => $endereco,
    'cep'          => $cep,
    'logradouro'   => $logradouro,
    'numero'       => $numero,
    'bairro'       => $bairro,
    'cidade'       => $cidade,
    'uf'           => $uf,

    'modelo'       => $modelo,
    'servico'      => $servico,
    'observacao'   => $observacao,

    'data_entrada' => $data,
    'hora_entrada' => $hora,

    'status'       => 'pendente',

    // 2 colunas (se existirem)
    'valor_dinheiro_pix' => $valor_dinheiro_pix,
    'valor_cartao'       => $valor_cartao,

    // 4 colunas (fallback)
    'valor_pix'      => $valor_pix,
    'valor_dinheiro' => $valor_dinheiro,
    'valor_credito'  => $valor_credito,
    'valor_debito'   => $valor_debito,

    'valor_total'    => $valor_total,

    // Método e pago ficam em aberto na criação
    'metodo_pagamento' => null,
    'pago'             => 0,

    'senha_padrao'   => $senha_padrao,
    'senha_escrita'  => $senha_escrita,

    'criado_em'      => date('Y-m-d H:i:s'),
    'atualizado_em'  => date('Y-m-d H:i:s'),
  ];

  foreach ($mapOS as $col => $val) {
    if (!hasCol($conn, $TAB_OS, $col)) continue; // só grava se a coluna existir

    $osCols[] = "`{$col}`";
    if ($val === null) { $ph[] = 'NULL'; continue; }

    if (in_array($col, [
      'valor_pix','valor_dinheiro','valor_credito','valor_debito',
      'valor_dinheiro_pix','valor_cartao','valor_total'
    ], true)) {
      $ph[]='?'; $typesO.='d'; $valsO[]=(float)$val;
    } elseif (in_array($col, ['pago','client_id'], true)) {
      $ph[]='?'; $typesO.='i'; $valsO[]=(int)$val;
    } else {
      $ph[]='?'; $typesO.='s'; $valsO[]=(string)$val;
    }
  }

  if (empty($osCols)) {
    throw new RuntimeException("Nenhuma coluna válida encontrada para inserir na tabela '{$TAB_OS}'.");
  }

  $sql = "INSERT INTO `{$TAB_OS}` (".implode(',', $osCols).") VALUES (".implode(',', $ph).")";
  $stmt = $conn->prepare($sql);
  if ($typesO !== '') $stmt->bind_param($typesO, ...$valsO);
  $stmt->execute();
  $newId = (int)$stmt->insert_id;
  $stmt->close();

  $conn->commit();

  json_exit(['ok'=>true, 'id'=>$newId]);
}
catch (Throwable $e) {
  $conn->rollback();
  json_exit(['ok'=>false, 'msg'=>'Erro ao salvar OS: '.$e->getMessage()], 500);
}
