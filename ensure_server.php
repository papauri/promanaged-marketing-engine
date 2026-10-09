<?php
if (PHP_SAPI !== 'cli') { // command line only: never reachable from a browser
    http_response_code(404);
    exit;
}
// Makes sure the ProManaged Proposals app is served on http://127.0.0.1:8085 (same as start.bat).
// Safe to run any number of times: it only starts the server when nothing is listening on the port.
$port = 8085;
$dir = __DIR__;
$up = function () use ($port): bool {
    $s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 0.5);
    if ($s) {
        fclose($s);
        return true;
    }
    return false;
};
if (!$up()) {
    $php = PHP_BINARY ?: 'php';
    $cmd = 'start "" /B "' . $php . '" -S 127.0.0.1:' . $port . ' -t "' . $dir . '" "' . $dir . '\router.php" > NUL 2>&1';
    pclose(popen($cmd, 'r'));
    for ($i = 0; $i < 20 && !$up(); $i++) {
        usleep(250000);
    }
}
echo $up() ? "ProManaged Proposals app is running at http://127.0.0.1:$port/index.php\n" : "Could not start the ProManaged Proposals server on port $port.\n";
