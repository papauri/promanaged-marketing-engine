<?php
/**
 * Extra checks on a post before it can go out (core's pm_social_lint stays; core merges this through pm_lint_ext).
 * BLOCK = must be fixed, WARN = shown to the owner. Pure PHP, no AI, no network.
 */

function pm_sx_brand_names(string $brand): array
{
    $s = pm_load('settings', 'pm_default_settings');
    return array_values(array_unique(array_filter([$brand === 'travel' ? (string)($s['travel']['company_name'] ?? 'Travel Malawi') : (string)($s['company_name'] ?? 'ProManaged IT'), $brand === 'travel' ? 'Travel Malawi' : 'ProManaged IT'])));
}

/** Every piece of text the reader will see (caption, picture words, slides, spoken and on-screen video text). */
function pm_sx_post_texts(array $p): array
{
    $t = ['caption' => trim((string)($p['caption'] ?? '')), 'headline' => trim((string)($p['headline'] ?? '')), 'sub' => trim((string)($p['sub'] ?? ''))];
    $i = 0;
    foreach ((array)($p['slides'] ?? []) as $s) {
        $t['slide' . $i++] = trim((string)($s['h'] ?? '') . '. ' . (string)($s['t'] ?? ''));
    }
    $v = (array)($p['video'] ?? []);
    if ($v) {
        $t['video'] = trim(implode('. ', array_filter([(string)($v['hook_2s'] ?? ''), (string)($v['cover_text'] ?? ''), (string)($v['cta'] ?? '')])));
        foreach ((array)($v['shots'] ?? []) as $k => $sh) {
            $t['shot' . $k] = trim((string)($sh['say'] ?? '') . '. ' . (string)($sh['onscreen'] ?? ''));
        }
    }
    return array_filter($t, fn($x) => $x !== '' && $x !== '.');
}

/** The facts a post may rely on: its own (stored on the post), else its seed's, else the Marketing brain and the owner's ideas. */
function pm_sx_post_facts(array $p): array
{
    $brand = ($p['brand'] ?? 'promanaged') === 'travel' ? 'travel' : 'promanaged';
    $facts = array_map('strval', (array)($p['facts'] ?? []));
    if (!$facts && !empty($p['seed']) && function_exists('pm_sx_seed_find')) {
        $s = pm_sx_seed_find($brand, (string)$p['seed'], !empty($p['occasion_date']) ? date('Y-m-d', strtotime($p['occasion_date'] . ' -30 days')) : null);
        $facts = $s ? $s['facts'] : [];
    }
    if (!$facts) {
        if (function_exists('pm_brain')) {
            $br = pm_brain($brand);
            $facts = array_merge([(string)($br['about'] ?? '')], preg_split('/\R/', (string)($br['facts'] ?? '')) ?: []);
        }
        foreach ((array)(pm_bank_get($brand)['ideas'] ?? []) as $i) {
            $facts[] = (string)($i['text'] ?? '');
        }
        foreach (function_exists('pm_social_proof_usable') ? pm_social_proof_usable($brand) : [] as $r) {
            $facts[] = (string)$r['text'];
        }
    }
    if (!empty($p['proof_id']) && function_exists('pm_social_proof_find') && ($r = pm_social_proof_find($brand, (string)$p['proof_id']))) {
        $facts[] = (string)$r['text'];
    }
    return array_values(array_filter(array_map('trim', $facts), fn($f) => $f !== ''));
}

/** Numbers in a text, normalised (no commas, no leading zeros) so "5,000" and "5000" match. */
function pm_sx_numbers(string $t): array
{
    $t = preg_replace('#https?://\S+|\#\w+#u', ' ', $t);
    preg_match_all('/\d+(?:[.,]\d+)*/', $t, $m);
    $out = [];
    foreach ($m[0] as $x) {
        $x = str_replace(',', '', rtrim($x, '.,'));
        $out[] = ltrim($x, '0') === '' ? '0' : ltrim($x, '0');
    }
    return $out;
}

function pm_sx_emoji_count(string $t): int
{
    return (int)preg_match_all('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}\x{2B06}\x{2300}-\x{23FF}]/u', $t);
}

/** First six lowercase words of a caption, for the "starts the same as another post" check. */
function pm_sx_opener(string $caption): string
{
    $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($caption), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return count($w) >= 4 ? implode(' ', array_slice($w, 0, 6)) : '';
}

/**
 * Checks a post for a channel. Returns [['sev' => 'block'|'warn', 'msg' => ''], ...]; empty = fine.
 * $pool (optional) is the list of other posts to compare openers with; default is the posts file.
 */
function pm_lint_ext(array $p, string $channel = 'facebook', ?array $pool = null): array
{
    $out = [];
    $block = function (string $m) use (&$out) { $out[] = ['sev' => 'block', 'msg' => $m]; };
    $warn = function (string $m) use (&$out) { $out[] = ['sev' => 'warn', 'msg' => $m]; };
    $brand = ($p['brand'] ?? 'promanaged') === 'travel' ? 'travel' : 'promanaged';
    $today = date('Y-m-d');
    $texts = pm_sx_post_texts($p);
    $caption = $texts['caption'] ?? '';
    $all = implode("\n", $texts);
    $facts = pm_sx_post_facts($p);
    $factText = implode("\n", $facts);
    $bank = pm_bank_get($brand);
    $class = function_exists('pm_sx_pillar_class') ? pm_sx_pillar_class((string)($p['pillar'] ?? '')) : '';
    $seeded = !empty($p['seed']) || !empty($p['facts']) || !empty($p['offline']);
    $gv = !empty($p['giveaway']) && $caption !== '' && $caption === trim(pm_giveaway_text($brand, (array)$p['giveaway'])); // the one compliant giveaway wording

    // 1. a link in the first 110 characters hides the hook
    if (preg_match('#https?://|www\.|wa\.me|\b[a-z0-9-]+\.(com|org|net|mw|co|io|app|ai)\b#i', mb_substr($caption, 0, 110))) {
        $block('There is a link in the first 110 characters. Put the hook first; the link goes at the end.');
    }
    // 2. opening
    $first = mb_strtolower(trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $caption)));
    if ($first !== '' && (str_starts_with($first, 'hello') || array_filter(pm_sx_brand_names($brand), fn($n) => str_starts_with($first, mb_strtolower($n))))) {
        $block('Starts with "Hello" or the business name. Start with what the reader gets or a question.');
    }
    if (!$gv) {
        // 3. claims
        $claims = ['best' => '/(?<!do your )\bbest\b(?!\s+practices?)/i', 'cheapest' => '/\bcheapest\b/i', 'number one' => '/\bnumber (?:one|1)\b|(?<![\w])#1(?!\w)/i', 'fastest' => '/\bfastest\b/i',
            'leading' => '/\bleading\b(?!\s+(?:to|up))/i', '100%' => '/100\s?%/', 'risk-free' => '/\brisk[- ]free\b/i', '24/7' => '/\b24\s?\/\s?7\b|\b24 hours a day\b/i',
            'a response time' => '/\bwithin (?:the next )?(?:\d+|one|two|three|a few|an?) (?:minutes?|hours?|days?|weeks?)\b/i', 'a guarantee' => '/\bguarantee[sd]?\b/i'];
        foreach ($claims as $name => $re) {
            if (preg_match_all($re, $all, $m)) {
                foreach ($m[0] as $hit) {
                    if (stripos($factText, $hit) === false) {
                        $block("Claims \"$hit\" ($name), which is not in the facts for this post.");
                        break;
                    }
                }
            }
        }
        // 4. numbers must come from the facts (list numbering is allowed)
        if ($seeded) {
            $okN = array_merge(pm_sx_numbers($factText), pm_sx_numbers((string)($p['hook_pattern'] ?? '')));
            $slides = count((array)($p['slides'] ?? []));
            $okN = array_merge($okN, array_map('strval', range(1, max(1, $slides + 1))));
            if (!empty($p['proof_id']) && function_exists('pm_social_proof_find') && ($r = pm_social_proof_find($brand, (string)$p['proof_id']))) {
                $okN = array_merge($okN, pm_sx_numbers((string)$r['text']));
            }
            if (!empty($p['expires'])) { // an offer or occasion date the owner set
                $okN = array_merge($okN, pm_sx_numbers(date('j F Y', strtotime((string)$p['expires']))));
            }
            $bad = [];
            foreach (pm_sx_numbers($all) as $x) {
                if (!in_array($x, $okN, true)) {
                    $bad[$x] = true;
                }
            }
            if ($bad) {
                $block('The number ' . implode(', ', array_keys($bad)) . ' is not in the facts for this post. Take it out, or add it to the Marketing brain or a content idea first.');
            }
        }
        // 5. free / no commission
        if (preg_match_all('/\bfree\b(?!\s+(?:up|of)\b)|\bno (?:listing )?(?:commission|fees?)\b|\bcommission[- ]free\b|\bzero commission\b/i', $all, $m)) {
            if ($brand === 'travel' && pm_sx_charging('travel')) {
                $block('Says "' . $m[0][0] . '" but Travel Malawi is marked as charging (Settings). Take it out.');
            } else {
                foreach ($m[0] as $hit) {
                    $re = preg_match('/free/i', $hit) && !preg_match('/commission|fee/i', $hit) ? '/\bfree\b/i' : '/commission|\bno (listing )?fees?\b/i';
                    if (!preg_match($re, $factText)) {
                        $block("Says \"$hit\" but the facts for this post do not say so.");
                        break;
                    }
                }
            }
        }
        // 6. time words need an end date the owner set
        if (empty($p['expires']) && preg_match('/\b(currently|now|limited|this week only)\b/i', $all, $m)) {
            $block('Uses "' . $m[1] . '" without an end date. Give the post an end date, or take the word out.');
        }
        // 7. giveaway bait
        foreach (preg_split('/(?<=[.!?\n])\s+/u', $all) ?: [] as $sent) {
            if (preg_match('/\b(win|enter|giveaway|prize|competition|chance)\b/i', $sent) && preg_match('/\b(share|tag|repost)\b|\blike\b.{0,25}\b(and|&|\+)\b.{0,12}\bshare\b/i', $sent)) {
                $block('Giveaway bait ("' . mb_substr(trim($sent), 0, 50) . '"): Facebook does not allow share or tag to enter. Use the Giveaway template in the Content tab.');
                break;
            }
        }
    }
    // 8. the owner's banned words
    foreach ((array)$bank['banned'] as $w) {
        $w = trim((string)$w);
        if ($w !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/iu', $all)) {
            $block("Contains \"$w\", one of your banned words.");
        }
    }
    // 9. naming a business needs consent
    $consented = [];
    $names = [];
    foreach (function_exists('pm_social_proof_all') ? pm_social_proof_all($brand) : [] as $r) {
        $n = trim((string)($r['client_name'] ?? ''));
        if (mb_strlen($n) >= 3) {
            $names[mb_strtolower($n)] = $n;
            if (!empty($r['consent']) && (($r['expires'] ?? '') === '' || $r['expires'] >= $today)) {
                $consented[mb_strtolower($n)] = true;
            }
        }
    }
    foreach (pm_sx_assets_all() as $a) {
        $n = trim((string)($a['owner'] ?? ''));
        if (($a['brand'] ?? '') === $brand && mb_strlen($n) >= 3) {
            $names[mb_strtolower($n)] = $n;
            if (!empty($a['consent']) && (($a['expires'] ?? '') === '' || $a['expires'] >= $today)) {
                $consented[mb_strtolower($n)] = true;
            }
        }
    }
    foreach ($names as $k => $n) {
        if (empty($consented[$k]) && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($n, '/') . '(?![\p{L}\p{N}])/iu', $all)) {
            $block("Names \"$n\" but there is no consent on file for them (Accounts > proof bank, or the photo library).");
        }
    }
    if ($class === 'spotlight') {
        $okProof = !empty($p['proof_id']) && function_exists('pm_social_proof_find') && pm_social_proof_find($brand, (string)$p['proof_id']);
        $a = !empty($p['asset_id']) ? pm_social_asset_find((string)$p['asset_id']) : null;
        $okAsset = $a && !empty($a['consent']) && (($a['expires'] ?? '') === '' || $a['expires'] >= $today);
        if (!$okProof && !$okAsset) {
            $block('A Stay spotlight needs the host\'s consent: a consented proof item or a consented photo.');
        }
    }
    // 10. places
    $verified = [];
    foreach ((array)$bank['places'] as $pl) {
        $n = trim((string)($pl['place'] ?? ''));
        if ($n === '') {
            continue;
        }
        if (!empty($pl['verified'])) {
            $verified[mb_strtolower($n)] = true;
        } elseif (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($n, '/') . '(?![\p{L}\p{N}])/iu', $all)) {
            $block("Mentions \"$n\", which is not marked as verified in Destination and place facts.");
        }
    }
    if ($class === 'destination') {
        $kind = (string)($p['seed_kind'] ?? '');
        $named = false;
        foreach (array_keys($verified) as $vn) {
            $named = $named || preg_match('/(?<![\p{L}\p{N}])' . preg_quote($vn, '/') . '(?![\p{L}\p{N}])/iu', $all);
        }
        if (!($kind === 'season' || ($kind === 'place' && !empty($verified)) || $named)) {
            $block('A Destination post needs a verified place (Content tab > Destination and place facts).');
        }
    }
    // 11. length and tags
    $words = count(preg_split('/\s+/u', trim(preg_replace('/#\S+/u', '', $caption)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $max = $channel === 'linkedin' ? 120 : 90;
    if ($words > $max && !$gv) {
        $block("The caption has $words words; keep it to $max or fewer.");
    }
    $tags = count(array_unique(array_merge(array_map('strtolower', (array)($p['hashtags'] ?? [])), array_map('strtolower', preg_match_all('/#[\p{L}\p{N}_]+/u', $caption, $mm) ? $mm[0] : []))));
    $cap = ['facebook' => 3, 'instagram' => 5, 'linkedin' => 3, 'x' => 2, 'tiktok' => 5, 'youtube_short' => 3][$channel] ?? 99;
    if ($tags > $cap) {
        $block("$tags hashtags; $channel works best with $cap or fewer.");
    }
    // 12. same opening as another post in the last 14 days
    $op = pm_sx_opener($caption);
    if ($op !== '') {
        $pool ??= function_exists('pm_social_posts') ? pm_social_posts() : [];
        $when = strtotime((string)($p['when'] ?? '')) ?: time();
        foreach ($pool as $q) {
            if (($q['id'] ?? '') === ($p['id'] ?? '') || ($q['brand'] ?? 'promanaged') !== $brand || in_array($q['status'] ?? '', ['failed', 'expired'], true)) {
                continue;
            }
            $qw = strtotime((string)($q['when'] ?? ''));
            if ($qw && abs($qw - $when) <= 14 * 86400 && pm_sx_opener((string)($q['caption'] ?? '')) === $op) {
                $block('Starts the same way as another post within 14 days ("' . mb_substr(trim((string)$q['caption']), 0, 40) . '..."). Change the first line.');
                break;
            }
        }
    }
    // warnings
    $letters = preg_match_all('/\p{L}/u', $caption);
    if ($letters >= 20 && preg_match_all('/\p{Lu}/u', $caption) / $letters > 0.25) {
        $warn('A lot of CAPITAL LETTERS reads like shouting.');
    }
    if (pm_sx_emoji_count($all) > 3) {
        $warn('More than 3 emoji.');
    }
    if (substr_count($all, '!') > 2) {
        $warn('More than 2 exclamation marks.');
    }
    $lim = ['facebook' => 63206, 'instagram' => 2200, 'linkedin' => 3000, 'x' => 280, 'tiktok' => 2200, 'google' => 1500, 'whatsapp_status' => 700, 'whatsapp_channel' => 4096][$channel] ?? 0;
    if ($lim && mb_strlen($caption) > $lim) {
        $warn(mb_strlen($caption) . " characters is over the $lim $channel allows.");
    }
    return $out;
}
