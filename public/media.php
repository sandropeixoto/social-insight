<?php

require_once __DIR__ . '/../bootstrap.php';

$relativePath = $_GET['path'] ?? '';

if (!is_string($relativePath) || $relativePath === '' || str_contains($relativePath, "..")) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$root = defined('MEDIA_STORAGE_PATH') ? MEDIA_STORAGE_PATH : (__DIR__ . '/../data/media');
$rootReal = realpath($root) ?: $root;
$requested = realpath($root . DIRECTORY_SEPARATOR . $relativePath);

if ($requested === false || !str_starts_with($requested, $rootReal)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

if (!is_file($requested) || !is_readable($requested)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$mime = mime_content_type($requested) ?: 'application/octet-stream';
$basename = basename($requested);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($requested));
header('Content-Disposition: inline; filename="' . addslashes($basename) . '"');

readfile($requested);
