<?php
$status = 'OK';
$phpVersion = phpversion();
$extensions = ['pdo_mysql', 'mysqli', 'mbstring', 'gd', 'intl', 'zip', 'soap', 'xsl'];
$loaded = [];
foreach ($extensions as $ext) {
    $loaded[$ext] = extension_loaded($ext);
}
$dbStatus = 'No configurado';
$dbHost = defined('DB_HOST') ? DB_HOST : null;
if ($dbHost) {
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $dbStatus = 'Conectado';
    } catch (Exception $e) {
        $dbStatus = 'Error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Docker Web - Status</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; color: #333; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
  .card { background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); max-width: 560px; width: 90%; }
  h1 { font-size: 24px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
  .badge { display: inline-block; font-size: 13px; padding: 3px 12px; border-radius: 20px; font-weight: 600; }
  .badge-ok { background: #d4edda; color: #155724; }
  .badge-err { background: #f8d7da; color: #721c24; }
  .subtitle { color: #666; margin-bottom: 24px; font-size: 14px; }
  .section { margin-bottom: 20px; }
  .section h2 { font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 8px; }
  .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
  .row:last-child { border: none; }
  .status-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
  .dot-ok { background: #28a745; }
  .dot-err { background: #dc3545; }
  .footer { margin-top: 24px; font-size: 12px; color: #aaa; text-align: center; }
  a { color: #0366d6; text-decoration: none; }
  a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="card">
  <h1>
    <?php if ($status === 'OK'): ?><span class="badge badge-ok">&#10003; Operativo</span><?php endif; ?>
  </h1>
  <p class="subtitle">Contenedor Apache <?= apache_get_version() ?> &mdash; PHP <?= $phpVersion ?></p>

  <div class="section">
    <h2>Extensiones PHP</h2>
    <?php foreach ($loaded as $ext => $ok): ?>
    <div class="row">
      <span><?= $ext ?></span>
      <span><span class="status-dot <?= $ok ? 'dot-ok' : 'dot-err' ?>"></span><?= $ok ? 'cargada' : 'falta' ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="section">
    <h2>Conexión MySQL (Azure)</h2>
    <div class="row">
      <span>Servidor</span>
      <span><?= $dbHost ? htmlspecialchars($dbHost) : '—' ?></span>
    </div>
    <div class="row">
      <span>Estado</span>
      <span><span class="status-dot <?= str_starts_with($dbStatus, 'Conectado') ? 'dot-ok' : 'dot-err' ?>"></span><?= htmlspecialchars($dbStatus) ?></span>
    </div>
  </div>

  <div class="footer">
    <a href="https://github.com/gonzaloq28250/docker-web-apache-php" target="_blank">docker-web-apache-php</a>
  </div>
</div>
</body>
</html>
