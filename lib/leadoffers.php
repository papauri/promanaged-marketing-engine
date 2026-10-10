<?php
/**
 * Lead offers, the light half: the store, the public view of an offer, the tap-to-chat link and the landing-page link. Loaded by enquire.php (the public
 * page) and by lib/sx_leadposts.php (which makes the posts). Needs only store.php, agents.php and outbound.php.
 */

const PM_LP_VOLUMES = ['calm' => 'Calm', 'bold' => 'Bold', 'unhinged' => 'Unhinged'];
const PM_LP_BUTTONS = ['whatsapp' => 'Send WhatsApp message', 'message' => 'Send message', 'quote' => 'Get a quote', 'learn' => 'Learn more', 'book' => 'Book a call',
    'signup' => 'Sign up', 'call' => 'Call us', 'contact' => 'Contact us'];
const PM_LP_PILLAR = 'Lead offer';

/* ---------------- storage and the public offer ---------------- */

function pm_lp_all(): array
{
    return array_values((array)(pm_load('lead_offers', fn() => ['rows' => []])['rows'] ?? []));
}

function pm_lp_update(callable $fn): void
{
    pm_update('lead_offers', fn(array $d) => ['rows' => array_values($fn((array)($d['rows'] ?? [])))], fn() => ['rows' => []]);
}

function pm_lp_get(string $id): ?array
{
    foreach (pm_lp_all() as $r) {
        if (($r['id'] ?? '') === $id) {
            return $r;
        }
    }
    return null;
}

function pm_lp_for_brand(string $brand, bool $activeOnly = false): array
{
    return array_values(array_filter(pm_lp_all(), fn($r) => ($r['brand'] ?? '') === $brand && (!$activeOnly || ($r['status'] ?? '') === 'active')));
}

/** A line of owner text: one line, no markup, bounded. */
function pm_lp_line(mixed $v, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $max);
}

/** A sentence-start phrase with its end punctuation removed, so a template can add its own. */
function pm_lp_tr(string $s): string
{
    return rtrim(trim($s), " .!?;:,");
}

/** The WhatsApp number in digits for a business, '' when none or a landline. */
function pm_lp_wa_number(string $brand): string
{
    $was = pm_brand();
    pm_brand_set($brand);
    [$n, $k] = pm_wa_number((string)(pm_settings()['phone'] ?? ''));
    pm_brand_set($was);
    return $k === 'landline' ? '' : (string)$n;
}

/** A tap-to-chat link whose first message already says the keyword and the offer code. '' without a mobile number. */
function pm_lp_wa_link(array $o, string $ref = ''): string
{
    $n = pm_lp_wa_number((string)$o['brand']);
    if ($n === '') {
        return '';
    }
    $text = trim(($o['keyword'] !== '' ? $o['keyword'] : 'Hi') . ' (ref ' . ($ref !== '' ? $ref : pm_lp_ref((string)$o['id'])) . ')');
    return 'https://wa.me/' . $n . '?text=' . rawurlencode($text);
}

/** The offer's public landing page ('' until APP_URL is set, because only a hosted app has an address people can open). */
function pm_lp_url(string $brand, string $id, string $src = ''): string
{
    $base = pm_app_url();
    if ($base === '' || !preg_match('/^[a-f0-9]{10}$/', $id)) {
        return '';
    }
    return $base . '/enquire.php?' . http_build_query(array_filter(['b' => $brand !== 'promanaged' ? $brand : '', 'mode' => 'offer', 'o' => $id, 'src' => $src]));
}

/** What a visitor may see of an offer, or null when it is unknown, paused or past its end date. */
function pm_lp_public(string $brand, string $id): ?array
{
    $o = preg_match('/^[a-f0-9]{10}$/', $id) ? pm_lp_get($id) : null;
    if (!$o || ($o['brand'] ?? '') !== $brand || ($o['status'] ?? '') !== 'active' || (($o['ends'] ?? '') !== '' && $o['ends'] < date('Y-m-d'))) {
        return null;
    }
    return ['id' => $o['id'], 'title' => $o['title'], 'headline' => ucfirst(pm_lp_tr($o['title'])), 'sub' => $o['fix'] !== '' ? $o['fix'] : $o['problem'], 'problem' => $o['problem'], 'proof' => $o['proof'],
        'bullets' => array_values(array_filter(array_merge((array)$o['signs'], $o['proof'] !== '' ? [$o['proof']] : []))), 'button' => PM_LP_BUTTONS[$o['button']] ?? 'Send message', 'button_key' => $o['button'],
        'keyword' => $o['keyword'], 'questions' => $o['questions'], 'wa' => pm_lp_wa_link($o), 'volume' => $o['volume'], 'ends' => $o['ends'], 'brand' => $o['brand']];
}

/** Counts one visit (a lock and a write per visit is fine at this size; bots are rate-limited by the page itself). */
function pm_lp_view(string $id): void
{
    pm_lp_update(function (array $rows) use ($id) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id) {
                $rows[$i]['views'] = (int)($r['views'] ?? 0) + 1;
            }
        }
        return $rows;
    });
}

/** The message a lead record gets from the form: the answers to the offer's questions, one per line. */
function pm_lp_message(array $offer, array $answers, string $free): string
{
    $lines = [];
    foreach ((array)$offer['questions'] as $i => $q) {
        $a = pm_web_clean(strip_tags((string)($answers[$i] ?? '')), 200);
        if ($a !== '') {
            $lines[] = $q['label'] . ' ' . $a;
        }
    }
    $f = pm_web_clean(strip_tags($free), 600);
    return trim('Asked via the offer "' . $offer['title'] . '".' . ($lines ? ' ' . implode(' | ', $lines) : '') . ($f !== '' ? ' ' . $f : ''));
}

/** A four-character code for an offer, in the same shape as a post's ref so "(ref ABCD)" in a WhatsApp message is read the same way. */
function pm_lp_ref(string $id): string
{
    return strtoupper(substr(str_pad(base_convert(sprintf('%u', crc32($id)), 10, 36), 4, '0', STR_PAD_LEFT), -4));
}
