<?php
require_once __DIR__ . '/api_auth.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    $userId = $authenticatedUser['id'];
    $role = $authenticatedUser['role'];
    
    // 1. Projects Count
    if ($role === 'admin') {
        $projStmt = $db->query("SELECT COUNT(*) FROM projects");
    } else {
        $projStmt = $db->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ?");
        $projStmt->execute([$userId]);
    }
    $totalProjects = (int)$projStmt->fetchColumn();
    
    // 2. Created Backlinks Count
    if ($role === 'admin') {
        $backlinksStmt = $db->query("SELECT COUNT(*) FROM backlinks WHERE status = 'created'");
    } else {
        $backlinksStmt = $db->prepare("
            SELECT COUNT(b.id) 
            FROM backlinks b 
            JOIN projects p ON b.project_id = p.id 
            WHERE p.user_id = ? AND b.status = 'created'
        ");
        $backlinksStmt->execute([$userId]);
    }
    $totalBacklinks = (int)$backlinksStmt->fetchColumn();
    
    // 3. Social Accounts Stats (Active / Total)
    $activeAccStmt = $db->query("SELECT COUNT(*) FROM social_accounts WHERE status = 'active'");
    $activeAccounts = (int)$activeAccStmt->fetchColumn();
    
    $totalAccStmt = $db->query("SELECT COUNT(*) FROM social_accounts");
    $totalAccounts = (int)$totalAccStmt->fetchColumn();
    
    // 4. Backlink Queue Stats
    if ($role === 'admin') {
        $pendingStmt = $db->query("SELECT COUNT(*) FROM backlink_queue WHERE status = 'pending'");
        $failedStmt  = $db->query("SELECT COUNT(*) FROM backlink_queue WHERE status = 'failed'");
    } else {
        $pendingStmt = $db->prepare("
            SELECT COUNT(q.id) 
            FROM backlink_queue q 
            JOIN projects p ON q.project_id = p.id 
            WHERE p.user_id = ? AND q.status = 'pending'
        ");
        $pendingStmt->execute([$userId]);
        
        $failedStmt = $db->prepare("
            SELECT COUNT(q.id) 
            FROM backlink_queue q 
            JOIN projects p ON q.project_id = p.id 
            WHERE p.user_id = ? AND q.status = 'failed'
        ");
        $failedStmt->execute([$userId]);
    }
    $pendingTasks = (int)$pendingStmt->fetchColumn();
    $failedTasks  = (int)$failedStmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_projects' => $totalProjects,
            'total_backlinks_created' => $totalBacklinks,
            'active_social_accounts' => $activeAccounts,
            'total_social_accounts' => $totalAccounts,
            'pending_queue_tasks' => $pendingTasks,
            'failed_queue_tasks' => $failedTasks
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch dashboard stats: ' . $e->getMessage()
    ]);
}
