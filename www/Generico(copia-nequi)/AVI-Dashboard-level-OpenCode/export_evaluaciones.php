<?php
require_once 'config.php';

$pdo = getDBConnection();
$clienteActual = !empty($_GET['cliente']) ? $_GET['cliente'] : CLIENTE_ACTUAL;
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$searchTerm = $_GET['buscar_ev'] ?? '';
$filtroResultadoEv = $_GET['resultado_ev'] ?? '';

$where = "e.cliente = :cliente AND DATE(e.fecha_evaluacion) BETWEEN :desde AND :hasta";
$params = [':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta];

if (!empty($searchTerm)) {
    $where .= " AND (e.F9CallID LIKE :buscar OR e.ANI LIKE :buscar2)";
    $params[':buscar'] = "%$searchTerm%";
    $params[':buscar2'] = "%$searchTerm%";
}
if (!empty($filtroResultadoEv)) {
    $where .= " AND e.resultado = :resultado_ev";
    $params[':resultado_ev'] = $filtroResultadoEv;
}

$stmt = $pdo->prepare("
    SELECT e.F9CallID, e.ANI, e.resultado, e.call_result_correcto, e.se_puede_mejorar,
           e.info_disponible_sa, e.observacion, e.fecha_evaluacion,
           lc.F9TimeStamp, lc.duration, lvc.call_result as original_result,
           lvc.transcript_text
    FROM level_transcripciones_evaluacion e
    LEFT JOIN level_calls lc ON e.F9CallID = lc.F9CallID COLLATE utf8mb4_unicode_ci
    LEFT JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
    WHERE $where
    ORDER BY e.fecha_evaluacion DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="evaluaciones_' . $clienteActual . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// BOM UTF-8 para Excel
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Encabezados
fputcsv($output, [
    'F9CallID', 'ANI', 'Resultado', 'Call Result Original', 'Call Result Correcto',
    'Se Puede Mejorar', 'Info Disponible SA', 'Observacion',
    'Fecha Evaluacion', 'Fecha Llamada', 'Duracion (s)', 'Transcripcion'
]);

foreach ($rows as $row) {
    $transcript = $row['transcript_text'] ?? '';
    // Reemplazar saltos de línea para que no rompan el CSV
    $transcript = str_replace(["\r\n", "\r", "\n"], ' ', $transcript);

    fputcsv($output, [
        $row['F9CallID'],
        $row['ANI'] ?? '',
        $row['resultado'],
        $row['original_result'] ?? '',
        $row['resultado'] === 'no_pasa' ? ($row['call_result_correcto'] ?? '') : '',
        $row['se_puede_mejorar'] ? 'Si' : 'No',
        $row['info_disponible_sa'] ? 'Si' : 'No',
        $row['observacion'] ?? '',
        $row['fecha_evaluacion'],
        $row['F9TimeStamp'] ?? '',
        $row['duration'] ?? 0,
        $transcript,
    ]);
}

fclose($output);
