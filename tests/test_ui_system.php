<?php
/**
 * Guard test for the UI redesign (plans/ui-redesign-plan.md, Phase 0).
 * Measures the UI's "today" values and fails only when they get worse, so each later phase can tighten one budget
 * constant after it removes its offenders. Every check prints today's count next to its budget; a failure lists the
 * first 10 offenders.
 * Checks:
 *   1. font-size values in assets/app.css outside {12,13,14,16,20,24}px, minus the allowed exceptions
 *      (.go arrow, .bizcard.add plus, .lpshot b serif, loader spinner: decorative glyphs up to 26px)
 *   2. border-radius values outside {8px,12px,999px,50%,0} or var(--r-*)  (plan target: only 8/12 + pill + circle)
 *   3. number of :root blocks in app.css (plan target: 1)
 *   4. number of <style> blocks in lib/view_*.php and lib/sx_*.php (plan target: 0)
 *   5. style="..." attributes using banned properties (margin, padding, font-size, color, background, border,
 *      line-height, width in px) in lib/*.php and index.php (plan target: at most 40, all data-driven)
 *   6. per rendered screen, most non-small .btn.primary inside one .card / details / .sx-card / form (plan target: 1)
 *   7. WCAG contrast of ink/bg, muted/surface, white on every department colour from pm_ui_nav(), ok/ok-bg,
 *      warn/warn-bg, bad/bad-bg - all at least 4.5
 * Screens rendered: every URL in the plan's QA table, for 'promanaged' and for a business added in the app the way
 * tests/test_departments.php does. Runs on a TEMP COPY of data/ with the network stubbed. Run: php tests/test_ui_system.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
require_once dirname(__DIR__) . '/lib/mail.php'; // what index.php loads for the screens
require_once dirname(__DIR__) . '/lib/wa_biz.php';
file_put_contents($T['env'], "APP_URL=https://app.test\nFB_PAGE_ID=PAGE\nFB_PAGE_TOKEN=T\nIG_USER_ID=IGU\n");
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;
$GLOBALS['PM_GRAPH_STUB'] = function ($method, $path, $params) {
    if ($path === 'PAGE') {
        return [true, ['name' => 'P', 'about' => '', 'description' => '', 'website' => '', 'phone' => '', 'emails' => [], 'picture' => ['data' => ['url' => 'u', 'is_silhouette' => false]], 'cover' => ['source' => 'c'], 'hours' => [], 'location' => null, 'fan_count' => 12, 'followers_count' => 12]];
    }
    return [true, ['data' => []]];
};
foreach (['page_opt', 'cover_studio', 'social_bank', 'page_audit', 'social_channels'] as $f) {
    pm_save($f, []);
}

/* ---- Budgets: today's measured values (each later phase tightens them toward the plan's targets). ---- */
const UI_BUDGET_FONT = 0;         // font-size values outside the scale, minus exceptions (plan target: 0)
const UI_BUDGET_RADIUS = 0;       // border-radius values outside the tokens (plan target: 0)
const UI_BUDGET_ROOTS = 1;        // :root blocks in app.css (plan target: 1)
const UI_BUDGET_STYLE_BLOCKS = 0; // <style> blocks in lib/view_*.php + lib/sx_*.php (plan target: 0)
const UI_BUDGET_INLINE = 12;      // style= attributes with banned properties (plan target: at most 40, all data-driven)
const UI_BUDGET_PRIMARY = 1;      // most non-small .btn.primary in one container (plan target: 1)
const UI_MIN_CONTRAST = 4.5;      // WCAG AA for the text pairs below (already met today)

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/** Renders a view file the way index.php does, for a business (the way tests/test_departments.php's page() does). */
function page(string $file, string $vb = 'promanaged', array $get = [], string $section = ''): string
{
    pm_brand_set($vb);
    $GLOBALS['vb'] = $vb;
    $GLOBALS['section'] = $section;
    $GLOBALS['PM_WHO'] = '';
    $_GET = $get;
    $_POST = [];
    $settings = pm_settings();
    $csrf = 'tokD';
    $GLOBALS['PM_DEPT'] = pm_ui_dept_of((string)($get['tab'] ?? ''), (string)($get['view'] ?? ''), $section);
    ob_start();
    include dirname(__DIR__) . '/lib/' . $file;
    return (string)ob_get_clean();
}

/** Renders a full index.php screen (the proposal tabs live there) in a fresh PHP process pointed at the temp data. */
function page_index(array $get, string $brand): array
{
    $get['brand'] = $brand;
    $code = '$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["REQUEST_METHOD"]="GET";$_GET=' . var_export($get, true)
        . ';chdir(' . var_export(PM_ROOT, true) . ');ob_start();include "index.php";echo "\n===PM_UI_PAGE===\n" . ob_get_clean();';
    $env = [];
    foreach (['PATH', 'SystemRoot', 'ComSpec', 'PATHEXT', 'TEMP', 'TMP', 'WINDIR'] as $k) {
        $v = getenv($k);
        if ($v !== false) {
            $env[$k] = $v;
        }
    }
    $env['PM_DATA_DIR'] = $GLOBALS['PM_T']['tmp'] . '/data';
    $env['PM_OUT_DIR'] = $GLOBALS['PM_T']['tmp'] . '/out';
    $env['PM_ENV_FILE'] = $GLOBALS['PM_T']['tmp'] . '/env.txt';
    $env['PM_TEST'] = '1';
    $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PM_ROOT, $env);
    if (!is_resource($proc)) {
        return ['', 'could not start php'];
    }
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $parts = explode("\n===PM_UI_PAGE===\n", $out, 2);
    return [isset($parts[1]) ? $parts[1] : '', $err];
}

/** The app.css source, split into lines. */
function ui_css(): array
{
    return preg_split('/\R/', (string)file_get_contents(PM_ROOT . '/assets/app.css'));
}

/** Every [file => path] that a check scans: lib/*.php plus index.php. */
function ui_php_files(): array
{
    $files = [];
    foreach ((glob(PM_ROOT . '/lib/*.php') ?: []) as $f) {
        if (in_array(basename($f), ['pdf.php', 'mail.php', 'tm_email.php', 'onepager.php'], true)) {
            continue; // PDF and email markup: inline styles are how those are written (out of scope for the screens' design system)
        }
        $files[basename($f)] = $f;
    }
    $files['index.php'] = PM_ROOT . '/index.php';
    return $files;
}

/** The winning value of each custom property from the (possibly several) :root blocks, later blocks overriding. */
function ui_css_tokens(string $css): array
{
    $tokens = [];
    if (preg_match_all('/:root\s*\{([^}]*)\}/s', $css, $m)) {
        foreach ($m[1] as $body) {
            if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $d)) {
                foreach ($d[1] as $i => $name) {
                    $tokens[$name] = trim($d[2][$i]);
                }
            }
        }
    }
    return $tokens;
}

/* ---- WCAG contrast ---- */

function ui_lum(string $hex): float
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $f = fn($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    $r = $f(hexdec(substr($hex, 0, 2)) / 255);
    $g = $f(hexdec(substr($hex, 2, 2)) / 255);
    $b = $f(hexdec(substr($hex, 4, 2)) / 255);
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function ui_ratio(string $a, string $b): float
{
    $la = ui_lum($a);
    $lb = ui_lum($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/* ---- the screens (the plan's QA table) ---- */

function ui_screens(): array
{
    return [
        ['?tab=home', ['tab' => 'home'], 'view_home.php', ''],
        ['?tab=agents', ['tab' => 'agents'], 'view_agents.php', ''],
        ['?tab=whatsapp', ['tab' => 'whatsapp'], 'view_whatsapp.php', ''],
        ['?tab=targeting', ['tab' => 'targeting'], 'view_sales_targeting.php', ''],
        ['?tab=social', ['tab' => 'social', 'view' => ''], 'view_social.php', ''],
        ['?tab=social&view=leadposts', ['tab' => 'social', 'view' => 'leadposts'], 'view_social_leadposts.php', ''],
        ['?tab=social&view=content', ['tab' => 'social', 'view' => 'content'], 'view_social_content.php', ''],
        ['?tab=social&view=channels', ['tab' => 'social', 'view' => 'channels'], 'view_social_channels.php', ''],
        ['?tab=social&view=inbox', ['tab' => 'social', 'view' => 'inbox'], 'view_social_page.php', ''],
        ['?tab=social&view=page', ['tab' => 'social', 'view' => 'page'], 'view_social_page.php', ''],
        ['?tab=social&view=growth', ['tab' => 'social', 'view' => 'growth'], 'view_social_growth.php', ''],
        ['?tab=social&view=studio', ['tab' => 'social', 'view' => 'studio'], 'view_social_studio.php', ''],
        ['?tab=social&view=kit', ['tab' => 'social', 'view' => 'kit'], 'view_social_kit.php', ''],
        ['?tab=social&view=optimise', ['tab' => 'social', 'view' => 'optimise'], 'view_social_optimise.php', ''],
        ['?tab=social&view=voice', ['tab' => 'social', 'view' => 'voice'], 'view_social_voice.php', ''],
        ['?tab=social&view=results', ['tab' => 'social', 'view' => 'results'], 'view_social_results.php', ''],
        ['?tab=social&view=audit', ['tab' => 'social', 'view' => 'audit'], 'view_social_page.php', ''],
        ['?tab=social&view=numbers', ['tab' => 'social', 'view' => 'numbers'], 'view_social_numbers.php', ''],
        ['?tab=settings&section=business', ['tab' => 'settings', 'section' => 'business'], 'view_settings.php', 'business'],
        ['?tab=settings&section=email', ['tab' => 'settings', 'section' => 'email'], 'view_settings.php', 'email'],
        ['?tab=settings&section=connections', ['tab' => 'settings', 'section' => 'connections'], 'view_settings.php', 'connections'],
        ['?tab=settings&section=team', ['tab' => 'settings', 'section' => 'team'], 'view_settings.php', 'team'],
        ['?tab=business&new=1', ['tab' => 'business', 'new' => '1'], 'view_business.php', ''],
        ['?tab=proposal', ['tab' => 'proposal'], 'INDEX', ''],
        ['?tab=history', ['tab' => 'history'], 'INDEX', ''],
        ['?tab=template', ['tab' => 'template'], 'INDEX', ''],
    ];
}

/** Parses a rendered screen into a DOMDocument (UTF-8). */
function ui_dom(string $html): DOMDocument
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return $dom;
}

/** For one screen: the count of non-small .btn.primary inside each .card / details / .sx-card / form, worst first. */
function ui_screen_primaries(string $html, string $label): array
{
    $dom = ui_dom($html);
    $count = [];
    $info = [];
    foreach ($dom->getElementsByTagName('*') as $el) {
        $c = ' ' . $el->getAttribute('class') . ' ';
        if (!(str_contains($c, ' btn ') && str_contains($c, ' primary ') && !str_contains($c, ' small '))) {
            continue;
        }
        // the section a button belongs to: its nearest card, sx-card or details (a form inside a card counts toward the card)
        $sec = $el->parentNode;
        while ($sec instanceof DOMElement) {
            $sc = $sec->getAttribute('class');
            if (preg_match('/(^|\s)(card|sx-card)(\s|$)/', $sc) === 1 || strtolower($sec->tagName) === 'details') {
                break;
            }
            $sec = $sec->parentNode;
        }
        if (!($sec instanceof DOMElement)) {
            $sec = $el->parentNode;
        }
        $id = $sec->getNodePath();
        $count[$id] = ($count[$id] ?? 0) + 1;
        $info[$id] = '<' . strtolower($sec->tagName) . ' class="' . trim(preg_replace('/\s+/', ' ', $sec->getAttribute('class'))) . '">';
    }
    $out = [];
    foreach ($count as $id => $n) {
        $out[] = [$n, $label, $info[$id]];
    }
    usort($out, fn($a, $b) => $b[0] <=> $a[0]);
    return $out;
}

/* ---- a business added in the app, the way tests/test_departments.php does ---- */

t('A business added in the app renders too', function () {
    $ANS = ['name' => 'Sunrise Solar', 'sells' => 'We design and install solar systems for homes and small businesses.', 'customers' => 'Home owners', 'sell_to' => 'business', 'cities' => 'Lilongwe', 'targets' => 'schools, clinics',
        'facts' => "We install solar systems\nWe offer a free site visit", 'voice' => 'friendly', 'magnet' => 'a free site visit', 'email' => 'info@sunrise.example', 'phone' => '0999111222', 'website' => 'https://www.sunrise.example'];
    $GLOBALS['PM_AI_STUB'] = null;
    [$id] = pm_brand_create($ANS, pm_brand_draft($ANS)['draft']);
    pm_t_eq($id, 'sunrisesolar', 'the added business exists');
    $allCfg = pm_load('agents_config', 'pm_agents_default_config');
    $allCfg[$id] = array_replace((array)($allCfg[$id] ?? []), ['sectors' => ['schools', 'clinics'], 'cities' => ['Lilongwe'], 'offerings' => ['Solar: systems'], 'existing_clients' => ['Old Ltd']]);
    pm_save('agents_config', $allCfg);
});

/* ---- render every QA-table screen for both businesses ---- */

$UI_HTML = [];  // label => html
$UI_DIRTY = []; // screens that render with PHP warnings/errors

t('Every QA-table screen renders, for promanaged and the added business', function () {
    global $UI_HTML, $UI_DIRTY;
    foreach (ui_screens() as [$url, $get, $file, $section]) {
        foreach (['promanaged', 'sunrisesolar'] as $brand) {
            $label = $url . ' (' . $brand . ')';
            if ($file === 'INDEX') {
                [$html, $err] = page_index($get, $brand);
                if (trim($err) !== '') {
                    $UI_DIRTY[] = $label . ' stderr: ' . trim(preg_replace('/\s+/', ' ', $err));
                }
            } else {
                $html = page($file, $brand, $get, $section);
            }
            $UI_HTML[$label] = $html;
            if ($html === '' || str_contains($html, 'Fatal error')) {
                $UI_DIRTY[] = $label . ' rendered ' . ($html === '' ? 'nothing' : 'a fatal error');
            } elseif (preg_match('/(Warning|Notice):/', $html)) {
                $UI_DIRTY[] = $label . ' has a PHP warning or notice';
            }
        }
    }
    pm_t_assert(!$UI_DIRTY, 'all ' . count($UI_HTML) . ' screens render cleanly' . ($UI_DIRTY ? ' (first 10: ' . implode(' | ', array_slice($UI_DIRTY, 0, 10)) . ')' : ''));
});

/* ---- check 1: font sizes in app.css ---- */

t('Font sizes in app.css stay on the scale', function () {
    $scale = [12.0, 13.0, 14.0, 16.0, 20.0, 24.0];
    $exceptions = ['.go', '.bizcard.add', '.lpshot b', '.spin']; // decorative glyphs up to 26px, lead-post serif, spinner
    $offenders = [];
    foreach (ui_css() as $i => $line) {
        if (str_contains($line, 'font-size:')) {
            if (preg_match_all('/font-size\s*:\s*([^;}]+)/', $line, $m)) {
                foreach ($m[1] as $value) {
                    $value = trim($value);
                    if (preg_match('/^var\(--fs-/', $value)) {
                        continue; // a type-scale token
                    }
                    if (!preg_match('/^([\d.]+)px$/i', $value, $n)) {
                        $offenders[] = 'line ' . ($i + 1) . ' non-px value ' . $value;
                        continue;
                    }
                    $px = (float)$n[1];
                    if (in_array($px, $scale, true)) {
                        continue;
                    }
                    $exempt = false;
                    foreach ($exceptions as $sel) {
                        if (str_contains($line, $sel)) {
                            $exempt = $px <= 26;
                            break;
                        }
                    }
                    if (!$exempt) {
                        $offenders[] = 'line ' . ($i + 1) . ' ' . $value;
                    }
                }
            }
        }
        if (preg_match('/font\s*:/', $line) && !preg_match('/font-(family|weight|style|variant)/', $line)) {
            // the font: shorthand carries a size too
            if (preg_match('/font\s*:\s*([^;}]+)/', $line, $m) && preg_match_all('/(\d+(?:\.\d+)?)px/', $m[1], $n)) {
                foreach ($n[1] as $value) {
                    $px = (float)$value;
                    if (in_array($px, $scale, true)) {
                        continue;
                    }
                    $exempt = false;
                    foreach ($exceptions as $sel) {
                        if (str_contains($line, $sel)) {
                            $exempt = $px <= 26;
                            break;
                        }
                    }
                    if (!$exempt) {
                        $offenders[] = 'line ' . ($i + 1) . ' font ' . $value . 'px';
                    }
                }
            }
        }
    }
    echo '  today  font sizes off the scale: ' . count($offenders) . ' (budget ' . UI_BUDGET_FONT . ")\n";
    pm_t_assert(count($offenders) <= UI_BUDGET_FONT, count($offenders) . ' font sizes off the scale, budget ' . UI_BUDGET_FONT . ($offenders && count($offenders) > UI_BUDGET_FONT ? ' (first 10: ' . implode(' | ', array_slice($offenders, 0, 10)) . ')' : ''));
});

/* ---- check 2: border radii in app.css ---- */

t('Border radii in app.css stay on the tokens', function () {
    $allowed = ['8px', '12px', '999px', '50%', '0'];
    $offenders = [];
    foreach (ui_css() as $i => $line) {
        if (!str_contains($line, 'border-radius:')) {
            continue;
        }
        if (preg_match_all('/border-radius\s*:\s*([^;}]+)/', $line, $m)) {
            foreach ($m[1] as $value) {
                foreach (preg_split('/\s+/', trim($value)) as $part) {
                    if (preg_match('/^var\(--r-/', $part)) {
                        continue;
                    }
                    if (!in_array($part, $allowed, true)) {
                        $offenders[] = 'line ' . ($i + 1) . ' ' . $part;
                    }
                }
            }
        }
    }
    echo '  today  radii off the tokens: ' . count($offenders) . ' (budget ' . UI_BUDGET_RADIUS . ")\n";
    pm_t_assert(count($offenders) <= UI_BUDGET_RADIUS, count($offenders) . ' radii off the tokens, budget ' . UI_BUDGET_RADIUS . ($offenders && count($offenders) > UI_BUDGET_RADIUS ? ' (first 10: ' . implode(' | ', array_slice($offenders, 0, 10)) . ')' : ''));
});



/* ---- check 3: :root blocks ---- */

t(':root appears once', function () {
    $where = [];
    foreach (ui_css() as $i => $line) {
        if (preg_match('/:root\s*\{/', $line)) {
            $where[] = $i + 1;
        }
    }
    echo '  today  :root blocks: ' . count($where) . ' (budget ' . UI_BUDGET_ROOTS . ", plan target 1)\n";
    pm_t_assert(count($where) <= UI_BUDGET_ROOTS, count($where) . ' :root blocks, budget ' . UI_BUDGET_ROOTS . ($where && count($where) > UI_BUDGET_ROOTS ? ' (first 10: lines ' . implode(', ', array_slice($where, 0, 10)) . ')' : ''));
});

/* ---- check 4: <style> blocks in the views ---- */

t('No <style> blocks in view and sx files', function () {
    $offenders = [];
    foreach (ui_php_files() as $name => $path) {
        if (!str_starts_with($name, 'view_') && !str_starts_with($name, 'sx_')) {
            continue;
        }
        $n = substr_count((string)file_get_contents($path), '<style');
        if ($n > 0) {
            $offenders[] = $name . ': ' . $n;
        }
    }
    $total = 0;
    foreach ($offenders as $o) {
        $total += (int)explode(': ', $o)[1];
    }
    echo '  today  <style> blocks: ' . $total . ' in ' . count($offenders) . ' files (budget ' . UI_BUDGET_STYLE_BLOCKS . ", plan target 0)\n";
    pm_t_assert(count($offenders) <= UI_BUDGET_STYLE_BLOCKS, count($offenders) . ' files with <style>, budget ' . UI_BUDGET_STYLE_BLOCKS . ($offenders && count($offenders) > UI_BUDGET_STYLE_BLOCKS ? ' (first 10: ' . implode(' | ', array_slice($offenders, 0, 10)) . ')' : ''));
});

/* ---- check 5: inline styles with banned properties ---- */

t('Inline styles use only custom properties and % widths', function () {
    $offenders = [];
    foreach (ui_php_files() as $name => $path) {
        foreach (file($path) as $i => $line) {
            if (!str_contains($line, 'style=')) {
                continue;
            }
            if (!preg_match_all('/style\s*=\s*(["\'])(.*?)\1/s', $line, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $mm) {
                $content = preg_split('/<\?/', $mm[2], 2)[0]; // the literal text the developer wrote; dynamic parts only fill in values
                if (!preg_match_all('/([a-z-]+)\s*:\s*([^;]*)/', $content, $d, PREG_SET_ORDER)) {
                    continue;
                }
                $bad = false;
                foreach ($d as $decl) {
                    $prop = strtolower(trim($decl[1]));
                    $val = trim($decl[2]);
                    if (str_starts_with($prop, '--')) {
                        continue; // data-driven custom properties are the allowed way
                    }
                    if (in_array($prop, ['margin', 'padding', 'font-size', 'color', 'background', 'border', 'line-height'], true)
                        || preg_match('/^(margin|padding|border)-/', $prop) === 1
                        || ($prop === 'width' && preg_match('/px/i', $val))) {
                        $bad = true;
                        break;
                    }
                }
                if ($bad) {
                    $offenders[] = $name . ':' . ($i + 1) . '  ' . trim(preg_replace('/\s+/', ' ', $line));
                }
            }
        }
    }
    echo '  today  inline styles with banned properties: ' . count($offenders) . ' (budget ' . UI_BUDGET_INLINE . ")\n";
    pm_t_assert(count($offenders) <= UI_BUDGET_INLINE, count($offenders) . ' inline styles with banned properties, budget ' . UI_BUDGET_INLINE . ($offenders && count($offenders) > UI_BUDGET_INLINE ? ' (first 10: ' . implode(' | ', array_slice($offenders, 0, 10)) . ')' : ''));
});



/* ---- check 6: filled primary buttons per container ---- */

t('At most one filled primary button per card or form', function () {
    global $UI_HTML;
    $worst = [];
    foreach ($UI_HTML as $label => $html) {
        foreach (ui_screen_primaries($html, $label) as $row) {
            $worst[] = $row;
        }
    }
    usort($worst, fn($a, $b) => $b[0] <=> $a[0]);
    $max = $worst ? $worst[0][0] : 0;
    echo '  today  most non-small .btn.primary in one section (card/details): ' . $max . ' (budget ' . UI_BUDGET_PRIMARY . ", plan target 1)\n";
    $top = array_slice($worst, 0, 10);
    pm_t_assert($max <= UI_BUDGET_PRIMARY, 'most primaries per container ' . $max . ', budget ' . UI_BUDGET_PRIMARY . ($top && $max > UI_BUDGET_PRIMARY ? ' (first 10: ' . implode(' | ', array_map(fn($r) => $r[0] . ' in ' . $r[2] . ' on ' . $r[1], $top)) . ')' : ''));
});


/* ---- check 7: contrast ---- */

t('Every text pair meets WCAG AA', function () {
    $css = (string)file_get_contents(PM_ROOT . '/assets/app.css');
    $tok = ui_css_tokens($css);
    // resolve one level of alias chains (--okbg:var(--ok-bg) -> #ecf8f0)
    $resolve = function (string $v) use (&$resolve, $tok): string {
        if (preg_match('/^var\((--[a-z0-9-]+)\)$/', trim($v), $m) && isset($tok[$m[1]])) {
            return $resolve($tok[$m[1]]);
        }
        return $v;
    };
    $hex = fn($v) => preg_match('/^#[0-9a-fA-F]{3,8}$/', $resolve((string)$v)) ? $resolve((string)$v) : '';
    $surface = $hex($tok['--surface'] ?? '') ?: $hex($tok['--card'] ?? '') ?: '#ffffff';
    $badFg = $hex($tok['--bad'] ?? '') ?: $hex($tok['--danger'] ?? '');
    $badBg = $hex($tok['--badbg'] ?? '');
    if ($badBg === '') { // today: the tinted background that carries the danger text (the .sx-chip.bad look)
        if (preg_match_all('/\{([^{}]*var\(--(?:danger|bad)\)[^{}]*)\}/s', $css, $m)) {
            foreach ($m[1] as $body) {
                if (preg_match('/background\s*:\s*(#[0-9a-fA-F]{3,8})/', $body, $b)) {
                    $badBg = $b[1];
                    break;
                }
            }
        }
    }
    $pairs = [
        ['ink on bg', $hex($tok['--ink'] ?? ''), $hex($tok['--bg'] ?? '')],
        ['muted on surface', $hex($tok['--muted'] ?? ''), $surface],
        ['ok on ok-bg', $hex($tok['--ok'] ?? ''), $hex($tok['--okbg'] ?? '')],
        ['warn on warn-bg', $hex($tok['--warn'] ?? ''), $hex($tok['--warnbg'] ?? '')],
        ['bad on bad-bg', $badFg, $badBg],
    ];
    foreach (pm_ui_nav() as $dept => $d) {
        $pairs[] = ['white on ' . $dept . ' (' . $d['label'] . ')', '#ffffff', $d['color']];
    }
    $bad = [];
    foreach ($pairs as [$label, $fg, $bg]) {
        if ($fg === '' || $bg === '') {
            $bad[] = $label . ' is not a hex colour (' . $fg . ' on ' . $bg . ')';
            continue;
        }
        $ratio = ui_ratio($fg, $bg);
        if ($ratio < UI_MIN_CONTRAST) {
            $bad[] = $label . ' ' . $fg . ' on ' . $bg . ' = ' . round($ratio, 2) . ':1';
        }
    }
    echo '  today  pairs below ' . UI_MIN_CONTRAST . ':1 - ' . count($bad) . ' of ' . count($pairs) . "\n";
    pm_t_assert(!$bad, 'all ' . count($pairs) . ' text pairs at least ' . UI_MIN_CONTRAST . ':1' . ($bad ? ' (failing: ' . implode(' | ', $bad) . ')' : ''));
});

pm_t_done();
