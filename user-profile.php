<?php
// ============================================================
// user-profile.php
// Admin / User Profile & Connected Social Accounts Viewer
// Allows viewing user details, associated projects, and
// revealing social account passwords & platform configurations.
// ============================================================

require_once 'config.php';
requireLogin();

$db = getDB();
$isAdmin = (($_SESSION['role'] ?? 'client') === 'admin');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Determine which user profile to display
$targetUserId = isset($_GET['id']) ? (int)$_GET['id'] : $currentUserId;

// Non-admin users can only view their own profile
if (!$isAdmin && $targetUserId !== $currentUserId) {
    setFlash('danger', 'Access denied.');
    header('Location: dashboard.php');
    exit;
}

// Fetch user details
$userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$targetUserId]);
$profileUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$profileUser) {
    setFlash('danger', 'User not found.');
    header('Location: admin-dashboard.php');
    exit;
}

// Fetch all projects belonging to this user
$projStmt = $db->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY id DESC");
$projStmt->execute([$targetUserId]);
$userProjects = $projStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all social accounts for this user's projects
$projectIds = array_column($userProjects, 'id');
$socialAccounts = [];
if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $accStmt = $db->prepare("
        SELECT sa.*, COALESCE(p.business_name, p.target_keyword, p.website_url, 'Project') AS project_title, p.website_url 
        FROM social_accounts sa
        JOIN projects p ON sa.project_id = p.id
        WHERE sa.project_id IN ($placeholders)
        ORDER BY sa.platform ASC, sa.id DESC
    ");
    $accStmt->execute($projectIds);
    $socialAccounts = $accStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Platform Requirements Knowledge Base
$platformReqs = [
    'pinterest' => [
        'name' => 'Pinterest',
        'icon' => 'fab fa-pinterest text-danger',
        'auth_type' => 'Browser Automation (Playwright)',
        'required_fields' => 'Email, Password, Board Name (Optional), Image File',
        'desc' => 'Publishes image pins with title, rich description, and live dofollow backlink.'
    ],
    'medium' => [
        'name' => 'Medium',
        'icon' => 'fab fa-medium text-dark',
        'auth_type' => 'API / Automation',
        'required_fields' => 'Integration Token OR Username/Password',
        'desc' => 'High DA (96) authoritative publication for long-form SEO articles.'
    ],
    'tumblr' => [
        'name' => 'Tumblr',
        'icon' => 'fab fa-tumblr text-primary',
        'auth_type' => 'API / Playwright',
        'required_fields' => 'Email, Password, Blog Subdomain/Name',
        'desc' => 'Tier 1 & Tier 2 microblog posting with multimedia and tags.'
    ],
    'blogger' => [
        'name' => 'Blogger / Blogspot',
        'icon' => 'fab fa-blogger text-warning',
        'auth_type' => 'Google OAuth / Token',
        'required_fields' => 'Google Client ID, Client Secret, Blog ID',
        'desc' => 'Google owned high authority platform with instant indexation.'
    ],
    'wordpress' => [
        'name' => 'WordPress (Self-Hosted / Dotcom)',
        'icon' => 'fab fa-wordpress text-info',
        'auth_type' => 'REST API / App Password',
        'required_fields' => 'WordPress URL, Username, Application Password',
        'desc' => 'Direct automated blog posting with formatting and canonical tags.'
    ],
    'devto' => [
        'name' => 'DEV.to',
        'icon' => 'fab fa-dev text-dark',
        'auth_type' => 'REST API',
        'required_fields' => 'DEV.to API Key (Settings -> Extensions)',
        'desc' => 'Tech & business high DA platform with fast Google indexing.'
    ],
    'livejournal' => [
        'name' => 'LiveJournal',
        'icon' => 'fas fa-pen-nib text-secondary',
        'auth_type' => 'Browser Automation',
        'required_fields' => 'Username, Password',
        'desc' => 'Web 2.0 contextual backlink creation.'
    ],
    'bluesky' => [
        'name' => 'Bluesky',
        'icon' => 'fas fa-cloud text-info',
        'auth_type' => 'AT Protocol API',
        'required_fields' => 'Handle (e.g. user.bsky.social), App Password',
        'desc' => 'Decentralized social network with instant crawlable links.'
    ]
];

// Helper to decode password
function getDecodedPassword($encoded) {
    if (empty($encoded)) return '';
    $decoded = base64_decode($encoded, true);
    return ($decoded !== false && trim($decoded) !== '') ? $decoded : $encoded;
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Profile & Accounts - <?= htmlspecialchars($profileUser['username']) ?> - <?= SITE_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
<style>
  .profile-card {
    border: none;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
  }
  .account-row:hover {
    background-color: #f8fafc;
  }
  .pass-masked {
    font-family: monospace;
    letter-spacing: 2px;
  }
  .btn-reveal {
    border: none;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    padding: 0 6px;
  }
  .btn-reveal:hover {
    color: #0f172a;
  }
</style>
</head>
<body class="bg-light">
<?php include 'includes/navbar.php'; ?>

<div class="container py-4">

  <!-- Header Breadcrumb -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
          <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">Dashboard</a></li>
          <?php if ($isAdmin): ?>
            <li class="breadcrumb-item"><a href="admin-dashboard.php" class="text-decoration-none">Admin Panel</a></li>
          <?php endif; ?>
          <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($profileUser['username']) ?></li>
        </ol>
      </nav>
      <h3 class="fw-bold mb-0 text-dark">
        <i class="fas fa-user-circle text-primary me-2"></i>User Profile & Connected Accounts
      </h3>
    </div>
    <div>
      <a href="daily-reports.php" class="btn btn-outline-primary btn-sm me-2">
        <i class="fas fa-calendar-check me-1"></i>Daily Reports
      </a>
      <?php if ($isAdmin): ?>
        <a href="admin-dashboard.php" class="btn btn-secondary btn-sm">
          <i class="fas fa-arrow-left me-1"></i>Back to Admin
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
      <?= $flash['msg'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4 mb-4">
    <!-- User Information Card -->
    <div class="col-lg-4">
      <div class="card profile-card h-100 p-4">
        <div class="text-center mb-3">
          <div class="avatar-circle bg-primary text-white d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 70px; height: 70px; font-size: 28px; font-weight: bold;">
            <?= strtoupper(substr($profileUser['username'], 0, 1)) ?>
          </div>
          <h5 class="fw-bold mb-1"><?= htmlspecialchars($profileUser['username']) ?></h5>
          <span class="badge <?= $profileUser['role'] === 'admin' ? 'bg-danger' : 'bg-success' ?> mb-2">
            <i class="fas <?= $profileUser['role'] === 'admin' ? 'fa-shield-alt' : 'fa-user' ?> me-1"></i>
            <?= ucfirst($profileUser['role']) ?>
          </span>
          <p class="text-muted small mb-0"><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($profileUser['email']) ?></p>
        </div>

        <hr class="my-3 text-muted">

        <div class="mb-3">
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted small">Registered Date:</span>
            <span class="fw-bold small"><?= date('d M Y, h:i A', strtotime($profileUser['created_at'] ?? 'now')) ?></span>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted small">Total Projects:</span>
            <span class="badge bg-primary rounded-pill"><?= count($userProjects) ?></span>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted small">Active Accounts:</span>
            <span class="badge bg-success rounded-pill"><?= count($socialAccounts) ?></span>
          </div>
        </div>

        <?php if (!empty($profileUser['allowed_menus'])): ?>
          <div class="mt-2">
            <label class="form-label small fw-bold text-muted">Allowed Modules:</label>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach (explode(',', $profileUser['allowed_menus']) as $m): ?>
                <span class="badge bg-light text-dark border"><?= htmlspecialchars(trim($m)) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Associated Projects Overview -->
    <div class="col-lg-8">
      <div class="card profile-card h-100 p-4">
        <h5 class="fw-bold mb-3 text-dark">
          <i class="fas fa-folder-open text-warning me-2"></i>Projects & Client Websites (<?= count($userProjects) ?>)
        </h5>

        <?php if (empty($userProjects)): ?>
          <div class="text-center py-4 text-muted">
            <i class="fas fa-folder-minus fa-3x mb-2 text-secondary"></i>
            <p>No projects configured for this user yet.</p>
            <a href="add-project.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add New Project</a>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Project Name</th>
                  <th>Website URL</th>
                  <th>Target Keyword</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($userProjects as $p): ?>
                  <tr>
                    <td class="fw-bold">
                      <a href="client-profile.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark">
                        <?= htmlspecialchars($p['business_name'] ?: ($p['target_keyword'] ?: 'Project #' . $p['id'])) ?>
                      </a>
                    </td>
                    <td>
                      <a href="<?= htmlspecialchars($p['website_url'] ?? '#') ?>" target="_blank" class="small text-primary text-decoration-none">
                        <?= htmlspecialchars($p['website_url'] ?? '') ?> <i class="fas fa-external-link-alt" style="font-size: 10px;"></i>
                      </a>
                    </td>
                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['target_keyword'] ?? 'N/A') ?></span></td>
                    <td class="text-end">
                      <a href="client-profile.php?id=<?= $p['id'] ?>" class="btn btn-outline-secondary btn-sm" title="View Profile">
                        <i class="fas fa-eye"></i>
                      </a>
                      <a href="submission-manager.php" class="btn btn-outline-primary btn-sm" title="Manage Posts">
                        <i class="fas fa-paper-plane"></i>
                      </a>
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

  <!-- Connected Social Accounts & Passwords Section -->
  <div class="card profile-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h5 class="fw-bold mb-1 text-dark">
          <i class="fas fa-key text-success me-2"></i>Connected Platform Accounts & Credentials
        </h5>
        <p class="text-muted small mb-0">View all configured usernames, emails, and decoded passwords per platform.</p>
      </div>
      <span class="badge bg-light text-dark border p-2">Total Accounts: <?= count($socialAccounts) ?></span>
    </div>

    <?php if (empty($socialAccounts)): ?>
      <div class="alert alert-info mb-0">
        <i class="fas fa-info-circle me-1"></i>No social accounts have been added yet under this user's projects.
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Platform</th>
              <th>Project</th>
              <th>Username / Email</th>
              <th>Password (Admin Reveal)</th>
              <th>Requirements & Notes</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($socialAccounts as $acc): 
              $platKey = strtolower($acc['platform'] ?? '');
              $reqInfo = $platformReqs[$platKey] ?? [
                'icon' => 'fas fa-share-alt text-secondary',
                'name' => ucfirst($platKey),
                'required_fields' => 'Username, Password',
                'auth_type' => 'Automation'
              ];
              $decodedPass = getDecodedPassword($acc['password'] ?? '');
              $accId = $acc['id'];
            ?>
              <tr class="account-row">
                <td>
                  <i class="<?= $reqInfo['icon'] ?> fa-lg me-2"></i>
                  <span class="fw-bold"><?= htmlspecialchars($reqInfo['name']) ?></span>
                </td>
                <td class="small fw-semibold"><?= htmlspecialchars($acc['project_title'] ?? 'Project') ?></td>
                <td>
                  <code><?= htmlspecialchars($acc['username'] ?: $acc['email'] ?: 'N/A') ?></code>
                </td>
                <td>
                  <div class="d-flex align-items-center">
                    <span id="pass_mask_<?= $accId ?>" class="pass-masked text-muted">••••••••••</span>
                    <span id="pass_text_<?= $accId ?>" class="fw-bold text-dark font-monospace d-none"><?= htmlspecialchars($decodedPass) ?></span>
                    <button type="button" class="btn-reveal ms-2" onclick="togglePassword(<?= $accId ?>)" title="Show/Hide Password">
                      <i id="eye_icon_<?= $accId ?>" class="fas fa-eye"></i>
                    </button>
                    <button type="button" class="btn-reveal text-primary" onclick="copyPassword('<?= htmlspecialchars($decodedPass, ENT_QUOTES) ?>')" title="Copy Password">
                      <i class="fas fa-copy"></i>
                    </button>
                  </div>
                </td>
                <td class="small text-muted">
                  <span class="badge bg-light text-dark border"><?= $reqInfo['auth_type'] ?></span>
                  <div style="font-size: 11px; margin-top: 3px;"><?= $reqInfo['required_fields'] ?></div>
                </td>
                <td>
                  <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Platform Requirements Reference Card -->
  <div class="card profile-card p-4">
    <h5 class="fw-bold mb-3 text-dark">
      <i class="fas fa-list-check text-primary me-2"></i>Platform Requirements Reference Guide
    </h5>
    <div class="row g-3">
      <?php foreach ($platformReqs as $key => $req): ?>
        <div class="col-md-6 col-lg-3">
          <div class="p-3 border rounded-3 bg-white h-100 shadow-sm">
            <div class="d-flex align-items-center mb-2">
              <i class="<?= $req['icon'] ?> fa-lg me-2"></i>
              <h6 class="fw-bold mb-0"><?= htmlspecialchars($req['name']) ?></h6>
            </div>
            <div class="small text-muted mb-1"><strong>Auth:</strong> <?= $req['auth_type'] ?></div>
            <div class="small text-secondary mb-2"><strong>Needs:</strong> <?= $req['required_fields'] ?></div>
            <div class="small text-muted fst-italic" style="font-size: 11px;"><?= $req['desc'] ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<script>
function togglePassword(accId) {
  const maskEl = document.getElementById('pass_mask_' + accId);
  const textEl = document.getElementById('pass_text_' + accId);
  const iconEl = document.getElementById('eye_icon_' + accId);
  
  if (textEl.classList.contains('d-none')) {
    textEl.classList.remove('d-none');
    maskEl.classList.add('d-none');
    iconEl.classList.remove('fa-eye');
    iconEl.classList.add('fa-eye-slash');
  } else {
    textEl.classList.add('d-none');
    maskEl.classList.remove('d-none');
    iconEl.classList.remove('fa-eye-slash');
    iconEl.classList.add('fa-eye');
  }
}

function copyPassword(pass) {
  navigator.clipboard.writeText(pass).then(() => {
    alert('Password copied to clipboard!');
  }).catch(err => {
    console.error('Failed to copy', err);
  });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
