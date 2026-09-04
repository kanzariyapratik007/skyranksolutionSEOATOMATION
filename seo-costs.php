<?php
require_once 'config.php';
requireMenuPermission('cost-ranking');

?>
<!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SEO Cost & Google Ranking Guide</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container py-4" style="max-width:900px;">

  <h3><i class="fas fa-rupee-sign me-2 text-success"></i>Cost + Google Top Ranking — The Truth</h3>

  <div class="alert alert-warning">
    <strong>The Truth:</strong> No software can guarantee "Google #1 in one click".
    Meta tags + backlinks + content + time (2-3 months) are required. This project does 80% of the work automatically — 20% you need to apply on the website.
  </div>

  <div class="card mb-4">
    <div class="card-header bg-success text-white"><h5 class="mb-0">💰 Monthly Cost (India — Estimate)</h5></div>
    <div class="card-body table-responsive">
      <table class="table table-bordered mb-0">
        <thead class="table-light">
          <tr><th>Item</th><th>Cost</th><th>Deliverables</th><th>Required?</th></tr>
        </thead>
        <tbody>
          <tr class="table-success">
            <td><strong>XAMPP / Hosting</strong></td>
            <td>₹0 – ₹500/mo</td>
            <td>Local free; live site Hostinger ~₹149/mo</td>
            <td>✅ Yes</td>
          </tr>
          <tr class="table-success">
            <td><strong>OpenAI (ChatGPT)</strong></td>
            <td><strong>₹400 – ₹2000/mo</strong></td>
            <td>Meta tags, articles, social content</td>
            <td>✅ Yes</td>
          </tr>
          <tr>
            <td>DataForSEO</td>
            <td>₹0 – ₹800/mo</td>
            <td>Real Google rank check (~100 free/day)</td>
            <td>Low optional</td>
          </tr>
          <tr>
            <td>OpenAI (ChatGPT)</td>
            <td>₹400 – ₹2000/mo</td>
            <td>Primary AI for all content generation</td>
            <td>✅ Yes</td>
          </tr>
          <tr>
            <td>Google Ads</td>
            <td>₹5000+ /mo</td>
            <td>Instant traffic — SEO is different</td>
            <td>optional</td>
          </tr>
          <tr class="table-primary">
            <td><strong>Minimum real SEO</strong></td>
            <td><strong>₹400 – ₹500/mo</strong></td>
            <td>ChatGPT + hosting</td>
            <td>—</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header bg-primary text-white"><h5 class="mb-0">🏷️ Meta Tags — What to change?</h5></div>
    <div class="card-body">
      <p>Your <strong>actual website</strong> (learnmoretechnologies.in etc.) needs all of this:</p>
      <ol>
        <li><code>&lt;title&gt;</code> — keyword + brand (30–60 chars)</li>
        <li><code>&lt;meta name="description"&gt;</code> — 150 chars, keyword + phone/CTA</li>
        <li><code>og:title</code>, <code>og:description</code>, <code>og:image</code> — social share</li>
        <li><code>&lt;link rel="canonical"&gt;</code> — duplicate URL avoid</li>
        <li><code>Schema JSON-LD</code> — Google rich results</li>
        <li><code>&lt;h1&gt;</code> — only one per page, with keyword</li>
      </ol>
      <p class="mb-0">
        <strong>This project:</strong> Run SEO → <strong>Meta Tags</strong> tab → Copy HTML → Paste into your website's <code>&lt;head&gt;</code>.
        If WordPress: Fill in Title + Description in Yoast SEO / Rank Math.
      </p>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">📈 To rank Top on Google — Real steps (2–3 months)</h5></div>
    <div class="card-body">
      <table class="table">
        <tr><td width="40"><strong>1</strong></td><td>Meta + On-Page fix (this system) — score 70+</td></tr>
        <tr><td><strong>2</strong></td><td>Add site in Google Search Console — <a href="https://search.google.com/search-console" target="_blank">search.google.com/search-console</a> (FREE)</td></tr>
        <tr><td><strong>3</strong></td><td>Submissions — Blogger, Dev.to, backlinks (this system)</td></tr>
        <tr><td><strong>4</strong></td><td>Weekly content — 2 blogs/month with keywords</td></tr>
        <tr><td><strong>5</strong></td><td>Rank Tracker — Monitor progress (DataForSEO optional)</td></tr>
      </table>
      <p class="text-muted small mb-0">If competition is high (like "power bi training"), it can take 3-6 months. Local keywords rank faster.</p>
    </div>
  </div>

  <a href="api-setup.php" class="btn btn-primary me-2">API Keys Setup</a>
  <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
</div>
<?php include 'includes/footer.php'; ?>
</body>
</html>
