<?php
/**
 * Full Facebook Page management: a connect helper that turns a short user token into a permanent Page token,
 * token health, the Page's own posts (edit, delete), every comment (reply, like, hide, delete), the Messenger inbox,
 * and an AI page audit that judges the Page, its posts and its replies.
 */
require_once __DIR__ . '/social_platforms.php';
require_once __DIR__ . '/sx_replies.php';
require_once __DIR__ . '/sx_inbound.php';

/** What the app needs from Facebook to manage the Page fully. */
function pm_fb_scopes(): array
{
    return [
        'pages_show_list' => 'see your Pages', 'pages_read_engagement' => 'read posts, followers and engagement', 'pages_read_user_content' => 'read comments and visitor posts',
        'pages_manage_posts' => 'publish, edit and delete posts', 'pages_manage_engagement' => 'reply to, like, hide and delete comments', 'pages_manage_metadata' => 'change the profile picture and cover',
        'pages_messaging' => 'read and answer Messenger messages', 'read_insights' => 'reach and impressions for the audit',
        'ads_management' => 'create targeted ads (always paused until you switch them on)', 'ads_read' => 'read ad results',
        // Instagram: optional (the Page works without them); needed to read and answer Instagram comments and messages
        'instagram_basic' => 'read your linked Instagram account', 'instagram_manage_comments' => 'read and answer Instagram comments',
        'instagram_manage_messages' => 'answer Instagram messages (private replies)', 'instagram_manage_insights' => 'Instagram reach and followers',
    ];
}

/** Permissions that are nice to have: never counted as "missing". */
const PM_FB_OPTIONAL_SCOPES = ['read_insights', 'ads_management', 'ads_read', 'instagram_basic', 'instagram_manage_comments', 'instagram_manage_messages', 'instagram_manage_insights'];

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
        'missing' => array_values(array_diff(array_keys(pm_fb_scopes()), $scopes, PM_FB_OPTIONAL_SCOPES)), // insights, ads and Instagram are nice-to-haves
        'optional_missing' => array_values(array_intersect(PM_FB_OPTIONAL_SCOPES, array_diff(array_keys(pm_fb_scopes()), $scopes))),
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
            'answered' => (bool)array_filter($replies, fn($r) => $r['ours']), 'answered_at' => (string)(array_values(array_filter($replies, fn($r) => $r['ours']))[0]['at'] ?? ''), 'replies' => $replies];
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

/** Marks a judged item as answered now (public reply or private reply). Never throws. */
function pm_inb_mark_answered(string $brand, string $id): void
{
    try {
        $now = date('c');
        pm_update('fb_judged', function (array $all) use ($brand, $id, $now) {
            if (isset($all[$brand][$id])) {
                $all[$brand][$id]['answered'] = true;
                $all[$brand][$id]['answered_at'] = ($all[$brand][$id]['answered_at'] ?? '') ?: $now;
            }
            return $all;
        }, fn() => []);
    } catch (Throwable) {
    }
}

/** The lead made from a Facebook/Instagram person gets first_reply_at the first time we answer them. Never throws. */
function pm_inb_lead_replied(string $brand, string $fromId): void
{
    if ($fromId === '') {
        return;
    }
    try {
        $leads = pm_leads();
        $lb = pm_brand_norm($brand);
        $hit = false;
        foreach ($leads as $k => $l) {
            if (($l['brand'] ?? 'promanaged') === $lb && ($l['fb_from_id'] ?? '') === $fromId && empty($l['first_reply_at'])) {
                $leads[$k]['first_reply_at'] = date('c');
                $hit = true;
            }
        }
        if ($hit) {
            pm_leads_save($leads);
        }
    } catch (Throwable) {
    }
}

/** Page actions: edit/delete a post, reply/like/unlike/hide/unhide/delete a comment. Returns [ok, message]. Replies and edits pass pm_reply_lint first. */
function pm_fb_action(string $brand, string $what, string $id, string $text = ''): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || !preg_match('/^[0-9_]+$/', $id)) {
        return [false, 'Not connected, or a bad id.'];
    }
    if (in_array($what, ['reply', 'edit'], true)) {
        $bad = pm_reply_lint($text, $brand, $what === 'edit' ? 'post' : 'reply');
        if ($bad) {
            return [false, 'Not posted: ' . implode(' ', $bad)];
        }
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
    if ($ok && $what === 'reply') {
        pm_inb_mark_answered($brand, $id);
        pm_inb_lead_replied($brand, (string)((array)(pm_load('fb_judged', fn() => [])[$brand][$id] ?? [])['from_id'] ?? ''));
    }
    return $ok ? [true, $labels[$what] ?? 'Done.'] : [false, 'Facebook: ' . $d];
}

/**
 * One private reply to a comment, within 7 days of it (Facebook allows exactly one per comment; needs pages_messaging).
 * Instagram ($platform "ig"): POST {ig_id}/messages with the comment id as recipient. Refuses a second send. Returns [ok, message].
 */
function pm_fb_private_reply(string $brand, string $commentId, string $text, string $platform = 'fb'): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || !preg_match('/^\d+(_\d+)?$/', $commentId)) {
        return [false, 'Not connected, or not a comment id.'];
    }
    $ig = $platform === 'ig';
    if ($ig && $c['ig_id'] === '') {
        return [false, 'No Instagram account is linked to this Page.'];
    }
    $text = trim($text);
    if ($text === '') {
        return [false, 'No text.'];
    }
    $bad = pm_reply_lint($text, $brand, 'dm');
    if ($bad) {
        return [false, 'Not sent: ' . implode(' ', $bad)];
    }
    $row = (array)(pm_load('fb_judged', fn() => [])[$brand][$commentId] ?? []);
    if (!empty($row['private_reply_at'])) {
        return [false, 'Already answered privately. Facebook allows one private reply per comment.'];
    }
    $at = (string)($row['at'] ?? '');
    if ($at === '') { // the comment's age, from the cached feed
        $feed = $ig ? pm_ig_feed($brand) : pm_fb_feed($brand);
        foreach ($feed['items'] as $post) {
            foreach ($post['comment_list'] as $cm) {
                if ($cm['id'] === $commentId) {
                    $at = $cm['at'];
                    $row = ['from_id' => $cm['from_id'] ?? '', 'from' => $cm['from'] ?? '', 'text' => $cm['message'] ?? '', 'link' => $post['url'] ?? ''] + $row;
                }
            }
        }
    }
    $ts = strtotime($at);
    if (!$ts) {
        return [false, 'Could not confirm how old the comment is, so no private reply was sent.'];
    }
    if ($ts < pm_inb_now() - 7 * 86400) {
        return [false, 'That comment is more than 7 days old: Facebook no longer allows a private reply. Use WhatsApp or the public reply.'];
    }
    // claim it under the lock so two clicks cannot both send
    $stop = '';
    $stub = $row + ['id' => $commentId, 'kind' => 'comment', 'from' => '', 'text' => '', 'at' => $at, 'hidden' => false, 'liked' => false, 'answered' => false, 'post' => '', 'link' => '',
        'verdict' => 'question', 'action' => 'reply', 'reason' => 'Private reply', 'reply' => '', 'state' => '', 'by' => 'owner', 'platform' => $platform];
    pm_update('fb_judged', function (array $all) use ($brand, $commentId, $stub, &$stop) {
        $r = $all[$brand][$commentId] ?? $stub;
        if (!empty($r['private_reply_at'])) {
            $stop = 'Already answered privately. Facebook allows one private reply per comment.';
        } elseif ((int)($r['private_claim'] ?? 0) > time() - 300) {
            $stop = 'A private reply to this comment is already being sent.';
        } else {
            $r['private_claim'] = time();
            $all[$brand][$commentId] = $r;
        }
        return $all;
    }, fn() => []);
    if ($stop !== '') {
        return [false, $stop];
    }
    if ($ig) {
        [$ok, $d] = pm_graph('POST', $c['ig_id'] . '/messages', ['recipient' => json_encode(['comment_id' => $commentId]), 'message' => json_encode(['text' => $text])], $c['token']);
    } else {
        [$ok, $d] = pm_graph('POST', $commentId . '/private_replies', ['message' => $text], $c['token']);
    }
    $now = date('c');
    pm_update('fb_judged', function (array $all) use ($brand, $commentId, $ok, $now) {
        if (isset($all[$brand][$commentId])) {
            unset($all[$brand][$commentId]['private_claim']);
            if ($ok) {
                $all[$brand][$commentId]['private_reply_at'] = $now;
                $all[$brand][$commentId]['answered_at'] = ($all[$brand][$commentId]['answered_at'] ?? '') ?: $now;
            }
        }
        return $all;
    }, fn() => []);
    if ($ok) {
        pm_inb_lead_replied($brand, (string)($row['from_id'] ?? ''));
        pm_agent_log('Social', 'Private reply sent to ' . ($row['from'] ?? 'a commenter'));
        return [true, 'Private reply sent. They get it in ' . ($ig ? 'Instagram messages' : 'Messenger') . '; you cannot send another to this comment.'];
    }
    $why = (string)$d;
    return [false, 'Facebook: ' . $why . (preg_match('/permission|pages_messaging|\(#10\)|\(#200\)/i', $why) ? ' (the token needs pages_messaging' . ($ig ? ' and instagram_manage_messages' : '') . ')' : '')];
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

/**
 * A Messenger answer. Normal replies work within 24 hours of their last message. $human (owner-pressed only, never the autopilot) sends a
 * human follow-up with the HUMAN_AGENT tag, which Facebook allows from 24 hours up to 7 days after their last message.
 */
function pm_fb_message(string $brand, string $psid, string $text, bool $human = false): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || !preg_match('/^\d+$/', $psid)) {
        return [false, 'Not connected, or a bad recipient.'];
    }
    $bad = pm_reply_lint($text, $brand, 'dm');
    if ($bad) {
        return [false, 'Not sent: ' . implode(' ', $bad)];
    }
    $note = ' (Facebook only allows replies within 24 hours of their last message.)';
    $p = ['recipient' => json_encode(['id' => $psid]), 'messaging_type' => 'RESPONSE', 'message' => json_encode(['text' => $text])];
    if ($human) {
        if (!empty($GLOBALS['PM_AUTOPILOT'])) {
            return [false, 'The autopilot never sends human follow-ups.'];
        }
        $t = pm_inb_thread($brand, $psid);
        $last = 0;
        foreach ((array)($t['messages'] ?? []) as $m) {
            $last = !$m['ours'] ? strtotime($m['at']) : $last;
        }
        $age = $last ? pm_inb_now() - $last : 0;
        if ($last && $age > 7 * 86400) {
            return [false, 'More than 7 days since their last message: Facebook does not allow a follow-up from apps. Answer from the Facebook app.'];
        }
        if ($last && $age >= 86400) {
            $p['messaging_type'] = 'MESSAGE_TAG';
            $p['tag'] = 'HUMAN_AGENT';
        }
    }
    [$ok, $d] = pm_graph('POST', $c['page_id'] . '/messages', $p, $c['token']);
    if ($ok) {
        pm_inb_lead_replied($brand, $psid);
        return [true, 'Message sent.'];
    }
    $why = (string)$d;
    if (isset($p['tag']) && preg_match('/tag|HUMAN_AGENT|outside|window|#10|#200|permission/i', $why)) {
        return [false, 'Facebook: ' . $why . $note]; // the tag was refused: say what the 24-hour rule is
    }
    return [false, 'Facebook: ' . $why . (str_contains($why, '24') ? $note : '')];
}

/* ---------------- Instagram: comments to answer, followers ---------------- */

/** 'off' (no Instagram linked), 'needs_scope', 'error' or 'ok'. Based on the last cached read. */
function pm_ig_state(string $brand): string
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || $c['ig_id'] === '') {
        return 'off';
    }
    $f = pm_ig_feed($brand);
    return !empty($f['needs_scope']) ? 'needs_scope' : ($f['error'] !== '' ? 'error' : 'ok');
}

/**
 * Our Instagram posts with their comments in one cached call, in the same shape as pm_fb_feed (platform "ig").
 * No Instagram linked: empty and silent. Missing permission: needs_scope true, never throws.
 */
function pm_ig_feed(string $brand): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || $c['ig_id'] === '') {
        return ['error' => '', 'items' => [], 'off' => true];
    }
    [$ok, $d] = pm_graph('GET', $c['ig_id'] . '/media', ['limit' => 20, 'fields' => 'id,caption,timestamp,like_count,comments_count,permalink,media_type,comments.limit(25){id,text,username,timestamp,replies{id,username}}'], $c['token']);
    if (!$ok) {
        $scope = (bool)preg_match('/permission|scope|\(#10\)|\(#200\)|instagram_basic|instagram_manage|OAuth|access token/i', (string)$d);
        return ['error' => $scope ? 'needs scope: ' . $d : (string)$d, 'items' => [], 'needs_scope' => $scope];
    }
    $user = '';
    [$okU, $dU] = pm_graph('GET', $c['ig_id'], ['fields' => 'username'], $c['token']);
    if ($okU) {
        $user = strtolower((string)($dU['username'] ?? ''));
    }
    $out = [];
    foreach ((array)($d['data'] ?? []) as $m) {
        $cl = [];
        foreach ((array)($m['comments']['data'] ?? []) as $cm) {
            $u = (string)($cm['username'] ?? '');
            $ours = $user !== '' && strtolower($u) === $user;
            $answered = (bool)array_filter((array)($cm['replies']['data'] ?? []), fn($r) => $user !== '' && strtolower((string)($r['username'] ?? '')) === $user);
            $cl[] = ['id' => (string)$cm['id'], 'from' => $u ?: 'Someone', 'from_id' => 'ig:' . strtolower($u), 'ours' => $ours, 'message' => (string)($cm['text'] ?? ''), 'at' => (string)($cm['timestamp'] ?? ''),
                'likes' => 0, 'hidden' => false, 'liked' => false, 'answered' => $answered, 'answered_at' => '', 'replies' => []];
        }
        $out[] = ['id' => (string)$m['id'], 'ours' => true, 'platform' => 'ig', 'from' => '', 'hidden' => false, 'message' => (string)($m['caption'] ?? ''), 'at' => (string)($m['timestamp'] ?? ''),
            'url' => (string)($m['permalink'] ?? ''), 'picture' => '', 'type' => (string)($m['media_type'] ?? ''), 'shares' => 0, 'reactions' => (int)($m['like_count'] ?? 0),
            'comments' => (int)($m['comments_count'] ?? 0), 'comment_list' => $cl];
    }
    return ['error' => '', 'items' => $out];
}

/** Answers an Instagram comment (POST {comment}/replies). Returns [ok, message]. */
function pm_ig_reply(string $brand, string $commentId, string $text): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || $c['ig_id'] === '' || !preg_match('/^\d+$/', $commentId)) {
        return [false, 'Instagram is not linked, or a bad comment id.'];
    }
    $bad = pm_reply_lint($text, $brand, 'reply');
    if ($bad) {
        return [false, 'Not posted: ' . implode(' ', $bad)];
    }
    [$ok, $d] = pm_graph('POST', $commentId . '/replies', ['message' => $text], $c['token']);
    if ($ok) {
        pm_inb_mark_answered($brand, $commentId);
        pm_inb_lead_replied($brand, (string)((array)(pm_load('fb_judged', fn() => [])[$brand][$commentId] ?? [])['from_id'] ?? ''));
    }
    return $ok ? [true, 'Instagram reply posted.'] : [false, 'Instagram: ' . $d . (preg_match('/permission|scope|#10|#200/i', (string)$d) ? ' (needs instagram_manage_comments)' : '')];
}

/** Followers and post count of the linked Instagram account, or null (not linked, or permission missing). */
function pm_ig_followers(string $brand): ?array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || $c['ig_id'] === '') {
        return null;
    }
    [$ok, $d] = pm_graph('GET', $c['ig_id'], ['fields' => 'followers_count,media_count'], $c['token']);
    return $ok ? ['followers' => (int)($d['followers_count'] ?? 0), 'media_count' => (int)($d['media_count'] ?? 0)] : null;
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
    pm_update('page_audit', function (array $all) use ($brand, $out) {
        $all[$brand] = $out;
        return $all;
    }, fn() => []);
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
