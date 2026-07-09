<?php
/**
 * PHP Built-in Server Router
 * Usage (from inside restaurant-pos folder):
 *   php -S localhost:8000 router.php
 * Then visit: http://localhost:8000/
 */

// Fix DOCUMENT_ROOT for built-in server - it sometimes sets it to cwd
$_SERVER['DOCUMENT_ROOT'] = __DIR__;

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve existing static files (css, js, images, fonts) directly
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    $ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    $mime = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'svg'  => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
    ];
    if (isset($mime[$ext])) {
        header('Content-Type: ' . $mime[$ext]);
        readfile(__DIR__ . $uri);
        return true;
    }
    return false; // Let PHP handle .php files
}

// Root → POS counter
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

// Route all .php requests
if (substr($uri, -4) === '.php') {
    $file = __DIR__ . $uri;
    if (file_exists($file)) {
        // Block direct access to includes/ and data/
        if (strpos($uri, '/includes/') === 0 || strpos($uri, '/data/') === 0) {
            http_response_code(403);
            echo '403 Forbidden';
            return true;
        }
        require $file;
        return true;
    }
}

// 404
http_response_code(404);
echo '<!DOCTYPE html><html><head><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f6fa}</style></head>';
echo '<body><div style="text-align:center"><h2>404 — Page not found</h2><a href="/" style="color:#e85d26">← Back to POS Counter</a></div></body></html>';
