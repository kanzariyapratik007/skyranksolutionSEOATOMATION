<?php
require_once __DIR__ . '/../config.php';

try {
    $db = getDB();

    echo "=== backlinks table columns ===\n";
    $stmt = $db->query("DESCRIBE backlinks");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
    }

    echo "\n=== backlink_queue table columns ===\n";
    $stmt2 = $db->query("DESCRIBE backlink_queue");
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
