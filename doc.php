<?php
// Public: serves one PDF from a business's share kit (Operations > Business details), for the link in its emails and WhatsApp messages: doc.php?d=<20 hex>.
// The id is random, so only people who were sent the link can open it; nothing else under data/ is reachable from here.
require_once __DIR__ . '/lib/store.php';

$found = pm_share_find_doc((string)($_GET['d'] ?? ''));
$path = $found ? pm_share_doc_path($found[1]) : '';
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}
$name = pm_share_friendly_name((string)$found[1]['title']);
$size = (int)filesize($path);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . $size);
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($path);
}
