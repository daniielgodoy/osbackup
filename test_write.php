<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = __DIR__;
$dir  = $base . DIRECTORY_SEPARATOR . 'pedidos';
$file = $dir . DIRECTORY_SEPARATOR . 'teste_' . date('Ymd_His') . '.txt';

if (!is_dir($dir)) {
  echo "Pasta /pedidos não existe. Tentando criar em: $dir<br>";
  @mkdir($dir, 0775, true);
}

echo "Base: $base<br>";
echo "Dir pedidos: $dir<br>";
echo "É diretório? " . (is_dir($dir) ? 'SIM' : 'NÃO') . "<br>";
echo "É gravável? " . (is_writable($dir) ? 'SIM' : 'NÃO') . "<br>";

$ok = @file_put_contents($file, "ok ".date('c'));
echo $ok !== false
  ? "Arquivo criado: $file"
  : "FALHA ao salvar. Último erro: " . print_r(error_get_last(), true);
