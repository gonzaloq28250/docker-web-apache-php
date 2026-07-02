<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuración de conexión a MariaDB
$host = 'phone.icq24.com';
$port = '3306';
$dbname = 'asteriskcdrdb';
$username = 'gonzaloq';
$password = '73ch$iCC';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Obtener filtros
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$filtro_numero = $_GET['numero'] ?? '';
$filtro_extension = $_GET['extension'] ?? '';
$filtro_contexto = $_GET['contexto'] ?? '';
$filtro_quien_colgo = $_GET['quien_colgo'] ?? '';

// Helper function para convertir segundos a MM:SS
function secondsToMMSS($seconds) {
    if ($seconds <= 0) return '00:00';
    $totalSeconds = (int)round($seconds);
    $minutes = intdiv($totalSeconds, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d', $minutes, $secs);
}

// Helper function para convertir segundos a HH:MM:SS
function secondsToHHMMSS($seconds) {
    if ($seconds <= 0) return '00:00:00';
    $totalSeconds = (int)round($seconds);
    $hours = intdiv($totalSeconds, 3600);
    $minutes = intdiv($totalSeconds % 3600, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

// Query principal - construir dinámicamente con filtros
$sql = "
WITH first_chanstart AS (
    SELECT
        c.*,
        ROW_NUMBER() OVER (
            PARTITION BY c.linkedid
            ORDER BY c.eventtime ASC
        ) AS rn
    FROM cel c
    WHERE c.eventtype = 'CHAN_START'
      AND DATE(c.eventtime) BETWEEN :fecha_desde AND :fecha_hasta
),

first_hangup AS (
    SELECT
        c.*,
        ROW_NUMBER() OVER (
            PARTITION BY c.linkedid
            ORDER BY c.eventtime ASC
        ) AS rn
    FROM cel c
    WHERE c.eventtype = 'HANGUP'
)

SELECT
    cs.linkedid,
    cs.uniqueid,
    cs.eventtime AS call_start_time,
    cs.cid_name,
    cs.cid_num,
    cs.exten,
    cs.context,
    cs.channame,
    cs.peer,
    cs.appname,
    cs.appdata,

    hu.eventtime AS hangup_time,
    hu.extra AS hangup_extra,

    -- QUIÉN COLGÓ (TRUNK LIMPIO)
    CASE
        WHEN hu.extra LIKE '%hangupsource\":\"Caller%' THEN 'Caller'
        WHEN hu.extra LIKE '%hangupsource\":\"Peer%' THEN 'Agent/Peer'
        WHEN hu.extra LIKE '%hangupsource\":\"PBX%' THEN 'PBX'
        WHEN hu.extra LIKE '%hangupsource\":\"dialplan%' THEN 'Dialplan'

        -- EXTRAER TRUNK: PJSIP/XXXXX
        WHEN hu.extra LIKE '%hangupsource\":\"PJSIP/%' THEN
            SUBSTRING_INDEX(
                SUBSTRING(
                    hu.extra,
                    LOCATE('hangupsource\":\"', hu.extra) + LENGTH('hangupsource\":\"')
                ),
                '-',
                1
            )

        ELSE 'Unknown'
    END AS who_hung_up,

    cdr.calldate,
    cdr.duration,
    cdr.billsec,
    cdr.disposition

FROM first_chanstart cs
LEFT JOIN first_hangup hu
    ON hu.linkedid = cs.linkedid
   AND hu.rn = 1
LEFT JOIN cdr
    ON cdr.linkedid = cs.linkedid

WHERE cs.rn = 1
";

// Agregar filtros adicionales dinámicamente
$conditions = [];
$params = [
    ':fecha_desde' => $fecha_desde,
    ':fecha_hasta' => $fecha_hasta
];

if (!empty($filtro_numero)) {
    $conditions[] = "cs.cid_num LIKE :numero";
    $params[':numero'] = "%$filtro_numero%";
}

if (!empty($filtro_extension)) {
    $conditions[] = "cs.exten LIKE :extension";
    $params[':extension'] = "%$filtro_extension%";
}

if (!empty($filtro_contexto)) {
    $conditions[] = "cs.context LIKE :contexto";
    $params[':contexto'] = "%$filtro_contexto%";
}

if (!empty($filtro_quien_colgo)) {
    $conditions[] = "CASE
        WHEN hu.extra LIKE '%hangupsource\":\"Caller%' THEN 'Caller'
        WHEN hu.extra LIKE '%hangupsource\":\"Peer%' THEN 'Agent/Peer'
        WHEN hu.extra LIKE '%hangupsource\":\"PBX%' THEN 'PBX'
        WHEN hu.extra LIKE '%hangupsource\":\"dialplan%' THEN 'Dialplan'
        WHEN hu.extra LIKE '%hangupsource\":\"PJSIP/%' THEN
            SUBSTRING_INDEX(
                SUBSTRING(
                    hu.extra,
                    LOCATE('hangupsource\":\"', hu.extra) + LENGTH('hangupsource\":\"')
                ),
                '-',
                1
            )
        ELSE 'Unknown'
    END LIKE :quien_colgo";
    $params[':quien_colgo'] = "%$filtro_quien_colgo%";
}

if (!empty($conditions)) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY cs.eventtime";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$llamadas = $stmt->fetchAll();

// Calcular KPIs
$total_llamadas = count($llamadas);
$duracion_total = array_sum(array_column($llamadas, 'duration'));
$billsec_total = array_sum(array_column($llamadas, 'billsec'));
$duracion_promedio = $total_llamadas > 0 ? $duracion_total / $total_llamadas : 0;

// Distribución por quién colgó
$hangup_distribution = [];
foreach ($llamadas as $llamada) {
    $who = $llamada['who_hung_up'] ?? 'Unknown';
    if (!isset($hangup_distribution[$who])) {
        $hangup_distribution[$who] = 0;
    }
    $hangup_distribution[$who]++;
}

// Distribución por disposición
$disposition_distribution = [];
foreach ($llamadas as $llamada) {
    $disp = $llamada['disposition'] ?? 'N/A';
    if (!isset($disposition_distribution[$disp])) {
        $disposition_distribution[$disp] = 0;
    }
    $disposition_distribution[$disp]++;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte FreePBX - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-lg shadow-lg p-6 mb-6">
            <h1 class="text-3xl font-bold text-white mb-2">
                <i class="fas fa-phone-alt mr-2"></i>Reporte FreePBX
            </h1>
            <p class="text-blue-100">Dashboard de llamadas y análisis de hangup</p>
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar mr-1"></i>Fecha Desde
                        </label>
                        <input
                            type="date"
                            name="fecha_desde"
                            value="<?php echo htmlspecialchars($fecha_desde); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar mr-1"></i>Fecha Hasta
                        </label>
                        <input
                            type="date"
                            name="fecha_hasta"
                            value="<?php echo htmlspecialchars($fecha_hasta); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone mr-1"></i>Número
                        </label>
                        <input
                            type="text"
                            name="numero"
                            value="<?php echo htmlspecialchars($filtro_numero); ?>"
                            placeholder="Buscar número..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-hashtag mr-1"></i>Extensión
                        </label>
                        <input
                            type="text"
                            name="extension"
                            value="<?php echo htmlspecialchars($filtro_extension); ?>"
                            placeholder="Buscar extensión..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-folder mr-1"></i>Contexto
                        </label>
                        <input
                            type="text"
                            name="contexto"
                            value="<?php echo htmlspecialchars($filtro_contexto); ?>"
                            placeholder="Buscar contexto..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone-slash mr-1"></i>Quién Colgó
                        </label>
                        <input
                            type="text"
                            name="quien_colgo"
                            value="<?php echo htmlspecialchars($filtro_quien_colgo); ?>"
                            placeholder="Ej: PJSIP/Five9, Caller..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                    </div>
                </div>
                <div class="flex gap-4">
                    <button
                        type="submit"
                        class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                    >
                        <i class="fas fa-filter mr-2"></i>Filtrar
                    </button>
                    <button
                        type="button"
                        onclick="exportToExcel()"
                        class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                    >
                        <i class="fas fa-file-excel mr-2"></i>Exportar Excel
                    </button>
                    <a
                        href="reporte-freepbx.php"
                        class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors"
                    >
                        <i class="fas fa-eraser mr-2"></i>Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- KPIs -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium">Total Llamadas</p>
                        <p class="text-3xl font-bold text-blue-600"><?php echo number_format($total_llamadas); ?></p>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-phone text-2xl text-blue-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium">Duración Total</p>
                        <p class="text-3xl font-bold text-green-600"><?php echo secondsToHHMMSS($duracion_total); ?></p>
                    </div>
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-clock text-2xl text-green-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium">Duración Promedio</p>
                        <p class="text-3xl font-bold text-purple-600"><?php echo secondsToHHMMSS($duracion_promedio); ?></p>
                    </div>
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-chart-line text-2xl text-purple-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium">Billsec Total</p>
                        <p class="text-3xl font-bold text-orange-600"><?php echo secondsToHHMMSS($billsec_total); ?></p>
                    </div>
                    <div class="bg-orange-100 rounded-full p-3">
                        <i class="fas fa-stopwatch text-2xl text-orange-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <!-- Distribución por quién colgó -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-phone-slash mr-2 text-red-600"></i>Distribución por Quién Colgó
                </h3>
                <canvas id="hangupChart"></canvas>
            </div>

            <!-- Distribución por disposición -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-pie mr-2 text-blue-600"></i>Distribución por Disposición
                </h3>
                <canvas id="dispositionChart"></canvas>
            </div>
        </div>

        <!-- Tabla de llamadas -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-list mr-2"></i>Detalle de Llamadas
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" id="llamadasTable">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">LinkedID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Inicio Llamada</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Caller ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Número</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Extensión</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contexto</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quién Colgó</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duración</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Billsec</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($llamadas as $llamada): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <button
                                    onclick="openCelModal('<?php echo htmlspecialchars($llamada['linkedid'] ?? ''); ?>')"
                                    class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-xs"
                                >
                                    <i class="fas fa-eye mr-1"></i>Ver CEL
                                </button>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($llamada['linkedid'] ?? ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($llamada['call_start_time'] ?? ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($llamada['cid_name'] ?? ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($llamada['cid_num'] ?? ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($llamada['exten'] ?? ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($llamada['context'] ?? ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php
                                $who = $llamada['who_hung_up'] ?? 'Unknown';
                                $colorClass = 'bg-gray-100 text-gray-800';
                                if ($who == 'Caller') $colorClass = 'bg-blue-100 text-blue-800';
                                elseif ($who == 'Agent/Peer') $colorClass = 'bg-green-100 text-green-800';
                                elseif ($who == 'PBX') $colorClass = 'bg-red-100 text-red-800';
                                ?>
                                <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $colorClass; ?>">
                                    <?php echo htmlspecialchars($who); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo secondsToHHMMSS($llamada['duration'] ?? 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo secondsToHHMMSS($llamada['billsec'] ?? 0); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal CEL Detalle -->
    <div id="celModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 overflow-y-auto">
        <div class="min-h-screen px-4 py-8 flex items-start justify-center">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl">
                <!-- Header del Modal -->
                <div class="flex items-center justify-between p-6 border-b border-gray-200 bg-gradient-to-r from-blue-600 to-blue-800 rounded-t-lg">
                    <h3 class="text-xl font-semibold text-white">
                        <i class="fas fa-list-alt mr-2"></i>Detalle CEL - <span id="modalLinkedId"></span>
                    </h3>
                    <button onclick="closeCelModal()" class="text-white hover:text-gray-200 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <!-- Contenido del Modal -->
                <div class="p-6">
                    <div id="celLoading" class="text-center py-8">
                        <i class="fas fa-spinner fa-spin text-4xl text-blue-600"></i>
                        <p class="mt-4 text-gray-600">Cargando eventos...</p>
                    </div>
                    <div id="celContent" class="hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200" id="celTable">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event Time</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">CID Name</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">CID Num</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Exten</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Context</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Channel</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">App</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">App Data</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Extra</th>
                                    </tr>
                                </thead>
                                <tbody id="celTableBody" class="bg-white divide-y divide-gray-200">
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div id="celError" class="hidden text-center py-8">
                        <i class="fas fa-exclamation-triangle text-4xl text-red-600"></i>
                        <p class="mt-4 text-red-600" id="celErrorMessage">Error al cargar los datos</p>
                    </div>
                </div>
                <!-- Footer del Modal -->
                <div class="flex justify-end gap-4 p-6 border-t border-gray-200">
                    <button
                        onclick="exportCelToExcel()"
                        class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors"
                    >
                        <i class="fas fa-file-excel mr-2"></i>Exportar Excel
                    </button>
                    <button
                        onclick="closeCelModal()"
                        class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 transition-colors"
                    >
                        <i class="fas fa-times mr-2"></i>Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Variables globales para el modal
        let currentLinkedId = '';

        // Funciones del modal CEL
        function openCelModal(linkedid) {
            currentLinkedId = linkedid;
            document.getElementById('modalLinkedId').textContent = linkedid;
            document.getElementById('celModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';

            // Mostrar loading, ocultar contenido y error
            document.getElementById('celLoading').classList.remove('hidden');
            document.getElementById('celContent').classList.add('hidden');
            document.getElementById('celError').classList.add('hidden');

            // Cargar datos
            loadCelData(linkedid);
        }

        function closeCelModal() {
            document.getElementById('celModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function loadCelData(linkedid) {
            fetch(`get_cel_detalle.php?linkedid=${encodeURIComponent(linkedid)}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('celLoading').classList.add('hidden');

                    if (data.error) {
                        document.getElementById('celError').classList.remove('hidden');
                        document.getElementById('celErrorMessage').textContent = data.error;
                        return;
                    }

                    if (data.success && data.data) {
                        renderCelTable(data.data);
                        document.getElementById('celContent').classList.remove('hidden');
                    }
                })
                .catch(error => {
                    document.getElementById('celLoading').classList.add('hidden');
                    document.getElementById('celError').classList.remove('hidden');
                    document.getElementById('celErrorMessage').textContent = 'Error de conexión: ' + error.message;
                });
        }

        function renderCelTable(eventos) {
            const tbody = document.getElementById('celTableBody');
            tbody.innerHTML = '';

            eventos.forEach((evento, index) => {
                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-50';

                // Color según tipo de evento
                let eventClass = 'bg-gray-100 text-gray-800';
                if (evento.eventtype === 'CHAN_START') eventClass = 'bg-green-100 text-green-800';
                else if (evento.eventtype === 'HANGUP') eventClass = 'bg-red-100 text-red-800';
                else if (evento.eventtype === 'ANSWER') eventClass = 'bg-blue-100 text-blue-800';
                else if (evento.eventtype === 'BRIDGE_ENTER') eventClass = 'bg-purple-100 text-purple-800';
                else if (evento.eventtype === 'BRIDGE_EXIT') eventClass = 'bg-yellow-100 text-yellow-800';

                row.innerHTML = `
                    <td class="px-4 py-2 text-sm text-gray-900">${index + 1}</td>
                    <td class="px-4 py-2 text-sm text-gray-900">${escapeHtml(evento.eventtime)}</td>
                    <td class="px-4 py-2 text-sm">
                        <span class="px-2 py-1 rounded text-xs font-medium ${eventClass}">
                            ${escapeHtml(evento.eventtype)}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-sm text-gray-900">${escapeHtml(evento.cid_name)}</td>
                    <td class="px-4 py-2 text-sm text-gray-900">${escapeHtml(evento.cid_num)}</td>
                    <td class="px-4 py-2 text-sm text-gray-900">${escapeHtml(evento.exten)}</td>
                    <td class="px-4 py-2 text-sm text-gray-900">${escapeHtml(evento.context)}</td>
                    <td class="px-4 py-2 text-sm text-gray-900 max-w-xs truncate" title="${escapeHtml(evento.channame)}">${escapeHtml(evento.channame)}</td>
                    <td class="px-4 py-2 text-sm text-gray-900">${escapeHtml(evento.appname)}</td>
                    <td class="px-4 py-2 text-sm text-gray-900 max-w-xs truncate" title="${escapeHtml(evento.appdata)}">${escapeHtml(evento.appdata)}</td>
                    <td class="px-4 py-2 text-sm text-gray-900 max-w-xs truncate" title="${escapeHtml(evento.extra)}">${escapeHtml(evento.extra)}</td>
                `;
                tbody.appendChild(row);
            });
        }

        function exportCelToExcel() {
            const table = document.getElementById('celTable');
            const wb = XLSX.utils.table_to_book(table, {sheet: "CEL"});
            XLSX.writeFile(wb, `cel_detalle_${currentLinkedId}.xlsx`);
        }

        // Cerrar modal al hacer clic fuera
        document.getElementById('celModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCelModal();
            }
        });

        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCelModal();
            }
        });

        // Datos para los gráficos
        const hangupData = <?php echo json_encode($hangup_distribution); ?>;
        const dispositionData = <?php echo json_encode($disposition_distribution); ?>;

        // Gráfico de quién colgó
        const hangupCtx = document.getElementById('hangupChart').getContext('2d');
        new Chart(hangupCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(hangupData),
                datasets: [{
                    data: Object.values(hangupData),
                    backgroundColor: [
                        '#3B82F6', // Blue
                        '#10B981', // Green
                        '#EF4444', // Red
                        '#F59E0B', // Orange
                        '#8B5CF6', // Purple
                        '#6B7280'  // Gray
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Gráfico de disposición
        const dispositionCtx = document.getElementById('dispositionChart').getContext('2d');
        new Chart(dispositionCtx, {
            type: 'pie',
            data: {
                labels: Object.keys(dispositionData),
                datasets: [{
                    data: Object.values(dispositionData),
                    backgroundColor: [
                        '#10B981', // Green
                        '#F59E0B', // Orange
                        '#EF4444', // Red
                        '#6B7280'  // Gray
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Función para exportar a Excel
        function exportToExcel() {
            const table = document.getElementById('llamadasTable');
            const wb = XLSX.utils.table_to_book(table, {sheet: "Llamadas"});
            const fechaDesde = '<?php echo $fecha_desde; ?>';
            const fechaHasta = '<?php echo $fecha_hasta; ?>';
            XLSX.writeFile(wb, `reporte_freepbx_${fechaDesde}_${fechaHasta}.xlsx`);
        }
    </script>
</body>
</html>
