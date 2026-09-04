<?php
require_once __DIR__ . '/api_auth.php';
require_once dirname(__DIR__) . '/ai-content.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Only POST is accepted.'
    ]);
    exit;
}

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true) ?? [];

$projectId = (int)($input['project_id'] ?? $_POST['project_id'] ?? 0);

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'project_id is required.'
    ]);
    exit;
}

function apiGenerateAllArticles($keyword, $site) {
    $prompts = [
        [
            'title_hint' => 'Complete Guide',
            'prompt'     => "Write a comprehensive SEO blog post (600-800 words) about '{$keyword}'. Structure with HTML tags: <h1> title with keyword, <h2> What is, <h2> Why Learn, <h2> Career Opportunities, <h2> Best Training Institute, <p> conclusion with link to <a href='{$site}'>{$site}</a>. Natural usage 3-4 times.",
        ],
        [
            'title_hint' => 'Beginners Guide',
            'prompt'     => "Write a beginner-friendly blog post (500-700 words) about '{$keyword}' using HTML tags. Include <h1> beginner title, <h2> What You Will Learn, <h2> Step by Step, <h2> Tools, <h2> Where to Learn. Mention <a href='{$site}'>{$site}</a>.",
        ],
        [
            'title_hint' => 'Career Guide',
            'prompt'     => "Write a career-focused article (600 words) about jobs and salary for '{$keyword}'. HTML format: <h1> title, <h2> Job Roles, <h2> Average Salary, <h2> Top Companies, <h2> Best Institute link to <a href='{$site}'>{$site}</a>.",
        ],
        [
            'title_hint' => 'Top 10 Tips',
            'prompt'     => "Write a 'Top 10 Tips for {$keyword}' article (500-600 words). HTML format: <h1> title, <p> intro, 10 headings <h3> with explanations, <p> conclusion with link to <a href='{$site}'>{$site}</a>.",
        ],
        [
            'title_hint' => 'FAQ Article',
            'prompt'     => "Write an FAQ article answering 8 common questions about '{$keyword}' (600 words). HTML format: <h1> title, 8 questions as <h2> tags with detailed answers. Last answer link to <a href='{$site}'>{$site}</a>.",
        ],
    ];

    $articles = [];
    foreach ($prompts as $p) {
        $promptText = str_replace(['{$keyword}', '{$site}'], [$keyword, $site], $p['prompt']);
        $ai = generateWithAI($promptText);
        $content = $ai['text'] ?: generateAIContent($keyword, $site, 'blog', 'article', '', OPENAI_API_KEY)['content'];
        $source = $ai['text'] ? $ai['source'] : 'Template';

        $articles[] = [
            'title'   => ucwords($keyword) . ' - ' . $p['title_hint'] . ' ' . date('Y'),
            'content' => $content,
            'source'  => $source,
        ];
        sleep(1); // Avoid API rate limits
    }
    return $articles;
}

try {
    $db = getDB();
    $userId = $authenticatedUser['id'];
    $role = $authenticatedUser['role'];

    // Verify project ownership
    if ($role === 'admin') {
        $projStmt = $db->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
        $projStmt->execute([$projectId]);
    } else {
        $projStmt = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ? LIMIT 1");
        $projStmt->execute([$projectId, $userId]);
    }
    $project = $projStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Project not found or access denied.'
        ]);
        exit;
    }

    $keyword = $project['target_keyword'];
    $site    = $project['target_site'] ?: $project['website_url'];

    // Resolve primary keyword and target site
    $keywordsList = array_filter(array_map('trim', explode(',', $keyword)));
    $primaryKw = $keywordsList[0] ?? '';
    
    $sitesList = array_filter(array_map('trim', explode(',', $site)));
    $primarySite = $sitesList[0] ?? '';

    if (empty($primaryKw) || empty($primarySite)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Target keywords or website URL is missing from project settings.'
        ]);
        exit;
    }

    // Delete old drafts
    $db->prepare("DELETE FROM content_queue WHERE project_id=? AND status='draft'")->execute([$projectId]);

    // Generate articles
    $articles = apiGenerateAllArticles($primaryKw, $primarySite);

    // Save to database
    $insert = $db->prepare("INSERT INTO content_queue (project_id, title, article, status) VALUES (?,?,?, 'draft')");
    foreach ($articles as $art) {
        $insert->execute([$projectId, $art['title'], $art['content']]);
    }

    echo json_encode([
        'success' => true,
        'generated_count' => count($articles),
        'message' => 'Successfully generated ' . count($articles) . ' articles via AI.'
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'AI Generation failed: ' . $e->getMessage()
    ]);
}
