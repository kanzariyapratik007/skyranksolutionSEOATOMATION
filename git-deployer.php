<?php
require_once 'config.php';
requireMenuPermission('git-push-agent');
$db = getDB();
$userId = $_SESSION['user_id'];

// Run database migrations to add local_code_path, git_repo_url, github_user, and github_token to projects table
try {
    $db->exec("ALTER TABLE projects ADD COLUMN local_code_path VARCHAR(255) DEFAULT ''");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE projects ADD COLUMN git_repo_url VARCHAR(255) DEFAULT ''");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE projects ADD COLUMN github_user VARCHAR(255) DEFAULT ''");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE projects ADD COLUMN github_token VARCHAR(255) DEFAULT ''");
} catch (Exception $e) {}

// Get all projects for selection dropdown
$projectsStmt = $db->prepare("SELECT id, website_url, target_keyword FROM projects WHERE user_id=?");
$projectsStmt->execute([$userId]);
$projects = $projectsStmt->fetchAll();

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0 && !empty($projects)) {
    $projectId = (int)$projects[0]['id'];
}

$project = null;
$savedMeta = null;
if ($projectId > 0) {
    $stmt = $db->prepare("SELECT * FROM projects WHERE id=? AND user_id=?");
    $stmt->execute([$projectId, $userId]);
    $project = $stmt->fetch();
    
    if ($project) {
        $savedMeta = $db->prepare("SELECT * FROM project_meta WHERE project_id=?");
        $savedMeta->execute([$projectId]);
        $savedMeta = $savedMeta->fetch();
    }
}

$error = '';
$success = '';
$gitLog = [];
$scanResults = [];

// Recursive Project Scanner
function scanProjectFilesForSEO($dir, $savedMeta = null, $projectUrl = 'https://learnmoretech.in/') {
    $results = [];
    if (!is_dir($dir)) return [];
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php', 'html', 'js', 'jsx', 'ts', 'tsx'])) {
            $filePath = $file->getRealPath();
            
            // Skip library and build folders
            if (strpos($filePath, 'vendor') !== false || 
                strpos($filePath, 'node_modules') !== false || 
                strpos($filePath, '.next') !== false || 
                strpos($filePath, '.git') !== false || 
                strpos($filePath, '.gemini') !== false ||
                strpos($filePath, 'chrome_profile') !== false ||
                strpos($filePath, 'scratch') !== false) {
                continue;
            }
            
            $content = @file_get_contents($filePath);
            if (empty($content)) continue;
            
            $issues = [];
            
            // Rule 1: Missing Alt attribute on images
            if (preg_match_all('/<(img|Image)\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $imgTag = $match[0];
                    $tagName = $match[1];
                    $imgSrc = $match[2];
                    if (stripos($imgTag, 'alt=') === false) {
                        $filename = pathinfo($imgSrc, PATHINFO_FILENAME);
                        $cleanAlt = ucwords(trim(str_replace(['-', '_'], ' ', $filename)));
                        if (empty($cleanAlt)) {
                            $cleanAlt = 'SEO Optimized Image';
                        }
                        $issues[] = [
                            'type' => 'Missing Image ALT Attribute',
                            'detail' => 'Image missing alternative text: ' . htmlspecialchars($imgTag),
                            'original' => $imgTag,
                            'fixed' => str_replace('<' . $tagName, '<' . $tagName . ' alt="' . htmlspecialchars($cleanAlt) . '"', $imgTag)
                        ];
                    }
                }
            }
            
            // Rule 2: Multiple H1 tags
            if (preg_match_all('/<h1[^>]*>/i', $content, $h1Matches)) {
                if (count($h1Matches[0]) > 1) {
                    $issues[] = [
                        'type' => 'Multiple H1 Heading Tags',
                        'detail' => 'SEO best practice is exactly one <h1> tag per page for clean headings hierarchy.',
                        'original' => '',
                        'fixed' => ''
                    ];
                }
            }
            
            // Rule 3: Missing Viewport Meta (only in index/layout/header files)
            if (stripos($content, '<head>') !== false && stripos($content, 'name="viewport"') === false) {
                $issues[] = [
                    'type' => 'Missing Viewport Meta Tag',
                    'detail' => 'Missing viewport meta tag inside <head>, which hurts mobile index page loading.',
                    'original' => '<head>',
                    'fixed' => "<head>\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">"
                ];
            }

            // Rule 4: Missing Canonical URL
            if (stripos($content, '<head>') !== false && stripos($content, 'rel="canonical"') === false) {
                $cleanProjUrl = rtrim($projectUrl, '/') . '/';
                $issues[] = [
                    'type' => 'Missing Canonical URL Link Tag',
                    'detail' => 'Missing canonical URL link tag inside <head> to prevent duplicate search results.',
                    'original' => '<head>',
                    'fixed' => "<head>\n    <link rel=\"canonical\" href=\"" . htmlspecialchars($cleanProjUrl) . "\">"
                ];
            }
            
            // Rule 5: Next.js Layout Metadata Optimization
            if ($savedMeta && (strpos($filePath, 'layout.tsx') !== false || strpos($filePath, 'layout.js') !== false)) {
                // Find metadata block
                if (preg_match('/export\s+const\s+metadata[\s\S]*?=\{[\s\S]*?\}/i', $content, $metaBlockMatches)) {
                    $originalBlock = $metaBlockMatches[0];
                    
                    // Build new metadata block
                    $dbTitle = addslashes($savedMeta['meta_title']);
                    $dbDesc  = addslashes($savedMeta['meta_description']);
                    
                    // Check if they need update
                    if (stripos($originalBlock, $savedMeta['meta_title']) === false || stripos($originalBlock, $savedMeta['meta_description']) === false) {
                        $fixedBlock = "export const metadata: Metadata = {\n  title: '" . $dbTitle . "',\n  description: '" . $dbDesc . "',\n}";
                        
                        $issues[] = [
                            'type' => 'AI Meta Tag Optimization',
                            'detail' => 'Next.js layout metadata title/description does not match the optimized version in the database.',
                            'original' => $originalBlock,
                            'fixed' => $fixedBlock
                        ];
                    }
                }
            }

            // Rule 6: HTML/PHP Header Meta Tag Optimization
            if ($savedMeta && (strpos($filePath, '.html') !== false || strpos($filePath, '.php') !== false)) {
                // Check <title>
                if (preg_match('/<title>(.*?)<\/title>/is', $content, $titleMatches)) {
                    $currTitle = trim($titleMatches[1]);
                    if ($currTitle !== trim($savedMeta['meta_title'])) {
                        $issues[] = [
                            'type' => 'AI Title Tag Optimization',
                            'detail' => 'Title tag in code does not match the AI optimized title in the database.',
                            'original' => $titleMatches[0],
                            'fixed' => '<title>' . htmlspecialchars($savedMeta['meta_title']) . '</title>'
                        ];
                    }
                } elseif (stripos($content, '<head>') !== false) {
                    // Title tag missing from <head>
                    $issues[] = [
                        'type' => 'Missing Title Tag',
                        'detail' => 'Title tag is missing in the <head> container.',
                        'original' => '<head>',
                        'fixed' => "<head>\n    <title>" . htmlspecialchars($savedMeta['meta_title']) . "</title>"
                    ];
                }

                // Check Meta Description
                if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/is', $content, $descMatches)) {
                    $currDesc = trim($descMatches[1]);
                    if ($currDesc !== trim($savedMeta['meta_description'])) {
                        $issues[] = [
                            'type' => 'AI Meta Description Optimization',
                            'detail' => 'Meta description content does not match the AI optimized description in the database.',
                            'original' => $descMatches[0],
                            'fixed' => '<meta name="description" content="' . htmlspecialchars($savedMeta['meta_description']) . '">'
                        ];
                    }
                } elseif (stripos($content, '<head>') !== false) {
                    // Description tag missing from <head>
                    $issues[] = [
                        'type' => 'Missing Meta Description',
                        'detail' => 'Meta description tag is missing in the <head> container.',
                        'original' => '<head>',
                        'fixed' => "<head>\n    <meta name=\"description\" content=\"" . htmlspecialchars($savedMeta['meta_description']) . "\">"
                    ];
                }
            }

            if (!empty($issues)) {
                $results[] = [
                    'file' => str_replace($dir . DIRECTORY_SEPARATOR, '', $filePath),
                    'full_path' => $filePath,
                    'issues' => $issues
                ];
            }
        }
    }
    return $results;
}

// Handle Configuration Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security request.';
    } else {
        $localPath = trim($_POST['local_code_path'] ?? '');
        $repoUrl   = trim($_POST['git_repo_url'] ?? '');
        $gitUser   = trim($_POST['github_user'] ?? '');
        $gitToken  = trim($_POST['github_token'] ?? '');
        
        $db->prepare("UPDATE projects SET local_code_path=?, git_repo_url=?, github_user=?, github_token=? WHERE id=?")
           ->execute([$localPath, $repoUrl, $gitUser, $gitToken, $projectId]);
        
        $success = 'Project credentials have been saved.';
        $project['local_code_path'] = $localPath;
        $project['git_repo_url'] = $repoUrl;
        $project['github_user'] = $gitUser;
        $project['github_token'] = $gitToken;
    }
}

// Handle Git Clone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clone_repo'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security request.';
    } else {
        $localPath = $project['local_code_path'];
        $repoUrl   = $project['git_repo_url'];
        $username  = trim($_POST['github_user'] ?? '');
        $token     = trim($_POST['github_token'] ?? '');
        
        if (!empty($localPath) && !empty($repoUrl)) {
            $gitLog[] = "⚡ Cloning client website repository...";
            
            // Build authenticated URL if credentials provided
            $authRepo = $repoUrl;
            if (!empty($username) && !empty($token)) {
                if (preg_match('/github\.com[\/:][^\/]+\/[^\/\s\.]+/', $repoUrl, $m)) {
                    $cleanRepo = str_replace('.git', '', $m[0]);
                    $encodedToken = urlencode($token);
                    $authRepo = "https://{$username}:{$encodedToken}@{$cleanRepo}.git";
                }
            }
            
            // Create folder and run clone
            @mkdir(dirname($localPath), 0777, true);
            $cloneOut = shell_exec("git clone \"{$authRepo}\" " . escapeshellarg($localPath) . " 2>&1");
            $gitLog[] = "📁 git clone: " . trim($cloneOut);
            
            if (is_dir($localPath)) {
                $success = "Website code cloned successfully!";
            } else {
                $error = "Failed to clone. Please check folder path and credentials.";
            }
        }
    }
}

// Run scanner on the configured target project directory
if ($project && !empty($project['local_code_path']) && is_dir($project['local_code_path'])) {
    $scanResults = scanProjectFilesForSEO($project['local_code_path'], $savedMeta, $project['website_url'] ?? 'https://learnmoretech.in/');
}

// Handle apply changes and push execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_git_push'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security request.';
    } else {
        $username = trim($_POST['github_user'] ?? '');
        $token    = trim($_POST['github_token'] ?? '');
        $branch   = trim($_POST['github_branch'] ?? 'main');
        $selectedFixes = $_POST['fixes'] ?? [];
        
        $localPath = !empty($project['local_code_path']) ? $project['local_code_path'] : '';

        $gitLog[] = "⚡ Starting AI approval deployment flow...";
        
        $filesUpdated = 0;
        
        // Step 1: Apply Selected Code Fixes
        if (!empty($selectedFixes) && is_dir($localPath)) {
            foreach ($selectedFixes as $fixKey) {
                parts: list($fIdx, $iIdx) = explode(':', $fixKey);
                $fIdx = (int)$fIdx;
                $iIdx = (int)$iIdx;
                
                if (isset($scanResults[$fIdx]['issues'][$iIdx])) {
                    $fileData = $scanResults[$fIdx];
                    $issue = $fileData['issues'][$iIdx];
                    
                    if (!empty($issue['original']) && !empty($issue['fixed'])) {
                        $filePath = $fileData['full_path'];
                        $content = file_get_contents($filePath);
                        
                        // Replace original code with fixed version
                        $newContent = str_replace($issue['original'], $issue['fixed'], $content);
                        if (file_put_contents($filePath, $newContent) !== false) {
                            $gitLog[] = "🔧 Applied SEO fix to {$fileData['file']}";
                            $filesUpdated++;
                        }
                    }
                }
            }
        }

        // Step 2: Push changes via Git inside target project path
        if (($filesUpdated > 0 || isset($_POST['force_push'])) && is_dir($localPath)) {
            // Configure credentials inside the target git repository
            if (!empty($username) && !empty($token)) {
                $repoUrl = !empty($project['git_repo_url']) ? $project['git_repo_url'] : shell_exec("git -C " . escapeshellarg($localPath) . " remote get-url origin 2>&1");
                
                if (preg_match('/github\.com[\/:][^\/]+\/[^\/\s\.]+/', $repoUrl, $m)) {
                    $cleanRepo = str_replace('.git', '', $m[0]);
                    $encodedToken = urlencode($token);
                    $authRemote = "https://{$username}:{$encodedToken}@{$cleanRepo}.git";
                    
                    $checkOrigin = shell_exec("git -C " . escapeshellarg($localPath) . " remote get-url origin 2>&1");
                    if (stripos($checkOrigin, 'fatal:') !== false) {
                        shell_exec('git -C ' . escapeshellarg($localPath) . ' remote add origin "' . $authRemote . '"');
                    } else {
                        shell_exec('git -C ' . escapeshellarg($localPath) . ' remote set-url origin "' . $authRemote . '"');
                    }
                    $gitLog[] = "🔑 Configured GitHub Access Token credentials.";
                }
            }

            // Create/checkout branch
            shell_exec("git -C " . escapeshellarg($localPath) . " checkout -b " . escapeshellarg($branch) . " 2>&1");
            $checkoutOut = shell_exec("git -C " . escapeshellarg($localPath) . " checkout " . escapeshellarg($branch) . " 2>&1");
            $gitLog[] = "🌿 Switched to branch: " . htmlspecialchars($branch);

            // Stage, commit and push
            $addOut = shell_exec("git -C " . escapeshellarg($localPath) . " add . 2>&1");
            $gitLog[] = "➕ git add: " . (trim($addOut) ?: "Success");

            $commitMsg = "AI Auto-Deploy: Optimized SEO code structure for {$filesUpdated} issues";
            $commitOut = shell_exec("git -C " . escapeshellarg($localPath) . " commit -m " . escapeshellarg($commitMsg) . " 2>&1");
            $gitLog[] = "💾 git commit: " . trim($commitOut);

            $pushOut = shell_exec("git -C " . escapeshellarg($localPath) . " push origin " . escapeshellarg($branch) . " 2>&1");
            $gitLog[] = "🚀 git push origin {$branch}: " . trim($pushOut);

            if (stripos($pushOut, 'Everything up-to-date') !== false || stripos($pushOut, '->') !== false || stripos($pushOut, 'main') !== false || stripos($pushOut, 'master') !== false) {
                $success = "Code changes deployed to GitHub successfully! 🎉";
                // Rescan
                $scanResults = scanProjectFilesForSEO($localPath, $savedMeta, $project['website_url'] ?? 'https://learnmoretech.in/');
            } else {
                $error = "Error pushing to GitHub. Check credentials.";
            }
        } else {
            $error = "Select at least 1 change or force push.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Git Push Deployer - SEO 80/20</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
<style>
.terminal-box {
    background: #0f172a;
    color: #38bdf8;
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    padding: 20px;
    border-radius: 12px;
    border: 2px solid #334155;
    max-height: 350px;
    overflow-y: auto;
    box-shadow: inset 0 0 10px rgba(0,0,0,0.5);
}
.diff-del {
    background-color: #fee2e2;
    color: #991b1b;
    padding: 2px 5px;
    border-radius: 3px;
}
.diff-add {
    background-color: #dcfce7;
    color: #166534;
    padding: 2px 5px;
    border-radius: 3px;
}
</style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="container-fluid py-4 px-4">
    <!-- Header -->
    <div class="row align-items-center mb-4">
        <div class="col-md-6">
            <h3><i class="fab fa-github text-dark me-2"></i>AI Code Scanner & Git Deployer</h3>
            <p class="text-muted mb-0">Website repo scanning and GitHub push approval panel</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <form method="GET" action="git-deployer.php" class="d-inline-block">
                <select name="id" class="form-select w-auto d-inline-block align-middle me-2" onchange="this.form.submit()">
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] === $projectId ? 'selected' : '' ?>>
                            <?= clean($p['website_url']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button class="btn btn-warning" onclick="document.getElementById('inlineConfigCard').classList.toggle('d-none')">
                <i class="fas fa-cog me-2"></i>Configure Path & Repo
            </button>
        </div>
    </div>

    <!-- Inline Configuration Card (Toggled via button click above) -->
    <div class="card p-4 border-0 shadow-sm border-warning mb-4 <?= (empty($project['local_code_path'] ?? '') || empty($project['git_repo_url'] ?? '')) ? '' : 'd-none' ?>" id="inlineConfigCard" style="border-radius:16px;">
        <div class="card-header bg-warning text-dark py-2" style="border-radius:12px 12px 0 0;">
            <h6 class="mb-0"><i class="fas fa-cog me-2"></i>Configure Project Path & Repository</h6>
        </div>
        <div class="card-body p-3 bg-light" style="border-radius: 0 0 12px 12px;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="mb-3">
                    <label class="form-label small fw-bold">GitHub Repository URL</label>
                    <input type="text" name="git_repo_url" class="form-control form-control-sm" value="<?= htmlspecialchars($project['git_repo_url'] ?? '') ?>" placeholder="e.g. https://github.com/learnmore-dev/learnmore-website-1.git" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Local Directory Path (on your Server)</label>
                    <input type="text" name="local_code_path" class="form-control form-control-sm" value="<?= htmlspecialchars($project['local_code_path'] ?? '') ?>" placeholder="e.g. C:\Users\ADMIN\Desktop\learnmore-website-1" required>
                    <span class="text-muted small" style="font-size: 10px;">Git cloning, committing, and push processes will take place inside this folder.</span>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">GitHub Username</label>
                        <input type="text" name="github_user" class="form-control form-control-sm" value="<?= htmlspecialchars(($project['github_user'] ?? '') ?: 'kartik') ?>" placeholder="e.g. kartik" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">GitHub Access Token / Password</label>
                        <input type="password" name="github_token" class="form-control form-control-sm" value="<?= htmlspecialchars(($project['github_token'] ?? '') ?: '12345678') ?>" placeholder="e.g. 12345678" required>
                    </div>
                </div>
                <button type="submit" name="save_config" class="btn btn-primary btn-sm">
                    <i class="fas fa-save me-2"></i>Save Configuration & Start
                </button>
            </form>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= $success ?></div>
    <?php endif; ?>

    <?php if (!$project): ?>
        <div class="alert alert-warning text-center py-5">
            <i class="fas fa-folder-open fa-3x mb-3 text-muted"></i>
            <h4>No Active Projects</h4>
        </div>
    <?php else: ?>
        
        <?php if (empty($project['local_code_path']) || empty($project['git_repo_url'])): ?>
            <!-- Setup Warning -->
            <div class="card p-5 text-center border-dashed">
                <i class="fas fa-tools fa-3x mb-3 text-warning"></i>
                <h5>Connect Project Repo & Folder</h5>
                <p class="text-muted max-width-600 mx-auto">
                    Set your client's GitHub repo link (like <code>https://github.com/learnmore-dev/learnmore-website-1.git</code>) and the local folder path of the site using the <strong>"Configure Path & Repo"</strong> button above.
                </p>
            </div>
        <?php elseif (!is_dir($project['local_code_path'])): ?>
            <!-- Clone Required -->
            <div class="card p-5 text-center border-dashed">
                <i class="fas fa-cloud-download-alt fa-3x mb-3 text-primary"></i>
                <h5>Local Website Directory not found</h5>
                <p class="text-muted">
                    The path for the project <code><?= htmlspecialchars($project['local_code_path']) ?></code> does not exist. Clone it directly from the connected GitHub repo.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="d-flex flex-column align-items-center gap-3">
                        <div class="row g-2 justify-content-center" style="max-width:500px;">
                            <div class="col-6">
                                <input type="text" name="github_user" class="form-control form-control-sm" placeholder="GitHub Username">
                            </div>
                            <div class="col-6">
                                <input type="password" name="github_token" class="form-control form-control-sm" placeholder="Token (Required for Private Repos)">
                            </div>
                        </div>
                        <button type="submit" name="clone_repo" class="btn btn-primary">
                            <i class="fas fa-download me-2"></i>Clone Repository Now
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            
            <!-- Main Scan results & Push Form -->
            <form method="POST" id="deployForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="row g-4">
                    <!-- Left Panel: Code Analysis Scanner -->
                    <div class="col-lg-8">
                        <div class="card p-4 border-0 shadow-sm" style="border-radius: 16px;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><i class="fas fa-search-code text-primary me-2"></i>AI Code Scan Results</h5>
                                <span class="badge bg-primary">Scanned <?= count($scanResults) ?> files with issues</span>
                            </div>
                            
                            <p class="text-muted small">
                                AI has scanned the code of your connected project folder (<code><?= htmlspecialchars($project['local_code_path']) ?></code>):
                            </p>

                            <?php if (empty($scanResults)): ?>
                                <div class="alert alert-success py-4 text-center">
                                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                                    <h6>Your entire codebase is SEO friendly! No issues were found.</h6>
                                </div>
                            <?php else: ?>
                                <div class="accordion" id="scannerAccordion">
                                    <?php foreach ($scanResults as $fIdx => $fileData): ?>
                                        <div class="accordion-item border-0 mb-3 shadow-sm rounded">
                                            <h2 class="accordion-header rounded">
                                                <button class="accordion-button collapsed fw-bold text-dark rounded" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?= $fIdx ?>">
                                                    <i class="far fa-file-code text-secondary me-2"></i>
                                                    <?= clean($fileData['file']) ?>
                                                    <span class="badge bg-danger ms-auto me-2"><?= count($fileData['issues']) ?> Issues</span>
                                                </button>
                                            </h2>
                                            <div id="collapse_<?= $fIdx ?>" class="accordion-collapse collapse" data-bs-parent="#scannerAccordion">
                                                <div class="accordion-body bg-light">
                                                    <!-- View Full File Code Button -->
                                                    <button class="btn btn-xs btn-outline-secondary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#fullCode_<?= $fIdx ?>" style="font-size: 11px;">
                                                        <i class="fas fa-code me-1"></i>View Full File Code
                                                    </button>
                                                    <div class="collapse mb-3" id="fullCode_<?= $fIdx ?>">
                                                        <pre class="bg-dark text-white p-3 rounded" style="max-height: 250px; overflow-y: auto; font-size: 11px;"><?= htmlspecialchars(file_get_contents($fileData['full_path'])) ?></pre>
                                                    </div>
                                                    <?php foreach ($fileData['issues'] as $iIdx => $issue): ?>
                                                        <div class="card p-3 mb-3 border-0 shadow-sm">
                                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                                <strong class="text-danger small"><?= clean($issue['type']) ?></strong>
                                                                <div class="form-check">
                                                                    <input class="form-check-input border-primary" type="checkbox" name="fixes[]" value="<?= "{$fIdx}:{$iIdx}" ?>" id="check_<?= "{$fIdx}_{$iIdx}" ?>" checked>
                                                                    <label class="form-check-label small fw-bold text-primary" for="check_<?= "{$fIdx}_{$iIdx}" ?>">Approve Fix</label>
                                                                </div>
                                                            </div>
                                                            <p class="small text-muted mb-2"><?= clean($issue['detail']) ?></p>
                                                            
                                                            <?php if (!empty($issue['original'])): ?>
                                                                <div class="small mt-2 font-monospace">
                                                                    <div class="mb-1"><span class="badge bg-danger">Before (Live)</span></div>
                                                                    <div class="diff-del p-2 mb-2"><?= htmlspecialchars($issue['original']) ?></div>
                                                                    <div class="mb-1"><span class="badge bg-success">After (AI Proposed Fix)</span></div>
                                                                    <div class="diff-add p-2"><?= htmlspecialchars($issue['fixed']) ?></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Panel: Push Deploy controls -->
                    <div class="col-lg-4">
                        <div class="card p-4 border-0 shadow-sm mb-4" style="border-radius: 16px;">
                            <h5><i class="fas fa-paper-plane text-success me-2"></i>Approve & Deploy</h5>
                            
                            <div class="mb-3 mt-3">
                                <label class="form-label small fw-bold">GitHub Username</label>
                                <input type="text" name="github_user" class="form-control form-control-sm" value="<?= htmlspecialchars($project['github_user'] ?: 'kartik') ?>" placeholder="e.g. kartik" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">GitHub Access Token (PAT)</label>
                                <input type="password" name="github_token" class="form-control form-control-sm" value="<?= htmlspecialchars($project['github_token'] ?: '12345678') ?>" placeholder="ghp_••••••••••••" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Deploy Branch</label>
                                <input type="text" name="github_branch" class="form-control form-control-sm" value="ai-seo-fixes">
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="force_push" id="forcePushCheck">
                                <label class="form-check-label small text-muted" for="forcePushCheck">Force Commit & Push even if no new fixes</label>
                            </div>

                            <button type="submit" name="execute_git_push" class="btn btn-success w-100">
                                <i class="fas fa-thumbs-up me-2"></i>Approve & Push to GitHub
                            </button>
                        </div>

                        <!-- Git Terminal Logs -->
                        <?php if (!empty($gitLog)): ?>
                            <div class="card p-3 border-0 shadow-sm" style="border-radius: 16px;">
                                <h6 class="mb-2"><i class="fas fa-terminal me-2"></i>Deployment Logs</h6>
                                <div class="terminal-box">
                                    <?php foreach ($gitLog as $log): ?>
                                        <div><?= htmlspecialchars($log) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
</body>
</html>
