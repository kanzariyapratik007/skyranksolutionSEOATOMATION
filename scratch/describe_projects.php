<?php
require_once __DIR__ . '/../config.php';

try {
    $db = getDB();
    echo "=== projects table columns ===\n";
    $stmt = $db->query("DESCRIBE projects");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
