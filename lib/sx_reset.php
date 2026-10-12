<?php
/**
 * "Reset this page to defaults", on every page that holds settings, with one Undo.
 * A reset never touches data (leads, posts, offers, ideas, history) or identity and logins (business name, contact details, logos, proposal numbers, mail passwords, keys,
 * Facebook connection, profile links, the team). It removes only the page's own settings, so every reader falls back to its built-in default; a few values that have no
 * fallback are written back as the default instead. What was there is kept in data/page_resets.json, so "Undo the reset" puts it back exactly.
 * Pages without settings (Leads, WhatsApp, New proposal, History, Lead posts, Page posts, Playbook, Ads, Page clean-up, Audit, Enter numbers, Health) show no bar:
 * everything on them is work, not settings. Studio keeps its own "Back to the classic cover" button.
 *   pm_rs_pages()                  the registry: key => page links, what comes back, what is kept, global or per business, the changes
 *   pm_rs_run($key, $brand, $undo) do it (or undo it), returns [message, kind, redirect target]
 *   pm_rs_bar($tab, $vb, $csrf)    the bar at the bottom of the page shown
 */

/** Where a business's own block sits in settings.json. */
function pm_rs_base(string $b): array
{
    return $b === 'promanaged' ? [] : ($b === 'travel' ? ['travel'] : ['brands', $b]);
}

/** Where a business's own block sits in agents_config.json (ProManaged at the top, the others nested). */
function pm_rs_abase(string $b): array
{
    return $b === 'promanaged' ? [] : [$b];
}

/**
 * The pages that can be reset. 'ops' gives, for one business, a list of [store, path, value] changes: value null removes the key (the default then applies),
 * anything else is written. 'global' pages are the same for every business.
 */
function pm_rs_pages(): array
{
    $soc = fn(string $b, array $keys) => array_map(fn($k) => ['settings', array_merge(pm_rs_base($b), ['social', $k]), null], $keys);
    return [
        'targeting' => ['href' => ['?tab=targeting'], 'name' => 'Targeting and messages',
            'what' => 'who to look for, the daily limits, the replies and the agents switched on, the sending days and hours, and the default first message and signatures',
            'keep' => 'your existing clients list, leads and drafts',
            'ops' => fn(string $b) => array_merge(
                array_map(fn($k) => ['agents_config', array_merge(pm_rs_abase($b), [$k]), null], ['offerings', 'sectors', 'cities', 'markets', 'new_per_day', 'scouts_per_day', 'send_cap', 'wa_cap', 'followup_days', 'max_followups', 'enabled']),
                [['agents_config', ['reply_mode'], null], ['agents_config', ['wa_reply_mode'], null], ['send_schedule', [$b], null], ['message_defaults', [$b], null]],
                $b === 'travel' ? [['settings', ['travel', 'host_email'], null]] : [])],
        'template' => ['href' => ['?tab=template'], 'name' => 'Template and prices', 'global' => true,
            'what' => 'every package, price, extra and line of proposal wording, for every business',
            'keep' => 'proposals already made and their history',
            'ops' => fn(string $b) => [['template', [], pm_default_template()]]],
        'plan' => ['href' => ['?tab=social'], 'name' => 'Publishing rules',
            'what' => 'auto-publish (off), editor review (on), the channels posted to automatically and the posting times (automatic)',
            'keep' => 'every planned and published post',
            'ops' => fn(string $b) => array_merge($soc($b, ['editor_review', 'channels', 'times']), [['settings', array_merge(pm_rs_base($b), ['social_auto']), null]])],
        'content' => ['href' => ['?tab=social&view=content'], 'name' => 'Content',
            'what' => 'the content pillars and their mix, the built-in ideas switch and the hashtag sets',
            'keep' => 'your ideas, proof bank and photo library',
            'ops' => fn(string $b) => array_merge($soc($b, ['pillars']), [['social_bank', [$b, 'builtin_ideas'], null], ['social_bank', [$b, 'tags'], null]])],
        'channels' => ['href' => ['?tab=social&view=channels'], 'name' => 'Channels',
            'what' => 'which channels are switched off and the founder voice',
            'keep' => 'groups, catalogue, review link and what you ticked as done',
            'ops' => function (string $b) {
                $ticks = (array)(pm_load('social_channels', fn() => [])[$b]['ticks'] ?? []);
                return array_merge([['social_channels', [$b, 'founder_voice'], null]],
                    array_map(fn($k) => ['social_channels', [$b, 'ticks', $k], null], array_values(array_filter(array_keys($ticks), fn($k) => str_starts_with((string)$k, 'off_')))));
            }],
        'inbox' => ['href' => ['?tab=social&view=inbox'], 'name' => 'Community rules',
            'what' => 'the autopilot level (safe) and the reply style',
            'keep' => 'comments, messages and replies',
            'ops' => fn(string $b) => $soc($b, ['autopilot', 'reply_style'])],
        'kit' => ['href' => ['?tab=social&view=kit'], 'name' => 'Brand kit',
            'what' => 'the cover headline and line, and the brand team working on its own',
            'keep' => 'your logo, hero photo and the brand team\'s proposals',
            'ops' => fn(string $b) => array_merge($soc($b, ['cover_head', 'cover_line']), [['brand_team', [$b, 'auto'], null]]),
            'after' => function (string $b) {
                if (function_exists('pm_kit_build')) {
                    try {
                        pm_kit_build($b);
                    } catch (Throwable) {
                    }
                }
            }],
        'profiles' => ['href' => ['?tab=social&view=optimise'], 'name' => 'Profiles',
            'what' => 'the channels you said you use and the AI-polished wording (back to plain wording)',
            'keep' => 'opening hours, contact details and what was already pushed to your profiles',
            'ops' => fn(string $b) => array_merge(array_map(fn($k) => ['social_channels', [$b, 'ticks', 'use_' . $k], null], ['x', 'linkedin', 'tiktok', 'youtube']), [['page_opt', [$b, 'ai'], null]])],
        'voice' => ['href' => ['?tab=social&view=voice'], 'name' => 'Voice',
            'what' => 'what the AI is told about the business: about, facts, audience, voice and words to avoid' . ' (an added business goes back to blank facts)',
            'keep' => 'everything else',
            'ops' => fn(string $b) => [['settings', array_merge(pm_rs_base($b), ['brain']), null]]],
        'results' => ['href' => ['?tab=social&view=results'], 'name' => 'Goals and limits',
            'what' => 'the follower, enquiry, lead and post goals, the cost limit and this business\'s daily AI budget',
            'keep' => 'all results and numbers entered',
            'ops' => fn(string $b) => [['social_goals', [$b], null]]],
        'business' => ['href' => ['?tab=settings&section=business', '?tab=settings'], 'name' => 'Business details',
            'what' => 'the colour, the website link in messages on or off, online signing, e-signature tags' . ' and charging for onboarding',
            'keep' => 'the name, contact details, logo, signature, signatory and proposal numbers',
            'ops' => fn(string $b) => array_map(fn($k) => ['settings', array_merge(pm_rs_base($b), [$k]), null],
                array_merge(['accent_color', 'link_on', 'online_signing', 'esign_tags'], $b === 'travel' ? ['charging'] : []))],
        'email' => ['href' => ['?tab=settings&section=email'], 'name' => 'Email',
            'what' => 'the sender name (the business name) and the copy of each email to your own mailbox (on)',
            'keep' => 'the mail server, login and password',
            'ops' => fn(string $b) => [['settings', array_merge(pm_rs_base($b), ['smtp', 'from_name']), pm_brand_name($b)], ['settings', array_merge(pm_rs_base($b), ['smtp', 'bcc_self']), true]]],
        'connections' => ['href' => ['?tab=settings&section=connections'], 'name' => 'AI settings', 'global' => true,
            'what' => 'the AI quality, model, daily token budget and parallel work, for every business',
            'keep' => 'the AI keys, the Facebook connection and the profile links',
            'ops' => fn(string $b) => array_map(fn($k) => ['agents_config', [$k], null], ['quality', 'gemini_model', 'daily_token_budget', 'parallel'])],
        'team' => ['href' => ['?tab=settings&section=team'], 'name' => 'Currencies', 'global' => true,
            'what' => 'the list of currencies, their rates (check them after a reset) and the default quote currency, for every business',
            'keep' => 'the team and the base currency',
            'ops' => fn(string $b) => [['settings', ['currencies'], null], ['settings', ['default_quote_currency'], null], ['settings', ['rates_date'], null]]],
    ];
}

/** [exists, value] at a path ([] is the whole store). */
function pm_rs_get(array $a, array $path): array
{
    foreach ($path as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return [false, null];
        }
        $a = $a[$k];
    }
    return [true, $a];
}

/** The store with the value at $path written ($unset: removed). */
function pm_rs_put(array $a, array $path, $v, bool $unset = false): array
{
    if (!$path) {
        return $unset ? [] : (array)$v;
    }
    $k = array_shift($path);
    if (!$path) {
        if ($unset) {
            unset($a[$k]);
        } else {
            $a[$k] = $v;
        }
        return $a;
    }
    if (!isset($a[$k]) || !is_array($a[$k])) {
        if ($unset) {
            return $a;
        }
        $a[$k] = [];
    }
    $a[$k] = pm_rs_put($a[$k], $path, $v, $unset);
    return $a;
}

/** The page key for the screen shown ('' when the page holds no settings). */
function pm_rs_key_for(string $tab, string $view, string $section): string
{
    foreach (pm_rs_pages() as $k => $pg) {
        foreach ($pg['href'] as $href) {
            if (pm_ui_href_on($href, $tab, $view, $section)) {
                return $k;
            }
        }
    }
    return '';
}

/** Reset a page (or undo the last reset of it). Returns [message, kind, redirect target]. */
function pm_rs_run(string $key, string $brand, bool $undo = false): array
{
    $pg = pm_rs_pages()[$key] ?? null;
    if (!$pg) {
        return ['That page cannot be reset.', 'err', 'home'];
    }
    $to = substr($pg['href'][0], 5); // "?tab=x&view=y" -> "x&view=y"
    $slot = !empty($pg['global']) ? '_all' : $brand;
    $defs = ['settings' => 'pm_default_settings', 'template' => 'pm_default_template'];
    if ($undo) {
        $rec = (array)(pm_load('page_resets', fn() => [])[$slot][$key] ?? []);
        if (empty($rec['ops'])) {
            return ['There is no reset to undo.', 'err', $to];
        }
        $by = [];
        foreach ($rec['ops'] as $o) {
            $by[$o[0]][] = $o;
        }
        foreach ($by as $store => $ops) {
            pm_update($store, function (array $d) use ($ops) {
                foreach (array_reverse($ops) as [, $path, $had, $old]) {
                    $d = $had ? pm_rs_put($d, $path, $old) : pm_rs_put($d, $path, null, true);
                }
                return $d;
            }, $defs[$store] ?? null);
        }
        pm_update('page_resets', function (array $all) use ($slot, $key) {
            unset($all[$slot][$key]);
            return $all;
        });
        isset($pg['after']) && ($pg['after'])($brand);
        return [$pg['name'] . ': the settings from before the reset are back.', 'ok', $to];
    }
    $by = [];
    foreach (($pg['ops'])($brand) as $o) {
        $by[$o[0]][] = $o;
    }
    $done = [];
    foreach ($by as $store => $ops) {
        pm_update($store, function (array $d) use ($ops, &$done) {
            foreach ($ops as [$st, $path, $v]) {
                [$had, $old] = pm_rs_get($d, $path);
                $done[] = [$st, $path, $had, $old];
                $d = pm_rs_put($d, $path, $v, $v === null);
            }
            return $d;
        }, $defs[$store] ?? null);
    }
    pm_update('page_resets', function (array $all) use ($slot, $key, $done) {
        $all[$slot][$key] = ['at' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? ''), 'ops' => $done];
        return $all;
    });
    isset($pg['after']) && ($pg['after'])($brand);
    return [$pg['name'] . ' reset to the defaults' . (!empty($pg['global']) ? ' (for every business)' : ' for ' . pm_brand_name($brand)) . '. Undo is at the bottom of the page.', 'ok', $to];
}

/** The bar at the bottom of a page that holds settings: what a reset puts back, what it keeps, the button, and Undo after a reset. */
function pm_rs_bar(string $tab, string $vb, string $csrf): string
{
    $key = pm_rs_key_for($tab, preg_replace('/[^a-z]/', '', (string)($_GET['view'] ?? '')), preg_replace('/[^a-z]/', '', (string)($_GET['section'] ?? '')));
    if ($key === '') {
        return '';
    }
    $pg = pm_rs_pages()[$key];
    $global = !empty($pg['global']);
    $rec = (array)(pm_load('page_resets', fn() => [])[$global ? '_all' : $vb][$key] ?? []);
    $hid = '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="page_reset"><input type="hidden" name="key" value="' . pm_h($key) . '">';
    $who = $global ? 'every business' : pm_brand_name($vb);
    $h = '<div class="card flat resetbar"><div class="cluster"><div class="grow"><b>Defaults</b> <span class="hint">A reset puts back ' . pm_h($pg['what']) . '. Kept: ' . pm_h($pg['keep']) . '.</span></div>';
    if (!empty($rec['ops'])) {
        $h .= '<form method="post">' . $hid . '<input type="hidden" name="undo" value="1"><button class="btn small" title="Reset ' . pm_h($rec['at'] . ($rec['by'] !== '' ? ' by ' . $rec['by'] : '')) . '">Undo the reset of ' . pm_h(substr((string)$rec['at'], 5)) . '</button></form>';
    }
    return $h . '<form method="post">' . $hid . '<button class="btn small danger" data-confirm="' . pm_h('Reset ' . $pg['name'] . ' to the defaults for ' . $who . '? This puts back ' . $pg['what'] . '. You can undo it.') . '">Reset this page to defaults</button></form></div></div>';
}
