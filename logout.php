<?php
require_once 'config.php';
session_destroy();
header('Location: ' . SITE_URL . '/index.php');
exit;
?>
