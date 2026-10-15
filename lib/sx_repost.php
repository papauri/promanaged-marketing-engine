<?php
/**
 * Repost: an earlier post comes back with the same picture or video and new words. The source is one of our published posts or any post on the Facebook Page's wall
 * (made in the app or not). One small call on the cheapest model rewrites the caption and hashtags (pm_regen_words): a punchier first line, short lines, one easy next step,
 * still the owner's voice and only the original's facts (a number nobody gave is dropped, links stay exactly as they were). The new post is a draft at the time the owner
 * chose, on the channels the owner ticked; only channels we may publish to are offered (Facebook with the posting permission, Instagram when linked and readable, LinkedIn
 * and X when connected and switched on). The owner reads and approves it in the Plan, and it goes out at that time. Nothing is posted from here.
 * Data: data/repost.json {brand: {source key: date reposted}}; a new post carries reposted_from.
 */

const PM_RP_NOTE = 'Make it punchier: open with a first line that stops the scroll (a question, the customer\'s problem, or the most surprising true detail), keep the lines short, '
    . 'and end with one easy next step. It must still sound like a real person from the business talking, not an advert, and be clearly new, not the old caption shuffled.';

/** Channels this business may publish a repost to now: key => label. A video goes to Facebook and Instagram only. */
function pm_rp_channels(string $brand, bool $video = false): array
{
    $c = pm_social_cfg($brand);
    $on = function_exists('pm_social_settings') ? (array)pm_social_settings($brand)['channels'] : ['facebook' => true, 'instagram' => true];
    $out = [];
    if ($c['ready'] && !empty($on['facebook'])) {
        $ti = function_exists('pm_fb_token_info') ? pm_fb_token_info($c['token']) : ['ok' => true, 'scopes' => ['pages_manage_posts']];
        if (!empty($ti['ok']) && in_array('pages_manage_posts', (array)($ti['scopes'] ?? []), true)) {
            $out['facebook'] = 'Facebook';
        }
    }
    if (!empty($on['instagram']) && $c['ig_id'] !== '' && function_exists('pm_ig_state') && pm_ig_state($brand) === 'ok' && (!$video || pm_app_url() !== '')) {
        $out['instagram'] = 'Instagram';
    }
    if (!$video && !empty($on['linkedin']) && function_exists('pm_linkedin_cfg') && pm_linkedin_cfg($brand)['ready']) {
        $out['linkedin'] = 'LinkedIn';
    }
    if (!$video && !empty($on['x']) && function_exists('pm_x_cfg') && pm_x_cfg($brand)['ready']) {
        $out['x'] = 'X';
    }
    return $out;
}

/** When each source was last reposted: key => date. */
function pm_rp_done(string $brand): array
{
    return (array)(pm_load('repost', fn() => [])[$brand] ?? []);
}

/**
 * Posts that can come back, newest first: key => [key, text, tags, at, picture (a link or a file), video, url, eng, from (app|page)].
 * Our own published posts with a picture or video, and the Page wall's posts with a picture or video that the app did not make.
 */
function pm_rp_sources(string $brand, int $n = 15): array
{
    $out = [];
    $ours = [];
    foreach (pm_sx_posts($brand) as $p) {
        if (($p['status'] ?? '') !== 'published' || trim((string)($p['caption'] ?? '')) === '' || !in_array($p['format'] ?? '', ['image', 'reel'], true)) {
            continue;
        }
        ($p['fb_id'] ?? '') !== '' && $ours[(string)$p['fb_id']] = 1;
        $media = pm_social_has_media($p) ? pm_social_media($p) : '';
        if ($media === '' && ($p['format'] ?? '') === 'reel') {
            continue; // the video file is gone
        }
        $out['app:' . $p['id']] = ['key' => 'app:' . $p['id'], 'text' => (string)$p['caption'], 'tags' => implode(' ', (array)($p['hashtags'] ?? [])), 'at' => (string)($p['when'] ?? ''),
            'picture' => $media, 'video' => $media !== '' && pm_social_is_video($media), 'url' => '', 'eng' => '', 'from' => 'app', 'title' => (string)($p['headline'] ?? '')];
    }
    if (pm_social_cfg($brand)['ready']) {
        foreach ((array)(pm_fb_posts($brand, 25)['posts'] ?? []) as $fp) {
            $video = str_contains((string)($fp['type'] ?? ''), 'video');
            if (isset($ours[(string)$fp['id']]) || ((string)($fp['picture'] ?? '') === '' && !$video)) {
                continue; // made here (listed above), or nothing to show again
            }
            $out['fb:' . $fp['id']] = ['key' => 'fb:' . $fp['id'], 'text' => (string)$fp['message'], 'tags' => '', 'at' => (string)$fp['at'], 'picture' => (string)$fp['picture'], 'video' => $video,
                'url' => (string)$fp['url'], 'eng' => (int)$fp['reactions'] . ' reactions, ' . (int)$fp['comments'] . ' comments, ' . (int)$fp['shares'] . ' shares', 'from' => 'page', 'title' => ''];
        }
    }
    uasort($out, fn($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));
    return array_slice($out, 0, $n, true);
}

/** Downloads a picture or video to data/social. Returns the stored path or ''. */
function pm_rp_fetch(string $url, string $file, int $max = 200 * 1048576): string
{
    if (!preg_match('#^https://#i', $url)) {
        return '';
    }
    $dest = pm_social_dir() . '/' . $file;
    $fp = @fopen($dest, 'wb');
    if (!$fp) {
        return '';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 180, CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($c, $dt, $d) => $d > $max ? 1 : 0]);
    function_exists('pm_curl_native_ca') && pm_curl_native_ca($ch);
    $ok = curl_exec($ch) && (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
    curl_close($ch);
    fclose($fp);
    if (!$ok || filesize($dest) < 1000) {
        @unlink($dest);
        return '';
    }
    return $dest;
}

/** The picture or video of a source, as a new file for the new post: [path, video?] or ['', false]. */
function pm_rp_media(string $brand, array $src, string $newId): array
{
    if ($src['from'] === 'app') {
        if ($src['picture'] === '') {
            return ['', false]; // a drawn card: the new post draws the same card from the same headline and design
        }
        $ext = strtolower(pathinfo($src['picture'], PATHINFO_EXTENSION)) ?: 'jpg';
        $dest = pm_social_dir() . '/' . $newId . '.' . $ext;
        return @copy($src['picture'], $dest) ? [$dest, $src['video']] : ['', false];
    }
    $c = pm_social_cfg($brand);
    $fbId = substr($src['key'], 3);
    if ($src['video']) { // the post's video: its id from the attachment, then the file
        [$ok, $d] = pm_graph('GET', $fbId, ['fields' => 'attachments{media_type,target}'], $c['token']);
        $vid = $ok ? (string)($d['attachments']['data'][0]['target']['id'] ?? '') : '';
        if ($vid !== '') {
            [$ok, $v] = pm_graph('GET', $vid, ['fields' => 'source'], $c['token']);
            if ($ok && ($path = pm_rp_fetch((string)($v['source'] ?? ''), $newId . '.mp4')) !== '') {
                return [$path, true];
            }
        }
        if ($src['picture'] === '') {
            return ['', false];
        }
    }
    $big = $src['picture'];
    [$ok, $d] = pm_graph('GET', $fbId, ['fields' => 'attachments{media,subattachments{media}}'], $c['token']); // the full-size picture, not the feed's crop
    if ($ok) {
        $a = (array)($d['attachments']['data'][0] ?? []);
        $big = (string)($a['media']['image']['src'] ?? $a['subattachments']['data'][0]['media']['image']['src'] ?? $big);
    }
    $path = pm_rp_fetch($big, $newId . '.jpg', 15 * 1048576);
    return [$path, false];
}

/**
 * Makes the repost: same picture or video, new words, a draft at $when on the ticked channels. Returns ['ok', 'msg', 'id'].
 * $note: what the owner wants changed (optional). One small AI call; without AI the original words come back with a fresh opening line.
 */
function pm_rp_make(string $brand, string $key, array $chs, string $when, string $note = ''): array
{
    $src = pm_rp_sources($brand, 40)[$key] ?? null;
    if (!$src) {
        return ['ok' => false, 'msg' => 'That post is not in the list any more.', 'id' => ''];
    }
    $allowed = pm_rp_channels($brand, $src['video']);
    $chs = array_values(array_intersect($chs, array_keys($allowed)));
    if (!$chs) {
        return ['ok' => false, 'msg' => 'Tick at least one channel we may post to.', 'id' => ''];
    }
    $ts = strtotime($when);
    if (!$ts || $ts < time() + 300) {
        return ['ok' => false, 'msg' => 'Choose a time at least five minutes from now.', 'id' => ''];
    }
    $text = trim($src['text']);
    $note = mb_substr(trim($note), 0, 300);
    if ($text === '' && $note === '') {
        return ['ok' => false, 'msg' => 'That post has no words to rework. Write a few words about it in the note.', 'id' => ''];
    }
    $id = bin2hex(random_bytes(10));
    [$media, $video] = pm_rp_media($brand, $src, $id);
    if ($media === '' && ($src['from'] === 'page' || $src['video'])) {
        return ['ok' => false, 'msg' => 'The ' . ($src['video'] ? 'video' : 'picture') . ' could not be downloaded from Facebook. Try again later.', 'id' => ''];
    }
    $tags = $src['tags'];
    $words = ['caption' => $text !== '' ? $text : $note, 'hashtags' => $tags];
    $by = 'plain';
    if (pm_agents_ready()) {
        try {
            $GLOBALS['PM_AI_REF'] = 'repost';
            $new = pm_regen_words(array_filter($words), 'post', PM_RP_NOTE . ($note !== '' ? ' The owner adds: ' . $note : ''));
            if (($new['caption'] ?? $words['caption']) !== $words['caption']) {
                $words = $new + $words;
                $by = 'ai';
            }
        } catch (Throwable) {
        }
    }
    if ($by === 'plain') {
        $words['caption'] = PM_SX_RECYCLE_FRAMES[abs(crc32($key)) % count(PM_SX_RECYCLE_FRAMES)] . $words['caption'];
    }
    $orig = $src['from'] === 'app' ? (array)(array_column(pm_sx_posts($brand), null, 'id')[substr($key, 4)] ?? []) : [];
    $post = array_intersect_key($orig, array_flip(['format', 'layout', 'asset_id', 'headline', 'sub', 'pillar', 'cta', 'alt', 'audience', 'segment', 'slides'])) + [
        'id' => $id, 'brand' => $brand, 'status' => 'draft', 'when' => date('Y-m-d H:i', $ts), 'format' => $video ? 'reel' : 'image', 'caption' => (string)$words['caption'],
        'hashtags' => array_values(array_filter(preg_split('/\s+/', trim((string)$words['hashtags'])) ?: [])), 'media' => $media !== '' ? pm_social_media_rel($media) : '',
        'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [], 'script' => '', 'created' => date('Y-m-d H:i'), 'by' => pm_social_who() ?: 'owner',
        'src' => 'repost', 'reposted_from' => $key, 'words_by' => $by, 'tokens' => 0,
        'ch_skip' => array_values(array_diff(['facebook', 'instagram', 'linkedin'], $chs)),
    ];
    $video && $post['format'] = 'reel';
    !in_array('x', $chs, true) && $post['x'] = 'off'; // the X job leaves a post alone once 'x' is set
    if (!$post['hashtags'] && function_exists('pm_social_hashtags')) { // fresh tags from the bank
        try {
            $post['hashtags'] = array_values((array)pm_social_hashtags($post, 'facebook'));
        } catch (Throwable) {
        }
    }
    $post = pm_social_resolve($post, pm_social_lint_post($post));
    pm_social_add_planned([$post], false);
    pm_update('repost', function (array $all) use ($brand, $key) {
        $all[$brand][$key] = date('Y-m-d');
        return $all;
    }, fn() => []);
    function_exists('pm_social_log') && pm_social_log($id, 'repost', 'from ' . $key);
    pm_agent_log('Social', 'Repost drafted for ' . date('D j M H:i', $ts) . ' on ' . implode(', ', array_map(fn($c) => $allowed[$c], $chs)) . ($by === 'ai' ? ', with new words' : ', with a fresh opening line'));
    return ['ok' => true, 'msg' => 'The repost is a draft for ' . date('D j M, H:i', $ts) . ' on ' . implode(', ', array_map(fn($c) => $allowed[$c], $chs)) . ($by === 'ai' ? ' with new words' : ' (no AI: the old words with a fresh opening line)')
        . '. Read it, change anything, and approve: it goes out at that time.' . (($post['status'] ?? '') === 'needs_edit' ? ' It needs an edit first: ' . implode(' ', (array)$post['lint']) : ''), 'id' => $id];
}

function pm_do_repost(string $vb): array
{
    $key = preg_replace('/[^a-z0-9:_]/i', '', (string)($_POST['src'] ?? ''));
    $chs = array_values(array_filter((array)($_POST['ch'] ?? []), 'is_string'));
    $when = trim((string)($_POST['date'] ?? '')) . ' ' . trim((string)($_POST['time'] ?? '18:30'));
    $r = pm_rp_make($vb, $key, $chs, $when, (string)($_POST['note'] ?? ''));
    return ['msg' => $r['msg'], 'kind' => $r['ok'] ? 'ok' : 'err', 'to' => $r['ok'] ? 'social#s' . $r['id'] : 'social&view=content&rp=' . rawurlencode($key) . '#repost'];
}

/** The Repost card on Social > Content. */
function pm_rp_panel(string $vb, string $csrf): string
{
    $src = pm_rp_sources($vb);
    $done = pm_rp_done($vb);
    $pick = (string)($_GET['rp'] ?? '');
    $h = '<div class="card" id="repost"><h2>Repost with new words</h2>';
    if (!pm_rp_channels($vb)) {
        return $h . '<p class="hint mt-0">No channel we may post to is connected for this business, so there is nothing to repost to. Connect the Facebook Page (with permission to publish), Instagram, LinkedIn or X under <a href="?tab=settings&amp;section=connections">Connections</a>.</p></div>';
    }
    if (!$src) {
        return $h . '<p class="hint mt-0">No earlier post with a picture or video yet.</p></div>';
    }
    if (!isset($src[$pick]) && str_starts_with($pick, 'fb:')) { // a Page post the app made: listed under our own posts
        foreach (pm_sx_posts($vb) as $p) {
            $pick = (string)($p['fb_id'] ?? '') === substr($pick, 3) ? 'app:' . $p['id'] : $pick;
        }
    }
    $pick = isset($src[$pick]) ? $pick : (string)array_key_first($src);
    $h .= '<p class="hint mt-0">The same picture or video, new words: a punchier first line and an easy next step, still in your voice and only what the post said (one small AI call on the cheapest model). It becomes a draft at the time you choose, on the channels you tick; you read it and approve it in the Plan. Only channels we have permission to post to are shown.</p>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="repost">'
        . '<div class="rp-list" style="max-height:420px;overflow:auto">';
    foreach ($src as $k => $s) {
        $pic = $s['picture'] !== '' && str_starts_with($s['picture'], 'http') ? $s['picture'] : '';
        $h .= '<label class="check" style="display:flex;gap:10px;align-items:flex-start;margin:8px 0"><input type="radio" name="src" value="' . pm_h($k) . '"' . ($k === $pick ? ' checked' : '') . ' data-video="' . ($s['video'] ? '1' : '0') . '">'
            . ($pic !== '' ? '<img src="' . pm_h($pic) . '" alt="" loading="lazy" style="width:64px;height:64px;object-fit:cover;border-radius:6px">' : '<span class="sx-chip">' . ($s['video'] ? 'video' : 'picture') . '</span>')
            . '<span><b>' . pm_h($s['title'] !== '' ? $s['title'] : (mb_substr(trim($s['text']), 0, 70) ?: 'A post without words')) . '</b><br><span class="muted t-sm">'
            . pm_h(($s['from'] === 'page' ? 'On the Page wall' : 'Made here') . ($s['at'] !== '' ? ' · ' . date('j M Y', strtotime($s['at'])) : '') . ($s['video'] ? ' · video' : '') . ($s['eng'] !== '' ? ' · ' . $s['eng'] : '')
                . (isset($done[$k]) ? ' · reposted ' . date('j M', strtotime((string)$done[$k])) : '')) . '</span>'
            . ($s['url'] !== '' ? ' <a class="t-sm" href="' . pm_h($s['url']) . '" target="_blank" rel="noopener">open</a>' : '') . '</span></label>';
    }
    $chs = pm_rp_channels($vb);
    $vch = pm_rp_channels($vb, true);
    $slot = strtotime(pm_social_free_slot(pm_social_posts(), $vb, time() + 3600)) ?: time() + 86400;
    $h .= '</div><p class="hint mb-0"><b>Post it on</b></p><div class="btns mt-0">';
    foreach ($chs as $k => $l) {
        $h .= '<label class="check"><input type="checkbox" name="ch[]" value="' . $k . '" checked> ' . pm_h($l) . (isset($vch[$k]) ? '' : ' <span class="muted t-sm">(pictures only)</span>') . '</label>';
    }
    return $h . '</div><div class="row"><div><label>Day</label><input type="date" name="date" value="' . date('Y-m-d', $slot) . '" min="' . date('Y-m-d') . '" required></div>'
        . '<div><label>Time</label><input type="time" name="time" value="' . date('H:i', $slot) . '" required></div></div>'
        . '<label>Anything to change (optional)</label><input type="text" name="note" maxlength="300" placeholder="e.g. mention we now also deliver to Zomba">'
        . '<div class="btns"><button class="btn primary"' . (pm_agents_ready() ? '' : ' title="No AI key: the old words come back with a fresh opening line"') . '>Write new words and schedule it</button></div></form></div>';
}
