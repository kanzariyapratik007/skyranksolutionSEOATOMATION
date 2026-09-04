<?php
// Simple Bluesky credential tester — POST or GET `username` and `password`
header('Content-Type: application/json; charset=utf-8');
// Allow quick local access without authentication
if (php_sapi_name() !== 'cli') {
    // optional: include config.php for consistent environment
    if (is_readable(__DIR__ . '/config.php')) include __DIR__ . '/config.php';
}

$username = trim($_REQUEST['username'] ?? '');
$password = trim($_REQUEST['password'] ?? '');
if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'username and password required', 'usage' => 'POST or GET username, password']);
    exit;
}

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
    echo json_encode(['error' => 'curl_failed', 'message' => $curlErr]);
    exit;
}

$resp = json_decode($raw, true);
// Return both parsed JSON (if any) and raw string for debugging
echo json_encode(['http_code' => $httpCode, 'response' => $resp, 'raw' => $raw], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
