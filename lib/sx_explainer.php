<?php
/**
 * The one-page ad: one picture that explains the business to one audience, step by step, like a poster: who it is for, the promise, how easy it is to start
 * (three or four numbered steps with drawn icons), what they get, our goal, and how to reach us (with a QR code). For Travel Malawi's hosts: what we offer,
 * what they get, how easy it is to set up.
 *   The meeting: the department heads decide it together in ONE cheap AI call (Thandi chairs for Social; Amara brings the month's goal, Mphatso what customers
 *   ask and worry about, Tiwo the words, Jabu what sells). They may use only the business's facts, offerings and the steps its proposals promise; a line with
 *   a number nobody gave, a link or a boast is dropped and the plain version, built from the facts by code, takes its place.
 *   The picture is drawn by code (GD), icons included, in the business's colours and logo: free, and redrawn on every change.
 *   The owner fine-tunes it by chatting with Thandi (each turn one cheap AI call that returns the whole ad; Undo goes back a step) or by editing the words,
 *   and approves it. Approval puts it in the plan as an approved post and tells every department, on the team board, the promise the ad makes.
 * Data: data/explainers.json {brand: [{id, audience, focus, ad, lines, why, status, chat, prev, at, by, approved_at, post}]} (newest first, 8 kept);
 * pictures in data/explainers/<id>-share.png (1080x1350), -print.png (A4) and -a4.pdf.
 */

/** The icons the team may choose, drawn by code: key => what it shows. */
const PM_EX_ICONS = ['user' => 'a person (sign up, an account)', 'users' => 'people (partners, a team, guests)', 'house' => 'a building (a property, a shop, an office)',
    'camera' => 'a camera (photos)', 'check' => 'a tick (checked, approved, done)', 'star' => 'a star (quality, reviews, going live)', 'chat' => 'a speech bubble (chat, WhatsApp, answers)',
    'calendar' => 'a calendar (bookings, dates, sync)', 'free' => 'a price tag with 0 (free, no fee, no commission)', 'phone' => 'a mobile phone', 'globe' => 'a globe (online, seen everywhere)',
    'laptop' => 'a laptop (software, websites, computers)', 'gear' => 'a gear (set-up, support, running smoothly)', 'shield' => 'a shield (safe, secure, trusted)',
    'box' => 'a parcel (sourcing, delivery)', 'bolt' => 'a lightning bolt (fast, easy)', 'pin' => 'a map pin (a place, directions)', 'chart' => 'rising bars (growth, more bookings or sales)',
    'game' => 'a game controller (gaming gear)', 'heart' => 'a heart (care, local, together)'];

/** How the ad is illustrated: drawn icons only (free), or AI illustrations (each new one about 5 US cents; the same description is never paid twice). */
const PM_EX_PICS = ['icons' => 'Drawn icons (free)', 'hero' => 'An AI illustration at the top (about 5 US cents)', 'all' => 'AI illustrations at the top and for every step (about 20 to 25 US cents)'];

/** What every AI picture description must keep to (the honesty rules of lib/sx_aiimage.php, in words the team can follow). */
const PM_EX_PIC_RULES = 'Each picture description is one short scene for a friendly flat illustration: generic places, objects and hands or silhouettes only, never this business\'s own rooms, buildings, products, staff or customers, never a real person, and no words, numbers or logos in the picture.';

/* ---------------- storage ---------------- */

function pm_ex_all(string $brand): array
{
    return array_values(array_filter((array)(pm_load('explainers', fn() => [])[$brand] ?? []), 'is_array'));
}

function pm_ex_get(string $brand, string $id): ?array
{
    foreach (pm_ex_all($brand) as $x) {
        if (($x['id'] ?? '') === $id) {
            return $x;
        }
    }
    return null;
}

/** Locked change of one ad (or a new one when $id is ''); $fn(array $ad): array. Returns the stored ad. */
function pm_ex_put(string $brand, string $id, callable $fn): ?array
{
    $out = null;
    pm_update('explainers', function (array $all) use ($brand, $id, $fn, &$out) {
        $list = array_values(array_filter((array)($all[$brand] ?? []), 'is_array'));
        $i = $id === '' ? null : array_search($id, array_column($list, 'id'), true);
        if ($id !== '' && $i === false) {
            return $all;
        }
        $ad = $fn($i === null ? [] : $list[$i]);
        if ($i === null) {
            array_unshift($list, $ad);
            foreach (array_slice($list, 8) as $old) {
                pm_ex_drop_files((string)($old['id'] ?? ''));
            }
            $list = array_slice($list, 0, 8);
        } else {
            $list[$i] = $ad;
        }
        $all[$brand] = $list;
        $out = $ad;
        return $all;
    }, fn() => []);
    return $out;
}

function pm_ex_dir(): string
{
    $d = PM_DATA . '/explainers';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

/** The ad's picture files that exist: share|print|a4 => path. */
function pm_ex_files(string $id): array
{
    $out = [];
    foreach (['share' => '-share.png', 'print' => '-print.png', 'a4' => '-a4.pdf'] as $k => $sfx) {
        $f = PM_DATA . '/explainers/' . preg_replace('/[^a-f0-9]/', '', $id) . $sfx;
        if (is_file($f)) {
            $out[$k] = $f;
        }
    }
    return $out;
}

function pm_ex_drop_files(string $id): void
{
    foreach (pm_ex_files($id) as $f) {
        @unlink($f);
    }
}

/* ---------------- what the team works from ---------------- */

/** Who the ad can be for: the business's audiences, split from Social > Voice, and its target segments. */
function pm_ex_audiences(string $brand): array
{
    $out = array_map(fn($x) => trim(rtrim($x, '. ')), preg_split('/;|\.\s/', (string)(pm_brain($brand)['audience'] ?? '')) ?: []);
    foreach (pm_brand_targeting($brand)['segments'] as $s) {
        $out[] = 'Owners of ' . trim((string)$s['name']);
    }
    return array_values(array_unique(array_filter($out, fn($x) => mb_strlen($x) > 3)));
}

/** The offerings as [short name => full line] ("Listing" => "Listing: a free listing..."). */
function pm_ex_offerings(string $brand): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    $offs = (array)(pm_agents_config()['offerings'] ?? []);
    pm_brand_set($was);
    $out = [];
    foreach ($offs as $o) {
        $o = trim((string)$o);
        $k = str_contains($o, ':') ? trim(explode(':', $o)[0]) : mb_substr($o, 0, 40);
        if ($k !== '') {
            $out[$k] = $o;
        }
    }
    return $out;
}

/** The steps a proposal promises for this focus (Template and prices > Getting started): [[name, what], ...]. */
function pm_ex_steps_source(string $brand, string $focus): array
{
    if (pm_brand_is_custom($brand)) {
        return [];
    }
    $tpl = pm_template();
    if ($brand === 'travel') {
        $type = (string)($tpl['packages'][pm_onboarding_index()]['type'] ?? 'onboarding');
    } else {
        $f = strtolower($focus);
        $type = match (true) {
            (bool)preg_match('/sourc|laptop|computer|gaming|hardware|deliver/', $f) => 'hardware',
            (bool)preg_match('/support|backup|security/', $f) => 'support',
            (bool)preg_match('/website|web app|software|build|app/', $f) => 'web',
            default => '',
        };
        if ($type === '') {
            return [];
        }
    }
    return array_map(fn($r) => [trim((string)$r[0]), trim((string)($r[2] ?? ''))], array_slice(array_values(array_filter((array)(pm_apply_line($tpl, $type)['steps'] ?? []), fn($r) => trim((string)($r[0] ?? '')) !== '')), 0, 6));
}

/** Everything the words may come from (the guard checks numbers against it). */
function pm_ex_source(string $brand, string $audience, string $focus): string
{
    $b = pm_brain($brand);
    return implode("\n", array_merge([(string)$b['about'], (string)$b['facts'], $audience, $focus, pm_brand_name($brand)], array_values(pm_ex_offerings($brand)),
        array_map(fn($s) => $s[0] . ' ' . $s[1], pm_ex_steps_source($brand, $focus)), [pm_camp_cta($brand, [])['line']]));
}

/* ---------------- the ad ---------------- */

/** An ad made by code from the business's own words: the fallback, and the shape every ad has. */
function pm_ex_plain(string $brand, string $audience, string $focus): array
{
    $b = pm_brain($brand);
    $offs = pm_ex_offerings($brand);
    $facts = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$b['facts']) ?: [])));
    $cut = function (string $t, int $words): string {
        $w = preg_split('/\s+/u', trim(preg_replace('/\s*\(.*?\)|[:;].*$/u', '', $t)));
        return rtrim(implode(' ', array_slice($w, 0, $words)), ',. ');
    };
    $steps = [];
    foreach (pm_ex_steps_source($brand, $focus) as $i => [$n, $what]) {
        $steps[] = ['icon' => ['user', 'camera', 'check', 'star', 'chat'][$i] ?? 'check', 'title' => $cut($n, 4), 'text' => $cut($what, 12)];
    }
    if (count($steps) < 3) {
        $steps = [['icon' => 'chat', 'title' => 'Tell us what you need', 'text' => 'A short message is enough to start'], ['icon' => 'gear', 'title' => 'We set it up', 'text' => 'We do the work and keep you posted'],
            ['icon' => 'star', 'title' => 'You are ready', 'text' => 'Use it from day one, with us to help']];
    }
    $gets = [];
    foreach (array_slice($focus !== '' && isset($offs[$focus]) ? [$offs[$focus]] + $facts : array_merge(array_values($offs), $facts), 0, 4) as $i => $g) {
        $gets[] = ['icon' => ['free', 'chat', 'calendar', 'shield'][$i], 'text' => $cut(str_contains($g, ':') ? trim(explode(':', $g, 2)[1]) : $g, 9)];
    }
    $about = trim((string)(pm_opt_facts($brand)['tagline'] ?? '')) ?: trim((string)$b['about']);
    $first = trim((string)preg_split('/(?<=[.!?])\s/u', $about)[0]);
    return ['eyebrow' => mb_substr($audience !== '' ? $audience : 'For you', 0, 60), 'headline' => $cut($first !== '' ? $first : pm_brand_name($brand), 9), 'sub' => '',
        'steps' => array_slice($steps, 0, 4), 'gets' => array_slice($gets, 0, 4), 'goal' => '', 'cta' => pm_camp_cta($brand, [])['short']];
}

/** Keeps an ad to its shape and the facts: each field cut to size, a line the guard refuses replaced by the plain one, icons from the set. */
function pm_ex_clean(array $ad, array $plain, string $src): array
{
    $ok = fn(string $t, int $lim) => $t !== '' && pm_opt_ai_ok($t, $lim, $src);
    $txt = fn($v, int $lim) => rtrim(pm_card_clean(pm_swarm_clean($v ?? '', $lim)), ' .');
    $out = [];
    foreach (['eyebrow' => 60, 'headline' => 70, 'sub' => 120, 'goal' => 110, 'cta' => 60] as $k => $lim) {
        $t = $txt($ad[$k] ?? '', $lim + 20);
        $out[$k] = $ok($t, $lim) ? $t : (string)($plain[$k] ?? '');
    }
    foreach (['steps' => [3, 4, ['title' => 34, 'text' => 80]], 'gets' => [2, 4, ['text' => 60]]] as $k => [$min, $max, $fields]) {
        $rows = [];
        foreach (array_slice((array)($ad[$k] ?? []), 0, $max) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $row = ['icon' => isset(PM_EX_ICONS[$r['icon'] ?? '']) ? (string)$r['icon'] : 'check'];
            foreach ($fields as $f => $lim) {
                $row[$f] = $txt($r[$f] ?? '', $lim + 20);
            }
            if (count(array_filter($fields, fn($lim, $f) => $ok($row[$f], $lim), ARRAY_FILTER_USE_BOTH)) === count($fields)) {
                $rows[] = $row;
            }
        }
        $out[$k] = count($rows) >= $min ? $rows : (array)$plain[$k];
    }
    $out['caption'] = trim((string)($ad['caption'] ?? ''));
    $pics = (array)($ad['pics'] ?? $plain['pics'] ?? []);
    $pic = fn($v) => pm_swarm_clean(preg_replace('~https?://\S+~', '', (string)$v), 240);
    $out['pics'] = ['hero' => $pic($pics['hero'] ?? ''), 'steps' => array_slice(array_map($pic, array_values((array)($pics['steps'] ?? []))), 0, count($out['steps']))];
    return $out;
}

/** Descriptions for the pictures the ad still lacks, made by code (no AI call): the top from the business and the headline, each step from its words. */
function pm_ex_pics_fill(string $brand, array $ad): array
{
    $p = (array)($ad['pics'] ?? []) + ['hero' => '', 'steps' => []];
    if (trim((string)$p['hero']) === '') {
        $p['hero'] = function_exists('pm_aiimg_brief') ? pm_aiimg_brief($brand, (string)$ad['headline']) : (string)$ad['headline'];
    }
    foreach ($ad['steps'] as $i => $st) {
        if (trim((string)($p['steps'][$i] ?? '')) === '') {
            $p['steps'][$i] = 'A simple, friendly picture of this step: ' . $st['title'] . ' (' . $st['text'] . '), shown with generic objects and hands only';
        }
    }
    $p['steps'] = array_slice(array_values((array)$p['steps']), 0, count($ad['steps']));
    $ad['pics'] = $p;
    return $ad;
}

/**
 * The AI pictures the ad's mode asks for, made (or taken from the cache: the same description is never paid twice).
 * Returns ['hero' => path|'', 'steps' => [i => path|''], 'made' => new pictures paid for, 'why' => what went wrong or ''].
 */
function pm_ex_pictures(string $brand, array $x): array
{
    $mode = (string)($x['pics_mode'] ?? 'icons');
    $out = ['hero' => '', 'steps' => [], 'made' => 0, 'why' => ''];
    if ($mode === 'icons' || !function_exists('pm_aiimg_make') || !pm_aiimg_ready($brand)) {
        $out['why'] = $mode !== 'icons' ? 'AI pictures are not set up (a Gemini key is needed), so the drawn icons are used.' : '';
        return $out;
    }
    @set_time_limit(300);
    $ad = pm_ex_pics_fill($brand, (array)$x['ad']);
    $make = function (string $brief, int $w, int $h, string $style) use ($brand, &$out): string {
        $r = (array)pm_aiimg_make($brand, $brief, $w, $h, $style);
        if (!empty($r['ok']) && !empty($r['path']) && is_file((string)$r['path'])) {
            $out['made'] += empty($r['cached']) ? 1 : 0;
            return (string)$r['path'];
        }
        $out['why'] = $out['why'] ?: 'A picture did not come back' . (($r['why'] ?? '') !== '' ? ' (' . mb_substr((string)$r['why'], 0, 120) . ')' : '') . ', so a drawn icon stands in.';
        return '';
    };
    $out['hero'] = $make((string)$ad['pics']['hero'], 1600, 640, 'illustration');
    if ($mode === 'all') {
        foreach ($ad['steps'] as $i => $st) {
            $out['steps'][$i] = $make((string)$ad['pics']['steps'][$i], 640, 640, 'spot');
        }
    }
    return $out;
}

/** Copies a picture into a square with rounded corners (the corners take the page colour). */
function pm_ex_round_copy($im, $src, int $x, int $y, int $size, int $r, array $paper): void
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    $side = min($sw, $sh);
    imagecopyresampled($im, $src, $x, $y, (int)(($sw - $side) / 2), (int)(($sh - $side) / 2), $size, $size, $side, $side);
    $col = imagecolorallocate($im, $paper[0], $paper[1], $paper[2]);
    for ($k = 0; $k < 4; $k++) { // the four corners: 0 top left, 1 top right, 2 bottom left, 3 bottom right
        $ccx = $k % 2 ? $x + $size - $r : $x + $r;
        $ccy = $k < 2 ? $y + $r : $y + $size - $r;
        for ($yy = 0; $yy < $r; $yy++) {
            for ($xx = 0; $xx < $r; $xx++) {
                $px = $k % 2 ? $ccx + $xx : $ccx - $xx - 1;
                $py = $k < 2 ? $ccy - $yy - 1 : $ccy + $yy;
                if (($xx + 0.5) ** 2 + ($yy + 0.5) ** 2 > $r * $r) {
                    imagesetpixel($im, $px, $py, $col);
                }
            }
        }
    }
}

/** The post text that goes with the picture, by code: the promise, the steps, what they get, how to reach us. */
function pm_ex_caption(string $brand, array $ad): string
{
    $cta = pm_camp_cta($brand, []);
    $t = trim($ad['headline'] . ($ad['sub'] !== '' ? '. ' . $ad['sub'] : '')) . ".\n\nHow it works:\n";
    foreach ($ad['steps'] as $i => $s) {
        $t .= ($i + 1) . '. ' . $s['title'] . ': ' . lcfirst($s['text']) . "\n";
    }
    $t .= "\nWhat you get:\n" . implode("\n", array_map(fn($g) => '- ' . $g['text'], $ad['gets'])) . "\n\n" . ($ad['goal'] !== '' ? $ad['goal'] . ".\n\n" : '') . $cta['line'] . '.';
    return str_replace('..', '.', $t);
}

/**
 * The meeting: the heads decide the ad together in one cheap AI call. Returns ['ad', 'lines' => [[dept, says]], 'why', 'ai' => bool].
 * Without the AI (or when it fails) the ad is built from the facts by code and the meeting says so.
 */
function pm_ex_meet(string $brand, string $audience, string $focus, string $mode = 'icons'): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $offs = pm_ex_offerings($brand);
        $plain = pm_ex_plain($brand, $audience, $focus);
        $src = pm_ex_source($brand, $audience, $focus);
        if (!pm_agents_ready()) {
            return ['ad' => $plain + ['caption' => pm_ex_caption($brand, $plain)], 'lines' => [['social', 'No AI key yet, so I built the ad from our own words. Add a key under Operations > Connections and the team will meet on it.']], 'why' => '', 'ai' => false];
        }
        $dir = function_exists('pm_director_today') ? pm_director_today() : [];
        $asked = [];
        foreach (pm_tb_rows($brand, 30) as $r) {
            if (($r['from'] ?? '') === 'replies' || ($r['to'] ?? '') === 'social') {
                $asked[] = mb_substr((string)$r['text'], 0, 160);
            }
        }
        $n = fn($d) => pm_agent_first($d);
        $system = 'The department heads of ' . pm_brand_name($brand) . ' meet to decide ONE single-page picture ad that explains the business, step by step, to one audience: who it is for, '
            . 'the promise, how easy it is to start (3 or 4 numbered steps), what they get (4 short points), our goal in one line, and how to reach us. Plain words a busy owner reads in five seconds, simple like an illustrated poster. '
            . "At the table: {$n('social')} (Social, chairs and decides), {$n('director')} (Director: the month's goal and what matters most), {$n('replies')} (Replies: what these people ask and worry about), "
            . "{$n('writers')} (Writer: the words), {$n('advisor')} (Advisor: what sells). Each says one short line, then {$n('social')} decides.\n"
            . pm_agents_company_brief('tiny') . "\n"
            . 'Rules: use only the facts, the offerings and the steps given: no number, price, time, place, feature or claim they do not give, no link, no "best" or "guaranteed", no exclamation marks. '
            . 'The steps say how easy it is to start, from the steps given when there are any. Headline at most 8 words, sub at most 14, each step title at most 4 words and text at most 12, each point at most 8 words, the goal at most 14, the call to action at most 6 words and an invitation to take the first step (the WhatsApp number or website is printed under it, so do not repeat it). '
            . 'Icons only from: ' . implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys(PM_EX_ICONS), PM_EX_ICONS)) . '. '
            . ($mode !== 'icons' ? 'The ad also carries AI illustrations, because pictures speak louder than words: in "pics" describe the picture for the top (the feeling of the promise, a wide scene) '
                . ($mode === 'all' ? 'and one picture per step, in the same order (what that step looks like, simply)' : '') . '. ' . PM_EX_PIC_RULES . ' ' : '')
            . 'Reply JSON only: {"lines":[{"who":"social|director|replies|writers|advisor","says":""}],"why":"one line: why this ad","ad":{"eyebrow":"who it is for","headline":"","sub":"","steps":[{"icon":"","title":"","text":""}],"gets":[{"icon":"","text":""}],"goal":"","cta":""'
            . ($mode !== 'icons' ? ',"pics":{"hero":""' . ($mode === 'all' ? ',"steps":[""]' : '') . '}' : '') . '}}';
        $user = json_encode(array_filter(['audience' => $audience, 'focus' => $focus !== '' ? ($offs[$focus] ?? $focus) : 'everything we offer', 'offerings' => array_values($offs),
            'steps_our_proposals_promise' => array_map(fn($s) => $s[0] . ': ' . $s[1], pm_ex_steps_source($brand, $focus)) ?: null,
            'directors_focus_today' => mb_substr((string)($dir['headline'] ?? $dir['summary'] ?? ''), 0, 300) ?: null, 'what_customers_tell_us' => array_slice($asked, 0, 6) ?: null,
            'how_to_reach_us' => pm_camp_cta($brand, [])['line']]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $GLOBALS['PM_AI_REF'] = 'explainer_meet';
        $out = pm_agent_json(pm_claude($system, $user, false, 1400, 'cheap'));
        if (!is_array($out) || !is_array($out['ad'] ?? null)) {
            throw new RuntimeException('no ad');
        }
        $ad = pm_ex_clean((array)$out['ad'], $plain, $src);
        $ad['caption'] = pm_ex_caption($brand, $ad);
        $lines = [];
        foreach (array_slice((array)($out['lines'] ?? []), 0, 8) as $l) {
            $who = in_array($l['who'] ?? '', ['social', 'director', 'replies', 'writers', 'advisor'], true) ? (string)$l['who'] : 'social';
            $says = pm_swarm_clean($l['says'] ?? '', 260);
            $says !== '' && $lines[] = [$who, $says];
        }
        foreach ($lines as [$w, $s]) {
            pm_talk($brand, $w, $s, $w === 'social' ? '' : 'social');
        }
        return ['ad' => $ad, 'lines' => $lines, 'why' => pm_swarm_clean($out['why'] ?? '', 200), 'ai' => true];
    } catch (Throwable) {
        $plain = pm_ex_plain($brand, $audience, $focus);
        return ['ad' => $plain + ['caption' => pm_ex_caption($brand, $plain)], 'lines' => [['social', 'The meeting did not come back this time, so here is the ad built from our own words. Press Call the team again to retry.']], 'why' => '', 'ai' => false];
    } finally {
        pm_brand_set($was);
    }
}

/** One turn of the chat with the head of Social: the whole ad comes back changed as asked. Returns ['say', 'ad' or null, 'lesson', 'lesson_for']. */
function pm_ex_chat_turn(string $brand, array $x, string $ask): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $src = pm_ex_source($brand, (string)$x['audience'], (string)$x['focus']) . "\n" . $ask;
        $chat = array_slice((array)($x['chat'] ?? []), -8);
        $system = 'You are ' . pm_agent_first('social') . ', head of Social at ' . pm_brand_name($brand) . '. The team made this single-page picture ad and the owner is fine-tuning it with you. '
            . pm_agents_company_brief('tiny') . "\n"
            . 'When the owner asks for a change, make it and return the WHOLE ad with the change in "ad" (keep what they did not ask to change). When they only ask a question, answer it and set "ad" to null. '
            . 'Same rules as the meeting: only our facts and what the owner says; no new numbers, prices, times, claims or links; headline at most 8 words, sub at most 14, 3 or 4 steps (title at most 4 words, text at most 12), '
            . '2 to 4 points of at most 8 words, goal at most 14, call to action at most 6. "caption" is the post text that goes with the picture: keep it, or change it if asked. Icons only from: ' . implode(', ', array_keys(PM_EX_ICONS)) . '. '
            . (($x['pics_mode'] ?? 'icons') !== 'icons' ? 'The ad carries AI illustrations described in "pics" (the top, and one per step when there are step pictures): change a description when the owner asks for a different picture, and keep the others word for word (each new picture costs money). ' . PM_EX_PIC_RULES . ' ' : '')
            . '"say": one or two short sentences to the owner, first person. "lesson": only when the wish should hold for everything we write, as one short standing rule; else empty. '
            . 'Reply JSON only: {"say":"","ad":{"eyebrow":"","headline":"","sub":"","steps":[{"icon":"","title":"","text":""}],"gets":[{"icon":"","text":""}],"goal":"","cta":"","caption":"","pics":{"hero":"","steps":[""]}},"lesson":"","lesson_for":"voice|never"}';
        $user = json_encode(['the_ad_now' => $x['ad'], 'chat_so_far' => $chat ?: null, 'owner_now' => mb_substr($ask, 0, 600)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $GLOBALS['PM_AI_REF'] = 'explainer_chat';
        $out = pm_agent_json(pm_claude($system, $user, false, 1400, 'cheap'));
        if (!is_array($out)) {
            throw new RuntimeException(pm_agent_first('social') . ' did not answer. Try again.');
        }
        $ad = null;
        if (is_array($out['ad'] ?? null)) {
            $ad = pm_ex_clean((array)$out['ad'], (array)$x['ad'], $src);
            $cap = trim((string)($out['ad']['caption'] ?? ''));
            $ad['caption'] = $cap !== '' && !array_diff(pm_regen_links($cap), pm_regen_links((string)($x['ad']['caption'] ?? '') . "\n" . $src)) ? pm_regen_sourced($cap, $src . "\n" . ($x['ad']['caption'] ?? '')) ?: (string)$x['ad']['caption'] : (string)($x['ad']['caption'] ?? '');
            if ($ad == $x['ad']) {
                $ad = null;
            }
        }
        return ['say' => pm_swarm_clean($out['say'] ?? '', 400) ?: ($ad ? 'Done: the picture is redrawn.' : 'Tell me what to change.'), 'ad' => $ad,
            'lesson' => pm_swarm_clean($out['lesson'] ?? '', 160), 'lesson_for' => ($out['lesson_for'] ?? '') === 'never' ? 'never' : 'voice'];
    } finally {
        pm_brand_set($was);
    }
}

/* ---------------- the picture ---------------- */

/** One icon, drawn at 4x on its own tile and scaled down (smooth edges): a filled circle of $bg with the symbol in $fg, centred on ($cx, $cy). */
function pm_ex_icon($im, string $key, int $cx, int $cy, int $d, array $bg, array $fg, array $fonts): void
{
    $S = 4;
    $D = max(8, $d * $S);
    $t = imagecreatetruecolor($D, $D);
    imagesavealpha($t, true);
    imagealphablending($t, false);
    imagefill($t, 0, 0, imagecolorallocatealpha($t, 0, 0, 0, 127));
    imagealphablending($t, true);
    $B = imagecolorallocate($t, $bg[0], $bg[1], $bg[2]);
    $F = imagecolorallocate($t, $fg[0], $fg[1], $fg[2]);
    imagefilledellipse($t, (int)($D / 2), (int)($D / 2), $D - 2, $D - 2, $B);
    $u = $D / 100; // the symbol is drawn on a 100 x 100 grid
    $P = fn(array $pts) => array_map(fn($v) => (int)round($v * $u), $pts);
    $line = function (float $x1, float $y1, float $x2, float $y2, float $w) use ($t, $F, $u) {
        imagesetthickness($t, max(1, (int)round($w * $u)));
        imageline($t, (int)($x1 * $u), (int)($y1 * $u), (int)($x2 * $u), (int)($y2 * $u), $F);
        imagesetthickness($t, 1);
    };
    $ring = function (float $x, float $y, float $w, float $h, float $th) use ($t, $F, $B, $u) {
        imagefilledellipse($t, (int)($x * $u), (int)($y * $u), (int)($w * $u), (int)($h * $u), $F);
        imagefilledellipse($t, (int)($x * $u), (int)($y * $u), (int)(($w - 2 * $th) * $u), (int)(($h - 2 * $th) * $u), $B);
    };
    $box = function (float $x1, float $y1, float $x2, float $y2, bool $fill = true) use ($t, $F, $u) {
        $fill ? imagefilledrectangle($t, (int)($x1 * $u), (int)($y1 * $u), (int)($x2 * $u), (int)($y2 * $u), $F) : imagerectangle($t, (int)($x1 * $u), (int)($y1 * $u), (int)($x2 * $u), (int)($y2 * $u), $F);
    };
    $hole = function (float $x1, float $y1, float $x2, float $y2) use ($t, $B, $u) {
        imagefilledrectangle($t, (int)($x1 * $u), (int)($y1 * $u), (int)($x2 * $u), (int)($y2 * $u), $B);
    };
    $dot = fn(float $x, float $y, float $r, bool $hollow = false) => imagefilledellipse($t, (int)($x * $u), (int)($y * $u), (int)(2 * $r * $u), (int)(2 * $r * $u), $hollow ? $B : $F);
    switch ($key) {
        case 'user':
            $dot(50, 38, 13);
            imagefilledarc($t, (int)(50 * $u), (int)(78 * $u), (int)(48 * $u), (int)(44 * $u), 180, 360, $F, IMG_ARC_PIE);
            break;
        case 'users':
            $dot(38, 40, 11);
            imagefilledarc($t, (int)(38 * $u), (int)(76 * $u), (int)(38 * $u), (int)(38 * $u), 180, 360, $F, IMG_ARC_PIE);
            $dot(63, 37, 12);
            imagefilledarc($t, (int)(63 * $u), (int)(76 * $u), (int)(42 * $u), (int)(42 * $u), 180, 360, $F, IMG_ARC_PIE);
            break;
        case 'house':
            imagefilledpolygon($t, $P([50, 24, 77, 48, 23, 48]), $F);
            $box(31, 47, 69, 75);
            $hole(45, 58, 55, 75);
            break;
        case 'camera':
            $box(25, 37, 75, 71);
            $box(40, 30, 58, 38);
            $dot(50, 54, 12, true);
            $dot(50, 54, 7);
            break;
        case 'check':
            $line(30, 52, 45, 66, 9);
            $line(45, 66, 71, 36, 9);
            break;
        case 'star':
            $pts = [];
            for ($i = 0; $i < 10; $i++) {
                $r = $i % 2 ? 12 : 27;
                $a = -M_PI / 2 + $i * M_PI / 5;
                $pts[] = 50 + $r * cos($a);
                $pts[] = 52 + $r * sin($a);
            }
            imagefilledpolygon($t, $P($pts), $F);
            break;
        case 'chat':
            imagefilledellipse($t, (int)(50 * $u), (int)(47 * $u), (int)(54 * $u), (int)(40 * $u), $F);
            imagefilledpolygon($t, $P([33, 58, 30, 76, 47, 64]), $F);
            foreach ([38, 50, 62] as $x) {
                $dot($x, 47, 3.6, true);
            }
            break;
        case 'calendar':
            $box(27, 31, 73, 73);
            $hole(31, 43, 69, 69);
            $box(35, 25, 40, 35);
            $box(60, 25, 65, 35);
            foreach ([[37, 50], [47, 50], [57, 50], [37, 59], [47, 59]] as [$x, $y]) {
                $box($x, $y, $x + 6, $y + 5);
            }
            break;
        case 'free':
            imagefilledpolygon($t, $P([30, 32, 60, 32, 76, 50, 60, 68, 30, 68]), $F);
            $dot(64, 50, 4, true);
            imagettftext($t, 22 * $u, 0, (int)(35 * $u), (int)(61 * $u), $B, $fonts['sansb'], '0');
            break;
        case 'phone':
            $box(36, 24, 64, 76);
            $hole(39, 30, 61, 64);
            $dot(50, 70, 3, true);
            break;
        case 'globe':
            $ring(50, 50, 52, 52, 5);
            $ring(50, 50, 24, 52, 4.5);
            $line(25, 50, 75, 50, 4.5);
            break;
        case 'laptop':
            $box(30, 32, 70, 62);
            $hole(34, 36, 66, 58);
            imagefilledpolygon($t, $P([24, 64, 76, 64, 80, 71, 20, 71]), $F);
            break;
        case 'gear':
            for ($i = 0; $i < 8; $i++) {
                $a = $i * M_PI / 4;
                $line(50 + 17 * cos($a), 50 + 17 * sin($a), 50 + 27 * cos($a), 50 + 27 * sin($a), 9);
            }
            $dot(50, 50, 19);
            $dot(50, 50, 8, true);
            break;
        case 'shield':
            imagefilledpolygon($t, $P([50, 24, 73, 33, 71, 56, 50, 77, 29, 56, 27, 33]), $F);
            // the tick, in the background colour on the shield
            imagesetthickness($t, max(1, (int)round(5.5 * $u)));
            imageline($t, (int)(40 * $u), (int)(51 * $u), (int)(48 * $u), (int)(59 * $u), $B);
            imageline($t, (int)(48 * $u), (int)(59 * $u), (int)(61 * $u), (int)(43 * $u), $B);
            imagesetthickness($t, 1);
            break;
        case 'box':
            imagefilledpolygon($t, $P([50, 24, 76, 36, 50, 48, 24, 36]), $F);
            imagefilledpolygon($t, $P([24, 40, 48, 52, 48, 78, 24, 66]), $F);
            imagefilledpolygon($t, $P([52, 52, 76, 40, 76, 66, 52, 78]), $F);
            break;
        case 'bolt':
            imagefilledpolygon($t, $P([56, 22, 32, 55, 48, 55, 42, 78, 68, 44, 52, 44]), $F);
            break;
        case 'pin':
            $dot(50, 42, 17);
            imagefilledpolygon($t, $P([36, 50, 64, 50, 50, 77]), $F);
            $dot(50, 42, 7, true);
            break;
        case 'chart':
            $box(28, 56, 38, 72);
            $box(44, 46, 54, 72);
            $box(60, 32, 70, 72);
            $line(25, 75, 75, 75, 4);
            break;
        case 'game':
            imagefilledellipse($t, (int)(36 * $u), (int)(55 * $u), (int)(26 * $u), (int)(32 * $u), $F);
            imagefilledellipse($t, (int)(64 * $u), (int)(55 * $u), (int)(26 * $u), (int)(32 * $u), $F);
            $box(36, 41, 64, 63);
            $hole(31, 50, 41, 54);
            $hole(34, 47, 38, 57);
            $dot(61, 49, 3.2, true);
            $dot(67, 55, 3.2, true);
            break;
        case 'heart':
            $dot(39, 42, 13);
            $dot(61, 42, 13);
            imagefilledpolygon($t, $P([26.5, 46, 73.5, 46, 50, 74]), $F);
            break;
        default:
            $dot(50, 50, 10);
    }
    imagecopyresampled($im, $t, (int)round($cx - $d / 2), (int)round($cy - $d / 2), 0, 0, $d, $d, $D, $D);
    imagedestroy($t);
}

/** A colour between two (0 = $a, 1 = $b). */
function pm_ex_mix(array $a, array $b, float $t): array
{
    return [(int)round($a[0] + ($b[0] - $a[0]) * $t), (int)round($a[1] + ($b[1] - $a[1]) * $t), (int)round($a[2] + ($b[2] - $a[2]) * $t)];
}

/**
 * Draws the ad at $W x $H into $file: the band (logo, who it is for, the headline and promise), "How it works" (numbered steps on a path, each with its icon),
 * "What you get" (tiles with icons), our goal, the call-to-action panel with a QR code, and the website. Returns the path or ''.
 */
function pm_ex_draw(string $brand, array $ad, int $W, int $H, string $file): string
{
    if (!function_exists('pm_card_gd_ok') || !pm_card_gd_ok()) {
        return '';
    }
    $was = pm_brand();
    pm_brand_set($brand);
    $im = null;
    try {
        $s = pm_settings();
        $f = pm_card_fonts();
        $k = $W / 1080;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        $c = ['im' => $im, 'W' => $W, 'H' => $H, 's' => $k, 'f' => $f];
        $accent = pm_card_rgb((string)($s['accent_color'] ?? ''));
        $on = pm_card_on($accent);
        $ink = [22, 24, 29];
        $soft = [70, 74, 82];
        $paper = [250, 249, 247];
        $tint = pm_ex_mix($accent, [255, 255, 255], 0.88);
        $deep = pm_ex_mix($accent, [0, 0, 0], 0.25);
        imagefilledrectangle($im, 0, 0, $W, $H, pm_card_fill($c, $paper));
        $pad = (int)round(64 * $k);
        $px = fn(float $v) => (int)round($v * $k);
        $cta = pm_camp_cta($brand, []);
        $heroPic = (string)($ad['_hero'] ?? '');
        $stepPics = array_filter((array)($ad['_steps'] ?? []), fn($p) => $p !== '' && is_file($p));
        $load = fn(string $p) => $p !== '' && is_file($p) ? (@imagecreatefromstring((string)file_get_contents($p)) ?: null) : null;
        // the band: the AI illustration under a wash of the brand colour (strong where the words are), or the brand colour with soft shapes
        $heroIm = $load($heroPic);
        $heroPic = $heroIm ? $heroPic : '';
        $bandH = (int)round($H * ($heroIm ? ($stepPics ? 0.265 : 0.3) : ($ad['sub'] !== '' ? 0.275 : 0.245)));
        imagefilledrectangle($im, 0, 0, $W, $bandH, pm_card_fill($c, $accent));
        if ($heroIm) {
            $sw = imagesx($heroIm);
            $sh = imagesy($heroIm);
            $sc = max($W / $sw, $bandH / $sh);
            imagecopyresampled($im, $heroIm, 0, 0, (int)max(0, ($sw - $W / $sc) / 2), (int)max(0, ($sh - $bandH / $sc) / 2), $W, $bandH, (int)ceil($W / $sc), (int)ceil($bandH / $sc));
            imagedestroy($heroIm);
            for ($xx = 0; $xx < $W; $xx++) { // opaque behind the words on the left, opening up to the picture on the right
                $alpha = (int)round(127 * max(0, min(1, ($xx / $W - 0.34) / 0.5)) * 0.82);
                imageline($im, $xx, 0, $xx, $bandH, imagecolorallocatealpha($im, $accent[0], $accent[1], $accent[2], min(127, $alpha)));
            }
            $lbl = 'AI illustration';
            $lpx = $px(18);
            $lw = pm_card_tw($f['sans'], $lpx, $lbl);
            pm_card_rrect($c, $W - $pad - $lw - $px(20), $bandH - $px(46), $W - $pad, $bandH - $px(14), $px(14), [20, 20, 20]);
            pm_card_draw($c, [$lbl], $f['sans'], $lpx, [255, 255, 255], $W - $pad - $lw - $px(10), $bandH - $px(42), 1.2);
        } else {
            imagefilledellipse($im, $W - $px(70), $px(40), $px(420), $px(420), pm_card_fill($c, pm_ex_mix($accent, [255, 255, 255], 0.1)));
            imagefilledellipse($im, $W + $px(60), $bandH - $px(10), $px(300), $px(300), pm_card_fill($c, pm_ex_mix($accent, [0, 0, 0], 0.08)));
        }
        imagefilledrectangle($im, 0, $bandH, $W, $H, pm_card_fill($c, $paper)); // the shapes stay inside the band
        $textW = $heroPic !== '' ? (int)round($W * 0.6) - $pad : $W - 2 * $pad; // over a picture the words keep to the coloured side
        $y = $px(48);
        $logo = function_exists('pm_logo_path') ? pm_logo_path() : '';
        if ($logo !== '' && ($li = @imagecreatefrompng($logo))) {
            $lh = $px(64);
            $lw = min($px(240), (int)round(imagesx($li) * $lh / max(1, imagesy($li))));
            $lh = (int)round(imagesy($li) * $lw / max(1, imagesx($li)));
            pm_card_rrect($c, $pad - $px(12), $y - $px(12), $pad + $lw + $px(12), $y + $lh + $px(12), $px(14), [255, 255, 255]);
            imagecopyresampled($im, $li, $pad, $y, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
            imagedestroy($li);
            $nx = $pad + $lw + $px(34);
            [$npx, $nl] = pm_card_fit((string)pm_brand_name($brand), $f['sansb'], $px(34), $px(22), $W - $nx - $pad, $lh, 1.1);
            pm_card_draw($c, array_slice($nl, 0, 1), $f['sansb'], $npx, $on, $nx, $y + (int)(($lh - $npx) / 2), 1.1);
            $y += $lh + $px(34);
        } else {
            pm_card_draw($c, [pm_brand_name($brand)], $f['sansb'], $px(34), $on, $pad, $y, 1.1);
            $y += $px(70);
        }
        $eb = mb_strtoupper(trim((string)$ad['eyebrow']));
        if ($eb !== '') {
            $gapE = (int)max(1, 2 * $k);
            $epx = $px(24);
            while ($epx > $px(15) && pm_card_spaced_w($c, $eb, $epx, $gapE) > $textW) {
                $epx -= 1;
            }
            while (mb_strlen($eb) > 10 && pm_card_spaced_w($c, $eb, $epx, $gapE) > $textW) { // still too long: whole words off the end
                $eb = preg_replace('/\s+\S*$/u', '', $eb);
            }
            pm_card_spaced($c, rtrim($eb, ' ,;:'), $epx, pm_ex_mix($on, $accent, 0.15), $pad, $y, $gapE);
            $y += $px(42);
        }
        $subH = $ad['sub'] !== '' ? $px($heroPic !== '' ? 40 : 72) : 0; // over a picture the headline comes first, the promise takes what is left
        [$hpx, $hl] = pm_card_fit((string)$ad['headline'], $f['serifb'], $px(76), $px(40), $textW - ($heroPic !== '' ? 0 : $px(40)), max($px(60), $bandH - $y - $subH - $px(26)), 1.08);
        $y = pm_card_draw($c, $hl, $f['serifb'], $hpx, $on, $pad, $y, 1.08);
        if ($ad['sub'] !== '') {
            [$spx, $sl] = pm_card_fit((string)$ad['sub'], $f['sans'], $px(32), $px(22), $textW, max($px(30), $bandH - $y - $px(18)), 1.25);
            pm_card_draw($c, $sl, $f['sans'], $spx, pm_ex_mix($on, $accent, 0.1), $pad, $y + $px(10), 1.25);
        }
        // the sections below share what is left
        $footH = $px(58);
        $ctaH = (int)round($H * 0.135);
        $ctaTop = $H - $footH - $ctaH;
        $goalH = $ad['goal'] !== '' ? $px(64) : 0;
        $top = $bandH + $px(40);
        $avail = $ctaTop - $px(26) - $goalH - $top;
        $nSteps = count($ad['steps']);
        $rowsG = (int)ceil(count($ad['gets']) / 2);
        $labH = $px(52);
        $stepsH = (int)round(($avail - 2 * $labH) * ($stepPics ? 0.62 : $nSteps / ($nSteps + $rowsG * 0.95)));
        $stepsH = min($stepsH, $avail - 2 * $labH - $px(22) - ($rowsG * $px(84) + max(0, $rowsG - 1) * $px(18))); // what they get keeps room for two lines a tile
        $getsH = $avail - 2 * $labH - $stepsH - $px(22);
        $label = function (string $t, int $y) use ($c, $f, $accent, $pad, $px) {
            pm_card_spaced($c, mb_strtoupper($t), $px(22), $accent, $pad, $y, (int)max(1, 2 * $px(1)));
            imagefilledrectangle($c['im'], $pad, $y + $px(34), $pad + $px(46), $y + $px(38), pm_card_fill($c, $accent));
        };
        // how it works: numbered steps on a path
        $label('How it works', $top);
        $y0 = $top + $labH;
        $rowH = (int)floor($stepsH / max(1, $nSteps));
        $d = (int)min($px(92), $rowH * 0.78);
        $cx = $pad + (int)($d / 2);
        $tx = $pad + $d + $px(30);
        $tw = $W - $tx - $pad;
        if ($stepPics) { // pictures for the steps: a row of cards, each with its picture, its number, a title and a line
            $gapS = $px(16);
            $cardW = (int)floor(($W - 2 * $pad - ($nSteps - 1) * $gapS) / max(1, $nSteps));
            $img = (int)min($cardW, $stepsH - $px(126));
            $tpxAll = $px(30);
            foreach ($ad['steps'] as $st) { // one title size and one text size for every card: the longest decides
                $tpxAll = min($tpxAll, pm_card_fit((string)$st['title'], $f['sansb'], $px(30), $px(18), $cardW, $px(70), 1.12)[0]);
            }
            $titleH = 0;
            foreach ($ad['steps'] as $st) {
                $titleH = max($titleH, (int)ceil(min(2, count(pm_card_wrap((string)$st['title'], $f['sansb'], $tpxAll, $cardW))) * $tpxAll * 1.12) + 2);
            }
            $txtRoom = max($px(30), $stepsH - $img - $px(14) - $titleH - $px(6));
            $bpxAll = $px(23);
            foreach ($ad['steps'] as $st) {
                $bpxAll = min($bpxAll, pm_card_fit((string)$st['text'], $f['sans'], $px(23), $px(15), $cardW, $txtRoom, 1.2)[0]);
            }
            foreach ($ad['steps'] as $i => $st) {
                $x1 = $pad + $i * ($cardW + $gapS);
                $ix = $x1 + (int)(($cardW - $img) / 2);
                $pi = $load((string)($ad['_steps'][$i] ?? ''));
                pm_card_rrect($c, $ix, $y0, $ix + $img, $y0 + $img, $px(20), $tint);
                if ($pi) {
                    pm_ex_round_copy($im, $pi, $ix, $y0, $img, $px(20), $paper);
                    imagedestroy($pi);
                } else {
                    pm_ex_icon($im, (string)$st['icon'], $ix + (int)($img / 2), $y0 + (int)($img / 2), (int)($img * 0.5), $accent, $on, $f);
                }
                $nd = $px(46);
                $ncx = $ix + $px(10) + (int)($nd / 2);
                $ncy = $y0 + $px(10) + (int)($nd / 2);
                imagefilledellipse($im, $ncx, $ncy, $nd + $px(6), $nd + $px(6), pm_card_fill($c, $paper));
                imagefilledellipse($im, $ncx, $ncy, $nd, $nd, pm_card_fill($c, $deep));
                $num = (string)($i + 1);
                $npx = $nd * 0.6;
                pm_card_draw($c, [$num], $f['sansb'], $npx, pm_card_on($deep), $ncx - (int)(pm_card_tw($f['sansb'], $npx, $num) / 2), $ncy - (int)($npx * 0.55), 1.0);
                $ty = $y0 + $img + $px(14);
                [$tpx, $tl] = pm_card_fit((string)$st['title'], $f['sansb'], (int)$tpxAll, (int)$tpxAll, $cardW, $titleH, 1.12);
                pm_card_draw($c, array_slice($tl, 0, 2), $f['sansb'], $tpx, $ink, $x1, $ty, 1.12, 'center', $cardW);
                [$bpx, $bl] = pm_card_fit((string)$st['text'], $f['sans'], (int)$bpxAll, $px(15), $cardW, $txtRoom, 1.2);
                pm_card_draw($c, $bl, $f['sans'], $bpx, $soft, $x1, $ty + $titleH + $px(6), 1.2, 'center', $cardW);
            }
        }
        for ($i = 0; !$stepPics && $i < $nSteps - 1; $i++) { // the path between the circles, dashed
            $ya = $y0 + $i * $rowH + (int)($rowH / 2) + (int)($d / 2);
            $yb = $y0 + ($i + 1) * $rowH + (int)($rowH / 2) - (int)($d / 2);
            for ($yy = $ya + $px(6); $yy < $yb - $px(4); $yy += $px(16)) {
                imagefilledrectangle($im, $cx - $px(3), $yy, $cx + $px(3), min($yb - $px(4), $yy + $px(8)), pm_card_fill($c, pm_ex_mix($accent, [255, 255, 255], 0.45)));
            }
        }
        foreach ($stepPics ? [] : $ad['steps'] as $i => $st) {
            $cy = $y0 + $i * $rowH + (int)($rowH / 2);
            pm_ex_icon($im, (string)$st['icon'], $cx, $cy, $d, $accent, $on, $f);
            $nd = (int)($d * 0.42);
            imagefilledellipse($im, $cx + (int)($d * 0.36), $cy - (int)($d * 0.36), $nd + $px(6), $nd + $px(6), pm_card_fill($c, $paper));
            imagefilledellipse($im, $cx + (int)($d * 0.36), $cy - (int)($d * 0.36), $nd, $nd, pm_card_fill($c, $deep));
            $num = (string)($i + 1);
            $npx = $nd * 0.62;
            pm_card_draw($c, [$num], $f['sansb'], $npx, pm_card_on($deep), $cx + (int)($d * 0.36) - (int)(pm_card_tw($f['sansb'], $npx, $num) / 2), $cy - (int)($d * 0.36) - (int)($npx * 0.55), 1.0);
            [$tpx, $tl] = pm_card_fit((string)$st['title'], $f['sansb'], $px(38), $px(24), $tw, (int)($rowH * 0.42), 1.12);
            [$bpx, $bl] = pm_card_fit((string)$st['text'], $f['sans'], $px(29), $px(19), $tw, (int)($rowH * 0.5), 1.22);
            $blockH = (int)(count($tl) * $tpx * 1.12 + $px(6) + count($bl) * $bpx * 1.22);
            $ty = $cy - (int)($blockH / 2);
            $ty = pm_card_draw($c, $tl, $f['sansb'], $tpx, $ink, $tx, $ty, 1.12);
            pm_card_draw($c, $bl, $f['sans'], $bpx, $soft, $tx, $ty + $px(6), 1.22);
        }
        // what you get: tiles
        $gTop = $y0 + $stepsH + $px(22);
        $label('What you get', $gTop);
        $gy = $gTop + $labH;
        $gap = $px(18);
        $cols = count($ad['gets']) === 1 ? 1 : 2;
        $tileW = (int)(($W - 2 * $pad - ($cols - 1) * $gap) / $cols);
        $tileH = (int)min($px(150), ($getsH - ($rowsG - 1) * $gap) / max(1, $rowsG));
        $idG = (int)min($px(64), $tileH * 0.55);
        $gw = $tileW - $px(22) - $idG - $px(18) - $px(18);
        $gpxAll = $px(29);
        foreach ($ad['gets'] as $g) { // one text size for every tile: the longest decides
            $gpxAll = min($gpxAll, pm_card_fit((string)$g['text'], $f['sansb'], $px(29), $px(18), $gw, $tileH - $px(24), 1.2)[0]);
        }
        foreach ($ad['gets'] as $i => $g) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $x1 = $pad + $col * ($tileW + $gap);
            $y1 = $gy + $row * ($tileH + $gap);
            pm_card_rrect($c, $x1, $y1, $x1 + $tileW, $y1 + $tileH, $px(22), $tint);
            $id = (int)min($px(64), $tileH * 0.55);
            pm_ex_icon($im, (string)$g['icon'], $x1 + $px(22) + (int)($id / 2), $y1 + (int)($tileH / 2), $id, $accent, $on, $f);
            $gx = $x1 + $px(22) + $id + $px(18);
            [$gpx, $gl] = pm_card_fit((string)$g['text'], $f['sansb'], (int)$gpxAll, $px(18), $x1 + $tileW - $px(18) - $gx, $tileH - $px(24), 1.2);
            pm_card_draw($c, $gl, $f['sansb'], $gpx, $ink, $gx, $y1 + (int)(($tileH - count($gl) * $gpx * 1.2) / 2), 1.2);
        }
        // our goal
        if ($ad['goal'] !== '') {
            $goalY = $ctaTop - $px(20) - $goalH;
            [$opx, $ol] = pm_card_fit('Our goal: ' . $ad['goal'], $f['serif'], $px(30), $px(19), $W - 2 * $pad, $goalH - $px(8), 1.2);
            pm_card_draw($c, $ol, $f['serif'], $opx, $deep, $pad, $goalY + (int)(($goalH - count($ol) * $opx * 1.2) / 2), 1.2, 'center', $W - 2 * $pad);
        }
        // the call to action
        pm_card_rrect($c, $pad, $ctaTop, $W - $pad, $ctaTop + $ctaH - $px(8), $px(30), $accent);
        $qr = $ctaH - $px(40);
        $hasQr = pm_camp_qr($im, (string)$cta['link'], $W - $pad - $px(16) - $qr, $ctaTop + $px(16), $qr);
        $cw = $W - 2 * $pad - $px(64) - ($hasQr ? $qr + $px(24) : 0);
        $l2 = $cta['phone'] !== '' ? 'WhatsApp ' . $cta['phone'] : preg_replace('#^https?://(www\.)?#i', '', (string)$cta['site']);
        [$apx, $al] = pm_card_fit((string)($ad['cta'] ?: $cta['short']), $f['serifb'], $px(50), $px(26), $cw, (int)(($ctaH - $px(8)) * 0.55), 1.08);
        $bh = (int)(count($al) * $apx * 1.08) + ($l2 !== '' ? $px(48) : 0);
        $ty = $ctaTop + (int)(($ctaH - $px(8) - $bh) / 2);
        $ty = pm_card_draw($c, $al, $f['serifb'], $apx, $on, $pad + $px(32), $ty, 1.08);
        if ($l2 !== '') {
            [$lpx, $ll] = pm_card_fit($l2, $f['sansb'], $px(32), $px(18), $cw, $px(42), 1.15);
            pm_card_draw($c, array_slice($ll, 0, 1), $f['sansb'], $lpx, $on, $pad + $px(32), $ty + $px(8), 1.15);
        }
        $foot = implode('   ·   ', array_filter([preg_replace('#^https?://(www\.)?#i', '', rtrim((string)($s['website'] ?? ''), '/')), (string)($s['email'] ?? ''), $stepPics ? 'Illustrations made with AI' : '']));
        if ($foot !== '') {
            [$fpx, $fl] = pm_card_fit($foot, $f['sans'], $px(24), $px(15), $W - 2 * $pad, $px(32), 1.2);
            pm_card_draw($c, array_slice($fl, 0, 1), $f['sans'], $fpx, $soft, $pad, $H - $footH + $px(12), 1.2, 'center', $W - 2 * $pad);
        }
        imagepng($im, $file, 6);
        return is_file($file) && filesize($file) > 0 ? $file : '';
    } catch (Throwable) {
        return '';
    } finally {
        if ($im) {
            imagedestroy($im);
        }
        pm_brand_set($was);
    }
}

/** Draws the ad's files: the share picture (1080x1350), the A4 print picture and its PDF. Returns the share picture's path or ''. */
function pm_ex_render(string $brand, array $x): string
{
    $base = pm_ex_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$x['id']);
    $pics = pm_ex_pictures($brand, $x);
    $GLOBALS['PM_EX_PICS_NOTE'] = $pics;
    $ad = (array)$x['ad'] + ['_hero' => $pics['hero'], '_steps' => $pics['steps']];
    $share = pm_ex_draw($brand, $ad, 1080, 1350, $base . '-share.png');
    $print = pm_ex_draw($brand, $ad, 1654, 2339, $base . '-print.png');
    if ($print !== '' && class_exists('TCPDF')) {
        try {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);
            $pdf->SetTitle(pm_brand_name($brand) . ': ' . (string)$x['ad']['headline']);
            $pdf->AddPage();
            $pdf->Image($print, 0, 0, 210, 297, 'PNG');
            $pdf->Output($base . '-a4.pdf', 'F');
        } catch (Throwable) {
        }
    }
    return $share;
}

/* ---------------- what the buttons do ---------------- */

function pm_ex_back(string $msg, string $kind = 'ok', string $id = ''): array
{
    return ['msg' => $msg, 'kind' => $kind, 'to' => 'social&view=explainer' . ($id !== '' ? '&x=' . $id : '')];
}

/** Call the team together: a new ad for the audience and focus chosen. */
/** What happened to the AI pictures on the last drawing, for the owner: '' when nothing to say. */
function pm_ex_pics_msg(): string
{
    $n = (array)($GLOBALS['PM_EX_PICS_NOTE'] ?? []);
    return trim(((int)($n['made'] ?? 0) > 0 ? (int)$n['made'] . ' new AI illustration' . ((int)$n['made'] === 1 ? '' : 's') . ' made. ' : '') . (string)($n['why'] ?? ''));
}

function pm_do_ex_meet(string $vb): array
{
    @set_time_limit(400);
    $mode = isset(PM_EX_PICS[$_POST['pics'] ?? '']) ? (string)$_POST['pics'] : 'icons';
    $aud = mb_substr(trim((string)($_POST['audience'] ?? '')), 0, 120) ?: (pm_ex_audiences($vb)[0] ?? '');
    $focus = (string)($_POST['focus'] ?? '');
    $focus = isset(pm_ex_offerings($vb)[$focus]) ? $focus : '';
    $m = pm_ex_meet($vb, $aud, $focus, $mode);
    $id = bin2hex(random_bytes(6));
    $x = pm_ex_put($vb, '', fn() => ['id' => $id, 'audience' => $aud, 'focus' => $focus, 'pics_mode' => $mode, 'ad' => $m['ad'], 'lines' => $m['lines'], 'why' => $m['why'], 'status' => 'draft', 'chat' => [], 'prev' => [],
        'at' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? ''), 'ai' => $m['ai']]);
    if (!$x || pm_ex_render($vb, $x) === '') {
        return pm_ex_back('The ad was decided but the picture could not be drawn (check that GD and the fonts are there: Operations > Connections).', 'err', $id);
    }
    pm_tb_post($vb, 'social', 'director', 'We made a one-page ad for ' . ($aud ?: 'our audience') . ': "' . $x['ad']['headline'] . '". It waits for the owner\'s approval.', 'ex:' . $id);
    return pm_ex_back(trim(($m['ai'] ? 'The team met and decided the ad. Read it, chat with ' . pm_agent_first('social') . ' to fine-tune it, then approve it.' : $m['lines'][0][1]) . ' ' . pm_ex_pics_msg()), $m['ai'] ? 'ok' : 'err', $id);
}

/** Change how the ad is illustrated (drawn icons, or AI illustrations at the top or everywhere), and draw it again. */
function pm_do_ex_pics(string $vb): array
{
    @set_time_limit(400);
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['x'] ?? ''));
    $mode = isset(PM_EX_PICS[$_POST['pics'] ?? '']) ? (string)$_POST['pics'] : 'icons';
    $x = pm_ex_put($vb, $id, function (array $a) use ($vb, $mode) {
        if (($a['pics_mode'] ?? 'icons') !== $mode) {
            $a['pics_mode'] = $mode;
            $a['status'] = 'draft'; // a changed ad is approved again
            $mode !== 'icons' && $a['ad'] = pm_ex_pics_fill($vb, (array)$a['ad']);
        }
        return $a;
    });
    if (!$x) {
        return pm_ex_back('That ad is gone.', 'err');
    }
    pm_ex_render($vb, $x);
    return pm_ex_back(trim('Drawn again with ' . lcfirst(preg_replace('/ \(.*$/', '', PM_EX_PICS[$mode])) . '. ' . pm_ex_pics_msg()), 'ok', $id);
}

/** Chat with the head of Social: the ad changes as asked and is redrawn. */
function pm_do_ex_chat(string $vb): array
{
    @set_time_limit(400);
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['x'] ?? ''));
    $ask = trim((string)($_POST['ask'] ?? ''));
    $x = pm_ex_get($vb, $id);
    if (!$x || $ask === '') {
        return pm_ex_back($x ? 'Type what you would like changed.' : 'That ad is gone.', 'err', $id);
    }
    if (!pm_agents_ready()) {
        return pm_ex_back('The chat needs an AI key (Operations > Connections). You can still change the words by hand below.', 'err', $id);
    }
    try {
        $r = pm_ex_chat_turn($vb, $x, $ask);
    } catch (Throwable $e) {
        return pm_ex_back($e->getMessage(), 'err', $id);
    }
    $x = pm_ex_put($vb, $id, function (array $a) use ($r, $ask) {
        $a['chat'][] = ['who' => 'owner', 'said' => mb_substr($ask, 0, 600), 'at' => date('H:i')];
        $a['chat'][] = ['who' => 'social', 'said' => $r['say'], 'at' => date('H:i'), 'lesson' => $r['lesson'], 'lesson_for' => $r['lesson_for'], 'changed' => (bool)$r['ad']];
        $a['chat'] = array_slice($a['chat'], -30);
        if ($r['ad']) {
            $a['prev'][] = $a['ad'];
            $a['prev'] = array_slice($a['prev'], -10);
            $a['ad'] = $r['ad'];
            $a['status'] = 'draft'; // a changed ad is approved again
        }
        return $a;
    });
    $r['ad'] && $x && pm_ex_render($vb, $x);
    return pm_ex_back(trim(($r['ad'] ? 'Redrawn.' : 'Answered.') . ' ' . ($r['ad'] ? pm_ex_pics_msg() : '')), 'ok', $id);
}

/** The owner's own words for the ad (the form under the picture). */
function pm_do_ex_save(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['x'] ?? ''));
    $x = pm_ex_get($vb, $id);
    if (!$x) {
        return pm_ex_back('That ad is gone.', 'err');
    }
    $rows = fn(string $k, array $cols) => array_values(array_filter(array_map(function ($l) use ($cols) {
        $p = array_map('trim', explode('|', $l));
        if (count($p) < count($cols)) {
            array_unshift($p, 'check');
        }
        return array_combine($cols, array_pad(array_slice($p, 0, count($cols)), count($cols), ''));
    }, array_filter(array_map('trim', preg_split('/\R/', (string)($_POST[$k] ?? '')) ?: []))), fn($r) => trim(end($r)) !== ''));
    $own = ['eyebrow' => (string)($_POST['eyebrow'] ?? ''), 'headline' => (string)($_POST['headline'] ?? ''), 'sub' => (string)($_POST['sub'] ?? ''), 'goal' => (string)($_POST['goal'] ?? ''),
        'cta' => (string)($_POST['cta'] ?? ''), 'steps' => $rows('steps', ['icon', 'title', 'text']), 'gets' => $rows('gets', ['icon', 'text'])];
    $ad = [];
    foreach (['eyebrow' => 60, 'headline' => 90, 'sub' => 140, 'goal' => 130, 'cta' => 70] as $k => $lim) { // the owner's words are trusted: only cut to size
        $ad[$k] = rtrim(pm_card_clean(mb_substr(trim($own[$k]), 0, $lim)), ' .');
    }
    foreach (['steps' => [4, ['title' => 50, 'text' => 110]], 'gets' => [4, ['text' => 80]]] as $k => [$max, $fields]) {
        $ad[$k] = array_slice(array_map(function ($r) use ($fields) {
            $o = ['icon' => isset(PM_EX_ICONS[strtolower((string)$r['icon'])]) ? strtolower((string)$r['icon']) : 'check'];
            foreach ($fields as $f => $lim) {
                $o[$f] = rtrim(pm_card_clean(mb_substr((string)$r[$f], 0, $lim)), ' .');
            }
            return $o;
        }, $own[$k]), 0, $max);
    }
    if ($ad['headline'] === '' || count($ad['steps']) < 2 || !$ad['gets']) {
        return pm_ex_back('Keep a headline, at least two steps and one thing they get.', 'err', $id);
    }
    $ad['caption'] = trim(str_replace("\r\n", "\n", (string)($_POST['caption'] ?? ''))) ?: pm_ex_caption($vb, $ad);
    $picLine = fn($v) => pm_swarm_clean(preg_replace('~https?://\S+~', '', (string)$v), 240);
    $ad['pics'] = isset($_POST['pic_hero']) ? ['hero' => $picLine($_POST['pic_hero']), 'steps' => array_slice(array_map($picLine, preg_split('/\R/', (string)($_POST['pic_steps'] ?? '')) ?: []), 0, count($ad['steps']))]
        : (array)($x['ad']['pics'] ?? []); // the owner's own picture descriptions, else the ones there were
    $x = pm_ex_put($vb, $id, function (array $a) use ($ad) {
        if ($ad != $a['ad']) {
            $a['prev'][] = $a['ad'];
            $a['prev'] = array_slice($a['prev'], -10);
            $a['ad'] = $ad;
            $a['status'] = 'draft';
        }
        return $a;
    });
    $x && pm_ex_render($vb, $x);
    return pm_ex_back('Saved and redrawn.', 'ok', $id);
}

function pm_do_ex_undo(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['x'] ?? ''));
    $x = pm_ex_put($vb, $id, function (array $a) {
        if ($a['prev']) {
            $a['ad'] = array_pop($a['prev']);
            $a['status'] = 'draft';
        }
        return $a;
    });
    $x && pm_ex_render($vb, $x);
    return pm_ex_back($x ? 'The ad is back one step.' : 'That ad is gone.', $x ? 'ok' : 'err', $id);
}

/** Remember a rule the chat offered: every writer follows it (Social > Voice). */
function pm_do_ex_remember(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['x'] ?? ''));
    $msg = function_exists('pm_cc_remember') ? pm_cc_remember($vb, (string)($_POST['lesson'] ?? ''), (string)($_POST['for'] ?? 'voice')) : '';
    return pm_ex_back($msg ?: 'Nothing to remember.', $msg ? 'ok' : 'err', $id);
}

/** The owner approves: the ad goes in the plan as an approved post at the next free time, and every department hears the promise it makes. */
function pm_do_ex_approve(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['x'] ?? ''));
    $x = pm_ex_get($vb, $id);
    $files = $x ? pm_ex_files($id) : [];
    if (!$x || empty($files['share'])) {
        return pm_ex_back('That ad has no picture yet.', 'err', $id);
    }
    $who = function_exists('pm_social_who') ? pm_social_who() : '';
    $postId = (string)($x['post'] ?? '');
    $media = pm_social_dir() . '/explainer-' . $id . '-' . substr(md5_file($files['share']), 0, 8) . '.png';
    @copy($files['share'], $media);
    $p = ['id' => bin2hex(random_bytes(10)), 'brand' => $vb, 'status' => 'draft', 'when' => pm_social_free_slot(pm_social_posts(), $vb, time() + 3600), 'format' => 'image', 'layout' => 'photo',
        'caption' => (string)$x['ad']['caption'], 'hashtags' => [], 'headline' => (string)$x['ad']['headline'], 'sub' => (string)$x['ad']['sub'], 'alt' => trim($x['ad']['headline'] . '. ' . implode('. ', array_column($x['ad']['steps'], 'title'))),
        'pillar' => 'Lead offer', 'cta' => pm_social_phone($vb) !== '' ? 'whatsapp' : '', 'src' => 'explainer', 'explainer' => $id, 'script' => '', 'media' => pm_social_media_rel($media), 'fb_id' => '', 'error' => '',
        'created' => date('Y-m-d H:i'), 'by' => $who, 'facts' => [pm_ex_source($vb, (string)$x['audience'], (string)$x['focus'])]];
    if (function_exists('pm_social_hashtags')) {
        $p['hashtags'] = pm_social_hashtags($p, 'facebook');
    }
    $p['status'] = 'approved';
    $p = pm_social_stamp_approval($p, $who ?: 'owner');
    pm_social_update(function (array $all) use ($p, $postId) {
        $all = array_values(array_filter($all, fn($q) => !($postId !== '' && ($q['id'] ?? '') === $postId && in_array($q['status'] ?? '', ['draft', 'approved', 'needs_edit'], true))));
        $all[] = $p;
        return $all;
    });
    pm_ex_put($vb, $id, function (array $a) use ($p, $who) {
        $a['status'] = 'approved';
        $a['approved_at'] = date('Y-m-d H:i');
        $a['approved_by'] = $who;
        $a['post'] = $p['id'];
        $a['post_when'] = $p['when'];
        return $a;
    });
    $promise = $x['ad']['headline'] . ($x['ad']['sub'] !== '' ? ' (' . $x['ad']['sub'] . ')' : '');
    pm_tb_post($vb, 'social', 'all', 'The owner approved our one-page ad for ' . ($x['audience'] ?: 'our audience') . ': "' . $promise . '". Make the same promise in messages, replies and posts, in the same steps: '
        . implode(', ', array_column($x['ad']['steps'], 'title')) . '.', 'exok:' . $vb);
    pm_talk($vb, 'social', 'The owner approved the one-page ad "' . $x['ad']['headline'] . '". It goes out ' . date('D j M H:i', strtotime((string)$p['when'])) . '.');
    return pm_ex_back('Approved. It is in the plan for ' . date('D j M, H:i', strtotime((string)$p['when'])) . ' (Social > Plan), and every department now makes the same promise.', 'ok', $id);
}

function pm_do_ex_delete(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['x'] ?? ''));
    pm_update('explainers', function (array $all) use ($vb, $id) {
        $all[$vb] = array_values(array_filter((array)($all[$vb] ?? []), fn($a) => ($a['id'] ?? '') !== $id));
        return $all;
    }, fn() => []);
    pm_ex_drop_files($id);
    return pm_ex_back('Deleted.');
}

/** Serves an ad's picture or PDF to a signed-in person who may open the business (index.php ?explainer=<id>&f=share|print|a4[&dl=1]). */
function pm_ex_serve(): void
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['explainer'] ?? ''));
    $b = pm_brand_norm((string)($_GET['b'] ?? ''));
    $f = (string)($_GET['f'] ?? 'share');
    $x = $id !== '' ? pm_ex_get($b, $id) : null;
    $file = $x ? (pm_ex_files($id)[$f] ?? '') : '';
    if ($file === '' || (function_exists('pm_user_can_brand') && !pm_user_can_brand($b))) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: ' . ($f === 'a4' ? 'application/pdf' : 'image/png'));
    header('Cache-Control: private, max-age=60');
    if (!empty($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9]+/', '-', pm_brand_name($b)) . '-one-page-ad' . ($f === 'a4' ? '-A4.pdf' : ($f === 'print' ? '-print.png' : '.png')) . '"');
    }
    readfile($file);
    exit;
}
