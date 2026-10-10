<?php
/**
 * The shared look of every screen: one top bar (business switcher, main navigation with drop-downs, status), one tab style for pages that have
 * sub-pages, one page header. Pages call these instead of printing their own navigation, so they cannot drift apart.
 *   pm_ui_topbar($ctx)      the sticky bar at the top
 *   pm_ui_tabs($items, $on)  the underline tabs under a page header (Social, Proposals)
 *   pm_ui_head($title, ...)  the page title, one line of help and the page's main actions
 */

/** The main navigation: key => label, link, the tabs it covers, and its drop-down (groups of links, or a flat list). */
function pm_ui_nav(): array
{
    return [
        'agents' => ['label' => 'Leads', 'href' => '?tab=agents', 'tabs' => ['agents']],
        'whatsapp' => ['label' => 'WhatsApp', 'href' => '?tab=whatsapp', 'tabs' => ['whatsapp']],
        'social' => ['label' => 'Social', 'href' => '?tab=social', 'tabs' => ['social'], 'groups' => [
            'Publish' => ['' => 'Plan', 'content' => 'Content', 'channels' => 'Channels'],
            'Grow' => ['growth' => 'Growth', 'inbox' => 'Inbox', 'page' => 'Page', 'cleanup' => 'Clean-up', 'ads' => 'Ads'],
            'Measure' => ['results' => 'Results', 'audit' => 'Audit'],
            'Set up' => ['accounts' => 'Accounts & branding'],
        ]],
        'proposals' => ['label' => 'Proposals', 'href' => '?tab=proposal', 'tabs' => ['proposal', 'history', 'template'], 'items' => [
            '?tab=proposal' => 'New proposal', '?tab=history' => 'History', '?tab=template' => 'Template and prices']],
        'settings' => ['label' => 'Settings', 'href' => '?tab=settings', 'tabs' => ['settings', 'business'], 'items' => [
            '?tab=settings' => 'Business details', '?tab=settings#health' => 'Setup health', '?tab=settings#backups' => 'Backups', '?tab=settings#team' => 'Team',
            '?tab=business&new=1' => '+ Add a business']],
    ];
}

/** Which main section a tab belongs to ('' when none). */
function pm_ui_section_of(string $tab): string
{
    foreach (pm_ui_nav() as $k => $n) {
        if (in_array($tab, $n['tabs'], true)) {
            return $k;
        }
    }
    return '';
}

/** Underline tabs: $items is key => label or key => [label, href]. $on is the active key. */
function pm_ui_tabs(array $items, string $on, string $label = 'Sections'): string
{
    $h = '<nav class="tabs" aria-label="' . pm_h($label) . '">';
    foreach ($items as $k => $v) {
        [$lab, $href] = is_array($v) ? $v : [$v, '#'];
        $h .= '<a href="' . pm_h($href) . '"' . ((string)$k === $on ? ' class="on" aria-current="page"' : '') . '>' . $lab . '</a>';
    }
    return $h . '</nav>';
}

/** The page header: a title, one plain line under it, and the page's main actions on the right ($actions is trusted HTML). */
function pm_ui_head(string $title, string $sub = '', string $actions = ''): string
{
    return '<div class="pagehead"><div><h1>' . pm_h($title) . '</h1>' . ($sub !== '' ? '<p class="sub">' . $sub . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="headacts">' . $actions . '</div>' : '') . '</div>';
}

/** A small colour dot for a business (its own accent colour). */
function pm_ui_dot(string $color): string
{
    $c = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#6b7280';
    return '<i class="dot" style="background:' . $c . '"></i>';
}

/** A business's accent colour from the settings array. */
function pm_ui_accent(array $settings, string $b): string
{
    $c = (string)(pm_brand_block($settings, $b)['accent_color'] ?? '');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : ($b === 'travel' ? '#047857' : '#17375E');
}

/** The business menu: who the screens are for, and the way to add another. */
function pm_ui_biz_menu(array $settings, string $vb, string $tab, string $view): string
{
    $keep = $tab === 'business' ? 'agents' : $tab;
    $logo = pm_brand_asset($vb, 'logo');
    $hasLogo = is_file(PM_ROOT . '/' . $logo);
    $h = '<div class="dd biz"><button type="button" class="ddbtn bizbtn" aria-haspopup="true" aria-expanded="false">'
        . ($hasLogo ? '<img class="' . ($vb === 'travel' ? 'sq' : '') . '" src="' . pm_h($logo) . '?v=' . (int)@filemtime(PM_ROOT . '/' . $logo) . '" alt="">' : pm_ui_dot(pm_ui_accent($settings, $vb)))
        . '<span class="bizname">' . pm_h($vb === 'promanaged' ? (string)$settings['company_name'] : pm_brand_title($settings, $vb)) . '</span><span class="caret"></span></button>'
        . '<div class="menu" role="menu"><div class="menuhd">Working on</div>';
    foreach (pm_brand_ids() as $bid) {
        $href = '?tab=' . $keep . ($view !== '' && $tab === 'social' ? '&view=' . $view : '') . '&brand=' . $bid;
        $h .= '<a role="menuitem" href="' . pm_h($href) . '" class="item' . ($bid === $vb ? ' on' : '') . '">' . pm_ui_dot(pm_ui_accent($settings, $bid)) . '<span>'
            . pm_h($bid === 'promanaged' ? (string)$settings['company_name'] : pm_brand_title($settings, $bid)) . '</span>' . ($bid === $vb ? '<b class="tick">✓</b>' : '') . '</a>';
    }
    return $h . '<hr><a role="menuitem" class="item" href="?tab=business&amp;new=1"><span class="plus">+</span><span>Add a business</span></a>'
        . '<a role="menuitem" class="item" href="?tab=settings"><span class="plus">⚙</span><span>Business settings</span></a></div></div>';
}

/** The sticky top bar. $c: tab, view, vb, settings, csrf, team (list), who, hbAge (seconds or null). */
function pm_ui_topbar(array $c): string
{
    $tab = (string)$c['tab'];
    $view = (string)($c['view'] ?? '');
    $sec = pm_ui_section_of($tab);
    $h = '<header class="topbar"><div class="topin">' . pm_ui_biz_menu($c['settings'], (string)$c['vb'], $tab, $view) . '<nav class="mainnav" aria-label="Main">';
    foreach (pm_ui_nav() as $k => $n) {
        $on = $sec === $k;
        $dd = '';
        foreach ((array)($n['groups'] ?? []) as $g => $items) {
            $dd .= '<div class="menuhd">' . pm_h($g) . '</div>';
            foreach ($items as $v => $lab) {
                $dd .= '<a role="menuitem" class="item" href="?tab=social' . ($v !== '' ? '&amp;view=' . pm_h($v) : '') . '">' . pm_h($lab) . '</a>';
            }
        }
        foreach ((array)($n['items'] ?? []) as $href => $lab) {
            $dd .= '<a role="menuitem" class="item" href="' . pm_h($href) . '">' . pm_h($lab) . '</a>';
        }
        if ($dd === '') {
            $h .= '<a class="navitem' . ($on ? ' on' : '') . '" href="' . pm_h($n['href']) . '"' . ($on ? ' aria-current="page"' : '') . '>' . pm_h($n['label']) . '</a>';
        } else {
            $h .= '<div class="dd navdd' . ($on ? ' on' : '') . '"><a class="navitem" href="' . pm_h($n['href']) . '"' . ($on ? ' aria-current="page"' : '') . '>' . pm_h($n['label']) . '</a>'
                . '<button type="button" class="ddbtn caretbtn" aria-haspopup="true" aria-expanded="false" aria-label="' . pm_h($n['label']) . ' pages"><span class="caret"></span></button><div class="menu" role="menu">' . $dd . '</div></div>';
        }
    }
    $h .= '</nav><div class="topright">';
    $age = $c['hbAge'];
    $min = $age === null ? 0 : intdiv((int)$age, 60);
    $txt = $age === null ? 'Scheduler has not run yet' : 'Scheduler ran ' . ($min >= 120 ? intdiv($min, 60) . ' h' : $min . ' min') . ' ago';
    $cls = $age === null || $age >= 2700 ? ($age !== null && $age >= 7200 ? 'bad' : 'warn') : 'ok';
    $h .= '<a class="status ' . $cls . '" href="?tab=settings#health" title="Posts and emails go out when the scheduler runs (every 15 to 30 minutes), or when the app is open."><i class="dot"></i><span>' . pm_h($txt) . '</span></a>';
    $team = (array)$c['team'];
    if ($team) {
        $h .= '<form method="post" class="who" data-quiet><input type="hidden" name="csrf" value="' . pm_h((string)$c['csrf']) . '"><input type="hidden" name="action" value="who"><input type="hidden" name="back" value="' . pm_h($tab) . '">'
            . '<select name="who" onchange="this.form.submit()" aria-label="Who is working"><option value="">Working as…</option>';
        foreach ($team as $m) {
            $h .= '<option' . (($c['who'] ?? '') === $m ? ' selected' : '') . '>' . pm_h($m) . '</option>';
        }
        $h .= '</select></form>';
    }
    return $h . '</div></div></header>';
}

/** One card per business: who it is, how ready it is, and the way to switch to it or change it. */
function pm_view_biz_grid(array $settings, string $vb, string $csrf): void
{
    $leads = pm_load('leads', fn() => []);
    echo '<div class="bizgrid">';
    foreach (pm_brand_ids() as $bid) {
        $logo = pm_brand_asset($bid, 'logo');
        $n = count(array_filter($leads, fn($l) => ($l['brand'] ?? 'promanaged') === $bid));
        $title = $bid === 'promanaged' ? (string)$settings['company_name'] : pm_brand_title($settings, $bid);
        $tag = trim((string)(pm_brand_block($settings, $bid)['tagline'] ?? ''));
        echo '<div class="bizcard' . ($bid === $vb ? ' on' : '') . '" style="--bz:' . pm_h(pm_ui_accent($settings, $bid)) . '"><div class="bzhead">'
            . (is_file(PM_ROOT . '/' . $logo) ? '<img class="' . ($bid === 'travel' ? 'sq' : '') . '" src="' . pm_h($logo) . '?v=' . (int)@filemtime(PM_ROOT . '/' . $logo) . '" alt="">' : pm_ui_dot(pm_ui_accent($settings, $bid)))
            . '<div><b>' . pm_h($title) . '</b><small>' . pm_h($tag !== '' ? $tag : ($bid === 'travel' ? 'Stays across Malawi' : ($bid === 'promanaged' ? 'Software, hardware and IT support' : 'Added by you'))) . '</small></div></div>';
        $rows = pm_brand_checklist($bid);
        echo '<div class="chips">';
        foreach ($rows as $r) {
            echo '<span class="chip ' . ($r['ok'] ? 'ok' : 'todo') . '" title="' . pm_h($r['ok'] ? 'Ready' : $r['hint']) . '">' . ($r['ok'] ? '✓ ' : '') . pm_h($r['label']) . '</span>';
        }
        echo '</div><div class="muted" style="font-size:13px">' . $n . ' lead' . ($n === 1 ? '' : 's') . '</div>'
            . '<div class="btns" style="margin-top:0"><a class="btn small' . ($bid === $vb ? '' : ' primary') . '" href="?tab=agents&amp;brand=' . pm_h($bid) . '">' . ($bid === $vb ? 'Open leads' : 'Switch to it') . '</a>'
            . '<a class="btn small" href="?tab=settings&amp;brand=' . pm_h($bid) . '">Settings</a></div></div>';
    }
    echo '<a class="bizcard add" href="?tab=business&amp;new=1"><span style="font-size:26px;line-height:1">+</span><b>Add a business</b><small>A few questions, then it is ready</small></a></div>';
}
