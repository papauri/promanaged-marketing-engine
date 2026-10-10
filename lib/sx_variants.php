<?php
/**
 * Channel variants: one approved idea -> correctly shaped text for every channel. Pure PHP, zero tokens.
 * One link per post. Facebook/LinkedIn/X/Google/WhatsApp Channel carry at most one; Instagram, WhatsApp Status, TikTok, Shorts none.
 * Also owns data/social_channels.json (pm_channels_cfg): ticks, groups, catalogue, review link, Status log, founder voice.
 */

const PM_CH_LIST = ['facebook', 'instagram', 'linkedin', 'x', 'whatsapp_status', 'whatsapp_channel', 'tiktok', 'youtube_short', 'google'];
const PM_CH_LABEL = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'linkedin_personal' => 'LinkedIn (your own profile)', 'x' => 'X', 'whatsapp_status' => 'WhatsApp Status',
    'whatsapp_channel' => 'WhatsApp Channel', 'tiktok' => 'TikTok', 'youtube_short' => 'YouTube Shorts', 'google' => 'Google Business'];
const PM_CH_SIZE = ['facebook' => 'sq', 'instagram' => '4x5', 'linkedin' => 'link', 'linkedin_personal' => 'link', 'x' => 'link', 'whatsapp_status' => 'story', 'whatsapp_channel' => 'sq',
    'tiktok' => 'story', 'youtube_short' => 'story', 'google' => 'gbp'];
const PM_CH_LIMIT = ['facebook' => 2000, 'instagram' => 2200, 'linkedin' => 3000, 'linkedin_personal' => 3000, 'x' => 280, 'whatsapp_status' => 700, 'whatsapp_channel' => 1000,
    'tiktok' => 2200, 'youtube_short' => 100, 'google' => 1500];
const PM_CH_UTM = ['facebook' => 'facebook', 'instagram' => 'instagram', 'linkedin' => 'linkedin', 'linkedin_personal' => 'linkedin', 'x' => 'x', 'whatsapp_status' => 'whatsapp',
    'whatsapp_channel' => 'whatsapp', 'tiktok' => 'tiktok', 'youtube_short' => 'youtube', 'google' => 'google'];
const PM_CH_SRC = ['facebook' => 'fb', 'instagram' => 'ig', 'linkedin' => 'li', 'linkedin_personal' => 'li', 'x' => 'x', 'whatsapp_status' => 'wa', 'whatsapp_channel' => 'wa',
    'tiktok' => 'tt', 'youtube_short' => 'yt', 'google' => 'g'];

/* ---------------- data/social_channels.json ---------------- */

function pm_ch_brand(string $b): string { return pm_brand_norm($b); }

function pm_ch_defaults(): array
{
    return ['ticks' => [], 'google_review_url' => '', 'groups' => [], 'status_done' => [], 'catalogue' => [], 'founder_voice' => false];
}

/** The brand's channel settings, with defaults. */
function pm_channels_cfg(string $brand): array
{
    $all = pm_load('social_channels', fn() => []);
    return array_replace(pm_ch_defaults(), (array)($all[pm_ch_brand($brand)] ?? []));
}

/** Locked change of one brand's channel settings. $fn(array $cfg): array. */
function pm_channels_update(string $brand, callable $fn): array
{
    $b = pm_ch_brand($brand);
    $out = [];
    pm_update('social_channels', function (array $all) use ($b, $fn, &$out) {
        $out = $fn(array_replace(pm_ch_defaults(), (array)($all[$b] ?? [])));
        $all[$b] = $out;
        return $all;
    }, fn() => []);
    return $out;
}

/* ---------------- small text helpers ---------------- */

function pm_ch_cut(string $t, int $max, string $end = '…'): string
{
    $t = trim($t);
    if (mb_strlen($t) <= $max) {
        return $t;
    }
    $c = mb_substr($t, 0, max(1, $max - mb_strlen($end)));
    $sp = mb_strrpos($c, ' ');
    if ($sp !== false && $sp > $max * 0.5) {
        $c = mb_substr($c, 0, $sp);
    }
    return rtrim($c, " \t\n,.;:-") . $end;
}

function pm_ch_sentences(string $t): array
{
    return preg_split('/(?<=[.!?])\s+/u', trim(preg_replace('/\s*\n\s*/', ' ', $t)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

const PM_CH_LINK_RE = '~https?://\S+|\bwa\.me/\S+|\bwww\.\S+~i';

function pm_ch_links_in(string $t): array
{
    preg_match_all(PM_CH_LINK_RE, $t, $m);
    return $m[0];
}

/** Removes links; a line that was only a link lead-in ("Message us on WhatsApp:") goes too. */
function pm_ch_strip_links(string $t): string
{
    $out = [];
    foreach (preg_split('/\R/', $t) as $line) {
        if (preg_match(PM_CH_LINK_RE, $line)) {
            $line = trim(preg_replace(PM_CH_LINK_RE, '', $line));
            if (mb_strlen($line) < 14 || preg_match('/[:\-]\s*$/', $line)) {
                continue;
            }
        }
        $out[] = rtrim($line);
    }
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
}

function pm_ch_strip_tags(string $t): string
{
    return trim(preg_replace('/(?<!\S)#[\p{L}\p{N}_]+/u', '', $t));
}

function pm_ch_slug(string $s): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

function pm_ch_ref(array $p): string
{
    if (function_exists('pm_post_ref')) {
        return (string)pm_post_ref($p);
    }
    return str_pad(substr(strtoupper(base_convert(sprintf('%u', crc32((string)($p['id'] ?? ''))), 10, 36)), 0, 4), 4, '0');
}

/** X counts every link as 23 characters. */
function pm_ch_x_len(string $t): int
{
    return mb_strlen(preg_replace('~https?://\S+~i', str_repeat('x', 23), $t));
}

function pm_ch_headline(array $p): string
{
    $h = trim((string)($p['headline'] ?? ''));
    return $h !== '' ? $h : pm_ch_cut(trim((string)($p['caption'] ?? '')), 40, '');
}

/** The picture description for blind and low-vision readers: p['alt'], else headline + subline. */
function pm_variant_alt(array $p): string
{
    $a = trim((string)($p['alt'] ?? ''));
    return $a !== '' ? $a : trim(trim((string)($p['headline'] ?? '')) . ' ' . trim((string)($p['sub'] ?? '')));
}

/** First sentence of the caption (the hook). */
function pm_ch_hook(array $p, int $max = 140): string
{
    $v = trim((string)($p['video']['hook_2s'] ?? ''));
    $s = pm_ch_sentences(pm_ch_strip_tags(pm_ch_strip_links((string)($p['caption'] ?? ''))));
    $h = $v !== '' ? $v : ($s[0] ?? '');
    $head = trim((string)($p['headline'] ?? ''));
    if (($h === '' || mb_strlen($h) > $max) && $head !== '') {
        return pm_ch_cut($head, $max); // a long first sentence: the picture headline is the shorter hook
    }
    return pm_ch_cut($h, $max);
}

/** Clean hashtags for a channel: pm_social_hashtags when content-engine is there, else the post's own. */
function pm_ch_tags(array $p, string $ch, int $max): array
{
    $src = ($ch === 'linkedin_personal') ? 'linkedin' : ($ch === 'youtube_short' ? 'tiktok' : $ch);
    $t = function_exists('pm_social_hashtags') ? (array)pm_social_hashtags($p, $src) : [];
    if (!$t) {
        $t = (array)($p['hashtags'] ?? []);
    }
    $out = [];
    foreach ($t as $h) {
        $h = '#' . ltrim(preg_replace('/[^\p{L}\p{N}_]/u', '', (string)$h), '#');
        if (mb_strlen($h) > 1 && !in_array(strtolower($h), array_map('strtolower', $out), true)) {
            $out[] = $h;
        }
    }
    return array_slice($out, 0, $max);
}

/* ---------------- links and calls to action ---------------- */

function pm_ch_phone(): string
{
    return trim((string)(pm_settings()['phone'] ?? ''));
}

/** Effective call to action: whatsapp | check | host | comment | save | share | tag. check is ProManaged's, host is Travel Malawi's. */
function pm_ch_cta(array $p): string
{
    $c = (string)($p['cta'] ?? '');
    $travel = ($p['brand'] ?? '') === 'travel';
    if ($c === 'offer' && !empty($p['lead_offer'])) {
        return 'check'; // a lead post behaves like the enquiry-link call to action everywhere (see pm_ch_enquire and pm_ch_cta_line)
    }
    return match (true) {
        $c === 'check', $c === 'host' => ($travel ? 'host' : ($c === 'check' && !pm_brand_is_custom((string)($p['brand'] ?? '')) ? 'check' : 'whatsapp')),
        in_array($c, ['comment', 'save', 'share', 'tag'], true) => $c,
        default => 'whatsapp',
    };
}

/** wa.me link whose prefilled message names the post and its code, so the enquiry can be traced. '' when no usable number. */
function pm_ch_wa(array $p): string
{
    $phone = pm_ch_phone();
    if ($phone === '' || !function_exists('pm_wa_link')) {
        return '';
    }
    return pm_wa_link(['whatsapp' => $phone, 'phone' => $phone], 'Hi, I saw your post "' . pm_ch_headline($p) . '" (ref ' . pm_ch_ref($p) . ')');
}

/** Website link tagged with where it was seen. '' when the website link is off. */
function pm_ch_site(array $p, string $ch): string
{
    $lk = pm_link_cfg((string)$p['brand']);
    if (!$lk['on']) {
        return '';
    }
    $u = $lk['url'];
    if (str_contains($u, 'utm_source=')) {
        return $u;
    }
    $frag = '';
    if (($i = strpos($u, '#')) !== false) {
        $frag = substr($u, $i);
        $u = substr($u, 0, $i);
    }
    $content = pm_ch_slug(($p['pillar'] ?? '') . '-' . ($p['format'] ?? 'image') . '-' . (($p['cta'] ?? '') ?: 'whatsapp'));
    return $u . (str_contains($u, '?') ? '&' : '?') . http_build_query(['utm_source' => PM_CH_UTM[$ch] ?? $ch, 'utm_medium' => 'social', 'utm_campaign' => pm_ch_ref($p), 'utm_content' => $content]) . $frag;
}

function pm_ch_enquire(array $p, string $mode, string $ch): string
{
    if (!empty($p['lead_offer']) && function_exists('pm_lp_url')) { // a lead post points at its own offer page
        return pm_lp_url((string)($p['brand'] ?? 'promanaged'), (string)$p['lead_offer'], (PM_CH_SRC[$ch] ?? 'x') . '-' . pm_ch_ref($p));
    }
    return function_exists('pm_enquire_url') ? (string)pm_enquire_url((string)$p['brand'], $mode, (PM_CH_SRC[$ch] ?? 'x') . '-' . pm_ch_ref($p)) : '';
}

/** "Send CHECK on WhatsApp ..." (only useful when a number is set). */
function pm_ch_keyword_line(array $p, string $cta): string
{
    if (pm_ch_phone() === '') {
        return '';
    }
    if (function_exists('pm_magnet_keyword_line')) {
        $l = trim((string)pm_magnet_keyword_line((string)$p['brand']));
        if ($l !== '') {
            return $l;
        }
    }
    if ($cta === 'check') {
        return 'Send CHECK on WhatsApp and we will look at your website for free.';
    }
    $paying = !empty((pm_settings()['travel'] ?? [])['charging']);
    return 'Send HOST on WhatsApp to list your stay' . ($paying ? '.' : ' free.');
}

/**
 * The single call-to-action line for a channel with a link: ['line' => text, 'link' => the one link in it or ''].
 * check/host: the enquiry page (else the WhatsApp keyword). whatsapp: wa.me (Facebook, WhatsApp Channel). Otherwise the website, when on.
 */
function pm_ch_cta_line(array $p, string $ch, bool $allowWa): array
{
    $cta = pm_ch_cta($p);
    $site = pm_ch_site($p, $ch);
    if (!empty($p['lead_offer']) && function_exists('pm_lp_cta_line') && ($lp = pm_lp_cta_line($p, $ch, $allowWa)) !== null && $lp['line'] !== '') {
        return $lp;
    }
    if ($cta === 'check' || $cta === 'host') {
        $u = pm_ch_enquire($p, $cta, $ch);
        if ($u !== '') {
            return ['line' => ($cta === 'check' ? 'Get a free website check: ' : 'List your stay with us: ') . $u, 'link' => $u];
        }
        $kw = pm_ch_keyword_line($p, $cta);
        if ($kw !== '') {
            return ['line' => $kw . ($site !== '' ? "\n" . $site : ''), 'link' => $site];
        }
        return $site !== '' ? ['line' => $site, 'link' => $site] : ['line' => '', 'link' => ''];
    }
    if ($cta === 'whatsapp' && $allowWa) {
        $wa = pm_ch_wa($p);
        if ($wa !== '') {
            return ['line' => 'Message us on WhatsApp: ' . $wa, 'link' => $wa];
        }
    }
    if ($site !== '') {
        return ['line' => $site, 'link' => $site];
    }
    return ['line' => '', 'link' => ''];
}

/** A soft ask for save/share/tag when the caption does not already make it. */
function pm_ch_soft_line(array $p): string
{
    $cta = pm_ch_cta($p);
    $cap = strtolower((string)($p['caption'] ?? ''));
    return match (true) {
        $cta === 'save' && !str_contains($cap, 'save') => 'Save this for later.',
        $cta === 'share' && !str_contains($cap, 'share') => 'Share it with someone who needs it.',
        $cta === 'tag' && !str_contains($cap, 'tag') => 'Tag someone who should see this.',
        default => '',
    };
}

/**
 * First comment on the Facebook post: the website link, but only when the caption's link is the WhatsApp one (so the caption keeps one link)
 * and the website link is on. A text the planner supplied in p['first_comment'] wins. '' = no first comment.
 */
function pm_variant_first_comment(array $p): string
{
    $own = trim((string)($p['first_comment'] ?? ''));
    if ($own !== '') {
        return $own;
    }
    $prev = pm_brand();
    pm_brand_set((string)($p['brand'] ?? 'promanaged'));
    try {
        if (pm_ch_cta($p) !== 'whatsapp' || pm_ch_wa($p) === '') {
            return '';
        }
        $u = pm_ch_site($p, 'facebook');
        return $u === '' ? '' : 'More on our website: ' . $u;
    } finally {
        pm_brand_set($prev);
    }
}

/* ---------------- the variants ---------------- */

function pm_variant_caption(array $p, string $ch): string
{
    return pm_var_build($p, $ch)['text'];
}

/** All channels: ch => ['text','chars','limit','warn','size','mode','label', parts?, open?]. LinkedIn personal appears when founder voice is on. */
function pm_social_variants(array $p): array
{
    $out = [];
    foreach (PM_CH_LIST as $ch) {
        $out[$ch] = pm_var_build($p, $ch);
    }
    if (!empty(pm_channels_cfg((string)($p['brand'] ?? 'promanaged'))['founder_voice'])) {
        $out['linkedin_personal'] = pm_var_build($p, 'linkedin_personal');
    }
    return $out;
}

/** auto = the app posts it by itself; manual = copy and post by hand. */
function pm_ch_mode(string $brand, string $ch): string
{
    if (!function_exists('pm_social_cfg')) {
        return 'manual';
    }
    $c = pm_social_cfg($brand);
    $on = function_exists('pm_social_settings') ? (array)pm_social_settings($brand)['channels'] : [];
    return match ($ch) {
        'facebook' => $c['ready'] && !empty($on['facebook']) ? 'auto' : 'manual',
        'instagram' => $c['ig_id'] !== '' && $c['token'] !== '' && pm_app_url() !== '' && !empty($on['instagram']) ? 'auto' : 'manual',
        'linkedin' => function_exists('pm_linkedin_cfg') && pm_linkedin_cfg($brand)['ready'] && !empty($on['linkedin']) ? 'auto' : 'manual',
        default => 'manual',
    };
}

function pm_var_build(array $p, string $ch): array
{
    $brand = pm_ch_brand((string)($p['brand'] ?? 'promanaged'));
    $p['brand'] = $brand;
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        $v = pm_var_inner($p, $ch, $brand);
    } finally {
        pm_brand_set($prev);
    }
    $v += ['parts' => [], 'warn' => [], 'open' => ''];
    $v['text'] = trim($v['text']);
    $v['chars'] = $v['chars'] ?? mb_strlen($v['text']);
    $v['limit'] = PM_CH_LIMIT[$ch] ?? 2000;
    $v['size'] = PM_CH_SIZE[$ch] ?? 'sq';
    $v['mode'] = pm_ch_mode($brand, $ch);
    $v['label'] = PM_CH_LABEL[$ch] ?? $ch;
    if ($v['chars'] > $v['limit']) {
        $v['warn'][] = "{$v['chars']} characters; {$v['label']} allows {$v['limit']}.";
    }
    if (function_exists('pm_lint_ext')) {
        try {
            foreach ((array)pm_lint_ext($p, $ch === 'linkedin_personal' ? 'linkedin' : $ch) as $m) {
                $msg = (string)($m['msg'] ?? '');
                if (preg_match('/hashtag|characters is over|over the \d+/i', $msg)) {
                    continue; // lint counts the post's own tags and caption; this variant sets its own tags and length (checked above)
                }
                $v['warn'][] = (($m['sev'] ?? '') === 'block' ? 'Blocked: ' : '') . $msg;
            }
        } catch (Throwable) {
        }
    }
    $v['warn'] = array_values(array_unique(array_filter($v['warn'])));
    return $v;
}

function pm_var_inner(array $p, string $ch, string $brand): array
{
    $body = trim((string)($p['caption'] ?? ''));
    $plain = pm_ch_strip_tags(pm_ch_strip_links($body)); // no links, no inline hashtags
    $warn = [];
    switch ($ch) {
        case 'facebook':
            $t = pm_ch_strip_tags($body);
            $cl = ['line' => '', 'link' => ''];
            if (!pm_ch_links_in($t)) { // an old caption that already carries its link keeps it, and gets no second one
                $cl = pm_ch_cta_line($p, 'facebook', true);
                $soft = pm_ch_soft_line($p);
                $t .= ($soft !== '' ? "\n\n" . $soft : '') . ($cl['line'] !== '' ? "\n\n" . $cl['line'] : '');
            }
            $tags = pm_ch_tags($p, 'facebook', 3);
            $t .= $tags ? "\n\n" . implode(' ', $tags) : '';
            if (count(pm_ch_links_in($t)) > 1) {
                $warn[] = 'More than one link in the caption.';
            }
            return ['text' => $t, 'warn' => $warn];

        case 'instagram':
            $t = $plain;
            $first = strtok($t, "\n");
            if (mb_strlen((string)$first) > 125) {
                $warn[] = 'The first line is ' . mb_strlen((string)$first) . ' characters. Instagram shows about 125 before "more": start with the hook.';
            }
            $phone = pm_ch_phone();
            if ($phone !== '') {
                $t .= "\n\nWhatsApp us and say " . pm_ch_ref($p) . ': ' . $phone;
            }
            $soft = pm_ch_soft_line($p);
            $t .= $soft !== '' ? "\n\n" . $soft : '';
            $tags = pm_ch_tags($p, 'instagram', 5);
            if ($tags && count($tags) < 3) {
                $warn[] = 'Instagram does best with 3 to 5 hashtags.';
            }
            $t .= $tags ? "\n\n" . implode(' ', $tags) : '';
            if (pm_ch_phone() === '') {
                $warn[] = 'No WhatsApp number is set, so the caption has no way to enquire.';
            }
            return ['text' => $t, 'warn' => $warn, 'open' => 'https://www.instagram.com/'];

        case 'linkedin':
        case 'linkedin_personal':
            $t = $plain; // insight-led: the idea, no wa.me, no hashtags inline
            $first = strtok($t, "\n");
            if (mb_strlen((string)$first) > 140) {
                $ss = pm_ch_sentences((string)$first);
                if ($ss && mb_strlen($ss[0]) <= 140) {
                    $t = $ss[0] . "\n\n" . trim(mb_substr($t, mb_strlen($ss[0])));
                } elseif (mb_strlen(trim((string)($p['headline'] ?? ''))) > 0 && mb_strlen(trim((string)$p['headline'])) <= 140) {
                    $t = trim((string)$p['headline']) . "\n\n" . $t;
                } else {
                    $warn[] = 'The first line is longer than 140 characters; LinkedIn cuts it at "see more". Start with a short hook.';
                }
            }
            $cta = pm_ch_cta($p);
            $link = ($cta === 'check' || $cta === 'host') ? pm_ch_enquire($p, $cta, 'linkedin') : '';
            $link = $link !== '' ? $link : pm_ch_site($p, 'linkedin');
            if ($ch === 'linkedin_personal') { // first-person wrapper (deterministic): a closing line and a signature, to post from your own profile
                $who = trim((string)(pm_settings()['signatory_name'] ?? ''));
                $co = (string)(pm_settings()['company_name'] ?? '');
                $t .= "\n\nIf this sounds like you, reply here or message me.\n" . ($who !== '' ? "- $who, $co" : "- $co");
            }
            $t .= $link !== '' ? "\n\n" . $link : '';
            $tags = pm_ch_tags($p, 'linkedin', 3);
            $t .= $tags ? "\n\n" . implode(' ', $tags) : '';
            return ['text' => $t, 'warn' => $warn, 'open' => 'https://www.linkedin.com/feed/'];

        case 'x':
            $cta = pm_ch_cta($p);
            $link = ($cta === 'check' || $cta === 'host') ? pm_ch_enquire($p, $cta, 'x') : '';
            $link = $link !== '' ? $link : pm_ch_site($p, 'x');
            $link = $link !== '' ? $link : (in_array($cta, ['whatsapp'], true) ? pm_ch_wa($p) : '');
            $tags = pm_ch_tags($p, 'x', 2);
            $tail = ($tags ? ' ' . implode(' ', $tags) : '') . ($link !== '' ? "\n" . $link : '');
            $room = 280 - pm_ch_x_len($tail) - 1;
            $hook = pm_ch_cut(pm_ch_hook($p, 260), max(20, $room));
            $t = $hook . $tail;
            $url = 'https://x.com/intent/post?text=' . rawurlencode($t);
            return ['text' => $t, 'chars' => pm_ch_x_len($t), 'warn' => $warn, 'open' => $url];

        case 'whatsapp_status':
            $hook = pm_ch_hook($p, 160);
            $second = trim((string)($p['sub'] ?? ''));
            if ($second === '' || stripos($hook, $second) !== false) {
                $ss = pm_ch_sentences($plain);
                $second = $ss[1] ?? '';
            }
            $lines = array_values(array_filter([$hook, pm_ch_cut($second, 200, ''), 'WhatsApp us and say ' . pm_ch_ref($p)]));
            return ['text' => pm_ch_cut(implode("\n", array_slice($lines, 0, 3)), 700), 'warn' => $warn, 'open' => 'https://web.whatsapp.com/'];

        case 'whatsapp_channel':
            $cta = pm_ch_cta($p);
            $link = ($cta === 'check' || $cta === 'host') ? pm_ch_enquire($p, $cta, 'whatsapp_channel') : '';
            $link = $link !== '' ? $link : pm_ch_site($p, 'whatsapp_channel');
            $link = $link !== '' ? $link : pm_ch_wa($p);
            $t = pm_ch_cut($plain, 900, '') . ($link !== '' ? "\n\n" . $link : '');
            return ['text' => $t, 'warn' => $warn, 'open' => 'https://web.whatsapp.com/'];

        case 'tiktok':
            $tags = pm_ch_tags($p, 'tiktok', 3);
            if (count($tags) < 3) {
                $warn[] = 'TikTok does best with 3 hashtags.';
            }
            $t = pm_ch_hook($p, 150) . ($tags ? "\n\n" . implode(' ', $tags) : '');
            if (($p['format'] ?? '') !== 'reel') {
                $warn[] = 'TikTok is video first: post this as a short slideshow or film the reel.';
            }
            return ['text' => $t, 'warn' => $warn, 'open' => 'https://www.tiktok.com/upload'];

        case 'youtube_short':
            $title = pm_ch_cut(pm_ch_hook($p, 90), 100 - mb_strlen(' #Shorts'), '') . ' #Shorts';
            $desc = pm_ch_cut($plain, 400, '');
            $tags = pm_ch_tags($p, 'youtube_short', 3);
            $ph = pm_ch_phone();
            $desc .= ($ph !== '' ? "\n\nWhatsApp us and say " . pm_ch_ref($p) . ': ' . $ph : '') . ($tags ? "\n\n" . implode(' ', $tags) : '');
            return ['text' => $title, 'chars' => mb_strlen($title), 'parts' => ['Title' => $title, 'Description' => trim($desc)], 'warn' => $warn, 'open' => 'https://studio.youtube.com/'];

        case 'google':
            $sent = pm_ch_sentences(pm_ch_strip_tags(pm_ch_strip_links($body)));
            $sent = array_values(array_filter($sent, fn($s) => !preg_match('/\+?\d[\d\s().-]{6,}\d|\bwhatsapp\b.*\b(on|at)\b\s*[:+\d]/i', $s))); // no phone numbers in a Google post
            $t = '';
            foreach ($sent as $i => $s) {
                if ($t !== '' && mb_strlen($t . ' ' . $s) > 300) {
                    break;
                }
                $t .= ($t === '' ? '' : ' ') . $s;
            }
            $t = pm_ch_cut($t, 1500, '');
            if (mb_strlen($t) < 150) {
                $warn[] = 'Aim for 150 to 300 characters: add a sentence that says who it helps.';
            }
            $cta = pm_ch_cta($p);
            $btn = $cta === 'whatsapp' ? 'Call' : (($brand === 'travel' && (($p['audience'] ?? '') === 'guest' || preg_match('/stay|destination/i', (string)($p['pillar'] ?? '')))) ? 'Book' : 'Learn more');
            $url = ($cta === 'check' || $cta === 'host') ? pm_ch_enquire($p, $cta, 'google') : '';
            $url = $url !== '' ? $url : pm_ch_site($p, 'google');
            if ($btn !== 'Call' && $url === '') {
                $warn[] = 'No link for the button: turn the website link on in Settings.';
            }
            return ['text' => $t, 'parts' => ['Button' => $btn] + ($url !== '' ? ['Button link' => $url] : []), 'warn' => $warn, 'open' => 'https://business.google.com/'];
    }
    return ['text' => $plain, 'warn' => ['Unknown channel.']];
}

/* ---------------- Plan card panel: Channels ---------------- */

/** Rendering is cheap; the card pictures are only linked (core serves ?simg=<id>&size=<key>&dl=1). */
function pm_panel_plan_card_variants(string $vb, array $ctx): string
{
    $p = $ctx['post'] ?? null;
    if (!is_array($p) || !in_array((string)($p['status'] ?? ''), ['draft', 'approved', 'review', 'needs_edit', 'needs_video', 'needs_asset', 'needs_check', 'publishing', 'failed', 'published'], true)) {
        return '';
    }
    $vs = pm_social_variants($p);
    $id = (string)($p['id'] ?? '');
    $hasPic = ($p['format'] ?? 'image') !== 'text';
    static $css = false;
    $h = '';
    if (!$css) {
        $css = true;
        $h .= '<style>.sx-var{margin-top:10px}.sx-var>summary{cursor:pointer;font-weight:600;font-size:13px}.sx-var .vch{margin:6px 0;padding:8px 10px}.sx-var .vch>summary{cursor:pointer;font-size:13px}'
            . '.sx-var pre{white-space:pre-wrap;word-break:break-word;font:13px/1.45 inherit;margin:8px 0;padding:8px 10px;background:var(--bg,#f6f7f8);border-radius:8px}.sx-var .vb{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0}'
            . '.sx-var .vw{color:var(--warn,#8a5a00);font-size:12px;margin:2px 0}</style>';
    }
    $h .= '<details class="sx-var"><summary>Channels (' . count($vs) . ')</summary>';
    foreach ($vs as $ch => $v) {
        $over = $v['chars'] > $v['limit'];
        $h .= '<details class="sx-card vch"><summary><b>' . pm_h($v['label']) . '</b> <span class="sx-chip ' . ($v['mode'] === 'auto' ? 'ok' : '') . '">' . ($v['mode'] === 'auto' ? 'posted by the app' : 'by hand') . '</span> '
            . '<span class="sx-chip ' . ($over ? 'bad' : ($v['warn'] ? 'warn' : 'ok')) . '">' . (int)$v['chars'] . '/' . (int)$v['limit'] . '</span></summary>';
        $h .= '<pre>' . pm_h($v['text']) . '</pre>';
        foreach ($v['warn'] as $w) {
            $h .= '<div class="vw">' . pm_h($w) . '</div>';
        }
        $h .= '<div class="vb"><button type="button" class="btn small" data-copy="' . pm_h($v['text']) . '">Copy text</button>';
        foreach ($v['parts'] as $lbl => $txt) {
            $h .= '<button type="button" class="btn small" data-copy="' . pm_h($txt) . '">Copy ' . pm_h(strtolower($lbl)) . '</button>';
        }
        if ($hasPic && ($p['format'] ?? '') !== 'reel') {
            $h .= '<a class="btn small" href="?simg=' . pm_h($id) . '&amp;size=' . pm_h($v['size']) . '&amp;dl=1">Download picture (' . pm_h($v['size']) . ')</a>';
        }
        if (($p['format'] ?? '') === 'carousel') {
            $h .= '<a class="btn small" href="?sslides=' . pm_h($id) . '">Download slides (zip)</a>';
        }
        if ($v['open'] !== '' && $v['mode'] !== 'auto') {
            $h .= '<a class="btn small" href="' . pm_h($v['open']) . '" target="_blank" rel="noopener noreferrer">Open ' . pm_h($v['label']) . '</a>';
        }
        $h .= '</div></details>';
    }
    $h .= '<div class="vb">';
    if ($hasPic && in_array((string)($p['format'] ?? 'image'), ['image', 'carousel'], true)) {
        $h .= '<form method="post" class="inl">' . pm_ch_form('make_story') . '<input type="hidden" name="id" value="' . pm_h($id) . '"><button class="btn small" title="Makes a 9:16 story draft from this post (no AI)">Make a Story</button></form>';
    }
    return $h . '</div></details>';
}

/** Hidden fields for a social_ext form. */
function pm_ch_form(string $do, string $view = 'channels'): string
{
    $csrf = (string)($GLOBALS['csrf'] ?? ($_SESSION['csrf'] ?? ''));
    return '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '">';
}
