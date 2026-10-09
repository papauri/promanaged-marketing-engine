<?php
require_once PM_ROOT . '/vendor/autoload.php';

class PM_PDF extends TCPDF
{
    public array $s = [];
    public string $ref = '';
    public array $accent = [23, 55, 94];
    public string $footNote = '';

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->tcpdflink = false; // no "Powered by TCPDF" line on the last page
    }

    public function Header(): void
    {
        if ($this->getPage() === 1) {
            return;
        }
        $this->SetY(10);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(95, 5, mb_strtoupper($this->s['company_name']), 0, 0, 'L');
        $this->Cell(0, 5, $this->ref, 0, 1, 'R');
        $this->SetDrawColor(210, 210, 210);
        $this->SetLineWidth(0.2);
        $this->Line(20, 16, 190, 16);
    }

    public function Footer(): void
    {
        $this->SetY(-14);
        $this->SetDrawColor(210, 210, 210);
        $this->SetLineWidth(0.2);
        $this->Line(20, $this->GetY() - 2, 190, $this->GetY() - 2);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(120, 120, 120);
        $bits = array_filter([$this->s['company_name'], $this->s['phone'], $this->s['email'], $this->s['website']]);
        $this->Cell(140, 5, $this->footNote !== '' ? $this->footNote : implode('   ·   ', $bits), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

function pm_hex_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return [23, 55, 94];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function pm_section(PM_PDF $pdf, string $num, string $title, ?string $lead = null): void
{
    // Keep a heading with its first rows: start a new page if less than ~75 mm is left.
    if ($pdf->GetY() > $pdf->getPageHeight() - 20 - 75) {
        $pdf->AddPage();
    } else {
        $pdf->Ln(4);
    }
    [$r, $g, $b] = $pdf->accent;
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor($r, $g, $b);
    $pdf->Cell(0, 5, $num, 0, 1, 'L');
    $pdf->SetFont('times', '', 19);
    $pdf->SetTextColor(20, 20, 20);
    $pdf->MultiCell(0, 9, $title, 0, 'L'); // long titles wrap instead of running off the page
    $pdf->SetDrawColor($r, $g, $b);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(20, $pdf->GetY() + 1, 34, $pdf->GetY() + 1);
    $pdf->Ln(5);
    if ($lead) {
        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->MultiCell(0, 5, $lead, 0, 'L');
        $pdf->Ln(3);
    }
}

function pm_html(PM_PDF $pdf, string $html, float $vpad = 0): void
{
    // Zero side padding: table text starts exactly on the 20 mm margin and right-aligned
    // figures end exactly on 190 mm, in line with every heading and rule.
    $pdf->setCellPaddings(0, $vpad, 0, $vpad);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->setCellPaddings(0, 0, 0, 0);
}

/** A table with uniform rows: vertical padding only, gutters are spacer columns. */
function pm_table(PM_PDF $pdf, string $html, float $vpad = 1.8): void
{
    pm_html($pdf, $html, $vpad);
}

/** Spacer column between table columns. */
function pm_gap(float $w = 2, string $style = ''): string
{
    return '<td width="' . $w . '%"' . ($style !== '' ? ' style="' . $style . '"' : '') . '></td>';
}

/**
 * Table drawn cell by cell so every column starts and ends exactly on the grid:
 * text from 20 mm, right-aligned figures to 190 mm, rules edge to edge, even row spacing.
 * $cols: [['w' => share, 'align' => 'L'|'R'], ...]; gaps of $gap mm between columns.
 * $rows: arrays of cell HTML; a row may be ['_cells' => [...], '_rule' => 'top-accent'|'none'].
 */
function pm_grid(PM_PDF $pdf, array $cols, ?array $head, array $rows, array $o = []): void
{
    $x0 = 20.0;
    $W = 170.0;
    $gap = $o['gap'] ?? 4.0;
    $vpad = $o['vpad'] ?? 2.0;
    $rule = $o['rule'] ?? [222, 224, 228];
    $font = $o['font'] ?? 9.0;
    $total = array_sum(array_column($cols, 'w'));
    $avail = $W - $gap * (count($cols) - 1);
    $xs = [];
    $ws = [];
    $x = $x0;
    foreach ($cols as $c) {
        $w = $avail * $c['w'] / $total;
        $xs[] = $x;
        $ws[] = $w;
        $x += $w + $gap;
    }
    $bottom = $pdf->getPageHeight() - $pdf->getBreakMargin();

    $measure = function (array $cells, float $pad) use ($pdf, $xs, $ws, $cols, $font): float {
        $h = 0;
        foreach ($cells as $i => $html) {
            $pdf->startTransaction();
            $pdf->SetFont('helvetica', '', $font);
            $y = $pdf->GetY();
            $pdf->setCellPaddings(0, $pad, 0, $pad);
            $pdf->writeHTMLCell($ws[$i], 0, $xs[$i], $y, (string)$html, 0, 1, false, true, $cols[$i]['align'] ?? 'L');
            $h = max($h, $pdf->GetY() - $y);
            $pdf->rollbackTransaction(true);
        }
        return $h;
    };
    $draw = function (array $cells, float $h, float $pad) use ($pdf, $xs, $ws, $cols, $font) {
        $y = $pdf->GetY();
        foreach ($cells as $i => $html) {
            $pdf->SetFont('helvetica', '', $font);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->setCellPaddings(0, $pad, 0, $pad);
            $pdf->writeHTMLCell($ws[$i], $h, $xs[$i], $y, (string)$html, 0, 0, false, true, $cols[$i]['align'] ?? 'L');
        }
        $pdf->SetY($y + $h);
    };
    $drawHead = function () use ($pdf, $head, $measure, $draw, $xs) {
        if (!$head) {
            return;
        }
        $cells = array_map(fn($t) => '<span style="font-size:6.8pt;color:#7a7f87;">' . pm_h(mb_strtoupper((string)$t)) . '</span>', $head);
        $h = $measure($cells, 1.2);
        $draw($cells, $h, 1.2);
        $pdf->SetDrawColor(150, 154, 160);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    };

    $pdf->SetAutoPageBreak(false);
    $drawHead();
    $rows = array_values($rows);
    $n = count($rows);
    foreach ($rows as $k => $r) {
        $cells = $r['_cells'] ?? $r;
        $style = $r['_rule'] ?? 'normal';
        $h = $measure($cells, $vpad);
        // No orphans: if the last row would end up alone on the next page, move the last two rows together.
        $keep = $h;
        if ($k === $n - 2 && $n >= 3) {
            $last = $rows[$n - 1];
            $keep += $measure($last['_cells'] ?? $last, $vpad);
        }
        if ($pdf->GetY() + $h > $bottom || ($pdf->GetY() + $keep > $bottom && $k > 0)) {
            $pdf->AddPage();
            $pdf->SetY($pdf->getMargins()['top'] + 4);
            $drawHead();
        }
        if ($style === 'top-accent') {
            [$ar, $ag, $ab] = $pdf->accent;
            $pdf->SetDrawColor($ar, $ag, $ab);
            $pdf->SetLineWidth(0.45);
            $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        }
        $draw($cells, $h, $vpad);
        if ($style === 'normal') {
            $pdf->SetDrawColor(...$rule);
            $pdf->SetLineWidth(0.2);
            $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        }
    }
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->Ln(2.5);
}

/** Small note under a table, on the same 20–190 mm measure. */
function pm_note(PM_PDF $pdf, string $html): void
{
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(85, 85, 85);
    $pdf->writeHTMLCell(170, 0, 20, $pdf->GetY(), $html, 0, 1, false, true, 'L');
    $pdf->Ln(1.5);
}

/** Small grey label + value, used on the acceptance page. */
/** Largest font size (down to $min) at which $text fits in $w mm on one line; sets that font and returns the size. */
function pm_fit(PM_PDF $pdf, string $text, string $family, string $style, float $max, float $w, float $min = 7): float
{
    for ($sz = $max; $sz > $min; $sz -= 0.5) {
        $pdf->SetFont($family, $style, $sz);
        if ($pdf->GetStringWidth($text) <= $w) {
            return $sz;
        }
    }
    $pdf->SetFont($family, $style, $min);
    return $min;
}

/** $text cut with an ellipsis so it fits in $w mm at the current font. */
function pm_clip(PM_PDF $pdf, string $text, float $w): string
{
    if ($pdf->GetStringWidth($text) <= $w) {
        return $text;
    }
    while (mb_strlen($text) > 1 && $pdf->GetStringWidth($text . '…') > $w) {
        $text = mb_substr($text, 0, -1);
    }
    return rtrim($text) . '…';
}

function pm_kv(PM_PDF $pdf, string $label, string $value, float $x, float $y, float $w, float $size = 10): void
{
    $pdf->SetXY($x, $y);
    $pdf->SetFont('helvetica', '', 6.8);
    $pdf->SetTextColor(125, 125, 125);
    $pdf->setFontSpacing(0.3);
    $pdf->Cell($w, 4, mb_strtoupper($label), 0, 0);
    $pdf->setFontSpacing(0);
    $pdf->SetXY($x, $y + 4.3);
    pm_fit($pdf, $value, 'helvetica', '', $size, $w, 7);
    $pdf->SetTextColor(20, 20, 20);
    $pdf->Cell($w, 5.5, pm_clip($pdf, $value, $w), 0, 0);
}

/**
 * Build the PDF.
 * $tpl is already in the quote currency (pm_template_in). $p = proposal form.
 * $opt: sign_url (string), signed (array: name, title, email, at, ip, ua, method, image, typed, hash, viewed_at, sent_at),
 *       file (output path), pm_sig (path to our signature image).
 */
function pm_build_pdf(array $s, array $tpl, array $p, string $ref, array $opt = []): string
{
    pm_set_free(!empty($tpl['free'])); // free onboarding prints "Free", never "MWK 0"
    pm_set_from(!empty($tpl['price_from'])); // "From" quotations
    $q = pm_quote($tpl, $p);
    $curRow = pm_currency($s, (string)($p['currency'] ?? $s['currency']));
    $cur = $curRow['code'];
    $type = $p['doc_type'] ?? 'both';
    $withPitch = $type !== 'contract';
    $withContract = $type !== 'pitch';
    $signed = $opt['signed'] ?? null;
    $signUrl = (string)($opt['sign_url'] ?? '');
    $date = $p['date'] ?: date('Y-m-d');
    $validUntil = date('j F Y', strtotime($date . ' +' . (int)$tpl['valid_days'] . ' days'));
    $docLabel = $type === 'pitch' ? 'Proposal' : ($type === 'contract' ? 'Service Agreement' : 'Proposal and Service Agreement');
    $startLabel = !empty($p['start_date']) ? date('j F Y', strtotime($p['start_date'])) : 'To be agreed';

    $pdf = new PM_PDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->s = $s;
    $pdf->ref = $ref . ($signed ? ' · SIGNED' : '');
    $pdf->accent = pm_hex_rgb($s['accent_color'] ?? '#17375E');
    [$ar, $ag, $ab] = $pdf->accent;
    $accentHex = '#' . ltrim($s['accent_color'] ?? '17375E', '#');
    $pdf->SetCreator($s['company_name']);
    $pdf->SetAuthor($s['company_name']);
    $pdf->SetTitle($tpl['title'] . ' — ' . $docLabel . ' — ' . $p['business']);
    $pdf->SetSubject($ref);
    $pdf->SetMargins(20, 22, 20);
    $pdf->SetHeaderMargin(8);
    $pdf->SetFooterMargin(12);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->setCellHeightRatio(1.35);
    $pdf->setCellPaddings(0, 0, 0, 0);

    // ---------- Cover ----------
    $pdf->AddPage();
    $logo = pm_logo_path();
    if ($logo !== '') {
        $ls = @getimagesize($logo);
        $square = $ls && $ls[1] > 0 && ($ls[0] / $ls[1]) < 1.4; // a square mark gets a name beside it
        $pdf->Image($logo, 20, 19, $square ? 14 : 34, 0, 'PNG');
        if ($square) {
            $pdf->SetXY(37, 20);
            $pdf->SetFont('helvetica', 'B', 17);
            $pdf->SetTextColor($ar, $ag, $ab);
            $pdf->Cell(90, 12, (string)$s['company_name'], 0, 0);
        }
    } else {
        $pdf->SetXY(20, 21);
        $pdf->SetFont('helvetica', 'B', 17);
        $pdf->SetTextColor($ar, $ag, $ab);
        $pdf->Cell(90, 9, (string)$s['company_name'], 0, 0);
    }
    if ($signed) {
        $pdf->SetXY(120, 24);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetTextColor($ar, $ag, $ab);
        $pdf->setFontSpacing(0.5);
        $pdf->Cell(70, 5, 'SIGNED ' . mb_strtoupper(date('j M Y', strtotime($signed['at']))), 0, 0, 'R');
        $pdf->setFontSpacing(0);
    }
    $pdf->SetY(92);
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor($ar, $ag, $ab);
    $pdf->setFontSpacing(0.6);
    $pdf->Cell(0, 6, mb_strtoupper($docLabel), 0, 1);
    $pdf->setFontSpacing(0);
    $pdf->SetFont('times', '', 34);
    $pdf->SetTextColor(15, 15, 15);
    $pdf->MultiCell(150, 13, $tpl['title'], 0, 'L');
    $pdf->Ln(4);
    $pdf->SetFont('helvetica', '', 10.5);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell(140, 5.6, $tpl['intro'], 0, 'L');
    if ($withPitch && trim((string)($tpl['cover_hook'] ?? '')) !== '') {
        $pdf->Ln(9);
        $yh = $pdf->GetY();
        $pdf->SetFont('times', 'I', 14);
        $pdf->SetTextColor(40, 40, 40);
        $pdf->MultiCell(132, 6.6, $tpl['cover_hook'], 0, 'L', false, 1, 26);
        $pdf->SetDrawColor($ar, $ag, $ab);
        $pdf->SetLineWidth(0.8);
        $pdf->Line(20.5, $yh + 0.5, 20.5, $pdf->GetY() - 0.5);
    }

    $pdf->SetDrawColor($ar, $ag, $ab);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(20, 198, 190, 198);
    $meta = function (string $label, string $value, float $x, float $w) use ($pdf) {
        $pdf->SetX($x);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell($w, 4.5, mb_strtoupper($label), 0, 2);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(25, 25, 25);
        $pdf->MultiCell($w, 5, $value !== '' ? $value : '—', 0, 'L', false, 1, $x);
        $pdf->Ln(2.5);
    };
    $y0 = 203;
    $pdf->SetY($y0);
    $meta('Prepared for', trim($p['business'] . "\n" . $p['contact'] . ($p['contact_title'] ? ', ' . $p['contact_title'] : '')), 20, 80);
    $meta('Location', $p['address'] ?? '', 20, 80);
    $pdf->SetY($y0);
    $meta('Prepared by', trim($s['company_name'] . "\n" . ($s['signatory_name'] ? $s['signatory_name'] . ', ' . $s['signatory_title'] : '')), 110, 80);
    $yy = $pdf->GetY();
    $pdf->SetY($yy);
    $meta('Reference', $ref, 110, 38);
    $pdf->SetY($yy);
    $meta('Date', date('j F Y', strtotime($date)), 150, 40);
    $yy = $pdf->GetY();
    $pdf->SetY($yy);
    $meta('Valid until', $validUntil, 110, 38);
    $pdf->SetY($yy);
    $meta('Currency', $curRow['name'] . ' (' . $cur . ')', 150, 40);

    // ---------- Body ----------
    $pdf->AddPage();
    $n = 0;
    $num = function () use (&$n) { $n++; return sprintf('%02d', $n); };
    // Keep a short section on one page: if it would split across pages, start it on a fresh page instead.
    $keep = function (callable $draw) use ($pdf, &$n): void {
        // Draw it once on a throwaway copy to see whether it would cross a page break.
        $nBefore = $n;
        $probe = clone $pdf;
        $draw($probe);
        $spills = $probe->getPage() !== $pdf->getPage();
        unset($probe);
        $n = $nBefore;
        if ($spills) {
            $pdf->AddPage();
        }
        $draw($pdf);
    };
    $serif = fn($t, $size = 11.5) => '<span style="font-family:times;font-size:' . $size . 'pt;color:#111111;">' . pm_h($t) . '</span>';
    $grey = fn($t, $size = 8.5) => '<span style="font-size:' . $size . 'pt;color:#5a5f66;">' . pm_h($t) . '</span>';
    $daily = pm_daily((float)$q['monthly'], $curRow);

    if ($withPitch) {
        // Pain points first: the client's own business type, then the general leaks.
        $compact = !empty($tpl['compact']);
        $know = (array)($tpl['what_we_know'] ?? []);
        if ($know) {
            pm_section($pdf, $num(), mb_strlen($p['business']) <= 24 ? 'What we understand about ' . $p['business'] : 'What we understand about your business', trim((string)$p['personal_note']) ?: null);
            pm_html($pdf, '<table cellpadding="0" cellspacing="0">' . implode('', array_map(fn($k) => '<tr><td width="4%"><span style="color:' . $accentHex . ';font-family:dejavusans;">&#9679;</span></td><td width="96%"><span style="font-size:10pt;color:#1f2328;">' . pm_h($k) . '</span></td></tr>', $know)) . '</table>', 0.8);
        }
        $pains = array_slice(pm_pains_for($tpl, $q['package']['type'] ?? 'all'), 0, $compact ? 4 : 5); // the strongest few, not a catalogue
        if ($pains) {
            $lead = trim((($p['personal_note'] && !$know) ? $p['personal_note'] . "\n\n" : '') . ($compact ? '' : ($tpl['pain_intro'] ?? '')));
            pm_section($pdf, $num(), $tpl['pain_title'] ?? 'Where businesses like yours lose money', $lead);
            $rows = [];
            foreach ($pains as $pn) {
                $rows[] = [$serif($pn['pain']), (!empty($pn['fact']) ? '<span style="font-size:7.8pt;color:' . $accentHex . ';">We noticed: ' . pm_h(rtrim($pn['fact'], '. ')) . '.</span><br/>' : '') . $grey($pn['cost']),
                    '<span style="font-size:8.5pt;color:#1f2328;"><span style="font-family:dejavusans;color:' . $accentHex . ';">&#8594;</span> ' . pm_h($pn['fix']) . '</span>'];
            }
            pm_grid($pdf, [['w' => 29], ['w' => 41], ['w' => 30]], ['The leak', 'What it costs you', 'How it stops'], $rows, ['vpad' => 2.6]);
        }

        if ($compact && !empty($tpl['gains'])) {
            pm_section($pdf, $num(), 'What you gain', null);
            pm_html($pdf, '<table cellpadding="0" cellspacing="0">' . implode('', array_map(fn($g) => '<tr><td width="4%"><span style="color:' . $accentHex . ';font-family:dejavusans;">&#10003;</span></td><td width="96%"><span style="font-family:times;font-size:12pt;color:#111111;">' . pm_h($g) . '</span></td></tr>', $tpl['gains'])) . '</table>', 1.2);
        }
        if (!$compact) {
            pm_section($pdf, $num(), $tpl['benefits_title'] ?? 'What changes on day one', $pains ? null : ($p['personal_note'] ?: null));
            $bt = $q['package']['type'] ?? 'all';
            $b = array_slice(array_values(array_filter($tpl['benefits'], fn($x) => in_array($x[2] ?? 'all', ['all', '', $bt], true))), 0, 6);
            $rows = [];
            for ($i = 0; $i < count($b); $i += 2) {
                $cell = fn($x) => $x ? '<b style="font-size:9.5pt;color:#111111;">' . pm_h($x[0]) . '</b><br/><span style="font-size:8.8pt;color:#5a5f66;">' . pm_h($x[1]) . '</span>' : '';
                $rows[] = ['_cells' => [$cell($b[$i]), $cell($b[$i + 1] ?? null)], '_rule' => 'none'];
            }
            pm_grid($pdf, [['w' => 1], ['w' => 1]], null, $rows, ['gap' => 10, 'vpad' => 2.4]);
        }

        // What is included: only the areas for this client's business type.
        $typeKeys = array_keys(pm_types());
        $col = array_search($q['package']['type'] ?? '', $typeKeys, true);
        $incl = [];
        if (!empty($tpl['included'])) {
            foreach ($tpl['included'] as $m) { // business-line packages list their own inclusions
                $incl[] = [$m[0], $m[1], 'Yes'];
            }
        } else {
            foreach ($tpl['modules'] as $m) {
                $v = $col !== false ? ($m[2 + $col] ?? '') : (in_array('Yes', array_slice($m, 2), true) ? 'Yes' : '');
                if ($v === 'Yes' || $v === 'Optional') {
                    $incl[] = [$m[0], $m[1], $v];
                }
            }
        }
        usort($incl, fn($a, $c) => ($a[2] === 'Yes' ? 0 : 1) <=> ($c[2] === 'Yes' ? 0 : 1));
        if ($compact) {
            $incl = array_slice($incl, 0, 6);
        }
        if ($incl) {
            pm_section($pdf, $num(), 'What your ' . $q['package']['name'] . ' package includes', (string)($tpl['included_lead'] ?? 'Everything below runs in one system with one login. Add-ons can be switched on at any time.'));
            $rows = [];
            foreach ($incl as [$area, $desc, $v]) {
                $mark = $v === 'Yes'
                    ? '<span style="font-size:8pt;color:' . $accentHex . ';"><span style="font-family:dejavusans;">&#9679;</span>&nbsp;Included</span>'
                    : '<span style="font-size:8pt;color:#8a8f96;"><span style="font-family:dejavusans;">&#9675;</span>&nbsp;Add-on</span>';
                $rows[] = ['<b style="font-size:9pt;">' . pm_h($area) . '</b>', $grey($desc), $mark];
            }
            pm_grid($pdf, [['w' => 27], ['w' => 57], ['w' => 16, 'align' => 'R']], ['Area', 'What it covers', ''], $rows);
        }
    }

    // ---------- Quotation ----------
    pm_section($pdf, $num(), 'Your quotation', 'Prepared for ' . $p['business'] . ' on the ' . $q['package']['name'] . ' package. All amounts in ' . $curRow['name'] . '.');
    $money = fn($v) => '<span style="font-size:9.5pt;">' . pm_h(pm_money((float)$v, $cur)) . '</span>';
    $rows = [[
        '<b style="font-size:9.5pt;">' . pm_h($q['package']['name']) . ' package</b><br/>' . $grey((string)($tpl['package_note'] ?? 'Setup and onboarding, then subscription'), 8),
        $money($q['package']['setup']), $money($q['package']['monthly']),
    ]];
    foreach ($q['lines'] as $l) {
        $rows[] = [pm_h($l['name']) . ($l['qty'] > 1 ? ' <span style="color:#7a7f87;">× ' . $l['qty'] . '</span>' : ''),
            $l['period'] === 'once' ? $money($l['total']) : '', $l['period'] === 'month' ? $money($l['total']) : ''];
    }
    if ($q['discount_setup'] > 0 || $q['discount_monthly'] > 0) {
        $pct = fn($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
        $lbl = trim(($q['discount_setup'] > 0 ? $pct($q['discount_setup']) . '% off setup ' : '') . ($q['discount_monthly'] > 0 ? $pct($q['discount_monthly']) . '% off monthly' : ''));
        $rows[] = ['Discount <span style="color:#7a7f87;">(' . pm_h($lbl) . ')</span>',
            $q['discount_setup'] > 0 ? '- ' . $money($q['setup_gross'] - $q['setup']) : '',
            $q['discount_monthly'] > 0 ? '- ' . $money($q['monthly_gross'] - $q['monthly']) : ''];
    }
    $rows[] = ['_cells' => ['<b style="font-size:10pt;">Total</b>', '<b style="font-size:10pt;">' . pm_h(pm_money($q['setup'], $cur)) . '</b>',
        '<b style="font-size:10pt;">' . pm_h(pm_money($q['monthly'], $cur)) . '</b>' . (empty($tpl['free']) ? '<span style="font-size:8pt;color:#7a7f87;"> / month</span>' : '')], '_rule' => 'top-accent'];
    pm_grid($pdf, [['w' => 52], ['w' => 24, 'align' => 'R'], ['w' => 24, 'align' => 'R']], ['Item', 'Once', 'Monthly'], $rows);

    if ($q['monthly'] > 0 && $withPitch && empty($tpl['price_from']) && trim((string)($tpl['anchor_note'] ?? '')) !== '') {
        // Cost per day, in an inset panel on the same measure.
        $y = $pdf->GetY() + 1;
        if ($y + 20 > $pdf->getPageHeight() - 20) {
            $pdf->AddPage();
            $y = $pdf->GetY();
        }
        $pdf->SetFillColor(246, 247, 249);
        $pdf->Rect(20, $y, 170, 18, 'F');
        $pdf->SetFillColor($ar, $ag, $ab);
        $pdf->Rect(20, $y, 1.1, 18, 'F');
        $pdf->SetXY(26, $y + 3);
        $pdf->SetFont('times', '', 13.5);
        $pdf->SetTextColor(15, 15, 15);
        $pdf->Cell(160, 6.5, pm_money($q['monthly'], $cur) . ' a month is about ' . pm_money($daily, $cur) . ' a day.', 0, 2);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(85, 85, 85);
        $pdf->Cell(160, 5, (string)($tpl['anchor_note'] ?? ''), 0, 0);
        $pdf->SetY($y + 22);
    }
    if (!empty($tpl['price_from'])) {
        pm_note($pdf, 'These are starting prices (&quot;From&quot;). We confirm the exact price in writing, based on what you need, before you sign.');
    }
    pm_note($pdf, (($tpl['show_first_year'] ?? true) && empty($tpl['price_from']) ? 'First-year cost: <b>' . pm_h(pm_money($q['first_year'], $cur)) . '</b> (setup plus 12 months). ' : '') . 'Start date: ' . pm_h($startLabel) . '. ' . pm_h(pm_fees_note($tpl, $curRow)));

    if ($withPitch) {
        $exList = $compact ? [] : array_values(array_filter($tpl['extras'], fn($e) => trim((string)$e['name']) !== ''));
        if ($exList) $keep(function ($pdf) use ($num, $exList, $cur) {
            pm_section($pdf, $num(), 'Optional extras', 'Not included above. Add any of these now or later.');
            $rows = [];
            foreach ($exList as $ex) {
                $fee = (float)$ex['price'] > 0 ? pm_money((float)$ex['price'], $cur) . ($ex['period'] === 'month' ? ' / month' : '') . ($ex['note'] ? ' ' . $ex['note'] : '') : $ex['note'];
                $rows[] = [pm_h($ex['name']), '<span style="color:#3b4047;">' . pm_h($fee) . '</span>'];
            }
            pm_grid($pdf, [['w' => 55], ['w' => 45, 'align' => 'R']], ['Extra', 'Fee'], $rows);
        });

        $keep(function ($pdf) use ($num, $tpl, $accentHex, $grey) {
        pm_section($pdf, $num(), 'Getting started', (string)($tpl['steps_lead'] ?? 'Most businesses are live within three to four weeks of signing, depending on how quickly we receive your information. The setup fee covers every step below.'));
        $rows = [];
        foreach ($tpl['steps'] as $i => $st) {
            $rows[] = ['<span style="font-family:times;font-size:14pt;color:' . $accentHex . ';">' . ($i + 1) . '</span>',
                '<b style="font-size:9pt;">' . pm_h($st[0]) . '</b><br/>' . $grey($st[1], 8), $grey($st[2], 9)];
        }
        pm_grid($pdf, [['w' => 4], ['w' => 26], ['w' => 70]], null, $rows, ['vpad' => 2.4]);
        pm_note($pdf, pm_h($tpl['steps_note']));
        });

        if (!$compact) $keep(function ($pdf) use ($num, $tpl, $grey) {
            pm_section($pdf, $num(), 'Support', $tpl['support_intro']);
            $rows = [];
            foreach ($tpl['support'] as $su) {
                $rows[] = ['<b>' . pm_h($su[0]) . '</b>', $grey($su[1], 8.8), pm_h($su[2]), pm_h($su[3])];
            }
            pm_grid($pdf, [['w' => 13], ['w' => 43], ['w' => 22], ['w' => 22, 'align' => 'R']], ['Priority', 'Example', 'First response', 'Target fix'], $rows);
            pm_note($pdf, pm_h($tpl['support_note']));
        });
    }

    // ---------- Terms + Acceptance ----------
    if ($withContract) {
        $pdf->AddPage();
        $gainList = !empty($tpl['gains']) ? $tpl['gains'] : array_map(fn($b) => $b[0] . ': ' . $b[1], array_slice(array_values(array_filter($tpl['benefits'] ?? [], fn($x) => in_array($x[2] ?? 'all', ['all', '', $q['package']['type'] ?? 'all'], true))), 0, 4));
        if ($gainList) {
            pm_section($pdf, $num(), 'What this agreement gives you', 'In plain words, before the detail.');
            pm_html($pdf, '<table cellpadding="0" cellspacing="0">' . implode('', array_map(fn($g) => '<tr><td width="4%"><span style="color:' . $accentHex . ';font-family:dejavusans;">&#10003;</span></td><td width="96%"><span style="font-size:9.5pt;color:#1f2328;">' . pm_h($g) . '</span></td></tr>', $gainList)) . '</table>', 1);
        }
        pm_section($pdf, $num(), 'Terms of service', 'This agreement is between ' . $s['company_name'] . ' ("we") and ' . $p['business'] . ' ("you"). It starts on the start date on the Acceptance page.');
        $rows = [];
        $termList = $tpl['terms'];
        if (!empty($tpl['price_from'])) {
            array_unshift($termList, ['Final price', 'The prices shown are starting prices. The price that applies is the one confirmed in writing by us before you sign and recorded on the Acceptance page.']);
        }
        foreach ($termList as $i => $t) {
            $rows[] = ['_cells' => ['<span style="font-size:8.3pt;color:' . $accentHex . ';">' . ($i + 1) . '.</span>',
                '<span style="font-size:8.3pt;"><b>' . pm_h($t[0]) . '.</b> <span style="color:#3b4047;">' . pm_h($t[1]) . '</span></span>'], '_rule' => 'none'];
        }
        pm_grid($pdf, [['w' => 4], ['w' => 96]], null, $rows, ['gap' => 1.5, 'vpad' => 1.0]);

        pm_acceptance($pdf, $s, $tpl, $p, $q, $ref, $num(), [
            'cur' => $cur, 'docLabel' => $docLabel, 'validUntil' => $validUntil, 'startLabel' => $startLabel,
            'signUrl' => $signUrl, 'signed' => $signed, 'accentHex' => $accentHex,
        ]);

        if ($signed) {
            pm_certificate($pdf, $s, $p, $ref, $docLabel, $signed);
        }
    }

    if (!is_dir(PM_OUT)) {
        mkdir(PM_OUT, 0775, true);
    }
    $file = $opt['file'] ?? (PM_OUT . '/' . $ref . '_' . pm_slug($p['business']) . ($signed ? '_SIGNED' : '') . '.pdf');
    $pdf->Output($file, 'F');
    return $file;
}

/** The acceptance page: deal summary, sign-online block, and two signature panels. */
function pm_acceptance(PM_PDF $pdf, array $s, array $tpl, array $p, array $q, string $ref, string $num, array $o): void
{
    [$ar, $ag, $ab] = $pdf->accent;
    $signed = $o['signed'];
    $cur = $o['cur'];
    $pdf->AddPage();
    pm_section($pdf, $num, $signed ? 'Accepted and signed' : 'Ready to go ahead?', $signed
        ? 'This ' . strtolower($o['docLabel']) . ' was accepted and signed electronically. The signature certificate is on the last page.'
        : 'Signing accepts this ' . strtolower($o['docLabel']) . ', the quotation and the terms of service. This offer is valid until ' . $o['validUntil'] . '.');

    // Deal summary strip
    $y = $pdf->GetY() + 1;
    $pdf->SetDrawColor(225, 227, 230);
    $pdf->SetLineWidth(0.25);
    $pdf->Line(20, $y, 190, $y);
    $cells = [['Package', $q['package']['name']], ['Setup, once', pm_money($q['setup'], $cur)], ['Monthly', pm_money($q['monthly'], $cur)], ['Start date', $o['startLabel']]];
    $w = 170 / 4;
    foreach ($cells as $i => [$label, $value]) {
        $x = 20 + $i * $w;
        $pdf->SetXY($x + ($i ? 4 : 0), $y + 4);
        $pdf->SetFont('helvetica', '', 6.8);
        $pdf->SetTextColor(125, 125, 125);
        $pdf->setFontSpacing(0.3);
        $pdf->Cell($w - 4, 4, mb_strtoupper($label), 0, 0);
        $pdf->setFontSpacing(0);
        $pdf->SetXY($x + ($i ? 4 : 0), $y + 9);
        pm_fit($pdf, (string)$value, 'times', '', 14, $w - 5, 8.5);
        $pdf->SetTextColor(15, 15, 15);
        $pdf->Cell($w - 4, 7, pm_clip($pdf, (string)$value, $w - 5), 0, 0);
        if ($i) {
            $pdf->Line($x, $y + 3, $x, $y + 18);
        }
    }
    $pdf->Line(20, $y + 21, 190, $y + 21);
    $pdf->SetY($y + 27);

    // Sign online
    if (!$signed && $o['signUrl'] !== '' && !empty($s['online_signing'])) {
        $by = $pdf->GetY();
        $pdf->SetFillColor(246, 247, 249);
        $pdf->Rect(20, $by, 170, 34, 'F');
        $pdf->SetFillColor($ar, $ag, $ab);
        $pdf->Rect(20, $by, 1.2, 34, 'F');
        $pdf->write2DBarcode($o['signUrl'], 'QRCODE,M', 158, $by + 3.5, 27, 27, ['border' => false, 'padding' => 0, 'fgcolor' => [15, 15, 15], 'bgcolor' => false]);
        $pdf->SetXY(28, $by + 5);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetTextColor($ar, $ag, $ab);
        $pdf->setFontSpacing(0.4);
        $pdf->Cell(120, 4, 'FASTEST', 0, 1);
        $pdf->setFontSpacing(0);
        $pdf->SetX(28);
        $pdf->SetFont('times', '', 15);
        $pdf->SetTextColor(15, 15, 15);
        $pdf->Cell(120, 8, 'Accept and sign online in two minutes', 0, 1);
        $pdf->SetX(28);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell(122, 4.4, 'Scan the code or open the link, check the details and sign with your finger or mouse. A signed copy is emailed to you straight away.', 0, 'L', false, 1, 28);
        $pdf->SetX(28);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor($ar, $ag, $ab);
        $pdf->Cell(122, 5, preg_replace('#^https?://#', '', $o['signUrl']), 0, 1, 'L', false, $o['signUrl']);
        $pdf->SetY($by + 40);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(140, 140, 140);
        $pdf->Cell(0, 4, 'OR SIGN THIS PAGE  ·  type into the fields in any PDF reader, or print, sign and scan', 0, 1, 'C');
        $pdf->Ln(3);
    }

    // Client details
    $field = function (string $label, string $name, string $value, float $x, float $yy, float $w) use ($pdf, $signed) {
        pm_kv($pdf, $label, $signed ? $value : '', $x, $yy, $w);
        if (!$signed) {
            pm_fit($pdf, $value, 'helvetica', '', 10, $w - 2, 6.5);
            $pdf->SetTextColor(20, 20, 20);
            $pdf->TextField($name, $w, 6, [], ['v' => $value, 'dv' => $value], $x, $yy + 4);
        }
        $pdf->SetDrawColor(190, 192, 196);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($x, $yy + 10.5, $x + $w, $yy + 10.5);
    };
    $pdf->setFormDefaultProp(['lineWidth' => 0, 'borderStyle' => 'solid', 'fillColor' => [255, 255, 255], 'strokeColor' => [255, 255, 255]]);
    $y = $pdf->GetY();
    $field('Business name', 'business_name', $p['business'], 20, $y, 80);
    $field('TPIN', 'tpin', (string)($signed['tpin'] ?? $p['tpin'] ?? ''), 110, $y, 80);
    $y += 15;
    $field('Registered address', 'address', (string)($p['address'] ?? ''), 20, $y, 80);
    $field('Contact phone', 'contact_phone', (string)($p['phone'] ?? ''), 110, $y, 80);
    $y += 19;

    // Signature panels
    $panel = function (float $x, string $heading, array $who, ?array $sig, string $prefix, bool $isClient) use ($pdf, $s, $signed, $ar, $ag, $ab) {
        $py = $pdf->GetY();
        $pdf->SetXY($x, $py);
        $pdf->SetTextColor($ar, $ag, $ab);
        $pdf->setFontSpacing(0.4);
        pm_fit($pdf, mb_strtoupper($heading), 'helvetica', 'B', 7.5, 78, 5.5);
        $pdf->Cell(80, 5, pm_clip($pdf, mb_strtoupper($heading), 78), 0, 0);
        $pdf->setFontSpacing(0);
        $boxY = $py + 7;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(214, 217, 222);
        $pdf->SetLineWidth(0.25);
        $pdf->RoundedRect($x, $boxY, 80, 30, 2, '1111', 'DF');
        if ($sig && !empty($sig['image']) && is_file($sig['image'])) {
            $pdf->Image($sig['image'], $x + 6, $boxY + 3, 68, 22, '', '', '', true, 300, '', false, false, 0, 'CM');
        } elseif ($sig && !empty($sig['typed'])) {
            $pdf->SetXY($x + 4, $boxY + 7);
            $pdf->SetFont('times', 'I', 24);
            $pdf->SetTextColor(20, 20, 40);
            $pdf->Cell(72, 12, $sig['typed'], 0, 0, 'C');
        } else {
            $pdf->SetXY($x + 5, $boxY + 22);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetTextColor(170, 170, 170);
            $pdf->Cell(40, 4, 'Sign here', 0, 0);
            $pdf->SetDrawColor(200, 202, 206);
            $pdf->Line($x + 5, $boxY + 21, $x + 75, $boxY + 21);
            if (!$signed && $isClient) {
                // Typeable signature field. Its name follows DocuSign's PDF-field convention, so DocuSign
                // turns it into a Sign Here field when "transform PDF fields" is on.
                $pdf->TextField('DocuSignSignHere_Client', 70, 12, [], [], $x + 5, $boxY + 8);
                if (!empty($s['esign_tags'])) {
                    // Invisible Adobe Sign text tag + DocuSign anchor text (white, 1pt).
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont('helvetica', '', 1);
                    $pdf->SetXY($x + 5, $boxY + 16);
                    $pdf->Cell(70, 1, '{{Sig_es_:signer1:signature}}  \\s1\\', 0, 0);
                }
            }
        }
        if ($sig && !empty($sig['caption'])) {
            $pdf->SetXY($x + 4, $boxY + 25.2);
            $pdf->SetFont('helvetica', '', 6.3);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->Cell(72, 3.5, $sig['caption'], 0, 0, 'C');
        }
        $fy = $boxY + 34;
        foreach ([['Name', 'name'], ['Title', 'title'], ['Date', 'date']] as [$label, $key]) {
            pm_kv($pdf, $label, ($signed || !$isClient) ? (string)($who[$key] ?? '') : '', $x, $fy, 80, 9.5);
            if (!$signed && $isClient) {
                $pdf->SetFont('helvetica', '', 9.5);
                $pdf->SetTextColor(20, 20, 20);
                $fname = $key === 'date' ? 'DocuSignDateSigned_Client' : $prefix . '_' . $key;
                $pdf->TextField($fname, 80, 6, [], ['v' => (string)($who[$key] ?? ''), 'dv' => (string)($who[$key] ?? '')], $x, $fy + 4);
                if ($key === 'date' && !empty($s['esign_tags'])) {
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont('helvetica', '', 1);
                    $pdf->SetXY($x + 40, $fy + 6);
                    $pdf->Cell(38, 1, '{{Dte_es_:signer1:date}}  \\d1\\', 0, 0);
                }
            }
            $pdf->SetDrawColor(190, 192, 196);
            $pdf->SetLineWidth(0.2);
            $pdf->Line($x, $fy + 10.5, $x + 80, $fy + 10.5);
            $fy += 14;
        }
        return $fy;
    };

    $pdf->SetY($y);
    $clientWho = $signed
        ? ['name' => $signed['name'], 'title' => $signed['title'], 'date' => date('j F Y', strtotime($signed['at']))]
        : ['name' => $p['contact'], 'title' => $p['contact_title'] ?? '', 'date' => ''];
    $clientSig = $signed ? [
        'image' => $signed['image'] ?? '', 'typed' => $signed['typed'] ?? '',
        'caption' => 'Signed electronically · ' . date('j M Y, H:i', strtotime($signed['at'])) . ' · ' . ($signed['ip'] ?? ''),
    ] : null;
    $endA = $panel(20, 'For ' . $p['business'], $clientWho, $clientSig, 'client', true);

    $pdf->SetY($y);
    $ourSigFile = pm_signature_path();
    $issued = date('j F Y', strtotime(($signed['sent_at'] ?? '') ?: ($p['date'] ?: 'now')));
    if (is_file($ourSigFile)) {
        $ourSig = ['image' => $ourSigFile, 'caption' => 'Issued and signed by ' . $s['company_name'] . ' · ' . $issued];
    } elseif ($signed) {
        // Countersigned at issue: our signatory's name adopted as signature on the signed copy.
        $ourSig = ['typed' => $s['signatory_name'] ?: $s['company_name'], 'caption' => 'Issued and signed electronically by ' . $s['company_name'] . ' · ' . $issued];
    } else {
        $ourSig = null;
    }
    $ourWho = ['name' => $s['signatory_name'], 'title' => $s['signatory_title'], 'date' => ($ourSig ? $issued : '')];
    $endB = $panel(110, 'For ' . $s['company_name'], $ourWho, $ourSig, 'pm', false);

    $pdf->SetY(max($endA, $endB) + 2);
    $pdf->SetFont('helvetica', '', 7.3);
    $pdf->SetTextColor(135, 135, 135);
    $pdf->MultiCell(0, 3.8, $signed
        ? 'Reference ' . $ref . '. Signed copies have been sent to ' . ($signed['email'] ?? 'the signer') . ' and ' . ($s['email'] ?: $s['company_name']) . '.'
        : 'Reference ' . $ref . '. Compatible with DocuSign and Adobe Acrobat Sign. Questions before signing: ' . trim(($s['phone'] ? $s['phone'] . ' · ' : '') . $s['email'], ' ·') . '.', 0, 'L');
}

/** Final page of a signed document: who signed, when, from where, and the document fingerprint. */
function pm_certificate(PM_PDF $pdf, array $s, array $p, string $ref, string $docLabel, array $sg): void
{
    $pdf->AddPage();
    pm_section($pdf, 'CERTIFICATE', 'Signature certificate', 'An electronic record of how and when this ' . strtolower($docLabel) . ' was accepted.');
    $rows = [
        ['Document', $docLabel . ' for ' . $p['business']],
        ['Reference', $ref],
        ['Document fingerprint (SHA-256 of the copy sent)', $sg['hash'] ?? ''],
        ['Sent', !empty($sg['sent_at']) ? date('j F Y, H:i', strtotime($sg['sent_at'])) : '—'],
        ['First opened', !empty($sg['viewed_at']) ? date('j F Y, H:i', strtotime($sg['viewed_at'])) : '—'],
        ['Signed', date('j F Y, H:i:s', strtotime($sg['at'])) . ' (' . date_default_timezone_get() . ')'],
        ['Signed by', $sg['name'] . ($sg['title'] ? ', ' . $sg['title'] : '') . ' for ' . $p['business']],
        ['Email', $sg['email'] ?? ''],
        ['Signature method', ($sg['method'] ?? 'drawn') === 'typed' ? 'Typed name, adopted as signature' : 'Drawn by hand on screen'],
        ['IP address', $sg['ip'] ?? ''],
        ['Device', mb_substr((string)($sg['ua'] ?? ''), 0, 160)],
        ['Agreement statement', $sg['consent'] ?? ''],
    ];
    $cells = [];
    foreach ($rows as [$k, $v]) {
        $val = str_contains($k, 'SHA')
            ? '<span style="font-family:courier;font-size:7.6pt;">' . pm_h((string)$v) . '</span>'
            : '<span style="font-size:8.8pt;">' . pm_h((string)$v) . '</span>';
        $cells[] = ['<span style="font-size:8pt;color:#7a7f87;">' . pm_h($k) . '</span>', $val];
    }
    pm_grid($pdf, [['w' => 30], ['w' => 70]], null, $cells);
    pm_note($pdf, '<span style="font-size:7.5pt;color:#888888;">The signer reviewed the document online, confirmed the agreement statement above and signed. This certificate was generated automatically by ' . pm_h($s['company_name']) . ' at the moment of signing.</span>');
}
