<?php
require_once 'config.php';
requireMenuPermission('dashboard');
$db = getDB();
$userId = $_SESSION['user_id'];

// Handle Project Deletion
if (isset($_GET['delete_project'])) {
    $deleteId = (int)$_GET['delete_project'];
    
    // First verify the project belongs to the logged-in user
    $check = $db->prepare("SELECT id FROM projects WHERE id=? AND user_id=?");
    $check->execute([$deleteId, $userId]);
    if ($check->fetch()) {
        $db->beginTransaction();
        try {
            // Delete related backlinks
            $db->prepare("DELETE FROM backlinks WHERE project_id=?")->execute([$deleteId]);
            // Delete related queue tasks
            $db->prepare("DELETE FROM backlink_queue WHERE project_id=?")->execute([$deleteId]);
            // Delete related SEO reports
            $db->prepare("DELETE FROM seo_reports WHERE project_id=?")->execute([$deleteId]);
            // Delete related credentials/social accounts
            $db->prepare("DELETE FROM social_accounts WHERE project_id=?")->execute([$deleteId]);
            // Delete the project
            $db->prepare("DELETE FROM projects WHERE id=? AND user_id=?")->execute([$deleteId, $userId]);
            
            $db->commit();
            setFlash('success', 'Project deleted successfully along with its backlinks, queue, and reports.');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('danger', 'Error deleting project: ' . $e->getMessage());
        }
    } else {
        setFlash('danger', 'Access denied or project not found.');
    }
    header("Location: dashboard.php");
    exit;
}

// Self-healing migration for package_type
try {
    $db->exec("ALTER TABLE projects ADD COLUMN package_type VARCHAR(50) DEFAULT 'basic'");
} catch (PDOException $e) {
    // already exists
}

// Fetch projects with stats (filters strictly by the logged-in user's account for isolation)
$projects = $db->prepare("
    SELECT p.*,
        (SELECT COUNT(*) FROM backlinks b WHERE b.project_id = p.id AND b.status='created') AS backlinks_count,
        (SELECT `rank` FROM seo_reports WHERE project_id = p.id ORDER BY report_date DESC LIMIT 1) AS current_rank,
        (SELECT seo_score FROM seo_reports WHERE project_id = p.id ORDER BY report_date DESC LIMIT 1) AS latest_score
    FROM projects p WHERE p.user_id = ?
    ORDER BY p.created_at DESC
");
$projects->execute([$userId]);
$projects = $projects->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - SEO 80/20 System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container-fluid py-4">
  <div class="row mb-4">
    <div class="col">
      <h3><i class="fas fa-tachometer-alt me-2 text-primary"></i>Dashboard</h3>
      <p class="text-muted">Welcome back, <strong><?= clean($_SESSION['username']) ?></strong></p>
    </div>
    <div class="col-auto">
      <a href="add-project.php" class="btn btn-primary btn-lg">
        <i class="fas fa-plus me-2"></i>Add New Project
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
    <?= $flash['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Stats Row -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="stat-card bg-primary text-white">
        <div class="stat-icon"><i class="fas fa-project-diagram"></i></div>
        <div class="stat-info">
          <h2><?= count($projects) ?></h2>
          <p>Total Projects</p>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card bg-success text-white">
        <div class="stat-icon"><i class="fas fa-link"></i></div>
        <div class="stat-info">
          <h2><?= array_sum(array_column($projects, 'backlinks_count')) ?></h2>
          <p>Total Backlinks</p>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card bg-warning text-white">
        <div class="stat-icon"><i class="fas fa-star"></i></div>
        <div class="stat-info">
          <h2><?= count($projects) > 0 ? round(array_sum(array_column($projects, 'latest_score')) / count($projects)) : 0 ?>%</h2>
          <p>Avg SEO Score</p>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card bg-info text-white">
        <div class="stat-icon"><i class="fas fa-search"></i></div>
        <div class="stat-info">
          <h2><?= count(array_filter(array_column($projects, 'current_rank'), fn($r) => $r > 0 && $r <= 10)) ?></h2>
          <p>Top 10 Rankings</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Projects Table -->
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="fas fa-list me-2"></i>Your Projects</h5>
    </div>
    <div class="card-body p-0">
      <?php if (empty($projects)): ?>
      <div class="text-center py-5">
        <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
        <p class="text-muted">No projects yet. <a href="add-project.php">Add your first project</a></p>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-dark">
            <tr>
              <th>Client / Project</th>
              <th>Contact Details</th>
              <th>Keyword</th>
              <th>SEO Score</th>
              <th>Rank</th>
              <th>Backlinks</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td>
                <strong><?= clean($p['website_url']) ?></strong>
                <?php
                $plan = $p['package_type'] ?? 'basic';
                if ($plan === 'premium') {
                    echo '<span class="badge bg-warning text-dark border ms-1" style="font-size: 10px;">Premium</span>';
                } elseif ($plan === 'standard') {
                    echo '<span class="badge bg-primary text-white ms-1" style="font-size: 10px;">Standard</span>';
                } else {
                    echo '<span class="badge bg-success text-white ms-1" style="font-size: 10px;">Basic</span>';
                }
                ?><br>
                <a href="client-profile.php?id=<?= $p['id'] ?>" class="text-primary fw-bold small text-decoration-none" title="View/Edit Profile">
                  <i class="fas fa-edit me-1"></i><?= clean($p['business_name'] ?: 'No Business Name') ?>
                </a>
              </td>
              <td>
                <small>
                  <strong>Name:</strong> <?= clean($p['contact_name'] ?: '-') ?><br>
                  <strong>Phone:</strong> <?= clean($p['phone'] ?: '-') ?><br>
                  <strong>Email:</strong> <?= clean($p['email'] ?: '-') ?>
                </small>
              </td>
              <td><span class="badge bg-secondary"><?= clean($p['target_keyword']) ?></span></td>
              <td>
                <?php $score = $p['latest_score'] ?? 0; ?>
                <div class="progress" style="height:8px;width:80px;">
                  <div class="progress-bar <?= $score >= 70 ? 'bg-success' : ($score >= 40 ? 'bg-warning' : 'bg-danger') ?>"
                       style="width:<?= $score ?>%"></div>
                </div>
                <small><?= $score ?>/100</small>
              </td>
              <td>
                <?php $rank = $p['current_rank'] ?? 0; ?>
                <?php if ($rank > 0): ?>
                  <span class="badge <?= $rank <= 10 ? 'bg-success' : ($rank <= 30 ? 'bg-warning' : 'bg-danger') ?>">
                    #<?= $rank ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <td><span class="badge bg-info"><?= $p['backlinks_count'] ?></span></td>
              <td><small><?= formatLocalTime($p['created_at'], 'd M Y') ?></small></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <a href="seo-80-20.php?id=<?= $p['id'] ?>" class="btn btn-primary" title="Run SEO">
                    <i class="fas fa-rocket me-1"></i> Run SEO
                  </a>
                  <a href="client-profile.php?id=<?= $p['id'] ?>" class="btn btn-warning text-dark" title="Client Profile">
                    <i class="fas fa-id-card me-1"></i> Profile
                  </a>
                  <a href="export-excel.php?id=<?= $p['id'] ?>" class="btn btn-success" title="Export Excel">
                    <i class="fas fa-file-excel"></i>
                  </a>
                  <a href="dashboard.php?delete_project=<?= $p['id'] ?>" class="btn btn-danger" title="Delete Project" onclick="return confirm('Are you sure you want to delete this project and all its related backlinks, queue, and reports? This action cannot be undone.');">
                    <i class="fas fa-trash"></i>
                  </a>
                </div>
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
<?php include 'includes/footer.php'; ?>
</body>
</html>
