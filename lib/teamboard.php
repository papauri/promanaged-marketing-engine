<?php
/**
 * The team board: how the departments (the agents) talk to each other, at no AI cost. A department posts a short note when something happens that another one should
 * act on (a reply and what it said, a deal lost and why, the owner turning a draft down and why, the Director's orders for the day); every agent's instructions carry the
 * few newest notes addressed to it (a few dozen tokens), so the Writers write what the replies taught, the Follow-up agent knows what the owner disliked, Social posts
 * what the Director wants. The owner sees the same board on Overview and can strike a note that is wrong.
 * Data: data/team_board.json {brand: [{id, at, from, to, text, key}]}; notes live 14 days, newest 200 kept per business.
 */

const PM_TB_DEPTS = ['director' => 'Director', 'scouts' => 'Scouts', 'research' => 'Researchers', 'writers' => 'Writers', 'followup' => 'Follow-up', 'replies' => 'Replies',
    'social' => 'Social', 'contact' => 'Contact Finder', 'sender' => 'Sender', 'owner' => 'You', 'all' => 'Everyone'];

/** Posts a note from one department to another. $key: the same key replaces the earlier note (so a repeated event does not fill the board). */
function pm_tb_post(string $brand, string $from, string $to, string $text, string $key = ''): void
{
    $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
    if ($text === '' || !isset(PM_TB_DEPTS[$to])) {
        return;
    }
    function_exists('pm_talk') && pm_talk($brand, $from, $text, $to === $from ? '' : $to); // the Audit's conversation: who told whom
    try {
        pm_update('team_board', function (array $all) use ($brand, $from, $to, $text, $key) {
            $rows = array_values(array_filter((array)($all[$brand] ?? []), fn($r) => ($key === '' || ($r['key'] ?? '') !== $key) && strtotime((string)$r['at']) > strtotime('-14 days')));
            $rows[] = ['id' => substr(sha1(uniqid('', true)), 0, 10), 'at' => date('Y-m-d H:i'), 'from' => $from, 'to' => $to, 'text' => mb_substr($text, 0, 240), 'key' => $key];
            $all[$brand] = array_slice($rows, -200);
            return $all;
        }, fn() => []);
    } catch (Throwable) {
        // a note that could not be written never stops the work
    }
}

/** The newest notes for a department (and those to everyone): another head's ask for help and the Director's orders first, then the rest newest first. */
function pm_tb_for(string $brand, string $to, int $n = 4): array
{
    $rows = array_reverse(array_values(array_filter((array)(pm_load('team_board', fn() => [])[$brand] ?? []), fn($r) => in_array($r['to'] ?? '', [$to, 'all'], true) && empty($r['struck']))));
    $first = fn($r) => preg_match('/^(ask|order):/', (string)($r['key'] ?? '')) ? 0 : 1;
    usort($rows, fn($a, $b) => $first($a) <=> $first($b) ?: strcmp((string)$b['at'], (string)$a['at']));
    return array_slice($rows, 0, $n);
}

/** One line for an agent's instructions: its own goal, then what the rest of the team told it lately. '' when nothing. */
function pm_tb_line(string $brand, string $to, int $n = 4): string
{
    $goal = function_exists('pm_heads_goal_line') ? pm_heads_goal_line($brand, $to) : '';
    $rows = pm_tb_for($brand, $to, $n);
    if (!$rows) {
        return $goal;
    }
    return trim($goal . ' Notes from the rest of the team (act on them where they fit; a head asking for help comes first): ' . implode(' | ', array_map(fn($r) => (PM_TB_DEPTS[$r['from']] ?? $r['from']) . ': ' . $r['text'], $rows)) . '.');
}

/** Is a note with this key up? */
function pm_tb_has(string $brand, string $key): bool
{
    foreach ((array)(pm_load('team_board', fn() => [])[$brand] ?? []) as $r) {
        if (($r['key'] ?? '') === $key && empty($r['struck']) && strtotime((string)$r['at']) > strtotime('-14 days')) {
            return true;
        }
    }
    return false;
}

/** Takes a note down (the ask was answered): the agents stop reading it. */
function pm_tb_take(string $brand, string $key): void
{
    try {
        pm_update('team_board', function (array $all) use ($brand, $key) {
            $all[$brand] = array_values(array_filter((array)($all[$brand] ?? []), fn($r) => ($r['key'] ?? '') !== $key));
            return $all;
        }, fn() => []);
    } catch (Throwable) {
    }
}

/** The owner strikes a note that is wrong: the agents stop reading it. */
function pm_tb_strike(string $brand, string $id): void
{
    pm_update('team_board', function (array $all) use ($brand, $id) {
        foreach ((array)($all[$brand] ?? []) as $i => $r) {
            if (($r['id'] ?? '') === $id) {
                $all[$brand][$i]['struck'] = date('Y-m-d H:i');
            }
        }
        return $all;
    }, fn() => []);
}

/** The board for the owner: [rows newest first]. */
function pm_tb_rows(string $brand, int $n = 30): array
{
    return array_slice(array_reverse(array_values((array)(pm_load('team_board', fn() => [])[$brand] ?? []))), 0, $n);
}
