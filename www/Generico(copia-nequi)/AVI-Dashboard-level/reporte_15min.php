<?php
require_once 'config.php';
date_default_timezone_set('America/Puerto_Rico');

$pdo = getDBConnection();

$clientesDisponibles = defined('CLIENTES_DISPONIBLES') ? CLIENTES_DISPONIBLES : ['NEQUI' => 'NEQUI', 'NEQUI2' => 'NEQUI2'];
$clienteActual = (!empty($_GET['cliente']) && in_array($_GET['cliente'], $clientesDisponibles, true))
    ? $_GET['cliente']
    : (defined('CLIENTE_ACTUAL') ? CLIENTE_ACTUAL : 'NEQUI2');
$clienteLabel = array_search($clienteActual, $clientesDisponibles, true) ?: $clienteActual;

$fecha = $_GET['fecha'] ?? date('Y-m-d');
$umbral = max(1, (int)($_GET['umbral'] ?? 19));

$stmt = $pdo->prepare("
    SELECT
        FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(F9TimeStamp) / 900) * 900) AS intervalo_15_min,
        SUM(CASE WHEN duration <= :umbral THEN 1 ELSE 0 END) AS llamadas_menor_igual_u,
        SUM(CASE WHEN duration > :umbral2 THEN 1 ELSE 0 END) AS llamadas_mayor_u,
        COUNT(*) AS total_llamadas
    FROM level_calls
    WHERE cliente = :cliente
    AND DATE(F9TimeStamp) = :fecha
    GROUP BY intervalo_15_min
    ORDER BY intervalo_15_min
");
$stmt->execute([':umbral' => $umbral, ':umbral2' => $umbral, ':cliente' => $clienteActual, ':fecha' => $fecha]);
$rows = $stmt->fetchAll();

$totalMenorU = array_sum(array_column($rows, 'llamadas_menor_igual_u'));
$totalMayorU = array_sum(array_column($rows, 'llamadas_mayor_u'));
$totalGeneral = array_sum(array_column($rows, 'total_llamadas'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte 15 min - <?= htmlspecialchars($clienteLabel) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <i class="fas fa-clock text-3xl"></i>
                    <div>
                        <h1 class="text-3xl font-bold">Reporte de Intervalos 15 min</h1>
                        <p class="text-blue-100">Distribución de llamadas por duración - <strong><?= htmlspecialchars($clienteLabel) ?></strong></p>
                    </div>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <select name="cliente" onchange="this.form.submit()"
                            class="bg-white/90 text-gray-800 text-sm font-semibold rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-white">
                        <?php foreach ($clientesDisponibles as $etiqueta => $valor): ?>
                            <option value="<?= htmlspecialchars($valor) ?>" <?= $valor === $clienteActual ? 'selected' : '' ?>><?= htmlspecialchars($etiqueta) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha) ?>">
                    <input type="hidden" name="umbral" value="<?= $umbral ?>">
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-filter text-blue-600"></i> Filtros
            </h3>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                <input type="hidden" name="cliente" value="<?= htmlspecialchars($clienteActual) ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha</label>
                    <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Umbral (seg)</label>
                    <input type="number" name="umbral" value="<?= $umbral ?>" min="1" max="300"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded-lg transition duration-200">
                        <i class="fas fa-search mr-2"></i>Consultar
                    </button>
                </div>
            </form>
        </div>

        <?php if (!empty($rows)): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Total Llamadas</h3>
                    <i class="fas fa-phone text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= number_format($totalGeneral) ?></p>
            </div>
            <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Duración ≤ <?= $umbral ?>s</h3>
                    <i class="fas fa-bolt text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= number_format($totalMenorU) ?></p>
                <p class="text-xs opacity-75 mt-1"><?= $totalGeneral > 0 ? number_format($totalMenorU / $totalGeneral * 100, 1) : 0 ?>% del total</p>
            </div>
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Duración > <?= $umbral ?>s</h3>
                    <i class="fas fa-hourglass text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= number_format($totalMayorU) ?></p>
                <p class="text-xs opacity-75 mt-1"><?= $totalGeneral > 0 ? number_format($totalMayorU / $totalGeneral * 100, 1) : 0 ?>% del total</p>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-bar text-blue-600"></i> Llamadas por Intervalo de 15 min
            </h3>
            <div style="height: 350px;">
                <canvas id="chartIntervalos"></canvas>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <i class="fas fa-table"></i> Detalle por Intervalo
                </h3>
                <span class="bg-white text-blue-700 px-3 py-1 rounded-full text-sm font-bold"><?= count($rows) ?> intervalos</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-4 py-3 text-left">Intervalo</th>
                            <th class="px-4 py-3 text-right">Dur. ≤ <?= $umbral ?>s</th>
                            <th class="px-4 py-3 text-right">Dur. > <?= $umbral ?>s</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">% ≤ <?= $umbral ?>s</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($rows as $r):
                            $hora = date('H:i', strtotime($r['intervalo_15_min']));
                            $horaFin = date('H:i', strtotime($r['intervalo_15_min'] . ' +15 minutes'));
                            $pct = $r['total_llamadas'] > 0 ? ($r['llamadas_menor_igual_u'] / $r['total_llamadas']) * 100 : 0;
                        ?>
                        <tr class="hover:bg-blue-50 transition">
                            <td class="px-4 py-3 font-semibold text-gray-800"><?= $hora ?> - <?= $horaFin ?></td>
                            <td class="px-4 py-3 text-right text-orange-700 font-semibold"><?= number_format($r['llamadas_menor_igual_u']) ?></td>
                            <td class="px-4 py-3 text-right text-green-700 font-semibold"><?= number_format($r['llamadas_mayor_u']) ?></td>
                            <td class="px-4 py-3 text-right font-bold"><?= number_format($r['total_llamadas']) ?></td>
                            <td class="px-4 py-3 text-right text-gray-600"><?= number_format($pct, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        const labels = <?= json_encode(array_map(function($r) {
            return date('H:i', strtotime($r['intervalo_15_min']));
        }, $rows)) ?>;
        const dataMenorU = <?= json_encode(array_map(function($r) { return (int)$r['llamadas_menor_igual_u']; }, $rows)) ?>;
        const dataMayorU = <?= json_encode(array_map(function($r) { return (int)$r['llamadas_mayor_u']; }, $rows)) ?>;

        new Chart(document.getElementById('chartIntervalos'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Duración ≤ <?= $umbral ?>s',
                        data: dataMenorU,
                        backgroundColor: 'rgba(249, 115, 22, 0.7)',
                        borderColor: 'rgba(249, 115, 22, 1)',
                        borderWidth: 1,
                        borderRadius: 3,
                    },
                    {
                        label: 'Duración > <?= $umbral ?>s',
                        data: dataMayorU,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: 'rgba(16, 185, 129, 1)',
                        borderWidth: 1,
                        borderRadius: 3,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                },
                scales: {
                    x: {
                        title: { display: true, text: 'Hora del día' },
                        ticks: { maxTicksLimit: 24 },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        title: { display: true, text: 'Llamadas' },
                    }
                }
            }
        });
        </script>
        <?php else: ?>
        <div class="bg-gray-100 rounded-lg p-8 text-center">
            <i class="fas fa-chart-bar text-gray-400 text-5xl mb-4"></i>
            <p class="text-gray-600 text-lg">No hay datos para la fecha seleccionada</p>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
