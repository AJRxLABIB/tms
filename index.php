<?php
/**
 * index.php — the only PHP file in the project root: the front controller.
 *
 * Every public page lives in frontend/ (webhooks in backend/webhooks/). Apache (.htaccess) sends a request such as
 * /hotels.php or /robots.txt here, and this file runs the matching page while keeping the URL, $_GET/$_POST and
 * $_SERVER['PHP_SELF'] exactly as if that page sat in the root, so links, redirects, forms and payment-gateway
 * callback URLs keep working unchanged.
 *   /            -> frontend/index.php
 *   /hotels.php  -> frontend/hotels.php
 *   /webhook-bkash.php -> backend/webhooks/bkash.php   (also nagad, sslcommerz)
 *   /robots.txt  -> frontend/robots.php
 * Under PHP's built-in server (no .htaccess):  php -S 127.0.0.1:8099 index.php
 */
$uri  = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$leaf = basename(rawurldecode($uri));
$name = 'index';
if ($uri !== '' && substr($uri, -1) !== '/') {
    if ($leaf === 'robots.txt')                                  { $name = 'robots'; }
    elseif (preg_match('/^([A-Za-z0-9_-]+)\.php$/', $leaf, $m))   { $name = $m[1]; }
    else                                                         { $name = ''; }
}

$root = __DIR__;
$webhooks = ['webhook-bkash' => 'bkash', 'webhook-nagad' => 'nagad', 'webhook-sslcommerz' => 'sslcommerz'];
$target = '';
if ($name !== '') {
    if (isset($webhooks[$name]))                        $target = $root . '/backend/webhooks/' . $webhooks[$name] . '.php';
    elseif (is_file($root . '/frontend/' . $name . '.php')) $target = $root . '/frontend/' . $name . '.php';
}
if ($target === '' || !is_file($target)) {
    // Built-in server only: let it serve real static files (css, js, images) itself.
    if (PHP_SAPI === 'cli-server' && is_file($root . $uri) && $uri !== '/index.php') return false;
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return;
}

$self = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/') . '/' . $name . '.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $self;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $name . '.php';
unset($uri, $leaf, $m, $webhooks, $self);
require $target;
