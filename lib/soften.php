<?php
/**
 * Making hard leads soft, decided by code (no AI, free): how warm a lead is, what is holding them back, and the one move the firm makes next.
 * The writers, the follow-up head, the sit-down and the win-back agent are all handed the same move, so the firm acts as one and never repeats a move that did not work.
 *   pm_objection_guess($text)    'price' | 'timing' | 'competitor' | 'no_need' | 'no_trust' | ''  (what is holding them back, from their words)
 *   pm_soften($lead, $maxFu)     ['temp','temp_label','why','play','label','move']  (how warm, and the next move)
 *   pm_soften_note($lead)        the move as one line for a writer's instructions
 */

/** The moves, softest first: key => [short label, what the writer does]. Every one stays inside the honesty rules: facts only, no prices, no pressure. */
const PM_SOFTEN_PLAYS = [
    'book' => ['Book the next step', 'They are warm: make the next step concrete and easy, offer two times for a 10-minute call or to send the one-page plan, nothing else to decide.'],
    'value_first' => ['Give first', 'Open with one small useful thing for them from what we know about them (a tip they can use today, no strings attached), then one easy question.'],
    'yes_no' => ['A yes or no question', 'Ask one question they can answer in one word ("Worth a short look, yes or no?"); nothing else to read or decide.'],
    'right_person' => ['Ask for the right person', 'Ask kindly whether someone else looks after this, so you can write to them instead; no pitch.'],
    'proof' => ['Show, do not tell', 'Give one concrete thing we have done or can show, from the facts only (never a number nobody gave), and offer to show it in 10 minutes.'],
    'smaller_step' => ['A smaller first step', 'Offer the smallest first step (a one-page plan, a 10-minute look, one part only); never a price.'],
    'channel' => ['Change channel', 'Same point in one line, and suggest a quick WhatsApp or call instead of email.'],
    'their_date' => ['Their own timing', 'Respect their timing: say you will come back when they said, and leave one useful thing now.'],
    'second_opinion' => ['A second opinion', 'Never knock whoever they use: offer to be a backup or a second pair of eyes, with no switching asked.'],
    'new_trigger' => ['Something new', 'Bring one genuinely new, true reason from what we know about them (news, a change, a new offering).'],
    'close_file' => ['Close the file politely', 'Say kindly this is the last note about it and ask whether to close their file or come back another time; warm, no pressure.'],
];

/** What is holding them back, read from their words by code (the reply agent's own answer wins when it gives one). '' = nothing clear. */
function pm_objection_guess(string $text): string
{
    $t = mb_strtolower(mb_substr($text, 0, 1500));
    return match (true) {
        (bool)preg_match('/\b(too expensive|can\'?t afford|cannot afford|no budget|not in (the|our) budget|budget is|cost(s)? too|pricey|money is tight|no funds|funds are)\b/u', $t) => 'price',
        (bool)preg_match('/\b(already (have|use|work with|got)|we have (someone|a company|a provider|an it)|our (it|web|own) (guy|company|team|person|provider)|in[- ]house|under contract|contract with)\b/u', $t) => 'competitor',
        (bool)preg_match('/\b(not (right )?now|maybe later|later (this|in the) year|next (year|month|quarter|season)|too busy|very busy|bad time|after the (season|holidays?)|not the right time|get back to (you|us) (later|next))\b/u', $t) => 'timing',
        (bool)preg_match('/\b(is this (a )?scam|is this legit|who are you|how did you get (my|our)|never heard of you|don\'?t know you)\b/u', $t) => 'no_trust',
        (bool)preg_match('/\b(don\'?t need|do not need|no need|not necessary|we\'?re fine|we are fine|happy with what we have|works fine for us|we are okay)\b/u', $t) => 'no_need',
        default => '',
    };
}

/** How warm a lead is and why: ['hot'|'warm'|'cool'|'hard', why]. Code only, from what they did. */
function pm_soften_temp(array $l): array
{
    $intent = (string)($l['reply_intent'] ?? '');
    $views = (int)($l['view_count'] ?? 0);
    $fu = (int)($l['followups'] ?? 0);
    if (!empty($l['pitch_response']) || $views >= 2 || (in_array($intent, ['interested', 'question', 'meeting_request'], true) && in_array($l['status'] ?? '', ['replied', 'proposal', 'contacted'], true))) {
        return ['hot', !empty($l['pitch_response']) ? 'answered on the proposal page' : ($views >= 2 ? 'opened the proposal ' . $views . ' times' : 'wrote back with interest')];
    }
    if (!empty($l['objection']['kind']) || in_array($intent, ['not_now', 'not_interested'], true) || ($l['status'] ?? '') === 'lost') {
        return ['hard', 'said ' . (in_array($intent, ['not_now'], true) ? 'not now' : 'no') . (!empty($l['objection']['kind']) ? ' (' . str_replace('_', ' ', (string)$l['objection']['kind']) . ')' : '')];
    }
    if ($fu >= 2 || !empty($l['email_bad'])) {
        return ['hard', !empty($l['email_bad']) ? 'the email bounced' : $fu . ' follow-ups with no answer'];
    }
    if ($views === 1 || !empty($l['opened_at']) || !empty($l['last_reply'])) {
        return ['warm', !empty($l['last_reply']) ? 'has written to us before' : 'opened what we sent'];
    }
    return ['cool', !empty($l['sent']) ? 'no answer yet' : 'not written to yet'];
}

/**
 * The next move for this lead: the objection's own move when they told us what holds them back, the booking move when they are warm, else the next rung of the
 * ladder (give first, a yes or no question, the right person, proof, another channel, the polite last note), skipping a move already used on them.
 */
function pm_soften(array $l, int $maxFu = 3): array
{
    [$temp, $why] = pm_soften_temp($l);
    $used = array_column(array_slice((array)($l['plays'] ?? []), -6), 'play');
    $phone = trim((string)($l['phone'] ?? '') . (string)($l['whatsapp'] ?? '')) !== '';
    $nth = (int)($l['followups'] ?? 0) + 1;
    $obj = (string)($l['objection']['kind'] ?? '');
    $byObj = ['price' => 'smaller_step', 'timing' => 'their_date', 'competitor' => 'second_opinion', 'no_need' => 'new_trigger', 'no_trust' => 'proof'];
    if ($obj !== '' && isset($byObj[$obj])) {
        $play = $byObj[$obj];
    } elseif ($temp === 'hot') {
        $play = 'book';
    } elseif (!empty($l['sent']) && $nth >= max(2, $maxFu) && ($l['status'] ?? '') === 'contacted') {
        $play = 'close_file'; // the last follow-up allowed
    } else {
        $ladder = empty($l['sent']) ? ['value_first'] : ($temp === 'warm' ? ['proof', 'smaller_step', 'yes_no'] : ['yes_no', trim((string)($l['contact'] ?? '')) === '' ? 'right_person' : 'proof', $phone ? 'channel' : 'new_trigger', 'smaller_step']);
        $play = $ladder[0];
        foreach ($ladder as $p) {
            if (!in_array($p, $used, true)) {
                $play = $p;
                break;
            }
        }
    }
    return ['temp' => $temp, 'temp_label' => ['hot' => 'Hot', 'warm' => 'Warm', 'cool' => 'Cool', 'hard' => 'Hard'][$temp], 'why' => $why,
        'play' => $play, 'label' => PM_SOFTEN_PLAYS[$play][0], 'move' => PM_SOFTEN_PLAYS[$play][1]];
}

/** The move as one line for a writer (sent with each lead to the follow-up head, the sit-down and the win-back agent). */
function pm_soften_note(array $l, int $maxFu = 3): string
{
    $s = pm_soften($l, $maxFu);
    return $s['temp_label'] . ' lead (' . $s['why'] . '). Our move: ' . $s['label'] . '. ' . $s['move'];
}

/** Remembers the move a message used, so the next one tries another (last six kept). */
function pm_soften_used(array &$lead, string $play): void
{
    if (!isset(PM_SOFTEN_PLAYS[$play])) {
        return;
    }
    $lead['plays'] = array_slice(array_merge((array)($lead['plays'] ?? []), [['play' => $play, 'at' => date('Y-m-d')]]), -6);
}

/** When a lead that said no is worth one fresh try, by what held them back (days after the no). */
function pm_soften_winback_days(string $objection): int
{
    return ['timing' => 60, 'price' => 60, 'no_trust' => 45, 'no_need' => 90, 'competitor' => 120][$objection] ?? 180;
}
