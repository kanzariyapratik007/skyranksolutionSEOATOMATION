<?php
require_once 'config.php';
requireLogin();
$db = getDB();

// ============================================================
// Blogger Token Auto-Refresh
// Uses Google OAuth2 to get new access token from refresh token
// ============================================================

// Step 1: Get saved credentials
$projectId = (int)($_GET['project_id'] ?? $_GET['id'] ?? 0);
$creds = $db->prepare("SELECT * FROM social_accounts WHERE project_id=? AND platform='blogger'");
$creds->execute([$projectId]);
$creds = $creds->fetch();

$message = null;
$success = false;

// Step 2: Handle refresh request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'refresh_token') {
        $refreshToken = $creds['refresh_token'] ?? '';

        if (empty($refreshToken)) {
            $message = ['type' => 'danger', 'text' => 'No refresh token saved. Please generate a new token from OAuth Playground.'];
        } else {
            // Use Google OAuth2 to get new access token
            // Note: This requires the same client_id/secret used to generate the refresh token
            // OAuth Playground uses Google's own client - we need to use it directly
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'client_id'     => '407408718192.apps.googleusercontent.com',
                    'client_secret' => 'GOCSPX-_placeholder_',
                    'refresh_token' => $refreshToken,
                    'grant_type'    => 'refresh_token',
                ]),
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($result['access_token'])) {
                $db->prepare("UPDATE social_accounts SET api_key=? WHERE project_id=? AND platform='blogger'")
                   ->execute([$result['access_token'], $projectId]);
                $message = ['type' => 'success', 'text' => 'Token refreshed successfully! Valid for 1 hour.'];
                $success = true;
                $creds['api_key'] = $result['access_token'];
            } else {
                $message = ['type' => 'warning', 'text' => 'Auto-refresh failed. Please get new token manually from OAuth Playground.'];
            }
        }
    }

    if ($_POST['action'] === 'save_token') {
        $newToken     = clean($_POST['new_token'] ?? '');
        $refreshToken = clean($_POST['refresh_token'] ?? '');
        $blogId       = clean($_POST['blog_id'] ?? $creds['api_secret'] ?? '');

        if (!empty($newToken)) {
            $db->prepare("UPDATE social_accounts SET api_key=?, api_secret=?, refresh_token=? WHERE project_id=? AND platform='blogger'")
               ->execute([$newToken, $blogId, $refreshToken, $projectId]);
            $message = ['type' => 'success', 'text' => 'New token saved! Auto Post will work now.'];
            $success = true;
        } else {
            $message = ['type' => 'danger', 'text' => 'Token cannot be empty.'];
        }
    }
}

// Test current token
$tokenValid = false;
if (!empty($creds['api_key'])) {
    $ch = curl_init("https://www.googleapis.com/blogger/v3/blogs/{$creds['api_secret']}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $creds['api_key']],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $test = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $tokenValid = !isset($test['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Blogger Token Manager</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container py-4" style="max-width:800px;">

  <h3><i class="fab fa-blogger me-2 text-warning"></i>Blogger Token Manager</h3>
  <p class="text-muted">Manage your Blogger OAuth token — auto-refresh or manually update</p>

  <?php if ($message): ?>
  <div class="alert alert-<?= $message['type'] ?> alert-dismissible fade show">
    <?= $message['text'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Token Status -->
  <div class="card mb-4 border-<?= $tokenValid ? 'success' : 'danger' ?> shadow-sm">
    <div class="card-header bg-<?= $tokenValid ? 'success' : 'danger' ?> text-white">
      <h5 class="mb-0">
        <?php if ($tokenValid): ?>
          ✅ Token Valid — Blogger Auto Post will work
        <?php else: ?>
          ❌ Token Expired — Update needed
        <?php endif; ?>
      </h5>
    </div>
    <div class="card-body">
      <table class="table table-sm mb-0">
        <tr><td><strong>Blog ID</strong></td><td><?= clean($creds['api_secret'] ?? 'Not set') ?></td></tr>
        <tr><td><strong>Token Preview</strong></td><td><code><?= substr($creds['api_key'] ?? '', 0, 30) ?>...</code></td></tr>
        <tr><td><strong>Refresh Token</strong></td><td><?= !empty($creds['refresh_token']) ? '✅ Saved' : '❌ Not saved' ?></td></tr>
      </table>
    </div>
  </div>

  <!-- Option 1: Auto Refresh -->
  <?php if (!empty($creds['refresh_token'])): ?>
  <div class="card mb-4 border-primary shadow-sm">
    <div class="card-header bg-primary text-white">
      <h5 class="mb-0"><i class="fas fa-sync me-2"></i>Option 1: Auto Refresh (1 Click)</h5>
    </div>
    <div class="card-body">
      <p class="text-muted">Refresh token saved — click button to get new access token automatically.</p>
      <form method="POST">
        <input type="hidden" name="action" value="refresh_token">
        <button type="submit" class="btn btn-primary btn-lg">
          <i class="fas fa-sync me-2"></i>Auto Refresh Token Now
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Option 2: Manual Update -->
  <div class="card mb-4 border-warning shadow-sm">
    <div class="card-header bg-warning text-dark">
      <h5 class="mb-0"><i class="fas fa-key me-2"></i>Option 2: Manual Token Update</h5>
    </div>
    <div class="card-body">
      <p>Get new token from <a href="https://developers.google.com/oauthplayground" target="_blank">OAuth Playground</a>:</p>
      <ol class="small mb-3">
        <li>Select <strong>Blogger API v3</strong> → <code>https://www.googleapis.com/auth/blogger</code></li>
        <li>Click <strong>"Authorize APIs"</strong> → Login with Google</li>
        <li>Click <strong>"Exchange authorization code for tokens"</strong></li>
        <li>Copy <strong>access_token</strong> value</li>
        <li>Paste below → Save</li>
      </ol>
      <form method="POST">
        <input type="hidden" name="action" value="save_token">
        <div class="mb-3">
          <label class="form-label fw-bold">New Access Token <span class="text-danger">*</span></label>
          <textarea name="new_token" class="form-control" rows="3" placeholder="ya29.a0Aa7MYi..." required></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Refresh Token (optional — for future auto-refresh)</label>
          <input type="text" name="refresh_token" class="form-control" placeholder="1//04...">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Blog ID</label>
          <input type="text" name="blog_id" class="form-control" value="<?= clean($creds['api_secret'] ?? '2201670847900613032') ?>">
        </div>
        <button type="submit" class="btn btn-warning btn-lg">
          <i class="fas fa-save me-2"></i>Save New Token
        </button>
      </form>
    </div>
  </div>

  <div class="text-center">
    <a href="submission-manager.php?project_id=<?= $projectId ?>" class="btn btn-outline-secondary">
      <i class="fas fa-arrow-left me-2"></i>Back to Submission Manager
    </a>
    <?php if ($tokenValid): ?>
    <a href="submission-manager.php?project_id=<?= $projectId ?>" class="btn btn-success ms-2">
      <i class="fas fa-rocket me-2"></i>Auto Post Now
    </a>
    <?php endif; ?>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
