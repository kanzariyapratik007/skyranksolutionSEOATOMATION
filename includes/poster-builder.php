<?php
/**
 * Learnmore-style marketing poster (like ChatGPT AWS Ahmedabad example)
 * Target Keyword + phone + email + location — exact text, professional layout
 */

function posterFontBold(): ?string {
    $paths = [
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/Arial Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf',
    ];
    foreach ($paths as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }
    return null;
}

function posterFontRegular(): ?string {
    $paths = [
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/Arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        __DIR__ . '/../assets/fonts/DejaVuSans.ttf',
    ];
    foreach ($paths as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }
    return null;
}

function parseKeywordForPoster(string $keyword): array {
    $keyword = trim($keyword);
    $location = '';
    $topic    = $keyword;

    if (preg_match('/^(.+?)\s+in\s+(.+)$/iu', $keyword, $m)) {
        $topic    = trim($m[1]);
        $location = trim($m[2]);
    } elseif (preg_match('/^(.+?)\s+at\s+(.+)$/iu', $keyword, $m)) {
        $topic    = trim($m[1]);
        $location = trim($m[2]);
    }

    $topicUpper = strtoupper($topic);
    $locUpper   = $location ? strtoupper($location) : '';

    // Split topic: "AWS Training" → main "AWS", sub "TRAINING"
    $words = preg_split('/\s+/', $topicUpper);
    $main  = $words[0] ?? $topicUpper;
    $sub   = count($words) > 1 ? implode(' ', array_slice($words, 1)) : 'TRAINING';

    return [
        'topic'      => $topic,
        'location'   => $location,
        'main_word'  => $main,
        'sub_word'   => $sub,
        'loc_upper'  => $locUpper,
        'full_upper' => strtoupper($keyword),
    ];
}

function getPosterCopyFromChatGPT(string $keyword, string $targetSite): array {
    $prompt = <<<PROMPT
For a professional IT training marketing poster about "{$keyword}" (website: {$targetSite}).
Return ONLY valid JSON:
{
  "tagline": "one short line under the title, max 60 chars",
  "cta": "short call to action button text, max 45 chars",
  "benefits": [
    {"title": "BENEFIT 1 TITLE", "desc": "8-12 words"},
    {"title": "BENEFIT 2 TITLE", "desc": "8-12 words"},
    {"title": "BENEFIT 3 TITLE", "desc": "8-12 words"},
    {"title": "BENEFIT 4 TITLE", "desc": "8-12 words"}
  ]
}
Topics should match "{$keyword}" (cloud, data, power bi, python, etc).
PROMPT;

    $ai = generateWithAI($prompt);
    if (!empty($ai['text'])) {
        $json = preg_replace('/^```json\s*|\s*```$/m', '', trim($ai['text']));
        $data = json_decode($json, true);
        if (is_array($data) && !empty($data['benefits'])) {
            return $data;
        }
    }

    return [
        'tagline'  => 'Learn skills. Build your career. Enroll today.',
        'cta'      => 'GROW & SHAPE YOUR FUTURE',
        'benefits' => [
            ['title' => 'LEARN FROM EXPERTS', 'desc' => 'Industry-certified trainers'],
            ['title' => 'HANDS-ON PRACTICE', 'desc' => 'Real-time projects & labs'],
            ['title' => 'CERTIFICATION', 'desc' => 'Prepare and get certified'],
            ['title' => 'BOOST CAREER', 'desc' => 'High-demand job skills'],
        ],
    ];
}

function downloadPosterBackground(string $location, string $savePath): bool {
    $query = $location ? urlencode($location . ' city landmark india') : urlencode('technology training india');
    $urls  = [
        "https://source.unsplash.com/900x720/?{$query}",
        "https://images.unsplash.com/photo-1587474260524-1414ee65c1fa?w=900&h=720&fit=crop",
    ];

    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data && $code === 200 && strlen($data) > 3000) {
            file_put_contents($savePath, $data);
            return true;
        }
    }
    return false;
}

function posterDrawText($img, int $size, int $x, int $y, string $text, int $color, bool $bold = false): void {
    $font = $bold ? posterFontBold() : posterFontRegular();
    if ($font) {
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    } else {
        $gdSize = max(1, min(5, (int) ($size / 8)));
        imagestring($img, $gdSize, $x, $y - 12, $text, $color);
    }
}

function posterDrawFilledRoundedRect($img, int $x, int $y, int $w, int $h, int $color): void {
    imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $color);
}

/**
 * Build poster matching AWS/Ahmedabad marketing style
 */
function buildLearnmoreStylePoster(
    string $keyword,
    string $phone,
    string $email,
    string $targetSite,
    string $outputPath
): array {
    if (!function_exists('imagecreatetruecolor')) {
        return ['error' => 'PHP GD extension required'];
    }

    $W = 1280;
    $H = 720;
    $img = imagecreatetruecolor($W, $H);

    $white   = imagecolorallocate($img, 255, 255, 255);
    $black   = imagecolorallocate($img, 30, 30, 30);
    $orange  = imagecolorallocate($img, 255, 153, 0);
    $navy    = imagecolorallocate($img, 35, 47, 62);
    $light   = imagecolorallocate($img, 248, 249, 252);
    $gray    = imagecolorallocate($img, 100, 100, 110);
    $whiteT  = imagecolorallocate($img, 255, 255, 255);

    imagefill($img, 0, 0, $light);

    $parts = parseKeywordForPoster($keyword);
    $copy  = getPosterCopyFromChatGPT($keyword, $targetSite);

    // Right: city / topic background
    $bgTemp = sys_get_temp_dir() . '/poster_bg_' . md5($keyword) . '.jpg';
    if (downloadPosterBackground($parts['location'] ?: $parts['topic'], $bgTemp)) {
        $bg = @imagecreatefromjpeg($bgTemp);
        if ($bg) {
            $bw = imagesx($bg);
            $bh = imagesy($bg);
            $destX = (int) ($W * 0.42);
            $destW = $W - $destX;
            imagecopyresampled($img, $bg, $destX, 0, 0, 0, $destW, $H, $bw, $bh);
            imagedestroy($bg);
        }
        @unlink($bgTemp);
    } else {
        // Gradient right side
        for ($y = 0; $y < $H; $y++) {
            $r = (int) (40 + 30 * $y / $H);
            $g = (int) (80 + 40 * $y / $H);
            $b = (int) (140 + 30 * $y / $H);
            imageline($img, (int) ($W * 0.42), $y, $W, $y, imagecolorallocate($img, $r, $g, $b));
        }
    }

    // Left white panel overlay
    $panelW = (int) ($W * 0.58);
    imagefilledrectangle($img, 0, 0, $panelW, $H, $white);

    // Soft fade between panel and photo
    for ($i = 0; $i < 40; $i++) {
        $alpha = (int) (127 - ($i * 3));
        $fade  = imagecolorallocatealpha($img, 255, 255, 255, min(127, $alpha));
        imageline($img, $panelW - 40 + $i, 0, $panelW - 40 + $i, $H, $fade);
    }

    $y = 52;

    // Title block — AWS style multi-line
    posterDrawText($img, 52, 48, $y, $parts['main_word'], $black, true);
    $y += 58;
    posterDrawText($img, 44, 48, $y, $parts['sub_word'], $navy, true);
    $y += 52;
    if ($parts['loc_upper']) {
        posterDrawText($img, 40, 48, $y, 'IN ' . $parts['loc_upper'], $orange, true);
        $y += 48;
    }

    posterDrawText($img, 16, 48, $y, $copy['tagline'] ?? '', $gray, false);
    $y += 42;

    // 4 benefit icons row
    $iconY = $y + 20;
    $iconSize = 56;
    $gap = (int) (($panelW - 60) / 4);
    $benefits = array_slice($copy['benefits'] ?? [], 0, 4);

    foreach ($benefits as $i => $b) {
        $cx = 40 + $i * $gap + (int) ($gap / 2) - (int) ($iconSize / 2);
        imagefilledellipse($img, $cx + (int) ($iconSize / 2), $iconY + (int) ($iconSize / 2), $iconSize, $iconSize, $navy);
        $letter = strtoupper(substr($b['title'] ?? 'A', 0, 1));
        posterDrawText($img, 22, $cx + 18, $iconY + 38, $letter, $whiteT, true);

        $tx = $cx - 10;
        $ty = $iconY + $iconSize + 18;
        posterDrawText($img, 11, max(8, $tx), $ty, mb_substr($b['title'] ?? '', 0, 22), $navy, true);
        posterDrawText($img, 9, max(8, $tx), $ty + 20, mb_substr($b['desc'] ?? '', 0, 28), $gray, false);
    }

    // Orange CTA button
    $ctaY = $H - 115;
    posterDrawFilledRoundedRect($img, 48, $ctaY, 420, 44, $orange);
    posterDrawText($img, 14, 62, $ctaY + 30, mb_strtoupper($copy['cta'] ?? 'ENROLL TODAY'), $whiteT, true);

    // Contact panel — bottom right (dark blue)
    $boxW = 340;
    $boxH = 130;
    $boxX = $W - $boxW - 24;
    $boxY = $H - $boxH - 20;
    imagefilledrectangle($img, $boxX, $boxY, $boxX + $boxW, $boxY + $boxH, $navy);

    posterDrawText($img, 15, $boxX + 20, $boxY + 32, $phone, $whiteT, true);
    posterDrawText($img, 13, $boxX + 20, $boxY + 58, $email, $whiteT, false);
    $locLine = $parts['loc_upper'] ? $parts['loc_upper'] . ', India' : 'India';
    posterDrawText($img, 13, $boxX + 20, $boxY + 84, $locLine, $orange, false);

    // Logo top center on panel
    $logoExt  = @file_get_contents(__DIR__ . '/../assets/logo_ext.txt');
    $logoFile = $logoExt ? __DIR__ . '/../assets/logo.' . trim($logoExt) : null;
    if ($logoFile && file_exists($logoFile)) {
        $ext = strtolower(trim($logoExt));
        $logo = null;
        if ($ext === 'png') {
            $logo = @imagecreatefrompng($logoFile);
        } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
            $logo = @imagecreatefromjpeg($logoFile);
        }
        if ($logo) {
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            $nw = 140;
            $nh = (int) ($lh * ($nw / $lw));
            imagecopyresampled($img, $logo, (int) ($panelW / 2) - 70, 12, 0, 0, $nw, $nh, $lw, $lh);
            imagedestroy($logo);
        }
    } else {
        posterDrawText($img, 14, (int) ($panelW / 2) - 80, 36, 'TRAINING PARTNER', $navy, true);
    }

    // Site URL small under contact
    $siteClean = str_replace(['https://', 'http://'], '', $targetSite);
    posterDrawText($img, 10, $boxX + 20, $boxY + 108, mb_substr($siteClean, 0, 40), $whiteT, false);

    imagejpeg($img, $outputPath, 94);
    imagedestroy($img);

    return [
        'success' => true,
        'source'  => 'ChatGPT copy + Professional Poster (Learnmore style)',
    ];
}
