<?php
require_once 'config.php';
requireLogin();
$db = getDB();
$projectId = (int)($_GET['project_id'] ?? 0);

$project = $db->prepare("SELECT * FROM projects WHERE id=? AND user_id=?");
$project->execute([$projectId, $_SESSION['user_id']]);
$project = $project->fetch();
if (!$project) { echo json_encode(['error' => 'Project not found']); exit; }

// Check if post image exists
$postImage = $project['post_image'] ?? null;
if (!$postImage || !file_exists(__DIR__ . '/uploads/' . $postImage)) {
    echo json_encode(['error' => 'No image uploaded for this project. Upload an image first.']);
    exit;
}

// Check if logo exists
$logoExt  = @file_get_contents(__DIR__ . '/assets/logo_ext.txt');
$logoFile = $logoExt ? __DIR__ . '/assets/logo.' . trim($logoExt) : null;
if (!$logoFile || !file_exists($logoFile)) {
    echo json_encode(['error' => 'No logo uploaded. Upload logo first from the red section.']);
    exit;
}

if (!function_exists('imagecreatetruecolor')) {
    echo json_encode(['error' => 'GD extension not available. Restart Apache.']);
    exit;
}

// Load main image
$imgPath = __DIR__ . '/uploads/' . $postImage;
$info    = getimagesize($imgPath);
if (!$info) { echo json_encode(['error' => 'Cannot read image file.']); exit; }

$src = null;
switch ($info[2]) {
    case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($imgPath); break;
    case IMAGETYPE_PNG:  $src = imagecreatefrompng($imgPath);  break;
    case IMAGETYPE_WEBP: $src = imagecreatefromwebp($imgPath); break;
    case IMAGETYPE_GIF:  $src = imagecreatefromgif($imgPath);  break;
}
if (!$src) { echo json_encode(['error' => 'Cannot load image.']); exit; }

$w = imagesx($src);
$h = imagesy($src);

// Load logo
$ext     = strtolower(trim($logoExt));
$logoImg = null;
switch ($ext) {
    case 'png':  $logoImg = @imagecreatefrompng($logoFile);  break;
    case 'jpg':
    case 'jpeg': $logoImg = @imagecreatefromjpeg($logoFile); break;
    case 'webp': $logoImg = @imagecreatefromwebp($logoFile); break;
    case 'gif':  $logoImg = @imagecreatefromgif($logoFile);  break;
}
if (!$logoImg) { echo json_encode(['error' => 'Cannot load logo.']); exit; }

$lw = imagesx($logoImg);
$lh = imagesy($logoImg);

// Scale logo — max 20% of image width
$maxLogoW = (int)($w * 0.22);
$maxLogoH = (int)($h * 0.12);
$scale    = min($maxLogoW / $lw, $maxLogoH / $lh);
$newLW    = (int)($lw * $scale);
$newLH    = (int)($lh * $scale);

// Position: top-right with padding
$padding = (int)($w * 0.02);
$logoX   = $w - $newLW - $padding;
$logoY   = $padding;

// White background box for logo
$white = imagecolorallocate($src, 255, 255, 255);
imagefilledrectangle($src, $logoX - 8, $logoY - 8, $logoX + $newLW + 8, $logoY + $newLH + 8, $white);

// Copy logo onto image
imagecopyresampled($src, $logoImg, $logoX, $logoY, 0, 0, $newLW, $newLH, $lw, $lh);
imagedestroy($logoImg);

// Save as new file
$newFilename = 'logo_added_' . $projectId . '_' . time() . '.jpg';
$newPath     = __DIR__ . '/uploads/' . $newFilename;
imagejpeg($src, $newPath, 92);
imagedestroy($src);

// Update project post_image
$db->prepare("UPDATE projects SET post_image=? WHERE id=?")->execute([$newFilename, $projectId]);

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'image'   => SITE_URL . '/uploads/' . $newFilename . '?t=' . time(),
    'message' => 'Logo added successfully!'
]);
?>
