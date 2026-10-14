<?php
/**
 * The default first message of a business: an email and a WhatsApp message the owner writes once, with {placeholders} for the lead's own words, plus the signature that
 * ends every first email and the sign-off that ends every WhatsApp message. It exists beside the AI: the AI writes a message per lead; this one is the same for everyone,
 * costs nothing and says exactly what the owner wrote. A lead's card can take it with one press, and a business can choose to draft every new lead from it instead of the AI.
 *
 * Stored in data/message_defaults.json, one block per business: use ('ai' | 'default'), email_subject, email_body, whatsapp, signature, wa_signature. A field that was never
 * saved shows the starting text (Travel Malawi's is its own first email, with the share-kit links and the starter pack added as for any first email); the signature fields,
 * while never saved, change nothing: emails and WhatsApp messages end as they always did.
 */

const PM_DM_VARS = ['first_name' => 'their first name', 'name' => 'their business', 'city' => 'their town', 'currency' => 'the money of their country (kwacha in Malawi, never dollars)', 'type' => 'kind of business', 'intro' => 'who is writing ("I\'m Grace from ...")',
    'sender' => 'your name', 'company' => 'your business name', 'tagline' => 'your tagline', 'phone' => 'your phone', 'email' => 'your email', 'website' => 'your website',
    'address' => 'your address', 'title' => 'your job title', 'link' => 'your website link', 'opening' => 'the personal line about this lead from the day\'s study (one true detail about them); with nothing found, how you came across them'];

/** The default opening line (who is writing and how they came across the lead), with {placeholders}. */
const PM_DM_OPENING_TRAVEL = 'I would love {name} in {city} to be part of something new.'; // no name (the signature has it), no posing as a traveller
const PM_DM_OPENING_TRAVEL_OLD = '{intro}, and I came across {name} while looking at places to stay in {city}.'; // written out in saved messages from before {opening}
const PM_DM_OPENING = 'I came across {name} in {city} and thought we might be able to help.';

function pm_dm_all(): array
{
    return pm_load('message_defaults', fn() => []);
}

/** Runs $fn as $brand (the settings, the sender and the links all follow the business in use). */
function pm_dm_as(string $brand, callable $fn): mixed
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        return $fn();
    } finally {
        pm_brand_set($was);
    }
}

/** The starting text of every field. */
function pm_dm_seed(string $brand): array
{
    $sig = "Kind regards,\n{sender}\n{company}\n{phone}\n{website}";
    if ($brand === 'travel') {
        $pack = function_exists('pm_share_pack_state') && pm_share_pack_state('travel') !== 'none';
        return ['email_subject' => 'Direct bookings for {name}', 'email_body' => pm_tm_email_compose([], '', true),
            'whatsapp' => 'Hello {first_name}, this is {company}. ' . PM_TM_WA_AIM . ' '
                . ($pack ? 'The host starter pack and the links to get listed are below.' : 'May I send you the details?'),
            'signature' => $sig, 'wa_signature' => '— {sender}, {company}'];
    }
    return ['email_subject' => 'A short note for {name}',
        'email_body' => "Hello {first_name},\n\n{intro}.\n\n{opening} If it would be useful, I can send you a short plan for your business.\n\n"
            . (pm_our_wa_number(pm_settings()) !== '' ? 'Reply to this email, or message us on WhatsApp on {phone}, and I will come back to you.' : 'Reply to this email and I will come back to you.'), // WhatsApp only with a number to reach us on
        'whatsapp' => 'Hello {first_name}, {intro}. I came across {name} and thought we could help. May I send you a short plan for your business?',
        'signature' => $sig, 'wa_signature' => '— {sender}, {company}'];
}

/** The business's default message as it stands: ['use', 'email_subject', 'email_body', 'whatsapp', 'signature' (null = never saved), 'wa_signature' (null = never saved), 'saved' => bool]. */
function pm_dm_cfg(string $brand = ''): array
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $st = (array)(pm_dm_all()[$brand] ?? []);
    $seed = pm_dm_as($brand, fn() => pm_dm_seed($brand));
    $c = ['use' => ($st['use'] ?? '') === 'default' ? 'default' : 'ai', 'saved' => $st !== []];
    foreach (['email_subject', 'email_body', 'whatsapp'] as $k) {
        $c[$k] = trim((string)($st[$k] ?? '')) !== '' ? (string)$st[$k] : (string)$seed[$k];
    }
    $c['email_body'] = str_replace(PM_DM_OPENING_TRAVEL_OLD, '{opening}', $c['email_body']); // the line that was written out before {opening} existed
    foreach (['signature', 'wa_signature'] as $k) {
        $c[$k] = array_key_exists($k, $st) ? (string)$st[$k] : null;
        $c[$k . '_seed'] = (string)$seed[$k];
    }
    return $c;
}

/** True when this business drafts new first messages from its default message instead of asking the AI. */
function pm_dm_use_default(string $brand = ''): bool
{
    return pm_dm_cfg($brand)['use'] === 'default';
}

/* ---------------- filling in the placeholders ---------------- */

function pm_dm_first(array $lead): string
{
    $nm = trim((string)preg_replace('/^(dr|mr|mrs|ms|miss|prof|eng|hon|rev|pastor)\.?\s+/i', '', trim((string)($lead['contact'] ?? ''))));
    return (string)preg_replace('/[^\p{L}\' -]/u', '', trim(explode(' ', $nm)[0] ?? ''));
}

/** The words for every placeholder, for a lead (or [] for the signature alone), as the business in use. */
function pm_dm_vars(array $lead = [], string $opening = ''): array
{
    $s = pm_settings();
    $company = trim((string)($s['company_name'] ?? ''));
    $sender = pm_sender_name();
    $me = trim(explode(' ', $sender)[0] ?? '');
    $link = pm_link_cfg()['url'];
    $city = trim((string)($lead['city'] ?? ''));
    $v = ['first_name' => pm_dm_first($lead), 'name' => trim((string)($lead['name'] ?? '')) ?: 'your business', 'city' => $city !== '' && strcasecmp($city, 'Malawi') !== 0 ? $city : 'Malawi',
        'type' => trim((string)($lead['type'] ?? '')) ?: 'business', 'sender' => $sender, 'intro' => $me !== '' ? "I'm $me from $company" : "I am writing from $company", 'company' => $company,
        'tagline' => trim((string)($s['tagline'] ?? '')), 'phone' => trim((string)($s['phone'] ?? '')), 'email' => trim((string)($s['email'] ?? '')), 'website' => trim((string)($s['website'] ?? '')),
        'address' => trim((string)($s['address'] ?? '')), 'currency' => pm_local_currency($lead)['word'], 'title' => $sender !== '' && $sender === trim((string)($s['signatory_name'] ?? '')) ? trim((string)($s['signatory_title'] ?? '')) : '',
        'link' => $link !== '' ? $link : (trim((string)($s['website'] ?? '')) !== '' ? 'https://' . preg_replace('#^https?://#i', '', trim((string)$s['website'])) : '')];
    $personal = function_exists('pm_lead_personal') ? pm_lead_personal($lead) : ''; // the day's study wrote it: no AI here
    $v['opening'] = $opening !== '' ? $opening : ($personal !== '' ? $personal : pm_dm_fill(pm_brand() === 'travel' ? PM_DM_OPENING_TRAVEL : PM_DM_OPENING, $v));
    return $v;
}

function pm_dm_fill(string $text, array $vars): string
{
    $t = (string)preg_replace_callback('/\{([A-Za-z_]+)\}/', fn($m) => array_key_exists(strtolower($m[1]), $vars) ? (string)$vars[strtolower($m[1])] : $m[0], $text); // {Email} works as {email}
    return (string)preg_replace(['/(Hello|Hi|Dear) +,/', '/[ \t]+,/'], ['$1,', ','], $t); // no name: "Hello,"
}

/** A signature: one line per line of the template, a line whose words are all missing is left out ("{phone}" with no phone). */
function pm_dm_fill_lines(string $text, array $vars): string
{
    $out = [];
    foreach (preg_split('/\R/', str_replace("\r", '', $text)) ?: [] as $line) {
        $f = trim(pm_dm_fill($line, $vars));
        $f = trim((string)preg_replace(['/^([—–-]) *, */u', '/ *,+ *$/', '/, *,/'], ['$1 ', '', ','], $f)); // "— , Company," -> "— Company"
        if (preg_match('/\{[A-Za-z_]+\}/', $line) && preg_match('/^[\s,.·|—–-]*$/u', $f)) {
            continue;
        }
        $out[] = $f;
    }
    return trim(implode("\n", $out));
}

/** The email signature the owner wrote, filled in, or null when they never wrote one (emails then end as they always did). */
function pm_dm_signature(): ?string
{
    $c = pm_dm_cfg()['signature'];
    return $c === null ? null : pm_dm_fill_lines($c, pm_dm_vars());
}

/** The sign-off the owner wrote for WhatsApp messages, filled in, or null when they never wrote one. */
function pm_dm_wa_signature(): ?string
{
    $c = pm_dm_cfg()['wa_signature'];
    return $c === null ? null : pm_dm_fill_lines($c, pm_dm_vars());
}

/** The default message for one lead: ['email_subject', 'email_body', 'whatsapp'] (no signature: the app adds that when it sends), as the business in use. $opening is the line the AI wrote for {opening}. */
function pm_dm_render(array $lead, string $opening = ''): array
{
    $c = pm_dm_cfg();
    $v = pm_dm_vars($lead, $opening);
    $subject = pm_dm_fill($c['email_subject'], $v);
    $body = pm_dm_fill($c['email_body'], $v);
    $body = pm_no_wa_invite($body, pm_settings(), (string)(pm_dm_signature() ?? '')); // no "message us on WhatsApp" without a number (in the details or the signature)
    return ['email_subject' => function_exists('pm_subject_tidy') ? pm_subject_tidy($subject, $lead) : $subject, 'email_body' => pm_brand() === 'travel' ? pm_tm_pack_swap($body) : $body, 'whatsapp' => pm_dm_fill($c['whatsapp'], $v)];
}

/** Rows in the shape the writer returns, for leads drafted from the default message (no AI). */
function pm_dm_rows(array $leads): array
{
    return array_map(fn($l) => ['id' => $l['id']] + pm_dm_render($l), $leads);
}

/** Placeholders in a text that mean nothing: ['{foo}']. */
function pm_dm_unknown(string $text): array
{
    preg_match_all('/\{([a-z_]+)\}/i', $text, $m);
    return array_values(array_unique(array_map(fn($x) => '{' . $x . '}', array_filter($m[1], fn($x) => !isset(PM_DM_VARS[strtolower($x)])))));
}

/** What the owner needs to see before relying on it: ['email' => the email as sent, 'whatsapp' => the WhatsApp as sent, 'words', 'problems' => [...]]. Built for an example lead, as the business in use. */
function pm_dm_preview(): array
{
    $c = pm_dm_cfg();
    $lead = pm_brand() === 'travel' ? ['name' => 'Example Lodge', 'city' => 'Zomba', 'contact' => 'Grace Phiri', 'type' => 'lodge', 'brand' => 'travel']
        : ['name' => 'Example Business', 'city' => 'Lilongwe', 'contact' => 'Grace Phiri', 'type' => 'business', 'brand' => pm_brand()];
    $r = pm_dm_render($lead);
    $problems = pm_outreach_lint($r['email_subject'], $r['email_body'], true);
    if ($bad = pm_dm_unknown($c['email_subject'] . ' ' . $c['email_body'] . ' ' . $c['whatsapp'] . ' ' . (string)$c['signature'] . ' ' . (string)$c['wa_signature'])) {
        $problems[] = 'Unknown placeholder ' . implode(', ', $bad) . ': it would be sent as typed.';
    }
    foreach (pm_outreach_lint('WhatsApp message', $r['whatsapp'], true) as $p) {
        if (!str_starts_with($p, 'The message is')) {
            $problems[] = 'WhatsApp: ' . $p;
        }
    }
    $wa = str_word_count($r['whatsapp']);
    $wa > 80 && $problems[] = "WhatsApp: the message is $wa words; keep it under 80.";
    $email = $r['email_body'] . (function_exists('pm_share_email_block') ? pm_share_email_block($r['email_body']) : '') . pm_outreach_footer(pm_settings());
    return ['subject' => $r['email_subject'], 'email' => $email, 'whatsapp' => pm_wa_text($lead, $r['whatsapp'], true), 'words' => str_word_count($r['email_body']), 'problems' => $problems];
}

/** Saves the form of one business (the fields posted in $_POST['dm']). Returns [message, kind]. */
function pm_dm_save(string $brand): array
{
    if (!pm_brand_valid($brand)) {
        return ['Choose a business first.', 'err'];
    }
    if (!empty($_POST['reset'])) {
        pm_update('message_defaults', function (array $all) use ($brand) {
            unset($all[$brand]);
            return $all;
        }, fn() => []);
        return ['Back to the starting text.', 'ok'];
    }
    $in = (array)($_POST['dm'] ?? []);
    $t = fn(string $k, int $max) => mb_substr(trim(str_replace("\r\n", "\n", (string)($in[$k] ?? ''))), 0, $max);
    $row = ['use' => ($in['use'] ?? '') === 'default' ? 'default' : 'ai', 'email_subject' => $t('email_subject', 120), 'email_body' => $t('email_body', 3000), 'whatsapp' => $t('whatsapp', 700),
        'signature' => $t('signature', 500), 'wa_signature' => $t('wa_signature', 160)];
    pm_update('message_defaults', function (array $all) use ($brand, $row) {
        $all[$brand] = $row;
        return $all;
    }, fn() => []);
    $p = pm_dm_as($brand, fn() => pm_dm_preview()['problems']);
    return ['Saved the default message for ' . pm_brand_name($brand) . ($p ? '. It needs a fix before it can be sent: ' . implode(' ', $p) : '. It passes the checks.'), $p ? 'err' : 'ok'];
}

/** Puts the default message on one lead as its draft (email and WhatsApp), replacing what is there. Returns [ok, message]. */
function pm_dm_apply(array &$lead, bool $plain = false): array
{
    if (!empty($lead['sent'])) {
        return [false, 'A first email was already sent to this business: use a follow-up.'];
    }
    $use = $plain ? array_diff_key($lead, ['research' => 1]) : $lead; // plain: the default opening, without the study's personal line
    $r = pm_dm_as((string)($lead['brand'] ?? pm_brand()), fn() => pm_dm_render($use));
    $lead['drafts'] = $r + ['source' => 'default'] + ($plain ? ['plain' => true] : []); // plain stays plain: the morning run does not put the personal line back
    if (in_array($lead['status'] ?? '', ['new', 'qualified'], true)) {
        $lead['status'] = 'drafted';
    }
    pm_lead_note($lead, 'Default message used');
    return [true, 'The default message is now the draft for ' . ($lead['name'] ?? 'this lead') . '. Read it, then send.'];
}

/**
 * Every lead with an email address is ready to send without opening it: a lead that has an address but no first email yet gets one now from the business's default
 * message, with the study's personal line and the owner's name when they are known (no AI, so it costs nothing). Leads already written, sent, closed, opted out or
 * clients are left alone. Returns how many drafts were made.
 */
function pm_dm_ready_all(?array &$leads = null): int
{
    $own = $leads === null;
    if ($own) {
        $leads = pm_leads();
    }
    $n = 0;
    foreach ($leads as $id => $l) {
        if (!empty($l['sent']) && in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true)) { // emailed, but still showing as not written to: put right
            $leads[$id]['status'] = 'contacted';
            unset($leads[$id]['approved_at'], $leads[$id]['send_after']);
            if (!in_array('Already contacted', (array)($l['folders'] ?? []), true)) {
                $leads[$id]['folders'][] = 'Already contacted';
            }
            pm_lead_note($leads[$id], 'Moved to Contacted: it was emailed on ' . (string)end($l['sent']));
            $n++;
            continue;
        }
        if (!filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) || trim((string)($l['drafts']['email_body'] ?? '')) !== '' || !empty($l['sent']) || !empty($l['rewrite_reason'])
            || !in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true) || (function_exists('pm_lead_is_client') && pm_lead_is_client($l))
            || (!empty($l['email_bad']) && strcasecmp((string)$l['email_bad'], (string)$l['email']) === 0)) {
            continue;
        }
        $r = pm_dm_as((string)($l['brand'] ?? 'promanaged'), fn() => pm_dm_render($l));
        if (trim((string)$r['email_body']) === '') {
            continue;
        }
        $leads[$id]['drafts'] = $r + ['source' => 'default'];
        $leads[$id]['status'] = 'drafted';
        pm_lead_note($leads[$id], 'First email made from the default message, so it is ready to send' . (function_exists('pm_lead_personal') && pm_lead_personal($l) !== '' ? ' (with the personal line from the study)' : ''));
        $n++;
    }
    if ($own && $n) {
        pm_leads_save($leads);
    }
    return $n;
}
