<?php
/**
 * Finding a business's missing contact details (Operations > Business details > Missing details). Nothing is ever filled in by itself: each find is a
 * suggestion with where it came from, and the owner presses "Use this" on each one. The business name is never touched.
 * Free sources first: the connected Facebook Page, then the business's own website. The AI (one web search, std tier) only looks for what those did not
 * give, and its answer is kept only when the page it names really shows the value (a page that cannot be opened is shown as "not checked").
 * Data: data/biz_found.json {brand: {at, note, rows: {field: {value, src, how, checked}}}}.
 */

const PM_BIZFIND_FIELDS = ['whatsapp' => 'WhatsApp number', 'phone' => 'Phone number', 'email' => 'Email address', 'website' => 'Website', 'address' => 'Address or town', 'tagline' => 'Tagline'];

/** The business's settings block as stored: [raw settings, path to its block ([] = top level, ['travel'], ['brands', id])]. */
function pm_bizfind_path(string $brand): array
{
    return $brand === 'travel' ? ['travel'] : (pm_brand_is_custom($brand) ? ['brands', $brand] : []);
}

/** The fields of the business that are empty now (never the name). */
function pm_bizfind_empty(string $brand): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    $s = pm_settings();
    pm_brand_set($was);
    $mobile = trim((string)($s['phone'] ?? '')) !== '' && function_exists('pm_wa_number') && pm_wa_number((string)$s['phone'])[1] !== 'landline'; // a mobile phone is the WhatsApp number too
    return array_values(array_filter(array_keys(PM_BIZFIND_FIELDS), fn($k) => trim((string)($s[$k] ?? '')) === '' && !($k === 'whatsapp' && $mobile)));
}

/** A value in plain form for its field, or '' when it is not a usable one. */
function pm_bizfind_clean(string $k, mixed $v): string
{
    $v = trim(preg_replace('/\s+/u', ' ', strip_tags(is_scalar($v) ? (string)$v : '')));
    if ($v === '') {
        return '';
    }
    return match ($k) {
        'whatsapp' => function_exists('pm_wa_number') && pm_wa_number($v)[1] === 'landline' ? '' : (preg_match('/^\+?[\d\s\-()]{9,20}$/', $v) ? $v : ''),
        'phone' => preg_match('/^\+?[\d\s\-()]{7,20}$/', $v) ? $v : '',
        'email' => filter_var($v, FILTER_VALIDATE_EMAIL) ? mb_strtolower($v) : '',
        'website' => preg_match('#^(https?://)?([a-z0-9-]+\.)+[a-z]{2,}(/\S*)?$#i', $v) && !preg_match('#facebook\.com|instagram\.com|wa\.me|google\.#i', $v) ? preg_replace('#^https?://(www\.)?#i', '', rtrim($v, '/')) : '',
        'address' => mb_strlen($v) >= 3 && mb_strlen($v) <= 160 ? $v : '',
        'tagline' => mb_strlen($v) <= 90 && !preg_match('/\b(best|cheapest|fastest|guarantee[sd]?|number one|leading)\b/i', $v) ? $v : '',
        default => '',
    };
}

/** Does a page's text show this value? Phones by their last nine digits, emails and websites as written, addresses by their main word. */
function pm_bizfind_shows(string $k, string $v, string $page): bool
{
    $p = mb_strtolower($page);
    return match ($k) {
        'whatsapp', 'phone' => ($d = substr(preg_replace('/\D/', '', $v), -9)) !== '' && str_contains(preg_replace('/\D/', '', $page), $d),
        'email' => str_contains($p, mb_strtolower($v)),
        'website' => str_contains($p, mb_strtolower(preg_replace('#/.*$#', '', $v))),
        'address' => (bool)array_filter(preg_split('/[\s,]+/u', mb_strtolower($v)) ?: [], fn($w) => mb_strlen($w) >= 5 && str_contains($p, $w)),
        default => true,
    };
}

/**
 * Looks for the business's empty details and saves them as suggestions. Returns the saved record. $ai = false keeps to the free sources.
 */
function pm_bizfind_run(string $brand, bool $ai = true): array
{
    $want = pm_bizfind_empty($brand);
    $rows = [];
    $notes = [];
    $add = function (string $k, mixed $v, string $src, string $how, bool $checked = true) use (&$rows, $want) {
        $v = pm_bizfind_clean($k, $v);
        if ($v !== '' && in_array($k, $want, true) && !isset($rows[$k])) {
            $rows[$k] = ['value' => $v, 'src' => $src, 'how' => $how, 'checked' => $checked];
        }
    };
    $was = pm_brand();
    pm_brand_set($brand);
    $s = pm_settings();
    pm_brand_set($was);
    // 1. the connected Facebook Page (free)
    $c = function_exists('pm_social_cfg') ? pm_social_cfg($brand) : [];
    if (!empty($c['ready']) && !empty($c['page_id']) && function_exists('pm_graph')) {
        [$ok, $pg] = pm_graph('GET', (string)$c['page_id'], ['fields' => 'website,phone,emails,whatsapp_number,location,single_line_address,link'], (string)$c['token']);
        if ($ok && is_array($pg)) {
            $fb = (string)($pg['link'] ?? 'https://facebook.com/' . $c['page_id']);
            $add('whatsapp', $pg['whatsapp_number'] ?? '', $fb, 'your Facebook Page');
            $add('phone', $pg['phone'] ?? '', $fb, 'your Facebook Page');
            $add('email', ((array)($pg['emails'] ?? []))[0] ?? '', $fb, 'your Facebook Page');
            $add('website', $pg['website'] ?? '', $fb, 'your Facebook Page');
            $add('address', $pg['single_line_address'] ?? trim(implode(', ', array_filter([(string)($pg['location']['street'] ?? ''), (string)($pg['location']['city'] ?? '')]))), $fb, 'your Facebook Page');
        }
    }
    // 2. the business's own website (free)
    $site = trim((string)($s['website'] ?? '')) ?: (string)($rows['website']['value'] ?? '');
    if ($site !== '' && function_exists('pm_study_pages')) {
        $b = pm_study_pages($site, 3);
        $url = (string)($b['url'] ?: pm_study_url($site));
        $all = implode("\n", array_column((array)$b['pages'], 'text'));
        if (preg_match('#wa\.me/(\d{9,15})#', $all, $m)) {
            $add('whatsapp', '+' . $m[1], $url, 'your website');
        }
        foreach ((array)$b['phones'] as $ph) {
            $add('phone', $ph, $url, 'your website');
        }
        foreach ((array)$b['emails'] as $em) {
            $add('email', $em, $url, 'your website');
        }
        if ($b['note'] !== '') {
            $notes[] = 'Website: ' . $b['note'];
        }
    }
    // 3. the AI, with a web search, for what is still empty
    $left = array_values(array_diff($want, array_keys($rows)));
    $ready = function_exists('pm_agents_ready') && (pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']));
    if ($ai && $left && $ready) {
        $brain = pm_brain($brand);
        $p = pm_brand_profile($brand);
        $known = array_filter(['website' => (string)($s['website'] ?? ''), 'phone' => (string)($s['phone'] ?? ''), 'email' => (string)($s['email'] ?? ''), 'address' => (string)($s['address'] ?? '')]);
        $socials = array_values(array_filter(array_map(fn($x) => (string)($x['url'] ?? ''), (array)(pm_brand_block(pm_load('settings', 'pm_default_settings'), $brand)['profile']['presence'] ?? [])))); // where it is online, from its study
        try {
            $system = 'You find the public contact details of one real business on the web. Only give a detail you saw on a page about THIS business (its own site, its Facebook or '
                . 'Instagram page, its Google listing or a directory naming it), with that page\'s address and the words around the detail. Never guess, never build an email or a number '
                . 'from a pattern, never give another business\'s details. A whatsapp number only when the page says it is on WhatsApp (or a wa.me link). Leave out anything not found. '
                . 'The tagline is the one exception: a short line under 10 words written from the About text, with no claims (no best, leading, number one). Reply with JSON only.';
            $user = json_encode(['business' => pm_brand_name($brand), 'about' => (string)$brain['about'], 'market' => (string)$p['market'], 'already_known' => $known,
                    'its_pages' => array_slice($socials, 0, 6), 'find' => $left], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nJSON: {\"found\":{\"<field from find>\":{\"value\":\"\",\"source_url\":\"https://...\",\"quote\":\"the words on that page around it\"}}}";
            $r = pm_agent_json(pm_claude($system, $user, true, 1200, 'std'));
            if (is_array($r) && array_is_list($r) && is_array($r[0] ?? null)) {
                $r = $r[0];
            }
            foreach ((array)($r['found'] ?? []) as $k => $x) {
                $k = (string)$k;
                if (!in_array($k, $left, true) || !is_array($x)) {
                    continue;
                }
                $v = pm_bizfind_clean($k, $x['value'] ?? '');
                if ($v === '') {
                    continue;
                }
                if ($k === 'tagline') {
                    $add('tagline', $v, '', 'written by the AI from your About text');
                    continue;
                }
                $src = (string)($x['source_url'] ?? '');
                if (!preg_match('#^https?://#i', $src)) {
                    continue; // no page, no detail
                }
                if ($k === 'whatsapp' && !preg_match('/whats\s?app|wa\.me/i', (string)($x['quote'] ?? '') . ' ' . $src)) {
                    continue;
                }
                $pg = function_exists('pm_study_fetch') ? pm_study_fetch($src, 800000) : ['ok' => false];
                $text = !empty($pg['ok']) ? html_entity_decode(strip_tags((string)$pg['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' ' . (string)$pg['html'] : '';
                if ($text !== '' && !pm_bizfind_shows($k, $v, $text)) {
                    continue; // the page it named does not show it: an invention
                }
                $add($k, $v, $src, 'found on the web by the AI', $text !== '');
            }
        } catch (Throwable $e) {
            $notes[] = 'The AI search did not work this time (' . mb_substr($e->getMessage(), 0, 80) . ').';
        }
    } elseif ($ai && $left && !$ready) {
        $notes[] = 'The AI is not set up, so only your Facebook Page and website were read.';
    }
    $missed = array_values(array_diff($want, array_keys($rows)));
    if ($missed) {
        $notes[] = 'Not found: ' . implode(', ', array_map(fn($k) => PM_BIZFIND_FIELDS[$k], $missed)) . '. Type ' . (count($missed) === 1 ? 'it' : 'them') . ' in below.';
    }
    $rec = ['at' => date('Y-m-d H:i'), 'note' => implode(' ', $notes), 'rows' => $rows];
    pm_update('biz_found', function (array $all) use ($brand, $rec) {
        $all[$brand] = $rec;
        return $all;
    }, fn() => []);
    return $rec;
}

/** The saved suggestions that are still useful (their field is still empty). */
function pm_bizfind_saved(string $brand): array
{
    $rec = (array)(pm_load('biz_found', fn() => [])[$brand] ?? []);
    $empty = pm_bizfind_empty($brand);
    $rec['rows'] = array_intersect_key((array)($rec['rows'] ?? []), array_flip($empty));
    return $rec + ['at' => '', 'note' => ''];
}

/** Writes one field of a business (never the name). */
function pm_bizfind_write(string $brand, string $k, string $v): void
{
    $path = pm_bizfind_path($brand);
    pm_update('settings', function (array $raw) use ($path, $k, $v) {
        if (!$path) {
            $raw[$k] = $v;
        } elseif (count($path) === 1) {
            $raw[$path[0]][$k] = $v;
        } else {
            $raw[$path[0]][$path[1]][$k] = $v;
        }
        return $raw;
    }, 'pm_default_settings');
}

/** Drops one suggestion. */
function pm_bizfind_drop(string $brand, string $k): void
{
    pm_update('biz_found', function (array $all) use ($brand, $k) {
        unset($all[$brand]['rows'][$k]);
        return $all;
    }, fn() => []);
}

/* ---------------- forms (POST action=social_ext&do=biz_*) ---------------- */

function pm_bizfind_back(string $msg, string $kind = 'ok'): array
{
    return ['msg' => $msg, 'kind' => $kind, 'to' => 'settings&section=business#missing'];
}

/** "Find the missing details": the free sources, then the AI for the rest. */
function pm_do_biz_find(string $vb): array
{
    @set_time_limit(180);
    if (!pm_bizfind_empty($vb)) {
        return pm_bizfind_back('Nothing is missing.');
    }
    $r = pm_bizfind_run($vb, !empty($_POST['ai']));
    $n = count($r['rows']);
    return pm_bizfind_back(($n ? $n . ' detail' . ($n === 1 ? '' : 's') . ' found: check each one and press Use this.' : 'Nothing was found.') . ($r['note'] !== '' ? ' ' . $r['note'] : ''), $n ? 'ok' : 'err');
}

/** "Use this": the one suggestion goes into the business details, only while that box is still empty. */
function pm_do_biz_use(string $vb): array
{
    $k = (string)($_POST['k'] ?? '');
    $row = pm_bizfind_saved($vb)['rows'][$k] ?? null;
    if (!isset(PM_BIZFIND_FIELDS[$k]) || !$row) {
        return pm_bizfind_back('That suggestion is gone, or the box is filled in already.', 'err');
    }
    pm_bizfind_write($vb, $k, (string)$row['value']);
    pm_bizfind_drop($vb, $k);
    return pm_bizfind_back(PM_BIZFIND_FIELDS[$k] . ' saved: ' . $row['value'] . '.');
}

/** "Not right": forgets one suggestion. */
function pm_do_biz_skip(string $vb): array
{
    $k = (string)($_POST['k'] ?? '');
    pm_bizfind_drop($vb, $k);
    return pm_bizfind_back('Left out.');
}

/** The suggestions part of the Missing details card. */
function pm_bizfind_card(string $brand, string $csrf): string
{
    $empty = pm_bizfind_empty($brand);
    if (!$empty) {
        return '';
    }
    $rec = pm_bizfind_saved($brand);
    $hid = fn(string $do, string $k = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">'
        . ($k !== '' ? '<input type="hidden" name="k" value="' . pm_h($k) . '">' : '');
    $h = '';
    if ($rec['rows']) {
        $h .= '<h3 class="mt-2">Found for you: check each one</h3><ul class="nextlist">';
        foreach ($rec['rows'] as $k => $r) {
            $src = (string)$r['src'] !== '' ? ' <a href="' . pm_h((string)$r['src']) . '" target="_blank" rel="noopener noreferrer">see the page</a>' : '';
            $h .= '<li><span class="grow"><b>' . pm_h(PM_BIZFIND_FIELDS[$k] ?? $k) . ':</b> ' . pm_h((string)$r['value']) . '<br><span class="muted t-sm">' . pm_h(str_starts_with((string)$r['how'], 'written') ? ucfirst((string)$r['how']) : 'From ' . $r['how']) . '.' . $src
                . (empty($r['checked']) && $k !== 'tagline' ? ' The page could not be opened to check it: make sure it is right.' : '') . '</span></span>'
                . '<form method="post" class="inl">' . $hid('biz_use', (string)$k) . '<button class="btn small primary">Use this</button></form>'
                . '<form method="post" class="inl">' . $hid('biz_skip', (string)$k) . '<button class="btn small ghost">Not right</button></form></li>';
        }
        $h .= '</ul>';
    }
    if ($rec['note'] !== '') {
        $h .= '<p class="hint">' . pm_h((string)$rec['note']) . '</p>';
    }
    $ai = function_exists('pm_agents_ready') && pm_agents_ready();
    $h .= '<form method="post" class="btns">' . $hid('biz_find') . '<input type="hidden" name="ai" value="' . ($ai ? '1' : '') . '"><button class="btn small' . ($rec['rows'] ? '' : ' primary') . '">'
        . ($rec['at'] !== '' ? 'Look again' : 'Find the missing details' . ($ai ? ' with AI' : '')) . '</button><span class="hint">Reads your Facebook Page and website first (free)'
        . ($ai ? ', then the AI searches the web for the rest (about 2 US cents)' : '') . '. Nothing is saved until you press Use this on each one. The business name is never changed.</span></form>';
    return $h;
}
