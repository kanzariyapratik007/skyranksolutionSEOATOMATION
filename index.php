<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}
$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: index.php'); exit;
    }
    $username = clean($_POST['username']);
    $password = $_POST['password'];
    $db = getDB();
    
    // Self-healing migration for user role
    try {
        $db->exec("ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'client'");
    } catch (PDOException $e) {}

    $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'] ?: 'client';
        header('Location: ' . SITE_URL . '/dashboard.php'); exit;
    } else {
        setFlash('danger', 'Invalid username or password.');
        header('Location: index.php'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - SEO 80/20 System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-logo">
      <i class="fas fa-chart-line"></i>
      <h2>SEO 80/20 System</h2>
      <p>80% Auto · 20% Manual</p>
    </div>
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
      <?= $flash['msg'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="login" value="1">
      <div class="mb-3">
        <label class="form-label">Username or Email</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-user"></i></span>
          <input type="text" name="username" class="form-control" placeholder="Enter username" required>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-lock"></i></span>
          <input type="password" name="password" class="form-control" placeholder="Enter password" required>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">
        <i class="fas fa-sign-in-alt me-2"></i>Login
      </button>
    </form>
    <div class="text-center mt-3">
    </div>
    <div class="text-center mt-2">
      <a href="setup.php" class="text-muted small">System setup check</a>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
