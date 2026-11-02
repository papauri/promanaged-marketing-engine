<?php
/**
 * The first-email summit: the whole team sits down ONCE and agrees how every first email to a stranger starts. A new lead has never heard of us and has not sat down to learn
 * what we do, so a first email that only pitches the one thing that fits them (or only says "I am writing from X") leaves them without direction and without the bigger picture.
 * The summit (one cheap-ish AI call, held again only when the offerings or facts change or after 30 days) decides:
 *   about    the one or two sentences every first email carries: who we are and the WHOLE of what we do, in plain spoken words
 *   bridge   how to go from the one thing we noticed about them to the part of what we do that fits it, then widen to the rest
 *   ask      the one easy next step the email ends on
 *   mistakes what the waiting first emails get wrong, as instructions
 * Everything that writes a first email reads the plan: the Writers, the study's ready starters, the default message ({about}) and the sit-down. And code guarantees it, free:
 * an email that does not name us and at least two of our offerings gets the plan's "about" added (pm_op_fix_body), waiting drafts nobody edited or approved are repaired
 * (pm_op_repair), and "My name is with X" (no sender name) is put right. The owner has the last word: Sales > Targeting > First-email plan shows the plan and takes their own wording.
 * Travel Malawi keeps its own fixed text (lib/tm_email.php) and is left alone. Data: data/opener_plan.json {brand: {at, hash, tried_at, about, owner_about, bridge, ask, mistakes, lines}}.
 */

const PM_OP_FILE = 'opener_plan';

/** Does the first-email plan apply to this business? Travel Malawi has its own fixed first email. */
function pm_op_applies(string $brand): bool
{
    return $brand !== '' && $brand !== 'travel';
}

function pm_op_all(): array
{
    return pm_load(PM_OP_FILE, fn() => []);
}

/** The agreed plan of a business ([] until the summit was held). */
function pm_op_plan(string $brand): array
{
    return (array)(pm_op_all()[$brand] ?? []);
}

/** What the plan was built from: the offerings, facts and rules every writer is told. A change in any of them calls the summit again. */
function pm_op_hash(string $brand): string
{
    return md5(pm_dm_as($brand, fn() => pm_agents_company_brief('tiny') . '|' . pm_offer_line($brand)));
}

/** The names of what we offer, for a sentence: "Build, Source and Support". */
function pm_op_offer_names(string $brand): string
{
    $n = array_values(array_filter(array_column(pm_offer_keys($brand), 'name')));
    return $n ? pm_list_and($n) : '';
}

/** The first word of the business name, to see whether an email names us ("ProManaged" for "ProManaged IT"). */
function pm_op_company_word(): string
{
    $w = trim((string)(preg_split('/\s+/', trim((string)(pm_settings()['company_name'] ?? '')))[0] ?? ''));
    return mb_strlen($w) >= 3 ? $w : '';
}

/** What the business says about itself, as a sentence a stranger can follow, when no summit has written one: the brain's "about" with our name in front of it. */
function pm_op_fallback_about(string $brand): string
{
    $about = trim((string)(pm_brain($brand)['about'] ?? ''));
    if ($about === '') {
        return '';
    }
    $co = trim((string)(pm_settings()['company_name'] ?? ''));
    return preg_match('/^[A-Z][a-z]+s\b/', $about) && $co !== '' ? $co . ' ' . lcfirst($about) : $about; // "Builds software..." -> "ProManaged IT builds software..."
}

/** The sentences every first email carries about us: the owner's own wording, else the team's, else the brain's. '' for a business without any. */
function pm_op_about(string $brand): string
{
    if (!pm_op_applies($brand)) {
        return '';
    }
    $p = pm_op_plan($brand);
    return trim((string)($p['owner_about'] ?? '')) ?: (trim((string)($p['about'] ?? '')) ?: pm_op_fallback_about($brand));
}

/** What a first email shows of us: ['company' => names us, 'offers' => how many of our offerings it speaks about, 'need' => how many it should, 'ok' => the whole picture is there]. */
function pm_op_picture(string $body, string $brand = ''): array
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $names = array_column(pm_offer_keys($brand), 'name');
    $need = min(2, count($names));
    $co = pm_op_company_word();
    $text = (string)preg_replace('/\b(this|the|my|your) e-?mail\b/i', '', $body); // "reply to this email" is not about our email offering
    $offers = count(array_filter(pm_offer_in_text($brand, $text)));
    $company = $co === '' || stripos($body, $co) !== false;
    return ['company' => $company, 'offers' => $offers, 'need' => $need, 'ok' => $company && $offers >= $need];
}

/** The team's plan as a paragraph for a writer's instructions ('' for Travel Malawi or when there is nothing to say about us). */
function pm_op_rules(string $brand): string
{
    $about = pm_op_about($brand);
    if ($about === '') {
        return '';
    }
    $p = pm_op_plan($brand);
    $names = pm_op_offer_names($brand);
    return 'FIRST-EMAIL PLAN, agreed by the whole team (it overrides the layout above wherever they differ): the person reading has never heard of us and has not sat down to learn what we do, so every first email must tell them, '
        . 'in its second paragraph and in your own words, WHO we are and the WHOLE of what we do, never only the one thing that fits them: "' . $about . '" '
        . 'Then give the one specific, true thing we noticed about THEM' . (trim((string)($p['bridge'] ?? '')) !== '' ? ' (' . trim((string)$p['bridge']) . ')' : ', joined to the part of what we do that fits it') . ($names !== '' ? ', and say in a few words that we help with the rest too (' . $names . ')' : '') . '. '
        . (trim((string)($p['ask'] ?? '')) !== '' ? 'End on the one easy next step: ' . trim((string)$p['ask']) . ' ' : '')
        . (!empty($p['mistakes']) ? 'Mistakes the team found in our earlier first emails, do not repeat them: ' . implode(' ', array_map('strval', (array)$p['mistakes'])) . ' ' : '')
        . 'Never "I am writing from X." alone, and never open as if they already knew us. The email is 100 to 150 words.';
}

/** The email rules with the sender made safe (no name given: never "My name is") and the team's plan added. $plan false = rules only (the message composer has its own shapes). */
function pm_email_craft(string $sender = '', bool $plan = true): string
{
    $c = PM_EMAIL_CRAFT;
    if (trim($sender) === '') {
        $c = str_replace(["with your first name (if given) and the company", "e.g. 'My name is <sender>, and I run <company>. I don't believe we've met, so I'll keep this short.' or 'I'm <sender> from <company>; we haven't met yet.' Vary the wording every time. "],
            ['with the company (no personal name is given)', "e.g. 'I'm writing from <company>; we haven't met yet.' or 'We are <company>, and we haven't been in touch before.' Never write 'My name is' and never invent a name. Vary the wording every time. "], $c);
    }
    return $c . ($plan && function_exists('pm_op_rules') ? ' ' . pm_op_rules(pm_brand()) : '');
}

/**
 * A first email made to carry the picture, by code and free: "My name is with X" (no sender) becomes "I am with X"; an email that does not name us and at least two of our offerings
 * gets the plan's "about" in its introduction (or an introduction of its own when it has none). Left as it is when it would pass the 190-word limit. $brand '' = the business in use.
 */
function pm_op_fix_body(string $body, string $brand = ''): string
{
    $brand = $brand !== '' ? $brand : pm_brand();
    if (!pm_op_applies($brand) || trim($body) === '') {
        return $body;
    }
    $b = (string)preg_replace('/\bMy name is (with|from|at)\b/i', 'I am $1', $body);
    $about = pm_op_about($brand);
    if ($about === '' || pm_op_picture($b, $brand)['ok'] || str_word_count($b) + str_word_count($about) > 185) {
        return $b;
    }
    $paras = preg_split('/\n{2,}/', str_replace("\r", '', trim($b))) ?: [$b];
    $greet = fn(string $p) => (bool)preg_match('/^(hello|hi|dear|good (day|morning|afternoon)|moni)\b[^\n]{0,60},?$/iu', trim($p));
    $co = pm_op_company_word();
    $at = null;
    foreach ($paras as $i => $p) {
        if (!($i === 0 && $greet($p)) && $co !== '' && stripos($p, $co) !== false) {
            $at = $i;
            break;
        }
    }
    $end = fn(string $s) => rtrim($s) . (preg_match('/[.!?]$/u', rtrim($s)) ? '' : '.');
    if ($at !== null) {
        $paras[$at] = $end($paras[$at]) . ' ' . $about;
    } else {
        $intro = (string)(pm_dm_vars()['intro'] ?? '');
        array_splice($paras, $greet((string)$paras[0]) ? 1 : 0, 0, [$end($intro) . ' ' . $about]);
    }
    return implode("\n\n", $paras);
}

/** Writer rows (['id', 'email_body', ...]) with every first email made to carry the picture. */
function pm_op_fix_rows(array $rows): array
{
    foreach ($rows as $i => $r) {
        if (is_array($r) && isset($r['email_body']) && is_string($r['email_body'])) {
            $rows[$i]['email_body'] = pm_op_fix_body($r['email_body']);
        }
    }
    return $rows;
}

/**
 * Waiting first emails that lack the picture, put right without AI: drafts nobody sent, approved or edited by hand. A draft made from the default message is made again from it
 * (unless the owner saved a default message of their own); an AI draft gets the plan's "about" (pm_op_fix_body). Returns how many changed.
 */
function pm_op_repair(array &$leads, string $brand): int
{
    if (!pm_op_applies($brand)) {
        return 0;
    }
    $n = 0;
    $ownDefault = !empty(pm_dm_cfg($brand)['saved']);
    foreach ($leads as $id => $l) {
        $d = (array)($l['drafts'] ?? []);
        if (($l['brand'] ?? 'promanaged') !== $brand || !empty($l['sent']) || !empty($l['approved_at']) || !empty($d['edited_at']) || trim((string)($d['email_body'] ?? '')) === ''
            || !in_array($l['status'] ?? '', ['qualified', 'drafted'], true) || !empty($l['rewrite_reason'])) {
            continue;
        }
        $was = (string)$d['email_body'];
        if (($d['source'] ?? '') === 'default') {
            if ($ownDefault) {
                continue; // their own default message is theirs
            }
            $use = !empty($d['plain']) ? array_diff_key($l, ['research' => 1]) : $l;
            $r = pm_dm_as($brand, fn() => pm_dm_render($use));
            $new = $r['email_body'];
            $new !== '' && $new !== $was && $leads[$id]['drafts'] = $r + ['source' => 'default'] + (!empty($d['plain']) ? ['plain' => true] : []);
        } else {
            $new = pm_dm_as($brand, fn() => pm_op_fix_body($was, $brand));
            $new !== $was && $leads[$id]['drafts']['email_body'] = $new;
        }
        if ($new !== '' && $new !== $was) {
            pm_lead_note($leads[$id], 'First email now tells them who we are and all that we do (the first-email plan)');
            $n++;
        }
    }
    return $n;
}

/** Does the summit need to be held? Never held, the offerings or facts changed, or 30 days old; not tried again within 12 hours. */
function pm_op_due(string $brand): bool
{
    if (!pm_op_applies($brand) || !pm_agents_ready()) {
        return false;
    }
    $p = pm_op_plan($brand);
    if (strtotime((string)($p['tried_at'] ?? '')) > strtotime('-12 hours')) {
        return false;
    }
    return !$p || empty($p['about']) || ($p['hash'] ?? '') !== pm_op_hash($brand) || strtotime((string)($p['at'] ?? '')) < strtotime('-30 days');
}

/** What the team reads in the summit: our waiting first emails (with what each shows of us), what people wrote back, the owner's feedback. Short. */
function pm_op_evidence(string $brand): array
{
    $mine = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    $emails = [];
    $replies = [];
    foreach (array_reverse($mine) as $l) {
        $d = (array)($l['drafts'] ?? []);
        if (count($emails) < 6 && trim((string)($d['email_body'] ?? '')) !== '' && empty($l['sent'])) {
            $pic = pm_dm_as($brand, fn() => pm_op_picture((string)$d['email_body'], $brand));
            $emails[] = ['subject' => (string)($d['email_subject'] ?? ''), 'email' => mb_substr((string)$d['email_body'], 0, 650), 'names_us' => $pic['company'], 'offerings_it_speaks_about' => $pic['offers']];
        }
        foreach (array_reverse((array)($l['thread'] ?? [])) as $m) {
            if (count($replies) < 5 && ($m['dir'] ?? '') === 'in' && trim((string)($m['text'] ?? '')) !== '') {
                $replies[] = mb_substr(trim((string)$m['text']), 0, 160);
                break;
            }
        }
    }
    return ['our_waiting_first_emails' => $emails, 'what_people_wrote_back' => $replies];
}

/**
 * Holds the summit: one AI call in which research, writers, sender, replies, follow-up and the director talk, then decide the plan. The talk goes into the agents' conversation (Audit).
 * Returns [ok, message]. $force = also when the plan is still fresh (the owner pressed the button).
 */
function pm_op_hold(string $brand, bool $force = false): array
{
    if (!pm_op_applies($brand)) {
        return [false, 'Travel Malawi keeps its own fixed first email, so there is nothing for this team to decide.'];
    }
    if (!$force && !pm_op_due($brand)) {
        return [true, 'The plan is up to date.'];
    }
    if (!pm_agents_ready()) {
        return [false, 'The AI engine is not ready, so the team cannot sit down (Operations > Connections).'];
    }
    pm_update(PM_OP_FILE, function (array $all) use ($brand) { // a failed summit is not tried again for 12 hours
        $all[$brand]['tried_at'] = date('Y-m-d H:i');
        return $all;
    }, fn() => []);
    $names = pm_op_offer_names($brand);
    $need = min(2, count(array_column(pm_offer_keys($brand), 'name')));
    $res = pm_dm_as($brand, function () use ($brand, $names, $need) {
        $sys = pm_agents_company_brief('tiny') . "\nYou chair the FIRST-EMAIL SUMMIT of our marketing team. The first email is the most important one we send: the owner who reads it has never heard of us and has not sat down to learn what we do. "
            . 'The owner\'s complaint: our first emails lack direction and miss the bigger picture of what we offer, and read as if the lead already knew who we are. At the table: research (what we know about leads), writers (how to open), sender (how it lands and whether it looks like spam), '
            . 'replies (what people answer and ask), followup (what the second email must not repeat) and the director, who decides. Read our waiting first emails (names_us, offerings_it_speaks_about) and what people wrote back, and be honest about what is missing. '
            . 'Write 6 to 8 lines of the team talking to EACH OTHER, each {"who","says"}: one short first-person sentence, sharp and specific, from the facts given only, the director last. Then the plan: '
            . '"about" = the one or two sentences EVERY first email carries, telling a stranger who we are and the WHOLE of what we do in plain spoken words: it names every part of what we offer (' . $names . ') in everyday words, says who it is for, starts with "We", has no company name (the introduction before it already names us), '
            . 'at most 45 words, no prices, no buzzwords, nothing beyond the facts given. "bridge" = one sentence telling a writer how to go from the one thing noticed about THEM to the part of what we do that fits it, then widen to the rest in a few words. '
            . '"ask" = one sentence: the single easy next step the email ends on, as a question. "mistakes" = 2 to 4 short instructions that fix what the waiting emails get wrong. ' . PM_TRUST_RULES . ' '
            . pm_firm_line($brand) . ' ' . pm_tb_line($brand, 'writers', 3) . ' ' . PM_AGENT_RULES;
        $user = json_encode(pm_op_evidence($brand), JSON_UNESCAPED_UNICODE) . "\nJSON: {\"lines\":[{\"who\":\"research|writers|sender|replies|followup|director\",\"says\":\"\"}],\"about\":\"\",\"bridge\":\"\",\"ask\":\"\",\"mistakes\":[\"\"]}";
        $why = '';
        for ($try = 0; $try < 2; $try++) {
            $out = pm_agent_json(pm_claude($sys, $user . ($why !== '' ? "\nYour last 'about' was refused: $why Write it again." : ''), false, 1500, 'write'));
            $out = is_array($out) ? $out : [];
            $about = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($out['about'] ?? ''))));
            function_exists('pm_human_polish') && $about !== '' && $about = trim(pm_human_polish($about, 'email'));
            $w = str_word_count($about);
            if ($w < 10 || $w > 60) {
                $why = "it has $w words; it must be 10 to 60.";
            } elseif (pm_has_price($about) || preg_match('#https?://|www\.#i', $about)) {
                $why = 'it holds a price or a link.';
            } elseif (count(array_filter(pm_offer_in_text($brand, $about))) < $need) {
                $why = "it must speak about at least $need of: $names.";
            } else {
                return ['out' => $out, 'about' => $about];
            }
        }
        return ['why' => $why];
    });
    if (!isset($res['about'])) {
        return [false, 'The team could not agree a wording that passes the checks (' . ($res['why'] ?? 'no answer') . '). Writers keep using the business details until the next try.'];
    }
    $out = (array)$res['out'];
    $clean = fn($v, int $max) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $max);
    $lines = [];
    foreach (array_slice((array)($out['lines'] ?? []), 0, 8) as $ln) {
        $who = is_array($ln) ? strtolower(trim((string)($ln['who'] ?? ''))) : '';
        $who = ['researcher' => 'research', 'writer' => 'writers', 'follow-up' => 'followup'][$who] ?? $who;
        $says = is_array($ln) ? $clean($ln['says'] ?? '', 220) : '';
        if ($says !== '' && in_array($who, ['research', 'replies', 'writers', 'sender', 'followup', 'director'], true)) {
            $lines[] = [$who, $says];
        }
    }
    $plan = ['at' => date('Y-m-d H:i'), 'hash' => pm_op_hash($brand), 'about' => $res['about'], 'bridge' => $clean($out['bridge'] ?? '', 260), 'ask' => $clean($out['ask'] ?? '', 200),
        'mistakes' => array_values(array_filter(array_map(fn($m) => $clean($m, 200), array_slice((array)($out['mistakes'] ?? []), 0, 4)))), 'lines' => $lines];
    pm_update(PM_OP_FILE, function (array $all) use ($brand, $plan) {
        $keep = array_intersect_key((array)($all[$brand] ?? []), ['owner_about' => 1]); // the owner's own wording stays theirs
        $all[$brand] = $plan + $keep;
        return $all;
    }, fn() => []);
    if (function_exists('pm_talk')) {
        foreach ($lines as $i => [$who, $says]) {
            pm_talk($brand, $who, ($i === 0 ? 'First-email summit: ' : '') . $says);
        }
        pm_talk($brand, 'director', 'Decision: every first email tells a new lead who we are and all that we do: "' . $plan['about'] . '"');
    }
    return [true, 'The team sat down and agreed how every first email starts.'];
}

/** The buttons of Sales > Targeting > First-email plan: 'save' (the owner's wording), 'team' (back to the team's), 'hold' (sit down again). Waiting drafts are put right after. Returns [message, kind]. */
function pm_op_handle(string $do, string $brand): array
{
    if (!pm_brand_valid($brand) || !pm_op_applies($brand)) {
        return ['Choose a business first.', 'err'];
    }
    if ($do === 'hold') {
        [$ok, $msg] = pm_op_hold($brand, true);
        if (!$ok) {
            return [$msg, 'err'];
        }
    } else {
        $mine = $do === 'save' ? mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($_POST['op_about'] ?? '')))), 0, 500) : '';
        $team = trim((string)(pm_op_plan($brand)['about'] ?? ''));
        if ($mine !== '' && (pm_has_price($mine) || preg_match('#https?://|www\.#i', $mine))) {
            return ['Leave prices and links out of this: it opens every first email.', 'err'];
        }
        pm_update(PM_OP_FILE, function (array $all) use ($brand, $mine, $team) {
            if ($mine !== '' && $mine !== $team) {
                $all[$brand]['owner_about'] = $mine;
            } else {
                unset($all[$brand]['owner_about']);
            }
            return $all;
        }, fn() => []);
        $msg = $mine !== '' && $mine !== $team ? 'Saved. Your wording opens every first email from now on.' : 'Back to the team\'s wording.';
    }
    $leads = pm_leads();
    $n = pm_op_repair($leads, $brand);
    $n && pm_leads_save($leads);
    return [$msg . ($n ? " $n waiting first email" . ($n === 1 ? ' was' : 's were') . ' updated to match.' : ''), 'ok'];
}
