<?php
/**
 * WhatsApp Business Cloud API responder — functions only (MARKETING.md MG-S10). The webhook endpoint lives in wa.php.
 * Completely off for a business unless its own WA_BIZ_TOKEN, WA_BIZ_PHONE_ID and WA_BIZ_VERIFY are set in .env (ProManaged: WA_BIZ_*, Travel Malawi: TM_WA_BIZ_*,
 * a business added in the app: <ID>_WA_BIZ_*), so every business answers from its own number. A message is handled as the business whose number it came to.
 * Known leads get the Reply agent's draft (24h window); STOP marks the lead optout and is honoured forever.
 */
if (!function_exists('pm_looks_like_stop') && is_file(__DIR__ . '/engage.php')) {
    require_once __DIR__ . '/engage.php';
}
if (!function_exists('pm_wa_biz_cfg')) {
    function pm_wa_biz_cfg(?string $brand = null): array
    {
        $brand ??= pm_brand();
        $c = ['brand' => $brand, 'prefix' => pm_brand_env_prefix($brand), 'token' => pm_brand_env($brand, 'WA_BIZ_TOKEN'), 'phone_id' => pm_brand_env($brand, 'WA_BIZ_PHONE_ID'),
            'verify' => pm_brand_env($brand, 'WA_BIZ_VERIFY'), 'template' => pm_brand_env($brand, 'WA_BIZ_TEMPLATE'), 'lang' => pm_brand_env($brand, 'WA_BIZ_TEMPLATE_LANG') ?: 'en'];
        $c['ready'] = $c['token'] !== '' && $c['phone_id'] !== '' && $c['verify'] !== '';
        return $c;
    }

    /** The businesses whose own WhatsApp Business number is connected (only the one that owns $phoneId when it is given). */
    function pm_wa_biz_brands(string $phoneId = ''): array
    {
        $out = [];
        foreach (pm_brand_ids() as $b) {
            $c = pm_wa_biz_cfg($b);
            if ($c['ready'] && ($phoneId === '' || $c['phone_id'] === $phoneId)) {
                $out[] = $b;
            }
        }
        return $out;
    }

    /**
     * Is this webhook call really from WhatsApp? When a business has its Meta app secret in .env ({prefix}WA_BIZ_APP_SECRET), the body must carry a valid
     * X-Hub-Signature-256 made with it; a business without one is accepted as before (Setup health says so).
     */
    function pm_wa_biz_signature_ok(string $raw, string $sig, array $phoneIds): bool
    {
        $secrets = [];
        foreach (array_unique($phoneIds ?: ['']) as $pid) {
            foreach (pm_wa_biz_brands((string)$pid) as $b) {
                $s = pm_brand_env($b, 'WA_BIZ_APP_SECRET');
                if ($s !== '') {
                    $secrets[] = $s;
                }
            }
        }
        if (!$secrets) {
            return true;
        }
        foreach ($secrets as $s) {
            if ($sig !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $s), $sig)) {
                return true;
            }
        }
        return false;
    }

    /** Meta's webhook check: does this token belong to any connected business? */
    function pm_wa_biz_verify_ok(string $token): bool
    {
        foreach (pm_wa_biz_brands() as $b) {
            if ($token !== '' && hash_equals(pm_wa_biz_cfg($b)['verify'], $token)) {
                return true;
            }
        }
        return false;
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

    /** The lead this WhatsApp number belongs to (phone or whatsapp field), else null. With $brands, only leads of those businesses are considered. */
    function pm_wa_biz_find_lead(array $leads, string $number, array $brands = [], bool $withOptout = false): ?array
    {
        $n = pm_wa_biz_norm($number);
        if (strlen($n) < 9) {
            return null;
        }
        foreach ($leads as $l) {
            if ((!$withOptout && ($l['status'] ?? '') === 'optout') || ($brands && !in_array((string)($l['brand'] ?? 'promanaged'), $brands, true))) {
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

    /** Sends one text via the Cloud API, from the number of $brand (default: the current business). [ok, msg]. PM_WA_STUB for tests. */
    function pm_wa_biz_send(string $to, string $text, ?string $brand = null): array
    {
        $c = pm_wa_biz_cfg($brand);
        if (!$c['ready']) {
            return [false, 'WhatsApp Business is not configured for ' . pm_brand_name($c['brand']) . ' (' . $c['prefix'] . 'WA_BIZ_* in .env).'];
        }
        if (isset($GLOBALS['PM_WA_STUB']) && is_callable($GLOBALS['PM_WA_STUB'])) {
            return $GLOBALS['PM_WA_STUB']($to, $text, $c['phone_id']);
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

    /**
     * One inbound WhatsApp message, fully handled, AS THE BUSINESS IT CAME TO: its brief, its voice, its settings and its own number for the answer, not whichever
     * business the process happened to start in. $phoneId is the number id Meta says received it; a business that owns it is the only one whose leads can match
     * (without it, any connected business). Returns a short note.
     */
    function pm_wa_biz_handle(string $from, string $text, string $phoneId = '', string $name = ''): string
    {
        $brands = pm_wa_biz_brands($phoneId);
        if (!$brands) {
            return $phoneId !== '' ? 'a number that is not connected to any business: ignored' : 'no business has WhatsApp Business connected';
        }
        $lead = pm_wa_biz_find_lead(pm_leads(), $from, $brands);
        $prev = pm_brand();
        pm_brand_set((string)($lead['brand'] ?? $brands[0]));
        try {
            return pm_wa_biz_handle_run($from, $text, $brands, ['name' => $name]);
        } finally {
            pm_brand_set($prev);
        }
    }

    /**
     * Someone we have no lead for wrote to a business's WhatsApp number. They started the conversation, so they are a warm lead: it is created for the business whose
     * number it came to (source whatsapp, or the offer when the message is an offer's keyword), the owner is told, and the message is handled like any other. A keyword
     * gets the owner's own reply words for that offer (no AI); anything else gets an answer drafted for the owner, which is never sent to a stranger by itself.
     */
    function pm_wa_biz_new_contact(string $from, string $text, string $name = ''): string
    {
        $brand = pm_brand();
        if (pm_looks_like_stop($text)) {
            return 'STOP from a number with no lead: nothing to stop';
        }
        if (!pm_rate_hit('wabiznew', $brand, 30, 86400)) {
            return 'too many new numbers today: ignored';
        }
        $num = '+' . pm_wa_biz_norm($from);
        $name = trim(mb_substr((string)preg_replace('/[^\p{L}\p{N} \'.&-]/u', '', strip_tags($name)), 0, 80));
        $offer = function_exists('pm_lp_keyword_hit') ? pm_lp_keyword_hit($brand, $text) : null;
        $r = pm_web_lead(['brand' => $brand, 'kind' => 'enquiry', 'name' => $name, 'business' => $name !== '' ? $name : 'WhatsApp ' . $num, 'phone' => $num, 'message' => '',
            'label' => 'Wrote to us on WhatsApp' . ($offer ? ' (' . $offer['title'] . ')' : ''), 'src_tag' => $offer ? 'offer-' . $offer['id'] : 'whatsapp', 'channel' => 'whatsapp', 'source' => 'whatsapp', 'status' => 'replied']);
        if (function_exists('pm_notify_owner')) {
            pm_notify_owner('New WhatsApp contact (' . $r['lead']['name'] . ')', $r['lead']['name'] . ' wrote to ' . pm_brand_name($brand) . " on WhatsApp:\n" . mb_substr($text, 0, 400) . "\n\nOpen the lead in the app to answer.");
        }
        $canned = null;
        if ($offer) {
            $answer = pm_lp_keyword_reply($offer, $name);
            if (!pm_reply_lint($answer, $brand)) {
                $canned = ['intent' => 'interested', 'summary' => 'Wrote the offer keyword ' . $offer['keyword'], 'needs_human' => false, 'reply_subject' => '', 'reply_body' => $answer, 'whatsapp' => $answer, 'resume_on' => '', 'next_step' => 'reply'];
            }
        }
        return 'new lead from WhatsApp: ' . pm_wa_biz_handle_run($from, $text, [$brand], ['created' => true, 'force_draft' => $canned === null, 'canned' => $canned]);
    }

    function pm_wa_biz_handle_run(string $from, string $text, array $brands = [], array $opt = []): string
    {
        if (!pm_rate_hit('wabiz', $from, 10, 3600)) {
            return 'rate limited';
        }
        $leads = pm_leads();
        $lead = pm_wa_biz_find_lead($leads, $from, $brands);
        if (!$lead) {
            if (pm_wa_biz_find_lead($leads, $from, $brands, true) !== null) { // someone who said STOP: never a new lead, never an answer
                return 'a do-not-contact number wrote again: nothing sent, nothing changed';
            }
            return empty($opt['created']) ? pm_wa_biz_new_contact($from, $text, (string)($opt['name'] ?? '')) : 'the new contact could not be recorded';
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
        $draftMode = !empty($opt['force_draft']) || (function_exists('pm_reply_mode') && pm_reply_mode('wa') === 'draft'); // C2-A04: review-before-send (always for a stranger's first message)
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
        try {
            $reply = is_array($opt['canned'] ?? null) ? $opt['canned'] : pm_agent_reply($lead, $text, 'wa');
        } catch (Throwable $e) { // the AI is down or out of budget: a person reads it, and the message is still logged
            $reply = pm_reply_fallback('The AI could not draft an answer (' . mb_substr($e->getMessage(), 0, 80) . '): please read it.');
        }
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
    function pm_wa_biz_send_template(string $to, string $template, string $lang, array $params, ?string $brand = null): array
    {
        $c = pm_wa_biz_cfg($brand);
        if (!$c['ready'] || $template === '') {
            return [false, 'WhatsApp Business is not configured for ' . pm_brand_name($c['brand']) . ', or no approved template name is set (' . $c['prefix'] . 'WA_BIZ_TEMPLATE in .env).'];
        }
        if (isset($GLOBALS['PM_WA_STUB']) && is_callable($GLOBALS['PM_WA_STUB'])) {
            return $GLOBALS['PM_WA_STUB']($to, '[template ' . $template . '] ' . implode(' | ', $params), $c['phone_id']);
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

    /** Asks WhatsApp for the connected number's name: proves the token and number work, sends nothing. [ok, message]. $GLOBALS['PM_WA_CHECK_STUB'] (tests) replaces the network. */
    function pm_wa_biz_check(?string $brand = null): array
    {
        $c = pm_wa_biz_cfg($brand);
        if (!$c['ready']) {
            return [false, 'WhatsApp Business is not configured for ' . pm_brand_name($c['brand']) . '.'];
        }
        if (isset($GLOBALS['PM_WA_CHECK_STUB']) && is_callable($GLOBALS['PM_WA_CHECK_STUB'])) {
            return $GLOBALS['PM_WA_CHECK_STUB']($c);
        }
        $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode($c['phone_id']) . '?fields=verified_name,display_phone_number');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['token']]]);
        pm_curl_native_ca($ch);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($raw, true);
        if ($code >= 200 && $code < 300 && !empty($j['display_phone_number'])) {
            return [true, 'connected to ' . $j['display_phone_number'] . (!empty($j['verified_name']) ? ' (' . $j['verified_name'] . ')' : '')];
        }
        return [false, 'WhatsApp said: ' . ($code ?: 'no answer') . ' ' . mb_substr((string)($j['error']['message'] ?? $raw), 0, 120)];
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
        $brand = (string)($lead['brand'] ?? pm_brand()); // the answer goes out from the number of the business the lead belongs to
        if (!pm_wa_biz_cfg($brand)['ready']) {
            return [false, 'WhatsApp Business is not connected for ' . pm_brand_name($brand) . ': use "Reply on WhatsApp" to answer from your phone.'];
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
        [$ok, $mid] = pm_wa_biz_send($num, $text, $brand);
        if (!$ok) {
            return [false, (string)$mid];
        }
        $lead['wa_sent'][] = date('Y-m-d H:i');
        pm_reply_sent($lead, $text, 'wa');
        pm_lead_note($lead, 'WhatsApp answer sent (approved by ' . (($GLOBALS['PM_WHO'] ?? '') !== '' ? $GLOBALS['PM_WHO'] : 'the owner') . ')');
        return [true, 'Sent on WhatsApp to ' . ($lead['name'] ?? 'the lead') . '.'];
    }
}
