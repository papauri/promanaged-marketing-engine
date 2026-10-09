<?php
/**
 * WhatsApp Business Cloud API webhook endpoint (MARKETING.md MG-S10).
 * Completely off unless WA_BIZ_TOKEN, WA_BIZ_PHONE_ID and WA_BIZ_VERIFY are set in .env.
 * GET  ?hub.verify_token=...&hub.challenge=...  -> Meta webhook verification
 * POST -> inbound messages, handled by lib/wa_biz.php.
 */
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/agents.php';
if (!function_exists('pm_agent_reply')) {
    require_once __DIR__ . '/lib/engage.php';
}
require_once __DIR__ . '/lib/wa_biz.php';

$cfg = pm_wa_biz_cfg();
if (!$cfg['ready']) {
    http_response_code(404);
    exit('Not found');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (hash_equals($cfg['verify'], (string)($_GET['hub_verify_token'] ?? '')) && isset($_GET['hub_challenge'])) {
        header('Content-Type: text/plain');
        echo (string)$_GET['hub_challenge'];
        exit;
    }
    http_response_code(403);
    exit;
}
$body = json_decode((string)file_get_contents('php://input'), true);
$out = [];
foreach ((array)($body['entry'] ?? []) as $entry) {
    foreach ((array)($entry['changes'] ?? []) as $change) {
        $v = (array)($change['value'] ?? []);
        foreach ((array)($v['messages'] ?? []) as $m) {
            if (($m['type'] ?? '') !== 'text') {
                continue;
            }
            $from = (string)($m['from'] ?? '');
            $text = (string)($m['text']['body'] ?? '');
            if ($from !== '' && $text !== '') {
                $out[] = $from . ': ' . pm_wa_biz_handle($from, $text);
            }
        }
    }
}
header('Content-Type: text/plain');
echo implode("\n", $out) ?: 'ok';
