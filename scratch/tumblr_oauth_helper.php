<?php
require_once __DIR__ . '/../config.php';

session_start();

// Handle reset of keys
if (isset($_GET['reset'])) {
    unset($_SESSION['custom_consumer_key']);
    unset($_SESSION['custom_consumer_secret']);
    unset($_SESSION['oauth_token_secret']);
    header('Location: tumblr_oauth_helper.php');
    exit;
}

// Allow passing custom keys via query parameters
if (isset($_GET['consumer_key']) && isset($_GET['consumer_secret'])) {
    $_SESSION['custom_consumer_key'] = trim($_GET['consumer_key']);
    $_SESSION['custom_consumer_secret'] = trim($_GET['consumer_secret']);
    header('Location: tumblr_oauth_helper.php');
    exit;
}

$consumerKey = $_SESSION['custom_consumer_key'] ?? '';
$consumerSecret = $_SESSION['custom_consumer_secret'] ?? '';

// Callback URL back to this script
$host = $_SERVER['HTTP_HOST'];
$callbackUrl = "http://" . $host . "/scratch/tumblr_oauth_helper.php";

echo "<div style='font-family: system-ui, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>";
echo "<h2 style='margin-top:0; color:#1e293b;'>Tumblr OAuth Setup Helper</h2>";

if (empty($consumerKey) || empty($consumerSecret)) {
    echo "<p style='color:#64748b; font-size:14px;'>Tumblr has suspended the default developer keys. You must register your own app to generate tokens.</p>";
    echo "<div style='background:#f8fafc; padding:16px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:20px;'>";
    echo "<h4 style='margin-top:0; margin-bottom:8px; color:#0f172a;'>How to get Tumblr Consumer Key & Secret:</h4>";
    echo "<ol style='font-size:13px; color:#475569; padding-left:20px; line-height:1.6;'>";
    echo "<li>Go to <a href='https://www.tumblr.com/oauth/apps' target='_blank' style='color:#3b82f6;'>tumblr.com/oauth/apps</a> and log in.</li>";
    echo "<li>Click <b>+ Register an application</b>.</li>";
    echo "<li>Fill the form: Application Name, Description, Website.</li>";
    echo "<li>Set <b>Default Callback URL</b> to exactly: <br><code style='background:#e2e8f0; padding:2px 6px; border-radius:4px; font-weight:bold; display:block; margin:6px 0;'>" . htmlspecialchars($callbackUrl) . "</code></li>";
    echo "<li>Save it, then copy your <b>OAuth Consumer Key</b> and <b>OAuth Consumer Secret</b> and paste them below:</li>";
    echo "</ol>";
    echo "</div>";

    echo "<form method='GET' style='display:flex; flex-direction:column; gap:12px;'>";
    echo "<div><label style='font-size:12px; font-weight:bold; color:#475569;'>OAuth Consumer Key (API Key):</label><input type='text' name='consumer_key' required style='width:100%; padding:10px; border-radius:6px; border:1px solid #cbd5e1; margin-top:4px;'></div>";
    echo "<div><label style='font-size:12px; font-weight:bold; color:#475569;'>OAuth Consumer Secret (Secret Key):</label><input type='text' name='consumer_secret' required style='width:100%; padding:10px; border-radius:6px; border:1px solid #cbd5e1; margin-top:4px;'></div>";
    echo "<button type='submit' style='background:#4f46e5; color:#fff; border:none; padding:12px; border-radius:8px; font-weight:bold; cursor:pointer; transition:background 0.2s;'>Set API Keys</button>";
    echo "</form>";
    echo "</div>";
    exit;
}

if (!isset($_GET['oauth_token'])) {
    // Step 1: Get Request Token
    $url = 'https://www.tumblr.com/oauth/request_token';
    $method = 'POST';
    
    $nonce = md5(uniqid(rand(), true));
    $timestamp = time();
    
    $oauthParams = [
        'oauth_callback' => $callbackUrl,
        'oauth_consumer_key' => $consumerKey,
        'oauth_nonce' => $nonce,
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => $timestamp,
        'oauth_version' => '1.0'
    ];
    
    ksort($oauthParams);
    
    $queryParts = [];
    foreach ($oauthParams as $key => $val) {
        $queryParts[] = rawurlencode($key) . '=' . rawurlencode($val);
    }
    $queryString = implode('&', $queryParts);
    
    $baseString = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($queryString);
    $signatureKey = rawurlencode($consumerSecret) . '&';
    $signature = base64_encode(hash_hmac('sha1', $baseString, $signatureKey, true));
    
    $oauthParams['oauth_signature'] = $signature;
    
    $headerParts = [];
    foreach ($oauthParams as $key => $val) {
        $headerParts[] = $key . '="' . rawurlencode($val) . '"';
    }
    $authHeader = 'Authorization: OAuth ' . implode(', ', $headerParts);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [$authHeader],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "<p style='color:#ef4444; font-weight:bold;'>Error getting request token (HTTP $httpCode): " . htmlspecialchars($response) . "</p>";
        echo "<p style='font-size:14px; color:#475569;'>Please verify your Consumer Key and Consumer Secret are correct.</p>";
        echo "<p><a href='?reset=1' style='color:#3b82f6; text-decoration:none; font-weight:bold;'>← Reset and Try Again</a></p>";
        echo "</div>";
        exit;
    }
    
    parse_str($response, $tokenData);
    if (!empty($tokenData['oauth_token']) && !empty($tokenData['oauth_token_secret'])) {
        $_SESSION['oauth_token_secret'] = $tokenData['oauth_token_secret'];
        $authUrl = "https://www.tumblr.com/oauth/authorize?oauth_token=" . $tokenData['oauth_token'];
        echo "<p style='color:#475569; font-size:14px;'>API keys registered successfully in session! Now authorize the application with your Tumblr account:</p>";
        echo "<a href='$authUrl' style='display:block; text-align:center; background:#4f46e5; color:#fff; padding:12px 24px; text-decoration:none; border-radius:8px; font-weight:bold; margin-top:20px; transition:background 0.2s;'>Authorize Application</a>";
        echo "<p style='text-align:center; margin-top:16px;'><a href='?reset=1' style='color:#94a3b8; font-size:13px; text-decoration:none;'>Reset Keys</a></p>";
    } else {
        echo "Failed to parse request token: " . htmlspecialchars($response);
    }
} else {
    // Step 2: Callback - exchange Request Token for Access Token
    $oauthToken = $_GET['oauth_token'];
    $oauthVerifier = $_GET['oauth_verifier'];
    $oauthTokenSecret = $_SESSION['oauth_token_secret'] ?? '';
    
    $url = 'https://www.tumblr.com/oauth/access_token';
    $method = 'POST';
    
    $nonce = md5(uniqid(rand(), true));
    $timestamp = time();
    
    $oauthParams = [
        'oauth_consumer_key' => $consumerKey,
        'oauth_nonce' => $nonce,
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => $timestamp,
        'oauth_token' => $oauthToken,
        'oauth_verifier' => $oauthVerifier,
        'oauth_version' => '1.0'
    ];
    
    ksort($oauthParams);
    
    $queryParts = [];
    foreach ($oauthParams as $key => $val) {
        $queryParts[] = rawurlencode($key) . '=' . rawurlencode($val);
    }
    $queryString = implode('&', $queryParts);
    
    $baseString = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($queryString);
    $signatureKey = rawurlencode($consumerSecret) . '&' . rawurlencode($oauthTokenSecret);
    $signature = base64_encode(hash_hmac('sha1', $baseString, $signatureKey, true));
    
    $oauthParams['oauth_signature'] = $signature;
    
    $headerParts = [];
    foreach ($oauthParams as $key => $val) {
        $headerParts[] = $key . '="' . rawurlencode($val) . '"';
    }
    $authHeader = 'Authorization: OAuth ' . implode(', ', $headerParts);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [$authHeader],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "<p style='color:#ef4444; font-weight:bold;'>Error getting access token (HTTP $httpCode): " . htmlspecialchars($response) . "</p>";
        echo "<p><a href='?reset=1' style='color:#3b82f6; text-decoration:none;'>← Start Over</a></p>";
        echo "</div>";
        exit;
    }
    
    parse_str($response, $accessTokenData);
    if (!empty($accessTokenData['oauth_token']) && !empty($accessTokenData['oauth_token_secret'])) {
        echo "<h3 style='color:#10b981; margin-top:0;'>🎉 Authorization Successful!</h3>";
        echo "<p style='font-size:14px; color:#475569;'>Here are your Tumblr Access Tokens. Copy these keys and paste them into your Tumblr credentials form:</p>";
        
        echo "<div style='display:flex; flex-direction:column; gap:12px; margin-top:20px;'>";
        
        echo "<div>";
        echo "<label style='font-size:12px; font-weight:bold; color:#64748b;'>OAuth Consumer Key (API Key)</label>";
        echo "<div style='background:#f1f5f9; padding:10px; border-radius:6px; font-family:monospace; word-break:break-all; border:1px solid #e2e8f0; font-size:14px; margin-top:4px;'>" . htmlspecialchars($consumerKey) . "</div>";
        echo "</div>";

        echo "<div>";
        echo "<label style='font-size:12px; font-weight:bold; color:#64748b;'>OAuth Consumer Secret (Secret Key)</label>";
        echo "<div style='background:#f1f5f9; padding:10px; border-radius:6px; font-family:monospace; word-break:break-all; border:1px solid #e2e8f0; font-size:14px; margin-top:4px;'>" . htmlspecialchars($consumerSecret) . "</div>";
        echo "</div>";

        echo "<div>";
        echo "<label style='font-size:12px; font-weight:bold; color:#64748b;'>OAuth Token (Access Token)</label>";
        echo "<div style='background:#e0f2fe; color:#0369a1; padding:10px; border-radius:6px; font-family:monospace; word-break:break-all; border:1px solid #bae6fd; font-size:14px; margin-top:4px;'>" . htmlspecialchars($accessTokenData['oauth_token']) . "</div>";
        echo "</div>";

        echo "<div>";
        echo "<label style='font-size:12px; font-weight:bold; color:#64748b;'>OAuth Token Secret (Access Token Secret)</label>";
        echo "<div style='background:#e0f2fe; color:#0369a1; padding:10px; border-radius:6px; font-family:monospace; word-break:break-all; border:1px solid #bae6fd; font-size:14px; margin-top:4px;'>" . htmlspecialchars($accessTokenData['oauth_token_secret']) . "</div>";
        echo "</div>";
        
        echo "</div>";
        
        echo "<br><div style='margin-top:20px; font-size:14px;'><a href='../submission-manager.php' style='color:#3b82f6; text-decoration:none; font-weight:bold;'>← Go back to Project Manager</a> | <a href='?reset=1' style='color:#94a3b8; text-decoration:none;'>Clear Session</a></div>";
    } else {
        echo "Failed to parse access token: " . htmlspecialchars($response);
    }
}
echo "</div>";
?>
