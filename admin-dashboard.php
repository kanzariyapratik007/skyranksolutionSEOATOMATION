<?php
// ============================================================
// admin-dashboard.php
// Admin Control Center to manage SMTP settings, view client lists,
// and trigger onboarding setup emails.
// ============================================================

require_once 'config.php';
// Check if the user is allowed to access the admin dashboard or the user manager
requireLogin();
$isAdmin = (($_SESSION['role'] ?? 'client') === 'admin');
$hasPerm = false;

if (!$isAdmin) {
    // If not admin, check if they have either admin-panel or create-login-account permission
    $userId = $_SESSION['user_id'] ?? 0;
    if ($userId > 0) {
        $db = getDB();
        $stmt = $db->prepare("SELECT allowed_menus FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        if ($val !== null && $val !== '') {
            $allowed = array_map('trim', explode(',', strtolower($val)));
            if (in_array('admin-panel', $allowed) || in_array('create-login-account', $allowed)) {
                $hasPerm = true;
            }
        }
    }
    if (!$hasPerm) {
        setFlash('danger', 'Access denied. You do not have permission to access that page.');
        header('Location: dashboard.php');
        exit;
    }
}

$db = getDB();

// User Management Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: admin-dashboard.php');
        exit;
    }
    $username = clean($_POST['username'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $role = clean($_POST['role'] ?? 'client');

    if (empty($username) || empty($email) || empty($password)) {
        setFlash('danger', 'All fields are required.');
    } elseif (strlen($password) < 6) {
        setFlash('danger', 'Password must be at least 6 characters.');
    } else {
        $chk = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            setFlash('danger', 'Username or Email already exists.');
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $email, $role]);
            setFlash('success', 'User ' . htmlspecialchars($username) . ' created successfully!');
        }
    }
    header('Location: admin-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: admin-dashboard.php');
        exit;
    }
    $delId = (int)($_POST['user_id'] ?? 0);
    if ($delId === (int)$_SESSION['user_id']) {
        setFlash('danger', 'You cannot delete your own admin account!');
    } else {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$delId]);
        setFlash('success', 'User account deleted.');
    }
    header('Location: admin-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: admin-dashboard.php');
        exit;
    }
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';

    if (strlen($newPassword) < 6) {
        setFlash('danger', 'Password must be at least 6 characters.');
    } else {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hash, $targetUserId]);
        setFlash('success', 'Password changed successfully!');
    }
    header('Location: admin-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_permissions'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: admin-dashboard.php');
        exit;
    }
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $selectedMenus = $_POST['allowed_menus'] ?? [];
    
    // Clean and validate menus
    $cleanedMenus = array_map('clean', $selectedMenus);
    $menuStr = implode(',', $cleanedMenus);
    
    $stmt = $db->prepare("UPDATE users SET allowed_menus = ? WHERE id = ?");
    $stmt->execute([$menuStr, $targetUserId]);
    setFlash('success', 'User permissions updated successfully!');
    header('Location: admin-dashboard.php');
    exit;
}
$userId = $_SESSION['user_id'] ?? 0;

// Dynamic database schema updates for Backlink Verifier & Onboarding Status
try {
    $db->exec("ALTER TABLE backlinks ADD COLUMN verified_status VARCHAR(50) DEFAULT 'unverified'");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE backlinks ADD COLUMN last_checked_at DATETIME DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE projects ADD COLUMN onboarding_submitted INT DEFAULT 0");
    $db->exec("ALTER TABLE projects ADD COLUMN onboarding_submitted_at DATETIME DEFAULT NULL");
} catch (PDOException $e) {}

// Handle SMTP Credentials Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_smtp'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: admin-dashboard.php');
        exit;
    }
    
    $smtp_host = clean($_POST['smtp_host'] ?? 'smtp.gmail.com');
    $smtp_port = (int)($_POST['smtp_port'] ?? 587);
    $smtp_user = clean($_POST['smtp_user'] ?? '');
    $smtp_pass = clean($_POST['smtp_pass'] ?? '');

    $existing = is_readable(__DIR__ . '/config.local.php')
        ? (array) include __DIR__ . '/config.local.php'
        : [];
        
    $keys = [
        'SMTP_HOST' => $smtp_host,
        'SMTP_PORT' => $smtp_port,
        'SMTP_USER' => $smtp_user,
        'SMTP_PASS' => $smtp_pass
    ];
    
    $merged = array_merge($existing, $keys);
    
    $export = "<?php\n// Auto-saved from SMTP Admin Setup — " . date('Y-m-d H:i') . "\nreturn " . var_export($merged, true) . ";\n";
    if (file_put_contents(__DIR__ . '/config.local.php', $export) !== false) {
        setFlash('success', 'SMTP settings updated successfully! System mailer is now configured.');
    } else {
        setFlash('danger', 'Could not write to config.local.php. Please check file permissions.');
    }
    header('Location: admin-dashboard.php');
    exit;
}

// Handle AJAX Onboarding Email Send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_onboarding_email_ajax'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $clientId = (int)$_POST['client_id'];
    
    // Fetch project details
    $stmt = $db->prepare("SELECT * FROM projects WHERE id=?");
    $stmt->execute([$clientId]);
    $proj = $stmt->fetch();
    
    if (!$proj) {
        echo json_encode(['success' => false, 'error' => 'Client profile not found.']);
        exit;
    }
    
    $clientEmail = trim($proj['email'] ?? '');
    if (empty($clientEmail)) {
        echo json_encode(['success' => false, 'error' => 'Client email is empty. Please configure a contact email first.']);
        exit;
    }
    
    require_once __DIR__ . '/includes/mailer.php';
    
    $subject = "Welcome to " . SITE_NAME . "! SEO Campaign Onboarding Details 🚀";
    
    $agencyName  = SITE_NAME;
    $agencyEmail = SMTP_USER;
    $website     = $proj['website_url'];
    $keyword     = $proj['target_keyword'];
    $onboardingUrl = SITE_URL . "/client-onboarding.php?id=" . $clientId . "&token=" . getOnboardingToken($clientId, $website);
    
    // Generate beautiful bilingual email body
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 650px; margin: 0 auto; padding: 25px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff; color: #2d3748; line-height: 1.6;'>
        <div style='text-align: center; border-bottom: 2px solid #edf2f7; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #3182ce; margin: 0;'>SEO Onboarding Details Checklist</h2>
            <p style='color: #718096; margin: 5px 0 0 0;'>Welcome to {$agencyName} Campaign</p>
        </div>
        
        <p>Dear Partner,</p>
        <p>We are excited to begin optimizing your website SEO for <strong>{$website}</strong> targeting keyword <strong>\"{$keyword}\"</strong>.</p>
        
        <div style='background-color: #ebf8ff; padding: 20px; border: 1px dashed #3182ce; border-radius: 8px; text-align: center; margin: 20px 0;'>
            <h4 style='margin-top: 0; color: #2b6cb0;'>📋 Submit Your Website & Tracking Details:</h4>
            <p style='font-size: 13px; color: #4a5568; margin-bottom: 15px;'>
                Please click the button below to fill in your WordPress credentials & tracking IDs directly. Our automated setup robot will then configure your tags instantly!
                <br>Please click the button below to fill in the form and set up automatic Google tags and schema on your site.
            </p>
            <a href='{$onboardingUrl}' target='_blank' style='background-color: #3182ce; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; font-size: 15px;'>
                👉 Open Campaign Setup Form
            </a>
        </div>

        <p>Alternatively, you may manually provide the access details listed below:</p>
        
        <hr style='border:0; border-top:1px solid #edf2f7; margin:20px 0;'>

        <!-- 1. WordPress CMS Credentials -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-lock'></i> 1. Website WordPress CMS Access</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> Our automated robot needs login credentials to auto-inject tracking codes, schema data, and resolve meta issues instantly without disturbing your developers.
            </p>
            <p style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>Required Details:</strong><br>
                • Admin Login URL (e.g. <code>https://yoursite.com/wp-admin</code>)<br>
                • Username / Email Address<br>
                • Password
            </p>
        </div>

        <!-- 2. Google Search Console Access -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fab fa-google'></i> 2. Google Search Console (GSC) Access</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> To verify indexation health and monitor live Google ranking shifts.
            </p>
            <div style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>How to Grant Owner Access:</strong><br>
                1. Go to <a href='https://search.google.com/search-console' target='_blank'>Search Console Dashboard</a>.<br>
                2. Click on <strong>Settings</strong> in the left sidebar → Select <strong>Users and permissions</strong>.<br>
                3. Click the blue <strong>Add User</strong> button.<br>
                4. Enter email: <code>{$agencyEmail}</code> and select <strong>Owner</strong> or <strong>Full</strong> permission.<br>
                5. Click Add.
            </div>
        </div>

        <!-- 3. Google Analytics (GA4) ID -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-chart-line'></i> 3. Google Analytics (GA4) ID</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> To measure incoming search traffic and analyze organic visitor statistics.
            </p>
            <div style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>How to Find Measurement ID:</strong><br>
                1. Open <a href='https://analytics.google.com' target='_blank'>Google Analytics</a>.<br>
                2. Click on the <strong>Admin</strong> gear icon in the bottom-left corner.<br>
                3. Click on <strong>Data Streams</strong> in the second column → Click your Web stream.<br>
                4. Copy the <strong>Measurement ID</strong> in the top right starting with <code>G-</code> (e.g., <code>G-XXXXXXXXXX</code>).
            </div>
        </div>

        <!-- 4. Google Ads Conversion ID -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-ad'></i> 4. Google Ads Conversion ID (Optional)</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> To track phone calls, WhatsApp inquiries, and contact submissions from advertising.
            </p>
            <div style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>How to Find Conversion ID:</strong><br>
                1. Open <a href='https://ads.google.com' target='_blank'>Google Ads</a>.<br>
                2. Go to <strong>Tools and Settings</strong> → <strong>Conversions</strong> → click on <strong>Google Tag</strong>.<br>
                3. Copy the Conversion ID starting with <code>AW-</code> (e.g., <code>AW-XXXXXXXXXX</code>).
            </div>
        </div>

        <!-- 5. Local Business Map Details -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-map-marker-alt'></i> 5. Local Business Map Details</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> To structure local SEO schema code and boost your business profile on Google Maps searches.
            </p>
            <p style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>Details needed:</strong><br>
                • Exact Business Address (as shown on Google Maps)<br>
                • Active Business Phone Number<br>
                • Operating Hours (e.g. Mon-Sat 9AM-7PM)
            </p>
        </div>

        <!-- 6. Competitors list -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-users'></i> 6. Top 3 Competitors</h3>
            <p style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                Provide the URLs/names of 3 competitor sites in your industry so we can perform comparative backlink profiling.
            </p>
        </div>

        <hr style='border:0; border-top:1px solid #edf2f7; margin:20px 0;'>

        <div style='background-color: #f0fff4; padding: 15px; border-left: 4px solid #38a169; border-radius: 4px; margin: 20px 0; font-size:14px; line-height: 1.6;'>
            <h4 style='margin-top: 0; color: #276749;'>🛠️ Campaign Deliverables & Exact Outputs:</h4>
            <ul style='margin: 0; padding-left: 20px;'>
                <li><strong>GSC & GA4 Auto-Setup:</strong> We will verify your site on Google Search Console, install Google Analytics (GA4), and set up Google Ads conversion tracking tags automatically via Selenium.</li>
                <li><strong>Local Maps SEO:</strong> Dynamic JSON-LD Schema code injection on your site and automated promotional updates posted to Google Maps.</li>
                <li><strong>Daily Backlinks:</strong> Creation and propagation of daily backlinks using unique 1200-1500 words promotional articles with index pinging.</li>
                <li><strong>Link Status Monitor:</strong> 24/7 scanning of created backlinks to notify and alert us of any deleted or broken links.</li>
                <li><strong>Weekly Reports:</strong> Detailed ranking graphs, traffic reports, and active backlinks summary emailed to you every Monday morning.</li>
            </ul>
        </div>
        
        <p>Please reply to this email with the requested access details at your earliest convenience.</p>
        <p>Warm regards,</p>
        <p><strong>{$agencyName} Onboarding Team</strong></p>
        <p style='font-size: 11px; color: #a0aec0; text-align: center; margin-top: 30px;'>This is an automated onboarding checklist email sent via {$agencyName} Admin Console.</p>
    </div>
    ";
    
    $sent = sendSmtpMail($clientEmail, $subject, $body);
    if ($sent) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email failed to send. Check SMTP connection in Admin settings.']);
    }
    exit;
}

// Handle AJAX Quick Onboarding Email Send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_quick_email_ajax'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $email   = trim($_POST['email'] ?? '');
    $website = trim($_POST['website'] ?? 'your website');
    $keyword = trim($_POST['keyword'] ?? 'your keywords');
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'error' => 'Email address is required.']);
        exit;
    }
    
    require_once __DIR__ . '/includes/mailer.php';
    
    $subject = "Welcome to " . SITE_NAME . "! SEO Onboarding & Details Checklist 🚀";
    $agencyName = SITE_NAME;
    $agencyEmail = SMTP_USER;
    
    // Find project ID for this email if it exists
    $stmt = $db->prepare("SELECT id, website_url FROM projects WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $existingProj = $stmt->fetch();
    
    $onboardingUrl = '';
    if ($existingProj) {
        $onboardingUrl = SITE_URL . "/client-onboarding.php?id=" . $existingProj['id'] . "&token=" . getOnboardingToken($existingProj['id'], $existingProj['website_url']);
    }
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 650px; margin: 0 auto; padding: 25px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff; color: #2d3748; line-height: 1.6;'>
        <div style='text-align: center; border-bottom: 2px solid #edf2f7; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #3182ce; margin: 0;'>SEO Onboarding Details Checklist</h2>
            <p style='color: #718096; margin: 5px 0 0 0;'>Welcome to {$agencyName} Campaign</p>
        </div>
        
        <p>Dear Partner,</p>
        <p>We are excited to begin optimizing your website SEO for <strong>{$website}</strong> targeting keyword <strong>\"{$keyword}\"</strong>.</p>
        
        " . ($onboardingUrl ? "
        <div style='background-color: #ebf8ff; padding: 20px; border: 1px dashed #3182ce; border-radius: 8px; text-align: center; margin: 20px 0;'>
            <h4 style='margin-top: 0; color: #2b6cb0;'>📋 Submit Your Website & Tracking Details:</h4>
            <p style='font-size: 13px; color: #4a5568; margin-bottom: 15px;'>
                Please click the button below to fill in your WordPress credentials & tracking IDs directly. Our automated setup robot will then configure your tags instantly!
                <br>Please click the button below to fill in the form and set up automatic Google tags and schema on your site.
            </p>
            <a href='{$onboardingUrl}' target='_blank' style='background-color: #3182ce; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; font-size: 15px;'>
                👉 Open Campaign Setup Form
            </a>
        </div>
        <p>Alternatively, you may manually provide the access details listed below:</p>
        " : "
        <p>To launch your campaign, please share the following website credentials and access details:</p>
        ") . "
        
        <hr style='border:0; border-top:1px solid #edf2f7; margin:20px 0;'>

        <!-- 1. WordPress CMS Credentials -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-lock'></i> 1. Website WordPress CMS Access</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> Our automated robot needs login credentials to auto-inject tracking codes, schema data, and resolve meta issues instantly without disturbing your developers.
            </p>
            <p style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>Required Details:</strong><br>
                • Admin Login URL (e.g. <code>https://yoursite.com/wp-admin</code>)<br>
                • Username / Email Address<br>
                • Password
            </p>
        </div>

        <!-- 2. Google Search Console Access -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fab fa-google'></i> 2. Google Search Console (GSC) Access</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> To verify indexation health and monitor live Google ranking shifts.
            </p>
            <div style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>How to Grant Owner Access:</strong><br>
                1. Go to <a href='https://search.google.com/search-console' target='_blank'>Search Console Dashboard</a>.<br>
                2. Click on <strong>Settings</strong> in the left sidebar → Select <strong>Users and permissions</strong>.<br>
                3. Click the blue <strong>Add User</strong> button.<br>
                4. Enter email: <code>{$agencyEmail}</code> and select <strong>Owner</strong> or <strong>Full</strong> permission.<br>
                5. Click Add.
            </div>
        </div>

        <!-- 3. Google Analytics (GA4) ID -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-chart-line'></i> 3. Google Analytics (GA4) ID</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> To measure incoming search traffic and analyze organic visitor statistics.
            </p>
            <div style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>How to Find Measurement ID:</strong><br>
                1. Open <a href='https://analytics.google.com' target='_blank'>Google Analytics</a>.<br>
                2. Click on the <strong>Admin</strong> gear icon in the bottom-left corner.<br>
                3. Click on <strong>Data Streams</strong> in the second column → Click your Web stream.<br>
                4. Copy the <strong>Measurement ID</strong> in the top right starting with <code>G-</code> (e.g., <code>G-XXXXXXXXXX</code>).
            </div>
        </div>

        <!-- 4. Google Ads Conversion ID -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-ad'></i> 4. Google Ads Conversion ID (Optional)</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> To track phone calls, WhatsApp inquiries, and contact submissions from advertising.
            </p>
            <div style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>How to Find Conversion ID:</strong><br>
                1. Open <a href='https://ads.google.com' target='_blank'>Google Ads</a>.<br>
                2. Go to <strong>Tools and Settings</strong> → <strong>Conversions</strong> → click on <strong>Google Tag</strong>.<br>
                3. Copy the Conversion ID starting with <code>AW-</code> (e.g., <code>AW-XXXXXXXXXX</code>).
            </div>
        </div>

        <!-- 5. Local Business Map Details -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-map-marker-alt'></i> 5. Local Business Map Details</h3>
            <p style='margin: 0; font-size:13px; color:#718096;'>
                <strong>Why we need this:</strong> To structure local SEO schema code and boost your business profile on Google Maps searches.
            </p>
            <p style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                <strong>Details needed:</strong><br>
                • Exact Business Address (as shown on Google Maps)<br>
                • Active Business Phone Number<br>
                • Operating Hours (e.g. Mon-Sat 9AM-7PM)
            </p>
        </div>

        <!-- 6. Competitors list -->
        <div style='margin-bottom: 25px;'>
            <h3 style='color: #2b6cb0; margin-bottom: 5px; font-size:16px;'><i class='fas fa-users'></i> 6. Top 3 Competitors</h3>
            <p style='margin: 5px 0 0 0; font-size:14px; background:#f7fafc; padding:10px; border-radius:4px;'>
                Provide the URLs/names of 3 competitor sites in your industry so we can perform comparative backlink profiling.
            </p>
        </div>

        <hr style='border:0; border-top:1px solid #edf2f7; margin:20px 0;'>

        <div style='background-color: #f0fff4; padding: 15px; border-left: 4px solid #38a169; border-radius: 4px; margin: 20px 0; font-size:14px; line-height: 1.6;'>
            <h4 style='margin-top: 0; color: #276749;'>🛠️ Campaign Deliverables & Exact Outputs:</h4>
            <ul style='margin: 0; padding-left: 20px;'>
                <li><strong>GSC & GA4 Auto-Setup:</strong> We will verify your site on Google Search Console, install Google Analytics (GA4), and set up Google Ads conversion tracking tags automatically via Selenium.</li>
                <li><strong>Local Maps SEO:</strong> Dynamic JSON-LD Schema code injection on your site and automated promotional updates posted to Google Maps.</li>
                <li><strong>Daily Backlinks:</strong> Creation and propagation of daily backlinks using unique 1200-1500 words promotional articles with index pinging.</li>
                <li><strong>Link Status Monitor:</strong> 24/7 scanning of created backlinks to notify and alert us of any deleted or broken links.</li>
                <li><strong>Weekly Reports:</strong> Detailed ranking graphs, traffic reports, and active backlinks summary emailed to you every Monday morning.</li>
            </ul>
        </div>
        
        <p>Please reply to this email with the requested access details at your earliest convenience.</p>
        <p>Warm regards,</p>
        <p><strong>{$agencyName} Onboarding Team</strong></p>
        <p style='font-size: 11px; color: #a0aec0; text-align: center; margin-top: 30px;'>This is an automated onboarding checklist email sent via {$agencyName} Admin Console.</p>
    </div>
    ";
    
    $sent = sendSmtpMail($email, $subject, $body);
    if ($sent) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email failed to send. Check SMTP connection settings.']);
    }
    exit;
}

// Fetch stats for Admin overview
$totalProjects = (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$totalLinks    = (int)$db->query("SELECT COUNT(*) FROM backlinks WHERE status='created'")->fetchColumn();
$totalAccounts = (int)$db->query("SELECT COUNT(*) FROM social_accounts")->fetchColumn();
$brokenLinks   = (int)$db->query("SELECT COUNT(*) FROM backlinks WHERE verified_status='broken'")->fetchColumn();

// Fetch all projects/clients
$projects = $db->query("SELECT * FROM projects ORDER BY id DESC")->fetchAll();

// Fetch all registered users
$allUsers = $db->query("
    SELECT 
        u.id, 
        u.username, 
        u.email, 
        u.role, 
        u.created_at,
        u.allowed_menus,
        (SELECT COUNT(*) FROM projects p WHERE p.user_id = u.id) AS total_projects,
        (SELECT COUNT(b.id) FROM backlinks b JOIN projects p ON b.project_id = p.id WHERE p.user_id = u.id AND b.status = 'created') AS total_backlinks,
        (SELECT CONCAT(b.platform, '::', b.created_at) FROM backlinks b JOIN projects p ON b.project_id = p.id WHERE p.user_id = u.id AND b.status = 'created' ORDER BY b.created_at DESC LIMIT 1) AS latest_activity
    FROM users u
    ORDER BY u.id DESC
")->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard & SMTP Control - SEO 80/20</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
<style>
  .card-stat {
    border: none;
    border-radius: 12px;
    transition: transform 0.2s;
  }
  .card-stat:hover {
    transform: translateY(-4px);
  }
</style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="container py-4">
  
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h3 class="mb-0 fw-bold text-dark"><i class="fas fa-user-shield text-danger me-2"></i>Admin Dashboard</h3>
      <p class="text-muted small mb-0">Manage Global SMTP settings and send client onboarding emails dynamically.</p>
    </div>
    <span class="badge bg-danger p-2"><i class="fas fa-lock me-1"></i>Administrator Control</span>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
      <?= $flash['msg'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Stats Grid -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card card-stat bg-primary text-white shadow-sm">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <h6 class="text-white-50 mb-1">Total Clients</h6>
            <h2 class="fw-bold mb-0"><?= $totalProjects ?></h2>
          </div>
          <i class="fas fa-users fa-2x text-white-50"></i>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card card-stat bg-success text-white shadow-sm">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <h6 class="text-white-50 mb-1">Active Backlinks</h6>
            <h2 class="fw-bold mb-0"><?= $totalLinks ?></h2>
          </div>
          <i class="fas fa-link fa-2x text-white-50"></i>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card card-stat bg-info text-white shadow-sm">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <h6 class="text-white-50 mb-1">Social Logins</h6>
            <h2 class="fw-bold mb-0"><?= $totalAccounts ?></h2>
          </div>
          <i class="fas fa-key fa-2x text-white-50"></i>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card card-stat bg-danger text-white shadow-sm">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <h6 class="text-white-50 mb-1">Broken Links</h6>
            <h2 class="fw-bold mb-0"><?= $brokenLinks ?></h2>
          </div>
          <i class="fas fa-exclamation-triangle fa-2x text-white-50"></i>
        </div>
      </div>
  </div>

  <!-- Quick Actions / Pitch Generator Callout -->
  <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%); border-radius: 16px;">
    <div class="card-body p-4 text-white d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <h5 class="fw-bold mb-1"><i class="fas fa-file-invoice me-2"></i>Client Pitch PDF Report Generator</h5>
        <p class="mb-0 text-white-50 small">Scan a lead's website, identify SEO issues in real-time, and download a beautiful white-label PDF proposal to close new clients.</p>
      </div>
      <a href="client-pitch.php" class="btn btn-light text-primary fw-bold px-4 py-2.5 rounded-pill shadow-sm">
        <i class="fas fa-magic me-2"></i>Generate Pitch Report Now
      </a>
    </div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="row g-4">
    <!-- LEFT: Clients List & Onboarding triggers -->
    <div class="col-lg-8">
      <div class="card border-0 bg-light shadow-sm">
        <div class="card-header bg-dark text-white fw-bold">
          <i class="fas fa-list me-2"></i>Active Clients List
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-secondary">
                <tr>
                  <th>#</th>
                  <th>Client / Keyword</th>
                  <th>Email Address</th>
                  <th>Onboard Form Status</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($projects)): ?>
                  <tr>
                    <td colspan="5" class="text-center text-muted p-4">No client projects registered yet. Click Add Project menu above.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($projects as $i => $proj): ?>
                    <tr>
                      <td><?= $i + 1 ?></td>
                      <td>
                        <strong class="text-dark"><?= clean($proj['business_name'] ?: 'No Business Name') ?></strong>
                        <br><small class="text-muted"><?= clean($proj['website_url']) ?></small>
                        <br><span class="badge bg-secondary" style="font-size:10px;">Keyword: <?= clean($proj['target_keyword']) ?></span>
                      </td>
                      <td>
                        <?= !empty($proj['email']) ? clean($proj['email']) : '<em class="text-danger small">No email configured</em>' ?>
                      </td>
                      <td>
                        <?php if (isset($proj['onboarding_submitted']) && $proj['onboarding_submitted'] == 1): ?>
                          <span class="badge bg-success" style="font-size:11px;" title="Submitted on: <?= htmlspecialchars($proj['onboarding_submitted_at']) ?>">
                            <i class="fas fa-check-circle me-1"></i>Submitted
                          </span>
                        <?php else: ?>
                          <span class="badge bg-warning text-dark" style="font-size:11px;">
                            <i class="fas fa-clock me-1"></i>Pending
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="text-center">
                        <div class="btn-group btn-group-sm">
                          <a href="client-profile.php?id=<?= $proj['id'] ?>" class="btn btn-outline-primary" title="Edit Profile">
                            <i class="fas fa-edit"></i> Profile
                          </a>
                          <button class="btn btn-warning text-dark fw-bold" 
                                  onclick="sendOnboardingEmail(<?= $proj['id'] ?>, '<?= clean($proj['email']) ?>', this)"
                                  <?= empty($proj['email']) ? 'disabled' : '' ?> title="Send Welcome & Details Checklist Email">
                            <i class="fas fa-paper-plane"></i> Send Mail
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT: Quick Send Onboarding & SMTP Config -->
    <div class="col-lg-4">
      <!-- Quick Onboarding Mail Box -->
      <div class="card border-0 bg-light shadow-sm mb-4">
        <div class="card-header bg-warning text-dark fw-bold">
          <i class="fas fa-paper-plane me-2"></i>Quick Send Onboarding Mail
        </div>
        <div class="card-body">
          <p class="small text-muted mb-3">
            Fill in the form below to send onboarding details directly by typing client email:
          </p>
          <div class="mb-3">
            <label class="form-label fw-bold">Client Email <span class="text-danger">*</span></label>
            <input type="email" id="quickEmail" class="form-control" placeholder="client@example.com" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Website URL (Optional)</label>
            <input type="text" id="quickUrl" class="form-control" placeholder="e.g., https://example.com">
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Target Keyword (Optional)</label>
            <input type="text" id="quickKeyword" class="form-control" placeholder="e.g., car detailing in rajkot">
          </div>
          <button type="button" class="btn btn-warning text-dark fw-bold w-100" onclick="sendQuickOnboardingMail(this)">
            <i class="fas fa-envelope-open-text me-1"></i>Send Onboarding Checklist
          </button>
        </div>
      </div>

      <div class="card border-0 bg-light shadow-sm">
        <div class="card-header bg-danger text-white fw-bold">
          <i class="fas fa-cog me-2"></i>Global SMTP Settings
        </div>
        <div class="card-body">
          <p class="small text-muted mb-3">
            Set your email and **Gmail App Password** here so the system can automatically send onboarding and weekly reports to clients.
          </p>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            
            <div class="mb-3">
              <label class="form-label fw-bold">SMTP Host</label>
              <input type="text" name="smtp_host" class="form-control" value="<?= clean(SMTP_HOST) ?>" required>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">SMTP Port</label>
              <select name="smtp_port" class="form-select">
                <option value="587" <?= SMTP_PORT === 587 ? 'selected' : '' ?>>587 (TLS - Recommended)</option>
                <option value="465" <?= SMTP_PORT === 465 ? 'selected' : '' ?>>465 (SSL)</option>
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">Sender Email (Username)</label>
              <input type="email" name="smtp_user" class="form-control" value="<?= clean(SMTP_USER) ?>" placeholder="your-email@gmail.com" required>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">Gmail App Password</label>
              <input type="password" name="smtp_pass" class="form-control" value="<?= clean(SMTP_PASS) ?>" placeholder="16-character google app password" required>
              <div class="form-text small text-danger" style="font-size:11px;">
                * Standard Gmail password will not work. Go to Google Account -> Security -> App Passwords to create a 16-character app password.
              </div>
            </div>

            <button type="submit" name="save_smtp" class="btn btn-danger w-100 fw-bold">
              <i class="fas fa-save me-1"></i>Save SMTP Settings
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- User Access Management Section -->
  <div class="row g-4 mt-2">
    <div class="col-12">
      <div class="card border-0 bg-light shadow-sm" id="user-accounts">
        <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
          <span><i class="fas fa-users-cog me-2"></i>User Access & Client Accounts Management</span>
          <span class="badge bg-light text-primary fw-bold"><?= count($allUsers) ?> Accounts</span>
        </div>
        <div class="card-body">
          <div class="row">
            <!-- Left Side: Create New Account Form -->
            <div class="col-md-4 border-end">
              <h5 class="fw-bold mb-3 text-secondary"><i class="fas fa-user-plus me-2"></i>Create Login Account</h5>
              <form method="POST" action="admin-dashboard.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="create_user" value="1">
                
                <div class="mb-3">
                  <label class="form-label fw-bold">Username <span class="text-danger">*</span></label>
                  <input type="text" name="username" class="form-control" placeholder="e.g. client_pratik" required>
                </div>
                
                <div class="mb-3">
                  <label class="form-label fw-bold">Email Address <span class="text-danger">*</span></label>
                  <input type="email" name="email" class="form-control" placeholder="e.g. pratik@example.com" required>
                </div>
                
                <div class="mb-3">
                  <label class="form-label fw-bold">Password <span class="text-danger">*</span></label>
                  <input type="password" name="password" class="form-control" placeholder="Min 6 characters" required minlength="6">
                </div>

                <div class="mb-3">
                  <label class="form-label fw-bold">Role</label>
                  <input type="text" class="form-control bg-light text-muted" value="Client / Client Profile Access" readonly disabled>
                  <input type="hidden" name="role" value="client">
                </div>
                
                <button type="submit" class="btn btn-primary w-100 fw-bold">
                  <i class="fas fa-plus me-1"></i>Create Account
                </button>
              </form>
            </div>
            
            <!-- Right Side: User Accounts List -->
            <div class="col-md-8">
              <h5 class="fw-bold mb-3 text-secondary"><i class="fas fa-list me-2"></i>Registered Accounts</h5>
              <div class="table-responsive">
                <table class="table table-striped align-middle">
                  <thead>
                    <tr>
                      <th>Username</th>
                      <th>Email</th>
                      <th>Role</th>
                      <th>Projects</th>
                      <th>Backlinks</th>
                      <th>Latest Activity</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($allUsers as $usr): ?>
                      <?php 
                      $actStr = $usr['latest_activity'] ?? '';
                      $actPlatform = '';
                      $actTime = '';
                      if (!empty($actStr)) {
                          $actParts = explode('::', $actStr);
                          $actPlatform = $actParts[0] ?? '';
                          $actTime = isset($actParts[1]) ? date('d M H:i', strtotime($actParts[1])) : '';
                      }
                      ?>
                      <tr>
                        <td>
                          <a href="user-profile.php?id=<?= $usr['id'] ?>" class="text-primary text-decoration-none fw-bold" title="View Full Profile & Passwords">
                            <?= htmlspecialchars($usr['username']) ?> <i class="fas fa-external-link-alt" style="font-size: 10px;"></i>
                          </a>
                          <?php if ($usr['id'] === (int)$_SESSION['user_id']): ?>
                            <span class="badge bg-success ms-1">You</span>
                          <?php endif; ?>
                        </td>
                        <td><small><?= htmlspecialchars($usr['email']) ?></small></td>
                        <td>
                          <span class="badge bg-<?= $usr['role'] === 'admin' ? 'danger' : 'info' ?>">
                            <?= ucfirst($usr['role']) ?>
                          </span>
                        </td>
                        <td>
                          <span class="badge bg-light text-dark border"><?= $usr['total_projects'] ?></span>
                        </td>
                        <td>
                          <span class="badge bg-success"><?= $usr['total_backlinks'] ?></span>
                        </td>
                        <td>
                          <?php if (!empty($actPlatform)): ?>
                            <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($actPlatform)) ?></span>
                            <br><small class="text-muted" style="font-size:10px;"><?= $actTime ?></small>
                          <?php else: ?>
                            <em class="text-muted small">No activity yet</em>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                          <div class="d-inline-flex gap-1">
                            <!-- View Profile & Credentials -->
                            <a href="user-profile.php?id=<?= $usr['id'] ?>" class="btn btn-sm btn-outline-info" title="View User Profile & Passwords">
                              <i class="fas fa-user-circle"></i>
                            </a>

                            <!-- Edit Permissions Action -->
                            <button class="btn btn-sm btn-outline-primary" 
                                    onclick="showPermissionsModal(<?= $usr['id'] ?>, '<?= htmlspecialchars(addslashes($usr['username'])) ?>', '<?= htmlspecialchars(addslashes($usr['allowed_menus'] ?? '')) ?>')"
                                    title="Edit Menu Permissions">
                              <i class="fas fa-tasks"></i>
                            </button>

                            <!-- Change Password Action -->
                            <button class="btn btn-sm btn-outline-warning" 
                                    onclick="showChangePasswordModal(<?= $usr['id'] ?>, '<?= htmlspecialchars(addslashes($usr['username'])) ?>')"
                                    title="Change Password">
                              <i class="fas fa-key"></i>
                            </button>
                            
                            <!-- Delete User Action -->
                            <?php if ($usr['id'] !== (int)$_SESSION['user_id']): ?>
                              <form method="POST" action="admin-dashboard.php" onsubmit="return confirm('Are you sure you want to delete user <?= htmlspecialchars($usr['username']) ?>?');" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="delete_user" value="1">
                                <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete User">
                                  <i class="fas fa-trash"></i>
                                </button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Change Password Modal -->
<div class="modal fade" id="passChangeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title fw-bold"><i class="fas fa-key me-2"></i>Change Password for <span id="passModalUsername"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="admin-dashboard.php">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="change_password" value="1">
          <input type="hidden" name="user_id" id="passModalUserId">
          
          <div class="mb-3">
            <label class="form-label fw-bold">New Password</label>
            <input type="password" name="new_password" class="form-control" placeholder="Min 6 characters" required minlength="6">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning fw-bold">Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Permissions Modal -->
<div class="modal fade" id="permissionsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title fw-bold"><i class="fas fa-tasks me-2"></i>Configure Allowed Menus for <span id="permModalUsername"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="admin-dashboard.php">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="update_permissions" value="1">
          <input type="hidden" name="user_id" id="permModalUserId">
          
          <p class="small text-muted mb-3">
            Configure allowed menus/tabs for this user:
          </p>
          
          <div class="row g-3">
            <?php
            $menuOptions = [
                'dashboard' => 'Dashboard',
                'add-project' => 'Add Project',
                'submissions' => 'Submissions',
                'api-keys' => 'API Keys',
                'admin-panel' => 'Admin Panel',
                'create-login-account' => 'Create Login Account',
                'cost-ranking' => 'Cost & Ranking',
                'auto-schedule' => 'Auto-Schedule',
                'ai-workflow' => 'AI Workflow',
                'google-console' => 'Google Console',
                'git-push-agent' => 'Git Push Agent',
                'how-to-use' => 'How to Use'
            ];
            foreach ($menuOptions as $mCode => $mLabel):
            ?>
              <div class="col-md-6 col-lg-4">
                <div class="form-check card p-2 bg-light border-0">
                  <div class="d-flex align-items-center">
                    <input class="form-check-input ms-0 me-2 perm-checkbox" type="checkbox" name="allowed_menus[]" value="<?= $mCode ?>" id="chk_<?= $mCode ?>">
                    <label class="form-check-label fw-bold text-dark mb-0" for="chk_<?= $mCode ?>">
                      <?= $mLabel ?>
                    </label>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-bold">Save Permissions</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function sendOnboardingEmail(clientId, clientEmail, btn) {
  if (!confirm(`Are you sure you want to send the Onboarding Checklist & Service details email to ${clientEmail} now?`)) {
    return;
  }
  
  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  
  const formData = new FormData();
  formData.append('send_onboarding_email_ajax', '1');
  formData.append('client_id', clientId);
  formData.append('csrf_token', '<?= csrfToken() ?>');
  
  fetch('admin-dashboard.php', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = originalHtml;
    
    if (data.success) {
      alert('🎉 Onboarding Checklist Email sent successfully to client!');
    } else {
      alert('Error sending email: ' + data.error);
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalHtml;
    alert('Unexpected connection error: ' + err);
  });
}

function sendQuickOnboardingMail(btn) {
  const email = document.getElementById('quickEmail').value.trim();
  const url = document.getElementById('quickUrl').value.trim();
  const keyword = document.getElementById('quickKeyword').value.trim();
  
  if (!email) {
    alert('Please enter a client email address.');
    return;
  }
  
  if (!confirm(`Send onboarding checklist email to ${email}?`)) {
    return;
  }
  
  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
  
  const formData = new FormData();
  formData.append('send_quick_email_ajax', '1');
  formData.append('email', email);
  formData.append('website', url);
  formData.append('keyword', keyword);
  formData.append('csrf_token', '<?= csrfToken() ?>');
  
  fetch('admin-dashboard.php', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = originalHtml;
    
    if (data.success) {
      alert('🎉 Onboarding checklist email sent successfully!');
      document.getElementById('quickEmail').value = '';
      document.getElementById('quickUrl').value = '';
      document.getElementById('quickKeyword').value = '';
    } else {
      alert('Error: ' + data.error);
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalHtml;
    alert('Unexpected connection error: ' + err);
  });
}

function showChangePasswordModal(userId, username) {
  document.getElementById('passModalUserId').value = userId;
  document.getElementById('passModalUsername').innerText = username;
  const myModal = new bootstrap.Modal(document.getElementById('passChangeModal'));
  myModal.show();
}

function showPermissionsModal(userId, username, allowedMenusStr) {
  document.getElementById('permModalUserId').value = userId;
  document.getElementById('permModalUsername').innerText = username;
  
  // Uncheck all checkboxes first
  document.querySelectorAll('.perm-checkbox').forEach(cb => {
    cb.checked = false;
  });
  
  if (allowedMenusStr) {
    const allowed = allowedMenusStr.split(',').map(s => s.trim().toLowerCase());
    allowed.forEach(mCode => {
      const el = document.getElementById('chk_' + mCode);
      if (el) el.checked = true;
    });
  } else {
    // If empty/null, check all by default (which represents full access)
    document.querySelectorAll('.perm-checkbox').forEach(cb => {
      cb.checked = true;
    });
  }
  
  const myModal = new bootstrap.Modal(document.getElementById('permissionsModal'));
  myModal.show();
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
