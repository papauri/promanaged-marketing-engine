<?php
/**
 * Social > Content: what the business posts about, kept in one place. The idea bank (the owner's own ideas beside the built-in starters), the hashtags, and the auto-publish switch live here;
 * the pillars, the proof bank, the suggested mix and "bring back what worked" are drawn on the same page by lib/view_social_content.php. Auto-loaded by lib/social_modules.php.
 * Handlers: POST action=social_ext&do=idea_add | idea_toggle | idea_delete | idea_builtin | tags_save | autopublish. Each returns [msg, kind, to].
 */

const PM_CONTENT_MAX_IDEAS = 60;
const PM_CONTENT_TO = 'social&view=content';

/** The owner's own ideas (not the built-in starters), newest last. */
function pm_content_ideas(string $brand): array
{
    return array_values(array_filter((array)(pm_bank_get($brand)['ideas'] ?? []), fn($i) => is_array($i) && trim((string)($i['text'] ?? '')) !== ''));
}

/** Adds one idea to the bank (the idea form and the idea room both come here). Returns [ok, message]. */
function pm_content_add_idea(string $vb, string $text, string $pillar = ''): array
{
    $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
    if (mb_strlen($text) < 8) {
        return [false, 'Write the idea in a sentence or two (at least 8 characters), so a post can be written from it.'];
    }
    $text = mb_substr($text, 0, 240);
    $names = array_map(fn($p) => (string)$p['name'], pm_social_pillars($vb));
    $pillar = in_array(trim($pillar), $names, true) ? trim($pillar) : '';
    $msg = '';
    pm_bank_set($vb, function (array $b) use ($text, $pillar, &$msg) {
        foreach ((array)$b['ideas'] as $x) {
            if (mb_strtolower(trim((string)($x['text'] ?? ''))) === mb_strtolower($text)) {
                $msg = 'That idea is already in the bank.';
                return $b;
            }
        }
        if (count((array)$b['ideas']) >= PM_CONTENT_MAX_IDEAS) {
            $msg = 'The bank holds ' . PM_CONTENT_MAX_IDEAS . ' ideas of your own. Delete one you will not use first.';
            return $b;
        }
        $b['ideas'][] = ['id' => 'o' . substr(sha1($text), 0, 9), 'text' => $text, 'pillar' => $pillar, 'active' => true];
        return $b;
    });
    return $msg !== '' ? [false, $msg] : [true, 'Added. The planner can write a post from it in the next plan.'];
}

function pm_do_idea_add(string $vb): array
{
    [$ok, $m] = pm_content_add_idea($vb, (string)($_POST['text'] ?? ''), (string)($_POST['pillar'] ?? ''));
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => PM_CONTENT_TO . '#ideas'];
}
function pm_do_idea_toggle(string $vb): array
{
    $id = preg_replace('/[^a-z0-9]/i', '', (string)($_POST['id'] ?? ''));
    $found = false;
    $on = true;
    pm_bank_set($vb, function (array $b) use ($id, &$found, &$on) {
        foreach ($b['ideas'] as $k => $x) {
            if (($x['id'] ?? '') === $id) {
                $b['ideas'][$k]['active'] = ($x['active'] ?? true) === false;
                $on = $b['ideas'][$k]['active'];
                $found = true;
            }
        }
        return $b;
    });
    return $found ? ['msg' => $on ? 'The idea is back in use.' : 'The idea is paused: the planner will not use it until you turn it back on.', 'kind' => 'ok', 'to' => PM_CONTENT_TO . '#ideas']
        : ['msg' => 'That idea is not in the bank any more.', 'kind' => 'err', 'to' => PM_CONTENT_TO . '#ideas'];
}

function pm_do_idea_delete(string $vb): array
{
    $id = preg_replace('/[^a-z0-9]/i', '', (string)($_POST['id'] ?? ''));
    $before = 0;
    $after = 0;
    pm_bank_set($vb, function (array $b) use ($id, &$before, &$after) {
        $before = count((array)$b['ideas']);
        $b['ideas'] = array_values(array_filter((array)$b['ideas'], fn($x) => ($x['id'] ?? '') !== $id));
        $after = count($b['ideas']);
        return $b;
    });
    return ['msg' => $after < $before ? 'Deleted.' : 'That idea is not in the bank any more.', 'kind' => $after < $before ? 'ok' : 'err', 'to' => PM_CONTENT_TO . '#ideas'];
}

/** Switches the built-in starter ideas on or off. */
function pm_do_idea_builtin(string $vb): array
{
    $on = !empty($_POST['on']);
    pm_bank_set($vb, function (array $b) use ($on) {
        $b['builtin_ideas'] = $on;
        return $b;
    });
    return ['msg' => $on ? 'The built-in starter ideas are in use again.' : 'Only your own ideas will be used.', 'kind' => 'ok', 'to' => PM_CONTENT_TO . '#ideas'];
}

/** Splits typed hashtags into clean tags (a # in front, no spaces, no repeats). */
function pm_content_tags(string $raw, int $max = 15): array
{
    $out = [];
    foreach (preg_split('/[\s,]+/u', $raw) ?: [] as $t) {
        $t = pm_sx_tag($t);
        if ($t !== '' && !in_array($t, $out, true)) {
            $out[] = $t;
        }
    }
    return array_slice($out, 0, $max);
}

function pm_do_tags_save(string $vb): array
{
    $local = pm_content_tags((string)($_POST['local'] ?? ''));
    $niche = pm_content_tags((string)($_POST['niche'] ?? ''));
    pm_bank_set($vb, function (array $b) use ($local, $niche) {
        $b['tags']['local'] = $local; // empty = back to the built-in set
        $b['tags']['niche'] = $niche;
        return $b;
    });
    return ['msg' => ($local || $niche) ? 'Hashtags saved. Posts use them from the next plan.' : 'Hashtags are back to the built-in set.', 'kind' => 'ok', 'to' => PM_CONTENT_TO . '#tags'];
}

/** Auto-publish on or off for the business on screen. Editors cannot switch it. */
function pm_do_autopublish(string $vb): array
{
    if (!pm_social_can_approve()) {
        return ['msg' => 'Only an approver can switch Auto-publish.', 'kind' => 'err', 'to' => 'social#rules'];
    }
    $on = !empty($_POST['on']);
    pm_update('settings', function (array $s) use ($vb, $on) {
        if ($vb === 'promanaged') {
            $s['social_auto'] = $on;
        } elseif ($vb === 'travel') {
            $s['travel']['social_auto'] = $on;
        } else {
            $s['brands'][$vb]['social_auto'] = $on;
        }
        return $s;
    }, 'pm_default_settings');
    return ['msg' => $on ? 'Auto-publish is on: planned posts go out at their time without approval.' : 'Auto-publish is off: every post waits for your approval.', 'kind' => 'ok', 'to' => 'social#rules'];
}
