<?php
// Public: serves only the pictures prepared for Instagram (random names), because Instagram fetches them from a web address.
$f = (string)($_GET['f'] ?? '');
$path = __DIR__ . '/data/social/' . $f;
if (!preg_match('/^ig_[a-f0-9]{24}\.jpg$/', $f) || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
readfile($path);
