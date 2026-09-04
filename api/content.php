<?php
require_once __DIR__ . '/api_auth.php';
require_once dirname(__DIR__) . '/ai-content.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$userId = $authenticatedUser['id'];
$role = $authenticatedUser['role'];

if ($method === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'project_id is required.']);
        exit;
    }

    try {
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
            echo json_encode(['success' => false, 'error' => 'Project not found or access denied.']);
            exit;
        }

        // Fetch articles from content_queue
        $stmt = $db->prepare("SELECT * FROM content_queue WHERE project_id = ? ORDER BY created_at DESC");
        $stmt->execute([$projectId]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'articles' => $articles
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true) ?? [];

    $action    = trim($input['action'] ?? $_POST['action'] ?? '');
    $contentId = (int)($input['content_id'] ?? $_POST['content_id'] ?? 0);
    $projectId = (int)($input['project_id'] ?? $_POST['project_id'] ?? 0);

    if ($contentId <= 0 || $projectId <= 0 || empty($action)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'action, content_id, and project_id are required.']);
        exit;
    }

    try {
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
            echo json_encode(['success' => false, 'error' => 'Project not found or access denied.']);
            exit;
        }

        // 1. Action: Approve / Publish
        if ($action === 'approve') {
            $status = trim($input['status'] ?? $_POST['status'] ?? 'approved');
            if (!in_array($status, ['draft', 'approved', 'published'])) {
                $status = 'approved';
            }

            $stmt = $db->prepare("UPDATE content_queue SET status = ? WHERE id = ? AND project_id = ?");
            $stmt->execute([$status, $contentId, $projectId]);

            echo json_encode([
                'success' => true,
                'message' => 'Article status updated to ' . $status
            ], JSON_PRETTY_PRINT);
            exit;
        }

        // 2. Action: Regenerate
        if ($action === 'regenerate') {
            $keyword = $project['target_keyword'];
            $site    = $project['target_site'] ?: $project['website_url'];

            // Take the first keyword if comma-separated
            $keywordsList = array_filter(array_map('trim', explode(',', $keyword)));
            $kw = $keywordsList[0] ?? '';
            $sitesList = array_filter(array_map('trim', explode(',', $site)));
            $st = $sitesList[0] ?? '';

            $prompt = "Write a completely new, unique SEO article about '{$kw}'. 
Use HTML tags with h1, h2, h3, p, ul tags.
600-800 words. Include backlink to {$st}.
Make it 100% different from any previous article.";

            $ai = generateWithAI($prompt);
            
            if ($ai['text']) {
                $stmt = $db->prepare("UPDATE content_queue SET article = ?, status = 'draft' WHERE id = ? AND project_id = ?");
                $stmt->execute([$ai['text'], $contentId, $projectId]);

                echo json_encode([
                    'success' => true,
                    'article' => $ai['text'],
                    'message' => 'Article regenerated with ' . $ai['source'] . ' ✨'
                ], JSON_PRETTY_PRINT);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'AI Generation failed. Make sure your OpenAI / Gemini keys are configured.'
                ]);
            }
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action. Use "approve" or "regenerate".']);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
