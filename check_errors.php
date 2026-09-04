<?php
require_once __DIR__ . '/config.php';

try {
    $db = getDB();
    $arg1 = isset($argv[1]) ? strtolower(trim($argv[1])) : '';
    $limit = isset($argv[2]) && is_numeric($argv[2]) ? (int)$argv[2] : (is_numeric($arg1) ? (int)$arg1 : 30);

    if ($arg1 === 'summary') {
        // Show the latest failed task for EVERY platform
        $stmt = $db->prepare("
            SELECT q1.id, q1.platform, q1.error_message, q1.updated_at 
            FROM backlink_queue q1
            INNER JOIN (
                SELECT platform, MAX(id) as max_id 
                FROM backlink_queue 
                WHERE status = 'failed' 
                GROUP BY platform
            ) q2 ON q1.id = q2.max_id
            ORDER BY q1.id DESC
        ");
        $stmt->execute();
        $title = "LATEST FAILED TASK FOR EACH PLATFORM";
    } elseif ($arg1 !== '' && !is_numeric($arg1) && $arg1 !== 'all') {
        $stmt = $db->prepare("SELECT id, platform, error_message, updated_at FROM backlink_queue WHERE status = 'failed' AND platform = ? ORDER BY id DESC LIMIT $limit");
        $stmt->execute([$arg1]);
        $title = "LAST $limit FAILED " . strtoupper($arg1) . " TASKS";
    } else {
        $stmt = $db->prepare("SELECT id, platform, error_message, updated_at FROM backlink_queue WHERE status = 'failed' ORDER BY id DESC LIMIT $limit");
        $stmt->execute();
        $title = "LAST $limit FAILED TASKS (ALL PLATFORMS)";
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "========================================\n";
    echo "$title\n";
    echo "========================================\n";

    if (empty($results)) {
        echo "No failed tasks found.\n";
    } else {
        foreach ($results as $row) {
            echo "Task ID: {$row['id']}\n";
            echo "Platform: " . ucfirst($row['platform']) . "\n";
            echo "Failed At: {$row['updated_at']}\n";
            echo "Error Detail: " . ($row['error_message'] ?? 'Unknown Error') . "\n";
            echo "----------------------------------------\n";
        }
    }
} catch (Exception $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}

