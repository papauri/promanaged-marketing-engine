<?php
// Public: serves only the files prepared for Instagram (random names), because Instagram fetches them from a web address:
//   ig_<24 hex>.jpg (a picture post), ig_<post id>_<n>.jpg (a carousel slide), ig_<post id>.mp4 (a reel, with Range support).
// ?ping=1 answers "ok": the Accounts screen uses it (HEAD) to check that the address is reachable from outside.
if (isset($_GET['ping'])) {
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    exit('ok');
}
$f = (string)($_GET['f'] ?? '');
$path = __DIR__ . '/data/social/' . $f;
$jpg = preg_match('/^ig_[a-f0-9]{20,24}(_\d{1,2})?\.jpg$/', $f);
$mp4 = preg_match('/^ig_[a-f0-9]{20,24}\.mp4$/', $f);
if ((!$jpg && !$mp4) || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}
$size = (int)filesize($path);
header('Content-Type: ' . ($mp4 ? 'video/mp4' : 'image/jpeg'));
header('Cache-Control: public, max-age=86400');
header('Accept-Ranges: bytes');
$start = 0;
$end = $size - 1;
if ($mp4 && isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim((string)$_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { // the last N bytes
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        $end = $m[2] !== '' ? min((int)$m[2], $size - 1) : $end;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
$h = fopen($path, 'rb');
fseek($h, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($h)) {
    $chunk = fread($h, min(65536, $left));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($h);
