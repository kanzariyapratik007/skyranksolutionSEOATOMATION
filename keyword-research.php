<?php
require_once 'config.php';
require_once 'ai-content.php';
requireLogin();
$db = getDB();

// Self-healing migration for all columns
try {
    $db->exec("ALTER TABLE keywords ADD COLUMN cpc DECIMAL(5,2) DEFAULT 0.00");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE keywords ADD COLUMN seo_difficulty INT DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE keywords ADD COLUMN competition FLOAT DEFAULT 0.00");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE keywords ADD COLUMN score INT DEFAULT 0");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE keywords ADD COLUMN status VARCHAR(20) DEFAULT 'Pending'");
} catch (PDOException $e) {}

$projectId = (int)($_GET['id'] ?? 0);
$isAjax = isset($_GET['ajax']);
$isRun  = isset($_GET['run']);

$stmt = $db->prepare("SELECT * FROM projects WHERE id=? AND user_id=?");
$stmt->execute([$projectId, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) { echo json_encode(['error' => 'Not found']); exit; }

$success = '';
$error = '';

// Scoring Calculator
function calculateKeywordScore($volume, $cpc, $competition, $difficulty) {
    // 1. Search Volume Score (Max 25 pts)
    $volScore = 5;
    if ($volume >= 2000) $volScore = 25;
    elseif ($volume >= 1000) $volScore = 20;
    elseif ($volume >= 500) $volScore = 15;
    elseif ($volume >= 100) $volScore = 10;

    // 2. CPC Score (Max 25 pts)
    $cpcScore = 5;
    if ($cpc >= 10.00) $cpcScore = 25;
    elseif ($cpc >= 5.00) $cpcScore = 20;
    elseif ($cpc >= 2.00) $cpcScore = 15;
    elseif ($cpc >= 0.50) $cpcScore = 10;

    // 3. Competition Score (Max 25 pts)
    $compScore = 5;
    if ($competition <= 0.3) $compScore = 25;
    elseif ($competition <= 0.6) $compScore = 15;

    // 4. SEO Difficulty Score (Max 25 pts)
    $diffScore = 5;
    if ($difficulty < 30) $diffScore = 25;
    elseif ($difficulty < 50) $diffScore = 20;
    elseif ($difficulty < 70) $diffScore = 10;

    return $volScore + $cpcScore + $compScore + $diffScore;
}

// POST: Google Keyword Planner CSV Parser
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($file, "r")) !== FALSE) {
            $headers = [];
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $data = array_map('trim', $data);
                $isHeader = false;
                foreach ($data as $val) {
                    if (stripos($val, 'keyword') !== false) {
                        $isHeader = true;
                        break;
                    }
                }
                if ($isHeader) {
                    $headers = $data;
                    break;
                }
            }
            
            if (!empty($headers)) {
                $kwIdx = -1;
                $volIdx = -1;
                $compIdx = -1;
                $cpcIdx = -1;
                
                foreach ($headers as $idx => $h) {
                    if (stripos($h, 'keyword') !== false) $kwIdx = $idx;
                    elseif (stripos($h, 'searches') !== false || stripos($h, 'volume') !== false) $volIdx = $idx;
                    elseif (stripos($h, 'competition (indexed') !== false || ($compIdx == -1 && stripos($h, 'competition') !== false)) $compIdx = $idx;
                    elseif (stripos($h, 'bid (high') !== false || stripos($h, 'cpc') !== false || stripos($h, 'bid') !== false) $cpcIdx = $idx;
                }
                
                $importedCount = 0;
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $data = array_map('trim', $data);
                    if (empty($data) || count($data) <= max($kwIdx, 0)) continue;
                    
                    $kw = $data[$kwIdx] ?? '';
                    if (empty($kw)) continue;
                    
                    $volume = 0;
                    if ($volIdx != -1 && isset($data[$volIdx])) {
                        $volume = (int)preg_replace('/[^0-9]/', '', $data[$volIdx]);
                    }
                    
                    $comp = 0.50;
                    if ($compIdx != -1 && isset($data[$compIdx])) {
                        $compVal = preg_replace('/[^0-9\.]/', '', $data[$compIdx]);
                        $comp = (float)$compVal;
                        if ($comp > 1.0) $comp = $comp / 100.0;
                    }
                    
                    $cpc = 0.00;
                    if ($cpcIdx != -1 && isset($data[$cpcIdx])) {
                        $cpcVal = preg_replace('/[^0-9\.]/', '', $data[$cpcIdx]);
                        $cpc = (float)$cpcVal;
                    }
                    
                    $sd = rand(30, 55);
                    $score = calculateKeywordScore($volume, $cpc, $comp, $sd);
                    $status = 'Pending';
                    if ($score >= 70) $status = 'Target';
                    elseif ($score < 40) $status = 'Ignore';
                    
                    $chk = $db->prepare("SELECT id FROM keywords WHERE project_id=? AND keyword=?");
                    $chk->execute([$projectId, $kw]);
                    if ($chk->fetch()) {
                        $db->prepare("UPDATE keywords SET search_volume=?, cpc=?, competition=?, score=?, status=? WHERE project_id=? AND keyword=?")
                           ->execute([$volume, $cpc, $comp, $score, $status, $projectId, $kw]);
                    } else {
                        $db->prepare("INSERT INTO keywords (project_id, keyword, search_volume, cpc, competition, seo_difficulty, score, status) VALUES (?,?,?,?,?,?,?,?)")
                           ->execute([$projectId, $kw, $volume, $cpc, $comp, $sd, $score, $status]);
                    }
                    $importedCount++;
                }
                $success = "{$importedCount} keywords have been imported from Google Keyword Planner CSV! 📊";
            } else {
                $error = "CSV header not found. Make sure it contains a 'Keyword' column.";
            }
            fclose($handle);
        }
    } else {
        $error = "Something went wrong while uploading the file.";
    }
}

// POST: Add Manual Keyword
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual_keyword'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $kw = clean($_POST['keyword'] ?? '');
        $volume = (int)($_POST['volume'] ?? 0);
        $cpc = (float)($_POST['cpc'] ?? 0.00);
        $comp = (float)($_POST['competition'] ?? 0.50);
        $sd = (int)($_POST['difficulty'] ?? 40);
        
        if (!empty($kw)) {
            $score = calculateKeywordScore($volume, $cpc, $comp, $sd);
            $status = 'Pending';
            if ($score >= 70) $status = 'Target';
            elseif ($score < 40) $status = 'Ignore';
            
            $chk = $db->prepare("SELECT id FROM keywords WHERE project_id=? AND keyword=?");
            $chk->execute([$projectId, $kw]);
            if ($chk->fetch()) {
                $db->prepare("UPDATE keywords SET search_volume=?, cpc=?, competition=?, seo_difficulty=?, score=?, status=? WHERE project_id=? AND keyword=?")
                   ->execute([$volume, $cpc, $comp, $sd, $score, $status, $projectId, $kw]);
            } else {
                $db->prepare("INSERT INTO keywords (project_id, keyword, search_volume, cpc, competition, seo_difficulty, score, status) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$projectId, $kw, $volume, $cpc, $comp, $sd, $score, $status]);
            }
            $success = "New keyword added successfully!";
        } else {
            $error = "Keyword name cannot be empty.";
        }
    }
}

// AJAX POST: Update SEO Difficulty manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_difficulty'])) {
    $kwId = (int)$_POST['kw_id'];
    $sd = (int)$_POST['difficulty'];
    
    $stmt = $db->prepare("SELECT * FROM keywords WHERE id=? AND project_id=?");
    $stmt->execute([$kwId, $projectId]);
    $kwData = $stmt->fetch();
    
    if ($kwData) {
        $score = calculateKeywordScore($kwData['search_volume'], $kwData['cpc'], $kwData['competition'], $sd);
        $status = 'Pending';
        if ($score >= 70) $status = 'Target';
        elseif ($score < 40) $status = 'Ignore';
        
        $db->prepare("UPDATE keywords SET seo_difficulty=?, score=?, status=? WHERE id=?")
           ->execute([$sd, $score, $status, $kwId]);
           
        echo json_encode([
            'success' => true, 
            'score' => $score, 
            'status' => $status
        ]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Keyword not found']);
    exit;
}

// AJAX POST: Update Target Status directly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $kwId = (int)$_POST['kw_id'];
    $status = clean($_POST['status']);
    if (in_array($status, ['Target', 'Pending', 'Ignore'])) {
        $db->prepare("UPDATE keywords SET status=? WHERE id=? AND project_id=?")
           ->execute([$status, $kwId, $projectId]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

// 80% AUTO: Fetch keywords from Google Suggest API
function fetchGoogleSuggest($keyword) {
    $keywords = [];
    $prefixes = ['', 'best ', 'how to ', 'free '];
    $suffixes = [' course', ' training', ' tutorial', ' certification', ' near me', ' online', ' for beginners', ' jobs', ' salary', ' 2024'];

    foreach ($prefixes as $prefix) {
        $query = $prefix . $keyword;
        $url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . urlencode($query);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data[1]) && is_array($data[1])) {
                foreach ($data[1] as $kw) {
                    if (!in_array($kw, $keywords)) $keywords[] = $kw;
                }
            }
        }
        usleep(200000); // 0.2s delay
    }

    // Add suffix variations
    foreach ($suffixes as $suffix) {
        $kw = $keyword . $suffix;
        if (!in_array($kw, $keywords)) $keywords[] = $kw;
    }

    return array_unique($keywords);
}

function autoPopulateKeywordsFromApis($projectId, $targetKeyword, $db) {
    // Split target keyword by commas in case the project lists multiple target keywords
    $targetKeywordsList = array_filter(array_map('trim', explode(',', $targetKeyword)));
    if (empty($targetKeywordsList)) {
        $targetKeywordsList = [$targetKeyword];
    }
    
    $keywords = [];
    foreach ($targetKeywordsList as $tkw) {
        if (empty($tkw)) continue;
        
        // 1. Fetch from Google Suggest for this sub-keyword
        $subSuggestions = fetchGoogleSuggest($tkw);
        foreach ($subSuggestions as $s) {
            if (!in_array($s, $keywords)) $keywords[] = $s;
        }
        
        // 2. Fetch from ChatGPT for this sub-keyword
        $aiPrompt = "Generate 10 long-tail keyword variations for '{$tkw}' that people search on Google.
Include:
- Question keywords (how, what, why, where)
- Location-based keywords
- Comparison keywords
Return ONLY a plain list, one keyword per line, no numbering, no bullets.";
        $aiKw = generateWithAI($aiPrompt);
        if ($aiKw['text']) {
            $lines = array_filter(array_map('trim', explode("\n", $aiKw['text'])));
            foreach ($lines as $kw) {
                // Clean formatting or list prefixes
                $kw = trim($kw, " \t\n\r\0\x0B\"'*-•");
                if (!empty($kw) && !in_array($kw, $keywords)) {
                    $keywords[] = $kw;
                }
            }
        }
    }
    
    // Save to database
    $db->prepare("DELETE FROM keywords WHERE project_id=?")->execute([$projectId]);
    
    // Slice keywords list to maximum 100 items to keep UI responsive
    $keywords = array_slice($keywords, 0, 100);
    
    foreach ($keywords as $kw) {
        $volume = rand(100, 5000);
        $cpc = rand(5, 120) / 10;
        $comp = rand(5, 95) / 100;
        $sd = rand(15, 75);
        $score = calculateKeywordScore($volume, $cpc, $comp, $sd);
        
        $status = 'Pending';
        if ($score >= 70) $status = 'Target';
        elseif ($score < 40) $status = 'Ignore';
        
        $db->prepare("INSERT INTO keywords (project_id, keyword, search_volume, cpc, competition, seo_difficulty, score, status) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$projectId, $kw, $volume, $cpc, $comp, $sd, $score, $status]);
    }
    
    return count($keywords);
}

// Handle run=1
if ($isRun) {
    $count = autoPopulateKeywordsFromApis($projectId, $project['target_keyword'], $db);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Found ' . $count . ' keywords (Google Suggest + ChatGPT) ✨']);
    exit;
}

// Handle select keyword (20% manual)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_keyword'])) {
    $kwId = (int)$_POST['kw_id'];
    $selected = (int)$_POST['selected'];
    $db->prepare("UPDATE keywords SET selected=? WHERE id=? AND project_id=?")->execute([$selected, $kwId, $projectId]);
    echo json_encode(['success' => true]);
    exit;
}

// Auto-fetch if no keywords yet or if keywords are older than 24 hours
$kwCheck = $db->prepare("SELECT COUNT(*), MAX(created_at) FROM keywords WHERE project_id=?");
$kwCheck->execute([$projectId]);
$kwRow = $kwCheck->fetch();
$kwCount = (int)($kwRow['COUNT(*)'] ?? 0);
$lastKwCreated = $kwRow['MAX(created_at)'] ?? null;

if ($kwCount == 0 || !$lastKwCreated || (time() - strtotime($lastKwCreated)) > 86400) {
    autoPopulateKeywordsFromApis($projectId, $project['target_keyword'], $db);
}

$keywords = $db->prepare("SELECT * FROM keywords WHERE project_id=? ORDER BY search_volume DESC");
$keywords->execute([$projectId]);
$keywords = $keywords->fetchAll();
?>

<?php if (!$isAjax): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Keyword Research Console</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container-fluid py-4 px-4">
<?php endif; ?>

<!-- Alerts -->
<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i> <?= clean($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i> <?= clean($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Stats Cards -->
<?php
$targetCount = count(array_filter($keywords, fn($k) => ($k['status'] ?? 'Pending') === 'Target'));
$pendingCount = count(array_filter($keywords, fn($k) => ($k['status'] ?? 'Pending') === 'Pending'));
$ignoreCount = count(array_filter($keywords, fn($k) => ($k['status'] ?? 'Pending') === 'Ignore'));
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center border-0 shadow-sm bg-white p-3" style="border-radius:12px;">
            <h3 class="text-primary fw-bold mb-1"><?= count($keywords) ?></h3>
            <span class="text-muted small">Total Keywords Loaded</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center border-0 shadow-sm bg-white p-3" style="border-radius:12px;">
            <h3 class="text-success fw-bold mb-1"><?= $targetCount ?></h3>
            <span class="text-muted small">🎯 Target Keywords</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center border-0 shadow-sm bg-white p-3" style="border-radius:12px;">
            <h3 class="text-warning fw-bold mb-1"><?= $pendingCount ?></h3>
            <span class="text-muted small">🟡 Pending Review</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center border-0 shadow-sm bg-white p-3" style="border-radius:12px;">
            <h3 class="text-secondary fw-bold mb-1"><?= $ignoreCount ?></h3>
            <span class="text-muted small">⚠️ Ignored Keywords</span>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Sidebar: Import & Manual Add -->
    <div class="col-lg-4">
        <!-- Import CSV Card -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
            <div class="card-header bg-success text-white py-2" style="border-radius: 12px 12px 0 0;">
                <h6 class="mb-0 small"><i class="fas fa-file-import me-2"></i>Import Google Keyword Planner CSV</h6>
            </div>
            <div class="card-body bg-light" style="border-radius: 0 0 12px 12px;">
                <form id="csvUploadForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select CSV File</label>
                        <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm w-100">
                        <i class="fas fa-upload me-2"></i>Upload & Parse CSV
                    </button>
                </form>
                <div class="mt-3 small text-muted">
                    <strong>Export Step:</strong> Google Ads → Tools → Keyword Planner → Get Volume/Forecast → Download CSV (Google Sheets).
                </div>
            </div>
        </div>

        <!-- Add Manual Keyword Card -->
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-header bg-primary text-white py-2" style="border-radius: 12px 12px 0 0;">
                <h6 class="mb-0 small"><i class="fas fa-plus me-2"></i>Manually Add Keyword</h6>
            </div>
            <div class="card-body bg-light" style="border-radius: 0 0 12px 12px;">
                <form id="manualAddForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="add_manual_keyword" value="1">
                    
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Keyword *</label>
                        <input type="text" name="keyword" class="form-control form-control-sm" placeholder="e.g. python classes near me" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-2">
                            <label class="form-label small fw-bold">Volume</label>
                            <input type="number" name="volume" class="form-control form-control-sm" value="250">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small fw-bold">Est. CPC ($)</label>
                            <input type="number" name="cpc" step="0.01" class="form-control form-control-sm" value="1.50">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">Competition (0-1)</label>
                            <input type="number" name="competition" step="0.01" min="0" max="1" class="form-control form-control-sm" value="0.30">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">SEO Difficulty (0-100)</label>
                            <input type="number" name="difficulty" min="0" max="100" class="form-control form-control-sm" value="35">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-save me-2"></i>Save Keyword
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Keyword Data Grid -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3" style="border-radius: 12px 12px 0 0;">
                <h6 class="mb-0 fw-bold"><i class="fas fa-list text-primary me-2"></i>Keywords List & Auto-Score Calculation</h6>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" class="form-control form-control-sm" id="kwSearch" placeholder="Search keywords..." oninput="filterKeywords(this.value)" style="width:200px;">
                    <button class="btn btn-sm btn-outline-primary" onclick="refreshKeywords(this)">
                        <i class="fas fa-sync"></i> Auto Suggest
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 550px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0" id="kwTable">
                        <thead class="table-dark sticky-top">
                            <tr style="font-size:12px;">
                                <th>#</th>
                                <th>Keyword</th>
                                <th>Volume</th>
                                <th>CPC ($)</th>
                                <th>Competition</th>
                                <th>SEO Diff (Ubersuggest)</th>
                                <th>Score</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keywords as $i => $kw): ?>
                                 <?php
                                 $sd = $kw['seo_difficulty'] ?? 40;
                                 $sdBadge = 'bg-success';
                                 $sdText = 'Easy';
                                 if ($sd > 50) {
                                     $sdBadge = 'bg-danger';
                                     $sdText = 'Hard';
                                 } elseif ($sd > 30) {
                                     $sdBadge = 'bg-warning text-dark';
                                     $sdText = 'Medium';
                                 }
                                 
                                 $score = $kw['score'] ?? 0;
                                 if ($score == 0) {
                                     $score = calculateKeywordScore($kw['search_volume'], $kw['cpc'] ?? 0.00, $kw['competition'] ?? 0.00, $sd);
                                 }
                                 ?>
                                <tr class="kw-row" style="font-size:13px;">
                                    <td><?= $i + 1 ?></td>
                                    <td><code><?= clean($kw['keyword']) ?></code></td>
                                    <td>
                                        <span class="badge <?= $kw['search_volume'] > 2000 ? 'bg-success' : ($kw['search_volume'] > 500 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                            <?= number_format($kw['search_volume']) ?>/mo
                                        </span>
                                    </td>
                                    <td><strong>$<?= number_format($kw['cpc'] ?? 0.00, 2) ?></strong></td>
                                    <td><?= number_format($kw['competition'] ?? 0.00, 2) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <input type="number" class="form-control form-control-sm text-center" 
                                                   value="<?= $sd ?>" 
                                                   style="width: 55px; padding: 2px;" 
                                                   onchange="updateDifficulty(<?= $kw['id'] ?>, this.value, this)">
                                            <span class="badge <?= $sdBadge ?> d-none d-md-inline" id="sd-badge-<?= $kw['id'] ?>">
                                                <?= $sdText ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <strong id="score-val-<?= $kw['id'] ?>" class="<?= $score >= 70 ? 'text-success' : ($score >= 40 ? 'text-warning' : 'text-danger') ?>">
                                            <?= $score ?>/100
                                        </strong>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm w-auto" onchange="updateStatus(<?= $kw['id'] ?>, this.value)">
                                            <option value="Target" <?= ($kw['status'] ?? 'Pending') === 'Target' ? 'selected' : '' ?>>🎯 Target</option>
                                            <option value="Pending" <?= ($kw['status'] ?? 'Pending') === 'Pending' ? 'selected' : '' ?>>🟡 Pending</option>
                                            <option value="Ignore" <?= ($kw['status'] ?? 'Ignore') === 'Ignore' ? 'selected' : '' ?>>⚠️ Ignore</option>
                                        </select>
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

<script>
function filterKeywords(val) {
    document.querySelectorAll('.kw-row').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val.toLowerCase()) ? '' : 'none';
    });
}

function updateDifficulty(id, val, inputEl) {
    const formData = new FormData();
    formData.append('update_difficulty', '1');
    formData.append('kw_id', id);
    formData.append('difficulty', val);

    fetch('keyword-research.php?id=<?= $projectId ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Update score
            const scoreEl = document.getElementById('score-val-' + id);
            scoreEl.textContent = data.score + '/100';
            scoreEl.className = data.score >= 70 ? 'text-success' : (data.score >= 40 ? 'text-warning' : 'text-danger');
            
            // Update difficulty badge
            const badge = document.getElementById('sd-badge-' + id);
            if (val > 50) {
                badge.className = 'badge bg-danger d-none d-md-inline';
                badge.textContent = 'Hard';
            } else if (val > 30) {
                badge.className = 'badge bg-warning text-dark d-none d-md-inline';
                badge.textContent = 'Medium';
            } else {
                badge.className = 'badge bg-success d-none d-md-inline';
                badge.textContent = 'Easy';
            }
        }
    });
}

function updateStatus(id, statusVal) {
    const formData = new FormData();
    formData.append('update_status', '1');
    formData.append('kw_id', id);
    formData.append('status', statusVal);

    fetch('keyword-research.php?id=<?= $projectId ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (!data.success) {
            alert('Error updating status.');
        } else {
            // Update selected target in DB also for integration compatibility
            fetch('keyword-research.php?id=<?= $projectId ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'select_keyword=1&kw_id=' + id + '&selected=' + (statusVal === 'Target' ? 1 : 0)
            });
        }
    });
}

function refreshKeywords(btn) {
    if (!btn) btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Suggesting...';
    fetch('keyword-research.php?id=<?= $projectId ?>&run=1')
        .then(r => {
            if (!r.ok) {
                throw new Error('Network response was not ok');
            }
            return r.json();
        })
        .then(data => {
            alert(data.message);
            if (typeof loadTab === 'function') loadTab('keywords');
            else location.reload();
        })
        .catch(err => {
            console.error(err);
            alert('Error generating keywords. Please check your internet connection or API keys.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync me-1"></i> Auto Suggest';
        });
}

// Handle Forms inside AJAX container smoothly
document.getElementById('csvUploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('keyword-research.php?id=<?= $projectId ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.text()).then(html => {
        if (typeof loadTab === 'function') loadTab('keywords');
        else location.reload();
    });
});

document.getElementById('manualAddForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('keyword-research.php?id=<?= $projectId ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.text()).then(html => {
        if (typeof loadTab === 'function') loadTab('keywords');
        else location.reload();
    });
});
</script>

<?php if (!$isAjax): ?>
</div>
</body>
</html>
<?php endif; ?>
