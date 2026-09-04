<?php
require_once 'config.php';
requireMenuPermission('add-project');

$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.'); header('Location: add-project.php'); exit;
    }
    $website_url     = clean($_POST['website_url']);
    $target_keyword  = clean($_POST['target_keyword']);
    $target_site     = clean($_POST['target_site']);
    $package_type    = clean($_POST['package_type'] ?? 'basic');
    
    // New Client Profile & Access fields
    $business_name   = clean($_POST['business_name'] ?? '');
    $contact_name    = clean($_POST['contact_name'] ?? '');
    $phone           = clean($_POST['phone'] ?? '');
    $email           = clean($_POST['email'] ?? '');
    $gsc_access      = clean($_POST['gsc_access'] ?? '');
    $ga_access       = clean($_POST['ga_access'] ?? '');
    $google_ads_id   = clean($_POST['google_ads_id'] ?? '');
    $admin_url       = clean($_POST['admin_url'] ?? '');
    $admin_user      = clean($_POST['admin_user'] ?? '');
    $admin_pass      = base64_encode($_POST['admin_pass'] ?? '');
    $competitor_sites= clean($_POST['competitor_sites'] ?? '');
    $business_desc   = clean($_POST['business_desc'] ?? '');

    if (empty($website_url) || empty($target_keyword)) {
        setFlash('danger', 'Website URL and Target Keyword are required.');
        header('Location: add-project.php'); exit;
    }

    $db = getDB();
    
    // Self-healing migration for complete Client Profile & Access columns
    try {
        $db->exec("ALTER TABLE projects ADD COLUMN package_type VARCHAR(50) DEFAULT 'basic'");
    } catch (PDOException $e) {}
    try {
        $db->exec("ALTER TABLE projects ADD COLUMN business_name VARCHAR(255) DEFAULT ''");
        $db->exec("ALTER TABLE projects ADD COLUMN contact_name VARCHAR(255) DEFAULT ''");
        $db->exec("ALTER TABLE projects ADD COLUMN phone VARCHAR(50) DEFAULT ''");
        $db->exec("ALTER TABLE projects ADD COLUMN email VARCHAR(255) DEFAULT ''");
        $db->exec("ALTER TABLE projects ADD COLUMN gsc_access TEXT DEFAULT NULL");
        $db->exec("ALTER TABLE projects ADD COLUMN ga_access TEXT DEFAULT NULL");
        $db->exec("ALTER TABLE projects ADD COLUMN google_ads_id VARCHAR(100) DEFAULT NULL");
        $db->exec("ALTER TABLE projects ADD COLUMN admin_url VARCHAR(255) DEFAULT ''");
        $db->exec("ALTER TABLE projects ADD COLUMN admin_user VARCHAR(255) DEFAULT ''");
        $db->exec("ALTER TABLE projects ADD COLUMN admin_pass TEXT DEFAULT NULL");
        $db->exec("ALTER TABLE projects ADD COLUMN competitor_sites TEXT DEFAULT NULL");
        $db->exec("ALTER TABLE projects ADD COLUMN business_desc TEXT DEFAULT NULL");
        $db->exec("ALTER TABLE projects MODIFY COLUMN target_keyword TEXT NOT NULL");
        $db->exec("ALTER TABLE projects MODIFY COLUMN target_site TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // already exists or migrated
    }

    $client_user_id = (int)$_SESSION['user_id'];

    $stmt = $db->prepare("INSERT INTO projects (user_id, website_url, target_keyword, target_site, package_type, business_name, contact_name, phone, email, gsc_access, ga_access, google_ads_id, admin_url, admin_user, admin_pass, competitor_sites, business_desc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $client_user_id, $website_url, $target_keyword, $target_site, $package_type,
        $business_name, $contact_name, $phone, $email, $gsc_access, $ga_access, $google_ads_id,
        $admin_url, $admin_user, $admin_pass, $competitor_sites, $business_desc
    ]);
    $projectId = $db->lastInsertId();

    // If welcome email checkbox is checked, trigger onboarding mail
    if (isset($_POST['send_welcome_email']) && $_POST['send_welcome_email'] === '1' && !empty($email)) {
        require_once __DIR__ . '/includes/mailer.php';
        
        $subject = "Welcome to " . SITE_NAME . "! SEO Onboarding & Details Checklist 🚀";
        
        $agencyName  = SITE_NAME;
        $agencyEmail = SMTP_USER;
        $website     = $website_url;
        $keyword     = $target_keyword;
        $onboardingUrl = SITE_URL . "/client-onboarding.php?id=" . $projectId . "&token=" . getOnboardingToken($projectId, $website);
        
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
        
        try {
            sendSmtpMail($email, $subject, $body);
        } catch (Exception $e) {
            setFlash('warning', 'Project added, but onboarding email could not be sent (SMTP not configured).');
        }
    }

    setFlash('success', 'Project added! Plan: ' . ucfirst($package_type) . ' SEO. Client user linked.');
    header('Location: seo-80-20.php?id=' . $projectId); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Project - SEO 80/20 System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container py-4" style="max-width:700px;">
  <div class="card shadow">
    <div class="card-header bg-primary text-white">
      <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add New SEO Project</h5>
    </div>
    <div class="card-body">
      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>"><?= $flash['msg'] ?></div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        
        <!-- SECTION 1: Core Website & SEO Plan -->
        <div class="card mb-4 border-0 bg-light shadow-sm">
          <div class="card-body">
            <h6 class="text-primary fw-bold mb-3"><i class="fas fa-globe me-2"></i>1. Core Website & SEO Target</h6>
            <div class="mb-3">
              <label class="form-label fw-bold">Website URL <span class="text-danger">*</span></label>
              <input type="url" name="website_url" class="form-control" placeholder="https://example.com" required>
              <div class="form-text small">Client's main public domain.</div>
            </div>
            <div class="row g-2">
              <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Target Keyword <span class="text-danger">*</span></label>
                <input type="text" name="target_keyword" class="form-control" placeholder="e.g. car detailing in rajkot" required>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Target Page URL (for On-Page Audit)</label>
                <input type="url" name="target_site" class="form-control" placeholder="https://example.com/services">
              </div>
            </div>
            <input type="hidden" name="package_type" value="premium">
          </div>
        </div>

        <!-- SECTION 2: Client Profile & Business details -->
        <div class="card mb-4 border-0 bg-light shadow-sm">
          <div class="card-body">
            <h6 class="text-primary fw-bold mb-3"><i class="fas fa-building me-2"></i>2. Client Profile Details</h6>
            <div class="row g-2">
              <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Business Name</label>
                <input type="text" name="business_name" class="form-control" placeholder="e.g. Rajkot Car Spa">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Contact Name</label>
                <input type="text" name="contact_name" class="form-control" placeholder="e.g. Pratik Patel">
              </div>
            </div>
            <div class="row g-2">
              <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Phone Number</label>
                <input type="text" name="phone" class="form-control" placeholder="+91 XXXXX XXXXX">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="client@example.com">
              </div>
            </div>
            <div class="mb-0">
              <label class="form-label fw-bold">Services Description / Keywords Angle</label>
              <textarea name="business_desc" class="form-control" rows="2" placeholder="List services, products or details about the client's business..."></textarea>
            </div>
          </div>
        </div>

        <!-- SECTION 3: Google Access Accounts -->
        <div class="card mb-4 border-0 bg-light shadow-sm">
          <div class="card-body">
            <h6 class="text-primary fw-bold mb-3"><i class="fab fa-google me-2"></i>3. Google Services Access Details</h6>
            <div class="mb-3">
              <label class="form-label fw-bold">Google Search Console Access</label>
              <input type="text" name="gsc_access" class="form-control" placeholder="e.g., Delegated owner access to email: your-agency-email@gmail.com">
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold">Google Analytics Access / Property ID</label>
              <input type="text" name="ga_access" class="form-control" placeholder="e.g., GA4 stream Measurement ID (G-XXXXXXXXXX) or GA access email">
            </div>
            <div class="mb-0">
              <label class="form-label fw-bold">Google Ads Conversion ID (Optional)</label>
              <input type="text" name="google_ads_id" class="form-control" placeholder="e.g., AW-XXXXXXXXXX">
            </div>
          </div>
        </div>

        <!-- SECTION 4: CMS Admin Login (for On-Page Implementation) -->
        <div class="card mb-4 border-0 bg-light shadow-sm">
          <div class="card-body">
            <h6 class="text-primary fw-bold mb-3"><i class="fas fa-lock me-2"></i>4. Client Website CMS Admin Credentials</h6>
            <div class="mb-3">
              <label class="form-label fw-bold">Admin Login URL</label>
              <input type="url" name="admin_url" class="form-control" placeholder="e.g., https://example.com/wp-admin">
            </div>
            <div class="row g-2">
              <div class="col-md-6 mb-0">
                <label class="form-label fw-bold">Admin Username / Email</label>
                <input type="text" name="admin_user" class="form-control" placeholder="admin">
              </div>
              <div class="col-md-6 mb-0">
                <label class="form-label fw-bold">Admin Password</label>
                <input type="password" name="admin_pass" class="form-control" placeholder="••••••••">
              </div>
            </div>
          </div>
        </div>

        <!-- SECTION 5: Competitor Sites -->
        <div class="card mb-4 border-0 bg-light shadow-sm">
          <div class="card-body">
            <h6 class="text-primary fw-bold mb-3"><i class="fas fa-users me-2"></i>5. Competitor Websites</h6>
            <div class="mb-0">
              <label class="form-label fw-bold">Competitor Domains (comma separated)</label>
              <input type="text" name="competitor_sites" class="form-control" placeholder="e.g., competitor1.com, competitor2.com">
            </div>
          </div>
        </div>

        <!-- Checkbox to auto-send welcome email -->
        <div class="form-check mb-4 bg-light p-3 rounded border border-warning" style="margin-left: 0;">
          <input class="form-check-input ms-1 me-2" type="checkbox" name="send_welcome_email" id="sendWelcomeEmail" value="1" checked>
          <label class="form-check-label fw-bold text-dark" for="sendWelcomeEmail">
            🚀 Send SEO Onboarding & Services Checklist Email to client immediately!
            <br><span class="text-muted small" style="font-weight:normal; font-size:12px;">Keeping this checked will automatically send the onboarding checklist email to the client.</span>
          </label>
        </div>

        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-rocket me-2"></i>Create Project & Start SEO
          </button>
          <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
