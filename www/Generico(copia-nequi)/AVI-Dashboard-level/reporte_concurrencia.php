<?php
require_once 'config_reporte_concurrencia.php';

date_default_timezone_set('America/Puerto_Rico');

$pdo = getDBConnection();

$clientesDisponibles = CLIENTES_DISPONIBLES;
$clienteActual = (!empty($_GET['cliente']) && in_array($_GET['cliente'], $clientesDisponibles, true))
    ? $_GET['cliente']
    : CLIENTE_ACTUAL;
$clienteLabel = array_search($clienteActual, $clientesDisponibles, true) ?: $clienteActual;

$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-7 days'));
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

$callsJSON = '[]';
$diasCount = 0;

if (!empty($fechaDesde) && !empty($fechaHasta)) {
    $cc = buildClienteCondition($clienteActual);
    $params = $cc['params'];
    $params[':fecha_desde'] = $fechaDesde;
    $params[':fecha_hasta'] = $fechaHasta;

    $stmt = $pdo->prepare("
        SELECT F9CallID, F9TimeStamp, duration, ElevenTimeStamp
        FROM level_calls
        WHERE {$cc['sql']}
          AND DATE(F9TimeStamp) BETWEEN :fecha_desde AND :fecha_hasta
        ORDER BY F9TimeStamp
    ");
    $stmt->execute($params);
    $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $callsJSON = json_encode($calls);
    $diasCount = count(array_unique(array_map(function ($r) { return substr($r['F9TimeStamp'], 0, 10); }, $calls)));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Concurrencia - <?= htmlspecialchars($clienteLabel) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-gradient-to-r from-teal-600 to-teal-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <i class="fas fa-people-arrows text-3xl"></i>
                    <div>
                        <h1 class="text-3xl font-bold">Reporte de Concurrencia</h1>
                        <p class="text-teal-100">Llamadas concurrentes por día</p>
                    </div>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <label for="cliente" class="text-teal-100 text-sm">Cliente:</label>
                    <select name="cliente" id="cliente" onchange="this.form.submit()"
                            class="bg-white/90 text-gray-800 text-sm font-semibold rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-white">
                        <?php foreach ($clientesDisponibles as $etiqueta => $valor): ?>
                            <option value="<?= htmlspecialchars($valor) ?>" <?= $valor === $clienteActual ? 'selected' : '' ?>><?= htmlspecialchars($etiqueta) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>">
                    <input type="hidden" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>">
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-filter text-teal-600"></i>
                Filtros
            </h3>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <input type="hidden" name="cliente" value="<?= htmlspecialchars($clienteActual) ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha desde</label>
                    <input type="date" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha hasta</label>
                    <input type="date" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 rounded-lg transition duration-200">
                        <i class="fas fa-search mr-2"></i>Consultar
                    </button>
                </div>
            </form>
        </div>

        <?php if (!empty($calls) && count($calls) > 0): ?>
        <div id="kpis" class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-gradient-to-br from-teal-500 to-teal-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Días consultados</h3>
                    <i class="fas fa-calendar-alt text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold" id="kpi-dias"><?= $diasCount ?></p>
            </div>
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Total llamadas</h3>
                    <i class="fas fa-phone text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold" id="kpi-total"><?= count($calls) ?></p>
            </div>
            <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Máx concurrente</h3>
                    <i class="fas fa-people-arrows text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold" id="kpi-max-concurrent">-</p>
                <p class="text-xs opacity-75 mt-1">Pico máximo del período</p>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-line text-teal-600"></i>
                Concurrencia por Día
            </h3>
            <div class="relative" style="height: 350px;">
                <canvas id="chartConcurrencia"></canvas>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-table text-teal-600"></i>
                Detalle por Día
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gradient-to-r from-teal-600 to-teal-700 text-white">
                        <tr>
                            <th class="px-4 py-3 text-left">Fecha</th>
                            <th class="px-4 py-3 text-right">Total Llamadas</th>
                            <th class="px-4 py-3 text-right">Duración Promedio (min)</th>
                            <th class="px-4 py-3 text-right">Máx Concurrente</th>
                            <th class="px-4 py-3 text-right">Factor de uso</th>
                        </tr>
                    </thead>
                    <tbody id="tabla-body" class="divide-y divide-gray-200"></tbody>
                </table>
            </div>
        </div>

        <script>
        const calls = <?= $callsJSON ?>;

        function parseDate(s) { return new Date(s.replace(' ', 'T') + 'Z'); }

        function getEndTime(call) {
            if (call.ElevenTimeStamp) return parseDate(call.ElevenTimeStamp);
            const d = parseDate(call.F9TimeStamp);
            return new Date(d.getTime() + parseInt(call.duration || 0) * 1000);
        }

        function getDateKey(d) { return d.toISOString().slice(0, 10); }

        const eventos = calls.flatMap(call => {
            const start = parseDate(call.F9TimeStamp);
            const end = getEndTime(call);
            return [
                { fecha: getDateKey(start), ts: start.getTime(), delta: 1 },
                { fecha: getDateKey(end), ts: end.getTime(), delta: -1 }
            ];
        });

        const dias = {};
        calls.forEach(call => {
            const key = getDateKey(parseDate(call.F9TimeStamp));
            if (!dias[key]) dias[key] = { total: 0, duracionSum: 0 };
            dias[key].total++;
            dias[key].duracionSum += parseInt(call.duration || 0);
        });

        Object.keys(dias).forEach(key => {
            const dayEvents = eventos.filter(e => e.fecha === key);
            dayEvents.sort((a, b) => a.ts - b.ts || b.delta - a.delta);
            let conc = 0;
            let maxConc = 0;
            dayEvents.forEach(e => { conc += e.delta; if (conc > maxConc) maxConc = conc; });
            dias[key].maxConcurrent = maxConc;
        });

        const fechas = Object.keys(dias).sort();
        const totales = fechas.map(f => dias[f].total);
        const duracionProm = fechas.map(f => dias[f].total > 0 ? (dias[f].duracionSum / dias[f].total / 60) : 0);
        const maxConcurrent = fechas.map(f => dias[f].maxConcurrent);

        document.getElementById('kpi-max-concurrent').textContent = Math.max(...maxConcurrent);

        const tbody = document.getElementById('tabla-body');
        fechas.forEach((f, i) => {
            const factor = dias[f].maxConcurrent > 0 ? (dias[f].total / dias[f].maxConcurrent).toFixed(1) : '0.0';
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-teal-50 transition';
            tr.innerHTML = `
                <td class="px-4 py-3 font-semibold text-gray-800">${new Date(f + 'T00:00:00').toLocaleDateString('es')}</td>
                <td class="px-4 py-3 text-right font-semibold">${dias[f].total.toLocaleString()}</td>
                <td class="px-4 py-3 text-right">${duracionProm[i].toFixed(1)}</td>
                <td class="px-4 py-3 text-right"><span class="bg-orange-100 text-orange-800 font-bold px-3 py-1 rounded-full">${dias[f].maxConcurrent}</span></td>
                <td class="px-4 py-3 text-right text-gray-600">${factor}</td>
            `;
            tbody.appendChild(tr);
        });

        new Chart(document.getElementById('chartConcurrencia'), {
            type: 'bar',
            data: {
                labels: fechas.map(f => { const d = new Date(f + 'T00:00:00'); return d.toLocaleDateString('es'); }),
                datasets: [
                    {
                        label: 'Total Llamadas',
                        data: totales,
                        backgroundColor: 'rgba(13, 148, 136, 0.6)',
                        borderColor: 'rgba(13, 148, 136, 1)',
                        borderWidth: 1,
                        yAxisID: 'y',
                        order: 2
                    },
                    {
                        label: 'Máx Concurrente',
                        data: maxConcurrent,
                        type: 'line',
                        borderColor: 'rgba(234, 88, 12, 1)',
                        backgroundColor: 'rgba(234, 88, 12, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: 'rgba(234, 88, 12, 1)',
                        pointRadius: 5,
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y1',
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: { display: true, text: 'Total Llamadas' },
                        ticks: { precision: 0 }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: { display: true, text: 'Máx Concurrente' },
                        ticks: { precision: 0 },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
        </script>
        <?php else: ?>
            <div class="bg-gray-100 rounded-lg p-8 text-center">
                <i class="fas fa-chart-bar text-gray-400 text-5xl mb-4"></i>
                <p class="text-gray-600 text-lg">No hay datos para el rango seleccionado</p>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
