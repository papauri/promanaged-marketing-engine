<?php
/**
 * Inbound replies without AI: intent templates (rotating, never a price) and the lint every public or private reply must pass.
 * Own state file: data/social_inbound.json {alerts, rotation, ads, hourly_replies, notes}.
 */

function pm_inb_get(): array { return pm_load('social_inbound', fn() => []); }
function pm_inb_update(callable $fn): array { return pm_update('social_inbound', fn(array $d) => $fn($d), fn() => []); }

/** Runs $fn with the brand switched, then puts the old brand back. */
function pm_inb_as(string $brand, callable $fn): mixed
{
    $prev = pm_brand();
    pm_brand_set($brand);
    try {
        return $fn();
    } finally {
        pm_brand_set($prev);
    }
}

function pm_inb_label(string $brand): string { return $brand === 'travel' ? 'Travel Malawi' : 'ProManaged IT'; }

/** The brand's WhatsApp number as digits ('' when none, or a landline). */
function pm_inb_wa_digits(string $brand): string
{
    return (string)pm_inb_as($brand, function () {
        [$n, $k] = pm_wa_number((string)(pm_settings()['phone'] ?? ''));
        return $k === 'landline' ? '' : $n;
    });
}

function pm_inb_wa_link(string $brand, string $text = ''): string
{
    $n = pm_inb_wa_digits($brand);
    return $n === '' ? '' : 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/* ---------------- intents ---------------- */

const PM_COMMENT_INTENTS = [
    'price' => '/\b(price|prices|pricing|how much|cost|costs|tariffs?|rates?|charges?|fees?|mtengo|zingati|ndi zingati|bei)\b/iu',
    'availability' => '/\b(availab\w*|book|booking|bookings|booked|reserve|reservation|rooms?|vacan\w*|tonight|this weekend|check.?in)\b/iu',
    'location' => '/\b(where|location|located|address|directions?|how do (i|we) get|ili kuti|ali kuti|muli kuti|pali kuti|kuli kuti)\b/iu',
    'interested' => '/\b(interested|inbox|dm|dm me|pm me|message me|send (me )?(the )?(details|info|more)|more info|ndikufuna|ndi interested|ndili ndi chidwi)\b/iu',
    'thanks' => '/^\W*(thanks|thank you|thank u|thx|zikomo( kwambiri)?)\b/iu',
];

const PM_REPLY_VARIANTS = [
    'promanaged' => [
        'price' => [
            '{hi}thanks for asking. We price each business individually, so we start by understanding what you need. {wa}',
            '{hi}good question. A short chat about your business lets us give you a clear quote. {wa}',
            '{hi}thank you for your interest. Prices depend on what you need, so the quickest way is a short message to us. {wa}',
            '{hi}happy to help with that. We quote after hearing about your business, so you only pay for what you need. {wa}',
        ],
        'interested' => [
            '{hi}thank you for your interest. Tell us a little about your business and we will take it from there. {wa}',
            '{hi}great to hear from you. Send us a message with what you need and a person will reply. {wa}',
            '{hi}thanks for reaching out. We would be glad to share the details. {wa}',
            '{hi}welcome. Message us about your business and we will show you how we can help. {wa}',
        ],
        'location' => [
            '{hi}thanks for asking. {loc}We work with businesses across Malawi. {wa}',
            '{hi}good question. {loc}Message us and we will share where to find us. {wa}',
            '{hi}thank you. {loc}Send us a message and we will give you the details. {wa}',
        ],
        'availability' => [
            '{hi}thanks for asking. Tell us what you need and we will find a time to talk. {wa}',
            '{hi}happy to help. Send us a message about your business and we will arrange a chat. {wa}',
            '{hi}thank you. Message us and we will agree a time that suits you. {wa}',
        ],
        'thanks' => [
            '{hi}thank you, we appreciate it.',
            '{hi}zikomo, we are glad you like it.',
            '{hi}thanks for the kind words.',
        ],
    ],
    'travel' => [
        'price' => [
            '{hi}thanks for asking. Rates depend on the stay and the dates, so tell us which stay and when. {wa}',
            '{hi}good question. Each stay sets its own rates, so send us the stay and dates you have in mind. {wa}',
            '{hi}thank you for your interest. Rates differ from stay to stay, so message us the details and we will point you the right way. {wa}',
            '{hi}happy to help. Tell us where you want to stay and for how many nights. {wa}',
        ],
        'interested' => [
            '{hi}thank you for your interest. Tell us what kind of stay you are looking for. {wa}',
            '{hi}great to hear from you. Message us the town and dates and we will help. {wa}',
            '{hi}thanks for reaching out. Send us a message and a person will reply. {wa}',
            '{hi}welcome. Tell us about your trip and we will help you find a stay. {wa}',
        ],
        'location' => [
            '{hi}thanks for asking. Tell us which stay you mean and we will share where it is. {wa}',
            '{hi}good question. Message us the name of the stay and we will send the location. {wa}',
            '{hi}thank you. Send us the stay you are asking about and we will help. {wa}',
        ],
        'availability' => [
            '{hi}thanks for asking. Send us the stay, the dates and how many people, and we will check. {wa}',
            '{hi}happy to help. Message us your dates and the number of guests. {wa}',
            '{hi}thank you. Tell us when you want to travel and for how many, and we will help. {wa}',
        ],
        'thanks' => [
            '{hi}thank you, we appreciate it.',
            '{hi}zikomo, we are glad you like it.',
            '{hi}thanks for the kind words.',
        ],
    ],
];

const PM_REPLY_PRIVATE_LINE = [
    'price' => 'Prices depend on what you need, so we like to talk first.',
    'availability' => 'Tell us the details and we will help.',
    'location' => 'Message us and we will share where to find us.',
    'interested' => 'We would be glad to share the details.',
    'thanks' => 'We appreciate it.',
    '' => 'How can we help?',
];

/** The first intent a text matches ('' when none). Order: price, availability, location, interested, thanks. */
function pm_reply_intent(string $text): string
{
    foreach (PM_COMMENT_INTENTS as $k => $re) {
        if (preg_match($re, $text)) {
            return $k;
        }
    }
    return '';
}

function pm_inb_first_name(string $name): string
{
    $f = trim(explode(' ', trim(preg_replace('/[^\p{L}\' -]/u', '', $name)))[0] ?? '');
    return $f === '' ? '' : mb_strtoupper(mb_substr($f, 0, 1)) . mb_substr($f, 1, 19);
}

/** The WhatsApp/location fill-ins shared by public and private templates. */
function pm_inb_fill(string $brand, string $tpl, string $first): string
{
    $wa = pm_inb_wa_link($brand);
    $addr = (string)pm_inb_as($brand, fn() => trim((string)(pm_settings()['address'] ?? '')));
    $loc = ($addr !== '' && strcasecmp($addr, 'Malawi') !== 0 && !preg_match('/\d{4,}/', $addr)) ? 'We are in ' . rtrim($addr, '. ') . '. ' : '';
    $out = strtr($tpl, ['{hi}' => $first !== '' ? "Hi $first, " : 'Hello, ', '{loc}' => $loc,
        '{wa}' => $wa !== '' ? 'WhatsApp us here: ' . $wa : 'Send us a private message and we will help.']);
    return trim(preg_replace('/\s{2,}/', ' ', $out));
}

/**
 * A ready reply for a visitor's words, no AI: ['intent','text'] or null when no template fits.
 * Rotates through 3-4 variants per brand and intent, so the same words are never used twice in a row. Never states a price.
 */
function pm_reply_template(string $brand, string $text, string $first = ''): ?array
{
    $brand = $brand === 'travel' ? 'travel' : 'promanaged';
    $intent = pm_reply_intent($text);
    $vars = PM_REPLY_VARIANTS[$brand][$intent] ?? null;
    if ($intent === '' || !$vars) {
        return null;
    }
    $idx = 0;
    pm_inb_update(function (array $d) use ($brand, $intent, $vars, &$idx) {
        $idx = ((int)($d['rotation'][$brand][$intent] ?? -1) + 1) % count($vars);
        $d['rotation'][$brand][$intent] = $idx;
        return $d;
    });
    return ['intent' => $intent, 'text' => pm_inb_fill($brand, $vars[$idx], pm_inb_first_name($first))];
}

/** Private (Messenger / Instagram DM) first line for a commenter: greeting, one answer line, WhatsApp link. No prices. */
function pm_reply_private_text(string $brand, string $text, string $first = ''): string
{
    $brand = $brand === 'travel' ? 'travel' : 'promanaged';
    $intent = pm_reply_intent($text);
    $line = PM_REPLY_PRIVATE_LINE[$intent] ?? PM_REPLY_PRIVATE_LINE[''];
    $wa = pm_inb_wa_link($brand);
    $f = pm_inb_first_name($first);
    return ($f !== '' ? "Hi $f, " : 'Hello, ') . 'thanks for your comment. ' . $line . ($wa !== '' ? ' WhatsApp us here: ' . $wa : ' Reply here and we will help.');
}

/* ---------------- lint ---------------- */

const PM_REPLY_NEVER = ['cheapest', 'lowest price', 'best price', 'number one', '#1', 'risk-free', 'risk free', 'no risk', 'get rich', 'act now', 'last chance'];

/**
 * Reasons a reply may not go out (empty = fine). mode: 'reply' (public comment or first DM: max 400 chars, one link),
 * 'dm' (a Messenger answer you typed: max 1000), 'post' (an edited Page post: no length or link-count limit, links must still be ours).
 */
function pm_reply_lint(string $text, string $brand, string $mode = 'reply'): array
{
    $t = trim($text);
    if ($t === '') {
        return ['Empty text.'];
    }
    $brand = $brand === 'travel' ? 'travel' : 'promanaged';
    return (array)pm_inb_as($brand, function () use ($t, $brand, $mode) {
        $why = [];
        $phone = substr(pm_inb_wa_digits($brand) ?: preg_replace('/\D/', '', (string)(pm_settings()['phone'] ?? '')), -9);
        // links
        $hosts = ['wa.me'];
        foreach ([function_exists('pm_brand_site') ? pm_brand_site($brand) : '', pm_app_url()] as $u) {
            $h = strtolower((string)parse_url(preg_match('#^[a-z]+://#i', $u) ? $u : 'http://' . $u, PHP_URL_HOST));
            if ($h !== '') {
                $hosts[] = preg_replace('/^www\./', '', $h);
            }
        }
        preg_match_all('#(?:https?://|www\.)[^\s<>"\']+|\b(?:wa\.me|[a-z0-9-]+\.(?:com|net|org|info|mw|co|io|me|ly|xyz|top|link|app|ai|site|online))(?:/[^\s<>"\']*)?(?![\w@])#iu', $t, $m);
        $urls = array_values(array_unique($m[0]));
        $rest = $t;
        foreach ($urls as $u) {
            $rest = str_replace($u, ' ', $rest);
            $h = strtolower((string)parse_url(preg_match('#^[a-z]+://#i', $u) ? $u : 'http://' . $u, PHP_URL_HOST));
            $h = preg_replace('/^www\./', '', $h);
            if (!in_array($h, $hosts, true)) {
                $why[] = 'Links to a site that is not ours (' . $h . ').';
            } elseif ($h === 'wa.me') {
                $d = preg_replace('/\D/', '', (string)parse_url('http://' . preg_replace('#^https?://#i', '', $u), PHP_URL_PATH));
                if ($phone === '' || substr($d, -9) !== $phone) {
                    $why[] = 'WhatsApp link is not our number.';
                }
            }
        }
        if (count($urls) > ($mode === 'post' ? 2 : 1)) {
            $why[] = 'More than one link.';
        }
        // phone numbers
        $noPhone = $rest;
        if (preg_match_all('/\+?\d[\d\s().\-]{5,}\d/', $rest, $pm)) {
            foreach ($pm[0] as $n) {
                $d = preg_replace('/\D/', '', $n);
                if (strlen($d) >= 7) {
                    if ($phone === '' || substr($d, -9) !== $phone) {
                        $why[] = 'Contains a phone number that is not ours.';
                    }
                    $noPhone = str_replace($n, ' ', $noPhone);
                }
            }
        }
        // money
        if (preg_match('/(?i:\b(MWK|USD|ZAR|ZMW|GBP|EUR|kwacha|dollars?)\b)|[$£€]\s?\d|(?<![A-Za-z])K\s?\d|\d\s?[Kk]\b|\b\d{1,3}(?:,\d{3})+\b|\b\d+\s*(?:per|a|\/)\s*night\b/u', $noPhone)) {
            $why[] = 'Contains a price or money amount.';
        }
        if (preg_match('/\b(guarantee[sd]?|guaranteed|promise[sd]?|100\s?%|assured)\b/iu', $t)) {
            $why[] = 'Promises or guarantees something.';
        }
        if (preg_match('/\b(within|in under|in less than|less than)\s+(\d+|an?|one|two|three|four|five|ten|fifteen|thirty|a few|few)\s*(seconds?|mins?|minutes?|hours?|hrs?|days?)\b/iu', $t)
            || preg_match('/\b(instantly|immediately|right away)\b/iu', $t)) {
            $why[] = 'Promises a response time.';
        }
        if (preg_match('#24\s*/\s*7|24 hours a day|round the clock|around the clock#iu', $t)) {
            $why[] = 'Claims 24/7 availability.';
        }
        $low = mb_strtolower($t);
        $never = PM_REPLY_NEVER;
        if (function_exists('pm_bank_get')) {
            try {
                $never = array_merge($never, array_map('mb_strtolower', array_filter((array)(pm_bank_get($brand)['banned'] ?? []), 'is_string')));
            } catch (Throwable) {
            }
        }
        foreach ($never as $w) {
            if ($w !== '' && str_contains($low, $w)) {
                $why[] = 'Uses a word we never say ("' . $w . '").';
            }
        }
        if ($brand === 'travel' && !empty(pm_settings()['travel']['charging']) && preg_match('/\b(free|no commission|no fee|no fees)\b/iu', $t)) {
            $why[] = 'Says free, but we are charging.';
        }
        if (($mode === 'reply' && mb_strlen($t) > 400) || ($mode === 'dm' && mb_strlen($t) > 1000)) {
            $why[] = 'Too long (' . mb_strlen($t) . ' characters).';
        }
        return array_values(array_unique($why));
    });
}
