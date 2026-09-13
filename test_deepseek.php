<?php
/**
 * Test DeepSeek API Connection & Content Generation
 * Run via browser: http://localhost/seo-system/test_deepseek.php
 * Or via CLI: php test_deepseek.php
 */
require_once __DIR__ . '/config.php';

$deepseekKey = defined('DEEPSEEK_API_KEY') ? DEEPSEEK_API_KEY : '';
if (isset($_GET['key']) && !empty($_GET['key'])) {
    $deepseekKey = trim($_GET['key']);
} elseif (isset($_POST['key']) && !empty($_POST['key'])) {
    $deepseekKey = trim($_POST['key']);
}

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Test DeepSeek API</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light p-4">
    <div class="container" style="max-width: 700px;">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white font-weight-bold">
                🚀 DeepSeek API Tester
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold">DeepSeek API Key:</label>
                        <input type="text" name="key" class="form-control font-monospace" placeholder="sk-..." value="' . htmlspecialchars($deepseekKey) . '" required>
                        <div class="form-text">Get your API Key from <a href="https://platform.deepseek.com/api_keys" target="_blank">platform.deepseek.com/api_keys</a></div>
                    </div>
                    <button type="submit" class="btn btn-success fw-bold">🧪 Test DeepSeek Connection</button>
                </form>
            </div>
        </div>';
}

if (!empty($deepseekKey)) {
    if (!$isCli) echo '<div class="card shadow-sm"><div class="card-header fw-bold">Response Output</div><div class="card-body">';
    
    echo $isCli ? "Testing DeepSeek API Key: " . substr($deepseekKey, 0, 8) . "...\n" : "<p><strong>Testing API Key:</strong> <code>" . htmlspecialchars(substr($deepseekKey, 0, 8)) . "...</code></p>";

    $startTime = microtime(true);
    $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
    $payload = json_encode([
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful SEO copywriting assistant.'],
            ['role' => 'user', 'content' => 'Write a short 2-sentence SEO tip about backlinks.']
        ],
        'temperature' => 0.7,
        'max_tokens' => 100
    ]);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $deepseekKey,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $duration = round(microtime(true) - $startTime, 2);

    if ($httpCode === 200) {
        $json = json_decode($res, true);
        $reply = $json['choices'][0]['message']['content'] ?? 'No text returned';
        $tokens = $json['usage']['total_tokens'] ?? 0;

        if ($isCli) {
            echo "✅ SUCCESS! (HTTP 200 in {$duration}s | Tokens used: {$tokens})\n";
            echo "DeepSeek Response:\n" . $reply . "\n";
        } else {
            echo '<div class="alert alert-success fw-bold">✅ Success! HTTP 200 (Response time: ' . $duration . 's | Tokens used: ' . $tokens . ')</div>';
            echo '<h5>Generated Sample Content:</h5>';
            echo '<div class="p-3 bg-white border rounded mb-3"><em>' . nl2br(htmlspecialchars($reply)) . '</em></div>';
        }
    } else {
        $json = json_decode($res, true);
        $msg = $json['error']['message'] ?? $err ?: "HTTP $httpCode Error";
        if ($isCli) {
            echo "❌ FAILED (HTTP {$httpCode}): {$msg}\n";
        } else {
            echo '<div class="alert alert-danger fw-bold">❌ Error (HTTP ' . $httpCode . '): ' . htmlspecialchars($msg) . '</div>';
        }
    }

    if (!$isCli) echo '</div></div>';
}

if (!$isCli) {
    echo '</div></body></html>';
}
