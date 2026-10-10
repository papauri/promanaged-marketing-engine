<?php
/**
 * WhatsApp Business Cloud API webhook endpoint (MARKETING.md MG-S10).
 * Completely off unless at least one business has its own WA_BIZ_TOKEN, WA_BIZ_PHONE_ID and WA_BIZ_VERIFY in .env (ProManaged: WA_BIZ_*, Travel Malawi: TM_WA_BIZ_*,
 * an added business: <ID>_WA_BIZ_*). One webhook address serves every connected number: Meta says which number a message came to, and that decides the business.
 * GET  ?hub.verify_token=...&hub.challenge=...  -> Meta webhook verification
 * POST -> inbound messages, handled by lib/wa_biz.php.
 */
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/agents.php';
if (!function_exists('pm_agent_reply')) {
    require_once __DIR__ . '/lib/engage.php';
}
require_once __DIR__ . '/lib/wa_biz.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/social_modules.php'; // offer keywords and the reply rules
if (is_file(__DIR__ . '/lib/mail.php')) {
    require_once __DIR__ . '/lib/mail.php'; // so the owner is told when WhatsApp needs a person
}

if (!pm_wa_biz_brands()) {
    http_response_code(404);
    exit('Not found');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (pm_wa_biz_verify_ok((string)($_GET['hub_verify_token'] ?? '')) && isset($_GET['hub_challenge'])) {
        header('Content-Type: text/plain');
        echo (string)$_GET['hub_challenge'];
        exit;
    }
    http_response_code(403);
    exit;
}
$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
$ids = [];
foreach ((array)($body['entry'] ?? []) as $entry) {
    foreach ((array)($entry['changes'] ?? []) as $change) {
        $ids[] = (string)($change['value']['metadata']['phone_number_id'] ?? '');
    }
}
if (!pm_wa_biz_signature_ok($raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), $ids)) {
    http_response_code(403); // not signed by WhatsApp with this business's app secret
    exit('Bad signature');
}
$out = [];
foreach ((array)($body['entry'] ?? []) as $entry) {
    foreach ((array)($entry['changes'] ?? []) as $change) {
        $v = (array)($change['value'] ?? []);
        $phoneId = (string)($v['metadata']['phone_number_id'] ?? ''); // the business number the message came to
        $names = [];
        foreach ((array)($v['contacts'] ?? []) as $c) { // the name each sender gave WhatsApp
            $names[(string)($c['wa_id'] ?? '')] = (string)($c['profile']['name'] ?? '');
        }
        foreach ((array)($v['messages'] ?? []) as $m) {
            if (($m['type'] ?? '') !== 'text') {
                continue;
            }
            $from = (string)($m['from'] ?? '');
            $text = (string)($m['text']['body'] ?? '');
            if ($from !== '' && $text !== '') {
                $out[] = $from . ': ' . pm_wa_biz_handle($from, $text, $phoneId, $names[$from] ?? '');
            }
        }
    }
}
header('Content-Type: text/plain');
echo implode("\n", $out) ?: 'ok';
