<?php
/**
 * sx_wacampaign — MARKETING.md C2-A07: WhatsApp outbound campaign drafts. A campaign is a message plus an audience and a start time.
 * It is drafted by anyone, APPROVED by an approver (the same gate as social posts), and only then sent by the scheduler: a few messages per pass,
 * in working hours, inside the daily WhatsApp limit, never to anyone who said STOP, never twice to one company in a week.
 * Completely off unless WhatsApp Business keys are in .env (lib/wa_biz.php). Outside the 24-hour window WhatsApp delivers only an approved
 * template (WA_BIZ_TEMPLATE, WA_BIZ_TEMPLATE_LANG); without one those people are skipped and the campaign says so.
 * Data: data/wa_campaigns.json {rows:[{id,brand,name,text,aud:{statuses,group,min_score},send_from,status,recipients:[{id,name,num,state,at,note}],...}]}.
 */

const PM_WAC_STATUSES = ['draft', 'approved', 'sending', 'paused', 'done', 'cancelled'];
const PM_WAC_AUDIENCE_STATUSES = ['qualified' => 'Qualified', 'drafted' => 'Draft ready', 'contacted' => 'Contacted', 'replied' => 'Replied', 'proposal' => 'Proposal sent', 'won' => 'Won (clients)'];
const PM_WAC_MAX_RECIPIENTS = 150;
const PM_WAC_PER_PASS = 3;

function pm_wac_boot(): bool
{
    if (!function_exists('pm_wa_biz_cfg') && is_file(__DIR__ . '/wa_biz.php')) {
        require_once __DIR__ . '/wa_biz.php';
    }
    return function_exists('pm_wa_biz_cfg');
}

/* ---------------- storage ---------------- */

function pm_wac_all(): array
{
    return array_values((array)(pm_load('wa_campaigns', fn() => ['rows' => []])['rows'] ?? []));
}

/** Changes the campaign rows under the lock. $fn(array $rows): array. */
function pm_wac_update(callable $fn): void
{
    pm_update('wa_campaigns', fn(array $d) => ['rows' => array_values($fn((array)($d['rows'] ?? [])))], fn() => ['rows' => []]);
}

function pm_wac_get(string $id): ?array
{
    foreach (pm_wac_all() as $r) {
        if (($r['id'] ?? '') === $id) {
            return $r;
        }
    }
    return null;
}

function pm_wac_campaigns(string $brand): array
{
    return array_values(array_filter(pm_wac_all(), fn($r) => ($r['brand'] ?? '') === $brand));
}

/** Changes one campaign by id; returns the changed row or null. */
function pm_wac_patch(string $id, callable $fn): ?array
{
    $out = null;
    pm_wac_update(function (array $rows) use ($id, $fn, &$out) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id) {
                $rows[$i] = $out = (array)$fn($r);
            }
        }
        return $rows;
    });
    return $out;
}

/* ---------------- text ---------------- */

function pm_wac_first_name(array $lead): string
{
    $nm = trim(preg_replace('/^(dr|mr|mrs|ms|miss|prof|eng|hon|rev|pastor)\.?\s+/i', '', trim((string)($lead['contact'] ?? ''))));
    return trim(explode(' ', $nm)[0] ?? '');
}

/** The message one lead would get: placeholders filled, then the usual greeting, sign-off and STOP line. */
function pm_wac_render(string $text, array $lead): string
{
    $first = pm_wac_first_name($lead);
    $t = str_replace(['{first_name}', '{business}'], [$first !== '' ? $first : 'there', (string)($lead['name'] ?? 'your business')], $text);
    return pm_wa_text($lead, $t);
}

/** Problems that would stop the campaign: the same honesty and spam rules as any first WhatsApp message, on a sample render. */
function pm_wac_lint(string $text): array
{
    if (trim($text) === '') {
        return ['Write the message first.'];
    }
    if (mb_strlen($text) > 700) {
        return ['Keep the message under 700 characters.'];
    }
    return pm_outreach_lint('WhatsApp campaign', pm_wac_render($text, ['name' => 'Sample Business', 'contact' => 'Sample Person', 'brand' => pm_brand()]), true);
}

/* ---------------- audience and guardrails ---------------- */

/**
 * Why this lead must NOT get a campaign message right now ('' = fine). Used for the preview, at approval and again at every send,
 * so a STOP that arrives after approval is honoured. $leads is the whole lead file (for the company check).
 */
function pm_wac_skip_reason(array $lead, array $leads, array $cfg, bool $optinOnly = true): string
{
    if (($lead['status'] ?? '') === 'optout') {
        return 'asked us to stop';
    }
    if (pm_wa_best($lead)[0] === '') {
        return 'no mobile number';
    }
    if ($optinOnly && function_exists('pm_wa_optin') && !pm_wa_optin($lead)) { // C3-A01: only people who agreed to WhatsApp messages, unless the campaign says otherwise
        return 'no WhatsApp opt-in on record';
    }
    if (pm_lead_snoozed($lead)) {
        return 'asked for time';
    }
    $email = trim((string)($lead['email'] ?? ''));
    if ($email !== '' && pm_suppressed($leads, $email, (string)($lead['id'] ?? ''))) {
        return 'that company asked us to stop';
    }
    if (($lead['brand'] ?? 'promanaged') !== 'travel' && array_filter((array)($cfg['existing_clients'] ?? []), fn($x) => $x !== '' && stripos((string)($lead['name'] ?? ''), (string)$x) !== false)) {
        return 'already a client we look after personally';
    }
    foreach ((array)($lead['wa_sent'] ?? []) as $at) {
        if (strtotime((string)$at) > time() - 7 * 86400) {
            return 'messaged on WhatsApp in the last 7 days';
        }
    }
    if (function_exists('pm_company_contacted_with') && ($who = pm_company_contacted_with($leads, $lead, 7)) !== null) {
        return "the company was contacted this week ($who)";
    }
    return '';
}

/** [eligible leads, skipped [name => reason]] for an audience spec of this brand. Best score first, capped. */
function pm_wac_audience(string $brand, array $aud, ?array $leads = null): array
{
    $leads ??= pm_load('leads', fn() => []);
    $cfg = pm_agents_config($brand);
    $st = array_values(array_intersect(array_map('strval', (array)($aud['statuses'] ?? [])), array_keys(PM_WAC_AUDIENCE_STATUSES)));
    $min = max(0, min(100, (int)($aud['min_score'] ?? 0)));
    $group = (string)($aud['group'] ?? '');
    $optin = (bool)($aud['optin_only'] ?? true);
    $ok = [];
    $skipped = [];
    foreach ($leads as $k => $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || !in_array((string)($l['status'] ?? ''), $st, true) || (int)($l['score'] ?? 0) < $min
            || ($group !== '' && pm_lead_group($l) !== $group)) {
            continue;
        }
        $l['id'] = (string)($l['id'] ?? $k);
        $why = pm_wac_skip_reason($l, $leads, $cfg, $optin);
        if ($why !== '') {
            $skipped[(string)($l['name'] ?? $l['id'])] = $why;
        } else {
            $ok[] = $l;
        }
    }
    usort($ok, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    return [array_slice($ok, 0, PM_WAC_MAX_RECIPIENTS), $skipped];
}

/* ---------------- forms (POST action=social_ext&do=wac_*) ---------------- */

function pm_wac_back(string $msg, string $kind = 'ok'): array
{
    return ['msg' => $msg, 'kind' => $kind, 'to' => 'whatsapp'];
}

function pm_do_wac_create(string $vb): array
{
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
    $text = trim((string)($_POST['text'] ?? ''));
    if ($name === '' || $text === '') {
        return pm_wac_back('Give the campaign a name and write the message.', 'err');
    }
    $when = trim((string)($_POST['send_from'] ?? ''));
    $ts = $when !== '' ? strtotime(str_replace('T', ' ', $when)) : false;
    $row = ['id' => 'wc' . substr(md5($vb . $name . microtime(true)), 0, 10), 'brand' => $vb, 'name' => $name, 'text' => mb_substr($text, 0, 700),
        'aud' => ['statuses' => array_values(array_intersect(array_map('strval', (array)($_POST['statuses'] ?? [])), array_keys(PM_WAC_AUDIENCE_STATUSES))),
            'group' => mb_substr(trim((string)($_POST['group'] ?? '')), 0, 40), 'min_score' => max(0, min(100, (int)($_POST['min_score'] ?? 0))),
            'optin_only' => isset($_POST['optin_form']) ? !empty($_POST['optin_only']) : true],
        'template' => isset(pm_wa_templates($vb)[(string)($_POST['template'] ?? '')]) ? (string)$_POST['template'] : '',
        'send_from' => $ts ? date('Y-m-d H:i', $ts) : date('Y-m-d H:i'), 'status' => 'draft', 'recipients' => [], 'created' => date('Y-m-d H:i'),
        'by' => trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner'];
    if (!$row['aud']['statuses']) {
        return pm_wac_back('Tick at least one kind of lead to message.', 'err');
    }
    pm_wac_update(function (array $rows) use ($row) {
        $rows[] = $row;
        return $rows;
    });
    $bad = pm_wac_lint($text);
    return pm_wac_back('Campaign drafted. It sends nothing until an approver approves it.' . ($bad ? ' Fix before approving: ' . implode(' ', $bad) : ''), $bad ? 'err' : 'ok');
}

/** Editing the text of a draft (before approval only). */
function pm_do_wac_save(string $vb): array
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
    $text = trim((string)($_POST['text'] ?? ''));
    $r = pm_wac_patch($id, function (array $c) use ($vb, $text) {
        if (($c['brand'] ?? '') === $vb && ($c['status'] ?? '') === 'draft' && $text !== '') {
            $c['text'] = mb_substr($text, 0, 700);
        }
        return $c;
    });
    return $r && ($r['text'] ?? '') === mb_substr($text, 0, 700) ? pm_wac_back('Saved.') : pm_wac_back('Only a draft can be edited.', 'err');
}

function pm_do_wac_approve(string $vb): array
{
    pm_wac_boot();
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
    $c = pm_wac_get($id);
    if (!$c || ($c['brand'] ?? '') !== $vb || ($c['status'] ?? '') !== 'draft') {
        return pm_wac_back('Only a draft can be approved.', 'err');
    }
    if (!pm_social_can_approve()) {
        return pm_wac_back('You are an editor: an approver has to approve campaigns.', 'err');
    }
    if (!pm_wa_biz_cfg($vb)['ready']) {
        return pm_wac_back('WhatsApp Business is not connected for ' . pm_brand_name($vb) . ' (' . pm_brand_env_prefix($vb) . 'WA_BIZ_* in .env).', 'err');
    }
    if ($bad = pm_wac_lint((string)$c['text'])) {
        return pm_wac_back('Not approved. ' . implode(' ', $bad), 'err');
    }
    [$ok] = pm_wac_audience($vb, (array)$c['aud']);
    if (!$ok) {
        return pm_wac_back('Nobody can get this campaign right now (no one matches, or everyone is held back by the safety rules).', 'err');
    }
    $by = trim((string)($GLOBALS['PM_WHO'] ?? '')) ?: 'owner';
    pm_wac_patch($id, function (array $x) use ($ok, $by) {
        $x['status'] = 'approved';
        $x['approved_at'] = date('Y-m-d H:i');
        $x['approved_by'] = $by;
        $x['recipients'] = array_map(fn($l) => ['id' => (string)$l['id'], 'name' => (string)$l['name'], 'num' => pm_wa_best($l)[0], 'state' => 'pending', 'at' => '', 'note' => ''], $ok);
        return $x;
    });
    return pm_wac_back('Approved: ' . count($ok) . ' people. It goes out a few at a time in working hours, inside your daily WhatsApp limit.');
}

/** pause | resume | cancel | delete. */
function pm_wac_change(string $vb, string $how): array
{
    $id = preg_replace('/[^a-z0-9]/', '', (string)($_POST['id'] ?? ''));
    $c = pm_wac_get($id);
    if (!$c || ($c['brand'] ?? '') !== $vb) {
        return pm_wac_back('Campaign not found.', 'err');
    }
    $st = (string)$c['status'];
    if ($how === 'delete') {
        if (!in_array($st, ['draft', 'cancelled', 'done'], true)) {
            return pm_wac_back('Cancel it first, then delete it.', 'err');
        }
        pm_wac_update(fn(array $rows) => array_filter($rows, fn($r) => ($r['id'] ?? '') !== $id));
        return pm_wac_back('Deleted.');
    }
    $to = ['pause' => ['from' => ['approved', 'sending'], 'to' => 'paused'], 'resume' => ['from' => ['paused'], 'to' => 'approved'],
        'cancel' => ['from' => ['draft', 'approved', 'sending', 'paused'], 'to' => 'cancelled']][$how] ?? null;
    if (!$to || !in_array($st, $to['from'], true)) {
        return pm_wac_back('That cannot be done to a ' . $st . ' campaign.', 'err');
    }
    pm_wac_patch($id, function (array $x) use ($to) {
        $x['status'] = $to['to'];
        $x['error'] = '';
        return $x;
    });
    return pm_wac_back(['pause' => 'Paused: nothing more is sent until you resume it.', 'resume' => 'Resumed.', 'cancel' => 'Cancelled: nothing more will be sent.'][$how]);
}

function pm_do_wac_pause(string $vb): array { return pm_wac_change($vb, 'pause'); }
function pm_do_wac_resume(string $vb): array { return pm_wac_change($vb, 'resume'); }
function pm_do_wac_cancel(string $vb): array { return pm_wac_change($vb, 'cancel'); }
function pm_do_wac_delete(string $vb): array { return pm_wac_change($vb, 'delete'); }

/* ---------------- sending ---------------- */

/** Takes one recipient for sending, atomically: true only for the first caller while the person is still 'pending'. A claim that never finishes is never retried (no double send). */
function pm_wac_claim(string $id, int $i): bool
{
    $got = false;
    pm_wac_patch($id, function (array $x) use ($i, &$got) {
        if (($x['recipients'][$i]['state'] ?? '') === 'pending') {
            $x['recipients'][$i]['state'] = 'sending';
            $x['recipients'][$i]['at'] = date('Y-m-d H:i');
            $got = true;
        }
        return $x;
    });
    return $got;
}

/** Counts of recipient states for one campaign. */
function pm_wac_counts(array $c): array
{
    $n = ['pending' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'sending' => 0];
    foreach ((array)($c['recipients'] ?? []) as $r) {
        $n[$r['state'] ?? 'pending'] = ($n[$r['state'] ?? 'pending'] ?? 0) + 1;
    }
    return $n;
}

/**
 * Sends at most $max pending messages of one campaign. $sender(num, text, lead): [ok, id|message] sends one message (tests pass a stub;
 * the job passes the Business API). Every guardrail is checked again here, per person. Returns the number sent.
 */
function pm_wac_send_pass(string $id, callable $sender, int $max = PM_WAC_PER_PASS): int
{
    $sent = 0;
    $fails = 0;
    $leads = pm_load('leads', fn() => []);
    $cfg = pm_agents_config();
    $cap = (int)($cfg['wa_cap'] ?? 40);
    $c = pm_wac_get($id);
    if (!$c || !in_array($c['status'] ?? '', ['approved', 'sending'], true)) {
        return 0;
    }
    pm_wac_patch($id, function (array $x) {
        $x['status'] = 'sending';
        return $x;
    });
    foreach ((array)$c['recipients'] as $i => $r) {
        if ($sent >= $max || ($r['state'] ?? '') !== 'pending') {
            continue;
        }
        if (pm_wa_sent_today($leads) >= $cap) {
            break; // today's WhatsApp limit is reached: the rest waits for tomorrow
        }
        if (!pm_wac_claim($id, (int)$i)) {
            continue; // another scheduler pass took this person: never send twice
        }
        $lead = $leads[$r['id']] ?? null;
        $why = !$lead ? 'lead no longer exists' : pm_wac_skip_reason($lead + ['id' => $r['id']], $leads, $cfg, (bool)($c['aud']['optin_only'] ?? true));
        if ($why === '') {
            [$ok, $m] = $sender((string)$r['num'], pm_wac_render((string)$c['text'], $lead), $lead);
            if ($ok) {
                $now = date('Y-m-d H:i');
                pm_update('leads', function (array $all) use ($r, $c, $now) {
                    if (isset($all[$r['id']])) {
                        $all[$r['id']]['wa_sent'][] = $now;
                        $all[$r['id']]['thread'][] = ['dir' => 'out', 'at' => $now, 'text' => mb_substr((string)$c['text'], 0, 1500), 'ch' => 'wa'];
                        $all[$r['id']]['last_contacted'] = $all[$r['id']]['last_out'] = $now;
                        if (in_array($all[$r['id']]['status'] ?? '', ['new', 'qualified', 'drafted'], true)) {
                            $all[$r['id']]['status'] = 'contacted';
                        }
                        pm_lead_note($all[$r['id']], 'WhatsApp campaign "' . $c['name'] . '" sent');
                    }
                    return $all;
                });
                $leads = pm_load('leads', fn() => []); // so the daily count and the company check see this send
                $state = ['state' => 'sent', 'at' => $now, 'note' => ''];
                $sent++;
                $fails = 0;
            } else {
                $state = ['state' => 'failed', 'at' => date('Y-m-d H:i'), 'note' => mb_substr((string)$m, 0, 160)];
                $fails++;
            }
        } else {
            $state = ['state' => 'skipped', 'at' => date('Y-m-d H:i'), 'note' => $why];
        }
        pm_wac_patch($id, function (array $x) use ($i, $state) {
            $x['recipients'][$i] = array_merge($x['recipients'][$i], $state);
            return $x;
        });
        if ($fails >= 3) { // three failures in a row: something is wrong (token, template): stop and tell the owner
            pm_wac_patch($id, function (array $x) use ($state) {
                $x['status'] = 'paused';
                $x['error'] = 'Stopped after three failed sends in a row. Last error: ' . $state['note'];
                return $x;
            });
            return $sent;
        }
    }
    $fresh = pm_wac_get($id);
    if ($fresh && !pm_wac_counts($fresh)['pending'] && ($fresh['status'] ?? '') === 'sending') { // anyone still marked "sending" belongs to a pass that is running or died: not resent
        pm_wac_patch($id, function (array $x) {
            $x['status'] = 'done';
            $x['done_at'] = date('Y-m-d H:i');
            return $x;
        });
    }
    return $sent;
}

/**
 * C3-A02 · the approved WhatsApp templates the owner has named for one business: name => ['name','lang','default']. The one in .env (WA_BIZ_TEMPLATE, with the
 * business's prefix) is the default; the others are added on the WhatsApp screen. Templates belong to a business's own WhatsApp number, so each business has
 * its own list (a saved template with no business belongs to ProManaged IT). Approval itself happens in Meta's template manager: this only remembers the
 * names. Each template takes one variable, {{1}}, which is the person's first name.
 */
function pm_wa_templates(?string $brand = null): array
{
    $brand ??= pm_brand();
    $out = [];
    $c = pm_wa_biz_cfg($brand);
    if ($c['template'] !== '') {
        $out[$c['template']] = ['name' => $c['template'], 'lang' => $c['lang'], 'default' => true];
    }
    foreach ((array)(pm_load('settings', 'pm_default_settings')['wa_templates'] ?? []) as $t) {
        $n = (string)($t['name'] ?? '');
        if (($t['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        if (preg_match('/^[a-z0-9_]{1,60}$/', $n) && !isset($out[$n])) {
            $out[$n] = ['name' => $n, 'lang' => preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', (string)($t['lang'] ?? '')) ? $t['lang'] : 'en', 'default' => false];
        }
    }
    return $out;
}

/** The template a campaign uses: the one it names, else the default, else none. */
function pm_wa_template_for(string $chosen, ?string $brand = null): ?array
{
    $all = pm_wa_templates($brand);
    if ($chosen !== '' && isset($all[$chosen])) {
        return $all[$chosen];
    }
    foreach ($all as $t) {
        if (!empty($t['default'])) {
            return $t;
        }
    }
    return null;
}

function pm_do_wa_template_add(string $vb): array
{
    $name = strtolower(trim((string)($_POST['tname'] ?? '')));
    $lang = trim((string)($_POST['tlang'] ?? '')) ?: 'en';
    if (!preg_match('/^[a-z0-9_]{1,60}$/', $name)) {
        return pm_wac_back('A template name uses lower-case letters, digits and underscores only, exactly as it is in Meta\'s template manager.', 'err');
    }
    if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $lang)) {
        return pm_wac_back('The language is a code such as en or en_GB.', 'err');
    }
    if (isset(pm_wa_templates($vb)[$name])) {
        return pm_wac_back('That template is already in the list.', 'err');
    }
    pm_update('settings', function (array $s) use ($name, $lang, $vb) {
        $s['wa_templates'] = array_values(array_merge((array)($s['wa_templates'] ?? []), [['name' => $name, 'lang' => $lang, 'brand' => $vb]]));
        return $s;
    }, 'pm_default_settings');
    return pm_wac_back('Template added. Check in Meta\'s template manager that it is approved and takes one variable (the first name).');
}

function pm_do_wa_template_remove(string $vb): array
{
    $name = strtolower(trim((string)($_POST['tname'] ?? '')));
    pm_update('settings', function (array $s) use ($name, $vb) {
        $s['wa_templates'] = array_values(array_filter((array)($s['wa_templates'] ?? []), fn($t) => !((string)($t['name'] ?? '') === $name && ($t['brand'] ?? 'promanaged') === $vb)));
        return $s;
    }, 'pm_default_settings');
    return pm_wac_back('Template removed from the list. Campaigns that used it fall back to the default template.');
}

/** The sender the scheduler uses: free text inside the 24-hour window, the campaign's approved template outside it. */
function pm_wac_api_sender(string $template = '', ?string $brand = null): callable
{
    return function (string $num, string $text, array $lead) use ($template, $brand): array {
        $b = $brand ?? (string)($lead['brand'] ?? pm_brand()); // always the number of the business the campaign belongs to
        if (pm_wa_biz_window_open($lead)) {
            return pm_wa_biz_send($num, $text, $b);
        }
        $t = pm_wa_template_for($template, $b);
        if (!$t) {
            return [false, 'outside the 24-hour window and no approved template is set (' . pm_brand_env_prefix($b) . 'WA_BIZ_TEMPLATE, or add one on the WhatsApp screen)'];
        }
        return pm_wa_biz_send_template($num, $t['name'], $t['lang'], [pm_wac_first_name($lead) ?: 'there'], $b);
    };
}

/** Scheduler job (per brand): sends a few messages of each due campaign, in working hours only. Off without WhatsApp Business keys. */
function pm_job_wa_campaigns(string $brand): string
{
    if (!pm_wac_boot() || !pm_wa_biz_cfg($brand)['ready']) {
        return '';
    }
    $due = array_filter(pm_wac_campaigns($brand), fn($c) => in_array($c['status'] ?? '', ['approved', 'sending'], true) && strtotime((string)($c['send_from'] ?? '')) <= pm_now());
    if (!$due) {
        return '';
    }
    if (!pm_send_window()) {
        return 'campaigns wait for working hours';
    }
    $n = 0;
    foreach ($due as $c) {
        $n += pm_wac_send_pass((string)$c['id'], pm_wac_api_sender((string)($c['template'] ?? ''), $brand), max(1, PM_WAC_PER_PASS - $n));
        if ($n >= PM_WAC_PER_PASS) {
            break;
        }
    }
    return $n ? "$n campaign message(s) sent" : 'campaign messages are waiting (daily limit, or nobody left that is allowed)';
}

/* ---------------- screen ---------------- */

/** The Campaigns card of the WhatsApp tab. Shows only a short note until WhatsApp Business is connected. */
function pm_view_wa_campaigns(string $vb, string $csrf): void
{
    $wc = pm_wa_biz_cfg($vb);
    $biz = pm_wac_boot() && $wc['ready'];
    $sx = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
    $rows = array_reverse(pm_wac_campaigns($vb));
    echo '<details class="card" style="margin-top:14px"' . ($rows ? ' open' : '') . '><summary><b>Campaigns</b> <span class="pill">' . ($biz ? 'WhatsApp Business connected' : 'off: needs WhatsApp Business') . '</span></summary>';
    if (!$biz) {
        echo '<p class="hint">Campaigns are scheduled messages to a group of leads, with the same approval, STOP and daily-limit rules as everything else. They go out from this business\'s own WhatsApp number, and switch on when ' . pm_h($wc['prefix']) . 'WA_BIZ_TOKEN, ' . pm_h($wc['prefix']) . 'WA_BIZ_PHONE_ID and ' . pm_h($wc['prefix']) . 'WA_BIZ_VERIFY are in .env (see Settings &gt; Setup health).</p></details>';
        return;
    }
    $tpl = $wc['template'];
    echo '<p class="hint">A draft sends nothing. An approver approves it, then it goes out a few messages at a time, Monday to Friday 08:00 to 16:30, inside your daily WhatsApp limit. Anyone who said STOP is skipped, even if they said it after approval. '
        . ($tpl !== '' ? 'People who have not written to you in the last 24 hours get your approved template "' . pm_h($tpl) . '".' : 'Without an approved template (' . pm_h($wc['prefix']) . 'WA_BIZ_TEMPLATE in .env, or one named below) only people who wrote to you in the last 24 hours can be messaged; the others are skipped and listed.') . '</p>';
    foreach ($rows as $c) {
        $n = pm_wac_counts($c);
        $st = (string)$c['status'];
        echo '<div class="card" style="margin:8px 0"><div class="wahead"><div><b>' . pm_h((string)$c['name']) . '</b> <span class="pill ' . (in_array($st, ['approved', 'sending'], true) ? 'hot' : ($st === 'paused' ? 'warn' : '')) . '">' . pm_h($st) . '</span>'
            . ' <span class="muted">· from ' . pm_h((string)$c['send_from']) . ' · ' . pm_h(implode(', ', array_map(fn($k) => PM_WAC_AUDIENCE_STATUSES[$k] ?? $k, (array)$c['aud']['statuses']))) . (($c['aud']['group'] ?? '') !== '' ? ' · ' . pm_h((string)$c['aud']['group']) : '')
            . (($c['aud']['optin_only'] ?? true) ? ' · agreed to WhatsApp only' : ' · everyone allowed, opt-in not required')
            . (($c['template'] ?? '') !== '' ? ' · template ' . pm_h((string)$c['template']) : '') . '</span></div></div>';
        if ($st === 'draft') {
            [$ok, $skip] = pm_wac_audience($vb, (array)$c['aud']);
            $bad = pm_wac_lint((string)$c['text']);
            echo '<form method="post">' . $sx('wac_save', '<input type="hidden" name="id" value="' . pm_h((string)$c['id']) . '">')
                . '<textarea name="text" rows="3" aria-label="Campaign message">' . pm_h((string)$c['text']) . '</textarea>'
                . '<p class="hint">Would reach <b>' . count($ok) . '</b> people' . ($ok ? ' (' . pm_h(implode(', ', array_map(fn($l) => $l['name'], array_slice($ok, 0, 4)))) . (count($ok) > 4 ? '…' : '') . ')' : '') . '; ' . count($skip) . ' held back by the safety rules.'
                . ($bad ? ' <span class="warnt">Fix before approving: ' . pm_h(implode(' ', $bad)) . '</span>' : '') . '</p>'
                . '<div class="btns"><button class="btn small">Save text</button>'
                . (pm_social_can_approve() ? '<button class="btn small primary" name="do" value="wac_approve" onclick="return confirm(\'Approve this campaign? It will start sending in working hours.\')">Approve</button>' : '<span class="hint">An approver approves campaigns.</span>')
                . '<button class="btn small" name="do" value="wac_cancel">Cancel</button></div></form>';
        } else {
            echo '<p style="white-space:pre-wrap;margin:6px 0">' . pm_h((string)$c['text']) . '</p><p class="hint">' . (int)$n['sent'] . ' sent · ' . ((int)$n['pending'] + (int)$n['sending']) . ' waiting · ' . (int)$n['skipped'] . ' skipped · ' . (int)$n['failed'] . ' failed'
                . (!empty($c['approved_by']) ? ' · approved by ' . pm_h((string)$c['approved_by']) . ' ' . pm_h((string)$c['approved_at']) : '') . '</p>';
            if (!empty($c['error'])) {
                echo '<p class="hint warnt">' . pm_h((string)$c['error']) . '</p>';
            }
            $why = array_filter(array_map(fn($r) => in_array($r['state'] ?? '', ['skipped', 'failed'], true) ? pm_h((string)$r['name']) . ': ' . pm_h((string)$r['note']) : '', (array)$c['recipients']));
            if ($why) {
                echo '<details class="more"><summary>Who was skipped, and why</summary><p class="hint">' . implode('<br>', array_slice($why, 0, 40)) . '</p></details>';
            }
            echo '<form method="post" class="btns">' . $sx('', '<input type="hidden" name="id" value="' . pm_h((string)$c['id']) . '">');
            foreach (['approved' => ['wac_pause' => 'Pause', 'wac_cancel' => 'Cancel'], 'sending' => ['wac_pause' => 'Pause', 'wac_cancel' => 'Cancel'], 'paused' => ['wac_resume' => 'Resume', 'wac_cancel' => 'Cancel'],
                'done' => ['wac_delete' => 'Delete'], 'cancelled' => ['wac_delete' => 'Delete']][$st] ?? [] as $do => $lab) {
                echo '<button class="btn small" name="do" value="' . $do . '">' . $lab . '</button>';
            }
            echo '</form>';
        }
        echo '</div>';
    }
    $groups = [];
    foreach (pm_load('leads', fn() => []) as $l) {
        if (($l['brand'] ?? 'promanaged') === $vb && ($l['status'] ?? '') !== 'optout') {
            $g = pm_lead_group($l);
            $groups[$g] = ($groups[$g] ?? 0) + 1;
        }
    }
    arsort($groups);
    echo '<h3>Templates</h3><p class="hint">WhatsApp only lets you start a chat with a template Meta has approved (made in Meta\'s template manager, one variable: the first name). Name the ones you have, then pick one for each campaign.</p>';
    foreach (pm_wa_templates($vb) as $t) {
        echo '<form method="post" class="task">' . $sx(empty($t['default']) ? 'wa_template_remove' : '', '<input type="hidden" name="tname" value="' . pm_h($t['name']) . '">')
            . '<span><b>' . pm_h($t['name']) . '</b> <span class="muted">· ' . pm_h($t['lang']) . (!empty($t['default']) ? ' · default, set in .env' : '') . '</span></span>'
            . (empty($t['default']) ? '<button class="btn small">Remove</button>' : '') . '</form>';
    }
    echo '<form method="post" class="findrow">' . $sx('wa_template_add') . '<input type="text" name="tname" placeholder="template_name" maxlength="60" aria-label="Template name" required><input type="text" name="tlang" placeholder="en" value="en" maxlength="5" aria-label="Language" class="narrow"><button class="btn small">Add template</button></form>';
    echo '<h3>New campaign</h3><form method="post">' . $sx('wac_create')
        . '<div class="row"><div><label>Name (for you)</label><input type="text" name="name" maxlength="60" required></div>'
        . '<div><label>Start from</label><input type="datetime-local" name="send_from" value="' . pm_h(date('Y-m-d\TH:i', strtotime('+1 hour'))) . '"></div>'
        . '<div><label>Only business type</label><select name="group"><option value="">Any</option>' . implode('', array_map(fn($g, $n) => '<option value="' . pm_h($g) . '">' . pm_h($g) . ' (' . $n . ')</option>', array_keys($groups), $groups)) . '</select></div>'
        . '<div><label>Score at least</label><input type="number" name="min_score" min="0" max="100" value="0"></div>'
        . '<div><label>Template outside the 24-hour window</label><select name="template"><option value="">' . (pm_wa_template_for('', $vb) ? 'Default (' . pm_h(pm_wa_template_for('', $vb)['name']) . ')' : 'None set: only people who wrote in the last 24 hours') . '</option>'
        . implode('', array_map(fn($t) => empty($t['default']) ? '<option value="' . pm_h($t['name']) . '">' . pm_h($t['name']) . ' (' . pm_h($t['lang']) . ')</option>' : '', pm_wa_templates($vb))) . '</select></div></div>'
        . '<input type="hidden" name="optin_form" value="1"><label class="check"><input type="checkbox" name="optin_only" value="1" checked> Only people who agreed to WhatsApp messages <span class="muted">(they wrote to us, replied, ticked the form box, or you recorded it). Recommended.</span></label>'
        . '<label>Message <span class="muted">(use {first_name} and {business}; the greeting, sign-off and STOP line are added)</span></label><textarea name="text" rows="3" required placeholder="We are running a short check-in with the businesses we have spoken to..."></textarea>'
        . '<label>Who</label><div class="checks">' . implode('', array_map(fn($k, $l) => '<label class="check"><input type="checkbox" name="statuses[]" value="' . $k . '"' . (in_array($k, ['contacted', 'won'], true) ? ' checked' : '') . '> ' . pm_h($l) . '</label>', array_keys(PM_WAC_AUDIENCE_STATUSES), PM_WAC_AUDIENCE_STATUSES)) . '</div>'
        . '<div class="btns"><button class="btn small primary">Save as draft</button></div></form></details>';
}
