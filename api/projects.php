<?php
require_once __DIR__ . '/api_auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$userId = $authenticatedUser['id'];
$role = $authenticatedUser['role'];

if ($method === 'GET') {
    // 1. Fetch single project details
    if (isset($_GET['id'])) {
        $projId = (int)$_GET['id'];
        try {
            if ($role === 'admin') {
                $stmt = $db->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
                $stmt->execute([$projId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ? LIMIT 1");
                $stmt->execute([$projId, $userId]);
            }
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$project) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Project not found.']);
                exit;
            }
            
            // Decrypt password if set (for API client use if needed)
            if (!empty($project['admin_pass'])) {
                $project['admin_pass_plain'] = base64_decode($project['admin_pass']);
            }
            
            echo json_encode([
                'success' => true,
                'project' => $project
            ], JSON_PRETTY_PRINT);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // 2. Fetch all projects
    try {
        if ($role === 'admin') {
            $stmt = $db->query("SELECT id, user_id, website_url, target_keyword, target_site, business_name, package_type FROM projects ORDER BY id DESC");
        } else {
            $stmt = $db->prepare("SELECT id, website_url, target_keyword, target_site, business_name, package_type FROM projects WHERE user_id = ? ORDER BY id DESC");
            $stmt->execute([$userId]);
        }
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'projects' => $projects
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
    
    $website_url      = trim($input['website_url'] ?? $_POST['website_url'] ?? '');
    $target_keyword   = trim($input['target_keyword'] ?? $_POST['target_keyword'] ?? '');
    $target_site      = trim($input['target_site'] ?? $_POST['target_site'] ?? '');
    $package_type     = trim($input['package_type'] ?? $_POST['package_type'] ?? 'basic');
    $business_name    = trim($input['business_name'] ?? $_POST['business_name'] ?? '');
    $contact_name     = trim($input['contact_name'] ?? $_POST['contact_name'] ?? '');
    $phone            = trim($input['phone'] ?? $_POST['phone'] ?? '');
    $email            = trim($input['email'] ?? $_POST['email'] ?? '');
    $business_desc    = trim($input['business_desc'] ?? $_POST['business_desc'] ?? '');
    
    // Optional details
    $gsc_access       = $input['gsc_access'] ?? $_POST['gsc_access'] ?? null;
    $ga_access        = $input['ga_access'] ?? $_POST['ga_access'] ?? null;
    $google_ads_id    = $input['google_ads_id'] ?? $_POST['google_ads_id'] ?? null;
    $admin_url        = $input['admin_url'] ?? $_POST['admin_url'] ?? '';
    $admin_user       = $input['admin_user'] ?? $_POST['admin_user'] ?? '';
    $admin_pass       = isset($input['admin_pass']) ? base64_encode($input['admin_pass']) : (isset($_POST['admin_pass']) ? base64_encode($_POST['admin_pass']) : null);
    $competitor_sites = $input['competitor_sites'] ?? $_POST['competitor_sites'] ?? null;
    
    if (empty($website_url) || empty($target_keyword)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Website URL and target keyword are required.'
        ]);
        exit;
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO projects (
                user_id, website_url, target_keyword, target_site, package_type, 
                business_name, contact_name, phone, email, gsc_access, 
                ga_access, google_ads_id, admin_url, admin_user, admin_pass, 
                competitor_sites, business_desc
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        
        $stmt->execute([
            $userId, $website_url, $target_keyword, $target_site, $package_type,
            $business_name, $contact_name, $phone, $email, $gsc_access,
            $ga_access, $google_ads_id, $admin_url, $admin_user, $admin_pass,
            $competitor_sites, $business_desc
        ]);
        
        $newProjectId = $db->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'project_id' => $newProjectId,
            'message' => 'Project created successfully.'
        ], JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to create project: ' . $e->getMessage()
        ]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
