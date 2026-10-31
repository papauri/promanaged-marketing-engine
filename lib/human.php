<?php
/**
 * The human voice layer. Everything a customer reads that an AI helped write (emails, WhatsApp messages, follow-ups, replies, posts, profile words) goes through two things:
 *  1. PM_HUMAN_RULES, a few lines added to the writers' instructions: plain words, one specific detail instead of a general claim, none of the stock phrases people now recognise as machine-written.
 *  2. pm_human_polish(), a code pass over what comes back (no AI, no cost): the tells that slip through anyway are fixed: em dashes, stock openers, buzzwords, stacked exclamation marks, emoji in an email.
 * pm_human_flags() lists what is still left, so a text that cannot be made natural by code is refused rather than sent. The wording is checked against what is widely listed as AI tells:
 * delve / leverage / seamless / robust / elevate / unlock / game-changer, "thrilled to announce", "in today's fast-paced world", "not just X, it's Y", habitual lists of three, and the em dash.
 */

const PM_HUMAN_RULES = "Sound like one real person writing to another: plain everyday words, short sentences, and one specific detail instead of a general claim (a competitor must not be able to paste your sentence on their own page). "
    . "Never use: delve, leverage, seamless, robust, elevate, unlock, game-changer, cutting-edge, streamline, empower, journey, transformative, \"in today's fast-paced world\", \"I hope this finds you well\", \"thrilled to announce\", "
    . "\"not just X, but Y\". No em dashes. Do not list things in threes out of habit, and do not end with a slogan.";

/** How the best marketers make words sell, kept honest: for profile words, covers and reworded posts (a few dozen tokens). */
const PM_MARKETING_PSYCH = "Use what makes people act, honestly: lead with the outcome the customer wants or the problem they want gone, in the words a customer would use (benefit before feature); "
    . "name who it is for so the right people recognise themselves; one concrete detail from the facts beats any adjective; proof (years, clients, places, reviews) only when the facts carry it; "
    . "a familiar local cue (the town) builds trust; make the next step small and easy (message us, ask a question) when there is room. Warm and confident, never hype, fake urgency or pressure.";

/** Words and phrases swapped for plain ones (whole words, case kept at the start). */
const PM_HUMAN_SWAPS = [
    'game-changer' => 'big help', 'game changer' => 'big help', 'game-changing' => 'useful', 'cutting-edge' => 'modern', 'state-of-the-art' => 'modern', 'best-in-class' => 'strong',
    'take it to the next level' => 'make it better', 'take your business to the next level' => 'make your business stronger', 'unlock the power of' => 'use', 'harness the power of' => 'use',
    'harnessing the power of' => 'using', 'delve into' => 'look at', 'delving into' => 'looking at', 'dive into' => 'look at', 'navigate the complexities of' => 'deal with',
    'streamline' => 'simplify', 'streamlines' => 'simplifies', 'streamlined' => 'simpler', 'streamlining' => 'simplifying', 'leverage' => 'use', 'leverages' => 'uses', 'leveraging' => 'using', 'leveraged' => 'used',
    'utilize' => 'use', 'utilizes' => 'uses', 'utilise' => 'use', 'utilises' => 'uses', 'seamlessly' => 'easily', 'seamless' => 'easy', 'robust' => 'solid', 'elevate' => 'improve', 'elevates' => 'improves', 'elevated' => 'better',
    'unlock' => 'open up', 'unlocks' => 'opens up', 'empower' => 'help', 'empowers' => 'helps', 'empowering' => 'helping', 'revolutionize' => 'change', 'revolutionise' => 'change', 'revolutionary' => 'new',
    'transformative' => 'useful', 'holistic' => 'complete', 'synergy' => 'fit', 'paradigm' => 'way', 'pivotal' => 'important', 'myriad of' => 'many', 'a myriad of' => 'many', 'plethora of' => 'many',
];

/** Stock openers and filler removed whole. */
const PM_HUMAN_CUTS = [
    '/\b(?:I|We) hope (?:this|that) (?:email|message|note|finds? you)[^.!?\n]*[.!?]\s*/i', '/\bI trust (?:this|that) (?:email|message|note)[^.!?\n]*[.!?]\s*/i',
    '/\bIn today\'s (?:fast[- ]paced|digital|competitive|ever[- ]changing|modern)[a-z\- ]*?(?:world|landscape|environment|era|age|market)\b,?\s*/i', '/\bAt the end of the day,?\s*/i',
    '/\bIt(?:\'s| is) no secret that\s*/i', '/\bNeedless to say,?\s*/i', '/\bI (?:just )?wanted to (?:reach out|touch base)(?: and| to)?\s*/i', '/\bI am reaching out (?:to|because)\s*/i',
];

/** Stock openers swapped for something a person would write. */
const PM_HUMAN_REWORD = [
    '/\bI(?:\'m| am) (?:so |really )?(?:thrilled|excited|delighted) to (?:announce|share|introduce)\b/i' => 'I wanted to tell you about',
    '/\bWe(?:\'re| are) (?:so |really )?(?:thrilled|excited|delighted) to (?:announce|share|introduce)\b/i' => 'We are glad to tell you about',
    '/\bThrilled to (?:announce|share)\b/i' => 'Glad to share', '/\bExcited to (?:announce|share)\b/i' => 'Glad to share',
    '/\bI(?:\'m| am) reaching out\b/i' => 'I am writing', '/\bJust (?:a quick )?(?:checking|touching) (?:in|base)\b/i' => 'A quick follow-up',
    // stiff office English that no one says out loud, swapped for what a person would say across the table
    '/\b(?:Please )?(?:Do not|Don\'t|do not|don\'t) hesitate to (?:contact|call|reach out to|get in touch with|reach) (?:me|us)\b/' => 'Just reply here any time',
    '/\bat your earliest convenience\b/i' => 'when you have a moment', '/\bPlease find attached\b/' => 'I have attached', '/\bplease find attached\b/' => 'I have attached',
    '/\b(?:will|shall) revert(?: back)?(?: to you)?\b/i' => 'will get back to you', '/\b(?:Kindly|Please) revert(?: back)?(?: to (?:me|us))?\b/' => 'Please get back to me', '/\b(?:kindly|please) revert(?: back)?(?: to (?:me|us))?\b/' => 'please get back to me',
    '/\bKindly\b/' => 'Please', '/\bkindly\b/' => 'please', '/\byour good selves?\b/i' => 'you',
    '/\b[Ii]n regards? to\b/' => 'about', '/\bWith regards? to\b/' => 'About', '/\bwith regards? to\b/' => 'about', '/\bAs per\b/' => 'Following', '/\bas per\b/' => 'following',
    '/\b(?:We|I) (?:are|am) (?:pleased|delighted|happy) to inform you that\s*/i' => "\x01", '/\bI am writing to (?:inform you|let you know) that\s*/i' => "\x01",
    '/\b([Ww]e|I) would like to take this opportunity to\b/' => '$1 want to',
];

/** Signs that a message talks like a letterhead instead of a person: [regex, the tip]. Checked by pm_human_read, never refused on. */
const PM_HUMAN_STIFF = [
    '/\b(?:esteemed|valued (?:client|customer|partner)|the undersigned|herewith|aforementioned|hereby)\b/i' => 'letter-style words (esteemed, herewith, the undersigned)',
    '/\b(?:our (?:solutions?|services offering|value proposition)|one-stop shop|end-to-end|synerg)/i' => 'brochure words (solutions, one-stop shop)',
    '/\b(?:touch base|reach out|circle back|loop in)\b/i' => 'office jargon (reach out, touch base)',
];

/** What is still wrong with a text: a list of short labels (empty = it reads naturally). */
function pm_human_flags(string $text, string $channel = 'email'): array
{
    $f = [];
    $t = $text;
    if (preg_match('/[\x{2014}]|\s--\s/u', $t)) {
        $f[] = 'em dash';
    }
    foreach (array_keys(PM_HUMAN_SWAPS) as $w) {
        if (preg_match('/(?<![\p{L}-])' . preg_quote($w, '/') . '(?![\p{L}-])/iu', $t)) {
            $f[] = $w;
        }
    }
    foreach (PM_HUMAN_CUTS as $re) {
        if (preg_match($re, $t, $m)) {
            $f[] = trim(mb_substr($m[0], 0, 30));
        }
    }
    if (preg_match('/\b(?:not (?:just|only) [^.!?\n]{2,60}?,? (?:but|it\'s|it is)|it\'s not (?:just )?[^.!?\n]{2,40}?, it\'s)\b/i', $t)) {
        $f[] = 'not just X, but Y';
    }
    if ($channel === 'email' && preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $t)) {
        $f[] = 'emoji in an email';
    }
    if (substr_count($t, '!') > ($channel === 'email' ? 0 : 1)) {
        $f[] = 'too many exclamation marks';
    }
    return array_values(array_unique($f));
}

/**
 * Fixes the tells that slip through, in code. $channel: email | subject | whatsapp | reply | post | profile. Numbers, links, phone numbers and the meaning are never touched.
 * Idempotent: polishing a polished text changes nothing.
 */
function pm_human_polish(string $text, string $channel = 'email'): string
{
    $t = str_replace("\r\n", "\n", function_exists('pm_no_usd') ? pm_no_usd($text) : $text); // never US dollars (lib/localcur.php)
    if (trim($t) === '') {
        return trim($t);
    }
    // dashes: a ranged en dash stays a hyphen (9-5, Mon-Fri); a break in a sentence becomes a comma, or a full stop before a capital
    $t = preg_replace('/(\p{L}|\d)\x{2013}(\p{L}|\d)/u', '$1-$2', $t);
    $t = preg_replace('/\s*\x{2014}\s*(?=\p{Lu})/u', '. ', (string)$t);
    $t = preg_replace('/\s*(?:\x{2014}|\s\x{2013}\s|\s--\s)\s*/u', ', ', (string)$t);
    $t = preg_replace('/,\s*,/', ',', (string)$t);
    $t = preg_replace('/,\s*([.!?])/', '$1', (string)$t);
    // stock openers and filler (a marker is left where one was cut, so the next word can start with a capital)
    foreach (PM_HUMAN_CUTS as $re) {
        $t = preg_replace($re, "\x01", (string)$t);
    }
    foreach (PM_HUMAN_REWORD as $re => $to) {
        $t = preg_replace($re, $to, (string)$t);
    }
    $t = preg_replace_callback('/\x01(\p{Ll})?/u', fn($m) => isset($m[1]) ? mb_strtoupper($m[1]) : '', (string)$t);
    // buzzwords, longest first, keeping a capital letter at the start of a sentence
    $swaps = PM_HUMAN_SWAPS;
    uksort($swaps, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($swaps as $from => $to) {
        $t = preg_replace_callback('/(?<![\p{L}-])' . preg_quote($from, '/') . '(?![\p{L}-])/iu', fn($m) => preg_match('/^\p{Lu}/u', $m[0]) ? mb_strtoupper(mb_substr($to, 0, 1)) . mb_substr($to, 1) : $to, (string)$t);
    }
    // exclamation marks and emoji
    if ($channel === 'email' || $channel === 'subject' || $channel === 'profile') {
        $t = str_replace('!', '.', (string)$t);
        $t = preg_replace('/\.{2,}(?!\.)/', '.', (string)$t);
        if ($channel !== 'profile') {
            $t = preg_replace('/\s*[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]+\s*/u', ' ', (string)$t);
        }
    } elseif ($channel === 'whatsapp' || $channel === 'reply') {
        $n = 0;
        $t = preg_replace_callback('/!+/', function ($m) use (&$n) {
            return ++$n === 1 ? '!' : '.';
        }, (string)$t);
    }
    // tidy
    $t = preg_replace('/[ \t]{2,}/', ' ', (string)$t);
    $t = preg_replace('/ +([,.;:!?])/', '$1', (string)$t);
    $t = preg_replace('/\n{3,}/', "\n\n", (string)$t);
    $t = trim((string)$t);
    return preg_replace_callback('/^\p{Ll}/u', fn($m) => mb_strtoupper($m[0]), $t) ?? $t;
}

/** Polishes named fields of a list of rows (what a writer returned). $fields: field => channel. Rows that are not arrays are left alone. */
function pm_human_rows(array $rows, array $fields): array
{
    foreach ($rows as $i => $r) {
        if (!is_array($r)) {
            continue;
        }
        foreach ($fields as $f => $ch) {
            if (isset($r[$f]) && is_string($r[$f])) {
                $rows[$i][$f] = pm_human_polish($r[$f], $ch);
            }
        }
    }
    return $rows;
}

/**
 * How a message reads to the person getting it, measured by code (free): [[short label, what to change]], empty when it reads like a person talking.
 * Looked at: talking about us more than them, sentences too long to say in one breath, a wall of a paragraph, more than one question, letter-style words.
 * Advice only: the sit-down reads it, the message box shows it, and New wording fixes exactly these when the owner gives no note.
 */
function pm_human_read(string $text): array
{
    $t = trim(str_replace("\r\n", "\n", $text));
    if ($t === '' || str_word_count($t) < 20) {
        return [];
    }
    $body = (string)preg_replace('/^[^\n]{0,60},\s*\n/u', '', $t); // the greeting line is not a sentence
    $out = [];
    $we = preg_match_all('/\b(we|our|us|i|my|me)\b/i', $body);
    $you = preg_match_all('/\b(you|your|yours)\b/i', $body);
    if ($we >= 4 && $we > $you * 1.5) {
        $out[] = ['about us more than them', 'talk about them more than about us: turn "we offer" sentences into what they get ("you can...")'];
    }
    $long = 0;
    foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $body) ?: [] as $s) {
        $long = max($long, str_word_count($s));
    }
    if ($long > 28) {
        $out[] = ['a ' . $long . '-word sentence', 'split the ' . $long . '-word sentence in two, the way you would say it out loud'];
    }
    foreach (preg_split('/\n\s*\n/u', $body) ?: [] as $p) {
        if (str_word_count($p) > 75) {
            $out[] = ['a wall of text', 'break the long paragraph into two short ones'];
            break;
        }
    }
    if (($q = substr_count($body, '?')) > 2) {
        $out[] = [$q . ' questions', 'keep one question at the end, the easiest one to answer'];
    }
    foreach (PM_HUMAN_STIFF as $re => $label) {
        if (preg_match($re, $body)) {
            $out[] = [$label, 'swap the ' . $label . ' for everyday words'];
        }
    }
    return $out;
}

/** pm_human_read as a note for New wording: what to change, in one line ('' when nothing). */
function pm_human_read_note(string $text): string
{
    $r = pm_human_read($text);
    return $r ? 'Make it sound like one person talking to another: ' . implode('; ', array_column($r, 1)) . '. Keep everything else.' : '';
}
