<?php
// API para obtener datos de REALTIME sin recargar toda la página
header('Content-Type: application/json');

require_once 'config.php';

// Establecer zona horaria a Puerto Rico
date_default_timezone_set('America/Puerto_Rico');

$pdo = getDBConnection();
$clienteActual = CLIENTE_ACTUAL;
$fechaHoy = date('Y-m-d');

// Llamadas en Curso (hoy)
$stmtEnCursoHoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM level_calls
    WHERE cliente = :cliente
    AND Estado = 'IN-PROGRESS'
    AND DATE(F9TimeStamp) = :fecha_hoy
");
$stmtEnCursoHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$llamadasEnCursoHoy = $stmtEnCursoHoy->fetch()['total'];

// Duración Total (hoy) - usando level_calls.duration
// Solo para llamadas con call_result (excluyendo IVR_Regular)
$stmtDuracionTotalHoy = $pdo->prepare("
    SELECT SUM(lc.duration) as total
    FROM level_calls lc
    LEFT JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
    WHERE lc.cliente = :cliente
    AND DATE(lc.F9TimeStamp) = :fecha_hoy
    AND lvc.call_result IS NOT NULL
    AND lvc.call_result != 'IVR_Regular'
    AND lvc.call_result != '\"IVR_Regular\"'
    AND lc.duration >= 60
");
$stmtDuracionTotalHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$duracionTotalSecsHoy = $stmtDuracionTotalHoy->fetch()['total'] ?? 0;
$duracionTotalSecsHoy = max(0, $duracionTotalSecsHoy);

// Gráfico Pie: Tipo de llamadas (hoy)
// Excluir IVR_Regular y solo contar llamadas con call_result
$stmtResultadosHoy = $pdo->prepare("
    SELECT
        lvc.call_result as resultado,
        COUNT(*) as total
    FROM level_calls lc
    LEFT JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
    WHERE lc.cliente = :cliente
    AND DATE(lc.F9TimeStamp) = :fecha_hoy
    AND lvc.call_result IS NOT NULL
    AND lvc.call_result != 'IVR_Regular'
    AND lvc.call_result != '\"IVR_Regular\"'
    GROUP BY lvc.call_result
    ORDER BY total DESC
");
$stmtResultadosHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$resultadosHoy = $stmtResultadosHoy->fetchAll();

// Total de llamadas = solo las que tienen call_result (excluyendo IVR_Regular)
$totalLlamadasHoy = array_sum(array_column($resultadosHoy, 'total'));
// Duración Promedio (hoy)
$duracionPromedioSecsHoy = $totalLlamadasHoy > 0 ? $duracionTotalSecsHoy / $totalLlamadasHoy : 0;
// No se calculan llamadas colgadas en REALTIME
$colgadasHoy = 0;

// Tasa Retención AVI (hoy)
// Solo cuenta con "contencion_exitosa" y "consulta_resuelta"
$retencionExitosaHoy = 0;
$consultaResueltaHoy = 0;
foreach ($resultadosHoy as $row) {
    if ($row['resultado'] === 'contencion_exitosa') {
        $retencionExitosaHoy = $row['total'];
    } elseif ($row['resultado'] === 'consulta_resuelta') {
        $consultaResueltaHoy = $row['total'];
    }
}
$tasaRetencionAVIHoy = $totalLlamadasHoy > 0 ? (($retencionExitosaHoy + $consultaResueltaHoy) / $totalLlamadasHoy) * 100 : 0;

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
        'totalLlamadas' => $totalLlamadasHoy,
        'duracionTotal' => secondsToHHMMSS($duracionTotalSecsHoy),
        'duracionPromedio' => secondsToMMSS($duracionPromedioSecsHoy),
        'tasaRetencionAVI' => $tasaRetencionAVIHoy,
        'resultados' => $resultadosHoy,
        'colgadas' => $colgadasHoy,
        'fechaHoy' => date('d/m/Y')
    ]
]);
