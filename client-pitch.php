<?php
set_time_limit(120); // Prevent PHP execution timeout on slow PageSpeed + AI requests
require_once 'config.php';
requireLogin();

// Self-healing check for database just in case
$db = getDB();

// Default values / cookie retrieval
$agencyName = $_COOKIE['pitch_agency_name'] ?? 'SEO 80/20';
$agencyLogo = $_COOKIE['pitch_agency_logo'] ?? '';
$agencyEmail = $_COOKIE['pitch_agency_email'] ?? '';
$agencyPhone = $_COOKIE['pitch_agency_phone'] ?? '';
$agencyCta = $_COOKIE['pitch_agency_cta'] ?? 'Scale your organic revenue with automated search intelligence. Contact us today!';

$url = '';
$keyword = '';
$clientName = '';
$auditResult = null;
$pagespeedScore = null;
$aiAdvice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_audit'])) {
    $url = trim($_POST['client_url'] ?? '');
    $keyword = trim($_POST['client_keyword'] ?? '');
    $clientName = trim($_POST['client_name'] ?? 'Prospective Client');

    // Branding options
    $agencyName = trim($_POST['agency_name'] ?? 'SEO 80/20');
    $agencyLogo = trim($_POST['agency_logo'] ?? '');
    $agencyEmail = trim($_POST['agency_email'] ?? '');
    $agencyPhone = trim($_POST['agency_phone'] ?? '');
    $agencyCta = trim($_POST['agency_cta'] ?? '');

    // Handle file upload for agency logo
    if (isset($_FILES['agency_logo_file']) && $_FILES['agency_logo_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['agency_logo_file']['tmp_name'];
        $originalName = $_FILES['agency_logo_file']['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])) {
            $newName = 'pitch_logo_' . time() . '.' . $ext;
            $dest = __DIR__ . '/uploads/' . $newName;
            if (move_uploaded_file($tmpName, $dest)) {
                $agencyLogo = 'uploads/' . $newName;
            }
        }
    }

    // Set branding cookies for persistence
    setcookie('pitch_agency_name', $agencyName, time() + 31536000, '/');
    setcookie('pitch_agency_logo', $agencyLogo, time() + 31536000, '/');
    setcookie('pitch_agency_email', $agencyEmail, time() + 31536000, '/');
    setcookie('pitch_agency_phone', $agencyPhone, time() + 31536000, '/');
    setcookie('pitch_agency_cta', $agencyCta, time() + 31536000, '/');

    if (!empty($url) && !empty($keyword)) {
        // Ensure protocol exists
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "https://" . $url;
        }

        // Run On-Page analysis
        $auditResult = analyzeWebsitePitch($url, $keyword);

        if ($auditResult && !isset($auditResult['error'])) {
            // Run PageSpeed Insight check
            $pagespeedScore = getPageSpeedScoreLive($url);
            
            // Build Prompt for AI Growth blueprint
            $prompt = "As an expert B2B SEO agency, write exactly 3 high-impact, bullet-point growth recommendations for the website '{$url}' targeting the keyword '{$keyword}'. Keep each point concise, practical, and action-oriented. Do not use markdown backticks, raw HTML tags, or formatting codes. Keep it plain text with 1., 2., 3. style.";
            
            if (hasChatGPT()) {
                $aiAdvice = generateWithOpenAI($prompt, OPENAI_API_KEY);
            } elseif (hasGemini()) {
                $aiAdvice = generateWithGemini($prompt, GEMINI_API_KEY);
            }

            if (empty($aiAdvice)) {
                // Fallback B2B template
                $aiAdvice = "1. Content Gap Optimization: Create dedicated landing pages focusing on related keywords like '" . htmlspecialchars($keyword) . " near me' to capture high-intent local traffic.\n"
                          . "2. Authority Link Building: Focus on acquiring high-authority backlinks from niche-relevant blogs and local business citation sites to boost Domain Authority.\n"
                          . "3. User Experience & Speed: Optimize image sizing and leverage browser caching to improve core web vitals and overall search ranking performance.";
            }
        }
    }
}

// core scraping & analysis function
function analyzeWebsitePitch($url, $keyword) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $httpCode !== 200) {
        return ['error' => 'Could not fetch website (HTTP ' . $httpCode . ')', 'score' => 0, 'issues' => []];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $issues = [];
    $score = 100;

    // 1. Title Tag
    $titles = $xpath->query('//title');
    $titleText = $titles->length > 0 ? trim($titles->item(0)->textContent) : '';
    if (empty($titleText)) {
        $issues[] = [
            'element' => 'Title Tag',
            'status' => 'Missing Title Tag',
            'severity' => 'critical',
            'impact' => 'Search engines cannot identify what this page is about. High impact on ranking.',
            'fix' => 'Add a Title tag immediately in the head section.',
            'code' => '<title>' . htmlspecialchars(ucwords($keyword)) . ' | [Brand Name]</title>'
        ];
        $score -= 20;
    } else {
        $titleKw = stripos($titleText, $keyword) !== false;
        $titleLen = strlen($titleText);
        if (!$titleKw) {
            $issues[] = [
                'element' => 'Title Tag',
                'status' => 'Keyword Absent in Title',
                'severity' => 'high',
                'impact' => 'The target keyword "' . htmlspecialchars($keyword) . '" is missing in the title. Leads to poor query matching.',
                'fix' => 'Insert your target keyword naturally at the start of your Title tag.',
                'code' => '<title>' . htmlspecialchars(ucwords($keyword)) . ' - ' . htmlspecialchars($titleText) . '</title>'
            ];
            $score -= 15;
        }
        if ($titleLen < 30 || $titleLen > 60) {
            $issues[] = [
                'element' => 'Title Tag',
                'status' => 'Title Length Suboptimal (' . $titleLen . ' chars)',
                'severity' => 'medium',
                'impact' => 'Title is too short or too long. Ideally, it should be 30-60 characters to fit search results.',
                'fix' => 'Optimize title length to stay between 30 and 60 characters.',
                'code' => '<title>' . htmlspecialchars(substr($titleText, 0, 50)) . '...</title>'
            ];
            $score -= 5;
        }
    }

    // 2. Meta Description
    $metas = $xpath->query('//meta[@name="description"]/@content');
    $metaDesc = $metas->length > 0 ? trim($metas->item(0)->textContent) : '';
    if (empty($metaDesc)) {
        $issues[] = [
            'element' => 'Meta Description',
            'status' => 'Missing Meta Description',
            'severity' => 'critical',
            'impact' => 'Google will auto-generate descriptions, leading to poor click-through rates (CTR).',
            'fix' => 'Add a compelling meta description tag.',
            'code' => '<meta name="description" content="Discover professional ' . htmlspecialchars($keyword) . ' services. Click here to learn more!">'
        ];
        $score -= 15;
    } else {
        $descKw = stripos($metaDesc, $keyword) !== false;
        $descLen = strlen($metaDesc);
        if (!$descKw) {
            $issues[] = [
                'element' => 'Meta Description',
                'status' => 'Keyword Absent in Description',
                'severity' => 'high',
                'impact' => 'Keyword missing in meta description, which fails to highlight search terms to users.',
                'fix' => 'Include target keyword "' . htmlspecialchars($keyword) . '" within the description text.',
                'code' => '<meta name="description" content="' . htmlspecialchars(ucfirst($keyword)) . ': ' . htmlspecialchars(substr($metaDesc, 0, 120)) . '...">'
            ];
            $score -= 10;
        }
        if ($descLen < 120 || $descLen > 160) {
            $issues[] = [
                'element' => 'Meta Description',
                'status' => 'Suboptimal Description Length (' . $descLen . ' chars)',
                'severity' => 'medium',
                'impact' => 'Ideally, meta description should be 120-160 characters to prevent clipping in SERPs.',
                'fix' => 'Shorten or expand description to 120-160 characters.',
                'code' => '<meta name="description" content="' . htmlspecialchars(substr($metaDesc, 0, 150)) . '...">'
            ];
            $score -= 5;
        }
    }

    // 3. H1 Tag
    $h1s = $xpath->query('//h1');
    if ($h1s->length === 0) {
        $issues[] = [
            'element' => 'H1 Header',
            'status' => 'Missing H1 Heading',
            'severity' => 'critical',
            'impact' => 'No primary header on page. Hard for search bots to determine main topic.',
            'fix' => 'Add a single `<h1>` tag at the top of page body.',
            'code' => '<h1>' . htmlspecialchars(ucwords($keyword)) . '</h1>'
        ];
        $score -= 15;
    } elseif ($h1s->length > 1) {
        $issues[] = [
            'element' => 'H1 Header',
            'status' => 'Multiple H1 Tags (' . $h1s->length . ' found)',
            'severity' => 'medium',
            'impact' => 'Multiple H1 elements dilute thematic relevance. Confuses search engines.',
            'fix' => 'Consolidate headings to a single `<h1>` tag, convert others to `<h2>` or `<h3>`.',
            'code' => '<!-- Keep only one H1 -->'
        ];
        $score -= 5;
    } else {
        $h1Text = trim($h1s->item(0)->textContent);
        if (stripos($h1Text, $keyword) === false) {
            $issues[] = [
                'element' => 'H1 Header',
                'status' => 'Keyword Absent in H1',
                'severity' => 'high',
                'impact' => 'The target keyword is missing from the primary H1 heading.',
                'fix' => 'Rewrite the H1 tag to include "' . htmlspecialchars($keyword) . '" naturally.',
                'code' => '<h1>' . htmlspecialchars(ucwords($keyword)) . ' - [Your Subject]</h1>'
            ];
            $score -= 10;
        }
    }

    // 4. H2 Tags
    $h2s = $xpath->query('//h2');
    if ($h2s->length === 0) {
        $issues[] = [
            'element' => 'H2 Headers',
            'status' => 'No H2 Headers Found',
            'severity' => 'low',
            'impact' => 'Lacks structural subheadings, reducing readability and secondary keyword placements.',
            'fix' => 'Break content sections with descriptive `<h2>` subheadings.',
            'code' => '<h2>Key Benefits of ' . htmlspecialchars(ucwords($keyword)) . '</h2>'
        ];
        $score -= 5;
    }

    // 5. Images Alt Check
    $images = $xpath->query('//img');
    $noAlt = 0;
    foreach ($images as $img) {
        $alt = $img->getAttribute('alt');
        if (empty(trim($alt))) {
            $noAlt++;
        }
    }
    if ($noAlt > 0) {
        $issues[] = [
            'element' => 'Image Alt Attributes',
            'status' => $noAlt . ' Image(s) Missing Alt Text',
            'severity' => 'medium',
            'impact' => 'Search engines cannot read images without alt descriptions, missing image search traffic.',
            'fix' => 'Add descriptive `alt` tags to all image elements.',
            'code' => '<img src="..." alt="' . htmlspecialchars($keyword) . ' description">'
        ];
        $score -= min(10, $noAlt * 2);
    }

    // 6. Keyword Density
    $bodyText = strtolower(strip_tags($html));
    $words = preg_split('/\s+/', $bodyText);
    $wordCount = count(array_filter($words));
    $kwCount = substr_count($bodyText, strtolower($keyword));
    $density = $wordCount > 0 ? round(($kwCount / $wordCount) * 100, 2) : 0;
    if ($density < 0.5) {
        $issues[] = [
            'element' => 'Keyword Density',
            'status' => 'Low Keyword Density (' . $density . '%)',
            'severity' => 'medium',
            'impact' => 'The target keyword is used too rarely. Page may not be seen as highly relevant.',
            'fix' => 'Naturally integrate the keyword and its synonyms into body paragraphs, lists, and headings.',
            'code' => 'Aim for 1% - 1.5% density.'
        ];
        $score -= 10;
    } elseif ($density > 3.0) {
        $issues[] = [
            'element' => 'Keyword Density',
            'status' => 'High Keyword Density (' . $density . '%)',
            'severity' => 'high',
            'impact' => 'Keyword stuffing detected. Can trigger search engine spam filters and penalties.',
            'fix' => 'Rewrite content to reduce keyword frequency and use natural variations instead.',
            'code' => 'Reduce frequency below 2.5%.'
        ];
        $score -= 10;
    }

    $score = max(0, $score);
    return [
        'score' => $score,
        'issues' => $issues,
        'meta' => [
            'title' => $titleText,
            'description' => $metaDesc,
            'word_count' => $wordCount,
            'keyword_count' => $kwCount,
            'density' => $density,
            'h1_count' => $h1s->length,
            'h2_count' => $h2s->length
        ]
    ];
}

function getPageSpeedScoreLive($url) {
    $apiKey = defined('GOOGLE_API_KEY') ? GOOGLE_API_KEY : '';
    $apiUrl = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=" . urlencode($url) . "&category=performance&strategy=mobile";
    if (!empty($apiKey)) {
        $apiUrl .= "&key=" . urlencode($apiKey);
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90, // Increase timeout to 90s for slow sites
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    $score = null;
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
    }
    
    // Log the API call result for debugging
    $logMsg = date('Y-m-d H:i:s') . " | URL: $url | Key: " . (empty($apiKey) ? "NO" : "YES") . " | HTTP Code: $httpCode";
    if ($curlErr) {
        $logMsg .= " | cURL Error: $curlErr";
    }
    if ($score !== null) {
        $logMsg .= " | Score: " . round($score * 100);
    } else {
        $logMsg .= " | Score: NULL";
        if ($httpCode !== 200) {
            $logMsg .= " | Resp: " . substr($response, 0, 150);
        }
    }
    @file_put_contents(dirname(__FILE__) . '/logs/pagespeed_debug.log', $logMsg . "\n", FILE_APPEND);
    
    if ($score !== null) {
        return round($score * 100);
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Pitch PDF Report Generator - SEO 80/20</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    
    <style>
        .progress-ring__circle {
            transition: stroke-dashoffset 0.4s;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }
        #loadingOverlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            z-index: 9999;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .spinner-growth-large {
            width: 4rem;
            height: 4rem;
            color: var(--primary);
        }
        .score-circle {
            position: relative;
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .score-number {
            position: absolute;
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
        }
        .pagespeed-badge {
            font-size: 13px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 20px;
        }
        .ai-blueprint {
            background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%);
            border-left: 5px solid var(--primary);
            border-radius: 16px;
        }
        .fix-code-block {
            background: #0f172a;
            color: #38bdf8;
            font-family: monospace;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-top: 6px;
            word-break: break-all;
        }
        
        /* Print layout adjustments */
        .print-header-layout {
            display: none;
        }

        @media print {
            @page {
                size: auto;
                margin: 0mm; /* Hides default browser print header (date/title) and footer (URL) */
            }
            body {
                background: #fff !important;
                color: #000 !important;
                font-size: 11pt;
                margin: 1.6cm !important; /* Re-applies safe print margins to the content */
            }
            .navbar, .no-print, .btn, form, .accordion, #loadingOverlay, hr.no-print {
                display: none !important;
            }
            .container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
                padding: 0 !important;
                margin-bottom: 20px !important;
            }
            .print-header-layout {
                display: flex !important;
                justify-content: space-between;
                align-items: center;
                border-bottom: 3px solid #4f46e5;
                padding-bottom: 12px;
                margin-bottom: 30px;
            }
            .page-break {
                page-break-before: always;
            }
            .keep-together {
                page-break-inside: avoid;
            }
            .table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            .table th, .table td {
                border: 1px solid #cbd5e1 !important;
                padding: 8px !important;
            }
            .fix-code-block {
                background: #f1f5f9 !important;
                color: #0f172a !important;
                border: 1px solid #cbd5e1 !important;
            }
        }
    </style>
</head>
<body>
    
    <?php include 'includes/navbar.php'; ?>

    <div id="loadingOverlay">
        <div class="spinner-grow spinner-growth-large mb-4" role="status"></div>
        <h4 class="fw-bold mb-2">Analyzing Client Site</h4>
        <p id="loadingStepText" class="text-white-50">🔌 Establishing connection and fetching HTML...</p>
    </div>

    <div class="container my-5">
        
        <!-- Screen Header -->
        <div class="row no-print mb-4">
            <div class="col-md-8">
                <h1 class="fw-extrabold text-dark"><i class="fas fa-file-invoice me-2 text-primary"></i>Client Pitch PDF Report Generator</h1>
                <p class="text-muted">Enter any prospect website URL to run a live SEO checkup. White-label the report with your own agency logo & contact details, then click Print to save it as a PDF proposal.</p>
            </div>
        </div>

        <!-- Audit Form Card -->
        <div class="card shadow-sm border-0 p-4 mb-4 no-print" style="border-radius: 20px;">
            <form id="auditForm" method="POST" action="" enctype="multipart/form-data">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Client Website URL</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-link"></i></span>
                            <input type="text" name="client_url" class="form-control" placeholder="example.com" value="<?= htmlspecialchars($url) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Target Search Keyword</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" name="client_keyword" class="form-control" placeholder="Austin dentist" value="<?= htmlspecialchars($keyword) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Client / Business Name</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-briefcase"></i></span>
                            <input type="text" name="client_name" class="form-control" placeholder="Dr. John Smile Clinic" value="<?= htmlspecialchars($clientName) ?>" required>
                        </div>
                    </div>
                </div>

                <!-- Accordion: Agency white label config -->
                <div class="accordion mt-3" id="brandingAccordion">
                    <div class="accordion-item border-0 bg-light rounded-3">
                        <h2 class="accordion-header" id="brandingHeading">
                            <button class="accordion-button collapsed bg-transparent fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#brandingCollapse" aria-expanded="false" aria-controls="brandingCollapse">
                                <i class="fas fa-sliders-h me-2 text-primary"></i> Agency White-Label Branding Settings
                            </button>
                        </h2>
                        <div id="brandingCollapse" class="accordion-collapse collapse" aria-labelledby="brandingHeading" data-bs-parent="#brandingAccordion">
                            <div class="accordion-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Your Agency Name</label>
                                        <input type="text" name="agency_name" class="form-control" value="<?= htmlspecialchars($agencyName) ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Agency Logo (Upload File)</label>
                                        <input type="file" name="agency_logo_file" class="form-control" accept="image/*">
                                        <input type="hidden" name="agency_logo" value="<?= htmlspecialchars($agencyLogo) ?>">
                                        <?php if (!empty($agencyLogo)): ?>
                                            <div class="form-text text-success mt-1">
                                                <i class="fas fa-check-circle"></i> Current Logo: <code><?= htmlspecialchars(basename($agencyLogo)) ?></code>
                                            </div>
                                        <?php else: ?>
                                            <div class="form-text">Choose a local image file to upload.</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Contact Email</label>
                                        <input type="email" name="agency_email" class="form-control" placeholder="hello@youragency.com" value="<?= htmlspecialchars($agencyEmail) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Contact Phone / Whatsapp</label>
                                        <input type="text" name="agency_phone" class="form-control" placeholder="+91 99988 88877" value="<?= htmlspecialchars($agencyPhone) ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold">Call To Action (CTA) Text in Footer</label>
                                        <textarea name="agency_cta" class="form-control" rows="2" required><?= htmlspecialchars($agencyCta) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <button type="submit" name="run_audit" class="btn btn-primary px-5 py-3 rounded-pill fw-bold">
                        <i class="fas fa-cog fa-spin me-2"></i>Generate SEO Audit Report
                    </button>
                </div>
            </form>
        </div>

        <?php if ($auditResult): ?>
            <?php if (isset($auditResult['error'])): ?>
                <div class="alert alert-danger shadow-sm border-0 no-print" style="border-radius: 12px;">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($auditResult['error']) ?>
                </div>
            <?php else: ?>
                
                <!-- PRINT HEADER ONLY -->
                <div class="print-header-layout">
                    <div class="d-flex align-items-center">
                        <?php if (!empty($agencyLogo)): ?>
                            <?php 
                            $logoSrc = $agencyLogo;
                            if (strpos($logoSrc, 'http') !== 0) {
                                $logoSrc = SITE_URL . '/' . $logoSrc;
                            }
                            ?>
                            <img src="<?= htmlspecialchars($logoSrc) ?>" alt="Agency Logo" style="max-height: 55px; margin-right: 15px; margin-bottom: 0;">
                        <?php endif; ?>
                        <div>
                            <h3 class="fw-bold m-0 text-dark"><?= htmlspecialchars($agencyName) ?></h3>
                            <small class="text-muted d-block"><?= htmlspecialchars($agencyEmail) ?> <?php if(!empty($agencyPhone)) echo " | " . htmlspecialchars($agencyPhone); ?></small>
                        </div>
                    </div>
                    <div class="text-end">
                        <h4 class="fw-bold m-0 text-dark">SEO Performance Pitch Audit</h4>
                        <span class="badge bg-light text-dark border mt-1">Prepared For: <?= htmlspecialchars($clientName) ?></span>
                        <div class="small text-muted mt-1">Date: <?= date('M d, Y') ?></div>
                    </div>
                </div>

                <!-- REPORT SECTION START -->
                <div class="row align-items-stretch g-4 mb-4">
                    
                    <!-- Circular Score Card -->
                    <div class="col-md-4">
                        <div class="card shadow-sm border-0 p-4 text-center h-100 d-flex flex-column align-items-center justify-content-center" style="border-radius: 20px;">
                            <h6 class="text-uppercase fw-bold text-muted mb-3">Overall SEO Health Score</h6>
                            
                            <div class="score-circle mb-3">
                                <svg width="120" height="120">
                                    <circle stroke="#f1f5f9" stroke-width="12" fill="transparent" r="50" cx="60" cy="60"/>
                                    <circle class="progress-ring__circle" stroke="<?php 
                                        if ($auditResult['score'] >= 85) echo '#10b981';
                                        elseif ($auditResult['score'] >= 60) echo '#f59e0b';
                                        else echo '#ef4444';
                                    ?>" stroke-width="12" fill="transparent" r="50" cx="60" cy="60"/>
                                </svg>
                                <span class="score-number" id="healthScoreValue"><?= $auditResult['score'] ?></span>
                            </div>

                            <span class="badge bg-opacity-10 py-2 px-3 rounded-pill <?php
                                if ($auditResult['score'] >= 85) echo 'bg-success text-success';
                                elseif ($auditResult['score'] >= 60) echo 'bg-warning text-warning';
                                else echo 'bg-danger text-danger';
                            ?>">
                                <?php
                                    if ($auditResult['score'] >= 85) echo 'Excellent Optimization';
                                    elseif ($auditResult['score'] >= 60) echo 'Fair (Needs Attention)';
                                    else echo 'Critical SEO Issues';
                                ?>
                            </span>
                        </div>
                    </div>

                    <!-- Details Card -->
                    <div class="col-md-8">
                        <div class="card shadow-sm border-0 p-4 h-100" style="border-radius: 20px;">
                            <h5 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-info-circle text-primary me-2"></i>Analysis Parameters</h5>
                            
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="small text-muted">Audited Domain</div>
                                    <div class="fw-bold text-truncate"><a href="<?= htmlspecialchars($url) ?>" target="_blank"><?= htmlspecialchars($url) ?></a></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-muted">Target Keyword Focus</div>
                                    <div class="fw-bold text-primary">"<?= htmlspecialchars($keyword) ?>"</div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-muted">Lead Name</div>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($clientName) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-muted">Google PageSpeed (Mobile)</div>
                                    <div class="d-flex align-items-center mt-1">
                                        <?php if ($pagespeedScore === null): ?>
                                            <span class="badge bg-secondary">Unchecked</span>
                                        <?php else: ?>
                                            <span class="badge <?php
                                                if ($pagespeedScore >= 90) echo 'bg-success';
                                                elseif ($pagespeedScore >= 50) echo 'bg-warning text-dark';
                                                else echo 'bg-danger';
                                            ?> pagespeed-badge">
                                                <i class="fas fa-bolt me-1"></i> <?= $pagespeedScore ?> / 100
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-muted">Word Count / Density</div>
                                    <div class="fw-bold"><?= $auditResult['meta']['word_count'] ?> words | <?= $auditResult['meta']['density'] ?>% density</div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-muted">Headings Structure</div>
                                    <div class="fw-bold">H1: <?= $auditResult['meta']['h1_count'] ?> | H2: <?= $auditResult['meta']['h2_count'] ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI Recommendations Box -->
                <div class="card shadow-sm border-0 p-4 mb-4 ai-blueprint" style="border-radius: 20px;">
                    <h5 class="fw-bold text-primary mb-3"><i class="fas fa-brain me-2"></i>AI-Powered SEO Optimization Plan</h5>
                    <div style="white-space: pre-wrap; font-size: 15px; line-height: 1.6;" class="text-dark fw-medium"><?= htmlspecialchars($aiAdvice) ?></div>
                </div>

                <!-- Table Header in screen -->
                <h5 class="fw-bold text-dark mb-3 no-print"><i class="fas fa-list text-primary me-2"></i>SEO Checkpoints & On-Page Audit</h5>

                <!-- Audit Checks Table -->
                <div class="card shadow-sm border-0 overflow-hidden mb-4" style="border-radius: 20px;">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle m-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 20%;">SEO Element</th>
                                    <th style="width: 25%;">Detected Status</th>
                                    <th style="width: 15%;" class="text-center">Severity</th>
                                    <th style="width: 40%;">How to Fix / Proposed Code</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($auditResult['issues'])): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-success fw-bold">
                                            <i class="fas fa-check-circle fa-2x mb-2 d-block"></i> No SEO Issues Found! Website is perfectly optimized.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($auditResult['issues'] as $issue): ?>
                                        <tr class="keep-together">
                                            <td>
                                                <span class="fw-bold text-dark"><?= htmlspecialchars($issue['element']) ?></span>
                                            </td>
                                            <td>
                                                <span class="text-danger fw-semibold d-block"><i class="fas fa-times-circle me-1"></i> <?= htmlspecialchars($issue['status']) ?></span>
                                                <small class="text-muted d-block mt-1"><?= htmlspecialchars($issue['impact']) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge py-1.5 px-3 rounded-pill <?php
                                                    if ($issue['severity'] === 'critical') echo 'bg-danger';
                                                    elseif ($issue['severity'] === 'high') echo 'bg-warning text-dark';
                                                    elseif ($issue['severity'] === 'medium') echo 'bg-info text-dark';
                                                    else echo 'bg-secondary';
                                                ?>"><?= ucfirst($issue['severity']) ?></span>
                                            </td>
                                            <td>
                                                <div class="small fw-semibold"><?= htmlspecialchars($issue['fix']) ?></div>
                                                <?php if (!empty($issue['code'])): ?>
                                                    <div class="fix-code-block"><?= htmlspecialchars($issue['code']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Page Break CTA Box -->
                <div class="card border-0 p-4 text-center mt-5" style="border-radius: 20px; background: #e0e7ff;">
                    <h5 class="fw-bold text-primary mb-2"><i class="fas fa-rocket me-2"></i>Ready to Scale Your Organic Traffic?</h5>
                    <p class="text-dark-50 fw-semibold m-0" style="font-size: 15px;"><?= htmlspecialchars($agencyCta) ?></p>
                    <div class="mt-3 font-semibold text-dark">
                        📍 Contact: <span class="text-primary"><?= htmlspecialchars($agencyEmail) ?></span> <?php if(!empty($agencyPhone)) echo " | 📞 " . htmlspecialchars($agencyPhone); ?>
                    </div>
                </div>

                <!-- Print Control Banner -->
                <div class="d-flex justify-content-between align-items-center mt-4 no-print">
                    <button type="button" onclick="window.scrollTo({top: 0, behavior: 'smooth'});" class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="fas fa-arrow-up me-1"></i> Back to Form
                    </button>
                    <button type="button" onclick="window.print();" class="btn btn-success px-5 py-3 rounded-pill fw-bold shadow">
                        <i class="fas fa-file-pdf me-2"></i>Download PDF Report
                    </button>
                </div>

            <?php endif; ?>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('auditForm').addEventListener('submit', function() {
            document.getElementById('loadingOverlay').style.display = 'flex';
            
            // Cycle text steps
            var steps = [
                "🔌 Fetching target website content...",
                "📝 Parsing title metadata and tags...",
                "🎯 Checking keyword match ratios...",
                "🚀 Querying Google PageSpeed score...",
                "🧠 Synthesizing AI SEO blueprints..."
            ];
            var idx = 0;
            var el = document.getElementById('loadingStepText');
            setInterval(function() {
                if (idx < steps.length) {
                    el.innerText = steps[idx++];
                }
            }, 3000);
        });

        // Initialize radial circular scores
        window.addEventListener('DOMContentLoaded', () => {
            const circle = document.querySelector('.progress-ring__circle');
            const scoreEl = document.getElementById('healthScoreValue');
            if (circle && scoreEl) {
                const radius = circle.r.baseVal.value;
                const circumference = radius * 2 * Math.PI;
                
                circle.style.strokeDasharray = `${circumference} ${circumference}`;
                circle.style.strokeDashoffset = circumference;
                
                const score = parseInt(scoreEl.innerText);
                const offset = circumference - (score / 100) * circumference;
                
                // Animate offset
                setTimeout(() => {
                    circle.style.strokeDashoffset = offset;
                }, 200);
            }
        });
    </script>
</body>
</html>
