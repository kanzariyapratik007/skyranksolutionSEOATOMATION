<?php
/**
 * Cleanup Zombie Processes & Stale Profile Locks
 * Prevents Chrome/Playwright memory leaks and locked context crashes.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI execution only.\n");
}

echo "[" . date('Y-m-d H:i:s') . "] Starting Zombie Process & Lock Cleanup...\n";

// 1. Clean up stale lock files from chrome profiles
$seleniumDir = __DIR__ . '/selenium';
if (is_dir($seleniumDir)) {
    $cleanedCount = 0;
    try {
        $dirItr = new RecursiveDirectoryIterator(
            $seleniumDir,
            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::CATCH_GET_CHILD
        );
        $files = new RecursiveIteratorIterator($dirItr, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $fileinfo) {
            try {
                $filename = $fileinfo->getFilename();
                if (in_array($filename, ['SingletonLock', 'SingletonCookie', 'SingletonSocket', 'LOCK', 'Singleton*'])) {
                    @unlink($fileinfo->getRealPath());
                    $cleanedCount++;
                }
            } catch (Throwable $e) {
                // Ignore individual file permission errors
            }
        }
    } catch (Throwable $e) {
        echo "Warning: Could not iterate some lock directories ({$e->getMessage()})\n";
    }
    // Clean up temporary chrome profile directories older than 24 hours or if no task processing
    $profilesCleaned = 0;
    foreach (glob($seleniumDir . '/chrome_profile_*', GLOB_ONLYDIR) as $profileDir) {
        // If profile is older than 24 hours (86400s)
        if (filemtime($profileDir) < (time() - 86400)) {
            @exec("rm -rf " . escapeshellarg($profileDir));
            $profilesCleaned++;
        }
    }
    if ($profilesCleaned > 0) {
        echo "Cleaned up {$profilesCleaned} old chrome_profile directories in {$seleniumDir}\n";
    }
    echo "Cleaned up {$cleanedCount} stale lock files in {$seleniumDir}\n";
}

// 2. Check if any task is currently active in DB
require_once __DIR__ . '/config.php';
$isTaskProcessing = false;

try {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) FROM backlink_queue WHERE status = 'processing'");
    $count = (int)$stmt->fetchColumn();
    if ($count > 0) {
        $isTaskProcessing = true;
    }
} catch (Exception $e) {
    echo "DB Check Warning: " . $e->getMessage() . "\n";
}

// 3. Kill hung Chrome / Playwright node driver processes
if (!$isTaskProcessing) {
    echo "No tasks currently processing. Cleaning up all leftover Chrome & Playwright driver processes...\n";
    @exec("pkill -9 -f 'chrome' 2>/dev/null");
    @exec("pkill -9 -f 'chromedriver' 2>/dev/null");
    @exec("pkill -9 -f 'playwright' 2>/dev/null");
} else {
    echo "A task is currently processing. Cleaning up stale lock files...\n";
    // Check if task is older than 3 minutes, then kill
    @exec("find /var/www/html/logs/ -name 'cron_worker.lock' -mmin +3 -delete 2>/dev/null");
}

echo "[" . date('Y-m-d H:i:s') . "] Cleanup complete.\n";
