<?php
/**
 * selenium-bridge.php
 * PHP → Python Selenium bridge
 * Called by auto-poster.php for platforms that need real browser automation
 */

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    define('PYTHON_EXE', 'C:\\Users\\ADMIN\\AppData\\Local\\Programs\\Python\\Python311\\python.exe');
} else {
    define('PYTHON_EXE', '/usr/bin/python3');
}
define('SELENIUM_DIR', __DIR__);

/**
 * Run a Selenium Python script and return parsed JSON result
 */
function runSeleniumScript(string $script, array $args, int $timeout = 240): array {
    $scriptPath = SELENIUM_DIR . DIRECTORY_SEPARATOR . $script;
    if (!file_exists($scriptPath)) {
        return ['success' => false, 'error' => "Script not found: {$script}"];
    }

    // Build command — escape all args
    $isLinux = (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN');
    $xvfbPath = '';
    if ($isLinux) {
        $xvfbCheck = trim((string)shell_exec('which xvfb-run 2>/dev/null'));
        if (!empty($xvfbCheck) && file_exists($xvfbCheck)) {
            $xvfbPath = $xvfbCheck;
        }
    }

    if (!empty($xvfbPath)) {
        $cmd = escapeshellarg($xvfbPath) . ' -a ' . escapeshellarg(PYTHON_EXE) . ' ' . escapeshellarg($scriptPath);
    } else {
        $cmd = escapeshellarg(PYTHON_EXE) . ' ' . escapeshellarg($scriptPath);
    }
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }

    // Run with timeout
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    // Get the current running system user to prevent multi-user permission conflicts (e.g. between ubuntu and www-data)
    $systemUser = getenv('USER') ?: getenv('USERNAME');
    if (empty($systemUser) && function_exists('posix_getpwuid')) {
        $systemUser = posix_getpwuid(posix_geteuid())['name'] ?? '';
    }
    $systemUser = $systemUser ? preg_replace('/[^a-zA-Z0-9_-]/', '', $systemUser) : 'default';

    $appTmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp_dir_' . $systemUser;
    if (!file_exists($appTmpDir)) {
        @mkdir($appTmpDir, 0777, true);
    }
    @chmod($appTmpDir, 0777);
    $env['WDM_DIR'] = $appTmpDir . DIRECTORY_SEPARATOR . '.wdm';
    $env['HOME'] = $appTmpDir;
    $env['XDG_CONFIG_HOME'] = $appTmpDir;
    $env['XDG_CACHE_HOME'] = $appTmpDir;

    $process = proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        return ['success' => false, 'error' => 'Failed to start Python process'];
    }

    fclose($pipes[0]);

    // Read output with timeout
    $output = '';
    $stderr = '';
    $start  = time();

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    while (true) {
        $chunk = fread($pipes[1], 4096);
        $err   = fread($pipes[2], 4096);
        if ($chunk) $output .= $chunk;
        if ($err)   $stderr .= $err;

        if (feof($pipes[1]) && feof($pipes[2])) break;
        if ((time() - $start) > $timeout) {
            proc_terminate($process);
            $lines = array_filter(explode("\n", trim($output)));
            foreach ($lines as $line) {
                $decoded = json_decode(trim($line), true);
                if ($decoded !== null && isset($decoded['log'])) {
                    echo "[Python Log (Pre-timeout)] " . $decoded['log'] . "\n";
                }
            }
            return ['success' => false, 'error' => "Selenium timeout after {$timeout}s. Stderr: " . $stderr];
        }
        usleep(100000); // 100ms
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // Parse last JSON line from output (script prints JSON lines)
    $lines = array_filter(explode("\n", trim($output)));
    $lastResult = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        $decoded = json_decode($line, true);
        if ($decoded !== null) {
            if (isset($decoded['log'])) {
                echo "[Python Log] " . $decoded['log'] . "\n";
            }
            if (isset($decoded['success'])) {
                $lastResult = $decoded;
            }
        }
    }

    if ($lastResult !== null) {
        return $lastResult;
    }

    // No valid result found
    return [
        'success' => false,
        'error'   => 'No result from Selenium script. Stderr: ' . substr($stderr, 0, 5000),
        'raw'     => substr($output, 0, 5000),
    ];
}

/**
 * Decode base64 password stored in DB
 */
function decodePass(string $encoded): string {
    $decoded = base64_decode($encoded, true);
    return ($decoded !== false && trim($decoded) !== '') ? trim($decoded) : trim($encoded);
}

// ============================================================
// PINTEREST — Selenium auto-post
// ============================================================
function seleniumPinterest(array $creds, string $keyword, string $targetSite, int $projectId = 0): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($email) || empty($password)) {
        return ['error' => 'Pinterest: Add email + password in Social Accounts.'];
    }

    // Generate AI title + description
    require_once dirname(__DIR__) . '/ai-content.php';
    $postCount  = 1;
    $usedTitles = [];
    $businessName = '';
    $businessDesc = '';
    try {
        $db = getDB();
        $s  = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE platform='pinterest' AND status='created'");
        $s->execute();
        $postCount = (int)$s->fetchColumn() + 1;
        $s2 = $db->prepare("SELECT post_title FROM backlinks WHERE post_title IS NOT NULL ORDER BY created_at ASC LIMIT 50");
        $s2->execute();
        $usedTitles = $s2->fetchAll(PDO::FETCH_COLUMN);

        if ($projectId > 0) {
            $pStmt = $db->prepare("SELECT business_name, business_desc, phone, email, post_image FROM projects WHERE id=?");
            $pStmt->execute([$projectId]);
            $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($proj) {
                $businessName = $proj['business_name'] ?? '';
                $businessDesc = $proj['business_desc'] ?? '';
                $dbPhone      = $proj['phone'] ?? '';
                $dbEmail      = $proj['email'] ?? '';
                $projectImg   = $proj['post_image'] ?? '';
            }
        }
    } catch (Exception $e) {}

    $ai      = generateAIContent($keyword, $targetSite, 'pinterest', 'image_caption', '', OPENAI_API_KEY, $postCount, $usedTitles, $businessName, $businessDesc);
    $aiTitle = $ai['title'] ?? generateUniqueTitle($keyword, $postCount, $usedTitles, OPENAI_API_KEY);

    // Pinterest description: keyword-rich, long, with URL — max 500 chars
    $aiDesc = strip_tags($ai['content'] ?? '');
    if (empty($aiDesc)) {
        return ['error' => $ai['error'] ?? 'AI description generation failed for Pinterest. Check API keys.'];
    }
    // Keep max 500 chars (Pinterest limit)
    $aiDesc = mb_substr($aiDesc, 0, 500, 'UTF-8');

    // Find or generate vertical project image
    $imagePath = '';
    $uploadDir = dirname(__DIR__) . '/uploads/';

    // 1. Get project details including post_image (already loaded)

    // 2. Use project_image if exists
    if ($projectImg && file_exists($uploadDir . $projectImg)) {
        $imagePath = $uploadDir . $projectImg;
    } else {
        // 3. Try vertical image
        $verticalImage = $uploadDir . 'auto_img_vertical_' . $projectId . '.jpg';
        if (file_exists($verticalImage)) {
            $imagePath = $verticalImage;
        } else {
            // 4. Generate vertical image (1000x1500 px) on the fly
            require_once dirname(__DIR__) . '/image-generator.php';
            $phone = !empty($dbPhone) ? $dbPhone : '9036354554';
            $imgEmail = !empty($dbEmail) ? $dbEmail : 'office.learnmore@gmail.com';
            $res = generateMarketingImage($keyword, $targetSite, $phone, $imgEmail, $verticalImage, true);
            if (!empty($res['success'])) {
                $imagePath = $verticalImage;
            }
        }
    }

    if (empty($imagePath)) {
        // Fallback: Find latest project image
        $images = glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE);
        if ($images) {
            usort($images, fn($a, $b) => filemtime($b) - filemtime($a));
            $imagePath = $images[0];
        }
    }

    $args = [$email, $password, $keyword, $targetSite];
    if ($imagePath) $args[] = $imagePath;
    else             $args[] = '';          // placeholder so argv indices stay stable
    $args[] = $aiTitle;
    $args[] = $aiDesc;

    $result = runSeleniumScript('pinterest_post_playwright.py', $args, 240);

    if (!empty($result['success'])) {
        if (!empty($result['url']) && !empty($creds['id'])) {
            try {
                $db = getDB();
                $upStmt = $db->prepare("UPDATE social_accounts SET profile_url = ? WHERE id = ? AND (profile_url IS NULL OR profile_url = '')");
                $upStmt->execute([$result['url'], $creds['id']]);
            } catch (Exception $e) {}
        }
        return [
            'success'    => true,
            'url'        => $result['url'] ?: 'https://www.pinterest.com/me/',
            'source'     => 'Selenium',
            'post_title' => $aiTitle,
        ];
    }
    return ['error' => 'Pinterest Selenium failed: ' . ($result['error'] ?? 'Unknown error')];
}

// ============================================================
// MEDIUM — Selenium auto-post
// ============================================================
function seleniumMedium(array $creds, string $keyword, string $targetSite, string $content = '', int $projectId = 0): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($email) || empty($password)) {
        return ['error' => 'Medium: Add email + password in Social Accounts.'];
    }

    // Generate AI title + content
    require_once dirname(__DIR__) . '/ai-content.php';
    $postCount  = 1;
    $usedTitles = [];
    $businessName = '';
    $businessDesc = '';
    try {
        $db    = getDB();
        $stmt  = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE platform='medium' AND status='created'");
        $stmt->execute();
        $postCount = (int)$stmt->fetchColumn() + 1;
        $stmt2 = $db->prepare("SELECT post_title FROM backlinks WHERE post_title IS NOT NULL ORDER BY created_at ASC LIMIT 50");
        $stmt2->execute();
        $usedTitles = $stmt2->fetchAll(PDO::FETCH_COLUMN);

        if ($projectId > 0) {
            $pStmt = $db->prepare("SELECT business_name, business_desc FROM projects WHERE id=?");
            $pStmt->execute([$projectId]);
            $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($proj) {
                $businessName = $proj['business_name'] ?? '';
                $businessDesc = $proj['business_desc'] ?? '';
            }
        }
    } catch (Exception $e) {}

    $ai      = generateAIContent($keyword, $targetSite, 'medium', 'blog_post', '', OPENAI_API_KEY, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($content) && empty($ai['content'])) {
        return ['error' => $ai['error'] ?? 'AI content generation failed for Medium. Check API keys.'];
    }
    $aiTitle = $ai['title'] ?? generateUniqueTitle($keyword, $postCount, $usedTitles, OPENAI_API_KEY);
    $aiBody  = !empty($content) ? $content : strip_tags($ai['content']);

    // Get project image path
    $imgPath = '';
    $uploadDir = dirname(__DIR__) . '/uploads/';
    try {
        $db2 = getDB();
        // Find project by keyword match (Medium doesn't have projectId passed here)
        $imgFiles = glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE);
        if ($imgFiles) {
            usort($imgFiles, fn($a, $b) => filemtime($b) - filemtime($a));
            $imgPath = $imgFiles[0];
        }
    } catch (Exception $e) {}

    // Write content to temp file — avoids Windows command line 8191 char limit
    $tmpFile = sys_get_temp_dir() . '/medium_content_' . time() . '.txt';
    file_put_contents($tmpFile, $aiBody);

    $args   = [$email, $password, $keyword, $targetSite, $aiTitle, $imgPath, $tmpFile];
    $result = runSeleniumScript('medium_post.py', $args, 240);

    // Cleanup
    @unlink($tmpFile);

    if (!empty($result['success'])) {
        return ['success' => true, 'url' => $result['url'] ?: 'https://medium.com/me/stories', 'source' => 'Selenium', 'post_title' => $aiTitle];
    }
    return [
        'manual'     => true,
        'message'    => 'Medium Selenium: ' . ($result['error'] ?? 'Failed') . ' — Article ready to copy.',
        'url'        => 'https://medium.com/new-story',
        'source'     => 'Selenium Fallback',
        'post_title' => $aiTitle,
    ];
}

// ============================================================
// GENERIC — Selenium auto-post (substack, tumblr, scoopit, etc.)
// ============================================================
function seleniumGeneric(string $platform, array $creds, string $keyword, string $targetSite, string $content = ''): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($email) || empty($password)) {
        return ['error' => ucfirst($platform) . ': Add email + password in Social Accounts.'];
    }

    // Write content to temp file to preserve HTML tags and formatting
    $tmpFile = sys_get_temp_dir() . '/' . $platform . '_content_' . time() . '.txt';
    file_put_contents($tmpFile, $content);

    $args   = [$platform, $email, $password, $keyword, $targetSite, $tmpFile];
    $result = runSeleniumScript('generic_post.py', $args, 240);

    @unlink($tmpFile);

    if (!empty($result['success'])) {
        return ['success' => true, 'url' => $result['url'] ?: "https://www.{$platform}.com", 'source' => 'Selenium'];
    }

    $errorMsg = $result['error'] ?? 'Unknown error';
    return [
        'manual'  => true,
        'message' => ucfirst($platform) . ' Selenium failed: ' . $errorMsg . ' — Content ready to copy.',
        'url'     => "https://www.{$platform}.com",
        'source'  => 'Selenium Fallback',
    ];
}

// ============================================================
// MICRO BLOG PLATFORMS — Selenium via micro_blog_post.py
// Handles: scoopit, wakelet, padlet, pearltrees, mewe, instapaper, vivauae
// ============================================================
function seleniumMicroBlog(string $platform, array $creds, string $keyword, string $targetSite, string $aiTitle = '', string $aiContent = '', int $projectId = 0): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($email) || empty($password)) {
        return ['error' => ucfirst($platform) . ': Add email + password in Social Accounts.'];
    }

    // Generate AI title + content if not provided
    if (empty($aiTitle) || empty($aiContent)) {
        require_once dirname(__DIR__) . '/ai-content.php';
        $db = getDB();
        $postCount = 1;
        $usedTitles = [];
        $businessName = '';
        $businessDesc = '';
        try {
            // Try to get post count + used titles from DB
            $stmt = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE platform=? AND status='created'");
            $stmt->execute([$platform]);
            $postCount = (int)$stmt->fetchColumn() + 1;

            $stmt2 = $db->prepare("SELECT post_title FROM backlinks WHERE post_title IS NOT NULL ORDER BY created_at ASC LIMIT 50");
            $stmt2->execute();
            $usedTitles = $stmt2->fetchAll(PDO::FETCH_COLUMN);

            if ($projectId > 0) {
                $pStmt = $db->prepare("SELECT business_name, business_desc FROM projects WHERE id=?");
                $pStmt->execute([$projectId]);
                $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
                if ($proj) {
                    $businessName = $proj['business_name'] ?? '';
                    $businessDesc = $proj['business_desc'] ?? '';
                }
            }
        } catch (Exception $e) { /* ignore */ }

        $ai = generateAIContent($keyword, $targetSite, $platform, 'micro_blog', '', OPENAI_API_KEY, $postCount, $usedTitles, $businessName, $businessDesc);
        if (empty($ai['content'])) {
            return ['error' => $ai['error'] ?? 'AI content generation failed for ' . ucfirst($platform) . '. Check API keys.'];
        }
        $aiTitle   = $ai['title']   ?? generateUniqueTitle($keyword, $postCount, $usedTitles, OPENAI_API_KEY);
        $aiContent = strip_tags($ai['content']);
    }

    // Write content to temp file — avoids Windows CLI 8191 char limit
    $tmpFile = sys_get_temp_dir() . '/' . $platform . '_content_' . time() . '.txt';
    file_put_contents($tmpFile, $aiContent);

    $args   = [$platform, $email, $password, $keyword, $targetSite, $aiTitle, $tmpFile];
    $result = runSeleniumScript('micro_blog_post.py', $args, 240);

    @unlink($tmpFile);

    if (!empty($result['success'])) {
        return [
            'success'    => true,
            'url'        => $result['url'] ?: "https://www.{$platform}.com",
            'source'     => 'Selenium',
            'post_title' => $aiTitle,
        ];
    }

    $errorMsg = $result['error'] ?? 'Unknown error';
    return [
        'manual'     => true,
        'message'    => ucfirst($platform) . ': ' . $errorMsg,
        'url'        => "https://www.{$platform}.com",
        'source'     => 'Selenium Fallback',
        'post_title' => $aiTitle,
    ];
}

// ============================================================
// GHOST.IO — Selenium auto-post
// ============================================================
function seleniumGhost(array $creds, string $keyword, string $targetSite): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');
    if (empty($email) || empty($password)) {
        return ['error' => 'Ghost: Add email + password in credentials.'];
    }
    $args   = [$email, $password, $keyword, $targetSite];
    $result = runSeleniumScript('ghost_post.py', $args, 180);
    if (!empty($result['success'])) {
        return ['success' => true, 'url' => $result['url'] ?: 'https://ghost.io', 'source' => 'Selenium'];
    }
    return ['error' => 'Ghost Selenium failed: ' . ($result['error'] ?? 'Unknown')];
}

// ============================================================
// SITE123 — Selenium auto blog post via site123_add_post.py
// ============================================================
function seleniumSite123(array $creds, string $keyword, string $targetSite, int $projectId = 0): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($email) || empty($password)) {
        return ['error' => 'Site123: Add email + password in credentials.'];
    }

    require_once dirname(__DIR__) . '/ai-content.php';
    $postCount  = 1; $usedTitles = [];
    $businessName = '';
    $businessDesc = '';
    try {
        $db = getDB();
        $s  = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE platform='site123' AND status='created'"); $s->execute();
        $postCount = (int)$s->fetchColumn() + 1;
        $s2 = $db->prepare("SELECT post_title FROM backlinks WHERE post_title IS NOT NULL ORDER BY created_at ASC LIMIT 50"); $s2->execute();
        $usedTitles = $s2->fetchAll(PDO::FETCH_COLUMN);

        if ($projectId > 0) {
            $pStmt = $db->prepare("SELECT business_name, business_desc FROM projects WHERE id=?");
            $pStmt->execute([$projectId]);
            $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($proj) {
                $businessName = $proj['business_name'] ?? '';
                $businessDesc = $proj['business_desc'] ?? '';
            }
        }
    } catch (Exception $e) {}
    $ai      = generateAIContent($keyword, $targetSite, 'site123', 'blog_post', '', OPENAI_API_KEY, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) {
        return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
    }
    $aiTitle = $ai['title'] ?? generateUniqueTitle($keyword, $postCount, $usedTitles, OPENAI_API_KEY);
    $aiBody  = strip_tags($ai['content']);

    // Get project image path
    $imgPath = '';
    $enqueuedImg = function_exists('getEnqueuedImagePath') ? getEnqueuedImagePath() : null;
    if ($enqueuedImg && file_exists(dirname(__DIR__) . '/uploads/' . $enqueuedImg)) {
        $imgPath = dirname(__DIR__) . '/uploads/' . $enqueuedImg;
    } else {
        $imgFiles = glob(dirname(__DIR__) . '/uploads/*.{jpg,jpeg,png}', GLOB_BRACE);
        if ($imgFiles) {
            usort($imgFiles, fn($a, $b) => filemtime($b) - filemtime($a));
            $imgPath = $imgFiles[0];
        }
    }

    // Write content to temp file — avoids Windows CLI limit
    $tmpFile = sys_get_temp_dir() . '/site123_content_' . time() . '.txt';
    file_put_contents($tmpFile, $aiBody);

    $args   = [$email, $password, $keyword, $targetSite, $aiTitle, $imgPath, $tmpFile];
    $result = runSeleniumScript('site123_add_post.py', $args, 240);

    @unlink($tmpFile);

    if (!empty($result['success'])) {
        return ['success' => true, 'url' => $result['url'] ?: 'https://app.site123.com/blog?w=12200919', 'source' => 'Selenium', 'post_title' => $aiTitle];
    }
    return ['manual' => true, 'message' => 'Site123: ' . ($result['error'] ?? 'Unknown error'), 'url' => 'https://www.site123.com/my-websites', 'source' => 'Selenium Fallback', 'post_title' => $aiTitle];
}

// ============================================================
// PADLET — Selenium auto-post via padlet_post.py
// Uses system Chrome profile (browser must have Padlet logged in)
// Board: https://padlet.com/kanzariyapratik124/lmt-wb7faycbn66hp2z5
// ============================================================
function seleniumPadlet(array $creds, string $keyword, string $targetSite): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($email)) {
        return ['error' => 'Padlet: Add email credentials.'];
    }

    $args   = [$email, $password, $keyword, $targetSite];
    $result = runSeleniumScript('padlet_post.py', $args, 150);

    if (!empty($result['success'])) {
        return [
            'success' => true,
            'url'     => $result['url'] ?: 'https://padlet.com/kanzariyapratik124/lmt-wb7faycbn66hp2z5',
            'source'  => 'Selenium',
        ];
    }

    $errorMsg = $result['error'] ?? 'Unknown error';
    return [
        'manual'  => true,
        'message' => 'Padlet: ' . $errorMsg . ' — Make sure Chrome browser is closed before running Auto Post.',
        'url'     => 'https://padlet.com/kanzariyapratik124/lmt-wb7faycbn66hp2z5',
        'source'  => 'Selenium Fallback',
    ];
}

// ============================================================
// LIVEJOURNAL — Selenium auto-post via livejournal_post.py
// ============================================================
function seleniumLiveJournal(array $creds, string $keyword, string $targetSite, int $projectId = 0): array {
    $username = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($username)) {
        return ['error' => 'LiveJournal: Add username credentials.'];
    }

    require_once dirname(__DIR__) . '/ai-content.php';
    $postCount  = 1; $usedTitles = [];
    $businessName = '';
    $businessDesc = '';
    try {
        $db = getDB();
        $s  = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE platform='livejournal' AND status='created'"); $s->execute();
        $postCount = (int)$s->fetchColumn() + 1;
        $s2 = $db->prepare("SELECT post_title FROM backlinks WHERE post_title IS NOT NULL ORDER BY created_at ASC LIMIT 50"); $s2->execute();
        $usedTitles = $s2->fetchAll(PDO::FETCH_COLUMN);

        if ($projectId > 0) {
            $pStmt = $db->prepare("SELECT business_name, business_desc FROM projects WHERE id=?");
            $pStmt->execute([$projectId]);
            $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($proj) {
                $businessName = $proj['business_name'] ?? '';
                $businessDesc = $proj['business_desc'] ?? '';
            }
        }
    } catch (Exception $e) {}
    $ai      = generateAIContent($keyword, $targetSite, 'livejournal', 'blog_post', '', OPENAI_API_KEY, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) {
        return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
    }
    $aiTitle = $ai['title'] ?? generateUniqueTitle($keyword, $postCount, $usedTitles, OPENAI_API_KEY);
    // Keep HTML for LiveJournal — supports rich text with clickable links
    $aiBody  = $ai['content'];
    // Strip only dangerous tags, keep <a>, <h2>, <p>, <ul>, <li>, <strong>
    $aiBody = strip_tags($aiBody, '<a><h1><h2><h3><p><ul><ol><li><strong><em><br>');

    // Get project image path and resolve its public HTTP URL
    $imgPath = '';
    $enqueuedImg = function_exists('getEnqueuedImagePath') ? getEnqueuedImagePath() : null;
    if ($enqueuedImg && file_exists(dirname(__DIR__) . '/uploads/' . $enqueuedImg)) {
        $imgPath = dirname(__DIR__) . '/uploads/' . $enqueuedImg;
    } else {
        $imgFiles = glob(dirname(__DIR__) . '/uploads/*.{jpg,jpeg,png}', GLOB_BRACE);
        if ($imgFiles) {
            usort($imgFiles, fn($a, $b) => filemtime($b) - filemtime($a));
            $imgPath = $imgFiles[0];
        }
    }
    if (!empty($imgPath)) {
        $publicBase = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            // Check if detectSiteUrl() is defined
            $publicBase = function_exists('detectSiteUrl') ? detectSiteUrl() : 'http://' . $_SERVER['HTTP_HOST'];
        } else {
            // CLI fallback: try resolving public IP or use SITE_URL constant if not localhost
            if (defined('SITE_URL') && strpos(SITE_URL, 'localhost') === false) {
                $publicBase = SITE_URL;
            } else {
                $ip = trim(@file_get_contents('https://api.ipify.org', false, stream_context_create(['http' => ['timeout' => 2.5]])));
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $publicBase = 'http://' . $ip;
                } else {
                    $publicBase = defined('SITE_URL') ? SITE_URL : 'http://localhost';
                }
            }
        }
        $imgPath = rtrim($publicBase, '/') . '/uploads/' . basename($imgPath);
    }

    $args   = [$username, $password, $keyword, $targetSite, $aiTitle, $imgPath];

    // Write content to temp file — avoids Windows command line 8191 char limit
    $tmpFile = sys_get_temp_dir() . '/lj_content_' . time() . '.txt';
    file_put_contents($tmpFile, $aiBody);
    $args[] = $tmpFile;

    $result = runSeleniumScript('livejournal_post.py', $args, 240);

    // Cleanup temp file
    @unlink($tmpFile);

    if (!empty($result['success'])) {
        return ['success' => true, 'url' => $result['url'] ?: 'https://lmt-12.livejournal.com/', 'source' => 'Selenium', 'post_title' => $aiTitle];
    }
    return ['manual' => true, 'message' => 'LiveJournal: ' . ($result['error'] ?? 'Failed'), 'url' => 'https://www.livejournal.com/post/', 'source' => 'Selenium Fallback', 'post_title' => $aiTitle];
}

// ============================================================
// MINDS.COM — Playwright auto-post
// ============================================================
function seleniumMinds(array $creds, string $keyword, string $targetSite, string $aiTitle = '', string $aiContent = '', int $projectId = 0): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($email) || empty($password)) {
        return ['error' => 'Minds: Add email + password in Social Accounts.'];
    }

    if (empty($aiTitle) || empty($aiContent)) {
        require_once dirname(__DIR__) . '/ai-content.php';
        $db = getDB();
        $postCount = 1;
        $usedTitles = [];
        $businessName = '';
        $businessDesc = '';
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE platform='minds' AND status='created'");
            $stmt->execute();
            $postCount = (int)$stmt->fetchColumn() + 1;

            $stmt2 = $db->prepare("SELECT post_title FROM backlinks WHERE post_title IS NOT NULL ORDER BY created_at ASC LIMIT 50");
            $stmt2->execute();
            $usedTitles = $stmt2->fetchAll(PDO::FETCH_COLUMN);

            if ($projectId > 0) {
                $pStmt = $db->prepare("SELECT business_name, business_desc FROM projects WHERE id=?");
                $pStmt->execute([$projectId]);
                $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
                if ($proj) {
                    $businessName = $proj['business_name'] ?? '';
                    $businessDesc = $proj['business_desc'] ?? '';
                }
            }
        } catch (Exception $e) {}

        $ai = generateAIContent($keyword, $targetSite, 'minds', 'micro_blog', '', OPENAI_API_KEY, $postCount, $usedTitles, $businessName, $businessDesc);
        if (empty($ai['content'])) {
            return ['error' => $ai['error'] ?? 'AI content generation failed for Minds. Check API keys.'];
        }
        $aiTitle   = $ai['title']   ?? generateUniqueTitle($keyword, $postCount, $usedTitles, OPENAI_API_KEY);
        $aiContent = strip_tags($ai['content']);
    }

    $args   = [$email, $password, $keyword, $targetSite, $aiTitle, $aiContent];
    $result = runSeleniumScript('minds_post_playwright.py', $args, 240);

    if (!empty($result['success'])) {
        return [
            'success'    => true,
            'url'        => $result['url'] ?: "https://www.minds.com",
            'source'     => 'Playwright',
            'post_title' => $aiTitle,
        ];
    }

    $errorMsg = $result['error'] ?? 'Unknown error';
    return [
        'manual'     => true,
        'message'    => 'Minds: ' . $errorMsg,
        'url'        => "https://www.minds.com",
        'source'     => 'Playwright Fallback',
        'post_title' => $aiTitle,
    ];
}

// ============================================================
// LIVEJOURNAL — Playwright auto-post
// ============================================================
function seleniumLiveJournalPlaywright(array $creds, string $keyword, string $targetSite, int $projectId = 0): array {
    $username = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($username)) {
        return ['error' => 'LiveJournal: Add username credentials.'];
    }

    require_once dirname(__DIR__) . '/ai-content.php';
    $postCount  = 1; $usedTitles = [];
    $businessName = '';
    $businessDesc = '';
    try {
        $db = getDB();
        $s  = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE platform='livejournal' AND status='created'"); $s->execute();
        $postCount = (int)$s->fetchColumn() + 1;
        $s2 = $db->prepare("SELECT post_title FROM backlinks WHERE post_title IS NOT NULL ORDER BY created_at ASC LIMIT 50"); $s2->execute();
        $usedTitles = $s2->fetchAll(PDO::FETCH_COLUMN);

        if ($projectId > 0) {
            $pStmt = $db->prepare("SELECT business_name, business_desc FROM projects WHERE id=?");
            $pStmt->execute([$projectId]);
            $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($proj) {
                $businessName = $proj['business_name'] ?? '';
                $businessDesc = $proj['business_desc'] ?? '';
            }
        }
    } catch (Exception $e) {}
    $ai      = generateAIContent($keyword, $targetSite, 'livejournal', 'blog_post', '', OPENAI_API_KEY, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) {
        return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
    }
    $aiTitle = $ai['title'] ?? generateUniqueTitle($keyword, $postCount, $usedTitles, OPENAI_API_KEY);
    $aiBody  = $ai['content'];
    $aiBody = strip_tags($aiBody, '<a><h1><h2><h3><p><ul><ol><li><strong><em><br>');

    // Get project image path and resolve its public HTTP URL
    $imgPath = '';
    $enqueuedImg = function_exists('getEnqueuedImagePath') ? getEnqueuedImagePath() : null;
    if ($enqueuedImg && file_exists(dirname(__DIR__) . '/uploads/' . $enqueuedImg)) {
        $imgPath = dirname(__DIR__) . '/uploads/' . $enqueuedImg;
    } else {
        $imgFiles = glob(dirname(__DIR__) . '/uploads/*.{jpg,jpeg,png}', GLOB_BRACE);
        if ($imgFiles) {
            usort($imgFiles, fn($a, $b) => filemtime($b) - filemtime($a));
            $imgPath = $imgFiles[0];
        }
    }
    if (!empty($imgPath)) {
        
        $publicBase = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $publicBase = function_exists('detectSiteUrl') ? detectSiteUrl() : 'http://' . $_SERVER['HTTP_HOST'];
        } else {
            if (defined('SITE_URL') && strpos(SITE_URL, 'localhost') === false) {
                $publicBase = SITE_URL;
            } else {
                $ip = trim(@file_get_contents('https://api.ipify.org', false, stream_context_create(['http' => ['timeout' => 2.5]])));
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $publicBase = 'http://' . $ip;
                } else {
                    $publicBase = defined('SITE_URL') ? SITE_URL : 'http://localhost';
                }
            }
        }
        $imgPath = rtrim($publicBase, '/') . '/uploads/' . basename($imgPath);
    }

    $args   = [$username, $password, $keyword, $targetSite, $aiTitle, $imgPath];

    $tmpFile = sys_get_temp_dir() . '/lj_content_' . time() . '.txt';
    file_put_contents($tmpFile, $aiBody);
    $args[] = $tmpFile;

    $result = runSeleniumScript('livejournal_post_playwright.py', $args, 240);

    @unlink($tmpFile);

    if (!empty($result['success'])) {
        return ['success' => true, 'url' => $result['url'] ?: 'https://' . $username . '.livejournal.com/', 'source' => 'Playwright', 'post_title' => $aiTitle];
    }
    return ['manual' => true, 'message' => 'LiveJournal: ' . ($result['error'] ?? 'Failed'), 'url' => 'https://www.livejournal.com/post/', 'source' => 'Playwright Fallback', 'post_title' => $aiTitle];
}

// ============================================================
// WORDPRESS — Selenium auto-fix
// ============================================================
function seleniumWpAutoFix(int $projectId, string $wpUrl, string $wpUser, string $wpPassBase64, string $fixType, string $fixValue): array {
    $args = [
        $projectId,
        $wpUrl,
        $wpUser,
        $wpPassBase64,
        $fixType,
        $fixValue
    ];
    $result = runSeleniumScript('wp_autofix.py', $args, 120);
    if ($result['success'] ?? false) {
        return ['success' => true, 'url' => $result['url'] ?: $wpUrl, 'source' => 'Selenium'];
    }
    return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
}

// ============================================================
// GOOGLE SEARCH CONSOLE — Selenium verification
// ============================================================
function seleniumGscVerify(int $projectId, string $clientSite, string $wpUrl, string $wpUser, string $wpPassBase64): array {
    $args = [
        $projectId,
        $clientSite,
        $wpUrl,
        $wpUser,
        $wpPassBase64
    ];
    $result = runSeleniumScript('gsc_verify.py', $args, 180);
    if ($result['success'] ?? false) {
        return ['success' => true, 'url' => $result['url'] ?: 'https://search.google.com/search-console', 'source' => 'Selenium'];
    }
    return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
}

// ============================================================
// WORDPRESS HEADER CODE INJECTOR — Selenium
// ============================================================
function seleniumWpInsertHeaderCode(int $projectId, string $wpUrl, string $wpUser, string $wpPassBase64, string $blockType, string $codeToInsert): array {
    $codeBase64 = base64_encode($codeToInsert);
    $args = [
        $projectId,
        $wpUrl,
        $wpUser,
        $wpPassBase64,
        $blockType,
        $codeBase64
    ];
    $result = runSeleniumScript('wp_insert_header_code.py', $args, 120);
    if ($result['success'] ?? false) {
        return ['success' => true, 'url' => $result['url'] ?: $wpUrl, 'source' => 'Selenium'];
    }
    return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
}

// ============================================================
// GOOGLE BUSINESS PROFILE — Selenium auto-post
// ============================================================
function seleniumGbpPost(int $projectId, string $businessName, string $postText, ?string $imagePath): array {
    $nameBase64 = base64_encode($businessName);
    $textBase64 = base64_encode($postText);
    $imgPath    = $imagePath ?: 'none';
    
    $args = [
        $nameBase64,
        $textBase64,
        $imgPath
    ];
    
    $result = runSeleniumScript('gbp_poster.py', $args, 180);
    if ($result['success'] ?? false) {
        return ['success' => true, 'url' => $result['url'] ?: 'https://business.google.com', 'source' => 'Selenium'];
    }
    return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
}

// ============================================================
// SYMBALOO — Selenium auto-post
// ============================================================
function seleniumSymbaloo(array $creds, string $keyword, string $targetSite, string $aiDesc = ''): array {
    $email    = $creds['username'] ?? '';
    $password = decodePass($creds['password'] ?? '');

    if (empty($email) || empty($password)) {
        return ['error' => 'Symbaloo: Add email + password in credentials.'];
    }

    $customMix = $creds['api_key'] ?? '';
    $args      = [$email, $password, $keyword, $targetSite, $customMix, $aiDesc];
    $result    = runSeleniumScript('symbaloo_post_playwright.py', $args, 240);

    if (!empty($result['success'])) {
        return [
            'success'    => true,
            'url'        => $result['url'] ?: 'https://www.symbaloo.com/home/mix/13ePQXNM4g',
            'source'     => 'Selenium',
            'post_title' => $keyword,
        ];
    }

    $errorMsg = $result['error'] ?? 'Unknown error';
    return ['error' => 'Symbaloo: ' . $errorMsg];
}
