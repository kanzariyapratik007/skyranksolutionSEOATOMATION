<?php
// ============================================================
// setup-complete-account.php - Auto-create complete account
// Takes all API keys from database and creates new ready-to-use account
// ============================================================

require_once 'config.php';

$db = getDB();

echo "<h2>🚀 Complete Account Setup</h2>";
echo "<p>This script will:</p>";
echo "<ol>";
echo "<li>Read all API keys/credentials from existing accounts</li>";
echo "<li>Create a new user account</li>";
echo "<li>Copy all credentials to the new user</li>";
echo "<li>Create a sample project ready for auto-posting</li>";
echo "</ol>";
echo "<hr>";

// Step 1: Read all existing credentials
echo "<h3>Step 1: Reading existing credentials...</h3>";
$stmt = $db->query("SELECT * FROM social_accounts");
$existingAccounts = $stmt->fetchAll();

if (empty($existingAccounts)) {
    echo "<p class='text-warning'>⚠️ No existing credentials found in database.</p>";
    echo "<p>Please add credentials first via <a href='social-accounts.php'>Social Accounts</a> page.</p>";
    exit;
}

echo "<p>Found " . count($existingAccounts) . " credential sets:</p>";
echo "<table class='table table-sm'><tr><th>Platform</th><th>Username</th><th>Has API Key</th><th>Has Secret</th></tr>";
foreach ($existingAccounts as $acc) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($acc['platform']) . "</td>";
    echo "<td>" . htmlspecialchars($acc['username']) . "</td>";
    echo "<td>" . ($acc['api_key'] ? '✅' : '❌') . "</td>";
    echo "<td>" . ($acc['api_secret'] ? '✅' : '❌') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Step 2: Create new user
echo "<h3>Step 2: Creating new user...</h3>";
$newUsername = 'autopost_' . time();
$newPassword = password_hash('Autopost123!', PASSWORD_DEFAULT);
$newEmail = $newUsername . '@seo-system.local';

try {
    $stmt = $db->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
    $stmt->execute([$newUsername, $newPassword, $newEmail]);
    $newUserId = $db->lastInsertId();
    echo "<p class='text-success'>✅ New user created: <strong>$newUsername</strong></p>";
} catch (Exception $e) {
    echo "<p class='text-danger'>❌ Error creating user: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

// Step 3: Copy all credentials to new user
echo "<h3>Step 3: Copying credentials to new user...</h3>";
$copiedCount = 0;
foreach ($existingAccounts as $acc) {
    try {
        $stmt = $db->prepare("INSERT INTO social_accounts (user_id, platform, username, password, api_key, api_secret, refresh_token) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $newUserId,
            $acc['platform'],
            $acc['username'],
            $acc['password'],
            $acc['api_key'],
            $acc['api_secret'],
            $acc['refresh_token']
        ]);
        $copiedCount++;
        echo "<p class='text-success'>✅ Copied: " . htmlspecialchars($acc['platform']) . "</p>";
    } catch (Exception $e) {
        echo "<p class='text-danger'>❌ Failed to copy " . htmlspecialchars($acc['platform']) . ": " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<p><strong>Total credentials copied: $copiedCount</strong></p>";

// Step 4: Create a sample project
echo "<h3>Step 4: Creating sample project...</h3>";
$sampleKeyword = 'SEO Training';
$sampleWebsite = 'https://example.com';
$sampleTarget = 'https://example.com/seo-course';

try {
    $stmt = $db->prepare("INSERT INTO projects (user_id, website_url, target_keyword, target_site) VALUES (?, ?, ?, ?)");
    $stmt->execute([$newUserId, $sampleWebsite, $sampleKeyword, $sampleTarget]);
    $projectId = $db->lastInsertId();
    echo "<p class='text-success'>✅ Sample project created (ID: $projectId)</p>";
    echo "<p>Keyword: <strong>$sampleKeyword</strong></p>";
    echo "<p>Target: <strong>$sampleTarget</strong></p>";
} catch (Exception $e) {
    echo "<p class='text-danger'>❌ Error creating project: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Step 5: Generate backlink opportunities
echo "<h3>Step 5: Generating backlink opportunities...</h3>";
$backlinkSites = [
    ['platform' => 'Medium.com', 'da' => 95, 'url' => 'https://medium.com/new-story'],
    ['platform' => 'WordPress.com', 'da' => 94, 'url' => 'https://wordpress.com/post/new'],
    ['platform' => 'Blogger.com', 'da' => 93, 'url' => 'https://www.blogger.com/blog/post/create'],
    ['platform' => 'LinkedIn Articles', 'da' => 98, 'url' => 'https://www.linkedin.com/post/new'],
    ['platform' => 'Quora', 'da' => 93, 'url' => 'https://www.quora.com/search?q=' . urlencode($sampleKeyword)],
    ['platform' => 'Reddit', 'da' => 91, 'url' => 'https://www.reddit.com/search/?q=' . urlencode($sampleKeyword)],
    ['platform' => 'GitHub', 'da' => 96, 'url' => 'https://github.com/new'],
    ['platform' => 'Tumblr', 'da' => 89, 'url' => 'https://www.tumblr.com/new/text'],
];

$backlinkCount = 0;
foreach ($backlinkSites as $site) {
    try {
        $stmt = $db->prepare("INSERT INTO backlinks (project_id, backlink_url, platform, da_score, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$projectId, $site['url'], $site['platform'], $site['da']]);
        $backlinkCount++;
    } catch (Exception $e) {
        // Ignore duplicates
    }
}
echo "<p class='text-success'>✅ Generated $backlinkCount backlink opportunities</p>";

// Final summary
echo "<hr>";
echo "<div class='card bg-success text-white p-4'>";
echo "<h2>✅ Setup Complete!</h2>";
echo "<p><strong>Login Credentials:</strong></p>";
echo "<ul>";
echo "<li>Username: <code>$newUsername</code></li>";
echo "<li>Password: <code>Autopost123!</code></li>";
echo "</ul>";
echo "<p><strong>What's ready:</strong></p>";
echo "<ul>";
echo "<li>✅ All API keys copied from existing accounts</li>";
echo "<li>✅ Sample project created with keyword: $sampleKeyword</li>";
echo "<li>✅ Backlink opportunities generated</li>";
echo "<li>✅ Ready to auto-post to all platforms</li>";
echo "</ul>";
echo "<p><a href='index.php' class='btn btn-light'>Go to Login</a></p>";
echo "<p><a href='auto-poster.php?id=$projectId' class='btn btn-warning'>Test Auto-Post</a></p>";
echo "</div>";
?>
<!DOCTYPE html>
<html>
<head>
    <title>Setup Complete Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
</body>
</html>
