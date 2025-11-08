<?php
// =====================================================
// Conexão com o banco de dados v10_os
// =====================================================
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "v10_os_bckp";

// Cria conexão
$conn = new mysqli($host, $user, $pass, $dbname);

// Verifica erro de conexão
if ($conn->connect_error) {
  die("Falha na conexão com o banco de dados: " . $conn->connect_error);
}

// Define charset UTF-8
$conn->set_charset("utf8mb4");
?>
