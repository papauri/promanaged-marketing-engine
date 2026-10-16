<?php
/**
 * Where Facebook sends the owner back after "Continue with Facebook" (no sign-in here: the one-time state from the signed-in owner's press is the key,
 * and it lives 30 minutes). The code becomes a long-lived login and the list of Pages; the owner then chooses the Page on Operations > Connections.
 */
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/agents.php';
require_once __DIR__ . '/lib/social_modules.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
@set_time_limit(60);
$to = pm_fbo_callback($_GET);
if ($to === '') {
    http_response_code(400);
    exit('This Facebook connection link has expired. Go back to the app and press Continue with Facebook again.');
}
header('Location: ' . $to);
exit;
