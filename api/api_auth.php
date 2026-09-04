<?php
require_once dirname(__DIR__) . '/config.php';

// Disable standard HTML rendering for flash messages or redirects in API requests
if (!defined('RUNNING_AS_API')) {
    define('RUNNING_AS_API', true);
}

// Ensure sessions can be used
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function authenticateAPIRequest(): array {
    $apiKey = '';
    
    // 1. Check Authorization Header (Bearer Token)
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (isset($headers['authorization'])) {
        $authHeader = $headers['authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $apiKey = trim($matches[1]);
        }
    }
    
    // 2. Fallback to GET/POST parameters
    if (empty($apiKey)) {
        $apiKey = $_REQUEST['api_key'] ?? '';
    }
    
    if (empty($apiKey)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized. API key is required in Authorization Header (Bearer Token) or api_key parameter.'
        ]);
        exit;
    }
    
    // 3. Query User in Database
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE api_key = ? LIMIT 1");
        $stmt->execute([$apiKey]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Unauthorized. Invalid API key.'
            ]);
            exit;
        }
        
        // Populate standard session keys so existing dashboard helpers and DB queries function correctly
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        
        return $user;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error during API authentication: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Run authentication
$authenticatedUser = authenticateAPIRequest();
