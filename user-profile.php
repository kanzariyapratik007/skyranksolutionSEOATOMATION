<?php
// ============================================================
// user-profile.php
// Admin / User Profile & Dynamic Platform Credentials Viewer
// Renders platform-specific custom database fields (OAuth Keys,
// API Tokens, Secrets, Passwords, Hostnames) with 👁️ Show/Hide
// toggles and 1-click clipboard copy.
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

// Helper to decode password/token
function getDecodedValue($encoded) {
    if (empty($encoded)) return '';
    $decoded = base64_decode($encoded, true);
    return ($decoded !== false && trim($decoded) !== '') ? $decoded : $encoded;
}

// Dynamic Platform Parser — extracts exact platform fields
function getPlatformFields($acc) {
    $platform = strtolower($acc['platform'] ?? '');
    $username = $acc['username'] ?? '';
    $apiKey = $acc['api_key'] ?? '';
    $apiSecret = $acc['api_secret'] ?? '';
    $refreshToken = $acc['refresh_token'] ?? '';
    $rawPassword = $acc['password'] ?? '';
    $decodedPassword = getDecodedValue($rawPassword);

    $fields = [];
    $authType = 'Automation';
    $icon = 'fas fa-share-alt text-primary';
    $platformName = ucfirst($platform);

    switch ($platform) {
        case 'tumblr':
            $platformName = 'Tumblr';
            $icon = 'fab fa-tumblr text-primary';
            $authType = 'OAuth 1.0a API';
            $parts = explode(':', $decodedPassword);
            $oauthToken = $parts[0] ?? $decodedPassword;
            $oauthSecret = $parts[1] ?? '';

            $fields[] = ['label' => 'Blog Hostname', 'value' => $username, 'is_secret' => false, 'hint' => 'e.g. user.tumblr.com'];
            $fields[] = ['label' => 'OAuth Consumer Key (API Key)', 'value' => $apiKey, 'is_secret' => false, 'hint' => 'Tumblr App Key'];
            $fields[] = ['label' => 'OAuth Consumer Secret (Secret Key)', 'value' => $apiSecret, 'is_secret' => true, 'hint' => 'Tumblr Secret Key'];
            $fields[] = ['label' => 'OAuth Token (Access Token)', 'value' => $oauthToken, 'is_secret' => true, 'hint' => 'Tumblr OAuth Access Token'];
            $fields[] = ['label' => 'OAuth Token Secret (Access Token Secret)', 'value' => $oauthSecret, 'is_secret' => true, 'hint' => 'Tumblr OAuth Token Secret'];
            break;

        case 'devto':
        case 'dev.to':
            $platformName = 'DEV.to';
            $icon = 'fab fa-dev text-dark';
            $authType = 'REST API';
            $fields[] = ['label' => 'Username / Email', 'value' => $username, 'is_secret' => false];
            if (!empty($decodedPassword)) {
                $fields[] = ['label' => 'Password', 'value' => $decodedPassword, 'is_secret' => true];
            }
            $fields[] = ['label' => 'DEV.to API Key / Integration Token', 'value' => $apiKey ?: $decodedPassword, 'is_secret' => true, 'hint' => 'Generated at dev.to/settings/extensions'];
            break;

        case 'blogger':
            $platformName = 'Blogger.com';
            $icon = 'fab fa-blogger text-warning';
            $authType = 'Google OAuth 2.0';
            $fields[] = ['label' => 'Blog ID', 'value' => $username, 'is_secret' => false, 'hint' => 'Blogger unique numeric ID'];
            $fields[] = ['label' => 'Google Client ID', 'value' => $apiKey, 'is_secret' => false];
            $fields[] = ['label' => 'Google Client Secret', 'value' => $apiSecret, 'is_secret' => true];
            $fields[] = ['label' => 'OAuth Refresh Token', 'value' => $refreshToken ?: $decodedPassword, 'is_secret' => true];
            break;

        case 'pinterest':
            $platformName = 'Pinterest';
            $icon = 'fab fa-pinterest text-danger';
            $authType = 'Playwright Engine (Real Browser)';
            $fields[] = ['label' => 'Pinterest Email / Account', 'value' => $username ?: ($acc['email'] ?? ''), 'is_secret' => false];
            $fields[] = ['label' => 'Password', 'value' => $decodedPassword, 'is_secret' => true];
            if (!empty($apiKey)) {
                $fields[] = ['label' => 'Target Board Name', 'value' => $apiKey, 'is_secret' => false];
            }
            break;

        case 'bluesky':
            $platformName = 'Bluesky';
            $icon = 'fas fa-cloud text-info';
            $authType = 'AT Protocol API';
            $fields[] = ['label' => 'Bluesky Handle', 'value' => $username, 'is_secret' => false, 'hint' => 'e.g. username.bsky.social'];
            $fields[] = ['label' => 'App Password', 'value' => $decodedPassword, 'is_secret' => true, 'hint' => 'Created in Settings -> App Passwords'];
            break;

        case 'mastodon':
            $platformName = 'Mastodon';
            $icon = 'fab fa-mastodon text-primary';
            $authType = 'Mastodon REST API';
            $fields[] = ['label' => 'Instance URL', 'value' => $apiKey ?: 'https://mastodon.social', 'is_secret' => false];
            $fields[] = ['label' => 'Account / Handle', 'value' => $username, 'is_secret' => false];
            $fields[] = ['label' => 'Bearer Access Token', 'value' => $decodedPassword, 'is_secret' => true];
            break;

        case 'github':
            $platformName = 'GitHub';
            $icon = 'fab fa-github text-dark';
            $authType = 'GitHub REST API';
            $fields[] = ['label' => 'GitHub Username', 'value' => $username, 'is_secret' => false];
            $fields[] = ['label' => 'Personal Access Token (PAT)', 'value' => $decodedPassword, 'is_secret' => true];
            if (!empty($apiKey)) {
                $fields[] = ['label' => 'Target Repository', 'value' => $apiKey, 'is_secret' => false];
            }
            break;

        case 'medium':
            $platformName = 'Medium';
            $icon = 'fab fa-medium text-dark';
            $authType = 'Integration Token API';
            $fields[] = ['label' => 'Username / Author Name', 'value' => $username, 'is_secret' => false];
            $fields[] = ['label' => 'Integration Token', 'value' => $apiKey ?: $decodedPassword, 'is_secret' => true];
            break;

        case 'wordpress':
            $platformName = 'WordPress';
            $icon = 'fab fa-wordpress text-info';
            $authType = 'WP REST API';
            $fields[] = ['label' => 'WordPress Site URL', 'value' => $apiKey, 'is_secret' => false, 'hint' => 'e.g. https://example.com'];
            $fields[] = ['label' => 'Admin Username', 'value' => $username, 'is_secret' => false];
            $fields[] = ['label' => 'Application Password', 'value' => $decodedPassword, 'is_secret' => true];
            break;

        default:
            $platformName = ucfirst($platform);
            $icon = 'fas fa-share-alt text-secondary';
            $authType = 'Credentials';
            $fields[] = ['label' => 'Username / Email', 'value' => $username ?: ($acc['email'] ?? ''), 'is_secret' => false];
            $fields[] = ['label' => 'Password', 'value' => $decodedPassword, 'is_secret' => true];
            if (!empty($apiKey)) {
                $fields[] = ['label' => 'API Key / Host', 'value' => $apiKey, 'is_secret' => false];
            }
            break;
    }

    return [
        'name' => $platformName,
        'icon' => $icon,
        'auth_type' => $authType,
        'fields' => $fields
    ];
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
  .platform-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    transition: all 0.2s ease;
  }
  .platform-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 6px 16px rgba(0,0,0,0.05);
  }
  .field-box {
    background: #f8fafc;
    border: 1px solid #edf2f7;
    border-radius: 8px;
    padding: 8px 12px;
  }
  .field-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
  }
  .field-value {
    font-family: monospace;
    font-size: 13px;
    color: #0f172a;
    word-break: break-all;
  }
  .btn-copy, .btn-eye {
    border: none;
    background: transparent;
    color: #94a3b8;
    cursor: pointer;
    padding: 0 4px;
    transition: color 0.15s;
  }
  .btn-copy:hover, .btn-eye:hover {
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
        <i class="fas fa-user-circle text-primary me-2"></i>User Profile & Connected Credentials
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

  <!-- Dynamic Connected Platform Accounts Section -->
  <div class="card profile-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h5 class="fw-bold mb-1 text-dark">
          <i class="fas fa-key text-success me-2"></i>Dynamic Platform Accounts & Credentials
        </h5>
        <p class="text-muted small mb-0">Exact database fields, OAuth tokens, API secrets, and passwords configured per platform.</p>
      </div>
      <span class="badge bg-primary text-white p-2">Total Accounts: <?= count($socialAccounts) ?></span>
    </div>

    <?php if (empty($socialAccounts)): ?>
      <div class="alert alert-info mb-0">
        <i class="fas fa-info-circle me-1"></i>No social accounts have been added yet under this user's projects.
      </div>
    <?php else: ?>
      <div class="row g-4">
        <?php foreach ($socialAccounts as $accIdx => $acc): 
          $parsed = getPlatformFields($acc);
          $accId = $acc['id'];
        ?>
          <div class="col-lg-6">
            <div class="platform-card p-3 h-100 shadow-sm">
              <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div class="d-flex align-items-center">
                  <i class="<?= $parsed['icon'] ?> fa-2x me-2"></i>
                  <div>
                    <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($parsed['name']) ?></h6>
                    <small class="text-muted fw-semibold">Project: <?= htmlspecialchars($acc['project_title'] ?? 'Project') ?></small>
                  </div>
                </div>
                <div>
                  <span class="badge bg-light text-dark border"><?= $parsed['auth_type'] ?></span>
                  <span class="badge bg-success ms-1"><i class="fas fa-check-circle me-1"></i>Active</span>
                </div>
              </div>

              <!-- Render All Platform-Specific Dynamic Fields -->
              <div class="d-flex flex-column gap-2">
                <?php foreach ($parsed['fields'] as $fIdx => $fld): 
                  $fieldId = "field_{$accId}_{$fIdx}";
                  $val = $fld['value'] ?? '';
                ?>
                  <div class="field-box">
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="field-label"><?= htmlspecialchars($fld['label']) ?></div>
                      <div class="d-flex align-items-center gap-1">
                        <?php if ($fld['is_secret']): ?>
                          <button type="button" class="btn-eye" onclick="toggleField('<?= $fieldId ?>')" title="Show/Hide">
                            <i id="eye_<?= $fieldId ?>" class="fas fa-eye"></i>
                          </button>
                        <?php endif; ?>
                        <button type="button" class="btn-copy" onclick="copyValue('<?= htmlspecialchars($val, ENT_QUOTES) ?>')" title="Copy Value">
                          <i class="fas fa-copy"></i>
                        </button>
                      </div>
                    </div>
                    
                    <div class="field-value">
                      <?php if ($fld['is_secret']): ?>
                        <span id="mask_<?= $fieldId ?>" class="text-muted" style="letter-spacing: 2px;">••••••••••••</span>
                        <span id="val_<?= $fieldId ?>" class="fw-bold text-dark d-none"><?= htmlspecialchars($val ?: 'Empty') ?></span>
                      <?php else: ?>
                        <span class="text-dark fw-semibold"><?= htmlspecialchars($val ?: 'None') ?></span>
                      <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($fld['hint'])): ?>
                      <div class="text-muted small" style="font-size: 10px; margin-top: 2px;">
                        <i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($fld['hint']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>

            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
function toggleField(fieldId) {
  const maskEl = document.getElementById('mask_' + fieldId);
  const valEl = document.getElementById('val_' + fieldId);
  const eyeEl = document.getElementById('eye_' + fieldId);
  
  if (valEl.classList.contains('d-none')) {
    valEl.classList.remove('d-none');
    maskEl.classList.add('d-none');
    eyeEl.classList.remove('fa-eye');
    eyeEl.classList.add('fa-eye-slash');
  } else {
    valEl.classList.add('d-none');
    maskEl.classList.remove('d-none');
    eyeEl.classList.remove('fa-eye-slash');
    eyeEl.classList.add('fa-eye');
  }
}

function copyValue(val) {
  if (!val) {
    alert('Field is empty');
    return;
  }
  navigator.clipboard.writeText(val).then(() => {
    alert('Copied to clipboard!');
  }).catch(err => {
    console.error('Failed to copy', err);
  });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
