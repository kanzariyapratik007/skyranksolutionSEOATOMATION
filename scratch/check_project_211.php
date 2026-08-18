<?php
require_once __DIR__ . '/../config.php';

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, website_url, target_keyword, target_site, business_name, business_desc FROM projects WHERE id=?");
    $stmt->execute([211]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "=== Project 211 Settings ===\n";
        echo "ID: {$row['id']}\n";
        echo "Website URL: {$row['website_url']}\n";
        echo "Target Keyword: {$row['target_keyword']}\n";
        echo "Target Site: {$row['target_site']}\n";
        echo "Business Name: {$row['business_name']}\n";
        echo "Business Desc: {$row['business_desc']}\n";
    } else {
        echo "Project 211 not found in database!\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
