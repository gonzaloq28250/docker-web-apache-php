<?php
// Conexión PDO
try {
    $pdo = new PDO(
        'mysql:host=icqdbmysqlreports.mysql.database.azure.com;dbname=n8n_icq;charset=utf8mb4',
        'gonzaloq',
        '73ch$iCC',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die('Error de conexión: ' . $e->getMessage());
}

// Función helper para convertir segundos a formato MM:SS
function secondsToMMSS($seconds) {
    if ($seconds <= 0) return '00:00';
    $totalSeconds = (int)round($seconds);
    $minutes = intdiv($totalSeconds, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d', $minutes, $secs);
}

// Obtener parámetros de filtro
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01'); // Primer día del mes actual
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d'); // Hoy
$clienteFiltro = $_GET['cliente'] ?? '';

// Construir WHERE clause base
$whereBase = "WHERE DATE(metadata_date_local) BETWEEN :fecha_inicio AND :fecha_fin";
$params = [
    ':fecha_inicio' => $fechaInicio,
    ':fecha_fin' => $fechaFin
];

if (!empty($clienteFiltro)) {
    $whereBase .= " AND cliente = :cliente";
    $params[':cliente'] = $clienteFiltro;
}

// KPI 1: Total de llamadas
$stmtTotalLlamadas = $pdo->prepare("SELECT COUNT(*) as total FROM avi_call_costs $whereBase");
$stmtTotalLlamadas->execute($params);
$totalLlamadas = $stmtTotalLlamadas->fetch()['total'];

// KPI 2: Costo total de llamadas (USD)
$stmtCostoLlamadas = $pdo->prepare("SELECT SUM(call_cost_usd) as total FROM avi_call_costs $whereBase");
$stmtCostoLlamadas->execute($params);
$costoTotalLlamadas = $stmtCostoLlamadas->fetch()['total'] ?? 0;

// KPI 3: Costo total LLM (USD)
$stmtCostoLLM = $pdo->prepare("SELECT SUM(llm_cost_total_usd) as total FROM avi_call_costs $whereBase");
$stmtCostoLLM->execute($params);
$costoTotalLLM = $stmtCostoLLM->fetch()['total'] ?? 0;

// KPI 4: Costo total combinado
$costoTotalCombinado = $costoTotalLlamadas + $costoTotalLLM;

// KPI 5: Promedio costo por llamada
$promedioCostoLlamada = $totalLlamadas > 0 ? $costoTotalLlamadas / $totalLlamadas : 0;

// KPI 6: Promedio costo LLM por llamada
$promedioCostoLLM = $totalLlamadas > 0 ? $costoTotalLLM / $totalLlamadas : 0;

// KPI 7: Duración total en segundos
$stmtDuracionTotal = $pdo->prepare("SELECT SUM(connection_duration_secs) as total FROM avi_call_costs $whereBase");
$stmtDuracionTotal->execute($params);
$duracionTotalSecs = $stmtDuracionTotal->fetch()['total'] ?? 0;
$duracionTotalMinutos = round($duracionTotalSecs / 60, 2);

// KPI 8: Promedio duración por llamada
$promedioDuracion = $totalLlamadas > 0 ? $duracionTotalSecs / $totalLlamadas : 0;

// Breakdown por cliente
$stmtPorCliente = $pdo->prepare("
    SELECT
        cliente,
        COUNT(*) as total_llamadas,
        SUM(call_cost_usd) as costo_llamadas,
        SUM(llm_cost_total_usd) as costo_llm,
        SUM(call_cost_usd + llm_cost_total_usd) as costo_total,
        AVG(connection_duration_secs) as promedio_duracion
    FROM avi_call_costs
    $whereBase
    GROUP BY cliente
    ORDER BY costo_total DESC
");
$stmtPorCliente->execute($params);
$breakdownClientes = $stmtPorCliente->fetchAll();

// Tendencia por día
$stmtTendenciaDiaria = $pdo->prepare("
    SELECT
        DATE(metadata_date_local) as fecha,
        COUNT(*) as total_llamadas,
        SUM(call_cost_usd) as costo_llamadas,
        SUM(llm_cost_total_usd) as costo_llm,
        SUM(call_cost_usd + llm_cost_total_usd) as costo_total
    FROM avi_call_costs
    $whereBase
    GROUP BY DATE(metadata_date_local)
    ORDER BY fecha ASC
");
$stmtTendenciaDiaria->execute($params);
$tendenciaDiaria = $stmtTendenciaDiaria->fetchAll();

// Top 10 llamadas más costosas
$stmtTopCostosas = $pdo->prepare("
    SELECT
        f9_call_id,
        cliente,
        caller_id,
        called_number,
        connection_duration_mmss,
        call_cost_usd,
        llm_cost_total_usd,
        (call_cost_usd + llm_cost_total_usd) as costo_total,
        metadata_date_local
    FROM avi_call_costs
    $whereBase
    ORDER BY costo_total DESC
    LIMIT 10
");
$stmtTopCostosas->execute($params);
$topCostosas = $stmtTopCostosas->fetchAll();

// Obtener lista de clientes para el filtro
$stmtClientes = $pdo->query("SELECT DISTINCT cliente FROM avi_call_costs WHERE cliente IS NOT NULL ORDER BY cliente");
$clientes = $stmtClientes->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Costos - Análisis de Llamadas y LLM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <header class="bg-gradient-to-r from-purple-600 to-purple-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="flex items-center gap-3">
                <i class="fas fa-chart-line text-3xl"></i>
                <div>
                    <h1 class="text-3xl font-bold">Dashboard de Costos</h1>
                    <p class="text-purple-100">Análisis de Llamadas y LLM</p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 py-8">
        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-filter text-purple-600"></i>Filtros
            </h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fecha Fin</label>
                    <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Cliente</label>
                    <select name="cliente" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <option value="">Todos</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= htmlspecialchars($cliente) ?>" <?= $clienteFiltro === $cliente ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cliente) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 rounded-lg transition duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-search"></i>Aplicar Filtros
                    </button>
                </div>
            </form>
        </div>

        <!-- KPIs Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <!-- Total Llamadas -->
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Total Llamadas</h3>
                    <i class="fas fa-phone text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold"><?= number_format($totalLlamadas) ?></p>
                <p class="text-xs opacity-75 mt-1"><?= $fechaInicio ?> - <?= $fechaFin ?></p>
            </div>

            <!-- Costo Total Llamadas -->
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Costo Llamadas</h3>
                    <i class="fas fa-dollar-sign text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold">$<?= number_format($costoTotalLlamadas, 2) ?></p>
                <p class="text-xs opacity-75 mt-1">Promedio: $<?= number_format($promedioCostoLlamada, 4) ?></p>
            </div>

            <!-- Costo Total LLM -->
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Costo LLM</h3>
                    <i class="fas fa-brain text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold">$<?= number_format($costoTotalLLM, 2) ?></p>
                <p class="text-xs opacity-75 mt-1">Promedio: $<?= number_format($promedioCostoLLM, 4) ?></p>
            </div>

            <!-- Costo Total Combinado -->
            <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Costo Total</h3>
                    <i class="fas fa-chart-pie text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold">$<?= number_format($costoTotalCombinado, 2) ?></p>
                <p class="text-xs opacity-75 mt-1">
                    <?php
                    $porcentajeLLM = $costoTotalCombinado > 0 ? ($costoTotalLLM / $costoTotalCombinado) * 100 : 0;
                    echo number_format($porcentajeLLM, 1) . '% LLM';
                    ?>
                </p>
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

            <!-- Costo por Minuto -->
            <div class="bg-gradient-to-br from-pink-500 to-pink-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Costo por Minuto</h3>
                    <i class="fas fa-money-bill-wave text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold">
                    $<?= $duracionTotalMinutos > 0 ? number_format($costoTotalCombinado / $duracionTotalMinutos, 4) : '0.00' ?>
                </p>
                <p class="text-xs opacity-75 mt-1">Promedio general</p>
            </div>

            <!-- Eficiencia -->
            <div class="bg-gradient-to-br from-cyan-500 to-cyan-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Relación LLM/Llamada</h3>
                    <i class="fas fa-balance-scale text-2xl opacity-50"></i>
                </div>
                <p class="text-3xl font-bold">
                    <?= $costoTotalLlamadas > 0 ? number_format(($costoTotalLLM / $costoTotalLlamadas) * 100, 1) : '0' ?>%
                </p>
                <p class="text-xs opacity-75 mt-1">LLM vs Costo de Llamada</p>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Gráfico de Tendencia Diaria -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-line text-purple-600"></i>Tendencia Diaria de Costos
                </h2>
                <canvas id="chartTendencia"></canvas>
            </div>

            <!-- Gráfico Pie: Distribución de Costos -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-purple-600"></i>Distribución de Costos
                </h2>
                <canvas id="chartDistribucion"></canvas>
            </div>
        </div>

        <!-- Resumen Diario -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-calendar-day text-purple-600"></i>Resumen Diario de Costos
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gradient-to-r from-purple-600 to-purple-700 text-white">
                        <tr>
                            <th class="px-4 py-3 text-left">Fecha</th>
                            <th class="px-4 py-3 text-right">Total Llamadas</th>
                            <th class="px-4 py-3 text-right">Costo Llamadas</th>
                            <th class="px-4 py-3 text-right">Costo LLM</th>
                            <th class="px-4 py-3 text-right">Costo Total</th>
                            <th class="px-4 py-3 text-right">Costo Prom. por Llamada</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php
                        $totalDiasLlamadas = 0;
                        $totalDiasCostoLlamadas = 0;
                        $totalDiasCostoLLM = 0;
                        $totalDiasCostoTotal = 0;

                        foreach ($tendenciaDiaria as $row):
                            $totalDiasLlamadas += $row['total_llamadas'];
                            $totalDiasCostoLlamadas += $row['costo_llamadas'];
                            $totalDiasCostoLLM += $row['costo_llm'];
                            $totalDiasCostoTotal += $row['costo_total'];
                            $costoProm = $row['total_llamadas'] > 0 ? $row['costo_total'] / $row['total_llamadas'] : 0;
                        ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-4 py-3 font-semibold text-gray-800"><?= date('d/m/Y', strtotime($row['fecha'])) ?></td>
                            <td class="px-4 py-3 text-right"><?= number_format($row['total_llamadas']) ?></td>
                            <td class="px-4 py-3 text-right text-green-600 font-semibold">$<?= number_format($row['costo_llamadas'], 2) ?></td>
                            <td class="px-4 py-3 text-right text-purple-600 font-semibold">$<?= number_format($row['costo_llm'], 2) ?></td>
                            <td class="px-4 py-3 text-right text-orange-600 font-bold">$<?= number_format($row['costo_total'], 2) ?></td>
                            <td class="px-4 py-3 text-right text-gray-600">$<?= number_format($costoProm, 4) ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Fila de Totales -->
                        <tr class="bg-purple-50 border-t-2 border-purple-600 font-bold">
                            <td class="px-4 py-4 text-purple-800 text-base">TOTAL</td>
                            <td class="px-4 py-4 text-right text-base"><?= number_format($totalDiasLlamadas) ?></td>
                            <td class="px-4 py-4 text-right text-green-700 text-base">$<?= number_format($totalDiasCostoLlamadas, 2) ?></td>
                            <td class="px-4 py-4 text-right text-purple-700 text-base">$<?= number_format($totalDiasCostoLLM, 2) ?></td>
                            <td class="px-4 py-4 text-right text-orange-700 text-base">$<?= number_format($totalDiasCostoTotal, 2) ?></td>
                            <td class="px-4 py-4 text-right text-gray-700 text-base">
                                $<?= $totalDiasLlamadas > 0 ? number_format($totalDiasCostoTotal / $totalDiasLlamadas, 4) : '0.0000' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Breakdown por Cliente -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-users text-purple-600"></i>Análisis por Cliente
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gradient-to-r from-purple-600 to-purple-700 text-white">
                        <tr>
                            <th class="px-4 py-3 text-left">Cliente</th>
                            <th class="px-4 py-3 text-right">Llamadas</th>
                            <th class="px-4 py-3 text-right">Costo Llamadas</th>
                            <th class="px-4 py-3 text-right">Costo LLM</th>
                            <th class="px-4 py-3 text-right">Costo Total</th>
                            <th class="px-4 py-3 text-right">% del Total</th>
                            <th class="px-4 py-3 text-right">Duración Prom.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($breakdownClientes as $row):
                            $porcentajeDelTotal = $costoTotalCombinado > 0 ? ($row['costo_total'] / $costoTotalCombinado) * 100 : 0;
                        ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-4 py-3 font-semibold text-gray-800"><?= htmlspecialchars($row['cliente']) ?></td>
                            <td class="px-4 py-3 text-right"><?= number_format($row['total_llamadas']) ?></td>
                            <td class="px-4 py-3 text-right text-green-600 font-semibold">$<?= number_format($row['costo_llamadas'], 2) ?></td>
                            <td class="px-4 py-3 text-right text-purple-600 font-semibold">$<?= number_format($row['costo_llm'], 2) ?></td>
                            <td class="px-4 py-3 text-right text-orange-600 font-bold">$<?= number_format($row['costo_total'], 2) ?></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="w-16 bg-gray-200 rounded-full h-2">
                                        <div class="bg-purple-600 h-2 rounded-full" style="width: <?= $porcentajeDelTotal ?>%"></div>
                                    </div>
                                    <span class="text-gray-600"><?= number_format($porcentajeDelTotal, 1) ?>%</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-600"><?= secondsToMMSS($row['promedio_duracion']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top 10 Llamadas más Costosas -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-trophy text-purple-600"></i>Top 10 Llamadas más Costosas
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gradient-to-r from-purple-600 to-purple-700 text-white">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">F9 Call ID</th>
                            <th class="px-4 py-3 text-left">Cliente</th>
                            <th class="px-4 py-3 text-left">Origen</th>
                            <th class="px-4 py-3 text-left">Destino</th>
                            <th class="px-4 py-3 text-left">Duración</th>
                            <th class="px-4 py-3 text-right">Costo Llamada</th>
                            <th class="px-4 py-3 text-right">Costo LLM</th>
                            <th class="px-4 py-3 text-right">Costo Total</th>
                            <th class="px-4 py-3 text-left">Fecha</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php $rank = 1; foreach ($topCostosas as $row): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-4 py-3 font-bold text-purple-600"><?= $rank++ ?></td>
                            <td class="px-4 py-3 font-mono text-xs"><?= htmlspecialchars($row['f9_call_id']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($row['cliente']) ?></td>
                            <td class="px-4 py-3 font-mono text-xs"><?= htmlspecialchars($row['caller_id']) ?></td>
                            <td class="px-4 py-3 font-mono text-xs"><?= htmlspecialchars($row['called_number']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($row['connection_duration_mmss']) ?></td>
                            <td class="px-4 py-3 text-right text-green-600">$<?= number_format($row['call_cost_usd'], 4) ?></td>
                            <td class="px-4 py-3 text-right text-purple-600">$<?= number_format($row['llm_cost_total_usd'], 4) ?></td>
                            <td class="px-4 py-3 text-right text-orange-600 font-bold">$<?= number_format($row['costo_total'], 4) ?></td>
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
        const costoLlamadas = tendenciaData.map(d => parseFloat(d.costo_llamadas) || 0);
        const costoLLM = tendenciaData.map(d => parseFloat(d.costo_llm) || 0);
        const costoTotal = tendenciaData.map(d => parseFloat(d.costo_total) || 0);

        // Gráfico de Tendencia
        const ctxTendencia = document.getElementById('chartTendencia').getContext('2d');
        new Chart(ctxTendencia, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Costo Total',
                        data: costoTotal,
                        borderColor: 'rgb(249, 115, 22)',
                        backgroundColor: 'rgba(249, 115, 22, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Costo Llamadas',
                        data: costoLlamadas,
                        borderColor: 'rgb(34, 197, 94)',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Costo LLM',
                        data: costoLLM,
                        borderColor: 'rgb(168, 85, 247)',
                        backgroundColor: 'rgba(168, 85, 247, 0.1)',
                        tension: 0.4
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
                                return context.dataset.label + ': $' + context.parsed.y.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    }
                }
            }
        });

        // Gráfico de Distribución (Pie)
        const ctxDistribucion = document.getElementById('chartDistribucion').getContext('2d');
        new Chart(ctxDistribucion, {
            type: 'doughnut',
            data: {
                labels: ['Costo Llamadas', 'Costo LLM'],
                datasets: [{
                    data: [<?= $costoTotalLlamadas ?>, <?= $costoTotalLLM ?>],
                    backgroundColor: [
                        'rgb(34, 197, 94)',
                        'rgb(168, 85, 247)'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': $' + context.parsed.toFixed(2) + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
