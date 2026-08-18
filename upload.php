<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['backup'])) {
        move_uploaded_file($_FILES['backup']['tmp_name'], '/var/www/html/backup.sql');
        echo "Upload successful!";
    } else {
        echo "No file uploaded.";
    }
    exit;
}
?>
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="backup" required>
    <button type="submit">Upload Database Backup</button>
</form>
