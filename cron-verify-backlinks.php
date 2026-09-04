<?php
// ============================================================
// cron-verify-backlinks.php
// Crawls all created backlinks and checks if they are still live.
// Can be run via Windows Task Scheduler or CMD.
// ============================================================

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/config.php';

$db = getDB();

echo "=======================================================\n";
echo "       BACKLINK VERIFICATION ENGINE RUNNING\n";
echo "=======================================================\n";

// Fetch all backlinks
$backlinks = $db->query("
    SELECT b.*, p.website_url, p.target_site 
    FROM backlinks b
    JOIN projects p ON b.project_id = p.id
    ORDER BY b.id DESC
")->fetchAll();

echo "Found " . count($backlinks) . " total backlink(s) in database.\n\n";

$verifiedCount = 0;
$brokenCount   = 0;
$nofollowCount = 0;

foreach ($backlinks as $bl) {
    $blId = (int)$bl['id'];
    $url  = $bl['backlink_url'];
    
    // Skip empty or placeholder URLs
    if (empty($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        continue;
    }
    
    echo "Checking Link #{$blId} (Platform: " . ucfirst($bl['platform']) . "): {$url} ... ";
    
    $status = verifyBacklink($url, $bl['website_url'], $bl['target_site']);
    
    $db->prepare("UPDATE backlinks SET verified_status=?, last_checked_at=NOW() WHERE id=?")
       ->execute([$status, $blId]);
       
    if ($status === 'active') {
        echo "[Live / Dofollow]\n";
        $verifiedCount++;
    } elseif ($status === 'nofollow') {
        echo "[Live / Nofollow]\n";
        $nofollowCount++;
    } else {
        echo "[Broken / Missing Link]\n";
        $brokenCount++;
    }
    
    // Friendly delay to avoid hitting targets too hard
    usleep(500000); // 0.5s
}

echo "\n=======================================================\n";
echo "VERIFICATION COMPLETED:\n";
echo "  - Dofollow/Active: {$verifiedCount}\n";
echo "  - Nofollow: {$nofollowCount}\n";
echo "  - Broken/Missing: {$brokenCount}\n";
echo "=======================================================\n";
// Verify functions loaded via config.php
