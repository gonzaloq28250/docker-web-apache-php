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

$clienteActual = CLIENTE_ACTUAL;

// ============================================
// PARTE 1: REALTIME - DATOS DEL DÍA ACTUAL
// ============================================

$fechaHoy = date('Y-m-d');

// Llamadas Five9 (hoy) - desde avi_calls
$stmtFive9Hoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_calls
    WHERE cliente = :cliente
    AND DATE(F9TimeStamp) = :fecha_hoy
");
$stmtFive9Hoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$totalLlamadasFive9Hoy = $stmtFive9Hoy->fetch()['total'];

// Llamadas AVI (hoy) - desde avi_call_costs
$stmtAVIHoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_call_costs
    WHERE cliente = :cliente
    AND DATE(metadata_date_local) = :fecha_hoy
");
$stmtAVIHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$totalLlamadasAVIHoy = $stmtAVIHoy->fetch()['total'];

// KPI 2: Duración Total (hoy)
$stmtDuracionTotalHoy = $pdo->prepare("
    SELECT SUM(connection_duration_secs) as total
    FROM avi_call_costs
    WHERE cliente = :cliente
    AND DATE(metadata_date_local) = :fecha_hoy
");
$stmtDuracionTotalHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$duracionTotalSecsHoy = $stmtDuracionTotalHoy->fetch()['total'] ?? 0;

// KPI 3: Duración Promedio (hoy)
$duracionPromedioSecsHoy = $totalLlamadasAVIHoy > 0 ? $duracionTotalSecsHoy / $totalLlamadasAVIHoy : 0;

// Llamadas en Curso (hoy)
$stmtEnCursoHoy = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_calls
    WHERE cliente = :cliente
    AND Estado = 'IN-PROGRESS'
    AND DATE(F9TimeStamp) = :fecha_hoy
");
$stmtEnCursoHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$llamadasEnCursoHoy = $stmtEnCursoHoy->fetch()['total'];

// Duración máxima de llamadas en curso eliminada por solicitud

// Gráfico Pie: Tipo de llamadas (hoy) - desde siigo_lead_data_v2
$stmtResultadosHoy = $pdo->prepare("
    SELECT
        res.valor as resultado,
        COUNT(DISTINCT r.F9CallID) as total
    FROM siigo_lead_data_v2 r
    INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID
        AND res.clave = 'resultado_llamada'
        AND res.method = 'ResultadoPerfil'
    INNER JOIN siigo_lead_data_v2 ts ON r.F9CallID = ts.F9CallID
        AND ts.clave = 'F9TimeStamp'
    INNER JOIN siigo_lead_data_v2 c ON r.F9CallID = c.F9CallID
        AND c.clave = 'CLIENTE'
        AND c.valor = :cliente
    WHERE DATE(ts.valor) = :fecha_hoy
    GROUP BY res.valor
    ORDER BY total DESC
");
$stmtResultadosHoy->execute([':cliente' => $clienteActual, ':fecha_hoy' => $fechaHoy]);
$resultadosHoy = $stmtResultadosHoy->fetchAll();

// Calcular totales para el gráfico de hoy
$totalResultadosHoy = array_sum(array_column($resultadosHoy, 'total'));
// Llamadas colgadas = Five9 - AVI (llamadas que entraron pero no completaron el proceso AVI)
$colgadasHoy = max(0, $totalLlamadasFive9Hoy - $totalLlamadasAVIHoy);

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

// Construir WHERE clause para filtros históricos
$whereHistorico = "WHERE cliente = :cliente";
$paramsHistorico = [':cliente' => $clienteActual];

// Filtro de rango de fechas con hora
if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
    $whereHistorico .= " AND metadata_date_local BETWEEN :fecha_desde AND :fecha_hasta";
    $paramsHistorico[':fecha_desde'] = $fechaDesdeSQL;
    $paramsHistorico[':fecha_hasta'] = $fechaHastaSQL;
} elseif (!empty($fechaDesdeSQL)) {
    $whereHistorico .= " AND metadata_date_local >= :fecha_desde";
    $paramsHistorico[':fecha_desde'] = $fechaDesdeSQL;
} elseif (!empty($fechaHastaSQL)) {
    $whereHistorico .= " AND metadata_date_local <= :fecha_hasta";
    $paramsHistorico[':fecha_hasta'] = $fechaHastaSQL;
}

// Variables para datos históricos
$totalLlamadasFive9Historico = 0;
$totalLlamadasAVIHistorico = 0;
$duracionTotalSecsHistorico = 0;
$duracionPromedioSecsHistorico = 0;
$resultadosHistorico = [];
$totalResultadosHistorico = 0;
$colgadasHistorico = 0;

$aplicarFiltros = !empty($fechaDesdeSQL) || !empty($fechaHastaSQL) || !empty($buscar);

if ($aplicarFiltros) {
    // Construir WHERE para Five9 (avi_calls)
    $whereFive9Historico = "WHERE cliente = :cliente";
    $paramsFive9Historico = [':cliente' => $clienteActual];

    if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
        $whereFive9Historico .= " AND F9TimeStamp BETWEEN :fecha_desde AND :fecha_hasta";
        $paramsFive9Historico[':fecha_desde'] = $fechaDesdeSQL;
        $paramsFive9Historico[':fecha_hasta'] = $fechaHastaSQL;
    } elseif (!empty($fechaDesdeSQL)) {
        $whereFive9Historico .= " AND F9TimeStamp >= :fecha_desde";
        $paramsFive9Historico[':fecha_desde'] = $fechaDesdeSQL;
    } elseif (!empty($fechaHastaSQL)) {
        $whereFive9Historico .= " AND F9TimeStamp <= :fecha_hasta";
        $paramsFive9Historico[':fecha_hasta'] = $fechaHastaSQL;
    }

    // Total Llamadas Five9 (filtrado)
    $sqlFive9Historico = "SELECT COUNT(*) as total FROM avi_calls $whereFive9Historico";
    $stmtFive9Historico = $pdo->prepare($sqlFive9Historico);
    $stmtFive9Historico->execute($paramsFive9Historico);
    $totalLlamadasFive9Historico = $stmtFive9Historico->fetch()['total'];

    // KPI 1: Total Llamadas AVI (filtrado)
    $sqlTotalHistorico = "SELECT COUNT(*) as total FROM avi_call_costs $whereHistorico";
    $stmtTotalHistorico = $pdo->prepare($sqlTotalHistorico);
    $stmtTotalHistorico->execute($paramsHistorico);
    $totalLlamadasAVIHistorico = $stmtTotalHistorico->fetch()['total'];

    // KPI 2: Duración Total (filtrado)
    $sqlDuracionTotalHistorico = "SELECT SUM(connection_duration_secs) as total FROM avi_call_costs $whereHistorico";
    $stmtDuracionTotalHistorico = $pdo->prepare($sqlDuracionTotalHistorico);
    $stmtDuracionTotalHistorico->execute($paramsHistorico);
    $duracionTotalSecsHistorico = $stmtDuracionTotalHistorico->fetch()['total'] ?? 0;

    // KPI 3: Duración Promedio (filtrado)
    $duracionPromedioSecsHistorico = $totalLlamadasAVIHistorico > 0 ? $duracionTotalSecsHistorico / $totalLlamadasAVIHistorico : 0;

    // Construir WHERE para resultados (siigo_lead_data_v2)
    $whereResumenHistorico = "WHERE c.valor = :cliente";
    $paramsResumenHistorico = [':cliente' => $clienteActual];

    if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
        $whereResumenHistorico .= " AND ts.valor BETWEEN :fecha_desde AND :fecha_hasta";
        $paramsResumenHistorico[':fecha_desde'] = $fechaDesdeSQL;
        $paramsResumenHistorico[':fecha_hasta'] = $fechaHastaSQL;
    } elseif (!empty($fechaDesdeSQL)) {
        $whereResumenHistorico .= " AND ts.valor >= :fecha_desde";
        $paramsResumenHistorico[':fecha_desde'] = $fechaDesdeSQL;
    } elseif (!empty($fechaHastaSQL)) {
        $whereResumenHistorico .= " AND ts.valor <= :fecha_hasta";
        $paramsResumenHistorico[':fecha_hasta'] = $fechaHastaSQL;
    }

    // Gráfico Pie: Tipo de llamadas (filtrado)
    $sqlResultadosHistorico = "
        SELECT
            res.valor as resultado,
            COUNT(DISTINCT r.F9CallID) as total
        FROM siigo_lead_data_v2 r
        INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID
            AND res.clave = 'resultado_llamada'
            AND res.method = 'ResultadoPerfil'
        INNER JOIN siigo_lead_data_v2 ts ON r.F9CallID = ts.F9CallID
            AND ts.clave = 'F9TimeStamp'
        INNER JOIN siigo_lead_data_v2 c ON r.F9CallID = c.F9CallID
            AND c.clave = 'CLIENTE'
        $whereResumenHistorico
        GROUP BY res.valor
        ORDER BY total DESC
    ";
    $stmtResultadosHistorico = $pdo->prepare($sqlResultadosHistorico);
    $stmtResultadosHistorico->execute($paramsResumenHistorico);
    $resultadosHistorico = $stmtResultadosHistorico->fetchAll();

    $totalResultadosHistorico = array_sum(array_column($resultadosHistorico, 'total'));
    // Llamadas colgadas = Five9 - AVI (llamadas que entraron pero no completaron el proceso AVI)
    $colgadasHistorico = max(0, $totalLlamadasFive9Historico - $totalLlamadasAVIHistorico);

    // ============================================
    // DATOS PARA TABLA RESUMEN DIARIO - HISTÓRICO
    // ============================================

    // Obtener resumen diario agrupado por fecha
    // Construir WHERE específico para esta consulta (sin incluir cliente ya está en INNER JOIN)
    $whereResumenDiario = "";
    $paramsResumenDiario = [':cliente' => $clienteActual];

    if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
        $whereResumenDiario .= " AND ts.valor BETWEEN :fecha_desde AND :fecha_hasta";
        $paramsResumenDiario[':fecha_desde'] = $fechaDesdeSQL;
        $paramsResumenDiario[':fecha_hasta'] = $fechaHastaSQL;
    } elseif (!empty($fechaDesdeSQL)) {
        $whereResumenDiario .= " AND ts.valor >= :fecha_desde";
        $paramsResumenDiario[':fecha_desde'] = $fechaDesdeSQL;
    } elseif (!empty($fechaHastaSQL)) {
        $whereResumenDiario .= " AND ts.valor <= :fecha_hasta";
        $paramsResumenDiario[':fecha_hasta'] = $fechaHastaSQL;
    }

    $sqlResumenDiarioHistorico = "
        SELECT
            DATE(ts.valor) as fecha,
            res.valor as resultado,
            COUNT(DISTINCT r.F9CallID) as total
        FROM siigo_lead_data_v2 r
        INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID
            AND res.clave = 'resultado_llamada'
            AND res.method = 'ResultadoPerfil'
        INNER JOIN siigo_lead_data_v2 ts ON r.F9CallID = ts.F9CallID
            AND ts.clave = 'F9TimeStamp'
        INNER JOIN siigo_lead_data_v2 c ON r.F9CallID = c.F9CallID
            AND c.clave = 'CLIENTE'
            AND c.valor = :cliente
        WHERE 1=1
        $whereResumenDiario
        GROUP BY DATE(ts.valor), res.valor
        ORDER BY DATE(ts.valor) ASC, res.valor
    ";
    $stmtResumenDiarioHistorico = $pdo->prepare($sqlResumenDiarioHistorico);
    $stmtResumenDiarioHistorico->execute($paramsResumenDiario);
    $resumenDiarioHistorico = $stmtResumenDiarioHistorico->fetchAll(PDO::FETCH_ASSOC);

    // Organizar datos de resumen por fecha y resultado
    $resumenPorFechaHistorico = [];
    $todosResultadosHistorico = [];
    foreach ($resumenDiarioHistorico as $row) {
        $fecha = $row['fecha'];
        $resultado = $row['resultado'];
        $total = $row['total'];
        if (!isset($resumenPorFechaHistorico[$fecha])) {
            $resumenPorFechaHistorico[$fecha] = [];
        }
        $resumenPorFechaHistorico[$fecha][$resultado] = $total;
        if (!in_array($resultado, $todosResultadosHistorico)) {
            $todosResultadosHistorico[] = $resultado;
        }
    }

    // Calcular colgadas por fecha (histórico)
    $colgadasPorFechaHistorico = [];

    // Obtener conteos de Five9 por fecha
    $whereFive9Fechas = "WHERE cliente = :cliente";
    $paramsFive9Fechas = [':cliente' => $clienteActual];

    if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
        $whereFive9Fechas .= " AND F9TimeStamp BETWEEN :fecha_desde AND :fecha_hasta";
        $paramsFive9Fechas[':fecha_desde'] = $fechaDesdeSQL;
        $paramsFive9Fechas[':fecha_hasta'] = $fechaHastaSQL;
    } elseif (!empty($fechaDesdeSQL)) {
        $whereFive9Fechas .= " AND F9TimeStamp >= :fecha_desde";
        $paramsFive9Fechas[':fecha_desde'] = $fechaDesdeSQL;
    } elseif (!empty($fechaHastaSQL)) {
        $whereFive9Fechas .= " AND F9TimeStamp <= :fecha_hasta";
        $paramsFive9Fechas[':fecha_hasta'] = $fechaHastaSQL;
    }

    $sqlFive9Fechas = "
        SELECT DATE(F9TimeStamp) as fecha, COUNT(*) as total
        FROM avi_calls
        $whereFive9Fechas
        GROUP BY DATE(F9TimeStamp)
    ";
    $stmtFive9Fechas = $pdo->prepare($sqlFive9Fechas);
    $stmtFive9Fechas->execute($paramsFive9Fechas);
    $five9PorFechaHistorico = $stmtFive9Fechas->fetchAll(PDO::FETCH_ASSOC);

    // Obtener conteos de AVI por fecha
    $whereAVIFechas = "WHERE cliente = :cliente";
    $paramsAVIFechas = [':cliente' => $clienteActual];

    if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
        $whereAVIFechas .= " AND DATE(metadata_date_local) BETWEEN :fecha_desde AND :fecha_hasta";
        $paramsAVIFechas[':fecha_desde'] = $fechaDesdeSQL;
        $paramsAVIFechas[':fecha_hasta'] = $fechaHastaSQL;
    } elseif (!empty($fechaDesdeSQL)) {
        $whereAVIFechas .= " AND DATE(metadata_date_local) >= :fecha_desde";
        $paramsAVIFechas[':fecha_desde'] = $fechaDesdeSQL;
    } elseif (!empty($fechaHastaSQL)) {
        $whereAVIFechas .= " AND DATE(metadata_date_local) <= :fecha_hasta";
        $paramsAVIFechas[':fecha_hasta'] = $fechaHastaSQL;
    }

    $sqlAVIFechas = "
        SELECT DATE(metadata_date_local) as fecha, COUNT(*) as total
        FROM avi_call_costs
        $whereAVIFechas
        GROUP BY DATE(metadata_date_local)
    ";
    $stmtAVIFechas = $pdo->prepare($sqlAVIFechas);
    $stmtAVIFechas->execute($paramsAVIFechas);
    $aviPorFechaHistorico = $stmtAVIFechas->fetchAll(PDO::FETCH_ASSOC);

    // Crear arrays asociativos para fácil acceso
    $five9PorFecha = [];
    foreach ($five9PorFechaHistorico as $row) {
        $five9PorFecha[$row['fecha']] = $row['total'];
    }

    $aviPorFecha = [];
    foreach ($aviPorFechaHistorico as $row) {
        $aviPorFecha[$row['fecha']] = $row['total'];
    }

    // Calcular colgadas = Five9 - AVI para cada fecha
    $todasFechas = array_unique(array_merge(
        array_keys($five9PorFecha),
        array_keys($aviPorFecha),
        array_keys($resumenPorFechaHistorico)
    ));

    foreach ($todasFechas as $fecha) {
        $totalFive9 = $five9PorFecha[$fecha] ?? 0;
        $totalAVI = $aviPorFecha[$fecha] ?? 0;
        $colgadasPorFechaHistorico[$fecha] = max(0, $totalFive9 - $totalAVI);
    }

    // ============================================
    // DATOS PARA LEADS - HISTÓRICO
    // ============================================

    $whereLeadsHistorico = "WHERE method = 'Insert Call' AND EXISTS (
        SELECT 1 FROM siigo_lead_data_v2 c
        WHERE c.F9CallID = d.F9CallID AND c.clave = 'CLIENTE' AND c.valor = '$clienteActual'
    )";
    $paramsLeadsHistorico = [];

    if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
        $whereLeadsHistorico .= " AND EXISTS (
            SELECT 1 FROM siigo_lead_data_v2 f
            WHERE f.F9CallID = d.F9CallID AND f.clave = 'F9TimeStamp'
            AND f.valor BETWEEN :fecha_desde AND :fecha_hasta
        )";
        $paramsLeadsHistorico[':fecha_desde'] = $fechaDesdeSQL;
        $paramsLeadsHistorico[':fecha_hasta'] = $fechaHastaSQL;
    } elseif (!empty($fechaDesdeSQL)) {
        $whereLeadsHistorico .= " AND EXISTS (
            SELECT 1 FROM siigo_lead_data_v2 f
            WHERE f.F9CallID = d.F9CallID AND f.clave = 'F9TimeStamp'
            AND f.valor >= :fecha_desde
        )";
        $paramsLeadsHistorico[':fecha_desde'] = $fechaDesdeSQL;
    } elseif (!empty($fechaHastaSQL)) {
        $whereLeadsHistorico .= " AND EXISTS (
            SELECT 1 FROM siigo_lead_data_v2 f
            WHERE f.F9CallID = d.F9CallID AND f.clave = 'F9TimeStamp'
            AND f.valor <= :fecha_hasta
        )";
        $paramsLeadsHistorico[':fecha_hasta'] = $fechaHastaSQL;
    }

    // Filtro de búsqueda
    if (!empty($buscar)) {
        $whereLeadsHistorico .= " AND EXISTS (
            SELECT 1 FROM siigo_lead_data_v2 s
            WHERE s.F9CallID = d.F9CallID
            AND s.valor LIKE :buscar
        )";
        $paramsLeadsHistorico[':buscar'] = "%$buscar%";
    }

    $sqlLeadsHistorico = "SELECT F9CallID, clave, valor FROM siigo_lead_data_v2 d $whereLeadsHistorico ORDER BY F9CallID";
    $stmtLeadsHistorico = $pdo->prepare($sqlLeadsHistorico);
    $stmtLeadsHistorico->execute($paramsLeadsHistorico);
    $rowsLeadsHistorico = $stmtLeadsHistorico->fetchAll(PDO::FETCH_ASSOC);

    // Organizar leads por F9CallID
    $leadsHistorico = [];
    foreach ($rowsLeadsHistorico as $row) {
        $leadsHistorico[$row['F9CallID']][$row['clave']] = $row['valor'];
    }

    // Obtener claves únicas de Insert Call
    $keysStmt = $pdo->query("SELECT DISTINCT clave FROM siigo_lead_data_v2 WHERE method = 'Insert Call'");
    $keys = $keysStmt->fetchAll(PDO::FETCH_COLUMN);
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
    <title>Dashboard Consolidado - <?= CLIENTE_ACTUAL ?></title>
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
                    <h1 class="text-3xl font-bold">Dashboard Consolidado</h1>
                    <p class="text-indigo-100">Cliente: <?= CLIENTE_ACTUAL ?></p>
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
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6 relative">
                <!-- Indicador de actualización -->
                <div id="refresh-indicator" class="absolute -top-2 -right-2 bg-green-500 text-white px-2 py-1 rounded-full text-xs opacity-0 transition-opacity duration-300">
                    <i class="fas fa-check mr-1"></i>Actualizado
                </div>

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

                <!-- Total Llamadas Five9 -->
                <div class="bg-gradient-to-br from-gray-600 to-gray-700 rounded-lg shadow-md p-6 text-white">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold opacity-90">Total Llamadas Five9</h3>
                        <i class="fas fa-phone text-2xl opacity-50"></i>
                    </div>
                    <p class="text-4xl font-bold"><span id="rt-total-five9"><?= number_format($totalLlamadasFive9Hoy) ?></span></p>
                    <p class="text-xs opacity-75 mt-1">Hoy <?= date('d/m/Y') ?></p>
                </div>

                <!-- Total Llamadas AVI -->
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-md p-6 text-white">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold opacity-90">Total Llamadas AVI</h3>
                        <i class="fas fa-phone-volume text-2xl opacity-50"></i>
                    </div>
                    <p class="text-4xl font-bold"><span id="rt-total-avi"><?= number_format($totalLlamadasAVIHoy) ?></span></p>
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

                <!-- Duración Promedio -->
                <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg shadow-md p-6 text-white">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold opacity-90">Duración Promedio</h3>
                        <i class="fas fa-hourglass-half text-2xl opacity-50"></i>
                    </div>
                    <p class="text-4xl font-bold"><span id="rt-duracion-promedio"><?= secondsToMMSS($duracionPromedioSecsHoy) ?></span></p>
                    <p class="text-xs opacity-75 mt-1">MM:SS por llamada</p>
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
                                <tr class="bg-gray-50">
                                    <td class="px-4 py-2 font-semibold">Llamadas Five9</td>
                                    <td class="px-4 py-2 text-right font-semibold"><?= number_format($totalLlamadasFive9Hoy) ?></td>
                                    <td class="px-4 py-2 text-right text-gray-500">-</td>
                                </tr>
                                <tr class="bg-blue-50">
                                    <td class="px-4 py-2 font-semibold text-blue-600">Llamadas AVI</td>
                                    <td class="px-4 py-2 text-right font-semibold text-blue-600"><?= number_format($totalLlamadasAVIHoy) ?></td>
                                    <td class="px-4 py-2 text-right text-gray-500">-</td>
                                </tr>
                                <?php foreach ($resultadosHoy as $row): $porcentaje = $totalLlamadasAVIHoy > 0 ? ($row['total'] / $totalLlamadasAVIHoy) * 100 : 0; ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2 pl-8">→ <?= htmlspecialchars($row['resultado']) ?></td>
                                    <td class="px-4 py-2 text-right font-semibold"><?= number_format($row['total']) ?></td>
                                    <td class="px-4 py-2 text-right"><?= number_format($porcentaje, 1) ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($colgadasHoy > 0): $porcentajeColgadas = ($colgadasHoy / $totalLlamadasAVIHoy) * 100; ?>
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
                        <a href="dashboard_consolidado.php" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-3 rounded-lg transition duration-200 flex items-center justify-center gap-2 text-center">
                            <i class="fas fa-redo"></i>Limpiar
                        </a>
                    </div>
                </form>
            </div>

            <!-- Resultados Históricos -->
            <?php if ($aplicarFiltros): ?>
                <!-- KPIs Históricos -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <!-- Total Llamadas Five9 -->
                    <div class="bg-gradient-to-br from-gray-500 to-gray-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Total Llamadas Five9</h3>
                            <i class="fas fa-phone text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><?= number_format($totalLlamadasFive9Historico) ?></p>
                        <p class="text-xs opacity-75 mt-1">
                            <?= !empty($fechaDesde) ? date('d/m/Y', strtotime($fechaDesde)) : '...' ?>
                            <?= !empty($fechaDesde) && !empty($fechaHasta) ? ' - ' : '' ?>
                            <?= !empty($fechaHasta) ? date('d/m/Y', strtotime($fechaHasta)) : '' ?>
                        </p>
                    </div>

                    <!-- Total Llamadas AVI -->
                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold opacity-90">Total Llamadas AVI</h3>
                            <i class="fas fa-phone-volume text-2xl opacity-50"></i>
                        </div>
                        <p class="text-4xl font-bold"><?= number_format($totalLlamadasAVIHistorico) ?></p>
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
                                    <tr class="bg-gray-50">
                                        <td class="px-4 py-2 font-semibold">Llamadas Five9</td>
                                        <td class="px-4 py-2 text-right font-semibold"><?= number_format($totalLlamadasFive9Historico) ?></td>
                                        <td class="px-4 py-2 text-right text-gray-500">-</td>
                                    </tr>
                                    <tr class="bg-purple-50">
                                        <td class="px-4 py-2 font-semibold text-purple-600">Llamadas AVI</td>
                                        <td class="px-4 py-2 text-right font-semibold text-purple-600"><?= number_format($totalLlamadasAVIHistorico) ?></td>
                                        <td class="px-4 py-2 text-right text-gray-500">-</td>
                                    </tr>
                                    <?php foreach ($resultadosHistorico as $row): $porcentaje = $totalLlamadasAVIHistorico > 0 ? ($row['total'] / $totalLlamadasAVIHistorico) * 100 : 0; ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2 pl-8">→ <?= htmlspecialchars($row['resultado']) ?></td>
                                        <td class="px-4 py-2 text-right font-semibold"><?= number_format($row['total']) ?></td>
                                        <td class="px-4 py-2 text-right"><?= number_format($porcentaje, 1) ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if ($colgadasHistorico > 0): $porcentajeColgadas = ($colgadasHistorico / $totalLlamadasAVIHistorico) * 100; ?>
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
                                    <th class="px-4 py-3 text-right font-bold">Llamadas Five9</th>
                                    <th class="px-4 py-3 text-right font-bold">Llamadas AVI</th>
                                    <?php foreach ($todosResultadosHistorico as $resultado): ?>
                                        <th class="px-4 py-3 text-right font-bold"><?= htmlspecialchars($resultado) ?></th>
                                    <?php endforeach; ?>
                                    <th class="px-4 py-3 text-right font-bold">Llamadas Colgadas</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php
                                $todasFechasHistorico = array_unique(array_merge(
                                    array_keys($resumenPorFechaHistorico),
                                    array_keys($colgadasPorFechaHistorico)
                                ));
                                sort($todasFechasHistorico);

                                $totalFive9General = 0;
                                $totalAVIGeneral = 0;
                                $totalesPorResultadoHistorico = [];
                                foreach ($todosResultadosHistorico as $res) {
                                    $totalesPorResultadoHistorico[$res] = 0;
                                }
                                $totalColgadasGeneral = 0;

                                foreach ($todasFechasHistorico as $fecha):
                                    // Usar los arrays ya calculados
                                    $five9 = $five9PorFecha[$fecha] ?? 0;
                                    $avi = $aviPorFecha[$fecha] ?? 0;
                                    $resultados = $resumenPorFechaHistorico[$fecha] ?? [];
                                    $colgadas = $colgadasPorFechaHistorico[$fecha] ?? 0;

                                    $totalFive9General += $five9;
                                    $totalAVIGeneral += $avi;
                                    foreach ($todosResultadosHistorico as $res) {
                                        $totalesPorResultadoHistorico[$res] += ($resultados[$res] ?? 0);
                                    }
                                    $totalColgadasGeneral += $colgadas;
                                ?>
                                <tr class="hover:bg-purple-50 transition">
                                    <td class="px-4 py-3 font-semibold text-gray-800"><?= date('d/m/Y', strtotime($fecha)) ?></td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= number_format($five9) ?></td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= number_format($avi) ?></td>
                                    <?php foreach ($todosResultadosHistorico as $resultado): ?>
                                        <td class="px-4 py-3 text-right <?= isset($resultados[$resultado]) ? 'text-gray-800 font-semibold' : 'text-gray-400'; ?>">
                                            <?= isset($resultados[$resultado]) ? number_format($resultados[$resultado]) : '-' ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-800">
                                        <?= number_format($colgadas) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <!-- Fila de Totales -->
                                <tr class="bg-purple-100 border-t-2 border-purple-600 font-bold text-base">
                                    <td class="px-4 py-4 text-purple-800">TOTAL</td>
                                    <td class="px-4 py-4 text-right text-purple-800"><?= number_format($totalFive9General) ?></td>
                                    <td class="px-4 py-4 text-right text-purple-800"><?= number_format($totalAVIGeneral) ?></td>
                                    <?php foreach ($todosResultadosHistorico as $resultado): ?>
                                        <td class="px-4 py-4 text-right text-purple-800">
                                            <?= number_format($totalesPorResultadoHistorico[$resultado]) ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="px-4 py-4 text-right text-purple-800"><?= number_format($totalColgadasGeneral) ?></td>
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
                    </div>
                    <!-- Buscador específico para interacciones -->
                    <div class="mb-4">
                        <div class="relative">
                            <input type="text" id="buscarInteraccion" placeholder="Buscar por F9CallID, ANI, teléfono..."
                                   class="w-full px-4 py-3 pl-12 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                   oninput="filtrarInteracciones()">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <button onclick="limpiarBusqueda()" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </button>
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
                                        <th class="px-4 py-3 text-left font-bold">DNIS</th>
                                        <th class="px-4 py-3 text-left font-bold">F911DNIS</th>
                                    </tr>
                                </thead>
                                <tbody id="interaccionesContainer" class="divide-y divide-gray-200">
                                    <?php $count = 0; foreach ($leadsHistorico as $f9id => $values): $count++; ?>
                                        <tr class="interaccion-item hover:bg-purple-50 transition"
                                             data-search="<?= htmlspecialchars(strtolower($f9id . ' ' . implode(' ', $values))) ?>">
                                            <td class="px-4 py-3 text-center font-semibold"><?= $count ?></td>
                                            <td class="px-4 py-3 text-center">
                                                <button onclick="openDetailModal('<?= htmlspecialchars($f9id) ?>')"
                                                       class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-lg transition duration-200 flex items-center gap-2 text-xs">
                                                    <i class="fas fa-eye"></i>Ver Detalle
                                                </button>
                                            </td>
                                            <td class="px-4 py-3 font-mono text-purple-700 font-semibold text-xs"><?= htmlspecialchars($f9id) ?></td>
                                            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($values['F9TimeStamp'] ?? '-') ?></td>
                                            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($values['ANI'] ?? '-') ?></td>
                                            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($values['DNIS'] ?? '-') ?></td>
                                            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($values['F911DNIS'] ?? '-') ?></td>
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
            fetch('dashboard_realtime_data.php')
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

            // Actualizar Total Llamadas Five9
            const five9Element = document.getElementById('rt-total-five9');
            if (five9Element) {
                five9Element.textContent = data.totalLlamadasFive9;
            }

            // Actualizar Total Llamadas AVI
            const aviElement = document.getElementById('rt-total-avi');
            if (aviElement) {
                aviElement.textContent = data.totalLlamadasAVI;
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

            // Actualizar gráfico de pie y tabla
            actualizarGraficoPie(data);

            // Mostrar indicador de actualización
            showRefreshIndicator();
        }

        function actualizarTablaResultados(data) {
            const tbody = document.getElementById('rt-resultados-tbody');
            if (!tbody) return;

            let html = '';

            // Llamadas Five9
            html += `
                <tr class="bg-gray-50">
                    <td class="px-4 py-2 font-semibold">Llamadas Five9</td>
                    <td class="px-4 py-2 text-right font-semibold">${data.totalLlamadasFive9}</td>
                    <td class="px-4 py-2 text-right text-gray-500">-</td>
                </tr>
            `;

            // Llamadas AVI
            html += `
                <tr class="bg-blue-50">
                    <td class="px-4 py-2 font-semibold text-blue-600">Llamadas AVI</td>
                    <td class="px-4 py-2 text-right font-semibold text-blue-600">${data.totalLlamadasAVI}</td>
                    <td class="px-4 py-2 text-right text-gray-500">-</td>
                </tr>
            `;

            // Resultados
            data.resultados.forEach(row => {
                const porcentaje = data.totalLlamadasAVI > 0 ? (row.total / data.totalLlamadasAVI) * 100 : 0;
                html += `
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 pl-8">→ ${row.resultado}</td>
                        <td class="px-4 py-2 text-right font-semibold">${row.total}</td>
                        <td class="px-4 py-2 text-right">${porcentaje.toFixed(1)}%</td>
                    </tr>
                `;
            });

            // Llamadas colgadas
            if (data.colgadas > 0) {
                const porcentajeColgadas = (data.colgadas / data.totalLlamadasAVI) * 100;
                html += `
                    <tr class="hover:bg-gray-50 bg-red-50">
                        <td class="px-4 py-2 pl-8 font-semibold text-red-600">→ Llamadas Colgadas</td>
                        <td class="px-4 py-2 text-right font-semibold text-red-600">${data.colgadas}</td>
                        <td class="px-4 py-2 text-right text-red-600">${porcentajeColgadas.toFixed(1)}%</td>
                    </tr>
                `;
            }

            tbody.innerHTML = html;
        }

        function actualizarGraficoPie(data) {
            const chart = Chart.getChart('chartTipoLlamadasHoy');
            if (!chart) return;

            const labels = [];
            const valores = [];
            const colores = [];

            // Agregar cada resultado
            data.resultados.forEach((d, i) => {
                labels.push(d.resultado);
                valores.push(d.total);
                const paleta = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1'];
                colores.push(paleta[i % paleta.length]);
            });

            // Agregar colgadas si existen
            if (data.colgadas > 0) {
                labels.push('Llamadas Colgadas');
                valores.push(data.colgadas);
                colores.push('#ef4444'); // Rojo para colgadas
            }

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
        const totalFive9Hoy = <?= $totalLlamadasFive9Hoy ?>;
        const totalAVIHoy = <?= $totalLlamadasAVIHoy ?>;
        const colgadasHoy = <?= $colgadasHoy ?>;
        const datosHoy = <?= json_encode($resultadosHoy) ?>;

        // El gráfico muestra: AVI (con resultados desglosados) + Colgadas
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
        const totalFive9Historico = <?= $totalLlamadasFive9Historico ?>;
        const totalAVIHistorico = <?= $totalLlamadasAVIHistorico ?>;
        const colgadasHistorico = <?= $colgadasHistorico ?>;
        const datosHistorico = <?= json_encode($resultadosHistorico) ?>;

        // El gráfico muestra: AVI (con resultados desglosados) + Colgadas
        const labelsHistorico = [];
        const dataHistorico = [];
        const coloresHistorico = [];

        // Agregar cada resultado individualmente
        datosHistorico.forEach(d => {
            labelsHistorico.push(d.resultado);
            dataHistorico.push(d.total);
        });

        // Agregar colgadas si existen
        if (colgadasHistorico > 0) {
            labelsHistorico.push('Llamadas Colgadas');
            dataHistorico.push(colgadasHistorico);
        }

        // Generar colores (rojo para colgadas)
        const numColoresHistorico = labelsHistorico.length;
        for (let i = 0; i < numColoresHistorico; i++) {
            if (i === labelsHistorico.length - 1 && colgadasHistorico > 0) {
                coloresHistorico.push('#ef4444'); // Rojo para colgadas
            } else {
                const paleta = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1'];
                coloresHistorico.push(paleta[i % paleta.length]);
            }
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
            iframe.src = 'detalle_lead_v2.php?F9CallID=' + encodeURIComponent(f9CallID);
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

            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.table_to_sheet(table);
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
            const container = document.getElementById('interaccionesContainer');
            const tabla = document.getElementById('tablaInteracciones');
            const sinResultados = document.getElementById('sinResultados');

            if (!container) return;

            const rows = container.getElementsByClassName('interaccion-item');
            let visibleCount = 0;

            Array.from(rows).forEach(function(row) {
                const searchData = row.getAttribute('data-search') || '';

                if (searchData.includes(busqueda)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Mostrar mensaje si no hay resultados
            if (sinResultados) {
                if (visibleCount === 0 && rows.length > 0) {
                    sinResultados.classList.remove('hidden');
                    if (tabla) tabla.classList.add('hidden');
                } else {
                    sinResultados.classList.add('hidden');
                    if (tabla) tabla.classList.remove('hidden');
                }
            }
        }

        function limpiarBusqueda() {
            document.getElementById('buscarInteraccion').value = '';
            filtrarInteracciones();
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
