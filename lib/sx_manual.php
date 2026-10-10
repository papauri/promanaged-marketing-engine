<?php
/**
 * The hand-post workflow for channels no API can reach (TikTok, Shorts, Google, WhatsApp Status/Channel, X, personal LinkedIn, Instagram when not linked):
 * dated tasks, Mark posted, weekly Status pack, film cards and SRT, group posts, host share kits. Nothing here posts anything by itself.
 */

const PM_CH_MANUAL = ['instagram', 'tiktok', 'youtube_short', 'google', 'whatsapp_status', 'whatsapp_channel', 'x', 'linkedin', 'linkedin_personal'];
/** Minimum seconds after the post's own time before a hand-post is due. */
const PM_CH_OFFSET = ['instagram' => 0, 'tiktok' => 3600, 'youtube_short' => 86400, 'google' => 86400, 'whatsapp_status' => 0, 'whatsapp_channel' => 7200, 'x' => 1800, 'linkedin' => 0, 'linkedin_personal' => 86400];

/* ---------------- when each channel is best posted ---------------- */

/** Slots for a channel: list of ['dow' => [1..7], 'time' => 'HH:MM']. Measured slots (src data) win where measure-steer has them. */
function pm_social_channel_times(string $brand, string $ch): array
{
    $travel = $brand === 'travel';
    $all = [1, 2, 3, 4, 5, 6, 7];
    $weekend = [5, 6, 7];
    $def = match ($ch) {
        'whatsapp_status' => [['dow' => $all, 'time' => '06:45'], ['dow' => $all, 'time' => '19:30']],
        'instagram' => [['dow' => $travel ? [4, 5, 6, 7] : $all, 'time' => '12:30'], ['dow' => $travel ? [4, 5, 6, 7] : $all, 'time' => '19:00']],
        'linkedin', 'linkedin_personal' => [['dow' => [2, 3, 4], 'time' => '07:30']],
        'google' => [['dow' => [3], 'time' => '10:00']],
        'tiktok', 'youtube_short' => [['dow' => $travel ? $weekend : $all, 'time' => '18:30'], ['dow' => $travel ? $weekend : $all, 'time' => '20:30']],
        'x' => $travel ? [['dow' => $all, 'time' => '18:00']] : [['dow' => [1, 2, 3, 4, 5], 'time' => '08:00'], ['dow' => [1, 2, 3, 4, 5], 'time' => '17:30']],
        default => [['dow' => $all, 'time' => '08:30']],
    };
    if (in_array($ch, ['instagram', 'tiktok', 'youtube_short', 'x'], true) && function_exists('pm_social_slots')) {
        try {
            $m = [];
            foreach ((array)pm_social_slots($brand) as $s) {
                if (($s['src'] ?? '') === 'data' && preg_match('/^\d{2}:\d{2}$/', (string)($s['time'] ?? '')) && (int)($s['dow'] ?? 0) >= 1) {
                    $m[] = ['dow' => [(int)$s['dow']], 'time' => (string)$s['time']];
                }
            }
            if (count($m) >= 2) {
                return array_slice($m, 0, 3);
            }
        } catch (Throwable) {
        }
    }
    return $def;
}

/** First slot of a channel at or after $fromTs (within 3 weeks, else $fromTs). */
function pm_ch_next_slot(string $brand, string $ch, int $fromTs): int
{
    $slots = pm_social_channel_times($brand, $ch);
    usort($slots, fn($a, $b) => strcmp($a['time'], $b['time']));
    for ($d = 0; $d < 21; $d++) {
        $day = date('Y-m-d', strtotime(date('Y-m-d', $fromTs) . " +$d days"));
        $dow = (int)date('N', strtotime($day));
        foreach ($slots as $s) {
            $ts = strtotime($day . ' ' . $s['time']);
            if (in_array($dow, $s['dow'], true) && $ts !== false && $ts >= $fromTs) {
                return $ts;
            }
        }
    }
    return $fromTs;
}

/** When a hand-post is due: the post's own time plus the channel's offset, moved to the channel's next slot. 'Y-m-d H:i'. */
function pm_ch_due(string $brand, string $ch, array $p): string
{
    $base = strtotime((string)($p['published'] ?? '')) ?: (strtotime((string)($p['when'] ?? '')) ?: time());
    return date('Y-m-d H:i', pm_ch_next_slot($brand, $ch, $base + (PM_CH_OFFSET[$ch] ?? 0)));
}

/* ---------------- which hand-post channels suit a post ---------------- */

function pm_ch_off(string $brand, string $ch): bool
{
    return !empty(pm_channels_cfg($brand)['ticks']['off_' . $ch]);
}

/** Hand-post channels that make sense for this post's format and the brand's setup (owner can switch channels off). */
function pm_manual_channels(array $p): array
{
    $brand = pm_ch_brand((string)($p['brand'] ?? 'promanaged'));
    $fmt = (string)($p['format'] ?? 'image');
    $cfg = pm_channels_cfg($brand);
    $li = function_exists('pm_social_settings') ? !empty(pm_social_settings($brand)['channels']['linkedin']) : false;
    $out = [];
    foreach (PM_CH_MANUAL as $ch) {
        if (!empty($cfg['ticks']['off_' . $ch])) {
            continue;
        }
        $ok = match ($ch) {
            'instagram' => $fmt !== 'text' && (pm_ch_mode($brand, 'instagram') === 'manual' || (($p['channels']['instagram']['mode'] ?? '') === 'manual' && ($p['channels']['instagram']['state'] ?? '') !== 'done')),
            'tiktok' => in_array($fmt, ['reel', 'carousel'], true),
            'youtube_short' => $fmt === 'reel',
            'google' => in_array($fmt, ['image', 'text', 'carousel'], true),
            'whatsapp_status' => true,
            'whatsapp_channel', 'x' => in_array($fmt, ['image', 'text', 'carousel'], true),
            'linkedin' => $li && pm_ch_mode($brand, 'linkedin') === 'manual' && in_array($fmt, ['image', 'text', 'carousel'], true),
            'linkedin_personal' => !empty($cfg['founder_voice']) && in_array($fmt, ['image', 'text', 'carousel'], true),
            default => false,
        };
        if ($ok) {
            $out[] = $ch;
        }
    }
    return $out;
}

/** Hand-post tasks for the next $days days (and anything up to 3 days overdue), soonest first. Only approved or published posts. */
function pm_manual_tasks(string $brand, int $days = 7, bool $light = false): array
{
    $brand = pm_ch_brand($brand);
    $now = time();
    $from = $now - 3 * 86400;
    $to = $now + max(1, $days) * 86400;
    $posts = array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $brand && in_array($p['status'] ?? '', ['approved', 'published'], true) && !empty($p['when'])));
    usort($posts, fn($a, $b) => strcmp($a['when'], $b['when']));
    $out = [];
    $googleWeeks = [];
    foreach ($posts as $p) {
        if (($t = strtotime($p['when'])) === false || $t < $from - 86400 || $t > $to) {
            continue;
        }
        foreach (pm_manual_channels($p) as $ch) {
            $e = (array)($p['channels'][$ch] ?? []);
            if (($e['state'] ?? 'todo') !== 'todo') {
                continue;
            }
            $due = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string)($e['at'] ?? '')) ? $e['at'] : pm_ch_due($brand, $ch, $p);
            $dts = strtotime($due);
            if ($dts < $from || $dts > $to) {
                continue;
            }
            if ($ch === 'google') { // one Google update a week is plenty
                $wk = date('o-W', $dts);
                if (isset($googleWeeks[$wk])) {
                    continue;
                }
                $googleWeeks[$wk] = true;
            }
            $v = $light ? ['label' => PM_CH_LABEL[$ch] ?? $ch, 'text' => '', 'parts' => [], 'chars' => 0, 'limit' => PM_CH_LIMIT[$ch] ?? 0, 'size' => PM_CH_SIZE[$ch] ?? 'sq', 'open' => '', 'warn' => []] : pm_var_build($p, $ch);
            $hasPic = !in_array($p['format'] ?? 'image', ['text', 'reel'], true);
            $out[] = ['post_id' => (string)$p['id'], 'channel' => $ch, 'label' => $v['label'], 'when' => $due, 'overdue' => $dts < $now, 'text' => $v['text'], 'parts' => $v['parts'], 'chars' => $v['chars'], 'limit' => $v['limit'],
                'size' => $v['size'], 'asset_url' => $hasPic ? '?simg=' . $p['id'] . '&size=' . $v['size'] . '&dl=1' : '', 'deep_link' => $v['open'], 'state' => 'todo',
                'headline' => (string)($p['headline'] ?? ''), 'ref' => pm_ch_ref($p), 'format' => (string)($p['format'] ?? 'image'), 'warn' => $v['warn']];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['when'], $b['when']));
    return $out;
}

/** Mark posted / skipped (optionally with the pasted link of the post). POST id, ch, url, how. */
function pm_do_task_done(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $ch = (string)($_POST['ch'] ?? '');
    $how = in_array($_POST['how'] ?? '', ['skipped', 'todo'], true) ? $_POST['how'] : 'done';
    $url = trim((string)($_POST['url'] ?? ''));
    $url = preg_match('#^https?://\S+$#i', $url) ? mb_substr($url, 0, 300) : '';
    $to = 'social&view=channels';
    if ($id === '' || !in_array($ch, array_merge(PM_CH_MANUAL, ['host_kit']), true)) {
        return ['msg' => 'Unknown task.', 'kind' => 'err', 'to' => $to];
    }
    $found = false;
    $r = pm_social_patch($id, function (array $q) use ($vb, $ch, $how, $url, &$found) {
        if (($q['brand'] ?? '') !== $vb) {
            return $q;
        }
        $found = true;
        $old = (array)($q['channels'][$ch] ?? []);
        $q['channels'][$ch] = ['mode' => 'manual', 'state' => $how, 'at' => $how === 'todo' ? ($old['at'] ?? '') : date('Y-m-d H:i'), 'url' => $url !== '' ? $url : (string)($old['url'] ?? ''),
            'due' => (string)($old['due'] ?? $old['at'] ?? ''), 'by' => (string)($GLOBALS['PM_WHO'] ?? '')] + $old;
        return $q;
    });
    if (!$r || !$found) {
        return ['msg' => 'That post is gone.', 'kind' => 'err', 'to' => $to];
    }
    if (function_exists('pm_social_log')) {
        pm_social_log($id, 'hand_post_' . $how, $ch);
    }
    return ['msg' => $how === 'done' ? (PM_CH_LABEL[$ch] ?? $ch) . ' marked as posted.' : ($how === 'skipped' ? 'Skipped.' : 'Put back on the list.'), 'kind' => 'ok', 'to' => $to];
}

/** Which hand-post channels the owner uses (the rest never create tasks). POST use[channel]. */
function pm_do_channels_use(string $vb): array
{
    $use = (array)($_POST['use'] ?? []);
    pm_channels_update($vb, function (array $c) use ($use) {
        foreach (PM_CH_MANUAL as $ch) {
            if (empty($use[$ch])) {
                $c['ticks']['off_' . $ch] = true;
            } else {
                unset($c['ticks']['off_' . $ch]);
            }
        }
        $c['founder_voice'] = !empty($_POST['founder_voice']);
        return $c;
    });
    return ['msg' => 'Channels saved.', 'kind' => 'ok', 'to' => 'social&view=channels'];
}

/* ---------------- today items and the daily alert ---------------- */

function pm_today_manual(string $brand): array
{
    $t = pm_manual_tasks($brand, 2, true);
    $out = [];
    $late = array_filter($t, fn($x) => $x['overdue']);
    if ($late) {
        $names = array_unique(array_column($late, 'label'));
        $out[] = ['text' => count($late) . ' hand-post' . (count($late) > 1 ? 's' : '') . ' overdue (' . implode(', ', array_slice($names, 0, 4)) . ')', 'href' => '?tab=social&view=channels#tasks', 'urgency' => 1];
    }
    $soon = array_filter($t, fn($x) => !$x['overdue'] && substr($x['when'], 0, 10) === date('Y-m-d'));
    if ($soon) {
        $out[] = ['text' => count($soon) . ' hand-post' . (count($soon) > 1 ? 's' : '') . ' to do today', 'href' => '?tab=social&view=channels#tasks', 'urgency' => 0];
    }
    if ($n = count(pm_group_tasks($brand))) {
        $out[] = ['text' => "$n group post" . ($n > 1 ? 's' : '') . ' due this week', 'href' => '?tab=social&view=channels#groups', 'urgency' => 0];
    }
    if ($n = count(pm_host_kits($brand))) {
        $out[] = ['text' => "$n share kit" . ($n > 1 ? 's' : '') . ' to send', 'href' => '?tab=social&view=channels#kits', 'urgency' => 0];
    }
    return $out;
}

/** One owner alert a day when hand-posts are overdue. */
function pm_job_manual_alert(string $brand): string
{
    $late = array_filter(pm_manual_tasks($brand, 1, true), fn($x) => $x['overdue']);
    if (!$late || !function_exists('pm_social_state_set')) {
        return '';
    }
    $key = 'manual_alert_' . $brand;
    $send = false;
    pm_social_state_set(function (array $s) use ($key, &$send) {
        if (($s[$key] ?? '') !== date('Y-m-d')) {
            $s[$key] = date('Y-m-d');
            $send = true;
        }
        return $s;
    });
    if (!$send) {
        return '';
    }
    pm_social_notify((pm_brand_name($brand)) . ': ' . count($late) . ' hand-posts overdue', implode("\n", array_map(fn($x) => $x['label'] . ' · ' . $x['headline'] . ' · due ' . $x['when'], array_slice($late, 0, 8))));
    return count($late) . ' overdue hand-posts: owner alerted';
}

/* ---------------- WhatsApp Status pack ---------------- */

/** A Tip-pillar caption near this week (links and tags removed), or the brand's own facts when none exists. */
function pm_week_tip(string $brand, string $date = ''): array
{
    $date = $date ?: date('Y-m-d');
    $best = null;
    $bd = PHP_INT_MAX;
    foreach (pm_social_posts() as $p) {
        if (($p['brand'] ?? '') !== $brand || !preg_match('/tip|how.?to/i', (string)($p['pillar'] ?? '')) || !in_array($p['status'] ?? '', ['approved', 'published', 'draft', 'review'], true)) {
            continue;
        }
        $d = abs((strtotime(substr((string)$p['when'], 0, 10)) ?: 0) - strtotime($date));
        if ($d < $bd && $d <= 5 * 86400) {
            $bd = $d;
            $best = $p;
        }
    }
    if ($best) {
        return ['post_id' => (string)$best['id'], 'headline' => (string)($best['headline'] ?? ''), 'text' => pm_ch_strip_tags(pm_ch_strip_links((string)$best['caption']))];
    }
    return ['post_id' => '', 'headline' => '', 'text' => ''];
}

/** The Status idea for a day: tip, this week at X, consented proof, question - rotating by date, no AI, no invented facts. */
function pm_status_idea(string $brand, string $date): array
{
    $n = (int)floor(strtotime($date) / 86400);
    $kinds = ['tip', 'week', 'proof', 'question'];
    $kind = $kinds[$n % 4];
    $co = pm_ch_company($brand);
    if ($kind === 'proof') {
        $rows = array_values(array_filter(function_exists('pm_social_proof_usable') ? pm_social_proof_usable($brand) : [], fn($r) => ($r['type'] ?? '') !== 'offer'));
        if ($rows) {
            $r = $rows[intdiv($n, 4) % count($rows)];
            return ['kind' => 'proof', 'label' => 'A word from a client', 'text' => '"' . trim((string)$r['text']) . '"' . (trim((string)($r['client_name'] ?? '')) !== '' ? ' - ' . trim($r['client_name']) : ''), 'note' => 'Used with their consent.'];
        }
        $kind = 'tip';
    }
    if ($kind === 'tip') {
        $tip = pm_week_tip($brand, $date);
        $text = $tip['text'];
        if ($text === '') {
            $facts = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)pm_brain($brand)['facts']))));
            $text = $facts ? $facts[intdiv($n, 4) % count($facts)] : '';
        }
        $ss = pm_ch_sentences($text);
        return ['kind' => 'tip', 'label' => 'A quick tip', 'text' => $text === '' ? '' : pm_ch_cut(implode(' ', array_slice($ss, 0, 2)), 280, '') . "\nWhatsApp us for more.", 'note' => ''];
    }
    if ($kind === 'question') {
        if ($brand === 'travel') {
            return ['kind' => 'question', 'label' => 'A question', 'text' => "Question for you: which part of Malawi should we show next? Reply here and tell us.", 'note' => ''];
        }
        if (pm_brand_is_custom($brand)) {
            return ['kind' => 'question', 'label' => 'A question', 'text' => "Question for you: what would you like to hear more about from $co? Reply here and tell us.", 'note' => ''];
        }
        $pains = function_exists('pm_template') ? (array)(pm_template()['pain_points'] ?? []) : [];
        $pain = $pains ? trim((string)($pains[intdiv($n, 4) % count($pains)]['pain'] ?? '')) : '';
        return ['kind' => 'question', 'label' => 'A question', 'text' => $pain !== '' ? "Does this sound like your business: $pain\nReply YES and we will share a simple fix." : "What is the biggest IT headache in your business this week?\nReply here and tell us.", 'note' => ''];
    }
    return ['kind' => 'week', 'label' => 'This week at ' . $co, 'text' => "This week at $co: [one real thing you did or are doing, with a photo].\nWhatsApp us if you want the same.", 'note' => 'Fill the brackets with something true and add a real photo.'];
}

function pm_ch_company(string $brand): string
{
    $prev = pm_brand();
    pm_brand_set($brand);
    $n = (string)(pm_settings()['company_name'] ?? (pm_brand_name($brand)));
    pm_brand_set($prev);
    return $n;
}

/** Weekly pack: the next 3 posts as story cards with Status text, and a Status idea for each of the next 7 days. */
function pm_status_pack(string $brand, string $prefer = ''): array
{
    $brand = pm_ch_brand($brand);
    $today = date('Y-m-d');
    $posts = array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $brand && in_array($p['status'] ?? '', ['approved', 'draft', 'review', 'published'], true)
        && substr((string)($p['when'] ?? ''), 0, 10) >= $today && in_array($p['format'] ?? 'image', ['image', 'text', 'carousel', 'story', 'reel'], true)));
    usort($posts, fn($a, $b) => [$a['id'] === $prefer ? 0 : 1, $a['when']] <=> [$b['id'] === $prefer ? 0 : 1, $b['when']]);
    $items = [];
    foreach (array_slice($posts, 0, 3) as $p) {
        $v = pm_var_build($p, 'whatsapp_status');
        $items[] = ['id' => $p['id'], 'headline' => (string)($p['headline'] ?? ''), 'when' => $p['when'], 'status' => $p['status'], 'text' => $v['text'], 'chars' => $v['chars'], 'warn' => $v['warn'],
            'card' => ($p['format'] ?? 'image') === 'text' ? '' : '?simg=' . $p['id'] . '&size=story&dl=1'];
    }
    $done = (array)pm_channels_cfg($brand)['status_done'];
    $ideas = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("$today +$i days"));
        $ideas[] = ['date' => $d] + pm_status_idea($brand, $d) + ['done' => !empty($done[$d])];
    }
    return ['posts' => $items, 'ideas' => $ideas];
}

function pm_do_status_done(string $vb): array
{
    $d = (string)($_POST['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return ['msg' => 'Unknown day.', 'kind' => 'err', 'to' => 'social&view=channels'];
    }
    $on = ($_POST['how'] ?? 'done') !== 'undo';
    pm_channels_update($vb, function (array $c) use ($d, $on) {
        if ($on) {
            $c['status_done'][$d] = true;
        } else {
            unset($c['status_done'][$d]);
        }
        $cut = date('Y-m-d', strtotime('-60 days'));
        $c['status_done'] = array_filter((array)$c['status_done'], fn($v, $k) => $k >= $cut, ARRAY_FILTER_USE_BOTH);
        return $c;
    });
    return ['msg' => $on ? 'Status marked as posted.' : 'Put back.', 'kind' => 'ok', 'to' => 'social&view=channels'];
}

/* ---------------- reels: film card, SRT, film day ---------------- */

function pm_srt_time(float $s): string
{
    $ms = (int)round($s * 1000);
    return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
}

/** SubRip captions from the shots' "say" lines: each cue runs for its shot's seconds. */
function pm_srt_from_video(array $video): string
{
    $t = 0.0;
    $n = 0;
    $out = '';
    foreach ((array)($video['shots'] ?? []) as $s) {
        $secs = max(1.0, (float)($s['secs'] ?? 3));
        $say = trim(preg_replace('/\s+/', ' ', (string)($s['say'] ?? '')));
        if ($say !== '') {
            $n++;
            $out .= $n . "\n" . pm_srt_time($t) . ' --> ' . pm_srt_time($t + $secs) . "\n" . wordwrap($say, 42, "\n", true) . "\n\n";
        }
        $t += $secs;
    }
    return $out;
}

function pm_ch_data_uri(string $file, string $mime = 'image/png'): string
{
    return is_file($file) && filesize($file) < 3 * 1024 * 1024 ? 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($file)) : '';
}

/** Reels waiting for their video, soonest first. */
function pm_film_day(string $brand): array
{
    $out = array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? '') === $brand && ($p['format'] ?? '') === 'reel' && !pm_social_has_media($p)
        && in_array($p['status'] ?? '', ['needs_video', 'draft', 'approved', 'review', 'needs_edit'], true) && substr((string)$p['when'], 0, 10) >= date('Y-m-d', strtotime('-1 day'))));
    usort($out, fn($a, $b) => strcmp($a['when'], $b['when']));
    return $out;
}

/** A reel with no video and less than 48 hours to its slot. */
function pm_reel_late(array $p): bool
{
    return ($p['format'] ?? '') === 'reel' && !pm_social_has_media($p) && (strtotime((string)$p['when']) ?: 0) - time() < 48 * 3600;
}

function pm_ch_shot_spec(array $p, array $s, int $i, int $of): array
{
    return ['n' => $i + 1, 'of' => $of, 'secs' => (int)($s['secs'] ?? 3), 'show' => (string)($s['show'] ?? ''), 'say' => (string)($s['say'] ?? ''), 'onscreen' => (string)($s['onscreen'] ?? ''), 'title' => (string)($p['headline'] ?? '')];
}

/** Printable shoot sheet for one reel (HTML). Cards are inlined as pictures when content-engine can draw them. */
function pm_film_card_html(array $p, bool $withCards = true): string
{
    $v = (array)($p['video'] ?? []);
    $shots = array_values((array)($v['shots'] ?? []));
    $brand = pm_ch_brand((string)$p['brand']);
    $h = '<div class="sx-print sx-film"><h3>' . pm_h((string)($p['headline'] ?: 'Reel')) . ' · film card</h3>';
    if (!$v) {
        return $h . '<p class="hint">This reel has no shot list yet. Use the script below.</p><pre style="white-space:pre-wrap">' . pm_h((string)($p['script'] ?? '')) . '</pre></div>';
    }
    $h .= '<p><b>Hook (say it in the first 2 seconds):</b> ' . pm_h((string)($v['hook_2s'] ?? '')) . '</p>';
    $h .= '<table class="sx-table"><tr><th>#</th><th>Secs</th><th>Show</th><th>Say</th><th>On-screen text</th></tr>';
    foreach ($shots as $i => $s) {
        $h .= '<tr><td>' . ($i + 1) . '</td><td>' . (int)($s['secs'] ?? 0) . '</td><td>' . pm_h((string)($s['show'] ?? '')) . '</td><td>' . pm_h((string)($s['say'] ?? '')) . '</td><td>' . pm_h((string)($s['onscreen'] ?? '')) . '</td></tr>';
    }
    $h .= '</table><p><b>Total:</b> about ' . (int)($v['total_secs'] ?? array_sum(array_column($shots, 'secs'))) . ' seconds. <b>Call to action:</b> ' . pm_h((string)($v['cta'] ?? '')) . '</p>';
    if (trim((string)($v['cover_text'] ?? '')) !== '') {
        $h .= '<p><b>Cover text:</b> ' . pm_h((string)$v['cover_text']) . '</p>';
    }
    if (trim((string)($v['music_note'] ?? '')) !== '') {
        $h .= '<p><b>Music:</b> ' . pm_h((string)$v['music_note']) . '</p>';
    }
    $h .= '<ul><li>Good light: face a window, never the sun behind you.</li><li>Hold the phone upright (9:16) and wipe the lens.</li><li>Hook first: say or show it in the first 2 seconds.</li><li>One take per shot; film each shot on its own, then join them.</li><li>Quiet place, speak slowly and clearly.</li></ul>';
    if ($withCards && function_exists('pm_card_special')) {
        $sb = pm_card_special($brand, 'storyboard', ['title' => (string)($p['headline'] ?? ''), 'hook' => (string)($v['hook_2s'] ?? ''), 'cta' => (string)($v['cta'] ?? ''), 'total_secs' => (int)($v['total_secs'] ?? 0),
            'shots' => array_map(fn($s, $i) => pm_ch_shot_spec($p, $s, $i, count($shots)), $shots, array_keys($shots))], 'story');
        $u = is_string($sb) ? pm_ch_data_uri($sb) : '';
        if ($u !== '') {
            $h .= '<p><img src="' . $u . '" alt="Storyboard" style="max-width:220px;border-radius:8px"> <a class="btn small" download="storyboard-' . pm_h((string)$p['id']) . '.png" href="' . $u . '">Download storyboard</a></p>';
        }
    }
    return $h . '</div>';
}

/** One shot card (picture, data address) for a reel; '' when not available. */
function pm_film_shot_card(array $p, int $i): string
{
    $shots = array_values((array)($p['video']['shots'] ?? []));
    if (!isset($shots[$i]) || !function_exists('pm_card_special')) {
        return '';
    }
    $f = pm_card_special(pm_ch_brand((string)$p['brand']), 'shot', pm_ch_shot_spec($p, $shots[$i], $i, count($shots)), 'sq');
    return is_string($f) ? pm_ch_data_uri($f) : '';
}

/** Slideshow instead of a video: carousel slides built from the shots. Back to draft so it is checked and approved again. POST id. */
function pm_do_reel_to_carousel(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $to = 'social&view=channels';
    $msg = '';
    $r = pm_social_patch($id, function (array $q) use ($vb, &$msg) {
        if (($q['brand'] ?? '') !== $vb || ($q['format'] ?? '') !== 'reel') {
            $msg = 'That is not a reel of yours.';
            return $q;
        }
        $slides = [];
        foreach (array_slice(array_values((array)($q['video']['shots'] ?? [])), 0, 6) as $s) {
            $say = trim((string)($s['say'] ?? ''));
            $h = trim((string)($s['onscreen'] ?? '')) ?: pm_ch_cut($say !== '' ? $say : (string)($s['show'] ?? ''), 50, '');
            $t = $say !== '' ? $say : trim((string)($s['show'] ?? ''));
            if ($h !== '') {
                $slides[] = ['h' => mb_substr($h, 0, 60), 't' => mb_substr($t, 0, 140)];
            }
        }
        if (count($slides) < 2) {
            $msg = 'The shot list is too short for a slideshow: edit the reel first.';
            return $q;
        }
        $q['format'] = 'carousel';
        $q['slides'] = $slides;
        $q['status'] = 'draft';
        $q['media'] = '';
        $q['error'] = '';
        $q['alt'] = trim((string)($q['alt'] ?? '')) ?: pm_variant_alt($q);
        unset($q['approved_hash'], $q['approved_by'], $q['approved_at']);
        if (function_exists('pm_social_lint_post') && function_exists('pm_social_resolve')) {
            $q = pm_social_resolve($q, pm_social_lint_post($q));
        }
        $q['channels'] = array_diff_key((array)($q['channels'] ?? []), ['tiktok' => 1, 'youtube_short' => 1]);
        return $q;
    });
    if (!$r || $msg !== '') {
        return ['msg' => $msg ?: 'That post is gone.', 'kind' => 'err', 'to' => $to];
    }
    if (function_exists('pm_social_log')) {
        pm_social_log($id, 'reel_to_carousel', count($r['slides'] ?? []) . ' slides');
    }
    return ['msg' => 'Turned into a slideshow post (' . count($r['slides']) . ' slides). Read it in the plan, then approve it.', 'kind' => 'ok', 'to' => 'social'];
}

/* ---------------- community groups ---------------- */

/** Groups that need a value post this week (never more than one per 3 days). */
function pm_group_tasks(string $brand): array
{
    $brand = pm_ch_brand($brand);
    $cfg = pm_channels_cfg($brand);
    $wk = date('o-W');
    $tip = pm_week_tip($brand);
    $out = [];
    foreach ((array)$cfg['groups'] as $g) {
        $last = (string)($g['last_post'] ?? '');
        $lt = $last !== '' ? strtotime($last) : 0;
        $due = $lt === 0 || (date('o-W', $lt) !== $wk && time() - $lt >= 3 * 86400);
        if (!$due) {
            continue;
        }
        $out[] = ['group' => $g, 'text' => pm_ch_cut($tip['text'], 700, ''), 'tip_post' => $tip['post_id'], 'note' => 'Post a tip, not a link-drop; reply to 3 threads.'];
    }
    return $out;
}

function pm_do_group_save(string $vb): array
{
    $rows = (array)($_POST['group'] ?? []);
    pm_channels_update($vb, function (array $c) use ($rows) {
        $old = [];
        foreach ((array)$c['groups'] as $g) {
            $old[$g['id']] = $g;
        }
        $new = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !empty($r['delete'])) {
                continue;
            }
            $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 80);
            if ($name === '') {
                continue;
            }
            $id = preg_match('/^[a-f0-9]{8}$/', (string)($r['id'] ?? '')) ? $r['id'] : bin2hex(random_bytes(4));
            $url = trim((string)($r['url'] ?? ''));
            $new[] = ['id' => $id, 'name' => $name, 'url' => preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 300) : ($url === '' ? '' : 'https://' . mb_substr($url, 0, 290)),
                'platform' => in_array($r['platform'] ?? '', ['facebook', 'whatsapp', 'linkedin', 'other'], true) ? $r['platform'] : 'facebook', 'rule_note' => mb_substr(trim((string)($r['rule_note'] ?? '')), 0, 300),
                'last_post' => (string)($old[$id]['last_post'] ?? ''), 'role' => ($r['role'] ?? '') === 'person' ? 'person' : 'page'];
        }
        $c['groups'] = $new;
        return $c;
    });
    return ['msg' => 'Groups saved. Nothing is posted to them automatically.', 'kind' => 'ok', 'to' => 'social&view=channels'];
}

function pm_do_group_done(string $vb): array
{
    $id = (string)($_POST['gid'] ?? '');
    pm_channels_update($vb, function (array $c) use ($id) {
        foreach ($c['groups'] as $i => $g) {
            if ($g['id'] === $id) {
                $c['groups'][$i]['last_post'] = date('Y-m-d');
            }
        }
        return $c;
    });
    return ['msg' => 'Marked as posted. The next one is due next week.', 'kind' => 'ok', 'to' => 'social&view=channels'];
}

/* ---------------- host / client share kit ---------------- */

/** The consented proof row a post names, or null. Same row the planner may use (consent given, not expired). */
function pm_host_kit_row(array $p): ?array
{
    $id = trim((string)($p['proof_id'] ?? ''));
    if ($id === '' || !function_exists('pm_social_proof_find')) {
        return null;
    }
    return pm_social_proof_find((string)$p['brand'], $id);
}

function pm_host_kit_message(array $p, array $row): array
{
    $brand = pm_ch_brand((string)$p['brand']);
    $name = trim((string)($row['client_name'] ?? ''));
    $lead = null;
    if (($row['lead_id'] ?? '') !== '' && function_exists('pm_leads')) {
        foreach (pm_leads() as $l) {
            if (($l['id'] ?? '') === $row['lead_id']) {
                $lead = $l;
            }
        }
    }
    $first = $lead ? trim(explode(' ', trim((string)preg_replace('/^(dr|mr|mrs|ms|miss|prof|eng|hon|rev|pastor)\.?\s+/i', '', (string)($lead['contact'] ?? ''))))[0] ?? '') : '';
    $post = pm_ch_fb_url((string)($p['fb_id'] ?? ''));
    $co = pm_ch_company($brand);
    $tag = !empty($row['tag_ok']) ? ' You said we may tag you, so we will try to tag your page too.' : '';
    $txt = 'Hello' . ($first !== '' ? " $first" : '') . ", we have just featured " . ($name !== '' ? $name : 'you') . " on $co: " . ($post !== '' ? $post : '[post link]')
        . ". If you are happy with it, please share it on your own page." . $tag . " Thank you for being part of $co.";
    $wa = $lead && function_exists('pm_wa_link') ? pm_wa_link($lead, $txt) : '';
    return ['text' => $txt, 'wa' => $wa];
}

/** Share kits waiting to be sent: published posts that name a consented proof row. */
function pm_host_kits(string $brand): array
{
    $brand = pm_ch_brand($brand);
    $out = [];
    foreach (pm_social_posts() as $p) {
        if (($p['brand'] ?? '') !== $brand || ($p['channels']['host_kit']['state'] ?? '') !== 'todo' || ($p['status'] ?? '') !== 'published') {
            continue;
        }
        $row = pm_host_kit_row($p);
        if ($row === null) {
            continue; // consent withdrawn or expired since: no kit
        }
        $m = pm_host_kit_message($p, $row);
        $out[] = ['post' => $p, 'row' => $row, 'msg' => $m['text'], 'wa' => $m['wa']];
    }
    return $out;
}

/** The two pictures of a kit (data addresses) and the badge's link. */
function pm_host_kit_cards(array $kit): array
{
    $p = $kit['post'];
    $row = $kit['row'];
    $brand = pm_ch_brand((string)$p['brand']);
    $travel = $brand === 'travel';
    $link = function_exists('pm_enquire_url') ? (string)pm_enquire_url($brand, $travel ? 'host' : (pm_brand_is_custom($brand) ? '' : 'check'), ($travel ? 'host-' : 'client-') . $row['id']) : '';
    $feat = $badge = '';
    if (function_exists('pm_card_special')) {
        $f = pm_card_special($brand, 'featured', ['name' => (string)($row['client_name'] ?? ''), 'headline' => (string)($p['headline'] ?? ''), 'handle' => (string)($row['ig_handle'] ?? '')], 'sq');
        $feat = is_string($f) ? pm_ch_data_uri($f) : '';
        $b = pm_card_special($brand, 'badge', ['text' => $travel ? 'Listed with Travel Malawi' : 'Powered by ' . pm_brand_name($brand), 'link' => $link], 'sq');
        $badge = is_string($b) ? pm_ch_data_uri($b) : '';
    }
    return ['featured' => $feat, 'badge' => $badge, 'link' => $link];
}
