<?php
require_once 'config.php';
require_once 'ai-content.php';
requireLogin();
$db = getDB();

// Self-healing migration for PageSpeed cache
try {
    $db->exec("ALTER TABLE projects ADD COLUMN pagespeed_score INT DEFAULT NULL");
} catch (PDOException $e) {}

$projectId = (int)($_GET['id'] ?? 0);
$isAjax = isset($_GET['ajax']);
$isRun  = isset($_GET['run']);

$stmt = $db->prepare("SELECT * FROM projects WHERE id=? AND user_id=?");
$stmt->execute([$projectId, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) { echo json_encode(['error' => 'Not found']); exit; }

// ============================================================
// CORE: Fetch and analyze website HTML (80% AUTO)
// ============================================================
function getPageSpeedScoreLive($url) {
    // Google PageSpeed Insights API (mobile strategy for mobile-first index)
    $apiUrl = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=" . urlencode($url) . "&category=performance&strategy=mobile";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response) {
        $data = json_decode($response, true);
        $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
        if ($score !== null) {
            return round($score * 100);
        }
    }
    return null;
}

function analyzeWebsite($url, $keyword, $pagespeedScore = null) {
    $issues = [];
    $score  = 100;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $httpCode !== 200) {
        return ['error' => 'Could not fetch website (HTTP ' . $httpCode . ')', 'score' => 0, 'issues' => []];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // 1. Title tag
    $titles = $xpath->query('//title');
    $title  = $titles->length > 0 ? trim($titles->item(0)->textContent) : '';
    if (empty($title)) {
        $issues[] = ['type' => 'title', 'detail' => 'Missing title tag', 'severity' => 'critical',
            'fix' => '<title>' . ucwords($keyword) . ' | Your Brand</title>'];
        $score -= 20;
    } elseif (stripos($title, $keyword) === false) {
        $issues[] = ['type' => 'title', 'detail' => 'Title does not contain keyword: "' . $keyword . '"', 'severity' => 'high',
            'fix' => '<title>' . ucwords($keyword) . ' - ' . $title . '</title>'];
        $score -= 10;
    } elseif (strlen($title) < 30 || strlen($title) > 60) {
        $issues[] = ['type' => 'title', 'detail' => 'Title length is ' . strlen($title) . ' chars (ideal: 30-60)', 'severity' => 'medium',
            'fix' => 'Adjust title to 30-60 characters including keyword.'];
        $score -= 5;
    }

    // 2. Meta description
    $metas = $xpath->query('//meta[@name="description"]/@content');
    $metaDesc = $metas->length > 0 ? trim($metas->item(0)->textContent) : '';
    if (empty($metaDesc)) {
        $issues[] = ['type' => 'meta_description', 'detail' => 'Missing meta description', 'severity' => 'critical',
            'fix' => '<meta name="description" content="Best ' . $keyword . '. Learn from experts. Enroll now!">'];
        $score -= 15;
    } elseif (stripos($metaDesc, $keyword) === false) {
        $issues[] = ['type' => 'meta_description', 'detail' => 'Meta description missing keyword', 'severity' => 'high',
            'fix' => '<meta name="description" content="' . ucfirst($keyword) . ' - ' . substr($metaDesc, 0, 100) . '">'];
        $score -= 8;
    } elseif (strlen($metaDesc) < 120 || strlen($metaDesc) > 160) {
        $issues[] = ['type' => 'meta_description', 'detail' => 'Meta description length: ' . strlen($metaDesc) . ' (ideal: 120-160)', 'severity' => 'medium',
            'fix' => 'Adjust meta description to 120-160 characters.'];
        $score -= 5;
    }

    // 3. H1 tag
    $h1s = $xpath->query('//h1');
    if ($h1s->length === 0) {
        $issues[] = ['type' => 'h1', 'detail' => 'No H1 tag found', 'severity' => 'critical',
            'fix' => '<h1>' . ucwords($keyword) . '</h1>'];
        $score -= 15;
    } elseif ($h1s->length > 1) {
        $issues[] = ['type' => 'h1', 'detail' => 'Multiple H1 tags found (' . $h1s->length . ')', 'severity' => 'medium',
            'fix' => 'Keep only one H1 tag per page.'];
        $score -= 5;
    } else {
        $h1Text = trim($h1s->item(0)->textContent);
        if (stripos($h1Text, $keyword) === false) {
            $issues[] = ['type' => 'h1', 'detail' => 'H1 does not contain keyword. Current: "' . substr($h1Text, 0, 60) . '"', 'severity' => 'high',
                'fix' => '<h1>' . ucwords($keyword) . '</h1>'];
            $score -= 10;
        }
    }

    // 4. H2 tags
    $h2s = $xpath->query('//h2');
    if ($h2s->length === 0) {
        $issues[] = ['type' => 'h2', 'detail' => 'No H2 tags found', 'severity' => 'low',
            'fix' => 'Add H2 subheadings with related keywords.'];
        $score -= 3;
    }

    // 5. Images without alt text
    $images = $xpath->query('//img');
    $noAlt  = 0;
    foreach ($images as $img) {
        $alt = $img->getAttribute('alt');
        if (empty(trim($alt))) $noAlt++;
    }
    if ($noAlt > 0) {
        $issues[] = ['type' => 'images', 'detail' => $noAlt . ' image(s) missing alt text', 'severity' => 'medium',
            'fix' => 'Add alt="' . $keyword . '" to images related to your keyword.'];
        $score -= min(10, $noAlt * 2);
    }

    // 6. Keyword density
    $bodyText = strtolower(strip_tags($html));
    $wordCount = str_word_count($bodyText);
    $kwCount   = substr_count($bodyText, strtolower($keyword));
    $density   = $wordCount > 0 ? round(($kwCount / $wordCount) * 100, 2) : 0;
    if ($density < 0.5) {
        $issues[] = ['type' => 'keyword_density', 'detail' => 'Keyword density too low: ' . $density . '% (ideal: 1-2%)', 'severity' => 'medium',
            'fix' => 'Add keyword "' . $keyword . '" naturally in content. Current count: ' . $kwCount . ' in ' . $wordCount . ' words.'];
        $score -= 8;
    } elseif ($density > 3) {
        $issues[] = ['type' => 'keyword_density', 'detail' => 'Keyword density too high: ' . $density . '% (may be penalized)', 'severity' => 'high',
            'fix' => 'Reduce keyword usage. Current: ' . $kwCount . ' times in ' . $wordCount . ' words.'];
        $score -= 10;
    }

    // 7. Canonical tag
    $canonical = $xpath->query('//link[@rel="canonical"]/@href');
    if ($canonical->length === 0) {
        $issues[] = ['type' => 'canonical', 'detail' => 'Missing canonical tag', 'severity' => 'medium',
            'fix' => '<link rel="canonical" href="' . $url . '">'];
        $score -= 5;
    }

    // 8. Schema markup
    if (stripos($html, 'application/ld+json') === false && stripos($html, 'itemtype') === false) {
        $issues[] = ['type' => 'schema', 'detail' => 'No schema markup found', 'severity' => 'low',
            'fix' => '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage","name":"' . ucwords($keyword) . '","description":"Best ' . $keyword . ' course"}</script>'];
        $score -= 5;
    }

    // 9. Robots meta
    $robots = $xpath->query('//meta[@name="robots"]/@content');
    if ($robots->length > 0 && stripos($robots->item(0)->textContent, 'noindex') !== false) {
        $issues[] = ['type' => 'robots', 'detail' => 'Page has noindex meta tag!', 'severity' => 'critical',
            'fix' => 'Remove noindex from meta robots tag.'];
        $score -= 25;
    }

    // 10. Robots.txt check
    $parsedUrl = parse_url($url);
    $baseUrl = ($parsedUrl['scheme'] ?? 'http') . '://' . ($parsedUrl['host'] ?? '');
    
    $robotsUrl = rtrim($baseUrl, '/') . '/robots.txt';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $robotsUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $robotsContent = curl_exec($ch);
    $robotsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $robotsFound = ($robotsHttpCode === 200);
    $sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';
    if ($robotsFound && !empty($robotsContent)) {
        if (preg_match('/sitemap:\s*(https?:\/\/[^\s]+)/i', $robotsContent, $matches)) {
            $sitemapUrl = trim($matches[1]);
        }
    } else {
        $issues[] = [
            'type' => 'robots',
            'detail' => 'robots.txt file not found or inaccessible (HTTP ' . $robotsHttpCode . ')',
            'severity' => 'high',
            'fix' => "Create a robots.txt file in the root directory and add the following lines:\nUser-agent: *\nAllow: /\nSitemap: " . $sitemapUrl
        ];
        $score -= 10;
    }
    
    // 11. Sitemap check
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $sitemapUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $sitemapContent = curl_exec($ch);
    $sitemapHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $sitemapFound = ($sitemapHttpCode === 200);
    if ($sitemapFound && !empty($sitemapContent)) {
        $xml = @simplexml_load_string($sitemapContent);
        if ($xml === false) {
            $issues[] = [
                'type' => 'sitemap',
                'detail' => 'sitemap.xml has invalid XML structure',
                'severity' => 'critical',
                'fix' => 'Check your sitemap generator settings and generate a clean valid XML sitemap.'
            ];
            $score -= 10;
        }
    } else {
        $issues[] = [
            'type' => 'sitemap',
            'detail' => 'sitemap.xml file not found or inaccessible (HTTP ' . $sitemapHttpCode . ')',
            'severity' => 'high',
            'fix' => 'Generate a sitemap.xml file and upload it to the root directory of your website.'
        ];
        $score -= 15;
    }

    // 12. PageSpeed Performance check
    if ($pagespeedScore !== null) {
        if ($pagespeedScore < 50) {
            $issues[] = [
                'type' => 'pagespeed',
                'detail' => 'Mobile PageSpeed score is critical: ' . $pagespeedScore . '/100',
                'severity' => 'critical',
                'fix' => 'Optimize site resources. Resize large images, convert to WebP, defer non-essential Javascript, and leverage local browser caching.'
            ];
            $score -= 20;
        } elseif ($pagespeedScore < 90) {
            $issues[] = [
                'type' => 'pagespeed',
                'detail' => 'Mobile PageSpeed score needs improvement: ' . $pagespeedScore . '/100',
                'severity' => 'medium',
                'fix' => 'Enable Gzip/Brotli compression, minify CSS/JS, and implement lazy loading for below-the-fold images.'
            ];
            $score -= 10;
        }
    }

    return [
        'score'          => max(0, $score),
        'issues'         => $issues,
        'title'          => $title,
        'meta_desc'      => $metaDesc,
        'h1'             => $h1s->length > 0 ? trim($h1s->item(0)->textContent) : '',
        'h2_count'       => $h2s->length,
        'images'         => $images->length,
        'no_alt'         => $noAlt,
        'word_count'     => $wordCount,
        'kw_density'     => $density,
        'kw_count'       => $kwCount,
        'robots_found'   => $robotsFound,
        'sitemap_found'  => $sitemapFound,
        'pagespeed'      => $pagespeedScore,
    ];
}

// Handle run=1 (AJAX auto-run)
if ($isRun) {
    $url = !empty($_GET['target_site']) ? clean($_GET['target_site']) : ($project['target_site'] ?: $project['website_url']);
    $url = trim(explode(',', $url)[0]);
    
    // Fetch live PageSpeed score
    $pagespeedScore = getPageSpeedScoreLive($url);
    if ($pagespeedScore !== null) {
        $db->prepare("UPDATE projects SET pagespeed_score=? WHERE id=?")->execute([$pagespeedScore, $projectId]);
    } else {
        $pagespeedScore = $project['pagespeed_score'] ?? null;
    }

    $keyword = !empty($_GET['keyword']) ? clean($_GET['keyword']) : $project['target_keyword'];
    $keyword = trim(explode(',', $keyword)[0]);
    $result = analyzeWebsite($url, $keyword, $pagespeedScore);

    if (!isset($result['error'])) {
        // Use ChatGPT to generate better fix suggestions
        if (!empty($result['issues'])) {
            $issueList = implode("\n", array_map(fn($i) => "- {$i['type']}: {$i['detail']}", $result['issues']));
            $aiPrompt = "I have these SEO issues on website {$url} for keyword '{$project['target_keyword']}':
{$issueList}

For each issue, provide a specific HTML fix code. Be concise and practical.
Format: ISSUE_TYPE: <fix code here>";
            $aiFix = generateWithAI($aiPrompt);
            if ($aiFix['text']) {
                foreach ($result['issues'] as &$issue) {
                    if (preg_match('/' . preg_quote($issue['type'], '/') . ':\s*(.+)/i', $aiFix['text'], $m)) {
                        $issue['fix'] = trim($m[1]) ?: $issue['fix'];
                    }
                }
            }
        }

        $db->prepare("DELETE FROM onpage_issues WHERE project_id=?")->execute([$projectId]);
        foreach ($result['issues'] as $issue) {
            $db->prepare("INSERT INTO onpage_issues (project_id, issue_type, issue_detail, fix_code, status) VALUES (?,?,?,?,'open')")
               ->execute([$projectId, $issue['type'], $issue['detail'], $issue['fix']]);
        }
        $today = date('Y-m-d');
        $existing = $db->prepare("SELECT id FROM seo_reports WHERE project_id=? AND report_date=?");
        $existing->execute([$projectId, $today]);
        if ($existing->fetch()) {
            $db->prepare("UPDATE seo_reports SET seo_score=? WHERE project_id=? AND report_date=?")
               ->execute([$result['score'], $projectId, $today]);
        } else {
            $db->prepare("INSERT INTO seo_reports (project_id, seo_score, report_date) VALUES (?,?,?)")
               ->execute([$projectId, $result['score'], $today]);
        }
        $db->prepare("UPDATE projects SET seo_score=? WHERE id=?")->execute([$result['score'], $projectId]);
    }
    header('Content-Type: application/json');
    echo json_encode(['message' => 'On-page analysis complete (ChatGPT fixes). Found ' . count($result['issues'] ?? []) . ' issues. Score: ' . ($result['score'] ?? 0)]);
    exit;
}

// Handle approve fix (Auto-fix with Selenium if WP credentials exist)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_issue'])) {
    $issueType = clean($_POST['issue_type'] ?? '');
    $fixCode   = $_POST['fix_code'] ?? '';
    
    // Check if WordPress credentials exist in CRM
    $wpUrl         = trim($project['admin_url'] ?? '');
    $wpUser        = trim($project['admin_user'] ?? '');
    $wpPassEncoded = trim($project['admin_pass'] ?? '');
    
    $autoFixed = false;
    $errorMsg  = '';
    
    if (!empty($wpUrl) && !empty($wpUser) && !empty($wpPassEncoded) && ($issueType === 'title' || $issueType === 'meta_description')) {
        // Run Selenium Auto-Fixer
        require_once __DIR__ . '/selenium/selenium-bridge.php';
        
        // Extract raw text value from HTML tag (e.g. <title>Text</title> or content="Text")
        $val = $fixCode;
        if ($issueType === 'title') {
            if (preg_match('/<title>(.*)<\/title>/i', $fixCode, $matches)) {
                $val = trim($matches[1]);
            }
        } elseif ($issueType === 'meta_description') {
            if (preg_match('/content=["\'](.*)["\']/i', $fixCode, $matches)) {
                $val = trim($matches[1]);
            }
        }
        
        $res = seleniumWpAutoFix($projectId, $wpUrl, $wpUser, $wpPassEncoded, $issueType, $val);
        if ($res['success'] ?? false) {
            $autoFixed = true;
        } else {
            $errorMsg = $res['error'] ?? 'Selenium auto-fix execution failed';
        }
    }
    
    // Re-check and update status in DB
    $db->prepare("UPDATE onpage_issues SET status='approved' WHERE project_id=? AND issue_type=?")
       ->execute([$projectId, $issueType]);
    
    echo json_encode([
        'success'    => true, 
        'auto_fixed' => $autoFixed, 
        'error'      => $errorMsg
    ]);
    exit;
}

// Load analysis for display (uses cached PageSpeed score to stay super fast)
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

$pagespeedScore = $project['pagespeed_score'] ?? null;
$result = analyzeWebsite($url, $keyword, $pagespeedScore);

// Save to DB on page load too
if (!isset($result['error'])) {
    $db->prepare("DELETE FROM onpage_issues WHERE project_id=?")->execute([$projectId]);
    foreach ($result['issues'] as $issue) {
        $db->prepare("INSERT INTO onpage_issues (project_id, issue_type, issue_detail, fix_code, status) VALUES (?,?,?,?,'open')")
           ->execute([$projectId, $issue['type'], $issue['detail'], $issue['fix']]);
    }
    $today = date('Y-m-d');
    $existing = $db->prepare("SELECT id FROM seo_reports WHERE project_id=? AND report_date=?");
    $existing->execute([$projectId, $today]);
    if ($existing->fetch()) {
        $db->prepare("UPDATE seo_reports SET seo_score=? WHERE project_id=? AND report_date=?")
           ->execute([$result['score'], $projectId, $today]);
    } else {
        $db->prepare("INSERT INTO seo_reports (project_id, seo_score, report_date) VALUES (?,?,?)")
           ->execute([$projectId, $result['score'], $today]);
    }
    $db->prepare("UPDATE projects SET seo_score=? WHERE id=?")->execute([$result['score'], $projectId]);
}

$severityColors = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'secondary'];
?>

<?php if ($isAjax): ?>
<!-- AJAX content only -->
  <!-- Keyword & Target Page URL Selectors for On-Page Audit -->
  <div class="card mb-4 border-info shadow-sm bg-light">
    <div class="card-body py-2 px-3 d-flex align-items-center flex-wrap gap-3">
      <div class="d-flex align-items-center gap-2">
        <strong class="small text-muted mb-0"><i class="fas fa-key me-1"></i> Audit Keyword:</strong>
        <select id="auditKeywordSelect" class="form-select form-select-sm" style="width: auto; min-width: 220px;" onchange="updateAuditSelection()">
          <?php foreach ($keywordsList as $kw): ?>
            <option value="<?= htmlspecialchars($kw, ENT_QUOTES, 'UTF-8') ?>" <?= $currentKeyword === $kw ? 'selected' : '' ?>><?= htmlspecialchars($kw) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="d-flex align-items-center gap-2">
        <strong class="small text-muted mb-0"><i class="fas fa-link me-1"></i> Audit Target Page URL:</strong>
        <select id="auditUrlSelect" class="form-select form-select-sm" style="width: auto; min-width: 320px;" onchange="updateAuditSelection()">
          <?php foreach ($targetSitesList as $url): ?>
            <option value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" <?= $currentTargetSite === $url ? 'selected' : '' ?>><?= htmlspecialchars($url) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary btn-sm ms-auto" onclick="runAuditNow(this)">
        <i class="fas fa-sync-alt me-1"></i>Run Audit
      </button>
    </div>
  </div>

<div class="row mb-3">
  <div class="col-md-4">
    <div class="card text-center border-0 shadow-sm">
      <div class="card-body">
        <?php if (isset($result['error'])): ?>
          <div class="alert alert-danger"><?= clean($result['error']) ?></div>
        <?php else: ?>
          <div class="seo-score-circle <?= $result['score'] >= 70 ? 'good' : ($result['score'] >= 40 ? 'average' : 'poor') ?>">
            <?= $result['score'] ?>
          </div>
          <p class="mt-2 fw-bold">SEO Score</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h6>Page Analysis Summary</h6>
        <?php if (!isset($result['error'])): ?>
        <table class="table table-sm">
          <tr><td>Title</td><td><?= clean(substr($result['title'], 0, 60)) ?: '<span class="text-danger">Missing</span>' ?></td></tr>
          <tr><td>Meta Description</td><td><?= clean(substr($result['meta_desc'], 0, 80)) ?: '<span class="text-danger">Missing</span>' ?></td></tr>
          <tr><td>H1 Tag</td><td><?= clean(substr($result['h1'], 0, 60)) ?: '<span class="text-danger">Missing</span>' ?></td></tr>
          <tr><td>H2 Tags</td><td><?= $result['h2_count'] ?></td></tr>
          <tr><td>Images / Missing Alt</td><td><?= $result['images'] ?> / <span class="text-danger"><?= $result['no_alt'] ?></span></td></tr>
          <tr><td>Keyword Density</td><td><?= $result['kw_density'] ?>% (<?= $result['kw_count'] ?> times in <?= $result['word_count'] ?> words)</td></tr>
          <tr><td>Mobile PageSpeed Score</td><td><?= isset($result['pagespeed']) ? '<strong>' . $result['pagespeed'] . '/100</strong>' : '<span class="text-muted">Not tested (click "Run Audit" to scan)</span>' ?></td></tr>
          <tr><td>Robots.txt Status</td><td><?= $result['robots_found'] ? '<span class="text-success fw-bold">✅ Found</span>' : '<span class="text-danger fw-bold">❌ Missing</span>' ?></td></tr>
          <tr><td>Sitemap.xml Status</td><td><?= $result['sitemap_found'] ? '<span class="text-success fw-bold">✅ Found</span>' : '<span class="text-danger fw-bold">❌ Missing</span>' ?></td></tr>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!isset($result['error']) && !empty($result['issues'])): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-danger text-white">
    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>
      Issues Found: <?= count($result['issues']) ?> &nbsp;
      <span class="badge bg-light text-dark">20% Manual: Review & Apply Fixes</span>
    </h6>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead><tr><th>Type</th><th>Issue</th><th>Severity</th><th>Fix Code</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($result['issues'] as $i => $issue): ?>
        <tr>
          <td><span class="badge bg-secondary"><?= clean($issue['type']) ?></span></td>
          <td><?= clean($issue['detail']) ?></td>
          <td><span class="badge bg-<?= $severityColors[$issue['severity']] ?? 'secondary' ?>"><?= $issue['severity'] ?></span></td>
          <td>
            <code class="small d-block" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                  title="<?= htmlspecialchars($issue['fix']) ?>">
              <?= clean(substr($issue['fix'], 0, 60)) ?>...
            </code>
          </td>
          <td>
            <button class="btn btn-sm btn-outline-success" onclick="approveFix(this)"
                    data-type="<?= htmlspecialchars($issue['type'], ENT_QUOTES) ?>"
                    data-fix="<?= htmlspecialchars($issue['fix'], ENT_QUOTES) ?>">
              <i class="fas fa-magic me-1"></i>Approve Fix
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif (!isset($result['error'])): ?>
<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>No major issues found! SEO looks good.</div>
<?php endif; ?>
<?php endif; ?>

</body>
</html>
