<?php
require_once __DIR__ . '/api_auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if ($method === 'GET') {
    try {
        $stmt = $db->query("SELECT id, platform, username, status, created_at FROM social_accounts ORDER BY platform ASC, username ASC");
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'accounts' => $accounts
        ], JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    // Read inputs (JSON or Form POST)
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true) ?? [];
    
    $platform   = trim($input['platform'] ?? $_POST['platform'] ?? '');
    $username   = trim($input['username'] ?? $_POST['username'] ?? '');
    $password   = $input['password'] ?? $_POST['password'] ?? '';
    
    $api_key    = trim($input['api_key'] ?? $_POST['api_key'] ?? '');
    $api_secret = trim($input['api_secret'] ?? $_POST['api_secret'] ?? '');
    $status     = trim($input['status'] ?? $_POST['status'] ?? 'active');
    
    if (empty($platform) || empty($username)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Platform and username are required.'
        ]);
        exit;
    }
    
    // Base64 encode password for security
    $encodedPassword = !empty($password) ? base64_encode($password) : null;
    
    try {
        // Check if account already exists
        $chk = $db->prepare("SELECT id FROM social_accounts WHERE platform = ? AND username = ? LIMIT 1");
        $chk->execute([$platform, $username]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update
            $sql = "UPDATE social_accounts SET status = ?";
            $params = [$status];
            
            if ($encodedPassword !== null) {
                $sql .= ", password = ?";
                $params[] = $encodedPassword;
            }
            if (!empty($api_key)) {
                $sql .= ", api_key = ?";
                $params[] = $api_key;
            }
            if (!empty($api_secret)) {
                $sql .= ", api_secret = ?";
                $params[] = $api_secret;
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $existing['id'];
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'account_id' => $existing['id'],
                'message' => 'Credentials updated successfully.'
            ], JSON_PRETTY_PRINT);
            
        } else {
            // Insert
            $stmt = $db->prepare("
                INSERT INTO social_accounts (platform, username, password, api_key, api_secret, status) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $platform, $username, $encodedPassword, 
                !empty($api_key) ? $api_key : null, 
                !empty($api_secret) ? $api_secret : null, 
                $status
            ]);
            
            $newAccountId = $db->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'account_id' => $newAccountId,
                'message' => 'Credentials created successfully.'
            ], JSON_PRETTY_PRINT);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to save credentials: ' . $e->getMessage()
        ]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
