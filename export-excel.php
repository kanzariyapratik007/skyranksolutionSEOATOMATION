<?php
require_once 'config.php';
requireLogin();
$db = getDB();
$projectId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT p.*, u.username, u.email FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id=? AND p.user_id=?");
$stmt->execute([$projectId, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) { die('Project not found'); }

// Fetch user password (for client report)
$userPass = $db->prepare("SELECT password FROM users WHERE id=?");
$userPass->execute([$_SESSION['user_id']]);
$userPass = $userPass->fetchColumn();

// Fetch all data
$backlinks = $db->prepare("SELECT * FROM backlinks WHERE project_id=? ORDER BY da_score DESC");
$backlinks->execute([$projectId]);
$backlinks = $backlinks->fetchAll();

// Separate created backlinks (from Submission Manager auto-posts)
$createdBacklinks = $db->prepare("SELECT * FROM backlinks WHERE project_id=? AND status='created' ORDER BY created_at DESC");
$createdBacklinks->execute([$projectId]);
$createdBacklinks = $createdBacklinks->fetchAll();

$keywords = $db->prepare("SELECT * FROM keywords WHERE project_id=? ORDER BY search_volume DESC");
$keywords->execute([$projectId]);
$keywords = $keywords->fetchAll();

$issues = $db->prepare("SELECT * FROM onpage_issues WHERE project_id=? ORDER BY created_at DESC");
$issues->execute([$projectId]);
$issues = $issues->fetchAll();

$reports = $db->prepare("SELECT * FROM seo_reports WHERE project_id=? ORDER BY report_date DESC LIMIT 30");
$reports->execute([$projectId]);
$reports = $reports->fetchAll();

$latestReport = $reports[0] ?? [];

// Generate CSV (Excel-compatible)
$filename = 'SEO-Client-Report-' . preg_replace('/[^a-z0-9]/i', '-', $project['target_keyword']) . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF"); // BOM for Excel UTF-8

// ============================================================
// SECTION 1: CLIENT DETAILS HEADER
// ============================================================
fputcsv($output, ['=== SEO 80/20 SYSTEM - CLIENT REPORT ===']);
fputcsv($output, ['Generated:', date('d M Y H:i:s')]);
fputcsv($output, []);

// ============================================================
// SECTION 2: MAIN TABLE - Client + Backlinks (exact format)
// ============================================================
fputcsv($output, ['WEBSITE', 'USERNAME', 'PASSWORD', 'TARGET KEYWORD', 'TARGETED SITE', 'PLATFORM', 'BACKLINK URL', 'STATUS']);

if (!empty($createdBacklinks)) {
    foreach ($createdBacklinks as $bl) {
        fputcsv($output, [
            $project['website_url'],
            $project['username'] ?? $_SESSION['username'],
            '********',  // Password hidden for security
            $project['target_keyword'],
            $project['target_site'] ?? $project['website_url'],
            ucfirst($bl['platform']),
            $bl['backlink_url'],
            'Created ✓',
        ]);
    }
} else {
    // Show project details even if no backlinks yet
    fputcsv($output, [
        $project['website_url'],
        $project['username'] ?? $_SESSION['username'],
        '********',
        $project['target_keyword'],
        $project['target_site'] ?? $project['website_url'],
        'N/A',
        'No backlinks created yet',
        'Pending',
    ]);
}
fputcsv($output, []);

// ============================================================
// SECTION 3: SEO SUMMARY
// ============================================================
fputcsv($output, ['SEO SUMMARY']);
fputcsv($output, ['Metric', 'Value', 'Status']);
fputcsv($output, ['SEO Score', ($latestReport['seo_score'] ?? 0) . '/100', ($latestReport['seo_score'] ?? 0) >= 70 ? 'Good' : 'Needs Work']);
fputcsv($output, ['Google Rank', $latestReport['rank'] ?? 'Not tracked', ($latestReport['rank'] ?? 0) > 0 && ($latestReport['rank'] ?? 0) <= 10 ? 'Top 10!' : 'Needs Improvement']);
fputcsv($output, ['Backlinks Created', count($createdBacklinks)]);
fputcsv($output, ['Total Opportunities', count($backlinks)]);
fputcsv($output, ['Keywords Found', count($keywords)]);
fputcsv($output, ['On-Page Issues', count($issues)]);
fputcsv($output, []);

$metaStmt = $db->prepare('SELECT * FROM project_meta WHERE project_id=?');
$metaStmt->execute([$projectId]);
$metaRow = $metaStmt->fetch();
if ($metaRow) {
    fputcsv($output, ['OPTIMIZED META TAGS (paste in website <head>)']);
    fputcsv($output, ['Field', 'Value']);
    fputcsv($output, ['Title', $metaRow['meta_title']]);
    fputcsv($output, ['Meta Description', $metaRow['meta_description']]);
    fputcsv($output, ['Keywords', $metaRow['meta_keywords']]);
    fputcsv($output, ['H1 Suggestion', $metaRow['h1_suggestion']]);
    fputcsv($output, []);
}

// ============================================================
// SECTION 4: ALL BACKLINK OPPORTUNITIES
// ============================================================
fputcsv($output, ['ALL BACKLINK OPPORTUNITIES']);
fputcsv($output, ['WEBSITE', 'USERNAME', 'PASSWORD', 'TARGET KEYWORD', 'TARGETED SITE', 'PLATFORM', 'BACKLINK URL', 'STATUS']);
foreach ($backlinks as $bl) {
    fputcsv($output, [
        $project['website_url'],
        $project['username'] ?? $_SESSION['username'],
        '********',
        $project['target_keyword'],
        $project['target_site'] ?? $project['website_url'],
        ucfirst($bl['platform']),
        $bl['backlink_url'],
        ucfirst($bl['status']),
    ]);
}
fputcsv($output, []);

// ============================================================
// SECTION 5: KEYWORDS
// ============================================================
fputcsv($output, ['KEYWORDS LIST']);
fputcsv($output, ['#', 'Keyword', 'Est. Search Volume', 'Selected']);
foreach ($keywords as $i => $kw) {
    fputcsv($output, [$i + 1, $kw['keyword'], $kw['search_volume'], $kw['selected'] ? 'Yes' : 'No']);
}
fputcsv($output, []);

// ============================================================
// SECTION 6: ON-PAGE ISSUES
// ============================================================
fputcsv($output, ['ON-PAGE SEO ISSUES']);
fputcsv($output, ['#', 'Issue Type', 'Issue Detail', 'Fix Code', 'Status']);
foreach ($issues as $i => $issue) {
    fputcsv($output, [$i + 1, $issue['issue_type'], $issue['issue_detail'], $issue['fix_code'], ucfirst($issue['status'])]);
}
fputcsv($output, []);

// ============================================================
// SECTION 7: RANK HISTORY
// ============================================================
fputcsv($output, ['RANK HISTORY']);
fputcsv($output, ['Date', 'Google Rank', 'SEO Score']);
foreach ($reports as $r) {
    fputcsv($output, [$r['report_date'], $r['rank'] ?: 'N/A', $r['seo_score'] ?: 'N/A']);
}
fputcsv($output, []);

// ============================================================
// SECTION 8: RECOMMENDATIONS
// ============================================================
fputcsv($output, ['RECOMMENDATIONS']);
fputcsv($output, ['Priority', 'Action', 'Expected Impact']);
fputcsv($output, ['HIGH', 'Fix all critical on-page issues (title, meta, H1)', 'SEO Score +20 points']);
fputcsv($output, ['HIGH', 'Create 10+ high DA backlinks (DA 80+)', 'Rank improvement in 4-8 weeks']);
fputcsv($output, ['MEDIUM', 'Publish 3-5 SEO articles with target keyword', 'More keyword coverage']);
fputcsv($output, ['MEDIUM', 'Fix all images missing alt text', 'SEO Score +5 points']);
fputcsv($output, ['LOW', 'Add schema markup to all pages', 'Rich snippets in Google']);
fputcsv($output, ['LOW', 'Improve page speed to 90+ score', 'Better user experience & ranking']);

fclose($output);
exit;
?>
