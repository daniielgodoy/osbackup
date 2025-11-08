<?php
// tools/seed_os.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../includes/mysqli.php';
$conn->set_charset('utf8mb4');

/* ------------------ parâmetros ------------------ */
$n     = max(1, (int)($_GET['n']     ?? 200));   // quantidade
$days  = max(1, (int)($_GET['days']  ?? 120));   // janela (dias)
$mix   = !empty($_GET['status_mix']);            // mistura status?

/* ------------------ helpers ------------------ */
function randCpf(): string {
  $num = '';
  for ($i=0;$i<11;$i++) $num .= (string)random_int(0,9);
  return $num; // sem máscara (você pediu CPF sem pontuação)
}
function randPhone(): string {
  // formato (DD) 9XXXX-XXXX sem pontuação na base (só números)
  $ddd = str_pad((string)random_int(11, 99), 2, '0', STR_PAD_LEFT);
  $rest = str_pad((string)random_int(900000000, 999999999), 9, '0', STR_PAD_LEFT);
  return $ddd.$rest;
}
function brFloat($min, $max){
  return round(mt_rand($min*100, $max*100) / 100, 2);
}
function splitPagamento(float $total): array {
  // escolhe entre 1 e 3 meios aleatórios e reparte o total
  $meios = ['dinheiro','pix','credito','debito'];
  shuffle($meios);
  $k = random_int(1, 3);
  $sel = array_slice($meios, 0, $k);

  $rest = $total;
  $parts = [];
  for ($i=0; $i<$k; $i++){
    if ($i === $k-1) {
      $v = round($rest, 2);
    } else {
      $maxPart = max(0.01, $rest - 0.01*($k-$i-1));
      $v = round(min($maxPart, brFloat(0.01, $maxPart)), 2);
    }
    $parts[$sel[$i]] = $v;
    $rest -= $v;
  }
  // normaliza chaves
  return [
    'valor_dinheiro' => $parts['dinheiro'] ?? 0.00,
    'valor_pix'      => $parts['pix']      ?? 0.00,
    'valor_credito'  => $parts['credito']  ?? 0.00,
    'valor_debito'   => $parts['debito']   ?? 0.00,
  ];
}

/* ------------------ dados fake ------------------ */
$nomes = [
  'Ana Paula Silva','João Lucas','Mariana Oliveira','Pedro Almeida','Bruna Santos',
  'Rafael Costa','Camila Rocha','Gustavo Nunes','Luana Ribeiro','Felipe Teixeira',
  'Aline Souza','Thiago Martins','Letícia Carvalho','Daniela Pires','Vinícius Moreira',
  'Carla Freitas','Matheus Araújo','Patrícia Lima','Rodrigo Barros','Isabela Monteiro',
  'Pamela Ferreira','Henrique Duarte','Nathalia Campos','Anderson Brito','Elaine Alves',
  'Fabio Fernandes','Gabriela Ramos','Hugo Rezende','Ingrid Falcão','Juliana Prado',
  'Kleber Santana','Larissa Rezende','Maurício Torres','Nicole Siqueira','Otávio Prado',
  'Paulo César','Queila Moraes','Renan Tavares','Sabrina Correia','Tatiane Nogueira',
  'Uelinton Moraes','Valéria Dias','Wellington Silva','Yasmin Duarte','Zé Roberto',
];
$modelos = [
  'iPhone 11','iPhone 12','iPhone 13','iPhone XR','Samsung A20','Samsung A32',
  'Samsung S10','Samsung S21','Moto G10','Moto G20','Moto G50','Redmi Note 8',
  'Redmi Note 10','Poco X3','Realme 7','Nokia 5.3','Asus Zenfone 6',
];
$servicos = [
  'Troca de tela','Troca de bateria','Conector de carga','Alto-falante','Câmera traseira',
  'Câmera frontal','Microfone','Tampa traseira','Substituição flex power',
  'Substituição flex sub','Lente da câmera','Reparo Wi-Fi','Limpeza interna',
  'Atualização de sistema','Recuperação de software','Substituição gaveta SIM',
  'Aplicação de película','Troca de carcaça','Higienização geral',
];

/* ------------------ status pool ------------------ */
$statuses = $mix
  ? ['concluido','em_andamento','pendente','orcamento','aguardando_retirada']
  : ['concluido'];

/* ------------------ statement ------------------ */
/* Obs: usamos apenas colunas “seguras” e comuns do seu projeto.
   Se alguma não existir na sua tabela, comente-a nas linhas abaixo e no bind. */
$sql = "INSERT INTO ordens_servico (
  nome, cpf, telefone, modelo, servico, observacao,
  data_entrada, hora_entrada,
  valor_pix, valor_dinheiro, valor_credito, valor_debito,
  pago, status, atualizado_em
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmt = $conn->prepare($sql);

/* ------------------ geração ------------------ */
$ok = 0; $fail = 0; $errors = [];

for ($i=0; $i<$n; $i++){
  $nome  = $nomes[array_rand($nomes)];
  $cpf   = randCpf();
  $tel   = randPhone();
  $mod   = $modelos[array_rand($modelos)];
  $srv   = $servicos[array_rand($servicos)];
  $obs   = (random_int(0,3)===0) ? 'Gerado para teste' : '';

  $ts = time() - random_int(0, $days)*86400 - random_int(0, 86399);
  $data = date('Y-m-d', $ts);
  $hora = date('H:i',   $ts);

  $status = $statuses[array_rand($statuses)];
  $pago   = ($status === 'concluido') ? 1 : random_int(0,1); // concluído tende a 1

  // total entre 50 e 1500
  $total = brFloat(50, 1500);

  // divide entre métodos
  $split = splitPagamento($total);

  // atualizado_em: se concluído, joga em data próxima à data_entrada; senão, espalha
  $atuTS = $ts + random_int(0, 3)*86400 + random_int(0, 86400);
  $atualizado = date('Y-m-d H:i:s', $atuTS);

  try {
    $stmt->bind_param(
      'ssssssssdddisss',
      $nome, $cpf, $tel, $mod, $srv, $obs,
      $data, $hora,
      $split['valor_pix'], $split['valor_dinheiro'], $split['valor_credito'], $split['valor_debito'],
      $pago, $status, $atualizado
    );
    $stmt->execute();
    $ok++;
  } catch (Throwable $e){
    $fail++;
    $errors[] = $e->getMessage();
  }
}

echo json_encode([
  'ok' => true,
  'inseridos' => $ok,
  'falhas'    => $fail,
  'obs'       => 'Use ?n=300&days=180&status_mix=1 para personalizar.',
  'erros'     => array_slice($errors, 0, 5) // mostra só os 5 primeiros
], JSON_UNESCAPED_UNICODE);
