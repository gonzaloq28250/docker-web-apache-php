<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>

<?php
$pdo = new PDO("mysql:host=icqdbmysqlreports.mysql.database.azure.com;dbname=n8n_icq;charset=utf8mb4", "gonzaloq", '73ch$iCC');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Obtener claves únicas
$keys_stmt = $pdo->query("SELECT DISTINCT clave FROM siigo_lead_data_v2 WHERE method = 'Insert Call'");
$keys = $keys_stmt->fetchAll(PDO::FETCH_COLUMN);

// Construir filtros desde formulario
$where = "WHERE method = 'Insert Call'";
$params = [];

//if (!empty($_GET['fecha'])) {
//    $where .= " AND valor = :fecha AND clave = 'F9TimeStamp'";
//    $params[':fecha'] = $_GET['fecha'];
//}
if (!empty($_GET['fecha'])) {
    $where .= " AND EXISTS (
        SELECT 1 FROM siigo_lead_data_v2 f
        WHERE f.F9CallID = d.F9CallID
          AND f.clave = 'F9TimeStamp'
          AND DATE(f.valor) = :fecha
    )";
    $params[':fecha'] = $_GET['fecha'];
}
if (!empty($_GET['proyecto'])) {
    $where .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 p WHERE p.F9CallID = d.F9CallID AND p.clave = 'PROYECTO' AND p.valor = :proyecto)";
    $params[':proyecto'] = $_GET['proyecto'];
}
if (!empty($_GET['ani'])) {
    $where .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 a WHERE a.F9CallID = d.F9CallID AND a.clave = 'ANI' AND a.valor = :ani)";
    $params[':ani'] = $_GET['ani'];
}
if (!empty($_GET['dnis'])) {
    $where .= " AND EXISTS (SELECT 1 FROM siigo_lead_data_v2 n WHERE n.F9CallID = d.F9CallID AND n.clave = 'DNIS' AND n.valor = :dnis)";
    $params[':dnis'] = $_GET['dnis'];
}

// Obtener datos
$sql = "SELECT F9CallID, clave, valor FROM siigo_lead_data_v2 d $where ORDER BY F9CallID";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar datos por F9CallID
$data = [];
foreach ($rows as $row) {
    $data[$row['F9CallID']][$row['clave']] = $row['valor'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Leads Dinámicos</title>
    <style>
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Leads Insert Call</h1>
    <form method="get">
        Fecha (F9TimeStamp): <input type="text" name="fecha" value="<?= htmlspecialchars($_GET['fecha'] ?? '') ?>">
        Proyecto: <input type="text" name="proyecto" value="<?= htmlspecialchars($_GET['proyecto'] ?? '') ?>">
        ANI: <input type="text" name="ani" value="<?= htmlspecialchars($_GET['ani'] ?? '') ?>">
        DNIS: <input type="text" name="dnis" value="<?= htmlspecialchars($_GET['dnis'] ?? '') ?>">
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <tr>
            <th>F9CallID</th>
            <?php foreach ($keys as $key): ?>
                <th><?= htmlspecialchars($key) ?></th>
            <?php endforeach; ?>
        </tr>
        <?php foreach ($data as $f9id => $values): ?>
        <tr>
          
			<td>
			<a href="detalle_lead.php?F9CallID=<?= urlencode($f9id) ?>&fecha=<?= urlencode($_GET['fecha'] ?? '') ?>&proyecto=<?= urlencode($_GET['proyecto'] ?? '') ?>&ani=<?= urlencode($_GET['ani'] ?? '') ?>&dnis=<?= urlencode($_GET['dnis'] ?? '') ?>">
			<?= htmlspecialchars($f9id) ?>
			</a>
			</td>
			
            <?php foreach ($keys as $key): ?>
                <td><?= htmlspecialchars($values[$key] ?? '') ?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
