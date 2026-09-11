<?php
/**
 * SkyRank Local PC Agent Downloader
 * Serves the portable 1-click PC Agent zip archive to users.
 */
require_once __DIR__ . '/config.php';

$zipFile = __DIR__ . '/skyrank_pc_agent.zip';

// If static zip doesn't exist, try creating it dynamically
if (!file_exists($zipFile)) {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $seleniumDir = __DIR__ . '/selenium';
            if (file_exists($seleniumDir . '/local_agent.py')) $zip->addFile($seleniumDir . '/local_agent.py', 'skyrank_agent/local_agent.py');
            if (file_exists($seleniumDir . '/pinterest_post.py')) $zip->addFile($seleniumDir . '/pinterest_post.py', 'skyrank_agent/pinterest_post.py');
            if (file_exists($seleniumDir . '/run_local_agent.bat')) $zip->addFile($seleniumDir . '/run_local_agent.bat', 'skyrank_agent/run_local_agent.bat');
            $readme = "=================================================================\r\n" .
                      "  SkyRank Local PC Agent - Setup Guide\r\n" .
                      "=================================================================\r\n\r\n" .
                      "Step 1: Extract this ZIP folder to your Desktop or any folder.\r\n\r\n" .
                      "Step 2: Double-click 'run_local_agent.bat' to start the agent.\r\n" .
                      "        (Keep the black terminal window open/minimized while posting)\r\n\r\n" .
                      "Step 3: Open your web portal:\r\n" .
                      "        http://52.55.247.39/submission-manager.php\r\n\r\n" .
                      "Step 4: The badge will show '🟢 PC Agent: Connected (Your PC)'.\r\n" .
                      "        Select your project/keyword and click 'Auto Post'!\r\n";
            $zip->addFromString('skyrank_agent/README.txt', $readme);
            $zip->close();
        }
    }
}

if (!file_exists($zipFile)) {
    die("Error: PC Agent ZIP archive not found on server.");
}

if (ob_get_length()) ob_clean();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="skyrank_pc_agent.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($zipFile);
exit;
