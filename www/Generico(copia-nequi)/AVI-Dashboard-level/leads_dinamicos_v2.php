<?php
// Cargar configuración central
require_once 'config.php';

// Obtener conexión PDO desde config
$pdo = getDBConnection();

// Obtener claves únicas
$keys_stmt = $pdo->query("SELECT DISTINCT clave FROM siigo_lead_data_v2 WHERE method = 'Insert Call' ");
$keys = $keys_stmt->fetchAll(PDO::FETCH_COLUMN);

// Construir filtros desde formulario
//$where = "WHERE method = 'Insert Call' ";

$clienteActual = CLIENTE_ACTUAL;

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

// Obtener resumen diario de resultados de llamadas
// Construir WHERE clause para el resumen
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

// Obtener llamadas AVI por día (desde avi_call_costs)
$whereAVI = "WHERE cliente = '$clienteActual'";

if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
    $whereAVI .= " AND DATE(metadata_date_local) BETWEEN :fecha_desde AND :fecha_hasta";
} elseif (!empty($_GET['fecha_desde'])) {
    $whereAVI .= " AND DATE(metadata_date_local) >= :fecha_desde";
} elseif (!empty($_GET['fecha_hasta'])) {
    $whereAVI .= " AND DATE(metadata_date_local) <= :fecha_hasta";
}

if (!empty($_GET['proyecto'])) {
    $whereAVI .= " AND EXISTS (SELECT 1 FROM avi_calls ac WHERE ac.F9CallID = a.f9_call_id AND ac.PROYECTO = :proyecto)";
}

if (!empty($_GET['ani'])) {
    $whereAVI .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 s WHERE s.F9CallID = a.f9_call_id AND s.clave = 'ANI' AND s.valor = :ani)";
}

$sqlAVI = "
    SELECT
        DATE(metadata_date_local) as fecha,
        COUNT(*) as total
    FROM avi_call_costs a
    $whereAVI
    GROUP BY DATE(metadata_date_local)
    ORDER BY DATE(metadata_date_local) ASC
";
$stmtAVI = $pdo->prepare($sqlAVI);
$stmtAVI->execute($params);
$aviData = $stmtAVI->fetchAll(PDO::FETCH_ASSOC);

// Organizar llamadas AVI por fecha
$aviPorFecha = [];
foreach ($aviData as $row) {
    $aviPorFecha[$row['fecha']] = $row['total'];
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
$totalAVIGeneral = 0;
$totalColgadasGeneral = 0;

foreach ($todosResultados as $resultado) {
    $totalesPorResultado[$resultado] = 0;
    foreach ($resumenPorFecha as $fecha => $resultados) {
        if (isset($resultados[$resultado])) {
            $totalesPorResultado[$resultado] += $resultados[$resultado];
        }
    }
}

// Calcular total de llamadas realizadas
foreach ($realizadasPorFecha as $fecha => $total) {
    $totalRealizadasGeneral += $total;
}

// Calcular total de llamadas AVI y colgadas
foreach ($aviPorFecha as $fecha => $total) {
    $totalAVIGeneral += $total;
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
    <title>Dashboard de Leads - <?= CLIENTE_ACTUAL ?></title>
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
                        <h1 class="text-3xl font-bold">Dashboard de Leads (Cliente: <?= CLIENTE_ACTUAL ?>)</h1>
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
                                <th class="px-4 py-3 text-right font-bold">Llamadas Five9</th>
                                <th class="px-4 py-3 text-right font-bold">Llamadas AVI</th>
                                <?php foreach ($todosResultados as $resultado): ?>
                                    <th class="px-4 py-3 text-right font-bold"><?php echo htmlspecialchars($resultado); ?></th>
                                <?php endforeach; ?>
                                <th class="px-4 py-3 text-right font-bold">Llamadas Colgadas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php
                            // Get all unique dates from realizadas, resumen and avi
                            $todasFechas = array_unique(array_merge(
                                array_keys($realizadasPorFecha),
                                array_keys($resumenPorFecha),
                                array_keys($aviPorFecha)
                            ));
                            sort($todasFechas);

                            foreach ($todasFechas as $fecha):
                                $realizadas = $realizadasPorFecha[$fecha] ?? 0;
                                $avi = $aviPorFecha[$fecha] ?? 0;
                                $resultados = $resumenPorFecha[$fecha] ?? [];
                                $totalConResultado = array_sum($resultados);
                                $colgadas = $avi - $totalConResultado;
                            ?>
                            <tr class="hover:bg-blue-50 transition">
                                <td class="px-4 py-3 font-semibold text-gray-800"><?php echo date('d/m/Y', strtotime($fecha)); ?></td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-800"><?php echo number_format($realizadas); ?></td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-800"><?php echo number_format($avi); ?></td>
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
                                <td class="px-4 py-4 text-right text-blue-800"><?php echo number_format($totalAVIGeneral); ?></td>
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
    </script>
</body>
</html>