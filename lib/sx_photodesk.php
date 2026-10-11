<?php
/**
 * The photo desk: the cover photo, from the pictures the business already has and from pictures it should take.
 *   Photo scout    (code)  gathers the candidates: the cover photo in use, the picture the website shares, and the consented pictures in the photo library
 *   Photo critic   (code)  reads every candidate with the image library and scores it 0 to 100 for a cover: size, shape, light, contrast, sharpness, and how busy it is where the headline sits;
 *                          every problem comes with a plain fix
 *   Photo director (AI)    when asked, writes shot briefs the owner can take with a phone (the place, the work, the people), one AI call for four briefs; a second round if the code critic
 *                          finds them vague (no subject, no calm space for the headline, people without permission, stock-photo thinking)
 * Real photos only: the app never makes or buys a picture. "Use as the cover photo" copies a candidate in as the hero photo and redraws the kit. Data: data/desks.json {brand: {photo: {photos, options, runs}}}.
 * Auto-loaded by social_modules.php.
 */

const PM_PD_THRESHOLD = 78;
const PM_PD_TO = 'social&view=kit#photo';
const PM_PD_KINDS = ['place' => 'The place', 'work' => 'The work', 'people' => 'The people'];

/* ---------------- the photo critic ---------------- */

function pm_pd_load(string $file): ?GdImage
{
    $info = @getimagesize($file);
    if (!$info || $info[0] * $info[1] > 36000000) {
        return null; // too big to open safely here
    }
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
        IMAGETYPE_PNG => @imagecreatefrompng($file),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        default => false,
    };
    return $im ?: null;
}

/** What the image library can tell about a photo: size, shape, brightness, contrast, sharpness, and how busy the headline area is. */
function pm_pd_analyse(string $file): array
{
    $info = @getimagesize($file);
    if (!$info) {
        return ['ok' => false];
    }
    [$w, $h] = $info;
    $res = ['ok' => true, 'w' => (int)$w, 'h' => (int)$h, 'ratio' => round($w / max(1, $h), 2), 'bytes' => (int)@filesize($file), 'pixels' => false];
    $im = pm_pd_load($file);
    if (!$im) {
        return $res;
    }
    $tw = 320;
    $th = max(8, (int)round($h * $tw / max(1, $w)));
    $sm = imagecreatetruecolor($tw, $th);
    imagecopyresampled($sm, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($im);
    $g = [];
    $sum = 0.0;
    for ($y = 0; $y < $th; $y++) {
        for ($x = 0; $x < $tw; $x++) {
            $c = imagecolorat($sm, $x, $y);
            $l = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            $g[$y][$x] = $l;
            $sum += $l;
        }
    }
    imagedestroy($sm);
    $n = $tw * $th;
    $mean = $sum / $n;
    $var = 0.0;
    foreach ($g as $row) {
        foreach ($row as $l) {
            $var += ($l - $mean) ** 2;
        }
    }
    $lap = 0.0;
    $lapN = 0;
    $busy = 0.0;
    $busyN = 0;
    for ($y = 1; $y < $th - 1; $y++) {
        for ($x = 1; $x < $tw - 1; $x++) {
            $d = 4 * $g[$y][$x] - $g[$y - 1][$x] - $g[$y + 1][$x] - $g[$y][$x - 1] - $g[$y][$x + 1];
            $lap += $d * $d;
            $lapN++;
            if ($x < 0.55 * $tw && $y > 0.35 * $th && $y < 0.9 * $th) { // where a left-aligned headline sits
                $busy += abs($g[$y][$x + 1] - $g[$y][$x]) + abs($g[$y + 1][$x] - $g[$y][$x]);
                $busyN++;
            }
        }
    }
    return array_replace($res, ['pixels' => true, 'luma' => round($mean, 1), 'contrast' => round(sqrt($var / $n), 1), 'sharp' => round($lap / max(1, $lapN), 1), 'busy' => round($busy / max(1, $busyN), 1)]);
}

/** Scores an analysis for use as a cover: ['score', 'issues', 'praise', 'fixes']. Pure. */
function pm_pd_score(array $a): array
{
    if (empty($a['ok'])) {
        return ['score' => 0, 'issues' => ['It is not a picture this server can read.'], 'praise' => [], 'fixes' => ['Use a JPG or PNG.']];
    }
    $score = 100;
    $issues = [];
    $praise = [];
    $fixes = [];
    $w = (int)$a['w'];
    if ($w < 900) {
        $score -= 40;
        $issues[] = 'Too small (' . $w . ' pixels across): it will look blurry on a cover.';
        $fixes[] = 'Take or find a version at least 1640 pixels across.';
    } elseif ($w < 1640) {
        $score -= 15;
        $issues[] = 'Smaller than a Facebook cover (' . $w . ' of 1640 pixels across).';
        $fixes[] = 'A larger original will look sharper.';
    } else {
        $praise[] = 'Good size';
    }
    if ($a['ratio'] < 1.0) {
        $score -= 25;
        $issues[] = 'A portrait picture: most of it will be cropped away.';
        $fixes[] = 'Turn the phone sideways and shoot wide.';
    } elseif ($a['ratio'] < 1.5) {
        $score -= 10;
        $issues[] = 'Not very wide: the top and bottom will be cropped.';
        $fixes[] = 'Shoot wider, with the subject low or to one side.';
    }
    if (empty($a['pixels'])) {
        return ['score' => max(0, $score), 'issues' => $issues, 'praise' => $praise, 'fixes' => $fixes];
    }
    if ($a['luma'] < 60) {
        $score -= 18;
        $issues[] = 'Dark: details are lost and the writing has little to stand out from.';
        $fixes[] = 'Shoot in daylight, or lift the exposure a little.';
    } elseif ($a['luma'] > 215) {
        $score -= 12;
        $issues[] = 'Very bright and washed out.';
        $fixes[] = 'Shoot when the sun is lower, or lower the exposure.';
    } else {
        $praise[] = 'Well lit';
    }
    if ($a['contrast'] < 28) {
        $score -= 12;
        $issues[] = 'Flat: there is little difference between light and dark.';
        $fixes[] = 'Choose a scene with light and shade, not an even grey day.';
    }
    if ($a['sharp'] < 25) {
        $score -= 25;
        $issues[] = 'Soft or blurry.';
        $fixes[] = 'Hold the phone steady, tap to focus, and shoot again.';
    } elseif ($a['sharp'] < 45) {
        $score -= 10;
        $issues[] = 'A little soft.';
    } else {
        $praise[] = 'Sharp';
    }
    if ($a['busy'] > 30) {
        $score -= 12;
        $issues[] = 'Very busy where the headline sits: the writing will be hard to read.';
        $fixes[] = 'Leave calm space on the left, or let the design add a darker veil.';
    } elseif ($a['busy'] < 14) {
        $praise[] = 'Calm space for the headline';
    }
    return ['score' => max(0, $score), 'issues' => $issues, 'praise' => $praise, 'fixes' => array_values(array_unique($fixes))];
}

/* ---------------- the photo scout ---------------- */

/** The photos that could be the cover: [key, label, file, kind]. Keys: current, site, lib-<asset id>. */
function pm_pd_candidates(string $brand): array
{
    $out = [];
    foreach (['jpg', 'png'] as $x) {
        $f = PM_ROOT . '/assets/' . pm_brand_norm($brand) . "_hero.$x";
        if (is_file($f)) {
            $out[] = ['key' => 'current', 'label' => 'Your cover photo', 'file' => $f, 'kind' => 'current'];
            break;
        }
    }
    $t = pm_link_thumb_path($brand);
    if (is_file($t)) {
        $out[] = ['key' => 'site', 'label' => 'The picture your website shares', 'file' => $t, 'kind' => 'site'];
    }
    foreach (array_slice(pm_social_assets_usable($brand), 0, 8) as $a) {
        $p = pm_social_asset_path($a);
        if ($p !== '') {
            $out[] = ['key' => 'lib-' . $a['id'], 'label' => trim((string)($a['note'] ?: $a['orig_name'])) !== '' ? mb_substr(trim((string)($a['note'] ?: $a['orig_name'])), 0, 50) : 'Photo library picture', 'file' => $p, 'kind' => 'library'];
        }
    }
    return $out;
}

/** The file behind a candidate key, or '' (used by the preview route and by "use"). */
function pm_pd_path(string $brand, string $key): string
{
    foreach (pm_pd_candidates($brand) as $c) {
        if ($c['key'] === $key) {
            return $c['file'];
        }
    }
    return '';
}

/** A small JPEG of a photo for the page, drawn from the file. '' when it cannot be read. */
function pm_pd_thumb(string $file, int $w = 480): string
{
    $im = pm_pd_load($file);
    if (!$im) {
        return '';
    }
    $sw = imagesx($im);
    $sh = imagesy($im);
    $w = min($w, $sw);
    $h = max(1, (int)round($sh * $w / $sw));
    $out = imagecreatetruecolor($w, $h);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $sw, $sh);
    imagedestroy($im);
    ob_start();
    imagejpeg($out, null, 82);
    $jpg = (string)ob_get_clean();
    imagedestroy($out);
    return $jpg;
}

/* ---------------- the photo director ---------------- */

function pm_pd_prompt(array $brief, int $n, array $feedback, array $tried): array
{
    $f = $brief['f'];
    $system = pm_agents_company_brief('tiny') . "\nYou are the photo director for " . $f['name'] . ' (Malawi). The owner will take cover photos with a phone, so you write shot briefs, not stock-photo ideas. Write ' . $n . ' briefs, '
        . 'each of a different kind: place (where customers find the business), work (the work or product close up) or people (only people who agreed to be photographed). Each brief says: subject (what is in the frame, concrete, '
        . 'something that really exists in the facts), setting (where), angle (how to hold the phone), light (when and where the light falls), text_space (where the frame stays calm so the headline can sit, usually the left or the lower left), '
        . 'avoid (what would spoil it) and why (one sentence for this audience). Real photos only: never suggest buying or generating a picture. No vague words like beautiful, stunning or vibrant. '
        . PM_HUMAN_RULES . ' Use only the facts for what exists. Reply JSON only.';
    $user = json_encode(['business' => $f['name'], 'about' => mb_substr($f['about'], 0, 400), 'audience' => mb_substr($f['audience'], 0, 200), 'facts' => array_slice($f['facts'], 0, 8), 'town' => $f['address_real'] ? $f['address'] : 'Malawi',
        'kinds' => array_keys(PM_PD_KINDS), 'already_proposed' => array_slice($tried, 0, 8), 'critics_said' => $feedback], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"shots\":[{\"kind\":\"place|work|people\",\"title\":\"\",\"subject\":\"\",\"setting\":\"\",\"angle\":\"\",\"light\":\"\",\"text_space\":\"\",\"avoid\":\"\",\"why\":\"\"}]}";
    return [$system, $user];
}

function pm_pd_ask(string $brand, array $brief, int $n, array $feedback, array $tried): array
{
    [$sys, $usr] = pm_pd_prompt($brief, $n, $feedback, $tried);
    $out = pm_agent_json(pm_claude($sys, $usr, false, 300 * $n + 250, 'write'));
    $made = [];
    foreach (array_slice((array)(is_array($out) ? ($out['shots'] ?? []) : []), 0, $n + 1) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $kind = pm_swarm_clean($s['kind'] ?? '', 10);
        $made[] = ['kind' => isset(PM_PD_KINDS[$kind]) ? $kind : 'place', 'title' => pm_swarm_clean($s['title'] ?? '', 60), 'subject' => pm_swarm_clean($s['subject'] ?? '', 220), 'setting' => pm_swarm_clean($s['setting'] ?? '', 140),
            'angle' => pm_swarm_clean($s['angle'] ?? '', 120), 'light' => pm_swarm_clean($s['light'] ?? '', 120), 'text_space' => pm_swarm_clean($s['text_space'] ?? '', 140), 'avoid' => pm_swarm_clean($s['avoid'] ?? '', 160),
            'why' => pm_human_polish(pm_swarm_clean($s['why'] ?? '', 180), 'profile')];
    }
    if (!$made) {
        throw new RuntimeException('The photo director did not return any brief.');
    }
    return $made;
}

/** No AI: three plain briefs from the facts. */
function pm_pd_fallback(array $brief): array
{
    $f = $brief['f'];
    $first = trim(preg_split('/(?<=[.!?])\s+/u', trim($f['about']))[0] ?? '');
    $thing = $f['facts'][0] ?? ($first !== '' ? $first : $f['name']);
    return [
        ['kind' => 'place', 'title' => 'Where people find you', 'subject' => 'The entrance, shopfront or the view a visitor sees first at ' . $f['name'], 'setting' => 'Outside, in daylight', 'angle' => 'Phone held sideways at chest height, a little back',
            'light' => 'Morning or late afternoon, sun behind you', 'text_space' => 'Keep the left third calm: sky, wall or ground', 'avoid' => 'Cars, signs from other businesses and strong shadows across the front', 'why' => 'A real place makes a business easier to trust.', 'how' => 'code'],
        ['kind' => 'work', 'title' => 'The work close up', 'subject' => mb_substr($thing, 0, 140) . ', shown as the work itself: hands, tools or the product in use', 'setting' => 'Where the work really happens', 'angle' => 'Close, slightly from above, phone sideways',
            'light' => 'Near a window or outdoors, no flash', 'text_space' => 'Put the subject on the right and leave the left calm', 'avoid' => 'Clutter, cables and screens full of private data', 'why' => 'Showing the work answers "what do you actually do".', 'how' => 'code'],
        ['kind' => 'people', 'title' => 'The people (with permission)', 'subject' => 'One or two team members at work, only with their agreement', 'setting' => 'Their usual workplace', 'angle' => 'At eye level, phone sideways, a few steps away',
            'light' => 'Soft daylight on their faces', 'text_space' => 'Leave the left third calm and let them stand on the right', 'avoid' => 'Photographing anyone who did not agree', 'why' => 'People make a small business feel real.', 'how' => 'code'],
    ];
}

/** The code critic for a shot brief: ['score', 'issues', 'praise']. */
function pm_pd_score_shot(array $s, array $brief): array
{
    $score = 100;
    $issues = [];
    $praise = [];
    $words = fn(string $t) => count(preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY));
    if ($words((string)$s['subject']) < 6) {
        $score -= 12;
        $issues[] = 'The subject is vague: say what is in the frame.';
    }
    if ($words((string)$s['setting']) < 2) {
        $score -= 8;
        $issues[] = 'It does not say where to shoot.';
    }
    if ($words((string)$s['angle']) < 2) {
        $score -= 6;
        $issues[] = 'It does not say how to hold the phone.';
    }
    if ($words((string)$s['light']) < 2) {
        $score -= 6;
        $issues[] = 'It does not say when or where the light falls.';
    }
    if ($words((string)$s['text_space']) < 4) {
        $score -= 12;
        $issues[] = 'It does not say where the frame stays calm for the headline.';
    } else {
        $praise[] = 'Leaves room for the headline';
    }
    if ($words((string)$s['avoid']) < 3) {
        $score -= 4;
        $issues[] = 'It does not say what to avoid.';
    }
    $all = implode(' ', [$s['subject'], $s['setting'], $s['angle'], $s['light'], $s['text_space'], $s['avoid'], $s['why']]);
    if (preg_match('/\b(beautiful|stunning|amazing|vibrant|breathtaking|perfect|gorgeous)\b/i', $all)) {
        $score -= 8;
        $issues[] = 'It uses vague praise instead of saying what to photograph.';
    }
    if (preg_match('/\bstock\b|\bgenerate[d]?\b|\bai[- ]generated\b|\bbuy\b/i', $all)) {
        $score -= 10;
        $issues[] = 'Real photos only: no stock or generated pictures.';
    }
    if (preg_match('/\b(people|person|guests?|staff|team|owner|customers?|children|faces?|workers?)\b/i', (string)$s['subject']) && !preg_match('/\b(permission|consent|agree[sd]?|agreement)\b/i', (string)$s['subject'] . ' ' . (string)$s['avoid'])) {
        $score -= 15;
        $issues[] = 'A photo with people needs their permission: say so.';
    }
    if (!array_intersect(pm_editor_words($all), $brief['factwords'])) {
        $score -= 10;
        $issues[] = 'Nothing in it ties back to what this business really does.';
    } else {
        $praise[] = 'Tied to the business';
    }
    return ['score' => max(0, $score), 'issues' => $issues, 'praise' => array_values(array_unique($praise))];
}

/* ---------------- one run ---------------- */

/** The desk's run: the critic reads every candidate photo (free); with $withBrief the director writes shot briefs (one or two AI calls). Returns [message, report]. */
function pm_pd_run(string $brand, bool $withBrief = true, string $by = 'owner'): array
{
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $t0 = function_exists('pm_usage_mark') ? pm_usage_mark() : 0;
        $photos = [];
        foreach (pm_pd_candidates($brand) as $c) {
            $a = pm_pd_analyse($c['file']);
            $photos[] = ['key' => $c['key'], 'label' => $c['label'], 'kind' => $c['kind'], 'mtime' => (int)@filemtime($c['file'])] + $a + pm_pd_score($a);
        }
        usort($photos, fn($x, $y) => $y['score'] <=> $x['score']);
        $log = ['The Photo scout found ' . count($photos) . ' photo' . (count($photos) === 1 ? '' : 's') . ' to look at; the Photo critic scored them' . ($photos ? ' (best ' . $photos[0]['score'] . ' of 100).' : '.')];
        $opts = [];
        $calls = 0;
        $best = 0;
        $how = 'code';
        if ($withBrief) {
            $brief = pm_cd_brief($brand);
            $res = pm_swarm_loop(['ai' => pm_agents_ready(), 'label' => 'shot briefs', 'threshold' => PM_PD_THRESHOLD, 'rounds' => 2, 'keep' => 3,
                'ask' => fn($round, $fb, $tried) => pm_pd_ask($brand, $brief, $round === 1 ? 4 : 3, $fb, $tried),
                'fallback' => fn($round, $tried) => pm_pd_fallback($brief),
                'score' => fn($c) => pm_pd_score_shot($c, $brief),
                'key' => fn($c) => (string)$c['kind'],
                'note' => fn($c) => '"' . $c['title'] . '"']);
            foreach ($res['kept'] as $c) {
                $opts[] = ['id' => substr(md5($c['kind'] . '|' . $c['subject']), 0, 12), 'kind' => $c['kind'], 'title' => $c['title'] ?: (PM_PD_KINDS[$c['kind']] ?? 'Shot'), 'subject' => $c['subject'], 'setting' => $c['setting'], 'angle' => $c['angle'],
                    'light' => $c['light'], 'text_space' => $c['text_space'], 'avoid' => $c['avoid'], 'why' => $c['why'], 'score' => $c['score'], 'issues' => array_slice($c['issues'], 0, 3), 'praise' => array_slice($c['praise'], 0, 3),
                    'round' => $c['round'], 'how' => $c['how'], 'at' => date('Y-m-d H:i'), 'status' => 'new'];
            }
            $log = array_merge($log, $res['log']);
            $calls = $res['calls'];
            $best = $res['best'];
            $how = $res['how'];
        }
        $tokens = function_exists('pm_usage_mark') ? max(0, pm_usage_mark() - $t0) : 0;
        pm_swarm_update($brand, 'photo', function (array $d) use ($photos, $opts, $log, $calls, $tokens, $best, $how, $by, $withBrief) {
            $d['photos'] = $photos;
            if ($withBrief) {
                foreach ($d['options'] as $k => $p) {
                    if (($p['status'] ?? '') === 'new') {
                        $d['options'][$k]['status'] = 'dismissed';
                    }
                }
                $d['options'] = array_slice(array_merge($opts, $d['options']), 0, 9);
            }
            $d['runs'] = array_slice(array_merge([['at' => date('Y-m-d H:i'), 'by' => $by, 'log' => $log, 'calls' => $calls, 'tokens' => $tokens, 'best' => $best, 'how' => $how]], (array)$d['runs']), 0, 8);
            return $d;
        });
        $msg = 'The photo desk read ' . count($photos) . ' photo' . (count($photos) === 1 ? '' : 's') . ($withBrief ? ' and wrote ' . count($opts) . ' shot brief' . (count($opts) === 1 ? '' : 's') . ' in ' . ($how === 'ai' ? $calls . ' AI call' . ($calls === 1 ? '' : 's') : 'code, no AI') : '') . '.';
        pm_agent_log('Photo desk', $msg);
        return [$msg, ['photos' => $photos, 'options' => $opts, 'log' => $log, 'calls' => $calls, 'tokens' => $tokens]];
    } finally {
        pm_brand_set($prev);
    }
}

/** Copies a candidate in as the cover photo and draws the kit again. Returns [ok, message]. */
function pm_pd_use(string $brand, string $key): array
{
    if ($key === 'current') {
        return [false, 'That is the cover photo already.'];
    }
    $src = pm_pd_path($brand, $key);
    if ($src === '') {
        return [false, 'That photo is not there any more. Ask the photo desk again.'];
    }
    $im = pm_pd_load($src);
    if (!$im) {
        return [false, 'This server could not open that photo.'];
    }
    foreach (glob(PM_ROOT . '/assets/' . pm_brand_norm($brand) . '_hero.*') ?: [] as $old) {
        @unlink($old);
    }
    $dest = PM_ROOT . '/assets/' . pm_brand_norm($brand) . '_hero.jpg';
    imagejpeg($im, $dest, 90);
    imagedestroy($im);
    if (pm_studio_can_draw()) {
        pm_studio_redraw_kit($brand);
    }
    return [true, 'That photo is now the cover photo. The "Your photo" style in the Studio uses it, and the kit pictures were drawn again.'];
}

/* ---------------- form handlers ---------------- */

function pm_do_pd_run(string $vb): array
{
    @set_time_limit(180);
    try {
        [$msg] = pm_pd_run($vb, !empty($_POST['brief']), 'owner');
    } catch (Throwable $e) {
        return ['msg' => 'The photo desk could not finish: ' . mb_substr($e->getMessage(), 0, 160), 'kind' => 'err', 'to' => PM_PD_TO];
    }
    return ['msg' => $msg, 'kind' => 'ok', 'to' => PM_PD_TO];
}

function pm_do_pd_use(string $vb): array
{
    [$ok, $m] = pm_pd_use($vb, preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['key'] ?? '')));
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => PM_PD_TO];
}

function pm_do_pd_dismiss(string $vb): array
{
    pm_swarm_mark($vb, 'photo', preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? '')), 'dismissed');
    return ['msg' => 'Put aside.', 'kind' => 'ok', 'to' => PM_PD_TO];
}
