<?php
// Cargar configuración
require_once 'config.php';

// Obtener conexión PDO
$pdo = getDBConnection();

$resultado = $_GET['resultado'] ?? '';
$cliente = $_GET['cliente'] ?? CLIENTE_ACTUAL;

if (empty($resultado)) {
    echo json_encode(['success' => false, 'error' => 'Resultado no especificado']);
    exit;
}

// Si el resultado es "Llamadas colgadas", buscar llamadas AVI sin resultado
if ($resultado === 'Llamadas colgadas') {
    // Obtener todas las llamadas AVI del día que NO tienen resultado
    // Usamos avi_call_costs y luego buscamos el detalle en avi_calls
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.*
        FROM avi_calls a
        WHERE a.cliente = :cliente
        AND DATE(a.F9TimeStamp) = CURDATE()
        AND EXISTS (
            SELECT 1 FROM avi_call_costs ac
            WHERE ac.F9CallID = a.F9CallID
            AND ac.cliente = :cliente
            AND DATE(ac.metadata_date_local) = CURDATE()
        )
        AND NOT EXISTS (
            SELECT 1 FROM siigo_lead_data_v2 s
            WHERE s.F9CallID = a.F9CallID
            AND s.method = 'ResultadoPerfil'
            AND s.clave = 'resultado_llamada'
        )
        ORDER BY a.F9TimeStamp DESC
    ");
    $stmt->execute([':cliente' => $cliente]);
} else {
    // Buscar llamadas con el resultado específico
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.*
        FROM avi_calls a
        INNER JOIN siigo_lead_data_v2 s ON a.F9CallID = s.F9CallID
        WHERE a.cliente = :cliente
        AND DATE(a.F9TimeStamp) = CURDATE()
        AND s.method = 'ResultadoPerfil'
        AND s.clave = 'resultado_llamada'
        AND s.valor = :resultado
        ORDER BY a.F9TimeStamp DESC
    ");
    $stmt->execute([
        ':cliente' => $cliente,
        ':resultado' => $resultado
    ]);
}

$llamadas = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'llamadas' => $llamadas,
    'total' => count($llamadas)
]);
