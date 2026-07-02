<?php
// Cargar configuración central
require_once 'config.php';

// Obtener conexión PDO desde config
$pdo = getDBConnection();

// Cliente actual desde config
$clienteActual = CLIENTE_ACTUAL;

// Función helper para convertir segundos a formato MM:SS
function secondsToMMSS($seconds) {
    if ($seconds <= 0) return '00:00';
    $totalSeconds = (int)round($seconds);
    $minutes = intdiv($totalSeconds, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d', $minutes, $secs);
}

// Función helper para convertir segundos a HH:MM:SS
function secondsToHHMMSS($seconds) {
    if ($seconds <= 0) return '00:00:00';
    $totalSeconds = (int)round($seconds);
    $hours = intdiv($totalSeconds, 3600);
    $minutes = intdiv($totalSeconds % 3600, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

// Obtener parámetros de filtro
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01'); // Primer día del mes actual
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d'); // Hoy

// Construir WHERE clause base
$whereBase = "WHERE DATE(metadata_date_local) BETWEEN :fecha_inicio AND :fecha_fin AND cliente = :cliente";
$params = [
    ':fecha_inicio' => $fechaInicio,
    ':fecha_fin' => $fechaFin,
    ':cliente' => $clienteActual
];

// KPI 1: Total de llamadas
$stmtTotalLlamadas = $pdo->prepare("SELECT COUNT(*) as total FROM avi_call_costs $whereBase");
$stmtTotalLlamadas->execute($params);
$totalLlamadas = $stmtTotalLlamadas->fetch()['total'];

// KPI 2: Duración total en segundos
$stmtDuracionTotal = $pdo->prepare("SELECT SUM(connection_duration_secs) as total FROM avi_call_costs $whereBase");
$stmtDuracionTotal->execute($params);
$duracionTotalSecs = $stmtDuracionTotal->fetch()['total'] ?? 0;
$duracionTotalMinutos = round($duracionTotalSecs / 60, 2);

// KPI 3: Promedio duración por llamada
$promedioDuracion = $totalLlamadas > 0 ? $duracionTotalSecs / $totalLlamadas : 0;

// Tendencia por día
$stmtTendenciaDiaria = $pdo->prepare("
    SELECT
        DATE(metadata_date_local) as fecha,
        COUNT(*) as total_llamadas,
        SUM(connection_duration_secs) as duracion_total,
        AVG(connection_duration_secs) as duracion_promedio
    FROM avi_call_costs
    $whereBase
    GROUP BY DATE(metadata_date_local)
    ORDER BY fecha ASC
");
$stmtTendenciaDiaria->execute($params);
$tendenciaDiaria = $stmtTendenciaDiaria->fetchAll();

// Obtener llamadas Five9 por día (desde avi_calls)
$whereFive9 = "WHERE cliente = '$clienteActual'";
$paramsFive9 = [];

if (!empty($_GET['fecha_inicio']) && !empty($_GET['fecha_fin'])) {
    $whereFive9 .= " AND DATE(F9TimeStamp) BETWEEN :fecha_inicio AND :fecha_fin";
    $paramsFive9[':fecha_inicio'] = $fechaInicio;
    $paramsFive9[':fecha_fin'] = $fechaFin;
} elseif (!empty($_GET['fecha_inicio'])) {
    $whereFive9 .= " AND DATE(F9TimeStamp) >= :fecha_inicio";
    $paramsFive9[':fecha_inicio'] = $fechaInicio;
} elseif (!empty($_GET['fecha_fin'])) {
    $whereFive9 .= " AND DATE(F9TimeStamp) <= :fecha_fin";
    $paramsFive9[':fecha_fin'] = $fechaFin;
}

$sqlFive9 = "
    SELECT
        DATE(F9TimeStamp) as fecha,
        COUNT(*) as total
    FROM avi_calls
    $whereFive9
    GROUP BY DATE(F9TimeStamp)
    ORDER BY DATE(F9TimeStamp) ASC
";
$stmtFive9 = $pdo->prepare($sqlFive9);
$stmtFive9->execute($paramsFive9);
$five9Data = $stmtFive9->fetchAll();

// Organizar llamadas Five9 por fecha
$five9PorFecha = [];
foreach ($five9Data as $row) {
    $five9PorFecha[$row['fecha']] = $row['total'];
}

// Organizar llamadas AVI por fecha (de tendenciaDiaria)
$aviPorFecha = [];
foreach ($tendenciaDiaria as $row) {
    $aviPorFecha[$row['fecha']] = $row['total_llamadas'];
}

// Calcular totales
$totalFive9General = array_sum($five9PorFecha);
$totalAVIGeneral = array_sum($aviPorFecha);

// Top 10 llamadas más largas
$stmtTopLargas = $pdo->prepare("
    SELECT
        f9_call_id,
        caller_id,
        called_number,
        connection_duration_secs,
        connection_duration_mmss,
        metadata_date_local
    FROM avi_call_costs
    $whereBase
    ORDER BY connection_duration_secs DESC
    LIMIT 10
");
$stmtTopLargas->execute($params);
$topLargas = $stmtTopLargas->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard <?= CLIENTE_ACTUAL ?> - Análisis de Llamadas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="flex items-center gap-3">
                <i class="fas fa-chart-bar text-3xl"></i>
                <div>
                    <h1 class="text-3xl font-bold">Dashboard <?= CLIENTE_ACTUAL ?></h1>
                    <p class="text-blue-100">Análisis de Llamadas</p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 py-8">
        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-filter text-blue-600"></i>Filtros
            </h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fecha Fin</label>
                    <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded-lg transition duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-search"></i>Aplicar Filtros
                    </button>
                </div>
            </form>
        </div>

        <!-- KPIs Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <!-- Total Llamadas -->
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Total Llamadas</h3>
                    <i class="fas fa-phone text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold"><?= number_format($totalLlamadas) ?></p>
                <p class="text-xs opacity-75 mt-1"><?= $fechaInicio ?> - <?= $fechaFin ?></p>
            </div>

            <!-- Duración Total -->
            <div class="bg-gradient-to-br from-teal-500 to-teal-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Duración Total</h3>
                    <i class="fas fa-clock text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold"><?= number_format($duracionTotalMinutos) ?></p>
                <p class="text-xs opacity-75 mt-1">Minutos</p>
            </div>

            <!-- Duración Promedio -->
            <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Duración Promedio</h3>
                    <i class="fas fa-hourglass-half text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold"><?= secondsToMMSS($promedioDuracion) ?></p>
                <p class="text-xs opacity-75 mt-1">MM:SS por llamada</p>
            </div>
        </div>

        <!-- Gráfico -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-line text-blue-600"></i>Tendencia Diaria de Llamadas
            </h2>
            <canvas id="chartTendencia"></canvas>
        </div>

        <!-- Resumen Diario -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-calendar-day text-blue-600"></i>Resumen Diario
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">Fecha</th>
                            <th class="px-4 py-3 text-right font-bold">Llamadas Five9</th>
                            <th class="px-4 py-3 text-right font-bold">Llamadas AVI</th>
                            <th class="px-4 py-3 text-right font-bold">Duración Total</th>
                            <th class="px-4 py-3 text-right font-bold">Duración Promedio</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php
                        $totalDiasLlamadas = 0;
                        $totalDiasDuracion = 0;

                        // Crear array con todas las fechas únicas
                        $todasFechas = array_unique(array_merge(
                            array_keys($aviPorFecha),
                            array_keys($five9PorFecha)
                        ));
                        sort($todasFechas);

                        // Crear un mapa de tendenciaDiaria por fecha para acceder a duración
                        $tendenciaPorFecha = [];
                        foreach ($tendenciaDiaria as $row) {
                            $tendenciaPorFecha[$row['fecha']] = $row;
                        }

                        foreach ($todasFechas as $fecha):
                            $avi = $aviPorFecha[$fecha] ?? 0;
                            $five9 = $five9PorFecha[$fecha] ?? 0;
                            $duracionTotal = isset($tendenciaPorFecha[$fecha]) ? $tendenciaPorFecha[$fecha]['duracion_total'] : 0;
                            $duracionPromedio = isset($tendenciaPorFecha[$fecha]) ? $tendenciaPorFecha[$fecha]['duracion_promedio'] : 0;

                            $totalDiasLlamadas += $avi;
                            $totalDiasDuracion += $duracionTotal;
                        ?>
                        <tr class="hover:bg-blue-50 transition">
                            <td class="px-4 py-3 font-semibold text-gray-800"><?= date('d/m/Y', strtotime($fecha)) ?></td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= number_format($five9) ?></td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= number_format($avi) ?></td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= number_format(round($duracionTotal / 60, 2)) ?> min</td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= secondsToMMSS($duracionPromedio) ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Fila de Totales -->
                        <tr class="bg-blue-100 border-t-2 border-blue-600 font-bold text-base">
                            <td class="px-4 py-4 text-blue-800">TOTAL</td>
                            <td class="px-4 py-4 text-right text-blue-800"><?= number_format($totalFive9General) ?></td>
                            <td class="px-4 py-4 text-right text-blue-800"><?= number_format($totalAVIGeneral) ?></td>
                            <td class="px-4 py-4 text-right text-blue-800"><?= number_format(round($totalDiasDuracion / 60, 2)) ?> min</td>
                            <td class="px-4 py-4 text-right text-blue-800">
                                <?= $totalDiasLlamadas > 0 ? secondsToMMSS($totalDiasDuracion / $totalDiasLlamadas) : '00:00' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top 10 Llamadas más Largas -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-trophy text-blue-600"></i>Top 10 Llamadas más Largas
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">F9 Call ID</th>
                            <th class="px-4 py-3 text-left">Origen</th>
                            <th class="px-4 py-3 text-left">Destino</th>
                            <th class="px-4 py-3 text-right">Duración</th>
                            <th class="px-4 py-3 text-left">Fecha</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php $rank = 1; foreach ($topLargas as $row): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-4 py-3 font-bold text-blue-600"><?= $rank++ ?></td>
                            <td class="px-4 py-3 font-mono text-xs"><?= htmlspecialchars($row['f9_call_id']) ?></td>
                            <td class="px-4 py-3 font-mono text-xs"><?= htmlspecialchars($row['caller_id']) ?></td>
                            <td class="px-4 py-3 font-mono text-xs"><?= htmlspecialchars($row['called_number']) ?></td>
                            <td class="px-4 py-3 text-right text-teal-600 font-bold"><?= htmlspecialchars($row['connection_duration_mmss']) ?></td>
                            <td class="px-4 py-3 text-xs"><?= date('Y-m-d H:i', strtotime($row['metadata_date_local'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        // Datos para gráfico de tendencia
        const tendenciaData = <?= json_encode($tendenciaDiaria) ?>;
        const labels = tendenciaData.map(d => d.fecha);
        const totalLlamadas = tendenciaData.map(d => parseInt(d.total_llamadas) || 0);

        // Gráfico de Tendencia
        const ctxTendencia = document.getElementById('chartTendencia').getContext('2d');
        new Chart(ctxTendencia, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total Llamadas',
                        data: totalLlamadas,
                        borderColor: 'rgb(37, 99, 235)',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y + ' llamadas';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + ' llamadas';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
