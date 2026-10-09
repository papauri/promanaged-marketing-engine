<?php
/**
 * Full Facebook Page management: a connect helper that turns a short user token into a permanent Page token,
 * token health, the Page's own posts (edit, delete), every comment (reply, like, hide, delete), the Messenger inbox,
 * and an AI page audit that judges the Page, its posts and its replies.
 */
require_once __DIR__ . '/social_platforms.php';

/** What the app needs from Facebook to manage the Page fully. */
function pm_fb_scopes(): array
{
    return [
        'pages_show_list' => 'see your Pages', 'pages_read_engagement' => 'read posts, followers and engagement', 'pages_read_user_content' => 'read comments and visitor posts',
        'pages_manage_posts' => 'publish, edit and delete posts', 'pages_manage_engagement' => 'reply to, like, hide and delete comments', 'pages_manage_metadata' => 'change the profile picture and cover',
        'pages_messaging' => 'read and answer Messenger messages', 'read_insights' => 'reach and impressions for the audit',
        'ads_management' => 'create targeted ads (always paused until you switch them on)', 'ads_read' => 'read ad results',
    ];
}

/* ---------------- .env writing (local file, keeps everything else as it is) ---------------- */

function pm_env_set(array $kv): void
{
    $f = PM_ROOT . '/.env';
    $lines = is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [];
    @copy($f, PM_ROOT . '/.env.bak');
    foreach ($kv as $k => $v) {
        $v = preg_replace('/[\r\n]/', '', (string)$v);
        $done = false;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\s*' . preg_quote($k, '/') . '\s*=/', $l)) {
                $lines[$i] = "$k=$v";
                $done = true;
            }
        }
        if (!$done) {
            $lines[] = "$k=$v";
        }
    }
    file_put_contents($f, implode("\n", $lines) . "\n", LOCK_EX);
}

/* ---------------- token health and the connect helper ---------------- */

/** What a token is and can do: type (PAGE/USER), permissions, missing permissions, expiry. */
function pm_fb_token_info(string $token): array
{
    if ($token === '') {
        return ['ok' => false, 'error' => 'No token'];
    }
    [$ok, $d] = pm_graph('GET', 'debug_token', ['input_token' => $token], $token);
    if (!$ok) {
        return ['ok' => false, 'error' => $d];
    }
    $data = (array)($d['data'] ?? []);
    $scopes = (array)($data['scopes'] ?? []);
    return ['ok' => !empty($data['is_valid']), 'type' => (string)($data['type'] ?? ''), 'scopes' => $scopes,
        'missing' => array_values(array_diff(array_keys(pm_fb_scopes()), $scopes, ['read_insights', 'ads_management', 'ads_read'])), // insights are a nice-to-have
        'expires' => (int)($data['expires_at'] ?? 0), 'error' => !empty($data['is_valid']) ? '' : 'Token is not valid'];
}

/**
 * Turns a token copied from Graph API Explorer into a long-lived one (needs the app ID and secret), then lists the Pages
 * it can manage with their permanent Page tokens. Returns [ok, pages|error].
 */
function pm_fb_connect_pages(string $appId, string $secret, string $userToken): array
{
    $ch = curl_init('https://graph.facebook.com/' . pm_graph_version() . '/oauth/access_token?' . http_build_query([
        'grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $secret, 'fb_exchange_token' => $userToken]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    pm_curl_native_ca($ch);
    $j = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($j['access_token'])) {
        return [false, 'Facebook did not extend the token: ' . ($j['error']['message'] ?? 'check the App ID, App Secret and token')];
    }
    [$ok, $d] = pm_graph('GET', 'me/accounts', ['fields' => 'id,name,access_token,tasks', 'limit' => 50], $j['access_token']);
    if (!$ok) {
        return [false, $d];
    }
    $pages = array_values(array_filter((array)($d['data'] ?? []), fn($p) => !empty($p['access_token'])));
    return $pages ? [true, $pages, $j['access_token']] : [false, 'No Pages came back. Tick business_management and choose the Page in the Facebook pop-up, then try again with a new token.'];
}

/* ---------------- the Page's posts and comments ---------------- */

/**
 * The Page's feed with every post AND its comments and replies in ONE call (cached 10 minutes).
 * Posts, comments, the judge, people and the autopilot all read from this instead of calling per post.
 */
function pm_fb_feed(string $brand): array
{
    $c = pm_social_cfg($brand);
    [$ok, $d] = pm_graph('GET', $c['page_id'] . '/feed', ['limit' => 25, 'fields' => 'id,message,created_time,permalink_url,full_picture,status_type,from,is_hidden,shares,reactions.summary(total_count).limit(0),'
        . 'comments.filter(toplevel).order(reverse_chronological).limit(25).summary(true){id,message,from,created_time,like_count,is_hidden,user_likes,comments.limit(10){id,message,from,created_time}}'], $c['token']);
    if (!$ok) {
        return ['error' => $d, 'items' => []];
    }
    $out = [];
    foreach ((array)($d['data'] ?? []) as $p) {
        $out[] = ['id' => $p['id'], 'ours' => ($p['from']['id'] ?? $c['page_id']) === $c['page_id'], 'from' => (string)($p['from']['name'] ?? ''), 'hidden' => !empty($p['is_hidden']),
            'message' => (string)($p['message'] ?? ''), 'at' => (string)($p['created_time'] ?? ''), 'url' => (string)($p['permalink_url'] ?? ''),
            'picture' => (string)($p['full_picture'] ?? ''), 'type' => (string)($p['status_type'] ?? ''), 'shares' => (int)($p['shares']['count'] ?? 0),
            'reactions' => (int)($p['reactions']['summary']['total_count'] ?? 0), 'comments' => (int)($p['comments']['summary']['total_count'] ?? 0),
            'comment_list' => pm_fb_comment_rows((array)($p['comments']['data'] ?? []), $c['page_id'])];
    }
    return ['error' => '', 'items' => $out];
}

function pm_fb_comment_rows(array $data, string $pageId): array
{
    $out = [];
    foreach ($data as $cm) {
        $replies = array_map(fn($r) => ['id' => $r['id'], 'from' => $r['from']['name'] ?? 'Someone', 'ours' => ($r['from']['id'] ?? '') === $pageId, 'message' => (string)($r['message'] ?? ''), 'at' => (string)($r['created_time'] ?? '')], (array)($cm['comments']['data'] ?? []));
        $out[] = ['id' => $cm['id'], 'from' => $cm['from']['name'] ?? 'Someone', 'from_id' => (string)($cm['from']['id'] ?? ''), 'ours' => ($cm['from']['id'] ?? '') === $pageId, 'message' => (string)($cm['message'] ?? ''),
            'at' => (string)($cm['created_time'] ?? ''), 'likes' => (int)($cm['like_count'] ?? 0), 'hidden' => !empty($cm['is_hidden']), 'liked' => !empty($cm['user_likes']),
            'answered' => (bool)array_filter($replies, fn($r) => $r['ours']), 'replies' => $replies];
    }
    return $out;
}

/** Our own posts, newest first. Up to 25 come from the shared feed call; more (the clean-up) costs one extra cached call. */
function pm_fb_posts(string $brand, int $limit = 25): array
{
    if ($limit <= 25) {
        $f = pm_fb_feed($brand);
        return ['error' => $f['error'], 'posts' => array_slice(array_values(array_filter($f['items'], fn($p) => $p['ours'])), 0, $limit)];
    }
    $c = pm_social_cfg($brand);
    [$ok, $d] = pm_graph('GET', $c['page_id'] . '/published_posts', ['limit' => $limit,
        'fields' => 'id,message,created_time,permalink_url,full_picture,status_type,shares,reactions.summary(total_count).limit(0),comments.summary(true).limit(0)'], $c['token']);
    if (!$ok) {
        return ['error' => $d, 'posts' => []];
    }
    $out = [];
    foreach ((array)($d['data'] ?? []) as $p) {
        $out[] = ['id' => $p['id'], 'ours' => true, 'message' => (string)($p['message'] ?? ''), 'at' => (string)($p['created_time'] ?? ''), 'url' => (string)($p['permalink_url'] ?? ''),
            'picture' => (string)($p['full_picture'] ?? ''), 'type' => (string)($p['status_type'] ?? ''), 'shares' => (int)($p['shares']['count'] ?? 0),
            'reactions' => (int)($p['reactions']['summary']['total_count'] ?? 0), 'comments' => (int)($p['comments']['summary']['total_count'] ?? 0)];
    }
    return ['error' => '', 'posts' => $out];
}

/** A post's comments: from the shared feed call when the post is in it (no extra call). */
function pm_fb_comments(string $brand, string $postId): array
{
    foreach (pm_fb_feed($brand)['items'] as $p) {
        if ($p['id'] === $postId) {
            return ['error' => '', 'comments' => $p['comment_list']];
        }
    }
    $c = pm_social_cfg($brand);
    [$ok, $d] = pm_graph('GET', $postId . '/comments', ['limit' => 50, 'filter' => 'toplevel', 'order' => 'reverse_chronological',
        'fields' => 'id,message,from,created_time,like_count,is_hidden,user_likes,comments.limit(10){id,message,from,created_time}'], $c['token']);
    return $ok ? ['error' => '', 'comments' => pm_fb_comment_rows((array)($d['data'] ?? []), $c['page_id'])] : ['error' => $d, 'comments' => []];
}

/** Page actions: edit/delete a post, reply/like/unlike/hide/unhide/delete a comment. Returns [ok, message]. */
function pm_fb_action(string $brand, string $what, string $id, string $text = ''): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || !preg_match('/^[0-9_]+$/', $id)) {
        return [false, 'Not connected, or a bad id.'];
    }
    [$ok, $d] = match ($what) {
        'edit' => pm_graph('POST', $id, ['message' => $text], $c['token']),
        'delete', 'delete_comment' => pm_graph('DELETE', $id, [], $c['token']),
        'reply' => pm_graph('POST', $id . '/comments', ['message' => $text], $c['token']),
        'like' => pm_graph('POST', $id . '/likes', [], $c['token']),
        'unlike' => pm_graph('DELETE', $id . '/likes', [], $c['token']),
        'hide' => pm_graph('POST', $id, ['is_hidden' => 'true'], $c['token']),
        'unhide' => pm_graph('POST', $id, ['is_hidden' => 'false'], $c['token']),
        default => [false, 'Unknown action'],
    };
    $labels = ['edit' => 'Post updated.', 'delete' => 'Post deleted from the Page.', 'delete_comment' => 'Comment deleted.', 'reply' => 'Reply posted.', 'like' => 'Liked.', 'unlike' => 'Like removed.', 'hide' => 'Comment hidden (the writer and their friends still see it).', 'unhide' => 'Comment visible again.'];
    return $ok ? [true, $labels[$what] ?? 'Done.'] : [false, 'Facebook: ' . $d];
}

/* ---------------- Messenger inbox ---------------- */

function pm_fb_inbox(string $brand): array
{
    $c = pm_social_cfg($brand);
    [$ok, $d] = pm_graph('GET', $c['page_id'] . '/conversations', ['limit' => 20, 'fields' => 'id,updated_time,participants,messages.limit(6){message,from,created_time}'], $c['token']);
    if (!$ok) {
        return ['error' => $d, 'threads' => []];
    }
    $out = [];
    foreach ((array)($d['data'] ?? []) as $t) {
        $who = '';
        $psid = '';
        foreach ((array)($t['participants']['data'] ?? []) as $p) {
            if (($p['id'] ?? '') !== $c['page_id']) {
                $who = $p['name'] ?? 'Someone';
                $psid = $p['id'] ?? '';
            }
        }
        $msgs = array_reverse(array_map(fn($m) => ['ours' => ($m['from']['id'] ?? '') === $c['page_id'], 'text' => (string)($m['message'] ?? ''), 'at' => (string)($m['created_time'] ?? '')], (array)($t['messages']['data'] ?? [])));
        $last = end($msgs) ?: ['ours' => true, 'at' => $t['updated_time'] ?? ''];
        $out[] = ['id' => $t['id'], 'who' => $who, 'psid' => $psid, 'updated' => (string)($t['updated_time'] ?? ''), 'messages' => $msgs,
            'waiting' => !$last['ours'], 'window' => strtotime((string)$last['at']) > time() - 86400];
    }
    return ['error' => '', 'threads' => $out];
}

function pm_fb_message(string $brand, string $psid, string $text): array
{
    $c = pm_social_cfg($brand);
    [$ok, $d] = pm_graph('POST', $c['page_id'] . '/messages', ['recipient' => json_encode(['id' => $psid]), 'messaging_type' => 'RESPONSE', 'message' => json_encode(['text' => $text])], $c['token']);
    return $ok ? [true, 'Message sent.'] : [false, 'Facebook: ' . $d . (str_contains((string)$d, '24') ? ' (Facebook only allows replies within 24 hours of their last message.)' : '')];
}

/* ---------------- AI page audit: judges the Page, its posts and its replies ---------------- */

/** Numbers the audit is built on, worked out in code (no AI needed). */
function pm_fb_audit_data(string $brand): array
{
    $c = pm_social_cfg($brand);
    [$ok, $pg] = pm_graph('GET', $c['page_id'], ['fields' => 'name,about,description,category,website,phone,emails,username,whatsapp_number,fan_count,followers_count,overall_star_rating,rating_count,picture{url},cover{source},hours,location'], $c['token']);
    $page = $ok ? $pg : [];
    $posts = pm_fb_posts($brand, 25)['posts'];
    $days = [];
    $hours = [];
    $byType = [];
    $sample = [];
    $answered = 0;
    $unanswered = 0;
    $ourReplies = [];
    foreach ($posts as $i => $p) {
        $eng = $p['reactions'] + 2 * $p['comments'] + 3 * $p['shares'];
        $t = strtotime($p['at']);
        $days[date('l', $t)][] = $eng;
        $hours[(int)date('G', $t)][] = $eng;
        $type = $p['picture'] !== '' ? ($p['type'] === 'added_video' ? 'video' : 'picture') : 'text';
        $byType[$type][] = $eng;
        $sample[] = ['when' => date('D j M H:i', $t), 'type' => $type, 'text' => mb_substr($p['message'], 0, 220), 'reactions' => $p['reactions'], 'comments' => $p['comments'], 'shares' => $p['shares']];
        if ($p['comments'] > 0 && $i < 10) {
            foreach (pm_fb_comments($brand, $p['id'])['comments'] as $cm) {
                if ($cm['ours']) {
                    continue;
                }
                $cm['answered'] ? $answered++ : $unanswered++;
                foreach ($cm['replies'] as $r) {
                    if ($r['ours'] && count($ourReplies) < 8) {
                        $ourReplies[] = ['comment' => mb_substr($cm['message'], 0, 160), 'our_reply' => mb_substr($r['message'], 0, 200)];
                    }
                }
            }
        }
    }
    $avg = fn($a) => $a ? round(array_sum($a) / count($a), 1) : 0;
    $span = count($posts) > 1 ? max(1, (strtotime($posts[0]['at']) - strtotime(end($posts)['at'])) / 86400) : 0;
    return [
        'page' => ['name' => $page['name'] ?? '', 'username' => $page['username'] ?? '', 'category' => $page['category'] ?? '', 'about' => $page['about'] ?? '', 'description' => mb_substr((string)($page['description'] ?? ''), 0, 400),
            'website' => $page['website'] ?? '', 'phone' => $page['phone'] ?? '', 'email' => $page['emails'][0] ?? '', 'whatsapp' => $page['whatsapp_number'] ?? '', 'has_hours' => !empty($page['hours']),
            'has_location' => !empty($page['location']), 'has_cover' => !empty($page['cover']), 'followers' => (int)($page['followers_count'] ?? 0), 'rating' => $page['overall_star_rating'] ?? 0, 'reviews' => (int)($page['rating_count'] ?? 0)],
        'posting' => ['posts_counted' => count($posts), 'posts_per_week' => $span ? round(count($posts) / $span * 7, 1) : 0, 'last_post' => $posts[0]['at'] ?? '',
            'avg_engagement' => $avg(array_map(fn($p) => $p['reactions'] + 2 * $p['comments'] + 3 * $p['shares'], $posts)),
            'by_format' => array_map($avg, $byType), 'by_day' => array_map($avg, $days), 'by_hour' => array_map($avg, $hours)],
        'replies' => ['visitor_comments' => $answered + $unanswered, 'answered' => $answered, 'unanswered' => $unanswered, 'samples' => $ourReplies],
        'recent_posts' => array_slice($sample, 0, 15),
    ];
}

/** The AI judge. One call. Returns the audit and saves it for the planner to learn from. */
function pm_agent_page_audit(string $brand): array
{
    pm_brand_set($brand);
    $data = pm_fb_audit_data($brand);
    $system = pm_agents_company_brief('tiny') . "\nYou are a senior social media strategist auditing this business's Facebook Page. Judge it honestly and specifically, using only the data given. "
        . "Score 0-100 overall and for: profile (completeness and clarity), consistency (how often and how regularly), content (variety, usefulness, hooks, calls to action), engagement (reactions, comments, shares for its size), responsiveness (answering comments, tone of replies). "
        . "For a small new Page, judge against what is realistic for a small Malawian business, not big brands. "
        . "Give: what is working (max 3), the most important fixes in priority order (max 6, each a concrete action someone can do this week), a verdict on each of up to 6 recent posts (why it did or did not work, and how to improve it), "
        . "the best days, times and formats to post based on the data (say 'not enough data yet' if so), a judgement of the tone of the Page's replies with an improved example if needed, and 3 post ideas for next week. "
        . "Never invent numbers. Reply JSON only.";
    $user = json_encode($data, JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"score\":0,\"scores\":{\"profile\":0,\"consistency\":0,\"content\":0,\"engagement\":0,\"responsiveness\":0},\"headline\":\"one sentence\",\"working\":[],\"fixes\":[{\"action\":\"\",\"why\":\"\"}],"
        . "\"posts\":[{\"post\":\"first words of the post\",\"verdict\":\"\",\"improve\":\"\"}],\"timing\":\"\",\"replies\":{\"verdict\":\"\",\"better_example\":\"\"},\"ideas\":[]}";
    $out = pm_agent_json(pm_claude($system, $user, false, 3000, 'write'));
    if (!is_array($out) || !isset($out['score'])) {
        throw new RuntimeException('The audit did not come back complete. Try again.');
    }
    $out['at'] = date('Y-m-d H:i');
    $out['data'] = $data;
    $all = pm_load('page_audit', fn() => []);
    $all[$brand] = $out;
    pm_save('page_audit', $all);
    return $out;
}

/** What the planner should know from the last audit (so new posts follow what works on this Page). */
function pm_audit_learnings(string $brand): string
{
    $a = pm_load('page_audit', fn() => [])[$brand] ?? null;
    if (!$a) {
        return '';
    }
    $fix = implode('; ', array_map(fn($f) => is_array($f) ? ($f['action'] ?? '') : (string)$f, array_slice((array)($a['fixes'] ?? []), 0, 3)));
    return 'Lessons from the latest audit of this Page: what works: ' . implode('; ', array_slice((array)($a['working'] ?? []), 0, 3)) . '. Timing: ' . ($a['timing'] ?? '') . '. Fix next: ' . $fix . '.';
}
