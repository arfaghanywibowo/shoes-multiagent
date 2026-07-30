<?php
/**
 * Local Development Router
 * Run: php -S localhost:8000 router.php
 * 
 * This routes /api/* requests to api/index.php
 * and serves static files normally.
 */

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Route /api/* to api/index.php
if (strpos($path, '/api') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Serve static files
$filePath = __DIR__ . $path;
if ($path !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false; // Let PHP built-in server handle static files
}

// Default to index.html
if ($path === '/' || !file_exists($filePath)) {
    readfile(__DIR__ . '/index.html');
    return true;
}

return false;
