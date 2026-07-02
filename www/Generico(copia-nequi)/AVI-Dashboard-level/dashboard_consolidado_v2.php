<?php
// Cargar configuración central
require_once 'config.php';

// Establecer zona horaria a Puerto Rico
date_default_timezone_set('America/Puerto_Rico');

// Obtener conexión PDO desde config
$pdo = getDBConnection();

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

$clienteActual = 'NEQUI2';

// ============================================
// PARTE 1: REALTIME - DATOS DEL DÍA ACTUAL
// ============================================

$fechaHoy = date('Y-m-d');

// Llamadas en Curso (hoy) — query simple sin JOIN
$stmtEnCursoHoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM level_calls
    WHERE cliente = :cliente
    AND Estado = 'IN-PROGRESS'
    AND DATE(F9TimeStamp) = :fecha_hoy
");
$stmtEnCursoHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$llamadasEnCursoHoy = $stmtEnCursoHoy->fetch()['total'];

// Resultados + duración (hoy) — query consolidada
$stmtJoinedHoy = $pdo->prepare("
    SELECT
        lvc.call_result as resultado,
        COUNT(*) as total,
        SUM(CASE WHEN lc.duration >= 60 THEN lc.duration ELSE 0 END) as duracion
    FROM level_calls lc
    INNER JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
    WHERE lc.cliente = :cliente
    AND lc.Estado = 'TERMINATED'
    AND lvc.customer = :cliente2
    AND DATE(lc.F9TimeStamp) = :fecha_hoy
    AND lvc.call_result IS NOT NULL
    AND lvc.call_result NOT IN ('IVR_Regular', '\"IVR_Regular\"')
    GROUP BY lvc.call_result
    ORDER BY total DESC
");
$stmtJoinedHoy->execute([':cliente' => $clienteActual, ':cliente2' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$rowsJoinedHoy = $stmtJoinedHoy->fetchAll();

$resultadosHoy = [];
$totalLlamadasHoy = 0;
$duracionTotalSecsHoy = 0;
foreach ($rowsJoinedHoy as $row) {
    $resultadosHoy[] = ['resultado' => $row['resultado'], 'total' => (int)$row['total']];
    $totalLlamadasHoy += (int)$row['total'];
    $duracionTotalSecsHoy += (int)$row['duracion'];
}
$colgadasHoy = 0;
$duracionTotalSecsHoy = max(0, $duracionTotalSecsHoy);
$duracionPromedioSecsHoy = $totalLlamadasHoy > 0 ? $duracionTotalSecsHoy / $totalLlamadasHoy : 0;

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

// ============================================
// PARTE 2: HISTÓRICO CON FILTROS
// ============================================

// Obtener parámetros de filtro
$fechaHoyInicio = date('Y-m-d') . 'T00:00';
$fechaHoyFin = date('Y-m-d') . 'T23:59';

// Si hay parámetros en la URL, usarlos; si no, usar valores por defecto
$fechaDesde = !empty($_GET['fecha_desde']) ? $_GET['fecha_desde'] : $fechaHoyInicio;
$fechaHasta = !empty($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : $fechaHoyFin;
$buscar = $_GET['buscar'] ?? '';

// Convertir formato datetime-local (YYYY-MM-DDTHH:MM) a formato MySQL (YYYY-MM-DD HH:MM:SS)
function convertDateTimeToLocal($dateTime) {
    if (empty($dateTime)) return '';
    // Reemplazar T por espacio y agregar segundos si faltan
    $converted = str_replace('T', ' ', $dateTime);
    // Si tiene formato HH:MM, agregar :00
    if (preg_match('/\d{2}:\d{2}$/', $converted)) {
        $converted .= ':00';
    }
    return $converted;
}

// Convertir formato MySQL a datetime-local para el HTML
function convertToDateTimeLocal($dateTime) {
    if (empty($dateTime)) return '';
    // Quitar segundos si existen
    $converted = preg_replace('/:\d{2}$/', '', $dateTime);
    // Reemplazar espacio por T
    return str_replace(' ', 'T', $converted);
}

$fechaDesde = convertDateTimeToLocal($fechaDesde);
$fechaHasta = convertDateTimeToLocal($fechaHasta);
// Convertir para queries SQL
$fechaDesdeSQL = convertDateTimeToLocal($_GET['fecha_desde'] ?? '');
$fechaHastaSQL = convertDateTimeToLocal($_GET['fecha_hasta'] ?? '');

// Variables para datos históricos
$totalLlamadasHistorico = 0;
$duracionTotalSecsHistorico = 0;
$duracionPromedioSecsHistorico = 0;
$resultadosHistorico = [];
$totalResultadosHistorico = 0;
$colgadasHistorico = 0;

$aplicarFiltros = !empty($fechaDesdeSQL) || !empty($fechaHastaSQL) || !empty($buscar);

if ($aplicarFiltros) {
    // ============================================
    // QUERY CONSOLIDADA #1: datos agregados (KPIs + pie + resumen diario)
    // ============================================
    $paramsAgg = [':cliente' => $clienteActual, ':cliente2' => $clienteActual];
    $whereAgg = "WHERE lc.cliente = :cliente AND lc.Estado = 'TERMINATED' AND lvc.customer = :cliente2";

    if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
        $whereAgg .= " AND lc.F9TimeStamp BETWEEN :fecha_desde AND :fecha_hasta";
        $paramsAgg[':fecha_desde'] = $fechaDesdeSQL;
        $paramsAgg[':fecha_hasta'] = $fechaHastaSQL;
    } elseif (!empty($fechaDesdeSQL)) {
        $whereAgg .= " AND lc.F9TimeStamp >= :fecha_desde";
        $paramsAgg[':fecha_desde'] = $fechaDesdeSQL;
    } elseif (!empty($fechaHastaSQL)) {
        $whereAgg .= " AND lc.F9TimeStamp <= :fecha_hasta";
        $paramsAgg[':fecha_hasta'] = $fechaHastaSQL;
    }

    $sqlAgg = "
        SELECT
            DATE(lc.F9TimeStamp) as fecha,
            lvc.call_result as resultado,
            COUNT(*) as total,
            SUM(CASE WHEN lc.duration >= 60 THEN lc.duration ELSE 0 END) as duracion
        FROM level_calls lc
        INNER JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
        $whereAgg
        AND lvc.call_result IS NOT NULL
        AND lvc.call_result NOT IN ('IVR_Regular', '\"IVR_Regular\"')
        GROUP BY DATE(lc.F9TimeStamp), lvc.call_result
        ORDER BY DATE(lc.F9TimeStamp) ASC, lvc.call_result
    ";
    $stmtAgg = $pdo->prepare($sqlAgg);
    $stmtAgg->execute($paramsAgg);
    $aggRows = $stmtAgg->fetchAll(PDO::FETCH_ASSOC);

    // Procesar datos agregados
    $resultadosHistorico = [];
    $totalesPorResultado = [];
    $resumenPorFechaHistorico = [];
    $totalPorFecha = [];
    $todosResultadosHistorico = [];
    $duracionTotalSecsHistorico = 0;

    foreach ($aggRows as $row) {
        $fecha = $row['fecha'];
        $res = $row['resultado'];
        $cnt = (int)$row['total'];
        $dur = (int)$row['duracion'];

        // Totales por resultado (para pie chart)
        if (!isset($totalesPorResultado[$res])) {
            $totalesPorResultado[$res] = 0;
            $todosResultadosHistorico[] = $res;
        }
        $totalesPorResultado[$res] += $cnt;

        // Duración total
        $duracionTotalSecsHistorico += $dur;

        // Resumen diario
        if (!isset($resumenPorFechaHistorico[$fecha])) {
            $resumenPorFechaHistorico[$fecha] = [];
            $totalPorFecha[$fecha] = 0;
        }
        $resumenPorFechaHistorico[$fecha][$res] = $cnt;
        $totalPorFecha[$fecha] += $cnt;
    }

    foreach ($totalesPorResultado as $res => $total) {
        $resultadosHistorico[] = ['resultado' => $res, 'total' => $total];
    }
    usort($resultadosHistorico, function($a, $b) { return $b['total'] - $a['total']; });

    $totalLlamadasHistorico = array_sum(array_column($resultadosHistorico, 'total'));
    $colgadasHistorico = 0;
    $duracionTotalSecsHistorico = max(0, $duracionTotalSecsHistorico);
    $duracionPromedioSecsHistorico = $totalLlamadasHistorico > 0 ? $duracionTotalSecsHistorico / $totalLlamadasHistorico : 0;
    $retencionExitosaHistorico = $totalesPorResultado['contencion_exitosa'] ?? 0;
    $consultaResueltaHistorico = $totalesPorResultado['consulta_resuelta'] ?? 0;
    $tasaRetencionAVIHistorico = $totalLlamadasHistorico > 0 ? (($retencionExitosaHistorico + $consultaResueltaHistorico) / $totalLlamadasHistorico) * 100 : 0;
    $colgadasPorFechaHistorico = [];

    // ============================================
    // QUERY CONSOLIDADA #2: detalle de leads con call_result
    // ============================================
    $paramsLeads = [':cliente' => $clienteActual, ':cliente2' => $clienteActual];
    $whereLeads = "WHERE lc.cliente = :cliente AND lc.Estado = 'TERMINATED'";

    if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
        $whereLeads .= " AND lc.F9TimeStamp BETWEEN :fecha_desde AND :fecha_hasta";
        $paramsLeads[':fecha_desde'] = $fechaDesdeSQL;
        $paramsLeads[':fecha_hasta'] = $fechaHastaSQL;
    } elseif (!empty($fechaDesdeSQL)) {
        $whereLeads .= " AND lc.F9TimeStamp >= :fecha_desde";
        $paramsLeads[':fecha_desde'] = $fechaDesdeSQL;
    } elseif (!empty($fechaHastaSQL)) {
        $whereLeads .= " AND lc.F9TimeStamp <= :fecha_hasta";
        $paramsLeads[':fecha_hasta'] = $fechaHastaSQL;
    }

    if (!empty($buscar)) {
        $whereLeads .= " AND (lc.ANI LIKE :buscar OR lc.DNIS LIKE :buscar OR lc.PROYECTO LIKE :buscar OR lc.F9CallID LIKE :buscar)";
        $paramsLeads[':buscar'] = "%$buscar%";
    }

    $sqlLeads = "
        SELECT lc.F9CallID, lc.F9TimeStamp, lc.ANI, lc.DNIS, lc.PROYECTO, lc.Estado, lc.duration,
               lvc.call_result as resultado_llamada
        FROM level_calls lc
        LEFT JOIN level_conversations lvc ON lc.F9CallID = lvc.callid AND lvc.customer = :cliente2
        $whereLeads
        ORDER BY lc.F9TimeStamp DESC
    ";
    $stmtLeads = $pdo->prepare($sqlLeads);
    $stmtLeads->execute($paramsLeads);
    $rowsLeads = $stmtLeads->fetchAll(PDO::FETCH_ASSOC);

    $leadsHistorico = [];
    $datosAdicionalesPorF9CallID = [];
    foreach ($rowsLeads as $row) {
        $f9id = $row['F9CallID'];
        $leadsHistorico[$f9id] = $row;
        $datosAdicionalesPorF9CallID[$f9id] = $row['resultado_llamada'] ?? '-';
    }
}

// Función para generar colores para el gráfico
function generarColores($cantidad) {
    $colores = [
        '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6',
        '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16'
    ];
    $resultado = [];
    for ($i = 0; $i < $cantidad; $i++) {
        $resultado[] = $colores[$i % count($colores)];
    }
    return $resultado;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Consolidado - NEQUI2</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <header class="bg-gradient-to-r from-indigo-600 to-purple-700 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="flex items-center gap-3">
                <i class="fas fa-chart-pie text-3xl"></i>
                <div>
                    <h1 class="text-3xl font-bold">Dashboard Consolidado - NEQUI2</h1>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <!-- ============================================ -->
        <!-- PARTE 1: REALTIME - DATOS DE HOY -->
        <!-- ============================================ -->
        <section class="mb-10" id="realtime-section">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="bg-green-500 text-white px-4 py-2 rounded-full flex items-center gap-2">
                        <span class="w-3 h-3 bg-white rounded-full animate-pulse"></span>
                        <span class="font-semibold">REALTIME</span>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800">Datos del Día Actual</h2>
                    <span class="text-gray-500">(<?= date('d/m/Y') ?>)</span>
                </div>

                <!-- Configuración de Refresh -->
                <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-lg shadow-sm border border-gray-200">
                    <span class="text-sm font-medium text-gray-700"><i class="fas fa-sync-alt mr-2"></i>Auto-refresh:</span>
                    <select id="refreshInterval" class="px-3 py-1 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-sm">
                        <option value="0">Desactivado</option>
                        <option value="5">5 segundos</option>
                        <option value="10">10 segundos</option>
                        <option value="15">15 segundos</option>
                        <option value="30">30 segundos</option>
                        <option value="60">60 segundos</option>
                    </select>
                    <button id="toggleRefreshBtn" onclick="toggleAutoRefresh()"
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-200 flex items-center gap-2 text-sm">
                        <i class="fas fa-play"></i>
                        <span id="refreshBtnText">Iniciar</span>
                    </button>
                    <span id="refreshStatus" class="text-xs text-gray-500"></span>
                </div>
            </div>

            <!-- KPIs Realtime -->
            <div class="relative mb-6">
                <!-- Indicador de actualización -->
                <div id="refresh-indicator" class="absolute -top-2 -right-2 bg-green-500 text-white px-2 py-1 rounded-full text-xs opacity-0 transition-opacity duration-300 z-10">
                    <i class="fas fa-check mr-1"></i>Actualizado
                </div>

                <!-- Primera fila -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                    <!-- Llamadas en Curso -->
                    <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Llamadas en Curso</h3>
                            <i class="fas fa-phone-volume text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold flex items-center gap-2">
                            <span id="rt-llamadas-en-curso"><?= number_format($llamadasEnCursoHoy) ?></span>
                            <span id="rt-pulse-indicator" class="<?= $llamadasEnCursoHoy > 0 ? '' : 'hidden' ?> w-3 h-3 bg-white rounded-full animate-pulse"></span>
                        </p>
                        <p class="text-xs opacity-75 mt-1">En progreso ahora mismo</p>
                    </div>

                    <!-- Total Llamadas -->
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Total Llamadas</h3>
                            <i class="fas fa-phone text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><span id="rt-total-llamadas"><?= number_format($totalLlamadasHoy) ?></span></p>
                        <p class="text-xs opacity-75 mt-1">Hoy <?= date('d/m/Y') ?></p>
                    </div>

                    <!-- Duración Total -->
                    <div class="bg-gradient-to-br from-teal-500 to-teal-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Duración Total</h3>
                            <i class="fas fa-clock text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><span id="rt-duracion-total"><?= secondsToHHMMSS($duracionTotalSecsHoy) ?></span></p>
                        <p class="text-xs opacity-75 mt-1">HH:MM:SS</p>
                    </div>
                </div>

                <!-- Segunda fila -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Duración Promedio -->
                    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Duración Promedio</h3>
                            <i class="fas fa-hourglass-half text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><span id="rt-duracion-promedio"><?= secondsToMMSS($duracionPromedioSecsHoy) ?></span></p>
                        <p class="text-xs opacity-75 mt-1">MM:SS por llamada</p>
                    </div>

                    <!-- Tasa Retención AVI -->
                    <div class="bg-gradient-to-br from-violet-500 to-violet-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Tasa Retención AVI</h3>
                            <i class="fas fa-percentage text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><span id="rt-tasa-retencion"><?= number_format($tasaRetencionAVIHoy, 1) ?>%</span></p>
                        <p class="text-xs opacity-75 mt-1">Con resultado</p>
                    </div>
                </div>
            </div>

            <!-- Gráfico Pie: Tipo de Llamadas -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-blue-600"></i>
                    Tipo de Llamadas
                </h3>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="relative" style="height: 300px;">
                        <canvas id="chartTipoLlamadasHoy"></canvas>
                    </div>
                    <div class="overflow-y-auto" style="max-height: 300px;">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-100 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2 text-left">Resultado</th>
                                    <th class="px-4 py-2 text-right">Total</th>
                                    <th class="px-4 py-2 text-right">%</th>
                                </tr>
                            </thead>
                            <tbody id="rt-resultados-tbody" class="divide-y divide-gray-200">
                                <tr class="bg-blue-50">
                                    <td class="px-4 py-2 font-semibold text-blue-600">Llamadas Totales</td>
                                    <td class="px-4 py-2 text-right font-semibold text-blue-600"><?= number_format($totalLlamadasHoy) ?></td>
                                    <td class="px-4 py-2 text-right text-gray-500">-</td>
                                </tr>
                                <?php foreach ($resultadosHoy as $row): $porcentaje = $totalLlamadasHoy > 0 ? ($row['total'] / $totalLlamadasHoy) * 100 : 0; ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2 pl-8">→ <?= htmlspecialchars($row['resultado']) ?></td>
                                    <td class="px-4 py-2 text-right font-semibold"><?= number_format($row['total']) ?></td>
                                    <td class="px-4 py-2 text-right"><?= number_format($porcentaje, 1) ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($colgadasHoy > 0): $porcentajeColgadas = ($colgadasHoy / $totalLlamadasHoy) * 100; ?>
                                <tr class="hover:bg-gray-50 bg-red-50">
                                    <td class="px-4 py-2 pl-8 font-semibold text-red-600">→ Llamadas Colgadas</td>
                                    <td class="px-4 py-2 text-right font-semibold text-red-600"><?= number_format($colgadasHoy) ?></td>
                                    <td class="px-4 py-2 text-right text-red-600"><?= number_format($porcentajeColgadas, 1) ?>%</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- Divider -->
        <div class="border-t-2 border-dashed border-gray-300 my-8"></div>

        <!-- ============================================ -->
        <!-- PARTE 2: HISTÓRICO CON FILTROS -->
        <!-- ============================================ -->
        <section>
            <div class="flex items-center gap-3 mb-6">
                <div class="bg-purple-500 text-white px-4 py-2 rounded-full flex items-center gap-2">
                    <i class="fas fa-history"></i>
                    <span class="font-semibold">HISTÓRICO</span>
                </div>
                <h2 class="text-2xl font-bold text-gray-800">Datos Filtrados</h2>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-filter text-purple-600"></i>
                    Filtros de Búsqueda
                </h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Fecha Desde -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar-alt mr-2"></i>Fecha y hora desde
                        </label>
                        <input type="datetime-local" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <!-- Fecha Hasta -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar-alt mr-2"></i>Fecha y hora hasta
                        </label>
                        <input type="datetime-local" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <!-- Buscar -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-search mr-2"></i>Buscar en datos
                        </label>
                        <input type="text" name="buscar" placeholder="Buscar en cualquier campo..."
                               value="<?= htmlspecialchars($buscar) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <!-- Botones -->
                    <div class="md:col-span-3 flex gap-3">
                        <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition duration-200 flex items-center justify-center gap-2">
                            <i class="fas fa-search"></i>Buscar
                        </button>
                        <a href="dashboard_consolidado_v2.php" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-3 rounded-lg transition duration-200 flex items-center justify-center gap-2 text-center">
                            <i class="fas fa-redo"></i>Limpiar
                        </a>
                    </div>
                </form>
            </div>

            <!-- Resultados Históricos -->
            <?php if ($aplicarFiltros): ?>
                <!-- KPIs Históricos -->
                <!-- Primera fila -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-4">
                    <!-- Total Llamadas -->
                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Total Llamadas</h3>
                            <i class="fas fa-phone text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><?= number_format($totalLlamadasHistorico) ?></p>
                        <p class="text-xs opacity-75 mt-1">
                            <?= !empty($fechaDesde) ? date('d/m/Y', strtotime($fechaDesde)) : '...' ?>
                            <?= !empty($fechaDesde) && !empty($fechaHasta) ? ' - ' : '' ?>
                            <?= !empty($fechaHasta) ? date('d/m/Y', strtotime($fechaHasta)) : '' ?>
                        </p>
                    </div>

                    <!-- Duración Total -->
                    <div class="bg-gradient-to-br from-pink-500 to-pink-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Duración Total</h3>
                            <i class="fas fa-clock text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><?= secondsToHHMMSS($duracionTotalSecsHistorico) ?></p>
                        <p class="text-xs opacity-75 mt-1">HH:MM:SS</p>
                    </div>

                    <!-- Duración Promedio -->
                    <div class="bg-gradient-to-br from-rose-500 to-rose-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Duración Promedio</h3>
                            <i class="fas fa-hourglass-half text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><?= secondsToMMSS($duracionPromedioSecsHistorico) ?></p>
                        <p class="text-xs opacity-75 mt-1">MM:SS por llamada</p>
                    </div>
                </div>

                <!-- Segunda fila -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Tasa Retención AVI -->
                    <div class="bg-gradient-to-br from-violet-500 to-violet-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Tasa Retención AVI</h3>
                            <i class="fas fa-percentage text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><?= number_format($tasaRetencionAVIHistorico, 1) ?>%</p>
                        <p class="text-xs opacity-75 mt-1">Contención + Consulta</p>
                    </div>
                </div>

                <!-- Gráfico Pie: Tipo de Llamadas (Histórico) -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-pie text-purple-600"></i>
                        Tipo de Llamadas
                    </h3>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="relative" style="height: 300px;">
                            <canvas id="chartTipoLlamadasHistorico"></canvas>
                        </div>
                        <div class="overflow-y-auto" style="max-height: 300px;">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-100 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left">Resultado</th>
                                        <th class="px-4 py-2 text-right">Total</th>
                                        <th class="px-4 py-2 text-right">%</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr class="bg-purple-50">
                                        <td class="px-4 py-2 font-semibold text-purple-600">Llamadas Totales</td>
                                        <td class="px-4 py-2 text-right font-semibold text-purple-600"><?= number_format($totalLlamadasHistorico) ?></td>
                                        <td class="px-4 py-2 text-right text-gray-500">-</td>
                                    </tr>
                                    <?php foreach ($resultadosHistorico as $row): $porcentaje = $totalLlamadasHistorico > 0 ? ($row['total'] / $totalLlamadasHistorico) * 100 : 0; ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2 pl-8">→ <?= htmlspecialchars($row['resultado']) ?></td>
                                        <td class="px-4 py-2 text-right font-semibold"><?= number_format($row['total']) ?></td>
                                        <td class="px-4 py-2 text-right"><?= number_format($porcentaje, 1) ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if ($colgadasHistorico > 0): $porcentajeColgadas = ($colgadasHistorico / $totalLlamadasHistorico) * 100; ?>
                                    <tr class="hover:bg-gray-50 bg-red-50">
                                        <td class="px-4 py-2 pl-8 font-semibold text-red-600">→ Llamadas Colgadas</td>
                                        <td class="px-4 py-2 text-right font-semibold text-red-600"><?= number_format($colgadasHistorico) ?></td>
                                        <td class="px-4 py-2 text-right text-red-600"><?= number_format($porcentajeColgadas, 1) ?>%</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Resumen Diario por Resultado de Llamada (Histórico) -->
                <div class="bg-white rounded-lg shadow-md p-6 mt-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-calendar-alt text-purple-600"></i>
                            Resumen Diario por Resultado de Llamada
                        </h3>
                        <button onclick="exportResumenHistoricoToExcel()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-200 flex items-center gap-2">
                            <i class="fas fa-file-excel"></i> Exportar
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table id="resumenTableHistorico" class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-purple-600 to-purple-700 text-white">
                                <tr>
                                    <th class="px-4 py-3 text-left font-bold">Fecha</th>
                                    <th class="px-4 py-3 text-right font-bold">Llamadas Totales</th>
                                    <th class="px-4 py-3 text-right font-bold">% Retención</th>
                                    <?php foreach ($todosResultadosHistorico as $resultado): ?>
                                        <th class="px-4 py-3 text-right font-bold"><?= htmlspecialchars($resultado) ?></th>
                                        <th class="px-4 py-3 text-right font-bold">%</th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php
                                $todasFechasHistorico = array_keys($resumenPorFechaHistorico);
                                sort($todasFechasHistorico);

                                $totalGeneral = 0;
                                $totalesPorResultadoHistorico = [];
                                foreach ($todosResultadosHistorico as $res) {
                                    $totalesPorResultadoHistorico[$res] = 0;
                                }

                                foreach ($todasFechasHistorico as $fecha):
                                    // Usar los arrays ya calculados
                                    $total = $totalPorFecha[$fecha] ?? 0;
                                    $resultados = $resumenPorFechaHistorico[$fecha] ?? [];

                                    $totalGeneral += $total;
                                    foreach ($todosResultadosHistorico as $res) {
                                        $totalesPorResultadoHistorico[$res] += ($resultados[$res] ?? 0);
                                    }
                                ?>
                                <tr class="hover:bg-purple-50 transition">
                                    <td class="px-4 py-3 font-semibold text-gray-800"><?= date('d/m/Y', strtotime($fecha)) ?></td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= number_format($total) ?></td>
                                    <td class="px-4 py-3 text-right font-semibold text-purple-700">
                                        <?php
                                        $retencionExitosa = ($resultados['contencion_exitosa'] ?? 0) + ($resultados['consulta_resuelta'] ?? 0);
                                        $porcentajeRetencion = $total > 0 ? ($retencionExitosa / $total) * 100 : 0;
                                        echo number_format($porcentajeRetencion, 1) . '%';
                                        ?>
                                    </td>
                                    <?php foreach ($todosResultadosHistorico as $resultado): ?>
                                        <td class="px-4 py-3 text-right <?= isset($resultados[$resultado]) ? 'text-gray-800 font-semibold' : 'text-gray-400'; ?>">
                                            <?= isset($resultados[$resultado]) ? number_format($resultados[$resultado]) : '-' ?>
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-600">
                                            <?= isset($resultados[$resultado]) && $total > 0 ? number_format(($resultados[$resultado] / $total) * 100, 1) . '%' : '-' ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>

                                <!-- Fila de Totales -->
                                <tr class="bg-purple-100 border-t-2 border-purple-600 font-bold text-base">
                                    <td class="px-4 py-4 text-purple-800">TOTAL</td>
                                    <td class="px-4 py-4 text-right text-purple-800"><?= number_format($totalGeneral) ?></td>
                                    <td class="px-4 py-4 text-right text-purple-800">
                                        <?php
                                        $retencionTotal = ($totalesPorResultadoHistorico['contencion_exitosa'] ?? 0) + ($totalesPorResultadoHistorico['consulta_resuelta'] ?? 0);
                                        $porcentajeRetencionTotal = $totalGeneral > 0 ? ($retencionTotal / $totalGeneral) * 100 : 0;
                                        echo number_format($porcentajeRetencionTotal, 1) . '%';
                                        ?>
                                    </td>
                                    <?php foreach ($todosResultadosHistorico as $resultado): ?>
                                        <td class="px-4 py-4 text-right text-purple-800">
                                            <?= number_format($totalesPorResultadoHistorico[$resultado]) ?>
                                        </td>
                                        <td class="px-4 py-4 text-right text-purple-700">
                                            <?= $totalGeneral > 0 ? number_format(($totalesPorResultadoHistorico[$resultado] / $totalGeneral) * 100, 1) . '%' : '-' ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tabla de Leads (Histórico) -->
                <div class="bg-white rounded-lg shadow-md p-6 mt-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-list text-purple-600"></i>
                            Detalle de Interacciones
                            <span class="text-sm font-normal text-gray-500">(<?= count($leadsHistorico) ?> registros)</span>
                        </h3>
                        <button onclick="exportarInteraccionesExcel()"
                                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-200 flex items-center gap-2">
                            <i class="fas fa-file-excel"></i>
                            <span>Exportar Excel</span>
                        </button>
                    </div>
                    <!-- Buscador específico para interacciones -->
                    <div class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="relative">
                            <input type="text" id="buscarInteraccion" placeholder="Buscar por F9CallID, ANI, teléfono..."
                                   class="w-full px-4 py-3 pl-12 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                   oninput="filtrarInteracciones()">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <button onclick="limpiarBusqueda()" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="relative">
                            <select id="filtrarResultado"
                                    class="w-full px-4 py-3 pl-12 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                    onchange="filtrarInteracciones()">
                                <option value="">Todos los resultados</option>
                                <option value="contencion_exitosa">Contención Exitosa</option>
                                <option value="consulta_resuelta">Consulta Resuelta</option>
                                <option value="unknown">Unknown</option>
                                <option value="failure">Failure</option>
                                <option value="-">Sin resultado</option>
                            </select>
                            <i class="fas fa-filter absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                    </div>
                    <?php if (empty($leadsHistorico)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-4"></i>
                            <p>No hay datos disponibles</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm" id="tablaInteracciones">
                                <thead class="bg-gradient-to-r from-purple-600 to-purple-700 text-white">
                                    <tr>
                                        <th class="px-4 py-3 text-center font-bold">#</th>
                                        <th class="px-4 py-3 text-center font-bold">Acciones</th>
                                        <th class="px-4 py-3 text-left font-bold">F9CallID</th>
                                        <th class="px-4 py-3 text-left font-bold">F9TimeStamp</th>
                                        <th class="px-4 py-3 text-left font-bold">ANI</th>
                                        <th class="px-4 py-3 text-left font-bold">Resultado</th>
                                        <th class="px-4 py-3 text-right font-bold">Duración</th>
                                    </tr>
                                </thead>
                                <tbody id="interaccionesContainer" class="divide-y divide-gray-200">
                                    <?php $count = 0; foreach ($leadsHistorico as $row): $count++;
                                        $f9id = $row['F9CallID'];
                                        $resultado = $datosAdicionalesPorF9CallID[$f9id] ?? '-';
                                        $duracion = $row['duration'] ?? 0; // Duración sin restar 60 segundos
                                    ?>
                                        <tr class="interaccion-item hover:bg-purple-50 transition"
                                             data-search="<?= htmlspecialchars(strtolower($f9id . ' ' . $row['ANI'] . ' ' . $row['DNIS'] . ' ' . $row['PROYECTO'])) ?>"
                                             data-result="<?= htmlspecialchars($resultado) ?>">
                                            <td class="px-4 py-3 text-center font-semibold"><?= $count ?></td>
                                            <td class="px-4 py-3 text-center">
                                                <button onclick="openDetailModal('<?= htmlspecialchars($f9id) ?>')"
                                                       class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-lg transition duration-200 flex items-center gap-2 text-xs">
                                                    <i class="fas fa-eye"></i>Ver Detalle
                                                </button>
                                            </td>
                                            <td class="px-4 py-3 font-mono text-purple-700 font-semibold text-xs"><?= htmlspecialchars($f9id) ?></td>
                                            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($row['F9TimeStamp']) ?></td>
                                            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($row['ANI']) ?></td>
                                            <td class="px-4 py-3 text-xs text-gray-700"><?= htmlspecialchars($resultado) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700 text-xs"><?= secondsToMMSS($duracion) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="sinResultados" class="hidden text-center py-8 text-gray-500">
                            <i class="fas fa-search text-4xl mb-4"></i>
                            <p>No se encontraron interacciones que coincidan con la búsqueda</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Mensaje cuando no hay filtros aplicados -->
                <div class="bg-gray-100 rounded-lg p-8 text-center">
                    <i class="fas fa-filter text-gray-400 text-5xl mb-4"></i>
                    <p class="text-gray-600 text-lg">Selecciona una fecha o usa el buscador para ver los datos históricos</p>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
        // ============================================
        // AUTO-REFRESH REALTIME CON PERSISTENCIA
        // ============================================

        const REFRESH_STORAGE_KEY = 'dashboard_refresh_config';
        const REFRESH_TIMESTAMP_KEY = 'dashboard_refresh_timestamp';

        let autoRefreshTimer = null;
        let isAutoRefreshing = false;

        function toggleAutoRefresh() {
            const interval = parseInt(document.getElementById('refreshInterval').value);
            const btn = document.getElementById('toggleRefreshBtn');
            const btnText = document.getElementById('refreshBtnText');
            const status = document.getElementById('refreshStatus');

            if (isAutoRefreshing) {
                // Detener
                stopAutoRefresh();
            } else {
                // Iniciar
                if (interval === 0) {
                    alert('Selecciona un intervalo de refresh');
                    return;
                }
                startAutoRefresh(interval);
            }
        }

        function startAutoRefresh(intervalSeconds) {
            autoRefreshTimer = setInterval(function() {
                refreshRealtimeData();
            }, intervalSeconds * 1000);

            isAutoRefreshing = true;
            updateRefreshButton(true);
            updateRefreshStatus(intervalSeconds);

            // Guardar configuración en localStorage
            saveRefreshConfig(intervalSeconds);
        }

        function stopAutoRefresh() {
            if (autoRefreshTimer) {
                clearInterval(autoRefreshTimer);
                autoRefreshTimer = null;
            }
            isAutoRefreshing = false;
            updateRefreshButton(false);
            updateRefreshStatus(0);

            // Limpiar configuración del localStorage
            clearRefreshConfig();
        }

        function refreshRealtimeData() {
            fetch('dashboard_realtime_data_v2.php')
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        updateRealtimeUI(result.data);
                    }
                })
                .catch(error => {
                    console.error('Error al actualizar datos realtime:', error);
                });
        }

        function updateRealtimeUI(data) {
            // Actualizar Llamadas en Curso
            const enCursoElement = document.getElementById('rt-llamadas-en-curso');
            if (enCursoElement) {
                enCursoElement.textContent = data.llamadasEnCurso;
                // Actualizar indicador pulsante
                const pulseIndicator = document.getElementById('rt-pulse-indicator');
                if (pulseIndicator) {
                    if (data.llamadasEnCurso > 0) {
                        pulseIndicator.classList.remove('hidden');
                    } else {
                        pulseIndicator.classList.add('hidden');
                    }
                }
            }

            // Actualizar Total Llamadas
            const totalElement = document.getElementById('rt-total-llamadas');
            if (totalElement) {
                totalElement.textContent = data.totalLlamadas;
            }

            // Actualizar Duración Total
            const duracionTotalElement = document.getElementById('rt-duracion-total');
            if (duracionTotalElement) {
                duracionTotalElement.textContent = data.duracionTotal;
            }

            // Actualizar Duración Promedio
            const duracionPromedioElement = document.getElementById('rt-duracion-promedio');
            if (duracionPromedioElement) {
                duracionPromedioElement.textContent = data.duracionPromedio;
            }

            // Actualizar Tasa Retención AVI
            const tasaRetencionElement = document.getElementById('rt-tasa-retencion');
            if (tasaRetencionElement) {
                tasaRetencionElement.textContent = data.tasaRetencionAVI.toFixed(1) + '%';
            }

            // Actualizar gráfico de pie y tabla
            actualizarGraficoPie(data);

            // Mostrar indicador de actualización
            showRefreshIndicator();
        }

        function actualizarTablaResultados(data) {
            const tbody = document.getElementById('rt-resultados-tbody');
            if (!tbody) return;

            const frag = document.createDocumentFragment();

            function addRow(html) {
                const tmp = document.createElement('tbody');
                tmp.innerHTML = html;
                while (tmp.firstChild) frag.appendChild(tmp.firstChild);
            }

            addRow(`
                <tr class="bg-blue-50">
                    <td class="px-4 py-2 font-semibold text-blue-600">Llamadas Totales</td>
                    <td class="px-4 py-2 text-right font-semibold text-blue-600">${data.totalLlamadas}</td>
                    <td class="px-4 py-2 text-right text-gray-500">-</td>
                </tr>
            `);

            data.resultados.forEach(row => {
                const porcentaje = data.totalLlamadas > 0 ? (row.total / data.totalLlamadas) * 100 : 0;
                addRow(`
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 pl-8">→ ${row.resultado}</td>
                        <td class="px-4 py-2 text-right font-semibold">${row.total}</td>
                        <td class="px-4 py-2 text-right">${porcentaje.toFixed(1)}%</td>
                    </tr>
                `);
            });

            tbody.textContent = '';
            tbody.appendChild(frag);
        }

        function actualizarGraficoPie(data) {
            const chart = Chart.getChart('chartTipoLlamadasHoy');
            if (!chart) return;

            const labels = [];
            const valores = [];
            const colores = [];
            const paleta = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1'];

            // Agregar cada resultado
            data.resultados.forEach((d, i) => {
                labels.push(d.resultado);
                valores.push(d.total);
                colores.push(paleta[i % paleta.length]);
            });

            chart.data.labels = labels;
            chart.data.datasets[0].data = valores;
            chart.data.datasets[0].backgroundColor = colores;
            chart.update('none'); // 'none' para evitar animación

            // También actualizar la tabla
            actualizarTablaResultados(data);
        }

        function showRefreshIndicator() {
            const indicator = document.getElementById('refresh-indicator');
            if (indicator) {
                indicator.classList.remove('opacity-0');
                setTimeout(() => {
                    indicator.classList.add('opacity-0');
                }, 500);
            }
        }

        function updateRefreshButton(isRunning) {
            const btn = document.getElementById('toggleRefreshBtn');
            const btnText = document.getElementById('refreshBtnText');
            const icon = btn.querySelector('i');

            if (isRunning) {
                btn.classList.remove('bg-green-600', 'hover:bg-green-700');
                btn.classList.add('bg-red-600', 'hover:bg-red-700');
                icon.classList.remove('fa-play');
                icon.classList.add('fa-stop');
                btnText.textContent = 'Detener';
            } else {
                btn.classList.remove('bg-red-600', 'hover:bg-red-700');
                btn.classList.add('bg-green-600', 'hover:bg-green-700');
                icon.classList.remove('fa-stop');
                icon.classList.add('fa-play');
                btnText.textContent = 'Iniciar';
            }
        }

        function updateRefreshStatus(interval) {
            const status = document.getElementById('refreshStatus');
            if (interval > 0) {
                const nextRefresh = new Date(Date.now() + interval * 1000);
                status.textContent = 'Próximo refresh: ' + nextRefresh.toLocaleTimeString();
            } else {
                status.textContent = '';
            }
        }

        function reloadPage() {
            // Recargar la página manteniendo los filtros actuales
            window.location.reload();
        }

        function saveRefreshConfig(intervalSeconds) {
            const config = {
                interval: intervalSeconds,
                isActive: true,
                savedAt: Date.now()
            };
            localStorage.setItem(REFRESH_STORAGE_KEY, JSON.stringify(config));
            localStorage.setItem(REFRESH_TIMESTAMP_KEY, Date.now().toString());
        }

        function clearRefreshConfig() {
            localStorage.removeItem(REFRESH_STORAGE_KEY);
            localStorage.removeItem(REFRESH_TIMESTAMP_KEY);
        }

        function loadRefreshConfig() {
            const saved = localStorage.getItem(REFRESH_STORAGE_KEY);
            if (!saved) return null;

            try {
                const config = JSON.parse(saved);
                // Verificar que la configuración no sea muy antigua (máximo 5 minutos)
                const age = Date.now() - (config.savedAt || 0);
                if (age > 5 * 60 * 1000) {
                    clearRefreshConfig();
                    return null;
                }
                return config;
            } catch (e) {
                clearRefreshConfig();
                return null;
            }
        }

        // Actualizar estado cuando cambia el intervalo
        document.addEventListener('DOMContentLoaded', function() {
            const intervalSelect = document.getElementById('refreshInterval');

            // Restaurar configuración guardada
            const savedConfig = loadRefreshConfig();
            if (savedConfig && savedConfig.isActive && savedConfig.interval > 0) {
                // Restaurar el select
                if (intervalSelect) {
                    intervalSelect.value = savedConfig.interval.toString();
                }
                // Iniciar el refresh automáticamente
                startAutoRefresh(savedConfig.interval);
            }

            if (intervalSelect) {
                intervalSelect.addEventListener('change', function() {
                    if (isAutoRefreshing) {
                        stopAutoRefresh();
                        const newInterval = parseInt(this.value);
                        if (newInterval > 0) {
                            startAutoRefresh(newInterval);
                        }
                    }
                });
            }

            // Actualizar el contador de próximo refresh cada segundo
            setInterval(function() {
                if (isAutoRefreshing) {
                    const interval = parseInt(document.getElementById('refreshInterval').value);
                    updateRefreshStatus(interval);
                }
            }, 1000);
        });

        // ============================================
        // GRÁFICO PIE: TIPO DE LLAMADAS (HOY)
        // ============================================
        const totalLlamadasHoyJS = <?= $totalLlamadasHoy ?>;
        const colgadasHoy = <?= $colgadasHoy ?>;
        const datosHoy = <?= json_encode($resultadosHoy) ?>;

        // El gráfico muestra: Llamadas Totales (con resultados desglosados) + Colgadas
        const labelsHoy = [];
        const dataHoy = [];
        const coloresHoy = [];

        // Agregar cada resultado individualmente
        datosHoy.forEach(d => {
            labelsHoy.push(d.resultado);
            dataHoy.push(d.total);
        });

        // Agregar colgadas si existen
        if (colgadasHoy > 0) {
            labelsHoy.push('Llamadas Colgadas');
            dataHoy.push(colgadasHoy);
        }

        // Generar colores (rojo para colgadas)
        const numColores = labelsHoy.length;
        for (let i = 0; i < numColores; i++) {
            if (i === labelsHoy.length - 1 && colgadasHoy > 0) {
                coloresHoy.push('#ef4444'); // Rojo para colgadas
            } else {
                const paleta = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1'];
                coloresHoy.push(paleta[i % paleta.length]);
            }
        }

        new Chart(document.getElementById('chartTipoLlamadasHoy'), {
            type: 'pie',
            data: {
                labels: labelsHoy,
                datasets: [{
                    data: dataHoy,
                    backgroundColor: coloresHoy,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        <?php if ($aplicarFiltros): ?>
        // ============================================
        // GRÁFICO PIE: TIPO DE LLAMADAS (HISTÓRICO)
        // ============================================
        const totalLlamadasHistoricoJS = <?= $totalLlamadasHistorico ?>;
        const datosHistorico = <?= json_encode($resultadosHistorico) ?>;

        // El gráfico muestra solo las llamadas con call_result (excluyendo IVR_Regular)
        const labelsHistorico = [];
        const dataHistorico = [];
        const coloresHistorico = [];

        // Agregar cada resultado individualmente
        datosHistorico.forEach(d => {
            labelsHistorico.push(d.resultado);
            dataHistorico.push(d.total);
        });

        // Generar colores
        const paleta = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1'];
        for (let i = 0; i < labelsHistorico.length; i++) {
            coloresHistorico.push(paleta[i % paleta.length]);
        }

        new Chart(document.getElementById('chartTipoLlamadasHistorico'), {
            type: 'pie',
            data: {
                labels: labelsHistorico,
                datasets: [{
                    data: dataHistorico,
                    backgroundColor: coloresHistorico,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // ============================================
        // MODAL DE DETALLE
        // ============================================

        function openDetailModal(f9CallID) {
            const modal = document.getElementById('detailModal');
            const iframe = document.getElementById('detailIframe');
            iframe.src = 'detalle_lead_v2_v2.php?F9CallID=' + encodeURIComponent(f9CallID);
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeDetailModal() {
            const modal = document.getElementById('detailModal');
            const iframe = document.getElementById('detailIframe');
            modal.classList.add('hidden');
            iframe.src = '';
            document.body.style.overflow = 'auto';
        }

        // Cerrar modal con ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDetailModal();
            }
        });

        // ============================================
        // EXPORTAR A EXCEL
        // ============================================

        function exportResumenHistoricoToExcel() {
            const table = document.getElementById('resumenTableHistorico');
            if (!table) return;

            const rows = table.querySelectorAll('tr');
            const data = [];

            rows.forEach(row => {
                const rowData = [];
                const cells = row.querySelectorAll('th, td');
                cells.forEach((cell, index) => {
                    let value = cell.innerText.trim();

                    // Convertir porcentajes: si contiene % y es un número, convertir a decimal
                    if (value.includes('%')) {
                        const numValue = parseFloat(value.replace('%', ''));
                        if (!isNaN(numValue)) {
                            // Guardar como número decimal para formato de porcentaje en Excel
                            rowData.push(numValue / 100);
                        } else {
                            rowData.push(value);
                        }
                    } else if (value === '-') {
                        rowData.push('');
                    } else {
                        // Eliminar paréntesis y espacios extra de números
                        value = value.replace(/[()\s]/g, '');
                        const numValue = parseFloat(value);
                        rowData.push(!isNaN(numValue) ? numValue : value);
                    }
                });
                data.push(rowData);
            });

            // Crear hoja de trabajo
            const ws = XLSX.utils.aoa_to_sheet(data);

            // Definir columnas y aplicar formato de porcentaje donde corresponde
            const colCount = data[0].length;
            const range = XLSX.utils.decode_range(ws['!ref']);

            // Identificar columnas de porcentaje (columnas pares después de cierta posición)
            // Patrón: Fecha(0), Llamadas Totales(1), %Ret(2), Result1(3), %(4), Result2(5), %(6), ...
            for (let C = 0; C <= range.e.c; C++) {
                // Columnas de porcentaje: índice 2 (% Retención) y columnas pares después de 3
                if (C === 2 || (C > 3 && C % 2 === 0)) {
                    for (let R = 1; R <= range.e.r; R++) {
                        const cellAddress = XLSX.utils.encode_cell({ r: R, c: C });
                        if (ws[cellAddress] && typeof ws[cellAddress].v === 'number') {
                            ws[cellAddress].z = '0.0%';
                        }
                    }
                }
            }

            // Ajustar ancho de columnas
            ws['!cols'] = [];
            for (let C = 0; C < colCount; C++) {
                if (C === 0) {
                    ws['!cols'].push({ wch: 12 }); // Fecha
                } else if (C === 2 || (C > 3 && C % 2 === 0)) {
                    ws['!cols'].push({ wch: 10 }); // Columnas de porcentaje
                } else {
                    ws['!cols'].push({ wch: 18 }); // Columnas de números
                }
            }

            // Crear libro de trabajo
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Resumen Histórico");

            const date = new Date();
            const filename = `resumen_historico_${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}.xlsx`;
            XLSX.writeFile(wb, filename);
        }

        // ============================================
        // FILTRAR INTERACCIONES EN TIEMPO REAL
        // ============================================

        function filtrarInteracciones() {
            const busqueda = document.getElementById('buscarInteraccion').value.toLowerCase();
            const filtroResultado = document.getElementById('filtrarResultado').value;
            const container = document.getElementById('interaccionesContainer');
            const tabla = document.getElementById('tablaInteracciones');
            const sinResultados = document.getElementById('sinResultados');

            if (!container) return;

            const rows = container.getElementsByClassName('interaccion-item');
            let visibleCount = 0;
            const toHide = [];

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const searchData = row.getAttribute('data-search') || '';
                const resultado = row.getAttribute('data-result') || '-';

                const coincideBusqueda = searchData.includes(busqueda);
                const coincideResultado = !filtroResultado || resultado === filtroResultado;

                if (coincideBusqueda && coincideResultado) {
                    visibleCount++;
                } else {
                    toHide.push(row);
                }
            }

            for (let i = 0; i < toHide.length; i++) {
                toHide[i].style.display = 'none';
            }

            if (visibleCount === 0 && rows.length > 0) {
                if (sinResultados) sinResultados.classList.remove('hidden');
                if (tabla) tabla.classList.add('hidden');
            } else {
                if (sinResultados) sinResultados.classList.add('hidden');
                if (tabla) tabla.classList.remove('hidden');
            }
        }

        function limpiarBusqueda() {
            document.getElementById('buscarInteraccion').value = '';
            document.getElementById('filtrarResultado').value = '';
            filtrarInteracciones();
        }

        // Exportar interacciones a Excel
        function exportarInteraccionesExcel() {
            const container = document.getElementById('interaccionesContainer');
            if (!container) return;

            const rows = container.getElementsByClassName('interaccion-item');
            const data = [];

            // Encabezados
            data.push(['F9CallID', 'F9TimeStamp', 'ANI', 'Resultado', 'Duración']);

            // Filas de datos (solo las visibles)
            Array.from(rows).forEach(function(row) {
                if (row.style.display === 'none') return;

                const cells = row.getElementsByTagName('td');
                const rowData = [];
                for (let i = 2; i < cells.length; i++) { // Empezar en 2 para saltar # y Acciones
                    rowData.push(cells[i].innerText.trim());
                }
                data.push(rowData);
            });

            // Crear hoja de trabajo
            const ws = XLSX.utils.aoa_to_sheet(data);

            // Ajustar ancho de columnas
            ws['!cols'] = [
                { wch: 30 },  // F9CallID
                { wch: 20 },  // F9TimeStamp
                { wch: 15 },  // ANI
                { wch: 20 },  // Resultado
                { wch: 10 }   // Duración
            ];

            // Crear libro de trabajo
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Interacciones');

            // Generar nombre de archivo con fecha
            const date = new Date();
            const filename = `detalle_interacciones_${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}.xlsx`;

            // Descargar archivo
            XLSX.writeFile(wb, filename);
        }

        // Permitir búsqueda con Enter
        document.addEventListener('DOMContentLoaded', function() {
            const input = document.getElementById('buscarInteraccion');
            if (input) {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        filtrarInteracciones();
                    }
                });
            }
        });
    </script>

    <!-- Modal de Detalle -->
    <div id="detailModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="flex items-center justify-between p-4 border-b bg-gradient-to-r from-indigo-600 to-purple-600 text-white">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <i class="fas fa-info-circle"></i>
                    Detalle de Llamada
                </h3>
                <button onclick="closeDetailModal()" class="text-white hover:text-gray-200 transition">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="flex-1 overflow-auto">
                <iframe id="detailIframe" class="w-full h-full border-0" style="min-height: 500px;"></iframe>
            </div>
        </div>
    </div>
</body>
</html>
