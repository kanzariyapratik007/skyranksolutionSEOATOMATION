<?php
/**
 * Cron Worker - Process backlink queue tasks sequentially.
 * Executed via server cron: * * * * * php /var/www/html/cron_worker.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai-content.php';
require_once __DIR__ . '/auto-poster.php';

// Process Lockfile Mutex: Prevent concurrent executions of cron_worker.php
$lockFile = __DIR__ . '/logs/cron_worker.lock';
$lockFp   = @fopen($lockFile, 'c+');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Another instance of cron_worker.php is currently running. Exiting.\n";
    exit;
}

// Prevent concurrent runs: check if any task is already processing
$db = getDB();
$stmt = $db->prepare("SELECT COUNT(*) FROM backlink_queue WHERE status = 'processing'");
$stmt->execute();
$running = (int)$stmt->fetchColumn();

if ($running > 0) {
    // Timeout check: if a task is stuck in 'processing' status for more than 15 minutes, mark as failed
    $timeoutStmt = $db->prepare("UPDATE backlink_queue SET status = 'failed', error_message = 'Timeout: Process hung or was terminated by the OS.' WHERE status = 'processing' AND updated_at < NOW() - INTERVAL 15 MINUTE");
    $timeoutStmt->execute();
    if ($timeoutStmt->rowCount() > 0) {
        @exec("php " . __DIR__ . "/cleanup_zombies.php > /dev/null 2>&1 &");
    }
    
    echo "A task is already processing. Exiting to avoid concurrency.\n";
    exit;
}

// Automatically clean up stale locks and zombie processes before starting a new batch
@exec("php " . __DIR__ . "/cleanup_zombies.php > /dev/null 2>&1");


// Helper to identify if a platform requires Selenium browser automation
function isSeleniumPlatform($platform, $creds = []) {
    $platform = strtolower($platform);
    // These platforms strictly use Selenium browser automation
    $seleniumOnly = ['pinterest', 'wakelet', 'symbaloo', 'pearltrees', 'diigo', 'plurk', 'livejournal'];
    if (in_array($platform, $seleniumOnly)) {
        return true;
    }
    
    // For platforms that support both API and Selenium (like tumblr, mewe, instapaper)
    // If they have no API key / token in credentials, they will fallback to Selenium
    $hybridPlatforms = ['tumblr', 'mewe', 'instapaper'];
    if (in_array($platform, $hybridPlatforms)) {
        $apiKey = $creds['api_key'] ?? '';
        if (empty($apiKey)) {
            return true; // No API key -> will run using Selenium
        }
    }
    
    return false; // Runs via API (fast)
}

// Get the batch limits (maximum 5 API tasks and 1 Selenium task per execution)
$maxApiTasks = 5;
$maxSeleniumTasks = 1;

// Get a larger pool of oldest pending tasks to filter
$stmt = $db->prepare("SELECT * FROM backlink_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 30");
$stmt->execute();
$pendingPool = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingPool)) {
    echo "No pending tasks in queue. Exiting.\n";
    exit;
}

$tasks = [];
$apiCount = 0;
$seleniumCount = 0;

foreach ($pendingPool as $task) {
    // Load credentials to check if it's a hybrid platform running on Selenium
    $accStmt = $db->prepare("SELECT * FROM social_accounts WHERE id = ?");
    $accStmt->execute([$task['social_account_id']]);
    $creds = $accStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    $isSelenium = isSeleniumPlatform($task['platform'], $creds);
    
    if ($isSelenium) {
        if ($seleniumCount >= $maxSeleniumTasks) {
            continue; // Already selected 1 Selenium task, skip others
        }
        $tasks[] = $task;
        $seleniumCount++;
    } else {
        if ($apiCount >= $maxApiTasks) {
            continue; // Already selected 5 API tasks, skip others
        }
        $tasks[] = $task;
        $apiCount++;
    }
    
    // Stop selecting if we have reached our maximum batch capacity
    if ($seleniumCount >= $maxSeleniumTasks && $apiCount >= $maxApiTasks) {
        break;
    }
}

if (empty($tasks)) {
    echo "No tasks selected for this batch. Exiting.\n";
    exit;
}

echo "Found " . count($tasks) . " tasks to process in this batch (API: {$apiCount}, Selenium: {$seleniumCount}).\n";

foreach ($tasks as $task) {
    $taskId = $task['id'];
    $projectId = $task['project_id'];
    $socialAccountId = $task['social_account_id'];
    $platform = $task['platform'];
    
    echo "----------------------------------------\n";
    echo "Processing Task ID {$taskId} (Platform: {$platform}, Project ID: {$projectId})\n";
    
    // Atomically claim task (status must still be 'pending')
    $updateStmt = $db->prepare("UPDATE backlink_queue SET status = 'processing', updated_at = NOW() WHERE id = ? AND status = 'pending'");
    $updateStmt->execute([$taskId]);
    if ($updateStmt->rowCount() === 0) {
        echo "Task ID {$taskId} already claimed or processing by another instance. Skipping.\n";
        continue;
    }
    
    try {
        // Load social account credentials
        $accStmt = $db->prepare("SELECT * FROM social_accounts WHERE id = ?");
        $accStmt->execute([$socialAccountId]);
        $creds = $accStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$creds) {
            // Fallback: search for any active credentials for this project and platform
            $fallbackStmt = $db->prepare("SELECT * FROM social_accounts WHERE project_id = ? AND platform = ? AND status = 'active' LIMIT 1");
            $fallbackStmt->execute([$projectId, $platform]);
            $creds = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$creds) {
                throw new Exception("Social credentials not found for Account ID {$socialAccountId}");
            }
        }
        
        // Load project details
        $projStmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $projStmt->execute([$projectId]);
        $project = $projStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$project) {
            throw new Exception("Project details not found for Project ID {$projectId}");
        }
        
        // Set GET parameters so runPlatformAutoPost() picks them up
        $_GET['keyword']     = $task['keyword'];
        $_GET['target_site'] = $task['target_url'];
        
        // Register the enqueued post image path so all posting engines pick it up
        if (!empty($task['post_image'])) {
            getEnqueuedImagePath($task['post_image']);
        } else {
            getEnqueuedImagePath(null); // Reset
        }
        
        // Safety rate-limit delay for Tumblr posts to protect API keys
        if ($platform === 'tumblr') {
            echo "Pausing 15 seconds for Tumblr API rate-limit safety...\n";
            sleep(15);
        }
        
        // Execute posting
        $result = runPlatformAutoPost($platform, $creds, $project, $projectId);
        
        if (!empty($result['success'])) {
            // Save posted backlink to database
            savePostedBacklink($db, $projectId, $platform, $result);
            
            // Update task status to success
            $finishStmt = $db->prepare("UPDATE backlink_queue SET status = 'success', published_url = ?, error_message = NULL, updated_at = NOW() WHERE id = ?");
            $finishStmt->execute([$result['url'] ?? '', $taskId]);
            echo "SUCCESS: Post published successfully at " . ($result['url'] ?? '') . "\n";
            
        } elseif (!empty($result['manual'])) {
            $msg = "Manual action required. " . ($result['message'] ?? '');
            $finishStmt = $db->prepare("UPDATE backlink_queue SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?");
            $finishStmt->execute([$msg, $taskId]);
            echo "MANUAL: " . $msg . "\n";
            
        } else {
            $err = $result['error'] ?? 'Unknown execution error occurred.';
            throw new Exception($err);
        }
        
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        echo "ERROR: {$errorMsg}\n";
        
        $failStmt = $db->prepare("UPDATE backlink_queue SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?");
        $failStmt->execute([$errorMsg, $taskId]);
    }
}

echo "Batch processing finished.\n";
