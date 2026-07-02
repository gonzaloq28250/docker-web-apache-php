<?php
require_once 'config.php';

echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>";
echo "<script src='https://cdn.tailwindcss.com'></script>";
echo "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>";
echo "<title>Crear Tabla Evaluación</title></head><body class='bg-gray-50 min-h-screen'>";
echo "<div class='max-w-4xl mx-auto px-4 py-12'>";
echo "<div class='bg-white rounded-lg shadow-md p-8'>";
echo "<h1 class='text-2xl font-bold text-gray-800 mb-6 flex items-center gap-3'>";
echo "<i class='fas fa-database text-teal-600'></i> Crear Tabla de Evaluaciones</h1>";

try {
    $pdo = getDBConnection();

    $sql = "
        CREATE TABLE IF NOT EXISTS level_transcripciones_evaluacion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            F9CallID VARCHAR(255) NOT NULL,
            ANI VARCHAR(255) DEFAULT NULL,
            resultado ENUM('pasa', 'no_pasa') NOT NULL,
            call_result_correcto VARCHAR(255) DEFAULT NULL,
            observacion TEXT,
            fecha_evaluacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            cliente VARCHAR(50) NOT NULL DEFAULT 'NEQUI',
            INDEX idx_f9callid (F9CallID),
            INDEX idx_cliente (cliente),
            INDEX idx_fecha_evaluacion (fecha_evaluacion),
            UNIQUE KEY uk_f9callid_cliente (F9CallID, cliente)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $pdo->exec($sql);

    // Agregar columnas faltantes si la tabla ya existía sin ellas
    $alterQueries = [
        "ALTER TABLE level_transcripciones_evaluacion ADD COLUMN call_result_correcto VARCHAR(255) DEFAULT NULL AFTER resultado",
        "ALTER TABLE level_transcripciones_evaluacion ADD COLUMN ANI VARCHAR(255) DEFAULT NULL AFTER F9CallID",
    ];
    foreach ($alterQueries as $q) {
        try { $pdo->exec($q); } catch (PDOException $e) { /* columna ya existe */ }
    }

    echo "<div class='bg-green-100 border-l-4 border-green-500 text-green-800 p-4 rounded-lg mb-6 flex items-center gap-3'>";
    echo "<i class='fas fa-check-circle text-2xl'></i>";
    echo "<div><strong>Tabla creada/actualizada exitosamente:</strong> level_transcripciones_evaluacion</div></div>";

    // Verificar la tabla
    $stmt = $pdo->query("SHOW CREATE TABLE level_transcripciones_evaluacion");
    $row = $stmt->fetch();
    echo "<div class='bg-gray-100 rounded-lg p-4 mb-6'>";
    echo "<h3 class='font-bold text-gray-700 mb-2'>Estructura de la tabla:</h3>";
    echo "<pre class='text-xs overflow-x-auto'>" . htmlspecialchars($row['Create Table']) . "</pre>";
    echo "</div>";

    echo "<div class='flex gap-3'>";
    echo "<a href='evaluacion_transcripciones.php' class='bg-teal-600 hover:bg-teal-700 text-white px-6 py-3 rounded-lg font-bold flex items-center gap-2'>";
    echo "<i class='fas fa-arrow-right'></i> Ir a Evaluación de Transcripciones</a>";
    echo "<a href='dashboard_consolidado.php' class='bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-3 rounded-lg font-bold flex items-center gap-2'>";
    echo "<i class='fas fa-chart-pie'></i> Volver al Dashboard</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-800 p-4 rounded-lg mb-6 flex items-center gap-3'>";
    echo "<i class='fas fa-times-circle text-2xl'></i>";
    echo "<div><strong>Error al crear la tabla:</strong> " . htmlspecialchars($e->getMessage()) . "</div></div>";
}

echo "</div></div></body></html>";
