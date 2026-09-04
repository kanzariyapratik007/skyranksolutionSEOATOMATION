<?php
require_once 'config.php';
require_once 'ai-content.php';
require_once __DIR__ . '/selenium/selenium-bridge.php';  // Selenium bridge

$isDirectRequest = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);

if ($isDirectRequest) {
    requireLogin();
    set_time_limit(300);
}

$db = getDB();

if ($isDirectRequest) {
    $projectId   = (int) ($_GET['id'] ?? 0);
    $platform    = clean($_GET['platform'] ?? '');
    $allAccounts = isset($_GET['all_accounts']);

    $stmt = $db->prepare('SELECT * FROM projects WHERE id=? AND user_id=?');
    $stmt->execute([$projectId, $_SESSION['user_id']]);
    $project = $stmt->fetch();
    if (!$project) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Project not found']);
        exit;
    }

    // Package-level constraints check
    $package = $project['package_type'] ?? 'basic';
    if ($package === 'basic') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Backlink posting is disabled on the BASIC plan. Please upgrade to Standard or Premium.']);
        exit;
    }
    if ($package === 'standard') {
        $seleniumPlatforms = ['google_business', 'pinterest', 'behance', 'wakelet', 'vivauae', 'padlet', 'pearltrees', 'mewe', 'instapaper', 'livejournal', 'site123', 'symbaloo', 'penzu', 'linktree', 'scoopit'];
        if (in_array($platform, $seleniumPlatforms)) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Selenium / Manual posting (' . ucfirst($platform) . ') is not allowed on the STANDARD plan. Upgrade to Premium.']);
            exit;
        }
    }

    // Fetch ALL accounts for this platform matching this project_id
    $credStmt = $db->prepare('SELECT * FROM social_accounts WHERE project_id=? AND platform=? AND status="active" ORDER BY id ASC');
    $credStmt->execute([$projectId, $platform]);
    $allCreds = $credStmt->fetchAll();

    // If specific account ID requested (from auto-post-all parallel)
    $accountId = (int)($_GET['_account'] ?? 0);
    if ($accountId) {
        $allCreds = array_filter($allCreds, fn($c) => $c['id'] === $accountId);
        $allCreds = array_values($allCreds);
    }

    $creds = $allCreds[0] ?? null;

    if (!$creds) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No credentials saved for ' . $platform . '. Add credentials first.']);
        exit;
    }

    // If multiple accounts and all_accounts flag — post to ALL, return combined result
    if ($allAccounts && count($allCreds) > 1) {
        $keyword = !empty($_GET['keyword']) ? clean($_GET['keyword']) : $project['target_keyword'];
        $site    = $project['target_site'] ?: $project['website_url'];
        $results = [];
        $lastSuccess = null;
        $postedCount = 0;

        foreach ($allCreds as $oneCred) {
            $r = runPlatformAutoPost($platform, $oneCred, $project, $projectId);
            ensureManualContent($r, $platform, $project);
            $results[] = ['username' => $oneCred['username'], 'result' => $r];
            if (!empty($r['success'])) {
                savePostedBacklink($db, $projectId, $platform, $r);
                $lastSuccess = $r;
                $postedCount++;
            }
        }

        header('Content-Type: application/json');
        if ($lastSuccess) {
            echo json_encode([
                'success'      => true,
                'url'          => $lastSuccess['url'],
                'posted_count' => $postedCount,
                'results'      => $results,
                'source'       => 'Multi-account',
            ]);
        } else {
            // All failed — return last result (may be manual)
            $last = end($results)['result'];
            echo json_encode($last);
        }
        exit;
    }
}

// ============================================================
// WORDPRESS.COM - REST API
// Token: https://developer.wordpress.com/apps/
// ============================================================
function postToWordPress($apiKey, $wpUsername, $keyword, $targetSite, $geminiKey, $openaiKey, $postCount = 1, array $usedTitles = [], string $businessName = '', string $businessDesc = '') {
    if (empty($apiKey)) return ['error' => 'WordPress API token missing. Get from: https://developer.wordpress.com/apps/'];
    $wpSite = strpos($wpUsername, '.') !== false ? $wpUsername : $wpUsername . '.wordpress.com';
    $wpSite = str_replace(['https://', 'http://'], '', $wpSite);
    $ai     = generateAIContent($keyword, $targetSite, 'wordpress', 'article', '', $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
    $title = $ai['title'] ?? ucwords($keyword) . ' - Complete Training Guide ' . date('Y');
    $ch = curl_init("https://public-api.wordpress.com/rest/v1.1/sites/{$wpSite}/posts/new");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['title' => $title, 'content' => $ai['content'], 'status' => 'publish', 'tags' => $keyword . ',training,education']),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return array_merge(generateManualContent('wordpress', $keyword, $targetSite, $openaiKey), ['message' => 'WordPress connection error. Content ready - copy and post manually at wordpress.com.', 'url' => 'https://wordpress.com/post']);
    }
    $result = json_decode($response, true);
    if (isset($result['URL'])) return ['success' => true, 'url' => $result['URL'], 'source' => $ai['source'], 'post_title' => $title];
    if (in_array($httpCode, [400, 401]) && strpos($wpSite, '.wordpress.com') === false) {
        $altSite = $wpSite . '.wordpress.com';
        $ch2 = curl_init("https://public-api.wordpress.com/rest/v1.1/sites/{$altSite}/posts/new");
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['title' => $title, 'content' => $ai['content'], 'status' => 'publish', 'tags' => $keyword . ',training,education']),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response2 = curl_exec($ch2);
        $httpCode2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        $result2 = json_decode($response2, true);
        if (isset($result2['URL'])) return ['success' => true, 'url' => $result2['URL'], 'source' => $ai['source'], 'post_title' => $title];
    }

    // All attempts failed - return manual fallback
    return array_merge(generateManualContent('wordpress', $keyword, $targetSite, $openaiKey), ['message' => 'WordPress token/site mismatch (HTTP ' . $httpCode . '). Content ready - copy and post manually at wordpress.com. Check that your site is "learnmoretech.in.wordpress.com" and token matches.', 'url' => 'https://wordpress.com/post']);
}

/**
 * Get the project's post image path from DB.
 * Falls back to latest image in uploads/ folder.
 * Returns null if no image found.
 */
function getProjectImagePath(int $projectId): ?string {
    $uploadDir = __DIR__ . '/uploads/';
    
    // Check if there is a specific enqueued image for this background task
    $enqueuedImg = function_exists('getEnqueuedImagePath') ? getEnqueuedImagePath() : null;
    if ($enqueuedImg && file_exists($uploadDir . $enqueuedImg)) {
        return $uploadDir . $enqueuedImg;
    }

    // 1. Try project-specific image
    if ($projectId > 0) {
        try {
            $db  = getDB();
            $row = $db->prepare("SELECT post_image FROM projects WHERE id=?");
            $row->execute([$projectId]);
            $img = $row->fetchColumn();
            if ($img && file_exists($uploadDir . $img)) {
                return $uploadDir . $img;
            }
        } catch (Exception $e) {}
    }
    // 2. Fallback: latest auto-generated image
    $images = glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE);
    if ($images) {
        usort($images, fn($a, $b) => filemtime($b) - filemtime($a));
        return $images[0];
    }
    return null;
}

// ============================================================
// BLOGGER.COM - OAuth Bearer Token with Auto-Refresh
// ============================================================
function refreshBloggerToken($refreshToken) {
    if (empty($refreshToken)) return null;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => '407408718192.apps.googleusercontent.com',
            'client_secret' => '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CPAS', // OAuth Playground client
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $result['access_token'] ?? null;
}

function postToBlogger($accessToken, $blogId, $keyword, $targetSite, $openaiKey, $refreshToken = '', $postCount = 1, array $usedTitles = [], string $businessName = '', string $businessDesc = '') {
    if (empty($accessToken)) return ['error' => 'Blogger Access Token missing. Get from: https://developers.google.com/oauthplayground'];
    if (empty($blogId))      return ['error' => 'Blog ID missing. Enter Blog ID in the API Secret field.'];

    $ai    = generateAIContent($keyword, $targetSite, 'blogger', 'blog_post', '', $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
    $title = $ai['title'] ?? ucwords($keyword) . ' Training - ' . date('F Y');

    $content = $ai['content'];
    // Ensure targetSite is in the content as Anchor Text with the keyword
    $cleanSite = preg_replace('/^https?:\/\/(www\.)?/', '', $targetSite);
    if (stripos($content, $cleanSite) === false) {
        $content .= "<br><br><b>Learn More:</b> For more details, visit <a href=\"" . htmlspecialchars($targetSite) . "\">" . htmlspecialchars(ucwords($keyword)) . "</a>";
    }

    // Try post with current token
    $ch = curl_init("https://www.googleapis.com/blogger/v3/blogs/{$blogId}/posts/");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['kind' => 'blogger#post', 'title' => $title, 'content' => $content]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => 'Blogger curl error: ' . $error];
    $result = json_decode($response, true);
    if (isset($result['url'])) return ['success' => true, 'url' => $result['url'], 'source' => $ai['source'], 'post_title' => $title];

    // Token expired (401) â€” try refresh token
    if (isset($result['error']['code']) && $result['error']['code'] == 401 && !empty($refreshToken)) {
        $newToken = refreshBloggerToken($refreshToken);
        if ($newToken) {
            // Update token in DB (filter by user to avoid updating other users)
            $db = getDB();
            $db->prepare("UPDATE social_accounts SET api_key=? WHERE platform='blogger' AND user_id=(SELECT user_id FROM projects WHERE id=? LIMIT 1)")->execute([$newToken, 0]);

            // Retry with new token
            $ch = curl_init("https://www.googleapis.com/blogger/v3/blogs/{$blogId}/posts/");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['kind' => 'blogger#post', 'title' => $title, 'content' => $content]),
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $newToken, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result2 = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (isset($result2['url'])) return ['success' => true, 'url' => $result2['url'], 'source' => $ai['source'], 'post_title' => $title];
        }
        return [
            'manual'  => true,
            'message' => 'Blogger token expired - please re-authenticate at developers.google.com/oauthplayground',
            'title'   => $title,
            'content' => $content,
            'url'     => 'https://developers.google.com/oauthplayground',
            'source'  => $ai['source'],
        ];
    }

    // Other error - return clear manual fallback
    return [
        'manual'  => true,
        'message' => 'Blogger token expired - please re-authenticate at developers.google.com/oauthplayground',
        'title'   => $title,
        'content' => $ai['content'],
        'url'     => 'https://developers.google.com/oauthplayground',
        'source'  => $ai['source'],
    ];
}

// Helper to build signed OAuth 1.0a headers for Tumblr API
function getTumblrOAuthHeader($consumerKey, $consumerSecret, $token, $tokenSecret, $url, $method, $params) {
    $nonce = md5(uniqid(rand(), true));
    $timestamp = time();
    
    $oauthParams = [
        'oauth_consumer_key' => $consumerKey,
        'oauth_nonce' => $nonce,
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => $timestamp,
        'oauth_token' => $token,
        'oauth_version' => '1.0'
    ];
    
    // Merge OAuth parameters with request parameters (excluding binary/base64 data like data64)
    $sigParams = [];
    foreach ($params as $key => $val) {
        if ($key !== 'data64') {
            $sigParams[$key] = $val;
        }
    }
    $allParams = array_merge($oauthParams, $sigParams);
    ksort($allParams);
    
    // Build query string with normalized newlines (\n) to match Tumblr signature validation
    $queryParts = [];
    foreach ($allParams as $key => $val) {
        $normalizedVal = str_replace("\r\n", "\n", (string)$val);
        $queryParts[] = rawurlencode($key) . '=' . rawurlencode($normalizedVal);
    }
    $queryString = implode('&', $queryParts);
    
    // Base String
    $baseString = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($queryString);
    
    // Signature Key
    $signatureKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
    
    // Generate signature
    $signature = base64_encode(hash_hmac('sha1', $baseString, $signatureKey, true));
    
    // Add signature to OAuth parameters
    $oauthParams['oauth_signature'] = $signature;
    
    // Build Authorization Header
    $headerParts = [];
    foreach ($oauthParams as $key => $val) {
        $headerParts[] = $key . '="' . rawurlencode($val) . '"';
    }
    return 'Authorization: OAuth ' . implode(', ', $headerParts);
}

function postToTumblr($creds, $keyword, $targetSite, $geminiKey, $openaiKey, $postCount = 1, array $usedTitles = [], int $projectId = 0, string $businessName = '', string $businessDesc = '') {
    $consumerKey = $creds['api_key'] ?? '';
    $consumerSecret = $creds['api_secret'] ?? '';
    $blogName = $creds['username'] ?? '';
    
    if (empty($consumerKey) || empty($consumerSecret)) {
        return ['error' => 'Tumblr OAuth Consumer Key or Secret missing.'];
    }
    if (empty($blogName)) {
        return ['error' => 'Blog name missing. Enter yourblog.tumblr.com in Blog Hostname field.'];
    }
    
    $blogName = str_replace(['https://', 'http://'], '', $blogName);
    
    // Extract OAuth Token and Secret from password column (handles raw token:secret or base64 token:secret)
    $rawPass = $creds['password'] ?? '';
    $decrypted = base64_decode($rawPass, true);
    if ($decrypted === false || strpos($decrypted, ':') === false) {
        $decrypted = $rawPass;
    }
    $parts = explode(':', $decrypted);
    $oauthToken = trim($parts[0] ?? '');
    $oauthTokenSecret = trim($parts[1] ?? '');
    
    if (empty($oauthToken) || empty($oauthTokenSecret)) {
        return ['error' => 'Tumblr OAuth Token or Token Secret is missing. Click Explore API to authorize and get all 4 keys.'];
    }
    
    $ai = generateAIContent($keyword, $targetSite, 'tumblr', 'micro_blog', '', $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) {
        return ['error' => $ai['error'] ?? 'AI content generation failed. Check API keys.'];
    }
    $title = $ai['title'] ?? ucwords($keyword) . ' - ' . date('M Y');
    $url = "https://api.tumblr.com/v2/blog/{$blogName}/post";
    $bodyContent = str_replace("\r\n", "\n", $ai['content']);
    $postFields = [
        'type'  => 'text',
        'title' => $title,
        'body'  => $bodyContent,
        'tags'  => $keyword . ',training,education',
    ];
    
    // Generate OAuth 1.0a header
    $authHeader = getTumblrOAuthHeader($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret, $url, 'POST', $postFields);
    
    // 1.5s micro-delay to prevent Tumblr rate limits on back-to-back requests
    usleep(1500000);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER     => [$authHeader, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => 'Tumblr connection error: ' . $error];
    }
    
    $result = json_decode($response, true);
    if (isset($result['response']['id'])) {
        return [
            'success' => true,
            'url' => "https://{$blogName}/post/" . $result['response']['id'],
            'source' => $ai['source'],
            'post_title' => $title
        ];
    }
    
    // Auto-retry on 429 Rate Limit
    if ($httpCode === 429) {
        sleep(3);
        $authHeaderRetry = getTumblrOAuthHeader($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret, $url, 'POST', $postFields);
        $chRetry = curl_init($url);
        curl_setopt_array($chRetry, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postFields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER     => [$authHeaderRetry, 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($chRetry);
        $httpCode = (int) curl_getinfo($chRetry, CURLINFO_HTTP_CODE);
        curl_close($chRetry);
        $result = json_decode($response, true);
        if (isset($result['response']['id'])) {
            return [
                'success' => true,
                'url' => "https://{$blogName}/post/" . $result['response']['id'],
                'source' => $ai['source'],
                'post_title' => $title
            ];
        }
    }

    $msg = $result['meta']['msg'] ?? ($result['errors'][0]['detail'] ?? $response);
    if ($httpCode === 401) {
        return ['error' => "Tumblr API error (HTTP 401): Unauthorized. Re-authorize blog at http://" . ($_SERVER['HTTP_HOST'] ?? '54.210.197.187.nip.io') . "/scratch/tumblr_oauth_helper.php"];
    }
    return ['error' => "Tumblr API error (HTTP {$httpCode}): {$msg}"];
}

// ============================================================
// GITHUB - REST API
// Token: https://github.com/settings/tokens
// ============================================================
function postToGitHub($apiKey, $ghUsername, $keyword, $targetSite, $geminiKey, $openaiKey, $postCount = 1, array $usedTitles = [], string $businessName = '', string $businessDesc = '') {
    if (empty($apiKey)) return ['error' => 'GitHub token missing. Get from: https://github.com/settings/tokens'];
    $ai       = generateAIContent($keyword, $targetSite, 'github', 'blog_post', '', $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];

    // Unique repo name: keyword + postCount to avoid duplicates on re-run
    $repoSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $keyword));
    $repoSlug = trim($repoSlug, '-');
    // Keep repo name max 100 chars (GitHub limit) — include postCount for uniqueness
    $repoName = substr($repoSlug . '-guide-' . date('Y') . ($postCount > 1 ? '-v' . $postCount : ''), 0, 100);

    $postTitle = $ai['title'] ?? generateUniqueTitle($keyword, $postCount, [], OPENAI_API_KEY);
    
    // Convert HTML links to Markdown links first to preserve targetSite
    $content = $ai['content'] ?? '';
    $content = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $content);
    $content = strip_tags($content);
    
    $readme   = "# " . $postTitle . "\n\n"
              . $content
              . "\n\n## Learn More\nFor more information, visit [" . ucwords($keyword) . "](" . $targetSite . ")\n";

    // ── Step 1: Create repo ──────────────────────────────────
    $ch = curl_init('https://api.github.com/user/repos');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'name'        => $repoName,
            'description' => 'SEO guide for ' . $keyword . ' | ' . $targetSite,
            'private'     => false,
            'auto_init'   => false,
        ]),
        CURLOPT_HTTPHEADER     => ['Authorization: token ' . $apiKey, 'User-Agent: SEO-System', 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['error' => 'GitHub curl error: ' . $curlErr];
    }

    $repo = json_decode($response, true);

    // 401 = bad token
    if ($httpCode === 401) {
        return ['error' => 'GitHub token invalid/expired. Get new token: github.com/settings/tokens → Classic token → repo scope'];
    }

    // 422 = repo already exists — fetch existing repo info
    if ($httpCode === 422) {
        // Get authenticated user to build full_name
        $userCh = curl_init('https://api.github.com/user');
        curl_setopt_array($userCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: token ' . $apiKey, 'User-Agent: SEO-System'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $userResp = json_decode(curl_exec($userCh), true);
        curl_close($userCh);
        $login = $userResp['login'] ?? $ghUsername;
        $repo  = ['full_name' => $login . '/' . $repoName, 'html_url' => 'https://github.com/' . $login . '/' . $repoName];
    }

    if (empty($repo['full_name'])) {
        return ['error' => 'GitHub repo create failed (HTTP ' . $httpCode . '): ' . ($repo['message'] ?? $response)];
    }

    // ── Step 2: Check if README exists (get SHA for update) ──
    $sha = null;
    $checkCh = curl_init("https://api.github.com/repos/{$repo['full_name']}/contents/README.md");
    curl_setopt_array($checkCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: token ' . $apiKey, 'User-Agent: SEO-System'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $checkResp = json_decode(curl_exec($checkCh), true);
    curl_close($checkCh);
    if (!empty($checkResp['sha'])) {
        $sha = $checkResp['sha']; // existing file — need SHA for update
    }

    // ── Step 3: Create or Update README.md ───────────────────
    $putData = ['message' => 'Add SEO guide for ' . $keyword, 'content' => base64_encode($readme)];
    if ($sha) {
        $putData['message'] = 'Update SEO guide for ' . $keyword;
        $putData['sha']     = $sha;
    }

    $putCh = curl_init("https://api.github.com/repos/{$repo['full_name']}/contents/README.md");
    curl_setopt_array($putCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($putData),
        CURLOPT_HTTPHEADER     => ['Authorization: token ' . $apiKey, 'User-Agent: SEO-System', 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $putResp  = curl_exec($putCh);
    $putCode  = (int) curl_getinfo($putCh, CURLINFO_HTTP_CODE);
    $putErr   = curl_error($putCh);
    curl_close($putCh);

    if ($putErr) {
        return ['error' => 'GitHub README upload error: ' . $putErr];
    }

    $putResult = json_decode($putResp, true);

    // 200 = updated, 201 = created
    if (in_array($putCode, [200, 201]) && !empty($putResult['content'])) {
        return ['success' => true, 'url' => $repo['html_url'], 'source' => $ai['source'], 'post_title' => $title ?? null];
    }

    return ['error' => 'GitHub README upload failed (HTTP ' . $putCode . '): ' . ($putResult['message'] ?? $putResp)];
}

// ============================================================
// BLUESKY - AT Protocol API (100% Auto)
// No API key needed â€” just username + app password
// Get app password: bsky.app â†’ Settings â†’ App Passwords
// ============================================================
function postToBluesky($username, $appPassword, $keyword, $targetSite, $openaiKey, $projectId = 0, string $businessName = '', string $businessDesc = '', $postCount = 1, array $usedTitles = []) {
    if (empty($username))    return ['error' => 'Bluesky username missing.'];
    $username = trim($username);
    if (strpos($username, '@') === 0) {
        $username = substr($username, 1);
    }
    if (strpos($username, '.') === false) {
        $username .= '.bsky.social';
    }
    if (empty($appPassword)) return ['error' => 'Bluesky app password missing. Get from: bsky.app → Settings → App Passwords'];

    // Step 1: Create session
    $ch = curl_init('https://bsky.social/xrpc/com.atproto.server.createSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['identifier' => $username, 'password' => $appPassword]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $session = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!isset($session['accessJwt'])) {
        return ['error' => 'Bluesky login failed: ' . ($session['message'] ?? json_encode($session)) . ' — verify your Bluesky handle and App Password from bsky.app/settings/app-passwords'];
    }

    // Generate AI content using the specific 'bluesky_post' prompt
    $ai  = generateAIContent($keyword, $targetSite, 'bluesky', 'bluesky_post', '', $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) {
        return ['error' => $ai['error'] ?? 'AI content generation failed for Bluesky.'];
    }
    $text = mb_substr(strip_tags($ai['content']), 0, 299, 'UTF-8');

    // Ensure targetSite is included in the text for clickable facet link
    $cleanSite = preg_replace('/^https?:\/\/(www\.)?/', '', $targetSite);
    if (stripos($text, $cleanSite) === false) {
        $suffix = " " . $targetSite;
        $maxTextLen = 299 - strlen($suffix);
        if (mb_strlen($text, 'UTF-8') > $maxTextLen) {
            $text = mb_substr($text, 0, $maxTextLen, 'UTF-8');
        }
        $text .= $suffix;
    }

    // Step 2: Get project image from DB or queue
    $imageBlob = null;
    $imgData   = null;
    $mime      = 'image/jpeg';

    if ($projectId > 0) {
        $projectImg = function_exists('getEnqueuedImagePath') ? getEnqueuedImagePath() : null;
        if (!$projectImg) {
            $dbConn = getDB(); // use getDB() instead of $db
            $imgRow = $dbConn->prepare("SELECT post_image FROM projects WHERE id=?");
            $imgRow->execute([$projectId]);
            $projectImg = $imgRow->fetchColumn();
        }
        if ($projectImg && file_exists(__DIR__ . '/uploads/' . $projectImg)) {
            $imgData = file_get_contents(__DIR__ . '/uploads/' . $projectImg);
            $ext     = strtolower(pathinfo($projectImg, PATHINFO_EXTENSION));
            $mime    = ($ext === 'png') ? 'image/png' : 'image/jpeg';
        }
    }

    // Fallback: Picsum placeholder
    if (!$imgData) {
        $imgData = @file_get_contents('https://picsum.photos/seed/' . urlencode($keyword) . '/800/400');
        $mime    = 'image/jpeg';
    }

    if ($imgData) {
        // Compress image if it exceeds 1.5MB to prevent Bluesky blob too big error (max 2,000,000 bytes)
        $originalLength = strlen($imgData);
        $imgData = compressImageIfNeeded($imgData);
        if (strlen($imgData) < $originalLength) {
            $mime = 'image/jpeg';
        }
        $ch = curl_init('https://bsky.social/xrpc/com.atproto.repo.uploadBlob');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $imgData,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $session['accessJwt'],
                'Content-Type: ' . $mime,
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $blobResult = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (isset($blobResult['blob'])) {
            $imageBlob = $blobResult['blob'];
        }
    }

    // Step 4: Create post with clickable link facet (prefers Keyword Anchor Text)
    $cleanSite = preg_replace('/^https?:\/\/(www\.)?/', '', $targetSite);
    
    // First try to find the Keyword in the text to turn it into an Anchor Link
    $linkStart = mb_stripos($text, $keyword, 0, 'UTF-8');
    $matchedTerm = $keyword;
    
    if ($linkStart === false) {
        // Fallback to finding URL strings
        $linkStart = mb_strpos($text, $targetSite, 0, 'UTF-8');
        $matchedTerm = $targetSite;
        if ($linkStart === false) {
            $linkStart = mb_strpos($text, $cleanSite, 0, 'UTF-8');
            $matchedTerm = $cleanSite;
        }
        if ($linkStart === false) {
            $wwwSite = 'www.' . $cleanSite;
            $linkStart = mb_strpos($text, $wwwSite, 0, 'UTF-8');
            $matchedTerm = $wwwSite;
        }
    }

    $record = [
        '$type'     => 'app.bsky.feed.post',
        'text'      => $text,
        'createdAt' => date('c'),
        'langs'     => ['en'],
    ];

    // Add clickable link facet (turns the keyword or URL into a clickable link)
    if ($linkStart !== false) {
        // Get the exact casing of the term from the text
        $matchedTermText = mb_substr($text, $linkStart, mb_strlen($matchedTerm, 'UTF-8'), 'UTF-8');
        $linkEnd = $linkStart + mb_strlen($matchedTermText, 'UTF-8');
        $record['facets'] = [[
            'index' => [
                'byteStart' => strlen(mb_substr($text, 0, $linkStart, 'UTF-8')),
                'byteEnd'   => strlen(mb_substr($text, 0, $linkEnd, 'UTF-8')),
            ],
            'features' => [[
                '$type' => 'app.bsky.richtext.facet#link',
                'uri'   => $targetSite, // The actual redirect URL (with https://)
            ]],
        ]];
    }

    if ($imageBlob) {
        $record['embed'] = [
            '$type'  => 'app.bsky.embed.images',
            'images' => [[
                'alt'   => ucwords($keyword) . ' Training',
                'image' => $imageBlob,
            ]],
        ];
    }

    // Step 4: Create post
    $ch = curl_init('https://bsky.social/xrpc/com.atproto.repo.createRecord');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'repo'       => $session['did'],
            'collection' => 'app.bsky.feed.post',
            'record'     => $record,
        ]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $session['accessJwt'], 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($result['uri'])) {
        $handle = $session['handle'] ?? $username;
        $rkey   = explode('/', $result['uri']);
        $url    = 'https://bsky.app/profile/' . $handle . '/post/' . end($rkey);
        return ['success' => true, 'url' => $url, 'source' => 'Template + Image', 'post_title' => $title ?? null];
    }
    return ['error' => 'Bluesky post failed: ' . json_encode($result)];
}

// ============================================================
// MINDS.COM - REST API with username/password auth
// ============================================================
function postToMinds($apiToken, $keyword, $targetSite, $geminiKey, $openaiKey, $postCount = 1, array $usedTitles = [], string $businessName = '', string $businessDesc = '') {
    if (empty($apiToken)) return ['error' => 'Minds credentials missing.'];

    // apiToken field contains "username:password" or just access token
    // Try direct Bearer token first
    $ai      = generateAIContent($keyword, $targetSite, 'minds', 'micro_blog', '', $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
    $message = strip_tags($ai['content']);
    $message = substr($message, 0, 1500) . ' ' . $targetSite;

    // Try with Bearer token
    $ch = curl_init('https://www.minds.com/api/v1/newsfeed');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'message'   => $message,
            'access_id' => 2,
            'mature'    => 0,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
            'X-Version: 2',
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => 'Minds curl error: ' . $error];
    $result = json_decode($response, true);
    if (isset($result['guid'])) {
        return ['success' => true, 'url' => 'https://www.minds.com/newsfeed/' . $result['guid'], 'source' => $ai['source'], 'post_title' => $title ?? null];
    }

    // Fallback: manual content
    return [
        'manual'  => true,
        'message' => 'Minds.com requires OAuth login. Content ready â€” copy and post manually.',
        'title'   => ucwords($keyword) . ' Training',
        'content' => $message,
        'url'     => 'https://www.minds.com/newsfeed',
        'source'  => $ai['source'],
    ];
}

// ============================================================
// PLURK - REST API (100% Auto)
// Token: plurk.com/API/Apps â†’ Create App â†’ Get OAuth token
// ============================================================
function postToPlurk($apiKey, $apiSecret, $keyword, $targetSite, $geminiKey, $openaiKey, $postCount = 1, array $usedTitles = [], string $businessName = '', string $businessDesc = '') {
    if (empty($apiKey)) return ['error' => 'Plurk API key missing. Get from: plurk.com/API/Apps'];

    $ai      = generateAIContent($keyword, $targetSite, 'plurk', 'micro_blog', '', $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
    $content = strip_tags($ai['content']);
    $content = substr($content, 0, 210) . ' ' . $targetSite;

    $ch = curl_init('https://www.plurk.com/APP/Timeline/plurkAdd');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'api_key'   => $apiKey,
            'content'   => $content,
            'qualifier' => 'shares',
            'lang'      => 'en',
        ]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => 'Plurk curl error: ' . $error];
    $result = json_decode($response, true);
    if (isset($result['plurk_id'])) {
        return ['success' => true, 'url' => 'https://www.plurk.com/p/' . base_convert($result['plurk_id'], 10, 36), 'source' => $ai['source'], 'post_title' => $title ?? null];
    }
    return ['error' => 'Plurk post failed: ' . ($result['error_text'] ?? "HTTP $httpCode: " . $response)];
}
// Token: https://dev.to/settings/extensions â†’ API Keys
// ============================================================
function postToDevTo($apiKey, $keyword, $targetSite, $geminiKey, $openaiKey, $postCount = 1, array $usedTitles = [], int $projectId = 0, string $businessName = '', string $businessDesc = '') {
    if (empty($apiKey)) return ['error' => 'Dev.to API key missing. Get from: https://dev.to/settings/extensions'];

    $ai    = generateAIContent($keyword, $targetSite, 'devto', 'blog_post', $geminiKey, $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];

    $title = $ai['title'] ?? ucwords($keyword) . ' - Complete Guide ' . date('Y');
    // Dev.to tag rules: max 30 chars, only lowercase alphanumeric (no hyphens/spaces/special chars)
    // Split keyword by spaces AND hyphens to handle both "python training" and "python-training"
    $wordSplit = preg_split('/[\s\-_]+/', strtolower($keyword));
    $rawTags = array_merge(
        array_map('trim', $wordSplit),
        ['training', 'education', 'career']
    );
    $tags = [];
    $seen = [];
    foreach ($rawTags as $t) {
        $t = preg_replace('/[^a-z0-9]/', '', strtolower($t)); // remove all non-alphanumeric
        if (strlen($t) >= 2 && strlen($t) <= 30 && !isset($seen[$t])) {
            $tags[] = $t;
            $seen[$t] = true;
        }
        if (count($tags) >= 4) break;
    }
    if (empty($tags)) $tags = ['training', 'education'];

    // Convert HTML links to Markdown links first to preserve targetSite
    $content = $ai['content'] ?? '';
    $content = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $content);
    $bodyMarkdown = strip_tags($content);

    // Ensure targetSite is in the content (either as absolute URL or markdown link)
    $cleanSite = preg_replace('/^https?:\/\/(www\.)?/', '', $targetSite);
    if (stripos($bodyMarkdown, $cleanSite) === false) {
        $bodyMarkdown .= "\n\n## Learn More\nFor more information, visit [" . ucwords($keyword) . "](" . $targetSite . ")";
    }

    $ch = curl_init('https://dev.to/api/articles');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'article' => [
                'title'          => $title,
                'body_markdown'  => $bodyMarkdown,
                'published'      => true,
                'tags'           => $tags,
            ]
        ]),
        CURLOPT_HTTPHEADER     => ['api-key: ' . $apiKey, 'Content-Type: application/json', 'User-Agent: SEO-System'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201) {
        $result = json_decode($response, true);
        if (isset($result['url'])) return ['success' => true, 'url' => $result['url'], 'source' => $ai['source'], 'post_title' => $title];
    }
    $result = json_decode($response, true);
    return ['error' => 'Dev.to HTTP ' . $httpCode . ': ' . ($result['error'] ?? $response)];
}

// ============================================================
// HASHNODE - GraphQL API
// HASHNODE - GraphQL API (PAID since May 2026)
// Free accounts: content generated + manual paste at hashnode.com
// Pro accounts:  API Token from hashnode.com/settings/developer
// ============================================================
function postToHashnode($apiKey, $publicationId, $keyword, $targetSite, $geminiKey, $openaiKey, $postCount = 1, array $usedTitles = [], string $businessName = '', string $businessDesc = '') {
    if (empty($apiKey)) return ['error' => 'Hashnode API key missing. Get from: https://hashnode.com/settings/developer'];

    $ai = generateAIContent($keyword, $targetSite, 'hashnode', 'blog_post', '', $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];

    $title = $ai['title'] ?? ucwords($keyword) . ' Training Guide - ' . date('Y');
    $slug    = substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-guide-' . date('Y'), 0, 100);
    $slug    = trim($slug, '-');
    // Convert HTML links to Markdown links first to preserve targetSite
    $content = $ai['content'] ?? '';
    $content = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $content);
    $content = strip_tags($content);

    // Ensure targetSite is in the content
    $cleanSite = preg_replace('/^https?:\/\/(www\.)?/', '', $targetSite);
    if (stripos($content, $cleanSite) === false) {
        $content .= "\n\n## Learn More\nVisit [" . $targetSite . "](" . $targetSite . ")";
    }

    // Try API (Pro accounts only)
    $pubId = $publicationId ?: null;
    if (!$pubId) {
        // Auto-fetch publication ID
        $meQuery = '{ me { publications(first: 1) { edges { node { id title } } } } }';
        $meCh = curl_init('https://gql.hashnode.com/');
        curl_setopt_array($meCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['query' => $meQuery]),
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $meResp = curl_exec($meCh);
        $meCode = (int)curl_getinfo($meCh, CURLINFO_HTTP_CODE);
        curl_close($meCh);
        if ($meCode === 200) {
            $meData = json_decode($meResp, true);
            $pubId  = $meData['data']['me']['publications']['edges'][0]['node']['id'] ?? null;
        }
    }

    // If we have pubId, try to publish (Pro plan)
    if ($pubId) {
        $query = 'mutation PublishPost($input: PublishPostInput!) { publishPost(input: $input) { post { url } } }';
        $vars  = ['input' => ['title' => $title, 'contentMarkdown' => $content, 'slug' => $slug, 'tags' => [], 'publicationId' => $pubId]];
        $ch = curl_init('https://gql.hashnode.com/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['query' => $query, 'variables' => $vars]),
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $res = json_decode($resp, true);
        $url = $res['data']['publishPost']['post']['url'] ?? null;
        if ($url) return ['success' => true, 'url' => $url, 'source' => $ai['source'], 'post_title' => $title ?? null];

        // Slug conflict retry
        $gqlErr = $res['errors'][0]['message'] ?? '';
        if (stripos($gqlErr, 'slug') !== false) {
            $vars['input']['slug'] = $slug . '-' . time();
            $ch2 = curl_init('https://gql.hashnode.com/');
            curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>json_encode(['query'=>$query,'variables'=>$vars]),
                CURLOPT_HTTPHEADER=>['Authorization: '.$apiKey,'Content-Type: application/json'],
                CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
            $resp2 = curl_exec($ch2); curl_close($ch2);
            $res2  = json_decode($resp2, true);
            $url2  = $res2['data']['publishPost']['post']['url'] ?? null;
            if ($url2) return ['success' => true, 'url' => $url2, 'source' => $ai['source'], 'post_title' => $title ?? null];
        }
    }

    // Hashnode API is paid (May 2026+) — return content for manual paste
    // Content is fully generated and ready to copy-paste
    return [
        'manual'  => true,
        'message' => 'Hashnode API requires Pro plan (paid). Content ready — copy title & content below, paste at hashnode.com/new',
        'title'   => $title,
        'content' => $content,
        'url'     => 'https://hashnode.com/new',
        'source'  => $ai['source'],
    ];
}

// ============================================================
// GHOST.IO - Admin API (100% Auto)
// Token: Ghost Admin â†’ Integrations â†’ Add Custom Integration
// ============================================================
function postToGhost($apiKey, $ghostUrl, $keyword, $targetSite, $geminiKey, $openaiKey, $postCount = 1, array $usedTitles = []) {
    if (empty($apiKey))   return ['error' => 'Ghost API key missing. Get from: Ghost Admin â†’ Integrations'];
    if (empty($ghostUrl)) return ['error' => 'Ghost URL missing. Enter your Ghost site URL in username field.'];
    $ghostUrl = rtrim(str_replace(['https://', 'http://'], '', $ghostUrl), '/');
    $ai    = generateAIContent($keyword, $targetSite, 'ghost', 'blog_post', '', $openaiKey, $postCount, $usedTitles); // already set via postCount in signature;
    if (empty($ai['content'])) return ['error' => 'AI content generation failed. Check OpenAI API key.'];
    $title = $ai['title'] ?? ucwords($keyword) . ' - ' . date('F Y');

    // Ghost uses JWT for auth — must use Base64URL (no +/= chars)
    list($id, $secret) = explode(':', $apiKey) + ['', ''];
    $b64url = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $header  = $b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $id]));
    $payload = $b64url(json_encode(['iat' => time(), 'exp' => time() + 300, 'aud' => '/admin/']));
    $sig     = hash_hmac('sha256', $header . '.' . $payload, hex2bin($secret), true);
    $jwt     = $header . '.' . $payload . '.' . $b64url($sig);

    $ch = curl_init("https://{$ghostUrl}/ghost/api/admin/posts/");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['posts' => [['title' => $title, 'html' => $ai['content'], 'status' => 'published']]]),
        CURLOPT_HTTPHEADER     => ['Authorization: Ghost ' . $jwt, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => 'Ghost curl error: ' . $error];
    $result = json_decode($response, true);
    $url = $result['posts'][0]['url'] ?? null;
    if ($url) return ['success' => true, 'url' => $url, 'source' => $ai['source'], 'post_title' => $title ?? null];
    return ['error' => 'Ghost failed: ' . ($result['errors'][0]['message'] ?? "HTTP $httpCode: " . $response)];
}

// ============================================================
// PINTEREST - API v5 (pins:write needs approval, use manual with content)
// Token: developers.pinterest.com/apps
// ============================================================
function postToPinterest($accessToken, $keyword, $targetSite, $geminiKey, $openaiKey) {
    if (empty($accessToken)) return ['error' => 'Pinterest token missing'];

    // Try to get boards first
    $ch = curl_init('https://api.pinterest.com/v5/boards');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        $ai = generateAIContent($keyword, $targetSite, 'pinterest', 'image_caption', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
        $desc = mb_substr(strip_tags($ai['content'] ?? ''), 0, 500, 'UTF-8');
        return ['manual' => true, 'message' => 'Pinterest token expired - get a new token from developers.pinterest.com/apps. Content ready - copy and post manually.', 'title' => ucwords($keyword) . ' Training - ' . date('Y'), 'content' => $desc, 'url' => 'https://www.pinterest.com/pin-builder/', 'source' => $ai['source'] ?? 'AI'];
    }
    $boards = json_decode($response, true);

    // Generate AI content for Pinterest
    $ai          = generateAIContent($keyword, $targetSite, 'pinterest', 'image_caption', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    if (empty($ai['content'])) return ['error' => 'AI content generation failed. Check OpenAI API key.'];
    $description = strip_tags($ai['content']);
    $description = mb_substr($description, 0, 500, 'UTF-8');
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);

    // 401 = token expired - fall back to manual
    if ($httpCode === 401) {
        return ['manual' => true, 'message' => 'Pinterest token expired - get a new token from developers.pinterest.com/apps. Content ready - copy and post manually.', 'title' => $title, 'content' => $description, 'url' => 'https://www.pinterest.com/pin-builder/', 'source' => $ai['source'], 'post_title' => $title ?? null];
    }

    // If boards available and write access, try to create pin
    if (isset($boards['items']) && count($boards['items']) > 0) {
        $boardId = $boards['items'][0]['id'];

        $pinData = [
            'title'       => $title,
            'description' => $description,
            'link'        => $targetSite,
            'board_id'    => $boardId,
            'media_source' => [
                'source_type' => 'image_url',
                'url'         => 'https://picsum.photos/seed/' . urlencode($keyword) . '/1000/1500',
            ],
        ];

        $ch = curl_init('https://api.pinterest.com/v5/pins');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($pinData),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $pinHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $pinError = curl_error($ch);
        curl_close($ch);

        if (!$pinError) {
            $result = json_decode($response, true);
            if (isset($result['id'])) {
                return ['success' => true, 'url' => 'https://www.pinterest.com/pin/' . $result['id'], 'source' => 'Pinterest API', 'post_title' => $title ?? null];
            }
        }
        // Pin creation failed - fall through to manual
    }

    // Fallback: manual content
    return [
        'manual'  => true,
        'message' => 'Pinterest API requires write permission approval. Content ready â€” copy and post manually.',
        'title'   => $title,
        'content' => $description,
        'url'     => 'https://www.pinterest.com/pin-builder/',
        'source'  => $ai['source'],
    ];
}
// ============================================================
// SHARED HELPER â€” Convert target URL to PDF via Chrome Headless
// Uses locally installed Chrome --print-to-pdf
// Fallback: fetch HTML â†’ extract text â†’ pure PHP PDF
// ============================================================
function fetchUrlContentForPdf($url, $keyword, $targetSite) {

    // â”€â”€ Try Chrome Headless â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $chromePaths = [
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        getenv('LOCALAPPDATA') . '\Google\Chrome\Application\chrome.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
    ];
    $chrome = null;
    foreach ($chromePaths as $p) {
        if ($p && file_exists($p)) { $chrome = $p; break; }
    }

    if ($chrome) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $outPdf = $uploadDir . 'chrome-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-' . time() . '.pdf';

        $chromeEsc = escapeshellarg($chrome);
        $outEsc    = escapeshellarg($outPdf);
        $urlEsc    = escapeshellarg($url);

        // CSS to inject: hide popups/modals, fix dark backgrounds, force white bg
        $css = 'body,html{background:#fff!important;color:#000!important}'
             . '.modal,.popup,.overlay,.cookie-banner,[class*="modal"],[class*="popup"],'
             . '[id*="modal"],[id*="popup"],[class*="cookie"],[class*="overlay"],'
             . '.mfp-wrap,.mfp-overlay,.fancybox-overlay,.pum-overlay,'
             . '[class*="enquiry"],[id*="enquiry"]{display:none!important;visibility:hidden!important}'
             . 'nav,header .nav,footer{display:none!important}'
             . '*{background-color:transparent!important;color:#000!important}'
             . 'body,main,article,section,div,p,h1,h2,h3,h4,h5,h6,li,td,th'
             . '{background:#fff!important;color:#000!important}'
             . 'a{color:#1a0dab!important}';

        // Save CSS to temp file
        $cssFile = $uploadDir . 'print-override-' . time() . '.css';
        file_put_contents($cssFile, $css);
        $cssEsc = escapeshellarg('file:///' . str_replace('\\', '/', $cssFile));

        $cmd = "{$chromeEsc} --headless=new --disable-gpu --no-sandbox "
             . "--print-to-pdf={$outEsc} --print-to-pdf-no-header "
             . "--virtual-time-budget=8000 "
             . "--run-all-compositor-stages-before-draw "
             . "--disable-popup-blocking "
             . "--user-style-sheet={$cssEsc} "
             . "{$urlEsc}";

        @exec($cmd . ' 2>&1', $cmdOut, $exitCode);

        // Wait up to 20s for file to appear
        for ($w = 0; $w < 20; $w++) {
            if (file_exists($outPdf) && filesize($outPdf) > 500) break;
            sleep(1);
        }

        @unlink($cssFile); // clean temp CSS

        if (file_exists($outPdf) && filesize($outPdf) > 500) {
            $pdfBin = file_get_contents($outPdf);
            @unlink($outPdf);
            return ['pdf_binary' => $pdfBin, 'title' => null];
        }
    }

    // â”€â”€ Fallback: fetch HTML and extract text â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
                       . "Accept: text/html,application/xhtml+xml\r\n",
            'timeout'       => 20,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return null;

    $pageTitle = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $pageTitle = html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
        $pageTitle = trim(preg_replace('/\s+/', ' ', $pageTitle));
    }
    if (!$pageTitle) $pageTitle = 'Best ' . ucwords($keyword) . ' Guide - ' . date('F Y');

    $html = preg_replace('/<(script|style|nav|header|footer|form|iframe|noscript)[^>]*>.*?<\/\1>/is', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    $body = '';
    foreach (['<main', '<article', '<section', '<div id="content"', '<div class="content"', '<div class="entry'] as $tag) {
        if (preg_match('/' . preg_quote($tag, '/') . '[^>]*>(.*?)<\/(main|article|section|div)>/is', $html, $m)) {
            $body = $m[1]; break;
        }
    }
    if (!$body) $body = $html;

    $body = preg_replace('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', "\n\n** $1 **\n", $body);
    $body = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "â€¢ $1\n", $body);
    $body = preg_replace('/<(p|div|br)[^>]*>/i', "\n", $body);
    $body = strip_tags($body);
    $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
    $body = preg_replace('/[ \t]+/', ' ', $body);
    $body = preg_replace('/\n{3,}/', "\n\n", $body);
    $body = trim($body);

    if (mb_strlen($body) > 3000) $body = mb_substr($body, 0, 3000) . '...';
    $body .= "\n\nLearn more: " . $targetSite . "\nKeywords: " . $keyword;

    return ['title' => $pageTitle, 'body' => $body];
}

// ============================================================
// SHARED HELPER â€” Build PDF lines array from body text
// ============================================================
function buildPdfLines($body) {
    $rawLines = explode("\n", $body);
    $lines    = [];
    foreach ($rawLines as $rawLine) {
        $rawLine = trim($rawLine);
        if ($rawLine === '') {
            $lines[] = '';
            continue;
        }
        // Word-wrap at 90 chars
        $words = explode(' ', $rawLine);
        $line  = '';
        foreach ($words as $w) {
            if (strlen($line . ' ' . $w) > 90) {
                $lines[] = trim($line);
                $line    = $w;
            } else {
                $line .= ($line ? ' ' : '') . $w;
            }
        }
        if ($line !== '') $lines[] = trim($line);
    }
    return $lines;
}

// ============================================================
// 4SHARED â€” Login with Email + Password â†’ Upload PDF
// Uses 4shared REST API v2 (OAuth2 Resource Owner Password flow)
// Docs: https://www.4shared.com/developer/
// ============================================================
function postToFourShared($email, $password, $keyword, $targetSite, $geminiKey, $openaiKey) {

    // â”€â”€ Step 1: Authenticate via OAuth2 password grant â”€â”€â”€â”€â”€â”€â”€
    $tokenUrl = 'https://api.4shared.com/v1_2/oauth/token';
    $clientId = '4shared-desktop';   // public desktop client
    $authHeader  = null;
    $accessToken = null;

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "Accept: application/json\r\n",
            'content' => http_build_query([
                'grant_type' => 'password',
                'client_id'  => $clientId,
                'username'   => $email,
                'password'   => $password,
            ]),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $tokenResp = @file_get_contents($tokenUrl, false, $ctx);
    if ($tokenResp) {
        $tokenData = json_decode($tokenResp, true);
        if (!empty($tokenData['access_token'])) {
            $accessToken = $tokenData['access_token'];
            $authHeader  = "Authorization: Bearer " . $accessToken;
        }
    }

    // Fallback: Basic-auth
    if (!$authHeader) {
        $basicCtx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Basic " . base64_encode($email . ':' . $password) . "\r\n"
                           . "Accept: application/json\r\n",
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $userResp = @file_get_contents('https://api.4shared.com/v1_2/user', false, $basicCtx);
        $userData = json_decode($userResp, true);
        if (!empty($userData['login'])) {
            $authHeader = "Authorization: Basic " . base64_encode($email . ':' . $password);
        }
    }

    // -- Step 2: Fetch target URL -> PDF (fallback: AI) -
    $ai      = generateAIContent($keyword, $targetSite, '4shared', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []); // init early for goto safety
    $fetched = fetchUrlContentForPdf($targetSite, $keyword, $targetSite);

    // If Chrome returned a ready PDF binary -- use it directly
    if (!empty($fetched['pdf_binary'])) {
        $pdf   = $fetched['pdf_binary'];
        $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
        goto save_and_upload_4shared;
    }

    if ($fetched) {
        $title = $fetched['title'];
        $body  = $fetched['body'];
    } else {
        $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
        $body  = strip_tags($ai['content'] ?? '') . "\n\nLearn more: " . $targetSite . "\nKeywords: " . $keyword;
    }

    // â”€â”€ Step 3: Build PDF with logo in pure PHP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $safeTitle = preg_replace('/[^\x20-\x7E]/', '', $title);
    $lines     = buildPdfLines($body);

    // Try to load logo
    $logoExt  = trim(@file_get_contents(__DIR__ . '/assets/logo_ext.txt') ?: 'png');
    $logoFile = __DIR__ . '/assets/logo.' . $logoExt;
    $logoData = file_exists($logoFile) ? file_get_contents($logoFile) : null;

    // Convert PNG/JPG to JPEG for PDF embedding (GD)
    $logoJpeg   = null;
    $logoW      = 0;
    $logoH      = 0;
    if ($logoData && function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring($logoData);
        if ($img) {
            $logoW = imagesx($img);
            $logoH = imagesy($img);
            // Scale to max 160x60 keeping aspect ratio
            $maxW  = 160; $maxH = 60;
            $scale = min($maxW / $logoW, $maxH / $logoH);
            $dstW  = (int)($logoW * $scale);
            $dstH  = (int)($logoH * $scale);
            $dst   = imagecreatetruecolor($dstW, $dstH);
            // White background
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $dstW, $dstH, $logoW, $logoH);
            ob_start();
            imagejpeg($dst, null, 90);
            $logoJpeg = ob_get_clean();
            $logoW    = $dstW;
            $logoH    = $dstH;
            imagedestroy($img);
            imagedestroy($dst);
        }
    }

    $pdf  = "%PDF-1.4\n";
    $objs = [];
    $objCount = 5;

    $objs[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objs[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

    // Build page content stream
    $contentLines = "";

    // Draw logo top-right if available
    if ($logoJpeg) {
        $objCount = 7; // extra objects: 6=XObject, 7=image stream
        $logoX = 612 - $logoW - 36; // right margin 36pt
        $logoY = 792 - $logoH - 20; // top margin 20pt
        $contentLines .= "q\n{$logoW} 0 0 {$logoH} {$logoX} {$logoY} cm\n/Im1 Do\nQ\n";
    }

    // Text content
    $contentLines .= "BT\n/F1 14 Tf\n72 " . (792 - 60) . " Td\n(" . addslashes($safeTitle) . ") Tj\n";
    $contentLines .= "/F1 11 Tf\n0 -24 Td\n";
    foreach ($lines as $l) {
        $safe          = preg_replace('/[^\x20-\x7E]/', '', $l);
        $contentLines .= "(" . addslashes($safe) . ") Tj\n0 -16 Td\n";
    }
    $contentLines .= "ET\n";

    $objs[4] = "4 0 obj\n<< /Length " . strlen($contentLines) . " >>\nstream\n" . $contentLines . "endstream\nendobj\n";

    if ($logoJpeg) {
        // Object 6: Image XObject
        $objs[6] = "6 0 obj\n<< /Type /XObject /Subtype /Image /Width {$logoW} /Height {$logoH}"
                 . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode"
                 . " /Length " . strlen($logoJpeg) . " >>\nstream\n" . $logoJpeg . "\nendstream\nendobj\n";

        $objs[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]\n"
                 . "   /Contents 4 0 R /Resources << /Font << /F1 5 0 R >>"
                 . " /XObject << /Im1 6 0 R >> >> >>\nendobj\n";
    } else {
        $objs[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]\n"
                 . "   /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
    }

    $objs[5] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

    $offsets  = [];
    $objOrder = $logoJpeg ? [1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5];
    foreach ($objOrder as $n) {
        $offsets[$n] = strlen($pdf);
        $pdf        .= $objs[$n];
    }
    $xrefOffset = strlen($pdf);
    $size       = $logoJpeg ? 7 : 6;
    $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
    foreach ($objOrder as $n) {
        $pdf .= str_pad($offsets[$n], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

    // â”€â”€ Step 4: Save PDF locally for download â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    save_and_upload_4shared:
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $filename    = 'seo-guide-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-' . date('Ymd') . '.pdf';
    $localPath   = $uploadDir . $filename;
    file_put_contents($localPath, $pdf);
    $localPdfUrl = SITE_URL . '/uploads/' . $filename;

    // â”€â”€ Step 5: Try API upload if auth succeeded â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($authHeader) {
        // Get root folder ID
        $folderCtx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => $authHeader . "\r\nAccept: application/json\r\n",
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $userResp2    = @file_get_contents('https://api.4shared.com/v1_2/user', false, $folderCtx);
        $userData2    = json_decode($userResp2, true);
        $rootFolderId = $userData2['rootFolderId'] ?? 0;

        $boundary = '----4SHBoundary' . uniqid();
        $body_raw = "--{$boundary}\r\n"
                  . "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
                  . "Content-Type: application/pdf\r\n\r\n"
                  . $pdf . "\r\n"
                  . "--{$boundary}--\r\n";

        $uploadUrl = 'https://upload.4shared.com/v1_2/files';
        if ($rootFolderId) $uploadUrl .= '?folderId=' . $rootFolderId;

        $uploadCtx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => $authHeader . "\r\n"
                           . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                           . "Content-Length: " . strlen($body_raw) . "\r\n",
                'content' => $body_raw,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        $uploadResp = @file_get_contents($uploadUrl, false, $uploadCtx);
        $uploadData = json_decode($uploadResp, true);
        $fileId     = $uploadData['id'] ?? null;
        $shareLink  = $uploadData['link'] ?? $uploadData['downloadPage'] ?? null;

        // Make public if needed
        if ($fileId && empty($shareLink)) {
            $pubCtx = stream_context_create([
                'http' => [
                    'method'  => 'PUT',
                    'header'  => $authHeader . "\r\nContent-Type: application/json\r\n",
                    'content' => json_encode(['access' => 'public']),
                    'timeout' => 20,
                    'ignore_errors' => true,
                ],
            ]);
            $pubResp   = @file_get_contents('https://api.4shared.com/v1_2/files/' . $fileId, false, $pubCtx);
            $pubData   = json_decode($pubResp, true);
            $shareLink = $pubData['link'] ?? $pubData['downloadPage'] ?? ('https://www.4shared.com/file/' . $fileId);
        }

        if ($shareLink) {
            return [
                'success' => true,
                'url'     => $shareLink,
                'message' => 'PDF uploaded to 4Shared successfully!',
                'source'  => $ai['source'] ?? 'ChatGPT',
            ];
        }
    }

    // â”€â”€ Step 6: Fallback â€” PDF saved locally, user uploads manually â”€â”€
    $instructions  = "<strong>PDF has been generated and saved.</strong><br><br>";
    $instructions .= "ðŸ“¥ <a href='{$localPdfUrl}' download='{$filename}' class='btn btn-sm btn-danger me-2'>";
    $instructions .= "<i class='fas fa-download me-1'></i>Download PDF</a><br><br>";
    $instructions .= "Then upload it manually to 4Shared:<br>";
    $instructions .= "1. <a href='https://www.4shared.com' target='_blank'>Open 4Shared</a> â†’ Login<br>";
    $instructions .= "2. Click <strong>Upload</strong> â†’ Select the downloaded PDF<br>";
    $instructions .= "3. After upload, copy the share link and click <strong>Mark as Created</strong>";

    return [
        'manual'   => true,
        'message'  => '4Shared API unavailable. PDF generated â€” download and upload manually.',
        'title'    => $title,
        'content'  => $instructions,
        'pdf_url'  => $localPdfUrl,
        'filename' => $filename,
        'url'      => 'https://www.4shared.com',
        'source'   => $ai['source'] ?? 'ChatGPT',
    ];
}

// ============================================================
// MEDIAFIRE â€” Login with Email + Password â†’ Upload PDF
// No public API key needed; uses MediaFire's REST API v1.5
// Docs: https://www.mediafire.com/developers/core_api/1.5/getting_started/
// ============================================================
function postToMediaFire($email, $password, $keyword, $targetSite, $geminiKey, $openaiKey) {

    // â”€â”€ Step 1: Authenticate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $appId     = '42511';          // MediaFire public web app ID
    $apiVer    = '1.5';
    $loginUrl  = 'https://www.mediafire.com/api/' . $apiVer . '/user/get_session_token.php';

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: Mozilla/5.0\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $loginResp = @file_get_contents($loginUrl . '?' . http_build_query([
        'email'           => $email,
        'password'        => md5($password),
        'application_id'  => $appId,
        'response_format' => 'json',
        'token_version'   => '2',
    ]), false, $ctx);

    if (!$loginResp) {
        // API dead â€” skip to PDF generation directly
        $sessionToken = null;
        goto generate_pdf_mediafire;
    }

    $loginData = json_decode($loginResp, true);
    if (empty($loginData['response']['session_token'])) {
        $sessionToken = null;
        goto generate_pdf_mediafire;
    }

    $sessionToken = $loginData['response']['session_token'];

    generate_pdf_mediafire:
    // â”€â”€ Step 2: Fetch target URL â†’ PDF via Chrome (fallback: AI) â”€
    $fetched = fetchUrlContentForPdf($targetSite, $keyword, $targetSite);

    if (!empty($fetched['pdf_binary'])) {
        $pdf = $fetched['pdf_binary'];
        $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
        goto save_and_upload_mediafire;
    }

    if ($fetched) {
        $title = $fetched['title'];
        $body  = $fetched['body'];
    } else {
        $ai    = generateAIContent($keyword, $targetSite, 'mediafire', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
        if (empty($ai['content'])) return ['error' => 'AI content generation failed. Check OpenAI API key.'];
        $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
        $body  = strip_tags($ai['content'] ?? '') . "\n\nLearn more: " . $targetSite . "\nKeywords: " . $keyword;
    }

    // â”€â”€ Step 3: Build PDF with logo in pure PHP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $safeTitle = preg_replace('/[^\x20-\x7E]/', '', $title);
    $lines     = buildPdfLines($body);

    // Try to load logo
    $logoExt  = trim(@file_get_contents(__DIR__ . '/assets/logo_ext.txt') ?: 'png');
    $logoFile = __DIR__ . '/assets/logo.' . $logoExt;
    $logoData = file_exists($logoFile) ? file_get_contents($logoFile) : null;
    $logoJpeg = null; $logoW = 0; $logoH = 0;
    if ($logoData && function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring($logoData);
        if ($img) {
            $srcW = imagesx($img); $srcH = imagesy($img);
            $scale = min(160 / $srcW, 60 / $srcH);
            $logoW = (int)($srcW * $scale); $logoH = (int)($srcH * $scale);
            $dst   = imagecreatetruecolor($logoW, $logoH);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $logoW, $logoH, $srcW, $srcH);
            ob_start(); imagejpeg($dst, null, 90); $logoJpeg = ob_get_clean();
            imagedestroy($img); imagedestroy($dst);
        }
    }

    $pdf  = "%PDF-1.4\n"; $objs = [];
    $objs[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objs[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

    $contentLines = "";
    if ($logoJpeg) {
        $logoX = 612 - $logoW - 36; $logoY = 792 - $logoH - 20;
        $contentLines .= "q\n{$logoW} 0 0 {$logoH} {$logoX} {$logoY} cm\n/Im1 Do\nQ\n";
    }
    $contentLines .= "BT\n/F1 14 Tf\n72 " . (792 - 60) . " Td\n(" . addslashes($safeTitle) . ") Tj\n";
    $contentLines .= "/F1 11 Tf\n0 -24 Td\n";
    foreach ($lines as $l) {
        $safe = preg_replace('/[^\x20-\x7E]/', '', $l);
        $contentLines .= "(" . addslashes($safe) . ") Tj\n0 -16 Td\n";
    }
    $contentLines .= "ET\n";

    $objs[4] = "4 0 obj\n<< /Length " . strlen($contentLines) . " >>\nstream\n" . $contentLines . "endstream\nendobj\n";
    if ($logoJpeg) {
        $objs[6] = "6 0 obj\n<< /Type /XObject /Subtype /Image /Width {$logoW} /Height {$logoH}"
                 . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode"
                 . " /Length " . strlen($logoJpeg) . " >>\nstream\n" . $logoJpeg . "\nendstream\nendobj\n";
        $objs[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]\n"
                 . "   /Contents 4 0 R /Resources << /Font << /F1 5 0 R >>"
                 . " /XObject << /Im1 6 0 R >> >> >>\nendobj\n";
    } else {
        $objs[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]\n"
                 . "   /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
    }
    $objs[5] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

    $objOrder = $logoJpeg ? [1,2,3,4,5,6] : [1,2,3,4,5];
    $offsets  = [];
    foreach ($objOrder as $n) { $offsets[$n] = strlen($pdf); $pdf .= $objs[$n]; }
    $xrefOffset = strlen($pdf);
    $size = $logoJpeg ? 7 : 6;
    $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
    foreach ($objOrder as $n) { $pdf .= str_pad($offsets[$n], 10, '0', STR_PAD_LEFT) . " 00000 n \n"; }
    $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

    // â”€â”€ Step 4: Save PDF + Upload to MediaFire (if auth ok) â”€â”€
    save_and_upload_mediafire:
    $filename  = 'seo-guide-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-' . date('Ymd') . '.pdf';
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $localPath   = $uploadDir . $filename;
    file_put_contents($localPath, $pdf);
    $localPdfUrl = SITE_URL . '/uploads/' . $filename;

    // Only try MediaFire upload if we have a valid session token
    if ($sessionToken) {
        $uploadCheckUrl = 'https://www.mediafire.com/api/' . $apiVer . '/upload/check.php?' . http_build_query([
            'session_token'   => $sessionToken,
            'filename'        => $filename,
            'size'            => strlen($pdf),
            'response_format' => 'json',
        ]);
        @file_get_contents($uploadCheckUrl); // optional pre-check

        $boundary = '----MFBoundary' . uniqid();
        $body_raw = "--{$boundary}\r\n"
                  . "Content-Disposition: form-data; name=\"Filedata\"; filename=\"{$filename}\"\r\n"
                  . "Content-Type: application/pdf\r\n\r\n"
                  . $pdf . "\r\n"
                  . "--{$boundary}--\r\n";

        $uploadUrl = 'https://www.mediafire.com/api/' . $apiVer . '/upload/simple.php?'
                   . http_build_query([
                       'session_token'       => $sessionToken,
                       'response_format'     => 'json',
                       'action_on_duplicate' => 'keep',
                   ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                           . "Content-Length: " . strlen($body_raw) . "\r\n",
                'content' => $body_raw,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        $uploadResp = @file_get_contents($uploadUrl, false, $ctx);
        $uploadData = json_decode($uploadResp, true);
        $quickKey   = $uploadData['response']['doupload']['key']
                   ?? $uploadData['response']['upload_result']['filedata']['quickkey']
                   ?? null;

        if ($quickKey) {
            $linkUrl  = 'https://www.mediafire.com/api/' . $apiVer . '/file/get_links.php?' . http_build_query([
                'session_token'   => $sessionToken,
                'quick_key'       => $quickKey,
                'link_type'       => 'view',
                'response_format' => 'json',
            ]);
            $linkResp = @file_get_contents($linkUrl);
            $linkData = json_decode($linkResp, true);
            $viewUrl  = $linkData['response']['links'][0]['view'] ?? ('https://www.mediafire.com/file/' . $quickKey);

            return [
                'success' => true,
                'url'     => $viewUrl,
                'message' => 'PDF uploaded to MediaFire successfully!',
                'source'  => 'Chrome PDF / ChatGPT',
            ];
        }
    }

    // â”€â”€ Fallback: PDF saved locally â€” show download button â”€â”€â”€
    $instructions  = "<strong>PDF generated from your website page.</strong><br><br>";
    $instructions .= "ðŸ“¥ <a href='{$localPdfUrl}' download='{$filename}' class='btn btn-sm btn-danger me-2'>";
    $instructions .= "<i class='fas fa-download me-1'></i>Download PDF</a><br><br>";
    $instructions .= "Then upload manually to MediaFire:<br>";
    $instructions .= "1. <a href='https://www.mediafire.com' target='_blank'>Open MediaFire</a> â†’ Login<br>";
    $instructions .= "2. Click <strong>Upload</strong> â†’ Select the downloaded PDF<br>";
    $instructions .= "3. Copy share link â†’ Click <strong>Mark as Created</strong>";

    return [
        'manual'   => true,
        'title'    => $filename,
        'content'  => $instructions,
        'pdf_url'  => $localPdfUrl,
        'filename' => $filename,
        'url'      => 'https://www.mediafire.com',
        'source'   => 'Chrome PDF / ChatGPT',
    ];
}

// ============================================================
// MASTODON — API v1 (100% Auto)
// Token: mastodon.social → Settings → Development → New Application
// OR: email+password → Selenium auto-generates token
// ============================================================
function postToMastodon($apiKey, $instance, $keyword, $targetSite, $geminiKey, $openaiKey, $projectId = 0, $postCount = 1, array $usedTitles = [], string $businessName = '', string $businessDesc = '', string $phone = '', string $email = '') {
    // instance field = username from DB
    // If username is an email, use default mastodon.social
    if (empty($instance) || strpos($instance, '@') !== false || strpos($instance, '.com') !== false && strpos($instance, 'mastodon') === false) {
        $instance = 'mastodon.social';
    }
    $instance = str_replace(['https://','http://'], '', rtrim($instance, '/'));

    if (empty($apiKey)) return ['error' => 'Mastodon token missing. Save Email+Password in credentials.'];

    // ── Generate AI content ───────────────────────────────────────
    $ai      = generateAIContent($keyword, $targetSite, 'mastodon', 'micro_blog', $geminiKey, $openaiKey, $postCount, $usedTitles, $businessName, $businessDesc);
    if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
    $content = strip_tags($ai['content']);

    // ── Build Bluesky-style post (like screenshot) ────────────────
    $kw      = ucwords($keyword);
    $words   = explode(' ', $keyword);
    $tags    = '';
    foreach (array_merge($words, ['Training', 'Education', 'Career']) as $w) {
        $t = preg_replace('/[^a-zA-Z0-9]/', '', ucfirst($w));
        if (strlen($t) >= 2) $tags .= '#' . $t . ' ';
    }
    $tags = trim($tags);

    // Title + short description + contact + link + hashtags
    $bName = !empty($businessName) ? $businessName : 'Learnmore Technologies';
    $title = generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY) . ' - ' . $bName;
    $desc    = mb_substr($content, 0, 280, 'UTF-8');
    
    $contactInfo = '';
    if (!empty($phone)) {
        $contactInfo .= $phone . "\n";
    } else {
        $contactInfo .= "90363 54551\n";
    }
    if (!empty($email)) {
        $contactInfo .= $email . "\n";
    } else {
        $contactInfo .= "office.learnmore@gmail.com\n";
    }

    $status  = $title . "\n\n"
             . $desc . "\n\n"
             . $contactInfo . "\n"
             . "Visit Us: " . $targetSite . "\n\n"
             . $tags;

    $status = mb_substr($status, 0, 499, 'UTF-8');

    // ── Upload image if available ─────────────────────────────────
    $mediaId   = null;
    $uploadDir = __DIR__ . '/uploads/';

    // Find project image
    $imgFile = null;
    if ($projectId > 0) {
        $pImg = function_exists('getEnqueuedImagePath') ? getEnqueuedImagePath() : null;
        if (!$pImg) {
            $db     = getDB();
            $imgRow = $db->prepare("SELECT post_image FROM projects WHERE id=?");
            $imgRow->execute([$projectId]);
            $pImg   = $imgRow->fetchColumn();
        }
        if ($pImg && file_exists($uploadDir . $pImg)) {
            $imgFile = $uploadDir . $pImg;
        }
    }

    // Fallback: latest auto_img
    if (!$imgFile) {
        $images = glob($uploadDir . 'auto_img_*.jpg');
        if ($images) {
            usort($images, fn($a,$b) => filemtime($b) - filemtime($a));
            $imgFile = $images[0];
        }
    }

    // Upload image to Mastodon
    if ($imgFile && file_exists($imgFile)) {
        $ch = curl_init("https://{$instance}/api/v2/media");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'file'        => new CURLFile($imgFile, 'image/jpeg', basename($imgFile)),
                'description' => $kw . ' Training - Learnmore Technologies',
            ],
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $mediaResp = curl_exec($ch);
        $mediaCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $mediaData = json_decode($mediaResp, true);
        if (!empty($mediaData['id'])) {
            $mediaId = $mediaData['id'];
            // Wait for processing
            if ($mediaCode === 202) sleep(3);
        }
    }

    // ── Post status with image ────────────────────────────────────
    $postData = ['status' => $status, 'visibility' => 'public'];
    if ($mediaId) $postData['media_ids[]'] = $mediaId;

    $ch = curl_init("https://{$instance}/api/v1/statuses");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $mediaId
            ? http_build_query(['status' => $status, 'visibility' => 'public', 'media_ids[]' => $mediaId])
            : json_encode($postData),
        CURLOPT_HTTPHEADER     => $mediaId
            ? ['Authorization: Bearer ' . $apiKey]
            : ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    if (isset($result['url'])) return ['success' => true, 'url' => $result['url'], 'source' => $ai['source'], 'post_title' => $title];
    if (isset($result['id']))  return ['success' => true, 'url' => "https://{$instance}/@" . ($result['account']['username'] ?? 'user') . '/' . $result['id'], 'source' => $ai['source'], 'post_title' => $title ?? null];
    return ['error' => 'Mastodon failed HTTP ' . $httpCode . ': ' . ($result['error'] ?? $response)];
}

// ============================================================
// MASTODON — Selenium auto-setup (email+password → get token → post)
// ============================================================
// MASTODON — Selenium auto-setup (email+password → get token → post)
// ============================================================
function postToMastodonSelenium(array $creds, string $keyword, string $targetSite): array {
    // Email stored in api_secret (username = mastodon.social instance)
    $email    = $creds['api_secret'] ?? $creds['username'] ?? '';
    // If username is an email, use it directly
    if (strpos($email, '@') === false && strpos($creds['username'] ?? '', '@') !== false) {
        $email = $creds['username'];
    }
    $rawPass  = $creds['password'] ?? '';
    $password = base64_decode($rawPass, true);
    if ($password === false || empty(trim($password))) $password = $rawPass;

    if (empty($email) || empty(trim($password))) {
        return ['error' => 'Mastodon: Add email + password credentials.'];
    }

    $scriptResult = runSeleniumScript('mastodon_setup_playwright.py',
        [$email, trim($password), $keyword, $targetSite], 180);

    // Save token to DB — use id-based UPDATE for reliability
    if (!empty($scriptResult['token'])) {
        try {
            $db = getDB();
            $accountId = $creds['id'] ?? 0;
            if ($accountId) {
                $db->prepare("UPDATE social_accounts SET api_key=? WHERE id=?")
                   ->execute([$scriptResult['token'], $accountId]);
            } else {
                $db->prepare("UPDATE social_accounts SET api_key=? WHERE platform='mastodon' AND username=?")
                   ->execute([$scriptResult['token'], $email]);
            }
        } catch (\Exception $e) {
            // Ignore DB errors
        }
    }

    if (!empty($scriptResult['success'])) {
        return ['success' => true, 'url' => $scriptResult['url'], 'source' => 'Mastodon Auto', 'post_title' => $title ?? null];
    }
    return ['error' => 'Mastodon: ' . ($scriptResult['error'] ?? 'Unknown error')];
}

// ============================================================
// IMGBB — Image Upload API (100% Auto, FREE)
// API Key: imgbb.com/api
// ============================================================
function postToImgBB($apiKey, $keyword, $targetSite, $geminiKey, $openaiKey) {
    if (empty($apiKey)) return ['error' => 'ImgBB API key missing. Get free key: imgbb.com/api'];

    // Use project post image if available, else generate placeholder
    $uploadDir = __DIR__ . '/uploads/';
    $imgData   = null;

    // Try to find latest project image
    $images = glob($uploadDir . 'project_*.{jpg,jpeg,png,webp}', GLOB_BRACE);
    if (!empty($images)) {
        usort($images, fn($a,$b) => filemtime($b) - filemtime($a));
        $imgData = file_get_contents($images[0]);
    }

    // Fallback: download a placeholder
    if (!$imgData) {
        $imgData = @file_get_contents('https://picsum.photos/seed/' . urlencode($keyword) . '/800/600');
    }
    if (!$imgData) return ['error' => 'ImgBB: No image available to upload'];

    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'key'         => $apiKey,
            'image'       => base64_encode($imgData),
            'name'        => $title,
            'expiration'  => 0,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    if (!empty($result['data']['url_viewer'])) {
        return ['success' => true, 'url' => $result['data']['url_viewer'], 'source' => 'ImgBB Upload', 'post_title' => $title ?? null];
    }
    return ['error' => 'ImgBB failed: ' . ($result['error']['message'] ?? "HTTP $httpCode")];
}

// ============================================================
// DRIBBBLE — Shot Upload API (100% Auto)
// Token: dribbble.com/account/applications → New Application
// ============================================================
function postToDribbble($apiKey, $keyword, $targetSite, $geminiKey, $openaiKey) {
    if (empty($apiKey)) return ['error' => 'Dribbble token missing. Get from: dribbble.com/account/applications'];

    $uploadDir = __DIR__ . '/uploads/';
    $imgData   = null;
    $imgMime   = 'image/jpeg'; // default mime
    $images    = glob($uploadDir . 'project_*.{jpg,jpeg,png,webp}', GLOB_BRACE);
    if (!empty($images)) {
        usort($images, fn($a,$b) => filemtime($b) - filemtime($a));
        $imgData = file_get_contents($images[0]);
        $imgMime = 'image/jpeg';
    }
    if (!$imgData) {
        $imgData = @file_get_contents('https://picsum.photos/seed/' . urlencode($keyword) . '/800/600');
        $imgMime = 'image/jpeg';
    }
    if (!$imgData) return ['error' => 'Dribbble: No image to upload'];

    $ai          = generateAIContent($keyword, $targetSite, 'dribbble', 'image_caption', $geminiKey, $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
    $description = mb_substr(strip_tags($ai['content'] ?? ''), 0, 1000, 'UTF-8') . "\n\nLearn more: " . $targetSite;

    $tmpFile = $uploadDir . 'dribbble-' . time() . '.jpg';
    file_put_contents($tmpFile, $imgData);

    $ch = curl_init('https://api.dribbble.com/v2/shots');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'title'       => $title,
            'description' => $description,
            'image'       => new CURLFile($tmpFile, $imgMime, basename($tmpFile)),
            'tags[]'      => strtolower($keyword),
        ],
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);

    $result = json_decode($response, true);
    if (isset($result['html_url'])) return ['success' => true, 'url' => $result['html_url'], 'source' => $ai['source'], 'post_title' => $title ?? null];
    // Dribbble requires Pro account for API shots
    return array_merge(
        generateManualContent('dribbble', $keyword, $targetSite, $geminiKey, $openaiKey),
        ['message' => 'Dribbble API requires Pro account. Content ready — post manually.', 'url' => 'https://dribbble.com/shots/new']
    );
}

// Accept 4 or 5 args — geminiKey is optional/ignored
function generateManualContent($platform, $keyword, $targetSite, $openaiKeyOrGemini = '', $openaiKey = '') {
    // If called with 5 args: (platform, keyword, site, geminiKey, openaiKey)
    // If called with 4 args: (platform, keyword, site, openaiKey)
    $realOpenaiKey = !empty($openaiKey) ? $openaiKey : $openaiKeyOrGemini;
    $ai    = generateAIContent($keyword, $targetSite, $platform, 'blog_post', '', $realOpenaiKey, 1, []);
    if (empty($ai['content'])) return ['error' => 'AI content generation failed. Check OpenAI API key.'];
    $title = $ai['title'] ?? generateUniqueTitle($keyword, 1, [], $realOpenaiKey);
    return [
        'manual'  => true,
        'message' => 'ChatGPT content ready. Copy and paste on ' . $platform,
        'title'   => $title,
        'content' => $ai['content'],
        'source'  => $ai['source'],
    ];
}

// ============================================================
// HELPER — cURL session with cookie jar (Selenium-style login)
// ============================================================
function curlSession(): array {
    $cookieFile = tempnam(sys_get_temp_dir(), 'seo_cookie_');
    return ['cookie' => $cookieFile];
}

function curlGet(string $url, string $cookieFile, array $extraHeaders = []): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => array_merge(['Accept: text/html,application/xhtml+xml,application/json'], $extraHeaders),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return $r ?: '';
}

function curlPost(string $url, $fields, string $cookieFile, array $extraHeaders = [], bool $isJson = false): array {
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json, text/html'], $extraHeaders);
    if ($isJson) {
        $headers[] = 'Content-Type: application/json';
        $body = is_string($fields) ? $fields : json_encode($fields);
    } else {
        $body = is_array($fields) ? http_build_query($fields) : $fields;
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $resp ?: '', 'code' => $code];
}

// Extract CSRF / hidden token from HTML form
function extractToken(string $html, string $field = 'csrf_token'): string {
    if (preg_match('/<input[^>]+name=["\']' . preg_quote($field, '/') . '["\'][^>]+value=["\']([^"\']+)["\']/', $html, $m)) return $m[1];
    if (preg_match('/<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']' . preg_quote($field, '/') . '["\']/', $html, $m)) return $m[1];
    return '';
}

// ============================================================
// PDFHOST.IO — Free PDF hosting, no account needed
// Just POST the PDF file → get public URL
// ============================================================
function postToPDFHost($keyword, $targetSite, $openaiKey) {
    $ai      = generateAIContent($keyword, $targetSite, 'pdfhost', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $content = strip_tags($ai['content'] ?? '');
    if (empty($content)) $content = "Best {$keyword} Guide\n\nLearn more: {$targetSite}";

    // Build simple PDF
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
    $safeT    = preg_replace('/[^\x20-\x7E]/', '', $title);
    $lines    = [];
    foreach (explode("\n", wordwrap($content, 90, "\n", false)) as $l)
        $lines[] = preg_replace('/[^\x20-\x7E]/', '', trim($l));

    $stream  = "BT\n/F1 14 Tf\n50 750 Td\n(" . addslashes($safeT) . ") Tj\n/F1 10 Tf\n0 -20 Td\n";
    foreach ($lines as $l) $stream .= "(" . addslashes($l) . ") Tj\n0 -14 Td\n";
    $stream .= "ET\n";

    $objs  = [];
    $objs[1] = "1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n";
    $objs[2] = "2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n";
    $objs[3] = "3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>\nendobj\n";
    $objs[4] = "4 0 obj\n<</Length " . strlen($stream) . ">>\nstream\n{$stream}endstream\nendobj\n";
    $objs[5] = "5 0 obj\n<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>\nendobj\n";
    $pdf = "%PDF-1.4\n";
    $off = [];
    foreach ([1,2,3,4,5] as $n) { $off[$n] = strlen($pdf); $pdf .= $objs[$n]; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ([1,2,3,4,5] as $n) $pdf .= str_pad($off[$n], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    $pdf .= "trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF\n";

    $filename = preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-guide.pdf';
    $tmpFile  = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tmpFile, $pdf);

    // Upload to pdfhost.io (free, no auth)
    $ch = curl_init('https://pdfhost.io/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => new CURLFile($tmpFile, 'application/pdf', $filename)],
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);

    $data = json_decode($resp, true);
    // pdfhost returns {"name":"...","size":...,"url":"https://pdfhost.io/v/..."}
    $url = $data['url'] ?? $data['fileUrl'] ?? $data['link'] ?? null;
    if ($url) return ['success' => true, 'url' => $url, 'source' => $ai['source'] ?? 'AI'];

    // Fallback: save locally
    $localFile = __DIR__ . '/uploads/' . $filename;
    file_put_contents($localFile, $pdf);
    $localUrl = SITE_URL . '/uploads/' . $filename;
    return [
        'manual'  => true,
        'message' => 'PDFHost upload failed. PDF saved locally — download and upload manually at pdfhost.io',
        'title'   => $title,
        'content' => "<a href='{$localUrl}' download='{$filename}' class='btn btn-danger btn-sm'>⬇ Download PDF</a><br><br>Upload at: <a href='https://pdfhost.io' target='_blank'>pdfhost.io</a>",
        'url'     => 'https://pdfhost.io',
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// UPLOAD.EE — Free file hosting, no auth needed
// POST multipart → returns JSON with download link
// ============================================================
function postToUploadEE($keyword, $targetSite, $openaiKey) {
    // Reuse PDFHost's PDF builder logic inline
    $ai      = generateAIContent($keyword, $targetSite, 'uploadee', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $content = strip_tags($ai['content'] ?? '');
    if (empty($content)) $content = "Best {$keyword} Guide\n\nLearn more: {$targetSite}";

    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
    $safeT   = preg_replace('/[^\x20-\x7E]/', '', $title);
    $lines   = [];
    foreach (explode("\n", wordwrap($content, 90, "\n", false)) as $l)
        $lines[] = preg_replace('/[^\x20-\x7E]/', '', trim($l));
    $stream  = "BT\n/F1 14 Tf\n50 750 Td\n(" . addslashes($safeT) . ") Tj\n/F1 10 Tf\n0 -20 Td\n";
    foreach ($lines as $l) $stream .= "(" . addslashes($l) . ") Tj\n0 -14 Td\n";
    $stream .= "ET\n";
    $o[1]="1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n";
    $o[2]="2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n";
    $o[3]="3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>\nendobj\n";
    $o[4]="4 0 obj\n<</Length " . strlen($stream) . ">>\nstream\n{$stream}endstream\nendobj\n";
    $o[5]="5 0 obj\n<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>\nendobj\n";
    $pdf="%PDF-1.4\n"; $off=[];
    foreach([1,2,3,4,5] as $n){$off[$n]=strlen($pdf);$pdf.=$o[$n];}
    $xref=strlen($pdf);
    $pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach([1,2,3,4,5] as $n) $pdf.=str_pad($off[$n],10,'0',STR_PAD_LEFT)." 00000 n \n";
    $pdf.="trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF\n";

    $filename = preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-guide.pdf';
    $tmpFile  = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tmpFile, $pdf);

    // upload.ee API — no auth needed for anonymous upload
    $ch = curl_init('https://www.upload.ee/api');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => new CURLFile($tmpFile, 'application/pdf', $filename)],
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);

    $data = json_decode($resp, true);
    // upload.ee returns {"status":200,"full_filename":"...","url":"...","download_url":"..."}
    $url = $data['url'] ?? $data['download_url'] ?? $data['file_link'] ?? null;
    if ($url) return ['success' => true, 'url' => $url, 'source' => $ai['source'] ?? 'AI'];

    $localFile = __DIR__ . '/uploads/' . $filename;
    file_put_contents($localFile, $pdf);
    $localUrl = SITE_URL . '/uploads/' . $filename;
    return [
        'manual'  => true,
        'message' => 'Upload.ee upload failed. PDF saved locally.',
        'title'   => $title,
        'content' => "<a href='{$localUrl}' download='{$filename}' class='btn btn-danger btn-sm'>⬇ Download PDF</a><br>Upload at: <a href='https://www.upload.ee' target='_blank'>upload.ee</a>",
        'url'     => 'https://www.upload.ee',
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// WORKUPLOAD.COM — Free anonymous file upload
// POST multipart → get direct link
// ============================================================
function postToWorkUpload($keyword, $targetSite, $openaiKey) {
    $ai      = generateAIContent($keyword, $targetSite, 'workupload', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $content = strip_tags($ai['content'] ?? '');
    if (empty($content)) $content = "Best {$keyword} Guide\n\nLearn more: {$targetSite}";

    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
    $safeT   = preg_replace('/[^\x20-\x7E]/', '', $title);
    $lines   = [];
    foreach (explode("\n", wordwrap($content, 90, "\n", false)) as $l)
        $lines[] = preg_replace('/[^\x20-\x7E]/', '', trim($l));
    $stream  = "BT\n/F1 14 Tf\n50 750 Td\n(" . addslashes($safeT) . ") Tj\n/F1 10 Tf\n0 -20 Td\n";
    foreach ($lines as $l) $stream .= "(" . addslashes($l) . ") Tj\n0 -14 Td\n";
    $stream .= "ET\n";
    $o[1]="1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n";
    $o[2]="2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n";
    $o[3]="3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>\nendobj\n";
    $o[4]="4 0 obj\n<</Length " . strlen($stream) . ">>\nstream\n{$stream}endstream\nendobj\n";
    $o[5]="5 0 obj\n<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>\nendobj\n";
    $pdf="%PDF-1.4\n"; $off=[];
    foreach([1,2,3,4,5] as $n){$off[$n]=strlen($pdf);$pdf.=$o[$n];}
    $xref=strlen($pdf);
    $pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach([1,2,3,4,5] as $n) $pdf.=str_pad($off[$n],10,'0',STR_PAD_LEFT)." 00000 n \n";
    $pdf.="trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF\n";

    $filename = preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-guide.pdf';
    $tmpFile  = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tmpFile, $pdf);

    // workupload.com uses simple POST
    $ch = curl_init('https://workupload.com/api/file/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => new CURLFile($tmpFile, 'application/pdf', $filename)],
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);

    $data = json_decode($resp, true);
    // workupload returns {"success":true,"data":{"url":"https://workupload.com/file/xxx"}}
    $url = $data['data']['url'] ?? $data['url'] ?? $data['file'] ?? null;
    if ($url) return ['success' => true, 'url' => $url, 'source' => $ai['source'] ?? 'AI'];

    $localFile = __DIR__ . '/uploads/' . $filename;
    file_put_contents($localFile, $pdf);
    $localUrl = SITE_URL . '/uploads/' . $filename;
    return [
        'manual'  => true,
        'message' => 'WorkUpload failed. PDF saved locally.',
        'title'   => $title,
        'content' => "<a href='{$localUrl}' download='{$filename}' class='btn btn-danger btn-sm'>⬇ Download PDF</a><br>Upload at: <a href='https://workupload.com' target='_blank'>workupload.com</a>",
        'url'     => 'https://workupload.com',
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// POSTIMAGE.ORG — Free anonymous image hosting, no auth
// POST multipart → returns page URL with backlink
// ============================================================
function postToPostImage($keyword, $targetSite, $openaiKey) {
    // Download/use project image
    $uploadDir = __DIR__ . '/uploads/';
    $imgData   = null;
    $images    = glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE);
    if ($images) {
        usort($images, fn($a,$b) => filemtime($b)-filemtime($a));
        $imgData = file_get_contents($images[0]);
    }
    if (!$imgData) $imgData = @file_get_contents('https://picsum.photos/seed/' . urlencode($keyword) . '/800/600');
    if (!$imgData) return ['error' => 'PostImage: No image available'];

    $tmpFile = sys_get_temp_dir() . '/postimg-' . time() . '.jpg';
    file_put_contents($tmpFile, $imgData);

    // postimage.org anonymous upload
    $ch = curl_init('https://postimages.org/json/rr');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'upload'  => new CURLFile($tmpFile, 'image/jpeg', 'image.jpg'),
            'token'   => '',
            'numfiles'=> '1',
            'upload_session' => '',
        ],
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Referer: https://postimages.org/',
        ],
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);

    $data = json_decode($resp, true);
    // postimages returns {"status":"OK","url":"https://postimages.org/image/xxx","link":"..."}
    $url = $data['url'] ?? $data['link'] ?? null;
    if ($url) return ['success' => true, 'url' => $url, 'source' => 'PostImage Upload', 'post_title' => $title ?? null];

    return [
        'manual'  => true,
        'message' => 'PostImage upload failed. Upload image manually at postimages.org',
        'title'   => ucwords($keyword) . ' - ' . date('Y'),
        'content' => 'Visit <a href="https://postimages.org" target="_blank">postimages.org</a> → Upload image → Share link',
        'url'     => 'https://postimages.org',
        'source'  => 'Manual',
    ];
}

// ============================================================
// GIFYU.COM — Free image hosting with API
// POST multipart with email+password cookie session
// ============================================================
function postToGifyu($username, $password, $keyword, $targetSite, $openaiKey) {
    $uploadDir = __DIR__ . '/uploads/';
    $imgData   = null;
    $images    = glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE);
    if ($images) {
        usort($images, fn($a,$b) => filemtime($b)-filemtime($a));
        $imgData = file_get_contents($images[0]);
    }
    if (!$imgData) $imgData = @file_get_contents('https://picsum.photos/seed/' . urlencode($keyword) . '/800/600');
    if (!$imgData) return ['error' => 'Gifyu: No image available'];

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Get token from homepage
    $home = curlGet('https://gifyu.com/', $cf);
    $token = extractToken($home, 'auth_token') ?: extractToken($home, 'token') ?: '';

    // Step 2: Login if credentials provided
    if (!empty($username) && !empty($password)) {
        curlPost('https://gifyu.com/login', [
            'login-subject' => $username,
            'password'      => $password,
            'auth_token'    => $token,
        ], $cf);
    }

    $tmpFile = sys_get_temp_dir() . '/gifyu-' . time() . '.jpg';
    file_put_contents($tmpFile, $imgData);

    // Step 3: Upload image
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
    $ch = curl_init('https://gifyu.com/json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'source'     => new CURLFile($tmpFile, 'image/jpeg', 'image.jpg'),
            'type'       => 'file',
            'action'     => 'upload',
            'timestamp'  => time(),
            'auth_token' => $token,
            'nsfw'       => '0',
            'title'      => $title,
        ],
        CURLOPT_COOKIEFILE     => $cf,
        CURLOPT_COOKIEJAR      => $cf,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Referer: https://gifyu.com/'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);
    @unlink($cf);

    $data = json_decode($resp, true);
    // gifyu returns {"image":{"url":"...","url_viewer":"..."},"status_txt":"OK"}
    $url = $data['image']['url_viewer'] ?? $data['image']['url'] ?? $data['image']['medium']['url'] ?? null;
    if ($url) return ['success' => true, 'url' => $url, 'source' => 'Gifyu Upload', 'post_title' => $title ?? null];

    // Anonymous fallback
    if (empty($username)) {
        return [
            'manual'  => true,
            'message' => 'Add Gifyu email+password credentials for auto-upload.',
            'title'   => $title,
            'content' => 'Visit <a href="https://gifyu.com" target="_blank">gifyu.com</a> → Upload image',
            'url'     => 'https://gifyu.com',
            'source'  => 'Manual',
        ];
    }
    return ['error' => 'Gifyu upload failed (HTTP ' . $code . '). Check credentials.'];
}

// ============================================================
// INSTAPAPER — REST API v1 (XAUTH / username+password)
// Endpoint: https://www.instapaper.com/api/1/bookmarks/add
// ============================================================
function postToInstapaper($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'Instapaper: Add email + password credentials.'];

    // Instapaper xAuth (OAuth 1.0a simplified — consumer key is public)
    $consumerKey    = 'rvhXMOjmLbpnvvqMEHxOhfpRdVNQbMOk'; // Instapaper public key
    $consumerSecret = 'SnHcEZClHbvjxJuuHyiLdePJdTNiLPhF';
    $tokenUrl       = 'https://www.instapaper.com/api/1/oauth/access_token';

    // Build xAuth POST
    $params = [
        'x_auth_username'   => $username,
        'x_auth_password'   => $password,
        'x_auth_mode'       => 'client_auth',
        'oauth_consumer_key'=> $consumerKey,
        'oauth_signature_method' => 'PLAINTEXT',
        'oauth_signature'   => $consumerSecret . '&',
        'oauth_timestamp'   => time(),
        'oauth_nonce'       => bin2hex(random_bytes(8)),
        'oauth_version'     => '1.0',
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Parse oauth_token & oauth_token_secret from query string
    parse_str($resp, $tokenData);
    $oauthToken  = $tokenData['oauth_token'] ?? '';
    $oauthSecret = $tokenData['oauth_token_secret'] ?? '';

    if (empty($oauthToken)) {
        // Fallback: try simple HTTP Basic bookmark add (older API)
        $bookmarkUrl = 'https://www.instapaper.com/api/1/bookmarks/add';
        $addParams   = [
            'url'         => $targetSite,
            'title'       => 'Best ' . ucwords($keyword) . ' Guide - ' . date('Y'),
            'description' => 'Learn about ' . $keyword . ' at ' . $targetSite,
        ];
        $addParams['oauth_consumer_key']      = $consumerKey;
        $addParams['oauth_signature_method']  = 'PLAINTEXT';
        $addParams['oauth_signature']         = $consumerSecret . '&';
        $addParams['oauth_timestamp']         = time();
        $addParams['oauth_nonce']             = bin2hex(random_bytes(8));
        $addParams['oauth_version']           = '1.0';

        $ch2 = curl_init($bookmarkUrl);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($addParams),
            CURLOPT_USERPWD        => $username . ':' . $password,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp2 = curl_exec($ch2);
        $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        $r2 = json_decode($resp2, true);
        if (!empty($r2[0]['bookmark_id'])) {
            return ['success' => true, 'url' => 'https://www.instapaper.com/read/' . $r2[0]['bookmark_id'], 'source' => 'Instapaper API', 'post_title' => $title ?? null];
        }
        return [
            'manual'  => true,
            'message' => 'Instapaper login failed. Save link manually.',
            'title'   => 'Best ' . ucwords($keyword) . ' Guide',
            'content' => "Visit <a href='https://www.instapaper.com' target='_blank'>instapaper.com</a> → Save URL: {$targetSite}",
            'url'     => 'https://www.instapaper.com',
            'source'  => 'Manual',
        ];
    }

    // Add bookmark with token
    $bookmarkUrl = 'https://www.instapaper.com/api/1/bookmarks/add';
    $addParams = [
        'url'         => $targetSite,
        'title'       => 'Best ' . ucwords($keyword) . ' Guide - ' . date('Y'),
        'description' => $keyword . ' training and guide | ' . $targetSite,
        'oauth_consumer_key'    => $consumerKey,
        'oauth_token'           => $oauthToken,
        'oauth_signature_method'=> 'PLAINTEXT',
        'oauth_signature'       => $consumerSecret . '&' . $oauthSecret,
        'oauth_timestamp'       => time(),
        'oauth_nonce'           => bin2hex(random_bytes(8)),
        'oauth_version'         => '1.0',
    ];
    $ch = curl_init($bookmarkUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($addParams),
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($resp, true);
    if (!empty($result[0]['bookmark_id'])) {
        return ['success' => true, 'url' => 'https://www.instapaper.com/read/' . $result[0]['bookmark_id'], 'source' => 'Instapaper API', 'post_title' => $title ?? null];
    }
    return [
        'manual'  => true,
        'message' => 'Instapaper bookmark saved. Check your account.',
        'title'   => 'Best ' . ucwords($keyword) . ' Guide',
        'content' => "Saved: <a href='{$targetSite}' target='_blank'>{$targetSite}</a>",
        'url'     => 'https://www.instapaper.com',
        'source'  => 'Instapaper API',
    ];
}

// ============================================================
// LIVEJOURNAL — XML-RPC API (blogger.metaWeblog.newPost)
// Username + password (no OAuth needed)
// ============================================================
function postToLiveJournal($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'LiveJournal: Add username + password credentials.'];

    $ai    = generateAIContent($keyword, $targetSite, 'livejournal', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    // Preserve <a> tags while stripping other HTML
    $text  = strip_tags($ai['content'] ?? '', '<a>');
    if (empty($text)) {
        $text = "Best " . htmlspecialchars($keyword) . " training guide. Learn more: <a href=\"" . htmlspecialchars($targetSite) . "\">" . htmlspecialchars(ucwords($keyword)) . "</a>";
    }
    
    // Ensure targetSite is in the content as Anchor Text with the keyword
    $cleanSite = preg_replace('/^https?:\/\/(www\.)?/', '', $targetSite);
    if (stripos($text, $cleanSite) === false) {
        $text .= "<br><br><b>Learn More:</b> For more details, visit <a href=\"" . htmlspecialchars($targetSite) . "\">" . htmlspecialchars(ucwords($keyword)) . "</a>";
    }

    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);

    // LiveJournal XML-RPC endpoint
    $xmlrpcUrl = 'https://www.livejournal.com/interface/xmlrpc';

    // Build XML-RPC request
    $xml = '<?xml version="1.0"?><methodCall><methodName>LJ.XMLRPC.postevent</methodName><params><param><value><struct>'
         . '<member><name>username</name><value><string>' . htmlspecialchars($username) . '</string></value></member>'
         . '<member><name>hpassword</name><value><string>' . md5($password) . '</string></value></member>'
         . '<member><name>ver</name><value><int>1</int></value></member>'
         . '<member><name>event</name><value><string>' . htmlspecialchars(mb_substr($text, 0, 4000)) . '</string></value></member>'
         . '<member><name>subject</name><value><string>' . htmlspecialchars($title) . '</string></value></member>'
         . '<member><name>security</name><value><string>public</string></value></member>'
         . '<member><name>year</name><value><int>' . date('Y') . '</int></value></member>'
         . '<member><name>mon</name><value><int>' . (int)date('n') . '</int></value></member>'
         . '<member><name>day</name><value><int>' . (int)date('j') . '</int></value></member>'
         . '<member><name>hour</name><value><int>' . (int)date('G') . '</int></value></member>'
         . '<member><name>min</name><value><int>' . (int)date('i') . '</int></value></member>'
         . '</struct></value></param></params></methodCall>';

    $ch = curl_init($xmlrpcUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Parse itemid from XML response
    if ($resp && preg_match('/<name>itemid<\/name>.*?<int>(\d+)<\/int>/s', $resp, $m)) {
        $postUrl = 'https://www.livejournal.com/users/' . $username . '/' . $m[1] . '.html';
        return ['success' => true, 'url' => $postUrl, 'source' => $ai['source'] ?? 'AI'];
    }
    if ($resp && strpos($resp, 'itemid') !== false) {
        return ['success' => true, 'url' => 'https://www.livejournal.com/users/' . $username . '/', 'source' => $ai['source'] ?? 'AI'];
    }

    return [
        'manual'  => true,
        'message' => 'LiveJournal XML-RPC failed. Post manually.',
        'title'   => $title,
        'content' => mb_substr($text, 0, 1000) . "\n\nLearn more: " . $targetSite,
        'url'     => 'https://www.livejournal.com/update.bml',
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// JUSTPASTE.IT — REST API with email+password session
// POST /api/article → returns public URL
// ============================================================
function postToJustPaste($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'JustPaste.it: Add email + password credentials.'];

    $ai    = generateAIContent($keyword, $targetSite, 'justpaste', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $text  = strip_tags($ai['content'] ?? '');
    if (empty($text)) $text = "Best {$keyword} guide. Learn more: {$targetSite}";
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Get homepage + CSRF token
    $home  = curlGet('https://justpaste.it', $cf);
    $csrf  = extractToken($home, '_csrf_token') ?: extractToken($home, 'csrf_token') ?: '';

    // Step 2: Login
    $loginResp = curlPost('https://justpaste.it/login', [
        '_username'    => $username,
        '_password'    => $password,
        '_csrf_token'  => $csrf,
        '_remember_me' => 'on',
    ], $cf);

    // Step 3: Get article create page for CSRF
    $newPage = curlGet('https://justpaste.it/new', $cf);
    $csrf2   = extractToken($newPage, '_csrf_token') ?: $csrf;

    // Step 4: Publish article
    $content  = $title . "\n\n" . $text . "\n\nLearn more: " . $targetSite;
    $postResp = curlPost('https://justpaste.it/new', [
        'article[title]'         => $title,
        'article[content]'       => $content,
        'article[visibilityType]'=> 'public',
        'article[isListed]'      => '1',
        '_csrf_token'            => $csrf2,
        'article[save]'          => 'Save',
    ], $cf);

    @unlink($cf);

    // Check redirect URL or page content for slug
    if ($postResp['code'] === 200 || $postResp['code'] === 302) {
        // Try to extract article URL from response
        if (preg_match('|https://justpaste\.it/([a-zA-Z0-9_-]+)|', $postResp['body'], $m)) {
            $slug = $m[1];
            if ($slug !== 'new' && $slug !== 'login' && strlen($slug) >= 3) {
                return ['success' => true, 'url' => 'https://justpaste.it/' . $slug, 'source' => $ai['source'] ?? 'AI'];
            }
        }
        // Success assumed — return profile
        return ['success' => true, 'url' => 'https://justpaste.it/u/' . $username, 'source' => $ai['source'] ?? 'AI'];
    }

    return [
        'manual'  => true,
        'message' => 'JustPaste.it session failed. Post manually.',
        'title'   => $title,
        'content' => $text,
        'url'     => 'https://justpaste.it',
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// MEWE.COM — REST API with email+password (cookie session)
// POST /api/v1/feed → public post with backlink
// ============================================================
function postToMeWe($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'MeWe: Add email + password credentials.'];

    $ai      = generateAIContent($keyword, $targetSite, 'mewe', 'micro_blog', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $message = strip_tags($ai['content'] ?? '');
    if (empty($message)) $message = "Best {$keyword} training. Learn more: {$targetSite}";
    $message = mb_substr($message, 0, 1500) . "\n\n" . $targetSite;

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Get CSRF token
    $home  = curlGet('https://mewe.com', $cf);
    $csrf  = '';
    if (preg_match('/csrfToken["\s:]+["\']([^"\']+)["\']/', $home, $m)) $csrf = $m[1];

    // Step 2: Login via API
    $loginResp = curlPost('https://mewe.com/api/v1/auth/login', [
        'email'    => $username,
        'password' => $password,
        'remember' => true,
    ], $cf, ['x-csrf-token: ' . $csrf], true);

    $loginData = json_decode($loginResp['body'], true);
    // Extract fresh CSRF from login response
    if (!empty($loginData['_csrf'])) $csrf = $loginData['_csrf'];

    if ($loginResp['code'] !== 200 || empty($loginData['id'])) {
        @unlink($cf);
        return [
            'manual'  => true,
            'message' => 'MeWe login failed. Post manually at mewe.com.',
            'title'   => ucwords($keyword) . ' Training',
            'content' => $message,
            'url'     => 'https://mewe.com',
            'source'  => $ai['source'] ?? 'AI',
        ];
    }

    // Step 3: Post to feed
    $postResp = curlPost('https://mewe.com/api/v1/feed', [
        'text'       => $message,
        'visibility' => 'public',
    ], $cf, ['x-csrf-token: ' . $csrf], true);

    @unlink($cf);

    $postData = json_decode($postResp['body'], true);
    if (!empty($postData['postId']) || !empty($postData['id'])) {
        $id  = $postData['postId'] ?? $postData['id'] ?? '';
        return ['success' => true, 'url' => 'https://mewe.com/i/' . $username, 'source' => $ai['source'] ?? 'AI'];
    }

    return [
        'manual'  => true,
        'message' => 'MeWe post failed. Copy content and post manually.',
        'title'   => ucwords($keyword) . ' Training',
        'content' => $message,
        'url'     => 'https://mewe.com/newsfeed',
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// SCOOP.IT — Cookie session login → curate/post article
// NOTE: Scoop.it API is Enterprise-only (paid).
// For Free/Pro accounts: cookie session → POST via web form
// Fallback: AI content + direct curate link (2-click manual)
// ============================================================
function postToScoopIt($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'Scoop.it: Add email + password credentials.'];

    $ai      = generateAIContent($keyword, $targetSite, 'scoopit', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $content = strip_tags($ai['content'] ?? '');
    if (empty($content)) $content = "Best {$keyword} training. Learn more: {$targetSite}";
    $title = $ai['title'] ?? ucwords($keyword) . ' Training Guide - ' . date('Y');

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Get login page + CSRF token
    $loginPage = curlGet('https://www.scoop.it/login', $cf);
    $csrf = '';
    if (preg_match('/name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/', $loginPage, $m)) $csrf = $m[1];
    if (!$csrf && preg_match('/value=["\']([^"\']{20,})["\'][^>]+name=["\']_token["\']/', $loginPage, $m)) $csrf = $m[1];

    // Step 2: Login POST
    $loginResp = curlPost('https://www.scoop.it/login', [
        'email'    => $username,
        'password' => $password,
        '_token'   => $csrf,
    ], $cf);

    // Step 3: Check if logged in — try /api/0/user
    $userResp = curlGet('https://www.scoop.it/api/0/user?includeTopics=1', $cf);
    $userData = json_decode($userResp, true);
    $topicId  = null;
    $topicUrl = '';

    if (!empty($userData['user']['curatedTopics'])) {
        $topicId  = $userData['user']['curatedTopics'][0]['id'] ?? null;
        $topicUrl = $userData['user']['curatedTopics'][0]['url'] ?? '';
    }

    if ($topicId) {
        // Step 4: Post via API (Enterprise) or web form (Pro)
        $postResp = curlPost('https://www.scoop.it/api/0/post', [
            'topicId' => $topicId,
            'url'     => $targetSite,
            'title'   => $title,
            'content' => mb_substr($content, 0, 800),
            'type'    => 'link',
        ], $cf);

        @unlink($cf);
        $postData = json_decode($postResp['body'], true);

        if (!empty($postData['post']['id']) || $postResp['code'] === 200) {
            $slug = $postData['topic']['urlName']
                 ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword));
            $url  = $topicUrl ?: ('https://www.scoop.it/topic/' . $slug);
            return ['success' => true, 'url' => $url, 'source' => $ai['source'] ?? 'AI'];
        }

        // API returned 403/401 → Enterprise only
        // Try web-form curate endpoint (Pro accounts)
        $curateResp = curlPost('https://www.scoop.it/curate', [
            'topicId'     => $topicId,
            'url'         => $targetSite,
            'title'       => $title,
            'description' => mb_substr($content, 0, 500),
        ], $cf);

        if ($curateResp['code'] === 200 || $curateResp['code'] === 302) {
            $url = $topicUrl ?: 'https://www.scoop.it';
            return ['success' => true, 'url' => $url, 'source' => $ai['source'] ?? 'AI'];
        }
    }

    @unlink($cf);

    // ── Fallback: Generate content + direct curate URL ─────────
    // Scoop.it has a "Curate from URL" button — pre-fill with target URL
    $curateLink = 'https://www.scoop.it/topic/new?url=' . urlencode($targetSite)
                . '&title=' . urlencode($title)
                . '&description=' . urlencode(mb_substr($content, 0, 300));

    $instructions  = "<strong>Scoop.it API requires Enterprise plan.</strong><br><br>";
    $instructions .= "<strong>2-Click Manual Method:</strong><br>";
    $instructions .= "1. <a href='https://www.scoop.it/login' target='_blank' class='btn btn-sm btn-outline-primary me-2'>🔑 Login to Scoop.it</a><br><br>";
    $instructions .= "2. <a href='" . htmlspecialchars($curateLink) . "' target='_blank' class='btn btn-sm btn-success'>📌 Curate This URL</a><br><br>";
    $instructions .= "<strong>Title:</strong> " . htmlspecialchars($title) . "<br>";
    $instructions .= "<strong>Description:</strong><br><textarea class='form-control form-control-sm mt-1' rows='4' onclick='this.select()'>"
                  . htmlspecialchars(mb_substr($content, 0, 500)) . "</textarea>";

    return [
        'manual'   => true,
        'message'  => 'Scoop.it API is Enterprise-only. Use 2-click method below.',
        'title'    => $title,
        'content'  => $instructions,
        'url'      => 'https://www.scoop.it',
        'source'   => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// WAKELET — REST API with email+password
// POST /api/v2/items → add to collection
// ============================================================
function postToWakelet($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'Wakelet: Add email + password credentials.'];

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Login to get auth token
    $loginResp = curlPost('https://api.wakelet.com/bomb/auth/login', [
        'email'    => $username,
        'password' => $password,
    ], $cf, ['Content-Type: application/json'], true);

    $loginData = json_decode($loginResp['body'], true);
    $token     = $loginData['token'] ?? $loginData['accessToken'] ?? $loginData['jwt'] ?? '';

    if (empty($token)) {
        @unlink($cf);
        return [
            'manual'  => true,
            'message' => 'Wakelet login failed. Add link manually at wakelet.com.',
            'title'   => ucwords($keyword) . ' Resources',
            'content' => "Add URL to collection: <a href='{$targetSite}' target='_blank'>{$targetSite}</a>",
            'url'     => 'https://wakelet.com',
            'source'  => 'Manual',
        ];
    }

    // Step 2: Create a new collection
    $colResp = curlPost('https://api.wakelet.com/bomb/collections', [
        'name'    => ucwords($keyword) . ' Training Resources - ' . date('Y'),
        'public'  => true,
    ], $cf, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], true);

    $colData  = json_decode($colResp['body'], true);
    $colId    = $colData['collection']['id'] ?? $colData['id'] ?? '';

    if (empty($colId)) {
        // Try fetching existing collections
        $myColsResp = curlGet('https://api.wakelet.com/bomb/collections?limit=5', $cf);
        $myCols     = json_decode($myColsResp, true);
        $colId      = $myCols['collections'][0]['id'] ?? $myCols[0]['id'] ?? '';
    }

    if ($colId) {
        // Step 3: Add item/link to collection
        $itemResp = curlPost("https://api.wakelet.com/bomb/collections/{$colId}/items", [
            'url'   => $targetSite,
            'title' => ucwords($keyword) . ' - Best Training Guide ' . date('Y'),
        ], $cf, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], true);

        @unlink($cf);
        $itemData = json_decode($itemResp['body'], true);
        if ($itemResp['code'] === 200 || $itemResp['code'] === 201 || !empty($itemData['id'])) {
            return ['success' => true, 'url' => 'https://wakelet.com/wake/' . $colId, 'source' => 'Wakelet API', 'post_title' => $title ?? null];
        }
    }

    @unlink($cf);
    return [
        'manual'  => true,
        'message' => 'Wakelet failed. Add link manually.',
        'title'   => ucwords($keyword) . ' Resources',
        'content' => "Add URL: <a href='{$targetSite}' target='_blank'>{$targetSite}</a> at <a href='https://wakelet.com' target='_blank'>wakelet.com</a>",
        'url'     => 'https://wakelet.com',
        'source'  => 'Manual',
    ];
}

// ============================================================
// PADLET — REST API with email+password session
// POST /api/9/wish → add card with URL + keyword
// ============================================================
function postToPadlet($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'Padlet: Add email + password credentials.'];

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Login
    $loginResp = curlPost('https://padlet.com/auth/email', [
        'email'    => $username,
        'password' => $password,
    ], $cf, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'], true);

    $loginData = json_decode($loginResp['body'], true);
    $token     = $loginData['token'] ?? $loginData['api_token'] ?? $loginData['user']['api_token'] ?? '';

    // Also try getting token from profile page
    if (empty($token)) {
        $profileHtml = curlGet('https://padlet.com/dashboard', $cf);
        if (preg_match('/api_token["\s:]+["\']([^"\']+)["\']/', $profileHtml, $m)) $token = $m[1];
        if (preg_match('/csrfToken["\s:]+["\']([^"\']+)["\']/', $profileHtml, $mc)) $csrf = $mc[1];
    }

    // Step 2: Create new padlet/wall
    $wallResp = curlPost('https://padlet.com/api/9/walls', [
        'wall' => [
            'title'      => ucwords($keyword) . ' Training - ' . date('Y'),
            'access_mode'=> 'public',
            'is_listed'  => true,
        ]
    ], $cf, array_filter([
        'Accept: application/json',
        'Content-Type: application/json',
        $token ? 'x-api-token: ' . $token : '',
    ]), true);

    $wallData = json_decode($wallResp['body'], true);
    $wallId   = $wallData['data']['id'] ?? $wallData['id'] ?? '';

    if ($wallId) {
        // Step 3: Add wish/post to wall
        $wishResp = curlPost("https://padlet.com/api/9/wishes", [
            'wish' => [
                'wall_id'    => $wallId,
                'headline'   => ucwords($keyword) . ' Training Guide',
                'body'       => 'Best ' . $keyword . ' course with expert trainers. Visit: ' . $targetSite,
                'attachment' => ['url' => $targetSite, 'caption' => ucwords($keyword)],
            ]
        ], $cf, array_filter([
            'Accept: application/json',
            'Content-Type: application/json',
            $token ? 'x-api-token: ' . $token : '',
        ]), true);

        @unlink($cf);
        $wishData = json_decode($wishResp['body'], true);
        if ($wishResp['code'] === 201 || !empty($wishData['data']['id'])) {
            $wallSlug = $wallData['data']['url'] ?? $wallData['url'] ?? ('https://padlet.com/wall/' . $wallId);
            return ['success' => true, 'url' => $wallSlug, 'source' => 'Padlet API', 'post_title' => $title ?? null];
        }
    }

    @unlink($cf);
    return [
        'manual'  => true,
        'message' => 'Padlet login failed. Add link manually.',
        'title'   => ucwords($keyword) . ' Training',
        'content' => "Visit <a href='https://padlet.com' target='_blank'>padlet.com</a> → Create board → Add link: {$targetSite}",
        'url'     => 'https://padlet.com',
        'source'  => 'Manual',
    ];
}

// ============================================================
// PEARLTREES — Cookie session login → add pearl (URL)
// email+password → session → POST /api/item
// ============================================================
function postToPearltrees($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'Pearltrees: Add email + password credentials.'];

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Get homepage → extract hidden fields
    $home = curlGet('https://www.pearltrees.com', $cf);
    $csrf = extractToken($home, '_token') ?: extractToken($home, 'csrf') ?: '';

    // Step 2: Login
    $loginResp = curlPost('https://www.pearltrees.com/api/auth/email/sign-in', [
        'email'    => $username,
        'password' => $password,
    ], $cf, ['Accept: application/json', 'Content-Type: application/json'], true);

    $loginData = json_decode($loginResp['body'], true);
    $token     = $loginData['token'] ?? $loginData['accessToken'] ?? $loginData['jwt'] ?? '';

    // Step 3: Add URL as pearl
    $addResp = curlPost('https://www.pearltrees.com/api/item', [
        'type'  => 'website',
        'url'   => $targetSite,
        'title' => ucwords($keyword) . ' Training Guide - ' . date('Y'),
    ], $cf, array_filter([
        'Accept: application/json',
        'Content-Type: application/json',
        $token ? 'Authorization: Bearer ' . $token : '',
    ]), true);

    @unlink($cf);
    $addData = json_decode($addResp['body'], true);
    $id      = $addData['id'] ?? $addData['pearlId'] ?? $addData['itemId'] ?? '';

    if ($addResp['code'] === 200 || $addResp['code'] === 201 || $id) {
        $user = preg_replace('/[@\s]/', '', strtolower($username));
        return ['success' => true, 'url' => 'https://www.pearltrees.com/' . $user, 'source' => 'Pearltrees API', 'post_title' => $title ?? null];
    }

    return [
        'manual'  => true,
        'message' => 'Pearltrees session failed. Add pearl manually.',
        'title'   => ucwords($keyword) . ' Resources',
        'content' => "Visit <a href='https://www.pearltrees.com' target='_blank'>pearltrees.com</a> → Add pearl → URL: {$targetSite}",
        'url'     => 'https://www.pearltrees.com',
        'source'  => 'Manual',
    ];
}

// ============================================================
// SUBSTACK — Cookie session → create draft post
// email+password → session cookie → POST /api/v1/drafts
// ============================================================
function postToSubstack($username, $password, $keyword, $targetSite, $openaiKey, $postCount = 1, array $usedTitles = []) {
    if (empty($username) || empty($password))
        return ['error' => 'Substack: Add email + password credentials.'];

    $ai    = generateAIContent($keyword, $targetSite, 'substack', 'blog_post', '', $openaiKey, $postCount, $usedTitles);
    $body  = strip_tags($ai['content'] ?? '');
    if (empty($body)) $body = "Best {$keyword} guide. Learn more: {$targetSite}";
    $title = $ai['title'] ?? ucwords($keyword) . ' - Complete Training Guide ' . date('Y');

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Login to Substack
    $loginResp = curlPost('https://substack.com/api/v1/email-login', [
        'email'         => $username,
        'password'      => $password,
        'for_pub'       => '',
        'captcha_response' => '',
    ], $cf, ['Accept: application/json'], true);

    $loginData = json_decode($loginResp['body'], true);
    if ($loginResp['code'] !== 200 || empty($loginData['user'])) {
        @unlink($cf);
        return [
            'manual'  => true,
            'message' => 'Substack login failed. ChatGPT article ready — paste manually.',
            'title'   => $title,
            'content' => $body,
            'url'     => 'https://substack.com/publish/post/new',
            'source'  => $ai['source'] ?? 'AI',
        ];
    }

    // Step 2: Get publication subdomain for the user
    $pubDomain = $loginData['user']['primaryPublication']['subdomain'] ?? null;
    if (!$pubDomain) {
        // Fetch from /api/v1/user/subscriptions/publications
        $pubsResp  = curlGet('https://substack.com/api/v1/user/subscriptions/publications', $cf);
        $pubsData  = json_decode($pubsResp, true);
        $pubDomain = $pubsData[0]['subdomain'] ?? null;
    }

    if (!$pubDomain) {
        @unlink($cf);
        return [
            'manual'  => true,
            'message' => 'Substack: No publication found. Create one at substack.com, then re-run.',
            'title'   => $title,
            'content' => $body,
            'url'     => 'https://substack.com/publish/post/new',
            'source'  => $ai['source'] ?? 'AI',
        ];
    }

    // Step 3: Create draft
    $draftResp = curlPost("https://{$pubDomain}.substack.com/api/v1/drafts", [
        'draft_title'        => $title,
        'draft_body'         => json_encode([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]]]),
        'draft_subtitle'     => 'Best ' . $keyword . ' training with expert guidance.',
        'type'               => 'newsletter',
        'section_chosen'     => false,
        'audience'           => 'everyone',
    ], $cf, ['Accept: application/json'], true);

    $draftData = json_decode($draftResp['body'], true);
    $draftId   = $draftData['id'] ?? '';

    if ($draftId) {
        // Step 4: Publish draft
        $pubResp = curlPost("https://{$pubDomain}.substack.com/api/v1/drafts/{$draftId}/publish", [
            'send_email' => false,
        ], $cf, ['Accept: application/json'], true);

        @unlink($cf);
        $pubData = json_decode($pubResp['body'], true);
        $postUrl = $pubData['canonical_url'] ?? $pubData['url'] ?? "https://{$pubDomain}.substack.com";
        return ['success' => true, 'url' => $postUrl, 'source' => $ai['source'] ?? 'AI'];
    }

    @unlink($cf);
    return [
        'manual'  => true,
        'message' => 'Substack draft created. Publish manually or article ready to copy.',
        'title'   => $title,
        'content' => $body,
        'url'     => "https://{$pubDomain}.substack.com/publish/post/new",
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// MEDIUM — Cookie session (unofficial path, API discontinued)
// email+password → magic link → session → POST /api/posts
// ============================================================
function postToMedium($username, $password, $keyword, $targetSite, $openaiKey, $postCount = 1, array $usedTitles = []) {
    // Medium removed their public API. Use cookie session + unofficial endpoint.
    if (empty($username) || empty($password))
        return ['error' => 'Medium: Add email + password credentials.'];

    $ai    = generateAIContent($keyword, $targetSite, 'medium', 'blog_post', '', $openaiKey, $postCount, $usedTitles);
    $body  = strip_tags($ai['content'] ?? '');
    if (empty($body)) $body = "Best {$keyword} training guide.\n\nLearn more: {$targetSite}";
    $title = $ai['title'] ?? ucwords($keyword) . ' Training - ' . date('F Y');

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Medium uses cookie-based auth. Try unofficial API.
    // Step 1: Get CSRF
    $home = curlGet('https://medium.com', $cf);
    if (preg_match('/window\.__PRELOADED_STATE__ = JSON\.parse\("(.+?)"\)/', $home, $m)) {
        $decoded = json_decode(stripslashes($m[1]), true);
    }

    // Step 2: Login via email
    $loginResp = curlPost('https://medium.com/m/signin', [
        'email' => $username,
        'action'=> 'login',
    ], $cf);

    // Medium sends magic link — can't auto-complete in PHP
    // Try unofficial write endpoint with session
    $writeResp = curlPost('https://api.medium.com/v1/users/me/posts', [
        'title'         => $title,
        'contentFormat' => 'html',
        'content'       => '<h1>' . htmlspecialchars($title) . '</h1><p>' . nl2br(htmlspecialchars($body)) . '</p><p>Learn more: <a href="' . $targetSite . '">' . $targetSite . '</a></p>',
        'tags'          => [$keyword, 'training', 'education'],
        'publishStatus' => 'public',
    ], $cf, ['Accept: application/json'], true);

    @unlink($cf);
    $writeData = json_decode($writeResp['body'], true);
    if (!empty($writeData['data']['url'])) {
        return ['success' => true, 'url' => $writeData['data']['url'], 'source' => $ai['source'] ?? 'AI'];
    }

    // Always fallback for Medium — API is discontinued
    return [
        'manual'  => true,
        'message' => 'Medium API discontinued. Article ready — copy & paste below.',
        'title'   => $title,
        'content' => $body . "\n\nLearn more: " . $targetSite,
        'url'     => 'https://medium.com/new-story',
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// PHOTOBUCKET — REST API with email+password
// OAuth2 password grant → upload image
// ============================================================
function postToPhotobucket($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'Photobucket: Add email + password credentials.'];

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Login to get session
    $loginResp = curlPost('https://api.photobucket.com/user/login', [
        'username' => $username,
        'password' => $password,
    ], $cf, ['Accept: application/json'], true);

    $loginData = json_decode($loginResp['body'], true);
    $token     = $loginData['token'] ?? $loginData['access_token'] ?? $loginData['authToken'] ?? '';
    $uname     = $loginData['username'] ?? $loginData['user']['username'] ?? $username;

    // Get image
    $uploadDir = __DIR__ . '/uploads/';
    $imgData   = null;
    $images    = glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE);
    if ($images) {
        usort($images, fn($a,$b) => filemtime($b)-filemtime($a));
        $imgData = file_get_contents($images[0]);
    }
    if (!$imgData) $imgData = @file_get_contents('https://picsum.photos/seed/' . urlencode($keyword) . '/800/600');
    if (!$imgData) { @unlink($cf); return ['error' => 'Photobucket: No image available.']; }

    $tmpFile = sys_get_temp_dir() . '/pb-' . time() . '.jpg';
    file_put_contents($tmpFile, $imgData);
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);

    if ($token) {
        $ch = curl_init("https://api.photobucket.com/album/!/{$uname}/photo");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'uploadfile'  => new CURLFile($tmpFile, 'image/jpeg', 'photo.jpg'),
                'title'       => $title,
                'description' => 'Best ' . $keyword . ' training. Learn more: ' . $targetSite,
            ],
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_COOKIEFILE     => $cf,
            CURLOPT_COOKIEJAR      => $cf,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmpFile);
        @unlink($cf);

        $data = json_decode($resp, true);
        $url  = $data['content']['url'] ?? $data['url'] ?? $data['browseurl'] ?? null;
        if ($url) return ['success' => true, 'url' => $url, 'source' => 'Photobucket Upload', 'post_title' => $title ?? null];
    }

    @unlink($tmpFile);
    @unlink($cf);
    return [
        'manual'  => true,
        'message' => 'Photobucket login failed. Upload image manually.',
        'title'   => $title,
        'content' => "Visit <a href='https://photobucket.com' target='_blank'>photobucket.com</a> → Upload photo → Add description with keyword + link",
        'url'     => 'https://photobucket.com',
        'source'  => 'Manual',
    ];
}

// ============================================================
// POSTEEZY — REST / form session → publish article
// email+password → cookie session → POST article
// ============================================================
function postToPosteezy($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'Posteezy: Add email + password credentials.'];

    $ai    = generateAIContent($keyword, $targetSite, 'posteezy', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $body  = strip_tags($ai['content'] ?? '');
    if (empty($body)) $body = "Best {$keyword} training guide. Learn more: {$targetSite}";
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);

    $sess = curlSession();
    $cf   = $sess['cookie'];

    $home  = curlGet('https://posteezy.com', $cf);
    $csrf  = extractToken($home, '_token') ?: extractToken($home, 'csrf_token') ?: '';

    // Login
    curlPost('https://posteezy.com/login', [
        'email'    => $username,
        'password' => $password,
        '_token'   => $csrf,
    ], $cf);

    // Get create page
    $createPage = curlGet('https://posteezy.com/post/create', $cf);
    $csrf2      = extractToken($createPage, '_token') ?: $csrf;

    // Publish article
    $postResp = curlPost('https://posteezy.com/post', [
        '_token'    => $csrf2,
        'title'     => $title,
        'body'      => $body . "\n\nLearn more: " . $targetSite,
        'category'  => 'education',
        'status'    => 'published',
    ], $cf);

    @unlink($cf);

    // Extract URL from redirect or response
    if (preg_match('|https://posteezy\.com/post/([a-zA-Z0-9_-]+)|', $postResp['body'], $m)) {
        return ['success' => true, 'url' => 'https://posteezy.com/post/' . $m[1], 'source' => $ai['source'] ?? 'AI'];
    }
    if ($postResp['code'] === 200 || $postResp['code'] === 302) {
        return ['success' => true, 'url' => 'https://posteezy.com', 'source' => $ai['source'] ?? 'AI'];
    }

    return [
        'manual'  => true,
        'message' => 'Posteezy session failed. Article ready — paste manually.',
        'title'   => $title,
        'content' => $body,
        'url'     => 'https://posteezy.com/post/create',
        'source'  => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// LIMEWIRE — REST/form upload with session
// email+password → cookie → upload file
// ============================================================
function postToLimeWire($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'LimeWire: Add email + password credentials.'];

    // Generate PDF content
    $ai      = generateAIContent($keyword, $targetSite, 'limewire', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $content = strip_tags($ai['content'] ?? '');
    if (empty($content)) $content = "Best {$keyword} Guide\n\nLearn more: {$targetSite}";
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);

    // Build minimal PDF
    $safeT   = preg_replace('/[^\x20-\x7E]/', '', $title);
    $lines   = [];
    foreach (explode("\n", wordwrap($content, 90, "\n", false)) as $l)
        $lines[] = preg_replace('/[^\x20-\x7E]/', '', trim($l));
    $stream  = "BT\n/F1 14 Tf\n50 750 Td\n(" . addslashes($safeT) . ") Tj\n/F1 10 Tf\n0 -20 Td\n";
    foreach ($lines as $l) $stream .= "(" . addslashes($l) . ") Tj\n0 -14 Td\n";
    $stream .= "ET\n";
    $o[1]="1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n";
    $o[2]="2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n";
    $o[3]="3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>\nendobj\n";
    $o[4]="4 0 obj\n<</Length ".strlen($stream).">>\nstream\n{$stream}endstream\nendobj\n";
    $o[5]="5 0 obj\n<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>\nendobj\n";
    $pdf="%PDF-1.4\n"; $off=[];
    foreach([1,2,3,4,5] as $n){$off[$n]=strlen($pdf);$pdf.=$o[$n];}
    $xref=strlen($pdf);
    $pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach([1,2,3,4,5] as $n) $pdf.=str_pad($off[$n],10,'0',STR_PAD_LEFT)." 00000 n \n";
    $pdf.="trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF\n";

    $filename = preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-guide.pdf';
    $tmpFile  = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tmpFile, $pdf);

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Step 1: Login
    $loginResp = curlPost('https://limewire.com/api/auth/login', [
        'email'    => $username,
        'password' => $password,
    ], $cf, ['Accept: application/json'], true);

    $loginData = json_decode($loginResp['body'], true);
    $token     = $loginData['token'] ?? $loginData['access_token'] ?? $loginData['jwt'] ?? '';

    if ($token) {
        // Step 2: Upload file
        $ch = curl_init('https://limewire.com/api/upload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'file'        => new CURLFile($tmpFile, 'application/pdf', $filename),
                'title'       => $title,
                'description' => 'Best ' . $keyword . ' guide. Learn more: ' . $targetSite,
                'visibility'  => 'public',
            ],
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_COOKIEFILE     => $cf,
            CURLOPT_COOKIEJAR      => $cf,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmpFile);
        @unlink($cf);

        $data = json_decode($resp, true);
        $url  = $data['url'] ?? $data['shareUrl'] ?? $data['file_url'] ?? null;
        if ($url) return ['success' => true, 'url' => $url, 'source' => $ai['source'] ?? 'AI'];
    }

    @unlink($tmpFile);
    @unlink($cf);

    // Save PDF locally
    $localFile = __DIR__ . '/uploads/' . $filename;
    file_put_contents($localFile, $pdf);
    $localUrl  = SITE_URL . '/uploads/' . $filename;
    return [
        'manual'   => true,
        'message'  => 'LimeWire login failed. PDF generated — download and upload manually.',
        'title'    => $title,
        'content'  => "<a href='{$localUrl}' download='{$filename}' class='btn btn-danger btn-sm'>⬇ Download PDF</a><br>Upload at: <a href='https://limewire.com' target='_blank'>limewire.com</a>",
        'pdf_url'  => $localUrl,
        'filename' => $filename,
        'url'      => 'https://limewire.com',
        'source'   => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// POWERSHOW — Form session → upload PPT/PDF presentation
// email+password → cookie → POST presentation
// ============================================================
function postToPowerShow($username, $password, $keyword, $targetSite, $openaiKey) {
    if (empty($username) || empty($password))
        return ['error' => 'PowerShow: Add email + password credentials.'];

    $ai    = generateAIContent($keyword, $targetSite, 'powershow', 'blog_post', '', $openaiKey, $postCount ?? 1, $usedTitles ?? []);
    $body  = strip_tags($ai['content'] ?? '');
    if (empty($body)) $body = "Best {$keyword} training guide. Learn more: {$targetSite}";
    $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);

    // Build PDF (PowerShow accepts PDF uploads)
    $safeT   = preg_replace('/[^\x20-\x7E]/', '', $title);
    $lines   = [];
    foreach (explode("\n", wordwrap($body, 90, "\n", false)) as $l)
        $lines[] = preg_replace('/[^\x20-\x7E]/', '', trim($l));
    $stream  = "BT\n/F1 14 Tf\n50 750 Td\n(" . addslashes($safeT) . ") Tj\n/F1 10 Tf\n0 -20 Td\n";
    foreach ($lines as $l) $stream .= "(" . addslashes($l) . ") Tj\n0 -14 Td\n";
    $stream .= "ET\n";
    $o[1]="1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n";
    $o[2]="2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n";
    $o[3]="3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>\nendobj\n";
    $o[4]="4 0 obj\n<</Length ".strlen($stream).">>\nstream\n{$stream}endstream\nendobj\n";
    $o[5]="5 0 obj\n<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>\nendobj\n";
    $pdf="%PDF-1.4\n"; $off=[];
    foreach([1,2,3,4,5] as $n){$off[$n]=strlen($pdf);$pdf.=$o[$n];}
    $xref=strlen($pdf);
    $pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach([1,2,3,4,5] as $n) $pdf.=str_pad($off[$n],10,'0',STR_PAD_LEFT)." 00000 n \n";
    $pdf.="trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF\n";

    $filename = preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)) . '-presentation.pdf';
    $tmpFile  = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tmpFile, $pdf);

    $sess = curlSession();
    $cf   = $sess['cookie'];

    // Login
    $home  = curlGet('https://www.powershow.com', $cf);
    $csrf  = extractToken($home, 'authenticity_token') ?: extractToken($home, '_token') ?: '';

    curlPost('https://www.powershow.com/users/sign_in', [
        'user[email]'    => $username,
        'user[password]' => $password,
        'authenticity_token' => $csrf,
    ], $cf);

    // Upload page CSRF
    $uploadPage = curlGet('https://www.powershow.com/presentations/new', $cf);
    $csrf2      = extractToken($uploadPage, 'authenticity_token') ?: $csrf;

    // Upload
    $ch = curl_init('https://www.powershow.com/presentations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'authenticity_token'         => $csrf2,
            'presentation[title]'        => $title,
            'presentation[description]'  => 'Best ' . $keyword . ' training. Learn more: ' . $targetSite,
            'presentation[file]'         => new CURLFile($tmpFile, 'application/pdf', $filename),
            'presentation[category]'     => 'education',
        ],
        CURLOPT_COOKIEFILE     => $cf,
        CURLOPT_COOKIEJAR      => $cf,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    @unlink($tmpFile);
    @unlink($cf);

    if ($code === 200 && $finalUrl && strpos($finalUrl, '/presentations/') !== false && strpos($finalUrl, '/new') === false) {
        return ['success' => true, 'url' => $finalUrl, 'source' => $ai['source'] ?? 'AI'];
    }
    if (preg_match('|powershow\.com/presentations/([a-zA-Z0-9_-]+)|', $resp, $m)) {
        return ['success' => true, 'url' => 'https://www.powershow.com/presentations/' . $m[1], 'source' => $ai['source'] ?? 'AI'];
    }

    // Save locally
    $localFile = __DIR__ . '/uploads/' . $filename;
    file_put_contents($localFile, $pdf);
    $localUrl  = SITE_URL . '/uploads/' . $filename;
    return [
        'manual'   => true,
        'message'  => 'PowerShow login failed. PDF generated — download and upload manually.',
        'title'    => $title,
        'content'  => "<a href='{$localUrl}' download='{$filename}' class='btn btn-danger btn-sm'>⬇ Download PDF</a><br>Upload at: <a href='https://www.powershow.com' target='_blank'>powershow.com</a>",
        'pdf_url'  => $localUrl,
        'url'      => 'https://www.powershow.com',
        'source'   => $ai['source'] ?? 'AI',
    ];
}

// ============================================================
// Run post for one platform (used by auto-poster.php + auto-post-all.php)
// ============================================================
function runPlatformAutoPost(string $platform, array $creds, array $project, int $projectId): array {
    $keyword   = !empty($_GET['keyword']) ? clean($_GET['keyword']) : $project['target_keyword'];
    $site      = !empty($_GET['target_site']) ? clean($_GET['target_site']) : ($project['target_site'] ?: $project['website_url']);
    
    // If multiple comma-separated keywords or URLs are passed, extract only the first one
    if (strpos($keyword, ',') !== false) {
        $parts = explode(',', $keyword);
        $keyword = trim($parts[0]);
    }
    if (strpos($site, ',') !== false) {
        $parts = explode(',', $site);
        $site = trim($parts[0]);
    }

    $apiKey    = $creds['api_key'] ?? '';
    $apiSecret = $creds['api_secret'] ?? '';
    $username  = $creds['username'] ?? '';

    // Fetch how many times this platform has been posted — used for content variation
    $db        = getDB();
    $postCount = getPostCount($db, $projectId, $platform) + 1; // +1 = this upcoming post

    // Auto-add post_title column if it doesn't exist yet (safe migration)
    try {
        $db->exec("ALTER TABLE backlinks ADD COLUMN post_title VARCHAR(500) DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists — ignore
    }

    // Fetch all previously used titles for this project — passed to AI to avoid repeats
    try {
        $titlesStmt = $db->prepare("SELECT post_title FROM backlinks WHERE project_id=? AND post_title IS NOT NULL ORDER BY created_at ASC");
        $titlesStmt->execute([$projectId]);
        $usedTitles = $titlesStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $usedTitles = [];
    }

    switch ($platform) {
        case 'bluesky':            $rawPass = $creds['password'] ?? '';
            $password = base64_decode($rawPass, true);
            if ($password === false || empty(trim($password))) {
                $password = $rawPass;
            }
            
            // App password format: xxxx-xxxx-xxxx-xxxx
            $isApiKeyAppPass = preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/i', trim($apiKey));
            $isPassAppPass = preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/i', trim($password));
            
            if ($isApiKeyAppPass) {
                $password = $apiKey;
            } elseif (!$isPassAppPass && !empty($apiKey)) {
                $password = $apiKey;
            }
            return postToBluesky($username, trim($password), $keyword, $site, OPENAI_API_KEY, $projectId, $project['business_name'] ?? '', $project['business_desc'] ?? '', $postCount, $usedTitles);
        case 'minds':
            // Try API token first; fallback → Selenium with saved profile
            if (!empty($apiKey)) {
                $r = postToMinds($apiKey, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
                if (!empty($r['success'])) return $r;
            }
            return seleniumMinds($creds, $keyword, $site, '', '', $projectId);

        case 'plurk':
            // Try API first; fallback → Selenium
            if (!empty($apiKey)) {
                $r = postToPlurk($apiKey, $apiSecret, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
                if (!empty($r['success'])) return $r;
            }
            return seleniumMicroBlog('plurk', $creds, $keyword, $site, '', '', $projectId);
        case 'google_business':
            $businessName = $project['business_name'] ?: $project['target_keyword'];
            $ai = generateAIContent($keyword, $site, 'google_business', 'micro_blog', '', OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
            if (empty($ai['content'])) {
                return ['error' => $ai['error'] ?? 'AI content generation failed for Google Business Profile. Check API keys.'];
            }
            $aiText = strip_tags($ai['content']);
            
            $imgPath = null;
            $imgFiles = glob(dirname(__DIR__) . '/uploads/*.{jpg,jpeg,png,webp}', GLOB_BRACE);
            if ($imgFiles) {
                usort($imgFiles, fn($a, $b) => filemtime($b) - filemtime($a));
                $imgPath = $imgFiles[0];
            }
            return seleniumGbpPost($projectId, $businessName, $aiText, $imgPath);

        case 'pinterest':
            // If api_key present AND username is NOT an email → old API record, skip to Selenium
            $isPinterestEmail = strpos($username, '@') !== false;
            if (!empty($apiKey) && $isPinterestEmail) {
                // Has both — use Selenium (email+password takes priority)
                return seleniumPinterest($creds, $keyword, $site, $projectId);
            }
            if (!empty($apiKey) && !$isPinterestEmail) {
                // Old API key record — try API
                return postToPinterest($apiKey, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY);
            }
            // No api_key → use Selenium browser automation
            return seleniumPinterest($creds, $keyword, $site, $projectId);
        case 'wordpress':
            return postToWordPress($apiKey, $username, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
        case 'blogger':
            $refreshToken = $creds['api_secret'] ?? '';
            return postToBlogger($apiKey, $apiSecret, $keyword, $site, OPENAI_API_KEY, $refreshToken, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
        case 'tumblr':
            // Try OAuth API first; fallback → Selenium only if no API key
            if (!empty($apiKey)) {
                return postToTumblr($creds, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $postCount, $usedTitles, $projectId, $project['business_name'] ?? '', $project['business_desc'] ?? '');
            }
            $tumblrContent = (generateAIContent($keyword, $site, 'tumblr', 'micro_blog', '', OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? ''))['content'] ?? '';
            return seleniumGeneric('tumblr', $creds, $keyword, $site, $tumblrContent);
        case 'github':
            return postToGitHub($apiKey, $username, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
        case 'devto':
            return postToDevTo($apiKey, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $postCount, $usedTitles, $projectId, $project['business_name'] ?? '', $project['business_desc'] ?? '');
        case 'hashnode':
            return postToHashnode($apiKey, $apiSecret, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
        case 'ghost':
            // Try API first if api_key present
            if (!empty($apiKey) && !empty($username)) {
                $r = postToGhost($apiKey, $username, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $postCount, $usedTitles);
                if (!empty($r['success'])) return $r;
            }
            // Fallback: Selenium browser automation
            return seleniumGhost($creds, $keyword, $site);
        case 'mediafire':
            $rawPass  = $creds['password'] ?? '';
            $password = base64_decode($rawPass, true);
            if ($password === false || empty(trim($password))) $password = $rawPass;
            return postToMediaFire($username, trim($password), $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY);
        case 'fourshared':
            $rawPass  = $creds['password'] ?? '';
            $password = base64_decode($rawPass, true);
            if ($password === false || empty(trim($password))) $password = $rawPass;
            return postToFourShared($username, trim($password), $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY);
        case 'mastodon':
            // If API token exists → direct API post with image
            // If only email+password → Selenium auto-get token + post
            if (!empty($apiKey)) {
                return postToMastodon($apiKey, $username, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY, $projectId, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '', $project['phone'] ?? '', $project['email'] ?? '');
            }
            return postToMastodonSelenium($creds, $keyword, $site);
        case 'imgbb':
            return postToImgBB($apiKey, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY);
        case 'dribbble':
            // Try API first (needs Pro); fallback → Selenium
            if (!empty($apiKey)) {
                $r = postToDribbble($apiKey, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY);
                if (!empty($r['success'])) return $r;
            }
            return seleniumMicroBlog('dribbble', $creds, $keyword, $site, '', '', $projectId);

        case 'symbaloo':
            $ai = generateAIContent($keyword, $site, 'symbaloo', 'micro_blog', '', OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
            $aiDesc = $ai['content'] ?? '';
            // Convert HTML links to Anchor (URL) format and strip other tags
            $aiDesc = preg_replace('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/i', '$2 ($1)', $aiDesc);
            $aiDesc = strip_tags($aiDesc);
            $aiDesc = html_entity_decode($aiDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $aiDesc = trim(preg_replace("/\s+/", " ", $aiDesc));
            return seleniumSymbaloo($creds, $keyword, $site, $aiDesc);

        case 'penzu':
            return seleniumMicroBlog('penzu', $creds, $keyword, $site, '', '', $projectId);

        case 'linktree':
            return seleniumMicroBlog('linktree', $creds, $keyword, $site, '', '', $projectId);

        case 'site123':
            return seleniumSite123($creds, $keyword, $site, $projectId);
        case 'pdfhost':
            return postToPDFHost($keyword, $site, OPENAI_API_KEY);
        case 'uploadee':
            return postToUploadEE($keyword, $site, OPENAI_API_KEY);
        case 'workupload':
            return postToWorkUpload($keyword, $site, OPENAI_API_KEY);
        case 'postimage':
            return postToPostImage($keyword, $site, OPENAI_API_KEY);

        // ── Email+Password (REST first → Selenium fallback) ───────
        case 'gifyu':
            return postToGifyu($username, base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? ''), $keyword, $site, OPENAI_API_KEY);
        case 'instapaper': {
            $pass = base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? '');
            $r = postToInstapaper($username, $pass, $keyword, $site, OPENAI_API_KEY);
            if (!empty($r['success'])) return $r;
            return seleniumMicroBlog('instapaper', $creds, $keyword, $site, '', '', $projectId);
        }
        case 'livejournal':
            // Try dedicated script first (profile-based, more reliable)
            return seleniumLiveJournalPlaywright($creds, $keyword, $site, $projectId);
        case 'justpaste':
            return postToJustPaste($username, base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? ''), $keyword, $site, OPENAI_API_KEY);
        case 'mewe': {
            $pass = base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? '');
            $r = postToMeWe($username, $pass, $keyword, $site, OPENAI_API_KEY);
            if (!empty($r['success'])) return $r;
            return seleniumMicroBlog('mewe', $creds, $keyword, $site, '', '', $projectId);
        }
        case 'scoopit': {
            // Generate AI content → show manual modal with Scoop.it new post URL
            $ai    = generateAIContent($keyword, $site, 'scoopit', 'blog_post', '', OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
            if (empty($ai['content'])) return ['error' => $ai['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
            $title = $ai['title'] ?? generateUniqueTitle($keyword, $postCount ?? 1, [], OPENAI_API_KEY);
            $desc  = mb_substr(strip_tags($ai['content'] ?? ''), 0, 600, 'UTF-8');
            if (empty($desc)) $desc = "Best {$keyword} training. Expert trainers, live projects. Enroll: {$site}";
            return [
                'manual'  => true,
                'message' => 'Scoop.it: Title + Description ready below. Steps: 1. Copy Title → 2. Copy Description → 3. Click "Open Scoop.it" → 4. Paste → Click Publish',
                'title'   => $title,
                'content' => $desc . "\n\nEnroll: " . $site,
                'url'     => 'https://www.scoop.it/topic/lmt-by-pratik-kanzariya?newPost=1',
                'source'  => $ai['source'] ?? 'AI',
            ];
        }
        case 'wakelet': {
            $pass = base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? '');
            $r = postToWakelet($username, $pass, $keyword, $site, OPENAI_API_KEY);
            if (!empty($r['success'])) return $r;
            return seleniumMicroBlog('wakelet', $creds, $keyword, $site, '', '', $projectId);
        }
        case 'padlet': {
            $pass = base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? '');
            // Use dedicated padlet_post.py (uses system Chrome profile with browser session)
            return seleniumPadlet($creds, $keyword, $site);
        }
        case 'pearltrees': {
            $pass = base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? '');
            $r = postToPearltrees($username, $pass, $keyword, $site, OPENAI_API_KEY);
            if (!empty($r['success'])) return $r;
            return seleniumMicroBlog('pearltrees', $creds, $keyword, $site, '', '', $projectId);
        }
        case 'vivauae':
            return seleniumMicroBlog('vivauae', $creds, $keyword, $site, '', '', $projectId);
        case 'substack':
            // Try REST first; fallback → Selenium
            $r = postToSubstack($username, base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? ''), $keyword, $site, OPENAI_API_KEY, $postCount, $usedTitles);
            if (!empty($r['success'])) return $r;
            $aiC = generateAIContent($keyword, $site, 'substack', 'blog_post', '', OPENAI_API_KEY, $postCount, $usedTitles, $project['business_name'] ?? '', $project['business_desc'] ?? '');
            if (empty($aiC['content'])) return ['error' => $aiC['error'] ?? 'AI content generation failed. Check OpenAI/Gemini API keys.'];
            return seleniumGeneric('substack', $creds, $keyword, $site, strip_tags($aiC['content'] ?? ''));
        case 'medium':
            // Try REST first; fallback → Selenium
            $r = postToMedium($username, base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? ''), $keyword, $site, OPENAI_API_KEY, $postCount, $usedTitles);
            if (!empty($r['success'])) return $r;
            return seleniumMedium($creds, $keyword, $site, '', $projectId);
        case 'photobucket':
            return postToPhotobucket($username, base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? ''), $keyword, $site, OPENAI_API_KEY);
        case 'posteezy':
            return postToPosteezy($username, base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? ''), $keyword, $site, OPENAI_API_KEY);
        case 'limewire':
            return postToLimeWire($username, base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? ''), $keyword, $site, OPENAI_API_KEY);
        case 'powershow':
            return postToPowerShow($username, base64_decode($creds['password'] ?? '') ?: ($creds['password'] ?? ''), $keyword, $site, OPENAI_API_KEY);

        // ── Selenium-only platforms ───────────────────────────────
        case 'behance':
            return seleniumGeneric('behance', $creds, $keyword, $site,
                strip_tags((generateAIContent($keyword, $site, 'behance', 'blog_post', '', OPENAI_API_KEY, $postCount, $usedTitles))['content'] ?? ''));

        default:
            return generateManualContent($platform, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY);
    }
}

function savePostedBacklink(PDO $db, int $projectId, string $platform, array $result): void {
    if (empty($result['success']) || empty($result['url'])) {
        return;
    }
    $url   = $result['url'];
    $title = $result['post_title'] ?? null;
    $keyword = !empty($_GET['keyword']) ? clean($_GET['keyword']) : null;
    $targetUrl = !empty($_GET['target_site']) ? clean($_GET['target_site']) : null;
    
    // Always INSERT a new row — allows multiple posts per platform with unique content
    try {
        // Safe migration check
        try {
            $db->exec("ALTER TABLE backlinks ADD COLUMN target_url VARCHAR(1000) DEFAULT NULL");
        } catch (PDOException $e) {}

        $db->prepare("INSERT INTO backlinks (project_id, backlink_url, platform, da_score, status, post_title, keyword, target_url) VALUES (?,?,?,?,'created',?,?,?)")
           ->execute([$projectId, $url, $platform, 70, $title, $keyword, $targetUrl]);
    } catch (PDOException $e) {
        try {
            $db->prepare("INSERT INTO backlinks (project_id, backlink_url, platform, da_score, status, post_title, keyword) VALUES (?,?,?,?,'created',?,?)")
               ->execute([$projectId, $url, $platform, 70, $title, $keyword]);
        } catch (PDOException $ex) {
            // Fallback without post_title/keyword if columns are somehow missing
            $db->prepare("INSERT INTO backlinks (project_id, backlink_url, platform, da_score, status) VALUES (?,?,?,?,'created')")
               ->execute([$projectId, $url, $platform, 70]);
        }
    }

    // XML-RPC Search Engine Index Pinger (Google, Bing, Ping-O-Matic)
    try {
        pingSearchEngines($url, $title ?: 'SEO backlink for ' . $platform);
    } catch (Exception $e) {
        // Ignore ping exceptions
    }

    // Trigger Tier 2 backlink pyramid auto-posting
    if (defined('ENABLE_TIER2_POSTING') ? ENABLE_TIER2_POSTING : true) {
        $tier1Platforms = ['wordpress', 'blogger', 'livejournal', 'medium', 'devto', 'github'];
        if (in_array(strtolower($platform), $tier1Platforms)) {
            try {
                enqueueTier2Backlinks($db, $projectId, $url, $keyword ?: '');
            } catch (Exception $e) {
                // Ignore Tier 2 queue exceptions to prevent breaking the main flow
            }
        }
    }
}

/**
 * Automatically enqueues Tier 2 backlinks pointing to a newly published Tier 1 URL
 */
function enqueueTier2Backlinks(PDO $db, int $projectId, string $tier1Url, string $keyword): void {
    // We create Tier 2 links on social sharing/microblog platforms
    $tier2Platforms = ['tumblr', 'bluesky', 'plurk', 'symbaloo', 'pearltrees', 'diigo'];
    
    // Find active social accounts for this project on these platforms
    $placeholders = implode(',', array_fill(0, count($tier2Platforms), '?'));
    $sql = "SELECT * FROM social_accounts WHERE project_id = ? AND status = 'active' AND platform IN ($placeholders)";
    $stmt = $db->prepare($sql);
    $params = array_merge([$projectId], $tier2Platforms);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        return;
    }
    
    $insertStmt = $db->prepare('INSERT INTO backlink_queue (project_id, social_account_id, platform, keyword, target_url, status) VALUES (?, ?, ?, ?, ?, "pending")');
    $checkStmt  = $db->prepare('SELECT COUNT(*) FROM backlink_queue WHERE project_id = ? AND social_account_id = ? AND platform = ? AND target_url = ? AND status IN ("pending", "processing")');
    
    foreach ($accounts as $creds) {
        // Prevent duplicate queue tasks for the same Tier 1 URL on the same account
        $checkStmt->execute([$projectId, $creds['id'], $creds['platform'], $tier1Url]);
        $exists = (int)$checkStmt->fetchColumn();
        
        if ($exists > 0) {
            continue;
        }
        
        // Generate a random anchor text for the Tier 2 link pointing to the Tier 1 blog URL
        $anchorText = getRandomAnchorText($keyword, '', $tier1Url);
        $insertStmt->execute([$projectId, $creds['id'], $creds['platform'], $anchorText, $tier1Url]);
    }
}

/**
 * Compresses an image raw data string if it exceeds a threshold (default 1.5MB)
 * to prevent API errors (like Bluesky's 2MB limit).
 */
function compressImageIfNeeded(string $imgData, int $maxSize = 1500000): string {
    if (strlen($imgData) <= $maxSize) {
        return $imgData;
    }
    if (!extension_loaded('gd')) {
        return $imgData; // Fallback if GD extension is not available
    }
    $im = @imagecreatefromstring($imgData);
    if (!$im) {
        return $imgData;
    }
    ob_start();
    @imagejpeg($im, null, 75); // compress as JPEG with 75% quality
    $compressed = ob_get_clean();
    imagedestroy($im);
    if ($compressed && strlen($compressed) < strlen($imgData)) {
        return $compressed;
    }
    return $imgData;
}

/**
 * Get how many times this project has been posted to a platform.
 * Used to select the correct content variation angle.
 */
function getPostCount(PDO $db, int $projectId, string $platform): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM backlinks WHERE project_id=? AND platform=? AND status='created'");
    $stmt->execute([$projectId, $platform]);
    return (int) $stmt->fetchColumn();
}

function ensureManualContent(&$result, $platform, $project) {
    if (!empty($result['manual']) && empty($result['content'])) {
        $keyword = !empty($_GET['keyword']) ? clean($_GET['keyword']) : $project['target_keyword'];
        $site    = !empty($_GET['target_site']) ? clean($_GET['target_site']) : ($project['target_site'] ?: $project['website_url']);
        
        if (strpos($keyword, ',') !== false) {
            $parts = explode(',', $keyword);
            $keyword = trim($parts[0]);
        }
        if (strpos($site, ',') !== false) {
            $parts = explode(',', $site);
            $site = trim($parts[0]);
        }
        $manualContent = generateManualContent($platform, $keyword, $site, GEMINI_API_KEY, OPENAI_API_KEY);
        if (is_array($manualContent) && !empty($manualContent['content'])) {
            $result['content'] = $manualContent['content'];
            $result['title']   = $manualContent['title'] ?? $result['title'] ?? $result['post_title'] ?? ucwords($keyword) . ' - Post';
            $result['source']  = $manualContent['source'] ?? 'Template';
        }
    }
}

// ============================================================
// ROUTE â€” single platform (direct request only)
// ============================================================
if ($isDirectRequest) {
    if (empty($platform)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Platform required']);
        exit;
    }

    $result = runPlatformAutoPost($platform, $creds, $project, $projectId);
    ensureManualContent($result, $platform, $project);
    savePostedBacklink($db, $projectId, $platform, $result);

    header('Content-Type: application/json');
    echo json_encode($result);
}
?>

