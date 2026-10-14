<?php
/**
 * Agent conversations (Audit, admin only): what the AI agents said to each other and did, day by day, as a short chat in plain words, each agent by its name.
 * Written by code from what the agents already log and the notes they pass on the team board: no AI, no extra tokens. One file a day (data/agent_talk/YYYY-MM-DD.json),
 * so a day can be deleted on its own; a deleted day is not rebuilt.
 */

/** The agents: each department's role, its usual name and what it does. The owner can rename them (Operations › Team and currencies › Your AI team). */
const PM_AGENT_ROLES = ['director' => 'Director', 'scouts' => 'Scout', 'research' => 'Researcher', 'writers' => 'Writer', 'followup' => 'Follow-up', 'replies' => 'Replies',
    'social' => 'Social', 'advisor' => 'Advisor', 'contact' => 'Contact Finder', 'sender' => 'Sender'];
const PM_AGENT_FIRST = ['director' => 'Amara', 'scouts' => 'Kwame', 'research' => 'Nia', 'writers' => 'Tiwo', 'followup' => 'Chiso', 'replies' => 'Mphatso',
    'social' => 'Thandi', 'advisor' => 'Jabu', 'contact' => 'Zola', 'sender' => 'Pemba'];
const PM_AGENT_JOBS = [
    'director' => 'Sets the day\'s strategy and gives each department its orders at the morning meeting.',
    'scouts' => 'Finds new businesses to write to.',
    'research' => 'Studies each lead: the business, its news, the owner\'s name and contacts.',
    'writers' => 'Writes the first emails and messages.',
    'followup' => 'Decides when to follow up and writes the follow-ups.',
    'replies' => 'Reads what people write back and drafts the answers; tells the others what each reply teaches.',
    'social' => 'Head of Social: reads the Page\'s comments and messages, finds people asking for what we offer, holds the morning huddle and sets the day\'s tasks.',
    'advisor' => 'Shows what is selling and the next moves.',
    'contact' => 'Finds missing emails, numbers and owner names.',
    'sender' => 'Sends what you approved, checks every email could not look like spam, and keeps the mail ledger.',
];

/** One agent's first name: the owner's choice, or the usual one. */
function pm_agent_first(string $dept): string
{
    $own = trim((string)(((array)(pm_settings()['agent_names'] ?? []))[$dept] ?? ''));
    return $own !== '' ? $own : (PM_AGENT_FIRST[$dept] ?? ucfirst($dept));
}

/** The agents by name (what the transcript calls them), and the department each name speaks for: ['director' => 'Amara (Director)', ...]. */
function pm_agent_names(): array
{
    static $memo = null;
    if ($memo !== null && empty($GLOBALS['PM_AGENT_NAMES_FRESH'])) {
        return $memo;
    }
    $m = [];
    foreach (PM_AGENT_ROLES as $d => $role) {
        $m[$d] = pm_agent_first($d) . ' (' . $role . ')';
    }
    return $memo = $m + ['swarm' => 'The team', 'owner' => 'You', 'all' => 'everyone', 'email' => $m['sender'], 'qualifier' => $m['research'], 'desks' => $m['social']];
}

/** The name for whatever an agent calls itself in the logs ("Scout", "Follow-up", "Contact Finder", "Copy desk"...). */
function pm_talk_name(string $who): string
{
    $k = strtolower(trim($who));
    $map = ['director' => 'director', 'scout' => 'scouts', 'scouts' => 'scouts', 'directory' => 'scouts', 'watch' => 'scouts', 'researcher' => 'research', 'research' => 'research',
        'qualifier' => 'research', 'writer' => 'writers', 'writers' => 'writers', 'follow-up' => 'followup', 'followup' => 'followup', 'win-back' => 'followup', 'reply' => 'replies',
        'replies' => 'replies', 'social' => 'social', 'copy desk' => 'social', 'photo desk' => 'social', 'brand team' => 'social', 'idea room' => 'social', 'advisor' => 'advisor',
        'contact finder' => 'contact', 'sender' => 'sender', 'email' => 'sender', 'swarm' => 'swarm', 'owner' => 'owner', 'you' => 'owner', 'all' => 'all'];
    $d = $map[$k] ?? '';
    return $d !== '' ? pm_agent_names()[$d] : ucfirst($who);
}

function pm_talk_file(string $day): string
{
    return PM_DATA . '/agent_talk/' . preg_replace('/[^0-9-]/', '', $day) . '.json';
}

/** Adds a line to today's conversation: who said it, to whom ('' = the room), what. Never throws. */
function pm_talk(string $brand, string $who, string $text, string $to = ''): void
{
    $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
    if ($text === '') {
        return;
    }
    try {
        $dir = PM_DATA . '/agent_talk';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $f = pm_talk_file(date('Y-m-d'));
        $fp = @fopen($f, 'c+');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_EX);
        $d = json_decode((string)stream_get_contents($fp), true);
        $d = is_array($d) ? $d : ['lines' => []];
        if (!empty($d['deleted'])) { // the admin deleted today: it starts again from now
            $d = ['lines' => []];
        }
        $d['lines'][] = ['at' => date('H:i'), 'brand' => $brand, 'who' => $who, 'to' => $to, 'text' => mb_substr($text, 0, 300)];
        $d['lines'] = array_slice($d['lines'], -1500);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
        fclose($fp);
    } catch (Throwable) {
    }
}

/** An agent's log line, said in the first person ("Mzuzu lodges: 3 found" becomes "I found 3 lodges and guest houses in Mzuzu"). */
function pm_talk_say(string $agent, string $msg): string
{
    $m = trim((string)preg_replace('/^\[[^\]]+\]\s*/', '', $msg));
    $a = strtolower($agent);
    if ($a === 'scout' && preg_match('/^(.+) in (.+): (\d+) found$/i', $m, $x)) {
        return "I searched for {$x[1]} in {$x[2]} and found {$x[3]}.";
    }
    if ($a === 'swarm' && preg_match('/^Run finished: (.+)$/i', $m, $x)) {
        return "Today's run is done: {$x[1]}.";
    }
    if ($a === 'directory' && preg_match('/^(.+): (\d+) listed, (\d+) new$/i', $m, $x)) {
        return "The directory for {$x[1]} lists {$x[2]}; {$x[3]} are new to us.";
    }
    return $m;
}

/** Rebuilds a past day from the agents' own log and the team board, when it has no conversation file yet (and was not deleted). */
function pm_talk_backfill(string $day): void
{
    $f = pm_talk_file($day);
    $have = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (is_array($have) && (!empty($have['deleted']) || !empty($have['rebuilt']) || !empty($have['merged']))) {
        return; // deleted, or already rebuilt from the logs
    }
    $from = is_array($have) && !empty($have['lines']) ? (string)$have['lines'][0]['at'] : '99:99'; // a day that began before this log existed: the earlier part comes from the logs
    $lines = [];
    foreach (pm_load('agent_log', fn() => []) as $r) {
        if (str_starts_with((string)($r['at'] ?? ''), $day) && substr((string)$r['at'], 11, 5) < $from) {
            $brand = preg_match('/^\[([^\]]+)\]/', (string)$r['msg'], $bm) ? (array_search($bm[1], array_map('pm_brand_name', array_combine(pm_brand_ids(), pm_brand_ids())), true) ?: 'promanaged') : 'promanaged';
            $lines[] = ['at' => substr((string)$r['at'], 11, 5), 'brand' => $brand, 'who' => (string)$r['agent'], 'to' => '', 'text' => pm_talk_say((string)$r['agent'], (string)$r['msg'])];
        }
    }
    foreach ((array)pm_load('team_board', fn() => []) as $brand => $rows) {
        foreach ((array)$rows as $r) {
            if (str_starts_with((string)($r['at'] ?? ''), $day) && substr((string)$r['at'], 11, 5) < $from) {
                $lines[] = ['at' => substr((string)$r['at'], 11, 5), 'brand' => (string)$brand, 'who' => (string)$r['from'], 'to' => (string)$r['to'], 'text' => (string)$r['text']];
            }
        }
    }
    usort($lines, fn($x, $y) => strcmp($x['at'], $y['at']));
    if (is_array($have)) { // today: the earlier part goes in front of what was said since
        pm_talk_merge($day, $lines);
        return;
    }
    if (!$lines) {
        return;
    }
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    @file_put_contents($f, json_encode(['lines' => $lines, 'rebuilt' => true], JSON_UNESCAPED_UNICODE));
}

/** The days that have a conversation, newest first (the last 30). */
function pm_talk_days(): array
{
    for ($i = 0; $i < 14; $i++) { // the agents' own log reaches back a few days: those days get a conversation too
        pm_talk_backfill(date('Y-m-d', strtotime("-$i days")));
    }
    $days = [];
    foreach (glob(PM_DATA . '/agent_talk/*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d) && empty($d['deleted']) && !empty($d['lines'])) {
            $days[] = basename($f, '.json');
        }
    }
    rsort($days);
    return array_slice($days, 0, 30);
}

/**
 * One day as a short chat: who said what, in a few lines. Lines in a row from the same agent to the same listener become one line ("I searched 5 places and found 13:
 * Chintheche 6, Mzuzu 3, ..."), so a day reads in a minute. $brand '' = every business. Returns [['at','who','to','text','brand']].
 */
function pm_talk_day(string $day, string $brand = ''): array
{
    $d = json_decode((string)@file_get_contents(pm_talk_file($day)), true);
    $rows = array_values(array_filter((array)($d['lines'] ?? []), fn($r) => $brand === '' || ($r['brand'] ?? '') === $brand));
    $out = [];
    foreach ($rows as $r) {
        $last = $out ? count($out) - 1 : -1;
        if ($last >= 0 && $out[$last]['who'] === $r['who'] && $out[$last]['to'] === ($r['to'] ?? '') && $out[$last]['brand'] === $r['brand']) {
            $out[$last]['parts'][] = $r['text'];
            continue;
        }
        $out[] = ['at' => (string)$r['at'], 'who' => (string)$r['who'], 'to' => (string)($r['to'] ?? ''), 'brand' => (string)$r['brand'], 'parts' => [(string)$r['text']]];
    }
    foreach ($out as $i => $o) {
        $p = $o['parts'];
        $scout = array_filter(array_map(fn($t) => preg_match('/^I searched for (.+) in (.+) and found (\d+)\.$/', $t, $x) ? [$x[2], (int)$x[3]] : null, $p));
        if (count($scout) > 1 && count($scout) === count($p)) { // many searches: one line with the total and where
            $tot = array_sum(array_column($scout, 1));
            $where = implode(', ', array_map(fn($s) => $s[0] . ' ' . $s[1], array_filter($scout, fn($s) => $s[1] > 0)));
            $out[$i]['text'] = 'I searched ' . count($scout) . " places and found $tot" . ($where !== '' ? ": $where." : '.');
        } else {
            $uniq = array_values(array_unique($p));
            $out[$i]['text'] = implode(' ', array_slice($uniq, 0, 3)) . (count($uniq) > 3 ? ' (and ' . (count($uniq) - 3) . ' more like this)' : '');
        }
        unset($out[$i]['parts']);
    }
    $seen = []; // the same thing said again later in the day: one line, with how often
    foreach ($out as $i => $o) {
        $k = $o['brand'] . '|' . $o['who'] . '|' . $o['to'] . '|' . $o['text'];
        if (isset($seen[$k])) {
            $out[$seen[$k]]['again'] = ($out[$seen[$k]]['again'] ?? 0) + 1;
            unset($out[$i]);
            continue;
        }
        $seen[$k] = $i;
    }
    foreach ($out as $i => $o) {
        if (!empty($o['again'])) {
            $out[$i]['text'] .= ' (said ' . ($o['again'] + 1) . ' times today)';
        }
    }
    return array_values($out);
}

/** Deletes a day's conversation (admin). A deleted day is not rebuilt from the logs. */
function pm_talk_delete(string $day): bool
{
    $f = pm_talk_file($day);
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    return @file_put_contents($f, json_encode(['deleted' => date('Y-m-d H:i'), 'lines' => []])) !== false;
}

/** Puts earlier lines in front of a day that already has a conversation, once. */
function pm_talk_merge(string $day, array $earlier): void
{
    $fp = @fopen(pm_talk_file($day), 'c+');
    if (!$fp) {
        return;
    }
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (is_array($d) && empty($d['merged']) && empty($d['deleted'])) {
        $d['lines'] = array_merge($earlier, (array)($d['lines'] ?? []));
        $d['merged'] = true;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
}
