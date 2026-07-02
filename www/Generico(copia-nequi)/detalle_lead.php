
<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$pdo = new PDO("mysql:host=icqdbmysqlreports.mysql.database.azure.com;dbname=n8n_icq;charset=utf8mb4", "gonzaloq", '73ch$iCC');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$f9id = $_GET['F9CallID'] ?? '';

// 1. Datos de ResultadoPerfil
$stmt1 = $pdo->prepare("SELECT clave, valor FROM siigo_lead_data_v2 WHERE F9CallID = :f9id AND method = 'ResultadoPerfil'");
$stmt1->execute([':f9id' => $f9id]);
$resultadoPerfil = $stmt1->fetchAll(PDO::FETCH_ASSOC);

// Obtener F911DNIS
$stmt_dnis = $pdo->prepare("SELECT valor FROM siigo_lead_data_v2 WHERE F9CallID = :f9id AND clave = 'F911DNIS' LIMIT 1");
$stmt_dnis->execute([':f9id' => $f9id]);
$f911dnis = $stmt_dnis->fetchColumn();

// 2. Datos de eleven_n8n_t1
$stmt2 = $pdo->prepare("SELECT * FROM eleven_n8n_t1 WHERE from_number = :dnis");
$stmt2->execute([':dnis' => $f911dnis]);
$transcripciones = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Obtener ElevenConversationID
$conversationIDs = array_column($transcripciones, 'ElevenConversationID');

// 3. Datos de eleven_n8n_t1_analisis
$analisis = [];
if (!empty($conversationIDs)) {
    $in = str_repeat('?,', count($conversationIDs) - 1) . '?';
    $stmt3 = $pdo->prepare("SELECT * FROM eleven_n8n_t1_analisis WHERE ElevenConversationID IN ($in)");
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

function colorResult($result) {
    if ($result === 'success') return 'style="background-color:#c8e6c9"';
    if ($result === 'unknown') return 'style="background-color:#fff9c4"';
    if ($result === 'failure') return 'style="background-color:#ffcdd2"';
    return '';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Detalle de F9CallID <?= htmlspecialchars($f9id) ?></title>
    <style>
        body { font-family: Arial, sans-serif; }
        .section { margin-bottom: 40px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Detalle de F9CallID <?= htmlspecialchars($f9id) ?></h1>
    <p><a href="leads_dinamicos.php?<?= http_build_query($_GET) ?>"><button>Volver a la búsqueda</button></a></p>

    <div class="section">
        <h2>Resultado Perfil</h2>
        <table>
            <tr><th>Clave</th><th>Valor</th></tr>
            <?php foreach ($resultadoPerfil as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['clave']) ?></td>
                    <td><?= htmlspecialchars($row['valor']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="section">
        <h2>Transcripción</h2>
        <table>
            <tr>
                <!--<th>Timestamp</th>
				<th>Direction</th>
				<th>To</th>
				<th>From</th>-->
				<th>Transcript</th>
				<th>Summary</th>
				<!--<th>Conversation ID</th>-->
            </tr>
            <?php foreach ($transcripciones as $row): ?>
                <tr>
                    <!--<td><?= htmlspecialchars($row['timestamp']) ?></td>-->
                    <!--<td><?= htmlspecialchars($row['direction']) ?></td>-->
                    <!--<td><?= htmlspecialchars($row['to_number']) ?></td>-->
                    <!--<td><?= htmlspecialchars($row['from_number']) ?></td>-->
                    <td><?= nl2br(htmlspecialchars($row['transcript'])) ?></td>
                    <td><?= nl2br(htmlspecialchars($row['summary'])) ?></td>
                    <!--<td><?= htmlspecialchars($row['ElevenConversationID']) ?></td>-->
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="section">
        <h2>Análisis</h2>
        <table>
            <tr>
                <!--<th>Timestamp</th>-->
				<th>Criteria ID</th>
				<th>Result</th>
				<th>Rationale</th>
				<!--<th>Conversation ID</th>-->
            </tr>
            <?php foreach ($analisis as $row): ?>
                <tr>
                    <!--<td><?= htmlspecialchars($row['timestamp']) ?></td>-->
                    <td><?= htmlspecialchars($row['criteria_id']) ?></td>
                    <td <?= colorResult($row['result']) ?>><?= htmlspecialchars($row['result']) ?></td>
                    <td><?= nl2br(htmlspecialchars($row['rationale'])) ?></td>
                    <!--<td><?= htmlspecialchars($row['ElevenConversationID']) ?></td>-->
                </tr>
            <?php endforeach; ?>
        </table>
        <p><strong>Resumen:</strong><br>
            Total: <?= $total ?><br>
            Success: <?= $success ?> (<?= $total ? round($success * 100 / $total, 1) : 0 ?>%)<br>
            Unknown: <?= $unknown ?> (<?= $total ? round($unknown * 100 / $total, 1) : 0 ?>%)<br>
            Failure: <?= $failure ?> (<?= $total ? round($failure * 100 / $total, 1) : 0 ?>%)
        </p>
    </div>
</body>
</html>
