<?php
require_once 'config.php';
require_once 'includes/meta-generator.php';
requireLogin();
$db = getDB();
$projectId = (int) ($_GET['id'] ?? 0);
$isAjax    = isset($_GET['ajax']);
$isRun     = isset($_GET['run']);

$stmt = $db->prepare('SELECT * FROM projects WHERE id=? AND user_id=?');
$stmt->execute([$projectId, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) {
    if ($isRun) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Project not found']);
        exit;
    }
    echo '<div class="alert alert-danger">Project not found.</div>';
    exit;
}

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

$url = trim(explode(',', $currentTargetSite)[0]);
$keyword = trim(explode(',', $currentKeyword)[0]);

if ($isRun) {
    header('Content-Type: application/json');
    $analysis = analyzeMetaTags($url, $keyword);
    $generated = generateMetaWithAI($project, $keyword, $url);
    if ($generated && !isset($generated['error'])) {
        saveProjectMeta($db, $projectId, $generated);
        $issueCount = count($analysis['issues'] ?? []);
        echo json_encode([
            'message' => "Meta tags generated with " . ($generated['source'] ?? 'AI') . ". Found {$issueCount} issues on live site — copy HTML to your website <head>.",
            'issues'  => $issueCount,
            'score'   => max(0, 100 - ($issueCount * 12)),
        ]);
    } else {
        $err = $generated['error'] ?? 'Meta generation failed. Add ChatGPT API key.';
        echo json_encode([
            'error' => $err,
            'message' => $err
        ]);
    }
    exit;
}

$analysis  = analyzeMetaTags($url, $keyword);
$savedMeta = loadProjectMeta($db, $projectId);
$metaError = null;

if (!$savedMeta && empty($analysis['error'])) {
    $generated = generateMetaWithAI($project, $keyword, $url);
    if ($generated && !isset($generated['error'])) {
        saveProjectMeta($db, $projectId, $generated);
        $savedMeta = loadProjectMeta($db, $projectId);
    } elseif (isset($generated['error'])) {
        $metaError = $generated['error'];
    }
}

$severityColors = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'secondary'];
?>

<?php if ($isAjax): ?>
  <!-- Keyword & Target Page URL Selectors for Meta Tags -->
  <div class="card mb-3 border-info shadow-sm bg-light">
    <div class="card-body py-2 px-3 d-flex align-items-center flex-wrap gap-3">
      <div class="d-flex align-items-center gap-2">
        <strong class="small text-muted mb-0"><i class="fas fa-key me-1"></i> Audit Keyword:</strong>
        <select id="metaKeywordSelect" class="form-select form-select-sm" style="width: auto; min-width: 220px;" onchange="updateMetaSelection()">
          <?php foreach ($keywordsList as $kw): ?>
            <option value="<?= htmlspecialchars($kw, ENT_QUOTES, 'UTF-8') ?>" <?= $currentKeyword === $kw ? 'selected' : '' ?>><?= htmlspecialchars($kw) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="d-flex align-items-center gap-2">
        <strong class="small text-muted mb-0"><i class="fas fa-link me-1"></i> Audit Target Page URL:</strong>
        <select id="metaUrlSelect" class="form-select form-select-sm" style="width: auto; min-width: 320px;" onchange="updateMetaSelection()">
          <?php foreach ($targetSitesList as $url): ?>
            <option value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" <?= $currentTargetSite === $url ? 'selected' : '' ?>><?= htmlspecialchars($url) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary btn-sm ms-auto" onclick="runMetaAuditNow(this)">
        <i class="fas fa-sync-alt me-1"></i>Run Audit
      </button>
    </div>
  </div>

<div class="alert alert-info border-0 mb-3">
  <i class="fas fa-info-circle me-2"></i>
  <strong>Important:</strong> This system **generates** meta tags. It is **required** to paste them into the <code>&lt;head&gt;</code> of your website for Google #1 ranking.
  No tool can guarantee 100% automatic top Google ranking — it takes 2-8 weeks.
</div>

<?php if (!empty($analysis['error'])): ?>
<div class="alert alert-danger"><?= clean($analysis['error']) ?></div>
<?php else: ?>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card h-100 border-danger">
      <div class="card-header bg-danger text-white"><h6 class="mb-0">🔴 Live Website</h6></div>
      <div class="card-body small">
        <?php $c = $analysis['current']; ?>
        <p><strong>Title:</strong><br><?= $c['title'] ? clean(mb_substr($c['title'], 0, 80)) : '<span class="text-danger">Missing</span>' ?></p>
        <p><strong>Meta Description:</strong><br><?= $c['description'] ? clean(mb_substr($c['description'], 0, 120)) : '<span class="text-danger">Missing</span>' ?></p>
        <p><strong>OG Title:</strong> <?= $c['og_title'] ? '✅' : '❌' ?> &nbsp;
           <strong>OG Image:</strong> <?= $c['og_image'] ? '✅' : '❌' ?> &nbsp;
           <strong>Canonical:</strong> <?= $c['canonical'] ? '✅' : '❌' ?></p>
        <?php if (!empty($analysis['issues'])): ?>
        <ul class="mb-0 ps-3">
          <?php foreach ($analysis['issues'] as $iss): ?>
          <li><span class="badge bg-<?= $severityColors[$iss['severity']] ?? 'secondary' ?>"><?= $iss['severity'] ?></span> <?= clean($iss['msg']) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="text-success mb-0">Live meta looks good!</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100 border-success">
      <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">🟢 AI Optimized Meta (Copy to website)</h6>
        <button type="button" class="btn btn-sm btn-light" onclick="regenerateMeta()">
          <i class="fas fa-sync"></i> Regenerate
        </button>
      </div>
      <div class="card-body small">
        <?php if ($savedMeta): ?>
        <p><strong>Title (<?= mb_strlen($savedMeta['meta_title']) ?> chars):</strong><br><?= clean($savedMeta['meta_title']) ?></p>
        <p><strong>Description (<?= mb_strlen($savedMeta['meta_description']) ?> chars):</strong><br><?= clean($savedMeta['meta_description']) ?></p>
        <p><strong>H1 suggestion:</strong> <code>&lt;h1&gt;<?= clean($savedMeta['h1_suggestion']) ?>&lt;/h1&gt;</code></p>
        <?php elseif (!empty($metaError)): ?>
        <div class="alert alert-danger py-2 small mb-0">
             <i class="fas fa-exclamation-circle me-1"></i> <?= clean($metaError) ?>
        </div>
        <?php else: ?>
        <p class="text-warning mb-0">No meta saved yet. Click Regenerate or Run All SEO.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($savedMeta): ?>
<div class="card border-primary shadow-sm mb-3">
  <div class="card-header bg-primary text-white d-flex justify-content-between">
    <span><i class="fas fa-code me-2"></i>Complete &lt;head&gt; HTML — Paste into your Website</span>
    <button class="btn btn-sm btn-warning" onclick="copyMetaHtml()"><i class="fas fa-copy me-1"></i>Copy All</button>
  </div>
  <div class="card-body p-0">
    <textarea id="metaHeadHtml" class="form-control font-monospace border-0" rows="18" readonly><?= htmlspecialchars($savedMeta['full_head_html']) ?></textarea>
  </div>
  <div class="card-footer small text-muted">
    <strong>Where to paste:</strong> WordPress → Theme / Yoast SEO → HTML in your site's <code>header.php</code> or page builder Custom HTML in &lt;head&gt;
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php endif; ?>
