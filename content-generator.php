<?php
require_once 'config.php';
require_once 'ai-content.php';
requireLogin();
$db = getDB();
$projectId = (int)($_GET['id'] ?? 0);
$isAjax = isset($_GET['ajax']);
$isRun  = isset($_GET['run']);

$stmt = $db->prepare("SELECT * FROM projects WHERE id=? AND user_id=?");
$stmt->execute([$projectId, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) { echo json_encode(['error' => 'Not found']); exit; }

$keyword = $project['target_keyword'];
$site    = $project['target_site'] ?: $project['website_url'];

// 80% AUTO: Generate 5 unique articles via ChatGPT
function generateAllArticles($keyword, $site) {
    global $project;
    $businessName = $project['business_name'] ?? '';
    
    $prompts = [
        [
            'title_hint' => 'Complete Guide',
            'prompt'     => "Write a comprehensive SEO blog post (600-800 words) about '{$keyword}'.
Structure with HTML tags:
- <h1> title with keyword
- <p> engaging introduction
- <h2> What is " . ucwords($keyword) . "?
- <h2> Why Learn " . ucwords($keyword) . " in 2024?
- <h2> Career Opportunities
- <h2> Best Training Institute
- <p> conclusion with link to {$link_placeholder}
Use keyword naturally 3-4 times. Make it unique and valuable.",
        ],
        [
            'title_hint' => 'Beginners Guide',
            'prompt'     => "Write a beginner-friendly blog post (500-700 words) about '{$keyword}' for someone who knows nothing about it.
Use HTML tags. Include:
- <h1> beginner-friendly title
- <h2> What You Will Learn
- <h2> Step by Step Process
- <h2> Tools You Need
- <h2> Where to Learn
- Mention {$link_placeholder} as the best resource
Make it conversational and encouraging.",
        ],
        [
            'title_hint' => 'Career Guide',
            'prompt'     => "Write a career-focused article (600 words) about jobs and salary after learning '{$keyword}'.
HTML format:
- <h1> career-focused title
- <h2> Job Roles Available
- <h2> Average Salary in India
- <h2> Top Companies Hiring
- <h2> How to Get Certified
- <h2> Best Institute: link to {$link_placeholder}
Include realistic salary figures and job titles.",
        ],
        [
            'title_hint' => 'Top 10 Tips',
            'prompt'     => "Write a 'Top 10 Tips' style article about '{$keyword}' (500-600 words).
HTML format:
- <h1> 'Top 10 Tips for {$keyword}' style title
- <p> introduction
- 10 numbered tips as <h3> headings with <p> explanations
- <p> conclusion mentioning {$link_placeholder}
Make tips practical and actionable.",
        ],
        [
            'title_hint' => 'FAQ Article',
            'prompt'     => "Write an FAQ-style article answering 8 common questions about '{$keyword}' (600 words).
HTML format:
- <h1> FAQ title
- <p> introduction
- 8 questions as <h2> tags
- <p> detailed answers
- Last answer should mention {$link_placeholder}
Questions should be what people actually search on Google.",
        ],
    ];

    $articles = [];
    foreach ($prompts as $p) {
        // Generate a random anchor text for this specific article
        $anchorText = getRandomAnchorText($keyword, $businessName, $site);
        $linkHTML = "<a href='{$site}'>{$anchorText}</a>";
        
        $promptText = str_replace('{$link_placeholder}', $linkHTML, $p['prompt']);
        $prompt = str_replace(['{$keyword}', '{$site}'], [$keyword, $site], $promptText);
        
        $ai     = generateWithAI($prompt);
        $content = $ai['text'] ?: generateAIContent($keyword, $site, 'blog', 'article', '', OPENAI_API_KEY)['content'];
        $source  = $ai['text'] ? $ai['source'] : 'Template';

        $articles[] = [
            'title'   => ucwords($keyword) . ' - ' . $p['title_hint'] . ' ' . date('Y'),
            'content' => $content,
            'source'  => $source,
        ];

        sleep(1); // Avoid API rate limit
    }
    return $articles;
}

// Handle run=1
if ($isRun) {
    $db->prepare("DELETE FROM content_queue WHERE project_id=?")->execute([$projectId]);
    $articles = generateAllArticles($keyword, $site);
    foreach ($articles as $art) {
        $db->prepare("INSERT INTO content_queue (project_id, title, article, status) VALUES (?,?,?,'draft')")
           ->execute([$projectId, $art['title'], $art['content']]);
    }
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Generated ' . count($articles) . ' unique articles via ChatGPT ✨']);
    exit;
}

// Handle approve/publish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_content'])) {
    $contentId = (int)$_POST['content_id'];
    $status = in_array($_POST['status'], ['approved', 'published']) ? $_POST['status'] : 'draft';
    $db->prepare("UPDATE content_queue SET status=? WHERE id=? AND project_id=?")->execute([$status, $contentId, $projectId]);
    echo json_encode(['success' => true]);
    exit;
}

// Handle regenerate single article
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate'])) {
    $contentId = (int)$_POST['content_id'];
    $prompt = "Write a completely new, unique SEO article about '{$keyword}'. 
Use HTML tags with h1, h2, h3, p, ul tags.
600-800 words. Include backlink to {$site}.
Make it 100% different from any previous article.";
    $prompt = str_replace(['{$keyword}', '{$site}'], [$keyword, $site], $prompt);
    $ai = generateWithAI($prompt);
    if ($ai['text']) {
        $db->prepare("UPDATE content_queue SET article=?, status='draft' WHERE id=? AND project_id=?")
           ->execute([$ai['text'], $contentId, $projectId]);
        echo json_encode(['success' => true, 'message' => 'Regenerated with ' . $ai['source'] . ' ✨']);
    } else {
        echo json_encode(['error' => 'ChatGPT API failed — add OpenAI key in API Keys page']);
    }
    exit;
}

// Auto-generate or auto-refresh drafts if older than 24 hours
$artCheck = $db->prepare("SELECT COUNT(*), MAX(created_at) FROM content_queue WHERE project_id=? AND status='draft'");
$artCheck->execute([$projectId]);
$artRow = $artCheck->fetch();
$artCount = (int)($artRow['COUNT(*)'] ?? 0);
$lastArtCreated = $artRow['MAX(created_at)'] ?? null;

if ($artCount == 0 || !$lastArtCreated || (time() - strtotime($lastArtCreated)) > 86400) {
    // Delete old drafts
    $db->prepare("DELETE FROM content_queue WHERE project_id=? AND status='draft'")->execute([$projectId]);
    
    // Target keywords fallback logic
    $keywordsList = [$keyword];
    $stmtKw = $db->prepare("SELECT keyword FROM keywords WHERE project_id=? AND status='Target' LIMIT 3");
    $stmtKw->execute([$projectId]);
    $kwRows = $stmtKw->fetchAll();
    if (!empty($kwRows)) {
        $keywordsList = array_column($kwRows, 'keyword');
    }
    
    foreach ($keywordsList as $kwItem) {
        $articles = generateAllArticles($kwItem, $site);
        foreach ($articles as $art) {
            $db->prepare("INSERT INTO content_queue (project_id, title, article, status) VALUES (?,?,?,'draft')")
               ->execute([$projectId, $art['title'], $art['content']]);
        }
    }
}

$articles = $db->prepare("SELECT * FROM content_queue WHERE project_id=? ORDER BY created_at DESC");
$articles->execute([$projectId]);
$articles = $articles->fetchAll();
?>

<?php if ($isAjax): ?>
<div class="alert alert-success">
  <i class="fas fa-robot me-2"></i>
  <strong>ChatGPT:</strong> <?= count($articles) ?> unique articles generated — every article is 100% different!
  <button class="btn btn-sm btn-outline-light ms-3" onclick="regenerateAll(this)">
    <i class="fas fa-sync me-1"></i>Regenerate All (New Content)
  </button>
</div>

<div class="row g-3">
<?php foreach ($articles as $art): ?>
  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 small"><?= clean(substr($art['title'], 0, 50)) ?>...</h6>
        <span class="badge <?= $art['status'] === 'published' ? 'bg-success' : ($art['status'] === 'approved' ? 'bg-primary' : 'bg-secondary') ?>">
          <?= ucfirst($art['status']) ?>
        </span>
      </div>
      <div class="card-body">
        <p class="small text-muted"><?= clean(substr(strip_tags($art['article']), 0, 120)) ?>...</p>
        <div class="d-flex gap-1 flex-wrap">
          <button class="btn btn-sm btn-outline-primary" onclick="viewArticle(<?= $art['id'] ?>)">
            <i class="fas fa-eye me-1"></i>View
          </button>
          <button class="btn btn-sm btn-outline-warning" onclick="regenerateOne(<?= $art['id'] ?>, this)">
            <i class="fas fa-sync me-1"></i>Regenerate
          </button>
          <button class="btn btn-sm btn-outline-success" onclick="copyArticle(<?= $art['id'] ?>)">
            <i class="fas fa-copy me-1"></i>Copy HTML
          </button>
          <?php if ($art['status'] === 'draft'): ?>
          <button class="btn btn-sm btn-success" onclick="updateContent(<?= $art['id'] ?>, 'approved', this)">
            <i class="fas fa-check me-1"></i>Approve
          </button>
          <?php elseif ($art['status'] === 'approved'): ?>
          <button class="btn btn-sm btn-primary" onclick="updateContent(<?= $art['id'] ?>, 'published', this)">
            <i class="fas fa-upload me-1"></i>Published
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Article Modal -->
<div class="modal fade" id="articleModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-robot me-2"></i>ChatGPT Generated Article</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3" id="articleTabs">
          <li class="nav-item"><a class="nav-link active" href="#" onclick="showArticleTab('preview', this)">Preview</a></li>
          <li class="nav-item"><a class="nav-link" href="#" onclick="showArticleTab('html', this)">HTML Code</a></li>
        </ul>
        <div id="previewTab" class="p-3 border rounded" style="max-height:500px;overflow-y:auto;"></div>
        <div id="htmlTab" style="display:none;">
          <textarea id="htmlContent" class="form-control" rows="15" readonly></textarea>
          <button class="btn btn-sm btn-outline-secondary mt-2" onclick="copyHtml()">
            <i class="fas fa-copy me-1"></i>Copy HTML
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$articlesJs = [];
foreach ($articles as $art) {
    $articlesJs[$art['id']] = ['title' => $art['title'], 'content' => $art['article']];
}
?>
<script>
const articles = <?= json_encode($articlesJs) ?>;
const PROJECT_ID = <?= $projectId ?>;

function viewArticle(id) {
  const art = articles[id];
  if (!art) return;
  document.getElementById('previewTab').innerHTML = art.content;
  document.getElementById('htmlContent').value = art.content;
  new bootstrap.Modal(document.getElementById('articleModal')).show();
}

function copyArticle(id) {
  const art = articles[id];
  if (!art) return;
  navigator.clipboard.writeText(art.content).then(() => alert('HTML copied!'));
}

function copyHtml() {
  const el = document.getElementById('htmlContent');
  el.select();
  navigator.clipboard.writeText(el.value).then(() => alert('Copied!'));
}

function showArticleTab(tab, el) {
  document.getElementById('previewTab').style.display = tab === 'preview' ? '' : 'none';
  document.getElementById('htmlTab').style.display    = tab === 'html'    ? '' : 'none';
  document.querySelectorAll('#articleTabs .nav-link').forEach(e => e.classList.remove('active'));
  el.classList.add('active');
  return false;
}

function updateContent(id, status, btn) {
  fetch('content-generator.php?id=' + PROJECT_ID, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'update_content=1&content_id=' + id + '&status=' + status
  }).then(r => r.json()).then(d => {
    if (d.success) { btn.textContent = '✓ ' + (status === 'approved' ? 'Approved' : 'Published'); btn.disabled = true; }
  });
}

function regenerateOne(id, btn) {
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  fetch('content-generator.php?id=' + PROJECT_ID, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'regenerate=1&content_id=' + id
  }).then(r => r.json()).then(d => {
    if (d.success) {
      btn.innerHTML = '<i class="fas fa-check"></i> Done';
      setTimeout(() => {
        if (typeof loadTab === 'function') loadTab('content');
        else location.reload();
      }, 1000);
    } else {
      btn.innerHTML = '<i class="fas fa-sync"></i> Retry';
      btn.disabled = false;
    }
  });
}

function regenerateAll(btn) {
  if (!confirm('Regenerate all 5 articles with fresh ChatGPT content?')) return;
  if (!btn) btn = event.target;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating...';
  fetch('content-generator.php?id=' + PROJECT_ID + '&run=1')
    .then(r => r.json())
    .then(d => {
      alert(d.message);
      if (typeof loadTab === 'function') loadTab('content');
      else location.reload();
    });
}
</script>
<?php endif; ?>
