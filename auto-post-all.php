<?php
/**
 * Auto-post to ALL platform accounts in parallel (curl_multi).
 * Supports single platform filter or all platforms.
 */
require_once 'config.php';
requireLogin();
set_time_limit(600);
ini_set('memory_limit', '256M');

$db        = getDB();
$projectId = (int)($_GET['id'] ?? 0);
$userId    = (int)$_SESSION['user_id'];

$stmt = $db->prepare('SELECT * FROM projects WHERE id=? AND user_id=?');
$stmt->execute([$projectId, $userId]);
$project = $stmt->fetch();

if (!$project) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Project not found']);
    exit;
}

require_once __DIR__ . '/auto-poster.php';

$platformFilter = $_GET['platform'] ?? null;
$platformsFilterStr = $_GET['platforms'] ?? null;

if (!empty($platformsFilterStr)) {
    $platformsList = array_filter(array_map('trim', explode(',', $platformsFilterStr)));
    if (!empty($platformsList)) {
        $inClause = implode(',', array_fill(0, count($platformsList), '?'));
        $params = array_merge([$projectId], $platformsList);
        $accountsStmt = $db->prepare("SELECT * FROM social_accounts WHERE project_id=? AND platform IN ($inClause) AND status='active' ORDER BY platform, id");
        $accountsStmt->execute($params);
    } else {
        $accountsStmt = $db->prepare('SELECT * FROM social_accounts WHERE project_id=? AND status="active" ORDER BY platform, id');
        $accountsStmt->execute([$projectId]);
    }
} elseif (!empty($platformFilter)) {
    $accountsStmt = $db->prepare('SELECT * FROM social_accounts WHERE project_id=? AND platform=? AND status="active" ORDER BY id');
    $accountsStmt->execute([$projectId, $platformFilter]);
} else {
    $accountsStmt = $db->prepare('SELECT * FROM social_accounts WHERE project_id=? AND status="active" ORDER BY platform, id');
    $accountsStmt->execute([$projectId]);
}
$accounts = $accountsStmt->fetchAll();

if (empty($accounts)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No credentials saved.', 'results' => []]);
    exit;
}

$queued = 0;
$results = [];

$keyword = $_GET['keyword'] ?? $project['keyword'] ?? '';
$targetSite = $_GET['target_site'] ?? $project['target_url'] ?? '';

if (empty($keyword)) {
    $keyword = $project['business_name'] ?? '';
}

$currentImage = $project['post_image'] ?? null;

$insertStmt = $db->prepare('INSERT INTO backlink_queue (project_id, social_account_id, platform, keyword, target_url, status, post_image) VALUES (?, ?, ?, ?, ?, "pending", ?)');
$checkStmt  = $db->prepare('SELECT COUNT(*) FROM backlink_queue WHERE project_id = ? AND social_account_id = ? AND keyword = ? AND (target_url = ? OR (target_url IS NULL AND ? = "")) AND status IN ("pending", "processing")');

foreach ($accounts as $creds) {
    $plat = strtolower($creds['platform']);

    // Check if task for this specific account, keyword & target_url is already pending/processing
    $checkStmt->execute([$projectId, $creds['id'], $keyword, $targetSite, $targetSite]);
    $exists = (int)$checkStmt->fetchColumn();

    if ($exists > 0) {
        $results[] = [
            'platform' => $creds['platform'],
            'name'     => ucfirst($creds['platform']) . ' (' . $creds['username'] . ')',
            'handle'   => $creds['username'],
            'status'   => 'duplicate',
            'message'  => 'Already queued/processing',
        ];
        continue;
    }

    $insertStmt->execute([
        $projectId,
        $creds['id'],
        $creds['platform'],
        $keyword,
        $targetSite,
        $currentImage
    ]);
    
    $results[] = [
        'platform' => $creds['platform'],
        'name'     => ucfirst($creds['platform']) . ' (' . $creds['username'] . ')',
        'handle'   => $creds['username'],
        'status'   => 'pending',
        'message'  => 'Queued for background execution',
    ];
    $queued++;
}

header('Content-Type: application/json');
echo json_encode([
    'message' => "Done: Queued {$queued} posting tasks in the background.",
    'queued'  => $queued,
    'results' => $results,
]);
