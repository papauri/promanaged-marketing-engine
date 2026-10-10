<?php
/**
 * The planner. pm_plan_posts() writes a batch with ONE short 'write'-tier AI call; pm_plan_offline() builds the same rows from templates with no AI.
 * What each post is about, its pillar, hook, CTA, format and photo come from the seed picker (sx_bank.php): deterministic PHP.
 * Proof and offer posts (client quotes) and giveaways are never reworded by AI: they are filled from the owner's own text.
 */

/* ---------------- small text helpers ---------------- */

function pm_sx_end(string $t): string
{
    $t = trim($t);
    return $t === '' || preg_match('/[.!?”"]$/u', $t) ? $t : $t . '.';
}

function pm_sx_up(string $t): string { return $t === '' ? '' : mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1); }

/** [head, rest]: a short phrase for a picture headline (cut at a natural break, 3 to 8 words) and what remains. */
function pm_sx_split(string $t): array
{
    $t = trim(preg_replace('/\s+/', ' ', $t));
    $t = rtrim($t, '.');
    $words = explode(' ', $t);
    if (count($words) <= 7) {
        return [$t, ''];
    }
    $cut = null;
    foreach (['/[,;:]\s/', '/\s(?:and|so|but|while|that|which|because|when|then)\s/'] as $re) {
        if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE)) {
            $n = count(explode(' ', substr($t, 0, $m[0][1])));
            if ($n >= 3 && $n <= 9 && ($cut === null || $m[0][1] < $cut)) {
                $cut = $m[0][1];
            }
        }
    }
    if ($cut !== null) {
        return [trim(substr($t, 0, $cut)), pm_sx_up(trim(preg_replace('/^(?:and|so|but|while|that|which|because|when|then)\s+/i', '', ltrim(substr($t, $cut), ',;: '))))];
    }
    return [implode(' ', array_slice($words, 0, 7)), pm_sx_up(implode(' ', array_slice($words, 7)))];
}

function pm_sx_clip(string $t, int $max): string
{
    $t = trim($t);
    if (mb_strlen($t) <= $max) {
        return $t;
    }
    $cut = mb_substr($t, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,;:-") ;
}

/** Joins paragraphs; if over $max words, drops the middle ones (never the first or the call to action). */
function pm_sx_join(array $paras, int $max = 84): string
{
    $paras = array_values(array_filter(array_map('trim', $paras), fn($x) => $x !== ''));
    $count = fn($a) => count(preg_split('/\s+/u', implode(' ', $a), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    while ($count($paras) > $max && count($paras) > 2) {
        array_splice($paras, count($paras) - 2, 1);
    }
    return implode("\n\n", $paras);
}

/** The call-to-action sentence. Deterministic per seed so a re-plan gives the same wording. */
function pm_sx_cta_line(string $cta, string $brand, string $aud, string $seedId): string
{
    $pick = fn(array $l) => $l[crc32($seedId . $cta) % count($l)];
    $travel = $brand === 'travel';
    return match ($cta) {
        'comment' => $pick($travel ? ($aud === 'host' ? ['What has worked best for your guests? Tell us in the comments.', 'What would you add? Tell us in the comments.']
            : ['Where in Malawi would you like to stay next? Tell us below.', 'Have you been? Tell us your favourite in the comments.']) : ['Does this sound like your week? Tell us in the comments.', 'What would you add? Tell us in the comments.']),
        'save' => $pick(['Save this post so you can find it again.', 'Save this for the next time you need it.']),
        'share' => $pick($travel ? ($aud === 'host' ? ['Share this with a host you know.'] : ['Share this with a friend who is planning a trip.']) : ['Share this with someone who runs a business.']),
        'tag' => $pick(['Tag a friend who would enjoy this.']),
        'whatsapp' => $pick(['Send us a message on WhatsApp and we will help.', 'Message us on WhatsApp if you would like help with this.']),
        'check', 'host' => pm_sx_magnet_line($brand),
        default => '',
    };
}

/* ---------------- offline copy: templates from the seed's own facts ---------------- */

/** Caption, headline, sub, slides and the hook pattern actually used, from a slot. No AI. */
function pm_sx_offline_copy(array $slot, string $brand): array
{
    $s = $slot['seed'];
    $f = $s['facts'];
    $cta = pm_sx_cta_line((string)$slot['cta'], $brand, (string)$slot['audience'], $s['id']);
    $hook = (string)$slot['hook_pattern'];
    $slides = [];
    $items = (array)($s['items'] ?? []);
    $class = pm_sx_pillar_class((string)$slot['pillar']);
    $r = ['hook' => 'plain'];
    switch ($s['kind']) {
        case 'pain':
            if ($items) {
                $n = count($items);
                $thing = (string)($s['thing'] ?? 'business');
                $lines = [];
                foreach ($items as $i => $it) {
                    $lines[] = ($i + 1) . '. ' . pm_sx_up(rtrim($it['h'], '.'));
                    $slides[] = ['h' => pm_sx_clip(pm_sx_up($it['h']), 60), 't' => pm_sx_clip((string)($it['t'] ?? ''), 120)];
                }
                $head = "$n places your $thing can quietly lose money";
                $r = ['caption' => pm_sx_join([$head . ":\n" . implode("\n", $lines), $cta]), 'headline' => $head, 'sub' => 'Do you recognise any of these?', 'slides' => $slides, 'hook' => 'signs'];
                break;
            }
            $pain = rtrim((string)$s['pain'], '.');
            $cost = pm_sx_end((string)$s['cost']);
            $fix = pm_sx_end((string)$s['fix']);
            if ($hook === 'beforeafter') {
                $r = ['caption' => pm_sx_join(["Before: $pain.", $cost, "After: $fix", $cta]), 'headline' => pm_sx_split($pain)[0], 'sub' => pm_sx_clip(rtrim($fix, '.'), 110),
                    'slides' => [['h' => 'Before', 't' => pm_sx_up($pain)], ['h' => 'After', 't' => rtrim($fix, '.')]], 'hook' => 'beforeafter'];
            } elseif ($hook === 'myth') {
                $r = ['caption' => pm_sx_join(["Myth: $pain is just part of running a business.", "Fact: $fix", $cta]), 'headline' => 'Myth: ' . pm_sx_split($pain)[0], 'sub' => pm_sx_clip(rtrim($fix, '.'), 130),
                    'slides' => [['h' => 'The myth', 't' => pm_sx_up($pain) . ' is just part of running a business.'], ['h' => 'The fact', 't' => $fix]], 'hook' => 'myth'];
            } elseif ($hook === 'question') {
                $r = ['caption' => pm_sx_join([pm_sx_up($pain) . '. Sound familiar?', $cost, $cta]), 'headline' => pm_sx_split($pain)[0], 'sub' => 'Does this sound like your business?', 'hook' => 'question'];
            } else {
                $r = ['caption' => pm_sx_join(["Where businesses like yours lose money: $pain.", $cost, "How it stops: $fix", $cta]), 'headline' => pm_sx_split($pain)[0], 'sub' => pm_sx_clip(rtrim($fix, '.'), 110), 'hook' => 'plain'];
            }
            break;
        case 'module':
            if ($items) {
                $lines = [];
                foreach ($items as $i => $it) {
                    $lines[] = ($i + 1) . '. ' . $it['h'] . (($it['t'] ?? '') !== '' ? ': ' . rtrim($it['t'], '.') : '');
                    $slides[] = ['h' => pm_sx_clip($it['h'], 60), 't' => pm_sx_clip((string)($it['t'] ?? ''), 120)];
                }
                $head = 'What one system can cover';
                $r = ['caption' => pm_sx_join([$head . ":\n" . implode("\n", array_slice($lines, 0, 5)), $cta]), 'headline' => $head, 'sub' => 'One login, one set of figures', 'slides' => $slides, 'hook' => 'plain'];
                break;
            }
            $r = ['caption' => pm_sx_join([pm_sx_end($s['topic'] . ': ' . rtrim($f[0], '.')), $cta]), 'headline' => $s['topic'], 'sub' => pm_sx_clip(rtrim($f[0], '.'), 110)];
            break;
        case 'benefit':
            $r = ['caption' => pm_sx_join([pm_sx_end($s['topic']), pm_sx_end($f[0]), $cta]), 'headline' => pm_sx_split($s['topic'])[0], 'sub' => pm_sx_clip(rtrim($f[0], '.'), 110)];
            break;
        case 'idea':
            [$h, $rest] = pm_sx_split($f[0]);
            $r = ['caption' => pm_sx_join([pm_sx_end($f[0]), 'Try it this week.', $cta]), 'headline' => pm_sx_up($h), 'sub' => pm_sx_clip($rest, 110)];
            break;
        case 'support':
            $r = ['caption' => pm_sx_join([pm_sx_end(implode(' ', array_slice($f, 0, 2))), $cta]), 'headline' => $s['topic'], 'sub' => 'By WhatsApp, email, phone and remote session'];
            break;
        case 'fact':
            if (!empty($s['proof_id'])) { // a client quote or an offer: their words, unchanged
                $text = trim((string)$f[0]);
                $who = trim((string)($s['host'] ?? ''));
                if ($class === 'offer') {
                    $end = !empty($s['ends']) ? ' Ends ' . date('j F Y', strtotime($s['ends'])) . '.' : '';
                    $r = ['caption' => pm_sx_join([pm_sx_end($text) . $end, $cta]), 'headline' => pm_sx_split($text)[0], 'sub' => $end !== '' ? trim($end) : '', 'hook' => 'offer'];
                } else {
                    $r = ['caption' => pm_sx_join(['“' . rtrim($text, '.”"') . '”' . ($who !== '' ? ' — ' . $who : ''), $cta]), 'headline' => pm_sx_split($text)[0], 'sub' => $who, 'hook' => 'quote'];
                }
                break;
            }
            [$h] = pm_sx_split($f[0]);
            $r = ['caption' => pm_sx_join(['Did you know?', pm_sx_end($f[0]), $cta]), 'headline' => pm_sx_up($h), 'sub' => ''];
            break;
        case 'magnet':
            $line = $f[0];
            $more = array_slice($f, 1, 2);
            $open = $brand === 'travel'
                ? ['Run an independent lodge, guest house or B&B in Malawi?', 'Want guests to find you and book you directly?', 'Is your stay easy for travellers to find?']
                : ['Not sure how your website looks to a customer?', 'Do customers find you online?', 'Wondering what your website is missing?'];
            $k = array_search($s['topic'], $brand === 'travel' ? ['List your stay with Travel Malawi', 'Direct bookings for independent stays', 'Put your lodge in front of travellers'] : ['A closer look at your website', 'Do customers find you online?', 'Not sure what your website is missing?'], true);
            $paras = [$open[$k === false ? 0 : $k], $more ? implode(' ', array_map('pm_sx_end', $more)) : '', pm_sx_end($line)];
            $r = ['caption' => pm_sx_join($paras), 'headline' => $brand === 'travel' ? 'List your stay' : 'A closer look at your website', 'sub' => pm_sx_clip(rtrim($line, '.'), 100), 'hook' => 'magnet'];
            break;
        case 'season':
            $r = ['caption' => pm_sx_join([pm_sx_end($f[0]), $cta]), 'headline' => $s['topic'], 'sub' => '', 'hook' => 'season'];
            break;
        case 'place':
            $p = (string)$s['place'];
            $lines = [];
            foreach ($f as $x) { // the owner's own words, with the label turned into a line
                $lines[] = pm_sx_up(trim(preg_replace('/^' . preg_quote($p, '/') . ',\s*/i', '', $x)));
            }
            $r = ['caption' => pm_sx_join([$p . "\n" . implode("\n", $lines), $cta]), 'headline' => $p, 'sub' => pm_sx_clip(rtrim((string)($lines[1] ?? $lines[0] ?? ''), '.'), 110), 'hook' => 'place'];
            break;
        case 'stay':
            $host = trim((string)($s['host'] ?? ''));
            $r = ['caption' => pm_sx_join([($host !== '' ? "Stay spotlight: $host\n" : '') . pm_sx_end($f[0]), $cta]), 'headline' => $host !== '' ? $host : pm_sx_split($f[0])[0], 'sub' => $host !== '' ? pm_sx_clip(rtrim($f[0], '.'), 100) : '', 'hook' => 'spot'];
            break;
        case 'occasion':
            $fact = pm_sx_end($f[0]);
            $tail = $brand === 'travel' ? 'A good moment to think about a short stay.' : 'A good moment to check your stock, your prices and your opening hours.';
            $r = ['caption' => pm_sx_join([$fact, $tail, $cta]), 'headline' => $s['topic'], 'sub' => rtrim($f[0], '.') === $s['topic'] ? '' : pm_sx_clip(rtrim($f[0], '.'), 100), 'hook' => 'occasion'];
            break;
        case 'giveaway':
            $g = (array)($slot['giveaway'] ?? []);
            $r = ['caption' => pm_giveaway_text($brand, $g), 'headline' => 'Win ' . pm_sx_clip((string)($g['prize'] ?? ''), 50), 'sub' => 'Comment to enter. Closes ' . date('j F', strtotime((string)($g['end'] ?? 'today'))), 'hook' => 'giveaway'];
            break;
    }
    $r += ['sub' => '', 'slides' => [], 'headline' => $s['topic'], 'caption' => '', 'hook' => 'plain']; // every template names its hook (the bank's ledger records it)
    return $r;
}

/* ---------------- AI copy ---------------- */

function pm_sx_cta_instruction(string $cta): string
{
    return ['comment' => 'end with one short question readers can answer in a comment', 'save' => 'invite them to save the post', 'share' => 'invite them to share it with someone who needs it',
        'tag' => 'invite them to tag a friend who would enjoy it (no prize, no giveaway)', 'whatsapp' => 'invite them to send a WhatsApp message (no number, it is added later)',
        'check' => 'invite them to send the word CHECK on WhatsApp, as the facts say', 'host' => 'invite hosts to send the word HOST on WhatsApp, as the facts say'][$cta] ?? 'end with a gentle invitation';
}

/** Does this slot need the AI? Quotes, offers and giveaways are filled from the owner's own text. */
function pm_sx_slot_needs_ai(array $slot): bool
{
    return !in_array(pm_sx_pillar_class((string)$slot['pillar']), ['proof', 'offer', 'giveaway'], true) && $slot['seed']['kind'] !== 'giveaway';
}

/** [system, user] for one batch call. Pure: easy to test. */
function pm_sx_prompt(string $brand, string $company, string $brief, array $items, string $start, string $learn): array
{
    $travel = $brand === 'travel';
    $system = $brief . "\nYou write short social posts for $company in Malawi" . ($travel ? ' for lodge, guest house, B&B and safari camp owners, and for travellers' : ' for small business owners') . ".\n"
        . "Write one post for each item. Per post: caption of 25 to 70 words in plain, warm English, one idea. Use ONLY the facts given for that post. Never invent numbers, names, places, prices, results, guarantees, response times or claims like best, cheapest, fastest, leading, number one. "
        . "Say Kwacha amounts only if a fact gives one. Say free or no commission only if a fact says so. No hashtags, links, emoji or phone numbers. Do not start with Hello or the business name. "
        . "Follow the item's hook pattern, audience and cta. headline: at most 7 words (printed on the picture). sub: at most 12 words. "
        . "slides: only when the item asks for them, each {h (at most 6 words), t (at most 14 words)}, from the facts only. video: only for a reel: {hook_2s, shots [3 to 5 of {secs, show, say, onscreen}], total_secs (at most 30), cta, cover_text, music_note}. "
        . ($learn !== '' ? "What has worked for this audience: $learn " : '') . 'Reply with JSON only.';
    $user = "Start date $start (Malawi).\nITEMS: " . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\nJSON: {\"posts\":[{\"i\":0,\"caption\":\"\",\"headline\":\"\",\"sub\":\"\",\"slides\":[{\"h\":\"\",\"t\":\"\"}],\"video\":{}}]}";
    return [$system, $user];
}

function pm_sx_clean_ai(string $t): string
{
    $t = preg_replace('#https?://\S+|www\.\S+#i', '', $t);
    $t = preg_replace('/(?<!\w)#[\p{L}\p{N}_]+/u', '', $t);
    $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $t);
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim(preg_replace('/ +([,.!?])/', '$1', $t));
}

function pm_sx_clean_video(mixed $v, string $cta): array
{
    if (!is_array($v)) {
        return [];
    }
    $shots = [];
    foreach (array_slice((array)($v['shots'] ?? []), 0, 5) as $sh) {
        if (is_array($sh) && trim((string)($sh['show'] ?? '')) !== '') {
            $shots[] = ['secs' => max(1, min(15, (int)($sh['secs'] ?? 5))), 'show' => mb_substr(trim((string)$sh['show']), 0, 140), 'say' => mb_substr(pm_sx_clean_ai((string)($sh['say'] ?? '')), 0, 160), 'onscreen' => mb_substr(pm_sx_clean_ai((string)($sh['onscreen'] ?? '')), 0, 60)];
        }
    }
    if (count($shots) < 2) {
        return [];
    }
    return ['hook_2s' => mb_substr(pm_sx_clean_ai((string)($v['hook_2s'] ?? '')), 0, 100), 'shots' => $shots, 'total_secs' => min(30, array_sum(array_column($shots, 'secs'))),
        'cta' => mb_substr(pm_sx_clean_ai((string)($v['cta'] ?? '')), 0, 100), 'cover_text' => mb_substr(pm_sx_clean_ai((string)($v['cover_text'] ?? '')), 0, 50), 'music_note' => mb_substr(pm_sx_clean_ai((string)($v['music_note'] ?? '')), 0, 100)];
}

function pm_sx_video_script(array $v): string
{
    $o = ['Hook (first 2 seconds): ' . ($v['hook_2s'] ?? '')];
    foreach ((array)($v['shots'] ?? []) as $i => $s) {
        $o[] = 'Shot ' . ($i + 1) . ' (' . (int)$s['secs'] . 's): ' . $s['show'] . ($s['say'] !== '' ? ' | Say: ' . $s['say'] : '') . ($s['onscreen'] !== '' ? ' | On screen: ' . $s['onscreen'] : '');
    }
    if (($v['cta'] ?? '') !== '') {
        $o[] = 'End: ' . $v['cta'];
    }
    return implode("\n", $o);
}

/* ---------------- building rows ---------------- */

function pm_sx_default_time(string $brand, int $i): string
{
    $h = function_exists('pm_social_hours') ? pm_social_hours($brand) : ['07:30', '12:30', '18:30'];
    return $h[$i % max(1, count($h))] ?? '18:30';
}

/** Maps an experiment arm's instruction to a CTA, when the experiment is about the call to action. */
function pm_sx_cta_from_instruction(string $ins, string $fallback): string
{
    foreach (['question' => 'comment', 'comment' => 'comment', 'whatsapp' => 'whatsapp', 'save' => 'save', 'share' => 'share', 'tag' => 'tag'] as $k => $v) {
        if (stripos($ins, $k) !== false) {
            return $v;
        }
    }
    return $fallback;
}

/** One saved-post row from a slot and its copy. */
function pm_sx_row(array $slot, array $copy, string $brand, int $i, bool $offline): array
{
    $s = $slot['seed'];
    $format = $slot['format'];
    $slides = array_slice(array_values($copy['slides'] ?? []), 0, 6);
    $items = (array)($s['items'] ?? []);
    if ($items && !$slides) {
        foreach (array_slice($items, 0, 6) as $it) {
            $slides[] = ['h' => pm_sx_clip(pm_sx_up($it['h']), 60), 't' => pm_sx_clip((string)($it['t'] ?? ''), 120)];
        }
    }
    $video = (array)($copy['video'] ?? []);
    if ($format === 'carousel' && count($slides) < 2) {
        $format = 'image';
    }
    if ($format === 'reel' && !$video) {
        $format = 'image';
    }
    if ($format !== 'carousel' && $format !== 'reel' && $items && count($slides) >= 2) {
        $slides = array_slice($slides, 0, 5); // a checklist picture
    } elseif (!in_array($format, ['carousel'], true) && !in_array($copy['hook'] ?? '', ['myth', 'beforeafter'], true) && !$items) {
        $slides = [];
    }
    $headline = pm_sx_clip(pm_card_clean_text((string)($copy['headline'] ?? '')), 60);
    $sub = pm_sx_clip(pm_card_clean_text((string)($copy['sub'] ?? '')), 140);
    $p = [
        'id' => bin2hex(random_bytes(10)), 'brand' => $brand, 'status' => 'draft', 'when' => date('Y-m-d') . ' ' . pm_sx_default_time($brand, $i), '_off' => $i,
        'format' => $format, 'pillar' => $slot['pillar'], 'cta' => $slot['cta'], 'proof_id' => (string)($slot['proof_id'] ?? ''),
        'caption' => trim((string)$copy['caption']), 'hashtags' => [], 'headline' => $headline, 'sub' => $sub, 'script' => $video ? pm_sx_video_script($video) : '',
        'media' => '', 'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [], 'created' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? ''),
        'seed' => $s['id'], 'seed_kind' => $s['kind'], 'facts' => array_slice($s['facts'], 0, 8), 'audience' => (string)$slot['audience'], 'hook_pattern' => (string)($copy['hook'] ?? $slot['hook_pattern']),
        'offline' => $offline, 'tokens' => 0,
    ];
    if (isset($s['place'])) {
        $p['place'] = $s['place'];
        $p['place_id'] = (string)($s['place_id'] ?? '');
    }
    if (!empty($s['host'])) {
        $p['host'] = $s['host'];
    }
    if ($slides) {
        $p['slides'] = $slides;
    }
    if ($video) {
        $p['video'] = $video;
    }
    foreach (['asset_id', 'occasion', 'occasion_date', 'expires', 'giveaway'] as $k) {
        if (!empty($slot[$k])) {
            $p[$k] = $slot[$k];
        }
    }
    $p['layout'] = pm_card_layout_for($p + ['format' => $format]);
    if (!empty($items) && $format === 'image' && count($slides) >= 2) {
        $p['layout'] = 'checklist';
    }
    if ($p['layout'] === 'photo' && empty($p['asset_id'])) {
        $p['layout'] = 'headline';
    }
    $p['alt'] = trim($headline . ' ' . $sub);
    $p['segment'] = function_exists('pm_sx_segment') ? pm_sx_segment($p, $brand) : ''; // C2-A05: the audience this post speaks to
    return $p;
}

/** card text cleaner that works even if sx_cards is not loaded */
function pm_card_clean_text(string $t): string
{
    return function_exists('pm_card_clean') ? pm_card_clean($t) : trim(preg_replace('/\s+/', ' ', $t));
}

/** After the rows exist: spread over days (core cadence), hashtags, lint, status, ledger. */
function pm_sx_finish(array $rows, string $brand, string $start, array $existing): array
{
    $rows = pm_social_cadence($rows, $existing, $brand, $start);
    $pool = $existing;
    $seedDates = [];
    $tagDates = [];
    $hookDates = [];
    foreach ($rows as $i => $p) {
        $p['hashtags'] = pm_social_hashtags($p, 'facebook');
        $d = substr((string)$p['when'], 0, 10);
        $why = pm_social_lint((string)$p['caption'], $brand, 'facebook', pm_social_recent_from($pool, $brand, $p['id']), $p);
        $warn = [];
        foreach (pm_lint_ext($p, 'facebook', $pool) as $r) {
            if ($r['sev'] === 'block') {
                $why[] = $r['msg'];
            } else {
                $warn[] = $r['msg'];
            }
        }
        $p = pm_social_resolve($p, array_values(array_unique($why)));
        $p['warn'] = $warn;
        if (!empty($p['needs_asset_flag'])) {
            unset($p['needs_asset_flag']);
        }
        $rows[$i] = $p;
        $pool[] = $p;
        $seedDates[$p['seed']] = $d;
        $hookDates[$p['hook_pattern']] = $d;
        foreach ($p['hashtags'] as $t) {
            $tagDates[$t] = $d;
        }
        if (!empty($p['asset_id'])) {
            pm_social_asset_use((string)$p['asset_id'], (string)$p['id']);
        }
    }
    pm_bank_mark($brand, $seedDates, $tagDates, $hookDates);
    return array_values($rows);
}

/** A slot that wants a photo and has none waits as needs_asset (a draft that cannot be approved yet). */
function pm_sx_apply_needs_asset(array $rows, array $slots): array
{
    foreach ($rows as $i => $p) {
        if (!empty($slots[$p['_slot'] ?? -1]['needs_asset']) && ($p['status'] ?? '') === 'draft' && empty($p['asset_id'])) {
            $rows[$i]['status'] = 'needs_asset';
        }
        unset($rows[$i]['_slot']);
    }
    return $rows;
}

/** Shared pipeline. $useAi false = templates only. */
function pm_sx_build(string $brand, int $n, string $start, string $focus, bool $useAi): array
{
    $existing = pm_social_posts();
    $slots = pm_social_seed_pick($brand, $n, $existing, $focus, $start);
    if (!$slots) {
        throw new RuntimeException('No content ideas are free right now (every one was used in the last 45 days). Add ideas in the Content tab.');
    }
    // experiments (measure package): an arm's instruction goes to the writer; its CTA/hour may change the slot
    $bank = pm_bank_get($brand);
    $exp = [];
    foreach ($slots as $i => $slot) {
        $e = function_exists('pm_experiment_for_slot') ? pm_experiment_for_slot($brand, $i) : null;
        if (!$e || empty($e['instruction'])) {
            continue;
        }
        $ins = (string)$e['instruction'];
        $field = (string)($e['field'] ?? '');
        if ($field === 'lang' && stripos($ins, 'chichewa') !== false) {
            $lines = array_values(array_filter(array_map(fn($r) => trim((string)($r['text'] ?? '')), (array)$bank['chichewa'])));
            if (!$lines) {
                continue; // never machine-translated: without the owner's own lines, no Chichewa arm
            }
            $ins .= ' Open with this owner-written line, unchanged: "' . $lines[crc32($slot['seed']['id']) % count($lines)] . '".';
        }
        if ($field === 'cta') {
            $slots[$i]['cta'] = pm_sx_cta_from_instruction($ins, $slot['cta']);
        }
        $exp[$i] = ['id' => (string)($e['id'] ?? ''), 'arm' => (string)($e['arm'] ?? ''), 'ins' => $ins, 'field' => $field];
    }
    $copies = [];
    $aiIdx = [];
    foreach ($slots as $i => $slot) {
        if ($useAi && pm_sx_slot_needs_ai($slot)) {
            $aiIdx[] = $i;
        }
    }
    $tokens = 0;
    if ($aiIdx) {
        $t0 = function_exists('pm_usage_mark') ? pm_usage_mark() : 0;
        $s = pm_settings();
        $items = [];
        foreach ($aiIdx as $i) {
            $slot = $slots[$i];
            $it = ['i' => $i, 'pillar' => $slot['pillar'], 'hook' => pm_sx_hook_patterns()[$slot['hook_pattern']][0] ?? '', 'audience' => $slot['audience'], 'format' => $slot['format'],
                'cta' => pm_sx_cta_instruction((string)$slot['cta']), 'topic' => $slot['seed']['topic'], 'facts' => array_slice($slot['seed']['facts'], 0, 6)];
            if ($slot['format'] === 'carousel' && empty($slot['seed']['items'])) {
                $it['slides'] = 'write 2 to 4 slides';
            } elseif (!empty($slot['seed']['items'])) {
                $it['slides'] = 'not needed, the items are the slides';
            }
            if ($slot['format'] === 'reel') {
                $it['video'] = 'write the video object';
            }
            if (isset($exp[$i])) {
                $it['note'] = $exp[$i]['ins'];
            }
            $items[] = $it;
        }
        $learn = '';
        if (function_exists('pm_plan_learnings')) { // MARKETING.md MG-S01: outcomes, trends, cross-brand lessons and the audience mix
            $learn = trim((string)pm_plan_learnings($brand));
        }
        if ($learn === '' && function_exists('pm_social_learnings')) {
            $learn = trim((string)pm_social_learnings($brand));
        }
        if ($learn === '' && function_exists('pm_audit_learnings')) {
            $learn = trim((string)pm_audit_learnings($brand));
        }
        [$sys, $usr] = pm_sx_prompt($brand, (string)$s['company_name'], pm_agents_company_brief('tiny'), $items, $start, $learn);
        $out = pm_agent_json(pm_claude($sys, $usr, false, min(4500, 300 + 250 * count($aiIdx)), 'write'));
        foreach ((array)(is_array($out) ? ($out['posts'] ?? []) : []) as $r) {
            if (!is_array($r) || !isset($r['i']) || !in_array((int)$r['i'], $aiIdx, true)) {
                continue;
            }
            $cap = pm_sx_clean_ai((string)($r['caption'] ?? ''));
            if ($cap === '') {
                continue;
            }
            $sl = [];
            foreach (array_slice((array)($r['slides'] ?? []), 0, 6) as $x) {
                if (is_array($x) && (trim((string)($x['h'] ?? '')) !== '' || trim((string)($x['t'] ?? '')) !== '')) {
                    $sl[] = ['h' => mb_substr(pm_sx_clean_ai((string)($x['h'] ?? '')), 0, 60), 't' => mb_substr(pm_sx_clean_ai((string)($x['t'] ?? '')), 0, 120)];
                }
            }
            $copies[(int)$r['i']] = ['caption' => $cap, 'headline' => pm_sx_clean_ai((string)($r['headline'] ?? '')), 'sub' => pm_sx_clean_ai((string)($r['sub'] ?? '')), 'slides' => $sl,
                'video' => pm_sx_clean_video($r['video'] ?? null, (string)$slots[(int)$r['i']]['cta']), 'hook' => $slots[(int)$r['i']]['hook_pattern']];
        }
        $tokens = function_exists('pm_usage_mark') ? max(0, pm_usage_mark() - $t0) : 0;
    }
    $rows = [];
    foreach ($slots as $i => $slot) {
        $ai = isset($copies[$i]);
        $copy = $copies[$i] ?? pm_sx_offline_copy($slot, $brand);
        if (!$ai) { // templates know their own hook; keep slot and copy consistent
            $slot['hook_pattern'] = $copy['hook'];
        }
        $row = pm_sx_row($slot, $copy, $brand, $i, !$ai && pm_sx_slot_needs_ai($slot) ? true : !$useAi);
        if (!$ai && !pm_sx_slot_needs_ai($slot)) {
            $row['offline'] = false; // a quote, offer or giveaway is always filled from the owner's text
        }
        if (isset($exp[$i]) && $ai) {
            $row['exp'] = $exp[$i]['id'];
            $row['arm'] = $exp[$i]['arm'];
            if ($exp[$i]['field'] === 'hour' && preg_match('/\b(\d{1,2}:\d{2})\b/', $exp[$i]['ins'], $m)) {
                $row['when'] = date('Y-m-d') . ' ' . str_pad($m[1], 5, '0', STR_PAD_LEFT);
            }
        }
        $row['_slot'] = $i;
        $rows[] = $row;
    }
    $per = $aiIdx ? (int)round($tokens / count($rows)) : 0;
    foreach ($rows as $i => $r) {
        $rows[$i]['tokens'] = $per;
    }
    $rows = pm_sx_finish($rows, $brand, $start, $existing);
    return pm_sx_apply_needs_asset($rows, $slots);
}

/**
 * A batch for the brand in use: one AI call (tier 'write') for the posts that need writing. Throws when the daily budget is used up
 * (the core then falls back to pm_plan_offline). Rows have every legacy key plus seed, layout, audience, hook_pattern, slides, video, alt, offline, tokens.
 */
function pm_plan_posts(int $n, string $focus, string $start): array
{
    pm_budget_check();
    return pm_sx_build(pm_brand(), max(1, min(14, $n)), $start, $focus, true);
}

/** The same batch with NO AI: templates from the facts. Always drafts; passes the same lint. */
function pm_plan_offline(string $brand, int $n, string $start): array
{
    $brand = pm_sx_brand($brand);
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        return pm_sx_build($brand, max(1, min(14, $n)), $start, 'mix', false);
    } finally {
        pm_brand_set($prev);
    }
}
