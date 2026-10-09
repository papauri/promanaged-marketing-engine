<?php
/**
 * Malawi calendar for content hooks. Fixed-date holidays below are from memory (the source page could not be fetched):
 * anything uncertain carries 'verify' => true and is never given to the planner. Movable Islamic holidays are left out (not computed, not invented).
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

/** [['date' => 'Y-m-d', 'hook' => '...', 'verify' => bool], ...] for $from..$to inclusive, sorted by date. */
function pm_malawi_calendar(string $from, string $to): array
{
    $f = strtotime($from);
    $t = strtotime($to);
    if (!$f || !$t || $t < $f) {
        return [];
    }
    $out = [];
    $add = function (string $d, string $hook, bool $verify = false) use (&$out, $f, $t) {
        $ts = strtotime($d);
        if ($ts >= $f && $ts <= $t) {
            $out[] = ['date' => date('Y-m-d', $ts), 'hook' => $hook, 'verify' => $verify];
        }
    };
    for ($y = (int)date('Y', $f); $y <= (int)date('Y', $t); $y++) {
        $e = strtotime(pm_easter($y));
        $add("$y-01-01", "New Year's Day (public holiday)");
        $add("$y-01-15", 'John Chilembwe Day (public holiday)');
        $add("$y-03-03", "Martyrs' Day (public holiday)");
        $add(date('Y-m-d', $e - 2 * 86400), 'Good Friday (public holiday)');
        $add(date('Y-m-d', $e + 86400), 'Easter Monday (public holiday)');
        $add("$y-05-01", 'Labour Day (public holiday)');
        $add("$y-05-14", 'Kamuzu Day: check it is still a public holiday', true);
        $add("$y-07-01", 'New government financial year starts (1 July)');
        $add("$y-07-06", 'Independence Day (public holiday)');
        $add("$y-10-15", "Mother's Day (public holiday)");
        $add("$y-12-25", 'Christmas Day (public holiday)');
        $add("$y-12-26", 'Boxing Day (public holiday)');
        // payday: the last 3 working days of each month
        for ($m = 1; $m <= 12; $m++) {
            $d = strtotime(date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $m))));
            $n = 0;
            while ($n < 3) {
                if ((int)date('N', $d) < 6) {
                    $n++;
                    $add(date('Y-m-d', $d), 'Month-end payday: people have money');
                }
                $d -= 86400;
            }
        }
        // school terms (dates change every year: verify before use)
        $add("$y-01-05", 'Schools: second term usually starts around now', true);
        $add("$y-04-20", 'Schools: third term usually starts around now', true);
        $add("$y-09-01", 'Schools: first term usually starts around now', true);
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
