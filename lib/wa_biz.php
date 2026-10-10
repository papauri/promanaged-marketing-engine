<?php
/**
 * WhatsApp Business Cloud API responder — functions only (MARKETING.md MG-S10). The webhook endpoint lives in wa.php.
 * Completely off unless WA_BIZ_TOKEN, WA_BIZ_PHONE_ID and WA_BIZ_VERIFY are set in .env.
 * Known leads get the Reply agent's draft (24h window); STOP marks the lead optout and is honoured forever.
 */
if (!function_exists('pm_looks_like_stop') && is_file(__DIR__ . '/engage.php')) {
    require_once __DIR__ . '/engage.php';
}
if (!function_exists('pm_wa_biz_cfg')) {
    function pm_wa_biz_cfg(): array
    {
        $get = fn($k) => trim((string)(function_exists('pm_env_val') ? pm_env_val($k) : (pm_env()[$k] ?? '')));
        $c = ['token' => $get('WA_BIZ_TOKEN'), 'phone_id' => $get('WA_BIZ_PHONE_ID'), 'verify' => $get('WA_BIZ_VERIFY')];
        $c['ready'] = $c['token'] !== '' && $c['phone_id'] !== '' && $c['verify'] !== '';
        return $c;
    }

    /** Normalises a WhatsApp number for matching: digits, leading 00 stripped. */
    function pm_wa_biz_norm(string $n): string
    {
        $n = preg_replace('/\D+/', '', $n);
        if (str_starts_with($n, '00')) {
            $n = substr($n, 2);
        }
        return $n;
    }

    /** The lead this WhatsApp number belongs to (phone or whatsapp field), else null. */
    function pm_wa_biz_find_lead(array $leads, string $number): ?array
    {
        $n = pm_wa_biz_norm($number);
        if (strlen($n) < 9) {
            return null;
        }
        foreach ($leads as $l) {
            if (($l['status'] ?? '') === 'optout') {
                continue;
            }
            foreach (['whatsapp', 'phone'] as $f) {
                $p = pm_wa_biz_norm((string)($l[$f] ?? ''));
                if ($p !== '' && (str_ends_with($p, substr($n, -9)) && str_ends_with($n, substr($p, -9)))) {
                    return $l;
                }
            }
        }
        return null;
    }

    /** Sends one text via the Cloud API. [ok, msg]. PM_WA_STUB for tests. */
    function pm_wa_biz_send(string $to, string $text): array
    {
        $c = pm_wa_biz_cfg();
        if (!$c['ready']) {
            return [false, 'WhatsApp Business is not configured.'];
        }
        if (isset($GLOBALS['PM_WA_STUB']) && is_callable($GLOBALS['PM_WA_STUB'])) {
            return $GLOBALS['PM_WA_STUB']($to, $text);
        }
        $ch = curl_init('https://graph.facebook.com/v21.0/' . $c['phone_id'] . '/messages');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $text]])]);
        pm_curl_native_ca($ch);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($raw, true);
        if ($code >= 200 && $code < 300 && !empty($j['messages'][0]['id'])) {
            return [true, (string)$j['messages'][0]['id']];
        }
        return [false, 'WhatsApp said: ' . ($code ?: 'no answer') . ' ' . mb_substr($raw, 0, 120)];
    }

    /** One inbound WhatsApp message, fully handled. Returns a short note. */
    function pm_wa_biz_handle(string $from, string $text): string
    {
        if (!pm_rate_hit('wabiz', $from, 10, 3600)) {
            return 'rate limited';
        }
        $leads = pm_leads();
        $lead = pm_wa_biz_find_lead($leads, $from);
        if (!$lead) {
            if (function_exists('pm_inb_unmatched_add')) {
                pm_inb_unmatched_add('whatsapp', $from, mb_substr($text, 0, 200));
            }
            return 'unknown number: logged as unmatched';
        }
        $id = (string)$lead['id'];
        if (pm_looks_like_stop($text)) {
            pm_update('leads', function (array $all) use ($id) {
                $all[$id]['status'] = 'optout';
                $all[$id]['optout_at'] = date('Y-m-d H:i');
                $all[$id]['optout_ch'] = 'whatsapp';
                pm_lead_note($all[$id], 'STOP received on WhatsApp: no further contact');
                return $all;
            });
            pm_wa_biz_send($from, 'Understood. We will not contact you again.');
            return 'STOP honoured';
        }
        $lastIn = 0;
        foreach ((array)($lead['thread'] ?? []) as $m) {
            if (($m['dir'] ?? '') === 'in') {
                $lastIn = max($lastIn, strtotime((string)($m['at'] ?? '')));
            }
        }
        $draftMode = function_exists('pm_reply_mode') && pm_reply_mode('wa') === 'draft'; // C2-A04: review-before-send
        $apply = function (array $r) use ($id, $text) { // the message is always logged and classified; the answer waits as a draft on the lead
            pm_update('leads', function (array $all) use ($id, $text, $r) {
                if (isset($all[$id])) {
                    pm_apply_reply($all[$id], $text, $r, 'draft', null, 'wa');
                }
                return $all;
            });
        };
        $alert = function (string $subject, string $extra) use ($lead, $text) {
            if (function_exists('pm_notify_owner')) {
                pm_notify_owner($subject . ' (' . $lead['name'] . ')', $lead['name'] . " wrote on WhatsApp:\n" . mb_substr($text, 0, 400) . "\n\n" . $extra);
            }
        };
        if (!$draftMode && $lastIn && time() - $lastIn > 86400) { // a conversation that went quiet: no machine answer, a polite pointer and a person
            $apply(pm_reply_fallback('They wrote after a long silence: please read it.'));
            pm_wa_biz_send($from, 'Thanks for writing back. It has been a little while — reply on email or call us and we will pick this up right away.');
            $alert('WhatsApp needs a human', 'This chat had been quiet for over a day, so only a polite pointer was sent.');
            return 'window closed: polite pointer sent';
        }
        $reply = pm_agent_reply($lead, $text, 'wa');
        $apply($reply);
        if (!empty($reply['needs_human'])) {
            $alert('WhatsApp needs a human', 'The Reply agent says a person should answer.');
            return 'needs a human: owner alerted';
        }
        $answer = (string)($reply['whatsapp'] ?? $reply['reply_body'] ?? '');
        if ($answer === '') {
            return 'no answer drafted';
        }
        if ($draftMode || !in_array((string)($reply['intent'] ?? ''), ['interested', 'question'], true)) { // review-before-send, or not a plain question or interest
            if ($draftMode) {
                $alert('WhatsApp answer waiting for you', "Drafted answer:\n" . $answer . "\n\nApprove or edit it in Agents or WhatsApp > They replied.");
            }
            return $draftMode ? 'draft saved: waiting for the owner to approve' : 'reply drafted for the owner';
        }
        [$ok, $mid] = pm_wa_biz_send($from, $answer);
        if ($ok) {
            pm_update('leads', function (array $all) use ($id, $answer) {
                if (isset($all[$id])) {
                    $all[$id]['wa_sent'][] = date('Y-m-d H:i');
                    pm_reply_sent($all[$id], $answer, 'wa');
                    pm_lead_note($all[$id], 'WhatsApp answered automatically');
                }
                return $all;
            });
            return 'answered from the Reply agent draft';
        }
        return 'send failed: ' . $mid;
    }

    /** Sends an approved template message (needed to start a chat outside the 24-hour window). [ok, id|message]. PM_WA_STUB lets tests stub the network. */
    function pm_wa_biz_send_template(string $to, string $template, string $lang, array $params): array
    {
        $c = pm_wa_biz_cfg();
        if (!$c['ready'] || $template === '') {
            return [false, 'WhatsApp Business is not configured, or no approved template name is set (WA_BIZ_TEMPLATE in .env).'];
        }
        if (isset($GLOBALS['PM_WA_STUB']) && is_callable($GLOBALS['PM_WA_STUB'])) {
            return $GLOBALS['PM_WA_STUB']($to, '[template ' . $template . '] ' . implode(' | ', $params));
        }
        $tpl = ['name' => $template, 'language' => ['code' => $lang !== '' ? $lang : 'en']];
        if ($params) {
            $tpl['components'] = [['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => mb_substr((string)$p, 0, 60)], array_values($params))]];
        }
        $ch = curl_init('https://graph.facebook.com/v21.0/' . $c['phone_id'] . '/messages');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => $tpl])]);
        pm_curl_native_ca($ch);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($raw, true);
        if ($code >= 200 && $code < 300 && !empty($j['messages'][0]['id'])) {
            return [true, (string)$j['messages'][0]['id']];
        }
        return [false, 'WhatsApp said: ' . ($code ?: 'no answer') . ' ' . mb_substr($raw, 0, 120)];
    }

    /** True when the lead wrote to us on WhatsApp in the last 24 hours: free-text answers are only allowed then. */
    function pm_wa_biz_window_open(array $lead): bool
    {
        $last = 0;
        foreach ((array)($lead['thread'] ?? []) as $m) {
            if (($m['dir'] ?? '') === 'in' && ($m['ch'] ?? '') === 'wa') {
                $last = max($last, (int)strtotime((string)($m['at'] ?? '')));
            }
        }
        return $last > 0 && time() - $last < 86400;
    }

    /**
     * The owner approved a drafted WhatsApp answer: send it through the Business API. Needs the connection, a number, an open 24-hour window and a clean text.
     * $lead is updated in place on success (thread, no longer waiting); the caller saves. Returns [ok, message].
     */
    function pm_wa_biz_send_approved(array &$lead, string $text): array
    {
        $text = trim($text);
        if (!pm_wa_biz_cfg()['ready']) {
            return [false, 'WhatsApp Business is not connected: use "Reply on WhatsApp" to answer from your phone.'];
        }
        if (($lead['status'] ?? '') === 'optout') {
            return [false, ($lead['name'] ?? 'This business') . ' asked not to be contacted.'];
        }
        [$num] = pm_wa_best($lead);
        if ($num === '' || $text === '') {
            return [false, 'This lead needs a mobile number and a written answer.'];
        }
        if (!pm_wa_biz_window_open($lead)) {
            return [false, 'More than 24 hours have passed since they wrote, so WhatsApp only allows an approved template. Answer from your phone with "Reply on WhatsApp".'];
        }
        $bad = array_values(array_filter(pm_outreach_lint('WhatsApp answer', $text, false), fn($x) => !str_starts_with($x, 'The message is')));
        if ($bad) {
            return [false, 'Not sent. ' . implode(' ', $bad)];
        }
        [$ok, $mid] = pm_wa_biz_send($num, $text);
        if (!$ok) {
            return [false, (string)$mid];
        }
        $lead['wa_sent'][] = date('Y-m-d H:i');
        pm_reply_sent($lead, $text, 'wa');
        pm_lead_note($lead, 'WhatsApp answer sent (approved by ' . (($GLOBALS['PM_WHO'] ?? '') !== '' ? $GLOBALS['PM_WHO'] : 'the owner') . ')');
        return [true, 'Sent on WhatsApp to ' . ($lead['name'] ?? 'the lead') . '.'];
    }
}
