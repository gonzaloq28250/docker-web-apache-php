<?php
/**
 * Script para crear índices de optimización del Dashboard NEQUI
 * Ejecutar este archivo desde el navegador: http://tu-sitio/crear_indices.php
 */

require_once 'config.php';

$pdo = getDBConnection();

$indices_creados = [];
$indices_errores = [];

// ============================================
// FUNCIONES HELPER
// ============================================

function crearIndice($pdo, $tabla, $nombre_indice, $sql_create, &$exitosos, &$errores) {
    try {
        // Primero verificar si el índice ya existe
        $stmt = $pdo->prepare("SHOW INDEX FROM ??
WHERE Key_name = ?");
        $stmt->execute([$tabla, $nombre_indice]);
        $existe = $stmt->fetch();

        if ($existe) {
            $exitosos[] = "✓ Índice '$nombre_indice' YA EXISTE en $tabla";
            return true;
        }

        // Crear el índice
        $pdo->exec($sql_create);
        $exitosos[] = "✓ Índice '$nombre_indice' creado en $tabla";
        return true;
    } catch (PDOException $e) {
        $errores[] = "✗ Error creando índice '$nombre_indice': " . $e->getMessage();
        return false;
    }
}

// ============================================
-- ÍNDICES PARA level_calls
-- ============================================

echo "<h1>Creando Índices de Optimización - Dashboard NEQUI</h1>";
echo "<h2>Tabla: level_calls</h2>";

// 1. Índice compuesto para fecha y estado
crearIndice($pdo,
    'level_calls',
    'idx_level_calls_fecha_estado',
    "CREATE INDEX idx_level_calls_fecha_estado ON level_calls(cliente, Estado, F9TimeStamp)",
    $indices_creados,
    $indices_errores
);

// 2. Índice para JOIN con level_conversations
crearIndice($pdo,
    'level_calls',
    'idx_level_calls_callid_cliente',
    "CREATE INDEX idx_level_calls_callid_cliente ON level_calls(F9CallID, cliente)",
    $indices_creados,
    $indices_errores
);

// 3. Índice para fechas y cliente
crearIndice($pdo,
    'level_calls',
    'idx_level_calls_fechas_cliente',
    "CREATE INDEX idx_level_calls_fechas_cliente ON level_calls(cliente, F9TimeStamp)",
    $indices_creados,
    $indices_errores
);

// 4. Índice para ANI
crearIndice($pdo,
    'level_calls',
    'idx_level_calls_ani',
    "CREATE INDEX idx_level_calls_ani ON level_calls(ANI)",
    $indices_creados,
    $indices_errores
);

// 5. Índice para DNIS
crearIndice($pdo,
    'level_calls',
    'idx_level_calls_dnis',
    "CREATE INDEX idx_level_calls_dnis ON level_calls(DNIS)",
    $indices_creados,
    $indices_errores
);

// 6. Índice para PROYECTO
crearIndice($pdo,
    'level_calls',
    'idx_level_calls_proyecto',
    "CREATE INDEX idx_level_calls_proyecto ON level_calls(PROYECTO)",
    $indices_creados,
    $indices_errores
);

// 7. Índice para ORDER BY timestamp
crearIndice($pdo,
    'level_calls',
    'idx_level_calls_timestamp_desc',
    "CREATE INDEX idx_level_calls_timestamp_desc ON level_calls(F9TimeStamp DESC)",
    $indices_creados,
    $indices_errores
);

// 8. Índice para F9CallID
crearIndice($pdo,
    'level_calls',
    'idx_level_calls_f9callid',
    "CREATE INDEX idx_level_calls_f9callid ON level_calls(F9CallID)",
    $indices_creados,
    $indices_errores
);

echo "<h2>Tabla: level_conversations</h2>";

// ============================================
-- ÍNDICES PARA level_conversations
-- ============================================

// 1. Índice para callid (JOIN con level_calls)
crearIndice($pdo,
    'level_conversations',
    'idx_level_conversations_callid',
    "CREATE INDEX idx_level_conversations_callid ON level_conversations(callid)",
    $indices_creados,
    $indices_errores
);

// 2. Índice para call_result
crearIndice($pdo,
    'level_conversations',
    'idx_level_conversations_call_result',
    "CREATE INDEX idx_level_conversations_call_result ON level_conversations(call_result)",
    $indices_creados,
    $indices_errores
);

// 3. Índice compuesto callid + call_result
crearIndice($pdo,
    'level_conversations',
    'idx_level_conversations_callid_result',
    "CREATE INDEX idx_level_conversations_callid_result ON level_conversations(callid, call_result)",
    $indices_creados,
    $indices_errores
);

// 4. Índice para customer (filtro por cliente en JOINs)
crearIndice($pdo,
    'level_conversations',
    'idx_level_conversations_customer',
    "CREATE INDEX idx_level_conversations_customer ON level_conversations(customer)",
    $indices_creados,
    $indices_errores
);

// 5. Índice compuesto customer + callid + call_result (ideal para JOINs con filtro de cliente)
crearIndice($pdo,
    'level_conversations',
    'idx_level_conv_cust_callid_result',
    "CREATE INDEX idx_level_conv_cust_callid_result ON level_conversations(customer, callid, call_result)",
    $indices_creados,
    $indices_errores
);

// ============================================
// RESULTADOS
// ============================================

echo "<div style='margin-top: 30px;'>";
echo "<h2>📊 Resultados</h2>";

if (!empty($indices_creados)) {
    echo "<h3 style='color: green;'>✓ Índices Procesados (" . count($indices_creados) . ")</h3>";
    echo "<ul style='font-family: monospace; font-size: 14px;'>";
    foreach ($indices_creados as $msg) {
        echo "<li style='color: green;'>$msg</li>";
    }
    echo "</ul>";
}

if (!empty($indices_errores)) {
    echo "<h3 style='color: red;'>✗ Errores (" . count($indices_errores) . ")</h3>";
    echo "<ul style='font-family: monospace; font-size: 14px;'>";
    foreach ($indices_errores as $msg) {
        echo "<li style='color: red;'>$msg</li>";
    }
    echo "</ul>";
}

// ============================================
-- VERIFICACIÓN
-- ============================================

echo "<h2>🔍 Verificación de Índices Creados</h2>";

echo "<h3>level_calls:</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr style='background: #f0f0f0;'><th>Table</th><th>Key_name</th><th>Column_name</th></tr>";

$stmt = $pdo->query("SHOW INDEX FROM level_calls");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['Key_name'] !== 'PRIMARY') {
        echo "<tr>";
        echo "<td>{$row['Table']}</td>";
        echo "<td>{$row['Key_name']}</td>";
        echo "<td>{$row['Column_name']}</td>";
        echo "</tr>";
    }
}
echo "</table>";

echo "<h3>level_conversations:</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr style='background: #f0f0f0;'><th>Table</th><th>Key_name</th><th>Column_name</th></tr>";

$stmt = $pdo->query("SHOW INDEX FROM level_conversations");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['Key_name'] !== 'PRIMARY') {
        echo "<tr>";
        echo "<td>{$row['Table']}</td>";
        echo "<td>{$row['Key_name']}</td>";
        echo "<td>{$row['Column_name']}</td>";
        echo "</tr>";
    }
}
echo "</table>";

echo "</div>";

// ============================================
-- SIGUIENTE PASO
-- ============================================

echo "<div style='margin-top: 30px; padding: 20px; background: #e8f4f8; border-radius: 5px;'>";
echo "<h2>🎯 Siguiente Paso: FASE 2</h2>";
echo "<p>Los índices han sido creados. Ahora puedes:</p>";
echo "<ol>";
echo "<li><strong>Probar el dashboard</strong> para notar la mejora en rendimiento</li>";
echo "<li><strong>Ejecutar 02 optimizar_queries.php</strong> para aplicar las optimizaciones de queries</li>";
echo "<li><strong>Monitorear</strong> con <code>SHOW PROCESSLIST;</code> para ver tiempos de query</li>";
echo "</ol>";
echo "<p><a href='dashboard_consolidado.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Ir al Dashboard →</a></p>";
echo "</div>";
?>