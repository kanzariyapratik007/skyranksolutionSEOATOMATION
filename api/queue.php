<?php
require_once __DIR__ . '/api_auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$userId = $authenticatedUser['id'];
$role = $authenticatedUser['role'];

if ($method === 'GET') {
    try {
        // Build SQL query to fetch queue and join project name
        if ($role === 'admin') {
            $stmt = $db->query("
                SELECT q.*, p.business_name 
                FROM backlink_queue q 
                LEFT JOIN projects p ON q.project_id = p.id 
                ORDER BY q.id DESC 
                LIMIT 100
            ");
        } else {
            $stmt = $db->prepare("
                SELECT q.*, p.business_name 
                FROM backlink_queue q 
                LEFT JOIN projects p ON q.project_id = p.id 
                WHERE p.user_id = ? 
                ORDER BY q.id DESC 
                LIMIT 100
            ");
            $stmt->execute([$userId]);
        }

        $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'queue' => $queue
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}

if ($method === 'POST') {
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true) ?? [];

    $action  = trim($input['action'] ?? $_POST['action'] ?? '');
    $queueId = (int)($input['queue_id'] ?? $_POST['queue_id'] ?? 0);

    if ($queueId <= 0 || $action !== 'retry') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid action or queue_id.'
        ]);
        exit;
    }

    try {
        // Verify ownership/authorization of the queue item before retrying
        if ($role !== 'admin') {
            $chk = $db->prepare("
                SELECT q.id 
                FROM backlink_queue q
                JOIN projects p ON q.project_id = p.id
                WHERE q.id = ? AND p.user_id = ? 
                LIMIT 1
            ");
            $chk->execute([$queueId, $userId]);
            if (!$chk->fetch()) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Access denied.'
                ]);
                exit;
            }
        }

        // Reset to pending
        $stmt = $db->prepare("
            UPDATE backlink_queue 
            SET status = 'pending', error_message = NULL, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$queueId]);

        echo json_encode([
            'success' => true,
            'message' => 'Task rescheduled to pending status successfully.'
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}

http_response_code(405);
echo json_encode([
    'success' => false,
    'error' => 'Method not allowed.'
]);
