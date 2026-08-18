<?php
require_once __DIR__ . '/../config.php';

echo "=== API Key Status ===\n";
echo "OPENAI_API_KEY defined: " . (defined('OPENAI_API_KEY') ? 'Yes' : 'No') . "\n";
echo "OPENAI_API_KEY empty: " . (empty(OPENAI_API_KEY) ? 'Yes' : 'No') . "\n";
if (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY)) {
    echo "OPENAI_API_KEY length: " . strlen(OPENAI_API_KEY) . "\n";
    echo "OPENAI_API_KEY prefix: " . substr(OPENAI_API_KEY, 0, 7) . "\n";
}

echo "\nGEMINI_API_KEY defined: " . (defined('GEMINI_API_KEY') ? 'Yes' : 'No') . "\n";
echo "GEMINI_API_KEY empty: " . (empty(GEMINI_API_KEY) ? 'Yes' : 'No') . "\n";
if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
    echo "GEMINI_API_KEY length: " . strlen(GEMINI_API_KEY) . "\n";
    echo "GEMINI_API_KEY prefix: " . substr(GEMINI_API_KEY, 0, 7) . "\n";
}
