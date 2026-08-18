<?php
require_once __DIR__ . '/../config.php';

$uploadsDir = dirname(__DIR__) . '/uploads';
echo "Uploads Dir: {$uploadsDir}\n";
echo "Exists: " . (is_dir($uploadsDir) ? 'Yes' : 'No') . "\n";
echo "Writable: " . (is_writable($uploadsDir) ? 'Yes' : 'No') . "\n";

$testFile = $uploadsDir . '/test_write_' . time() . '.txt';
$bytes = @file_put_contents($testFile, "Hello World");
if ($bytes !== false) {
    echo "Successfully wrote {$bytes} bytes to {$testFile}\n";
    @unlink($testFile);
} else {
    echo "FAILED to write to {$testFile}!\n";
    $err = error_get_last();
    if ($err) {
        echo "Error message: {$err['message']}\n";
    }
}
