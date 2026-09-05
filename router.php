<?php
/**
 * Dev-only router for PHP's built-in server.
 * Usage from the project root:  php -S localhost:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Block direct access to internal folders, mirroring the .htaccess rules.
if (preg_match('#^/(services|config|data|logs)(/|$)#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Clean API URLs: /api/route -> /api/route.php
if (preg_match('#^/api/([a-z-]+)/?$#', $uri, $m) && is_file(__DIR__ . "/api/{$m[1]}.php")) {
    require __DIR__ . "/api/{$m[1]}.php";
    return true;
}

// Serve real files as-is (css, js, images, existing .php).
$filePath = __DIR__ . $uri;
if ($uri !== '/' && is_file($filePath)) {
    return false;
}

// Default: serve the SPA shell.
require __DIR__ . '/public/index.html';
return true;
