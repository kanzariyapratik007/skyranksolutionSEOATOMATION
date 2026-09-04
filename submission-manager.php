<?php
require_once 'config.php';
requireMenuPermission('submissions');

$db = getDB();
$userId = $_SESSION['user_id'];

try {
    $db->exec("ALTER TABLE social_accounts ADD COLUMN project_id INT NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE social_accounts DROP INDEX user_platform");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE social_accounts DROP INDEX project_platform");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE backlinks ADD COLUMN verified_status VARCHAR(50) DEFAULT 'unverified'");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE backlinks ADD COLUMN last_checked_at DATETIME DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE backlinks ADD COLUMN target_url VARCHAR(1000) DEFAULT NULL");
} catch (PDOException $e) {}

// Handle AJAX keyword / target site url updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_targets'])) {
    header('Content-Type: application/json');
    $pId = (int)($_POST['project_id'] ?? 0);
    $targetKeywords = trim($_POST['target_keywords'] ?? '');
    $targetSites = trim($_POST['target_sites'] ?? '');

    $upd = $db->prepare("UPDATE projects SET target_keyword = ?, target_site = ? WHERE id = ? AND user_id = ?");
    $upd->execute([$targetKeywords, $targetSites, $pId, $userId]);

    echo json_encode(['success' => true]);
    exit;
}

// Platform list with what system does automatically
$platforms = [
    'profile_creation' => [
        'title' => '👤 Profile Creation Sites',
        'color' => 'primary',
        'sites' => [
            ['id' => 'google_business', 'name' => 'Google Business Profile', 'url' => 'https://business.google.com', 'what_system_does' => '🤖 Browser Automation — Google Email (Username). System Chrome reads master profile chrome_profile_gsc, goes to Business Search dashboard, and posts updates with text + image automatically!', 'autopost' => false],
            ['id' => 'pinterest',    'name' => 'Pinterest',      'url' => 'https://www.pinterest.com',   'what_system_does' => '🤖 Browser Automation — Email + Password save karo. System Chrome kholine auto login kare + pin create kare with image + backlink'],
            ['id' => 'bluesky',      'name' => 'Bluesky',        'url' => 'https://bsky.app',            'what_system_does' => '✅ 100% Auto — AT Protocol API. Use App Password from bsky.app → Settings → App Passwords'],
            ['id' => 'mastodon',     'name' => 'Mastodon',       'url' => 'https://mastodon.social',     'what_system_does' => '🤖 Browser Automation — Email + Password save karo. System auto-login kare, app create kare, token generate kare + post kare automatically!'],
            ['id' => 'minds',        'name' => 'Minds.com',      'url' => 'https://www.minds.com',       'what_system_does' => '✅ 100% Auto — REST API. Get token: minds.com → Settings → Security → API Token'],
            ['id' => 'dribbble',     'name' => 'Dribbble',       'url' => 'https://dribbble.com',        'what_system_does' => '✅ 100% Auto — Upload shot with keyword + backlink. Get token: dribbble.com/account/applications'],
            ['id' => 'symbaloo',     'name' => 'Symbaloo',       'url' => 'https://www.symbaloo.com',    'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System auto-login thaine webmix tile publish kare with keyword + backlink'],
            ['id' => 'penzu',        'name' => 'Penzu',          'url' => 'https://penzu.com',           'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System journal entries list ma post add kare automatically!'],
            ['id' => 'plurk',        'name' => 'Plurk',          'url' => 'https://www.plurk.com',       'what_system_does' => '✅ 100% Auto — REST API. Get key: plurk.com/API/Apps'],
            ['id' => 'devto',        'name' => 'Dev.to',         'url' => 'https://dev.to',              'what_system_does' => '✅ 100% Auto — API post. Get key: dev.to/settings/extensions'],
            ['id' => 'linktree',     'name' => 'Linktree',       'url' => 'https://linktr.ee',           'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System profile links ma new link add kare with title + backlink'],
        ]
    ],
    'micro_blogging' => [
        'title' => '✍️ Micro Blogging',
        'color' => 'success',
        'sites' => [
            ['id' => 'scoopit',      'name' => 'Scoop.it',       'url' => 'https://www.scoop.it',        'what_system_does' => '📋 Semi-Auto — Auto Post dabavo → Title + Description auto-generate thay → Copy karo → Scoop.it khulse → Paste karo → Publish button dabavo'],
            ['id' => 'wakelet',      'name' => 'Wakelet',        'url' => 'https://wakelet.com',         'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System collection create kare + URL add kare with keyword + backlink'],
            ['id' => 'vivauae',      'name' => 'Vivauae',        'url' => 'https://vivauae.com',         'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System article submit kare with keyword + backlink'],
            ['id' => 'padlet',       'name' => 'Padlet',         'url' => 'https://padlet.com',          'what_system_does' => '🤖 Browser Session — Chrome band karo → Auto Post click karo → System tumhara browser session use karke post add karega. Board: lmt-wb7faycbn66hp2z5'],
            ['id' => 'pearltrees',   'name' => 'Pearltrees',     'url' => 'https://www.pearltrees.com',  'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System pearl collection ma website link add kare'],
            ['id' => 'mewe',         'name' => 'MeWe',           'url' => 'https://mewe.com',            'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System auto-login kare + post kare with keyword + backlink'],
            ['id' => 'instapaper',   'name' => 'Instapaper',     'url' => 'https://www.instapaper.com',  'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System article save kare with website link'],
        ]
    ],
    'image_posting' => [
        'title' => '🖼️ Image Posting',
        'color' => 'warning',
        'sites' => [
            ['id' => 'gifyu',        'name' => 'Gifyu',          'url' => 'https://gifyu.com',           'what_system_does' => 'Image upload with keyword description + backlink'],
            ['id' => 'postimage',    'name' => 'PostImage',      'url' => 'http://www.postimage.org',    'what_system_does' => 'Image post with keyword + website link'],
            ['id' => 'photobucket',  'name' => 'Photobucket',    'url' => 'https://photobucket.com',     'what_system_does' => 'Photo album with keyword + backlink'],
            ['id' => 'behance',      'name' => 'Behance',        'url' => 'https://www.behance.net',     'what_system_does' => 'Portfolio project with keyword + website link'],
            ['id' => 'pbase',        'name' => 'Pbase',          'url' => 'https://www.pbase.com',       'what_system_does' => 'Photo gallery with keyword + backlink', 'autopost' => false],
            ['id' => 'dropbox',      'name' => 'Dropbox',        'url' => 'https://www.dropbox.com',     'what_system_does' => 'Shared folder with keyword content', 'autopost' => false],
            ['id' => 'imgbb',        'name' => 'ImgBB',          'url' => 'https://imgbb.com',           'what_system_does' => '✅ 100% Auto — Upload image with keyword + backlink. Get free API key: imgbb.com/api'],
            ['id' => 'googledrive',  'name' => 'Google Drive',   'url' => 'https://drive.google.com',    'what_system_does' => '✅ 100% Auto — Upload PDF to public folder. Get token: console.cloud.google.com → Drive API', 'autopost' => false],
        ]
    ],
    'blog_posting' => [
        'title' => '📝 Blog Posting',
        'color' => 'info',
        'sites' => [
            ['id' => 'devto',        'name' => 'Dev.to',         'url' => 'https://dev.to',              'what_system_does' => '✅ 100% Auto — API post. Get key: dev.to/settings/extensions'],
            ['id' => 'hashnode',     'name' => 'Hashnode',       'url' => 'https://hashnode.com',        'what_system_does' => '✅ 100% Auto — GraphQL API post. Get key: hashnode.com/settings/developer'],
            ['id' => 'ghost',        'name' => 'Ghost.io',       'url' => 'https://ghost.io',            'what_system_does' => '✅ 100% Auto — Admin API post. Get key: Ghost Admin → Integrations'],
            ['id' => 'site123',      'name' => 'Site123',        'url' => 'https://www.site123.com',     'what_system_does' => '🤖 Browser Automation — Email+Password save karo. System auto-login kare + blog post create kare with keyword + backlink'],
            ['id' => 'posteezy',     'name' => 'Posteezy',       'url' => 'https://www.posteezy.com',    'what_system_does' => 'Article with keyword + backlink'],
            ['id' => 'livejournal',  'name' => 'LiveJournal',    'url' => 'https://www.livejournal.com', 'what_system_does' => '🤖 Browser Automation — Username LMT_12 + Password. System auto-login + blog post create kare with keyword + backlink'],
            ['id' => 'justpaste',    'name' => 'JustPaste.it',   'url' => 'https://justpaste.it',        'what_system_does' => 'Article paste with keyword + backlink'],
            ['id' => 'wordpress',    'name' => 'WordPress.com',  'url' => 'https://wordpress.com',       'what_system_does' => '✅ 100% Auto — Click "Connect WordPress" button below to login and auto-save token'],
            ['id' => 'medium',       'name' => 'Medium.com',     'url' => 'https://medium.com',          'what_system_does' => 'ChatGPT article → Copy & paste (API discontinued)'],
            ['id' => 'substack',     'name' => 'Substack',       'url' => 'https://substack.com',        'what_system_does' => 'ChatGPT newsletter post → Copy & paste'],
            ['id' => 'blogger',      'name' => 'Blogger.com',    'url' => 'https://www.blogger.com',     'what_system_does' => '✅ 100% Auto — Google OAuth token + Blog ID'],
            ['id' => 'tumblr',       'name' => 'Tumblr',         'url' => 'https://www.tumblr.com',      'what_system_does' => '✅ 100% Auto — OAuth API. Get key: tumblr.com/oauth/apps'],
            ['id' => 'github',       'name' => 'GitHub',         'url' => 'https://github.com',          'what_system_does' => '✅ 100% Auto — Creates repo + README. Get token: github.com/settings/tokens'],
        ]
    ],
    'pdf_posting' => [
        'title' => '📄 PDF / Document Posting',
        'color' => 'danger',
        'sites' => [
            ['id' => 'mediafire',    'name' => 'MediaFire',      'url' => 'https://mediafire.com',       'what_system_does' => '✅ Auto — Logs in with Email + Password → Generates PDF with keyword description + backlink → Uploads to MediaFire → Returns public share link'],
            ['id' => 'limewire',     'name' => 'LimeWire',       'url' => 'https://limewire.com',        'what_system_does' => 'File share with keyword + backlink'],
            ['id' => 'fourshared',   'name' => '4Shared',        'url' => 'https://4shared.com',         'what_system_does' => '✅ Auto — Logs in with Email + Password → Generates PDF with keyword description + backlink → Uploads to 4Shared → Returns public share link'],
            ['id' => 'workupload',   'name' => 'WorkUpload',     'url' => 'https://workupload.com',      'what_system_does' => 'File upload with keyword description'],
            ['id' => 'powershow',    'name' => 'PowerShow',      'url' => 'https://www.powershow.com',   'what_system_does' => 'PPT presentation with keyword + backlink'],
            ['id' => 'uploadee',     'name' => 'Upload.ee',      'url' => 'https://www.upload.ee',       'what_system_does' => 'File upload with keyword + backlink'],
            ['id' => 'pdfhost',      'name' => 'PDFHost',        'url' => 'https://pdfhost.io',          'what_system_does' => 'PDF host with keyword description + backlink'],
        ]
    ],
];

// Sort sites inside each category so that active auto-post sites are at the top
foreach ($platforms as $catKey => &$category) {
    usort($category['sites'], function($a, $b) {
        $a_auto = isset($a['autopost']) ? $a['autopost'] : true;
        $b_auto = isset($b['autopost']) ? $b['autopost'] : true;
        if ($a_auto === $b_auto) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $a_auto ? -1 : 1;
    });
}
unset($category);

// Handle AJAX Live Backlinks & Pending Tasks Table Fetch
if (isset($_GET['action']) && $_GET['action'] === 'fetch_backlinks_html') {
    header('Content-Type: application/json');
    $pId = (int)($_GET['project_id'] ?? 0);
    $kw = trim($_GET['keyword'] ?? '');
    $siteUrl = trim($_GET['target_site'] ?? '');
    
    $createdBL = $db->prepare("
        SELECT * FROM backlinks 
        WHERE project_id = ? 
          AND status = 'created' 
          AND (keyword = ? OR (keyword IS NULL AND ? = ''))
          AND (target_url = ? OR target_url IS NULL OR (target_url IS NULL AND ? = ''))
        ORDER BY created_at DESC
    ");
    $createdBL->execute([$pId, $kw, $kw, $siteUrl, $siteUrl]);
    $createdBLList = $createdBL->fetchAll();

    $pendingTasks = $db->prepare("
        SELECT * FROM backlink_queue 
        WHERE project_id = ? 
          AND status IN ('pending', 'processing')
          AND (keyword = ? OR (keyword IS NULL AND ? = ''))
          AND (target_url = ? OR target_url IS NULL OR (target_url IS NULL AND ? = ''))
        ORDER BY created_at ASC
    ");
    $pendingTasks->execute([$pId, $kw, $kw, $siteUrl, $siteUrl]);
    $pendingList = $pendingTasks->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (!empty($createdBLList)): ?>
      <div class="card mb-4 border-success shadow-sm">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Created Backlinks (<?= count($createdBLList) ?>) — New URLs</h5>
          <a href="export-excel.php?id=<?= $pId ?>" class="btn btn-light btn-sm"><i class="fas fa-file-excel me-1 text-success"></i>Download Excel</a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-dark">
                <tr><th>#</th><th>Platform</th><th>Backlink URL</th><th>Date Created</th><th>Link Status</th><th>Google Index</th><th>Action</th></tr>
              </thead>
              <tbody>
              <?php foreach ($createdBLList as $i => $bl): ?>
                <tr id="bl-row-<?= $bl['id'] ?>">
                  <td><?= $i + 1 ?></td>
                  <td><span class="badge bg-success"><?= clean($bl['platform']) ?></span></td>
                  <td><a href="<?= clean($bl['backlink_url']) ?>" target="_blank" class="text-decoration-none"><?= clean(substr($bl['backlink_url'], 0, 60)) ?>...<i class="fas fa-external-link-alt ms-1 small"></i></a></td>
                  <td><small><?= formatLocalTime($bl['created_at'], 'd M Y H:i') ?></small></td>
                  <td class="bl-status-cell">
                    <?php
                    $vStatus = $bl['verified_status'] ?? 'unverified';
                    if ($vStatus === 'active') echo '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active (Dofollow)</span>';
                    elseif ($vStatus === 'nofollow') echo '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Nofollow</span>';
                    elseif ($vStatus === 'broken') echo '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Broken / Offline</span>';
                    else echo '<span class="badge bg-secondary"><i class="fas fa-question-circle me-1"></i>Unverified</span>';
                    ?>
                  </td>
                  <td class="indexing-status-cell">
                    <?php
                    $iStatus = $bl['indexing_status'] ?? 'unchecked';
                    if ($iStatus === 'indexed') echo '<span class="badge bg-success"><i class="fas fa-search me-1"></i>Indexed</span>';
                    elseif ($iStatus === 'not_indexed') echo '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Not Indexed</span>';
                    else echo '<span class="badge bg-secondary"><i class="fas fa-question-circle me-1"></i>Unchecked</span>';
                    ?>
                  </td>
                  <td>
                    <div class="btn-group btn-group-sm">
                      <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText('<?= clean($bl['backlink_url']) ?>').then(()=>alert('URL Copied!'))" title="Copy URL"><i class="fas fa-copy"></i></button>
                      <button class="btn btn-outline-primary" onclick="verifySingleLink(<?= $bl['id'] ?>, this)" title="Verify Live Link Now"><i class="fas fa-sync-alt"></i></button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif;
    $createdHtml = ob_get_clean();

    ob_start();
    if (!empty($pendingList)): ?>
      <div class="card mb-4 border-warning shadow-sm">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Tasks in Queue (<?= count($pendingList) ?>)</h5>
          <span class="badge bg-dark text-white">System is processing these in background</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-dark"><tr><th>ID</th><th>Platform</th><th>Keyword</th><th>Target URL</th><th>Date Queued</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($pendingList as $task): ?>
                <tr>
                  <td>#<?= $task['id'] ?></td>
                  <td><span class="badge bg-secondary"><?= clean($task['platform']) ?></span></td>
                  <td><?= clean($task['keyword']) ?></td>
                  <td><a href="<?= clean($task['target_url']) ?>" target="_blank" class="text-decoration-none text-dark fw-bold"><?= clean(substr((string)$task['target_url'], 0, 50)) ?>...</a></td>
                  <td><small><?= formatLocalTime($task['created_at'], 'd M H:i') ?></small></td>
                  <td><span class="badge bg-warning text-dark"><i class="fas fa-spinner fa-spin me-1"></i>Pending</span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif;
    $pendingHtml = ob_get_clean();

    // Render Quick Status Cards Grid for the passed keyword and target_site
    $primaryIds = ['pinterest', 'bluesky', 'mastodon', 'minds', 'symbaloo', 'devto', 'livejournal', 'blogger', 'tumblr', 'github'];
    $primaryPlatformsList = [];
    $iconMapList = [
        'pinterest' => 'fab fa-pinterest text-danger',
        'bluesky' => 'fas fa-cloud text-primary',
        'mastodon' => 'fab fa-mastodon text-info',
        'minds' => 'fas fa-circle-notch text-dark',
        'symbaloo' => 'fas fa-th text-warning',
        'devto' => 'fab fa-dev text-dark',
        'linktree' => 'fas fa-tree text-success',
        'site123' => 'fas fa-globe text-primary',
        'livejournal' => 'fas fa-book text-info',
        'blogger' => 'fab fa-blogger text-warning',
        'tumblr' => 'fab fa-tumblr text-primary',
        'github' => 'fab fa-github text-dark'
    ];
    foreach ($primaryIds as $id) {
        foreach ($platforms as $catKey => $cat) {
            foreach ($cat['sites'] as $site) {
                if ($site['id'] === $id) {
                    $site['icon'] = $iconMapList[$id] ?? 'fas fa-share-alt';
                    $primaryPlatformsList[] = $site;
                    break 2;
                }
            }
        }
    }

    $savedAccountsStmt = $db->prepare("SELECT * FROM social_accounts WHERE project_id=?");
    $savedAccountsStmt->execute([$pId]);
    $savedAccountsRows = $savedAccountsStmt->fetchAll();
    $savedMapAllList = [];
    foreach ($savedAccountsRows as $acc) {
        $savedMapAllList[$acc['platform']][] = $acc;
    }

    ob_start();
    ?>
    <div class="row g-2">
      <?php foreach ($primaryPlatformsList as $idx => $pSite): ?>
        <?php 
        $pAllAccounts = $savedMapAllList[$pSite['id']] ?? [];
        $pCooldown = checkPlatformCooldown($db, $pId, $pSite['id'], $kw, $siteUrl, count($pAllAccounts));
        ?>
        <div class="col-6 col-sm-4 col-md-3 col-lg-2">
          <div class="card h-100 border text-center p-2 bg-white shadow-none position-relative" style="transition: all 0.2s ease-in-out; border-radius: 8px;"
               onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'; this.style.transform='translateY(-2px)';" 
               onmouseout="this.style.boxShadow='none'; this.style.transform='none';">
            <div class="position-absolute top-0 end-0 p-1" style="z-index:2;">
              <input type="checkbox" class="form-check-input platform-select-checkbox" value="<?= $pSite['id'] ?>" onchange="updateSelectedPlatformsCount()" title="Select <?= htmlspecialchars($pSite['name']) ?>">
            </div>
            <div class="mb-1 mt-1">
              <i class="<?= $pSite['icon'] ?> fa-lg"></i>
            </div>
            <div class="fw-bold text-dark mb-1" style="font-size: 12.5px; line-height: 1.2; height: 30px; display: flex; align-items: center; justify-content: center;">
              <?= $idx + 1 ?>. <?= $pSite['name'] ?>
            </div>
            
            <!-- Status Badge -->
            <div class="mb-2">
              <?php if ($pCooldown['is_cooldown']): ?>
                <span class="badge bg-warning text-dark" style="font-size: 9px; padding: 3px 6px;">Posted (Cooldown)</span>
              <?php elseif (isset($pSite['autopost']) && $pSite['autopost'] === false): ?>
                <span class="badge bg-secondary text-white" style="font-size: 9px; padding: 3px 6px;">Coming Soon</span>
              <?php elseif (!empty($pAllAccounts)): ?>
                <span class="badge bg-success" style="font-size: 9px; padding: 3px 6px;">Ready</span>
              <?php else: ?>
                <span class="badge bg-light text-muted border" style="font-size: 9px; padding: 2px 5px;">Needs Creds</span>
              <?php endif; ?>
            </div>

            <!-- Action button -->
            <div class="mt-auto pt-1 mb-1">
              <?php if ($pCooldown['is_cooldown']): ?>
                <span class="text-muted fw-bold" style="font-size: 11px;"><i class="fas fa-clock me-1"></i>Wait <?= $pCooldown['time_str'] ?></span>
              <?php elseif (isset($pSite['autopost']) && $pSite['autopost'] === false): ?>
                <span class="text-muted" style="font-size: 11px;">Coming Soon</span>
              <?php elseif (!empty($pAllAccounts)): ?>
                <button class="btn btn-xs btn-success py-0 px-2 fw-bold" style="font-size: 10px;" onclick="autoPostAll('<?= $pSite['id'] ?>', '<?= $pSite['name'] ?>', <?= $pId ?>, <?= count($pAllAccounts) ?>)">
                  <i class="fas fa-paper-plane me-1"></i>Post
                </button>
              <?php else: ?>
                <button class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 10px;" onclick="showCredForm('<?= $pSite['id'] ?>', '<?= $pSite['name'] ?>', <?= $pId ?>)">
                  <i class="fas fa-key me-1"></i>Add
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    $quickStatusHtml = ob_get_clean();

    $primaryConsoleHtml = renderPrimaryConsoleTableHtml($db, $pId, $kw, $siteUrl, $platforms, $savedAccountsRows);

    echo json_encode([
        'success' => true,
        'createdCount' => count($createdBLList),
        'pendingCount' => count($pendingList),
        'createdHtml' => $createdHtml,
        'pendingHtml' => $pendingHtml,
        'quickStatusHtml' => $quickStatusHtml,
        'primaryConsoleHtml' => $primaryConsoleHtml
    ]);
    exit;
}


// ============================================================
// WordPress.com OAuth2 Callback — auto-save token
// URL: http://localhost/seo-system/submission-manager.php?wp_oauth=1&code=XXX
// ============================================================
if (isset($_GET['wp_oauth']) && isset($_GET['code'])) {
    $code         = clean($_GET['code']);
    $clientId     = '138093';
    $clientSecret = 'vJABVPp0TCW05oBjjfpXqgaeVH9PdvN3X0f53DlLFuHYyZtKuvibvTwzB4x795Ew';
    $redirectUri  = SITE_URL . '/submission-manager.php';

    // Exchange code for access token
    $ch = curl_init('https://public-api.wordpress.com/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);

    if (!empty($data['access_token'])) {
        $token    = $data['access_token'];
        $blogUrl  = $data['blog_url'] ?? '';
        $wpSite   = str_replace(['https://', 'http://'], '', rtrim($blogUrl, '/'));

        // Save to social_accounts
        $check = $db->prepare("SELECT id FROM social_accounts WHERE user_id=? AND platform='wordpress'");
        $check->execute([$userId]);
        if ($check->fetch()) {
            $db->prepare("UPDATE social_accounts SET api_key=?, username=? WHERE user_id=? AND platform='wordpress'")
               ->execute([$token, $wpSite, $userId]);
        } else {
            $db->prepare("INSERT INTO social_accounts (user_id, platform, username, api_key, status) VALUES (?,?,?,?,'active')")
               ->execute([$userId, 'wordpress', $wpSite, $token]);
        }
        setFlash('success', '✅ WordPress.com connected! Token saved automatically. Site: ' . $wpSite);
    } else {
        $err = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
        setFlash('danger', '❌ WordPress OAuth failed: ' . $err);
    }
    header('Location: submission-manager.php'); exit;
}

// WordPress AJAX Connect — email+password → token → save → test post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wp_ajax_connect'])) {
    header('Content-Type: application/json');

    $clientId     = '141079';
    $clientSecret = 'V4vTd95NVEIBHNYfFXLBUgCRSa70CrcWiPaLdKRuOOCV3VltBz3nvsG2wVLFVOAV';
    $wpEmail      = trim($_POST['wp_email'] ?? '');
    $wpPassword   = $_POST['wp_password'] ?? '';

    if (empty($wpEmail) || empty($wpPassword)) {
        echo json_encode(['error' => 'Email and Password required']);
        exit;
    }

    // Step 1: Get token
    $ch = curl_init('https://public-api.wordpress.com/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'password',
            'username'      => $wpEmail,
            'password'      => $wpPassword,
        ]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode($resp, true);

    if (empty($tokenData['access_token'])) {
        echo json_encode(['error' => $tokenData['error_description'] ?? $tokenData['error'] ?? 'Token failed']);
        exit;
    }
    $token = $tokenData['access_token'];

    // Step 2: Get sites
    $ch2 = curl_init('https://public-api.wordpress.com/rest/v1.1/me/sites?fields=ID,URL,name');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $sitesResp = curl_exec($ch2);
    curl_close($ch2);
    $sitesData = json_decode($sitesResp, true);
    $sites     = $sitesData['sites'] ?? [];

    // Fallback: get primary blog from /me
    if (empty($sites)) {
        $ch3 = curl_init('https://public-api.wordpress.com/rest/v1.1/me');
        curl_setopt_array($ch3, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $meData    = json_decode(curl_exec($ch3), true);
        curl_close($ch3);
        $primaryId  = $meData['primary_blog'] ?? null;
        $primaryUrl = $meData['primary_blog_url'] ?? null;
        if ($primaryId) {
            $sites = [['ID' => $primaryId, 'URL' => $primaryUrl, 'name' => 'Primary Blog']];
        }
    }

    if (empty($sites)) {
        echo json_encode(['error' => 'No WordPress site found for this account']);
        exit;
    }

    $siteId     = $sites[0]['ID'];
    $siteDomain = str_replace(['https://','http://'], '', rtrim($sites[0]['URL'] ?? '', '/'));

    // Step 3: Save token to DB
    $check = $db->prepare("SELECT id FROM social_accounts WHERE user_id=? AND platform='wordpress'");
    $check->execute([$userId]);
    if ($check->fetch()) {
        $db->prepare("UPDATE social_accounts SET api_key=?, username=?, status='active' WHERE user_id=? AND platform='wordpress'")
           ->execute([$token, $siteDomain, $userId]);
    } else {
        $db->prepare("INSERT INTO social_accounts (user_id,platform,username,api_key,status) VALUES (?,?,?,?,'active')")
           ->execute([$userId, 'wordpress', $siteDomain, $token]);
    }

    // Step 4: Test post
    $postUrl = null;
    $ch4 = curl_init("https://public-api.wordpress.com/rest/v1.1/sites/{$siteId}/posts/new");
    curl_setopt_array($ch4, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'title'   => 'SEO Guide - LearnMore Technologies ' . date('Y'),
            'content' => '<h1>Best Training in Bangalore</h1><p>LearnMore Technologies offers industry-best training programs. Visit <a href="https://learnmoretech.in">learnmoretech.in</a> to enroll today.</p>',
            'status'  => 'publish',
            'tags'    => 'training,seo,learnmore,bangalore',
        ]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $postResp   = curl_exec($ch4);
    $postCode   = (int)curl_getinfo($ch4, CURLINFO_HTTP_CODE);
    curl_close($ch4);
    $postResult = json_decode($postResp, true);
    $postUrl    = $postResult['URL'] ?? null;

    echo json_encode([
        'success'  => true,
        'site'     => $siteDomain,
        'site_id'  => $siteId,
        'token'    => substr($token, 0, 10) . '...',
        'post_url' => $postUrl,
        'message'  => 'WordPress connected! Token saved.',
    ]);
    exit;
}

// WordPress OAuth — start flow
if (isset($_GET['wp_connect'])) {
    $clientId    = '138093';
    $redirectUri = SITE_URL . '/submission-manager.php';
    $authUrl = 'https://public-api.wordpress.com/oauth2/authorize?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'global',
    ]);
    header('Location: ' . $authUrl); exit;
}

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo'])) {
    if (isset($_FILES['logo_image']) && $_FILES['logo_image']['error'] === 0) {
        $assetsDir = __DIR__ . '/assets/';
        if (!is_dir($assetsDir)) mkdir($assetsDir, 0755, true);
        $ext     = strtolower(pathinfo($_FILES['logo_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            move_uploaded_file($_FILES['logo_image']['tmp_name'], $assetsDir . 'logo.' . $ext);
            // Save extension to config file
            file_put_contents($assetsDir . 'logo_ext.txt', $ext);
            setFlash('success', 'Logo uploaded! Will be used in all generated images.');
        } else {
            setFlash('danger', 'Invalid file. Use PNG (recommended), JPG, GIF or WebP.');
        }
    }
    $projectId = (int)($_POST['project_id'] ?? 0);
    $kwParam = !empty($_POST['keyword']) ? '&keyword=' . urlencode($_POST['keyword']) : '';
    $siteParam = !empty($_POST['target_site']) ? '&target_site=' . urlencode($_POST['target_site']) : '';
    header('Location: submission-manager.php?project_id=' . $projectId . $kwParam . $siteParam); exit;
}

// Handle image upload for project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image'])) {
    $projectId = (int)$_POST['project_id'];
    if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] === 0) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext      = strtolower(pathinfo($_FILES['post_image']['name'], PATHINFO_EXTENSION));
        $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $filename = 'project_' . $projectId . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['post_image']['tmp_name'], $uploadDir . $filename);
            $db->prepare("UPDATE projects SET post_image=? WHERE id=? AND user_id=?")
               ->execute([$filename, $projectId, $userId]);
            setFlash('success', 'Image uploaded! System will use this image for all posts.');
        } else {
            setFlash('danger', 'Invalid file type. Use JPG, PNG, GIF or WebP.');
        }
    }
    $kwParam = !empty($_POST['keyword']) ? '&keyword=' . urlencode($_POST['keyword']) : '';
    $siteParam = !empty($_POST['target_site']) ? '&target_site=' . urlencode($_POST['target_site']) : '';
    header('Location: submission-manager.php?project_id=' . $projectId . $kwParam . $siteParam); exit;
}

// Fetch all projects for this user
$projects = $db->prepare("SELECT * FROM projects WHERE user_id=? ORDER BY created_at DESC");
$projects->execute([$userId]);
$projects = $projects->fetchAll();

$flash = getFlash();

// Handle save platform credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_platform'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.'); header('Location: submission-manager.php'); exit;
    }
    $accId     = (int)($_POST['account_id'] ?? 0);
    $platform  = clean($_POST['platform']);
    $username  = clean($_POST['username']);
    $password  = $_POST['password'] ?? '';
    $apiKey    = clean($_POST['api_key'] ?? '');
    $apiSecret = clean($_POST['api_secret'] ?? '');
    $projectId = (int)$_POST['project_id'];

    // Mastodon: username field = instance (mastodon.social)
    // email goes to api_secret for Selenium use
    if ($platform === 'mastodon') {
        $email = $username; // entered email
        if (strpos($email, '@') !== false) {
            // User entered email — store properly
            $username  = 'mastodon.social'; // instance
            $apiSecret = $email;            // email for Selenium
        }
    }

    $existing = null;
    if ($accId > 0) {
        $check = $db->prepare("SELECT * FROM social_accounts WHERE id=? AND user_id=?");
        $check->execute([$accId, $userId]);
        $existing = $check->fetch();
    }
    if (!$existing) {
        $check = $db->prepare("SELECT * FROM social_accounts WHERE project_id=? AND platform=? AND username=?");
        $check->execute([$projectId, $platform, $username]);
        $existing = $check->fetch();
    }

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
        if ($existing && (empty($password) || trim($password) === '')) {
            $encryptedPassword = $existing['password'];
        } else {
            $encryptedPassword = base64_encode($password);
        }
    }

    if ($existing) {
        $db->prepare("UPDATE social_accounts SET username=?, password=?, api_key=?, api_secret=?, user_id=?, status='active' WHERE id=?")
           ->execute([$username, $encryptedPassword, $apiKey, $apiSecret, $userId, $existing['id']]);
    } else {
        $db->prepare("INSERT INTO social_accounts (user_id, project_id, platform, username, password, api_key, api_secret, status) VALUES (?,?,?,?,?,?,?,'active')")
           ->execute([$userId, $projectId, $platform, $username, $encryptedPassword, $apiKey, $apiSecret]);
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'platform' => $platform, 'username' => $username, 'message' => ucfirst($platform) . ' credentials saved!']);
        exit;
    }
    setFlash('success', ucfirst($platform) . ' credentials updated! System will use these to post.');
    header('Location: submission-manager.php?project_id=' . $projectId); exit;
}

// Handle delete account
if (isset($_GET['delete_account'])) {
    $accId = (int)$_GET['delete_account'];
    $db->prepare("DELETE FROM social_accounts WHERE id=? AND user_id=?")->execute([$accId, $userId]);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle AJAX single backlink verification
if (isset($_GET['verify_backlink_ajax'])) {
    $blId = (int)$_GET['verify_backlink_ajax'];
    
    // Fetch backlink
    $stmt = $db->prepare("
        SELECT b.*, p.website_url, p.target_site 
        FROM backlinks b
        JOIN projects p ON b.project_id = p.id
        WHERE b.id = ? AND p.user_id = ?
    ");
    $stmt->execute([$blId, $userId]);
    $bl = $stmt->fetch();
    
    if (!$bl) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Backlink not found or access denied.']);
        exit;
    }
    
    $status = verifyBacklink($bl['backlink_url'], $bl['website_url'], $bl['target_site']);
    
    $db->prepare("UPDATE backlinks SET verified_status=?, last_checked_at=NOW() WHERE id=?")
       ->execute([$status, $blId]);
       
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'status' => $status, 
        'last_checked' => date('d M, H:i')
    ]);
    exit;
}

// Handle universal bulk account add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bulk_universal'])) {
    $projectId = (int)$_POST['project_id'];
    $lines     = explode("\n", trim($_POST['bulk_accounts'] ?? ''));
    $added = 0; $errors = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        $parts = str_getcsv($line); // handle commas in values
        if (count($parts) < 3) { $errors[] = "Skipped (bad format): $line"; continue; }
        $platform  = clean(strtolower(trim($parts[0])));
        $username  = clean(trim($parts[1]));
        $password  = trim($parts[2]); // api_key or password
        $apiSecret = isset($parts[3]) ? clean(trim($parts[3])) : '';

        // For platforms using api_key (devto, github, hashnode, plurk, tumblr, wordpress)
        $apiKeyPlatforms = ['devto','github','hashnode','plurk','tumblr','wordpress','blogger','ghost','minds'];
        if (in_array($platform, $apiKeyPlatforms)) {
            $db->prepare("INSERT INTO social_accounts (user_id, platform, username, api_key, api_secret, status) VALUES (?,?,?,?,?,'active')")
               ->execute([$userId, $platform, $username, $password, $apiSecret]);
        } else {
            // username+password platforms (bluesky, mediafire, fourshared, penzu, etc.)
            $db->prepare("INSERT INTO social_accounts (user_id, platform, username, password, api_key, status) VALUES (?,?,?,?,?,'active')")
               ->execute([$userId, $platform, $username, base64_encode($password), $apiSecret]);
        }
        $added++;
    }
    $msg = "$added account(s) added!";
    if (!empty($errors)) $msg .= ' Issues: ' . implode(', ', $errors);
    setFlash('success', $msg);
    header('Location: submission-manager.php?project_id=' . $projectId); exit;
}

// Handle bulk Bluesky accounts (legacy)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bulk_bluesky'])) {
    $projectId = (int)$_POST['project_id'];
    $lines = explode("\n", trim($_POST['bluesky_bulk']));
    foreach ($lines as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) === 2) {
            $handle = clean($parts[0]);
            $appPass = trim($parts[1]);
            $db->prepare("INSERT IGNORE INTO social_accounts (user_id, platform, username, password) VALUES (?, 'bluesky', ?, ?)")
               ->execute([$userId, $handle, base64_encode($appPass)]);
        }
    }
    setFlash('success', 'Bulk accounts processed!');
    header('Location: submission-manager.php?project_id=' . $projectId); exit;
}

$selectedProjectId = (int)($_GET['project_id'] ?? ($projects[0]['id'] ?? 0));
$project = null;
foreach ($projects as $p) {
    if ($p['id'] == $selectedProjectId) { $project = $p; break; }
}

// Self-healing migration for schema column
try {
    $db->exec("ALTER TABLE projects ADD COLUMN seo_schema_markup TEXT DEFAULT NULL");
} catch (PDOException $e) {}

// Handle AJAX indexing status checking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_indexing') {
    header('Content-Type: application/json');
    $backlinkId = (int)($_POST['backlink_id'] ?? 0);
    
    // Check ownership
    $stmt = $db->prepare("SELECT b.* FROM backlinks b JOIN projects p ON b.project_id = p.id WHERE b.id = ? AND (p.user_id = ? OR ? = 'admin')");
    $stmt->execute([$backlinkId, $userId, $_SESSION['role']]);
    $backlink = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$backlink) {
        echo json_encode(['success' => false, 'error' => 'Backlink not found.']);
        exit;
    }
    
    $status = checkUrlGoogleIndexing($backlink['backlink_url']);
    
    $update = $db->prepare("UPDATE backlinks SET indexing_status = ?, last_index_checked_at = NOW() WHERE id = ?");
    $update->execute([$status, $backlinkId]);
    
    echo json_encode(['success' => true, 'status' => $status]);
    exit;
}

// Handle AI Schema Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_schema'])) {
    if ($project) {
        $pName = !empty($project['business_name']) ? $project['business_name'] : parse_url($project['website_url'], PHP_URL_HOST);
        $pDesc = !empty($project['business_desc']) ? $project['business_desc'] : 'Professional service provider';
        $pUrl  = $project['website_url'];
        $pKw   = $project['target_keyword'];
        
        $schemaPrompt = "Generate a perfect, valid HTML <script type=\"application/ld+json\"> schema markup for a LocalBusiness or Organization.
        Business Name: {$pName}
        Website URL: {$pUrl}
        Keywords/Services: {$pKw}
        Description: {$pDesc}
        
        STRICT REQUIREMENTS:
        - Return ONLY the script tag containing the JSON-LD, like this: <script type=\"application/ld+json\">...</script>
        - Do NOT include any markdown backticks (such as ```html or ```json) or explanations. Just return the raw script tag code.
        - Ensure all double quotes are properly escaped and the JSON format is valid.";
        
        $generatedCode = '';
        if (hasChatGPT()) {
            $generatedCode = generateWithOpenAI($schemaPrompt, OPENAI_API_KEY);
        } elseif (hasGemini()) {
            $generatedCode = generateWithGemini($schemaPrompt, GEMINI_API_KEY);
        }
        
        // Intelligent Schema Fallback if AI key is missing or quota is empty
        if (empty($generatedCode)) {
            $schemaData = [
                "@context" => "https://schema.org",
                "@type" => "LocalBusiness",
                "name" => $pName,
                "url" => $pUrl,
                "description" => $pDesc,
                "keywords" => $pKw,
            ];
            if (!empty($project['phone'])) {
                $schemaData['telephone'] = $project['phone'];
            }
            if (!empty($project['email'])) {
                $schemaData['email'] = $project['email'];
            }
            if (!empty($project['business_address'])) {
                $schemaData['address'] = [
                    "@type" => "PostalAddress",
                    "streetAddress" => $project['business_address']
                ];
            }
            if (!empty($project['business_hours'])) {
                $schemaData['openingHours'] = $project['business_hours'];
            }
            $generatedCode = "<script type=\"application/ld+json\">\n" . json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n</script>";
        }
        
        if (!empty($generatedCode)) {
            $generatedCode = trim($generatedCode);
            $generatedCode = preg_replace('/^```[a-z]*\s*/i', '', $generatedCode);
            $generatedCode = preg_replace('/\s*```$/i', '', $generatedCode);
            $generatedCode = trim($generatedCode);
            
            // Save to database
            $upd = $db->prepare("UPDATE projects SET seo_schema_markup = ? WHERE id = ?");
            $upd->execute([$generatedCode, $selectedProjectId]);
            
            // Update local $project copy so it displays immediately without reload
            $project['seo_schema_markup'] = $generatedCode;
            
            setFlash('success', 'JSON-LD SEO Schema Markup generated & saved successfully!');
        } else {
            setFlash('danger', 'Failed to generate schema.');
        }
    }
    
    header("Location: submission-manager.php?project_id=" . $selectedProjectId);
    exit;
}

// Fetch saved credentials
$savedAccounts = $db->prepare("SELECT * FROM social_accounts WHERE project_id=?");
$savedAccounts->execute([$selectedProjectId]);
$savedAccounts = $savedAccounts->fetchAll();
$savedAccountsRows = $savedAccounts ?: [];
// savedMap: platform => first account (for backward compat)
// savedMapAll: platform => array of all accounts
$savedMap    = [];
$savedMapAll = [];
foreach ($savedAccounts as $acc) {
    if (!isset($savedMap[$acc['platform']])) {
        $savedMap[$acc['platform']] = $acc;
    }
    $savedMapAll[$acc['platform']][] = $acc;
}

function checkPlatformCooldown($db, $projectId, $platform, $keyword, $targetUrl, $activeAccountsCount = 1) {
    if ($activeAccountsCount < 1) {
        $activeAccountsCount = 1;
    }
    // Cooldown is 12 hours = 43200 seconds
    $cooldownPeriod = 43200; 

    // Retrieve all posts created for this specific platform, project, keyword & target_url in the last 12 hours
    $blCheck = $db->prepare("
        SELECT created_at FROM backlinks 
        WHERE project_id = ? 
          AND platform = ? 
          AND status = 'created' 
          AND keyword = ?
          AND (target_url = ? OR (target_url IS NULL AND ? = ''))
          AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
        ORDER BY created_at DESC
    ");
    $blCheck->execute([
        $projectId, 
        $platform, 
        $keyword, 
        $targetUrl,
        $targetUrl
    ]);
    $recentPosts = $blCheck->fetchAll(PDO::FETCH_ASSOC);

    // If the number of recent posts meets or exceeds the active accounts count, cooldown is active.
    if (count($recentPosts) >= $activeAccountsCount) {
        // Cooldown expires when the oldest of the N recent posts expires (is older than 12h)
        $oldestRecentPost = end($recentPosts);
        $lastPostTime = strtotime($oldestRecentPost['created_at']);
        $elapsed = time() - $lastPostTime;
        if ($elapsed < $cooldownPeriod) {
            $remaining = $cooldownPeriod - $elapsed;
            $hours = floor($remaining / 3600);
            $minutes = floor(($remaining % 3600) / 60);
            $timeString = ($hours > 0 ? "{$hours}h " : "") . "{$minutes}m";
            return [
                'is_cooldown' => true,
                'remaining' => $remaining,
                'time_str' => $timeString
            ];
        }
    }
    return [
        'is_cooldown' => false,
        'remaining' => 0,
        'time_str' => ''
    ];
}

function getPrimaryConsoleSites($platformsList) {
    $primaryIds = ['pinterest', 'bluesky', 'mastodon', 'minds', 'symbaloo', 'devto', 'livejournal', 'blogger', 'tumblr', 'github'];
    $orderedSitesList = [];
    $addedIds = [];

    foreach ($primaryIds as $id) {
        foreach ($platformsList as $catKey => $cat) {
            foreach ($cat['sites'] as $site) {
                if ($site['id'] === $id && !in_array($id, $addedIds)) {
                    $orderedSitesList[] = $site;
                    $addedIds[] = $id;
                    break 2;
                }
            }
        }
    }
    return array_slice($orderedSitesList, 0, 10);
}

function renderPrimaryConsoleTableHtml($db, $selectedProjectId, $currentKeyword, $currentTargetSite, $platformsList, $savedAccountsRows) {
    $firstBoxList = getPrimaryConsoleSites($platformsList);
    $savedMap = [];
    $savedMapAll = [];
    foreach (($savedAccountsRows ?: []) as $acc) {
        $savedMap[$acc['platform']] = true;
        $savedMapAll[$acc['platform']][] = $acc;
    }
    ob_start();
    ?>
    <div class="card mb-4 border-0 shadow-sm" id="primaryConsoleCard">
      <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Primary Platforms Console (1 - 10)</h5>
        <div>
          <button class="btn btn-sm btn-success text-white fw-bold me-2 shadow-sm" id="btnRunSelected" onclick="runSelectedPlatforms(<?= $selectedProjectId ?>)">
            <i class="fas fa-play me-1"></i>Run Selected (<span id="selectedCount">0</span>)
          </button>
          <button class="btn btn-sm btn-outline-light fw-bold" onclick="autoPostAll(<?= $selectedProjectId ?>)">
            <i class="fas fa-paper-plane me-1"></i>Run All Primary
          </button>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th style="width: 4%; text-align: center;">
                  <input type="checkbox" id="selectAllPlatformsCheckbox" class="form-check-input" onchange="toggleSelectAllPlatforms(this)" title="Select/Deselect All">
                </th>
                <th style="width: 5%;">#</th>
                <th style="width: 15%;">Platform</th>
                <th style="width: 35%;">What System Does Automatically</th>
                <th style="width: 20%;">Your Credentials</th>
                <th style="width: 12%;">Status</th>
                <th style="width: 13%;">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($firstBoxList as $idx => $site): ?>
              <?php 
              $saved = isset($savedMap[$site['id']]);
              $allAccounts = $savedMapAll[$site['id']] ?? []; 
              ?>
              <tr>
                <td class="text-center">
                  <input type="checkbox" class="form-check-input platform-select-checkbox" value="<?= $site['id'] ?>" onchange="updateSelectedPlatformsCount()">
                </td>
                <td><?= $idx + 1 ?></td>
                <td>
                  <strong><?= htmlspecialchars($site['name']) ?></strong><br>
                  <a href="<?= htmlspecialchars($site['url']) ?>" target="_blank" class="small text-muted">
                    <?= htmlspecialchars(substr($site['url'], 0, 30)) ?><?= strlen($site['url']) > 30 ? '...' : '' ?> <i class="fas fa-external-link-alt"></i>
                  </a>
                </td>
                <td>
                  <small class="text-success">
                    <i class="fas fa-robot me-1"></i><?= htmlspecialchars($site['what_system_does']) ?>
                  </small>
                </td>
                <td>
                  <?php if (isset($site['autopost']) && $site['autopost'] === false && empty($allAccounts)): ?>
                    <span class="text-muted small">Coming Soon</span>
                  <?php else: ?>
                    <?php if (!empty($allAccounts)): ?>
                      <?php foreach ($allAccounts as $acc): ?>
                      <div class="d-flex align-items-center gap-1 mb-1">
                        <span class="text-success small">
                          <i class="fas fa-check-circle me-1"></i><?php
                            if ($site['id'] === 'mastodon' && !empty($acc['api_secret'])) {
                                echo clean($acc['api_secret']);
                            } else {
                                echo clean($acc['username']);
                            }
                          ?>
                        </span>
                        <button class="btn btn-xs btn-outline-secondary py-0 px-1"
                                onclick='editCredForm(<?= json_encode($acc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($site['id']) ?>, <?= json_encode($site['name']) ?>, <?= (int)$selectedProjectId ?>)'
                                title="Edit Credentials / Token / Password">
                          <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-xs btn-outline-danger py-0 px-1"
                                onclick="deleteAccount(<?= $acc['id'] ?>, this)"
                                title="Remove">
                          <i class="fas fa-times"></i>
                        </button>
                      </div>
                      <?php endforeach; ?>
                      <?php if ($site['id'] === 'wordpress'): ?>
                        <button onclick="showWpConnect()" class="btn btn-xs btn-primary mt-1">
                          <i class="fab fa-wordpress me-1"></i>Re-Connect WordPress
                        </button>
                      <?php else: ?>
                      <button class="btn btn-xs btn-outline-primary mt-1"
                              onclick="showCredForm('<?= $site['id'] ?>', '<?= $site['name'] ?>', <?= $selectedProjectId ?>)">
                        <i class="fas fa-plus me-1"></i>Add More
                      </button>
                      <?php endif; ?>
                    <?php else: ?>
                      <?php if ($site['id'] === 'wordpress'): ?>
                        <button onclick="showWpConnect()" class="btn btn-sm btn-primary">
                          <i class="fab fa-wordpress me-1"></i>Connect WordPress
                        </button>
                        <br><small class="text-muted">Email + Password → Auto-save</small>
                      <?php else: ?>
                      <button class="btn btn-sm btn-outline-primary"
                              onclick="showCredForm('<?= $site['id'] ?>', '<?= $site['name'] ?>', <?= $selectedProjectId ?>)">
                        <i class="fas fa-key me-1"></i>Add Credentials
                      </button>
                      <?php endif; ?>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                  $cooldown = checkPlatformCooldown($db, $selectedProjectId, $site['id'], $currentKeyword, $currentTargetSite, count($allAccounts));
                  
                  // Fetch the latest background queue task for this platform
                  $queueStmt = $db->prepare("SELECT status, error_message FROM backlink_queue WHERE project_id=? AND platform=? AND keyword=? AND target_url=? ORDER BY id DESC LIMIT 1");
                  $queueStmt->execute([$selectedProjectId, $site['id'], $currentKeyword, $currentTargetSite]);
                  $qItem = $queueStmt->fetch(PDO::FETCH_ASSOC);
                  ?>
                  <?php if ($cooldown['is_cooldown']): ?>
                    <span class="badge bg-warning text-dark"><i class="fas fa-history me-1"></i>Posted (Cooldown)</span>
                  <?php elseif (isset($site['autopost']) && $site['autopost'] === false): ?>
                    <span class="badge bg-secondary"><i class="fas fa-clock me-1"></i>Coming Soon</span>
                  <?php elseif ($qItem && $qItem['status'] === 'pending'): ?>
                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>⏳ Queued</span>
                  <?php elseif ($qItem && $qItem['status'] === 'processing'): ?>
                    <span class="badge bg-info"><i class="fas fa-spinner fa-spin me-1"></i>⚙️ Posting...</span>
                  <?php elseif ($qItem && $qItem['status'] === 'failed'): ?>
                    <span class="badge bg-danger text-white" style="cursor:help;" title="<?= htmlspecialchars($qItem['error_message'] ?? 'Unknown Error') ?>"><i class="fas fa-exclamation-triangle me-1"></i>❌ Failed</span>
                  <?php elseif ($saved): ?>
                    <span class="badge bg-success">Ready to Post</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Needs Credentials</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($cooldown['is_cooldown']): ?>
                    <span class="text-muted fw-bold"><i class="fas fa-clock me-1"></i>Wait <?= $cooldown['time_str'] ?></span>
                  <?php elseif (!empty($allAccounts) && (!isset($site['autopost']) || $site['autopost'] !== false)): ?>
                    <button class="btn btn-sm btn-success"
                            onclick="autoPostAll('<?= $site['id'] ?>', '<?= $site['name'] ?>', <?= $selectedProjectId ?>, <?= count($allAccounts) ?>)">
                      <i class="fas fa-paper-plane me-1"></i>Auto Post
                      <?php if (count($allAccounts) > 1): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= count($allAccounts) ?> accounts</span>
                      <?php endif; ?>
                    </button>
                  <?php elseif (isset($site['autopost']) && $site['autopost'] === false): ?>
                    <span class="text-muted small">Coming Soon</span>
                  <?php else: ?>
                    <span class="text-muted small">Add credentials first</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}



// 10 Primary platforms (linktree and site123 moved to secondary)
$primaryIds = ['pinterest', 'bluesky', 'mastodon', 'minds', 'symbaloo', 'devto', 'livejournal', 'blogger', 'tumblr', 'github'];

$orderedSitesList = [];
$addedIds = [];

// 1. Add the primary 12 platforms in the exact order requested
foreach ($primaryIds as $id) {
    foreach ($platforms as $catKey => $cat) {
        foreach ($cat['sites'] as $site) {
            if ($site['id'] === $id && !in_array($id, $addedIds)) {
                $orderedSitesList[] = $site;
                $addedIds[] = $id;
                break 2;
            }
        }
    }
}

// 2. Add all other remaining platforms
// Grouped into auto-post first, then coming soon
$otherAuto = [];
$otherComing = [];

foreach ($platforms as $catKey => $cat) {
    foreach ($cat['sites'] as $site) {
        if (!in_array($site['id'], $addedIds)) {
            $isAuto = isset($site['autopost']) ? $site['autopost'] : true;
            if ($isAuto) {
                $otherAuto[] = $site;
            } else {
                $otherComing[] = $site;
            }
            $addedIds[] = $site['id'];
        }
    }
}

// Sort others alphabetically to be neat
usort($otherAuto, fn($a, $b) => strcasecmp($a['name'], $b['name']));
usort($otherComing, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Combine them into a single flat array
$orderedSitesList = array_merge($orderedSitesList, $otherAuto, $otherComing);

// Split into two boxes: 1-10 and 11-45 (linktree and site123 moved to secondary)
$firstBoxList  = array_slice($orderedSitesList, 0, 10);
$secondBoxList = array_slice($orderedSitesList, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submission Manager - SEO 80/20</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container-fluid py-4">

  <div class="row mb-4">
    <div class="col">
      <h3><i class="fas fa-paper-plane me-2 text-primary"></i>Submission Manager</h3>
      <p class="text-muted">Provide user credentials → System will post automatically</p>
    </div>
    <div class="col-auto">
      <!-- Project selector -->
      <select class="form-select" onchange="location.href='submission-manager.php?project_id='+this.value">
        <?php foreach ($projects as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $p['id'] == $selectedProjectId ? 'selected' : '' ?>>
            <?= clean($p['target_keyword']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
    <?= $flash['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($project): ?>
  <?php
  $keywordsList = array_filter(array_map('trim', explode(',', $project['target_keyword'])));
  if (empty($keywordsList)) {
      $keywordsList = ['SEO Services'];
  }
  $targetSitesList = array_filter(array_map('trim', explode(',', $project['target_site'] ?: $project['website_url'])));
  if (empty($targetSitesList)) {
      $targetSitesList = [$project['website_url']];
  }
  $currentKeyword = $_GET['keyword'] ?? $keywordsList[0] ?? '';
  $currentTargetSite = $_GET['target_site'] ?? $targetSitesList[0] ?? '';
  ?>
  <!-- Keyword & Target Page URL Selectors for Auto-Post -->
  <div class="card mb-3 border-info shadow-sm bg-light">
    <div class="card-body py-2 px-3 d-flex align-items-center flex-wrap gap-3">
      <div class="d-flex align-items-center gap-2">
        <strong class="small text-muted mb-0"><i class="fas fa-key me-1"></i> Select Keyword for Auto-Post:</strong>
        <select id="backlinkKeywordSelect" class="form-select form-select-sm" style="width: auto; min-width: 250px;" onchange="updateAutoPostSelection()">
          <?php foreach ($keywordsList as $kw): ?>
            <option value="<?= htmlspecialchars($kw, ENT_QUOTES, 'UTF-8') ?>" <?= $currentKeyword === $kw ? 'selected' : '' ?>><?= htmlspecialchars($kw) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="d-flex align-items-center gap-2">
        <strong class="small text-muted mb-0"><i class="fas fa-link me-1"></i> Select Target Page URL:</strong>
        <select id="backlinkUrlSelect" class="form-select form-select-sm" style="width: auto; min-width: 320px;" onchange="updateAutoPostSelection()">
          <?php foreach ($targetSitesList as $url): ?>
            <option value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" <?= $currentTargetSite === $url ? 'selected' : '' ?>><?= htmlspecialchars($url) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-sm btn-outline-secondary ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#manageTargetsPanel">
        <i class="fas fa-edit me-1"></i>Edit Lists
      </button>
    </div>
  </div>

  <!-- Manage Keywords & Target URLs Collapse Panel -->
  <div class="collapse mb-4" id="manageTargetsPanel">
    <div class="card border-info shadow-sm">
      <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-tasks me-2"></i>Manage Keywords & Target Page URLs</h6>
        <small class="text-white-50">Changes are auto-saved to your project profile</small>
      </div>
      <div class="card-body">
        <div class="row">
          <!-- Keywords Column -->
          <div class="col-md-6 border-end">
            <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-key me-1"></i> Keywords List</h6>
            <div id="managerKeywordsContainer" class="d-flex flex-wrap gap-2 mb-3">
              <!-- rendered via JS badge list -->
            </div>
            <div class="input-group input-group-sm">
              <input type="text" id="managerKeywordInput" class="form-control" placeholder="Add new keyword">
              <button class="btn btn-primary" type="button" onclick="managerAddKeyword()">Add</button>
            </div>
          </div>
          <!-- Target Page URLs Column -->
          <div class="col-md-6">
            <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-link me-1"></i> Target Page URLs</h6>
            <div id="managerSitesContainer" class="d-flex flex-wrap gap-2 mb-3">
              <!-- rendered via JS badge list -->
            </div>
            <div class="input-group input-group-sm">
              <input type="url" id="managerSiteInput" class="form-control" placeholder="Add new URL (https://...)">
              <button class="btn btn-info text-white" type="button" onclick="managerAddSite()">Add</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Project Info Banner -->
  <div class="alert alert-success mb-4">
    <div class="row align-items-center">
      <div class="col-md-7">
        <strong><i class="fas fa-info-circle me-2"></i>Project Details:</strong>
        <div class="mt-1 small">
          <div class="mb-1"><strong>Keywords:</strong> <code class="text-dark bg-white border px-2 py-0.5 rounded" style="word-break: break-all; white-space: normal; display: inline-block; max-width: 100%;"><?= clean($project['target_keyword']) ?></code></div>
          <div><strong>Target Site:</strong> <code class="text-dark bg-white border px-2 py-0.5 rounded" style="word-break: break-all; white-space: normal; display: inline-block; max-width: 100%;"><?= clean($project['target_site'] ?: $project['website_url']) ?></code></div>
        </div>
      </div>
      <div class="col-md-5 text-md-end text-start mt-3 mt-md-0">
        <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-md-end">
          <a href="export-excel.php?id=<?= $selectedProjectId ?>" class="btn btn-success btn-sm">
            <i class="fas fa-file-excel me-1"></i>Download Excel
          </a>
          <button class="btn btn-primary btn-sm" onclick="runAllSubmissions()">
            <i class="fas fa-rocket me-1"></i>Run All
          </button>
          <button class="btn btn-info btn-sm" onclick="bulkBlueskyPost(<?= $selectedProjectId ?>)">
            <i class="fas fa-paper-plane me-1"></i>Bulk Post All
          </button>
          <button class="btn btn-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#bulkAddPanel">
            <i class="fas fa-users-cog me-1"></i>Bulk Add
          </button>
        </div>
      </div>

        <!-- Universal Bulk Add Accounts Panel -->
        <div class="collapse mt-3" id="bulkAddPanel">
          <div class="card border-info shadow-sm">
            <div class="card-header bg-info text-white">
              <h6 class="mb-0"><i class="fas fa-users me-2"></i>Bulk Add Multiple Accounts — Any Platform</h6>
            </div>
            <div class="card-body">
              <p class="small text-muted mb-2">
                Format: <code>platform,username,password_or_apikey,api_secret(optional)</code><br>
                One account per line. Example:
              </p>
              <pre class="bg-dark text-success p-2 rounded small">bluesky,lmt20.bsky.social,ug4x-go7k-mdm3-5zld
bluesky,learnmore59.bsky.social,xgfn-obvs-wuvc-grac
devto,myuser2,api_key_here
plurk,myuser3,api_key_here
penzu,email@test.com,password123
github,myuser4,ghp_token_here
wordpress,myblog.wordpress.com,oauth_token_here</pre>
              <form method="post" action="submission-manager.php?project_id=<?= $selectedProjectId ?>">
                <input type="hidden" name="add_bulk_universal" value="1">
                <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                <textarea name="bulk_accounts" rows="8" class="form-control mb-2"
                  placeholder="bluesky,handle1.bsky.social,xxxx-xxxx-xxxx-xxxx&#10;bluesky,handle2.bsky.social,yyyy-yyyy-yyyy-yyyy&#10;devto,username,api_key_here"></textarea>
                <button type="submit" class="btn btn-success w-100">
                  <i class="fas fa-plus me-2"></i>Add All Accounts
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php
  // 10 Primary platforms (linktree and site123 moved to secondary)
  $primaryIds = ['pinterest', 'bluesky', 'mastodon', 'minds', 'symbaloo', 'devto', 'livejournal', 'blogger', 'tumblr', 'github'];
  $primaryPlatforms = [];
  
  // Icon Mapping
  $iconMap = [
      'pinterest' => 'fab fa-pinterest text-danger',
      'bluesky' => 'fas fa-cloud text-primary',
      'mastodon' => 'fab fa-mastodon text-info',
      'minds' => 'fas fa-circle-notch text-dark',
      'symbaloo' => 'fas fa-th text-warning',
      'devto' => 'fab fa-dev text-dark',
      'linktree' => 'fas fa-tree text-success',
      'site123' => 'fas fa-globe text-primary',
      'livejournal' => 'fas fa-book text-info',
      'blogger' => 'fab fa-blogger text-warning',
      'tumblr' => 'fab fa-tumblr text-primary',
      'github' => 'fab fa-github text-dark'
  ];

  foreach ($primaryIds as $id) {
      foreach ($platforms as $catKey => $cat) {
          foreach ($cat['sites'] as $site) {
              if ($site['id'] === $id) {
                  $site['icon'] = $iconMap[$id] ?? 'fas fa-share-alt';
                  $primaryPlatforms[] = $site;
                  break 2;
              }
          }
      }
  }
  ?>

  <!-- AI SEO Schema Markup Card -->
  <div class="card mb-4 border-info shadow-sm">
    <div class="card-header bg-info text-white py-2 d-flex justify-content-between align-items-center">
      <h6 class="mb-0 fw-bold"><i class="fas fa-code me-2"></i>AI JSON-LD Schema Markup Generator</h6>
      <span class="badge bg-light text-info small fw-bold">SEO Booster</span>
    </div>
    <div class="card-body">
      <p class="small text-muted mb-3">
        Schema Markup (JSON-LD) translates your business details into structured data that search engines understand instantly. Copy and paste this code inside the <code>&lt;head&gt;</code> tags of your main website to boost ranking and rich snippet visibility!
      </p>
      
      <?php if (!empty($project['seo_schema_markup'])): ?>
        <div class="position-relative">
          <textarea id="schemaMarkupText" class="form-control bg-dark text-success p-3 rounded font-monospace" style="font-size:13px; height: 200px; line-height: 1.4;" readonly><?= htmlspecialchars($project['seo_schema_markup']) ?></textarea>
          <button class="btn btn-sm btn-light position-absolute" style="top: 10px; right: 10px; opacity: 0.9;" 
                  onclick="navigator.clipboard.writeText(document.getElementById('schemaMarkupText').value).then(()=>alert('Schema Code Copied to Clipboard!'))">
            <i class="fas fa-copy me-1"></i>Copy Code
          </button>
        </div>
        <form method="post" action="submission-manager.php?project_id=<?= $selectedProjectId ?>" class="mt-3">
          <input type="hidden" name="generate_schema" value="1">
          <button type="submit" class="btn btn-outline-info btn-sm">
            <i class="fas fa-sync-alt me-1"></i>Regenerate Schema Code
          </button>
        </form>
      <?php else: ?>
        <div class="bg-light p-4 rounded text-center border">
          <i class="fas fa-code fa-3x text-info mb-3"></i>
          <h5>No Schema Markup Generated</h5>
          <p class="small text-muted mb-3">Click below to let AI automatically build search-engine-ready JSON-LD schema based on your project keywords and company description.</p>
          <form method="post" action="submission-manager.php?project_id=<?= $selectedProjectId ?>">
            <input type="hidden" name="generate_schema" value="1">
            <button type="submit" class="btn btn-info text-white btn-sm px-4">
              <i class="fas fa-magic me-2"></i>Generate Schema Code Now
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Primary Platforms Status Widget -->
  <div class="card mb-4 border-primary shadow-sm">
    <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
      <h6 class="mb-0 fw-bold"><i class="fas fa-star me-2"></i>Primary Platforms Quick Status</h6>
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-success text-white fw-bold shadow-sm" id="btnRunSelectedHeader" onclick="runSelectedPlatforms(<?= $selectedProjectId ?>)">
          <i class="fas fa-play me-1"></i>Run Selected (<span id="selectedCountHeader">0</span>)
        </button>
        <button class="btn btn-sm btn-light text-primary fw-bold" onclick="autoPostAll(<?= $selectedProjectId ?>)">
          <i class="fas fa-paper-plane me-1"></i>Run All Primary
        </button>
        <span class="badge bg-light text-primary small fw-bold">10 Platforms</span>
      </div>
    </div>
    <div class="card-body p-3 bg-light" id="quickStatusGridContainer">
      <div class="row g-2">
        <?php foreach ($primaryPlatforms as $idx => $pSite): ?>
          <?php 
          $pSaved = isset($savedMap[$pSite['id']]);
          $pAllAccounts = $savedMapAll[$pSite['id']] ?? [];
          
          // Cooldown check
          $pCooldown = checkPlatformCooldown($db, $selectedProjectId, $pSite['id'], $currentKeyword, $currentTargetSite, count($pAllAccounts));
          ?>
          <div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div class="card h-100 border text-center p-2 bg-white shadow-none position-relative" style="transition: all 0.2s ease-in-out; border-radius: 8px;"
                 onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'; this.style.transform='translateY(-2px)';" 
                 onmouseout="this.style.boxShadow='none'; this.style.transform='none';">
              <div class="position-absolute top-0 end-0 p-1" style="z-index:2;">
                <input type="checkbox" class="form-check-input platform-select-checkbox" value="<?= $pSite['id'] ?>" onchange="updateSelectedPlatformsCount()" title="Select <?= htmlspecialchars($pSite['name']) ?>">
              </div>
              <div class="mb-1 mt-1">
                <i class="<?= $pSite['icon'] ?> fa-lg"></i>
              </div>
              <div class="fw-bold text-dark mb-1" style="font-size: 12.5px; line-height: 1.2; height: 30px; display: flex; align-items: center; justify-content: center;">
                <?= $idx + 1 ?>. <?= $pSite['name'] ?>
              </div>
              
              <!-- Status Badge -->
              <div class="mb-2">
                <?php if ($pCooldown['is_cooldown']): ?>
                  <span class="badge bg-warning text-dark" style="font-size: 9px; padding: 3px 6px;">Posted (Cooldown)</span>
                <?php elseif (isset($pSite['autopost']) && $pSite['autopost'] === false): ?>
                  <span class="badge bg-secondary text-white" style="font-size: 9px; padding: 3px 6px;">Coming Soon</span>
                <?php elseif (!empty($pAllAccounts)): ?>
                  <span class="badge bg-success" style="font-size: 9px; padding: 3px 6px;">Ready</span>
                <?php else: ?>
                  <span class="badge bg-light text-muted border" style="font-size: 9px; padding: 2px 5px;">Needs Creds</span>
                <?php endif; ?>
              </div>

              <!-- Action button -->
              <div class="mt-auto pt-1 mb-1">
                <?php if ($pCooldown['is_cooldown']): ?>
                  <span class="text-muted fw-bold" style="font-size: 11px;"><i class="fas fa-clock me-1"></i>Wait <?= $pCooldown['time_str'] ?></span>
                <?php elseif (isset($pSite['autopost']) && $pSite['autopost'] === false): ?>
                  <span class="text-muted" style="font-size: 11px;">Coming Soon</span>
                <?php elseif (!empty($pAllAccounts)): ?>
                  <button class="btn btn-xs btn-success py-0 px-2 fw-bold" style="font-size: 10px;" onclick="autoPostAll('<?= $pSite['id'] ?>', '<?= $pSite['name'] ?>', <?= $selectedProjectId ?>, <?= count($pAllAccounts) ?>)">
                    <i class="fas fa-paper-plane me-1"></i>Post
                  </button>
                <?php else: ?>
                  <button class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 10px;" onclick="showCredForm('<?= $pSite['id'] ?>', '<?= $pSite['name'] ?>', <?= $selectedProjectId ?>)">
                    <i class="fas fa-key me-1"></i>Add
                  </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Logo Upload + Image Section -->
  <?php
  $postImage = $project['post_image'] ?? null;
  $imageUrl  = $postImage ? SITE_URL . '/uploads/' . $postImage : null;
  // Check if logo exists
  $logoExt  = @file_get_contents(__DIR__ . '/assets/logo_ext.txt');
  $logoPath = $logoExt ? __DIR__ . '/assets/logo.' . trim($logoExt) : null;
  $logoUrl  = ($logoPath && file_exists($logoPath)) ? SITE_URL . '/assets/logo.' . trim($logoExt) : null;
  ?>

  <!-- Logo Upload -->
  <div class="card mb-3 border-danger shadow-sm">
    <div class="card-header bg-danger text-white">
      <h6 class="mb-0"><i class="fas fa-trademark me-2"></i>Company Logo — Used in ALL generated images</h6>
    </div>
    <div class="card-body">
      <div class="row align-items-center">
        <div class="col-md-3 text-center">
          <?php if ($logoUrl): ?>
            <img src="<?= $logoUrl ?>?t=<?= time() ?>" style="max-height:80px;max-width:180px;" alt="Logo">
            <p class="small text-success mt-1"><i class="fas fa-check-circle"></i> Logo ready</p>
          <?php else: ?>
            <div class="border rounded p-3 text-muted text-center">
              <i class="fas fa-trademark fa-2x mb-1"></i><br>
              <small>No logo uploaded</small>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-9">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="upload_logo" value="1">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
            <input type="hidden" name="keyword" value="<?= htmlspecialchars($currentKeyword) ?>">
            <input type="hidden" name="target_site" value="<?= htmlspecialchars($currentTargetSite) ?>">
            <label class="form-label fw-bold">Upload Learnmore Technologies Logo (PNG recommended)</label>
            <div class="input-group">
              <input type="file" name="logo_image" class="form-control" accept="image/*" required>
              <button type="submit" class="btn btn-danger">
                <i class="fas fa-upload me-1"></i>Upload Logo
              </button>
            </div>
            <small class="text-muted">PNG with transparent background works best</small>
          </form>
        </div>
      </div>
    </div>
  </div>
  <div class="card mb-4 border-warning shadow-sm">
    <div class="card-header bg-warning text-dark">
      <h6 class="mb-0"><i class="fas fa-image me-2"></i>Post Image — System will use this image for ALL auto posts</h6>
    </div>
    <div class="card-body">
      <div class="row align-items-center">
        <div class="col-md-3 text-center">
          <?php if ($imageUrl): ?>
            <img src="<?= $imageUrl ?>" class="img-thumbnail" style="max-height:120px;" id="previewImg" alt="Post Image">
            <p class="small text-success mt-1"><i class="fas fa-check-circle"></i> Image ready</p>
            <button class="btn btn-sm btn-warning mt-1 w-100" onclick="addLogoToImage(<?= $selectedProjectId ?>, this)">
              <i class="fas fa-trademark me-1"></i>Add Logo to Image
            </button>
          <?php else: ?>
            <div class="border rounded p-3 text-muted text-center">
              <i class="fas fa-image fa-3x mb-2"></i><br>
              <small>No image uploaded</small>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-9">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="upload_image" value="1">
            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
            <input type="hidden" name="keyword" value="<?= htmlspecialchars($currentKeyword) ?>">
            <input type="hidden" name="target_site" value="<?= htmlspecialchars($currentTargetSite) ?>">
            <label class="form-label fw-bold">Upload your own image OR auto-generate below</label>
            <div class="input-group mb-2">
              <input type="file" name="post_image" class="form-control" accept="image/*">
              <button type="submit" class="btn btn-warning">
                <i class="fas fa-upload me-1"></i>Upload
              </button>
            </div>
          </form>
          <div class="mt-2">
            <button class="btn btn-primary w-100" onclick="generateImage(<?= $selectedProjectId ?>, this)">
              <i class="fas fa-magic me-2"></i>Create Poster (Keyword + Phone + Email)
            </button>
            <small class="text-muted d-block mt-1">
              Generates unique marketing image with: <strong><?= clean($project['target_keyword']) ?></strong> + 9036354554 + office.learnmore@gmail.com + LT Logo
            </small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Progress -->
  <div class="card mb-4 border-0 shadow-sm">
    <div class="card-body">
      <div class="row text-center">
        <?php
        $totalSites = 0;
        foreach ($platforms as $cat) $totalSites += count($cat['sites']);
        $savedCount = count($savedMap);
        $submittedCount = $db->prepare("
            SELECT COUNT(*) FROM backlinks 
            WHERE project_id = ? 
              AND status = 'created' 
              AND (keyword = ? OR (keyword IS NULL AND ? = ''))
              AND (target_url = ? OR target_url IS NULL OR (target_url IS NULL AND ? = ''))
        ");
        $submittedCount->execute([$selectedProjectId, $currentKeyword, $currentKeyword, $currentTargetSite, $currentTargetSite]);
        $submittedCount = $submittedCount->fetchColumn();
        ?>
        <div class="col-3">
          <h3 class="text-primary"><?= $totalSites ?></h3>
          <small>Total Sites</small>
        </div>
        <div class="col-3">
          <h3 class="text-warning"><?= $savedCount ?></h3>
          <small>Credentials Saved</small>
        </div>
        <div class="col-3">
          <h3 class="text-success"><?= $submittedCount ?></h3>
          <small>Posts Created</small>
        </div>
        <div class="col-3">
          <h3 class="text-info"><?= $totalSites - $submittedCount ?></h3>
          <small>Remaining</small>
        </div>
      </div>
    </div>
  </div>

  <!-- Created Backlinks Table -->
  <?php
  // Fetch created backlinks for this project, keyword, and target site
  $createdBL = $db->prepare("
      SELECT * FROM backlinks 
      WHERE project_id = ? 
        AND status = 'created' 
        AND (keyword = ? OR (keyword IS NULL AND ? = ''))
        AND (target_url = ? OR target_url IS NULL OR (target_url IS NULL AND ? = ''))
      ORDER BY created_at DESC
  ");
  $createdBL->execute([$selectedProjectId, $currentKeyword, $currentKeyword, $currentTargetSite, $currentTargetSite]);
  $createdBL = $createdBL->fetchAll();
  ?>
  <div id="createdBacklinksSection">
  <?php if (!empty($createdBL)): ?>
  <div class="card mb-4 border-success shadow-sm">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
      <h5 class="mb-0">
        <i class="fas fa-check-circle me-2"></i>
        Created Backlinks (<?= count($createdBL) ?>) — New URLs
      </h5>
      <a href="export-excel.php?id=<?= $selectedProjectId ?>" class="btn btn-light btn-sm">
        <i class="fas fa-file-excel me-1 text-success"></i>Download Excel
      </a>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-dark">
            <tr>
              <th>#</th>
              <th>Platform</th>
              <th>Backlink URL</th>
              <th>Date Created</th>
              <th>Link Status</th>
              <th>Google Index</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($createdBL as $i => $bl): ?>
            <tr id="bl-row-<?= $bl['id'] ?>">
              <td><?= $i + 1 ?></td>
              <td>
                <span class="badge bg-success"><?= clean($bl['platform']) ?></span>
              </td>
              <td>
                <a href="<?= clean($bl['backlink_url']) ?>" target="_blank" class="text-decoration-none">
                  <?= clean(substr($bl['backlink_url'], 0, 60)) ?>...
                  <i class="fas fa-external-link-alt ms-1 small"></i>
                </a>
              </td>
              <td><small><?= formatLocalTime($bl['created_at'], 'd M Y H:i') ?></small></td>
              <td class="bl-status-cell">
                <?php
                $vStatus = $bl['verified_status'] ?? 'unverified';
                if ($vStatus === 'active') {
                    echo '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active (Dofollow)</span>';
                } elseif ($vStatus === 'nofollow') {
                    echo '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Nofollow</span>';
                } elseif ($vStatus === 'broken') {
                    echo '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Broken / Offline</span>';
                } else {
                    echo '<span class="badge bg-secondary"><i class="fas fa-question-circle me-1"></i>Unverified</span>';
                }
                if (!empty($bl['last_checked_at'])) {
                    echo '<br><small class="text-muted" style="font-size: 10px;">Checked: ' . formatLocalTime($bl['last_checked_at'], 'd M, H:i') . '</small>';
                }
                ?>
              </td>
              <td class="indexing-status-cell">
                <?php
                $iStatus = $bl['indexing_status'] ?? 'unchecked';
                if ($iStatus === 'indexed') {
                    echo '<span class="badge bg-success"><i class="fas fa-search me-1"></i>Indexed</span>';
                } elseif ($iStatus === 'not_indexed') {
                    echo '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Not Indexed</span>';
                } else {
                    echo '<span class="badge bg-secondary"><i class="fas fa-question-circle me-1"></i>Unchecked</span>';
                }
                if (!empty($bl['last_index_checked_at'])) {
                    echo '<br><small class="text-muted" style="font-size: 10px;">Checked: ' . formatLocalTime($bl['last_index_checked_at'], 'd M, H:i') . '</small>';
                }
                ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-secondary"
                          onclick="navigator.clipboard.writeText('<?= clean($bl['backlink_url']) ?>').then(()=>alert('URL Copied!'))" title="Copy URL">
                    <i class="fas fa-copy"></i>
                  </button>
                  <button class="btn btn-outline-primary"
                          onclick="verifySingleLink(<?= $bl['id'] ?>, this)" title="Verify Live Link Now">
                    <i class="fas fa-sync-alt"></i>
                  </button>
                  <button class="btn btn-outline-info"
                          onclick="checkLinkIndexing(<?= $bl['id'] ?>, this)" title="Check Google Indexing">
                    <i class="fas fa-search"></i>
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
  </div>

  <?php
  // Fetch pending queue tasks for this specific project, keyword, and target site
  $pendingTasks = $db->prepare("
      SELECT * FROM backlink_queue 
      WHERE project_id = ? 
        AND status IN ('pending', 'processing')
        AND (keyword = ? OR (keyword IS NULL AND ? = ''))
        AND (target_url = ? OR target_url IS NULL OR (target_url IS NULL AND ? = ''))
      ORDER BY created_at ASC
  ");
  $pendingTasks->execute([$selectedProjectId, $currentKeyword, $currentKeyword, $currentTargetSite, $currentTargetSite]);
  $pendingTasks = $pendingTasks->fetchAll(PDO::FETCH_ASSOC);
  ?>

  <div id="pendingTasksSection">
  <?php if (!empty($pendingTasks)): ?>
  <div class="card mb-4 border-warning shadow-sm">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
      <h5 class="mb-0">
        <i class="fas fa-clock me-2"></i>
        Pending Tasks in Queue (<?= count($pendingTasks) ?>)
      </h5>
      <span class="badge bg-dark text-white">System is processing these in background</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-dark">
            <tr>
              <th>ID</th>
              <th>Platform</th>
              <th>Keyword</th>
              <th>Target URL</th>
              <th>Date Queued</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pendingTasks as $task): ?>
            <tr>
              <td>#<?= $task['id'] ?></td>
              <td><span class="badge bg-secondary"><?= clean($task['platform']) ?></span></td>
              <td><?= clean($task['keyword']) ?></td>
              <td><a href="<?= clean($task['target_url']) ?>" target="_blank" class="text-decoration-none text-dark fw-bold"><?= clean(substr((string)$task['target_url'], 0, 50)) ?>...</a></td>
              <td><small><?= formatLocalTime($task['created_at'], 'd M H:i') ?></small></td>
              <td><span class="badge bg-warning text-dark"><i class="fas fa-spinner fa-spin me-1"></i>Pending</span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
  </div>
  
  <?php endif; ?>

  <!-- Platform Submission Console (1 - 10) -->
  <div id="primaryConsoleContainer">
    <?= renderPrimaryConsoleTableHtml($db, $selectedProjectId, $currentKeyword, $currentTargetSite, $platforms, $savedAccountsRows) ?>
  </div>

  <!-- Platform Submission Console (11 - 45) -->
  <div class="card mb-4 border-0 shadow-sm">
    <div class="card-header bg-dark text-white">
      <h5 class="mb-0"><i class="fas fa-list me-2"></i>Secondary Platforms Console (11 - 45)</h5>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th style="width: 5%;">#</th>
              <th style="width: 15%;">Platform</th>
              <th style="width: 35%;">What System Does Automatically</th>
              <th style="width: 20%;">Your Credentials</th>
              <th style="width: 12%;">Status</th>
              <th style="width: 13%;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($secondBoxList as $idx => $site): ?>
            <?php 
            $saved = isset($savedMap[$site['id']]);
            $allAccounts = $savedMapAll[$site['id']] ?? []; 
            ?>
            <tr>
              <td><?= $idx + 14 ?></td>
              <td>
                <strong><?= htmlspecialchars($site['name']) ?></strong><br>
                <a href="<?= htmlspecialchars($site['url']) ?>" target="_blank" class="small text-muted">
                  <?= htmlspecialchars(substr($site['url'], 0, 30)) ?><?= strlen($site['url']) > 30 ? '...' : '' ?> <i class="fas fa-external-link-alt"></i>
                </a>
              </td>
              <td>
                <small class="text-success">
                  <i class="fas fa-robot me-1"></i><?= htmlspecialchars($site['what_system_does']) ?>
                </small>
              </td>
              <td>
                <?php if (isset($site['autopost']) && $site['autopost'] === false && empty($allAccounts)): ?>
                  <span class="text-muted small">Coming Soon</span>
                <?php else: ?>
                  <?php if (!empty($allAccounts)): ?>
                    <?php foreach ($allAccounts as $acc): ?>
                    <div class="d-flex align-items-center gap-1 mb-1">
                      <span class="text-success small">
                        <i class="fas fa-check-circle me-1"></i><?php
                          if ($site['id'] === 'mastodon' && !empty($acc['api_secret'])) {
                              echo clean($acc['api_secret']);
                          } else {
                              echo clean($acc['username']);
                          }
                        ?>
                      </span>
                      <button class="btn btn-xs btn-outline-secondary py-0 px-1"
                              onclick='editCredForm(<?= json_encode($acc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($site['id']) ?>, <?= json_encode($site['name']) ?>, <?= (int)$selectedProjectId ?>)'
                              title="Edit Credentials / Token / Password">
                        <i class="fas fa-edit"></i>
                      </button>
                      <button class="btn btn-xs btn-outline-danger py-0 px-1"
                              onclick="deleteAccount(<?= $acc['id'] ?>, this)"
                              title="Remove">
                        <i class="fas fa-times"></i>
                      </button>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($site['id'] === 'wordpress'): ?>
                      <button onclick="showWpConnect()" class="btn btn-xs btn-primary mt-1">
                        <i class="fab fa-wordpress me-1"></i>Re-Connect WordPress
                      </button>
                    <?php else: ?>
                    <button class="btn btn-xs btn-outline-primary mt-1"
                            onclick="showCredForm('<?= $site['id'] ?>', '<?= $site['name'] ?>', <?= $selectedProjectId ?>)">
                      <i class="fas fa-plus me-1"></i>Add More
                    </button>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if ($site['id'] === 'wordpress'): ?>
                      <button onclick="showWpConnect()" class="btn btn-sm btn-primary">
                        <i class="fab fa-wordpress me-1"></i>Connect WordPress
                      </button>
                      <br><small class="text-muted">Email + Password → Auto-save</small>
                    <?php else: ?>
                    <button class="btn btn-sm btn-outline-primary"
                            onclick="showCredForm('<?= $site['id'] ?>', '<?= $site['name'] ?>', <?= $selectedProjectId ?>)">
                      <i class="fas fa-key me-1"></i>Add Credentials
                    </button>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <?php
                $cooldown = checkPlatformCooldown($db, $selectedProjectId, $site['id'], $currentKeyword, $currentTargetSite, count($allAccounts));
                
                // Fetch the latest background queue task for this platform
                $queueStmt = $db->prepare("SELECT status, error_message FROM backlink_queue WHERE project_id=? AND platform=? AND keyword=? AND target_url=? ORDER BY id DESC LIMIT 1");
                $queueStmt->execute([$selectedProjectId, $site['id'], $currentKeyword, $currentTargetSite]);
                $qItem = $queueStmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <?php if ($cooldown['is_cooldown']): ?>
                  <span class="badge bg-warning text-dark"><i class="fas fa-history me-1"></i>Posted (Cooldown)</span>
                <?php elseif (isset($site['autopost']) && $site['autopost'] === false): ?>
                  <span class="badge bg-secondary"><i class="fas fa-clock me-1"></i>Coming Soon</span>
                <?php elseif ($qItem && $qItem['status'] === 'pending'): ?>
                  <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>⏳ Queued</span>
                <?php elseif ($qItem && $qItem['status'] === 'processing'): ?>
                  <span class="badge bg-info"><i class="fas fa-spinner fa-spin me-1"></i>⚙️ Posting...</span>
                <?php elseif ($qItem && $qItem['status'] === 'failed'): ?>
                  <span class="badge bg-danger text-white" style="cursor:help;" title="<?= htmlspecialchars($qItem['error_message'] ?? 'Unknown Error') ?>"><i class="fas fa-exclamation-triangle me-1"></i>❌ Failed</span>
                <?php elseif ($saved): ?>
                  <span class="badge bg-success">Ready to Post</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Needs Credentials</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($cooldown['is_cooldown']): ?>
                  <span class="text-muted fw-bold"><i class="fas fa-clock me-1"></i>Wait <?= $cooldown['time_str'] ?></span>
                <?php elseif (!empty($allAccounts) && (!isset($site['autopost']) || $site['autopost'] !== false)): ?>
                  <button class="btn btn-sm btn-success"
                          onclick="autoPostAll('<?= $site['id'] ?>', '<?= $site['name'] ?>', <?= $selectedProjectId ?>, <?= count($allAccounts) ?>)">
                    <i class="fas fa-paper-plane me-1"></i>Auto Post
                    <?php if (count($allAccounts) > 1): ?>
                      <span class="badge bg-warning text-dark ms-1"><?= count($allAccounts) ?> accounts</span>
                    <?php endif; ?>
                  </button>
                <?php elseif (isset($site['autopost']) && $site['autopost'] === false): ?>
                  <span class="text-muted small">Coming Soon</span>
                <?php else: ?>
                  <span class="text-muted small">Add credentials first</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
  </div>
</div>

<!-- Credentials Modal -->
<div class="modal fade" id="credModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          <i class="fas fa-key me-2"></i><span id="modalActionType">Add</span> Credentials: <span id="modalPlatformName"></span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info small">
          <i class="fas fa-robot me-2"></i>
          <strong>System will automatically:</strong><br>
          <span id="modalWhatSystemDoes"></span>
        </div>
        <form method="POST" id="credForm">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="save_platform" value="1">
          <input type="hidden" name="account_id" id="modalAccountId" value="0">
          <input type="hidden" name="platform" id="modalPlatformId">
          <input type="hidden" name="project_id" id="modalProjectId">

          <div class="mb-3">
            <label class="form-label fw-bold" id="modalUsernameLabel">Username / Email</label>
            <input type="text" name="username" id="modalUsernameInput" class="form-control"
                   placeholder="Your username or email" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold" id="modalPasswordLabel">Password</label>
            <input type="password" name="password" id="modalPasswordInput" class="form-control"
                   placeholder="Your password">
            <div class="form-text text-muted" id="modalPasswordHelp" style="display:none;">Leave blank to keep existing password / token.</div>
          </div>

          <!-- API Key section - shown for platforms that need it -->
          <div id="apiKeySection" class="mb-3">
            <label class="form-label fw-bold">
              API Key / Integration Token
              <span class="badge bg-danger ms-1">Required for Auto Post</span>
            </label>
            <input type="text" name="api_key" id="modalApiKey" class="form-control"
                   placeholder="Paste API key / token here">
            <div class="form-text" id="apiKeyHelp"></div>
          </div>

          <!-- No API notice - shown for email+password platforms -->
          <div id="noApiNotice" class="alert alert-success small" style="display:none;">
            <i class="fas fa-robot me-2"></i>
            <strong>Browser Automation Active</strong> — No API key needed!<br>
            <span id="noApiNoticeText">System will use Email + Password to auto-login and post.</span>
          </div>

          <div class="mb-3" id="apiSecretSection" style="display:none;">
            <label class="form-label fw-bold" id="modalApiSecretLabel">API Secret / Blog Name</label>
            <input type="text" name="api_secret" id="modalApiSecretInput" class="form-control"
                   placeholder="API secret or blog name (e.g. yourblog.tumblr.com)">
          </div>

          <div class="mb-3" id="tumblrSecretSection" style="display:none;">
            <label class="form-label fw-bold">OAuth Token Secret (Access Token Secret)</label>
            <input type="password" name="tumblr_token_secret" id="modalTumblrTokenSecret" class="form-control"
                   placeholder="Enter Access Token Secret">
          </div>

          <button type="submit" id="modalSubmitBtn" class="btn btn-primary w-100 btn-lg">
            <i class="fas fa-save me-2"></i><span id="modalSubmitBtnText">Save Credentials</span>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Auto Post Progress Modal -->
<div class="modal fade" id="postModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-rocket me-2"></i>Auto Posting...</h5>
      </div>
      <div class="modal-body text-center py-4">
        <div class="spinner-border text-success mb-3" style="width:3rem;height:3rem;"></div>
        <h5 id="postingStatus">System is posting...</h5>
        <p class="text-muted" id="postingDetail">Please wait...</p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- WordPress Connect Modal -->
<div class="modal fade" id="wpConnectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fab fa-wordpress me-2"></i>Connect WordPress.com</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="wpConnectForm">
          <p class="text-muted small mb-3">Enter Email + Password — token will be auto-fetched and saved in the system</p>
          <div class="mb-3">
            <label class="form-label fw-bold">WordPress.com Email</label>
            <input type="email" id="wpEmail" class="form-control" value="kanzariyapratik124@gmail.com" placeholder="email@gmail.com">
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Password</label>
            <input type="password" id="wpPassword" class="form-control" placeholder="WordPress.com password">
          </div>
          <div id="wpConnectMsg" class="mb-2"></div>
        </div>
        <div id="wpConnectSuccess" class="d-none text-center py-3">
          <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
          <h5 class="text-success">Connected Successfully!</h5>
          <p id="wpSiteLabel" class="text-muted"></p>
          <a id="wpPostLink" href="#" target="_blank" class="btn btn-success mt-2">
            <i class="fas fa-external-link-alt me-1"></i>View Test Post
          </a>
        </div>
      </div>
      <div class="modal-footer" id="wpConnectFooter">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="wpConnectBtn" onclick="doWpConnect()">
          <i class="fab fa-wordpress me-1"></i>Connect & Auto-Post
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const PROJECT_ID = <?= $selectedProjectId ?>;

function getTumblrHelpText() {
  let host = window.location.host;
  if (/^[0-9.]+(:[0-9]+)?$/.test(host)) {
      const parts = host.split(':');
      const ip = parts[0];
      const port = parts[1] ? ':' + parts[1] : '';
      host = ip + '.nip.io' + port;
  }
  const helperUrl = window.location.protocol + '//' + host + '/scratch/tumblr_oauth_helper.php';
  return '<strong>How to get Tumblr keys:</strong><br>' +
    '1. Register App at <a href="https://www.tumblr.com/oauth/apps" target="_blank" class="fw-bold text-decoration-underline">tumblr.com/oauth/apps</a>.<br>' +
    '2. Set <strong>Default Callback URL</strong> to:<br>' +
    '<code class="bg-light p-1 border rounded d-inline-block my-1" style="font-size:10px; word-break: break-all; color: #d63384;">' + helperUrl + '</code><br>' +
    '3. Save & copy <strong>OAuth Consumer Key</strong> and <strong>Secret Key</strong>.<br>' +
    '4. <a href="' + helperUrl + '" target="_blank" class="btn btn-xs btn-success text-white py-0 px-2 my-1 fw-bold" style="font-size:11px;">Click here to Authorize & Generate Tokens</a><br>' +
    '5. Paste the generated tokens in the fields above.';
}

const siteInfo = {
<?php foreach ($platforms as $cat): foreach ($cat['sites'] as $site): ?>
  '<?= $site['id'] ?>': { name: '<?= $site['name'] ?>', does: '<?= addslashes($site['what_system_does']) ?>' },
<?php endforeach; endforeach; ?>
};

function showWpConnect() {
  document.getElementById('wpConnectForm').classList.remove('d-none');
  document.getElementById('wpConnectSuccess').classList.add('d-none');
  document.getElementById('wpConnectFooter').classList.remove('d-none');
  document.getElementById('wpConnectMsg').innerHTML = '';
  new bootstrap.Modal(document.getElementById('wpConnectModal')).show();
}

function doWpConnect() {
  const email    = document.getElementById('wpEmail').value.trim();
  const password = document.getElementById('wpPassword').value;
  const btn      = document.getElementById('wpConnectBtn');
  const msg      = document.getElementById('wpConnectMsg');

  if (!email || !password) {
    msg.innerHTML = '<div class="alert alert-warning py-2">Enter Email and Password</div>';
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Connecting...';
  msg.innerHTML = '';

  const fd = new FormData();
  fd.append('wp_ajax_connect', '1');
  fd.append('wp_email', email);
  fd.append('wp_password', password);
  fd.append('project_id', PROJECT_ID);
  fd.append('csrf_token', '<?= csrfToken() ?>');

  fetch('submission-manager.php', {
    method: 'POST',
    credentials: 'same-origin',
    body: fd
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      document.getElementById('wpConnectForm').classList.add('d-none');
      document.getElementById('wpConnectFooter').classList.add('d-none');
      document.getElementById('wpSiteLabel').textContent = 'Site: ' + data.site;
      if (data.post_url) {
        document.getElementById('wpPostLink').href = data.post_url;
        document.getElementById('wpPostLink').classList.remove('d-none');
      }
      document.getElementById('wpConnectSuccess').classList.remove('d-none');
      setTimeout(() => location.reload(), 3000);
    } else {
      msg.innerHTML = '<div class="alert alert-danger py-2">❌ ' + (data.error || 'Failed') + '</div>';
      btn.disabled = false;
      btn.innerHTML = '<i class="fab fa-wordpress me-1"></i>Connect & Auto-Post';
    }
  })
  .catch(err => {
    msg.innerHTML = '<div class="alert alert-danger py-2">Error: ' + err.message + '</div>';
    btn.disabled = false;
    btn.innerHTML = '<i class="fab fa-wordpress me-1"></i>Connect & Auto-Post';
  });
}

function showCredForm(platformId, platformName, projectId) {
  document.getElementById('modalPlatformId').value = platformId;
  document.getElementById('modalProjectId').value = projectId;
  document.getElementById('modalPlatformName').textContent = platformName;
  document.getElementById('modalWhatSystemDoes').textContent = siteInfo[platformId]?.does || '';

  // Reset modal state
  document.getElementById('modalAccountId').value = '0';
  document.getElementById('modalActionType').textContent = 'Add';
  document.getElementById('modalSubmitBtnText').textContent = 'Save Credentials';
  const pwdHelp = document.getElementById('modalPasswordHelp');
  if (pwdHelp) pwdHelp.style.display = 'none';

  // Dynamically change password label & placeholder for Bluesky / Tumblr
  const passwordLabel = document.getElementById('modalPasswordLabel');
  const passwordInput = document.getElementById('modalPasswordInput');
  const usernameLabel = document.getElementById('modalUsernameLabel') || document.querySelector('label[for="modalUsername"], .modal-body label:first-of-type');
  const usernameInput = document.getElementById('modalUsernameInput') || document.querySelector('input[name="username"]');
  const tumblrSecretSection = document.getElementById('tumblrSecretSection');
  const apiSecretLabel = document.getElementById('modalApiSecretLabel');
  const apiSecretInput = document.getElementById('modalApiSecretInput');
  const apiKeyLabel = document.querySelector('#apiKeySection label');
  const apiKeyInput = document.querySelector('input[name="api_key"]');
  const tumblrSecretInput = document.getElementById('modalTumblrTokenSecret');

  // Reset values
  if (usernameInput) usernameInput.value = '';
  if (passwordInput) passwordInput.value = '';
  if (apiKeyInput) apiKeyInput.value = '';
  if (apiSecretInput) apiSecretInput.value = '';
  if (tumblrSecretInput) tumblrSecretInput.value = '';

  // Reset defaults
  if (tumblrSecretSection) tumblrSecretSection.style.display = 'none';
  if (apiSecretLabel) apiSecretLabel.textContent = 'API Secret / Blog Name';
  if (apiSecretInput) {
    apiSecretInput.type = 'text';
    apiSecretInput.placeholder = 'API secret or blog name (e.g. yourblog.tumblr.com)';
  }
  if (apiKeyLabel) {
    apiKeyLabel.innerHTML = 'API Key / Integration Token <span class="badge bg-danger ms-1">Required for Auto Post</span>';
  }
  if (apiKeyInput) {
    apiKeyInput.placeholder = 'Enter API Key';
  }

  if (platformId === 'tumblr') {
    passwordLabel.textContent = 'OAuth Token (Access Token)';
    passwordInput.placeholder = 'Enter OAuth Token';
    if (usernameLabel) usernameLabel.textContent = 'Blog Hostname (e.g., skyrankseo.tumblr.com)';
    if (usernameInput) usernameInput.placeholder = 'skyrankseo.tumblr.com';
    if (apiKeyLabel) {
      apiKeyLabel.innerHTML = 'OAuth Consumer Key (API Key) <span class="badge bg-danger ms-1">Required for Auto Post</span>';
    }
    if (apiSecretLabel) apiSecretLabel.textContent = 'OAuth Consumer Secret (Secret Key)';
    if (apiSecretInput) {
      apiSecretInput.type = 'password';
      apiSecretInput.placeholder = 'Enter OAuth Consumer Secret';
    }
    if (tumblrSecretSection) tumblrSecretSection.style.display = '';
  } else if (platformId === 'bluesky') {
    passwordLabel.textContent = 'App Password';
    passwordInput.placeholder = 'Your Bluesky App Password (e.g. xxxx-xxxx-xxxx-xxxx)';
    if (usernameInput) usernameInput.placeholder = 'Your Bluesky handle (e.g. user.bsky.social)';
    if (usernameLabel) usernameLabel.textContent = 'Username / Email';
  } else if (platformId === 'mastodon') {
    passwordLabel.textContent = 'Password';
    passwordInput.placeholder = 'Your Mastodon password';
    if (usernameInput) {
      usernameInput.placeholder = 'Your Mastodon email (e.g. you@gmail.com)';
      usernameInput.type = 'email';
    }
    if (usernameLabel) usernameLabel.textContent = 'Username / Email';
  } else {
    passwordLabel.textContent = 'Password';
    passwordInput.placeholder = 'Your password';
    if (usernameInput) {
      usernameInput.placeholder = 'Your username or email';
      usernameInput.type = 'text';
    }
    if (usernameLabel) usernameLabel.textContent = 'Username / Email';
  }

  if (platformId === 'symbaloo') {
    if (apiKeyLabel) {
      apiKeyLabel.innerHTML = 'Custom Webmix URL (Optional) <span class="badge bg-secondary ms-1">Optional</span>';
    }
    if (apiKeyInput) {
      apiKeyInput.placeholder = 'e.g., https://www.symbaloo.com/home/mix/13eOcXQ5OS';
    }
  }

  // Show API key help based on platform
  const apiKeyHelp = {
    'pinterest':  '🤖 Browser Automation — No API key needed! Enter Pinterest Email + Password. System will open Chrome, auto-login, and create a pin with image + keyword + backlink automatically.',
    'mastodon':   '🤖 Browser Automation — No API key needed! Enter Mastodon Email + Password. System will auto-login, create an app, generate access token, and post automatically!',
    'wordpress': 'Get token: <a href="https://developer.wordpress.com/apps/" target="_blank">developer.wordpress.com/apps</a> → Create New App → Access Token',
    'tumblr':    getTumblrHelpText(),
    'blogger':   'Get token: <a href="https://developers.google.com/oauthplayground" target="_blank">OAuth Playground</a> → Blogger scope → Access Token. Blog ID in API Secret.',
    'github':    'Get token: <a href="https://github.com/settings/tokens" target="_blank">github.com/settings/tokens</a> → Generate token → repo scope',
    'devto':     'Get key: <a href="https://dev.to/settings/extensions" target="_blank">dev.to/settings/extensions</a> → DEV Community API Keys → Generate',
    'hashnode':  'Get key: <a href="https://hashnode.com/settings/developer" target="_blank">hashnode.com/settings/developer</a> → Personal Access Token. Publication ID in API Secret.',
    'ghost':     'Ghost Admin → Settings → Integrations → Add Custom Integration → Admin API Key. Ghost site URL in Username.',
    'bluesky':   'No API key needed! Enter your Bluesky username + App Password (not your regular login password). Get App Password: <a href="https://bsky.app/settings/app-passwords" target="_blank">bsky.app → Settings → App Passwords</a>',
    'minds':     'Get token: <a href="https://www.minds.com/settings/security" target="_blank">minds.com → Settings → Security → API Token</a>',
    'plurk':     'Get key: <a href="https://www.plurk.com/API/Apps" target="_blank">plurk.com/API/Apps</a> → Create App → API Key',
    'mediafire': '✅ No API key needed! Enter your MediaFire Email + Password. System will log in, generate a PDF with keyword description + backlink, and upload it automatically.',
    'fourshared': '✅ No API key needed! Enter your 4Shared Email + Password. System will log in, generate a PDF with keyword description + backlink, and upload it automatically.',
  };

  // Platforms that need NO API key — ChatGPT content, copy-paste manually
  const noApiNeeded = ['pinterest','mastodon','minds','symbaloo','penzu','linktree',
    'scoopit','wakelet','vivauae','padlet','pearltrees','mewe','instapaper',
    'gifyu','postimage','photobucket','behance','pbase','dropbox','imgbb',
    'site123','posteezy','livejournal','justpaste','substack','dribbble',
    'limewire','workupload','powershow','uploadee','pdfhost',
    'mediafire','fourshared'];

  const helpText = apiKeyHelp[platformId] || (noApiNeeded.includes(platformId)
    ? '✅ No API key needed! Save Email + Password. System auto-login kare + post kare.'
    : 'API key required for auto posting');
  document.getElementById('apiKeyHelp').innerHTML = helpText;

  // Hide API key section for no-API platforms + Bluesky
  const apiSection = document.getElementById('apiKeySection');
  const apiSecretSection = document.getElementById('apiSecretSection');
  const noApiNotice = document.getElementById('noApiNotice');
  if ((noApiNeeded.includes(platformId) || platformId === 'bluesky') && platformId !== 'symbaloo') {
    apiSection.style.display = 'none';
    if(apiSecretSection) apiSecretSection.style.display = 'none';
    if(noApiNotice) {
      noApiNotice.style.display = '';
      // Platform-specific notice text
      const noticeText = document.getElementById('noApiNoticeText');
      if(noticeText) {
        if(platformId === 'pinterest') {
          noticeText.innerHTML = '🤖 <strong>Selenium Browser Automation</strong> — System will open Chrome headless, login to Pinterest with your credentials, upload image + create pin automatically!';
        } else if(platformId === 'mastodon') {
          noticeText.innerHTML = '🤖 <strong>Selenium Browser Automation</strong> — System will login to Mastodon, create developer app, generate access token automatically, and post! Token will be saved for next time.';
        } else if(['mediafire','fourshared'].includes(platformId)) {
          noticeText.innerHTML = '✅ System logs in with Email + Password → generates PDF → uploads automatically.';
        } else {
          noticeText.innerHTML = 'System will use Email + Password to auto-login and post.';
        }
      }
    }
  } else {
    apiSection.style.display = '';
    if(noApiNotice) {
      if (platformId === 'symbaloo') {
        noApiNotice.style.display = '';
        const noticeText = document.getElementById('noApiNoticeText');
        if(noticeText) {
          noticeText.innerHTML = '🤖 <strong>Selenium Browser Automation</strong> — System will use Email + Password to auto-login and add tile to your webmix.';
        }
      } else {
        noApiNotice.style.display = 'none';
      }
    }
    if(apiSecretSection) apiSecretSection.style.display = ['tumblr','wordpress','blogger','hashnode'].includes(platformId) ? '' : 'none';
  }

  new bootstrap.Modal(document.getElementById('credModal')).show();
}

function editCredForm(acc, platformId, platformName, projectId) {
  showCredForm(platformId, platformName, projectId);

  document.getElementById('modalAccountId').value = acc.id || 0;
  document.getElementById('modalActionType').textContent = 'Edit';
  document.getElementById('modalSubmitBtnText').textContent = 'Update Credentials';
  const pwdHelp = document.getElementById('modalPasswordHelp');
  if (pwdHelp) pwdHelp.style.display = '';

  const usernameInput = document.getElementById('modalUsernameInput') || document.querySelector('input[name="username"]');
  if (usernameInput) {
    if (platformId === 'mastodon' && acc.api_secret && acc.api_secret.includes('@')) {
      usernameInput.value = acc.api_secret;
    } else {
      usernameInput.value = acc.username || '';
    }
  }

  const passwordInput = document.getElementById('modalPasswordInput');
  if (passwordInput) {
    passwordInput.value = '';
    passwordInput.placeholder = 'Enter new password / token (leave blank to keep current)';
  }

  const apiKeyInput = document.getElementById('modalApiKey');
  if (apiKeyInput) {
    apiKeyInput.value = acc.api_key || '';
  }

  const apiSecretInput = document.getElementById('modalApiSecretInput');
  if (apiSecretInput) {
    if (platformId === 'mastodon' && acc.api_secret && acc.api_secret.includes('@')) {
      apiSecretInput.value = '';
    } else {
      apiSecretInput.value = acc.api_secret || '';
    }
  }
}

function updateAutoPostSelection() {
  const kwSelect = document.getElementById('backlinkKeywordSelect');
  const siteSelect = document.getElementById('backlinkUrlSelect');
  const kw = kwSelect ? kwSelect.value : '';
  const site = siteSelect ? siteSelect.value : '';
  
  if (kw) {
    localStorage.setItem('seo_selected_kw_' + PROJECT_ID, kw);
  }
  if (site) {
    localStorage.setItem('seo_selected_url_' + PROJECT_ID, site);
  }

  // Silently update address bar URL without triggering a page refresh
  const newUrl = 'submission-manager.php?project_id=' + PROJECT_ID + '&keyword=' + encodeURIComponent(kw) + '&target_site=' + encodeURIComponent(site);
  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, '', newUrl);
  }

  // Instantly refresh Created Backlinks & Pending Queue for newly selected keyword and URL
  refreshBacklinkTables();
}

function autoPost(platformId, platformName, projectId) {
  autoPostAll(platformId, platformName, projectId);
}

function autoPostAll(platformId, platformName, projectId) {
  const modal = new bootstrap.Modal(document.getElementById('postModal'));
  document.getElementById('postingStatus').textContent = 'Posting to ' + platformName + '...';
  document.getElementById('postingDetail').textContent = 'Posting to all accounts simultaneously...';
  modal.show();

  const kwSelect = document.getElementById('backlinkKeywordSelect');
  const kw = kwSelect ? encodeURIComponent(kwSelect.value) : '';
  const siteSelect = document.getElementById('backlinkUrlSelect');
  const siteUrl = siteSelect ? encodeURIComponent(siteSelect.value) : '';
  fetch('auto-post-all.php?id=' + projectId + '&platform=' + platformId + '&keyword=' + kw + '&target_site=' + siteUrl, {
    signal: AbortSignal.timeout(300000),
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
  })
    .then(r => r.text())
    .then(text => {
      try { return JSON.parse(text); }
      catch (err) {
        const preview = text.replace(/<[^>]*>/g, ' ').trim().slice(0, 300);
        throw new Error('Server error: ' + preview);
      }
    })
    .then(data => {
      if (data.queued) {
        document.getElementById('postingStatus').innerHTML = `✅ Tasks Queued! <span class="badge bg-success">${data.queued} tasks added</span>`;
        document.getElementById('postingDetail').innerHTML =
          `<strong>${data.queued} tasks</strong> added to background queue. Processing in background...`;
        refreshBacklinkTables();
        setTimeout(() => { modal.hide(); }, 2500);
      } else if (data.results && data.results.length > 0) {
        document.getElementById('postingStatus').innerHTML = '⚠️ Tasks Already Queued for this Keyword';
        let table = '<div style="max-height:200px;overflow-y:auto"><table class="table table-sm table-bordered mt-2 mb-0"><thead><tr><th>Platform</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
        (data.results || []).forEach(r => {
          const badge = r.status === 'duplicate' ? 'bg-warning text-dark' : 'bg-success';
          const detail = r.message || 'Already queued/processing';
          table += `<tr><td class="small">${r.name || r.platform}</td><td><span class="badge ${badge}">${r.status}</span></td><td class="small">${detail}</td></tr>`;
        });
        table += '</tbody></table></div>';
        document.getElementById('postingDetail').innerHTML = table;
        refreshBacklinkTables();
        setTimeout(() => { modal.hide(); }, 3500);
      } else {
        document.getElementById('postingStatus').innerHTML = '⚠️ ' + (data.error || 'No active accounts found');
        document.getElementById('postingDetail').innerHTML = 'Please check account credentials and try again.';
      }
    })
    .catch((err) => {
      document.getElementById('postingStatus').textContent = '⚠️ ' + (err.message || 'Error');
      document.getElementById('postingDetail').textContent = 'Please try again.';
    });
}

function deleteAccount(accountId, btn) {
  if (!confirm('Remove this account?')) return;
  fetch('submission-manager.php?delete_account=' + accountId + '&csrf=<?= csrfToken() ?>', {
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      btn.closest('.d-flex').remove();
    } else {
      alert('Error: ' + (d.error || 'Could not delete'));
    }
  });
}

function bulkBlueskyPost(projectId) {
  const modal = new bootstrap.Modal(document.getElementById('postModal'));
  document.getElementById('postingStatus').textContent = '🚀 Bulk posting to ALL Bluesky accounts...';
  document.getElementById('postingDetail').innerHTML =
    '<div class="spinner-border spinner-border-sm me-2"></div>Posting in parallel batches of 10... Please wait (may take 2-3 minutes for 50 accounts)';
  modal.show();

  const kwSelect = document.getElementById('backlinkKeywordSelect');
  const kw = kwSelect ? encodeURIComponent(kwSelect.value) : '';
  const siteSelect = document.getElementById('backlinkUrlSelect');
  const siteUrl = siteSelect ? encodeURIComponent(siteSelect.value) : '';
  fetch('auto-post-all.php?id=' + projectId + '&platform=bluesky&keyword=' + kw + '&target_site=' + siteUrl, {
    signal: AbortSignal.timeout(600000), // 10 minute timeout for 50 accounts
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
  })
  .then(r => r.text())
  .then(text => {
    try { return JSON.parse(text); }
    catch(e) { throw new Error('Server error: ' + text.replace(/<[^>]*>/g,' ').trim().slice(0,300)); }
  })
  .then(data => {
    const queued = data.queued || 0;
    document.getElementById('postingStatus').innerHTML =
      `✅ Tasks Queued! <span class="badge bg-warning text-dark">${queued} tasks added</span>`;

    // Build results table
    let table = '<div style="max-height:200px;overflow-y:auto"><table class="table table-sm table-bordered mt-2 mb-0"><thead><tr><th>Account</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
    (data.results || []).forEach(r => {
      const badge = 'bg-warning text-dark';
      const detail = r.message || 'Queued in background';
      table += `<tr><td class="small">${r.handle||r.platform}</td><td><span class="badge ${badge}">pending</span></td><td class="small">${detail}</td></tr>`;
    });
    table += '</tbody></table></div>';
    document.getElementById('postingDetail').innerHTML = table;
    setTimeout(() => { modal.hide(); }, 3000);
  })
  .catch(err => {
    document.getElementById('postingStatus').textContent = '⚠️ Error: ' + (err.message || 'Connection failed');
    document.getElementById('postingDetail').textContent = 'Bulk posting failed. Check server logs.';
  });
}


function showManualContent(platformName, content, title, siteUrl, pdfUrl = null, source = 'ChatGPT') {
  // Create a modal to show generated content for copy-paste
  const existing = document.getElementById('manualModal');
  if (existing) existing.remove();

  const pdfBtn = pdfUrl
    ? `<a href="${pdfUrl}" download class="btn btn-danger btn-lg w-100 mb-2">
         <i class="fas fa-file-pdf me-2"></i>Download Generated PDF
       </a>`
    : '';

  let warningBanner = '';
  if (source === 'Template') {
    warningBanner = `
    <div class="alert alert-warning py-2 mb-3">
      <strong><i class="fas fa-exclamation-triangle me-2"></i>API Key Warning:</strong> 
      Your OpenAI/Gemini API key has exceeded its quota or expired. This content was generated using a randomized local spintax template. Update your API Keys on the <a href="api-setup.php" class="alert-link">API Setup</a> page to resume direct live ChatGPT generation.
    </div>`;
  }

  const html = `
  <div class="modal fade" id="manualModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title">
            <i class="fas fa-robot me-2"></i>ChatGPT Content Ready — Post on ${platformName}
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          ${warningBanner}
          ${pdfUrl ? `
          <div class="alert alert-success">
            <strong><i class="fas fa-file-pdf me-2"></i>PDF Generated!</strong>
            Steps: 1. Download PDF below → 2. <a href="${siteUrl}" target="_blank">Open ${platformName}</a> →
            3. Upload the PDF → 4. Copy share link → 5. Click <strong>Mark as Created</strong>
          </div>
          ${pdfBtn}
          ` : `
          <div class="alert alert-info">
            <strong>Steps:</strong>
            1. Copy the title and content below →
            2. <a href="${siteUrl}" target="_blank">Open ${platformName}</a> →
            3. Create new post → Paste → Publish
          </div>
          `}
          ${!pdfUrl ? `
          <div class="mb-3">
            <label class="fw-bold">Title:</label>
            <div class="input-group">
              <input type="text" class="form-control" id="manualTitle" value="${(title||'').replace(/"/g,'&quot;')}" readonly>
              <button class="btn btn-outline-secondary" onclick="copyField('manualTitle')">
                <i class="fas fa-copy"></i> Copy
              </button>
            </div>
          </div>
          <div class="mb-3">
            <label class="fw-bold">Content (HTML):</label>
            <div class="d-flex justify-content-end mb-1">
              <button class="btn btn-sm btn-primary" onclick="copyField('manualContent')">
                <i class="fas fa-copy me-1"></i>Copy All Content
              </button>
            </div>
            <textarea class="form-control" id="manualContent" rows="12" readonly>${content||''}</textarea>
          </div>
          ` : `<div class="mt-2 p-3 bg-light rounded small">${content||''}</div>`}
          <a href="${siteUrl}" target="_blank" class="btn btn-success btn-lg w-100 mt-2">
            <i class="fas fa-external-link-alt me-2"></i>Open ${platformName}
          </a>
        </div>
      </div>
    </div>
  </div>`;

  document.body.insertAdjacentHTML('beforeend', html);
  new bootstrap.Modal(document.getElementById('manualModal')).show();
}

function copyField(id) {
  const el = document.getElementById(id);
  el.select();
  navigator.clipboard.writeText(el.value).then(() => {
    showToast('Copied to clipboard!', 'success');
  });
}

function addLogoToImage(projectId, btn) {
  if (!btn) btn = event.target;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Adding...';

  fetch('add-logo.php?project_id=' + projectId, {credentials: 'same-origin'})
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        // Update preview image
        const img = document.getElementById('previewImg');
        if (img) img.src = data.image;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Logo Added!';
        btn.classList.replace('btn-warning', 'btn-success');
        setTimeout(() => {
          btn.innerHTML = '<i class="fas fa-trademark me-1"></i>Add Logo Again';
          btn.classList.replace('btn-success', 'btn-warning');
          btn.disabled = false;
        }, 3000);
      } else {
        btn.innerHTML = '<i class="fas fa-trademark me-1"></i>Add Logo to Image';
        btn.disabled = false;
        alert('Error: ' + (data.error || 'Failed'));
      }
    })
    .catch(() => {
      btn.innerHTML = '<i class="fas fa-trademark me-1"></i>Add Logo to Image';
      btn.disabled = false;
      alert('Connection error. Try again.');
    });
}

function generateImage(projectId, btn) {
  if (!btn) btn = event.target;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating image...';

  // Get currently selected keyword and target URL from drop-downs
  const kw = document.getElementById('backlinkKeywordSelect').value;
  const site = document.getElementById('backlinkUrlSelect').value;

  fetch('image-generator.php?generate=1&project_id=' + projectId + '&keyword=' + encodeURIComponent(kw) + '&target_site=' + encodeURIComponent(site), {credentials: 'same-origin'})
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        // Show generated image
        const imgDiv = btn.closest('.col-md-9').previousElementSibling;
        imgDiv.innerHTML = '<img src="' + data.image + '?t=' + Date.now() + '" class="img-thumbnail" style="max-height:120px;" alt="Generated"><p class="small text-success mt-1"><i class="fas fa-check-circle"></i> Image ready</p>';
        btn.innerHTML = '<i class="fas fa-sync me-2"></i>Regenerate Image';
        btn.disabled = false;
        alert('✅ Marketing poster ready (AWS-style)! Now click Auto Post.');
      } else {
        btn.innerHTML = '<i class="fas fa-magic me-2"></i>Auto Generate Image';
        btn.disabled = false;
        alert('Error: ' + (data.error || 'Failed'));
      }
    })
    .catch(() => {
      btn.innerHTML = '<i class="fas fa-magic me-2"></i>Auto Generate Image';
      btn.disabled = false;
      alert('Connection error. Try again.');
    });
}

function runAllSubmissions() {
  const readySites = document.querySelectorAll('.btn-success[onclick*="autoPost"]');
  if (readySites.length === 0) {
    alert('No sites ready. Please add credentials first.');
    return;
  }
  if (!confirm('System will auto-post to ' + readySites.length + ' sites. Continue?')) return;

  let i = 0;
  function postNext() {
    if (i >= readySites.length) {
      alert('All submissions complete!');
      location.reload();
      return;
    }
    readySites[i].click();
    i++;
    setTimeout(postNext, 4000);
  }
  postNext();
}

function verifySingleLink(blId, btn) {
  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  
  const row = document.getElementById('bl-row-' + blId);
  const statusCell = row ? row.querySelector('.bl-status-cell') : null;
  
  fetch('submission-manager.php?verify_backlink_ajax=' + blId, {credentials: 'same-origin'})
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = originalHtml;
      
      if (data.success) {
        if (statusCell) {
          let badge = '';
          if (data.status === 'active') {
            badge = '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active (Dofollow)</span>';
          } else if (data.status === 'nofollow') {
            badge = '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Nofollow</span>';
          } else if (data.status === 'broken') {
            badge = '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Broken / Offline</span>';
          } else {
            badge = '<span class="badge bg-secondary"><i class="fas fa-question-circle me-1"></i>Unverified</span>';
          }
          statusCell.innerHTML = badge + '<br><small class="text-muted" style="font-size: 10px;">Checked: ' + data.last_checked + '</small>';
        }
      } else {
        alert('Verification failed: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = originalHtml;
      alert('Network error verifying link: ' + err.message);
    });
}

function checkLinkIndexing(blId, btn) {
  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  
  const row = document.getElementById('bl-row-' + blId);
  const indexCell = row ? row.querySelector('.indexing-status-cell') : null;
  
  const formData = new FormData();
  formData.append('action', 'check_indexing');
  formData.append('backlink_id', blId);
  
  fetch('submission-manager.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = originalHtml;
      
      if (data.success) {
        if (indexCell) {
          let badge = '';
          if (data.status === 'indexed') {
            badge = '<span class="badge bg-success"><i class="fas fa-search me-1"></i>Indexed</span>';
          } else if (data.status === 'not_indexed') {
            badge = '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Not Indexed</span>';
          } else {
            badge = '<span class="badge bg-secondary"><i class="fas fa-question-circle me-1"></i>Unchecked</span>';
          }
          const checkDate = new Date().toLocaleDateString('en-GB', {day: 'numeric', month: 'short'}) + ', ' + new Date().toLocaleTimeString('en-GB', {hour: '2-digit', minute:'2-digit'});
          indexCell.innerHTML = badge + '<br><small class="text-muted" style="font-size: 10px;">Checked: ' + checkDate + '</small>';
        }
      } else {
        alert('Indexing check failed: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = originalHtml;
      alert('Network error checking indexing: ' + err.message);
    });
}

// Manager Arrays
let managerKeywords = <?= json_encode(array_filter(array_map('trim', explode(',', $project['target_keyword'] ?? '')))) ?>;
let managerSites = <?= json_encode(array_filter(array_map('trim', explode(',', $project['target_site'] ?? '')))) ?>;

document.addEventListener("DOMContentLoaded", () => {
  renderManagerKeywords();
  renderManagerSites();

  // Bind Enter key inside target list manager inputs
  document.getElementById('managerKeywordInput')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      managerAddKeyword();
    }
  });
  // Intercept credentials modal form submit via AJAX without reloading page
  const credForm = document.getElementById('credForm');
  if (credForm) {
    credForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const submitBtn = credForm.querySelector('button[type="submit"]');
      const originalText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...'; }

      const fd = new FormData(credForm);
      fetch('submission-manager.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(r => r.json())
      .then(data => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
        if (data.success) {
          const credModalEl = document.getElementById('credModal');
          if (credModalEl) {
            const modalInstance = bootstrap.Modal.getInstance(credModalEl);
            if (modalInstance) modalInstance.hide();
          }
          // Dynamically update status text/badge on the page for this platform
          const platId = data.platform;
          const statusBadges = document.querySelectorAll('.platform-status-' + platId);
          statusBadges.forEach(el => {
            el.className = 'badge bg-success';
            el.innerHTML = '<i class="fas fa-check-circle me-1"></i>Active';
          });
          refreshBacklinkTables();
        } else {
          alert('Error saving credentials: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
        alert('Network error: ' + err.message);
      });
    });
  }

  // Load saved platform selections, keyword, and URL for this project
  restoreSavedKeywordAndUrl();
  loadSavedPlatformsSelection();
  refreshBacklinkTables();
  setInterval(refreshBacklinkTables, 8000);
});

function refreshBacklinkTables() {
  const kwSelect = document.getElementById('backlinkKeywordSelect');
  const kw = kwSelect ? encodeURIComponent(kwSelect.value) : '';
  const siteSelect = document.getElementById('backlinkUrlSelect');
  const siteUrl = siteSelect ? encodeURIComponent(siteSelect.value) : '';

  const gridSec = document.getElementById('quickStatusGridContainer');
  if (gridSec) gridSec.style.opacity = '0.6';

  fetch('submission-manager.php?action=fetch_backlinks_html&project_id=' + PROJECT_ID + '&keyword=' + kw + '&target_site=' + siteUrl, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (gridSec) gridSec.style.opacity = '1';
    if (data.success) {
      const blSec = document.getElementById('createdBacklinksSection');
      if (blSec && data.createdHtml !== undefined) {
        blSec.innerHTML = data.createdHtml;
      }
      const pendingSec = document.getElementById('pendingTasksSection');
      if (pendingSec && data.pendingHtml !== undefined) {
        pendingSec.innerHTML = data.pendingHtml;
      }
      if (gridSec && data.quickStatusHtml !== undefined) {
        gridSec.innerHTML = data.quickStatusHtml;
      }
      const primConsoleSec = document.getElementById('primaryConsoleContainer');
      if (primConsoleSec && data.primaryConsoleHtml !== undefined) {
        primConsoleSec.innerHTML = data.primaryConsoleHtml;
      }
      loadSavedPlatformsSelection();
    }
  })
  .catch(e => {
    if (gridSec) gridSec.style.opacity = '1';
    console.error('Error refreshing backlinks:', e);
  });
}

function restoreSavedKeywordAndUrl() {
  const savedKw = localStorage.getItem('seo_selected_kw_' + PROJECT_ID);
  const savedUrl = localStorage.getItem('seo_selected_url_' + PROJECT_ID);
  const kwSelect = document.getElementById('backlinkKeywordSelect');
  const siteSelect = document.getElementById('backlinkUrlSelect');

  if (savedKw && kwSelect) {
    const opt = Array.from(kwSelect.options).find(o => o.value === savedKw);
    if (opt) kwSelect.value = savedKw;
  }
  if (savedUrl && siteSelect) {
    const opt = Array.from(siteSelect.options).find(o => o.value === savedUrl);
    if (opt) siteSelect.value = savedUrl;
  }
  updateAutoPostSelection();
}

function updateSelectedPlatformsCount() {
  const checkboxes = document.querySelectorAll('.platform-select-checkbox:checked');
  const selected = Array.from(new Set(Array.from(checkboxes).map(cb => cb.value)));

  // Sync checkboxes across grid and table
  const allCheckboxes = document.querySelectorAll('.platform-select-checkbox');
  allCheckboxes.forEach(cb => {
    cb.checked = selected.includes(cb.value);
  });

  const countEl = document.getElementById('selectedCount');
  if (countEl) countEl.textContent = selected.length;
  const countHeaderEl = document.getElementById('selectedCountHeader');
  if (countHeaderEl) countHeaderEl.textContent = selected.length;
  
  // Save selected platforms for this project in localStorage
  localStorage.setItem('seo_selected_platforms_' + PROJECT_ID, JSON.stringify(selected));
}

function toggleSelectAllPlatforms(masterCb) {
  const checkboxes = document.querySelectorAll('.platform-select-checkbox');
  checkboxes.forEach(cb => cb.checked = masterCb.checked);
  updateSelectedPlatformsCount();
}

function loadSavedPlatformsSelection() {
  const saved = localStorage.getItem('seo_selected_platforms_' + PROJECT_ID);
  if (saved) {
    try {
      const selectedList = JSON.parse(saved);
      const checkboxes = document.querySelectorAll('.platform-select-checkbox');
      checkboxes.forEach(cb => {
        if (selectedList.includes(cb.value)) {
          cb.checked = true;
        }
      });
      updateSelectedPlatformsCount();
    } catch(e) {}
  }
}

function runSelectedPlatforms(projectId) {
  const checkboxes = document.querySelectorAll('.platform-select-checkbox:checked');
  const selected = Array.from(checkboxes).map(cb => cb.value);
  if (selected.length === 0) {
    alert('Please select at least one platform via checkboxes.');
    return;
  }

  const modal = new bootstrap.Modal(document.getElementById('postModal'));
  document.getElementById('postingStatus').textContent = `🚀 Auto Posting to ${selected.length} Selected Platforms...`;
  document.getElementById('postingDetail').innerHTML =
    '<div class="spinner-border spinner-border-sm me-2"></div>Adding tasks to queue... Please wait...';
  modal.show();

  const kwSelect = document.getElementById('backlinkKeywordSelect');
  const kw = kwSelect ? encodeURIComponent(kwSelect.value) : '';
  const siteSelect = document.getElementById('backlinkUrlSelect');
  const siteUrl = siteSelect ? encodeURIComponent(siteSelect.value) : '';
  
  fetch('auto-post-all.php?id=' + projectId + '&platforms=' + encodeURIComponent(selected.join(',')) + '&keyword=' + kw + '&target_site=' + siteUrl, {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
  })
  .then(r => r.json())
  .then(data => {
    const queued = data.queued || 0;
    document.getElementById('postingStatus').innerHTML =
      `✅ Tasks Queued! <span class="badge bg-success">${queued} tasks added</span>`;

    let table = '<div style="max-height:200px;overflow-y:auto"><table class="table table-sm table-bordered mt-2 mb-0"><thead><tr><th>Platform</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
    (data.results || []).forEach(r => {
      const badge = r.status === 'duplicate' ? 'bg-warning text-dark' : 'bg-success';
      const detail = r.message || 'Queued in background';
      table += `<tr><td class="small">${r.name || r.platform}</td><td><span class="badge ${badge}">${r.status}</span></td><td class="small">${detail}</td></tr>`;
    });
    table += '</tbody></table></div>';
    document.getElementById('postingDetail').innerHTML = table;
    refreshBacklinkTables();
    setTimeout(() => { modal.hide(); }, 2500);
  })
  .catch(err => {
    document.getElementById('postingStatus').textContent = '⚠️ Error: ' + (err.message || 'Connection failed');
    document.getElementById('postingDetail').textContent = 'Auto post failed. Check server logs.';
  });
}

function renderManagerKeywords() {
  const container = document.getElementById('managerKeywordsContainer');
  if (!container) return;
  container.innerHTML = '';
  if (managerKeywords.length === 0) {
    container.innerHTML = '<span class="text-muted small">No keywords added yet.</span>';
  }
  managerKeywords.forEach(kw => {
    const badge = document.createElement('span');
    badge.className = 'badge bg-primary text-white d-inline-flex align-items-center gap-2 py-2 px-3 rounded-pill';
    badge.innerHTML = `<span>${escapeHTML(kw)}</span><button type="button" class="btn-close btn-close-white" style="font-size:0.65rem;" onclick="managerRemoveKeyword('${escapeHTML(kw)}')"></button>`;
    container.appendChild(badge);
  });
}

function renderManagerSites() {
  const container = document.getElementById('managerSitesContainer');
  if (!container) return;
  container.innerHTML = '';
  if (managerSites.length === 0) {
    container.innerHTML = '<span class="text-muted small">No target URLs added yet.</span>';
  }
  managerSites.forEach(site => {
    const badge = document.createElement('span');
    badge.className = 'badge bg-info text-white d-inline-flex align-items-center gap-2 py-2 px-3 rounded-pill';
    badge.innerHTML = `<span>${escapeHTML(site)}</span><button type="button" class="btn-close btn-close-white" style="font-size:0.65rem;" onclick="managerRemoveSite('${escapeHTML(site)}')"></button>`;
    container.appendChild(badge);
  });
}

function saveManagerChanges() {
  const fd = new FormData();
  fd.append('update_project_targets', '1');
  fd.append('project_id', PROJECT_ID);
  fd.append('target_keywords', managerKeywords.join(', '));
  fd.append('target_sites', managerSites.join(', '));
  fd.append('csrf_token', '<?= csrfToken() ?>');

  fetch('submission-manager.php', {
    method: 'POST',
    credentials: 'same-origin',
    body: fd
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      updateSelectDropdowns();
    } else {
      alert('Error updating lists: ' + data.error);
    }
  });
}

function updateSelectDropdowns() {
  const kwSelect = document.getElementById('backlinkKeywordSelect');
  if (kwSelect) {
    const curVal = kwSelect.value;
    kwSelect.innerHTML = '';
    managerKeywords.forEach(kw => {
      const opt = document.createElement('option');
      opt.value = kw;
      opt.textContent = kw;
      if (kw === curVal) opt.selected = true;
      kwSelect.appendChild(opt);
    });
    if (!kwSelect.value && managerKeywords.length > 0) {
      kwSelect.value = managerKeywords[0];
    }
  }

  const siteSelect = document.getElementById('backlinkUrlSelect');
  if (siteSelect) {
    const curVal = siteSelect.value;
    siteSelect.innerHTML = '';
    managerSites.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s;
      opt.textContent = s;
      if (s === curVal) opt.selected = true;
      siteSelect.appendChild(opt);
    });
    if (!siteSelect.value && managerSites.length > 0) {
      siteSelect.value = managerSites[0];
    }
  }
}

function managerAddKeyword() {
  const input = document.getElementById('managerKeywordInput');
  if (!input) return;
  const val = input.value.trim();
  if (val && !managerKeywords.includes(val)) {
    managerKeywords.push(val);
    input.value = '';
    renderManagerKeywords();
    saveManagerChanges();
  }
}

function managerRemoveKeyword(kw) {
  managerKeywords = managerKeywords.filter(k => k !== kw);
  renderManagerKeywords();
  saveManagerChanges();
}

function managerAddSite() {
  const input = document.getElementById('managerSiteInput');
  if (!input) return;
  const val = input.value.trim();
  if (val) {
    if (!val.startsWith('http://') && !val.startsWith('https://')) {
      alert('Please enter a valid URL starting with http:// or https://');
      return;
    }
    if (!managerSites.includes(val)) {
      managerSites.push(val);
      input.value = '';
      renderManagerSites();
      saveManagerChanges();
    }
  }
}

function managerRemoveSite(site) {
  managerSites = managerSites.filter(s => s !== site);
  renderManagerSites();
  saveManagerChanges();
}

function escapeHTML(str) {
  return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>
</body>
</html>
