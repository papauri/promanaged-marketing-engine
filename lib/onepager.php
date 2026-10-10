<?php
/**
 * The one-page offer: a single A4 page about a business (who it is, what it offers, true things about it, a free first step, how to reach it), made only from
 * the business's own words in the app, in its own colour and with its own logo. It is what a business added in the app sends instead of a proposal, because
 * those have no price list or agreement wording of their own. No prices, no claims nobody backed up: anything the lint would hold in a post is flagged here too.
 * It is emailed only to someone who has written to the business, and only when the owner presses the button for that lead.
 */

/** What goes on the page, from the business's own settings, brain and agent settings. */
function pm_onepager_content(string $brand): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $s = pm_settings();
        $brn = pm_brain($brand);
        $p = pm_brand_profile($brand);
        $cfg = pm_agents_config($brand);
    } finally {
        pm_brand_set($was);
    }
    $offers = [];
    foreach (array_slice(array_values(array_filter(array_map('strval', (array)($cfg['offerings'] ?? [])))), 0, 5) as $o) {
        [$n, $d] = array_pad(explode(':', $o, 2), 2, '');
        $offers[] = $d !== '' ? ['name' => trim($n), 'what' => trim($d)] : ['name' => trim($n), 'what' => ''];
    }
    return ['name' => (string)($s['company_name'] ?? pm_brand_name($brand)), 'tagline' => trim((string)($s['tagline'] ?? '')), 'about' => trim((string)($brn['about'] ?? '')), 'offerings' => $offers,
        'facts' => array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($brn['facts'] ?? '')) ?: []))), 0, 6), 'magnet' => trim((string)$p['magnet']),
        'keyword' => (string)$p['cta_keyword'], 'phone' => trim((string)($s['phone'] ?? '')), 'email' => trim((string)($s['email'] ?? '')), 'website' => trim((string)($s['website'] ?? '')),
        'address' => trim((string)($s['address'] ?? '')), 'accent' => (string)($s['accent_color'] ?? '#17375E')];
}

/** Why the page is not ready, or [] when it is: something to say, something to offer, a way to reach the business, and nothing that cannot be backed up. */
function pm_onepager_problems(array $c): array
{
    $bad = [];
    if ($c['about'] === '') {
        $bad[] = 'Say what the business does (Settings > What the AI knows).';
    }
    if (!$c['offerings'] && !$c['facts']) {
        $bad[] = 'Add what the business offers, or a few true facts about it.';
    }
    if ($c['phone'] === '' && $c['email'] === '' && $c['website'] === '') {
        $bad[] = 'Add a phone number, an email address or a website so people can reach the business.';
    }
    $all = implode("\n", array_merge([$c['about'], $c['tagline'], $c['magnet']], $c['facts'], array_map(fn($o) => $o['name'] . ' ' . $o['what'], $c['offerings'])));
    if (preg_match('/\b(best|cheapest|fastest|number (?:one|1)|100\s?%|risk[- ]free|24\s?\/\s?7|guarantee[sd]?)\b/i', $all, $m)) {
        $bad[] = 'Take out "' . $m[1] . '": it cannot be backed up.';
    }
    if (preg_match('/\bMWK\b|\bUSD\b|\$\s?\d|\bK\s?\d{2,}/i', $all)) {
        $bad[] = 'Take out prices: the one-page offer carries none.';
    }
    return $bad;
}

/** The state of a business's one-page offer for the screens (computed once per request per business): ['problems' => [...], 'content' => [...]]. */
function pm_onepager_status(string $brand): array
{
    static $cache = [];
    if (!isset($cache[$brand])) {
        $c = pm_onepager_content($brand);
        $cache[$brand] = ['problems' => pm_onepager_problems($c), 'content' => $c];
    }
    return $cache[$brand];
}

/** The email that goes with it, for the owner to read and change: [subject, body]. Nothing in it is a promise. */
function pm_onepager_default_mail(array $lead): array
{
    $c = pm_onepager_content((string)($lead['brand'] ?? 'promanaged'));
    $first = trim(explode(' ', trim((string)($lead['contact'] ?? '')))[0] ?? '');
    $first = preg_replace('/[^\p{L}\' -]/u', '', $first);
    return [$c['name'] . ': a one-page summary', ($first !== '' ? "Hello $first,\n\n" : "Hello,\n\n") . 'Thank you for getting in touch with ' . $c['name'] . ". Attached is a one-page summary of what we do and how to reach us.\n\n"
        . 'If you tell us a little about what you need, we will come back with the next step.'];
}

/** Has this person written to the business? The one-page offer is only sent to someone who has. */
function pm_onepager_wrote(array $lead): bool
{
    foreach ((array)($lead['thread'] ?? []) as $m) {
        if (($m['dir'] ?? '') === 'in' && trim((string)($m['text'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/** Makes the PDF and returns its path (data of the business only). $compress=false keeps the text readable in the file, for tests. */
function pm_build_onepager(string $brand, ?string $file = null, bool $compress = true): string
{
    require_once __DIR__ . '/pdf.php';
    $c = pm_onepager_content($brand);
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $logo = pm_logo_path();
    } finally {
        pm_brand_set($was);
    }
    $pdf = new PM_PDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->s = ['company_name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'], 'website' => $c['website']];
    $pdf->onePage = true;
    $pdf->accent = pm_hex_rgb($c['accent']);
    [$ar, $ag, $ab] = $pdf->accent;
    $pdf->SetCompression($compress);
    $pdf->SetCreator($c['name']);
    $pdf->SetAuthor($c['name']);
    $pdf->SetTitle($c['name'] . ': about us and what we offer');
    $pdf->SetMargins(20, 18, 20);
    $pdf->SetFooterMargin(12);
    $pdf->SetAutoPageBreak(false);
    $pdf->setCellHeightRatio(1.35);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->AddPage();

    if ($logo !== '') {
        $ls = @getimagesize($logo);
        $square = $ls && $ls[1] > 0 && ($ls[0] / $ls[1]) < 1.4;
        $pdf->Image($logo, 20, 17, $square ? 14 : 34, 0, 'PNG');
        if ($square) {
            $pdf->SetXY(37, 18);
            $pdf->SetFont('helvetica', 'B', 15);
            $pdf->SetTextColor($ar, $ag, $ab);
            $pdf->Cell(90, 12, $c['name'], 0, 0);
        }
    } else {
        $pdf->SetXY(20, 19);
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetTextColor($ar, $ag, $ab);
        $pdf->Cell(100, 8, $c['name'], 0, 0);
    }
    $pdf->SetDrawColor($ar, $ag, $ab);
    $pdf->SetLineWidth(0.6);
    $pdf->Line(20, 36, 190, 36);

    $label = function (string $t) use ($pdf, $ar, $ag, $ab): void {
        $pdf->Ln(7);
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor($ar, $ag, $ab);
        $pdf->setFontSpacing(0.6);
        $pdf->Cell(0, 5, mb_strtoupper($t), 0, 1);
        $pdf->setFontSpacing(0);
        $pdf->Ln(1);
    };
    $room = fn() => $pdf->GetY() < 245;

    $pdf->SetY(44);
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor($ar, $ag, $ab);
    $pdf->setFontSpacing(0.6);
    $pdf->Cell(0, 5, 'ABOUT US AND WHAT WE OFFER', 0, 1);
    $pdf->setFontSpacing(0);
    $pdf->SetFont('times', '', 28);
    $pdf->SetTextColor(15, 15, 15);
    $pdf->MultiCell(170, 11, $c['name'], 0, 'L');
    if ($c['tagline'] !== '') {
        $pdf->SetFont('times', 'I', 13);
        $pdf->SetTextColor(70, 70, 70);
        $pdf->MultiCell(170, 6.5, $c['tagline'], 0, 'L');
    }
    $pdf->Ln(4);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(50, 50, 50);
    $pdf->MultiCell(165, 5.8, mb_substr($c['about'], 0, 400), 0, 'L');

    if ($c['offerings'] && $room()) {
        $label('What we offer');
        foreach ($c['offerings'] as $o) {
            if (!$room()) {
                break;
            }
            $pdf->SetFont('helvetica', 'B', 10.5);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->Cell(0, 5.6, $o['name'], 0, 1);
            if ($o['what'] !== '') {
                $pdf->SetFont('helvetica', '', 10);
                $pdf->SetTextColor(70, 70, 70);
                $pdf->MultiCell(165, 5.2, mb_substr($o['what'], 0, 200), 0, 'L');
            }
            $pdf->Ln(1.5);
        }
    }
    if ($c['facts'] && $room()) {
        $label('Good to know');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(60, 60, 60);
        foreach ($c['facts'] as $f) {
            if (!$room()) {
                break;
            }
            $pdf->MultiCell(165, 5.2, '-  ' . mb_substr($f, 0, 200), 0, 'L');
            $pdf->Ln(0.8);
        }
    }
    if ($c['magnet'] !== '' && $room()) {
        $label('A free first step');
        $pdf->SetFont('helvetica', '', 10.5);
        $pdf->SetTextColor(40, 40, 40);
        $pdf->MultiCell(165, 5.6, $c['keyword'] !== '' && $c['phone'] !== '' ? 'Send ' . $c['keyword'] . ' on WhatsApp to ' . $c['phone'] . ' for ' . $c['magnet'] . '.' : 'Ask us about ' . $c['magnet'] . '.', 0, 'L');
    }
    $touch = array_filter(['Phone / WhatsApp' => $c['phone'], 'Email' => $c['email'], 'Website' => $c['website'], 'Address' => $c['address']], fn($v) => $v !== '');
    if ($touch) {
        $label('Get in touch');
        foreach ($touch as $k => $v) {
            $pdf->SetFont('helvetica', 'B', 9.5);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->Cell(36, 5.6, $k, 0, 0);
            $pdf->SetFont('helvetica', '', 10.5);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->Cell(0, 5.6, mb_substr($v, 0, 90), 0, 1);
        }
    }

    if (!is_dir(PM_OUT)) {
        mkdir(PM_OUT, 0775, true);
    }
    $file ??= PM_OUT . '/onepager_' . preg_replace('/[^a-z0-9]/', '', $brand) . '.pdf';
    $pdf->Output($file, 'F');
    return $file;
}
