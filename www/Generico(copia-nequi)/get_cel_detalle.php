<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// Configuración de conexión a MariaDB
$host = 'phone.icq24.com';
$port = '3306';
$dbname = 'asteriskcdrdb';
$username = 'gonzaloq';
$password = '73ch$iCC';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

$linkedid = $_GET['linkedid'] ?? '';

if (empty($linkedid)) {
    echo json_encode(['error' => 'linkedid es requerido']);
    exit;
}

try {
    $sql = "SELECT * FROM cel WHERE linkedid = :linkedid ORDER BY eventtime";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':linkedid', $linkedid);
    $stmt->execute();
    $eventos = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $eventos]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error en consulta: ' . $e->getMessage()]);
}
