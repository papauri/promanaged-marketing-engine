<?php
/**
 * Lead folders: the owner's own groups of leads ("Lake lodges", "Call this week", "Hot"), made and filled straight from the Leads list. A lead can be in several folders.
 * The list of folders lives in data/lead_folders.json {brand: [name, ...]}; each lead carries its own 'folders' => [name, ...]. No AI.
 */

/** A folder name as stored: plain words, 1 to 40 characters. '' when unusable. */
function pm_folder_name(string $s): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', strip_tags($s)));
    return mb_substr($s, 0, 40);
}

/** The business's folders, in the order they were made, with how many leads are in each: [name => count]. */
function pm_folders(string $brand, ?array $leads = null): array
{
    $names = (array)(pm_load('lead_folders', fn() => [])[$brand] ?? []);
    $out = array_fill_keys($names, 0);
    foreach ($leads ?? pm_leads() as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        foreach ((array)($l['folders'] ?? []) as $f) {
            $out[$f] = ($out[$f] ?? 0) + 1; // a folder a lead still names is shown even if the list lost it
        }
    }
    return $out;
}

function pm_folders_save_list(string $brand, callable $fn): void
{
    pm_update('lead_folders', function (array $all) use ($brand, $fn) {
        $all[$brand] = array_values(array_unique(array_filter(array_map('pm_folder_name', (array)$fn((array)($all[$brand] ?? []))))));
        return $all;
    }, fn() => []);
}

/** Puts leads in a folder (made if new). Returns how many were added. */
function pm_folder_add(array &$leads, array $ids, string $folder, string $brand): int
{
    $folder = pm_folder_name($folder);
    if ($folder === '') {
        return 0;
    }
    pm_folders_save_list($brand, fn(array $l) => array_merge($l, [$folder]));
    $n = 0;
    foreach ($ids as $id) {
        if (isset($leads[$id]) && ($leads[$id]['brand'] ?? 'promanaged') === $brand && !in_array($folder, (array)($leads[$id]['folders'] ?? []), true)) {
            $leads[$id]['folders'][] = $folder;
            $n++;
        }
    }
    return $n;
}

/** Takes leads out of a folder (the folder stays). Returns how many were taken out. */
function pm_folder_remove(array &$leads, array $ids, string $folder): int
{
    $n = 0;
    foreach ($ids as $id) {
        if (isset($leads[$id]) && in_array($folder, (array)($leads[$id]['folders'] ?? []), true)) {
            $leads[$id]['folders'] = array_values(array_diff((array)$leads[$id]['folders'], [$folder]));
            if (!$leads[$id]['folders']) {
                unset($leads[$id]['folders']);
            }
            $n++;
        }
    }
    return $n;
}

/** Renames a folder everywhere ($to '' deletes it: the leads stay, only the folder goes). */
function pm_folder_rename(array &$leads, string $brand, string $from, string $to): void
{
    $to = pm_folder_name($to);
    pm_folders_save_list($brand, fn(array $l) => array_map(fn($x) => $x === $from ? $to : $x, $l));
    foreach ($leads as $id => $l) {
        if (($l['brand'] ?? 'promanaged') === $brand && in_array($from, (array)($l['folders'] ?? []), true)) {
            $f = array_values(array_unique(array_filter(array_map(fn($x) => $x === $from ? $to : $x, (array)$l['folders']))));
            if ($f) {
                $leads[$id]['folders'] = $f;
            } else {
                unset($leads[$id]['folders']);
            }
        }
    }
}
