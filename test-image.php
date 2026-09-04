<?php
require_once 'config.php';
requireLogin();

$result = null;
$imageUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey  = clean($_POST['openai_key'] ?? '');
    $prompt  = clean($_POST['prompt'] ?? 'Professional IT training marketing poster Power BI Training BTM dark blue background Indian professional laptop yellow headline 4K');
    $source  = $_POST['source'] ?? 'dalle3';

    if ($source === 'dalle3' && !empty($apiKey)) {
        // DALL-E 3
        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'   => 'dall-e-3',
                'prompt'  => $prompt,
                'n'       => 1,
                'size'    => '1024x1024',
                'quality' => 'standard',
            ]),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (isset($response['data'][0]['url'])) {
            $imageUrl = $response['data'][0]['url'];
            $result   = ['success' => true, 'source' => 'DALL-E 3'];
        } else {
            $result = ['error' => $response['error']['message'] ?? json_encode($response)];
        }

    } elseif ($source === 'stability') {
        // Stability AI
        $key = defined('STABILITY_API_KEY') ? STABILITY_API_KEY : '';
        $ch  = curl_init('https://api.stability.ai/v2beta/stable-image/generate/ultra');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['prompt' => $prompt, 'output_format' => 'jpeg'],
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Accept: image/*'],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code === 200 && strpos($type, 'image') !== false && strlen($data) > 5000) {
            $fname = 'test_stab_' . time() . '.jpg';
            file_put_contents(__DIR__ . '/uploads/' . $fname, $data);
            $imageUrl = SITE_URL . '/uploads/' . $fname;
            $result   = ['success' => true, 'source' => 'Stability AI Ultra'];
        } else {
            $result = ['error' => 'HTTP ' . $code . ' - ' . substr($data, 0, 200)];
        }

    } elseif ($source === 'flux') {
        // HuggingFace Dreamshaper-8 (working model)
        $key = defined('HUGGINGFACE_API_KEY') ? HUGGINGFACE_API_KEY : '';
        $ch  = curl_init('https://api-inference.huggingface.co/models/Lykon/dreamshaper-8');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['inputs' => $prompt]),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code === 200 && strpos($type, 'image') !== false && strlen($data) > 5000) {
            $fname = 'test_flux_' . time() . '.jpg';
            file_put_contents(__DIR__ . '/uploads/' . $fname, $data);
            $imageUrl = SITE_URL . '/uploads/' . $fname;
            $result   = ['success' => true, 'source' => 'HuggingFace FLUX.1'];
        } else {
            $result = ['error' => 'HTTP ' . $code . ' - ' . substr($data, 0, 200)];
        }

    } elseif ($source === 'pollinations') {
        // Pollinations - no key
        $encoded  = urlencode($prompt . ', professional marketing poster, 4K');
        $imageUrl = "https://image.pollinations.ai/prompt/{$encoded}?width=1024&height=1024&nologo=true&seed=" . rand(1, 99999) . "&model=flux";
        $result   = ['success' => true, 'source' => 'Pollinations AI (Free)'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Image Generation Test</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'includes/navbar.php'; ?>
<div class="container py-4" style="max-width:900px;">
  <h3><i class="fas fa-image me-2 text-primary"></i>Image Generation Test</h3>
  <p class="text-muted">Test different AI image generation APIs</p>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="POST">
        <div class="mb-3">
          <label class="form-label fw-bold">Image Prompt</label>
          <textarea name="prompt" class="form-control" rows="3"><?= isset($_POST['prompt']) ? clean($_POST['prompt']) : 'Professional IT training marketing poster for Power BI Training in BTM Bangalore. Dark blue gradient background. Young smiling Indian professional with laptop. Tech icons floating. Yellow gold bold headline. Checkmarks benefits list. Corporate modern design. High quality 4K' ?></textarea>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Select AI Source</label>
          <div class="row g-2">
            <div class="col-md-3">
              <div class="form-check border rounded p-3">
                <input class="form-check-input" type="radio" name="source" value="dalle3" id="dalle3" <?= ($_POST['source']??'dalle3')==='dalle3'?'checked':'' ?>>
                <label class="form-check-label fw-bold" for="dalle3">
                  <i class="fas fa-robot text-success me-1"></i>DALL-E 3<br>
                  <small class="text-muted">ChatGPT quality<br>$0.04/image</small>
                </label>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-check border rounded p-3">
                <input class="form-check-input" type="radio" name="source" value="stability" id="stab" <?= ($_POST['source']??'')==='stability'?'checked':'' ?>>
                <label class="form-check-label fw-bold" for="stab">
                  <i class="fas fa-star text-warning me-1"></i>Stability AI<br>
                  <small class="text-muted">25 free/day<br>Key: configured ✅</small>
                </label>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-check border rounded p-3">
                <input class="form-check-input" type="radio" name="source" value="flux" id="flux" <?= ($_POST['source']??'')==='flux'?'checked':'' ?>>
                <label class="form-check-label fw-bold" for="flux">
                  <i class="fas fa-bolt text-primary me-1"></i>FLUX.1<br>
                  <small class="text-muted">Free<br>Key: configured ✅</small>
                </label>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-check border rounded p-3">
                <input class="form-check-input" type="radio" name="source" value="pollinations" id="poll" <?= ($_POST['source']??'')==='pollinations'?'checked':'' ?>>
                <label class="form-check-label fw-bold" for="poll">
                  <i class="fas fa-leaf text-success me-1"></i>Pollinations<br>
                  <small class="text-muted">100% Free<br>No key needed</small>
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="mb-3" id="openaiKeyDiv" style="<?= ($_POST['source']??'dalle3')==='dalle3'?'':'display:none' ?>">
          <label class="form-label fw-bold">OpenAI API Key (for DALL-E 3)</label>
          <input type="text" name="openai_key" class="form-control" placeholder="sk-..." value="<?= clean($_POST['openai_key'] ?? '') ?>">
          <small class="text-muted">Get from: <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a></small>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">
          <i class="fas fa-magic me-2"></i>Generate Image
        </button>
      </form>
    </div>
  </div>

  <?php if ($result): ?>
  <div class="card shadow-sm">
    <div class="card-header <?= isset($result['success']) ? 'bg-success' : 'bg-danger' ?> text-white">
      <h5 class="mb-0">
        <?php if (isset($result['success'])): ?>
          ✅ Success — Generated by <?= $result['source'] ?>
        <?php else: ?>
          ❌ Error: <?= clean($result['error']) ?>
        <?php endif; ?>
      </h5>
    </div>
    <?php if ($imageUrl): ?>
    <div class="card-body text-center">
      <img src="<?= $imageUrl ?>" class="img-fluid rounded shadow" style="max-height:600px;" alt="Generated Image">
      <div class="mt-3">
        <a href="<?= $imageUrl ?>" download class="btn btn-success me-2">
          <i class="fas fa-download me-1"></i>Download
        </a>
        <a href="submission-manager.php" class="btn btn-primary">
          <i class="fas fa-paper-plane me-1"></i>Use for Auto Post
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('input[name="source"]').forEach(r => {
  r.addEventListener('change', () => {
    document.getElementById('openaiKeyDiv').style.display = r.value === 'dalle3' ? '' : 'none';
  });
});
</script>
</body>
</html>
