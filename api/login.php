<?php
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Only POST is accepted.'
    ]);
    exit;
}

// Read inputs (JSON or Form POST)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

$username = trim($input['username'] ?? $_POST['username'] ?? '');
$password = $input['password'] ?? $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Username and password are required.'
    ]);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid username or password.'
        ]);
        exit;
    }
    
    // Check or generate API Key
    $apiKey = $user['api_key'] ?? '';
    if (empty($apiKey)) {
        $apiKey = bin2hex(random_bytes(16));
        $update = $db->prepare("UPDATE users SET api_key = ? WHERE id = ?");
        $update->execute([$apiKey, $user['id']]);
    }
    
    echo json_encode([
        'success' => true,
        'api_key' => $apiKey,
        'username' => $user['username'],
        'role' => $user['role'],
        'message' => 'Login successful.'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error during login: ' . $e->getMessage()
    ]);
}
