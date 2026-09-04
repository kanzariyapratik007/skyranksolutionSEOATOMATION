<?php
require_once 'config.php';
$db = getDB();

// 1. Show all pinterest records
echo "<h3>Pinterest DB records:</h3><pre>";
$rows = $db->query("SELECT id, platform, username, password, api_key, status FROM social_accounts WHERE platform='pinterest' ORDER BY id")->fetchAll();
foreach($rows as $r) {
    $p = base64_decode($r['password']??'',true);
    echo "id={$r['id']} user={$r['username']} pass=" . ($p?:'EMPTY') . " api_key=" . (empty($r['api_key'])?'EMPTY':substr($r['api_key'],0,15).'...') . " status={$r['status']}\n";
}
echo count($rows) . " total records\n</pre>";

// 2. Simulate what auto-poster does (takes FIRST active record)
echo "<h3>What auto-poster will use (first active record):</h3><pre>";
$first = $db->query("SELECT * FROM social_accounts WHERE platform='pinterest' AND status='active' ORDER BY id ASC LIMIT 1")->fetch();
if($first) {
    $apiKey = $first['api_key'] ?? '';
    $username = $first['username'] ?? '';
    $pass = base64_decode($first['password']??'',true) ?: ($first['password']??'');
    $isEmail = strpos($username,'@') !== false;
    echo "username: $username\n";
    echo "apiKey: " . (empty($apiKey)?'EMPTY':substr($apiKey,0,20).'...') . "\n";
    echo "password: " . (empty($pass)?'EMPTY':'SET') . "\n";
    echo "isEmail: " . ($isEmail?'YES':'NO') . "\n";
    echo "\nRoute decision:\n";
    if(!empty($apiKey) && $isEmail) echo "→ seleniumPinterest() [email+apikey both set]\n";
    elseif(!empty($apiKey) && !$isEmail) echo "→ postToPinterest() API [old api key record]\n";
    else echo "→ seleniumPinterest() [no apikey, use email+pass]\n";
} else {
    echo "No active Pinterest record!\n";
}
echo "</pre>";
?>
