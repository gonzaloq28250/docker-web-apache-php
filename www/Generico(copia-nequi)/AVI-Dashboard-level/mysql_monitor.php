<?php
require_once 'config.php';

date_default_timezone_set('America/Puerto_Rico');

$pdo = getDBConnection();

$resultadoQuery = null;
$errorQuery = null;
$columnasQuery = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['custom_query'])) {
    $customQuery = trim($_POST['custom_query']);
    $upperQuery = strtoupper($customQuery);

    $bloqueadas = ['DELETE', 'DROP', 'TRUNCATE', 'UPDATE', 'INSERT', 'ALTER', 'CREATE',
                   'REPLACE', 'RENAME', 'GRANT', 'REVOKE', 'KILL', 'EXECUTE', 'SET'];
    $patternBloqueadas = '/\b(' . implode('|', $bloqueadas) . ')\b/';
    if (preg_match($patternBloqueadas, $upperQuery)) {
        $errorQuery = 'No se permiten operaciones de escritura (DELETE, DROP, UPDATE, INSERT, etc).';
    } elseif (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\s/i', $upperQuery)) {
        try {
            $stmt = $pdo->query($customQuery);
            if ($stmt && $stmt->columnCount() > 0) {
                $resultadoQuery = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($resultadoQuery)) {
                    $columnasQuery = array_keys($resultadoQuery[0]);
                }
            } else {
                $resultadoQuery = [];
            }
        } catch (PDOException $e) {
            $errorQuery = 'Error: ' . $e->getMessage();
        }
    } else {
        $errorQuery = 'Solo se permiten consultas SELECT, SHOW, DESCRIBE o EXPLAIN.';
    }
}

$totalConexiones = 0;
$conexionesActivas = 0;
$procesos = [];
$queriesLentos = [];

try {
    $row = $pdo->query("SHOW GLOBAL STATUS LIKE 'Threads_connected'")->fetch();
    $totalConexiones = (int)$row['Value'];

    $row = $pdo->query("SHOW GLOBAL STATUS LIKE 'Threads_running'")->fetch();
    $conexionesActivas = (int)$row['Value'];

    $stmt = $pdo->query("SHOW FULL PROCESSLIST");
    $procesos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($procesos as $p) {
        $time = (int)($p['Time'] ?? 0);
        if ($time > 5 && !in_array($p['Command'], ['Sleep', 'Daemon', 'Binlog Dump'])) {
            $queriesLentos[] = $p;
        }
    }
} catch (PDOException $e) {
    $errorQuery = 'Error al obtener estadísticas: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MySQL Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-3">
                <i class="fas fa-database text-3xl text-emerald-400"></i>
                <h1 class="text-3xl font-bold">MySQL Monitor</h1>
                <span class="text-sm text-gray-500"><?= htmlspecialchars(DB_HOST) ?></span>
            </div>
            <button id="autoRefreshBtn" onclick="toggleAutoRefresh()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm flex items-center gap-2">
                <i class="fas fa-sync-alt"></i> <span>Auto</span>
            </button>
            <button onclick="location.reload()" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm flex items-center gap-2">
                <i class="fas fa-sync-alt"></i> Refrescar
            </button>
        </div>

        <div id="kpi-grid" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-gray-800 rounded-xl p-5 border border-gray-700">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold text-gray-400">Total Conexiones</h3>
                    <i class="fas fa-plug text-blue-400"></i>
                </div>
                <p class="text-3xl font-bold text-blue-400"><?= number_format($totalConexiones) ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-5 border border-gray-700">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold text-gray-400">Conexiones Activas</h3>
                    <i class="fas fa-bolt text-yellow-400"></i>
                </div>
                <p class="text-3xl font-bold text-yellow-400"><?= number_format($conexionesActivas) ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-5 border border-gray-700">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold text-gray-400">Queries en Curso</h3>
                    <i class="fas fa-play-circle text-green-400"></i>
                </div>
                <p class="text-3xl font-bold text-green-400"><?= number_format(count(array_filter($procesos, fn($p) => !in_array($p['Command'] ?? '', ['Sleep', 'Daemon', 'Binlog Dump'])))) ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-5 border border-gray-700">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold text-gray-400">Queries Lentos (&gt;5s)</h3>
                    <i class="fas fa-exclamation-triangle text-red-400"></i>
                </div>
                <p class="text-3xl font-bold text-red-400"><?= count($queriesLentos) ?></p>
            </div>
        </div>

        <div id="panels-grid" class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div id="procesos-panel" class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                <div class="bg-gray-750 px-5 py-3 border-b border-gray-700 flex items-center gap-2">
                    <i class="fas fa-list text-green-400"></i>
                    <h2 class="font-semibold">Todos los Procesos</h2>
                    <span class="text-xs text-gray-500 ml-auto"><?= count($procesos) ?> conexiones</span>
                </div>
                <div class="overflow-x-auto max-h-80 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-750 text-gray-400 sticky top-0">
                            <tr>
                                <th class="px-3 py-2 text-left">ID</th>
                                <th class="px-3 py-2 text-left">Usuario</th>
                                <th class="px-3 py-2 text-left">Host</th>
                                <th class="px-3 py-2 text-left">DB</th>
                                <th class="px-3 py-2 text-left">Comando</th>
                                <th class="px-3 py-2 text-right">Tiempo</th>
                                <th class="px-3 py-2 text-left">Estado</th>
                                <th class="px-3 py-2 text-left">Query</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach ($procesos as $p): $time = (int)$p['Time'];
                                $isActive = !in_array($p['Command'], ['Sleep', 'Daemon', 'Binlog Dump']);
                            ?>
                            <tr class="<?= $isActive ? 'bg-gray-750' : 'text-gray-500' ?> hover:bg-gray-700">
                                <td class="px-3 py-1.5 font-mono"><?= htmlspecialchars($p['Id']) ?></td>
                                <td class="px-3 py-1.5"><?= htmlspecialchars($p['User']) ?></td>
                                <td class="px-3 py-1.5 font-mono text-[10px]"><?= htmlspecialchars($p['Host']) ?></td>
                                <td class="px-3 py-1.5"><?= htmlspecialchars($p['db'] ?? '-') ?></td>
                                <td class="px-3 py-1.5">
                                    <span class="<?= $isActive ? 'text-green-400' : 'text-gray-500' ?>"><?= htmlspecialchars($p['Command']) ?></span>
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono <?= $time > 10 ? 'text-red-400 font-bold' : ($time > 5 ? 'text-yellow-400' : '') ?>"><?= $time ?>s</td>
                                <td class="px-3 py-1.5"><?= htmlspecialchars($p['State'] ?? '-') ?></td>
                                <td class="px-3 py-1.5 max-w-xs truncate font-mono text-[10px]" title="<?= htmlspecialchars($p['Info'] ?? '') ?>"><?= htmlspecialchars(substr($p['Info'] ?? '', 0, 100)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                <div class="bg-gray-750 px-5 py-3 border-b border-gray-700 flex items-center gap-2">
                    <i class="fas fa-turtle text-red-400"></i>
                    <h2 class="font-semibold">Queries Lentos (&gt;5s)</h2>
                    <span class="text-xs text-gray-500 ml-auto"><?= count($queriesLentos) ?> queries</span>
                </div>
                <?php if (empty($queriesLentos)): ?>
                <div class="p-8 text-center text-gray-500">
                    <i class="fas fa-check-circle text-3xl text-emerald-500 mb-2"></i>
                    <p>No hay queries lentos en este momento</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto max-h-80 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-750 text-gray-400 sticky top-0">
                            <tr>
                                <th class="px-3 py-2 text-left">ID</th>
                                <th class="px-3 py-2 text-left">Usuario</th>
                                <th class="px-3 py-2 text-right">Tiempo</th>
                                <th class="px-3 py-2 text-left">Query</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach ($queriesLentos as $p): ?>
                            <tr class="hover:bg-gray-700">
                                <td class="px-3 py-1.5 font-mono"><?= htmlspecialchars($p['Id']) ?></td>
                                <td class="px-3 py-1.5"><?= htmlspecialchars($p['User']) ?></td>
                                <td class="px-3 py-1.5 text-right font-mono text-red-400 font-bold"><?= (int)$p['Time'] ?>s</td>
                                <td class="px-3 py-1.5 font-mono text-[10px] break-all max-w-md"><?= htmlspecialchars($p['Info'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-gray-800 rounded-xl border border-gray-700 mb-8">
            <div class="bg-gray-750 px-5 py-3 border-b border-gray-700 flex items-center gap-2">
                <i class="fas fa-terminal text-purple-400"></i>
                <h2 class="font-semibold">Query Personalizado</h2>
            </div>
            <div class="p-5">
                <form method="POST" class="space-y-3">
                    <textarea name="custom_query" rows="3"
                        class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-sm font-mono text-gray-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        placeholder="Escribe tu query SQL aquí... (SELECT, SHOW, DESCRIBE, EXPLAIN)"><?= htmlspecialchars($_POST['custom_query'] ?? '') ?></textarea>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2 rounded-lg text-sm font-semibold flex items-center gap-2">
                        <i class="fas fa-play"></i> Ejecutar
                    </button>
                </form>

                <?php if ($errorQuery): ?>
                <div class="mt-4 bg-red-900/50 border border-red-700 rounded-lg p-4 text-red-300">
                    <i class="fas fa-times-circle mr-2"></i><?= htmlspecialchars($errorQuery) ?>
                </div>
                <?php endif; ?>

                <?php if ($resultadoQuery !== null): ?>
                <div class="mt-4">
                    <div class="flex items-center gap-2 mb-2 text-sm text-gray-400">
                        <i class="fas fa-table"></i>
                        <span>Resultados: <?= count($resultadoQuery) ?> filas</span>
                    </div>
                    <div class="overflow-x-auto max-h-96 overflow-y-auto rounded-lg border border-gray-700">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-700 text-gray-300 sticky top-0">
                                <tr>
                                    <?php foreach ($columnasQuery as $col): ?>
                                    <th class="px-3 py-2 text-left font-semibold whitespace-nowrap"><?= htmlspecialchars($col) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700">
                                <?php foreach ($resultadoQuery as $fila): ?>
                                <tr class="hover:bg-gray-750">
                                    <?php foreach ($columnasQuery as $col): ?>
                                    <td class="px-3 py-1.5 font-mono text-gray-200 max-w-xs truncate" title="<?= htmlspecialchars($fila[$col] ?? '') ?>"><?= htmlspecialchars($fila[$col] ?? 'NULL') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let refreshTimer = null;
        let refreshEnabled = false;

        function toggleAutoRefresh() {
            const btn = document.getElementById('autoRefreshBtn');
            const icon = btn.querySelector('i');
            if (refreshEnabled) {
                clearInterval(refreshTimer);
                refreshTimer = null;
                refreshEnabled = false;
                btn.classList.remove('bg-red-600', 'hover:bg-red-700');
                btn.classList.add('bg-emerald-600', 'hover:bg-emerald-700');
                icon.classList.remove('fa-stop');
                icon.classList.add('fa-sync-alt');
                btn.querySelector('span').textContent = 'Auto';
            } else {
                refreshEnabled = true;
                btn.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
                btn.classList.add('bg-red-600', 'hover:bg-red-700');
                icon.classList.remove('fa-sync-alt');
                icon.classList.add('fa-stop');
                btn.querySelector('span').textContent = 'Detener';
                refreshTimer = setInterval(refreshStats, 10000);
            }
        }

        function refreshStats() {
            fetch(location.href)
                .then(r => r.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    const newKpi = doc.getElementById('kpi-grid');
                    const oldKpi = document.getElementById('kpi-grid');
                    if (newKpi && oldKpi) oldKpi.outerHTML = newKpi.outerHTML;

                    const newPanels = doc.getElementById('panels-grid');
                    const oldPanels = document.getElementById('panels-grid');
                    if (newPanels && oldPanels) oldPanels.outerHTML = newPanels.outerHTML;
                })
                .catch(() => {});
        }
    </script>
</body>
</html>
