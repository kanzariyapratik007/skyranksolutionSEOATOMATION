<?php
require_once 'config.php';
requireLogin();
$db = getDB();
$projectId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM projects WHERE id=? AND user_id=?");
$stmt->execute([$projectId, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) { header('Location: dashboard.php'); exit; }

// Fetch latest stats
$backlinksCount = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE project_id=? AND status='created'");
$backlinksCount->execute([$projectId]);
$backlinksCount = $backlinksCount->fetchColumn();

$latestReport = $db->prepare("SELECT * FROM seo_reports WHERE project_id=? ORDER BY report_date DESC LIMIT 1");
$latestReport->execute([$projectId]);
$latestReport = $latestReport->fetch();

$openIssues = $db->prepare("SELECT COUNT(*) FROM onpage_issues WHERE project_id=? AND status='open'");
$openIssues->execute([$projectId]);
$openIssues = $openIssues->fetchColumn();

$keywordsCount = $db->prepare("SELECT COUNT(*) FROM keywords WHERE project_id=?");
$keywordsCount->execute([$projectId]);
$keywordsCount = $keywordsCount->fetchColumn();

$contentCount = $db->prepare("SELECT COUNT(*) FROM content_queue WHERE project_id=?");
$contentCount->execute([$projectId]);
$contentCount = $contentCount->fetchColumn();

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
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SEO 80/20 - <?= clean($project['target_keyword']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container-fluid py-4">

  <!-- Project Header -->
  <div class="row mb-4">
    <div class="col">
      <h3><i class="fas fa-rocket me-2 text-primary"></i>SEO 80/20 System</h3>
      <div class="d-flex align-items-center gap-3 mt-2 flex-wrap text-muted small">
        <div class="d-flex align-items-center gap-2">
          <strong>Keyword:</strong>
          <select id="headerKeywordSelect" class="form-select form-select-sm" style="width: auto; min-width: 220px;" onchange="updateHeaderSelection()">
            <?php foreach ($keywordsList as $kw): ?>
              <option value="<?= htmlspecialchars($kw, ENT_QUOTES, 'UTF-8') ?>" <?= $currentKeyword === $kw ? 'selected' : '' ?>><?= htmlspecialchars($kw) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="d-flex align-items-center gap-2">
          <strong>Site:</strong>
          <select id="headerUrlSelect" class="form-select form-select-sm" style="width: auto; min-width: 320px;" onchange="updateHeaderSelection()">
            <?php foreach ($targetSitesList as $url): ?>
              <option value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" <?= $currentTargetSite === $url ? 'selected' : '' ?>><?= htmlspecialchars($url) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="col-auto">
      <a href="export-excel.php?id=<?= $projectId ?>" class="btn btn-success">
        <i class="fas fa-file-excel me-2"></i>Export Excel
      </a>
      <a href="dashboard.php" class="btn btn-outline-secondary ms-2">
        <i class="fas fa-arrow-left me-2"></i>Dashboard
      </a>
    </div>
  </div>

  <!-- 80/20 Progress Overview -->
  <div class="card mb-4 border-primary">
    <div class="card-body">
      <h5 class="mb-3"><i class="fas fa-tasks me-2"></i>80/20 SEO Progress</h5>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label text-success fw-bold">🤖 80% AUTO (System does automatically)</label>
          <div class="progress mb-1" style="height:20px;">
            <div class="progress-bar bg-success" id="autoProgress" style="width:0%">0%</div>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label text-warning fw-bold">👤 20% MANUAL (Your action needed)</label>
          <div class="progress mb-1" style="height:20px;">
            <div class="progress-bar bg-warning" id="manualProgress" style="width:0%">0%</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Stats -->
  <div class="row g-3 mb-4">
    <div class="col-md-2">
      <div class="mini-stat text-center p-3 bg-white rounded shadow-sm">
        <i class="fas fa-link fa-2x text-success mb-2"></i>
        <h4><?= $backlinksCount ?></h4><small>Backlinks</small>
      </div>
    </div>
    <div class="col-md-2">
      <div class="mini-stat text-center p-3 bg-white rounded shadow-sm">
        <i class="fas fa-star fa-2x text-warning mb-2"></i>
        <h4><?= $latestReport['seo_score'] ?? 0 ?></h4><small>SEO Score</small>
      </div>
    </div>
    <div class="col-md-2">
      <div class="mini-stat text-center p-3 bg-white rounded shadow-sm">
        <i class="fas fa-search fa-2x text-primary mb-2"></i>
        <h4><?= $latestReport['rank'] ?? '-' ?></h4><small>Google Rank</small>
      </div>
    </div>
    <div class="col-md-2">
      <div class="mini-stat text-center p-3 bg-white rounded shadow-sm">
        <i class="fas fa-exclamation-triangle fa-2x text-danger mb-2"></i>
        <h4><?= $openIssues ?></h4><small>Open Issues</small>
      </div>
    </div>
    <div class="col-md-2">
      <div class="mini-stat text-center p-3 bg-white rounded shadow-sm">
        <i class="fas fa-key fa-2x text-info mb-2"></i>
        <h4><?= $keywordsCount ?></h4><small>Keywords</small>
      </div>
    </div>
    <div class="col-md-2">
      <div class="mini-stat text-center p-3 bg-white rounded shadow-sm">
        <i class="fas fa-file-alt fa-2x text-secondary mb-2"></i>
        <h4><?= $contentCount ?></h4><small>Articles</small>
      </div>
    </div>
  </div>

  <!-- Main Tabs -->
  <ul class="nav nav-tabs nav-fill mb-4" id="seoTabs">
    <li class="nav-item">
      <a class="nav-link active" href="#" onclick="return loadTab('backlinks', event)">
        <i class="fas fa-link me-1"></i>Backlinks <span class="badge bg-success">80% Auto</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="#" onclick="return loadTab('meta', event)">
        <i class="fas fa-tags me-1"></i>Meta Tags <span class="badge bg-danger">Important</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="#" onclick="return loadTab('onpage', event)">
        <i class="fas fa-code me-1"></i>On-Page SEO <span class="badge bg-success">80% Auto</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="#" onclick="return loadTab('rank', event)">
        <i class="fas fa-chart-line me-1"></i>Rank Tracker <span class="badge bg-primary">100% Auto</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="#" onclick="return loadTab('keywords', event)">
        <i class="fas fa-key me-1"></i>Keywords <span class="badge bg-success">80% Auto</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="#" onclick="return loadTab('competitor', event)">
        <i class="fas fa-users me-1"></i>Competitors <span class="badge bg-success">80% Auto</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="#" onclick="return loadTab('content', event)">
        <i class="fas fa-pen me-1"></i>Content <span class="badge bg-success">80% Auto</span>
      </a>
    </li>
  </ul>

  <!-- Tab Content (loaded via AJAX) -->
  <div id="tabContent">
    <div class="text-center py-5">
      <i class="fas fa-rocket fa-3x text-primary mb-3"></i>
      <h4>Select a tab above to start</h4>
      <p class="text-muted">Each module does 80% of the work automatically</p>
      <button class="btn btn-primary btn-lg mt-2" onclick="runAllSEO()">
        <i class="fas fa-play me-2"></i>Run All SEO (80% Auto)
      </button>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const PROJECT_ID = <?= $projectId ?>;

function loadTab(tab, ev) {
  if (ev && ev.preventDefault) ev.preventDefault();
  document.querySelectorAll('#seoTabs .nav-link').forEach(el => {
    el.classList.remove('active');
    const oc = el.getAttribute('onclick') || '';
    if ((ev && ev.currentTarget === el) || (!ev && oc.indexOf("'" + tab + "'") !== -1)) {
      el.classList.add('active');
    }
  });

  const content = document.getElementById('tabContent');
  content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-3">Loading...</p></div>';

  const kwSelect = document.getElementById('headerKeywordSelect');
  const siteSelect = document.getElementById('headerUrlSelect');
  const kw = kwSelect ? encodeURIComponent(kwSelect.value) : '';
  const siteUrl = siteSelect ? encodeURIComponent(siteSelect.value) : '';

  const urls = {
    backlinks:  'backlink-system.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + kw + '&target_site=' + siteUrl,
    meta:       'meta-optimizer.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + kw + '&target_site=' + siteUrl,
    onpage:     'onpage-analyzer.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + kw + '&target_site=' + siteUrl,
    rank:       'rank-tracker.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + kw + '&target_site=' + siteUrl,
    keywords:   'keyword-research.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + kw + '&target_site=' + siteUrl,
    competitor: 'competitor-analysis.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + kw + '&target_site=' + siteUrl,
    content:    'content-generator.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + kw + '&target_site=' + siteUrl,
  };

  fetch(urls[tab])
    .then(r => r.text())
    .then(html => { content.innerHTML = html; updateProgress(); })
    .catch(() => { content.innerHTML = '<div class="alert alert-danger">Failed to load. Please try again.</div>'; });
}

// ── Global rank check function (called from ajax-loaded rank-tracker) ──
function checkRankNow(btn) {
  if (!btn) btn = document.querySelector('[onclick*="checkRankNow"]');
  if (!btn) return;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking Google rank...';

  fetch('rank-tracker.php?id=' + PROJECT_ID + '&run=1', {
    credentials: 'same-origin',
    signal: AbortSignal.timeout(120000)
  })
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-sync me-2"></i>Check Rank Now (100% Auto)';
      // Show toast
      const t = document.createElement('div');
      t.className = 'alert alert-success position-fixed top-0 end-0 m-3';
      t.style.zIndex = 9999;
      t.textContent = data.message || 'Rank checked!';
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 4000);
      // Reload rank tab
      setTimeout(() => loadTab('rank'), 1500);
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-sync me-2"></i>Check Rank Now (100% Auto)';
      alert('Error: ' + err.message);
    });
}

function runAllSEO() {
  const tabs = ['onpage', 'keywords', 'competitor', 'rank'];
  const content = document.getElementById('tabContent');
  content.innerHTML = `
    <div class="card p-4">
      <h5><i class="fas fa-rocket me-2 text-primary"></i>Running Full 80/20 SEO...</h5>
      <div id="runLog" class="mt-3 bg-dark text-success p-3 rounded" style="font-family:monospace;min-height:200px;max-height:400px;overflow-y:auto;">
        <div>⚡ Starting SEO automation...</div>
      </div>
      <div class="progress mt-3" style="height:25px;">
        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" id="runBar" style="width:0%">0%</div>
      </div>
    </div>`;

  const log = document.getElementById('runLog');
  const bar = document.getElementById('runBar');
  let step = 0;
  const steps = [
    { msg: '🏷️ Generating Meta Title, Description, OG tags...', url: 'meta-optimizer.php?id=' + PROJECT_ID + '&run=1' },
    { msg: '🔍 Fetching website HTML...', url: 'onpage-analyzer.php?id=' + PROJECT_ID + '&run=1' },
    { msg: '🔑 Fetching keywords from Google Suggest...', url: 'keyword-research.php?id=' + PROJECT_ID + '&run=1' },
    { msg: '🏆 Analyzing competitors...', url: 'competitor-analysis.php?id=' + PROJECT_ID + '&run=1' },
    { msg: '📊 Checking Google rank...', url: 'rank-tracker.php?id=' + PROJECT_ID + '&run=1' },
    { msg: '✍️ Generating SEO content...', url: 'content-generator.php?id=' + PROJECT_ID + '&run=1' },
    { msg: '🔗 Auto-posting backlinks (API platforms)...', url: 'auto-post-all.php?id=' + PROJECT_ID },
  ];

  function runStep() {
    if (step >= steps.length) {
      log.innerHTML += '<div class="text-warning mt-2">✅ 80% Auto tasks complete! Check each tab for 20% manual actions.</div>';
      bar.style.width = '100%'; bar.textContent = '100%';
      updateProgress();
      return;
    }
    const s = steps[step];
    log.innerHTML += '<div>' + s.msg + '</div>';
    log.scrollTop = log.scrollHeight;
    bar.style.width = (((step + 1) / steps.length) * 100) + '%';
    bar.textContent = Math.round(((step + 1) / steps.length) * 100) + '%';

    const isJson = s.json !== false;
    fetch(s.url, { credentials: 'same-origin', signal: AbortSignal.timeout(300000) })
      .then(r => isJson ? r.json() : r.json().catch(() => ({ message: 'Request sent' })))
      .then(data => {
        let detail = data.message || 'Done';
        if (data.posted !== undefined) {
          detail = data.posted + ' posted, ' + (data.failed || 0) + ' failed';
        }
        log.innerHTML += '<div class="text-info">  → ' + detail + '</div>';
        log.scrollTop = log.scrollHeight;
        step++;
        setTimeout(runStep, 1200);
      })
      .catch(() => {
        log.innerHTML += '<div class="text-danger">  → Error (continuing...)</div>';
        step++;
        setTimeout(runStep, 800);
      });
  }
  runStep();
}

function updateProgress() {
  // Simple progress indicator
  document.getElementById('autoProgress').style.width = '80%';
  document.getElementById('autoProgress').textContent = '80%';
  document.getElementById('manualProgress').style.width = '20%';
  document.getElementById('manualProgress').textContent = '20%';
}

function updateHeaderSelection() {
  const kw = document.getElementById('headerKeywordSelect').value;
  const site = document.getElementById('headerUrlSelect').value;
  location.href = 'seo-80-20.php?id=' + PROJECT_ID + '&keyword=' + encodeURIComponent(kw) + '&target_site=' + encodeURIComponent(site);
}

// On-Page SEO tab functions
function approveFix(btn) {
  const type = btn.getAttribute('data-type');
  const fix  = btn.getAttribute('data-fix');
  
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Applying...';
  btn.disabled = true;
  
  const formData = new FormData();
  formData.append('approve_issue', '1');
  formData.append('issue_type', type);
  formData.append('fix_code', fix);
  
  fetch('onpage-analyzer.php?id=' + PROJECT_ID, {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      if (data.auto_fixed) {
        btn.innerHTML = '<i class="fas fa-check-circle me-1"></i>Auto Fixed!';
        btn.classList.replace('btn-outline-success', 'btn-success');
        btn.classList.replace('btn-danger', 'btn-success');
        alert('🎉 Success! WordPress Site Title/Tagline updated automatically via Selenium Browser Automation!');
      } else {
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Approved!';
        btn.classList.replace('btn-outline-success', 'btn-success');
        btn.classList.replace('btn-danger', 'btn-success');
        alert('Approved! Apply this fix manually to your website:\n\n' + fix);
      }
    } else {
      btn.innerHTML = '<i class="fas fa-times me-1"></i>Failed';
      btn.classList.replace('btn-outline-success', 'btn-danger');
      alert('Error: ' + data.error);
    }
  })
  .catch(err => {
    btn.innerHTML = '<i class="fas fa-times me-1"></i>Error';
    btn.classList.replace('btn-outline-success', 'btn-danger');
    alert('An unexpected error occurred. Please apply the fix manually:\n\n' + fix);
  });
}

function updateAuditSelection() {
  const kw = document.getElementById('auditKeywordSelect').value;
  const site = document.getElementById('auditUrlSelect').value;
  
  const content = document.getElementById('tabContent');
  content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-3">Loading Audit...</p></div>';
  
  fetch('onpage-analyzer.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + encodeURIComponent(kw) + '&target_site=' + encodeURIComponent(site), {credentials: 'same-origin'})
    .then(r => r.text())
    .then(html => { content.innerHTML = html; })
    .catch(() => { content.innerHTML = '<div class="alert alert-danger">Failed to load audit.</div>'; });
}

function runAuditNow(btn) {
  const kw = document.getElementById('auditKeywordSelect').value;
  const site = document.getElementById('auditUrlSelect').value;
  
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Auditing...';
  
  fetch('onpage-analyzer.php?id=' + PROJECT_ID + '&ajax=1&run=1&keyword=' + encodeURIComponent(kw) + '&target_site=' + encodeURIComponent(site), {credentials: 'same-origin'})
    .then(r => r.json())
    .then(data => {
      updateAuditSelection();
    })
    .catch(() => {
      alert('Error running audit.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Run Audit';
    });
}

// Meta Tags tab functions
function copyMetaHtml() {
  const el = document.getElementById('metaHeadHtml');
  if (!el) return;
  el.select();
  navigator.clipboard.writeText(el.value).then(() => alert('Meta HTML copied! Paste in your website <head>.'));
}

function regenerateMeta() {
  if (!confirm('Generate new meta tags with AI?')) return;
  const kwSelect = document.getElementById('metaKeywordSelect');
  const siteSelect = document.getElementById('metaUrlSelect');
  const kw = kwSelect ? encodeURIComponent(kwSelect.value) : '';
  const siteUrl = siteSelect ? encodeURIComponent(siteSelect.value) : '';
  
  fetch('meta-optimizer.php?id=' + PROJECT_ID + '&run=1&keyword=' + kw + '&target_site=' + siteUrl, { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => { alert(d.message || 'Done'); loadTab('meta'); })
    .catch(() => alert('Error — check ChatGPT API key in API Keys page.'));
}

function updateMetaSelection() {
  const kw = document.getElementById('metaKeywordSelect').value;
  const site = document.getElementById('metaUrlSelect').value;
  
  const content = document.getElementById('tabContent');
  content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-3">Loading Meta Tags...</p></div>';
  
  fetch('meta-optimizer.php?id=' + PROJECT_ID + '&ajax=1&keyword=' + encodeURIComponent(kw) + '&target_site=' + encodeURIComponent(site), {credentials: 'same-origin'})
    .then(r => r.text())
    .then(html => { content.innerHTML = html; })
    .catch(() => { content.innerHTML = '<div class="alert alert-danger">Failed to load meta tags.</div>'; });
}

function runMetaAuditNow(btn) {
  const kw = document.getElementById('metaKeywordSelect').value;
  const site = document.getElementById('metaUrlSelect').value;
  
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Auditing...';
  
  fetch('meta-optimizer.php?id=' + PROJECT_ID + '&ajax=1&run=1&keyword=' + encodeURIComponent(kw) + '&target_site=' + encodeURIComponent(site), {credentials: 'same-origin'})
    .then(r => r.json())
    .then(data => {
      updateMetaSelection();
    })
    .catch(() => {
      alert('Error running audit.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Run Audit';
    });
}

document.addEventListener('DOMContentLoaded', function () {
  // Read active query parameters or default to selected options
  const kw = document.getElementById('headerKeywordSelect').value;
  const site = document.getElementById('headerUrlSelect').value;
  loadTab('meta');
});
</script>
<?php include 'includes/footer.php'; ?>
</body>
</html>
