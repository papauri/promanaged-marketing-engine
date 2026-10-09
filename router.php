<?php
// Router for `php -S`: only the app page, the public signing page and the logo/assets are reachable.
// Blocks .env, data/, lib/, vendor/, output/ and any other dotfile from being served.
// (On a normal web host, .htaccess does the same job.)
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
if ($path === '/media.php') {
    require __DIR__ . '/media.php';
    return true;
}
if ($path === '/sign.php') {
    require __DIR__ . '/sign.php';
    return true;
}
if ($path === '/enquire.php') { // public enquiry form and free website check (no login)
    require __DIR__ . '/enquire.php';
    return true;
}
if ($path === '/wa.php') { // WhatsApp Business responder webhook (off unless configured in .env)
    require __DIR__ . '/wa.php';
    return true;
}
if (preg_match('#^/assets/[A-Za-z0-9._-]+$#', $path) && is_file(__DIR__ . $path)) {
    return false; // let the built-in server send the static file
}
http_response_code(404);
echo 'Not found';
return true;
