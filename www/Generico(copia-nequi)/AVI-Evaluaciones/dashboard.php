
<?php
// Cargar configuración
require_once 'config.php';

// Obtener conexión PDO
$pdo = getDBConnection();

// Usar cliente de la configuración
$inProgressStmt = $pdo->prepare("
    SELECT * FROM avi_calls
    WHERE Estado = 'IN-PROGRESS' AND cliente = :cliente
    ORDER BY F9TimeStamp DESC
");
$inProgressStmt->execute([':cliente' => CLIENTE_ACTUAL]);
$inProgress = $inProgressStmt->fetchAll();

$otrosStmt = $pdo->prepare("
    SELECT * FROM avi_calls
    WHERE Estado <> 'IN-PROGRESS'
    AND cliente = :cliente
    AND DATE(F9TimeStamp) = CURDATE()
    ORDER BY F9TimeStamp DESC
    LIMIT 100
");
$otrosStmt->execute([':cliente' => CLIENTE_ACTUAL]);
$otros = $otrosStmt->fetchAll();

// Total de llamadas Five9 (de avi_calls)
$totalFive9Stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_calls
    WHERE cliente = :cliente
    AND DATE(F9TimeStamp) = CURDATE()
");
$totalFive9Stmt->execute([':cliente' => CLIENTE_ACTUAL]);
$totalFive9 = $totalFive9Stmt->fetch()['total'];

// Total de llamadas AVI (de avi_call_costs)
$totalAVIStmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM avi_call_costs
    WHERE cliente = :cliente
    AND DATE(metadata_date_local) = CURDATE()
");
$totalAVIStmt->execute([':cliente' => CLIENTE_ACTUAL]);
$totalAVI = $totalAVIStmt->fetch()['total'];

// Breakdown de resultado_llamada (solo hoy)
$resultadoStmt = $pdo->prepare("
    SELECT
        valor as resultado,
        COUNT(DISTINCT F9CallID) as total
    FROM siigo_lead_data_v2
    WHERE method = 'ResultadoPerfil'
    AND clave = 'resultado_llamada'
    AND EXISTS (
        SELECT 1 FROM siigo_lead_data_v2 t
        WHERE t.F9CallID = siigo_lead_data_v2.F9CallID
        AND t.clave = 'F9TimeStamp'
        AND DATE(t.valor) = CURDATE()
    )
    AND EXISTS (
        SELECT 1 FROM siigo_lead_data_v2 c
        WHERE c.F9CallID = siigo_lead_data_v2.F9CallID
        AND c.clave = 'CLIENTE'
        AND c.valor = :cliente
    )
    GROUP BY valor
    ORDER BY total DESC
");
$resultadoStmt->execute([':cliente' => CLIENTE_ACTUAL]);
$resultados = $resultadoStmt->fetchAll();

// Calcular total de resultados
$totalResultados = array_sum(array_column($resultados, 'total'));

// Agregar categoría "Llamadas colgadas" (diferencia entre llamadas AVI y con resultado)
$clienteCuelga = $totalAVI - $totalResultados;
if ($clienteCuelga > 0) {
    $resultados[] = [
        'resultado' => 'Llamadas colgadas',
        'total' => $clienteCuelga
    ];
}

// Ordenar resultados de mayor a menor
usort($resultados, function($a, $b) {
    return $b['total'] - $a['total'];
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= DASHBOARD_TITLE ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6 flex flex-col md:flex-row justify-between items-center gap-4">
            <h1 class="text-3xl font-bold">Dashboard de Llamadas (Cliente: <?= CLIENTE_ACTUAL ?>)</h1>
            <div class="flex items-center gap-4">
                <button id="manualRefresh" class="bg-blue-500 hover:bg-blue-400 px-4 py-2 rounded-lg transition duration-200 flex items-center gap-2">
                    <i class="fas fa-sync-alt"></i> Refrescar
                </button>
                <label for="refreshRate" class="text-sm">Refrescar cada:</label>
                <select id="refreshRate" class="bg-white text-blue-800 rounded px-2 py-1">
                    <option value="2000">2 seg</option>
                    <option value="5000" selected>5 seg</option>
                    <option value="10000">10 seg</option>
                    <option value="15000">15 seg</option>
                    <option value="30000">30 seg</option>
                    <option value="45000">45 seg</option>
                    <option value="60000">60 seg</option>
                </select>
            </div>
        </div>
    </header>

    <!-- Main -->
    <main class="max-w-7xl mx-auto px-4 py-8 space-y-8">
        <!-- IN-PROGRESS -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-2xl font-bold text-blue-600 mb-4 flex items-center gap-2">
                <i class="fas fa-phone-alt"></i> Llamadas en Progreso
            </h2>
            <ul id="inProgressList" class="space-y-2">
                <?php if (!empty($inProgress)): ?>
                    <?php foreach ($inProgress as $row): ?>
                        <li class="border border-gray-200 rounded-lg p-4 hover:border-blue-300 transition">
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($row['cliente'] ?? '') ?></p>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($row['numero_contacto'] ?? '') ?> | <?= htmlspecialchars($row['PROYECTO'] ?? '') ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($row['F9TimeStamp'] ?? '') ?></p>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-gray-500">No hay llamadas en progreso para <?= CLIENTE_ACTUAL ?>.</p>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Últimos 100 Registros (mostrando 10 por página) -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-2xl font-bold text-gray-700 flex items-center gap-2">
                    <i class="fas fa-history"></i> Últimos Registros (Hoy)
                </h2>
                <div class="text-sm text-gray-600">
                    Mostrando <span id="currentPageInfo">1-10</span> de <span id="totalRecords">0</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white">
                        <tr>
                            <th class="px-4 py-2 text-left">ID</th>
                            <th class="px-4 py-2 text-left">Cliente</th>
                            <th class="px-4 py-2 text-left">Estado</th>
                            <th class="px-4 py-2 text-left">Fecha</th>
                            <th class="px-4 py-2 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="otrosList" class="divide-y divide-gray-200">
                        <!-- Los registros se cargarán via JavaScript -->
                    </tbody>
                </table>
            </div>
            <!-- Controles de paginación -->
            <div class="flex items-center justify-between mt-4 border-t pt-4">
                <button id="prevPage" onclick="changePage(-1)"
                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg transition duration-200 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-chevron-left"></i> Anterior
                </button>
                <div id="pageNumbers" class="flex gap-2">
                    <!-- Los números de página se generarán via JavaScript -->
                </div>
                <button id="nextPage" onclick="changePage(1)"
                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg transition duration-200 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    Siguiente <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>

        <!-- Breakdown Resultado de Llamada -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-2xl font-bold text-gray-700 mb-4 flex items-center gap-2">
                <i class="fas fa-phone-square-alt"></i> Resultado de Llamadas (Hoy)
            </h2>

            <?php
            $totalResultados = array_sum(array_column($resultados, 'total'));
            ?>

            <!-- Total -->
            <div class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Total Llamadas Five9 -->
                <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg p-4 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm opacity-90">Llamadas Five9</p>
                            <p class="text-4xl font-bold" id="totalFive9"><?= number_format($totalFive9) ?></p>
                        </div>
                        <i class="fas fa-phone text-4xl opacity-50"></i>
                    </div>
                </div>

                <!-- Total Llamadas AVI -->
                <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg p-4 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm opacity-90">Llamadas AVI</p>
                            <p class="text-4xl font-bold" id="totalAVI"><?= number_format($totalAVI) ?></p>
                        </div>
                        <i class="fas fa-phone-volume text-4xl opacity-50"></i>
                    </div>
                </div>
            </div>

            <div id="resultadoList" class="space-y-3">
                <?php if (!empty($resultados)):
                    foreach ($resultados as $row):
                        $porcentaje = $totalAVI > 0 ? round(($row['total'] / $totalAVI) * 100, 1) : 0;
                        $colorClass = 'bg-blue-500';

                        $resultadoLower = strtolower($row['resultado']);
                        if (strpos($resultadoLower, 'exitosa') !== false || strpos($resultadoLower, 'éxito') !== false) {
                            $colorClass = 'bg-green-500';
                        } elseif (strpos($resultadoLower, 'no contesta') !== false || strpos($resultadoLower, 'ocupado') !== false) {
                            $colorClass = 'bg-yellow-500';
                        } elseif (strpos($resultadoLower, 'rechazada') !== false || strpos($resultadoLower, 'fallida') !== false) {
                            $colorClass = 'bg-red-500';
                        }
                ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                        <div class="flex-1">
                            <span class="font-semibold text-gray-800"><?= htmlspecialchars($row['resultado']) ?></span>
                            <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                <div class="<?= $colorClass ?> h-2 rounded-full" style="width: <?= $porcentaje ?>%"></div>
                            </div>
                        </div>
                        <div class="ml-4 text-right flex items-center gap-3">
                            <div>
                                <p class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($row['total']) ?></p>
                                <p class="text-xs text-gray-500"><?= $porcentaje ?>%</p>
                            </div>
                            <button onclick="openResultadoModal('<?= htmlspecialchars($row['resultado'], ENT_QUOTES) ?>')"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition duration-200 text-xs flex items-center gap-1 whitespace-nowrap">
                                <i class="fas fa-list"></i> Ver Llamadas
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-4">No hay datos del día de hoy</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal para detalle de lead -->
    <div id="detailModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg w-full max-w-7xl h-5/6 flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800">Detalle de Lead</h3>
                <button onclick="closeDetailModal()" class="text-gray-500 hover:text-gray-700 text-2xl font-bold">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <iframe id="detailFrame" class="w-full h-full border-0"></iframe>
            </div>
        </div>
    </div>

    <!-- Modal para lista de llamadas por resultado -->
    <div id="resultadoModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-40 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg w-full max-w-6xl max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800">
                    Llamadas: <span id="resultadoModalTitle"></span>
                </h3>
                <button onclick="closeResultadoModal()" class="text-gray-500 hover:text-gray-700 text-2xl font-bold">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="flex-1 overflow-auto p-4">
                <div class="mb-3 text-sm text-gray-600">
                    Total: <span id="resultadoModalCount">0</span> llamadas
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white sticky top-0">
                            <tr>
                                <th class="px-4 py-2 text-left">F9CallID</th>
                                <th class="px-4 py-2 text-left">Cliente</th>
                                <th class="px-4 py-2 text-left">Estado</th>
                                <th class="px-4 py-2 text-left">Fecha</th>
                                <th class="px-4 py-2 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="resultadoModalTableBody" class="divide-y divide-gray-200">
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let refreshInterval = 5000; // valor inicial
let intervalId;
let otrosData = <?php echo json_encode($otros); ?>; // Almacena todos los registros cargados desde PHP
let currentPage = 1;
let recordsPerPage = 10;

// Función para obtener datos
function fetchData() {
    fetch('dashboard_data.php?cliente=<?= CLIENTE_ACTUAL ?>')
        .then(res => res.json())
        .then(data => {
            document.getElementById('inProgressList').innerHTML = data.inProgressHTML;
            // Almacenar los datos de otros en lugar de renderizar directamente
            otrosData = data.otrosData || [];
            renderOtrosTable();
            document.getElementById('resultadoList').innerHTML = data.resultadoHTML;
            document.getElementById('totalFive9').textContent = data.totalFive9HTML;
            document.getElementById('totalAVI').textContent = data.totalAVIHTML;
        })
        .catch(err => console.error('Error al actualizar:', err));
}

// Función para renderizar la tabla de otros con paginación
function renderOtrosTable() {
    const startIndex = (currentPage - 1) * recordsPerPage;
    const endIndex = startIndex + recordsPerPage;
    const pageData = otrosData.slice(startIndex, endIndex);

    let html = '';
    pageData.forEach(row => {
        const fecha = row.F9TimeStamp ? row.F9TimeStamp.split(' ')[0] : '';
        const detalleUrl = `detalle_lead_v2.php?F9CallID=${encodeURIComponent(row.F9CallID || '')}&fecha=${encodeURIComponent(fecha)}`;

        html += `<tr class='hover:bg-gray-100 transition'>
            <td class='px-4 py-2'>${escapeHtml(row.F9CallID || '')}</td>
            <td class='px-4 py-2'>${escapeHtml(row.cliente || '')}</td>
            <td class='px-4 py-2'>${escapeHtml(row.Estado || '')}</td>
            <td class='px-4 py-2'>${escapeHtml(row.F9TimeStamp || '')}</td>
            <td class='px-4 py-2 text-center'>
                <button onclick='openDetailModal("${escapeHtml(detalleUrl)}")'
                        class='bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg transition duration-200 text-xs flex items-center gap-1 mx-auto'>
                    <i class='fas fa-eye'></i> Ver Detalle
                </button>
            </td>
        </tr>`;
    });

    if (html === '') {
        html = `<tr><td colspan='5' class='px-4 py-8 text-center text-gray-500'>No hay registros disponibles</td></tr>`;
    }

    document.getElementById('otrosList').innerHTML = html;
    updatePaginationControls();
}

// Función auxiliar para escapar HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Función para actualizar los controles de paginación
function updatePaginationControls() {
    const totalPages = Math.ceil(otrosData.length / recordsPerPage);
    const startRecord = otrosData.length === 0 ? 0 : (currentPage - 1) * recordsPerPage + 1;
    const endRecord = Math.min(currentPage * recordsPerPage, otrosData.length);

    // Actualizar info de página
    document.getElementById('currentPageInfo').textContent = `${startRecord}-${endRecord}`;
    document.getElementById('totalRecords').textContent = otrosData.length;

    // Actualizar botones anterior/siguiente
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');

    prevBtn.disabled = currentPage === 1;
    nextBtn.disabled = currentPage === totalPages || totalPages === 0;

    // Generar números de página
    const pageNumbersDiv = document.getElementById('pageNumbers');
    let pageNumbersHTML = '';

    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            const activeClass = i === currentPage ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300';
            pageNumbersHTML += `<button onclick="goToPage(${i})"
                class="${activeClass} px-3 py-1 rounded transition duration-200">${i}</button>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            pageNumbersHTML += `<span class="px-2">...</span>`;
        }
    }

    pageNumbersDiv.innerHTML = pageNumbersHTML;
}

// Función para cambiar de página
function changePage(direction) {
    const totalPages = Math.ceil(otrosData.length / recordsPerPage);
    const newPage = currentPage + direction;

    if (newPage >= 1 && newPage <= totalPages) {
        currentPage = newPage;
        renderOtrosTable();
    }
}

// Función para ir a una página específica
function goToPage(page) {
    currentPage = page;
    renderOtrosTable();
}

// Función para iniciar el auto-refresh
function startAutoRefresh() {
    if (intervalId) clearInterval(intervalId);
    intervalId = setInterval(fetchData, refreshInterval);
}

// Renderizar la tabla inicial con los datos cargados desde PHP
renderOtrosTable();

// Ejecutar la primera carga y activar el auto-refresh
fetchData();
startAutoRefresh();

// Cambiar intervalo dinámicamente
document.getElementById('refreshRate').addEventListener('change', (e) => {
    refreshInterval = parseInt(e.target.value);
    startAutoRefresh();
});

// Botón refrescar sin recargar la página
document.getElementById('manualRefresh').addEventListener('click', fetchData);

// Funciones para el modal de detalle
function openDetailModal(url) {
    const modal = document.getElementById('detailModal');
    const iframe = document.getElementById('detailFrame');
    iframe.src = url;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeDetailModal() {
    const modal = document.getElementById('detailModal');
    const iframe = document.getElementById('detailFrame');
    modal.classList.add('hidden');
    iframe.src = '';
    document.body.style.overflow = 'auto';
}

// Cerrar modal con tecla ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDetailModal();
    }
});

// Cerrar modal al hacer clic fuera del contenido
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target.id === 'detailModal') {
        closeDetailModal();
    }
});

// Funciones para el modal de llamadas por resultado
function openResultadoModal(resultado) {
    const modal = document.getElementById('resultadoModal');
    document.getElementById('resultadoModalTitle').textContent = resultado;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    // Cargar las llamadas para este resultado
    loadResultadoLlamadas(resultado);
}

function closeResultadoModal() {
    const modal = document.getElementById('resultadoModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
}

function loadResultadoLlamadas(resultado) {
    const tableBody = document.getElementById('resultadoModalTableBody');
    tableBody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Cargando...</td></tr>';

    fetch(`get_llamadas_by_resultado.php?resultado=${encodeURIComponent(resultado)}&cliente=<?= CLIENTE_ACTUAL ?>`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.llamadas.length > 0) {
                document.getElementById('resultadoModalCount').textContent = data.llamadas.length;

                let html = '';
                data.llamadas.forEach(row => {
                    const fecha = row.F9TimeStamp ? row.F9TimeStamp.split(' ')[0] : '';
                    const detalleUrl = `detalle_lead_v2.php?F9CallID=${encodeURIComponent(row.F9CallID || '')}&fecha=${encodeURIComponent(fecha)}`;

                    html += `<tr class='hover:bg-gray-100 transition'>
                        <td class='px-4 py-2'>${escapeHtml(row.F9CallID || '')}</td>
                        <td class='px-4 py-2'>${escapeHtml(row.cliente || '')}</td>
                        <td class='px-4 py-2'>${escapeHtml(row.Estado || '')}</td>
                        <td class='px-4 py-2'>${escapeHtml(row.F9TimeStamp || '')}</td>
                        <td class='px-4 py-2 text-center'>
                            <button onclick='openDetailModalFromResultado("${escapeHtml(detalleUrl)}")'
                                    class='bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg transition duration-200 text-xs flex items-center gap-1 mx-auto'>
                                <i class='fas fa-eye'></i> Ver Detalle
                            </button>
                        </td>
                    </tr>`;
                });
                tableBody.innerHTML = html;
            } else {
                tableBody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No hay llamadas con este resultado</td></tr>';
                document.getElementById('resultadoModalCount').textContent = '0';
            }
        })
        .catch(err => {
            console.error('Error al cargar llamadas:', err);
            tableBody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-red-500">Error al cargar las llamadas</td></tr>';
        });
}

function openDetailModalFromResultado(url) {
    // Cerrar el modal de resultados primero
    closeResultadoModal();
    // Abrir el modal de detalle
    setTimeout(() => openDetailModal(url), 300);
}

// Cerrar modal de resultados con tecla ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const resultadoModal = document.getElementById('resultadoModal');
        if (!resultadoModal.classList.contains('hidden')) {
            closeResultadoModal();
        }
    }
});

// Cerrar modal de resultados al hacer clic fuera del contenido
document.getElementById('resultadoModal').addEventListener('click', function(e) {
    if (e.target.id === 'resultadoModal') {
        closeResultadoModal();
    }
});
</script>
</body>
</html>
