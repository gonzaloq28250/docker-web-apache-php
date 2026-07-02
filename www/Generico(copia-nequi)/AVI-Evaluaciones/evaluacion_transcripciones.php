<?php
require_once 'config.php';
date_default_timezone_set('America/Puerto_Rico');

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

$fechaHoyInicio = date('Y-m-d') . 'T00:00';
$fechaHoyFin = date('Y-m-d') . 'T23:59';

$fechaDesde = !empty($_GET['fecha_desde']) ? $_GET['fecha_desde'] : $fechaHoyInicio;
$fechaHasta = !empty($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : $fechaHoyFin;
$buscar = $_GET['buscar'] ?? '';
$callResultFilter = $_GET['call_result'] ?? '';

function convertDateTimeToLocal($dateTime) {
    if (empty($dateTime)) return '';
    $converted = str_replace('T', ' ', $dateTime);
    if (preg_match('/\d{2}:\d{2}$/', $converted)) {
        $converted .= ':00';
    }
    return $converted;
}

function convertToDateTimeLocal($dateTime) {
    if (empty($dateTime)) return '';
    $converted = preg_replace('/:\d{2}$/', '', $dateTime);
    return str_replace(' ', 'T', $converted);
}

// Preservar valores para el formulario
$fechaDesdeDisplay = $fechaDesde;
$fechaHastaDisplay = $fechaHasta;

$fechaDesdeSQL = convertDateTimeToLocal($fechaDesde);
$fechaHastaSQL = convertDateTimeToLocal($fechaHasta);

// Query principal: llamadas con transcripción disponible
$clienteDB = $esEleven ? 'NEQUI' : $clienteActual;
$aliasCalls = $esEleven ? 'ac' : 'lc';
$where = "WHERE $aliasCalls.cliente = :cliente";
$params = [':cliente' => $clienteDB];

if (!empty($fechaDesdeSQL) && !empty($fechaHastaSQL)) {
    $where .= " AND $aliasCalls.F9TimeStamp BETWEEN :fecha_desde AND :fecha_hasta";
    $params[':fecha_desde'] = $fechaDesdeSQL;
    $params[':fecha_hasta'] = $fechaHastaSQL;
} elseif (!empty($fechaDesdeSQL)) {
    $where .= " AND $aliasCalls.F9TimeStamp >= :fecha_desde";
    $params[':fecha_desde'] = $fechaDesdeSQL;
} elseif (!empty($fechaHastaSQL)) {
    $where .= " AND $aliasCalls.F9TimeStamp <= :fecha_hasta";
    $params[':fecha_hasta'] = $fechaHastaSQL;
}

if (!empty($buscar)) {
    $where .= " AND ($aliasCalls.F9CallID LIKE :buscar OR $aliasCalls.ANI LIKE :buscar2 OR $aliasCalls.DNIS LIKE :buscar3)";
    $params[':buscar'] = "%$buscar%";
    $params[':buscar2'] = "%$buscar%";
    $params[':buscar3'] = "%$buscar%";
}

if (!empty($callResultFilter)) {
    if ($esEleven) {
        $where .= " AND slv.valor = :call_result";
    } else {
        $where .= " AND lvc.call_result = :call_result";
    }
    $params[':call_result'] = $callResultFilter;
}

if ($esEleven) {
    $sql = "
        SELECT ac.F9CallID, ac.F9TimeStamp, ac.ANI, ac.DNIS,
               acc.connection_duration_secs AS duration,
               slv.valor AS call_result,
               ent.transcript AS transcript_text,
               ent.summary AS transcript_summary
        FROM avi_calls ac
        INNER JOIN avi_call_costs acc ON ac.F9CallID = acc.f9_call_id
        INNER JOIN eleven_n8n_t1 ent ON acc.conversation_id = ent.ElevenConversationID
        LEFT JOIN siigo_lead_data_v2 slv ON ac.F9CallID = slv.F9CallID
            AND slv.method = 'ResultadoPerfil'
            AND slv.clave = 'resultado_llamada'
        $where
        AND ent.has_transcript = 1
        ORDER BY ac.F9TimeStamp DESC
    ";
} else {
    $sql = "
        SELECT lc.F9CallID, lc.F9TimeStamp, lc.ANI, lc.DNIS, lc.duration,
               lvc.call_result, lvc.transcript_text, lvc.transcript_summary
        FROM level_calls lc
        INNER JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
        $where
        AND lvc.transcript_text IS NOT NULL
        AND lvc.transcript_text != ''
        ORDER BY lc.F9TimeStamp DESC
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$calls = $stmt->fetchAll();

// Obtener lista de call_result disponibles
$callResultsDisponibles = [];
if ($esEleven) {
    $stmtResultados = $pdo->prepare("
        SELECT DISTINCT slv.valor
        FROM siigo_lead_data_v2 slv
        INNER JOIN avi_call_costs acc ON slv.F9CallID = acc.f9_call_id
        INNER JOIN eleven_n8n_t1 ent ON acc.conversation_id = ent.ElevenConversationID
        INNER JOIN avi_calls ac ON acc.f9_call_id = ac.F9CallID AND ac.cliente = :cliente_ac
        WHERE slv.method = 'ResultadoPerfil'
        AND slv.clave = 'resultado_llamada'
        AND slv.valor IS NOT NULL
        AND slv.valor != ''
        ORDER BY slv.valor
    ");
    $stmtResultados->execute([':cliente_ac' => $clienteActual === 'NEQUI-Eleven' ? 'NEQUI' : $clienteActual]);
    $callResultsDisponibles = $stmtResultados->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmtResultados = $pdo->prepare("
        SELECT DISTINCT lvc.call_result
        FROM level_conversations lvc
        INNER JOIN level_calls lc ON lc.F9CallID = lvc.callid
        WHERE lc.cliente = :cliente
        AND lvc.call_result IS NOT NULL
        AND lvc.call_result != ''
        AND lvc.call_result != 'IVR_Regular'
        AND lvc.call_result != '\"IVR_Regular\"'
        ORDER BY lvc.call_result
    ");
    $stmtResultados->execute([':cliente' => $clienteActual]);
    $callResultsDisponibles = $stmtResultados->fetchAll(PDO::FETCH_COLUMN);
}

// Cargar evaluaciones existentes
$evaluacionesMap = [];
$stmtEval = $pdo->prepare("SELECT F9CallID, resultado, call_result_correcto, se_puede_mejorar, info_disponible_sa, observacion, fecha_evaluacion FROM level_transcripciones_evaluacion WHERE cliente = :cliente");
$stmtEval->execute([':cliente' => $clienteActual]);
foreach ($stmtEval->fetchAll() as $ev) {
    $evaluacionesMap[$ev['F9CallID']] = $ev;
}

// Contar evaluaciones
$totalCalls = count($calls);
$evaluadas = count(array_intersect_key($evaluacionesMap, array_flip(array_column($calls, 'F9CallID'))));
$pendientes = $totalCalls - $evaluadas;
$pasaCount = 0;
$noPasaCount = 0;
foreach ($calls as $call) {
    $ev = $evaluacionesMap[$call['F9CallID']] ?? null;
    if ($ev) {
        if ($ev['resultado'] === 'pasa') $pasaCount++;
        else $noPasaCount++;
    }
}
$filtroEstado = $_GET['estado_eval'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluación de Transcripciones - <?= $clienteActual ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-gradient-to-r from-teal-600 to-teal-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <i class="fas fa-clipboard-check text-3xl"></i>
                    <div>
                        <h1 class="text-3xl font-bold">Evaluación de Transcripciones</h1>
                        <p class="text-teal-100">Cliente: <strong><?= $clienteActual ?></strong></p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <select id="clienteSelector" onchange="cambiarCliente(this.value)" class="bg-teal-700 text-white border border-teal-500 rounded-lg px-3 py-2 text-sm font-semibold cursor-pointer focus:outline-none focus:ring-2 focus:ring-teal-300">
                        <?php foreach ($clientesDisponibles as $c): ?>
                            <option value="<?= $c ?>" <?= $c === $clienteActual ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="dashboard_evaluacion.php<?= $clienteActual !== CLIENTE_ACTUAL ? '?cliente='.$clienteActual : '' ?>" class="bg-teal-500 hover:bg-teal-400 text-white px-4 py-2 rounded-lg transition flex items-center gap-2 text-sm">
                        <i class="fas fa-chart-bar"></i> Dashboard Evaluación
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
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-filter text-teal-600"></i> Filtros de Búsqueda
            </h3>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <input type="hidden" name="cliente" value="<?= $clienteActual ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-calendar-alt mr-2"></i>Desde
                    </label>
                    <input type="datetime-local" name="fecha_desde" value="<?= htmlspecialchars($fechaDesdeDisplay) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-calendar-alt mr-2"></i>Hasta
                    </label>
                    <input type="datetime-local" name="fecha_hasta" value="<?= htmlspecialchars($fechaHastaDisplay) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-search mr-2"></i>Buscar
                    </label>
                    <input type="text" name="buscar" placeholder="F9CallID, ANI, DNIS..."
                           value="<?= htmlspecialchars($buscar) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div id="filter-call-result">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-tag mr-2"></i>Resultado llamada
                    </label>
                    <select name="call_result"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <option value="">Todos</option>
                        <?php foreach ($callResultsDisponibles as $cr): ?>
                        <option value="<?= htmlspecialchars($cr) ?>" <?= $callResultFilter === $cr ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cr) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 rounded-lg transition flex items-center justify-center gap-2">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                    <a href="evaluacion_transcripciones.php<?= $clienteActual !== CLIENTE_ACTUAL ? '?cliente='.$clienteActual : '' ?>" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-2 rounded-lg transition flex items-center justify-center gap-2">
                        <i class="fas fa-redo"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- Contenido principal: listado + panel de evaluación -->
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <!-- Listado de llamadas (ocupa 2 columnas en desktop) -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-gradient-to-r from-teal-600 to-teal-700 text-white px-6 py-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-bold flex items-center gap-2">
                                <i class="fas fa-phone"></i> Llamadas con Transcripción
                            </h2>
                            <span class="bg-white text-teal-700 px-3 py-1 rounded-full text-sm font-bold"><?= count($calls) ?></span>
                        </div>
                    </div>
                    <?php if (empty($calls)): ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-inbox text-5xl mb-4"></i>
                            <p>No hay llamadas con transcripción en el rango seleccionado</p>
                        </div>
                    <?php else: ?>
                        <!-- Barra de resumen de evaluaciones -->
                        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <div class="flex items-center gap-4 text-sm">
                                    <span class="font-semibold text-gray-700">
                                        <i class="fas fa-list mr-1"></i> Total: <span id="stats-total" class="text-teal-700"><?= $totalCalls ?></span>
                                    </span>
                                    <span class="text-green-700 font-semibold">
                                        <i class="fas fa-check-circle"></i> Pasa: <span id="stats-pasa"><?= $pasaCount ?></span>
                                    </span>
                                    <span class="text-red-700 font-semibold">
                                        <i class="fas fa-times-circle"></i> No Pasa: <span id="stats-nopasa"><?= $noPasaCount ?></span>
                                    </span>
                                    <span class="text-gray-500 font-semibold">
                                        <i class="fas fa-hourglass-half"></i> Pendientes: <span id="stats-pendientes"><?= $pendientes ?></span>
                                    </span>
                                </div>
                                <select id="filtroEstadoEval" onchange="filtrarPorEstado()"
                                        class="text-sm px-3 py-1.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500">
                                    <option value="">Todas</option>
                                    <option value="pendiente" <?= $filtroEstado === 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                                    <option value="evaluada" <?= $filtroEstado === 'evaluada' ? 'selected' : '' ?>>Evaluadas</option>
                                    <option value="pasa" <?= $filtroEstado === 'pasa' ? 'selected' : '' ?>>Pasa</option>
                                    <option value="no_pasa" <?= $filtroEstado === 'no_pasa' ? 'selected' : '' ?>>No Pasa</option>
                                </select>
                            </div>
                        </div>
                        <div class="overflow-y-auto" style="max-height: 65vh;">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-100 sticky top-0">
                                    <tr>
                                        <th class="px-3 py-2 text-center">Estado</th>
                                        <th class="px-3 py-2 text-left">F9CallID</th>
                                        <th class="px-3 py-2 text-left">ANI</th>
                                        <th class="px-3 py-2 text-left">Fecha/Hora</th>
                                        <th class="px-3 py-2 text-left">Resultado</th>
                                        <th class="px-3 py-2 text-right">Dur.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($calls as $i => $call):
                                        $f9id = $call['F9CallID'];
                                        $eval = $evaluacionesMap[$f9id] ?? null;
                                        $evalClass = '';
                                        $evalIcon = '';
                                        if ($eval) {
                                            if ($eval['resultado'] === 'pasa') {
                                                $evalClass = 'border-l-4 border-green-500 bg-green-50';
                                                $evalIcon = '<i class="fas fa-check-circle text-green-600"></i>';
                                            } else {
                                                $evalClass = 'border-l-4 border-red-500 bg-red-50';
                                                $evalIcon = '<i class="fas fa-times-circle text-red-600"></i>';
                                            }
                                        }
                                    ?>
                                    <tr class="call-row cursor-pointer hover:bg-teal-50 transition <?= $evalClass ?>"
                                        data-f9callid="<?= htmlspecialchars($f9id) ?>"
                                        data-evalstatus="<?= $eval ? $eval['resultado'] : 'pendiente' ?>"
                                        onclick="selectCall('<?= htmlspecialchars($f9id) ?>')">
                                        <td class="px-3 py-3 text-center text-base"><?= $eval ? $evalIcon : '<i class="fas fa-circle text-gray-300 text-xs"></i>' ?></td>
                                        <td class="px-3 py-3 font-mono text-teal-700 font-semibold text-xs"><?= htmlspecialchars($f9id) ?></td>
                                        <td class="px-3 py-3 text-gray-700"><?= htmlspecialchars($call['ANI']) ?></td>
                                        <td class="px-3 py-3 text-gray-500 text-xs whitespace-nowrap"><?= date('Y-m-d H:i', strtotime($call['F9TimeStamp'])) ?></td>
                                        <td class="px-3 py-3 text-gray-700 text-xs"><?= htmlspecialchars($call['call_result'] ?? '-') ?></td>
                                        <td class="px-3 py-3 text-right text-gray-600 text-xs"><?= secondsToMMSS($call['duration'] ?? 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Panel de evaluación (ocupa 3 columnas en desktop) -->
            <div class="lg:col-span-3">
                <div id="panel-bienvenida" class="bg-white rounded-lg shadow-md p-12 text-center">
                    <i class="fas fa-hand-pointer text-6xl text-teal-300 mb-6"></i>
                    <h3 class="text-2xl font-bold text-gray-700 mb-2">Selecciona una llamada</h3>
                    <p class="text-gray-500">Haz clic en una llamada del listado para ver la transcripción y evaluarla</p>
                </div>

                <div id="panel-evaluacion" class="hidden">
                    <!-- Encabezado de la llamada seleccionada -->
                    <div class="bg-white rounded-lg shadow-md overflow-hidden mb-4">
                        <div class="bg-gradient-to-r from-teal-600 to-teal-700 text-white px-6 py-4">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-bold flex items-center gap-2">
                                    <i class="fas fa-file-alt"></i>
                                    Evaluación de Transcripción
                                </h2>
                                <div class="flex items-center gap-2">
                                    <span id="eval-badge" class="hidden text-sm px-3 py-1 rounded-full font-bold"></span>
                                    <span id="mejora-badge" class="hidden bg-yellow-100 text-yellow-800 text-sm px-3 py-1 rounded-full font-bold">
                                        <i class="fas fa-rocket mr-1"></i> Mejorable
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase">F9CallID</p>
                                    <p id="call-f9callid" class="font-mono text-teal-700 font-bold break-all"></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase">ANI</p>
                                    <p id="call-ani" class="text-gray-800 font-semibold"></p>
                                    <input type="hidden" id="call-ani-hidden" value="">
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase">Resultado</p>
                                    <p id="call-resultado" class="text-gray-800 font-semibold"></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase">Duración</p>
                                    <p id="call-duracion" class="text-gray-800 font-semibold"></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase">Fecha/Hora</p>
                                    <p id="call-fecha" class="text-gray-800 font-semibold text-sm"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transcripción -->
                    <div class="bg-white rounded-lg shadow-md overflow-hidden mb-4">
                        <div class="bg-gray-100 px-6 py-3 border-b border-gray-200">
                            <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-microphone text-teal-600"></i> Transcripción
                            </h3>
                        </div>
                        <div class="p-6 max-h-80 overflow-y-auto">
                            <div id="transcript-content" class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap"></div>
                        </div>
                    </div>

                    <!-- Resumen (si existe) -->
                    <div class="bg-white rounded-lg shadow-md overflow-hidden mb-4 hidden" id="summary-container">
                        <div class="bg-gray-100 px-6 py-3 border-b border-gray-200">
                            <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-bookmark text-teal-600"></i> Resumen
                            </h3>
                        </div>
                        <div class="p-6 max-h-40 overflow-y-auto">
                            <div id="summary-content" class="text-sm text-gray-700 leading-relaxed"></div>
                        </div>
                    </div>

                    <!-- Formulario de evaluación -->
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="bg-gray-100 px-6 py-3 border-b border-gray-200">
                            <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-clipboard-check text-teal-600"></i> Evaluación
                            </h3>
                        </div>
                        <div class="p-6">
                            <div class="mb-4">
                                <label class="block text-sm font-semibold text-gray-700 mb-3">Resultado de la evaluación</label>
                                <div class="flex gap-4">
                                    <button id="btn-pasa" onclick="setResultado('pasa')"
                                            class="flex-1 border-2 border-gray-300 rounded-lg py-4 px-6 text-center font-bold text-lg transition hover:shadow-md flex items-center justify-center gap-3">
                                        <i class="fas fa-check-circle text-2xl"></i>
                                        <span>Pasa</span>
                                    </button>
                                    <button id="btn-no-pasa" onclick="setResultado('no_pasa')"
                                            class="flex-1 border-2 border-gray-300 rounded-lg py-4 px-6 text-center font-bold text-lg transition hover:shadow-md flex items-center justify-center gap-3">
                                        <i class="fas fa-times-circle text-2xl"></i>
                                        <span>No Pasa</span>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="observacion" class="block text-sm font-semibold text-gray-700 mb-2">
                                    <i class="fas fa-comment mr-2"></i>Observaciones
                                </label>
                                <textarea id="observacion" rows="4"
                                          class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 resize-y"
                                          placeholder="Agrega observaciones sobre la transcripción..."></textarea>
                            </div>

                            <div class="mb-4 flex items-center gap-3">
                                <input type="checkbox" id="se_puede_mejorar" class="w-5 h-5 text-teal-600 border-gray-300 rounded focus:ring-teal-500 cursor-pointer">
                                <label for="se_puede_mejorar" class="text-sm font-semibold text-gray-700 cursor-pointer select-none">
                                    <i class="fas fa-rocket mr-1 text-teal-500"></i> Se puede mejorar
                                </label>
                            </div>

                            <!-- Selector de call_result correcto (solo visible cuando No Pasa, no aplica para NEQUI-Eleven) -->
                            <div id="call-result-correcto-container" class="mb-4 hidden">
                                <label class="block text-sm font-semibold text-gray-700 mb-3">
                                    <i class="fas fa-tag mr-2"></i>Selecciona el resultado correcto
                                </label>
                                <div class="grid grid-cols-2 gap-2">
                                    <?php foreach ($callResultsDisponibles as $cr): ?>
                                    <label class="cr-option flex items-center gap-3 px-4 py-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-teal-400 hover:bg-teal-50 transition has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50">
                                        <input type="radio" name="call_result_correcto" value="<?= htmlspecialchars($cr) ?>"
                                               class="w-4 h-4 text-teal-600 accent-teal-600"
                                               onchange="onCallResultCorrectoChange(this)">
                                        <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($cr) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div id="info-sa-container" class="mb-4 hidden">
                                <div class="flex items-center gap-3 px-4 py-3 bg-blue-50 border border-blue-200 rounded-lg">
                                    <input type="checkbox" id="info_disponible_sa" class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer">
                                    <label for="info_disponible_sa" class="text-sm font-semibold text-gray-700 cursor-pointer select-none">
                                        <i class="fas fa-database mr-1 text-blue-500"></i> Informaci&oacute;n Disponible en SA
                                    </label>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <button id="btn-guardar" onclick="guardarEvaluacion()" disabled
                                        class="flex-1 bg-teal-600 hover:bg-teal-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-white font-bold py-3 rounded-lg transition flex items-center justify-center gap-2 text-lg">
                                    <i id="btn-guardar-icono" class="fas fa-save"></i>
                                    <span id="btn-guardar-texto">Guardar Evaluación</span>
                                </button>
                            </div>
                            <div id="mensaje-guardado" class="hidden mt-4 p-4 rounded-lg text-center font-semibold"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Estado de la evaluación
        let currentF9CallID = null;
        let selectedResultado = null;
        let datosLlamadas = <?= json_encode(array_combine(
            array_column($calls, 'F9CallID'),
            array_map(function($c) {
                return [
                    'F9CallID' => $c['F9CallID'],
                    'ANI' => $c['ANI'],
                    'DNIS' => $c['DNIS'],
                    'duration' => $c['duration'],
                    'F9TimeStamp' => $c['F9TimeStamp'],
                    'call_result' => $c['call_result'],
                    'transcript_text' => $c['transcript_text'],
                    'transcript_summary' => $c['transcript_summary'],
                ];
            }, $calls)
        )) ?>;
        let evaluacionesExistentes = <?= json_encode($evaluacionesMap) ?>;
        const esEleven = <?= $esEleven ? 'true' : 'false' ?>;
        const clienteActual = '<?= $clienteActual ?>';

        function filtrarPorEstado() {
            const filtro = document.getElementById('filtroEstadoEval').value;
            const rows = document.querySelectorAll('.call-row');
            let visible = 0;
            rows.forEach(row => {
                const status = row.getAttribute('data-evalstatus') || 'pendiente';
                if (!filtro || status === filtro) {
                    row.style.display = '';
                    visible++;
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function selectCall(f9callid) {
            currentF9CallID = f9callid;
            const call = datosLlamadas[f9callid];
            if (!call) return;

            // Resaltar fila seleccionada
            document.querySelectorAll('.call-row').forEach(r => r.classList.remove('bg-teal-100', 'border-l-4', 'border-teal-500'));
            const row = document.querySelector(`.call-row[data-f9callid="${f9callid}"]`);
            if (row) row.classList.add('bg-teal-100', 'border-l-4', 'border-teal-500');

            // Mostrar panel de evaluación
            document.getElementById('panel-bienvenida').classList.add('hidden');
            const panel = document.getElementById('panel-evaluacion');
            panel.classList.remove('hidden');

            // Llenar datos de la llamada
            document.getElementById('call-f9callid').textContent = f9callid;
            document.getElementById('call-ani').textContent = call.ANI || '-';
            document.getElementById('call-ani-hidden').value = call.ANI || '';
            document.getElementById('call-resultado').textContent = call.call_result || '-';
            document.getElementById('call-duracion').textContent = formatDuracion(call.duration || 0);
            document.getElementById('call-fecha').textContent = call.F9TimeStamp ? new Date(call.F9TimeStamp).toLocaleString('es-US', {year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}) : '-';

            // Transcripción
            const transcriptEl = document.getElementById('transcript-content');
            if (call.transcript_text) {
                transcriptEl.innerHTML = call.transcript_text
                    .replace(/\b(Agent:)/g, '<strong class="text-blue-700">$1</strong>')
                    .replace(/\b(User:)/g, '<strong class="text-green-700">$1</strong>');
            } else {
                transcriptEl.innerHTML = '<span class="text-gray-400 italic">Sin transcripción disponible</span>';
            }

            // Resumen
            const summaryContainer = document.getElementById('summary-container');
            const summaryContent = document.getElementById('summary-content');
            if (call.transcript_summary) {
                summaryContainer.classList.remove('hidden');
                summaryContent.textContent = call.transcript_summary;
            } else {
                summaryContainer.classList.add('hidden');
            }

            // Cargar evaluación existente
            cargarEvaluacionExistente(f9callid);
        }

        function formatDuracion(seconds) {
            if (!seconds || seconds <= 0) return '00:00';
            const total = Math.round(seconds);
            const m = Math.floor(total / 60);
            const s = total % 60;
            return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        }

        function cargarEvaluacionExistente(f9callid) {
            const evalData = evaluacionesExistentes[f9callid];
            // Limpiar radios
            document.querySelectorAll('input[name="call_result_correcto"]').forEach(r => r.checked = false);
            const container = document.getElementById('call-result-correcto-container');

            const btnTexto = document.getElementById('btn-guardar-texto');
            const btnIcono = document.getElementById('btn-guardar-icono');

            if (evalData) {
                selectedResultado = evalData.resultado;
                document.getElementById('observacion').value = evalData.observacion || '';
                document.getElementById('se_puede_mejorar').checked = evalData.se_puede_mejorar == 1;
                mostrarMejora(evalData.se_puede_mejorar == 1);
                actualizarBotonResultado(evalData.resultado);
                mostrarBadge(evalData.resultado);
                habilitarBotonGuardar(true);
                mostrarMensaje('', '');
                btnIcono.className = 'fas fa-pen';
                btnTexto.textContent = 'Actualizar Evaluación';

                const saContainer = document.getElementById('info-sa-container');
                if (evalData.resultado === 'no_pasa') {
                    container.classList.remove('hidden');
                    saContainer.classList.remove('hidden');
                    document.getElementById('info_disponible_sa').checked = evalData.info_disponible_sa == 1;
                    if (evalData.call_result_correcto) {
                        const radio = document.querySelector(`input[name="call_result_correcto"][value="${evalData.call_result_correcto}"]`);
                        if (radio) radio.checked = true;
                    }
                } else {
                    container.classList.add('hidden');
                    saContainer.classList.add('hidden');
                }
            } else {
                // Resetear formulario
                selectedResultado = null;
                document.getElementById('observacion').value = '';
                document.getElementById('se_puede_mejorar').checked = false;
                mostrarMejora(false);
                actualizarBotonResultado(null);
                ocultarBadge();
                habilitarBotonGuardar(false);
                mostrarMensaje('', '');
                container.classList.add('hidden');
                document.getElementById('info-sa-container').classList.add('hidden');
                btnIcono.className = 'fas fa-save';
                btnTexto.textContent = 'Guardar Evaluación';
            }
        }

        function setResultado(resultado) {
            selectedResultado = resultado;
            actualizarBotonResultado(resultado);
            habilitarBotonGuardar(true);
            ocultarMensaje();

            const container = document.getElementById('call-result-correcto-container');
            const saContainer = document.getElementById('info-sa-container');
            if (resultado === 'no_pasa') {
                container.classList.remove('hidden');
                saContainer.classList.remove('hidden');
            } else {
                container.classList.add('hidden');
                saContainer.classList.add('hidden');
                // Limpiar selección al cambiar a pasa
                document.querySelectorAll('input[name="call_result_correcto"]').forEach(r => r.checked = false);
            }
        }

        function onCallResultCorrectoChange(el) {
            habilitarBotonGuardar(true);
            ocultarMensaje();
        }

        function actualizarBotonResultado(resultado) {
            const btnPasa = document.getElementById('btn-pasa');
            const btnNoPasa = document.getElementById('btn-no-pasa');

            btnPasa.classList.remove('border-green-500', 'bg-green-50', 'text-green-700');
            btnNoPasa.classList.remove('border-red-500', 'bg-red-50', 'text-red-700');
            btnPasa.classList.add('border-gray-300', 'bg-white', 'text-gray-700');
            btnNoPasa.classList.add('border-gray-300', 'bg-white', 'text-gray-700');

            if (resultado === 'pasa') {
                btnPasa.classList.remove('border-gray-300', 'bg-white', 'text-gray-700');
                btnPasa.classList.add('border-green-500', 'bg-green-50', 'text-green-700');
            } else if (resultado === 'no_pasa') {
                btnNoPasa.classList.remove('border-gray-300', 'bg-white', 'text-gray-700');
                btnNoPasa.classList.add('border-red-500', 'bg-red-50', 'text-red-700');
            }
        }

        function mostrarBadge(resultado) {
            const badge = document.getElementById('eval-badge');
            badge.classList.remove('hidden');
            if (resultado === 'pasa') {
                badge.className = 'bg-green-500 text-white text-sm px-3 py-1 rounded-full font-bold';
                badge.innerHTML = '<i class="fas fa-check mr-1"></i> Pasa';
            } else {
                badge.className = 'bg-red-500 text-white text-sm px-3 py-1 rounded-full font-bold';
                badge.innerHTML = '<i class="fas fa-times mr-1"></i> No Pasa';
            }
        }

        function mostrarMejora(checked) {
            const el = document.getElementById('mejora-badge');
            if (checked) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        }

        function ocultarBadge() {
            document.getElementById('eval-badge').classList.add('hidden');
        }

        function habilitarBotonGuardar(habilitar) {
            const btn = document.getElementById('btn-guardar');
            const requerido = selectedResultado === 'no_pasa' && !document.querySelector('input[name="call_result_correcto"]:checked');
            btn.disabled = !habilitar || !selectedResultado || requerido;
        }

        function guardarEvaluacion() {
            if (!currentF9CallID || !selectedResultado) return;

            const btn = document.getElementById('btn-guardar');
            const btnIcono = document.getElementById('btn-guardar-icono');
            const btnTexto = document.getElementById('btn-guardar-texto');
            btn.disabled = true;
            btnIcono.className = 'fas fa-spinner fa-spin';
            btnTexto.textContent = 'Guardando...';

            const call = datosLlamadas[currentF9CallID];
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('F9CallID', currentF9CallID);
            formData.append('ANI', document.getElementById('call-ani-hidden').value || '');
            formData.append('resultado', selectedResultado);
            formData.append('observacion', document.getElementById('observacion').value);
            formData.append('se_puede_mejorar', document.getElementById('se_puede_mejorar').checked ? '1' : '0');
            formData.append('info_disponible_sa', document.getElementById('info_disponible_sa').checked ? '1' : '0');
            const selectedRadio = document.querySelector('input[name="call_result_correcto"]:checked');
            if (selectedRadio) {
                formData.append('call_result_correcto', selectedRadio.value);
            }

            formData.append('cliente', clienteActual);

            fetch('api_evaluacion.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    mostrarMensaje(result.message, 'success');
                    mostrarBadge(selectedResultado);
                    mostrarMejora(document.getElementById('se_puede_mejorar').checked);

                    // Actualizar la fila en el listado
                    const row = document.querySelector(`.call-row[data-f9callid="${currentF9CallID}"]`);
                    if (row) {
                        const iconClass = selectedResultado === 'pasa' ? 'fa-check-circle text-green-600' : 'fa-times-circle text-red-600';
                        const rowClass = selectedResultado === 'pasa' ? 'border-l-4 border-green-500 bg-green-50' : 'border-l-4 border-red-500 bg-red-50';
                        row.className = `call-row cursor-pointer hover:bg-teal-50 transition ${rowClass}`;
                        row.setAttribute('data-evalstatus', selectedResultado);
                        const td = row.querySelector('td:first-child');
                        if (td) {
                            td.innerHTML = `<i class="fas ${iconClass}"></i>`;
                        }
                        // Re-aplicar filtro si hay uno activo
                        filtrarPorEstado();
                    }

                    // Actualizar mapa local
                    const crRadio = document.querySelector('input[name="call_result_correcto"]:checked');
                    evaluacionesExistentes[currentF9CallID] = {
                        F9CallID: currentF9CallID,
                        ANI: call ? call.ANI : null,
                        resultado: selectedResultado,
                        call_result_correcto: crRadio ? crRadio.value : null,
                        observacion: document.getElementById('observacion').value,
                    };

                    // Actualizar contadores de la barra de estado
                    actualizarStats();
                } else {
                    mostrarMensaje('Error: ' + (result.error || 'Error al guardar'), 'error');
                }
            })
            .catch(error => {
                mostrarMensaje('Error de conexión: ' + error.message, 'error');
            })
            .finally(() => {
                btn.disabled = false;
                // Restaurar según si existe evaluación o no
                const evalData = evaluacionesExistentes[currentF9CallID];
                if (evalData) {
                    btnIcono.className = 'fas fa-pen';
                    btnTexto.textContent = 'Actualizar Evaluación';
                } else {
                    btnIcono.className = 'fas fa-save';
                    btnTexto.textContent = 'Guardar Evaluación';
                }
            });
        }

        function mostrarMensaje(texto, tipo) {
            const el = document.getElementById('mensaje-guardado');
            el.classList.remove('hidden', 'bg-green-100', 'text-green-800', 'bg-red-100', 'text-red-800', 'bg-blue-100', 'text-blue-800');
            if (!texto) {
                el.classList.add('hidden');
                return;
            }
            if (tipo === 'success') {
                el.classList.add('bg-green-100', 'text-green-800');
            } else if (tipo === 'error') {
                el.classList.add('bg-red-100', 'text-red-800');
            } else {
                el.classList.add('bg-blue-100', 'text-blue-800');
            }
            el.textContent = texto;
        }

        function actualizarStats() {
            let pasa = 0, noPasa = 0, pendientes = 0;
            Object.values(datosLlamadas).forEach(call => {
                const ev = evaluacionesExistentes[call.F9CallID];
                if (ev) {
                    if (ev.resultado === 'pasa') pasa++;
                    else noPasa++;
                } else {
                    pendientes++;
                }
            });
            document.getElementById('stats-pasa').textContent = pasa;
            document.getElementById('stats-nopasa').textContent = noPasa;
            document.getElementById('stats-pendientes').textContent = pendientes;
        }

        function ocultarMensaje() {
            const el = document.getElementById('mensaje-guardado');
            el.classList.add('hidden');
        }

        // Aplicar filtro de estado al cargar si viene en URL
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            const estadoEval = params.get('estado_eval');
            if (estadoEval) {
                const sel = document.getElementById('filtroEstadoEval');
                if (sel) {
                    sel.value = estadoEval;
                    filtrarPorEstado();
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
