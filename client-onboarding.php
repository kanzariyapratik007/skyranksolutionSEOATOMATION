<?php
require_once 'config.php';

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token     = isset($_GET['token']) ? trim($_GET['token']) : '';

$db = getDB();

// Fetch project to validate token
$project = null;
if ($projectId > 0) {
    $stmt = $db->prepare("SELECT * FROM projects WHERE id=?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
}

if (!$project || getOnboardingToken($projectId, $project['website_url']) !== $token) {
    die("
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Access Denied - Onboarding Portal</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
        <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css' rel='stylesheet'>
        <style>
            body { background: #f8fafc; font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .error-card { max-width: 480px; width: 100%; padding: 30px; border-radius: 16px; border: none; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); background: #ffffff; text-align: center; }
        </style>
    </head>
    <body>
        <div class='error-card'>
            <div class='text-danger mb-3'><i class='fas fa-exclamation-triangle fa-3x'></i></div>
            <h4 class='fw-bold text-dark'>Invalid Verification Link</h4>
            <p class='text-muted small'>Warning: This link is invalid or has expired. Please contact your SEO administrator.</p>
            <p class='text-muted small'>This onboarding link is invalid or expired. Please contact your SEO campaign administrator.</p>
        </div>
    </body>
    </html>
    ");
}

$successMsg = '';
$errorMsg = '';
$manualMode = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $no_admin_access  = isset($_POST['no_admin_access']) && $_POST['no_admin_access'] === '1';
    
    $admin_url        = $no_admin_access ? '' : clean($_POST['admin_url'] ?? '');
    $admin_user       = $no_admin_access ? '' : clean($_POST['admin_user'] ?? '');
    $admin_pass       = ($no_admin_access || empty($_POST['admin_pass'])) ? '' : base64_encode($_POST['admin_pass']);
    
    $ga_access        = clean($_POST['ga_access'] ?? '');
    $google_ads_id    = clean($_POST['google_ads_id'] ?? '');
    $competitor_sites = clean($_POST['competitor_sites'] ?? '');
    $business_desc    = clean($_POST['business_desc'] ?? '');
    
    try {
        // Self-healing migration for onboarding submission flags
        try {
            $db->exec("ALTER TABLE projects ADD COLUMN onboarding_submitted INT DEFAULT 0");
            $db->exec("ALTER TABLE projects ADD COLUMN onboarding_submitted_at DATETIME DEFAULT NULL");
        } catch (PDOException $e) {}
        
        $stmt = $db->prepare("UPDATE projects SET admin_url=?, admin_user=?, admin_pass=?, ga_access=?, google_ads_id=?, competitor_sites=?, business_desc=?, onboarding_submitted=1, onboarding_submitted_at=NOW() WHERE id=?");
        $stmt->execute([
            $admin_url, $admin_user, $admin_pass, $ga_access, $google_ads_id, $competitor_sites, $business_desc, $projectId
        ]);
        
        $successMsg = "Details submitted successfully!";
        if ($no_admin_access) {
            $manualMode = true;
        }
        
        // Refresh project data
        $stmt = $db->prepare("SELECT * FROM projects WHERE id=?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
    } catch (PDOException $e) {
        $errorMsg = "Error updating details: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SEO Campaign Onboarding Form - <?= clean($project['business_name'] ?: 'Partner') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    body { background-color: #f1f5f9; color: #1e293b; font-family: system-ui, -apple-system, sans-serif; }
    .hero-banner { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; padding: 40px 20px; text-align: center; border-radius: 0 0 24px 24px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .onboard-card { border: none; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
    .form-section-title { font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 20px; }
    .required-star { color: #ef4444; }
    .code-box { font-family: monospace; font-size: 13px; background: #0f172a; color: #e2e8f0; padding: 15px; border-radius: 8px; border: 1px solid #334155; }
</style>
</head>
<body>

<div class="hero-banner">
    <div class="container" style="max-width: 750px;">
        <h2 class="fw-bold mb-1"><i class="fas fa-rocket me-2"></i>SEO Campaign Setup Form</h2>
        <p class="mb-0 opacity-75">Please fill in your website credentials & tracking IDs below</p>
    </div>
</div>

<div class="container py-2 mb-5" style="max-width: 750px;">
    <?php if ($successMsg): ?>
        <div class="card onboard-card border-0 bg-success text-white p-4 text-center mb-4 shadow">
            <div><i class="fas fa-check-circle fa-4x mb-3"></i></div>
            <h3 class="fw-bold">Thank You!</h3>
            <p class="mb-0">Your onboarding details have been saved successfully.</p>
        </div>

        <?php if ($manualMode): ?>
            <!-- Manual Setup Codes -->
            <div class="card onboard-card mb-4 border-0 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="fas fa-code text-danger me-2"></i>🛠️ Manual Code Setup Guide</h5>
                    <p class="small text-muted">Please copy the HTML codes below and paste them into the <code>&lt;head&gt;</code> section of your website manually, or email them to your developer:</p>
                    
                    <?php if (!empty($project['ga_access'])): 
                        $gaId = clean($project['ga_access']);
                        $gtagCode = "<!-- Global site tag (gtag.js) - Google Analytics -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id={$gaId}\"></script>\n<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n  gtag('config', '{$gaId}');\n</script>";
                    ?>
                        <div class="mb-4">
                            <label class="form-label fw-bold text-primary">1. Google Analytics (GA4) Tag</label>
                            <textarea class="form-control font-monospace small bg-light" rows="5" readonly><?= htmlspecialchars($gtagCode) ?></textarea>
                            <button class="btn btn-sm btn-outline-secondary mt-2" onclick="copyText(this)">Copy Code</button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($project['google_ads_id'])): 
                        $adsId = clean($project['google_ads_id']);
                        $adsCode = "<!-- Google Ads Conversion Tag -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id={$adsId}\"></script>\n<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n  gtag('config', '{$adsId}');\n</script>";
                    ?>
                        <div class="mb-4">
                            <label class="form-label fw-bold text-primary">2. Google Ads Conversion Tag</label>
                            <textarea class="form-control font-monospace small bg-light" rows="5" readonly><?= htmlspecialchars($adsCode) ?></textarea>
                            <button class="btn btn-sm btn-outline-secondary mt-2" onclick="copyText(this)">Copy Code</button>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Generate Schema
                    $bName = clean($project['business_name'] ?: $project['website_url']);
                    $schemaData = [
                        '@context' => 'https://schema.org',
                        '@type'    => 'LocalBusiness',
                        'name'     => $bName,
                        'url'      => clean($project['website_url'])
                    ];
                    if (!empty($project['business_desc']))    $schemaData['description'] = clean($project['business_desc']);
                    if (!empty($project['phone']))            $schemaData['telephone']   = clean($project['phone']);
                    if (!empty($project['email']))            $schemaData['email']       = clean($project['email']);
                    if (!empty($project['business_address'])) $schemaData['address'] = [
                        '@type'         => 'PostalAddress',
                        'streetAddress' => clean($project['business_address'])
                    ];
                    if (!empty($project['business_hours']))   $schemaData['openingHours'] = clean($project['business_hours']);
                    
                    $schemaJson = json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $schemaCode = "<script type=\"application/ld+json\">\n" . $schemaJson . "\n</script>";
                    ?>
                    <div class="mb-0">
                        <label class="form-label fw-bold text-primary">3. Local SEO Schema Markup (JSON-LD)</label>
                        <textarea class="form-control font-monospace small bg-light" rows="8" readonly><?= htmlspecialchars($schemaCode) ?></textarea>
                        <button class="btn btn-sm btn-outline-secondary mt-2" onclick="copyText(this)">Copy Code</button>
                    </div>
                </div>
            </div>
            
            <script>
            function copyText(btn) {
                const textarea = btn.previousElementSibling;
                textarea.select();
                document.execCommand('copy');
                btn.innerText = 'Copied!';
                setTimeout(() => btn.innerText = 'Copy Code', 2000);
            }
            </script>
        <?php endif; ?>
        
    <?php else: ?>
        
        <?php if ($errorMsg): ?>
            <div class="alert alert-danger shadow-sm border-0"><?= $errorMsg ?></div>
        <?php endif; ?>

        <form method="POST">
            <!-- SECTION 1: WordPress CMS Login -->
            <div class="card onboard-card mb-4">
                <div class="card-body p-4">
                    <h6 class="form-section-title text-primary fw-bold">
                        <i class="fas fa-lock me-2"></i>1. WordPress Website Access
                    </h6>
                    <p class="small text-muted mb-3">
                        <strong>Why we need this:</strong> To automatically inject GSC, Analytics, conversion tags, and Schema markup code directly into the website header.
                    </p>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="no_admin_access" name="no_admin_access" value="1" onchange="toggleAdminRequired(this)">
                        <label class="form-check-label fw-bold text-danger" for="no_admin_access">
                            I don't have WordPress / I will install codes manually (Tick for manual code setup guide)
                        </label>
                    </div>
                    
                    <div id="wp_credentials_fields">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Admin Login URL <span class="required-star">*</span></label>
                            <input type="url" name="admin_url" class="form-control" placeholder="https://example.com/wp-admin" value="<?= clean($project['admin_url'] ?? '') ?>" required>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Username / Admin Email <span class="required-star">*</span></label>
                                <input type="text" name="admin_user" class="form-control" placeholder="admin" value="<?= clean($project['admin_user'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Admin Password <span class="required-star">*</span></label>
                                <input type="password" name="admin_pass" class="form-control" placeholder="••••••••" required>
                                <div class="form-text small text-danger" style="font-size:11px;">* Your login details are encrypted and securely saved.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: Google Tracking tags -->
            <div class="card onboard-card mb-4">
                <div class="card-body p-4">
                    <h6 class="form-section-title text-primary fw-bold">
                        <i class="fab fa-google me-2"></i>2. Google Analytics & Ads IDs
                    </h6>
                    <p class="small text-muted mb-3">
                        Provide the following IDs to track website traffic and crawling (leave blank if not created).
                    </p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Google Analytics (GA4) Property ID</label>
                        <input type="text" name="ga_access" class="form-control" placeholder="G-XXXXXXXXXX" value="<?= clean($project['ga_access'] ?? '') ?>">
                        <div class="form-text small">e.g. <code>G-XXXXXXXXXX</code>. Go to Analytics Admin -> Data Streams -> click stream to copy.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold">Google Ads Conversion ID (Optional)</label>
                        <input type="text" name="google_ads_id" class="form-control" placeholder="AW-XXXXXXXXXX" value="<?= clean($project['google_ads_id'] ?? '') ?>">
                        <div class="form-text small">e.g. <code>AW-XXXXXXXXXX</code>. Found in Google Ads -> Tools -> Conversions -> Google Tag.</div>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: Competitors & Business Info -->
            <div class="card onboard-card mb-4">
                <div class="card-body p-4">
                    <h6 class="form-section-title text-primary fw-bold">
                        <i class="fas fa-users me-2"></i>3. Competitors & Services
                    </h6>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Top 3 Competitor Websites</label>
                        <input type="text" name="competitor_sites" class="form-control" placeholder="e.g. competitor1.com, competitor2.com" value="<?= clean($project['competitor_sites'] ?? '') ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold">Describe Business / Services Offered</label>
                        <textarea name="business_desc" class="form-control" rows="3" placeholder="Tell us about the services you offer to help us write better backlink articles..."><?= clean($project['business_desc'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="d-grid mb-4">
                <button type="submit" class="btn btn-primary btn-lg fw-bold p-3 rounded shadow">
                    <i class="fas fa-paper-plane me-2"></i>Submit Campaign Setup Details
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleAdminRequired(chk) {
    const fieldsDiv = document.getElementById('wp_credentials_fields');
    const urlF = document.querySelector('input[name="admin_url"]');
    const userF = document.querySelector('input[name="admin_user"]');
    const passF = document.querySelector('input[name="admin_pass"]');
    
    if (chk.checked) {
        fieldsDiv.style.opacity = '0.5';
        urlF.removeAttribute('required');
        userF.removeAttribute('required');
        passF.removeAttribute('required');
    } else {
        fieldsDiv.style.opacity = '1.0';
        urlF.setAttribute('required', 'required');
        userF.setAttribute('required', 'required');
        passF.setAttribute('required', 'required');
    }
}
</script>
</body>
</html>
