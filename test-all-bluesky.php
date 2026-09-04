<?php
require_once 'config.php';
requireLogin();
set_time_limit(300);

$db = getDB();
$userId = $_SESSION['user_id'];

$stmt = $db->prepare('SELECT username, api_key FROM social_accounts WHERE user_id=? AND platform=?');
$stmt->execute([$userId, 'bluesky']);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($accounts as $acc) {
    $username = trim($acc['username']);
    $password = trim($acc['api_key']); // stored as app password
    if (empty($username) || empty($password)) {
        $results[] = ['username' => $username, 'status' => 'error', 'message' => 'Missing credentials'];
        continue;
    }
    // ensure handle format
    if (strpos($username, '@') === 0) $username = substr($username, 1);
    if (strpos($username, '.') === false) $username .= '.bsky.social';

    $ch = curl_init('https://bsky.social/xrpc/com.atproto.server.createSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['identifier' => $username, 'password' => $password]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        $results[] = ['username' => $username, 'status' => 'error', 'message' => $curlErr];
        continue;
    }
    $resp = json_decode($raw, true);
    if (isset($resp['accessJwt'])) {
        $results[] = ['username' => $username, 'status' => 'success', 'message' => 'Login successful'];
    } else {
        $msg = $resp['message'] ?? json_encode($resp);
        $results[] = ['username' => $username, 'status' => 'error', 'message' => $msg];
    }
}

header('Content-Type: application/json');
echo json_encode(['results' => $results]);
?>
