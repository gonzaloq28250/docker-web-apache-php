<?php
header('Content-Type: application/json');

require_once 'config.php';

date_default_timezone_set('America/Puerto_Rico');

$pdo = getDBConnection();
$clienteActual = 'NEQUI2';
$fechaHoy = date('Y-m-d');

// 1. Llamadas en Curso (hoy) — query simple sin JOIN
$stmtEnCursoHoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM level_calls
    WHERE cliente = :cliente
    AND Estado = 'IN-PROGRESS'
    AND DATE(F9TimeStamp) = :fecha_hoy
");
$stmtEnCursoHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$llamadasEnCursoHoy = $stmtEnCursoHoy->fetch()['total'];

// 2. Resultados + duración (hoy) — query consolidada
$stmtJoined = $pdo->prepare("
    SELECT
        lvc.call_result as resultado,
        COUNT(*) as total,
        SUM(CASE WHEN lc.duration >= 60 THEN lc.duration ELSE 0 END) as duracion
    FROM level_calls lc
    INNER JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
    WHERE lc.cliente = :cliente
    AND lvc.customer = :cliente2
    AND DATE(lc.F9TimeStamp) = :fecha_hoy
    AND lvc.call_result IS NOT NULL
    AND lvc.call_result NOT IN ('IVR_Regular', '\"IVR_Regular\"')
    GROUP BY lvc.call_result
    ORDER BY total DESC
");
$stmtJoined->execute([':cliente' => $clienteActual, ':cliente2' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$rowsJoined = $stmtJoined->fetchAll();

$resultadosHoy = [];
$totalLlamadasHoy = 0;
$duracionTotalSecsHoy = 0;
foreach ($rowsJoined as $row) {
    $resultadosHoy[] = ['resultado' => $row['resultado'], 'total' => (int)$row['total']];
    $totalLlamadasHoy += (int)$row['total'];
    $duracionTotalSecsHoy += (int)$row['duracion'];
}
$duracionTotalSecsHoy = max(0, $duracionTotalSecsHoy);
$duracionPromedioSecsHoy = $totalLlamadasHoy > 0 ? $duracionTotalSecsHoy / $totalLlamadasHoy : 0;
$colgadasHoy = 0;

// Tasa Retención AVI (hoy)
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
