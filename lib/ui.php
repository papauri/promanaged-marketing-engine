<?php
/**
 * The shared look of every screen. The firm is organised as departments, and every screen belongs to one:
 *   Overview (the agency desk), Sales, Social, Brand, Insights, Operations.
 * One registry (pm_ui_nav) says which pages each department owns; the top bar, the department label in the page header, the tab strip inside a department and the colour of the screen all come
 * from it, so they cannot drift apart.
 *   pm_ui_topbar($ctx)        the sticky bar at the top: business switcher, one entry per department, status
 *   pm_ui_dept_of($tab,$view) which department a screen belongs to
 *   pm_ui_dept_tabs($dept)    the pages of a department that live inside the Social screen, as tab-strip items
 *   pm_ui_tabs($items, $on)   the underline tabs under a page header (a department's pages, Proposals)
 *   pm_ui_head($title, ...)   the department label, the page title, one line of help and the page's main actions
 */

/** Small line icons (24x24, stroke), one per department. */
const PM_UI_ICONS = [
    'hq' => '<rect x="3" y="3" width="7" height="9" rx="1.6"/><rect x="14" y="3" width="7" height="5" rx="1.6"/><rect x="14" y="12" width="7" height="9" rx="1.6"/><rect x="3" y="16" width="7" height="5" rx="1.6"/>',
    'sales' => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    'social' => '<path d="M4 5.5h16v11H10l-4.5 3.5v-3.5H4z"/><path d="M8 10h8M8 13h5"/>',
    'brand' => '<path d="M12 3a9 9 0 100 18c1.5 0 2.1-1.1 1.6-2.2-.6-1.3.2-2.6 1.7-2.6H18a3 3 0 003-3c0-5-4-10.2-9-10.2z"/><circle cx="7.6" cy="11.2" r="1"/><circle cx="10.6" cy="7.2" r="1"/><circle cx="15.2" cy="7.6" r="1"/>',
    'insights' => '<path d="M4 20V11M10 20V4M16 20v-7M22 20H2"/>',
    'ops' => '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
    'audit' => '<path d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/>',
];

function pm_ui_icon(string $k, int $size = 18): string
{
    return '<svg class="ico" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . (PM_UI_ICONS[$k] ?? PM_UI_ICONS['hq']) . '</svg>';
}

/**
 * The departments. Every page of the app is listed here exactly once, under the department whose job it is. key => label (top bar), name (page label), job (what the department is for),
 * colour, home link, and its pages as groups: group => [href => label]. A page's href says where it is: ?tab=..., &view=... (the Social screen's pages) or &section=... (Operations).
 */
function pm_ui_nav(): array
{
    return [
        'hq' => ['label' => 'Overview', 'name' => 'Agency overview', 'job' => 'See the whole firm', 'color' => '#2b2f36', 'href' => '?tab=home', 'groups' => []],
        'sales' => ['label' => 'Sales', 'name' => 'Sales department', 'job' => 'Win customers', 'color' => '#2f6b4f', 'href' => '?tab=agents', 'groups' => [
            'Pipeline' => ['?tab=agents' => 'Leads', '?tab=mailbox' => 'Mailbox', '?tab=whatsapp' => 'WhatsApp', '?tab=targeting' => 'Targeting and messages'],
            'Proposals' => ['?tab=proposal' => 'New proposal', '?tab=history' => 'History', '?tab=template' => 'Template and prices']]],
        'social' => ['label' => 'Social', 'name' => 'Social department', 'job' => 'Publish and answer the audience', 'color' => '#b4532a', 'href' => '?tab=social', 'groups' => [
            'Publish' => ['?tab=social' => 'Plan', '?tab=social&view=shop' => 'Shop', '?tab=social&view=leadposts' => 'Lead posts', '?tab=social&view=campaign' => 'Campaigns', '?tab=social&view=content' => 'Content', '?tab=social&view=channels' => 'Channels'],
            'Community' => ['?tab=social&view=scout' => 'Scouting', '?tab=social&view=leads' => 'Social leads', '?tab=social&view=inbox' => 'Inbox', '?tab=social&view=page' => 'Page posts', '?tab=social&view=growth' => 'Playbook'],
            'Paid' => ['?tab=social&view=ads' => 'Ads', '?tab=social&view=gads' => 'Google Ads']]],
        'brand' => ['label' => 'Brand', 'name' => 'Brand and creative department', 'job' => 'Look and sound right everywhere', 'color' => '#8a6a2f', 'href' => '?tab=social&view=studio', 'groups' => [
            'Look' => ['?tab=social&view=studio' => 'Studio', '?tab=social&view=kit' => 'Brand kit'],
            'Words and profiles' => ['?tab=social&view=optimise' => 'Profiles', '?tab=social&view=voice' => 'Voice', '?tab=social&view=relearn' => 'Relearn the business'],
            'Upkeep' => ['?tab=social&view=cleanup' => 'Page clean-up']]],
        'insights' => ['label' => 'Insights', 'name' => 'Insights department', 'job' => 'Measure what works and advise', 'color' => '#46607a', 'href' => '?tab=social&view=results', 'groups' => [
            'Reports' => ['?tab=social&view=results' => 'Results', '?tab=social&view=audit' => 'Page audit', '?tab=social&view=numbers' => 'Enter numbers']]],
        'ops' => ['label' => 'Operations', 'name' => 'Operations', 'job' => 'Keep the machinery running', 'color' => '#5e5a52', 'href' => '?tab=settings', 'groups' => [
            'Run the firm' => ['?tab=settings&section=business' => 'Business details', '?tab=settings&section=email' => 'Email', '?tab=settings&section=connections' => 'Connections',
                '?tab=settings&section=health' => 'Health and backups', '?tab=settings&section=team' => 'Team and currencies'],
            'Businesses' => ['?tab=business&new=1' => '+ Add a business']]],
    ] + (pm_ui_admin() ? [ // oversight of the team: only an admin sees this department (lib/view_audit.php)
        'audit' => ['label' => 'Audit', 'name' => 'Audit department', 'job' => 'Oversee the team and the work', 'color' => '#6b3a5c', 'href' => '?tab=audit', 'groups' => [
            'Oversight' => ['?tab=audit' => 'Activity log', '?tab=audit&section=followups' => 'Follow-ups', '?tab=audit&section=ai' => 'AI auditor', '?tab=audit&section=talk' => 'Agent conversations']]],
    ] : []);
}

/** Is the person using the screens an admin? (No sign-in loaded, as in the scheduler: yes.) */
function pm_ui_admin(): bool
{
    return !function_exists('pm_user_is_admin') || pm_user_is_admin();
}

/** The section shown when a Settings link names none. */
const PM_UI_DEFAULT_SECTION = 'business';

/** Pages that moved: the old place => the new one (links and redirects written before the move still arrive). Both are "tab&view=..." strings without a #anchor. */
const PM_UI_MOVED = ['social&view=accounts' => 'settings&section=connections'];

/** A redirect target ("social&view=accounts#x") with a moved page's new place. */
function pm_ui_moved(string $target): string
{
    foreach (PM_UI_MOVED as $old => $new) {
        if (str_starts_with($target, $old) && (strlen($target) === strlen($old) || in_array($target[strlen($old)], ['#', '&'], true))) {
            return $new . substr($target, strlen($old));
        }
    }
    return $target;
}

/** Does this page link ("?tab=social&view=studio") point at the screen shown? A Settings link with no section means the default one. */
function pm_ui_href_on(string $href, string $tab, string $view = '', string $section = ''): bool
{
    if (str_contains($href, '#')) { // a link into a part of a page: the page cannot know which part is open
        return false;
    }
    parse_str((string)parse_url($href, PHP_URL_QUERY), $q);
    if (($q['tab'] ?? '') !== $tab) {
        return false;
    }
    if ($tab === 'social') {
        return ($q['view'] ?? '') === $view;
    }
    if ($tab === 'settings') {
        return ($q['section'] ?? PM_UI_DEFAULT_SECTION) === ($section !== '' ? $section : PM_UI_DEFAULT_SECTION);
    }
    if ($tab === 'audit') {
        return ($q['section'] ?? '') === $section;
    }
    return true;
}

/** Which department a screen belongs to ('' when none). */
function pm_ui_dept_of(string $tab, string $view = '', string $section = ''): string
{
    foreach (pm_ui_nav() as $k => $d) {
        foreach ($d['groups'] as $items) {
            foreach (array_keys($items) as $href) {
                if (pm_ui_href_on($href, $tab, $view, $section)) {
                    return $k;
                }
            }
        }
    }
    return ['home' => 'hq', 'social' => 'social', 'settings' => 'ops', 'business' => 'ops', 'agents' => 'sales', 'mailbox' => 'sales', 'whatsapp' => 'sales', 'targeting' => 'sales', 'proposal' => 'sales', 'history' => 'sales', 'template' => 'sales', 'audit' => 'audit', 'guide' => 'hq'][$tab] ?? '';
}

/** A department's pages in order, as href => label (the "+ Add" links are buttons, not pages). */
function pm_ui_dept_pages(string $dept): array
{
    $out = [];
    foreach ((array)(pm_ui_nav()[$dept]['groups'] ?? []) as $items) {
        foreach ($items as $href => $label) {
            if (!str_starts_with($label, '+')) {
                $out[$href] = $label;
            }
        }
    }
    return $out;
}

/** The tab strip of the department the screen belongs to: its own pages only, the current one marked. Empty when the department has a single page. */
function pm_ui_strip(string $tab, string $view = '', string $section = ''): string
{
    $dept = pm_ui_dept_of($tab, $view, $section);
    $items = [];
    $on = '';
    foreach (pm_ui_dept_pages($dept) as $href => $label) {
        $items[$href] = [pm_h($label), $href];
        if ($on === '' && pm_ui_href_on($href, $tab, $view, $section)) {
            $on = $href;
        }
    }
    return count($items) > 1 ? pm_ui_tabs($items, $on, pm_ui_nav()[$dept]['label']) : '';
}

/** The pages that are views of the Social screen, in order: view => label (all departments that live there). */
function pm_ui_social_views(): array
{
    $out = [];
    foreach (pm_ui_nav() as $d) {
        foreach ($d['groups'] as $items) {
            foreach ($items as $href => $label) {
                if (str_starts_with($href, '?tab=social')) {
                    $out[preg_match('/view=([a-z]+)/', $href, $m) ? $m[1] : ''] = $label;
                }
            }
        }
    }
    return $out;
}

/** The inline style that gives a screen its department colour. */
function pm_ui_dept_style(string $dept): string
{
    return isset(pm_ui_nav()[$dept]) ? '--dept:' . pm_ui_nav()[$dept]['color'] : '';
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

/**
 * One page of a long list, cut on the server: [the rows of this page, the pager HTML ('' when everything fits on one page)].
 * The page comes from ?$param= (1 = first), or from $page when it is above 0 (e.g. the page that holds a linked row). The pager's links keep every
 * other GET parameter of the current address, except 'at' (a one-off "open the page of this row").
 */
function pm_ui_page(array $rows, int $per = 10, string $param = 'p', int $page = 0): array
{
    $rows = array_values($rows);
    $n = count($rows);
    $pages = max(1, (int)ceil($n / max(1, $per)));
    $page = max(1, min($pages, $page > 0 ? $page : (int)($_GET[$param] ?? 1)));
    $slice = array_slice($rows, ($page - 1) * $per, $per);
    if ($pages === 1) {
        return [$slice, ''];
    }
    $q = $_GET;
    unset($q['at']);
    $href = function (int $p) use ($q, $param) {
        $q[$param] = $p;
        return '?' . pm_h(http_build_query($q));
    };
    $from = ($page - 1) * $per + 1;
    $h = '<nav class="pager" aria-label="Pages">'
        . ($page > 1 ? '<a class="btn small" href="' . $href($page - 1) . '">‹ Previous</a>' : '<span class="btn small" aria-disabled="true">‹ Previous</span>')
        . '<span class="muted t-sm">' . $from . '–' . min($n, $from + $per - 1) . ' of ' . $n . '</span>'
        . ($page < $pages ? '<a class="btn small" href="' . $href($page + 1) . '">Next ›</a>' : '<span class="btn small" aria-disabled="true">Next ›</span>')
        . '</nav>';
    return [$slice, $h];
}

/** The page header: the department it belongs to, a title, one plain line under it, and the page's main actions on the right ($actions is trusted HTML). */
function pm_ui_head(string $title, string $sub = '', string $actions = ''): string
{
    $k = (string)($GLOBALS['PM_DEPT'] ?? '');
    $d = pm_ui_nav()[$k] ?? null;
    $eye = $d && $k !== 'hq' ? '<div class="eyebrows"><a class="eyebrow" href="' . pm_h($d['href']) . '">' . pm_ui_icon($k, 14) . pm_h($d['name']) . '</a><span class="job">' . pm_h($d['job']) . '</span></div>' : '';
    return '<div class="pagehead"><div>' . $eye . '<h1>' . pm_h($title) . '</h1>' . ($sub !== '' ? '<p class="sub">' . $sub . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="headacts">' . $actions . '</div>' : '') . '</div>';
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
    $keep = $tab === 'business' ? 'home' : $tab;
    $logo = pm_brand_asset($vb, 'logo');
    $hasLogo = is_file(PM_ROOT . '/' . $logo);
    $h = '<div class="dd biz"><button type="button" class="ddbtn bizbtn" aria-haspopup="true" aria-expanded="false">'
        . ($hasLogo ? '<img class="' . ($vb === 'travel' ? 'sq' : '') . '" src="' . pm_h($logo) . '?v=' . (int)@filemtime(PM_ROOT . '/' . $logo) . '" alt="">' : pm_ui_dot(pm_ui_accent($settings, $vb)))
        . '<span class="bizname">' . pm_h(pm_brand_name($vb)) . '</span><span class="caret"></span></button>'
        . '<div class="menu" role="menu"><div class="menuhd">Working on</div>';
    foreach (pm_user_brands() as $bid) { // only the businesses this person may open
        $href = '?tab=' . $keep . ($view !== '' && $tab === 'social' ? '&view=' . $view : '') . '&brand=' . $bid;
        $h .= '<a role="menuitem" href="' . pm_h($href) . '" class="item' . ($bid === $vb ? ' on' : '') . '">' . pm_ui_dot(pm_ui_accent($settings, $bid)) . '<span>'
            . pm_h(pm_brand_name($bid)) . '</span>' . ($bid === $vb ? '<b class="tick">✓</b>' : '') . '</a>';
    }
    return $h . '<hr>' . (pm_user_is_admin() ? '<a role="menuitem" class="item" href="?tab=business&amp;new=1"><span class="plus">+</span><span>Add a business</span></a>' : '')
        . '<a role="menuitem" class="item" href="?tab=settings"><span class="plus">⚙</span><span>Business settings</span></a></div></div>';
}

/** The sticky top bar. $c: tab, view, vb, settings, csrf, team (list), who, hbAge (seconds or null). */
function pm_ui_topbar(array $c): string
{
    $tab = (string)$c['tab'];
    $view = (string)($c['view'] ?? '');
    $section = (string)($c['section'] ?? '');
    $cur = pm_ui_dept_of($tab, $view, $section);
    $h = '<header class="topbar"><div class="topin">' . pm_ui_biz_menu($c['settings'], (string)$c['vb'], $tab, $view) . '<nav class="mainnav" aria-label="Departments">';
    foreach (pm_ui_nav() as $k => $n) {
        $on = $cur === $k;
        $dd = '';
        $pages = 0;
        foreach ($n['groups'] as $g => $items) {
            $dd .= '<div class="menuhd">' . pm_h($g) . '</div>';
            foreach ($items as $href => $lab) {
                if (str_contains($href, 'business&new') && !pm_user_is_admin()) {
                    continue; // adding a business is the admin's
                }
                $pages++;
                $dd .= '<a role="menuitem" class="item' . (pm_ui_href_on($href, $tab, $view, $section) ? ' on' : '') . '" href="' . pm_h($href) . '">' . pm_h($lab) . '</a>';
            }
        }
        $link = '<a class="navitem" href="' . pm_h($n['href']) . '"' . ($on ? ' aria-current="page"' : '') . '>' . pm_ui_icon($k) . '<span>' . pm_h($n['label']) . '</span></a>';
        $h .= '<div class="dd navdd' . ($on ? ' on' : '') . '" style="' . pm_h(pm_ui_dept_style($k)) . '">' . $link;
        if ($pages > 1) {
            $h .= '<button type="button" class="ddbtn caretbtn" aria-haspopup="true" aria-expanded="false" aria-label="' . pm_h($n['label']) . ' pages"><span class="caret"></span></button><div class="menu" role="menu">' . $dd . '</div>';
        }
        $h .= '</div>';
    }
    $h .= '</nav><div class="topright">';
    $age = $c['hbAge'];
    $min = $age === null ? 0 : intdiv((int)$age, 60);
    $txt = $age === null ? 'Scheduler has not run yet' : 'Scheduler ran ' . ($min >= 120 ? intdiv($min, 60) . ' h' : $min . ' min') . ' ago';
    $cls = $age === null || $age >= 2700 ? ($age !== null && $age >= 7200 ? 'bad' : 'warn') : 'ok';
    $h .= '<a class="status ' . $cls . '" href="?tab=settings&amp;section=health" title="' . pm_h($txt . '. Posts and emails go out when the scheduler runs (every 15 to 30 minutes), or when the app is open.') . '" aria-label="' . pm_h($txt) . '"><i class="dot"></i></a>'; // a dot only: the words are in the tooltip and on the Overview, so the bar never runs out of room
    $team = (array)$c['team'];
    if (pm_user()) {
        return $h . pm_auth_avatar() . '</div></div></header>'; // signed in: the account menu says who is working
    }
    if ($team) {
        $h .= '<form method="post" class="who" data-quiet><input type="hidden" name="csrf" value="' . pm_h((string)$c['csrf']) . '"><input type="hidden" name="action" value="who"><input type="hidden" name="back" value="' . pm_h($tab) . '">'
            . '<select name="who" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="Who is working"><option value="">Working as…</option>';
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
    foreach (pm_user_brands() as $bid) {
        $logo = pm_brand_asset($bid, 'logo');
        $n = count(array_filter($leads, fn($l) => ($l['brand'] ?? 'promanaged') === $bid));
        $title = pm_brand_name($bid);
        $tag = trim((string)(pm_brand_block($settings, $bid)['tagline'] ?? ''));
        echo '<div class="bizcard' . ($bid === $vb ? ' on' : '') . '" style="--bz:' . pm_h(pm_ui_accent($settings, $bid)) . '"><div class="bzhead">'
            . (is_file(PM_ROOT . '/' . $logo) ? '<img class="' . ($bid === 'travel' ? 'sq' : '') . '" src="' . pm_h($logo) . '?v=' . (int)@filemtime(PM_ROOT . '/' . $logo) . '" alt="">' : pm_ui_dot(pm_ui_accent($settings, $bid)))
            . '<div><b>' . pm_h($title) . '</b><small>' . pm_h($tag !== '' ? $tag : ($bid === 'travel' ? 'Stays across Malawi' : ($bid === 'promanaged' ? 'Software, hardware and IT support' : 'Added by you'))) . '</small></div></div>';
        $rows = pm_brand_checklist($bid);
        echo '<div class="chips">';
        foreach ($rows as $r) {
            echo '<span class="chip ' . ($r['ok'] ? 'ok' : 'todo') . '" title="' . pm_h($r['ok'] ? 'Ready' : $r['hint']) . '">' . ($r['ok'] ? '✓ ' : '') . pm_h($r['label']) . '</span>';
        }
        echo '</div><div class="muted t-sm">' . $n . ' lead' . ($n === 1 ? '' : 's') . '</div>'
            . '<div class="btns mt-0"><a class="btn small' . ($bid === $vb ? '' : ' primary') . '" href="?tab=agents&amp;brand=' . pm_h($bid) . '">' . ($bid === $vb ? 'Open leads' : 'Switch to it') . '</a>'
            . '<a class="btn small" href="?tab=settings&amp;brand=' . pm_h($bid) . '">Settings</a>'
            . (pm_brand_is_custom($bid) ? '<a class="btn small" href="?tab=business&amp;id=' . pm_h($bid) . '&amp;welcome=1" title="What the AI built for it, and build it up again">Build-up</a>' : '') . '</div></div>';
    }
    echo (pm_user_is_admin() ? '<a class="bizcard add" href="?tab=business&amp;new=1"><span style="font-size:26px;line-height:1">+</span><b>Add a business</b><small>Describe it, and the AI builds it up</small></a>' : '') . '</div>';
}
