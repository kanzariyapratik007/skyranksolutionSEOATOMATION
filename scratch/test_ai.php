<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai-content.php';

echo "=== TESTING OPENAI ===\n";
if (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY)) {
    $prompt = "Write a one-sentence message about 'Property in Gota Ahmedabad'.";
    $start = microtime(true);
    $res = generateWithOpenAI($prompt, OPENAI_API_KEY);
    $time = round(microtime(true) - $start, 2);
    echo "OpenAI time: {$time}s\n";
    if ($res) {
        echo "OpenAI response: {$res}\n";
    } else {
        echo "OpenAI returned empty (Check php_errors.log or test manually)\n";
    }
} else {
    echo "OpenAI Key not defined or empty\n";
}

echo "\n=== TESTING GEMINI ===\n";
if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
    $prompt = "Write a one-sentence message about 'Property in Gota Ahmedabad'.";
    $start = microtime(true);
    $res = generateWithGemini($prompt, GEMINI_API_KEY);
    $time = round(microtime(true) - $start, 2);
    echo "Gemini time: {$time}s\n";
    if ($res) {
        echo "Gemini response: {$res}\n";
    } else {
        echo "Gemini returned empty (Check php_errors.log or test manually)\n";
    }
} else {
    echo "Gemini Key not defined or empty\n";
}
