<?php
// Navbar — uses relative links so menu works on any localhost path
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$navUser = clean($_SESSION['username'] ?? 'User');
$navUserId = $_SESSION['user_id'] ?? 0;
$navUserRole = $_SESSION['role'] ?? 'client';

$navProjId = 0;
$navPendingCount = 0;
if ($navUserId > 0) {
    try {
        $db = getDB();
        $navProjStmt = $db->prepare("SELECT id FROM projects WHERE user_id=? LIMIT 1");
        $navProjStmt->execute([$navUserId]);
        $navProjId = (int)$navProjStmt->fetchColumn();
        
        // Count pending tasks in queue for this user's projects
        $navPendingStmt = $db->prepare("
            SELECT COUNT(*) FROM backlink_queue q 
            JOIN projects p ON q.project_id = p.id 
            WHERE p.user_id = ? AND q.status = 'pending'
        ");
        $navPendingStmt->execute([$navUserId]);
        $navPendingCount = (int)$navPendingStmt->fetchColumn();
    } catch (Exception $e) {}
}

// Fetch allowed menus for standard users (admins see everything)
$allowedMenus = null;
if ($navUserId > 0 && $navUserRole !== 'admin') {
    try {
        $db = getDB();
        $menuStmt = $db->prepare("SELECT allowed_menus FROM users WHERE id = ?");
        $menuStmt->execute([$navUserId]);
        $menuVal = $menuStmt->fetchColumn();
        if ($menuVal !== null && $menuVal !== '') {
            $allowedMenus = array_map('trim', explode(',', strtolower($menuVal)));
        }
    } catch (Exception $e) {}
}

if (!function_exists('isNavAllowed')) {
    function isNavAllowed($menuCode, $allowedMenus) {
        if ($allowedMenus === null) {
            return true; // Default to allow all menus if not explicitly configured
        }
        return in_array(strtolower($menuCode), $allowedMenus);
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-light">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">
      <i class="fas fa-chart-line me-2"></i>SEO 80/20
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu"
            aria-controls="navMenu" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav me-auto">
        <?php if (isNavAllowed('dashboard', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active fw-bold' : '' ?>" href="dashboard.php">
            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
          </a>
        </li>
        <?php endif; ?>

        <?php if ($navProjId > 0): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'client-profile.php' ? 'active fw-bold' : '' ?>" href="client-profile.php?id=<?= $navProjId ?>">
            <i class="fas fa-id-card me-1"></i>Client Profile
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('add-project', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'add-project.php' ? 'active fw-bold' : '' ?>" href="add-project.php">
            <i class="fas fa-plus me-1"></i>Add Project
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('client-pitch', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'client-pitch.php' ? 'active fw-bold' : '' ?>" href="client-pitch.php">
            <i class="fas fa-file-invoice me-1"></i>Client Pitch
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('submissions', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'submission-manager.php' ? 'active fw-bold' : '' ?>" href="submission-manager.php">
            <i class="fas fa-paper-plane me-1"></i>Submissions
            <?php if ($navPendingCount > 0): ?>
              <span class="badge bg-warning text-dark ms-1" style="font-size: 11px;" title="Pending Queue Tasks"><?= $navPendingCount ?></span>
            <?php endif; ?>
          </a>
        </li>
        <?php endif; ?>

        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'daily-reports.php' ? 'active fw-bold' : '' ?>" href="daily-reports.php">
            <i class="fas fa-calendar-check me-1"></i>Daily Reports
          </a>
        </li>

        <?php if (isNavAllowed('api-keys', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'api-setup.php' ? 'active fw-bold' : '' ?>" href="api-setup.php">
            <i class="fas fa-key me-1"></i>API Keys
          </a>
        </li>
        <?php endif; ?>

        <?php if (($navUserRole === 'admin') && isNavAllowed('admin-panel', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'admin-dashboard.php' ? 'active fw-bold' : '' ?>" href="admin-dashboard.php">
            <i class="fas fa-user-shield me-1"></i>Admin Panel
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('create-login-account', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link" href="admin-dashboard.php#user-accounts">
            <i class="fas fa-user-plus me-1"></i>Create Login Account
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('cost-ranking', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'seo-costs.php' ? 'active fw-bold' : '' ?>" href="seo-costs.php">
            <i class="fas fa-rupee-sign me-1"></i>Cost & Ranking
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('auto-schedule', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'schedule-setup.php' ? 'active fw-bold' : '' ?>" href="schedule-setup.php">
            <i class="fas fa-clock me-1"></i>Auto-Schedule
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('ai-workflow', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'workflow-dashboard.php' ? 'active fw-bold' : '' ?>" href="workflow-dashboard.php">
            <i class="fas fa-sync me-1"></i>AI Workflow
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('google-console', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'google-integration.php' ? 'active fw-bold' : '' ?>" href="google-integration.php">
            <i class="fab fa-google me-1"></i>Google Console
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('git-push-agent', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'git-deployer.php' ? 'active fw-bold' : '' ?>" href="git-deployer.php">
            <i class="fab fa-github me-1"></i>Git Push Agent
          </a>
        </li>
        <?php endif; ?>

        <?php if (isNavAllowed('how-to-use', $allowedMenus)): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'how-to-use.php' ? 'active fw-bold' : '' ?>" href="how-to-use.php">
            <i class="fas fa-question-circle me-1"></i>How to Use
          </a>
        </li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fas fa-user-circle me-1"></i><?= $navUser ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="setup.php"><i class="fas fa-cog me-2"></i>System Check</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
