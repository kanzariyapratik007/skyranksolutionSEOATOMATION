<?php
require_once 'config.php';
requireMenuPermission('auto-schedule');

$db = getDB();

// Auto-add auto_schedule column if missing
try {
    $db->exec("ALTER TABLE projects ADD COLUMN auto_schedule TINYINT(1) DEFAULT 1");
} catch (PDOException $e) { /* already exists */ }

// Handle direct CSV download
if (isset($_GET['download'])) {
    $date = preg_replace('/[^0-9\-]/', '', $_GET['download']);
    $file = __DIR__ . '/logs/daily-report-' . $date . '.csv';
    if (file_exists($file)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="SEO-Daily-Report-' . $date . '.csv"');
        header('Pragma: no-cache');
        readfile($file);
        exit;
    }
    // Generate on-demand for today
    if ($date === date('Y-m-d')) {
        if (!defined('RUNNING_AS_CRON')) define('RUNNING_AS_CRON', true);
        require_once __DIR__ . '/auto-schedule.php';
        if (file_exists($file)) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="SEO-Daily-Report-' . $date . '.csv"');
            readfile($file);
            exit;
        }
    }
    die('Report not found for ' . $date);
}

// Handle toggle enable/disable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle'])) {
    $pid    = (int)$_POST['project_id'];
    $status = (int)$_POST['status'];
    $db->prepare("UPDATE projects SET auto_schedule=? WHERE id=? AND user_id=?")
       ->execute([$status, $pid, $_SESSION['user_id']]);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle test run
$testOutput = '';
if (isset($_GET['test'])) {
    ob_start();
    if (!defined('RUNNING_AS_CRON')) define('RUNNING_AS_CRON', true);
    include __DIR__ . '/auto-schedule.php';
    $testOutput = ob_get_clean();
}

$projects = $db->prepare("SELECT * FROM projects WHERE user_id=? ORDER BY id");
$projects->execute([$_SESSION['user_id']]);
$projects = $projects->fetchAll();

$accounts = $db->query("SELECT platform, COUNT(*) as cnt FROM social_accounts WHERE status='active' GROUP BY platform")->fetchAll();
$enabledCount = count(array_filter($projects, fn($p) => ($p['auto_schedule'] ?? 1) == 1));

// Find all daily reports
$reportFiles = glob(__DIR__ . '/logs/daily-report-*.csv');
rsort($reportFiles);

$lastLog = glob(__DIR__ . '/logs/auto-schedule-*.log');
rsort($lastLog);
$lastRun = $lastLog ? date('d M Y H:i', filemtime($lastLog[0])) : 'Never';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Auto-Schedule Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">
<?php include 'includes/navbar.php'; ?>

<div class="container py-4">
  <h3 class="mb-4"><i class="fas fa-clock me-2 text-primary"></i>24-Hour Auto-Schedule Setup</h3>

  <!-- Status Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card border-0 shadow-sm text-center p-3">
        <div class="fs-1 text-primary"><?= count($projects) ?></div>
        <div class="text-muted small">Total Projects</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm text-center p-3">
        <div class="fs-1 text-success"><?= $enabledCount ?></div>
        <div class="text-muted small">Auto-Post Enabled</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm text-center p-3">
        <div class="fs-1 text-info"><?= count($accounts) ?></div>
        <div class="text-muted small">Active Platforms</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm text-center p-3">
        <div class="fs-6 fw-bold text-warning pt-2"><?= $lastRun ?></div>
        <div class="text-muted small">Last Auto-Post</div>
      </div>
    </div>
  </div>

  <!-- Projects — Enable/Disable Toggle -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
      <span><i class="fas fa-project-diagram me-2"></i>Projects — Auto-Post Control</span>
      <span class="badge bg-light text-primary"><?= $enabledCount ?>/<?= count($projects) ?> Enabled</span>
    </div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:50px">#</th>
            <th>Keyword</th>
            <th>Target Site</th>
            <th class="text-center" style="width:140px">Auto-Post</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $p):
            $enabled = ($p['auto_schedule'] ?? 1) == 1;
          ?>
          <tr id="row-<?= $p['id'] ?>">
            <td class="text-muted"><?= $p['id'] ?></td>
            <td>
              <strong><?= clean($p['target_keyword']) ?></strong>
            </td>
            <td>
              <a href="<?= clean($p['target_site'] ?: $p['website_url']) ?>" target="_blank" class="text-decoration-none small">
                <?= clean($p['target_site'] ?: $p['website_url']) ?>
              </a>
            </td>
            <td class="text-center">
              <?php if ($enabled): ?>
                <button class="btn btn-success btn-sm" onclick="toggleProject(<?= $p['id'] ?>, 0, this)">
                  <i class="fas fa-check me-1"></i>Enabled
                </button>
              <?php else: ?>
                <button class="btn btn-outline-secondary btn-sm" onclick="toggleProject(<?= $p['id'] ?>, 1, this)">
                  <i class="fas fa-times me-1"></i>Disabled
                </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer text-muted small">
      <i class="fas fa-info-circle me-1"></i>
      <strong>Enabled</strong> = Auto-post daily. <strong>Disabled</strong> = Skip this keyword in schedule.
    </div>
  </div>

  <!-- Test Run -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-bold">
      <i class="fas fa-play me-2"></i>Test — Abhi Run Karo (Only Enabled Projects)
    </div>
    <div class="card-body">
      <p class="text-muted mb-3">Only <strong><?= $enabledCount ?> enabled</strong> projects will be posted. Disabled projects will be skipped.</p>
      <a href="?test=1" class="btn btn-success" id="runBtn">
        <i class="fas fa-play me-2"></i>Run Now
      </a>
      <?php if ($testOutput): ?>
      <div class="mt-3">
        <pre class="bg-dark text-success rounded p-3" style="max-height:400px;overflow-y:auto;font-size:12px;"><?= htmlspecialchars($testOutput) ?></pre>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Task Scheduler Setup -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold">
      <i class="fas fa-cogs me-2"></i>Windows Task Scheduler Setup (One-time)
    </div>
    <div class="card-body">
      <ol class="mb-0">
        <li class="mb-2">Windows search: <code>Task Scheduler</code> → Open</li>
        <li class="mb-2">Right panel: <strong>"Create Basic Task"</strong></li>
        <li class="mb-2">Name: <code>SEO Auto Post</code> → Next</li>
        <li class="mb-2">Trigger: <strong>Daily</strong> → Time: <strong>09:00 AM</strong> → Next</li>
        <li class="mb-2">Action: <strong>Start a program</strong> → Next</li>
        <li class="mb-2">Program: <code class="bg-dark text-success px-2 py-1 rounded">C:\xampp\php\php.exe</code></li>
        <li class="mb-2">Arguments: <code class="bg-dark text-success px-2 py-1 rounded">C:\Users\ADMIN\Desktop\seo-system\auto-schedule.php</code></li>
        <li class="mb-2">Start in: <code class="bg-dark text-success px-2 py-1 rounded">C:\Users\ADMIN\Desktop\seo-system</code></li>
        <li><strong>Finish</strong> ✓</li>
      </ol>
    </div>
  </div>

  <!-- Download Reports -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-warning text-dark fw-bold d-flex justify-content-between align-items-center">
      <span><i class="fas fa-file-excel me-2"></i>Daily Excel Reports (Keyword + Backlink URL)</span>
      <a href="?download=<?= date('Y-m-d') ?>" class="btn btn-sm btn-dark">
        <i class="fas fa-download me-1"></i>Download Today's Report
      </a>
    </div>
    <div class="card-body p-0">
      <?php if (empty($reportFiles)): ?>
        <div class="p-3 text-muted">
          <i class="fas fa-info-circle me-2"></i>No reports yet. Run the schedule once to generate the first report.
        </div>
      <?php else: ?>
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr><th>Date</th><th>File</th><th class="text-end">Download</th></tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($reportFiles, 0, 10) as $rf):
              preg_match('/daily-report-(\d{4}-\d{2}-\d{2})\.csv/', $rf, $m);
              $rDate = $m[1] ?? basename($rf);
              $rSize = round(filesize($rf) / 1024, 1) . ' KB';
            ?>
            <tr>
              <td><?= date('d M Y', strtotime($rDate)) ?></td>
              <td class="text-muted small"><?= basename($rf) ?> (<?= $rSize ?>)</td>
              <td class="text-end">
                <a href="?download=<?= $rDate ?>" class="btn btn-sm btn-outline-success">
                  <i class="fas fa-download me-1"></i>Download CSV
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <div class="card-footer text-muted small">
      <i class="fas fa-info-circle me-1"></i>
      CSV opens in Excel. Columns: <strong>Keyword, Target Site, Platform, Backlink URL, Post Title, Status, Date</strong>
    </div>
  </div>

  <!-- Log Viewer -->
  <?php if ($lastLog): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-secondary text-white fw-bold">
      <i class="fas fa-file-alt me-2"></i>Latest Log: <?= basename($lastLog[0]) ?>
    </div>
    <div class="card-body p-0">
      <pre class="bg-dark text-light rounded-bottom p-3 mb-0" style="max-height:300px;overflow-y:auto;font-size:12px;"><?= htmlspecialchars(file_get_contents($lastLog[0])) ?></pre>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
document.getElementById('runBtn')?.addEventListener('click', function() {
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Running...';
  this.disabled = true;
});

function toggleProject(projectId, newStatus, btn) {
  if (!btn) btn = event.target.closest('button');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

  fetch('schedule-setup.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'toggle=1&project_id=' + projectId + '&status=' + newStatus
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      if (newStatus === 1) {
        btn.className = 'btn btn-success btn-sm';
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Enabled';
        btn.setAttribute('onclick', 'toggleProject(' + projectId + ', 0, this)');
      } else {
        btn.className = 'btn btn-outline-secondary btn-sm';
        btn.innerHTML = '<i class="fas fa-times me-1"></i>Disabled';
        btn.setAttribute('onclick', 'toggleProject(' + projectId + ', 1, this)');
      }
      btn.disabled = false;
    }
  });
}
</script>
</body>
</html>
