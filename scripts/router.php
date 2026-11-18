<?php
// Lokaler Testserver: npm run serve  →  http://127.0.0.1:8080/
// Bei BASE_PATH=/karriere bildet er das Deployment unter /karriere nach.
$base = rtrim(getenv('BASE_PATH') ?: '/', '/');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($base !== '' && strncmp($uri, $base, strlen($base)) !== 0) { header("Location: $base/"); return true; }
$rel = substr($uri, strlen($base)) ?: '/';
$file = __DIR__ . '/../dist' . $rel;
if (is_dir($file)) $file = rtrim($file, '/') . '/index.html';
if (preg_match('#^/api/(bewerbung|download)\.php$#', $rel)) {
    $_SERVER['SCRIPT_NAME'] = $uri;
    chdir(dirname($file));
    require $file;
    return true;
}
if (preg_match('#^/api/#', $rel)) { http_response_code(403); echo 'Forbidden'; return true; }
if (!is_file($file)) { http_response_code(404); readfile(__DIR__ . '/../dist/404.html'); return true; }
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif', 'xml' => 'application/xml', 'txt' => 'text/plain', 'json' => 'application/json'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
readfile($file);
return true;
