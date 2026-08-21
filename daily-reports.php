<?php
// ============================================================
// daily-reports.php
// Date-Filtered Daily Backlink & Account Failure Analytics
// Displays date-wise live published backlinks, failed accounts,
// exact error diagnostic root causes, and one-click re-queue actions.
// ============================================================

require_once 'config.php';
requireLogin();

$db = getDB();
$isAdmin = (($_SESSION['role'] ?? 'client') === 'admin');
$userId = (int)($_SESSION['user_id'] ?? 0);

// Handle AJAX One-Click Retry for Failed Task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_task_id'])) {
    header('Content-Type: application/json');
    $taskId = (int)$_POST['retry_task_id'];
    
    // Check permission
    $checkSql = $isAdmin 
        ? "SELECT q.* FROM backlink_queue q WHERE q.id = ?"
        : "SELECT q.* FROM backlink_queue q JOIN projects p ON q.project_id = p.id WHERE q.id = ? AND p.user_id = ?";
    $checkParams = $isAdmin ? [$taskId] : [$taskId, $userId];
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute($checkParams);
    $task = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        echo json_encode(['success' => false, 'error' => 'Task not found or access denied.']);
        exit;
    }
    
    // Reset to pending
    $resetStmt = $db->prepare("UPDATE backlink_queue SET status = 'pending', error_message = NULL, updated_at = NOW() WHERE id = ?");
    $resetStmt->execute([$taskId]);
    
    echo json_encode(['success' => true, 'message' => "Task #$taskId has been re-queued for processing!"]);
    exit;
}

// 1. Date Filter Determination
$selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) 
    ? $_GET['date'] 
    : date('Y-m-d');

$filterPreset = $_GET['preset'] ?? 'today';
if ($filterPreset === 'yesterday') {
    $selectedDate = date('Y-m-d', strtotime('-1 day'));
}

// 2. Fetch Selected Date's Metrics & Published Backlinks
$userFilterProj = $isAdmin ? "" : " AND p.user_id = $userId";

// A. Published Backlinks on Selected Date
$backlinksSql = "
    SELECT b.*, COALESCE(p.business_name, p.target_keyword, p.website_url, 'Project') AS project_title, p.website_url 
    FROM backlinks b
    JOIN projects p ON b.project_id = p.id
    WHERE DATE(b.created_at) = :selDate $userFilterProj
    ORDER BY b.id DESC
";
$bStmt = $db->prepare($backlinksSql);
$bStmt->execute([':selDate' => $selectedDate]);
$publishedBacklinks = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// B. Failed Queue Tasks on Selected Date
$failedSql = "
    SELECT q.*, COALESCE(p.business_name, p.target_keyword, p.website_url, 'Project') AS project_title, p.website_url, sa.username, sa.email
    FROM backlink_queue q
    JOIN projects p ON q.project_id = p.id
    LEFT JOIN social_accounts sa ON q.social_account_id = sa.id
    WHERE DATE(q.created_at) = :selDate AND q.status = 'failed' $userFilterProj
    ORDER BY q.id DESC
";
$fStmt = $db->prepare($failedSql);
$fStmt->execute([':selDate' => $selectedDate]);
$failedTasks = $fStmt->fetchAll(PDO::FETCH_ASSOC);

// C. Pending Queue Tasks on Selected Date
$pendingSql = "
    SELECT COUNT(*) 
    FROM backlink_queue q
    JOIN projects p ON q.project_id = p.id
    WHERE DATE(q.created_at) = :selDate AND q.status = 'pending' $userFilterProj
";
$pStmt = $db->prepare($pendingSql);
$pStmt->execute([':selDate' => $selectedDate]);
$pendingCount = (int)$pStmt->fetchColumn();

// Overall KPI Calculations for the selected date
$successCount = count($publishedBacklinks);
$failCount = count($failedTasks);
$totalAttempted = $successCount + $failCount + $pendingCount;
$successRate = $totalAttempted > 0 ? round(($successCount / ($successCount + $failCount ?: 1)) * 100, 1) : 100;

// Helper to categorize and format error message
function formatErrorReason($errMsg) {
    if (empty($errMsg)) return 'Unknown timeout or connection interruption.';
    $lower = strtolower($errMsg);
    if (strpos($lower, 'password') !== false || strpos($lower, 'incorrect') !== false) {
        return '🔴 Incorrect Password (Please verify credentials in User Profile)';
    }
    if (strpos($lower, 'too many login') !== false || strpos($lower, 'rate limit') !== false || strpos($lower, 'could not complete') !== false) {
        return '⏳ 30-min Rate Limit Cooldown (Pinterest temporarily paused new logins for this IP)';
    }
    if (strpos($lower, 'verification') !== false || strpos($lower, 'otp') !== false || strpos($lower, 'code') !== false) {
        return '📱 Email OTP / Verification Code Challenge required by platform';
    }
    if (strpos($lower, 'canvas') !== false || strpos($lower, 'selector') !== false || strpos($lower, 'input') !== false) {
        return '⚠️ Canvas UI stabilization delay (Page took longer to render)';
    }
    return htmlspecialchars($errMsg);
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Backlink & Failure Report - <?= SITE_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
<style>
  .report-card {
    border: none;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
  }
  .kpi-card {
    border: none;
    border-radius: 14px;
    transition: transform 0.2s ease;
  }
  .kpi-card:hover {
    transform: translateY(-3px);
  }
  .badge-platform {
    font-size: 12px;
    padding: 6px 10px;
    border-radius: 6px;
  }
</style>
</head>
<body class="bg-light">
<?php include 'includes/navbar.php'; ?>

<div class="container py-4">

  <!-- Header & Date Filter Form -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
      <h3 class="fw-bold mb-0 text-dark">
        <i class="fas fa-calendar-check text-primary me-2"></i>Daily Backlink & Account Health Report
      </h3>
      <p class="text-muted small mb-0">Date-wise backlink generation tracking, success verification, and error root-cause analytics.</p>
    </div>

    <!-- Date Picker & Filter Controls -->
    <form method="GET" action="daily-reports.php" class="d-flex flex-wrap align-items-center gap-2">
      <div class="btn-group" role="group">
        <a href="daily-reports.php?preset=today&date=<?= date('Y-m-d') ?>" class="btn btn-sm <?= $selectedDate === date('Y-m-d') ? 'btn-primary' : 'btn-outline-secondary' ?>">
          Today
        </a>
        <a href="daily-reports.php?preset=yesterday&date=<?= date('Y-m-d', strtotime('-1 day')) ?>" class="btn btn-sm <?= $selectedDate === date('Y-m-d', strtotime('-1 day')) ? 'btn-primary' : 'btn-outline-secondary' ?>">
          Yesterday
        </a>
      </div>

      <div class="input-group input-group-sm" style="width: 200px;">
        <span class="input-group-text bg-white"><i class="fas fa-calendar-alt text-muted"></i></span>
        <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($selectedDate) ?>" onchange="this.form.submit()">
      </div>
      <button type="submit" class="btn btn-sm btn-dark"><i class="fas fa-filter me-1"></i>Filter</button>
    </form>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
      <?= $flash['msg'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- KPI Summary Banner for Selected Date -->
  <div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
      <div class="card kpi-card bg-primary text-white shadow-sm p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-white-50 small fw-bold text-uppercase">Total Attempted</div>
            <h2 class="fw-bold mb-0"><?= $totalAttempted ?></h2>
          </div>
          <i class="fas fa-tasks fa-2x text-white-50"></i>
        </div>
      </div>
    </div>

    <div class="col-md-3 col-6">
      <div class="card kpi-card bg-success text-white shadow-sm p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-white-50 small fw-bold text-uppercase">✅ Successful Links</div>
            <h2 class="fw-bold mb-0"><?= $successCount ?></h2>
          </div>
          <i class="fas fa-check-circle fa-2x text-white-50"></i>
        </div>
      </div>
    </div>

    <div class="col-md-3 col-6">
      <div class="card kpi-card bg-danger text-white shadow-sm p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-white-50 small fw-bold text-uppercase">❌ Failed Accounts</div>
            <h2 class="fw-bold mb-0"><?= $failCount ?></h2>
          </div>
          <i class="fas fa-exclamation-triangle fa-2x text-white-50"></i>
        </div>
      </div>
    </div>

    <div class="col-md-3 col-6">
      <div class="card kpi-card bg-dark text-white shadow-sm p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-white-50 small fw-bold text-uppercase">📈 Success Rate</div>
            <h2 class="fw-bold mb-0"><?= $successRate ?>%</h2>
          </div>
          <i class="fas fa-chart-pie fa-2x text-white-50"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Detailed Tab Content -->
  <div class="card report-card p-4 mb-4">
    <ul class="nav nav-pills mb-3" id="reportTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active fw-bold" id="success-tab" data-bs-toggle="pill" data-bs-target="#success-pane" type="button" role="tab">
          <i class="fas fa-check-circle text-success me-2"></i>Published Backlinks (<?= $successCount ?>)
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold text-danger" id="failed-tab" data-bs-toggle="pill" data-bs-target="#failed-pane" type="button" role="tab">
          <i class="fas fa-triangle-exclamation me-2"></i>Failed Accounts & Diagnostics (<?= $failCount ?>)
        </button>
      </li>
    </ul>

    <div class="tab-content" id="reportTabContent">
      
      <!-- Tab 1: Published Live Backlinks -->
      <div class="tab-pane fade show active" id="success-pane" role="tabpanel">
        <?php if (empty($publishedBacklinks)): ?>
          <div class="text-center py-5 text-muted">
            <i class="fas fa-link-slash fa-3x mb-2 text-secondary"></i>
            <p>No backlinks were created on <?= date('d M Y', strtotime($selectedDate)) ?>.</p>
            <a href="submission-manager.php" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane me-1"></i>Start Auto-Post Queue</a>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Platform</th>
                  <th>Project Name</th>
                  <th>Keyword / Title</th>
                  <th>Live Clickable URL</th>
                  <th>Created Time</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($publishedBacklinks as $link): ?>
                  <tr>
                    <td>
                      <span class="badge bg-light text-dark border badge-platform">
                        <i class="fas fa-share-alt me-1 text-primary"></i><?= htmlspecialchars(ucfirst($link['platform'])) ?>
                      </span>
                    </td>
                    <td class="fw-bold"><?= htmlspecialchars($link['project_title'] ?? 'Project') ?></td>
                    <td>
                      <span class="badge bg-info text-dark"><?= htmlspecialchars($link['keyword'] ?: ($link['post_title'] ?: 'Backlink')) ?></span>
                    </td>
                    <td>
                      <a href="<?= htmlspecialchars($link['backlink_url']) ?>" target="_blank" class="text-primary text-decoration-none fw-semibold">
                        <?= htmlspecialchars($link['backlink_url']) ?> <i class="fas fa-external-link-alt ms-1" style="font-size: 11px;"></i>
                      </a>
                    </td>
                    <td class="small text-muted"><?= date('h:i A', strtotime($link['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Tab 2: Failed Tasks & Diagnostics -->
      <div class="tab-pane fade" id="failed-pane" role="tabpanel">
        <?php if (empty($failedTasks)): ?>
          <div class="alert alert-success d-flex align-items-center mb-0 p-4">
            <i class="fas fa-check-circle fa-2x text-success me-3"></i>
            <div>
              <h5 class="fw-bold mb-1">Clean Record! 0 Failures on <?= date('d M Y', strtotime($selectedDate)) ?></h5>
              <p class="mb-0 text-muted small">All automated posts and browser sessions executed with 100% success rate on this date.</p>
            </div>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Task ID</th>
                  <th>Platform & Account</th>
                  <th>Project</th>
                  <th>Exact Failure Reason & Diagnostic</th>
                  <th>Status & Resolution</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($failedTasks as $f): 
                  $accEmail = $f['username'] ?: ($f['email'] ?: 'Account ID #' . $f['social_account_id']);
                ?>
                  <tr id="task_row_<?= $f['id'] ?>">
                    <td><code>#<?= $f['id'] ?></code></td>
                    <td>
                      <span class="badge bg-danger text-white me-1"><?= ucfirst($f['platform']) ?></span>
                      <div class="small text-muted mt-1"><?= htmlspecialchars($accEmail) ?></div>
                    </td>
                    <td class="small fw-bold"><?= htmlspecialchars($f['project_title'] ?? 'Project') ?></td>
                    <td>
                      <div class="p-2 rounded bg-light border text-danger small font-monospace">
                        <?= formatErrorReason($f['error_message']) ?>
                      </div>
                    </td>
                    <td>
                      <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Requires Retry</span>
                    </td>
                    <td class="text-end">
                      <button type="button" class="btn btn-outline-success btn-sm" onclick="retryFailedTask(<?= $f['id'] ?>)">
                        <i class="fas fa-redo me-1"></i>Retry Now
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div>

<script>
function retryFailedTask(taskId) {
  if (!confirm('Do you want to re-queue Task #' + taskId + ' for instant processing?')) return;
  
  const formData = new FormData();
  formData.append('retry_task_id', taskId);

  fetch('daily-reports.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alert(data.message);
      const row = document.getElementById('task_row_' + taskId);
      if (row) {
        row.style.backgroundColor = '#d1fae5';
        row.querySelector('td:nth-child(5)').innerHTML = '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Re-queued</span>';
      }
    } else {
      alert('Error: ' + data.error);
    }
  })
  .catch(err => {
    alert('Failed to retry task: ' + err);
  });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
