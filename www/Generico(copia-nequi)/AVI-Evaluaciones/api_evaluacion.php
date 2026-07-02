<?php
header('Content-Type: application/json');
require_once 'config.php';

$pdo = getDBConnection();
$clienteActual = $_POST['cliente'] ?? $_GET['cliente'] ?? CLIENTE_ACTUAL;
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get':
        getEvaluacion($pdo, $clienteActual);
        break;
    case 'save':
        saveEvaluacion($pdo, $clienteActual);
        break;
    case 'list':
        listEvaluaciones($pdo, $clienteActual);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
}

function getEvaluacion($pdo, $cliente) {
    $f9callid = $_GET['F9CallID'] ?? '';
    if (empty($f9callid)) {
        echo json_encode(['success' => false, 'error' => 'F9CallID requerido']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id, F9CallID, ANI, resultado, call_result_correcto, se_puede_mejorar, info_disponible_sa, observacion, fecha_evaluacion
        FROM level_transcripciones_evaluacion
        WHERE F9CallID = :f9callid AND cliente = :cliente
        LIMIT 1
    ");
    $stmt->execute([':f9callid' => $f9callid, ':cliente' => $cliente]);
    $evaluacion = $stmt->fetch();

    if ($evaluacion) {
        echo json_encode(['success' => true, 'data' => $evaluacion]);
    } else {
        echo json_encode(['success' => true, 'data' => null]);
    }
}

function saveEvaluacion($pdo, $clientePorDefecto) {
    $f9callid = $_POST['F9CallID'] ?? '';
    $ani = $_POST['ANI'] ?? null;
    $resultado = $_POST['resultado'] ?? '';
    $call_result_correcto = $_POST['call_result_correcto'] ?? null;
    $se_puede_mejorar = !empty($_POST['se_puede_mejorar']) ? 1 : 0;
    $info_disponible_sa = !empty($_POST['info_disponible_sa']) ? 1 : 0;
    $observacion = $_POST['observacion'] ?? '';

    if (empty($f9callid) || !in_array($resultado, ['pasa', 'no_pasa'])) {
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        return;
    }

    // Obtener el cliente real desde level_calls o avi_calls según el F9CallID
    $stmtCliente = $pdo->prepare("SELECT cliente FROM level_calls WHERE F9CallID = :f9callid LIMIT 1");
    $stmtCliente->execute([':f9callid' => $f9callid]);
    $rowCliente = $stmtCliente->fetch();
    $cliente = $rowCliente ? $rowCliente['cliente'] : $clientePorDefecto;
    $rowAVI = null;

    // Si no se encontró en level_calls, buscar en avi_calls
    if (!$rowCliente) {
        $stmtAVI = $pdo->prepare("SELECT cliente, ANI FROM avi_calls WHERE F9CallID = :f9callid LIMIT 1");
        $stmtAVI->execute([':f9callid' => $f9callid]);
        $rowAVI = $stmtAVI->fetch();
    }

    // Si pasa, el call_result_correcto se limpia
    if ($resultado === 'pasa') {
        $call_result_correcto = null;
    }

    if (empty($call_result_correcto)) {
        $call_result_correcto = null;
    }

    if (empty($ani)) {
        $ani = null;
    }

    // Obtener ANI desde level_calls o avi_calls si no se proporcionó
    if (empty($ani)) {
        if ($rowCliente) {
            $ani = $rowCliente['ANI'] ?? null;
        } elseif ($rowAVI) {
            $ani = $rowAVI['ANI'] ?? null;
        }
    }

    $stmtCheck = $pdo->prepare("
        SELECT id FROM level_transcripciones_evaluacion
        WHERE F9CallID = :f9callid AND cliente = :cliente
    ");
    $stmtCheck->execute([':f9callid' => $f9callid, ':cliente' => $cliente]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE level_transcripciones_evaluacion
            SET ANI = :ani, resultado = :resultado, call_result_correcto = :call_result_correcto,
                se_puede_mejorar = :se_puede_mejorar, info_disponible_sa = :info_disponible_sa,
                observacion = :observacion, fecha_evaluacion = NOW()
            WHERE F9CallID = :f9callid AND cliente = :cliente
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO level_transcripciones_evaluacion (F9CallID, ANI, resultado, call_result_correcto, se_puede_mejorar, info_disponible_sa, observacion, cliente, fecha_evaluacion)
            VALUES (:f9callid, :ani, :resultado, :call_result_correcto, :se_puede_mejorar, :info_disponible_sa, :observacion, :cliente, NOW())
        ");
    }

    $stmt->execute([
        ':f9callid' => $f9callid,
        ':ani' => $ani,
        ':resultado' => $resultado,
        ':call_result_correcto' => $call_result_correcto,
        ':se_puede_mejorar' => $se_puede_mejorar,
        ':info_disponible_sa' => $info_disponible_sa,
        ':observacion' => $observacion,
        ':cliente' => $cliente,
    ]);

    echo json_encode(['success' => true, 'message' => $existing ? 'Evaluación actualizada' : 'Evaluación guardada']);
}

function listEvaluaciones($pdo, $cliente) {
    $stmt = $pdo->prepare("
        SELECT F9CallID, ANI, resultado, call_result_correcto, se_puede_mejorar, info_disponible_sa, observacion, fecha_evaluacion
        FROM level_transcripciones_evaluacion
        WHERE cliente = :cliente
    ");
    $stmt->execute([':cliente' => $cliente]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $result[$row['F9CallID']] = $row;
    }

    echo json_encode(['success' => true, 'data' => $result]);
}
