<?php
require_once 'config.php';

$checks = [];
$ok = true;

// PHP version
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks[] = ['PHP 8.0+', PHP_VERSION, $phpOk];
if (!$phpOk) $ok = false;

// Extensions
foreach (['pdo_mysql', 'curl', 'json', 'mbstring', 'dom'] as $ext) {
    $has = extension_loaded($ext);
    $checks[] = ["Extension: $ext", $has ? 'OK' : 'Missing', $has];
    if (!$has) $ok = false;
}

// Writable folders
foreach (['uploads', 'assets', 'logs'] as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) @mkdir($path, 0755, true);
    $w = is_dir($path) && is_writable($path);
    $checks[] = ["Writable: /$dir", $w ? 'OK' : 'Not writable', $w];
    if (!$w) $ok = false;
}

// Config local
$hasLocal = is_readable(__DIR__ . '/config.local.php');
$checks[] = ['config.local.php', $hasLocal ? 'Found' : 'Not created (use API Keys page)', $hasLocal || hasChatGPT()];
$checks[] = ['ChatGPT API Key', hasChatGPT() ? 'Configured' : 'Missing — API Keys page', hasChatGPT()];

// Database
$dbOk = false;
$dbMsg = '';
try {
    $db = getDB();
    $db->query('SELECT 1');
    $dbOk = true;
    $dbMsg = 'Connected to ' . DB_NAME;

    $tables = ['users', 'projects', 'social_accounts', 'project_meta', 'backlinks', 'keywords', 'seo_reports', 'backlink_queue'];
    foreach ($tables as $t) {
        $db->query("SELECT 1 FROM `$t` LIMIT 1");
        $checks[] = ["Table: $t", 'OK', true];
    }
} catch (Throwable $e) {
    $dbMsg = $e->getMessage();
    $checks[] = ['Database', $dbMsg, false];
    $ok = false;
}

$siteUrl = SITE_URL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Setup - SEO 80/20</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:720px;">
  <div class="card shadow">
    <div class="card-header bg-<?= $ok && $dbOk ? 'success' : 'warning' ?> text-white">
      <h4 class="mb-0"><i class="fas fa-cog me-2"></i>SEO System — Setup Check</h4>
    </div>
    <div class="card-body">
      <p><strong>Detected URL:</strong> <code><?= clean($siteUrl) ?></code></p>
      <p class="text-muted small">Menu links use relative paths. Login redirects use this URL.</p>

      <table class="table table-sm">
        <thead><tr><th>Check</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($checks as $c): ?>
          <tr>
            <td><?= clean($c[0]) ?></td>
            <td><code class="small"><?= clean($c[1]) ?></code></td>
            <td><?= $c[2] ? '✅' : '❌' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (!$dbOk): ?>
      <div class="alert alert-danger">
        <h6>Database fix:</h6>
        <ol class="mb-0 small">
          <li>Start Apache + MySQL in XAMPP/WAMP</li>
          <li>phpMyAdmin → Import → <code>database.sql</code></li>
          <li>Correct DB_HOST (e.g. 127.0.0.1:3307), DB_USER, DB_PASS in config.php</li>
        </ol>
      </div>
      <?php endif; ?>

      <div class="d-flex gap-2 flex-wrap">
        <?php if (isset($_SESSION['user_id'])): ?>
          <a href="dashboard.php" class="btn btn-primary">Dashboard</a>
          <a href="api-setup.php" class="btn btn-outline-primary">API Keys</a>
        <?php else: ?>
          <a href="index.php" class="btn btn-primary">Login</a>
          <a href="register.php" class="btn btn-outline-secondary">Register</a>
        <?php endif; ?>
      </div>

      <hr>
      <h6>To run project in XAMPP:</h6>
      <ol class="small text-muted">
        <li>Copy Folder: <code>C:\xampp\htdocs\seo-system\</code> (or create shortcut in htdocs)</li>
        <li>Browser: <code>http://localhost/seo-system/setup.php</code></li>
        <li>Register → API Keys → Add Project → Run SEO</li>
      </ol>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
</body>
</html>
