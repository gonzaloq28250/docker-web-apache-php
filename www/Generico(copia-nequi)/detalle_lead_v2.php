<?php
// Incluir config.php - asegúrate de que el archivo config.php esté en el mismo directorio
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

$f9id = $_GET['F9CallID'] ?? '';

// Capturar parámetros de filtro desde la URL
$filterParams = [];
if (!empty($_GET['fecha'])) $filterParams['fecha'] = $_GET['fecha'];
if (!empty($_GET['proyecto'])) $filterParams['proyecto'] = $_GET['proyecto'];
if (!empty($_GET['ani'])) $filterParams['ani'] = $_GET['ani'];
if (!empty($_GET['dnis'])) $filterParams['dnis'] = $_GET['dnis'];

// Construir query string para mantener filtros
$queryString = http_build_query($filterParams);
$backLink = 'leads_dinamicos_v2.php' . (!empty($queryString) ? '?' . $queryString : '');

// 1. Datos de Insert Call
$stmt_insert = $pdo->prepare("SELECT clave, valor FROM siigo_lead_data_v2 WHERE F9CallID = :f9id AND method = 'Insert Call'");
$stmt_insert->execute([':f9id' => $f9id]);
$insertCall = $stmt_insert->fetchAll(PDO::FETCH_ASSOC);

// 2. Datos de ResultadoPerfil
$stmt1 = $pdo->prepare("SELECT clave, valor FROM siigo_lead_data_v2 WHERE F9CallID = :f9id AND method = 'ResultadoPerfil'");
$stmt1->execute([':f9id' => $f9id]);
$resultadoPerfil = $stmt1->fetchAll(PDO::FETCH_ASSOC);

// Obtener F911DNIS
$stmt_dnis = $pdo->prepare("SELECT valor FROM siigo_lead_data_v2 WHERE F9CallID = :f9id AND clave = 'F911DNIS' LIMIT 1");
$stmt_dnis->execute([':f9id' => $f9id]);
$f911dnis = $stmt_dnis->fetchColumn();

// Obtener datos de avi_call_costs (duración y costo LLM)
$stmt_costos = $pdo->prepare("SELECT connection_duration_secs, llm_cost_total_usd FROM avi_call_costs WHERE f9_call_id = :f9id LIMIT 1");
$stmt_costos->execute([':f9id' => $f9id]);
$costos = $stmt_costos->fetch(PDO::FETCH_ASSOC);

// Función para convertir segundos a MM:SS
function secondsToMMSS($seconds) {
    if ($seconds <= 0) return '00:00';
    $totalSeconds = (int)round($seconds);
    $minutes = intdiv($totalSeconds, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d', $minutes, $secs);
}

// Obtener resultado_llamada de ResultadoPerfil
$resultadoLlamada = '-';
foreach ($resultadoPerfil as $row) {
    if ($row['clave'] === 'resultado_llamada') {
        $resultadoLlamada = $row['valor'];
        break;
    }
}

// 2. Datos de eleven_n8n_t1
$stmt2 = $pdo->prepare("SELECT * FROM eleven_n8n_t1 WHERE from_number = :dnis");
$stmt2->execute([':dnis' => $f911dnis]);
$transcripciones = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Obtener ElevenConversationID
$conversationIDs = array_column($transcripciones, 'ElevenConversationID');

// 3. Datos de eleven_n8n_t1_analisis
$analisis = [];
if (!empty($conversationIDs)) {
    $placeholders = implode(',', array_fill(0, count($conversationIDs), '?'));
    $stmt3 = $pdo->prepare("SELECT * FROM eleven_n8n_t1_analisis WHERE ElevenConversationID IN ($placeholders)");
    $stmt3->execute($conversationIDs);
    $analisis = $stmt3->fetchAll(PDO::FETCH_ASSOC);
}

// Calcular resumen de resultados
$total = count($analisis);
$success = $unknown = $failure = 0;
foreach ($analisis as $row) {
    if ($row['result'] === 'success') $success++;
    elseif ($row['result'] === 'unknown') $unknown++;
    elseif ($row['result'] === 'failure') $failure++;
}

function getResultColor($result) {
    if ($result === 'success') return 'bg-green-100 border-l-4 border-green-500';
    if ($result === 'unknown') return 'bg-yellow-100 border-l-4 border-yellow-500';
    if ($result === 'failure') return 'bg-red-100 border-l-4 border-red-500';
    return 'bg-gray-100';
}

function getResultBadgeColor($result) {
    if ($result === 'success') return 'bg-green-500 text-white';
    if ($result === 'unknown') return 'bg-yellow-500 text-white';
    if ($result === 'failure') return 'bg-red-500 text-white';
    return 'bg-gray-500 text-white';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de Lead - <?php echo htmlspecialchars($f9id); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white shadow-lg">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <a href="<?php echo htmlspecialchars($backLink); ?>" class="hover:bg-blue-700 p-2 rounded-lg transition duration-200">
                            <i class="fas fa-arrow-left text-2xl"></i>
                        </a>
                        <div>
                            <h1 class="text-3xl font-bold">Detalle de Lead</h1>
                            <p class="text-blue-100">ID: <span class="font-mono font-bold"><?php echo htmlspecialchars($f9id); ?></span></p>
                        </div>
                    </div>
                    <button onclick="copyToClipboard('<?php echo htmlspecialchars($f9id); ?>')" class="bg-blue-500 hover:bg-blue-400 px-4 py-2 rounded-lg transition duration-200 flex items-center gap-2">
                        <i class="fas fa-copy"></i>Copiar ID
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Container -->
        <main class="max-w-7xl mx-auto px-4 py-8">
            <!-- KPIs Destacados -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Resultado -->
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 border-l-4 border-blue-500 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-lg font-bold text-blue-800">Resultado</h3>
                        <i class="fas fa-phone-check text-3xl text-blue-500"></i>
                    </div>
                    <p class="text-2xl font-bold text-blue-700"><?php echo htmlspecialchars($resultadoLlamada); ?></p>
                </div>

                <!-- Duración -->
                <div class="bg-gradient-to-br from-green-50 to-green-100 border-l-4 border-green-500 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-lg font-bold text-green-800">Duración</h3>
                        <i class="fas fa-clock text-3xl text-green-500"></i>
                    </div>
                    <p class="text-2xl font-bold text-green-700"><?php echo secondsToMMSS($costos['connection_duration_secs'] ?? 0); ?></p>
                </div>

                <!-- Costo LLM -->
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 border-l-4 border-purple-500 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-lg font-bold text-purple-800">Costo LLM</h3>
                        <i class="fas fa-dollar-sign text-3xl text-purple-500"></i>
                    </div>
                    <p class="text-2xl font-bold text-purple-700">$<?php echo number_format($costos['llm_cost_total_usd'] ?? 0, 3); ?> USD</p>
                </div>
            </div>

            <!-- Información General -->
            <?php if (!empty($insertCall) || !empty($resultadoPerfil)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-600"></i>Información General
                </h2>

                <!-- Insert Call Data -->
                <?php if (!empty($insertCall)): ?>
                <div class="mb-6">
                    <h3 class="text-xl font-bold text-gray-700 mb-3 flex items-center gap-2 border-b-2 border-blue-500 pb-2">
                        <i class="fas fa-phone-volume text-blue-500"></i>Datos de Llamada
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($insertCall as $row):
                            // Ocultar DNIS y F911DNIS
                            if (in_array($row['clave'], ['DNIS', 'F911DNIS'])) continue;
                        ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:border-blue-300 transition duration-150">
                                <p class="text-sm font-semibold text-gray-600 uppercase"><?php echo htmlspecialchars($row['clave']); ?></p>
                                <p class="text-lg text-gray-800 mt-1 break-words"><?php echo htmlspecialchars($row['valor']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ResultadoPerfil Data -->
                <?php if (!empty($resultadoPerfil)): ?>
                <div>
                    <h3 class="text-xl font-bold text-gray-700 mb-3 flex items-center gap-2 border-b-2 border-green-500 pb-2">
                        <i class="fas fa-user-check text-green-500"></i>AVI Result
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($resultadoPerfil as $row):
                            // Ocultar campos que ya se muestran en KPIs
                            if (in_array($row['clave'], ['connection_duration_secs', 'llm_cost_total_usd'])) continue;
                        ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:border-blue-300 transition duration-150">
                                <p class="text-sm font-semibold text-gray-600 uppercase"><?php echo htmlspecialchars($row['clave']); ?></p>
                                <p class="text-lg text-gray-800 mt-1 break-words"><?php echo htmlspecialchars($row['valor']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Resumen de Resultados -->
            <?php if ($total > 0): ?>
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-blue-600"></i>Resumen de Resultados
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Success Card -->
                    <div class="bg-gradient-to-br from-green-50 to-green-100 border-l-4 border-green-500 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-lg font-bold text-green-800">Success</h3>
                            <i class="fas fa-check-circle text-3xl text-green-500"></i>
                        </div>
                        <p class="text-3xl font-bold text-green-700"><?php echo $success; ?></p>
                        <p class="text-sm text-green-600"><?php echo $total ? round($success * 100 / $total, 1) : 0; ?>% del total</p>
                    </div>

                    <!-- Unknown Card -->
                    <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 border-l-4 border-yellow-500 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-lg font-bold text-yellow-800">Unknown</h3>
                            <i class="fas fa-question-circle text-3xl text-yellow-500"></i>
                        </div>
                        <p class="text-3xl font-bold text-yellow-700"><?php echo $unknown; ?></p>
                        <p class="text-sm text-yellow-600"><?php echo $total ? round($unknown * 100 / $total, 1) : 0; ?>% del total</p>
                    </div>

                    <!-- Failure Card -->
                    <div class="bg-gradient-to-br from-red-50 to-red-100 border-l-4 border-red-500 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-lg font-bold text-red-800">Failure</h3>
                            <i class="fas fa-times-circle text-3xl text-red-500"></i>
                        </div>
                        <p class="text-3xl font-bold text-red-700"><?php echo $failure; ?></p>
                        <p class="text-sm text-red-600"><?php echo $total ? round($failure * 100 / $total, 1) : 0; ?>% del total</p>
                    </div>
                </div>
                <div class="bg-gray-100 rounded-lg p-4 mt-4">
                    <p class="text-gray-700"><strong>Total de análisis:</strong> <?php echo $total; ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Transcripciones -->
            <?php if (!empty($transcripciones)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-microphone text-blue-600"></i>Transcripciones
                </h2>
                <div class="space-y-4">
                    <?php foreach ($transcripciones as $idx => $trans): ?>
                    <div class="border border-gray-200 rounded-lg overflow-hidden">
                        <button class="w-full bg-gray-100 hover:bg-gray-200 px-6 py-4 text-left font-bold text-gray-800 flex items-center justify-between transition duration-150"
                                onclick="toggleTranscript(<?php echo $idx; ?>)">
                            <span><i class="fas fa-chevron-right mr-2 transition-transform" id="arrow-<?php echo $idx; ?>"></i>Conversación <?php echo $idx + 1; ?> - <?php echo htmlspecialchars($trans['ElevenConversationID']); ?></span>
                        </button>
                        <div id="transcript-<?php echo $idx; ?>" class="hidden">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-0 bg-white">
                                <!-- Transcripción -->
                                <div class="border-r border-gray-200 p-6">
                                    <h4 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                                        <i class="fas fa-scroll text-blue-600"></i>Transcripción
                                    </h4>
                                    <div class="bg-gray-50 rounded p-4 max-h-96 overflow-y-auto text-sm text-gray-700 leading-relaxed">
                                        <?php echo nl2br(htmlspecialchars($trans['transcript'] ?? 'Sin transcripción')); ?>
                                    </div>
                                </div>
                                <!-- Resumen -->
                                <div class="p-6">
                                    <h4 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                                        <i class="fas fa-bookmark text-blue-600"></i>Resumen
                                    </h4>
                                    <div class="bg-gray-50 rounded p-4 max-h-96 overflow-y-auto text-sm text-gray-700 leading-relaxed">
                                        <?php echo nl2br(htmlspecialchars($trans['summary'] ?? 'Sin resumen')); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Análisis Detallado -->
            <?php if (!empty($analisis)): ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-table text-blue-600"></i>Análisis Detallado
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white sticky top-0">
                            <tr>
                                <th class="px-6 py-4 text-left font-bold">Criteria ID</th>
                                <th class="px-6 py-4 text-left font-bold">Result</th>
                                <th class="px-6 py-4 text-left font-bold">Rationale</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($analisis as $row): ?>
                            <tr class="<?php echo getResultColor($row['result']); ?> hover:opacity-75 transition duration-150">
                                <td class="px-6 py-4 font-mono font-semibold"><?php echo htmlspecialchars($row['criteria_id']); ?></td>
                                <td class="px-6 py-4">
                                    <span class="<?php echo getResultBadgeColor($row['result']); ?> px-3 py-1 rounded-full text-sm font-semibold">
                                        <?php echo htmlspecialchars($row['result']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm"><?php echo nl2br(htmlspecialchars($row['rationale'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleTranscript(idx) {
            const element = document.getElementById('transcript-' + idx);
            const arrow = document.getElementById('arrow-' + idx);
            element.classList.toggle('hidden');
            arrow.style.transform = element.classList.contains('hidden') ? 'rotate(0deg)' : 'rotate(90deg)';
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('F9CallID copiado: ' + text);
            });
        }
    </script>
</body>
</html>