<?php
/**
 * Audit actions: every finding of the page audit becomes something that can be done from the Audit screen.
 *  - Settings the DATA suggests (no AI): the weekly posting goal, the times posts go out, the content-pillar mix, which channels post automatically, and how replies should sound.
 *    Each shows what it is now, the suggestion, and an Apply button; the owner can edit the value first.
 *  - "Fix these first" (the AI's list): every fix is sorted into a kind (about, hours, cover, posting, replies ...) by the AI, or by code when the audit is older. A fix that can be applied
 *    shows the same card as Optimise with the AI's own wording filled in (only if it passes the facts-only guard); the others open the screen that fixes them. A fix with no known screen
 *    can ask the AI for plain steps.
 *  - Post by post: "Rewrite with AI" makes an improved version of a post (one small call, facts only, no new numbers), editable, and "Save to Facebook" puts it on the Page.
 *  - Ideas for next week: "Add to content ideas" so the planner writes them; replies: "Use this reply style" so the reply drafts follow it.
 * Everything applied is logged with the Optimise log. Nothing is posted by this module except the one post edit the owner confirms.
 */

const PM_AA_KINDS = ['about' => 'fb_about', 'description' => 'fb_description', 'hours' => 'fb_hours', 'phone' => 'fb_phone', 'website' => 'fb_website', 'email' => 'fb_email', 'cover' => 'fb_cover', 'picture' => 'fb_picture',
    'posting' => 'fb_posting', 'replies' => 'fb_replies', 'messages' => 'fb_messages', 'cta' => 'fb_cta', 'formats' => 'fb_formats', 'pin' => 'fb_pin', 'reviews' => 'fb_reviews', 'timing' => 'post_times', 'other' => ''];

/** Which kind a fix is: the AI's own label when it gave a valid one, else read from the words (so audits made before this existed work too). */
function pm_aa_kind(array $fix): string
{
    $k = strtolower(trim((string)($fix['kind'] ?? '')));
    if (isset(PM_AA_KINDS[$k])) {
        return $k;
    }
    $t = strtolower(trim((string)($fix['action'] ?? ''))); // the action only: the reason often mentions other things ("zero content about websites")
    foreach ([
        'hours' => '/opening hours|business hours|working hours|\bhours\b/', 'cover' => '/\bcover\b|banner/', 'picture' => '/profile (picture|photo|image)|\blogo\b|avatar/',
        'phone' => '/\bphone\b|call button|contact number|whatsapp (business )?(number|button)/', 'website' => '/website|web address|\burl\b|link to your site/', 'email' => '/\bemail\b/',
        'description' => '/description|mission|our story/', 'about' => '/\babout\b|\bintro\b|\bbio\b|tagline/', 'reviews' => '/review|recommend|rating|testimonial/', 'pin' => '/\bpin(ned)?\b/',
        'messages' => '/messenger|inbox|direct message|private message/', 'replies' => '/repl(y|ies)|respond|unanswered|comments?/', 'cta' => '/call to action|\bcta\b|enquir|lead post|invite (people|them)/',
        'timing' => '/best time|time of day|timing|when to post|post(ing)? at (the )?(best|right|peak|these|the same)/', 'posting' => '/how often|frequen|consisten|regular|schedule|calendar|rhythm|post(ing)? (more|every|daily|weekly|at least)|times a week|per week|a week|each week|every (day|week)|spread|space (them|these|out)|within minutes|separate days|multiple posts/',
        'formats' => '/picture|photo|image|video|visual|format|text-only/',
    ] as $kind => $re) {
        if (preg_match($re, $t)) {
            return $kind;
        }
    }
    return 'other';
}

/* ---------------- what the settings are now ---------------- */

/** Times (HH:MM) the owner set for new posts, or [] (then the Page's own best times, then the habits, are used). */
function pm_aa_times(string $brand): array
{
    $t = (array)(pm_social_settings($brand)['times'] ?? []);
    return array_values(array_filter(array_map('strval', $t), fn($x) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $x) === 1));
}

/** One sentence on how replies should sound (from the audit), added to the reply drafts' instructions. */
function pm_aa_reply_style(string $brand): string
{
    return trim((string)(pm_social_settings($brand)['reply_style'] ?? ''));
}

/** The line the reply writer is given. */
function pm_aa_reply_style_line(string $brand): string
{
    $s = pm_aa_reply_style($brand);
    return $s !== '' ? ' The owner wants replies to sound like this: ' . $s . ' (never at the cost of the rules above).' : '';
}

/** Parses "18:00, 12:30, 7" into at most four valid times; [ok, list|message]. */
function pm_aa_parse_times(string $v): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
        if (!preg_match('/^(\d{1,2})(?::([0-5]\d))?$/', $p, $m) || (int)$m[1] > 23) {
            return [false, '"' . mb_substr($p, 0, 12) . '" is not a time. Write times like 18:00 or 7:30, separated by commas.'];
        }
        $out[sprintf('%02d:%02d', (int)$m[1], (int)($m[2] ?? 0))] = 1;
    }
    $out = array_keys($out);
    sort($out);
    return $out && count($out) <= 4 ? [true, $out] : [false, $out ? 'Four times at most.' : 'Write at least one time, like 18:00.'];
}

/** The audit's measured data (stored with the last audit, else read now from the Page: cached reads, no AI). */
function pm_aa_data(string $brand, ?array $au): array
{
    if (!empty($au['data'])) {
        return (array)$au['data'];
    }
    try {
        return pm_social_cfg($brand)['ready'] ? pm_fb_audit_data($brand) : [];
    } catch (Throwable) {
        return [];
    }
}

/* ---------------- settings the data suggests ---------------- */

/** Setting suggestions as Optimise-style items (channel 'audit', do aa_apply). */
function pm_aa_settings_items(string $brand, ?array $au, array $data): array
{
    $it = [];
    $f = pm_opt_facts($brand);
    $posting = (array)($data['posting'] ?? []);
    $n = (int)($posting['posts_counted'] ?? 0);
    $perWeek = (float)($posting['posts_per_week'] ?? 0);
    $mk = fn(string $key, string $title, string $state, string $why, array $o = []) => pm_opt_it('audit', $key, $title, $state, 2, $why, ['do' => 'aa_apply', 'how' => 'app'] + $o);

    // 1. the weekly posting goal
    if ($n >= 3) {
        $goal = (int)pm_social_goals($brand)['posts_per_week'];
        $sug = $perWeek < 1 ? 3 : ($perWeek < 3 ? min(5, (int)ceil($perWeek) + 2) : max(3, min(7, (int)round($perWeek))));
        $fine = $goal > 0 && $goal >= $perWeek * 0.8 && $goal <= $perWeek * 1.8 + 1;
        $it[] = $mk('goal_posts', 'Weekly posting goal', $fine || $goal === $sug ? 'ok' : 'weak', 'The goal is what the report and Today measure you against; a goal far from what you really manage is ignored.',
            ['current' => 'Goal ' . $goal . ' a week; the Page really gets ' . $perWeek . ' a week', 'proposal' => (string)$sug, 'input' => 'text', 'limit' => 2, 'bulk' => true, 'button' => 'Set the goal']);
    }
    // 2. the times posts go out
    $override = pm_aa_times($brand);
    $by = array_filter((array)($posting['by_hour'] ?? []), fn($v, $h) => (int)$h >= 6 && (int)$h <= 21, ARRAY_FILTER_USE_BOTH);
    $avg = (float)($posting['avg_engagement'] ?? 0);
    arsort($by);
    $best = array_slice(array_keys(array_filter($by, fn($v) => $avg > 0 && $v >= $avg * 1.1)), 0, 3);
    $cur = implode(', ', pm_social_hours($brand));
    if ($n >= 8 && $best) {
        $prop = implode(', ', array_map(fn($h) => sprintf('%02d:00', (int)$h), $best));
        $it[] = $mk('post_times', 'When new posts go out', ($override && implode(', ', $override) === $prop) ? 'ok' : 'weak', 'Posts at the hours your followers actually react get more reactions.',
            ['current' => ($override ? 'Set by you: ' : '') . $cur, 'proposal' => $prop, 'input' => 'text', 'limit' => 40, 'bulk' => true, 'button' => 'Use these times',
                'note' => 'From the ' . $n . ' latest posts: ' . implode(', ', array_map(fn($h, $v) => sprintf('%02d:00', (int)$h) . ' averages ' . $v, array_slice(array_keys($by), 0, 3), array_slice(array_values($by), 0, 3))) . '. New posts use them; posts already planned keep their time.']);
    } else {
        $it[] = $mk('post_times', 'When new posts go out', 'ok', 'Posts at the hours your followers are online get more reactions.',
            ['current' => ($override ? 'Set by you: ' : '') . $cur, 'proposal' => $override ? implode(', ', $override) : $cur, 'input' => 'text', 'limit' => 40, 'button' => 'Use these times',
                'note' => $n < 8 ? 'Only ' . $n . ' posts to learn from so far (8 are needed), so the usual Malawi times are used. You can set your own now.' : 'No hour clearly beats the others yet.']);
    }
    if ($override) {
        $it[] = $mk('post_times_clear', 'Go back to the automatic times', 'weak', 'The app learns the best times from the Page\'s own results.', ['current' => 'Set by you: ' . implode(', ', $override), 'button' => 'Use the automatic times']);
    }
    // 3. the content mix
    try {
        $sb = pm_social_scoreboard($brand);
    } catch (Throwable) {
        $sb = ['enough' => false, 'groups' => []];
    }
    $pillars = pm_social_pillars($brand);
    $grp = (array)($sb['groups']['pillar'] ?? []);
    if (!empty($sb['enough']) && $grp) {
        $new = [];
        $moved = false;
        foreach ($pillars as $p) {
            $g = $grp[$p['name']] ?? null;
            $w = (int)$p['weight'];
            if ($g && !empty($g['enough']) && $g['vs_avg'] !== null && ($g['vs_avg'] >= 1.3 || $g['vs_avg'] <= 0.7)) {
                $w = max(5, min(100, (int)round($w * (0.5 + 0.5 * (float)$g['vs_avg']))));
                $moved = true;
            }
            $new[] = $p['name'] . ' | ' . $w;
        }
        $it[] = $mk('pillars', 'The mix of post themes', $moved ? 'weak' : 'ok', 'The planner writes this mix. Themes that get more reactions than average get more room; weaker ones get less (never below 5).',
            ['current' => implode('; ', array_map(fn($p) => $p['name'] . ' ' . $p['weight'], $pillars)), 'proposal' => implode("\n", $new), 'input' => 'textarea', 'limit' => 600, 'rows' => max(3, count($new)), 'button' => 'Use this mix',
                'note' => implode(' · ', array_map(fn($k, $g) => $k . ': ' . ($g['vs_avg'] ?? '?') . '× the average (' . $g['n'] . ' posts)', array_keys(array_slice($grp, 0, 5, true)), array_slice($grp, 0, 5, true)))]);
    }
    // 4. which channels post by themselves
    $set = pm_social_settings($brand);
    $c = pm_social_cfg($brand);
    if ($c['ig_id'] !== '' && empty($set['channels']['instagram'])) {
        $it[] = $mk('ch_instagram_on', 'Post to Instagram too', 'weak', 'Instagram is linked, but posts only go to Facebook.', ['current' => 'Instagram is linked and switched off', 'button' => 'Switch Instagram posting on', 'bulk' => true]);
    }
    if (!empty($set['channels']['linkedin']) && !pm_linkedin_cfg($brand)['ready']) {
        $it[] = $mk('ch_linkedin_off', 'LinkedIn posting is on but not connected', 'weak', 'Posts for LinkedIn wait as hand-posts and clutter the plan.', ['current' => 'LinkedIn is switched on, with no connection', 'button' => 'Switch LinkedIn posting off', 'bulk' => true]);
    }
    // 5. how replies sound
    $style = trim((string)($au['replies']['style_rule'] ?? ''));
    $ex = trim((string)($au['replies']['better_example'] ?? ''));
    if ($style === '' && $ex !== '') {
        $style = 'Warm, short and personal, like: "' . mb_substr($ex, 0, 140) . '"';
    }
    if ($style !== '' && pm_opt_ai_ok(mb_substr($style, 0, 200), 200, implode(' ', array_filter([$f['name'], $f['tagline'], $f['about'], $ex])) . ' warm short personal like')) {
        $it[] = $mk('reply_style', 'How replies to comments sound', pm_aa_reply_style($brand) === mb_substr($style, 0, 200) ? 'ok' : 'weak', 'The audit judged the tone of our replies. This line is added to every reply the AI drafts.',
            ['current' => pm_aa_reply_style($brand) !== '' ? pm_aa_reply_style($brand) : 'No style set: replies use the default warm, short tone', 'proposal' => mb_substr($style, 0, 200), 'input' => 'textarea', 'limit' => 200, 'rows' => 2, 'button' => 'Use this reply style']);
    }
    return $it;
}

/* ---------------- the AI's fix list as actions ---------------- */

/** Optimise items by key (one run per request). */
function pm_aa_opt_items(string $brand): array
{
    static $cache = [];
    if (!isset($cache[$brand])) {
        $o = [];
        try {
            foreach (pm_opt_run($brand, false)['channels'] as $c) {
                foreach ($c['items'] as $i) {
                    $o[$i['key']] = $i;
                }
            }
        } catch (Throwable) {
        }
        $cache[$brand] = $o;
    }
    return $cache[$brand];
}

/** The card for one fix: the matching Optimise item with the AI's own wording in it (when it passes the guard), a settings item, or a link. */
function pm_aa_fix_card(string $brand, array $fix, array $settingsItems): array
{
    $kind = pm_aa_kind($fix);
    $key = PM_AA_KINDS[$kind];
    $opt = pm_aa_opt_items($brand);
    $set = [];
    foreach ($settingsItems as $s) {
        $set[$s['key']] = $s;
    }
    $item = $key !== '' ? ($set[$key] ?? $opt[$key] ?? null) : null;
    $text = trim((string)($fix['text'] ?? ''));
    if ($item && $text !== '' && in_array($kind, ['about', 'description'], true) && $item['limit'] > 0) {
        $f = pm_opt_facts($brand);
        if (pm_opt_ai_ok($text, (int)$item['limit'], implode(' ', array_filter([$f['name'], $f['tagline'], $f['about'], $f['audience'], implode(' ', $f['facts']), $f['address'], $f['phone']])))) {
            $item['proposal'] = $text;
            $item['ai'] = true;
        }
    }
    return ['kind' => $kind, 'item' => $item];
}

/* ---------------- post by post ---------------- */

/** The Page post an audit verdict is about: by id, else by the first words. */
function pm_aa_post_for(string $brand, array $row): ?array
{
    $posts = pm_fb_posts($brand, 25)['posts'];
    $id = (string)($row['id'] ?? '');
    foreach ($posts as $p) {
        if ($id !== '' && $p['id'] === $id) {
            return $p;
        }
    }
    $head = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', rtrim((string)($row['post'] ?? ''), '. …'))));
    if (mb_strlen($head) >= 12) {
        foreach ($posts as $p) {
            if (str_starts_with(mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $p['message']))), mb_substr($head, 0, 40))) {
                return $p;
            }
        }
    }
    return null;
}

/** A rewrite that keeps to the facts: not empty, not the same, within 1,500 characters, no number that was not in the post or the facts, no boast, no shouting. */
function pm_aa_rewrite_ok(string $new, string $old, string $source): bool
{
    $t = trim($new);
    if ($t === '' || mb_strlen($t) > 1500 || mb_strlen($t) < 20 || trim($old) === $t || substr_count($t, '!') > 1 || pm_brand_unsourced_number($t, $source . ' ' . $old) || preg_match('/\b\p{Lu}{6,}\b/u', $t)) {
        return false;
    }
    return !preg_match('/\b(best|number one|no\.? ?1|leading|guarantee[sd]?|cheapest|top[- ]rated|award[- ]winning|world[- ]class|unbeatable)\b/i', $t) || preg_match('/\b(best|leading|guarantee)/i', $source . ' ' . $old) === 1;
}

/** One small AI call: a better version of one post, following what the audit said. Returns [ok, text|message]. */
function pm_aa_rewrite(string $brand, string $postId, string $improve): array
{
    $post = null;
    foreach (pm_fb_posts($brand, 25)['posts'] as $p) {
        if ($p['id'] === $postId) {
            $post = $p;
        }
    }
    if (!$post || trim($post['message']) === '') {
        return [false, 'That post was not found on the Page (or it has no text).'];
    }
    $f = pm_opt_facts($brand);
    $system = pm_agents_company_brief('tiny') . "\nYou improve one Facebook Page post for " . $f['name'] . '. Follow the audit note. Keep the meaning, the language and the facts; make it clearer, warmer and easier to act on, '
        . 'with one easy next step (a WhatsApp message or a comment). Plain text, no more than 900 characters. Use ONLY what the post and the facts already say: add no number, price, claim or promise. Reply JSON only: {"text":""}';
    $user = json_encode(['post' => $post['message'], 'audit_note' => mb_substr($improve, 0, 300), 'facts' => array_slice($f['facts'], 0, 8), 'about' => mb_substr($f['about'], 0, 300), 'whatsapp' => $f['phone']], JSON_UNESCAPED_UNICODE);
    $out = pm_agent_json(pm_claude($system, $user, false, 700, 'write'));
    $t = trim((string)(is_array($out) && is_scalar($out['text'] ?? null) ? $out['text'] : ''));
    $src = implode(' ', array_filter([$f['name'], $f['tagline'], $f['about'], $f['audience'], implode(' ', $f['facts']), $f['phone'], $f['address']]));
    if (!pm_aa_rewrite_ok($t, $post['message'], $src)) {
        return [false, 'The AI\'s version added a claim or a number that is not in the post or your facts, or was not usable, so it was not kept. Edit the post by hand, or try again.'];
    }
    pm_opt_update($brand, function (array $d) use ($postId, $t, $improve) {
        $d['rewrites'][$postId] = ['text' => $t, 'improve' => mb_substr($improve, 0, 300), 'at' => date('Y-m-d H:i')];
        $d['rewrites'] = array_slice((array)$d['rewrites'], -12, null, true);
        return $d;
    });
    return [true, $t];
}

/** A short plain how-to for a fix that has no screen of its own. One small AI call; stored so it is not asked again. */
function pm_aa_howto(string $brand, int $i, array $fix): array
{
    $f = pm_opt_facts($brand);
    $system = pm_agents_company_brief('tiny') . "\nA social media audit of the Facebook Page of " . $f['name'] . ' (Malawi) says something needs fixing. Give 3 to 5 short, concrete steps a small business owner can follow on a phone or computer, '
        . 'naming where in Facebook or Meta Business Suite to look, and saying "look for" when you are not sure of the exact menu name. No invented features. Reply JSON only: {"steps":[""]}';
    $out = pm_agent_json(pm_claude($system, json_encode(['fix' => mb_substr((string)($fix['action'] ?? ''), 0, 300), 'why' => mb_substr((string)($fix['why'] ?? ''), 0, 300)], JSON_UNESCAPED_UNICODE), false, 500, 'cheap'));
    $steps = array_slice(array_values(array_filter(array_map(fn($s) => is_scalar($s) ? trim(strip_tags((string)$s)) : '', (array)(is_array($out) ? ($out['steps'] ?? []) : [])))), 0, 5);
    if (!$steps) {
        return [false, 'The AI did not return steps. Try again.'];
    }
    pm_opt_update($brand, function (array $d) use ($i, $steps, $fix) {
        $d['howto'][md5((string)($fix['action'] ?? $i))] = ['steps' => $steps, 'at' => date('Y-m-d H:i')];
        $d['howto'] = array_slice((array)$d['howto'], -20, null, true);
        return $d;
    });
    return [true, implode("\n", $steps)];
}

/* ---------------- applying ---------------- */

/** Applies one audit setting. $val is the text the owner saw (and may have edited). Returns [ok, message]. */
function pm_aa_apply(string $brand, string $key, string $val = ''): array
{
    $brand = pm_brand_norm($brand);
    $log = function (bool $ok, string $m) use ($brand, $key) {
        pm_opt_log($brand, 'audit', $key, $ok, $m);
        return [$ok, $m];
    };
    $val = trim((string)preg_replace('/[ \t]+/u', ' ', strip_tags($val)));
    switch (true) {
        case $key === 'goal_posts':
            if (!preg_match('/^\d{1,2}$/', $val) || (int)$val < 1 || (int)$val > 21) {
                return [false, 'The goal must be a number of posts a week between 1 and 21.'];
            }
            pm_update('social_goals', function (array $all) use ($brand, $val) {
                $all[$brand] = array_replace(pm_social_goals_default(), (array)($all[$brand] ?? []), ['posts_per_week' => (int)$val]);
                return $all;
            }, fn() => []);
            return $log(true, 'Weekly posting goal set to ' . (int)$val . '.');
        case $key === 'post_times':
            [$ok, $t] = pm_aa_parse_times($val);
            if (!$ok) {
                return [false, (string)$t];
            }
            $cfg = pm_social_settings($brand);
            $cfg['times'] = $t;
            pm_social_settings_save($brand, $cfg);
            return $log(true, 'New posts will go out at ' . implode(', ', $t) . '. Posts already planned keep their time.');
        case $key === 'post_times_clear':
            $cfg = pm_social_settings($brand);
            unset($cfg['times']);
            pm_social_settings_save($brand, $cfg);
            return $log(true, 'Back to the automatic times (the Page\'s own best hours, else the usual Malawi times).');
        case $key === 'pillars':
            $rows = [];
            foreach (preg_split('/\R/', $val) ?: [] as $line) {
                if (preg_match('/^(.{2,40}?)\s*[|:]\s*(\d{1,3})\s*$/u', trim($line), $m)) {
                    $rows[] = ['name' => trim($m[1]), 'weight' => min(100, (int)$m[2])];
                }
            }
            if (count($rows) < 2) {
                return [false, 'Write at least two themes, one per line, like "Tip/How-to | 30".'];
            }
            pm_social_pillars_save($brand, $rows);
            return $log(true, 'The post-theme mix is saved: ' . implode(', ', array_map(fn($r) => $r['name'] . ' ' . $r['weight'], $rows)) . '. The planner uses it from the next plan.');
        case $key === 'ch_instagram_on':
        case $key === 'ch_linkedin_off':
            $cfg = pm_social_settings($brand);
            $cfg['channels'][$key === 'ch_instagram_on' ? 'instagram' : 'linkedin'] = $key === 'ch_instagram_on';
            pm_social_settings_save($brand, $cfg);
            return $log(true, $key === 'ch_instagram_on' ? 'Posts now go to Instagram as well.' : 'LinkedIn posting is switched off.');
        case $key === 'reply_style':
            if ($val === '' || mb_strlen($val) > 200) {
                return [false, 'Write one short sentence (200 characters at most) about how replies should sound.'];
            }
            $cfg = pm_social_settings($brand);
            $cfg['reply_style'] = $val;
            pm_social_settings_save($brand, $cfg);
            return $log(true, 'Replies the AI drafts will now follow this style.');
        case str_starts_with($key, 'idea_'):
            if (mb_strlen($val) < 8) {
                return [false, 'The idea is too short to write a post from.'];
            }
            $text = mb_substr($val, 0, 240);
            pm_bank_set($brand, function (array $b) use ($text) {
                foreach ((array)$b['ideas'] as $x) {
                    if (trim((string)($x['text'] ?? '')) === $text) {
                        return $b;
                    }
                }
                $b['ideas'][] = ['id' => 'a' . substr(sha1($text), 0, 9), 'text' => $text, 'pillar' => '', 'active' => true];
                return $b;
            });
            return $log(true, 'Added to your content ideas: the planner will write a post from it.');
    }
    return [false, 'That audit suggestion cannot be applied from here.'];
}

/** Applies every setting suggestion marked safe in one go. Returns [applied, failed[], titles[]]. */
function pm_aa_apply_all(string $brand): array
{
    $au = pm_load('page_audit', fn() => [])[$brand] ?? null;
    $data = pm_aa_data($brand, is_array($au) ? $au : null);
    $n = 0;
    $fail = [];
    $titles = [];
    foreach (pm_aa_settings_items($brand, is_array($au) ? $au : null, $data) as $i) {
        if ($i['state'] === 'ok' || !$i['bulk']) {
            continue;
        }
        [$ok, $m] = pm_aa_apply($brand, $i['key'], (string)$i['proposal']);
        if ($ok) {
            $n++;
            $titles[] = $i['title'];
        } else {
            $fail[] = $i['title'] . ': ' . $m;
        }
    }
    return [$n, $fail, $titles];
}

/* ---------------- form handlers (POST action=social_ext&do=...) ---------------- */

function pm_aa_to(string $anchor = ''): string
{
    return 'social&view=audit' . ($anchor !== '' ? '#' . $anchor : '');
}

function pm_do_aa_apply(string $vb): array
{
    $key = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['key'] ?? ''));
    $val = $_POST['val'][$key] ?? '';
    [$ok, $m] = pm_aa_apply($vb, $key, is_scalar($val) ? (string)$val : '');
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => pm_aa_to('i-' . $key)];
}

function pm_do_aa_all(string $vb): array
{
    [$n, $fail, $titles] = pm_aa_apply_all($vb);
    if ($n === 0 && !$fail) {
        return ['msg' => 'Nothing is waiting that can be applied in one go.', 'kind' => 'ok', 'to' => pm_aa_to()];
    }
    return ['msg' => $n . ' setting' . ($n === 1 ? '' : 's') . ' changed' . ($titles ? ' (' . implode(', ', $titles) . ')' : '') . ($fail ? '. ' . count($fail) . ' could not be: ' . implode(' ', array_slice($fail, 0, 2)) : '') . '.', 'kind' => $fail && !$n ? 'err' : 'ok', 'to' => pm_aa_to()];
}

function pm_do_aa_rewrite(string $vb): array
{
    $id = preg_replace('/[^0-9_]/', '', (string)($_POST['post'] ?? ''));
    if (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB'])) {
        return ['msg' => 'The AI needs a key in .env first. You can still edit the post by hand under Page.', 'kind' => 'err', 'to' => pm_aa_to('p' . $id)];
    }
    try {
        [$ok, $m] = pm_aa_rewrite($vb, $id, (string)($_POST['improve'] ?? ''));
    } catch (Throwable $e) {
        return ['msg' => 'The AI could not rewrite it: ' . mb_substr($e->getMessage(), 0, 120), 'kind' => 'err', 'to' => pm_aa_to('p' . $id)];
    }
    return ['msg' => $ok ? 'A new version is ready below. Read it, edit it, then save it to Facebook.' : $m, 'kind' => $ok ? 'ok' : 'err', 'to' => pm_aa_to('p' . $id)];
}

/** Puts the (edited) text on the Page post. The same safety checks as any edit made from the Page screen apply. */
function pm_do_aa_post_save(string $vb): array
{
    $id = preg_replace('/[^0-9_]/', '', (string)($_POST['post'] ?? ''));
    $text = trim((string)($_POST['text'] ?? ''));
    if ($id === '' || $text === '') {
        return ['msg' => 'There is no text to save.', 'kind' => 'err', 'to' => pm_aa_to('p' . $id)];
    }
    [$ok, $m] = pm_fb_action($vb, 'edit', $id, $text);
    pm_opt_log($vb, 'audit', 'post_' . $id, $ok, $ok ? 'Post rewritten from the audit' : $m);
    if ($ok) {
        pm_opt_update($vb, function (array $d) use ($id) {
            unset($d['rewrites'][$id]);
            return $d;
        });
    }
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => pm_aa_to('p' . $id)];
}

function pm_do_aa_howto(string $vb): array
{
    $au = (array)(pm_load('page_audit', fn() => [])[$vb] ?? []);
    $i = (int)($_POST['n'] ?? -1);
    $fix = (array)(($au['fixes'] ?? [])[$i] ?? []);
    if (!$fix) {
        return ['msg' => 'That fix is not in the audit any more.', 'kind' => 'err', 'to' => pm_aa_to()];
    }
    if (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB'])) {
        return ['msg' => 'The AI needs a key in .env first.', 'kind' => 'err', 'to' => pm_aa_to('fix' . $i)];
    }
    try {
        [$ok, $m] = pm_aa_howto($vb, $i, is_array($fix) ? $fix : ['action' => (string)$fix]);
    } catch (Throwable $e) {
        return ['msg' => 'The AI could not answer: ' . mb_substr($e->getMessage(), 0, 120), 'kind' => 'err', 'to' => pm_aa_to('fix' . $i)];
    }
    return ['msg' => $ok ? 'Steps added under the fix.' : $m, 'kind' => $ok ? 'ok' : 'err', 'to' => pm_aa_to('fix' . $i)];
}
