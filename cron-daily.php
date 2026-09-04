<?php
// ============================================================
// cron-daily.php - Run daily at 9 AM
// Cron: 0 9 * * * php /path/to/seo-system/cron-daily.php
// ============================================================
require_once __DIR__ . '/config.php';

$db = getDB();
$log = [];
$log[] = '[' . date('Y-m-d H:i:s') . '] Daily cron started';

// Get all projects
$projects = $db->query("SELECT * FROM projects")->fetchAll();

foreach ($projects as $project) {
    $log[] = "Processing project #{$project['id']}: {$project['target_keyword']}";

    // 1. Check Google rank (100% Real API check)
    $targetSite = $project['target_site'] ?: $project['website_url'];
    $rank = checkGoogleRank($project['target_keyword'], $targetSite);

    // Save rank
    $today = date('Y-m-d');
    $existing = $db->prepare("SELECT id FROM seo_reports WHERE project_id=? AND report_date=?");
    $existing->execute([$project['id'], $today]);
    if ($existing->fetch()) {
        $db->prepare("UPDATE seo_reports SET `rank`=? WHERE project_id=? AND report_date=?")
           ->execute([$rank, $project['id'], $today]);
    } else {
        $db->prepare("INSERT INTO seo_reports (project_id, `rank`, report_date) VALUES (?,?,?)")
           ->execute([$project['id'], $rank, $today]);
    }
    $db->prepare("INSERT INTO rank_history (project_id, keyword, rank_position) VALUES (?,?,?)")
       ->execute([$project['id'], $project['target_keyword'], $rank]);

    $log[] = "  → Rank for '{$project['target_keyword']}': " . ($rank > 0 ? "#$rank" : "Not found");

    // 2. Check for rank drop (alert if dropped more than 5 positions)
    $prevRank = $db->prepare("SELECT `rank` FROM seo_reports WHERE project_id=? AND `rank` > 0 AND report_date < ? ORDER BY report_date DESC LIMIT 1");
    $prevRank->execute([$project['id'], $today]);
    $prevRank = $prevRank->fetchColumn();

    if ($prevRank && $rank > 0 && ($rank - $prevRank) > 5) {
        $log[] = "  ⚠️ RANK DROP ALERT: Was #{$prevRank}, now #{$rank}";
        // TODO: Send email alert
    }

    sleep(2); // Delay between projects
}

$log[] = '[' . date('Y-m-d H:i:s') . '] Daily cron completed';

// Save log
$logFile = __DIR__ . '/logs/cron-daily-' . date('Y-m-d') . '.log';
if (!is_dir(__DIR__ . '/logs')) mkdir(__DIR__ . '/logs', 0755, true);
file_put_contents($logFile, implode("\n", $log) . "\n");

echo implode("\n", $log);
?>
