<?php
/**
 * Malawi calendar for content hooks.
 * Fixed public holidays are from memory (the source page could not be fetched), so anything uncertain is confirmed=false and never becomes a dated claim:
 * school terms and the dry-season window are 'approx' only. Movable Islamic holidays are not computed: the owner adds them under "Dates that matter" (Content tab).
 */

/** Easter Sunday (Y-m-d) by the anonymous Gregorian algorithm. */
function pm_easter(int $y): string
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mo = intdiv($h + $l - 7 * $m + 114, 31);
    $da = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $y, $mo, $da);
}

/**
 * Occasions between $from and $to (Y-m-d, inclusive), sorted by date. $brand '' = all rows; otherwise rows for that brand (or for all).
 * Row: key, date, end (last day it matters), lead_days (how early to post), brand all|promanaged|travel, audience host|guest|'', label, hook, confirmed.
 * confirmed=false rows are shown to the owner but generate no dated claim until confirmed.
 */
function pm_malawi_occasions(string $from, string $to, string $brand = ''): array
{
    $f = strtotime($from);
    $t = strtotime($to);
    if (!$f || !$t || $t < $f) {
        return [];
    }
    $out = [];
    $add = function (string $key, string $d, int $lead, string $br, string $aud, string $label, string $hook, bool $ok = true, string $end = '') use (&$out, $f, $t, $brand) {
        $ts = strtotime($d);
        if ($ts === false || $ts < $f || $ts > $t || ($brand !== '' && $br !== 'all' && $br !== $brand)) {
            return;
        }
        $day = date('Y-m-d', $ts);
        $out[] = ['key' => $key, 'date' => $day, 'end' => $end ?: $day, 'lead_days' => $lead, 'brand' => $br, 'audience' => $aud, 'label' => $label, 'hook' => $hook, 'confirmed' => $ok];
    };
    for ($y = (int)date('Y', $f) - 0; $y <= (int)date('Y', $t); $y++) {
        $e = strtotime(pm_easter($y));
        $add("newyear-$y", "$y-01-01", 5, 'all', '', "New Year's Day", "New Year's Day (public holiday)");
        $add("chilembwe-$y", "$y-01-15", 3, 'all', '', 'John Chilembwe Day', 'John Chilembwe Day (public holiday)');
        $add("valentines-$y", "$y-02-14", 7, 'all', '', "Valentine's Day", "Valentine's Day");
        $add("martyrs-$y", "$y-03-03", 3, 'all', '', "Martyrs' Day", "Martyrs' Day (public holiday)");
        $add("goodfriday-$y", date('Y-m-d', $e - 2 * 86400), 7, 'all', 'guest', 'Good Friday', 'Good Friday (public holiday): a long Easter weekend');
        $add("easter-$y", date('Y-m-d', $e), 5, 'all', 'guest', 'Easter', 'Easter weekend');
        $add("eastermonday-$y", date('Y-m-d', $e + 86400), 3, 'all', '', 'Easter Monday', 'Easter Monday (public holiday)');
        $add("labour-$y", "$y-05-01", 3, 'all', '', 'Labour Day', 'Labour Day (public holiday)');
        $add("kamuzu-$y", "$y-05-14", 3, 'all', '', 'Kamuzu Day', 'Kamuzu Day: check it is still a public holiday', false);
        $add("finyear-$y", "$y-07-01", 7, 'promanaged', '', 'New financial year', 'New government financial year starts (1 July)');
        $add("independence-$y", "$y-07-06", 7, 'all', '', 'Independence Day', 'Independence Day (public holiday)');
        $add("mothers-$y", "$y-10-15", 7, 'all', '', "Mother's Day", "Mother's Day (public holiday)");
        $add("christmas-$y", "$y-12-25", 10, 'all', 'guest', 'Christmas', 'Christmas Day (public holiday)');
        $add("boxing-$y", "$y-12-26", 3, 'all', '', 'Boxing Day', 'Boxing Day (public holiday)');
        // payday: the last 3 working days of each month
        for ($m = 1; $m <= 12; $m++) {
            $d = strtotime(date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $m))));
            $days = [];
            while (count($days) < 3) {
                if ((int)date('N', $d) < 6) {
                    $days[] = date('Y-m-d', $d);
                }
                $d -= 86400;
            }
            $add(sprintf('payday-%04d-%02d', $y, $m), end($days), 2, 'all', '', 'Month-end', 'Month-end: many people are paid around now', true, $days[0]);
        }
        // approximate only: these dates change every year, so they never become a dated claim
        $add("school-t2-$y", "$y-01-05", 14, 'all', '', 'School term change (approx)', 'Schools: a term usually starts around now', false);
        $add("school-t3-$y", "$y-04-20", 14, 'all', '', 'School term change (approx)', 'Schools: a term usually starts around now', false);
        $add("school-t1-$y", "$y-09-01", 14, 'all', '', 'School term change (approx)', 'Schools: a term usually starts around now', false);
        $add("dry-$y", "$y-05-01", 14, 'travel', 'guest', 'Dry season starts (approx)', 'The dry season usually begins around now', false);
        $add("rainy-$y", "$y-11-01", 14, 'travel', 'guest', 'Rainy season starts (approx)', 'The rainy season usually begins around now', false);
    }
    // the owner's own dates ("Dates that matter"): confirmed unless the owner left them unticked
    if (function_exists('pm_bank_get')) {
        foreach ($brand === '' ? ['promanaged', 'travel'] : [$brand] as $b) {
            foreach ((array)(pm_bank_get($b)['dates'] ?? []) as $r) {
                $d = (string)($r['date'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || trim((string)($r['label'] ?? '')) === '') {
                    continue;
                }
                $aud = in_array($r['audience'] ?? '', ['host', 'guest'], true) ? $r['audience'] : '';
                $add('own-' . ($r['id'] ?? substr(sha1($d . $r['label']), 0, 8)), $d, max(0, min(30, (int)($r['lead_days'] ?? 7))), $b, $aud, (string)$r['label'], (string)$r['label'], !empty($r['confirmed']));
            }
        }
    }
    usort($out, fn($a, $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
    return $out;
}

/** Legacy shape [['date','hook','verify'], ...] for $from..$to, built from the occasions plus one season line. */
function pm_malawi_calendar(string $from, string $to): array
{
    $f = strtotime($from);
    if (!$f || !strtotime($to)) {
        return [];
    }
    $out = [];
    foreach (pm_malawi_occasions($from, $to, pm_brand()) as $o) {
        $out[] = ['date' => $o['date'], 'hook' => $o['hook'], 'verify' => !$o['confirmed']];
    }
    // seasons: stated once, on the first day of the window
    $mo = (int)date('n', $f);
    $out[] = ['date' => date('Y-m-d', $f), 'verify' => false,
        'hook' => ($mo >= 11 || $mo <= 4) ? 'Rainy season (Nov to Apr): roads, power cuts and humidity affect daily life; low tourist season' : 'Dry season (May to Oct): peak tourist season, good weather for lake, safari and hiking trips'];
    usort($out, fn($a, $b) => [$a['date'], $a['hook']] <=> [$b['date'], $b['hook']]);
    return $out;
}

/** Hooks for the planner: certain ones only, as "date: hook". */
function pm_social_hooks(string $start, int $days = 21): array
{
    $to = date('Y-m-d', strtotime($start . " +$days days"));
    return array_values(array_map(fn($h) => $h['date'] . ': ' . $h['hook'], array_filter(pm_malawi_calendar($start, $to), fn($h) => empty($h['verify']))));
}
