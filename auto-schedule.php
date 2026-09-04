<?php
// ============================================================
// auto-schedule.php
// Windows Task Scheduler thi run karo — har 24 hours ma
// Badha configured projects na badha platforms par auto-post kare
//
// Windows Task Scheduler setup:
//   Program:  C:\xampp\php\php.exe
//   Arguments: C:\Users\ADMIN\Desktop\seo-system\auto-schedule.php
//   Trigger:  Daily at 9:00 AM (ya je time joiye te)
// ============================================================

// CLI thi run thay chhe — session/login check nahi karvo
define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai-content.php';
require_once __DIR__ . '/auto-poster.php';

set_time_limit(3600); // 1 hour max
ini_set('memory_limit', '512M');

$db  = getDB();
$log = [];
$ts  = date('Y-m-d H:i:s');

$log[] = "========================================";
$log[] = "[{$ts}] auto-schedule.php started";
$log[] = "========================================";

// ── Step 1: Get all active projects (only auto_schedule enabled) ─
try {
    $projects = $db->query("SELECT * FROM projects WHERE auto_schedule = 1 ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {
    // Column doesn't exist yet — get all projects
    $projects = $db->query("SELECT * FROM projects ORDER BY id ASC")->fetchAll();
}

if (empty($projects)) {
    $log[] = "No projects found. Add a project first.";
    writeLog($log);
    exit;
}

$log[] = "Found " . count($projects) . " project(s)";

// ── Step 2: Get all active social accounts ────────────────────
$accounts = $db->query("SELECT * FROM social_accounts WHERE status='active' ORDER BY platform, id")->fetchAll();

if (empty($accounts)) {
    $log[] = "No active social accounts found. Add credentials first.";
    writeLog($log);
    exit;
}

$log[] = "Found " . count($accounts) . " active account(s)";

// ── Step 3: Post each project to all platforms ────────────────
$totalPosted  = 0;
$totalFailed  = 0;
$totalSkipped = 0;

foreach ($projects as $project) {
    $projectId = (int) $project['id'];
    
    // Support multiple comma-separated keywords and rotate them daily based on the day of the year
    $rawKeywords = $project['target_keyword'];
    $keywordsList = array_filter(array_map('trim', explode(',', $rawKeywords)));
    if (empty($keywordsList)) {
        $keywordsList = ['SEO Services'];
    }
    $dayOfYear = (int)date('z'); // Day of year (0 to 365)
    $keywordIndex = $dayOfYear % count($keywordsList);
    $keyword = $keywordsList[$keywordIndex];
    
    $site = $project['target_site'] ?: $project['website_url'];

    $log[] = "";
    $log[] = "--- Project #{$projectId}: '{$keyword}' (Rotated from: {$rawKeywords}) → {$site} ---";

    // Skip backlink posting entirely for Basic SEO plan
    $package = $project['package_type'] ?? 'basic';
    if ($package === 'basic') {
        $log[] = "  Skipped: Project is on BASIC SEO plan (no auto-backlinks).";
        continue;
    }

    // Fetch active social accounts for this specific project
    $accountsStmt = $db->prepare("SELECT * FROM social_accounts WHERE project_id=? AND status='active' ORDER BY platform, id");
    $accountsStmt->execute([$projectId]);
    $projectAccounts = $accountsStmt->fetchAll();

    if (empty($projectAccounts)) {
        $log[] = "  Skipped: No active social credentials configured for this project.";
        continue;
    }

    // Group accounts by platform
    $byPlatform = [];
    foreach ($projectAccounts as $acc) {
        $byPlatform[$acc['platform']][] = $acc;
    }

    foreach ($byPlatform as $platform => $platformAccounts) {
        // Skip Selenium/Manual platforms for Standard SEO plan
        if ($package === 'standard') {
            $seleniumPlatforms = ['google_business', 'pinterest', 'behance', 'wakelet', 'vivauae', 'padlet', 'pearltrees', 'mewe', 'instapaper', 'livejournal', 'site123', 'symbaloo', 'penzu', 'linktree', 'scoopit'];
            if (in_array($platform, $seleniumPlatforms)) {
                $log[] = "  Skipped {$platform}: Selenium/Manual platform not allowed on STANDARD plan.";
                continue;
            }
        }

        foreach ($platformAccounts as $creds) {
            $log[] = "  Posting to {$platform} ({$creds['username']})...";

            try {
                // Populate GET parameters so that runPlatformAutoPost and savePostedBacklink use rotated credentials
                $_GET['keyword'] = $keyword;
                $_GET['target_site'] = $site;

                $result = runPlatformAutoPost($platform, $creds, $project, $projectId);

                if (!empty($result['success'])) {
                    savePostedBacklink($db, $projectId, $platform, $result);
                    $log[] = "  ✓ {$platform}: Posted → " . ($result['url'] ?? 'no url');
                    $totalPosted++;
                } elseif (!empty($result['manual'])) {
                    $log[] = "  ⚠ {$platform}: Manual required — " . ($result['message'] ?? '');
                    $totalSkipped++;
                } else {
                    $log[] = "  ✗ {$platform}: Failed — " . ($result['error'] ?? 'Unknown error');
                    $totalFailed++;
                }
            } catch (Exception $e) {
                $log[] = "  ✗ {$platform}: Exception — " . $e->getMessage();
                $totalFailed++;
            }

            // Small delay between platforms to avoid rate limits
            sleep(3);
        }
    }

    // Delay between projects
    sleep(5);
}

// ── Step 4: Summary ────────────────────────────────────────────
$log[] = "";
$log[] = "========================================";
$log[] = "SUMMARY: Posted={$totalPosted}, Failed={$totalFailed}, Manual={$totalSkipped}";
$log[] = "[" . date('Y-m-d H:i:s') . "] auto-schedule.php completed";
$log[] = "========================================";

writeLog($log);

// ── Step 5: Generate Daily Excel (CSV) Report ─────────────────
generateDailyReport($db, $totalPosted, $totalFailed, $totalSkipped);

echo implode("\n", $log) . "\n";

// ── Helper ─────────────────────────────────────────────────────
function writeLog(array $lines): void {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . '/auto-schedule-' . date('Y-m-d') . '.log';
    file_put_contents($file, implode("\n", $lines) . "\n", FILE_APPEND);
}

// ── Daily CSV Report ───────────────────────────────────────────
function generateDailyReport(PDO $db, int $posted, int $failed, int $skipped): void {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $date     = date('Y-m-d');
    $filename = $dir . '/daily-report-' . $date . '.csv';

    $output = fopen($filename, 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    // Header
    fputcsv($output, ['=== SEO Auto-Schedule Daily Report ===']);
    fputcsv($output, ['Date:', date('d M Y H:i:s')]);
    fputcsv($output, ['Total Posted:', $posted, 'Failed:', $failed, 'Manual:', $skipped]);
    fputcsv($output, []);

    // All backlinks posted today
    fputcsv($output, ['KEYWORD', 'TARGET SITE', 'PLATFORM', 'BACKLINK URL', 'POST TITLE', 'STATUS', 'POSTED AT']);

    $stmt = $db->query("
        SELECT
            p.target_keyword,
            COALESCE(p.target_site, p.website_url) AS target_site,
            b.platform,
            b.backlink_url,
            b.post_title,
            b.status,
            b.created_at
        FROM backlinks b
        JOIN projects p ON b.project_id = p.id
        WHERE DATE(b.created_at) = '{$date}'
        ORDER BY b.created_at DESC
    ");
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        fputcsv($output, ['No posts today yet.']);
    } else {
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['target_keyword'],
                $row['target_site'],
                ucfirst($row['platform']),
                $row['backlink_url'],
                $row['post_title'] ?? '',
                ucfirst($row['status']),
                $row['created_at'],
            ]);
        }
    }

    fputcsv($output, []);

    // All-time summary per keyword
    fputcsv($output, ['ALL-TIME BACKLINKS SUMMARY']);
    fputcsv($output, ['KEYWORD', 'TARGET SITE', 'PLATFORM', 'BACKLINK URL', 'POST TITLE', 'POSTED AT']);

    $allStmt = $db->query("
        SELECT
            p.target_keyword,
            COALESCE(p.target_site, p.website_url) AS target_site,
            b.platform,
            b.backlink_url,
            b.post_title,
            b.created_at
        FROM backlinks b
        JOIN projects p ON b.project_id = p.id
        WHERE b.status = 'created'
        ORDER BY p.target_keyword, b.created_at DESC
    ");
    foreach ($allStmt->fetchAll() as $row) {
        fputcsv($output, [
            $row['target_keyword'],
            $row['target_site'],
            ucfirst($row['platform']),
            $row['backlink_url'],
            $row['post_title'] ?? '',
            $row['created_at'],
        ]);
    }

    fclose($output);
}
