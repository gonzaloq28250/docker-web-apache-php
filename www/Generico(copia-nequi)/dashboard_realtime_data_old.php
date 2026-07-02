<?php
// API para obtener datos de REALTIME sin recargar toda la página
header('Content-Type: application/json');

require_once 'config.php';

// Establecer zona horaria a Puerto Rico
date_default_timezone_set('America/Puerto_Rico');

$pdo = getDBConnection();
$clienteActual = CLIENTE_ACTUAL;
$fechaHoy = date('Y-m-d');

// Llamadas Five9 (hoy)
$stmtFive9Hoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_calls
    WHERE cliente = :cliente
    AND DATE(F9TimeStamp) = :fecha_hoy
");
$stmtFive9Hoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$totalLlamadasFive9Hoy = $stmtFive9Hoy->fetch()['total'];

// Llamadas AVI (hoy)
$stmtAVIHoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_call_costs
    WHERE cliente = :cliente
    AND DATE(metadata_date_local) = :fecha_hoy
");
$stmtAVIHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$totalLlamadasAVIHoy = $stmtAVIHoy->fetch()['total'];

// Duración Total (hoy)
$stmtDuracionTotalHoy = $pdo->prepare("
    SELECT SUM(connection_duration_secs) as total
    FROM avi_call_costs
    WHERE cliente = :cliente
    AND DATE(metadata_date_local) = :fecha_hoy
");
$stmtDuracionTotalHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$duracionTotalSecsHoy = $stmtDuracionTotalHoy->fetch()['total'] ?? 0;

// Duración Promedio (hoy)
$duracionPromedioSecsHoy = $totalLlamadasAVIHoy > 0 ? $duracionTotalSecsHoy / $totalLlamadasAVIHoy : 0;

// Llamadas en Curso (hoy)
$stmtEnCursoHoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_calls
    WHERE cliente = :cliente
    AND Estado = 'IN-PROGRESS'
    AND DATE(F9TimeStamp) = :fecha_hoy
");
$stmtEnCursoHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$llamadasEnCursoHoy = $stmtEnCursoHoy->fetch()['total'];

// Gráfico Pie: Tipo de llamadas (hoy)
$stmtResultadosHoy = $pdo->prepare("
    SELECT
        res.valor as resultado,
        COUNT(DISTINCT r.F9CallID) as total
    FROM siigo_lead_data_v2 r
    INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID
        AND res.clave = 'resultado_llamada'
        AND res.method = 'ResultadoPerfil'
    INNER JOIN siigo_lead_data_v2 ts ON r.F9CallID = ts.F9CallID
        AND ts.clave = 'F9TimeStamp'
    INNER JOIN siigo_lead_data_v2 c ON r.F9CallID = c.F9CallID
        AND c.clave = 'CLIENTE'
        AND c.valor = :cliente
    WHERE DATE(ts.valor) = :fecha_hoy
    GROUP BY res.valor
    ORDER BY total DESC
");
$stmtResultadosHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$resultadosHoy = $stmtResultadosHoy->fetchAll();

// Calcular totales para el gráfico de hoy
$totalResultadosHoy = array_sum(array_column($resultadosHoy, 'total'));
// Llamadas colgadas = Five9 - AVI (llamadas que entraron pero no completaron el proceso AVI)
$colgadasHoy = max(0, $totalLlamadasFive9Hoy - $totalLlamadasAVIHoy);

// Función helper para convertir segundos a formato HH:MM:SS
function secondsToHHMMSS($seconds) {
    if ($seconds <= 0) return '00:00:00';
    $totalSeconds = (int)round($seconds);
    $hours = intdiv($totalSeconds, 3600);
    $minutes = intdiv($totalSeconds % 3600, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

// Función helper para convertir segundos a formato MM:SS
function secondsToMMSS($seconds) {
    if ($seconds <= 0) return '00:00';
    $totalSeconds = (int)round($seconds);
    $minutes = intdiv($totalSeconds, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d', $minutes, $secs);
}

echo json_encode([
    'success' => true,
    'data' => [
        'llamadasEnCurso' => $llamadasEnCursoHoy,
        'totalLlamadasFive9' => $totalLlamadasFive9Hoy,
        'totalLlamadasAVI' => $totalLlamadasAVIHoy,
        'duracionTotal' => secondsToHHMMSS($duracionTotalSecsHoy),
        'duracionPromedio' => secondsToMMSS($duracionPromedioSecsHoy),
        'resultados' => $resultadosHoy,
        'colgadas' => $colgadasHoy,
        'fechaHoy' => date('d/m/Y')
    ]
]);
