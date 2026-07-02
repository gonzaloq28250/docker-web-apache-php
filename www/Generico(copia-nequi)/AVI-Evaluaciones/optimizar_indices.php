<?php
require_once 'config.php';

$pdo = getDBConnection();

echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>";
echo "<script src='https://cdn.tailwindcss.com'></script>";
echo "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>";
echo "<title>Optimización de Índices</title></head><body class='bg-gray-50 min-h-screen'>";
echo "<div class='max-w-4xl mx-auto px-4 py-8'>";
echo "<div class='bg-white rounded-lg shadow-md p-8'>";
echo "<h1 class='text-2xl font-bold text-gray-800 mb-6 flex items-center gap-3'>";
echo "<i class='fas fa-bolt text-yellow-500'></i> Optimización de Índices</h1>";

$success = [];
$errors = [];

function addIndex($pdo, $table, $name, $sql, &$success, &$errors) {
    // Check if index already exists
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute(['n8n_icq', $table, $name]);
    if ($stmt->fetch()) {
        $success[] = "✓ Índice '$name' ya existe en $table";
        return;
    }
    try {
        $pdo->exec($sql);
        $success[] = "✓ Índice '$name' creado en $table";
    } catch (PDOException $e) {
        $errors[] = "✗ Error creando '$name' en $table: " . $e->getMessage();
    }
}

// ============================================
// 1. level_transcripciones_evaluacion (CRÍTICO para dashboard)
// ============================================
echo "<h2 class='text-lg font-bold text-gray-700 mt-6 mb-3'>1. level_transcripciones_evaluacion</h2>";

addIndex($pdo, 'level_transcripciones_evaluacion', 'idx_cliente_fecha',
    "CREATE INDEX idx_cliente_fecha ON level_transcripciones_evaluacion(cliente, fecha_evaluacion)",
    $success, $errors);

addIndex($pdo, 'level_transcripciones_evaluacion', 'idx_cliente_f9id',
    "CREATE INDEX idx_cliente_f9id ON level_transcripciones_evaluacion(cliente, F9CallID)",
    $success, $errors);

// ============================================
// 2. avi_calls - índice compuesto cliente+F9TimeStamp
// ============================================
echo "<h2 class='text-lg font-bold text-gray-700 mt-6 mb-3'>2. avi_calls</h2>";

addIndex($pdo, 'avi_calls', 'idx_avicalls_cliente_fecha',
    "CREATE INDEX idx_avicalls_cliente_fecha ON avi_calls(cliente, F9TimeStamp)",
    $success, $errors);

addIndex($pdo, 'avi_calls', 'idx_avicalls_cliente_fecha_dnis',
    "CREATE INDEX idx_avicalls_cliente_fecha_dnis ON avi_calls(cliente, F9TimeStamp, DNIS)",
    $success, $errors);

// ============================================
// 3. avi_call_costs - índice compuesto f9_call_id + conversation_id
// ============================================
echo "<h2 class='text-lg font-bold text-gray-700 mt-6 mb-3'>3. avi_call_costs</h2>";

addIndex($pdo, 'avi_call_costs', 'idx_avicosts_f9id_conv',
    "CREATE INDEX idx_avicosts_f9id_conv ON avi_call_costs(f9_call_id, conversation_id)",
    $success, $errors);

// ============================================
// 4. eleven_n8n_t1 - columna generada has_transcript
// ============================================
echo "<h2 class='text-lg font-bold text-gray-700 mt-6 mb-3'>4. eleven_n8n_t1 (columna generada + índice)</h2>";

// Check if column already exists
$stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
$stmt->execute(['n8n_icq', 'eleven_n8n_t1', 'has_transcript']);
if (!$stmt->fetch()) {
    try {
        $pdo->exec("ALTER TABLE eleven_n8n_t1 ADD COLUMN has_transcript TINYINT(1) GENERATED ALWAYS AS (IF(transcript IS NOT NULL AND transcript != '', 1, 0)) STORED");
        $success[] = "✓ Columna 'has_transcript' creada en eleven_n8n_t1";
    } catch (PDOException $e) {
        $errors[] = "✗ Error creando columna has_transcript: " . $e->getMessage();
    }
} else {
    $success[] = "✓ Columna 'has_transcript' ya existe en eleven_n8n_t1";
}

addIndex($pdo, 'eleven_n8n_t1', 'idx_eleven_has_transcript',
    "CREATE INDEX idx_eleven_has_transcript ON eleven_n8n_t1(has_transcript)",
    $success, $errors);

addIndex($pdo, 'eleven_n8n_t1', 'idx_eleven_conv_has_transcript',
    "CREATE INDEX idx_eleven_conv_has_transcript ON eleven_n8n_t1(ElevenConversationID, has_transcript)",
    $success, $errors);

// ============================================
// 5. siigo_lead_data_v2 - índice cubridor para call_result
// ============================================
echo "<h2 class='text-lg font-bold text-gray-700 mt-6 mb-3'>5. siigo_lead_data_v2</h2>";

addIndex($pdo, 'siigo_lead_data_v2', 'idx_siigo_method_clave_valor_f9id',
    "CREATE INDEX idx_siigo_method_clave_valor_f9id ON siigo_lead_data_v2(method, clave, valor(50), F9CallID)",
    $success, $errors);

// ============================================
// Resultados
// ============================================
echo "<div class='mt-8 space-y-2'>";
foreach ($success as $msg) {
    echo "<div class='bg-green-100 border-l-4 border-green-500 text-green-800 p-3 rounded text-sm'>$msg</div>";
}
foreach ($errors as $msg) {
    echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-800 p-3 rounded text-sm'>$msg</div>";
}
echo "</div>";

echo "<div class='mt-8 flex gap-3'>";
echo "<a href='dashboard_evaluacion.php' class='bg-teal-600 hover:bg-teal-700 text-white px-6 py-3 rounded-lg font-bold flex items-center gap-2'>";
echo "<i class='fas fa-arrow-right'></i> Ir al Dashboard</a>";
echo "<a href='evaluacion_transcripciones.php' class='bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-3 rounded-lg font-bold flex items-center gap-2'>";
echo "<i class='fas fa-clipboard-check'></i> Evaluar Transcripciones</a>";
echo "</div>";

echo "</div></div></body></html>";
