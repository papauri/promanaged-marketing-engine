<?php
/**
 * Profile words, written like a person who does this for a living, with the marketing psychology that makes people act (PM_MARKETING_PSYCH): three options per profile field
 * (the customer's outcome first, who it is for with proof, short with an easy next step), made in ONE AI call for
 * the fields the owner ticks. Each option is polished by the human voice layer, must fit the platform's limit, and must stay inside the business's own facts (no new number, link, boast);
 * anything that still reads as machine-written is dropped. "Use" makes the option the suggestion everywhere (Optimise, Audit), and for Facebook and WhatsApp can apply it straight away.
 * Data: data/page_opt.json {brand: {copy: {key: {at, options: [{voice, text}]}}, ai: {key: text}}}.
 */

/** key => [label, limit, what it is, group]. The keys are the Optimise item keys, so a chosen option shows up there as the suggestion. */
const PM_COPY_FIELDS = [
    'biz_tagline' => ['One-line description', 120, 'The line under the business name; it becomes the intro on most profiles.', 'business'],
    'fb_about' => ['Facebook intro', 101, 'The line under the Page name in search results.', 'facebook'],
    'fb_description' => ['Facebook description', 250, 'What visitors read on the About tab.', 'facebook'],
    'ig_bio' => ['Instagram bio', 150, 'Who you are, who it is for, how to reach you.', 'instagram'],
    'wa_about' => ['WhatsApp about line', 139, 'Shown under your name in the chat.', 'whatsapp'],
    'wa_description' => ['WhatsApp business description', 512, 'What people read before they message you.', 'whatsapp'],
    'g_description' => ['Google Business description', 750, 'Your Maps listing: no links or phone numbers.', 'google'],
    'li_tagline' => ['LinkedIn tagline', 120, 'The line under the company name.', 'linkedin'],
    'x_bio' => ['X bio', 160, 'The first words a visitor reads.', 'x'],
    'tt_bio' => ['TikTok bio', 80, 'Short enough to read at a glance.', 'tiktok'],
    'yt_bio' => ['YouTube channel description', 300, 'About the channel.', 'youtube'],
];
const PM_COPY_GROUPS = ['business' => 'Business details', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp Business', 'google' => 'Google Business', 'linkedin' => 'LinkedIn', 'x' => 'X', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'];
const PM_COPY_VOICES = ['outcome' => 'Their outcome first', 'proof' => 'Who it is for, with proof', 'action' => 'Short, with an easy next step',
    'plain' => 'Plain and warm', 'specific' => 'Specific and confident', 'short' => 'Short and memorable']; // the last three: options written before
const PM_COPY_ASK = ['outcome', 'proof', 'action'];
const PM_COPY_APPLY = ['biz_tagline', 'fb_about', 'fb_description', 'wa_about', 'wa_description']; // the ones an app can put on the channel (the first is saved as the business's own line)

/** What the code would suggest with no AI. */
function pm_copy_plain(string $brand, string $key): string
{
    [, $limit] = PM_COPY_FIELDS[$key];
    $f = pm_opt_facts($brand);
    $bios = pm_bio_pack($brand);
    return match ($key) {
        'biz_tagline' => (string)(pm_opt_tagline_alts($f, [], [])[0]['text'] ?? ''),
        'fb_about' => (string)($bios['facebook']['text'] ?? ''), 'ig_bio' => (string)($bios['instagram']['text'] ?? ''), 'x_bio' => (string)($bios['x']['text'] ?? ''), 'tt_bio' => (string)($bios['tiktok']['text'] ?? ''),
        'li_tagline' => (string)($bios['linkedin']['text'] ?? ''), 'wa_about' => pm_bio_fit([$f['tagline'], 'Malawi'], $limit), default => pm_opt_long($f, $limit),
    };
}

/** A fingerprint of the facts the words are written from: when it changes, the written options are out of date. */
function pm_copy_sig(string $brand): string
{
    $f = pm_opt_facts($brand);
    return substr(md5((string)json_encode([$f['name'], $f['tagline'], $f['about'], $f['audience'], $f['facts'], $f['address'], $f['phone'], $f['site']])), 0, 10);
}

/** The options written so far: key => ['at', 'options' => [['voice','text']], 'sig']. */
function pm_copy_store(string $brand): array
{
    return (array)(pm_opt_store($brand)['copy'] ?? []);
}

/** Keeps the options that fit, stay in the facts and read naturally. */
function pm_copy_clean_options(array $raw, int $limit, string $source): array
{
    $out = [];
    $seen = [];
    foreach ($raw as $voice => $text) {
        if (!isset(PM_COPY_VOICES[(string)$voice]) || !is_scalar($text)) {
            continue;
        }
        $t = pm_human_polish(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$text))), 'profile');
        $k = mb_strtolower($t);
        if ($t === '' || isset($seen[$k]) || mb_strlen($t) > $limit || pm_human_flags($t, 'profile') !== [] || !pm_opt_ai_ok($t, $limit, $source)) {
            continue;
        }
        $seen[$k] = 1;
        $out[] = ['voice' => (string)$voice, 'text' => $t];
    }
    return $out;
}

/** One AI call for the chosen fields. Returns [fields done, message]. Throws when the AI cannot be reached. */
function pm_copy_write(string $brand, array $keys): array
{
    $keys = array_values(array_filter(array_unique($keys), fn($k) => isset(PM_COPY_FIELDS[$k])));
    if (!$keys) {
        return [0, 'Tick at least one profile.'];
    }
    $f = pm_opt_facts($brand);
    $source = implode(' ', array_filter(array_map('strval', [$f['name'], $f['tagline'], $f['about'], $f['audience'], implode(' ', $f['facts']), implode(' ', $f['offers']), $f['address'], $f['phone'], $f['website'], $f['site']])));
    $voice = trim((string)(pm_brain($brand)['voice'] ?? ''));
    $system = pm_agents_company_brief('tiny') . "\nYou are a senior direct-response copywriter for " . $f['name'] . ', the kind who writes for small independent businesses, not for software companies. '
        . 'Write the profile texts the way the owner would say it across a counter, so a visitor knows in two seconds what they get and why to message. ' . PM_MARKETING_PSYCH . ' Vary the sentence length '
        . 'and use the place name where it is natural. Avoid taglines built from "where X meets Y", "your partner in", "passionate about", "solutions", "tailored", "innovative". No hashtags, emoji or links. '
        . 'For each field give three options that really differ: outcome (the result the customer wants, or the problem gone, first), proof (who it is for and the most convincing specific detail from the facts), '
        . 'action (short and memorable, ending with the easy next step when it fits the limit). '
        . 'Show the whole of what we offer (everything_we_offer) wherever the limit allows, and never present us as only one part of it; a short field names the main areas in a few words. '
        . 'Every option must fit the character limit. Use ONLY the facts: no number, price, award, ranking or claim that is not in them, and never "best" or "leading". ' . PM_HUMAN_RULES
        . ($voice !== '' ? ' The owner describes their voice as: ' . mb_substr($voice, 0, 160) . '.' : '') . ' Reply JSON only.';
    $items = [];
    foreach ($keys as $k) {
        $items[] = ['key' => $k, 'field' => PM_COPY_FIELDS[$k][0], 'limit' => PM_COPY_FIELDS[$k][1], 'about' => PM_COPY_FIELDS[$k][2], 'plain_version' => pm_copy_plain($brand, $k)];
    }
    $user = json_encode(['business' => $f['name'], 'tagline' => $f['tagline'], 'about' => mb_substr($f['about'], 0, 500), 'audience' => mb_substr($f['audience'], 0, 240), 'facts' => array_slice($f['facts'], 0, 8),
            'everything_we_offer' => $f['offers'], 'town' => $f['address_real'] ? $f['address'] : 'Malawi', 'fields' => $items], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"items\":[{\"key\":\"\",\"outcome\":\"\",\"proof\":\"\",\"action\":\"\"}]}";
    $out = pm_agent_json(pm_claude($system, $user, false, 350 + 330 * count($keys), 'write'));
    $list = is_array($out) ? (array_is_list($out) ? $out : (array)($out['items'] ?? [])) : [];
    $done = 0;
    $dropped = 0;
    $new = [];
    foreach ($list as $x) {
        if (!is_array($x) || !isset($x['key']) || !in_array((string)$x['key'], $keys, true)) {
            continue;
        }
        $k = (string)$x['key'];
        $opts = pm_copy_clean_options(array_intersect_key($x, array_flip(PM_COPY_ASK)), PM_COPY_FIELDS[$k][1], $source);
        $dropped += 3 - count($opts);
        if ($opts) {
            $new[$k] = ['at' => date('Y-m-d H:i'), 'options' => $opts, 'sig' => pm_copy_sig($brand)];
            $done++;
        }
    }
    if ($new) {
        pm_opt_update($brand, function (array $d) use ($new) {
            $d['copy'] = $new + (array)($d['copy'] ?? []);
            return $d;
        });
    }
    return [$done, $done . ' of ' . count($keys) . ' profile' . (count($keys) === 1 ? '' : 's') . ' written' . ($dropped > 0 ? '. ' . $dropped . ' option' . ($dropped === 1 ? ' was' : 's were') . ' left out: too long, or it added a claim, a number or wording that reads as machine-written' : '') . '.'];
}

/** Makes an option the suggestion on every screen, and applies it when the channel allows. Returns [ok, message]. */
function pm_copy_use(string $brand, string $key, int $i, bool $apply): array
{
    $opt = pm_copy_store($brand)[$key]['options'][$i] ?? null;
    if (!$opt || !isset(PM_COPY_FIELDS[$key])) {
        return [false, 'That option is not there any more.'];
    }
    pm_opt_update($brand, function (array $d) use ($key, $opt) {
        $d['ai'][$key] = $opt['text'];
        return $d;
    });
    if ($apply && in_array($key, PM_COPY_APPLY, true)) {
        return pm_opt_apply($brand, $key, $opt['text']);
    }
    return [true, PM_COPY_FIELDS[$key][0] . ' is now the suggestion on Optimise and the Audit. Copy it into the profile' . (in_array($key, PM_COPY_APPLY, true) ? ', or press Apply' : '') . '.'];
}

/* ---------------- form handlers (POST action=social_ext&do=...) ---------------- */

function pm_do_copy_write(string $vb): array
{
    $at = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['anchor'] ?? '')) ?: 'words'; // back to the card that asked
    $keys = array_values(array_filter((array)($_POST['keys'] ?? []), 'is_string'));
    $groups = array_values(array_filter((array)($_POST['groups'] ?? []), 'is_string'));
    foreach (PM_COPY_FIELDS as $k => [, , , $g]) {
        if (in_array($g, $groups, true)) {
            $keys[] = $k;
        }
    }
    if (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB'])) {
        return ['msg' => 'The AI needs a key in .env first. The plain suggestions on Optimise work without it.', 'kind' => 'err', 'to' => 'social&view=optimise#' . $at];
    }
    try {
        [$n, $m] = pm_copy_write($vb, $keys);
    } catch (Throwable $e) {
        return ['msg' => 'The AI could not write them: ' . mb_substr($e->getMessage(), 0, 120) . '. Nothing changed.', 'kind' => 'err', 'to' => 'social&view=optimise#' . $at];
    }
    return ['msg' => $m, 'kind' => $n > 0 ? 'ok' : 'err', 'to' => 'social&view=optimise#' . $at];
}

function pm_do_copy_use(string $vb): array
{
    $key = preg_replace('/[^a-z_]/', '', (string)($_POST['key'] ?? ''));
    [$ok, $m] = pm_copy_use($vb, $key, (int)($_POST['i'] ?? -1), !empty($_POST['apply']));
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=optimise#w-' . $key];
}

function pm_do_copy_clear(string $vb): array
{
    pm_opt_update($vb, function (array $d) {
        $d['copy'] = [];
        $d['ai'] = [];
        return $d;
    });
    return ['msg' => 'The written options were cleared. The plain suggestions are back.', 'kind' => 'ok', 'to' => 'social&view=optimise#words'];
}
