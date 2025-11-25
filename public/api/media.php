<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

require_once __DIR__ . '/../../bootstrap.php';

if (!defined('MEDIA_STORAGE_PATH') || !is_dir(MEDIA_STORAGE_PATH)) {
    http_response_code(503);
    echo 'Media storage is not configured or available.';
    exit;
}

$file = $_GET['file'] ?? null;

if (!is_string($file) || $file === '') {
    http_response_code(400);
    echo 'File not specified.';
    exit;
}

// Basic security: prevent directory traversal.
if (str_contains($file, '..') || str_contains($file, '/') || str_contains($file, '\\')) {
    http_response_code(400);
    echo 'Invalid file path.';
    exit;
}

$path = MEDIA_STORAGE_PATH . DIRECTORY_SEPARATOR . $file;

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$mime = mime_content_type($path);

if ($mime === false) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year.
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

readfile($path);
exit;
