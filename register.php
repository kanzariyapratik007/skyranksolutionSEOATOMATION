<?php
require_once 'config.php';
// Public registration is disabled. Only Admin can create accounts.
setFlash('danger', 'Public registration is disabled. Please contact the administrator to get login credentials.');
header('Location: index.php');
exit;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.'); header('Location: register.php'); exit;
    }
    $username = clean($_POST['username']);
    $email    = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if (strlen($password) < 6) {
        setFlash('danger', 'Password must be at least 6 characters.'); header('Location: register.php'); exit;
    }
    if ($password !== $confirm) {
        setFlash('danger', 'Passwords do not match.'); header('Location: register.php'); exit;
    }
    $db = getDB();
    
    // Self-healing migration for user role
    try {
        $db->exec("ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'client'");
    } catch (PDOException $e) {}

    $check = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        setFlash('danger', 'Username or email already exists.'); header('Location: register.php'); exit;
    }
    
    // Set first registered user as admin
    $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $role = ($count == 0) ? 'admin' : 'client';

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (username, password, email, role) VALUES (?,?,?,?)");
    $stmt->execute([$username, $hash, $email, $role]);
    setFlash('success', 'Account created! Please login. Role: ' . ucfirst($role));
    header('Location: index.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register - SEO 80/20 System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-logo">
      <i class="fas fa-chart-line"></i>
      <h2>Create Account</h2>
      <p>SEO 80/20 System</p>
    </div>
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
      <?= $flash['msg'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="mb-3">
        <label class="form-label">Username</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-user"></i></span>
          <input type="text" name="username" class="form-control" required>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Email</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-envelope"></i></span>
          <input type="email" name="email" class="form-control" required>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-lock"></i></span>
          <input type="password" name="password" class="form-control" required>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Confirm Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-lock"></i></span>
          <input type="password" name="confirm_password" class="form-control" required>
        </div>
      </div>
      <button type="submit" class="btn btn-success w-100 btn-lg">
        <i class="fas fa-user-plus me-2"></i>Register
      </button>
    </form>
    <div class="text-center mt-3">
      <a href="index.php" class="text-decoration-none">Already have an account? <strong>Login</strong></a>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
