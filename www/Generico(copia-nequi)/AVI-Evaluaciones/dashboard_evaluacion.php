<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
date_default_timezone_set('America/Puerto_Rico');

try {
$pdo = getDBConnection();
$clienteActual = !empty($_GET['cliente']) ? $_GET['cliente'] : 'NEQUI2';
$clientesDisponibles = ['NEQUI', 'NEQUI2', 'NEQUI-Eleven'];
$esEleven = ($clienteActual === 'NEQUI-Eleven');

function secondsToMMSS($seconds) {
    if ($seconds <= 0) return '00:00';
    $totalSeconds = (int)round($seconds);
    $minutes = intdiv($totalSeconds, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d', $minutes, $secs);
}

// Fechas por defecto: hoy
$fechaDesde = !empty($_GET['fecha_desde']) ? $_GET['fecha_desde'] : date('Y-m-d');
$fechaHasta = !empty($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : date('Y-m-d');

// ============================================
// CACHE EN SESIÓN (30s) para queries agregadas
// ============================================
$cacheKey = "dash_{$clienteActual}_{$fechaDesde}_{$fechaHasta}";
$cacheTtl = 30;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['dash_cache'][$cacheKey]['time']) && time() - $_SESSION['dash_cache'][$cacheKey]['time'] < $cacheTtl) {
    $statsGenerales   = $_SESSION['dash_cache'][$cacheKey]['statsGenerales'];
    $totalPendientes  = $_SESSION['dash_cache'][$cacheKey]['totalPendientes'];
    $tendencia        = $_SESSION['dash_cache'][$cacheKey]['tendencia'];
    $correctos        = $_SESSION['dash_cache'][$cacheKey]['correctos'];
    $matriz           = $_SESSION['dash_cache'][$cacheKey]['matriz'];
    $comparacion      = $_SESSION['dash_cache'][$cacheKey]['comparacion'];
    $diasSemana       = $_SESSION['dash_cache'][$cacheKey]['diasSemana'];
}

// ============================================
// ESTADÍSTICAS GENERALES
// ============================================
if (!isset($statsGenerales)) {

$stmtTotal = $pdo->prepare("
    SELECT
        COUNT(*) as total_evaluadas,
        SUM(CASE WHEN resultado = 'pasa' THEN 1 ELSE 0 END) as total_pasa,
        SUM(CASE WHEN resultado = 'no_pasa' THEN 1 ELSE 0 END) as total_no_pasa,
        SUM(CASE WHEN se_puede_mejorar = 1 THEN 1 ELSE 0 END) as total_mejorable
    FROM level_transcripciones_evaluacion
    WHERE cliente = :cliente
    AND fecha_evaluacion >= :desde AND fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)
");
$stmtTotal->execute([':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$statsGenerales = $stmtTotal->fetch();

$totalEvaluadas = $statsGenerales['total_evaluadas'] ?? 0;
$totalPasa = $statsGenerales['total_pasa'] ?? 0;
$totalMejorable = $statsGenerales['total_mejorable'] ?? 0;
$totalNoPasa = $statsGenerales['total_no_pasa'] ?? 0;
$pctPasa = $totalEvaluadas > 0 ? round(($totalPasa / $totalEvaluadas) * 100, 1) : 0;
$pctNoPasa = $totalEvaluadas > 0 ? round(($totalNoPasa / $totalEvaluadas) * 100, 1) : 0;

// Total de llamadas con transcripción disponibles (pendientes)
if ($esEleven) {
    $stmtPendientes = $pdo->prepare("
        SELECT COUNT(DISTINCT ac.F9CallID) as total
        FROM avi_calls ac
        INNER JOIN avi_call_costs acc ON ac.F9CallID = acc.f9_call_id
        INNER JOIN eleven_n8n_t1 ent ON acc.conversation_id = ent.ElevenConversationID
        WHERE ac.cliente = 'NEQUI'
        AND ent.has_transcript = 1
        AND ac.F9TimeStamp >= :desde AND ac.F9TimeStamp < DATE_ADD(:hasta, INTERVAL 1 DAY)
        AND NOT EXISTS (
            SELECT 1 FROM level_transcripciones_evaluacion e
            WHERE e.F9CallID = ac.F9CallID COLLATE utf8mb4_unicode_ci AND e.cliente = :cliente2
        )
    ");
    $stmtPendientes->execute([':cliente2' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
} else {
    $stmtPendientes = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM level_calls lc
        INNER JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
        WHERE lc.cliente = :cliente
        AND lvc.transcript_text IS NOT NULL AND lvc.transcript_text != ''
        AND lc.F9TimeStamp >= :desde AND lc.F9TimeStamp < DATE_ADD(:hasta, INTERVAL 1 DAY)
        AND NOT EXISTS (
            SELECT 1 FROM level_transcripciones_evaluacion e
            WHERE e.F9CallID = lc.F9CallID COLLATE utf8mb4_unicode_ci AND e.cliente = :cliente2
        )
    ");
    $stmtPendientes->execute([':cliente' => $clienteActual, ':cliente2' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
}
$totalPendientes = $stmtPendientes->fetch()['total'];

// ============================================
// TENDENCIA DIARIA DE EVALUACIONES
// ============================================
$stmtTendencia = $pdo->prepare("
    SELECT
        DATE(fecha_evaluacion) as fecha,
        COUNT(*) as total,
        SUM(CASE WHEN resultado = 'pasa' THEN 1 ELSE 0 END) as pasa,
        SUM(CASE WHEN resultado = 'no_pasa' THEN 1 ELSE 0 END) as no_pasa
    FROM level_transcripciones_evaluacion
    WHERE cliente = :cliente
    AND fecha_evaluacion >= :desde AND fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)
    GROUP BY DATE(fecha_evaluacion)
    ORDER BY fecha ASC
");
$stmtTendencia->execute([
    ':cliente' => $clienteActual,
    ':desde' => $fechaDesde,
    ':hasta' => $fechaHasta,
]);
$tendencia = $stmtTendencia->fetchAll();

// ============================================
// RESULTADOS CORRECTOS MÁS COMUNES (cuando no_pasa)
// ============================================
$stmtCorrectos = $pdo->prepare("
    SELECT call_result_correcto, COUNT(*) as total
    FROM level_transcripciones_evaluacion
    WHERE cliente = :cliente
    AND resultado = 'no_pasa'
    AND call_result_correcto IS NOT NULL
    AND fecha_evaluacion >= :desde AND fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)
    GROUP BY call_result_correcto
    ORDER BY total DESC
");
$stmtCorrectos->execute([':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$correctos = $stmtCorrectos->fetchAll();

// ============================================
// MATRIZ: Original call_result vs resultado evaluación
// ============================================
$matriz = [];
if ($esEleven) {
    $stmtMatriz = $pdo->prepare("
        SELECT slv.valor as original, e.resultado, COUNT(*) as total
        FROM level_transcripciones_evaluacion e
        LEFT JOIN avi_call_costs acc ON e.F9CallID = acc.f9_call_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN siigo_lead_data_v2 slv ON e.F9CallID = slv.F9CallID COLLATE utf8mb4_unicode_ci
            AND slv.method = 'ResultadoPerfil'
            AND slv.clave = 'resultado_llamada'
        WHERE e.cliente = :cliente
        AND e.fecha_evaluacion >= :desde AND e.fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)
        GROUP BY slv.valor, e.resultado
        ORDER BY total DESC
    ");
    $stmtMatriz->execute([':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
    $matriz = $stmtMatriz->fetchAll();
} else {
    $stmtMatriz = $pdo->prepare("
        SELECT lvc.call_result as original, e.resultado, COUNT(*) as total
        FROM level_transcripciones_evaluacion e
        JOIN level_calls lc ON e.F9CallID = lc.F9CallID COLLATE utf8mb4_unicode_ci
        LEFT JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
        WHERE e.cliente = :cliente
        AND e.fecha_evaluacion >= :desde AND e.fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)
        GROUP BY lvc.call_result, e.resultado
        ORDER BY total DESC
    ");
    $stmtMatriz->execute([':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
    $matriz = $stmtMatriz->fetchAll();
}

// ============================================
// COMPARACIÓN: Original call_result vs Correcto (cuando No Pasa)
// ============================================
$comparacion = [];
if ($esEleven) {
    $stmtComparacion = $pdo->prepare("
        SELECT slv.valor as original_result, e.call_result_correcto, COUNT(*) as total
        FROM level_transcripciones_evaluacion e
        LEFT JOIN avi_call_costs acc ON e.F9CallID = acc.f9_call_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN siigo_lead_data_v2 slv ON e.F9CallID = slv.F9CallID COLLATE utf8mb4_unicode_ci
            AND slv.method = 'ResultadoPerfil'
            AND slv.clave = 'resultado_llamada'
        WHERE e.cliente = :cliente AND e.resultado = 'no_pasa'
        AND e.call_result_correcto IS NOT NULL
        AND e.fecha_evaluacion >= :desde AND e.fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)
        GROUP BY slv.valor, e.call_result_correcto
        ORDER BY total DESC
    ");
    $stmtComparacion->execute([':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
    $comparacion = $stmtComparacion->fetchAll();
} else {
    $stmtComparacion = $pdo->prepare("
        SELECT lvc.call_result as original_result, e.call_result_correcto, COUNT(*) as total
        FROM level_transcripciones_evaluacion e
        JOIN level_calls lc ON e.F9CallID = lc.F9CallID COLLATE utf8mb4_unicode_ci
        LEFT JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
        WHERE e.cliente = :cliente AND e.resultado = 'no_pasa'
        AND e.call_result_correcto IS NOT NULL
        AND e.fecha_evaluacion >= :desde AND e.fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)
        GROUP BY lvc.call_result, e.call_result_correcto
        ORDER BY total DESC
    ");
    $stmtComparacion->execute([':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
    $comparacion = $stmtComparacion->fetchAll();
}

// ============================================
// ÚLTIMAS EVALUACIONES (con paginación)
// ============================================
$porPagina = 50;
$paginaActual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($paginaActual - 1) * $porPagina;
$searchTerm = $_GET['buscar_ev'] ?? '';
$filtroResultadoEv = $_GET['resultado_ev'] ?? '';

$whereUltimas = "e.cliente = :cliente AND e.fecha_evaluacion >= :desde AND e.fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)";
$paramsUltimas = [':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta];

if (!empty($searchTerm)) {
    $whereUltimas .= " AND (e.F9CallID LIKE :buscar OR e.ANI LIKE :buscar2)";
    $paramsUltimas[':buscar'] = "%$searchTerm%";
    $paramsUltimas[':buscar2'] = "%$searchTerm%";
}
if (!empty($filtroResultadoEv)) {
    $whereUltimas .= " AND e.resultado = :resultado_ev";
    $paramsUltimas[':resultado_ev'] = $filtroResultadoEv;
}

// Total de registros para paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM level_transcripciones_evaluacion e WHERE $whereUltimas");
$stmtCount->execute($paramsUltimas);
$totalRegistros = (int)$stmtCount->fetchColumn();
$totalPaginas = max(1, ceil($totalRegistros / $porPagina));

// Query paginada (solo joins necesarios segun el cliente)
if ($esEleven) {
    $stmtUltimas = $pdo->prepare("
        SELECT e.F9CallID, e.ANI, e.resultado, e.call_result_correcto, e.se_puede_mejorar, e.info_disponible_sa, e.observacion, e.fecha_evaluacion,
               acc.connection_duration_secs as duration,
               ac.F9TimeStamp,
               slv.valor as original_result
        FROM level_transcripciones_evaluacion e
        LEFT JOIN avi_call_costs acc ON e.F9CallID = acc.f9_call_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN avi_calls ac ON e.F9CallID = ac.F9CallID COLLATE utf8mb4_unicode_ci
        LEFT JOIN siigo_lead_data_v2 slv ON e.F9CallID = slv.F9CallID COLLATE utf8mb4_unicode_ci
            AND slv.method = 'ResultadoPerfil'
            AND slv.clave = 'resultado_llamada'
        WHERE $whereUltimas
        ORDER BY e.fecha_evaluacion DESC
        LIMIT :limit OFFSET :offset
    ");
} else {
    $stmtUltimas = $pdo->prepare("
        SELECT e.F9CallID, e.ANI, e.resultado, e.call_result_correcto, e.se_puede_mejorar, e.info_disponible_sa, e.observacion, e.fecha_evaluacion,
               lc.duration,
               lc.F9TimeStamp,
               lvc.call_result as original_result
        FROM level_transcripciones_evaluacion e
        LEFT JOIN level_calls lc ON e.F9CallID = lc.F9CallID COLLATE utf8mb4_unicode_ci
        LEFT JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
        WHERE $whereUltimas
        ORDER BY e.fecha_evaluacion DESC
        LIMIT :limit OFFSET :offset
    ");
}
$stmtUltimas->bindValue(':limit', $porPagina, PDO::PARAM_INT);
$stmtUltimas->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($paramsUltimas as $k => $v) {
    $stmtUltimas->bindValue($k, $v);
}
$stmtUltimas->execute();
$ultimasEvaluaciones = $stmtUltimas->fetchAll();

// ============================================
// EVALUACIONES POR DÍA DE LA SEMANA
// ============================================
$stmtDiaSemana = $pdo->prepare("
    SELECT DAYNAME(fecha_evaluacion) as dia, COUNT(*) as total
    FROM level_transcripciones_evaluacion
    WHERE cliente = :cliente
    AND fecha_evaluacion >= :desde AND fecha_evaluacion < DATE_ADD(:hasta, INTERVAL 1 DAY)
    GROUP BY DAYNAME(fecha_evaluacion)
    ORDER BY MIN(fecha_evaluacion) ASC
");
$stmtDiaSemana->execute([':cliente' => $clienteActual, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$diasSemana = $stmtDiaSemana->fetchAll();
$mapDias = ['Monday'=>'Lun','Tuesday'=>'Mar','Wednesday'=>'Mié','Thursday'=>'Jue','Friday'=>'Vie','Saturday'=>'Sáb','Sunday'=>'Dom'];

$_SESSION['dash_cache'][$cacheKey] = [
    'time'           => time(),
    'statsGenerales' => $statsGenerales,
    'totalPendientes'=> $totalPendientes,
    'tendencia'      => $tendencia,
    'correctos'      => $correctos,
    'matriz'         => $matriz,
    'comparacion'    => $comparacion,
    'diasSemana'     => $diasSemana,
];
}
} catch (PDOException $e) {
    die('Error en la consulta: ' . $e->getMessage() . '<br><pre>' . print_r($e->errorInfo, true) . '</pre>');
} catch (Exception $e) {
    die('Error general: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Evaluación - <?= $clienteActual ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-gradient-to-r from-teal-600 to-teal-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <i class="fas fa-chart-bar text-3xl"></i>
                    <div>
                        <h1 class="text-3xl font-bold">Dashboard de Evaluación</h1>
                        <p class="text-teal-100">Análisis de transcripciones - <strong><?= $clienteActual ?></strong></p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <select onchange="cambiarCliente(this.value)" class="bg-teal-700 text-white border border-teal-500 rounded-lg px-3 py-2 text-sm font-semibold cursor-pointer focus:outline-none focus:ring-2 focus:ring-teal-300">
                        <?php foreach ($clientesDisponibles as $c): ?>
                            <option value="<?= $c ?>" <?= $c === $clienteActual ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="evaluacion_transcripciones.php<?= $clienteActual !== CLIENTE_ACTUAL ? '?cliente='.$clienteActual : '' ?>" class="bg-teal-500 hover:bg-teal-400 text-white px-4 py-2 rounded-lg transition flex items-center gap-2 text-sm">
                        <i class="fas fa-clipboard-check"></i> Evaluar
                    </a>
                    <a href="index.php" class="bg-teal-500 hover:bg-teal-400 text-white px-4 py-2 rounded-lg transition flex items-center gap-2 text-sm">
                        <i class="fas fa-home"></i> Inicio
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <form method="GET" class="flex items-end gap-4 flex-wrap">
                <input type="hidden" name="cliente" value="<?= $clienteActual ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Desde</label>
                    <input type="date" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>"
                           class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Hasta</label>
                    <input type="date" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>"
                           class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                </div>
                <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white font-semibold px-6 py-2 rounded-lg transition">
                    <i class="fas fa-filter mr-2"></i>Filtrar
                </button>
                <a href="dashboard_evaluacion.php<?= $clienteActual !== CLIENTE_ACTUAL ? '?cliente='.$clienteActual : '' ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold px-6 py-2 rounded-lg transition">
                    <i class="fas fa-redo mr-2"></i>Limpiar
                </a>
            </form>
        </div>

        <!-- KPIs -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <div class="bg-gradient-to-br from-teal-500 to-teal-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Total Evaluadas</h3>
                    <i class="fas fa-clipboard-check text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= number_format($totalEvaluadas) ?></p>
            </div>
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Pasa</h3>
                    <i class="fas fa-check-circle text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= number_format($totalPasa) ?></p>
                <p class="text-xs opacity-75 mt-1"><?= $pctPasa ?>% del total</p>
            </div>
            <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">No Pasa</h3>
                    <i class="fas fa-times-circle text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= number_format($totalNoPasa) ?></p>
                <p class="text-xs opacity-75 mt-1"><?= $pctNoPasa ?>% del total</p>
            </div>
            <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Pendientes</h3>
                    <i class="fas fa-hourglass-half text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= number_format($totalPendientes) ?></p>
            </div>
            <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Mejorable</h3>
                    <i class="fas fa-rocket text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= number_format($totalMejorable) ?></p>
            </div>
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-md p-6 text-white">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold opacity-90">Tasa de Aprobación</h3>
                    <i class="fas fa-percentage text-2xl opacity-50"></i>
                </div>
                <p class="text-4xl font-bold"><?= $pctPasa ?>%</p>
            </div>
        </div>

        <!-- Gráficos: Fila 1 -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Tendencia diaria -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-line text-teal-600"></i> Tendencia Diaria de Evaluaciones
                </h3>
                <div style="height: 280px;">
                    <canvas id="chartTendencia"></canvas>
                </div>
            </div>
            <!-- Distribución Pasa/No Pasa -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-teal-600"></i> Distribución General
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div style="height: 250px;">
                        <canvas id="chartDistribucion"></canvas>
                    </div>
                    <div class="flex flex-col justify-center">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="w-4 h-4 rounded-full bg-green-500"></span>
                            <span class="text-gray-700">Pasa: <strong><?= number_format($totalPasa) ?></strong></span>
                            <span class="text-gray-500 text-sm">(<?= $pctPasa ?>%)</span>
                        </div>
                        <div class="flex items-center gap-3 mb-3">
                            <span class="w-4 h-4 rounded-full bg-red-500"></span>
                            <span class="text-gray-700">No Pasa: <strong><?= number_format($totalNoPasa) ?></strong></span>
                            <span class="text-gray-500 text-sm">(<?= $pctNoPasa ?>%)</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-4 h-4 rounded-full bg-orange-500"></span>
                            <span class="text-gray-700">Pendientes: <strong><?= number_format($totalPendientes) ?></strong></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos: Fila 2 -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Resultados correctos más comunes (cuando no_pasa) -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-tag text-teal-600"></i> Resultado Correcto (cuando No Pasa)
                </h3>
                <?php if (empty($correctos)): ?>
                    <p class="text-gray-400 text-center py-8">Sin datos de correcciones</p>
                <?php else: ?>
                    <div style="height: 250px;">
                        <canvas id="chartCorrectos"></canvas>
                    </div>
                <?php endif; ?>
                <?php if (!empty($comparacion)): ?>
                <hr class="my-4 border-gray-200">
                <h4 class="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                    <i class="fas fa-exchange-alt text-teal-500"></i> Sistema Original → Correcto
                </h4>
                <div class="overflow-x-auto max-h-48 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-100 sticky top-0">
                            <tr>
                                <th class="px-3 py-2 text-left">Sistema (Original)</th>
                                <th class="px-3 py-2 text-left">Debe Ser (Correcto)</th>
                                <th class="px-3 py-2 text-center">Cantidad</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($comparacion as $c): ?>
                            <tr class="hover:bg-teal-50 transition">
                                <td class="px-3 py-2 font-mono text-teal-700 font-semibold"><?= htmlspecialchars($c['original_result'] ?? 'N/A') ?></td>
                                <td class="px-3 py-2">
                                    <span class="bg-green-100 text-green-800 px-2 py-0.5 rounded-full font-semibold"><?= htmlspecialchars($c['call_result_correcto']) ?></span>
                                </td>
                                <td class="px-3 py-2 text-center font-bold"><?= $c['total'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <!-- Evaluaciones por día de la semana -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-calendar-week text-teal-600"></i> Evaluaciones por Día de la Semana
                </h3>
                <div style="height: 250px;">
                    <canvas id="chartDiasSemana"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabla: Últimas Evaluaciones -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-teal-600 to-teal-700 text-white px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold flex items-center gap-2">
                        <i class="fas fa-list"></i> Últimas Evaluaciones
                    </h3>
                    <div class="flex items-center gap-2">
                        <span class="bg-white text-teal-700 px-3 py-1 rounded-full text-sm font-bold"><?= $totalRegistros ?></span>
                        <a href="export_evaluaciones.php?<?= http_build_query(array_merge($_GET, ['pagina' => null])) ?>" class="bg-white text-teal-700 hover:bg-teal-100 px-3 py-1 rounded-full text-sm font-bold transition flex items-center gap-1">
                            <i class="fas fa-download"></i> Exportar
                        </a>
                    </div>
                </div>
            </div>
            <!-- Filtros de la tabla -->
            <form method="GET" class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-end gap-3 flex-wrap">
                <input type="hidden" name="cliente" value="<?= $clienteActual ?>">
                <input type="hidden" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>">
                <input type="hidden" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Buscar</label>
                    <input type="text" name="buscar_ev" placeholder="F9CallID o ANI..."
                           value="<?= htmlspecialchars($searchTerm) ?>"
                           class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Resultado</label>
                    <select name="resultado_ev" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-teal-500">
                        <option value="">Todos</option>
                        <option value="pasa" <?= $filtroResultadoEv === 'pasa' ? 'selected' : '' ?>>Pasa</option>
                        <option value="no_pasa" <?= $filtroResultadoEv === 'no_pasa' ? 'selected' : '' ?>>No Pasa</option>
                    </select>
                </div>
                <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold px-4 py-1.5 rounded-lg transition">
                    <i class="fas fa-filter mr-1"></i> Filtrar
                </button>
            </form>
            <?php if (empty($ultimasEvaluaciones)): ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-inbox text-4xl mb-4"></i>
                    <p>No hay evaluaciones registradas</p>
                </div>
            <?php else: ?>
                <div class="px-4 py-2 bg-gray-50 border-b border-gray-200 text-xs text-gray-500">
                    Mostrando <?= $offset + 1 ?>-<?= min($offset + $porPagina, $totalRegistros) ?> de <?= $totalRegistros ?> evaluaciones
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-3 text-left">F9CallID</th>
                                <th class="px-4 py-3 text-left">ANI</th>
                                <th class="px-4 py-3 text-left">Resultado</th>
                                <th class="px-4 py-3 text-left">Mejorable</th>
                                <th class="px-4 py-3 text-left">Info SA</th>
                                <th class="px-4 py-3 text-left">Sistema (Original)</th>
                                <th class="px-4 py-3 text-left">Debe Ser (Correcto)</th>
                                <th class="px-4 py-3 text-left">Observación</th>
                                <th class="px-4 py-3 text-left">Fecha Evaluación</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($ultimasEvaluaciones as $ev): ?>
                            <tr class="hover:bg-teal-50 transition">
                                <td class="px-4 py-3 font-mono text-xs text-teal-700 font-semibold"><?= htmlspecialchars($ev['F9CallID']) ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars($ev['ANI'] ?? '-') ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($ev['resultado'] === 'pasa'): ?>
                                        <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-xs font-semibold">Pasa</span>
                                    <?php else: ?>
                                        <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-xs font-semibold">No Pasa</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-xs"><?php if (!empty($ev['se_puede_mejorar'])): ?><span class="bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded-full"><i class="fas fa-rocket mr-1"></i> Sí</span><?php else: ?>-<?php endif; ?></td>
                                <td class="px-4 py-3 text-xs"><?php if (!empty($ev['info_disponible_sa'])): ?><span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full"><i class="fas fa-database mr-1"></i> Sí</span><?php else: ?>-<?php endif; ?></td>
                                <td class="px-4 py-3 text-xs"><?= htmlspecialchars($ev['original_result'] ?? '-') ?></td>
                                <td class="px-4 py-3 text-xs"><?php if ($ev['resultado'] === 'no_pasa' && !empty($ev['call_result_correcto'])): ?><span class="bg-green-100 text-green-800 px-2 py-0.5 rounded-full"><?= htmlspecialchars($ev['call_result_correcto']) ?></span><?php else: ?>-<?php endif; ?></td>
                                <td class="px-4 py-3 text-xs text-gray-500 max-w-xs truncate"><?= htmlspecialchars($ev['observacion'] ?? '-') ?></td>
                                <td class="px-4 py-3 text-xs text-gray-600"><?= htmlspecialchars($ev['fecha_evaluacion']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Paginación -->
                <?php if ($totalPaginas > 1): ?>
                <div class="px-4 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                    <p class="text-xs text-gray-500">Página <?= $paginaActual ?> de <?= $totalPaginas ?></p>
                    <div class="flex gap-1">
                        <?php if ($paginaActual > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual - 1])) ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm hover:bg-gray-100 transition">&laquo; Anterior</a>
                        <?php endif; ?>
                        <?php
                        $inicio = max(1, $paginaActual - 2);
                        $fin = min($totalPaginas, $paginaActual + 2);
                        for ($i = $inicio; $i <= $fin; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>" class="px-3 py-1 border rounded text-sm transition <?= $i === $paginaActual ? 'bg-teal-600 text-white border-teal-600' : 'bg-white border-gray-300 hover:bg-gray-100' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <?php if ($paginaActual < $totalPaginas): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual + 1])) ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm hover:bg-gray-100 transition">Siguiente &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // ============================================
        // GRÁFICO: TENDENCIA DIARIA
        // ============================================
        const tendenciaData = <?= json_encode($tendencia) ?>;
        const labelsTendencia = tendenciaData.map(d => d.fecha);
        const pasaTendencia = tendenciaData.map(d => parseInt(d.pasa));
        const noPasaTendencia = tendenciaData.map(d => parseInt(d.no_pasa));

        new Chart(document.getElementById('chartTendencia'), {
            type: 'line',
            data: {
                labels: labelsTendencia,
                datasets: [{
                    label: 'Pasa',
                    data: pasaTendencia,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 3,
                }, {
                    label: 'No Pasa',
                    data: noPasaTendencia,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,0.1)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12 } }
                },
                scales: {
                    x: { ticks: { maxTicksLimit: 10 } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });

        // ============================================
        // GRÁFICO: DISTRIBUCIÓN PASA/NO PASA
        // ============================================
        new Chart(document.getElementById('chartDistribucion'), {
            type: 'doughnut',
            data: {
                labels: ['Pasa', 'No Pasa'],
                datasets: [{
                    data: [<?= $totalPasa ?>, <?= $totalNoPasa ?>],
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = ((ctx.parsed / total) * 100).toFixed(1);
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                cutout: '65%',
            }
        });

        <?php if (!empty($correctos)): ?>
        // ============================================
        // GRÁFICO: RESULTADOS CORRECTOS
        // ============================================
        const correctosData = <?= json_encode($correctos) ?>;
        const labelsCorrectos = correctosData.map(d => d.call_result_correcto);
        const dataCorrectos = correctosData.map(d => parseInt(d.total));
        const coloresCorrectos = ['#8b5cf6','#3b82f6','#f59e0b','#ec4899','#14b8a6','#f97316','#6366f1','#84cc16'];

        new Chart(document.getElementById('chartCorrectos'), {
            type: 'bar',
            data: {
                labels: labelsCorrectos,
                datasets: [{
                    label: 'Cantidad',
                    data: dataCorrectos,
                    backgroundColor: coloresCorrectos.slice(0, labelsCorrectos.length),
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
        <?php endif; ?>

        // ============================================
        // GRÁFICO: DÍAS DE LA SEMANA
        // ============================================
        const diasData = <?= json_encode($diasSemana) ?>;
        const diasMap = <?= json_encode($mapDias) ?>;
        const labelsDias = diasData.map(d => diasMap[d.dia] || d.dia);
        const dataDias = diasData.map(d => parseInt(d.total));

        new Chart(document.getElementById('chartDiasSemana'), {
            type: 'bar',
            data: {
                labels: labelsDias,
                datasets: [{
                    label: 'Evaluaciones',
                    data: dataDias,
                    backgroundColor: '#14b8a6',
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });

        function cambiarCliente(cliente) {
            const url = new URL(window.location.href);
            url.searchParams.set('cliente', cliente);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>
