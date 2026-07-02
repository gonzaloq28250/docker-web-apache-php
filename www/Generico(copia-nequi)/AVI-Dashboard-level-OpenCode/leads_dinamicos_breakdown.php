<?php
// Cargar configuración central
require_once 'config.php';

// Obtener conexión PDO desde config
$pdo = getDBConnection();

// Cliente actual desde config
$clienteActual = CLIENTE_ACTUAL;

// Obtener claves únicas
$keys_stmt = $pdo->query("SELECT DISTINCT clave FROM siigo_lead_data_v2 WHERE method = 'Insert Call' ");
$keys = $keys_stmt->fetchAll(PDO::FETCH_COLUMN);

// Construir filtros desde formulario
$where = "WHERE method = 'Insert Call' AND EXISTS (
    SELECT 1 FROM siigo_lead_data_v2 c
    WHERE c.F9CallID = d.F9CallID AND c.clave = 'CLIENTE' AND c.valor = '$clienteActual'
) ";

$params = [];

// Guardar filtros para pasar en los links
$filterParams = [];

if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
    $where .= " AND EXISTS ( SELECT 1 FROM siigo_lead_data_v2 f WHERE f.F9CallID = d.F9CallID AND f.clave = 'F9TimeStamp' AND DATE(f.valor) BETWEEN :fecha_desde AND :fecha_hasta )";
    $params[':fecha_desde'] = $_GET['fecha_desde'];
    $params[':fecha_hasta'] = $_GET['fecha_hasta'];
    $filterParams['fecha_desde'] = $_GET['fecha_desde'];
    $filterParams['fecha_hasta'] = $_GET['fecha_hasta'];
} elseif (!empty($_GET['fecha_desde'])) {
    $where .= " AND EXISTS ( SELECT 1 FROM siigo_lead_data_v2 f WHERE f.F9CallID = d.F9CallID AND f.clave = 'F9TimeStamp' AND DATE(f.valor) >= :fecha_desde )";
    $params[':fecha_desde'] = $_GET['fecha_desde'];
    $filterParams['fecha_desde'] = $_GET['fecha_desde'];
} elseif (!empty($_GET['fecha_hasta'])) {
    $where .= " AND EXISTS ( SELECT 1 FROM siigo_lead_data_v2 f WHERE f.F9CallID = d.F9CallID AND f.clave = 'F9TimeStamp' AND DATE(f.valor) <= :fecha_hasta )";
    $params[':fecha_hasta'] = $_GET['fecha_hasta'];
    $filterParams['fecha_hasta'] = $_GET['fecha_hasta'];
}

if (!empty($_GET['proyecto'])) {
    $where .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 p WHERE p.F9CallID = d.F9CallID AND p.clave = 'PROYECTO' AND p.valor = :proyecto)";
    $params[':proyecto'] = $_GET['proyecto'];
    $filterParams['proyecto'] = $_GET['proyecto'];
}

if (!empty($_GET['ani'])) {
    $where .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 a WHERE a.F9CallID = d.F9CallID AND a.clave = 'ANI' AND a.valor = :ani)";
    $params[':ani'] = $_GET['ani'];
    $filterParams['ani'] = $_GET['ani'];
}

// Obtener breakdown de resultado_llamada (agregado total)
$whereBreakdown = "WHERE r.method = 'ResultadoPerfil' AND EXISTS (
    SELECT 1 FROM siigo_lead_data_v2 c
    WHERE c.F9CallID = r.F9CallID AND c.clave = 'CLIENTE' AND c.valor = '$clienteActual'
) ";

if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
    $whereBreakdown .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 ts WHERE ts.F9CallID = r.F9CallID AND ts.clave = 'F9TimeStamp' AND DATE(ts.valor) BETWEEN :fecha_desde AND :fecha_hasta)";
} elseif (!empty($_GET['fecha_desde'])) {
    $whereBreakdown .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 ts WHERE ts.F9CallID = r.F9CallID AND ts.clave = 'F9TimeStamp' AND DATE(ts.valor) >= :fecha_desde)";
} elseif (!empty($_GET['fecha_hasta'])) {
    $whereBreakdown .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 ts WHERE ts.F9CallID = r.F9CallID AND ts.clave = 'F9TimeStamp' AND DATE(ts.valor) <= :fecha_hasta)";
}

if (!empty($_GET['proyecto'])) {
    $whereBreakdown .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 p WHERE p.F9CallID = r.F9CallID AND p.clave = 'PROYECTO' AND p.valor = :proyecto)";
}

if (!empty($_GET['ani'])) {
    $whereBreakdown .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 a WHERE a.F9CallID = r.F9CallID AND a.clave = 'ANI' AND a.valor = :ani)";
}

$sqlBreakdown = "
    SELECT
        res.valor as resultado,
        COUNT(DISTINCT r.F9CallID) as total
    FROM siigo_lead_data_v2 r
    INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID AND res.clave = 'resultado_llamada' AND res.method = 'ResultadoPerfil'
    $whereBreakdown
    GROUP BY res.valor
    ORDER BY total DESC
";
$stmtBreakdown = $pdo->prepare($sqlBreakdown);
$stmtBreakdown->execute($params);
$breakdownResultados = $stmtBreakdown->fetchAll(PDO::FETCH_ASSOC);

// Total de llamadas con resultado
$totalConResultadoBreakdown = array_sum(array_column($breakdownResultados, 'total'));

// Obtener total de llamadas realizadas
$whereRealizadasTotal = "WHERE cliente = '$clienteActual'";

if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
    $whereRealizadasTotal .= " AND DATE(F9TimeStamp) BETWEEN :fecha_desde AND :fecha_hasta";
} elseif (!empty($_GET['fecha_desde'])) {
    $whereRealizadasTotal .= " AND DATE(F9TimeStamp) >= :fecha_desde";
} elseif (!empty($_GET['fecha_hasta'])) {
    $whereRealizadasTotal .= " AND DATE(F9TimeStamp) <= :fecha_hasta";
}

if (!empty($_GET['proyecto'])) {
    $whereRealizadasTotal .= " AND PROYECTO = :proyecto";
}

if (!empty($_GET['ani'])) {
    $whereRealizadasTotal .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 s WHERE s.F9CallID = a.F9CallID AND s.clave = 'ANI' AND s.valor = :ani)";
}

$sqlRealizadasTotal = "SELECT COUNT(*) as total FROM avi_calls a $whereRealizadasTotal";
$stmtRealizadasTotal = $pdo->prepare($sqlRealizadasTotal);
$stmtRealizadasTotal->execute($params);
$totalRealizadasBreakdown = $stmtRealizadasTotal->fetch()['total'];

// Calcular llamadas colgadas
$llamadasColgadas = $totalRealizadasBreakdown - $totalConResultadoBreakdown;
if ($llamadasColgadas > 0) {
    $breakdownResultados[] = [
        'resultado' => 'Llamadas colgadas',
        'total' => $llamadasColgadas
    ];
}

// Ordenar resultados de mayor a menor
usort($breakdownResultados, function($a, $b) {
    return $b['total'] - $a['total'];
});

// Obtener resumen diario de resultados de llamadas
$whereResumen = "WHERE r.method = 'ResultadoPerfil' AND EXISTS (
    SELECT 1 FROM siigo_lead_data_v2 c
    WHERE c.F9CallID = r.F9CallID AND c.clave = 'CLIENTE' AND c.valor = '$clienteActual'
) ";

if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
    $whereResumen .= " AND DATE(ts.valor) BETWEEN :fecha_desde AND :fecha_hasta";
} elseif (!empty($_GET['fecha_desde'])) {
    $whereResumen .= " AND DATE(ts.valor) >= :fecha_desde";
} elseif (!empty($_GET['fecha_hasta'])) {
    $whereResumen .= " AND DATE(ts.valor) <= :fecha_hasta";
}

if (!empty($_GET['proyecto'])) {
    $whereResumen .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 p WHERE p.F9CallID = r.F9CallID AND p.clave = 'PROYECTO' AND p.valor = :proyecto)";
}

if (!empty($_GET['ani'])) {
    $whereResumen .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 a WHERE a.F9CallID = r.F9CallID AND a.clave = 'ANI' AND a.valor = :ani)";
}

$sqlResumen = "
    SELECT
        DATE(ts.valor) as fecha,
        res.valor as resultado,
        COUNT(DISTINCT r.F9CallID) as total
    FROM siigo_lead_data_v2 r
    INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID AND res.clave = 'resultado_llamada' AND res.method = 'ResultadoPerfil'
    INNER JOIN siigo_lead_data_v2 ts ON r.F9CallID = ts.F9CallID AND ts.clave = 'F9TimeStamp'
    $whereResumen
    GROUP BY DATE(ts.valor), res.valor
    ORDER BY DATE(ts.valor) ASC, res.valor
";
$stmtResumen = $pdo->prepare($sqlResumen);
$stmtResumen->execute($params);
$resumenData = $stmtResumen->fetchAll(PDO::FETCH_ASSOC);

// Obtener total de llamadas realizadas por día (desde avi_calls)
$whereRealizadas = "WHERE cliente = '$clienteActual'";

if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
    $whereRealizadas .= " AND DATE(F9TimeStamp) BETWEEN :fecha_desde AND :fecha_hasta";
} elseif (!empty($_GET['fecha_desde'])) {
    $whereRealizadas .= " AND DATE(F9TimeStamp) >= :fecha_desde";
} elseif (!empty($_GET['fecha_hasta'])) {
    $whereRealizadas .= " AND DATE(F9TimeStamp) <= :fecha_hasta";
}

if (!empty($_GET['proyecto'])) {
    $whereRealizadas .= " AND PROYECTO = :proyecto";
}

// Para ANI necesitamos hacer JOIN con siigo_lead_data_v2
if (!empty($_GET['ani'])) {
    $whereRealizadas .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 s WHERE s.F9CallID = a.F9CallID AND s.clave = 'ANI' AND s.valor = :ani)";
}

$sqlRealizadas = "
    SELECT
        DATE(F9TimeStamp) as fecha,
        COUNT(*) as total
    FROM avi_calls a
    $whereRealizadas
    GROUP BY DATE(F9TimeStamp)
    ORDER BY DATE(F9TimeStamp) ASC
";
$stmtRealizadas = $pdo->prepare($sqlRealizadas);
$stmtRealizadas->execute($params);
$realizadasData = $stmtRealizadas->fetchAll(PDO::FETCH_ASSOC);

// Organizar llamadas realizadas por fecha
$realizadasPorFecha = [];
foreach ($realizadasData as $row) {
    $realizadasPorFecha[$row['fecha']] = $row['total'];
}

// Organizar datos de resumen por fecha y resultado
$resumenPorFecha = [];
$todosResultados = [];
foreach ($resumenData as $row) {
    $fecha = $row['fecha'];
    $resultado = $row['resultado'];
    $total = $row['total'];

    if (!isset($resumenPorFecha[$fecha])) {
        $resumenPorFecha[$fecha] = [];
    }
    $resumenPorFecha[$fecha][$resultado] = $total;

    if (!in_array($resultado, $todosResultados)) {
        $todosResultados[] = $resultado;
    }
}

// Calcular totales por resultado
$totalesPorResultado = [];
$totalRealizadasGeneral = 0;
$totalColgadasGeneral = 0;

foreach ($todosResultados as $resultado) {
    $totalesPorResultado[$resultado] = 0;
    foreach ($resumenPorFecha as $fecha => $resultados) {
        if (isset($resultados[$resultado])) {
            $totalesPorResultado[$resultado] += $resultados[$resultado];
        }
    }
}

// Calcular total de llamadas realizadas y colgadas
foreach ($realizadasPorFecha as $fecha => $total) {
    $totalRealizadasGeneral += $total;
    $totalConResultado = 0;
    if (isset($resumenPorFecha[$fecha])) {
        $totalConResultado = array_sum($resumenPorFecha[$fecha]);
    }
    $totalColgadasGeneral += ($total - $totalConResultado);
}

// Obtener datos
$sql = "SELECT F9CallID, clave, valor FROM siigo_lead_data_v2 d $where ORDER BY F9CallID";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar datos por F9CallID
$data = [];
foreach ($rows as $row) {
    $data[$row['F9CallID']][$row['clave']] = $row['valor'];
}

// Construir query string para mantener filtros
$queryString = http_build_query($filterParams);
$queryStringLink = !empty($queryString) ? '&' . $queryString : '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Leads <?= CLIENTE_ACTUAL ?> - Análisis por Clasificación</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white shadow-lg">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <div class="flex items-center gap-3">
                    <i class="fas fa-chart-bar text-2xl"></i>
                    <div>
                        <h1 class="text-3xl font-bold">Dashboard de Leads con Análisis por Clasificación (Cliente: <?= CLIENTE_ACTUAL ?>)</h1>
                        <p class="text-blue-100">Sistema de gestión de llamadas</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Container -->
        <main class="max-w-7xl mx-auto px-4 py-8">
            <!-- Filtros Section -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex items-center gap-2 mb-4 cursor-pointer" onclick="toggleFilters()">
                    <i class="fas fa-filter text-blue-600"></i>
                    <h2 class="text-xl font-bold text-gray-800">Filtros Avanzados</h2>
                    <i id="filterIcon" class="fas fa-chevron-down text-gray-600 ml-auto"></i>
                </div>

                <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Filtro Fecha Desde -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar mr-2"></i>Fecha Desde
                        </label>
                        <input type="date" name="fecha_desde" value="<?php echo htmlspecialchars($_GET['fecha_desde'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Filtro Fecha Hasta -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar mr-2"></i>Fecha Hasta
                        </label>
                        <input type="date" name="fecha_hasta" value="<?php echo htmlspecialchars($_GET['fecha_hasta'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Filtro Proyecto -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-project-diagram mr-2"></i>Proyecto
                        </label>
                        <input type="text" name="proyecto" placeholder="Ingrese proyecto"
                               value="<?php echo htmlspecialchars($_GET['proyecto'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Filtro ANI -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone mr-2"></i>ANI
                        </label>
                        <input type="text" name="ani" placeholder="Ingrese ANI"
                               value="<?php echo htmlspecialchars($_GET['ani'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Botones -->
                    <div class="md:col-span-2 lg:col-span-4 flex gap-3">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded-lg transition duration-200 flex items-center justify-center gap-2">
                            <i class="fas fa-search"></i>Buscar
                        </button>
                        <button type="reset" onclick="resetForm()" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-2 rounded-lg transition duration-200 flex items-center justify-center gap-2">
                            <i class="fas fa-redo"></i>Limpiar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Resultados Info -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-600"></i>
                    <span class="text-gray-700"><strong><?php echo count($data); ?></strong> registro(s) encontrado(s)</span>
                </div>
            </div>

            <!-- Breakdown por Clasificación de Llamada -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-700 mb-4 flex items-center gap-2">
                    <i class="fas fa-phone-square-alt"></i> Clasificación de Llamadas
                </h2>

                <!-- Total Llamadas Realizadas -->
                <div class="mb-4">
                    <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg p-4 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Llamadas Realizadas</p>
                                <p class="text-4xl font-bold"><?= number_format($totalRealizadasBreakdown) ?></p>
                            </div>
                            <i class="fas fa-phone-volume text-4xl opacity-50"></i>
                        </div>
                    </div>
                </div>

                <!-- Breakdown de Resultados -->
                <div class="space-y-3">
                    <?php if (!empty($breakdownResultados)):
                        foreach ($breakdownResultados as $row):
                            $porcentaje = $totalRealizadasBreakdown > 0 ? round(($row['total'] / $totalRealizadasBreakdown) * 100, 1) : 0;
                            $colorClass = 'bg-blue-500';

                            $resultadoLower = strtolower($row['resultado']);
                            if (strpos($resultadoLower, 'exitosa') !== false || strpos($resultadoLower, 'éxito') !== false) {
                                $colorClass = 'bg-green-500';
                            } elseif (strpos($resultadoLower, 'no contesta') !== false || strpos($resultadoLower, 'ocupado') !== false) {
                                $colorClass = 'bg-yellow-500';
                            } elseif (strpos($resultadoLower, 'rechazada') !== false || strpos($resultadoLower, 'fallida') !== false || strpos($resultadoLower, 'colgada') !== false) {
                                $colorClass = 'bg-red-500';
                            }
                    ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                            <div class="flex-1">
                                <span class="font-semibold text-gray-800"><?= htmlspecialchars($row['resultado']) ?></span>
                                <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                    <div class="<?= $colorClass ?> h-2 rounded-full" style="width: <?= $porcentaje ?>%"></div>
                                </div>
                            </div>
                            <div class="ml-4 text-right flex items-center gap-3">
                                <div>
                                    <p class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($row['total']) ?></p>
                                    <p class="text-xs text-gray-500"><?= $porcentaje ?>%</p>
                                </div>
                                <button onclick="openResultadoModal('<?= htmlspecialchars($row['resultado'], ENT_QUOTES) ?>')"
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition duration-200 text-xs flex items-center gap-1 whitespace-nowrap">
                                    <i class="fas fa-list"></i> Ver Llamadas
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-gray-500 text-center py-4">No hay datos para el rango seleccionado</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resumen Diario por Resultado de Llamada -->
            <?php if (!empty($resumenPorFecha) || !empty($realizadasPorFecha)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-calendar-alt text-blue-600"></i>Resumen Diario por Resultado de Llamada
                    </h2>
                    <button onclick="exportToExcel()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-200 flex items-center gap-2">
                        <i class="fas fa-file-excel"></i> Exportar a Excel
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table id="resumenTable" class="w-full text-sm">
                        <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white">
                            <tr>
                                <th class="px-4 py-3 text-left font-bold">Fecha</th>
                                <th class="px-4 py-3 text-right font-bold">Llamadas Realizadas</th>
                                <?php foreach ($todosResultados as $resultado): ?>
                                    <th class="px-4 py-3 text-right font-bold"><?php echo htmlspecialchars($resultado); ?></th>
                                <?php endforeach; ?>
                                <th class="px-4 py-3 text-right font-bold">Llamadas Colgadas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php
                            // Get all unique dates from both realizadas and resumen
                            $todasFechas = array_unique(array_merge(array_keys($realizadasPorFecha), array_keys($resumenPorFecha)));
                            sort($todasFechas);

                            foreach ($todasFechas as $fecha):
                                $realizadas = $realizadasPorFecha[$fecha] ?? 0;
                                $resultados = $resumenPorFecha[$fecha] ?? [];
                                $totalConResultado = array_sum($resultados);
                                $colgadas = $realizadas - $totalConResultado;
                            ?>
                            <tr class="hover:bg-blue-50 transition">
                                <td class="px-4 py-3 font-semibold text-gray-800"><?php echo date('d/m/Y', strtotime($fecha)); ?></td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-800"><?php echo number_format($realizadas); ?></td>
                                <?php foreach ($todosResultados as $resultado): ?>
                                    <td class="px-4 py-3 text-right <?php echo isset($resultados[$resultado]) ? 'text-gray-800 font-semibold' : 'text-gray-400'; ?>">
                                        <?php echo isset($resultados[$resultado]) ? number_format($resultados[$resultado]) : '-'; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="px-4 py-3 text-right font-semibold text-gray-800">
                                    <?php echo number_format($colgadas); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <!-- Fila de Totales -->
                            <tr class="bg-blue-100 border-t-2 border-blue-600 font-bold text-base">
                                <td class="px-4 py-4 text-blue-800">TOTAL</td>
                                <td class="px-4 py-4 text-right text-blue-800"><?php echo number_format($totalRealizadasGeneral); ?></td>
                                <?php foreach ($todosResultados as $resultado): ?>
                                    <td class="px-4 py-4 text-right text-blue-800">
                                        <?php echo number_format($totalesPorResultado[$resultado]); ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="px-4 py-4 text-right text-blue-800"><?php echo number_format($totalColgadasGeneral); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabla de Leads -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white sticky top-0">
                            <tr>
								<th class="px-6 py-4 text-center font-bold">#</th>
                                <th class="px-6 py-4 text-center font-bold min-w-[150px]">Acciones</th>
                                <th class="px-6 py-4 text-left font-bold">F9CallID</th>
								<th class="px-6 py-4 text-left font-bold">F9TimeStamp</th>
                                <?php foreach ($keys as $key): ?>
                                    <th class="px-6 py-4 text-left font-bold"><?php echo htmlspecialchars($key); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>


<tbody class="divide-y divide-gray-200">
    <?php if (empty($data)): ?>
        <tr>
            <td colspan="<?php echo count($keys) + 4; ?>" class="px-6 py-8 text-center text-gray-500">
                <i class="fas fa-inbox text-4xl mb-4"></i>
                <p>No hay datos disponibles</p>
            </td>
        </tr>
    <?php else: ?>
        <?php $count = 0; foreach ($data as $f9id => $values): $count++; ?>
            <tr class="<?php echo $count % 2 == 0 ? 'bg-gray-50' : 'bg-white'; ?> hover:bg-blue-50 transition duration-150">
                <!-- Número de registro -->
                <td class="px-6 py-4 text-center font-semibold"><?php echo $count; ?></td>

                <!-- Acciones -->
                <td class="px-6 py-4 text-center">
                    <a href="detalle_lead_v2.php?F9CallID=<?php echo urlencode($f9id); ?><?php echo $queryStringLink; ?>"
                       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition duration-200 whitespace-nowrap">
                        <i class="fas fa-eye"></i>Ver Detalle
                    </a>
                </td>

                <!-- F9CallID -->
                <td class="px-6 py-4 font-semibold text-blue-600"><?php echo htmlspecialchars($f9id); ?></td>

                <!-- F9TimeStamp -->
                <td class="px-6 py-4 text-gray-700 text-sm">
                    <?php echo htmlspecialchars($values['F9TimeStamp'] ?? '-'); ?>
                </td>

                <!-- Campos dinámicos -->
                <?php foreach ($keys as $key): ?>
                    <td class="px-6 py-4 text-gray-700 text-sm">
                        <?php echo htmlspecialchars($values[$key] ?? '-'); ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</tbody>


                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal para detalle de lead -->
    <div id="detailModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg w-full max-w-7xl h-5/6 flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800">Detalle de Lead</h3>
                <button onclick="closeDetailModal()" class="text-gray-500 hover:text-gray-700 text-2xl font-bold">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <iframe id="detailFrame" class="w-full h-full border-0"></iframe>
            </div>
        </div>
    </div>

    <!-- Modal para lista de llamadas por resultado -->
    <div id="resultadoModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-40 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg w-full max-w-6xl max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800">
                    Llamadas: <span id="resultadoModalTitle"></span>
                </h3>
                <button onclick="closeResultadoModal()" class="text-gray-500 hover:text-gray-700 text-2xl font-bold">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="flex-1 overflow-auto p-4">
                <div class="mb-3 text-sm text-gray-600">
                    Total: <span id="resultadoModalCount">0</span> llamadas
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white sticky top-0">
                            <tr>
                                <th class="px-4 py-2 text-left">F9CallID</th>
                                <th class="px-4 py-2 text-left">Cliente</th>
                                <th class="px-4 py-2 text-left">Estado</th>
                                <th class="px-4 py-2 text-left">Proyecto</th>
                                <th class="px-4 py-2 text-left">Fecha</th>
                                <th class="px-4 py-2 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="resultadoModalTableBody" class="divide-y divide-gray-200">
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleFilters() {
            const filterForm = document.getElementById('filterForm');
            const filterIcon = document.getElementById('filterIcon');

            filterForm.classList.toggle('hidden');
            filterIcon.classList.toggle('fa-chevron-down');
            filterIcon.classList.toggle('fa-chevron-up');
        }

        function resetForm() {
            document.getElementById('filterForm').reset();
            window.location.href = window.location.pathname;
        }

        function exportToExcel() {
            // Get the table
            const table = document.getElementById('resumenTable');

            // Create a new workbook
            const wb = XLSX.utils.book_new();

            // Convert the table to a worksheet
            const ws = XLSX.utils.table_to_sheet(table);

            // Add the worksheet to the workbook
            XLSX.utils.book_append_sheet(wb, ws, "Resumen Diario");

            // Generate filename with current date
            const date = new Date();
            const filename = `resumen_llamadas_${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}.xlsx`;

            // Save the file
            XLSX.writeFile(wb, filename);
        }

        // Funciones para el modal de detalle
        function openDetailModal(url) {
            const modal = document.getElementById('detailModal');
            const iframe = document.getElementById('detailFrame');
            iframe.src = url;
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeDetailModal() {
            const modal = document.getElementById('detailModal');
            const iframe = document.getElementById('detailFrame');
            modal.classList.add('hidden');
            iframe.src = '';
            document.body.style.overflow = 'auto';
        }

        // Funciones para el modal de llamadas por resultado
        function openResultadoModal(resultado) {
            const modal = document.getElementById('resultadoModal');
            document.getElementById('resultadoModalTitle').textContent = resultado;
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';

            // Cargar las llamadas para este resultado
            loadResultadoLlamadas(resultado);
        }

        function closeResultadoModal() {
            const modal = document.getElementById('resultadoModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        function loadResultadoLlamadas(resultado) {
            const tableBody = document.getElementById('resultadoModalTableBody');
            tableBody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">Cargando...</td></tr>';

            // Obtener filtros actuales de la URL
            const urlParams = new URLSearchParams(window.location.search);
            const fechaDesde = urlParams.get('fecha_desde') || '';
            const fechaHasta = urlParams.get('fecha_hasta') || '';
            const proyecto = urlParams.get('proyecto') || '';
            const ani = urlParams.get('ani') || '';

            // Construir URL con filtros
            let fetchUrl = `get_llamadas_by_resultado_filtrado.php?resultado=${encodeURIComponent(resultado)}`;
            if (fechaDesde) fetchUrl += `&fecha_desde=${encodeURIComponent(fechaDesde)}`;
            if (fechaHasta) fetchUrl += `&fecha_hasta=${encodeURIComponent(fechaHasta)}`;
            if (proyecto) fetchUrl += `&proyecto=${encodeURIComponent(proyecto)}`;
            if (ani) fetchUrl += `&ani=${encodeURIComponent(ani)}`;

            fetch(fetchUrl)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        tableBody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-red-500">Error: ${escapeHtml(data.error || 'Error desconocido')}</td></tr>`;
                        return;
                    }

                    document.getElementById('resultadoModalCount').textContent = data.total;

                    if (data.llamadas.length === 0) {
                        tableBody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No hay llamadas para este resultado</td></tr>';
                        return;
                    }

                    let html = '';
                    data.llamadas.forEach(row => {
                        const urlParams = new URLSearchParams(window.location.search);
                        urlParams.set('F9CallID', row.F9CallID || '');
                        const detalleUrl = `detalle_lead_v2.php?${urlParams.toString()}`;

                        html += `<tr class='hover:bg-gray-100 transition'>
                            <td class='px-4 py-2 font-mono text-xs'>${escapeHtml(row.F9CallID || '')}</td>
                            <td class='px-4 py-2'>${escapeHtml(row.cliente || '')}</td>
                            <td class='px-4 py-2'>${escapeHtml(row.Estado || '')}</td>
                            <td class='px-4 py-2'>${escapeHtml(row.PROYECTO || '')}</td>
                            <td class='px-4 py-2 text-xs'>${escapeHtml(row.F9TimeStamp || '')}</td>
                            <td class='px-4 py-2 text-center'>
                                <button onclick="openDetailModal('${detalleUrl}')"
                                        class='bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg transition duration-200 text-xs flex items-center gap-1 mx-auto'>
                                    <i class='fas fa-eye'></i> Ver Detalle
                                </button>
                            </td>
                        </tr>`;
                    });

                    tableBody.innerHTML = html;
                })
                .catch(err => {
                    console.error('Error al cargar llamadas:', err);
                    tableBody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-red-500">Error al cargar las llamadas</td></tr>';
                });
        }

        // Función auxiliar para escapar HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Cerrar modales con tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDetailModal();
                closeResultadoModal();
            }
        });

        // Cerrar modales al hacer clic fuera del contenido
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target.id === 'detailModal') {
                closeDetailModal();
            }
        });

        document.getElementById('resultadoModal').addEventListener('click', function(e) {
            if (e.target.id === 'resultadoModal') {
                closeResultadoModal();
            }
        });
    </script>
</body>
</html>
