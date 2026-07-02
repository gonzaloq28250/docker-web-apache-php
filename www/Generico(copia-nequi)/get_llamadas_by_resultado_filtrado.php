<?php
// Cargar configuración
require_once 'config.php';

// Obtener conexión PDO
$pdo = getDBConnection();

$resultado = $_GET['resultado'] ?? '';
$fechaDesde = $_GET['fecha_desde'] ?? '';
$fechaHasta = $_GET['fecha_hasta'] ?? '';
$proyecto = $_GET['proyecto'] ?? '';
$ani = $_GET['ani'] ?? '';

if (empty($resultado)) {
    echo json_encode(['success' => false, 'error' => 'Resultado no especificado']);
    exit;
}

// Construir WHERE clause base
$whereBase = "WHERE a.cliente = :cliente";
$params = [':cliente' => CLIENTE_ACTUAL];

// Aplicar filtros de fecha
if (!empty($fechaDesde) && !empty($fechaHasta)) {
    $whereBase .= " AND DATE(a.F9TimeStamp) BETWEEN :fecha_desde AND :fecha_hasta";
    $params[':fecha_desde'] = $fechaDesde;
    $params[':fecha_hasta'] = $fechaHasta;
} elseif (!empty($fechaDesde)) {
    $whereBase .= " AND DATE(a.F9TimeStamp) >= :fecha_desde";
    $params[':fecha_desde'] = $fechaDesde;
} elseif (!empty($fechaHasta)) {
    $whereBase .= " AND DATE(a.F9TimeStamp) <= :fecha_hasta";
    $params[':fecha_hasta'] = $fechaHasta;
}

// Aplicar filtro de proyecto
if (!empty($proyecto)) {
    $whereBase .= " AND a.PROYECTO = :proyecto";
    $params[':proyecto'] = $proyecto;
}

// Si el resultado es "Llamadas colgadas", buscar llamadas AVI sin resultado
if ($resultado === 'Llamadas colgadas') {
    // Construir WHERE para avi_call_costs
    $whereAVI = "WHERE a.cliente = :cliente";
    $paramsAVI = [':cliente' => CLIENTE_ACTUAL];

    // Aplicar filtros de fecha
    if (!empty($fechaDesde) && !empty($fechaHasta)) {
        $whereAVI .= " AND DATE(a.metadata_date_local) BETWEEN :fecha_desde AND :fecha_hasta";
        $paramsAVI[':fecha_desde'] = $fechaDesde;
        $paramsAVI[':fecha_hasta'] = $fechaHasta;
    } elseif (!empty($fechaDesde)) {
        $whereAVI .= " AND DATE(a.metadata_date_local) >= :fecha_desde";
        $paramsAVI[':fecha_desde'] = $fechaDesde;
    } elseif (!empty($fechaHasta)) {
        $whereAVI .= " AND DATE(a.metadata_date_local) <= :fecha_hasta";
        $paramsAVI[':fecha_hasta'] = $fechaHasta;
    }

    // Aplicar filtro de proyecto
    if (!empty($proyecto)) {
        $whereAVI .= " AND a.PROYECTO = :proyecto";
        $paramsAVI[':proyecto'] = $proyecto;
    }

    // Construir WHERE adicional para ANI si existe
    $whereANI = '';
    if (!empty($ani)) {
        $whereANI = " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 ani WHERE ani.F9CallID = ac.F9CallID AND ani.clave = 'ANI' AND ani.valor = :ani)";
        $paramsAVI[':ani'] = $ani;
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT ac.*
        FROM avi_calls ac
        INNER JOIN avi_call_costs a ON ac.F9CallID = a.F9CallID
        $whereAVI
        AND NOT EXISTS (
            SELECT 1 FROM siigo_lead_data_v2 s
            WHERE s.F9CallID = ac.F9CallID
            AND s.method = 'ResultadoPerfil'
            AND s.clave = 'resultado_llamada'
        )
        $whereANI
        ORDER BY ac.F9TimeStamp DESC
    ");
    $stmt->execute($paramsAVI);
} else {
    // Buscar llamadas con el resultado específico
    $whereANI = '';
    if (!empty($ani)) {
        $whereANI = " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 ani WHERE ani.F9CallID = s.F9CallID AND ani.clave = 'ANI' AND ani.valor = :ani)";
        $params[':ani'] = $ani;
    }

    $params[':resultado'] = $resultado;

    $stmt = $pdo->prepare("
        SELECT DISTINCT a.*
        FROM avi_calls a
        INNER JOIN siigo_lead_data_v2 s ON a.F9CallID = s.F9CallID
        $whereBase
        AND s.method = 'ResultadoPerfil'
        AND s.clave = 'resultado_llamada'
        AND s.valor = :resultado
        $whereANI
        ORDER BY a.F9TimeStamp DESC
    ");
    $stmt->execute($params);
}

$llamadas = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'llamadas' => $llamadas,
    'total' => count($llamadas)
]);
