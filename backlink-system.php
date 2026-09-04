<?php
require_once 'config.php';
requireLogin();
$db = getDB();
$projectId = (int)($_GET['id'] ?? 0);
$isAjax = isset($_GET['ajax']);

$stmt = $db->prepare("SELECT * FROM projects WHERE id=? AND user_id=?");
$stmt->execute([$projectId, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) { echo json_encode(['error' => 'Not found']); exit; }

$keywordsList = array_filter(array_map('trim', explode(',', $project['target_keyword'])));
if (empty($keywordsList)) {
    $keywordsList = ['SEO Services'];
}

// Handle mark as created (20% manual action)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_created'])) {
    $blId = (int)$_POST['backlink_id'];
    $blUrl = clean($_POST['backlink_url'] ?? '');
    $db->prepare("UPDATE backlinks SET status='created', backlink_url=? WHERE id=? AND project_id=?")
       ->execute([$blUrl, $blId, $projectId]);
    echo json_encode(['success' => true]);
    exit;
}

// 80% AUTO: Generate backlink opportunities list
$backlinkSites = [
    // High DA Free Platforms
    ['platform' => 'Medium.com',        'da' => 95, 'url' => 'https://medium.com/new-story',
     'instructions' => 'Create account → New Story → Write article about "' . $project['target_keyword'] . '" → Add link to ' . $project['target_site']],
    ['platform' => 'WordPress.com',     'da' => 94, 'url' => 'https://wordpress.com/post/new',
     'instructions' => 'Create free blog → New Post → Write about keyword → Add backlink'],
    ['platform' => 'Blogger.com',       'da' => 93, 'url' => 'https://www.blogger.com/blog/post/create',
     'instructions' => 'Create blog → New Post → Add article with backlink'],
    ['platform' => 'LinkedIn Articles', 'da' => 98, 'url' => 'https://www.linkedin.com/post/new',
     'instructions' => 'Write Article → Add keyword in title → Include link to target site'],
    ['platform' => 'Quora',             'da' => 93, 'url' => 'https://www.quora.com/search?q=' . urlencode($project['target_keyword']),
     'instructions' => 'Search question about "' . $project['target_keyword'] . '" → Write detailed answer → Add link'],
    ['platform' => 'Reddit',            'da' => 91, 'url' => 'https://www.reddit.com/search/?q=' . urlencode($project['target_keyword']),
     'instructions' => 'Find relevant subreddit → Post helpful content → Add link in comments'],
    ['platform' => 'GitHub',            'da' => 96, 'url' => 'https://github.com/new',
     'instructions' => 'Create repo → Add README.md with article about keyword → Include backlink'],
    ['platform' => 'Tumblr',            'da' => 89, 'url' => 'https://www.tumblr.com/new/text',
     'instructions' => 'Create blog → New Post → Write about keyword → Add link'],
    ['platform' => 'HubPages',          'da' => 87, 'url' => 'https://hubpages.com/hub/new',
     'instructions' => 'Create account → New Hub → Write article → Add backlink'],
    ['platform' => 'Wix Blog',          'da' => 93, 'url' => 'https://www.wix.com',
     'instructions' => 'Create free site → Blog → New Post → Add backlink'],
    ['platform' => 'Weebly',            'da' => 88, 'url' => 'https://www.weebly.com',
     'instructions' => 'Create free site → Blog → New Post → Add backlink'],
    ['platform' => 'Livejournal',       'da' => 85, 'url' => 'https://www.livejournal.com/update.bml',
     'instructions' => 'Create account → New Post → Add article with link'],
    ['platform' => 'Blogspot',          'da' => 93, 'url' => 'https://www.blogger.com',
     'instructions' => 'Create blog → New Post → Add keyword article with backlink'],
    ['platform' => 'Typepad',           'da' => 84, 'url' => 'https://www.typepad.com',
     'instructions' => 'Create blog → New Post → Add backlink'],
    ['platform' => 'Pearltrees',        'da' => 79, 'url' => 'https://www.pearltrees.com',
     'instructions' => 'Create account → Add pearl with your URL → Add description with keyword'],
    ['platform' => 'Diigo',             'da' => 82, 'url' => 'https://www.diigo.com/post',
     'instructions' => 'Bookmark your URL → Add keyword tags → Add description'],
    ['platform' => 'Scoop.it',          'da' => 80, 'url' => 'https://www.scoop.it',
     'instructions' => 'Create topic → Curate content → Add your site link'],
    ['platform' => 'Folkd',             'da' => 72, 'url' => 'https://www.folkd.com/submit',
     'instructions' => 'Submit URL → Add keyword description'],
    ['platform' => 'BizSugar',          'da' => 68, 'url' => 'https://www.bizsugar.com/submit-story',
     'instructions' => 'Submit story → Add URL → Add keyword description'],
    ['platform' => 'Instapaper',        'da' => 83, 'url' => 'https://www.instapaper.com',
     'instructions' => 'Save URL → Add to public folder'],
    // Directories
    ['platform' => 'DMOZ Alternative',  'da' => 75, 'url' => 'https://www.jasminedirectory.com/submit.php',
     'instructions' => 'Submit site URL → Select category → Add description with keyword'],
    ['platform' => 'Entireweb',         'da' => 70, 'url' => 'https://www.entireweb.com/free_submission/',
     'instructions' => 'Submit URL → Add keyword description'],
    ['platform' => 'Addme',             'da' => 68, 'url' => 'https://www.addme.com/submission.htm',
     'instructions' => 'Submit URL → Add keyword description'],
    ['platform' => 'Scrubtheweb',       'da' => 65, 'url' => 'https://www.scrubtheweb.com/addurl.html',
     'instructions' => 'Submit URL → Add keyword'],
    ['platform' => 'Somuch',            'da' => 62, 'url' => 'https://www.somuch.com',
     'instructions' => 'Submit URL → Add description'],
    ['platform' => 'Exactseek',         'da' => 60, 'url' => 'https://www.exactseek.com/add.html',
     'instructions' => 'Submit URL → Add keyword description'],
    ['platform' => 'Jayde',             'da' => 58, 'url' => 'https://www.jayde.com/submit.html',
     'instructions' => 'Submit URL → Add description'],
    ['platform' => 'Abilogic',          'da' => 55, 'url' => 'https://www.abilogic.com/add-site.html',
     'instructions' => 'Submit URL → Add keyword description'],
    ['platform' => 'Cipinet',           'da' => 52, 'url' => 'https://www.cipinet.com/add-url.html',
     'instructions' => 'Submit URL → Add description'],
    ['platform' => 'Skaffe',            'da' => 50, 'url' => 'https://www.skaffe.com/addsite.php',
     'instructions' => 'Submit URL → Add keyword description'],
    // Q&A Sites
    ['platform' => 'Stack Exchange',    'da' => 92, 'url' => 'https://stackexchange.com',
     'instructions' => 'Find relevant question → Write detailed answer → Add link if relevant'],
    ['platform' => 'Yahoo Answers',     'da' => 88, 'url' => 'https://answers.yahoo.com',
     'instructions' => 'Search keyword question → Answer → Add link'],
    // Social Bookmarking
    ['platform' => 'Pinterest',         'da' => 94, 'url' => 'https://www.pinterest.com/pin/create/button/',
     'instructions' => 'Create pin → Add image → Link to target site → Add keyword description'],
    ['platform' => 'Mix.com',           'da' => 82, 'url' => 'https://mix.com',
     'instructions' => 'Submit URL → Add keyword tags'],
    ['platform' => 'Flipboard',         'da' => 91, 'url' => 'https://flipboard.com',
     'instructions' => 'Create magazine → Add your URL → Add keyword description'],
];

// Auto-save to DB if not already saved
$existing = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE project_id=?");
$existing->execute([$projectId]);
if ($existing->fetchColumn() == 0) {
    foreach ($backlinkSites as $site) {
        $db->prepare("INSERT INTO backlinks (project_id, backlink_url, platform, da_score, status) VALUES (?,?,?,?,'pending')")
           ->execute([$projectId, $site['url'], $site['platform'], $site['da']]);
    }
}

// Fetch from DB
$backlinks = $db->prepare("SELECT * FROM backlinks WHERE project_id=? ORDER BY da_score DESC");
$backlinks->execute([$projectId]);
$backlinks = $backlinks->fetchAll();

$created  = array_filter($backlinks, fn($b) => $b['status'] === 'created');
$pending  = array_filter($backlinks, fn($b) => $b['status'] === 'pending');

// Platforms with saved credentials (real auto-post)
$savedAccounts = $db->prepare('SELECT * FROM social_accounts WHERE project_id=?');
$savedAccounts->execute([$projectId]);
$savedAccounts = $savedAccounts->fetchAll();

$autoPlatforms = [];
foreach ($savedAccounts as $acc) {
    $currentKeyword = $_GET['keyword'] ?? $keywordsList[0] ?? '';
    $posted = $db->prepare("SELECT backlink_url FROM backlinks WHERE project_id=? AND platform=? AND status='created' AND (keyword=? OR (keyword IS NULL AND (post_title LIKE ? OR backlink_url LIKE ?)))");
    $posted->execute([$projectId, $acc['platform'], $currentKeyword, '%' . $currentKeyword . '%', '%' . $currentKeyword . '%']);
    $postedUrl = $posted->fetchColumn();
    
    // Fetch queue entry
    $queueStmt = $db->prepare("SELECT status, error_message FROM backlink_queue WHERE project_id=? AND platform=? AND social_account_id=? ORDER BY id DESC LIMIT 1");
    $queueStmt->execute([$projectId, $acc['platform'], $acc['id']]);
    $qItem = $queueStmt->fetch(PDO::FETCH_ASSOC);
    
    $autoPlatforms[] = [
        'id'           => $acc['platform'],
        'name'         => ucfirst($acc['platform']),
        'posted'       => (bool) $postedUrl,
        'url'          => $postedUrl ?: '',
        'queue_status' => $qItem['status'] ?? null,
        'queue_error'  => $qItem['error_message'] ?? null,
    ];
}

// Merge instructions
$siteMap = [];
foreach ($backlinkSites as $s) { $siteMap[$s['platform']] = $s; }
?>

<?php if ($isAjax): ?>

<!-- AUTO POST BACKLINKS -->
<?php $package = $project['package_type'] ?? 'basic'; ?>
<div class="card mb-4 border-success shadow-sm">
  <div class="card-header bg-success text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h6 class="mb-0"><i class="fas fa-paper-plane me-2"></i>Auto Post Backlinks (<?= ucfirst($package) ?> Plan)</h6>
    <?php if ($package !== 'basic' && count($autoPlatforms) > 0): ?>
    <button type="button" class="btn btn-warning btn-sm fw-bold" id="btnAutoPostAll" onclick="autoPostAllBacklinks()">
      <i class="fas fa-rocket me-1"></i>Auto Post All (<?= count($autoPlatforms) ?> sites)
    </button>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($package === 'basic'): ?>
    <div class="alert alert-warning mb-0">
      <i class="fas fa-exclamation-triangle me-2"></i>
      <strong>BASIC SEO Plan:</strong> Auto-posting backlinks is disabled on the Basic plan. 
      Upgrade to **Standard** (for API Auto Post) or **Premium** (for Full Selenium + API Auto Post) to unlock this feature.
    </div>
    <?php elseif (empty($autoPlatforms)): ?>
    <p class="mb-2 text-muted">
      <i class="fas fa-key me-1"></i> Add credentials first — then the system will post automatically.
    </p>
    <a href="submission-manager.php?project_id=<?= $projectId ?>" class="btn btn-primary">
      <i class="fas fa-cog me-2"></i>Submissions → Add Credentials
    </a>
    <?php else: ?>

    <div class="mb-3 d-flex align-items-center flex-wrap gap-2 border-bottom pb-3">
      <label for="backlinkKeywordSelect" class="form-label mb-0 fw-bold small text-muted">
        <i class="fas fa-key me-1"></i> Select Keyword:
      </label>
      <?php
      $currentKeyword = $_GET['keyword'] ?? $keywordsList[0] ?? '';
      ?>
      <select id="backlinkKeywordSelect" class="form-select form-select-sm" style="width: auto; min-width: 250px;" onchange="if(window.history&&window.history.replaceState){window.history.replaceState({},'','client-profile.php?id=<?= $projectId ?>&tab=backlinks&keyword='+encodeURIComponent(this.value));}">
        <?php foreach ($keywordsList as $kw): ?>
          <option value="<?= htmlspecialchars($kw, ENT_QUOTES, 'UTF-8') ?>" <?= $currentKeyword === $kw ? 'selected' : '' ?>><?= htmlspecialchars($kw) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="small text-muted mb-3">
      <?php if ($package === 'standard'): ?>
        <strong>STANDARD SEO Plan:</strong> Only API-based automatic posting is active. Selenium platforms (Pinterest, Behance, MeWe, etc.) are skipped. Upgrade to Premium for full Selenium automation.
      <?php else: ?>
        <strong>PREMIUM SEO Plan:</strong> Both API and Selenium browser automation are fully unlocked!
      <?php endif; ?>
    </p>
    <div id="autoPostLog" class="d-none mb-3 p-3 bg-dark text-success rounded small" style="max-height:200px;overflow-y:auto;font-family:monospace;"></div>
    <div class="row g-2">
      <?php foreach ($autoPlatforms as $ap): ?>
      <?php
      // Check if Standard plan and platform is Selenium
      $isSelenium = in_array($ap['id'], ['pinterest', 'behance', 'wakelet', 'vivauae', 'padlet', 'pearltrees', 'mewe', 'instapaper', 'livejournal', 'site123', 'symbaloo', 'penzu', 'linktree', 'scoopit']);
      $disabled = ($package === 'standard' && $isSelenium);
      ?>
      <div class="col-md-4 col-lg-3">
        <div class="border rounded p-2 h-100 <?= $ap['posted'] ? 'bg-light' : '' ?> <?= $disabled ? 'opacity-50' : '' ?>" style="<?= $disabled ? 'background: #f8f9fa; border-style: dashed !important;' : '' ?>">
          <strong class="small text-dark"><?= clean($ap['name']) ?></strong>
          <?php if ($disabled): ?>
            <br><span class="badge bg-secondary mt-1"><i class="fas fa-lock me-1"></i>Premium Only</span>
          <?php elseif ($ap['posted']): ?>
            <br><span class="badge bg-success mt-1">✅ Posted</span>
            <br><a href="<?= clean($ap['url']) ?>" target="_blank" class="small text-decoration-none">View link</a>
          <?php elseif ($ap['queue_status'] === 'pending'): ?>
            <br><span class="badge bg-warning text-dark mt-1"><i class="fas fa-clock me-1"></i>⏳ Queued</span>
          <?php elseif ($ap['queue_status'] === 'processing'): ?>
            <br><span class="badge bg-info mt-1"><i class="fas fa-spinner fa-spin me-1"></i>⚙️ Posting...</span>
          <?php else: ?>
            <br><button type="button" class="btn btn-sm btn-success mt-1 w-100"
                        onclick="autoPostOne('<?= clean($ap['id']) ?>', '<?= clean($ap['name']) ?>', this)">
              <i class="fas fa-paper-plane"></i> Auto Post
            </button>
            <?php if ($ap['queue_status'] === 'failed'): ?>
              <br><span class="badge bg-danger mt-1 text-white" style="cursor:help;" title="<?= htmlspecialchars($ap['queue_error'] ?? 'Unknown Error') ?>"><i class="fas fa-exclamation-triangle me-1"></i>❌ Failed</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="row mb-3">
  <div class="col-md-3">
    <div class="card text-center border-success">
      <div class="card-body">
        <h2 class="text-success"><?= count($created) ?></h2>
        <p>Backlinks Created</p>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-center border-warning">
      <div class="card-body">
        <h2 class="text-warning"><?= count($pending) ?></h2>
        <p>Pending</p>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card border-info">
      <div class="card-body">
        <h6 class="text-info"><i class="fas fa-info-circle me-2"></i>How it works (80/20)</h6>
        <p class="small mb-0">
          <strong>Auto:</strong> Green box above — API post with credentials.<br>
          <strong>Manual:</strong> <?= count($backlinks) ?> sites — Open → paste content → Mark Done
        </p>
      </div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-link me-2"></i>Backlink Opportunities (<?= count($backlinks) ?> sites)</h6>
    <div>
      <button class="btn btn-sm btn-outline-primary" onclick="filterBacklinks('all')">All</button>
      <button class="btn btn-sm btn-outline-warning" onclick="filterBacklinks('pending')">Pending</button>
      <button class="btn btn-sm btn-outline-success" onclick="filterBacklinks('created')">Created</button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
      <table class="table table-hover table-sm mb-0" id="backlinkTable">
        <thead class="table-dark sticky-top">
          <tr>
            <th>#</th>
            <th>Platform</th>
            <th>DA</th>
            <th>Instructions (80% Auto-prepared)</th>
            <th>Status</th>
            <th>Action (20% Manual)</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($backlinks as $i => $bl): ?>
          <?php $info = $siteMap[$bl['platform']] ?? null; ?>
          <tr class="bl-row" data-status="<?= $bl['status'] ?>">
            <td><?= $i + 1 ?></td>
            <td><strong><?= clean($bl['platform']) ?></strong></td>
            <td>
              <span class="badge <?= $bl['da_score'] >= 80 ? 'bg-success' : ($bl['da_score'] >= 60 ? 'bg-warning' : 'bg-secondary') ?>">
                DA <?= $bl['da_score'] ?>
              </span>
            </td>
            <td>
              <small class="text-muted"><?= clean($info['instructions'] ?? 'Submit your URL with keyword description') ?></small>
            </td>
            <td>
              <span class="badge <?= $bl['status'] === 'created' ? 'bg-success' : 'bg-warning' ?>" id="status-<?= $bl['id'] ?>">
                <?= ucfirst($bl['status']) ?>
              </span>
            </td>
            <td>
              <?php if ($bl['status'] === 'pending'): ?>
              <div class="d-flex gap-1">
                <a href="<?= clean($bl['backlink_url']) ?>" target="_blank" class="btn btn-xs btn-primary">
                  <i class="fas fa-external-link-alt"></i> Open
                </a>
                <button class="btn btn-xs btn-success" onclick="markCreated(<?= $bl['id'] ?>, this)">
                  <i class="fas fa-check"></i> Done
                </button>
              </div>
              <?php else: ?>
              <span class="text-success"><i class="fas fa-check-circle"></i> Created</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Pre-filled content template -->
<div class="card mt-3 border-info">
  <div class="card-header bg-info text-white">
    <h6 class="mb-0"><i class="fas fa-copy me-2"></i>80% Auto: Pre-filled Content Template (Copy & Paste)</h6>
  </div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-6">
        <label class="form-label fw-bold">Article Title:</label>
        <input type="text" class="form-control" id="blTitle"
               value="Best <?= clean($project['target_keyword']) ?> - Complete Guide 2024" readonly>
        <button class="btn btn-sm btn-outline-secondary mt-1" onclick="copyText('blTitle')">
          <i class="fas fa-copy"></i> Copy
        </button>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-bold">Anchor Text:</label>
        <input type="text" class="form-control" id="blAnchor"
               value="<?= clean($project['target_keyword']) ?>" readonly>
        <button class="btn btn-sm btn-outline-secondary mt-1" onclick="copyText('blAnchor')">
          <i class="fas fa-copy"></i> Copy
        </button>
      </div>
    </div>
    <div class="mt-3">
      <label class="form-label fw-bold">Article Content (with backlink):</label>
      <textarea class="form-control" id="blContent" rows="6" readonly><?php
$kw = $project['target_keyword'];
$site = $project['target_site'] ?: $project['website_url'];
echo "Are you looking for the best {$kw}? Look no further!\n\n";
echo ucwords($kw) . " is one of the most in-demand skills in today's job market. ";
echo "Whether you are a beginner or an experienced professional, mastering this skill can open doors to exciting career opportunities.\n\n";
echo "We recommend checking out <a href=\"{$site}\">" . ucwords($kw) . "</a> for comprehensive training.\n\n";
echo "Key benefits:\n";
echo "- Industry-recognized certification\n";
echo "- Hands-on practical training\n";
echo "- Expert instructors\n";
echo "- Job placement assistance\n\n";
echo "Visit " . $site . " to learn more about " . $kw . " courses and enroll today!";
?></textarea>
      <button class="btn btn-sm btn-outline-secondary mt-1" onclick="copyText('blContent')">
        <i class="fas fa-copy"></i> Copy Content
      </button>
    </div>
  </div>
</div>

<script>
const BL_PROJECT_ID = <?= $projectId ?>;

function autoPostOne(platformId, platformName, btn) {
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  }
  const kwSelect = document.getElementById('backlinkKeywordSelect');
  const kw = kwSelect ? encodeURIComponent(kwSelect.value) : '';
  fetch('auto-post-all.php?id=' + BL_PROJECT_ID + '&platform=' + platformId + '&keyword=' + kw, {
    credentials: 'same-origin',
    signal: AbortSignal.timeout(120000),
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'application/json'
    }
  })
    .then(r => r.text())
    .then(text => {
      try {
        return JSON.parse(text);
      } catch (err) {
        const preview = text.replace(/<[^>]*>/g, ' ').trim().slice(0, 500);
        throw new Error('Invalid JSON response from server. Response preview: ' + preview);
      }
    })
    .then(data => {
      if (data.queued) {
        alert('✅ ' + platformName + ' task added to queue!\nProcessing in background.');
        if (typeof loadTab === 'function') loadTab('backlinks');
        else location.reload();
      } else {
        alert('⚠️ ' + platformName + ': ' + (data.error || 'Failed to queue task'));
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Auto Post'; }
      }
    })
    .catch(err => {
      alert('Error: ' + err.message);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Auto Post'; }
    });
}

function autoPostAllBacklinks() {
  const n = <?= count($autoPlatforms) ?>;
  if (!confirm('Auto post to ' + n + ' platforms? (2–5 minutes)')) return;

  const log = document.getElementById('autoPostLog');
  const btn = document.getElementById('btnAutoPostAll');
  if (log) { log.classList.remove('d-none'); log.innerHTML = '<div>🚀 Starting auto post to ' + n + ' platforms...</div>'; }
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Posting...'; }

  const kwSelect = document.getElementById('backlinkKeywordSelect');
  const kw = kwSelect ? encodeURIComponent(kwSelect.value) : '';
  fetch('auto-post-all.php?id=' + BL_PROJECT_ID + '&keyword=' + kw, {
    credentials: 'same-origin',
    signal: AbortSignal.timeout(600000),
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'application/json'
    }
  })
    .then(r => r.text())
    .then(text => {
      let data;
      try {
        data = JSON.parse(text);
      } catch (err) {
        const preview = text.replace(/<[^>]*>/g, ' ').trim().slice(0, 500);
        throw new Error('Invalid JSON response. Preview: ' + preview);
      }

      if (data.error && !data.results) {
        if (log) log.innerHTML += '<div style="color:red">❌ ' + data.error + '</div>';
        alert(data.error);
        return;
      }

      let html = '<div><strong>⏳ ' + (data.message || 'Done') + '</strong></div>';
      (data.results || []).forEach(r => {
        const icon = '⏳';
        const label = r.name || (r.platform + (r.handle ? ' (' + r.handle + ')' : ''));
        const detail = r.message || 'Queued in background';
        html += '<div>' + icon + ' ' + label + ': ' + detail + '</div>';
      });
      if (log) log.innerHTML = html;

      const summary = '✅ Done!\n\nQueued: ' + (data.queued || 0) + ' tasks added to background queue.';
      alert(summary);
      if (typeof loadTab === 'function') loadTab('backlinks');
      else location.reload();
    })
    .catch(err => {
      if (log) log.innerHTML += '<div style="color:red">❌ Error: ' + err.message + '</div>';
      alert('Error: ' + err.message);
    })
    .finally(() => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-rocket me-1"></i>Auto Post All (' + n + ' sites)';
      }
    });
}

function markCreated(id, btn) {
  const url = prompt('Enter the URL where you created the backlink (e.g., https://medium.com/your-article):');
  if (!url) return;
  fetch('backlink-system.php?id=<?= $projectId ?>', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'mark_created=1&backlink_id=' + id + '&backlink_url=' + encodeURIComponent(url)
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      document.getElementById('status-' + id).textContent = 'Created';
      document.getElementById('status-' + id).className = 'badge bg-success';
      btn.closest('td').innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> Created</span>';
    }
  });
}

function filterBacklinks(status) {
  document.querySelectorAll('.bl-row').forEach(row => {
    if (status === 'all' || row.dataset.status === status) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

function copyText(id) {
  const el = document.getElementById(id);
  el.select();
  document.execCommand('copy');
  alert('Copied!');
}
</script>
<?php endif; ?>
