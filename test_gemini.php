<?php
require_once 'config.php';
require_once 'ai-content.php';

header('Content-Type: text/plain');

$key = GEMINI_API_KEY;
echo "Gemini Key in Config: " . ($key ? substr($key, 0, 8) . "..." : "EMPTY") . "\n";

$ch = curl_init("https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $key);
$payload = json_encode([
    'contents' => [['parts' => [['text' => 'Hello, respond with exactly "Gemini is working!"']]]]
]);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false
]);

$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
if ($err) {
    echo "Curl Error: " . $err . "\n";
}
echo "Raw Response:\n" . $res . "\n";
