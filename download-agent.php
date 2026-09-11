<?php
/**
 * SkyRank Local PC Agent Downloader
 * Packages the local agent scripts into a portable zip file for users.
 */
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$zipFile = sys_get_temp_dir() . '/skyrank_pc_agent_' . time() . '.zip';
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Error: Cannot create zip archive.");
}

$seleniumDir = __DIR__ . '/selenium';

// Add local_agent.py
if (file_exists($seleniumDir . '/local_agent.py')) {
    $zip->addFile($seleniumDir . '/local_agent.py', 'skyrank_agent/local_agent.py');
}

// Add pinterest_post.py
if (file_exists($seleniumDir . '/pinterest_post.py')) {
    $zip->addFile($seleniumDir . '/pinterest_post.py', 'skyrank_agent/pinterest_post.py');
}

// Add run_local_agent.bat
if (file_exists($seleniumDir . '/run_local_agent.bat')) {
    $zip->addFile($seleniumDir . '/run_local_agent.bat', 'skyrank_agent/run_local_agent.bat');
}

// Add README.txt
$readmeContent = "=================================================================\r\n" .
    "  SkyRank Local PC Agent - Setup Guide\r\n" .
    "=================================================================\r\n\r\n" .
    "Step 1: Extract this ZIP folder to your Desktop or any folder.\r\n\r\n" .
    "Step 2: Double-click 'run_local_agent.bat' to start the agent.\r\n" .
    "        (Keep the black terminal window open/minimized while posting)\r\n\r\n" .
    "Step 3: Open your web portal:\r\n" .
    "        http://52.55.247.39/submission-manager.php\r\n\r\n" .
    "Step 4: The badge will show '🟢 PC Agent: Connected (Your PC)'.\r\n" .
    "        Select your project/keyword and click 'Auto Post'!\r\n\r\n" .
    "=================================================================\r\n" .
    "Note: Ensure Google Chrome and Python are installed on your PC.\r\n" .
    "=================================================================\r\n";

$zip->addFromString('skyrank_agent/README.txt', $readmeContent);
$zip->close();

if (!file_exists($zipFile)) {
    die("Error: Failed to build zip archive.");
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="skyrank_pc_agent.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Pragma: no-cache');
header('Expires: 0');

readfile($zipFile);
@unlink($zipFile);
exit;
