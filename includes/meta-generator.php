<?php
/**
 * Meta tag analysis + AI generation for target websites
 */
require_once __DIR__ . '/../ai-content.php';

function analyzeMetaTags(string $url, string $keyword): array {
    $issues = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    $html = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $code !== 200) {
        return ['error' => 'Could not fetch website (HTTP ' . $code . ')', 'current' => [], 'issues' => []];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $getMeta = function ($name) use ($xpath) {
        $n = $xpath->query("//meta[@name='" . $name . "']/@content");
        return $n->length ? trim($n->item(0)->textContent) : '';
    };
    $getProp = function ($prop) use ($xpath) {
        $n = $xpath->query("//meta[@property='" . $prop . "']/@content");
        return $n->length ? trim($n->item(0)->textContent) : '';
    };

    $titles = $xpath->query('//title');
    $title  = $titles->length ? trim($titles->item(0)->textContent) : '';

    $current = [
        'title'            => $title,
        'description'      => $getMeta('description'),
        'keywords'         => $getMeta('keywords'),
        'robots'           => $getMeta('robots'),
        'viewport'         => $getMeta('viewport'),
        'og_title'         => $getProp('og:title') ?: $getMeta('og:title'),
        'og_description'   => $getProp('og:description'),
        'og_image'         => $getProp('og:image'),
        'og_url'           => $getProp('og:url'),
        'twitter_card'     => $getMeta('twitter:card'),
        'twitter_title'    => $getMeta('twitter:title'),
        'canonical'        => '',
    ];
    $canon = $xpath->query("//link[@rel='canonical']/@href");
    if ($canon->length) {
        $current['canonical'] = trim($canon->item(0)->textContent);
    }

    if ($title === '') {
        $issues[] = ['field' => 'title', 'severity' => 'critical', 'msg' => 'Title tag missing'];
    } elseif (mb_stripos($title, $keyword) === false) {
        $issues[] = ['field' => 'title', 'severity' => 'high', 'msg' => 'Title does not include target keyword'];
    } elseif (mb_strlen($title) < 30 || mb_strlen($title) > 60) {
        $issues[] = ['field' => 'title', 'severity' => 'medium', 'msg' => 'Title length should be 30–60 characters (now: ' . mb_strlen($title) . ')'];
    }

    if ($current['description'] === '') {
        $issues[] = ['field' => 'description', 'severity' => 'critical', 'msg' => 'Meta description missing'];
    } elseif (mb_stripos($current['description'], $keyword) === false) {
        $issues[] = ['field' => 'description', 'severity' => 'high', 'msg' => 'Meta description missing keyword'];
    } elseif (mb_strlen($current['description']) < 120 || mb_strlen($current['description']) > 160) {
        $issues[] = ['field' => 'description', 'severity' => 'medium', 'msg' => 'Meta description ideal length 120–160 chars (now: ' . mb_strlen($current['description']) . ')'];
    }

    if ($current['og_title'] === '') {
        $issues[] = ['field' => 'og', 'severity' => 'medium', 'msg' => 'Open Graph og:title missing (Facebook/WhatsApp share)'];
    }
    if ($current['og_description'] === '') {
        $issues[] = ['field' => 'og', 'severity' => 'medium', 'msg' => 'Open Graph og:description missing'];
    }
    if ($current['og_image'] === '') {
        $issues[] = ['field' => 'og', 'severity' => 'low', 'msg' => 'og:image missing — social shares look plain'];
    }
    if ($current['canonical'] === '') {
        $issues[] = ['field' => 'canonical', 'severity' => 'medium', 'msg' => 'Canonical URL missing'];
    }
    if ($current['viewport'] === '') {
        $issues[] = ['field' => 'viewport', 'severity' => 'high', 'msg' => 'viewport meta missing — mobile SEO hurt'];
    }

    return ['current' => $current, 'issues' => $issues, 'error' => null];
}

function generateMetaWithAI(array $project, ?string $customKeyword = null, ?string $customSite = null): ?array {
    $keyword = !empty($customKeyword) ? $customKeyword : $project['target_keyword'];
    $site    = !empty($customSite) ? $customSite : ($project['target_site'] ?: $project['website_url']);
    $keyword = trim(explode(',', $keyword)[0]);
    $site    = trim(explode(',', $site)[0]);
    $brand   = parse_url($site, PHP_URL_HOST) ?: 'Your Brand';

    // Verify if API Key is configured and valid
    $openaiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $isOpenAiValid = (!empty($openaiKey) && strpos($openaiKey, 'your-') === false && strpos($openaiKey, 'sk-') === 0);
    $isGeminiValid = (!empty($geminiKey) && strpos($geminiKey, 'your-') === false);

    if (!$isOpenAiValid && !$isGeminiValid) {
        return ['error' => 'API Key is not valid / missing. Please set a valid ChatGPT or Gemini key in the API Keys tab.'];
    }

    $prompt = <<<PROMPT
You are a senior SEO expert. Generate optimized meta tags for Google ranking.

Website: {$site}
Target keyword: {$keyword}
Brand/host: {$brand}

Return ONLY valid JSON (no markdown) with these exact keys:
{
  "meta_title": "50-60 chars, keyword near start, compelling",
  "meta_description": "150-160 chars, keyword + CTA + phone/location if training business",
  "meta_keywords": "8-12 comma-separated related keywords",
  "og_title": "same or shorter than title",
  "og_description": "under 200 chars",
  "h1_suggestion": "one H1 for page body",
  "schema_type": "LocalBusiness or Course or Organization",
  "schema_json": { valid JSON-LD object for schema.org with name, description, url }
}
PROMPT;

    $ai  = generateWithAI($prompt);
    $raw = $ai['text'];
    if (!$raw || ($ai['source'] ?? '') === 'Template') {
        return ['error' => 'API Call failed / API Key is not valid. Your API key might be expired, invalid, or has exceeded quota.'];
    }

    $raw = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['meta_title'])) {
        return ['error' => 'API Response parsing failed. The AI returned an invalid structure.'];
    }

    $data['meta_title']       = mb_substr(trim($data['meta_title']), 0, 70);
    $data['meta_description'] = mb_substr(trim($data['meta_description'] ?? ''), 0, 170);
    $data['meta_keywords']    = trim($data['meta_keywords'] ?? $keyword);
    $data['og_title']         = mb_substr(trim($data['og_title'] ?? $data['meta_title']), 0, 100);
    $data['og_description']   = mb_substr(trim($data['og_description'] ?? $data['meta_description']), 0, 200);
    $data['h1_suggestion']    = trim($data['h1_suggestion'] ?? ucwords($keyword));
    $data['og_image']         = rtrim($site, '/') . '/images/og-share.jpg';

    if (is_array($data['schema_json'] ?? null)) {
        $data['schema_json'] = json_encode($data['schema_json'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } else {
        $data['schema_json'] = json_encode([
            '@context'    => 'https://schema.org',
            '@type'       => $data['schema_type'] ?? 'Organization',
            'name'        => ucwords($keyword),
            'description' => $data['meta_description'],
            'url'         => $site,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    $data['full_head_html'] = buildMetaHeadHtml($data, $site);
    return $data;
}

function generateMetaFallback(array $project, ?string $customKeyword = null, ?string $customSite = null): array {
    $kw   = !empty($customKeyword) ? $customKeyword : $project['target_keyword'];
    $site = !empty($customSite) ? $customSite : ($project['target_site'] ?: $project['website_url']);
    $kw   = trim(explode(',', $kw)[0]);
    $site = trim(explode(',', $site)[0]);
    $businessName = trim($project['business_name'] ?? '');
    $businessDesc = trim($project['business_desc'] ?? '');

    // Detect niche (Real Estate / Property vs General Services / Training)
    $isProperty = false;
    $nicheWords = ['property', 'properties', 'propaty', 'propety', 'flat', 'flats', 'apartment', 'apartments', 'real estate', 'villa', 'villas', 'plot', 'plots', 'house', 'homes', 'home', 'builder', 'developer', 'infra', 'suyug', 'bhk', 'residency', 'land'];
    foreach ($nicheWords as $w) {
        if (stripos($kw, $w) !== false || stripos($businessDesc, $w) !== false) {
            $isProperty = true;
            break;
        }
    }

    if ($isProperty) {
        $brand = !empty($businessName) ? $businessName : 'Luxury Homes';
        $title = ucwords($kw) . ' | Premium Real Estate ' . date('Y');
        $desc  = 'Discover the best ' . $kw . '. Premium apartments, luxury flats & modern amenities. Visit ' . $site . ' to learn more.';
        $schemaType = 'LocalBusiness';
        $keywords = $kw . ', luxury flats, apartments, real estate, buy property, near me';
    } else {
        // Check if keyword is course/training related
        $isTraining = false;
        $trainWords = ['training', 'course', 'class', 'learn', 'institute', 'academy', 'placement', 'certification'];
        foreach ($trainWords as $w) {
            if (stripos($kw, $w) !== false || stripos($businessDesc, $w) !== false) {
                $isTraining = true;
                break;
            }
        }

        if ($isTraining) {
            $title = ucwords($kw) . ' | Best Training ' . date('Y');
            $desc  = 'Looking for ' . $kw . '? Expert training, certification & placement support. Visit ' . $site . ' — Enroll today!';
            $schemaType = 'Course';
            $keywords = $kw . ', training, course, certification, near me';
        } else {
            $title = ucwords($kw) . ' | Expert Services ' . date('Y');
            $desc  = 'Looking for ' . $kw . '? Professional services, expert support & high-quality solutions. Visit ' . $site . ' to learn more!';
            $schemaType = 'Organization';
            $keywords = $kw . ', services, expert, near me';
        }
    }

    $data  = [
        'meta_title'       => mb_substr($title, 0, 70),
        'meta_description' => mb_substr($desc, 0, 160),
        'meta_keywords'    => $keywords,
        'og_title'         => $title,
        'og_description'   => $desc,
        'og_image'         => $site,
        'h1_suggestion'    => ucwords($kw),
        'schema_json'      => json_encode([
            '@context' => 'https://schema.org',
            '@type'    => $schemaType,
            'name'     => ucwords($kw),
            'url'      => $site,
        ], JSON_PRETTY_PRINT),
    ];
    $data['full_head_html'] = buildMetaHeadHtml($data, $site);
    return $data;
}

function buildMetaHeadHtml(array $m, string $canonicalUrl): string {
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
  $canonicalUrl = rtrim($canonicalUrl, '/');

    return <<<HTML
<!-- SEO Meta Tags — paste inside <head> on your website -->
<title>{$esc($m['meta_title'])}</title>
<meta name="description" content="{$esc($m['meta_description'])}">
<meta name="keywords" content="{$esc($m['meta_keywords'] ?? '')}">
<meta name="robots" content="index, follow">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="canonical" href="{$esc($canonicalUrl)}">

<!-- Open Graph (Facebook, WhatsApp, LinkedIn) -->
<meta property="og:type" content="website">
<meta property="og:url" content="{$esc($canonicalUrl)}">
<meta property="og:title" content="{$esc($m['og_title'] ?? $m['meta_title'])}">
<meta property="og:description" content="{$esc($m['og_description'] ?? $m['meta_description'])}">
<meta property="og:image" content="{$esc($m['og_image'] ?? '')}">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$esc($m['og_title'] ?? $m['meta_title'])}">
<meta name="twitter:description" content="{$esc($m['og_description'] ?? $m['meta_description'])}">
<meta name="twitter:image" content="{$esc($m['og_image'] ?? '')}">

<!-- Schema.org JSON-LD -->
<script type="application/ld+json">
{$m['schema_json']}
</script>
HTML;
}

function saveProjectMeta(PDO $db, int $projectId, array $m): void {
    $db->prepare("DELETE FROM project_meta WHERE project_id=?")->execute([$projectId]);
    $db->prepare("INSERT INTO project_meta (project_id, meta_title, meta_description, meta_keywords, og_title, og_description, og_image, h1_suggestion, schema_json, full_head_html) VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([
           $projectId,
           $m['meta_title'],
           $m['meta_description'],
           $m['meta_keywords'] ?? '',
           $m['og_title'] ?? $m['meta_title'],
           $m['og_description'] ?? $m['meta_description'],
           $m['og_image'] ?? '',
           $m['h1_suggestion'] ?? '',
           $m['schema_json'] ?? '',
           $m['full_head_html'] ?? '',
       ]);
}

function loadProjectMeta(PDO $db, int $projectId): ?array {
    $stmt = $db->prepare("SELECT * FROM project_meta WHERE project_id=?");
    $stmt->execute([$projectId]);
    return $stmt->fetch() ?: null;
}
