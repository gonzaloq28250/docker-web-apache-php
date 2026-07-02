
<?php
// Cargar configuración
require_once 'config.php';

// Obtener conexión PDO
$pdo = getDBConnection();

$cliente = $_GET['cliente'] ?? CLIENTE_ACTUAL;

$inProgressStmt = $pdo->prepare("SELECT * FROM avi_calls WHERE Estado='IN-PROGRESS' AND cliente=:cliente ORDER BY F9TimeStamp DESC");
$inProgressStmt->execute([':cliente' => $cliente]);
$inProgress = $inProgressStmt->fetchAll();

$otrosStmt = $pdo->prepare("SELECT * FROM avi_calls WHERE Estado<>'IN-PROGRESS' AND cliente=:cliente AND DATE(F9TimeStamp) = CURDATE() ORDER BY F9TimeStamp DESC LIMIT 100");
$otrosStmt->execute([':cliente' => $cliente]);
$otros = $otrosStmt->fetchAll();

$inProgressHTML = '';
if (!empty($inProgress)) {
    foreach ($inProgress as $row) {
        $inProgressHTML .= "<li class='border border-gray-200 rounded-lg p-4 hover:border-blue-300 transition'>
            <p class='font-bold text-gray-800'>" . htmlspecialchars($row['cliente'] ?? '') . "</p>
            <p class='text-sm text-gray-600'>" . htmlspecialchars($row['numero_contacto'] ?? '') . " | " . htmlspecialchars($row['PROYECTO'] ?? '') . "</p>
            <p class='text-xs text-gray-500'>" . htmlspecialchars($row['F9TimeStamp'] ?? '') . "</p>
        </li>";
    }
} else {
    $inProgressHTML = "<p class='text-gray-500'>No hay llamadas en progreso para " . htmlspecialchars($cliente) . ".</p>";
}

// En lugar de generar HTML, devolvemos los datos directamente como array
$otrosData = $otros;

// Total de llamadas Five9 (de avi_calls)
$totalFive9Stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_calls
    WHERE cliente = :cliente
    AND DATE(F9TimeStamp) = CURDATE()
");
$totalFive9Stmt->execute([':cliente' => $cliente]);
$totalFive9 = $totalFive9Stmt->fetch()['total'];

// Total de llamadas AVI (de avi_call_costs)
$totalAVIStmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_call_costs
    WHERE cliente = :cliente
    AND DATE(metadata_date_local) = CURDATE()
");
$totalAVIStmt->execute([':cliente' => $cliente]);
$totalAVI = $totalAVIStmt->fetch()['total'];

// Breakdown de resultado_llamada (solo hoy)
$resultadoStmt = $pdo->prepare("
    SELECT
        valor as resultado,
        COUNT(DISTINCT F9CallID) as total
    FROM siigo_lead_data_v2
    WHERE method = 'ResultadoPerfil'
    AND clave = 'resultado_llamada'
    AND EXISTS (
        SELECT 1 FROM siigo_lead_data_v2 t
        WHERE t.F9CallID = siigo_lead_data_v2.F9CallID
        AND t.clave = 'F9TimeStamp'
        AND DATE(t.valor) = CURDATE()
    )
    AND EXISTS (
        SELECT 1 FROM siigo_lead_data_v2 c
        WHERE c.F9CallID = siigo_lead_data_v2.F9CallID
        AND c.clave = 'CLIENTE'
        AND c.valor = :cliente
    )
    GROUP BY valor
    ORDER BY total DESC
");
$resultadoStmt->execute([':cliente' => $cliente]);
$resultados = $resultadoStmt->fetchAll();

$totalResultados = array_sum(array_column($resultados, 'total'));

// Agregar categoría "Llamadas colgadas" (diferencia entre llamadas AVI y con resultado)
$clienteCuelga = $totalAVI - $totalResultados;
if ($clienteCuelga > 0) {
    $resultados[] = [
        'resultado' => 'Llamadas colgadas',
        'total' => $clienteCuelga
    ];
}

// Ordenar resultados de mayor a menor
usort($resultados, function($a, $b) {
    return $b['total'] - $a['total'];
});

// HTML del total de llamadas Five9 y AVI
$totalFive9HTML = number_format($totalFive9);
$totalAVIHTML = number_format($totalAVI);

// HTML del total con resultado
$totalHTML = number_format($totalResultados);

// HTML del breakdown
$resultadoHTML = '';
foreach ($resultados as $row) {
    $porcentaje = $totalAVI > 0 ? round(($row['total'] / $totalAVI) * 100, 1) : 0;
    $colorClass = 'bg-blue-500';

    // Asignar colores según el resultado (puedes personalizar esto)
    $resultadoLower = strtolower($row['resultado']);
    if (strpos($resultadoLower, 'exitosa') !== false || strpos($resultadoLower, 'éxito') !== false) {
        $colorClass = 'bg-green-500';
    } elseif (strpos($resultadoLower, 'no contesta') !== false || strpos($resultadoLower, 'ocupado') !== false) {
        $colorClass = 'bg-yellow-500';
    } elseif (strpos($resultadoLower, 'rechazada') !== false || strpos($resultadoLower, 'fallida') !== false) {
        $colorClass = 'bg-red-500';
    }

    $resultadoEscaped = htmlspecialchars($row['resultado'], ENT_QUOTES);
    $resultadoDisplay = htmlspecialchars($row['resultado']);
    $totalDisplay = htmlspecialchars($row['total']);

    $resultadoHTML .= '<div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">';
    $resultadoHTML .= '<div class="flex-1">';
    $resultadoHTML .= '<span class="font-semibold text-gray-800">' . $resultadoDisplay . '</span>';
    $resultadoHTML .= '<div class="w-full bg-gray-200 rounded-full h-2 mt-2">';
    $resultadoHTML .= '<div class="' . $colorClass . ' h-2 rounded-full" style="width: ' . $porcentaje . '%"></div>';
    $resultadoHTML .= '</div>';
    $resultadoHTML .= '</div>';
    $resultadoHTML .= '<div class="ml-4 text-right flex items-center gap-3">';
    $resultadoHTML .= '<div>';
    $resultadoHTML .= '<p class="text-2xl font-bold text-gray-800">' . $totalDisplay . '</p>';
    $resultadoHTML .= '<p class="text-xs text-gray-500">' . $porcentaje . '%</p>';
    $resultadoHTML .= '</div>';
    $resultadoHTML .= '<button onclick="openResultadoModal(\'' . $resultadoEscaped . '\')" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition duration-200 text-xs flex items-center gap-1 whitespace-nowrap">';
    $resultadoHTML .= '<i class="fas fa-list"></i> Ver Llamadas';
    $resultadoHTML .= '</button>';
    $resultadoHTML .= '</div>';
    $resultadoHTML .= '</div>';
}

if (empty($resultadoHTML)) {
    $resultadoHTML = "<p class='text-gray-500 text-center py-4'>No hay datos del día de hoy</p>";
}

echo json_encode([
    'inProgressHTML' => $inProgressHTML,
    'otrosData' => $otrosData,
    'resultadoHTML' => $resultadoHTML,
    'totalLlamadasHTML' => $totalHTML,
    'totalFive9HTML' => $totalFive9HTML,
    'totalAVIHTML' => $totalAVIHTML
]);
