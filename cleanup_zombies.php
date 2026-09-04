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
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($seleniumDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    $cleanedCount = 0;
    foreach ($files as $fileinfo) {
        $filename = $fileinfo->getFilename();
        if (in_array($filename, ['SingletonLock', 'SingletonCookie', 'SingletonSocket', 'LOCK', 'Singleton*'])) {
            try {
                @unlink($fileinfo->getRealPath());
                $cleanedCount++;
            } catch (Exception $e) {
                // Ignore permission errors
            }
        }
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

// 3. Kill hung Chrome / Playwright node driver processes if no task is active or processes are older than 10 mins
if (!$isTaskProcessing) {
    echo "No tasks currently processing. Cleaning up all leftover Chrome & Playwright driver processes...\n";
    @exec("pkill -9 -f 'chrome'");
    @exec("pkill -9 -f 'chromedriver'");
    @exec("pkill -9 -f 'playwright'");
} else {
    echo "A task is currently processing. Cleaning up only older zombie processes...\n";
    // Kill processes running longer than 10 minutes (600s)
    @exec("pkill -9 -o 600 -f 'chrome'");
    @exec("pkill -9 -o 600 -f 'chromedriver'");
}

echo "[" . date('Y-m-d H:i:s') . "] Cleanup complete.\n";
