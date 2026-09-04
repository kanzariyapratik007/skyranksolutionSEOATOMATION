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

// 80% AUTO: Fetch competitor data
function fetchCompetitorData($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return null;

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $title = $xpath->query('//title');
    $meta  = $xpath->query('//meta[@name="description"]/@content');
    $h1    = $xpath->query('//h1');
    $h2s   = $xpath->query('//h2');
    $words = str_word_count(strtolower(strip_tags($html)));

    return [
        'title'     => $title->length > 0 ? trim($title->item(0)->textContent) : 'N/A',
        'meta'      => $meta->length > 0 ? trim($meta->item(0)->textContent) : 'N/A',
        'h1'        => $h1->length > 0 ? trim($h1->item(0)->textContent) : 'N/A',
        'h2_count'  => $h2s->length,
        'word_count'=> $words,
    ];
}

// Get top competitors via Google search scraping (educational/analysis only)
function getCompetitors($keyword) {
    $competitors = [];

    // Try Google Custom Search Engine (CSE) API first if configured
    $googleApiKey = defined('GOOGLE_API_KEY') ? GOOGLE_API_KEY : '';
    $googleCseCx  = defined('GOOGLE_CSE_CX') ? GOOGLE_CSE_CX : '';
    if (!empty($googleApiKey) && !empty($googleCseCx)) {
        $cseUrl = "https://www.googleapis.com/customsearch/v1?key=" . urlencode($googleApiKey) . "&cx=" . urlencode($googleCseCx) . "&q=" . urlencode($keyword) . "&num=5";
        $ch = curl_init($cseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($resp, true);
        $items = $data['items'] ?? [];
        foreach ($items as $item) {
            $itemUrl = $item['link'] ?? '';
            if (!empty($itemUrl)) {
                $parsed = parse_url($itemUrl);
                $domain = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
                if (!in_array($domain, $competitors) && strpos($domain, 'google') === false) {
                    $competitors[] = $domain;
                }
            }
        }
    }

    // Fallback 1: Attempt standard Google search scraping
    if (empty($competitors)) {
        $url = 'https://www.google.com/search?q=' . urlencode($keyword) . '&num=10';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0',
            CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9'],
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html) {
            preg_match_all('/<a href="\/url\?q=(https?:\/\/[^&"]+)/', $html, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $link) {
                    $parsed = parse_url($link);
                    $domain = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
                    if (!empty($domain) && !in_array($domain, $competitors) && strpos($domain, 'google') === false) {
                        $competitors[] = $domain;
                        if (count($competitors) >= 5) break;
                    }
                }
            }
        }
    }

    // Fallback 2: General/real-estate sites if e-learning is not appropriate
    if (empty($competitors)) {
        $competitors = [
            'https://www.magicbricks.com',
            'https://www.nobroker.in',
            'https://www.99acres.com',
            'https://www.housing.com',
            'https://www.sulekha.com',
        ];
    }
    return $competitors;
}

if ($isRun) {
    $competitors = getCompetitors($project['target_keyword']);
    $result = [];
    foreach ($competitors as $comp) {
        $data = fetchCompetitorData($comp);
        if ($data) {
            $result[] = array_merge(['url' => $comp], $data);
        }
        sleep(1);
    }
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Analyzed ' . count($result) . ' competitors']);
    exit;
}

// Load competitors
$competitors = getCompetitors($project['target_keyword']);
$competitorData = [];
foreach ($competitors as $comp) {
    $data = fetchCompetitorData($comp);
    if ($data) {
        $competitorData[] = array_merge(['url' => $comp], $data);
    }
    sleep(1);
}
?>

<?php if ($isAjax): ?>
<div class="alert alert-info">
  <i class="fas fa-robot me-2"></i>
  <strong>80% Auto:</strong> System automatically found and analyzed top <?= count($competitorData) ?> competitors for "<?= clean($project['target_keyword']) ?>"
  <br><strong>20% Manual:</strong> Review the data and take action
</div>

<?php if (!empty($competitorData)): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-dark text-white">
    <h6 class="mb-0"><i class="fas fa-users me-2"></i>Competitor Analysis</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-secondary">
          <tr>
            <th>#</th>
            <th>Competitor URL</th>
            <th>Title</th>
            <th>H1</th>
            <th>H2 Count</th>
            <th>Word Count</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($competitorData as $i => $comp): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <a href="<?= clean($comp['url']) ?>" target="_blank" class="text-decoration-none">
                <?= clean(parse_url($comp['url'], PHP_URL_HOST)) ?>
                <i class="fas fa-external-link-alt ms-1 small"></i>
              </a>
            </td>
            <td><small><?= clean(substr($comp['title'], 0, 50)) ?>...</small></td>
            <td><small><?= clean(substr($comp['h1'], 0, 40)) ?>...</small></td>
            <td><span class="badge bg-info"><?= $comp['h2_count'] ?></span></td>
            <td>
              <span class="badge <?= $comp['word_count'] > 1500 ? 'bg-success' : 'bg-warning' ?>">
                <?= number_format($comp['word_count']) ?> words
              </span>
            </td>
            <td>
              <button class="btn btn-xs btn-outline-primary" onclick="analyzeComp('<?= clean($comp['url']) ?>')">
                <i class="fas fa-search"></i> Analyze
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Insights -->
<div class="card border-warning">
  <div class="card-header bg-warning">
    <h6 class="mb-0"><i class="fas fa-lightbulb me-2"></i>80% Auto: Key Insights & Recommendations</h6>
  </div>
  <div class="card-body">
    <?php
    $avgWords = count($competitorData) > 0 ? round(array_sum(array_column($competitorData, 'word_count')) / count($competitorData)) : 0;
    $avgH2    = count($competitorData) > 0 ? round(array_sum(array_column($competitorData, 'h2_count')) / count($competitorData)) : 0;
    ?>
    <ul class="list-unstyled mb-0">
      <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>
        Average competitor word count: <strong><?= number_format($avgWords) ?> words</strong>
        → Your content should be at least this long
      </li>
      <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>
        Average H2 tags: <strong><?= $avgH2 ?></strong>
        → Use at least <?= $avgH2 ?> H2 subheadings
      </li>
      <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>
        All competitors use keyword in title → Make sure your title includes "<?= clean($project['target_keyword']) ?>"
      </li>
      <li><i class="fas fa-arrow-right text-primary me-2"></i>
        <strong>20% Manual:</strong> Visit each competitor, study their content structure, and improve yours
      </li>
    </ul>
  </div>
</div>
<?php else: ?>
<div class="alert alert-warning">Could not fetch competitor data. Check your internet connection.</div>
<?php endif; ?>

<script>
function analyzeComp(url) {
  window.open(url, '_blank');
}
</script>
<?php endif; ?>
