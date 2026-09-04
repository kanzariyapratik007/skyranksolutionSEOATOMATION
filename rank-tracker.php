<?php
require_once 'config.php';
requireLogin();
$db = getDB();
$projectId = (int)($_GET['id'] ?? 0);
$isAjax = isset($_GET['ajax']);
$isRun  = isset($_GET['run']);

$stmt = $db->prepare("SELECT * FROM projects WHERE id=? AND user_id=?");
$stmt->execute([$projectId, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) { echo json_encode(['error' => 'Not found']); exit; }

// 100% AUTO: Check Google rank using DataForSEO API (FREE 100/day)
if (!function_exists('checkGoogleRank')) {
    function checkGoogleRank($keyword, $targetSite) {
        $domain = parse_url($targetSite, PHP_URL_HOST) ?: $targetSite;
        $domain = str_replace('www.', '', strtolower($domain));

        // ── Method 0: Google Custom Search Engine (CSE) API (Official & 100% Free 100/day) ──
        $googleApiKey = defined('GOOGLE_API_KEY') ? GOOGLE_API_KEY : '';
        $googleCseCx  = defined('GOOGLE_CSE_CX') ? GOOGLE_CSE_CX : '';
        if (!empty($googleApiKey) && !empty($googleCseCx)) {
            $cseUrl = "https://www.googleapis.com/customsearch/v1?key=" . urlencode($googleApiKey) . "&cx=" . urlencode($googleCseCx) . "&q=" . urlencode($keyword) . "&num=10";
            $ch = curl_init($cseUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($resp, true);
            
            // Check for API errors
            if (isset($data['error'])) {
                error_log("Google CSE API Error: " . json_encode($data['error']));
                // Continue to next method
            } else {
                $items = $data['items'] ?? [];
                foreach ($items as $pos => $item) {
                    $itemUrl = $item['link'] ?? '';
                    $ld = str_replace('www.', '', strtolower(parse_url($itemUrl, PHP_URL_HOST) ?: ''));
                    if (stripos($ld, $domain) !== false) {
                        return $pos + 1;
                    }
                }
            }
        }

        // ── Method 1: DataForSEO SERP API (most accurate) ────────
        $login    = defined('DATAFORSEO_LOGIN')    ? DATAFORSEO_LOGIN    : '';
        $password = defined('DATAFORSEO_PASSWORD') ? DATAFORSEO_PASSWORD : '';

        if ($login && $password) {
            $postData = json_encode([[
                'keyword'       => $keyword,
                'location_code' => 2356,   // India
                'language_code' => 'en',
                'device'        => 'desktop',
                'os'            => 'windows',
                'depth'         => 100,
            ]]);

            $ch = curl_init('https://api.dataforseo.com/v3/serp/google/organic/live/advanced');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postData,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . base64_encode($login . ':' . $password),
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($resp, true);
            $items = $data['tasks'][0]['result'][0]['items'] ?? [];

            foreach ($items as $item) {
                if (($item['type'] ?? '') !== 'organic') continue;
                $itemUrl    = $item['url'] ?? '';
                $itemDomain = parse_url($itemUrl, PHP_URL_HOST) ?: '';
                $itemDomain = str_replace('www.', '', strtolower($itemDomain));
                if (stripos($itemDomain, $domain) !== false ||
                    stripos($itemUrl, $domain) !== false) {
                    return (int)($item['rank_absolute'] ?? ($item['rank_group'] ?? 0));
                }
            }

            // If API returned results but domain not found → not in top 100
            if (!empty($items)) return 0;
        }

        // ── Method 2: Bing scraping fallback ─────────────────────
        $bingUrl = 'https://www.bing.com/search?q=' . urlencode($keyword) . '&count=50';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $bingUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html) {
            preg_match_all('/<h2><a[^>]+href="([^"]+)"/i', $html, $m);
            $links = $m[1] ?? [];
            if (empty($links)) {
                preg_match_all('/href="([^"]+)"/i', $html, $m2);
                $links = $m2[1] ?? [];
            }
            
            $pos = 1;
            foreach ($links as $link) {
                if (strpos($link, 'http') !== 0) continue;
                if (strpos($link, 'bing.com') !== false || 
                    strpos($link, 'microsoft.com') !== false || 
                    strpos($link, 'live.com') !== false || 
                    strpos($link, 'go.microsoft.com') !== false) {
                    continue;
                }
                
                $ld = str_replace('www.', '', strtolower(parse_url($link, PHP_URL_HOST) ?: ''));
                if (stripos($ld, $domain) !== false) {
                    return $pos;
                }
                $pos++;
            }
        }

        return 0;
    }
}

if ($isRun || $isAjax) {
    $targetSite = $project['target_site'] ?: $project['website_url'];
    // Clean duplicate words from keyword before checking rank
    require_once __DIR__ . '/ai-content.php';
    $cleanedKeyword = substr(cleanKeyword($project['target_keyword']), 0, 255);
    $rank = checkGoogleRank($cleanedKeyword, $targetSite);

    // Save to DB
    $today = date('Y-m-d');
    $existing = $db->prepare("SELECT id FROM seo_reports WHERE project_id=? AND report_date=?");
    $existing->execute([$projectId, $today]);
    if ($existing->fetch()) {
        $db->prepare("UPDATE seo_reports SET `rank`=? WHERE project_id=? AND report_date=?")
           ->execute([$rank, $projectId, $today]);
    } else {
        $db->prepare("INSERT INTO seo_reports (project_id, `rank`, report_date) VALUES (?,?,?)")
           ->execute([$projectId, $rank, $today]);
    }
    $db->prepare("INSERT INTO rank_history (project_id, keyword, rank_position) VALUES (?,?,?)")
       ->execute([$projectId, $cleanedKeyword, $rank]);

    if ($isRun) {
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Rank checked: ' . ($rank > 0 ? '#' . $rank : 'Not in top 100')]);
        exit;
    }
}

// Fetch rank history for chart
$history = $db->prepare("SELECT DATE(checked_at) as date, MIN(rank_position) as rank FROM rank_history WHERE project_id=? AND rank_position > 0 GROUP BY DATE(checked_at) ORDER BY date DESC LIMIT 30");
$history->execute([$projectId]);
$history = array_reverse($history->fetchAll());

$latestRank = $db->prepare("SELECT `rank` FROM seo_reports WHERE project_id=? AND `rank` > 0 ORDER BY report_date DESC LIMIT 1");
$latestRank->execute([$projectId]);
$latestRank = $latestRank->fetchColumn() ?: 0;
?>

<!-- HTML Content (Remains Same) -->
<?php if ($isAjax): ?>
<div class="row mb-3">
  <div class="col-md-3">
    <div class="card text-center border-primary">
      <div class="card-body">
        <h2 class="text-primary"><?= $latestRank > 0 ? '#' . $latestRank : 'N/A' ?></h2>
        <p>Current Rank</p>
        <small class="text-muted">100% Auto: Checked automatically</small>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-center border-success">
      <div class="card-body">
        <h2 class="text-success"><?= count($history) ?></h2>
        <p>Days Tracked</p>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card border-info">
      <div class="card-body">
        <h6>Keyword: <strong><?= clean(cleanKeyword($project['target_keyword'])) ?></strong></h6>
        <p class="mb-1">Target: <strong><?= clean($project['target_site'] ?: $project['website_url']) ?></strong></p>
        <button class="btn btn-sm btn-primary" onclick="checkRankNow(this)">
          <i class="fas fa-sync me-2"></i>Check Rank Now (100% Auto)
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Rank Chart -->
<?php if (!empty($history)): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header">
    <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Rank History (Lower = Better)</h6>
  </div>
  <div class="card-body">
    <canvas id="rankChart" height="100"></canvas>
  </div>
</div>
<script>
const ctx = document.getElementById('rankChart').getContext('2d');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($history, 'date')) ?>,
    datasets: [{
      label: 'Google Rank Position',
      data: <?= json_encode(array_column($history, 'rank')) ?>,
      borderColor: '#0d6efd',
      backgroundColor: 'rgba(13,110,253,0.1)',
      tension: 0.4,
      fill: true,
    }]
  },
  options: {
    responsive: true,
    scales: {
      y: { reverse: true, title: { display: true, text: 'Rank Position (lower is better)' } }
    }
  }
});
</script>
<?php else: ?>
<div class="alert alert-info">
  <i class="fas fa-info-circle me-2"></i>No rank history yet. Click "Check Rank Now" to start tracking.
</div>
<?php endif; ?>

<!-- Rank History Table -->
<?php if (!empty($history)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header"><h6 class="mb-0">Rank History</h6></div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Date</th><th>Rank</th><th>Change</th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($history) as $i => $h): ?>
        <?php
        $prev = isset($history[count($history) - $i - 2]) ? $history[count($history) - $i - 2]['rank'] : null;
        $change = $prev ? $prev - $h['rank'] : 0;
        ?>
        <tr>
          <td><?= $h['date'] ?></td>
          <td><span class="badge <?= $h['rank'] <= 10 ? 'bg-success' : ($h['rank'] <= 30 ? 'bg-warning' : 'bg-danger') ?>">#<?= $h['rank'] ?></span></td>
          <td>
            <?php if ($change > 0): ?>
              <span class="text-success"><i class="fas fa-arrow-up"></i> +<?= $change ?></span>
            <?php elseif ($change < 0): ?>
              <span class="text-danger"><i class="fas fa-arrow-down"></i> <?= $change ?></span>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
function checkRankNow(btn) {
  if (!btn) btn = document.querySelector('[onclick*="checkRankNow"]');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking Google rank...';

  fetch('rank-tracker.php?id=<?= $projectId ?>&run=1', {
    credentials: 'same-origin',
    signal: AbortSignal.timeout(120000)
  })
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-sync me-2"></i>Check Rank Now (100% Auto)';
      if (data.message) {
        showToast(data.message, 'success');
      }
      // Reload rank section
      setTimeout(() => {
        const tab = document.querySelector('[data-tab="rank"]') || document.querySelector('[onclick*="rank"]');
        if (tab) tab.click();
        else location.reload();
      }, 1500);
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-sync me-2"></i>Check Rank Now (100% Auto)';
      showToast('Error: ' + err.message, 'danger');
    });
}

function showToast(msg, type) {
  const t = document.createElement('div');
  t.className = 'alert alert-' + (type||'info') + ' position-fixed top-0 end-0 m-3';
  t.style.zIndex = 9999;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}
</script>
<?php endif; ?>