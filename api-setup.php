<?php
require_once 'config.php';
requireMenuPermission('api-keys');

$saved = false;
$error = '';

// AJAX live test endpoint for OpenAI / Gemini
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_ai_key'])) {
    header('Content-Type: application/json');
    $provider = $_POST['provider'] ?? 'openai';
    $key = trim($_POST['api_key'] ?? '');
    
    if (empty($key)) {
        echo json_encode(['success' => false, 'error' => 'Please enter an API key to test.']);
        exit;
    }
    
    if ($provider === 'openai') {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'Say test ok']],
                'max_tokens' => 5
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($res, true);
        
        if ($code === 200) {
            echo json_encode(['success' => true, 'message' => '✅ OpenAI API Key is VALID and working!']);
        } else {
            $msg = $json['error']['message'] ?? "HTTP $code Error";
            echo json_encode(['success' => false, 'error' => '❌ OpenAI Error: ' . $msg]);
        }
        exit;
    } elseif ($provider === 'gemini') {
        $ch = curl_init("https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $key);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [['parts' => [['text' => 'test']]]]
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($res, true);
        
        if ($code === 200) {
            echo json_encode(['success' => true, 'message' => '✅ Gemini API Key is VALID and working!']);
        } else {
            $msg = $json['error']['message'] ?? "HTTP $code Error";
            echo json_encode(['success' => false, 'error' => '❌ Gemini Error: ' . $msg]);
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_keys'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $keys = [
            'OPENAI_API_KEY'       => trim($_POST['openai'] ?? ''),
            'OPENAI_MODEL'         => trim($_POST['openai_model'] ?? 'gpt-4o-mini'),
            'OPENAI_IMAGE_API_KEY' => trim($_POST['openai_image'] ?? ''),
            'GEMINI_API_KEY'       => trim($_POST['gemini'] ?? ''),
            'DATAFORSEO_LOGIN'     => trim($_POST['dataforseo_login'] ?? ''),
            'DATAFORSEO_PASSWORD'  => trim($_POST['dataforseo_password'] ?? ''),
            'GOOGLE_API_KEY'       => trim($_POST['google'] ?? ''),
            'STABILITY_API_KEY'    => trim($_POST['stability'] ?? ''),
            'HUGGINGFACE_API_KEY'  => trim($_POST['huggingface'] ?? ''),
            'SMTP_USER'            => trim($_POST['smtp_user'] ?? ''),
            'SMTP_PASS'            => trim($_POST['smtp_pass'] ?? ''),
            'ENABLE_TIER2_POSTING' => isset($_POST['enable_tier2']),
        ];
        $existing = is_readable(__DIR__ . '/config.local.php')
            ? (array) include __DIR__ . '/config.local.php'
            : [];
        $merged = array_merge($existing, $keys);

        $export = "<?php\n// Auto-saved from API Setup — " . date('Y-m-d H:i') . "\nreturn " . var_export($merged, true) . ";\n";
        if (file_put_contents(__DIR__ . '/config.local.php', $export) !== false) {
            $saved = true;
            setFlash('success', 'API keys saved to config.local.php.');
            header('Location: api-setup.php');
            exit;
        }
        $error = 'Could not write config.local.php. Check file permissions.';
    }
}

$local = is_readable(__DIR__ . '/config.local.php') ? (array) include __DIR__ . '/config.local.php' : [];
$flash = getFlash();

function maskKey($v) {
    if (!$v) return '';
    $len = strlen($v);
    if ($len <= 8) return str_repeat('*', $len);
    return substr($v, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($v, -4);
}
?>
<!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API Keys Setup - SEO 80/20</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container py-4" style="max-width:900px;">

  <h3><i class="fas fa-key me-2 text-primary"></i>API Keys Setup</h3>
  <p class="text-muted">Save your API keys here. The system will use them for AI content, rank tracking, and images.</p>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-danger"><?= clean($error) ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card h-100 border-success">
        <div class="card-body">
          <h6 class="text-success">✅ Required (At least one AI Key)</h6>
          <p class="small mb-0"><strong>ChatGPT (OpenAI)</strong> or <strong>Gemini (Google)</strong> — Content, articles, meta tags, social posts.</p>
          <div class="mt-2">
            <span class="badge <?= hasChatGPT() ? 'bg-success' : 'bg-danger' ?> me-1">
              OpenAI: <?= hasChatGPT() ? 'Configured' : 'Missing' ?>
            </span>
            <span class="badge <?= hasGemini() ? 'bg-success' : 'bg-danger' ?>">
              Gemini: <?= hasGemini() ? 'Configured' : 'Missing' ?>
            </span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 border-primary">
        <div class="card-body">
          <h6 class="text-primary">📊 Rank Tracking</h6>
          <p class="small mb-0"><strong>DataForSEO</strong> — Real Google rank (100 free/day).</p>
          <span class="badge <?= hasApiKey('DATAFORSEO_LOGIN') ? 'bg-success' : 'bg-secondary' ?> mt-2">
            <?= hasApiKey('DATAFORSEO_LOGIN') ? 'Configured' : 'Optional' ?>
          </span>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 border-warning">
        <div class="card-body">
          <h6 class="text-warning">🔌 Social Auto-Post</h6>
          <p class="small mb-0">WordPress, Blogger, Bluesky, Dev.to — keys <strong>Submissions</strong> for each platform.</p>
        </div>
      </div>
    </div>
  </div>

  <form method="POST" class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white"><h5 class="mb-0">Save API Keys</h5></div>
    <div class="card-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="save_keys" value="1">

      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label fw-bold mb-0">OpenAI / ChatGPT API Key <span class="text-danger">*</span></label>
          <button type="button" class="btn btn-outline-success btn-sm" onclick="testKey('openai')">
            <i class="fas fa-plug me-1"></i>Test OpenAI Key
          </button>
        </div>
        <input type="text" id="input_openai" name="openai" class="form-control font-monospace" placeholder="sk-..."
               value="<?= clean($local['OPENAI_API_KEY'] ?? (OPENAI_API_KEY === 'your-openai-api-key' ? '' : OPENAI_API_KEY)) ?>">
        <div id="status_openai" class="mt-1 small"></div>
        <div class="form-text">
          <strong>How to get:</strong> <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a>
          → Login → <strong>Create new secret key</strong> → copy <code>sk-...</code> → paste here.
        </div>
      </div>

      <?php $currentModel = $local['OPENAI_MODEL'] ?? (defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini'); ?>
      <div class="mb-3">
        <label class="form-label fw-bold">OpenAI Model <span class="text-success">(Cost Optimization)</span></label>
        <select name="openai_model" class="form-select font-monospace">
          <option value="gpt-4o-mini" <?= $currentModel === 'gpt-4o-mini' ? 'selected' : '' ?>>gpt-4o-mini (Recommended - Super Cheap & Fast)</option>
          <option value="gpt-4o" <?= $currentModel === 'gpt-4o' ? 'selected' : '' ?>>gpt-4o (High Quality - More Expensive)</option>
          <option value="gpt-3.5-turbo" <?= $currentModel === 'gpt-3.5-turbo' ? 'selected' : '' ?>>gpt-3.5-turbo (Legacy Standard)</option>
        </select>
        <div class="form-text">
          <code>gpt-4o-mini</code> is ~15x cheaper than <code>gpt-4o</code> while producing high quality SEO articles.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-bold">OpenAI Image API Key <span class="text-secondary">(DALL-E 3 only — Optional)</span></label>
        <input type="text" name="openai_image" class="form-control font-monospace" placeholder="sk-..."
               value="<?= clean($local['OPENAI_IMAGE_API_KEY'] ?? (defined('OPENAI_IMAGE_API_KEY') && OPENAI_IMAGE_API_KEY !== 'your-openai-image-api-key' ? OPENAI_IMAGE_API_KEY : '')) ?>">
        <div class="form-text">
          Use a separate OpenAI key strictly for image generation if you want to isolate DALL-E costs. If empty, falls back to the main OpenAI Key.
        </div>
      </div>

      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label fw-bold mb-0">Google Gemini API Key <span class="text-secondary">(Free Alternative / Backup)</span></label>
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="testKey('gemini')">
            <i class="fas fa-plug me-1"></i>Test Gemini Key
          </button>
        </div>
        <input type="text" id="input_gemini" name="gemini" class="form-control font-monospace" placeholder="AIzaSy..."
               value="<?= clean($local['GEMINI_API_KEY'] ?? (GEMINI_API_KEY === 'your-gemini-api-key' ? '' : GEMINI_API_KEY)) ?>">
        <div id="status_gemini" class="mt-1 small"></div>
        <div class="form-text">
          <strong>How to get:</strong> <a href="https://aistudio.google.com/" target="_blank">aistudio.google.com</a>
          → Login → <strong>Get API key</strong> → copy key → paste here.
        </div>
      </div>

      <hr>
      <h6>DataForSEO — Real Google Rank</h6>
      <div class="row g-2 mb-3">
        <div class="col-md-6">
          <label class="form-label">Login (email)</label>
          <input type="text" name="dataforseo_login" class="form-control"
                 value="<?= clean($local['DATAFORSEO_LOGIN'] ?? DATAFORSEO_LOGIN) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">API Password</label>
          <input type="text" name="dataforseo_password" class="form-control"
                 value="<?= clean($local['DATAFORSEO_PASSWORD'] ?? DATAFORSEO_PASSWORD) ?>">
        </div>
      </div>
      <p class="form-text">
        <a href="https://app.dataforseo.com/register" target="_blank">app.dataforseo.com</a>
        → Register (FREE $1 credit, ~100 rank checks/day) → API Access → copy Login + API Password.
      </p>

      <div class="mb-3">
        <label class="form-label fw-bold">Google API Key (PageSpeed — optional)</label>
        <input type="text" name="google" class="form-control"
               value="<?= clean($local['GOOGLE_API_KEY'] ?? GOOGLE_API_KEY) ?>">
        <div class="form-text">
          <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>
          → Create project → APIs → PageSpeed Insights API → Credentials → API key.
        </div>
      </div>

      <details class="mb-3">
        <summary class="fw-bold">Image APIs (optional)</summary>
        <div class="mt-2 mb-2">
          <label class="form-label">Stability AI</label>
          <input type="text" name="stability" class="form-control" value="<?= clean($local['STABILITY_API_KEY'] ?? '') ?>">
          <small class="text-muted"><a href="https://platform.stability.ai/account/keys" target="_blank">platform.stability.ai</a> — 25 free images/day</small>
        </div>
        <div class="mb-2">
          <label class="form-label">Hugging Face</label>
          <input type="text" name="huggingface" class="form-control" value="<?= clean($local['HUGGINGFACE_API_KEY'] ?? '') ?>">
          <small class="text-muted"><a href="https://huggingface.co/settings/tokens" target="_blank">huggingface.co</a> → New token (Read)</small>
        </div>
      </details>

      <details class="mb-3">
        <summary class="fw-bold">SMTP Email Setup (Optional)</summary>
        <div class="mt-2 mb-2">
          <label class="form-label">SMTP Username (Email)</label>
          <input type="text" name="smtp_user" class="form-control" placeholder="e.g. your-email@gmail.com"
                 value="<?= clean($local['SMTP_USER'] ?? (SMTP_USER === 'your-smtp-username' ? '' : SMTP_USER)) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label">SMTP Password / App Password</label>
          <input type="password" name="smtp_pass" class="form-control" placeholder="••••••••"
                 value="<?= clean($local['SMTP_PASS'] ?? (SMTP_PASS === 'your-smtp-password' ? '' : SMTP_PASS)) ?>">
          <small class="text-muted">For Gmail: Go to Google Account → Security → 2-Step Verification → App passwords → Generate 16-character password.</small>
        </div>
      </details>

      <!-- Queue & Promotion Settings -->
      <div class="card mb-4 border-info shadow-sm mt-4">
        <div class="card-header bg-info text-dark fw-bold">
          <i class="fas fa-sitemap me-2"></i>Queue & Tier 2 Auto-Posting Settings
        </div>
        <div class="card-body">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="enable_tier2" id="enableTier2" 
                   <?= (isset($local['ENABLE_TIER2_POSTING']) ? (bool)$local['ENABLE_TIER2_POSTING'] : ENABLE_TIER2_POSTING) ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="enableTier2">Enable Tier 2 Backlink Auto-Posting</label>
          </div>
          <div class="form-text text-muted">
            When enabled, successfully creating a Tier 1 post (Blogger, Dev.to, GitHub, etc.) will automatically queue promotional posts (micro-blog shares on Bluesky, Tumblr, Symbaloo, Pearltrees) pointing to that Tier 1 backlink. 
            <strong>Disable this to save ChatGPT / Gemini API token costs.</strong>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-lg">
        <i class="fas fa-save me-2"></i>Save All Keys
      </button>
    </div>
  </form>

  <div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">📱 Social Platforms — keys on Submissions</h5></div>
    <div class="card-body table-responsive">
      <table class="table table-sm">
        <thead><tr><th>Platform</th><th>What is needed</th><th>Where to find</th></tr></thead>
        <tbody>
          <tr><td><strong>Bluesky</strong></td><td>Username + App Password</td><td>bsky.app → Settings → App Passwords</td></tr>
          <tr><td><strong>Blogger</strong></td><td>OAuth Access Token + Blog ID</td><td><a href="https://developers.google.com/oauthplayground" target="_blank">OAuth Playground</a> → Blogger API v3</td></tr>
          <tr><td><strong>WordPress.com</strong></td><td>Bearer token</td><td><a href="https://developer.wordpress.com/apps/" target="_blank">developer.wordpress.com/apps</a></td></tr>
          <tr><td><strong>GitHub</strong></td><td>Personal Access Token</td><td>github.com → Settings → Developer settings → Tokens</td></tr>
          <tr><td><strong>Dev.to</strong></td><td>API key</td><td>dev.to → Settings → Extensions</td></tr>
          <tr><td><strong>Hashnode</strong></td><td>API key + Publication ID</td><td>hashnode.com → Settings → Developer</td></tr>
          <tr><td><strong>Tumblr</strong></td><td>OAuth token + blog name</td><td>tumblr.com/oauth/apps</td></tr>
          <tr><td><strong>Pinterest</strong></td><td>Access token (write)</td><td>developers.pinterest.com</td></tr>
          <tr><td><strong>Minds / Medium</strong></td><td>❌ No API</td><td>ChatGPT content copy-paste manually</td></tr>
        </tbody>
      </table>
      <a href="submission-manager.php" class="btn btn-outline-primary">Go to Submissions →</a>
</div>
<script>
function testKey(provider) {
  const inputEl = document.getElementById('input_' + provider);
  const statusEl = document.getElementById('status_' + provider);
  const key = inputEl ? inputEl.value.trim() : '';

  if (!key) {
    statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Please enter an API key first.</span>';
    return;
  }

  statusEl.innerHTML = '<span class="text-primary"><i class="fas fa-spinner fa-spin me-1"></i>Testing API key connection...</span>';

  const fd = new FormData();
  fd.append('test_ai_key', '1');
  fd.append('provider', provider);
  fd.append('api_key', key);

  fetch('api-setup.php', {
    method: 'POST',
    body: fd
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      statusEl.innerHTML = '<span class="text-success fw-bold">' + data.message + '</span>';
    } else {
      statusEl.innerHTML = '<span class="text-danger fw-bold">' + data.error + '</span>';
    }
  })
  .catch(err => {
    statusEl.innerHTML = '<span class="text-danger fw-bold"><i class="fas fa-times-circle me-1"></i>Test failed: ' + err + '</span>';
  });
}
</script>
<?php include 'includes/footer.php'; ?>
</body>
</html>
