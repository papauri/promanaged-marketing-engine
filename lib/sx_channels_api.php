<?php
/**
 * Publishing the extra formats (carousel, story, reel) to Facebook and Instagram through pm_graph only, and the after-publish hook:
 * first comment, per-channel record (p['channels']) and the hand-post / host share-kit tasks. Plain image and text posts are not handled here.
 */

/** Which of Facebook / Instagram the app posts to for this brand. */
function pm_ch_flags(string $brand, array $c): array
{
    $on = function_exists('pm_social_settings') ? (array)pm_social_settings($brand)['channels'] : ['facebook' => true, 'instagram' => true];
    $fb = !empty($c['ready']) && !empty($on['facebook']);
    $ig = !empty($on['instagram']) && ($c['ig_id'] ?? '') !== '' && ($c['token'] ?? '') !== '';
    return [$fb, $ig];
}

function pm_ch_sleep(int $s): void
{
    if (!empty($GLOBALS['PM_NOSLEEP']) || getenv('PM_TEST')) {
        return;
    }
    function_exists('pm_social_sleep') ? pm_social_sleep($s) : sleep($s);
}

/** Polls an Instagram container: FINISHED | ERROR | EXPIRED | IN_PROGRESS (after $tries checks, $gap seconds apart). */
function pm_ch_wait(string $id, string $token, int $tries = 2, int $gap = 5): string
{
    $st = '';
    for ($i = 0; $i < $tries; $i++) {
        [$ok, $d] = pm_graph('GET', $id, ['fields' => 'status_code'], $token);
        $st = $ok && is_array($d) ? (string)($d['status_code'] ?? '') : '';
        if (in_array($st, ['FINISHED', 'ERROR', 'EXPIRED'], true)) {
            return $st;
        }
        if ($i < $tries - 1) {
            pm_ch_sleep($gap);
        }
    }
    return $st ?: 'IN_PROGRESS';
}

/** A JPEG copy of a picture that Instagram can fetch at APP_URL/media.php?f=<name>. Returns the public URL or ''. */
function pm_ch_ig_image(string $src, string $name): string
{
    if (pm_app_url() === '' || !is_file($src)) {
        return '';
    }
    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) {
        return '';
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255)); // transparency becomes white
    imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
    $ok = imagejpeg($flat, pm_social_dir() . '/' . $name, 90);
    return $ok ? pm_app_url() . '/media.php?f=' . $name : '';
}

function pm_ch_alt_for(array $p, int $i): string
{
    $s = $i > 0 ? ($p['slides'][$i - 1] ?? null) : null;
    $a = is_array($s) ? trim(trim((string)($s['h'] ?? '')) . '. ' . trim((string)($s['t'] ?? '')), ' .') : '';
    return mb_substr($a !== '' ? $a : pm_variant_alt($p), 0, 400);
}

/** Result array that core's pm_social_publish understands. */
function pm_ch_res(bool $ok, string $fb, string $ig, string $err = ''): array
{
    return ['ok' => $ok, 'fb_id' => $fb, 'ig' => $ig, 'error' => $err];
}

/**
 * Publishes carousel / story / reel. Returns null for other formats (core publishes those itself).
 * Sets $p['ig'], ig_post, ig_container and channels.instagram; core stores fb_id from the result.
 */
function pm_publish_ext(array &$p, array $c, string $media): ?array
{
    $fmt = (string)($p['format'] ?? 'image');
    if (!in_array($fmt, ['carousel', 'story', 'reel'], true)) {
        return null;
    }
    $brand = pm_ch_brand((string)($p['brand'] ?? 'promanaged'));
    pm_brand_set($brand);
    [$fbOn, $igOn] = pm_ch_flags($brand, $c);
    $fb = (string)($p['fb_id'] ?? '');
    $ig = (string)($p['ig'] ?? '');
    $err = '';
    if ($fbOn && $fb === '') {
        [$fb, $err] = match ($fmt) {
            'carousel' => pm_ch_fb_carousel($p, $c),
            'story' => pm_ch_fb_story($p, $c, $media),
            default => pm_ch_fb_reel($p, $c, $media),
        };
        if ($fb === '') {
            return pm_ch_res(false, '', (string)($p['ig'] ?? ''), $err);
        }
    }
    if ($igOn && (string)($p['ig_post'] ?? '') === '' && !str_starts_with($ig, 'pending')) {
        $ig = match ($fmt) {
            'carousel' => pm_ch_ig_carousel($p, $c),
            'story' => pm_ch_ig_story($p, $c, $media),
            default => pm_ch_ig_reel($p, $c, $media),
        };
        $p['ig'] = $ig;
    }
    $igGood = $ig === 'published' || str_starts_with($ig, 'pending');
    if ($fbOn) {
        return pm_ch_res(true, $fb, $ig);
    }
    return $igGood ? pm_ch_res(true, '', $ig) : pm_ch_res(false, '', $ig, (string)preg_replace('/^(failed|skipped): /', '', $ig) ?: 'Nothing is connected for this format.');
}

/* ---------------- carousel ---------------- */

function pm_ch_slides(array $p): array
{
    if (getenv('PM_TEST') && isset($GLOBALS['PM_SLIDES_STUB'])) { // tests draw nothing
        return array_values((array)($GLOBALS['PM_SLIDES_STUB'])($p));
    }
    $s = function_exists('pm_card_slides') ? (array)pm_card_slides($p, '4x5') : [];
    return array_values(array_filter($s, fn($f) => is_string($f) && is_file($f)));
}

function pm_ch_fb_carousel(array &$p, array $c): array
{
    $slides = pm_ch_slides($p);
    if (count($slides) < 2) {
        return ['', 'The carousel slides could not be drawn (check fonts), so nothing was posted.'];
    }
    $ids = [];
    foreach ($slides as $i => $f) {
        [$ok, $d] = pm_graph('POST', $c['page_id'] . '/photos', ['published' => 'false', 'alt_text_custom' => pm_ch_alt_for($p, $i)], $c['token'], ['source' => $f]);
        if (!$ok || empty($d['id'])) {
            return ['', is_string($d) ? $d : 'Facebook did not accept slide ' . ($i + 1) . '.'];
        }
        $ids[] = (string)$d['id'];
    }
    $media = [];
    foreach ($ids as $i => $id) {
        $media[$i] = json_encode(['media_fbid' => $id]);
    }
    [$ok, $d] = pm_graph('POST', $c['page_id'] . '/feed', ['message' => pm_variant_caption($p, 'facebook'), 'attached_media' => $media], $c['token']);
    return $ok ? [(string)($d['id'] ?? $d['post_id'] ?? ''), ''] : ['', is_string($d) ? $d : 'Facebook did not publish the carousel.'];
}

function pm_ch_ig_carousel(array &$p, array $c): string
{
    if (pm_app_url() === '') {
        return pm_ch_ig_todo($p, 'skipped: the app is not online (APP_URL), so Instagram cannot fetch the slides');
    }
    $slides = pm_ch_slides($p);
    if (count($slides) < 2) {
        return 'failed: the carousel slides could not be drawn';
    }
    $kids = [];
    foreach ($slides as $i => $f) {
        $url = pm_ch_ig_image($f, 'ig_' . $p['id'] . '_' . ($i + 1) . '.jpg');
        if ($url === '') {
            return 'failed: slide ' . ($i + 1) . ' could not be prepared';
        }
        [$ok, $d] = pm_graph('POST', $c['ig_id'] . '/media', ['image_url' => $url, 'is_carousel_item' => 'true', 'alt_text' => pm_ch_alt_for($p, $i)], $c['token']);
        if (!$ok || empty($d['id'])) {
            return 'failed: ' . (is_string($d) ? $d : 'no container for slide ' . ($i + 1));
        }
        $kids[] = (string)$d['id'];
    }
    foreach ($kids as $i => $k) {
        if (pm_ch_wait($k, $c['token'], 3) !== 'FINISHED') {
            return 'failed: Instagram did not finish processing slide ' . ($i + 1);
        }
    }
    [$ok, $d] = pm_graph('POST', $c['ig_id'] . '/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => pm_variant_caption($p, 'instagram')], $c['token']);
    if (!$ok || empty($d['id'])) {
        return 'failed: ' . (is_string($d) ? $d : 'no carousel container');
    }
    return pm_ch_ig_finish($p, $c, (string)$d['id'], 'the carousel');
}

/* ---------------- story ---------------- */

/** The 9:16 picture for a story: the owner's own picture when there is one, else the drawn card. '' = none. */
function pm_ch_story_pic(array $p, string $media): string
{
    if (getenv('PM_TEST') && isset($GLOBALS['PM_STORY_STUB'])) {
        return (string)($GLOBALS['PM_STORY_STUB'])($p);
    }
    if ($media !== '' && is_file($media) && !pm_social_is_video($media)) {
        return $media;
    }
    $f = function_exists('pm_card_render') ? pm_card_render($p, 'story') : null;
    if (!is_string($f) || $f === '') {
        $f = function_exists('pm_social_card') ? pm_social_card($p, 'story') : '';
    }
    return is_string($f) && is_file($f) ? $f : '';
}

function pm_ch_fb_story(array &$p, array $c, string $media): array
{
    $pic = pm_ch_story_pic($p, $media);
    if ($pic === '') {
        return ['', 'The story picture could not be drawn (check fonts), so nothing was posted.'];
    }
    [$ok, $d] = pm_graph('POST', $c['page_id'] . '/photos', ['published' => 'false', 'alt_text_custom' => mb_substr(pm_variant_alt($p), 0, 400)], $c['token'], ['source' => $pic]);
    if (!$ok || empty($d['id'])) {
        return ['', is_string($d) ? $d : 'Facebook did not accept the story picture.'];
    }
    [$ok, $d2] = pm_graph('POST', $c['page_id'] . '/photo_stories', ['photo_id' => (string)$d['id']], $c['token']);
    return $ok ? [(string)($d2['post_id'] ?? $d2['id'] ?? $d['id']), ''] : ['', is_string($d2) ? $d2 : 'Facebook did not publish the story.'];
}

function pm_ch_ig_story(array &$p, array $c, string $media): string
{
    if (pm_app_url() === '') {
        return pm_ch_ig_todo($p, 'skipped: the app is not online (APP_URL), so Instagram cannot fetch the picture');
    }
    $pic = pm_ch_story_pic($p, $media);
    $url = $pic !== '' ? pm_ch_ig_image($pic, 'ig_' . $p['id'] . '.jpg') : '';
    if ($url === '') {
        return 'failed: the story picture could not be prepared';
    }
    [$ok, $d] = pm_graph('POST', $c['ig_id'] . '/media', ['media_type' => 'STORIES', 'image_url' => $url], $c['token']);
    if (!$ok || empty($d['id'])) {
        return 'failed: ' . (is_string($d) ? $d : 'no story container');
    }
    return pm_ch_ig_finish($p, $c, (string)$d['id'], 'the story');
}

/* ---------------- reel ---------------- */

/**
 * Uploads the file of a Facebook reel. In tests it goes to PM_GRAPH_STUB as POST RUPLOAD/<video id>; otherwise a plain curl to the upload url Facebook gave.
 * Returns [ok, message].
 */
function pm_ch_rupload(string $url, string $videoId, string $token, string $file): array
{
    if (isset($GLOBALS['PM_GRAPH_STUB']) || getenv('PM_TEST')) {
        if (!isset($GLOBALS['PM_GRAPH_STUB'])) {
            return [false, 'No network in tests.'];
        }
        [$ok, $d] = ($GLOBALS['PM_GRAPH_STUB'])('POST', 'RUPLOAD/' . $videoId, [], $token, ['source' => $file]);
        return [(bool)$ok, is_string($d) ? $d : 'ok'];
    }
    if ($url === '' || !preg_match('#^https://[a-z0-9.-]*facebook\.com/#i', $url)) {
        return [false, 'No upload address from Facebook.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_POSTFIELDS => (string)file_get_contents($file),
        CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . $token, 'offset: 0', 'file_size: ' . filesize($file), 'Content-Type: application/octet-stream']]);
    if (function_exists('pm_curl_native_ca')) {
        pm_curl_native_ca($ch);
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$raw, true);
    return $raw !== false && $code < 300 && !empty($j['success']) ? [true, 'ok'] : [false, 'Upload failed (' . $code . ').'];
}

function pm_ch_fb_reel(array &$p, array $c, string $media): array
{
    if ($media === '' || !is_file($media) || !pm_social_is_video($media)) {
        return ['', 'This reel has no video file yet.'];
    }
    $caption = pm_variant_caption($p, 'facebook');
    $why = '';
    [$ok, $d] = pm_graph('POST', $c['page_id'] . '/video_reels', ['upload_phase' => 'start'], $c['token']);
    if ($ok && !empty($d['video_id'])) {
        $vid = (string)$d['video_id'];
        [$okU, $mU] = pm_ch_rupload((string)($d['upload_url'] ?? ''), $vid, $c['token'], $media);
        if ($okU) {
            [$okF, $dF] = pm_graph('POST', $c['page_id'] . '/video_reels', ['video_id' => $vid, 'upload_phase' => 'finish', 'video_state' => 'PUBLISHED', 'description' => $caption], $c['token']);
            if ($okF) {
                return [(string)($dF['post_id'] ?? $vid), '']; // the video id until measure resolves the post id
            }
            $why = is_string($dF) ? $dF : 'finish failed';
        } else {
            $why = $mU;
        }
    } else {
        $why = is_string($d) ? $d : 'start failed';
    }
    // The Reels endpoint did not take it: post it as an ordinary Page video (still plays as a reel on mobile)
    [$ok, $d2] = pm_graph('POST', $c['page_id'] . '/videos', ['description' => $caption], $c['token'], ['source' => $media], true);
    if ($ok) {
        $p['channels']['facebook']['note'] = 'Posted as a Page video: the Reels upload said "' . mb_substr($why, 0, 160) . '"';
        return [(string)($d2['post_id'] ?? $d2['id'] ?? ''), ''];
    }
    return ['', is_string($d2) ? $d2 : 'Facebook did not accept the video.'];
}

function pm_ch_ig_reel(array &$p, array $c, string $media): string
{
    if (pm_app_url() === '') {
        return pm_ch_ig_todo($p, 'skipped: the app is not online (APP_URL), so Instagram cannot fetch the video');
    }
    if ($media === '' || !is_file($media) || !pm_social_is_video($media)) {
        return 'failed: no video file';
    }
    $name = 'ig_' . $p['id'] . '.mp4'; // media.php serves this name
    if (!@copy($media, pm_social_dir() . '/' . $name)) {
        return 'failed: the video could not be prepared';
    }
    [$ok, $d] = pm_graph('POST', $c['ig_id'] . '/media', ['media_type' => 'REELS', 'video_url' => pm_app_url() . '/media.php?f=' . $name, 'caption' => pm_variant_caption($p, 'instagram'), 'share_to_feed' => 'true'], $c['token']);
    if (!$ok || empty($d['id'])) {
        return 'failed: ' . (is_string($d) ? $d : 'no reel container');
    }
    return pm_ch_ig_finish($p, $c, (string)$d['id'], 'the reel');
}

/* ---------------- Instagram helpers ---------------- */

/** Instagram cannot be reached automatically: say so and leave a hand-post task. */
function pm_ch_ig_todo(array &$p, string $why): string
{
    $p['channels']['instagram'] = ['mode' => 'manual', 'state' => 'todo', 'at' => '', 'url' => '', 'note' => $why];
    return $why;
}

/** Waits briefly for a container, then publishes it; if Instagram is still busy the container is kept for pm_job_channels_ig. */
function pm_ch_ig_finish(array &$p, array $c, string $container, string $what): string
{
    $st = pm_ch_wait($container, $c['token'], 2, 5);
    if ($st === 'FINISHED') {
        return pm_ch_ig_publish($p, $c, $container);
    }
    if ($st === 'ERROR' || $st === 'EXPIRED') {
        return "failed: Instagram could not process $what ($st)";
    }
    $p['ig_container'] = $container;
    return "pending: Instagram is still processing $what; the app finishes it in the next scheduled run";
}

function pm_ch_ig_publish(array &$p, array $c, string $container): string
{
    [$ok, $d] = pm_graph('POST', $c['ig_id'] . '/media_publish', ['creation_id' => $container], $c['token']);
    if (!$ok) {
        // a timeout may still have posted: never blindly retry
        $e = (array)($GLOBALS['PM_GRAPH_ERR'] ?? []);
        return 'failed: ' . (in_array((int)($e['errno'] ?? 0), [28, 52, 55, 56], true) ? 'no answer from Instagram, check the account before retrying. ' : '') . (is_string($d) ? $d : 'could not publish');
    }
    $p['ig_post'] = (string)($d['id'] ?? '');
    $p['ig_container'] = '';
    return 'published';
}

/** Hourly: finishes Instagram containers that were still processing when the post went out. */
function pm_job_channels_ig(string $brand): string
{
    $c = pm_social_cfg($brand);
    if (!$c['ready'] || $c['ig_id'] === '') {
        return '';
    }
    $n = 0;
    foreach (pm_social_posts() as $p) {
        if (($p['brand'] ?? '') !== $brand || ($p['ig_container'] ?? '') === '' || !str_starts_with((string)($p['ig'] ?? ''), 'pending') || ($p['ig_post'] ?? '') !== '') {
            continue;
        }
        $age = time() - (int)strtotime((string)($p['published'] ?? $p['when'] ?? 'now'));
        $st = pm_ch_wait((string)$p['ig_container'], $c['token'], 1);
        $res = null;
        if ($st === 'FINISHED') {
            $tmp = $p;
            $res = pm_ch_ig_publish($tmp, $c, (string)$p['ig_container']);
            $post = (string)($tmp['ig_post'] ?? '');
        } elseif (in_array($st, ['ERROR', 'EXPIRED'], true) || $age > 86400) {
            $res = 'failed: Instagram did not finish processing (' . $st . ')';
            $post = '';
        }
        if ($res === null) {
            continue;
        }
        pm_social_patch((string)$p['id'], function (array $q) use ($res, $post) {
            $q['ig'] = $res;
            if ($res === 'published') {
                $q['ig_post'] = $post;
                $q['ig_container'] = '';
                $q['channels']['instagram'] = ['mode' => 'auto', 'state' => 'done', 'at' => date('Y-m-d H:i'), 'url' => ''];
            }
            return $q;
        });
        $n++;
    }
    return $n ? "Instagram: finished $n pending post(s)" : '';
}

/* ---------------- after-publish hook ---------------- */

/** Days until the LinkedIn token expires, from LI_TOKEN_EXPIRES / TM_LI_TOKEN_EXPIRES (Y-m-d) in .env. null = unknown or not LinkedIn. */
function pm_li_token_days_left(string $brand): ?int
{
    $e = pm_env();
    $pre = $brand === 'travel' ? 'TM_' : '';
    if (trim((string)($e[$pre . 'LI_TOKEN'] ?? '')) === '') {
        return null;
    }
    $d = trim((string)($e[$pre . 'LI_TOKEN_EXPIRES'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !($t = strtotime($d . ' 23:59:59'))) {
        return null;
    }
    return (int)floor(($t - time()) / 86400);
}

/** The Facebook web address of a post id ("page_post" form works as is). */
function pm_ch_fb_url(string $fbId): string
{
    return $fbId === '' ? '' : 'https://www.facebook.com/' . rawurlencode($fbId);
}

/**
 * After a post is published: first comment on Facebook (once), record each channel's state, queue hand-post tasks and the host share kit.
 * Returns a patch for the post. Never throws.
 */
function pm_hook_after_publish_channels(array $p, array $c): ?array
{
    $brand = pm_ch_brand((string)($p['brand'] ?? 'promanaged'));
    pm_brand_set($brand);
    $ch = (array)($p['channels'] ?? []);
    $now = date('Y-m-d H:i');
    $fmt = (string)($p['format'] ?? 'image');
    $patch = [];
    $fbId = (string)($p['fb_id'] ?? '');
    if ($fbId !== '') {
        $e = (array)($ch['facebook'] ?? []);
        $e = ['mode' => 'auto', 'state' => 'done', 'at' => $e['at'] ?? $now, 'url' => pm_ch_fb_url($fbId)] + $e;
        if ($fmt !== 'story' && empty($e['comment_id']) && !empty($c['token'])) {
            $txt = pm_variant_first_comment($p);
            if ($txt !== '') {
                [$ok, $d] = pm_graph('POST', $fbId . '/comments', ['message' => $txt], (string)$c['token']);
                if ($ok && !empty($d['id'])) {
                    $e['comment_id'] = (string)$d['id'];
                    unset($e['comment_error']);
                } else {
                    $e['comment_error'] = mb_substr(is_string($d) ? $d : 'no id', 0, 160);
                }
            }
        }
        $ch['facebook'] = $e;
    }
    $ig = (string)($p['ig'] ?? '');
    if (($p['ig_post'] ?? '') !== '' || $ig === 'published') {
        $ch['instagram'] = ['mode' => 'auto', 'state' => 'done', 'at' => $now, 'url' => '', 'ig_post' => (string)($p['ig_post'] ?? '')] + (array)($ch['instagram'] ?? []);
    } elseif (str_starts_with($ig, 'pending')) {
        $ch['instagram'] = ['mode' => 'auto', 'state' => 'pending', 'at' => $now, 'url' => ''] + (array)($ch['instagram'] ?? []);
    } elseif (str_starts_with($ig, 'failed')) {
        $ch['instagram'] = ['mode' => 'auto', 'state' => 'failed', 'at' => $now, 'url' => '', 'note' => mb_substr($ig, 0, 200)];
    }
    if (($p['li'] ?? '') === 'published') {
        $ch['linkedin'] = ['mode' => 'auto', 'state' => 'done', 'at' => $now, 'url' => ''] + (array)($ch['linkedin'] ?? []);
        $urn = (string)($p['li_urn'] ?? '');
        if ($urn === '' && ($GLOBALS['PM_LI_LAST_URN'][0] ?? '') === $brand) {
            $urn = (string)$GLOBALS['PM_LI_LAST_URN'][1];
        }
        if ($urn !== '') {
            $patch['li_urn'] = $urn;
        }
    }
    // hand-post tasks (times are worked out from the channel's own slots)
    foreach (pm_manual_channels($p) as $m) {
        if (!isset($ch[$m])) {
            $ch[$m] = ['mode' => 'manual', 'state' => 'todo', 'at' => pm_ch_due($brand, $m, $p), 'url' => ''];
        }
    }
    if (pm_host_kit_row($p) !== null && !isset($ch['host_kit'])) {
        $ch['host_kit'] = ['mode' => 'manual', 'state' => 'todo', 'at' => $now, 'url' => '', 'proof_id' => (string)$p['proof_id']];
    }
    $patch['channels'] = $ch;
    return $patch;
}

/* ---------------- Make a Story (owner button, no AI) ---------------- */

/** A story draft from an approved/planned post: same idea, 9:16 card, no caption needed. */
function pm_do_make_story(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $src = null;
    foreach (pm_social_posts() as $p) {
        if (($p['id'] ?? '') === $id && ($p['brand'] ?? '') === $vb) {
            $src = $p;
        }
    }
    if (!$src) {
        return ['msg' => 'That post is gone.', 'kind' => 'err', 'to' => 'social'];
    }
    if (($src['format'] ?? '') === 'story') {
        return ['msg' => 'That already is a story.', 'kind' => 'err', 'to' => 'social'];
    }
    $new = [
        'id' => bin2hex(random_bytes(10)), 'brand' => $vb, 'status' => 'draft', 'when' => (string)$src['when'], 'format' => 'story',
        'pillar' => (string)($src['pillar'] ?? ''), 'cta' => (string)($src['cta'] ?? ''), 'proof_id' => (string)($src['proof_id'] ?? ''),
        'caption' => (string)$src['caption'], 'hashtags' => [], 'headline' => (string)($src['headline'] ?? ''), 'sub' => (string)($src['sub'] ?? ''), 'script' => '', 'media' => '',
        'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [], 'created' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? ''),
        'alt' => pm_variant_alt($src), 'sibling_of' => $id, 'seed' => (string)($src['seed'] ?? ''), 'layout' => (string)($src['layout'] ?? ''),
    ];
    if (function_exists('pm_social_lint_post') && function_exists('pm_social_resolve')) {
        $new = pm_social_resolve($new, pm_social_lint_post($new));
    }
    pm_social_update(function (array $posts) use ($new) {
        $posts[] = $new;
        return $posts;
    });
    if (function_exists('pm_social_log')) {
        pm_social_log($id, 'make_story', $new['id']);
    }
    return ['msg' => 'Story draft made from that post. Read it in the plan, then approve it. Stories do not use up a feed day.', 'kind' => 'ok', 'to' => 'social'];
}
