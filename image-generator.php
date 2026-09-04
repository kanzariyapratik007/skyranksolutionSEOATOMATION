<?php
require_once 'config.php';
require_once 'ai-content.php';
require_once __DIR__ . '/includes/poster-builder.php';

// ============================================================
// Image Generator — Learnmore marketing poster (screenshot style)
// ChatGPT: tagline + benefits | GD: layout + phone + email + city photo
// ============================================================

/**
 * ChatGPT builds DALL-E prompt from keyword + target site URL
 * Generates unique prompts every time using random seed
 */
function generateImagePromptForProject(string $keyword, string $targetSite, string $phone = '', string $email = ''): string {
    if (empty($phone)) $phone = '9036354554';
    if (empty($email)) $email = 'office.learnmore@gmail.com';
    // Send the user-defined custom prompt directly to DALL-E 3
    return "{$keyword} {$targetSite} Phone: {$phone} Email: {$email} mane seo post mate image banvi ne apo";
}

function addOverlay($imagePath, $keyword, $phone, $email, $targetSite, $outputPath) {
    if (!function_exists('imagecreatefromjpeg')) {
        return false;
    }

    $info = getimagesize($imagePath);
    if (!$info) {
        return false;
    }

    $src = null;
    if ($info[2] === IMAGETYPE_JPEG) {
        $src = imagecreatefromjpeg($imagePath);
    } elseif ($info[2] === IMAGETYPE_PNG) {
        $src = imagecreatefrompng($imagePath);
    } elseif ($info[2] === IMAGETYPE_WEBP) {
        $src = imagecreatefromwebp($imagePath);
    }

    if (!$src) {
        return false;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    $white     = imagecolorallocate($src, 255, 255, 255);
    $yellow    = imagecolorallocate($src, 255, 193, 7);
    $dark      = imagecolorallocatealpha($src, 0, 0, 0, 40);
    $darkSolid = imagecolorallocate($src, 10, 10, 30);
    $red       = imagecolorallocate($src, 220, 50, 50);
    $accent    = imagecolorallocate($src, 255, 193, 7);

    $logoExt  = @file_get_contents(__DIR__ . '/assets/logo_ext.txt');
    $logoFile = $logoExt ? __DIR__ . '/assets/logo.' . trim($logoExt) : null;

    if ($logoFile && file_exists($logoFile)) {
        $ext = strtolower(trim($logoExt));
        $logoImg = null;
        if ($ext === 'png') {
            $logoImg = @imagecreatefrompng($logoFile);
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $logoImg = @imagecreatefromjpeg($logoFile);
        } elseif ($ext === 'webp') {
            $logoImg = @imagecreatefromwebp($logoFile);
        } elseif ($ext === 'gif') {
            $logoImg = @imagecreatefromgif($logoFile);
        }

        if ($logoImg) {
            $lw = imagesx($logoImg);
            $lh = imagesy($logoImg);
            $maxW = 200;
            $maxH = 60;
            $scale = min($maxW / $lw, $maxH / $lh);
            $newW  = (int) ($lw * $scale);
            $newH  = (int) ($lh * $scale);
            $logoX = $w - $newW - 15;
            $logoY = 15;
            imagefilledrectangle($src, $logoX - 5, $logoY - 5, $logoX + $newW + 5, $logoY + $newH + 5, $white);
            imagecopyresampled($src, $logoImg, $logoX, $logoY, 0, 0, $newW, $newH, $lw, $lh);
            imagedestroy($logoImg);
        }
    } else {
        $logoW = 220;
        $logoH = 55;
        $logoX = $w - $logoW - 15;
        $logoY = 15;
        imagefilledrectangle($src, $logoX, $logoY, $logoX + $logoW, $logoY + $logoH, $white);
        imagestring($src, 5, $logoX + 10, $logoY + 5, 'LT', $red);
        imagestring($src, 3, $logoX + 35, $logoY + 5, 'Learnmore', $red);
        imagestring($src, 2, $logoX + 35, $logoY + 22, 'Technologies', $darkSolid);
    }

    $kwShort = mb_substr(strtoupper($keyword), 0, 40);
    imagefilledrectangle($src, 0, $h - 130, $w, $h - 60, $dark);

    $barY = $h - 60;
    imagefilledrectangle($src, 0, $barY, $w, $h, $darkSolid);
    imagefilledrectangle($src, 0, $barY, $w, $barY + 3, $accent);

    imagestring($src, 4, 20, $barY + 8, '  ' . $phone, $white);
    imagestring($src, 4, (int) ($w / 2), $barY + 8, '  ' . $email, $white);
    imagestring($src, 3, 20, $barY + 35, '  ' . str_replace(['https://', 'http://'], '', $targetSite), $yellow);

    imagejpeg($src, $outputPath, 92);
    imagedestroy($src);
    return true;
}

function generateWithPollinations($prompt, $outputPath) {
    $fullPrompt = $prompt . ', professional marketing poster, high quality, 4K, no watermark';
    $url = 'https://image.pollinations.ai/prompt/' . urlencode($fullPrompt)
        . '?width=1080&height=1080&nologo=true&enhance=true&seed=' . rand(1, 99999) . '&model=flux';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $imageData = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($imageData && $httpCode === 200 && strlen($imageData) > 5000) {
        file_put_contents($outputPath, $imageData);
        return true;
    }
    return false;
}

function generateGDFallback($keyword, $targetSite, $phone, $email, $outputPath) {
    if (!function_exists('imagecreatetruecolor')) {
        return ['error' => 'Image generation not available (GD extension missing)'];
    }

    $w = 1080;
    $h = 1080;
    $img = imagecreatetruecolor($w, $h);

    $themes = [
        [[13, 27, 62], [26, 35, 126], [255, 193, 7]],
        [[0, 77, 64], [0, 121, 107], [255, 235, 59]],
    ];
    $t = $themes[array_rand($themes)];

    for ($y = 0; $y < $h; $y++) {
        $r = (int) ($t[0][0] + ($t[1][0] - $t[0][0]) * $y / $h);
        $g = (int) ($t[0][1] + ($t[1][1] - $t[0][1]) * $y / $h);
        $b = (int) ($t[0][2] + ($t[1][2] - $t[0][2]) * $y / $h);
        imageline($img, 0, $y, $w, $y, imagecolorallocate($img, $r, $g, $b));
    }

    $accent = imagecolorallocate($img, ...$t[2]);
    $white  = imagecolorallocate($img, 255, 255, 255);
    $dark   = imagecolorallocate($img, 10, 10, 30);
    $gray   = imagecolorallocate($img, 180, 180, 180);

    $kw = strtoupper($keyword);
    imagestring($img, 5, 40, 120, mb_substr($kw, 0, 35), $accent);
    imagestring($img, 3, 40, 200, mb_substr(str_replace(['https://', 'http://'], '', $targetSite), 0, 45), $white);

    imagefilledrectangle($img, 0, $h - 90, $w, $h, $dark);
    imagestring($img, 4, 30, $h - 75, $phone, $white);
    imagestring($img, 4, 30, $h - 50, $email, $white);

    imagejpeg($img, $outputPath, 92);
    imagedestroy($img);
    return ['success' => true, 'source' => 'GD Fallback (add ChatGPT key for DALL-E)'];
}

/**
 * Main: AWS/Ahmedabad style poster — keyword + phone + email + city background
 */
function generateMarketingImage(string $keyword, string $targetSite, string $phone, string $email, string $outputPath) {
    // We send the user's custom raw prompt directly to Pollinations (Free and Unlimited)
    $dallePrompt = generateImagePromptForProject($keyword, $targetSite, $phone, $email);

    if (generateWithPollinations($dallePrompt, $outputPath)) {
        return ['success' => true, 'source' => 'Pollinations (Free and Unlimited)'];
    }

    return generateGDFallback($keyword, $targetSite, $phone, $email, $outputPath);
}

// ============================================================
// API endpoint
// ============================================================
if (isset($_GET['generate']) && isset($_GET['project_id'])) {
    requireLogin();
    $db        = getDB();
    $projectId = (int) $_GET['project_id'];

    $project = $db->prepare('SELECT * FROM projects WHERE id=? AND user_id=?');
    $project->execute([$projectId, $_SESSION['user_id']]);
    $project = $project->fetch();

    if (!$project) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Project not found']);
        exit;
    }

    $keyword    = isset($_GET['keyword']) && $_GET['keyword'] !== '' ? $_GET['keyword'] : $project['target_keyword'];
    $targetSite = isset($_GET['target_site']) && $_GET['target_site'] !== '' ? $_GET['target_site'] : ($project['target_site'] ?: $project['website_url']);

    if (empty($keyword) || empty($targetSite)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Project needs Target Keyword and Target Site URL']);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'auto_img_' . $projectId . '_' . time() . '.jpg';
    $path     = $uploadDir . $filename;

    // Fetch dynamic client phone and email from project settings
    $phone = '9036354554';
    $email = 'office.learnmore@gmail.com';
    try {
        $projRow = $db->prepare("SELECT phone, email FROM projects WHERE id = ?");
        $projRow->execute([$projectId]);
        $projInfo = $projRow->fetch(PDO::FETCH_ASSOC);
        if ($projInfo) {
            if (!empty($projInfo['phone'])) {
                $phone = $projInfo['phone'];
            }
            if (!empty($projInfo['email'])) {
                $email = $projInfo['email'];
            }
        }
    } catch (Exception $e) {}

    $result = generateMarketingImage(
        $keyword,
        $targetSite,
        $phone,
        $email,
        $path
    );

    header('Content-Type: application/json');

    if (!empty($result['success'])) {
        $db->prepare('UPDATE projects SET post_image=? WHERE id=?')->execute([$filename, $projectId]);
        echo json_encode([
            'success'       => true,
            'image'         => SITE_URL . '/uploads/' . $filename . '?t=' . time(),
            'source'        => $result['source'],
            'keyword'       => $keyword,
            'target_site'   => $targetSite,
            'message'       => 'Marketing poster created (Keyword + Phone + Email): ' . $keyword,
        ]);
    } else {
        echo json_encode(['error' => $result['error'] ?? 'Generation failed. Check OpenAI billing.']);
    }
    exit;
}
