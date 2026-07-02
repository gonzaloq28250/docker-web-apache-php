<?php
// Configuración para Reporte de Concurrencia
define('DB_HOST', 'icqdbmysqlreports.mysql.database.azure.com');
define('DB_NAME', 'n8n_icq');
define('DB_USER', 'gonzaloq');
define('DB_PASS', '73ch$iCC');
define('DB_CHARSET', 'utf8mb4');

// Catálogo de clientes seleccionables (ALL combina todos)
define('CLIENTE_ACTUAL', 'ALL');
define('CLIENTES_DISPONIBLES', [
    'AVI-Five9-All'  => 'ALL',
    'AVI-Five9'      => 'NEQUI',
    'AVI-Five9-v2'   => 'NEQUI2',
]);

/**
 * Obtiene la condición SQL y parámetros para filtrar por cliente(s).
 * Si el cliente es 'ALL', devuelve IN(...) con todos los valores reales.
 */
function buildClienteCondition($clienteActual, $alias = '') {
    $prefix = $alias ? "{$alias}." : '';
    if ($clienteActual === 'ALL') {
        $valores = array_values(array_filter(CLIENTES_DISPONIBLES, function($v) { return $v !== 'ALL'; }));
        $placeholders = [];
        $params = [];
        foreach ($valores as $i => $v) {
            $ph = ":c_{$i}";
            $placeholders[] = $ph;
            $params[$ph] = $v;
        }
        return [
            'sql'    => "{$prefix}cliente IN (" . implode(',', $placeholders) . ')',
            'params' => $params,
        ];
    }
    return [
        'sql'    => "{$prefix}cliente = :cliente",
        'params' => [':cliente' => $clienteActual],
    ];
}

function getDBConnection() {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        die(json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]));
    }
}
