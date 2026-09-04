<?php
require_once 'config.php';
requireLogin();
$db = getDB();
$userId = $_SESSION['user_id'];
$flash = getFlash();

// Handle save credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_account'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.'); header('Location: social-accounts.php'); exit;
    }
    
    $platform = clean($_POST['platform']);
    $username = clean($_POST['username']);
    $password = $_POST['password']; 
    $apiKey   = clean($_POST['api_key'] ?? '');
    $apiSecret = clean($_POST['api_secret'] ?? '');
    
    // Check if exists
    $check = $db->prepare("SELECT * FROM social_accounts WHERE user_id=? AND platform=?");
    $check->execute([$userId, $platform]);
    $existing = $check->fetch();
    
    if ($platform === 'tumblr') {
        $token = $_POST['password'] ?? '';
        $secret = $_POST['tumblr_token_secret'] ?? '';
        
        if ($existing) {
            $decrypted = base64_decode($existing['password']);
            $parts = explode(':', $decrypted);
            $oldToken = $parts[0] ?? '';
            $oldSecret = $parts[1] ?? '';
            
            $finalToken = !empty($token) ? $token : $oldToken;
            $finalSecret = !empty($secret) ? $secret : $oldSecret;
            
            $encryptedPassword = base64_encode($finalToken . ':' . $finalSecret);
        } else {
            $encryptedPassword = base64_encode($token . ':' . $secret);
        }
    } else {
        if ($existing && empty($password)) {
            $encryptedPassword = $existing['password'];
        } else {
            $encryptedPassword = base64_encode($password);
        }
    }
    
    if ($existing) {
        $db->prepare("UPDATE social_accounts SET username=?, password=?, api_key=?, api_secret=?, status='active' WHERE user_id=? AND platform=?")
           ->execute([$username, $encryptedPassword, $apiKey, $apiSecret, $userId, $platform]);
        setFlash('success', ucfirst($platform) . ' credentials updated!');
    } else {
        $db->prepare("INSERT INTO social_accounts (user_id, platform, username, password, api_key, api_secret, status) VALUES (?,?,?,?,?,?,'active')")
           ->execute([$userId, $platform, $username, $encryptedPassword, $apiKey, $apiSecret]);
        setFlash('success', ucfirst($platform) . ' credentials saved!');
    }
    header('Location: social-accounts.php'); exit;
}

// Fetch saved accounts
$accounts = $db->prepare("SELECT * FROM social_accounts WHERE user_id=?");
$accounts->execute([$userId]);
$accounts = $accounts->fetchAll();

$accountsMap = [];
foreach ($accounts as $acc) {
    $accountsMap[$acc['platform']] = $acc;
}

$platforms = [
    // ── API-based (existing) ──────────────────────────────────
    ['id' => 'medium',      'name' => 'Medium.com',        'icon' => 'fab fa-medium',    'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'wordpress',   'name' => 'WordPress.com',     'icon' => 'fab fa-wordpress', 'needs_api' => true,  'api_url' => 'https://developer.wordpress.com/apps/'],
    ['id' => 'blogger',     'name' => 'Blogger.com',       'icon' => 'fab fa-blogger',   'needs_api' => true,  'api_url' => 'https://console.cloud.google.com/apis/credentials'],
    ['id' => 'tumblr',      'name' => 'Tumblr',            'icon' => 'fab fa-tumblr',    'needs_api' => true,  'api_url' => 'https://www.tumblr.com/oauth/apps'],

    // ── Selenium browser automation (email+password) ──────────
    ['id' => 'pinterest',   'name' => 'Pinterest',         'icon' => 'fab fa-pinterest', 'needs_api' => false, 'api_url' => '', 'login_only' => true,
     'selenium' => true, 'note' => '🤖 Browser Automation — System opens Chrome, logs into Pinterest, creates pin automatically'],
    ['id' => 'behance',     'name' => 'Behance',           'icon' => 'fab fa-behance',   'needs_api' => false, 'api_url' => '', 'login_only' => true,
     'selenium' => true, 'note' => '🤖 Browser Automation — System logs in with Adobe account, creates project automatically'],

    // ── Email+Password platforms ──────────────────────────────
    ['id' => 'substack',    'name' => 'Substack',          'icon' => 'fas fa-newspaper', 'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'livejournal', 'name' => 'LiveJournal',       'icon' => 'fas fa-journal-whills', 'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'justpaste',   'name' => 'JustPaste.it',      'icon' => 'fas fa-paste',     'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'mewe',        'name' => 'MeWe',              'icon' => 'fas fa-users',     'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'scoopit',     'name' => 'Scoop.it',          'icon' => 'fas fa-newspaper', 'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'wakelet',     'name' => 'Wakelet',           'icon' => 'fas fa-bookmark',  'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'padlet',      'name' => 'Padlet',            'icon' => 'fas fa-th-large',  'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'pearltrees',  'name' => 'Pearltrees',        'icon' => 'fas fa-sitemap',   'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'instapaper',  'name' => 'Instapaper',        'icon' => 'fas fa-bookmark',  'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'posteezy',    'name' => 'Posteezy',          'icon' => 'fas fa-edit',      'needs_api' => false, 'api_url' => '', 'login_only' => true],

    // ── Image hosting (login optional) ───────────────────────
    ['id' => 'gifyu',       'name' => 'Gifyu.com',         'icon' => 'fas fa-image',     'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'photobucket', 'name' => 'Photobucket',       'icon' => 'fas fa-camera',    'needs_api' => false, 'api_url' => '', 'login_only' => true],

    // ── PDF/File platforms (login required) ──────────────────
    ['id' => 'limewire',    'name' => 'LimeWire',          'icon' => 'fas fa-file-pdf',  'needs_api' => false, 'api_url' => '', 'login_only' => true],
    ['id' => 'powershow',   'name' => 'PowerShow',         'icon' => 'fas fa-chalkboard-teacher', 'needs_api' => false, 'api_url' => '', 'login_only' => true],

    // ── No-auth (auto, no credentials needed) ────────────────
    ['id' => 'pdfhost',     'name' => 'PDFHost.io',        'icon' => 'fas fa-file-pdf',  'needs_api' => false, 'api_url' => '', 'no_auth' => true],
    ['id' => 'uploadee',    'name' => 'Upload.ee',         'icon' => 'fas fa-upload',    'needs_api' => false, 'api_url' => '', 'no_auth' => true],
    ['id' => 'workupload',  'name' => 'WorkUpload',        'icon' => 'fas fa-cloud-upload-alt', 'needs_api' => false, 'api_url' => '', 'no_auth' => true],
    ['id' => 'postimage',   'name' => 'PostImage.org',     'icon' => 'fas fa-image',     'needs_api' => false, 'api_url' => '', 'no_auth' => true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Social Accounts - SEO 80/20 System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container py-4">
  <div class="row mb-4">
    <div class="col">
      <h3><i class="fas fa-users me-2 text-primary"></i>Social Media Accounts</h3>
      <p class="text-muted">Add your credentials — system will auto-post backlinks</p>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
    <?= $flash['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    <strong>How it works:</strong> You manually create accounts → generate API keys → save here → System will automatically post
  </div>

  <div class="row g-4">
  <?php foreach ($platforms as $p): ?>
    <?php $saved = $accountsMap[$p['id']] ?? null; ?>
    <div class="col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">
            <i class="<?= $p['icon'] ?> me-2"></i><?= $p['name'] ?>
          </h6>
          <?php if (!empty($p['no_auth'])): ?>
            <span class="badge bg-success"><i class="fas fa-bolt"></i> Auto (No Login)</span>
          <?php elseif ($saved): ?>
            <span class="badge bg-success"><i class="fas fa-check"></i> Connected</span>
          <?php else: ?>
            <span class="badge bg-secondary">Not Connected</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (!empty($p['no_auth'])): ?>
            <!-- No credentials needed -->
            <div class="alert alert-success mb-0">
              <i class="fas fa-check-circle me-2"></i>
              <strong>Fully Automatic</strong> — No login required. System posts automatically.
            </div>
          <?php else: ?>
          <?php if (!empty($p['selenium'])): ?>
            <div class="alert alert-info py-2 mb-3">
              <i class="fas fa-robot me-2"></i><strong>Browser Automation (Selenium)</strong><br>
              <small><?= $p['note'] ?? '' ?></small>
            </div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="save_account" value="1">
            <input type="hidden" name="platform" value="<?= $p['id'] ?>">
            
            <?php if ($p['id'] === 'tumblr'): ?>
            <div class="alert alert-warning small py-2 mb-3">
              <i class="fas fa-key me-1"></i><strong>API Credentials:</strong> Register at <a href="https://www.tumblr.com/oauth/apps" target="_blank">tumblr.com/oauth/apps</a>. Then click <strong>"Explore API"</strong> to authorize and get your OAuth tokens.
            </div>
            <div class="mb-3">
              <label class="form-label">Blog Hostname (e.g., skyrankseo.tumblr.com)</label>
              <input type="text" name="username" class="form-control" 
                     value="<?= clean($saved['username'] ?? '') ?>" placeholder="skyrankseo.tumblr.com" required>
            </div>
            <div class="mb-3">
              <label class="form-label">OAuth Consumer Key (API Key)</label>
              <input type="text" name="api_key" class="form-control" 
                     value="<?= clean($saved['api_key'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label">OAuth Consumer Secret (Secret Key)</label>
              <input type="password" name="api_secret" class="form-control" 
                     value="<?= clean($saved['api_secret'] ?? '') ?>" placeholder="<?= $saved ? '••••••••' : 'Enter Secret Key' ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label">OAuth Token (Access Token)</label>
              <input type="password" name="password" class="form-control" 
                     placeholder="<?= $saved ? '••••••••' : 'Enter Access Token' ?>" <?= $saved ? '' : 'required' ?>>
            </div>
            <div class="mb-3">
              <label class="form-label">OAuth Token Secret (Access Token Secret)</label>
              <input type="password" name="tumblr_token_secret" class="form-control" 
                     placeholder="<?= $saved ? '••••••••' : 'Enter Access Token Secret' ?>" <?= $saved ? '' : 'required' ?>>
            </div>
            <?php else: ?>
            <div class="mb-3">
              <label class="form-label">Username / Email</label>
              <input type="text" name="username" class="form-control" 
                     value="<?= clean($saved['username'] ?? '') ?>" required>
            </div>
            
            <div class="mb-3">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" 
                     placeholder="<?= $saved ? '••••••••' : 'Enter password' ?>" <?= $saved ? '' : 'required' ?>>
              <?php if ($saved): ?>
                <small class="text-muted">Leave blank to keep existing password</small>
              <?php endif; ?>
            </div>

            <?php if (!empty($p['needs_api']) && empty($p['login_only'])): ?>
            <div class="alert alert-warning small">
              <strong>API Required:</strong> <a href="<?= $p['api_url'] ?>" target="_blank">Get API keys here</a>
            </div>
            <div class="mb-3">
              <label class="form-label">API Key / Access Token</label>
              <input type="text" name="api_key" class="form-control" 
                     value="<?= clean($saved['api_key'] ?? '') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">API Secret / Blog ID</label>
              <input type="text" name="api_secret" class="form-control" 
                     value="<?= clean($saved['api_secret'] ?? '') ?>">
            </div>
            <?php elseif (!empty($p['needs_api'])): ?>
            <div class="alert alert-warning small">
              <strong>API Required:</strong> <a href="<?= $p['api_url'] ?>" target="_blank">Get API keys here</a>
            </div>
            <div class="mb-3">
              <label class="form-label">API Key / Access Token</label>
              <input type="text" name="api_key" class="form-control" 
                     value="<?= clean($saved['api_key'] ?? '') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">API Secret / Blog ID</label>
              <input type="text" name="api_secret" class="form-control" 
                     value="<?= clean($saved['api_secret'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary w-100">
              <i class="fas fa-save me-2"></i><?= $saved ? 'Update' : 'Save' ?> Credentials
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="card mt-4 border-success">
    <div class="card-header bg-success text-white">
      <h6 class="mb-0"><i class="fas fa-rocket me-2"></i>Next Steps</h6>
    </div>
    <div class="card-body">
      <ol class="mb-0">
        <li>Manually create accounts on Medium, WordPress, LinkedIn, etc.</li>
        <li>Generate API keys (links provided above)</li>
        <li>Save credentials here</li>
        <li>Go to your project → Backlinks tab → Click "Auto Post" button</li>
        <li>System will automatically post articles with backlinks!</li>
      </ol>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
