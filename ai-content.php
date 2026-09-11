<?php
require_once 'config.php';

// ============================================================
// AI Content — ChatGPT (OpenAI) primary, Gemini optional backup
// ============================================================

/**
 * Generate a completely unique post title via ChatGPT.
 * Passes already-used titles so ChatGPT never repeats them.
 * Falls back to timestamp-salted templates if OpenAI is unavailable.
 */
/**
 * Remove duplicate consecutive words from a keyword.
 * "DevOps Training Training at Kalyan Nagar" → "DevOps Training at Kalyan Nagar"
 */
function cleanKeyword(string $keyword): string {
    $words  = preg_split('/\s+/', trim($keyword));
    $result = [];
    $prev   = '';
    foreach ($words as $word) {
        if (strtolower($word) !== strtolower($prev)) {
            $result[] = $word;
        }
        $prev = $word;
    }
    return implode(' ', $result);
}

function generateUniqueTitle(string $keyword, int $postCount = 1, array $usedTitles = [], ?string $openaiKey = null): string {
    $keyword   = cleanKeyword($keyword); // remove duplicate words
    $openaiKey = $openaiKey ?: OPENAI_API_KEY;
    $kw        = ucwords($keyword);
    $yr        = date('Y');
    $seed      = rand(100000, 999999);

    // Build "do NOT use these" list for ChatGPT
    $avoidBlock = '';
    if (!empty($usedTitles)) {
        $avoidList  = implode("\n", array_map(fn($t) => '- ' . $t, array_slice($usedTitles, -50)));
        $avoidBlock = "\n\nDo NOT use any of these already-used titles:\n{$avoidList}";
    }

    $prompt = "Generate ONE unique, compelling, SEO-optimized blog post title for the keyword: \"{$keyword}\".

Rules:
- Must contain the keyword \"{$keyword}\" naturally — but DO NOT repeat any word that already appears in the keyword itself
- Example: if keyword is \"Python Training\", do NOT add \"Training\" again in the title
- Must be different from every title listed below
- Must be creative — vary the format each time (how-to, listicle, question, guide, story, comparison, etc.)
- No quotes around the title in your response
- Return ONLY the title, nothing else
- Year {$yr} can be included if it fits naturally
- Random seed: {$seed}{$avoidBlock}";

    if (!empty($openaiKey) && strpos($openaiKey, 'sk-') === 0) {
        $model = defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini';
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => $model,
                'messages'    => [
                    ['role' => 'system', 'content' => 'You are an expert SEO copywriter. Generate creative, unique blog post titles that are never repeated.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 1.2,
                'max_tokens'  => 60,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openaiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp     = json_decode(curl_exec($ch), true);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $title = trim($resp['choices'][0]['message']['content'] ?? '');
            // Strip surrounding quotes if ChatGPT added them
            $title = trim($title, '"\'');
            if (!empty($title) && strlen($title) > 10 && strlen($title) < 200) {
                return $title;
            }
        }
    }

    // Try Gemini backup for title generation
    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (!empty($geminiKey)) {
        $geminiTitle = generateWithGemini($prompt, $geminiKey);
        if ($geminiTitle) {
            $title = trim($geminiTitle);
            $title = trim($title, '"\'');
            if (!empty($title) && strlen($title) > 10 && strlen($title) < 200) {
                return $title;
            }
        }
    }

    // Fallback — postCount + seed salted so they never exactly repeat even without AI
    // Detect niche (Real Estate / Property vs General Services / Training)
    $isProperty = false;
    $nicheWords = ['property', 'properties', 'propaty', 'propety', 'flat', 'flats', 'apartment', 'apartments', 'real estate', 'villa', 'villas', 'plot', 'plots', 'house', 'homes', 'home', 'builder', 'developer', 'infra', 'suyug', 'bhk', 'residency', 'land'];
    foreach ($nicheWords as $w) {
        if (stripos($keyword, $w) !== false) {
            $isProperty = true;
            break;
        }
    }

    $month = date('F');
    if ($isProperty) {
        $fallbacks = [
            "Discover {$kw}: Luxury Living & Premium Amenities in {$yr}",
            "Why {$kw} is the Best Real Estate Investment in {$month} {$yr}",
            "{$kw}: The Ultimate Homebuyer's Guide for {$yr}",
            "Top Features of {$kw}: What You Must Know in {$month}",
            "Explore {$kw}: Modern Lifestyle & Premium Spaces — Part {$postCount}",
            "Choosing {$kw}: Expert Tips for Homebuyers in {$yr}",
            "{$kw} Deep Dive: Location, Amenities & Pricing — Part {$postCount}",
            "Your {$month} {$yr} Guide to Buying {$kw}",
            "{$kw} Trends: What to Expect in {$yr} — Edition #{$postCount}",
            "Experience Luxury: {$kw} Lifestyle Tour for {$yr}",
        ];
    } else {
        $fallbacks = [
            "{$kw}: The Complete Guide for {$yr} — Updated {$month}",
            "Why {$kw} Is the #{$postCount} Skill to Learn This {$month}",
            "{$kw} Mastery — Edition #{$postCount} ({$yr})",
            "How to Excel at {$kw}: Insights for {$month} {$yr}",
            "The {$postCount}-Step {$kw} Blueprint for {$yr}",
            "Unlock {$kw}: Expert Knowledge for {$yr} — Part {$postCount}",
            "{$kw} Deep Dive #{$postCount}: Everything You Must Know in {$yr}",
            "Your {$month} {$yr} Guide to {$kw} — Version {$postCount}",
            "Getting Started with {$kw}: A {$yr} Roadmap — #{$postCount}",
            "{$kw} in {$yr}: Trends, Tips and Career Paths — Part {$postCount}",
        ];
    }
    $raw = $fallbacks[$seed % count($fallbacks)];

    // Remove duplicate words that already appear in the keyword (e.g. "Training Training")
    $kwWords = array_map('strtolower', preg_split('/\s+/', trim($keyword)));
    $titleWords = preg_split('/(\s+)/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
    $seen = [];
    $out  = [];
    foreach ($titleWords as $tok) {
        $lower = strtolower(trim($tok));
        if ($lower === '' || !in_array($lower, $kwWords)) {
            $out[] = $tok;
        } elseif (!isset($seen[$lower])) {
            $seen[$lower] = true;
            $out[] = $tok; // keep first occurrence
        }
        // skip duplicate kw words
    }
    return trim(implode('', $out));
}

if (!function_exists('generateWithOpenAI')) {
    function generateWithOpenAI(string $prompt, ?string $apiKey = null): ?string {
        $apiKey = $apiKey ?? OPENAI_API_KEY;
        if (empty($apiKey) || strpos($apiKey, 'your-') === 0 || strpos($apiKey, 'sk-') !== 0) {
            return null;
        }

        $model = defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini';

        // Add random seed to ensure unique content every time
        $randomSeed = rand(100000, 999999);
        $uniquePrompt = $prompt . "\n\nGenerate completely unique content. Random seed: {$randomSeed}. Current timestamp: " . time() . ". Do not repeat any previous content.";

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => $model,
                'messages'    => [
                    ['role' => 'system', 'content' => 'You are an expert SEO content writer. Write unique, engaging content every time. Never repeat the same content twice. Use HTML when asked. Each request must produce completely different content.'],
                    ['role' => 'user',   'content' => $uniquePrompt],
                ],
                'temperature' => 1.0, // Maximum randomness
                'top_p' => 0.9,
                'max_tokens'  => 4000,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = json_decode(curl_exec($ch), true);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        return $response['choices'][0]['message']['content'] ?? null;
    }
}

if (!function_exists('generateWithGemini')) {
    function generateWithGemini(string $prompt, ?string $apiKey = null): ?string {
        $apiKey = $apiKey ?? GEMINI_API_KEY;
        if (empty($apiKey) || strpos($apiKey, 'your-') === 0) {
            return null;
        }

        $maxRetries = 3;
        $retryDelay = 2; // seconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init("https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $apiKey);
            $payload = json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]]
            ]);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $json = json_decode($res, true);
                return $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
            }

            if ($httpCode === 429 && $attempt < $maxRetries) {
                sleep($retryDelay * $attempt);
                continue;
            }
            break;
        }
        return null;
    }
}

/** Primary AI: ChatGPT only, returns [text, source] */
function generateWithAI(string $prompt): array {
    $content = generateWithOpenAI($prompt);
    if ($content) {
        return ['text' => $content, 'source' => 'ChatGPT'];
    }
    $content = generateWithGemini($prompt);
    if ($content) {
        return ['text' => $content, 'source' => 'Gemini'];
    }
    return ['text' => null, 'source' => 'Template'];
}

function generateAIContent($keyword, $targetSite, $platform, $contentType, $geminiKey = '', $openaiKey = '', $postCount = 1, array $usedTitles = [], string $businessName = '', string $businessDesc = ''): array {
    $keyword   = cleanKeyword($keyword); // remove duplicate consecutive words

    $openaiKey = $openaiKey ?: OPENAI_API_KEY;
    $geminiKey = $geminiKey ?: GEMINI_API_KEY;

    $businessCtx = '';
    if (!empty($businessName)) {
        $businessCtx .= " Client Business Name: {$businessName}.";
    }
    if (!empty($businessDesc)) {
        $businessCtx .= " Client Niche & Features: {$businessDesc}.";
    }
    if (!empty($businessCtx)) {
        $businessCtx = "\n- Business/Niche context: " . $businessCtx . " Ensure the content naturally integrates this context and promotes this client's unique features, location, and services where relevant.";
    }

    // Add random seed to prompts for unique content every time
    $randomSeed = rand(100000, 999999);
    $timestamp  = time();

    // Detect if the project/niche is about education/training/courses
    $isEducation = false;
    $checkStr = strtolower($keyword . ' ' . $businessName . ' ' . $businessDesc);
    $eduWords = ['training', 'course', 'learn', 'academy', 'institute', 'class', 'student', 'tutorial', 'career', 'education', 'java', 'programming', 'developer', 'syllabus'];
    foreach ($eduWords as $w) {
        if (strpos($checkStr, $w) !== false) {
            $isEducation = true;
            break;
        }
    }

    // Content variation angles — rotate based on post count so each post is different
    if ($isEducation) {
        $variationAngles = [
            1 => "Focus angle: Explain what {$keyword} is and why it matters. Target: complete beginners who just heard about it.",
            2 => "Focus angle: Career benefits and salary potential after learning {$keyword}. Target: job seekers and career switchers.",
            3 => "Focus angle: Practical step-by-step 'how to get started' guide for {$keyword}. Target: motivated learners ready to begin.",
            4 => "Focus angle: Common mistakes beginners make learning {$keyword} and how to avoid them. Target: people who tried before and failed.",
            5 => "Focus angle: Compare {$keyword} with similar alternatives — why choose this skill over others. Target: analytical decision-makers.",
            6 => "Focus angle: Real success stories and case studies from people who learned {$keyword}. Target: skeptical readers needing social proof.",
            7 => "Focus angle: Future trends — how {$keyword} will evolve in the next 3 years and why now is the best time to learn. Target: forward-thinking learners.",
            8 => "Focus angle: Tools, resources, and software used by {$keyword} professionals daily. Target: hands-on technical learners.",
            9 => "Focus angle: Beginner FAQs — answer the 5 most common questions people ask about {$keyword}.",
            10 => "Focus angle: Day-in-the-life of a {$keyword} professional — what they actually do at work.",
        ];
    } else {
        $variationAngles = [
            1 => "Focus angle: Why {$keyword} is a top choice for customers looking for quality and reliability. Target: home buyers, investors, or general customers.",
            2 => "Focus angle: The ultimate buyer's guide and key factors to consider when choosing {$keyword}. Target: serious buyers ready to make a decision.",
            3 => "Focus angle: Top 5 benefits and advantages of investing in {$keyword}. Target: investors and long-term planners.",
            4 => "Focus angle: Common mistakes to avoid when choosing or purchasing {$keyword}. Target: first-time buyers looking to save money and avoid scams.",
            5 => "Focus angle: Comparing {$keyword} options or locations — what makes it the best choice. Target: analytical buyers looking for the best deal.",
            6 => "Focus angle: Real customer reviews, testimonials, and case studies about {$keyword}. Target: skeptical readers looking for social proof.",
            7 => "Focus angle: Future growth and market trends for {$keyword} — why now is the perfect time to invest. Target: forward-thinking buyers.",
            8 => "Focus angle: Step-by-step process of buying, getting started, or renting {$keyword}. Target: clients ready to take action.",
            9 => "Focus angle: Frequently Asked Questions — answer the top 5 questions customers ask about {$keyword}.",
            10 => "Focus angle: Inside look at {$keyword} — key highlights, features, and unique selling points.",
        ];
    }
    $angleKey  = (($postCount - 1) % count($variationAngles)) + 1;
    $angleHint = $variationAngles[$angleKey];

    // ── Generate a UNIQUE title via ChatGPT (unlimited, never repeats) ──────────
    $generatedTitle = generateUniqueTitle($keyword, $postCount, $usedTitles, $openaiKey);

    // Generate diverse anchor texts for Anchor Text Diversity
    $anchors = getDiverseAnchorTexts($keyword, $businessName, $targetSite);
    $introAnchor = $anchors['intro'];
    $midAnchor = $anchors['mid'];
    $outroAnchor = $anchors['outro'];

    if ($platform === 'pinterest') {
        $titlePrompt = "Generate ONE unique, compelling, SEO-optimized Pinterest Pin Title for the keyword: \"{$keyword}\".\nRules:\n- Must contain the keyword \"{$keyword}\"\n- Length must be strictly between 40 and 60 characters\n- Return ONLY the title, no quotes, nothing else.";
        $pinterestTitle = null;
        if (!empty($openaiKey) && strpos($openaiKey, 'sk-') === 0) {
            $pinterestTitle = generateWithOpenAI($titlePrompt, $openaiKey);
        }
        if (!$pinterestTitle && !empty($geminiKey)) {
            $pinterestTitle = generateWithGemini($titlePrompt, $geminiKey);
        }
        if ($pinterestTitle) {
            $generatedTitle = trim(trim($pinterestTitle), '"\'');
        } else {
            $generatedTitle = substr($generatedTitle, 0, 55);
        }
    }

    // Platform-specific context to ensure unique content per platform
    $platformContexts = [
        'wordpress'  => "This is for a WordPress.com blog post. Write in a detailed, long-form blog style with proper HTML structure. Audience: blog readers searching Google.",
        'blogger'    => "This is for a Blogger.com post. Write in a personal, story-driven blog style. Use conversational tone. Audience: general internet users.",
        'tumblr'     => "This is for Tumblr. Write in a trendy, youth-oriented style with visual appeal. Keep it punchy and shareable. Audience: young adults.",
        'github'     => "This is for a GitHub README. Write in a technical, developer-focused style using Markdown. Include code examples if relevant. Audience: developers.",
        'bluesky'    => "This is for Bluesky social network. Write a very short, punchy social post (max 300 chars, plain text only, no HTML). Audience: tech-savvy social media users.",
        'minds'      => "This is for Minds.com social platform. Write in a bold, thought-leadership style. Audience: free-speech focused online community.",
        'plurk'      => "This is for Plurk microblogging. Write a short, casual, conversational update (max 210 chars). Audience: Asian social media users.",
        'devto'      => "This is for Dev.to developer community. Write in a technical, tutorial-style with code examples. Audience: software developers and engineers.",
        'medium'     => "This is for Medium.com. Write in a thoughtful, narrative-driven style (500-700 words). Audience: educated professionals and entrepreneurs.",
        'reddit'     => "This is for Reddit. Write in a casual, community-driven style. Start with a hook. Audience: Reddit community members (varies by subreddit).",
        'pinterest'  => "This is for Pinterest. Write SEO-rich descriptions focused on visual content. Include keywords naturally. Audience: visual content seekers.",
        'mastodon'   => "This is for Mastodon (fediverse). Write in an open, community-friendly style. Short (max 500 chars). Audience: privacy-conscious tech users.",
        'linkedin'   => "This is for LinkedIn. Write in a professional, career-focused style. Highlight ROI and career benefits. Audience: professionals and job seekers.",
        'twitter'    => "This is for Twitter/X. Write in a punchy, viral style (max 280 chars, no HTML). Use hashtags. Audience: general social media users.",
    ];

    $platformCtx = $platformContexts[$platform] ?? "Write unique content for the '{$platform}' platform. Tailor style and length appropriately.";

    $prompts = [
        'blog_post' => "Write a highly detailed, comprehensive, SEO-optimized long-form article about '{$keyword}'.
{$platformCtx}
{$angleHint}{$businessCtx}
STRICT REQUIREMENTS:
- H1 title must be EXACTLY: \"{$generatedTitle}\"
- Minimum 1200-1500 words — a thorough, extensive resource ranks much better on Google. Use comprehensive paragraphs and details.
- Write this article from a completely fresh perspective. Do NOT reuse paragraph layouts, phrases, or sentence structures from previous posts. Every single sentence must be 100% unique.
- Use the keyword '{$keyword}' naturally at least 6-8 times throughout the body
- Include the following 3 backlink anchors naturally in the text:
  1. Once in the introduction: <a href='{$targetSite}'>{$introAnchor}</a>
  2. Once in the middle of the article: <a href='{$targetSite}'>{$midAnchor}</a>
  3. Once in the conclusion CTA: <a href='{$targetSite}'>{$outroAnchor}</a>
- 5-6 H2 subheadings covering different aspects
- Include a bullet list of at least 6 benefits or key points
- Add a FAQ section (3 questions) near the end with keyword in questions
- Strong call-to-action paragraph at the end mentioning {$targetSite}
- HTML formatting: <h1>, <h2>, <h3>, <p>, <ul>, <li>, <strong>, <a href> tags
- Make content completely unique and genuinely valuable
Platform: {$platform}. Post variation #{$postCount}. Random seed: {$randomSeed}. Timestamp: {$timestamp}. UNIQUE content only.",

        'micro_blog' => "Write a detailed micro-blog post (400-500 words) about '{$keyword}'.
{$platformCtx}
{$angleHint}{$businessCtx}
REQUIREMENTS:
- Title/heading must be: \"{$generatedTitle}\"
- Use keyword '{$keyword}' at least 3-4 times naturally
- Write with 100% unique phrasing. Never repeat sentence structures or paragraph flows from previous posts.
- Include the following 2 backlink anchors naturally:
  1. Once in the middle of the post: <a href='{$targetSite}'>{$midAnchor}</a>
  2. Once at the end CTA: <a href='{$targetSite}'>{$outroAnchor}</a>
- Start with an attention-grabbing opening line
- Include 4-5 key benefits as bullet points
- Add one real-world example or use case
- End with strong CTA linking to {$targetSite}
- Use HTML: <p>, <strong>, <ul>, <li>, <a href> tags
Platform: {$platform}. Post variation #{$postCount}. Random seed: {$randomSeed}. Timestamp: {$timestamp}. UNIQUE content only.",

        'profile_bio' => "Write a professional profile bio (150-200 words) for an expert in '{$keyword}'.
{$platformCtx}{$businessCtx}
- Use keyword '{$keyword}' naturally 2-3 times
- Include website URL: {$targetSite} with clickable link
- Mention specific skills, achievements, and expertise areas
- Professional but approachable tone
- End with: 'Visit {$targetSite} to learn more'
Platform: {$platform}. Post variation #{$postCount}. Random seed: {$randomSeed}. Timestamp: {$timestamp}. UNIQUE content only.",

        'image_caption' => "Write a professional, SEO-rich description for a Pinterest Pin about '{$keyword}'.
{$platformCtx}
{$angleHint}{$businessCtx}
STRICT REQUIREMENTS:
- Length: strictly between 150 and 300 characters total (Pinterest allows 500 max, but target 150-300).
- Start with or naturally include the main keyword '{$keyword}'.
- Include website link {$targetSite} naturally.
- Add between 3 and 8 relevant hashtags at the end (e.g. #{$keyword} #LearnAWS).
- Do not repeat previous posts.
Platform: {$platform}. Post variation #{$postCount}. Random seed: {$randomSeed}. Timestamp: {$timestamp}.",

        'bluesky_post' => "Write a unique, engaging social media post for Bluesky about '{$keyword}'.
{$platformCtx}
{$angleHint}{$businessCtx}
STRICT REQUIREMENTS:
- Length: strictly under 280 characters total.
- Keep the writing tone completely natural, informative, and engaging.
- Naturally include the main keyword '{$keyword}' and target link: {$targetSite}
- Add between 3 and 5 relevant hashtags at the end.
- Every post must be completely different and fresh.
Platform: {$platform}. Post variation #{$postCount}. Random seed: {$randomSeed}. Timestamp: {$timestamp}. UNIQUE content only.",

        'pdf_description' => "Write a detailed document/PDF description (300-400 words) about '{$keyword}'.
{$platformCtx}
{$angleHint}{$businessCtx}
REQUIREMENTS:
- Title: \"{$generatedTitle}\"
- Use keyword '{$keyword}' at least 4 times naturally
- Include URL {$targetSite} at least 2 times
- Professional tone with bullet points
- Cover: what it covers, who it's for, key takeaways, benefits
- End with: 'Download resources at {$targetSite}'
- SEO optimized with keyword-rich sentences
Platform: {$platform}. Post variation #{$postCount}. Random seed: {$randomSeed}. Timestamp: {$timestamp}. UNIQUE content only.",

        'article' => "Write a comprehensive, long-form SEO article about '{$keyword}' (900-1200 words).
{$platformCtx}
{$angleHint}{$businessCtx}
STRICT STRUCTURE:
- <h1> Must use EXACTLY: \"{$generatedTitle}\"
- <p> Strong intro paragraph — use keyword '{$keyword}' and mention {$targetSite}
- <h2> Section 1: What is {$keyword} and Why It Matters
- <h2> Section 2: Key Benefits (minimum 6 bullet points)
- <h2> Section 3: Step-by-Step Guide / How It Works
- <h2> Section 4: " . ($isEducation ? "Career Opportunities and Salary" : "Market Value, Pricing, and Investment Benefits") . "
- <h2> Section 5: Why Choose " . (!empty($businessName) ? $businessName : "Learnmore Technologies") . "
- <h2> Section 6: FAQ (3 questions with keyword in each)
- <h2> Conclusion with strong CTA linking to {$targetSite}
KEYWORD RULES:
- Use '{$keyword}' at least 6-8 times naturally
- Include the following 3 backlink anchors naturally in the text:
  1. Once in the introduction: <a href='{$targetSite}'>{$introAnchor}</a>
  2. Once in the middle section: <a href='{$targetSite}'>{$midAnchor}</a>
  3. Once in the conclusion CTA: <a href='{$targetSite}'>{$outroAnchor}</a>
- Use HTML tags throughout: <h1>, <h2>, <h3>, <p>, <ul>, <li>, <strong>, <a>
Platform: {$platform}. Post variation #{$postCount}. Random seed: {$randomSeed}. Timestamp: {$timestamp}. UNIQUE content only.",
    ];

    $prompt = $prompts[$contentType] ?? $prompts['blog_post'];

    $content = null;
    $source = 'Template';

    if (!empty($openaiKey) && strpos($openaiKey, 'sk-') === 0) {
        $content = generateWithOpenAI($prompt, $openaiKey);
        if ($content) {
            $source = 'ChatGPT';
        }
    }

    // Failover/backup to Gemini if OpenAI fails or is not configured
    if (!$content && !empty($geminiKey)) {
        $content = generateWithGemini($prompt, $geminiKey);
        if ($content) {
            $source = 'Gemini';
        }
    }

    if (!$content) {
        $source = 'Template';
        $title = $generatedTitle;
        $kw = ucwords($keyword);
        $yr = date('Y');
        
        switch ($contentType) {
            case 'blog_post':
            case 'article':
                $content = "<h1>{$title}</h1>\n"
                    . "<p>{Are you looking to accelerate your career|Want to upgrade your skills|Looking to build a professional path} and master the skills required for modern industry demands? Obtaining a professional training in <strong>{$kw}</strong> is {one of the most effective ways|a proven strategy|a great step} to stand out in today's competitive job market. In this comprehensive guide, we will explore {everything you need to know|key details|essential tips} about {$kw} and how you can get started today.</p>\n"
                    . "<h2>{What is|Understanding} {$kw} and Why is it Important?</h2>\n"
                    . "<p>{$kw} has emerged as a critical driver of {innovation, efficiency, and scale|modern technology|business success}. Businesses across the globe are {actively seeking|on the lookout for|hiring} certified professionals who can design, build, and optimize solutions using these concepts. By investing in quality training at <a href='{$targetSite}'>{$targetSite}</a>, you gain access to {structured learning paths|expert-led sessions|comprehensive modules}, real-world case studies, and hands-on laboratory exercises designed to simulate real industry challenges.</p>\n"
                    . "<h2>Key Benefits of {Mastering|Learning} {$kw}</h2>\n"
                    . "<ul>\n"
                    . "  <li><strong>{High Industry Demand|Booming Career Options}:</strong> Companies are facing a shortage of skilled experts in {$kw}, leading to excellent career opportunities.</li>\n"
                    . "  <li><strong>{Increased Earning Potential|Top-tier Salaries}:</strong> Certified practitioners command premium salaries globally.</li>\n"
                    . "  <li><strong>{Practical Project Experience|Hands-on Labs}:</strong> Work on live projects to build a robust portfolio.</li>\n"
                    . "  <li><strong>{Flexible Career Paths|Versatile Skills}:</strong> Applicable across multiple sectors including cloud computing, software engineering, and data science.</li>\n"
                    . "  <li><strong>{Comprehensive Placement Assistance|Job Assistance}:</strong> Guided mentorship to prepare for job interviews.</li>\n"
                    . "</ul>\n"
                    . "<h2>How to Choose the Right Training Institute</h2>\n"
                    . "<p>When selecting a course, look for institutes that offer industry-accredited certifications, interactive training modules, and expert mentors with real-world experience. Elevate your skills and learn more by visiting <a href='{$targetSite}'>{$targetSite}</a> to explore our customized modules tailored for {freshers and working professionals|students and experts} alike.</p>\n"
                    . "<h2>Frequently Asked Questions</h2>\n"
                    . "<p><strong>Q: Who is this course for?</strong><br>A: Anyone interested in building a technical career, including graduates, software developers, and IT professionals.</p>\n"
                    . "<p><strong>Q: Does this include real project training?</strong><br>A: Yes, the curriculum emphasizes hands-on projects to ensure you are job-ready.</p>\n"
                    . "<h2>Conclusion</h2>\n"
                    . "<p>{Don't wait to upgrade your career|Take action today}. Take the first step towards a successful future today. Enroll now at <a href='{$targetSite}'>{$targetSite}</a>.</p>";
                break;
                
            case 'micro_blog':
                $content = "<p><strong>{$title}</strong></p>\n"
                    . "<p>{Ready to upgrade your skillset|Looking to advance your IT career}? Discover the power of <strong>{$kw}</strong> and how it can help you land your dream job in {$yr}. Mastering {$kw} opens doors to top-tier technical roles with {high earning potential|excellent salaries}.</p>\n"
                    . "<p>Key features of our training module at <a href='{$targetSite}'>{$targetSite}</a>:</p>\n"
                    . "<ul>\n"
                    . "  <li>{Hands-on labs and live project experience|Practical exercises with real-world scenarios}</li>\n"
                    . "  <li>{Expert industry mentors|Mentorship by industry professionals}</li>\n"
                    . "  <li>{100% placement support|Career assistance and mock interviews}</li>\n"
                    . "  <li>{Accredited certification preparation|Prep for globally recognized certificates}</li>\n"
                    . "</ul>\n"
                    . "<p>{Start learning today and build a bright career|Unlock your potential today}. Enroll now at <a href='{$targetSite}'>{$targetSite}</a>!</p>";
                break;

            case 'bluesky_post':
                $content = "{Ready to master|Want to learn} {$kw} in {$yr}? Enrol in our {industry-recommended|expert-led} course at {$targetSite} today and {land your dream role|boost your tech career}! #{$keyword} {#CloudComputing|#TechCareers|#LearnAWS|#ITTraining}";
                break;
                
            case 'profile_bio':
                $content = "{Dedicated professional|Experienced specialist} and technical expert specializing in {$kw}. Focused on delivering {high-quality training|industry-standard workshops} and practical solutions. Visit <a href='{$targetSite}'>{$targetSite}</a> to learn more.";
                break;
                
            case 'image_caption':
            case 'pdf_description':
                $content = "{Boost your career|Elevate your skills} with our professional {$kw} training program. Get {hands-on lab experience|practical exposure}, real-world case studies, and placement assistance. Enroll now at {$targetSite} #{$platform} #{$keyword}";
                break;
                
            default:
                $content = "<h1>{$title}</h1>\n"
                    . "<p>{Learn and master|Gain deep expertise in} <strong>{$kw}</strong> with our comprehensive training program. Visit <a href='{$targetSite}'>{$targetSite}</a> for more information and to enroll today.</p>";
                break;
        }
    }

    if ($content) {
        $content = preg_replace('/^```html\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/```\s*$/i', '', $content);
        $content = trim($content);
        
        if (stripos($content, '<body') !== false) {
            if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $matches)) {
                $content = trim($matches[1]);
            }
        }
        $content = preg_replace('/<\/?html[^>]*>/is', '', $content);
        $content = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $content);
        $content = preg_replace('/<\/?body[^>]*>/is', '', $content);
        $content = trim($content);
    }

    if ($source === 'Template') {
        $content = spinText($content);
    }
    $content = injectKeywordLinks($content, $keyword, $targetSite);
    return ['content' => $content, 'source' => $source, 'title' => $generatedTitle];
}

function spinText(string $text): string {
    return preg_replace_callback('/\{([^{}]+)\}/', function($matches) {
        $opts = explode('|', $matches[1]);
        return $opts[array_rand($opts)];
    }, $text);
}

/**
 * Inject keyword anchor links into content.
 * Replaces keyword occurrences with <a href="targetSite">keyword</a>
 * - First occurrence: exact keyword → anchor link (primary backlink)
 * - Second occurrence: keyword variations → anchor link
 * - Skips occurrences already inside <a> tags
 * - Skips <h1> tags (keep clean for SEO title)
 */
function injectKeywordLinks(string $content, string $keyword, string $targetSite): string {
    if (empty($keyword) || empty($targetSite) || empty($content)) {
        return $content;
    }

    $kw      = cleanKeyword($keyword); // remove duplicate words
    $kwClean = htmlspecialchars($kw, ENT_QUOTES, 'UTF-8');
    $url     = htmlspecialchars($targetSite, ENT_QUOTES, 'UTF-8');

    // Build anchor tag
    $anchor  = '<a href="' . $url . '" title="' . $kwClean . '" target="_blank" rel="dofollow">' . $kwClean . '</a>';

    // Replace first 2 occurrences of keyword in content
    // But NOT inside existing <a> tags and NOT inside <h1> tags
    $count   = 0;
    $maxLinks = 3; // max 3 links per post

    // Use regex to find keyword outside of HTML tags
    $pattern = '/(?<!["\'=>\w])(' . preg_quote($kw, '/') . ')(?![\w"\'=])/iu';

    $result = preg_replace_callback($pattern, function($m) use ($anchor, &$count, $maxLinks) {
        if ($count < $maxLinks) {
            $count++;
            return $anchor;
        }
        return $m[0];
    }, $content);

    // Also make raw URLs clickable if not already in <a>
    $result = preg_replace(
        '/(?<!href=["\'])(?<!src=["\'])(https?:\/\/' . preg_quote(parse_url($targetSite, PHP_URL_HOST) ?? '', '/') . '[^\s<"\']*)/i',
        '<a href="$1" target="_blank" rel="dofollow">$1</a>',
        $result
    );

    return $result ?: $content;
}

function generateSmartTemplate($keyword, $targetSite, $contentType) {
    unset($contentType);
    $kw  = ucwords($keyword);
    $yr  = date('Y');

    return "<h1>Best {$kw} — Complete Guide {$yr}</h1>

<p>Are you looking for the best <strong>{$keyword}</strong> in {$yr}? You have come to the right place. At <a href='{$targetSite}'>Learnmore Technologies</a>, we offer industry-leading {$keyword} programs designed to help you build a successful career.</p>

<h2>Why Learn {$kw}?</h2>
<p>The demand for <strong>{$keyword}</strong> professionals is growing rapidly. Here are the top reasons to start your journey today:</p>
<ul>
<li>High salary potential — ₹4 LPA to ₹20 LPA</li>
<li>Growing job market with thousands of openings</li>
<li>Industry-recognized certification</li>
<li>Work from anywhere with top companies</li>
<li>Fast career growth within 6-12 months</li>
<li>Practical hands-on training with real projects</li>
</ul>

<h2>What You Will Learn in Our {$kw} Program</h2>
<p>Our comprehensive <strong>{$keyword}</strong> course at <a href='{$targetSite}'>{$targetSite}</a> covers everything from fundamentals to advanced concepts:</p>
<ul>
<li>Core concepts and fundamentals of {$keyword}</li>
<li>Hands-on projects with real industry data</li>
<li>Tools and technologies used by professionals</li>
<li>Interview preparation and career guidance</li>
<li>Placement support with top companies</li>
</ul>

<h2>Career Opportunities After {$kw}</h2>
<p>After completing <strong>{$keyword}</strong> training, you can work in various roles with competitive salaries. Companies across India and globally are hiring {$keyword} experts at premium pay.</p>

<h2>Why Choose Learnmore Technologies?</h2>
<p>We are the top-rated institute for <strong>{$keyword}</strong> with proven placement results. Our expert trainers bring real-world industry experience to every session.</p>
<ul>
<li>Expert trainers with 10+ years industry experience</li>
<li>Small batch sizes for personalized attention</li>
<li>Flexible batch timings — weekday and weekend</li>
<li>100% placement assistance</li>
<li>Globally recognized certification</li>
</ul>

<h2>Frequently Asked Questions</h2>
<p><strong>Q: What is the duration of the {$keyword} course?</strong><br>A: Our {$keyword} program is 2-3 months with flexible batch options.</p>
<p><strong>Q: Do I need prior experience for {$keyword} training?</strong><br>A: No prior experience required. We start from basics and go to advanced level.</p>
<p><strong>Q: What is the salary after completing {$keyword}?</strong><br>A: Professionals with {$keyword} skills earn ₹4-20 LPA depending on experience and role.</p>

<h2>Enroll Today — Limited Seats</h2>
<p>Don't miss this opportunity to transform your career with <strong>{$keyword}</strong>. Join hundreds of successful students who have already built their careers through our program.</p>
<p>Visit <a href='{$targetSite}'>{$targetSite}</a> now to check batch schedules, fees, and enroll online. You can also call us or visit our center at Kalyan Nagar, Bangalore.</p>
<p><strong><a href='{$targetSite}'>Click here to enroll in {$kw} →</a></strong></p>";
}

/**
 * DALL-E 3 image via OpenAI (same API key as ChatGPT).
 * Generates unique images every time using random seed.
 */
function generateImageWithDalle(string $prompt, string $outputPath, ?string $apiKey = null): ?array {
    $apiKey = $apiKey ?? (defined('OPENAI_IMAGE_API_KEY') && OPENAI_IMAGE_API_KEY !== '' ? OPENAI_IMAGE_API_KEY : OPENAI_API_KEY);
    if ($apiKey === '' || strpos($apiKey, 'sk-') !== 0) {
        return ['error' => 'OpenAI API key missing'];
    }

    $model = defined('OPENAI_IMAGE_MODEL') ? OPENAI_IMAGE_MODEL : 'dall-e-3';
    $size  = defined('OPENAI_IMAGE_SIZE') ? OPENAI_IMAGE_SIZE : '1024x1024';

    // Add random seed to ensure unique image every time
    $randomSeed = rand(100000, 999999);
    $timestamp = time();
    $uniquePrompt = $prompt . " Random seed: {$randomSeed}. Timestamp: {$timestamp}. Create a completely unique image - never repeat the same composition or style.";

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'           => $model,
            'prompt'          => mb_substr($uniquePrompt, 0, 4000),
            'n'               => 1,
            'size'            => $size,
            'quality'         => 'standard',
            'response_format' => 'url',
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = json_decode(curl_exec($ch), true);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => $response['error']['message'] ?? ('HTTP ' . $httpCode)];
    }

    $imageUrl = $response['data'][0]['url'] ?? null;
    if (!$imageUrl) {
        return ['error' => 'No image URL from OpenAI'];
    }

    $imgCh = curl_init($imageUrl);
    curl_setopt_array($imgCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $imageData = curl_exec($imgCh);
    curl_close($imgCh);

    if (!$imageData || strlen($imageData) < 1000) {
        return ['error' => 'Failed to download DALL-E image'];
    }

    file_put_contents($outputPath, $imageData);
    return ['success' => true, 'source' => 'ChatGPT DALL-E 3'];
}
