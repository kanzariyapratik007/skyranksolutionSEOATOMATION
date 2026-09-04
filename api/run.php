<?php
require_once __DIR__ . '/api_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Only POST is accepted.'
    ]);
    exit;
}

// Read inputs (JSON or Form POST)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true) ?? [];

$projectId      = (int)($input['project_id'] ?? $_POST['project_id'] ?? 0);
$keyword        = trim($input['keyword'] ?? $_POST['keyword'] ?? '');
$targetSite     = trim($input['target_site'] ?? $_POST['target_site'] ?? '');
$platformFilter = trim($input['platform'] ?? $_POST['platform'] ?? '');

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Valid project_id is required.'
    ]);
    exit;
}

try {
    $db = getDB();
    $userId = $authenticatedUser['id'];
    $role = $authenticatedUser['role'];
    
    // Verify project belongs to user (or user is admin)
    if ($role === 'admin') {
        $projStmt = $db->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
        $projStmt->execute([$projectId]);
    } else {
        $projStmt = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ? LIMIT 1");
        $projStmt->execute([$projectId, $userId]);
    }
    $project = $projStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Project not found or access denied.'
        ]);
        exit;
    }
    
    // Populate default keyword and target site if not provided
    if (empty($keyword)) {
        $keywordsList = array_filter(array_map('trim', explode(',', $project['target_keyword'])));
        $keyword = $keywordsList[0] ?? '';
    }
    if (empty($targetSite)) {
        $targetSitesList = array_filter(array_map('trim', explode(',', $project['target_site'] ?: $project['website_url'])));
        $targetSite = $targetSitesList[0] ?? '';
    }
    
    if (empty($keyword)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Keyword is empty and could not be auto-resolved from the project.'
        ]);
        exit;
    }
    
    // Fetch active social accounts
    if (!empty($platformFilter)) {
        $accountsStmt = $db->prepare('SELECT * FROM social_accounts WHERE project_id = ? AND platform = ? AND status = "active" ORDER BY id');
        $accountsStmt->execute([$projectId, $platformFilter]);
    } else {
        $accountsStmt = $db->prepare('SELECT * FROM social_accounts WHERE project_id = ? AND status = "active" ORDER BY platform, id');
        $accountsStmt->execute([$projectId]);
    }
    $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No active credentials/social accounts found for this project.'
        ]);
        exit;
    }
    
    $queuedCount = 0;
    $results = [];
    
    $insertStmt = $db->prepare('INSERT INTO backlink_queue (project_id, social_account_id, platform, keyword, target_url, status) VALUES (?, ?, ?, ?, ?, "pending")');
    $checkStmt  = $db->prepare('SELECT COUNT(*) FROM backlink_queue WHERE project_id = ? AND social_account_id = ? AND status IN ("pending", "processing")');
    
    foreach ($accounts as $creds) {
        $checkStmt->execute([$projectId, $creds['id']]);
        $exists = (int)$checkStmt->fetchColumn();
        
        if ($exists > 0) {
            $results[] = [
                'platform' => $creds['platform'],
                'username' => $creds['username'],
                'status' => 'duplicate',
                'message' => 'Already queued or processing in background'
            ];
            continue;
        }
        
        $insertStmt->execute([$projectId, $creds['id'], $creds['platform'], $keyword, $targetSite]);
        $queuedCount++;
        
        $results[] = [
            'platform' => $creds['platform'],
            'username' => $creds['username'],
            'status' => 'queued',
            'message' => 'Successfully queued'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'queued_count' => $queuedCount,
        'results' => $results,
        'message' => 'Auto-posting tasks triggered.'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to trigger postings: ' . $e->getMessage()
    ]);
}
