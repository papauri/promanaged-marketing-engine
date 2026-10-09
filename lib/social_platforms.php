<?php
/**
 * Every major social platform in one place: what each needs, step-by-step setup, what the app can do there,
 * correctly sized profile and cover pictures for each, and one-click profile/cover updates where the platform allows it.
 */
require_once __DIR__ . '/social.php';

/** LinkedIn REST API version (YYYYMM) sent as the LinkedIn-Version header. Override with LI_API_VERSION in .env. Each version is supported for about a year. */
const PM_LI_API_VERSION = '202601';

/**
 * The platform catalogue. auto: what the app can do by itself once connected. env: the .env lines it needs (TM_ prefix for Travel Malawi).
 * profile/cover: picture sizes in pixels (cover null = the platform has none). steps: setup, thorough and in order.
 */
function pm_platforms(): array
{
    return [
        'facebook' => [
            'name' => 'Facebook Page', 'profile' => [720, 720], 'cover' => [1640, 856],
            'auto' => 'Publishes posts, pictures and videos on schedule, reads followers and engagement, answers comments, and can change the profile picture and cover.',
            'env' => ['FB_PAGE_ID', 'FB_PAGE_TOKEN'],
            'steps' => [
                'Create the Page (if needed): on Facebook go to Menu > Pages > Create new Page. Use the business name, category (ProManaged IT: "Information technology company"; Travel Malawi: "Travel company") and a one-line bio.',
                'Finish the Page: add the profile picture and cover from the branding kit below, the website link, WhatsApp number, email, address/area and opening hours. Set a username (@...) so the link is short.',
                'Add an action button: Edit action button > Send WhatsApp message (or Send message). This is where enquiries arrive.',
                'Give your team access safely: Settings > Page access > Add people, as "Facebook access" with only the tasks they need. Never share your personal password.',
                'Connect the app: use the "Facebook connection" box at the top of this screen (3 short steps). It makes the permanent Page token and finds the Page ID for you, so there is nothing to copy into .env.',
            ],
            'manual' => [
                'Page ID: open the Page on Facebook > About > Page transparency, or Meta Business Suite > Settings > Business assets > Pages (the ID shows under the Page name).',
                'Make the token last: in developers.facebook.com > Tools > Access Token Debugger, paste the token from Graph API Explorer, press "Debug", then "Extend Access Token" and copy the new long-lived token.',
                'Back in Graph API Explorer, paste it in the Access Token box, set the request to GET me/accounts and press Submit. Copy the "id" (the Page ID) and "access_token" (the Page token, starts with EAA). It does not expire unless you change your Facebook password, remove the app or lose admin rights.',
                'Empty me/accounts list: the Page sits in a business portfolio. Tick business_management when you generate the token, or add yourself to the Page with full control in Meta Business Suite, then try again.',
                'Put both in the .env file (FB_PAGE_ID and FB_PAGE_TOKEN, or TM_FB_PAGE_ID and TM_FB_PAGE_TOKEN for Travel Malawi), save, and press "Check the Facebook connection" in Settings. It should show the Page name and followers.',
            ],
        ],
        'instagram' => [
            'name' => 'Instagram (business account)', 'profile' => [1080, 1080], 'cover' => null,
            'auto' => 'Publishes picture, carousel and story posts on schedule through the Facebook connection, and reels when the app can serve the video. Instagram fetches every picture and video from a public web address, so the app must be online (APP_URL in .env). Until then the Channels tab lists each Instagram post as a hand-post task. Profile picture: change it in the Instagram app.',
            'env' => ['IG_USER_ID'],
            'steps' => [
                'In the Instagram app create the account (@travelmalawi or @promanagedit if free), then Settings > Account type and tools > Switch to professional account > Business.',
                'Profile: add the profile picture from the branding kit, a bio (what you do + who for + a call to action), the website link and the WhatsApp/Contact buttons.',
                'Link it to the Facebook Page: on the Facebook Page go to Settings > Linked accounts > Instagram > Connect (or Meta Business Suite > Settings > Instagram accounts > Connect). Instagram posting from the app only works for an account linked to a Page.',
                'Connect (or reconnect) Facebook in the "Facebook connection" box at the top of this screen. The app finds the linked Instagram account by itself and shows it as Linked. Tick instagram_basic and instagram_content_publish when you make the token.',
                'Only if it still shows "not linked": in Graph API Explorer, with the Page token, GET <Page ID>?fields=instagram_business_account, copy the "id" inside it (not your @username) and add it to .env as IG_USER_ID.',
                'Put the app online (APP_URL in .env, the public https address of this app) so Instagram can fetch post pictures. Instagram downloads each picture or video from APP_URL/media.php, so a link that only works on your own computer does not work. Until then the Channels tab gives you the caption and picture to post by hand.',
                'Reels: Instagram publishes a reel only when it can download the video from APP_URL/media.php. If the app is offline the reel appears in the Channels tab as a hand-post task, never as a failure.',
            ],
        ],
        'linkedin' => [
            'name' => 'LinkedIn Company Page', 'profile' => [400, 400], 'cover' => [1128, 191],
            'auto' => 'Can publish text, link and picture posts to the Company Page once LinkedIn approves API access (Community Management API). The post id it returns is kept so results can be read later. Until then, use "Copy" on a post (Channels tab) and paste it on LinkedIn. Logo and cover: change on LinkedIn (Edit page).',
            'env' => ['LI_ORG_ID', 'LI_TOKEN'],
            'steps' => [
                'From your personal LinkedIn profile: For Business (grid icon) > Create a Company Page > Company. Name, LinkedIn public URL (e.g. linkedin.com/company/promanaged-it), website, industry ("IT Services and IT Consulting" / "Travel Arrangements"), size "0-1 employees" (be honest), type "Self-owned" or "Privately held".',
                'Add the logo and cover from the branding kit, a tagline, the About text, location (Malawi), and a "Visit website" or "Contact us" button.',
                'Post from the Page 2 to 3 times a week and ask staff and friends to follow it and to list the company as their workplace.',
                'For posting from the app (optional, takes approval): on linkedin.com/developers create a NEW app just for this (LinkedIn requires the Community Management API to be the only product on its app), link it to your Company Page and verify it as the Page admin, then request "Community Management API". LinkedIn reviews it and may ask for business details.',
                'When approved, open the developer portal > Docs and tools > OAuth Token Tools, pick the app and the scopes w_organization_social, r_organization_social and rw_organization_admin, and create the token. Tokens last 60 days: put a reminder to renew it.',
                'Add LI_ORG_ID (the number in your Company Page admin address, linkedin.com/company/<number>/admin) and LI_TOKEN to .env, then switch on "LinkedIn" under Post automatically to.',
                'Add LI_TOKEN_EXPIRES=YYYY-MM-DD (60 days after you made the token) so the app warns you before it stops working. Renew the token in the same way as step 5.',
                'API version: the app sends the LinkedIn-Version header set by LI_API_VERSION in .env (YYYYMM, default ' . PM_LI_API_VERSION . '). LinkedIn supports each version for about a year; if posting says "version not active" (HTTP 426), set LI_API_VERSION to a newer month listed in LinkedIn\'s Marketing API versioning page.',
            ],
        ],
        'tiktok' => [
            'name' => 'TikTok', 'profile' => [720, 720], 'cover' => null,
            'auto' => 'The Social tab writes the short-video scripts. Film on a phone and post from the TikTok app (posting from apps needs a TikTok audit, not worth it yet).',
            'env' => [],
            'steps' => [
                'Install TikTok, sign up with the business email, then Profile > menu > Settings and privacy > Account > Switch to Business Account. Pick the category.',
                'Profile: picture from the branding kit, name, a short bio and the website link (business accounts can add one).',
                'Each week, film 1 to 3 of the video scripts from the Social tab (15 to 45 seconds, vertical, natural light, say the hook in the first 2 seconds, on-screen text, local music from TikTok\'s library).',
                'Post at the suggested time, answer every comment, and pin your best video to the top.',
            ],
        ],
        'youtube' => [
            'name' => 'YouTube (Shorts)', 'profile' => [800, 800], 'cover' => [2560, 1440],
            'auto' => 'Use the same phone videos as TikTok and Reels as YouTube Shorts. Upload from the YouTube app; change the picture and banner in YouTube Studio > Customisation.',
            'env' => [],
            'steps' => [
                'Sign in to YouTube with the business Google account, then profile picture > Create a channel. Use the business name and a handle (@...).',
                'YouTube Studio > Customisation > Branding: profile picture and banner from the branding kit (the banner keeps its text inside the centre safe area for phones and TVs).',
                'Basic info: description, links (website, WhatsApp, Facebook) and contact email.',
                'Upload the same vertical videos you post on TikTok and Facebook Reels as Shorts (under 60 seconds), with the caption from the Social tab.',
            ],
        ],
        'x' => [
            'name' => 'X (Twitter)', 'profile' => [400, 400], 'cover' => [1500, 500],
            'auto' => 'Copy a post from the Social tab and paste it on X (automatic posting needs a paid X API plan).',
            'env' => [],
            'steps' => [
                'Create the account with the business email, choose the handle (@...), then Edit profile: picture and header from the branding kit, bio, location (Malawi) and website.',
                'Post 3 to 5 times a week using "Copy" on a Social post (keep it under 280 characters), and reply to people in your industry and in Malawi.',
            ],
        ],
        'google' => [
            'name' => 'Google Business Profile (Maps)', 'profile' => [720, 720], 'cover' => [1080, 608],
            'auto' => 'The most important free listing for local search: the app writes the updates and you post them on the profile. Ask every happy client for a Google review.',
            'env' => [],
            'steps' => [
                'Go to business.google.com with the business Google account > Add business. Choose the category, add the area you serve (a home-based business can hide its address and list service areas instead).',
                'Verify the business (Google may ask for a short video of the workplace, a phone call or a postcard). Nothing shows publicly until it is verified.',
                'Add the logo and cover from the branding kit, opening hours, phone/WhatsApp, website, services with descriptions, and at least 5 real photos.',
                'Post an update every week (copy a Social post, Google allows 1,500 characters and one picture) and reply to every review within a day or two.',
            ],
        ],
        'whatsapp' => [
            'name' => 'WhatsApp Business', 'profile' => [640, 640], 'cover' => null,
            'auto' => 'Outreach runs through the WhatsApp tab. Use the same picture as the profile photo and post the Social pictures as Status updates.',
            'env' => [],
            'steps' => [
                'Install WhatsApp Business on the business phone number (not your personal number if you can avoid it).',
                'Business profile: profile picture from the branding kit, category, description, address/area, hours, email and website.',
                'Business tools: set a greeting message, an away message for outside working hours ("Thanks for your message, we reply on the next working day"), and quick replies (copy them from the WhatsApp tab).',
                'Catalogue: add your packages or services (no prices needed). Labels: New lead, Proposal sent, Client.',
                'Post the Social pictures as Status 3 to 4 times a week: your contacts see them for 24 hours.',
            ],
        ],
        'whatsapp_channel' => [
            'name' => 'WhatsApp Channel', 'profile' => [640, 640], 'cover' => null,
            'auto' => 'The Channels tab prepares the text and picture; you post it from the Updates tab. There is no automatic posting: only admins can post to a Channel, and a Channel made from a personal number has no API at all.',
            'env' => [],
            'steps' => [
                'Open WhatsApp (or WhatsApp Business) > Updates tab > Channels > + > Create channel. The person who creates it is the admin.',
                'Name it after the business, add the profile picture from the branding kit and a one-line description. Set it to public so people can find it.',
                'Put the Channel link in your Facebook Page intro, the links page and your email signature, and ask clients to follow it. Followers are anonymous to each other and cannot reply in the Channel.',
                'Post 2 to 3 times a week from the Updates tab: use the Copy button and the story or square picture from the Channels tab, then Mark posted. One link per update.',
                'Honest limits: Channels cannot be posted to by the app for a personal number, and updates are not private conversations, so keep enquiries on your WhatsApp number (the post text already says how).',
            ],
        ],
    ];
}

/* ---------------- per-business social settings ---------------- */

function pm_social_settings(string $brand): array
{
    $s = pm_load('settings', 'pm_default_settings');
    $c = (array)(($brand === 'travel' ? ($s['travel']['social'] ?? []) : ($s['social'] ?? [])));
    $def = $brand === 'travel'
        ? ['cover_head' => "Book Malawi's independent stays, direct.", 'cover_line' => 'Free for hosts · no commission · guests pay you directly', 'channels' => ['facebook' => true, 'instagram' => true, 'linkedin' => false]]
        : ['cover_head' => 'Business systems, built and supported in Malawi.', 'cover_line' => 'Software · websites · computer equipment · IT support', 'channels' => ['facebook' => true, 'instagram' => true, 'linkedin' => true]];
    return array_replace_recursive($def, $c);
}

function pm_social_settings_save(string $brand, array $c): void
{
    pm_update('settings', function (array $s) use ($brand, $c) { // under the lock: other settings saved meanwhile are kept
        if ($brand === 'travel') {
            $s['travel']['social'] = $c;
        } else {
            $s['social'] = $c;
        }
        return $s;
    }, 'pm_default_settings');
}

/** Saves the content pillars (name + weight) for a brand. Empty list = back to the defaults. */
function pm_social_pillars_save(string $brand, array $rows): void
{
    $list = [];
    foreach ($rows as $r) {
        $n = mb_substr(trim((string)($r['name'] ?? '')), 0, 40);
        $w = max(0, min(100, (int)($r['weight'] ?? 0)));
        if ($n !== '' && $w > 0) {
            $list[] = ['name' => $n, 'weight' => $w];
        }
    }
    $cfg = pm_social_settings($brand);
    $cfg['pillars'] = $list;
    pm_social_settings_save($brand, $cfg);
}

/* ---------------- branding kit ---------------- */

function pm_kit_dir(string $brand): string
{
    $d = PM_DATA . '/brandkit/' . ($brand === 'travel' ? 'travel' : 'promanaged');
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
    return $d;
}

/** The brand's hero photo: one you uploaded, else the website's preview picture. '' = none. */
function pm_hero_path(string $brand): string
{
    foreach (['jpg', 'png'] as $x) {
        $f = PM_ROOT . '/assets/' . ($brand === 'travel' ? 'travel' : 'promanaged') . "_hero.$x";
        if (is_file($f)) {
            return $f;
        }
    }
    $t = pm_link_thumb_path($brand);
    return is_file($t) ? $t : '';
}

/** Square profile picture: the logo centred on a clean background, kept inside the circle every platform crops to. */
function pm_kit_profile(string $brand, int $size): string
{
    $im = imagecreatetruecolor($size, $size);
    $logo = PM_ROOT . ($brand === 'travel' ? '/assets/travel_logo.png' : '/assets/logo.png');
    $li = is_file($logo) ? @imagecreatefrompng($logo) : false;
    if ($brand === 'travel' && $li) { // the TM mark is already a square tile
        imagecopyresampled($im, $li, 0, 0, 0, 0, $size, $size, imagesx($li), imagesy($li));
    } else {
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        if ($li) {
            $box = (int)($size * 0.62); // inside the circle crop
            $sc = min($box / imagesx($li), $box / imagesy($li));
            $w = (int)(imagesx($li) * $sc);
            $h = (int)(imagesy($li) * $sc);
            imagecopyresampled($im, $li, (int)(($size - $w) / 2), (int)(($size - $h) / 2), 0, 0, $w, $h, imagesx($li), imagesy($li));
        }
    }
    $f = pm_kit_dir($brand) . "/profile-{$size}.png";
    imagepng($im, $f, 6);
    return $f;
}

/** Cover/banner at any size: brand colour, the hero photo on the right, headline and line in the area every device shows. */
function pm_kit_cover(string $brand, int $W, int $H, array $safe = null): string
{
    pm_brand_set($brand);
    $s = pm_settings();
    $cfg = pm_social_settings($brand);
    $serif = pm_font(true);
    $sans = pm_font(false);
    $sansB = pm_font(false, true) ?: $sans;
    $im = imagecreatetruecolor($W, $H);
    [$r, $g, $b] = sscanf(ltrim($s['accent_color'] ?: '#17375E', '#'), '%02x%02x%02x');
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    $white = imagecolorallocate($im, 255, 255, 255);
    $soft = imagecolorallocatealpha($im, 255, 255, 255, 30);
    // the part of the banner every device shows
    [$sx, $sy, $sw, $sh] = $safe ?? [0, 0, $W, $H];
    $hero = pm_hero_path($brand);
    if ($hero !== '' && ($ph = @imagecreatefromstring((string)file_get_contents($hero)))) {
        $pw = (int)($W * 0.33);
        $cw = imagesx($ph);
        $chh = imagesy($ph);
        $cropW = (int)min($cw, $chh * $pw / $H);
        $cropH = (int)($cropW * $H / $pw);
        imagecopyresampled($im, $ph, $W - $pw, 0, (int)(($cw - $cropW) / 2), (int)(($chh - $cropH) / 2), $pw, $H, $cropW, $cropH);
        $fade = (int)($pw * 0.33);
        for ($x = 0; $x < $fade; $x++) {
            imageline($im, $W - $pw + $x, 0, $W - $pw + $x, $H, imagecolorallocatealpha($im, $r, $g, $b, (int)(127 * $x / $fade)));
        }
        $textW = (int)(($W - $pw) - $sx - $sw * 0.06);
    } else {
        $textW = (int)($sw * 0.86);
    }
    $textW = min($textW, (int)($sx + $sw * 0.94) - (int)($sx + $sw * 0.06));
    $x0 = (int)($sx + $sw * 0.06);
    // headline sized to the safe area, at most 2 lines
    $head = trim((string)$cfg['cover_head']) ?: $s['company_name'];
    $line = trim((string)$cfg['cover_line']);
    $hs = $sh * 0.16;
    do {
        $hl = pm_wrap_px($head, $serif, $hs, $textW);
        $hs *= 0.94;
    } while (count($hl) > 2 && $hs > 10);
    $ls = $hs * 0.42;
    $blockH = count($hl) * $hs * 1.3 + ($line !== '' ? $ls * 2.2 : 0) + $ls * 2;
    $y = (int)($sy + ($sh - $blockH) / 2 + $hs);
    foreach ($hl as $l) {
        imagettftext($im, $hs, 0, $x0, $y, $white, $serif, $l);
        $y += (int)($hs * 1.3);
    }
    if ($line !== '') {
        $y += (int)($ls * 0.4);
        imagettftext($im, $ls, 0, $x0, $y, $soft, $sans, pm_wrap_px($line, $sans, $ls, $textW)[0] ?? $line);
        $y += (int)($ls * 1.9);
    }
    $url = preg_replace('#^https?://#', '', rtrim(pm_link_cfg($brand)['url'] ?: ($s['website'] ?? ''), '/'));
    if ($url !== '') {
        imagettftext($im, $ls * 0.95, 0, $x0, $y + (int)($ls * 0.4), $white, $sansB, $url);
    }
    $f = pm_kit_dir($brand) . "/cover-{$W}x{$H}.png";
    imagepng($im, $f, 6);
    return $f;
}

/** Makes every platform's profile and cover picture for a business. Returns [platform => ['profile' => file, 'cover' => file|null]]. */
function pm_kit_build(string $brand): array
{
    $out = [];
    foreach (pm_platforms() as $k => $p) {
        $out[$k]['profile'] = pm_kit_profile($brand, $p['profile'][0]);
        $cv = $p['cover'];
        $safe = null;
        if ($k === 'youtube') {
            $safe = [(2560 - 1546) / 2, (1440 - 423) / 2, 1546, 423]; // YouTube shows only the centre on phones
        } elseif ($k === 'facebook') {
            $safe = [0, (856 - 624) / 2, 1640, 624]; // phones crop the top and bottom
        }
        $out[$k]['cover'] = $cv ? pm_kit_cover($brand, $cv[0], $cv[1], $safe) : null;
    }
    return $out;
}

/** Sets the Facebook Page's profile picture or cover from the kit. Returns [ok, message]. */
function pm_fb_apply(string $brand, string $which): array
{
    $c = pm_social_cfg($brand);
    if (!$c['ready']) {
        return [false, 'Connect the Facebook Page first (see the steps).'];
    }
    $p = pm_platforms()['facebook'];
    if ($which === 'cover') {
        $f = pm_kit_dir($brand) . "/cover-{$p['cover'][0]}x{$p['cover'][1]}.png";
        if (!is_file($f)) {
            $f = pm_kit_cover($brand, $p['cover'][0], $p['cover'][1], [0, (856 - 624) / 2, 1640, 624]);
        }
        [$ok, $d] = pm_graph('POST', $c['page_id'] . '/photos', ['published' => 'false'], $c['token'], ['source' => $f]);
        if (!$ok) {
            return [false, $d];
        }
        [$ok, $d2] = pm_graph('POST', $c['page_id'], ['cover' => $d['id'], 'offset_y' => 50], $c['token']);
        return $ok ? [true, 'Facebook cover updated.'] : [false, $d2 . ' You can upload the cover by hand: download it below.'];
    }
    $f = pm_kit_dir($brand) . "/profile-{$p['profile'][0]}.png";
    if (!is_file($f)) {
        $f = pm_kit_profile($brand, $p['profile'][0]);
    }
    [$ok, $d] = pm_graph('POST', $c['page_id'] . '/picture', [], $c['token'], ['source' => $f]);
    return $ok ? [true, 'Facebook profile picture updated.'] : [false, 'Facebook did not accept it (' . $d . '). Download the picture below and set it on the Page by hand.'];
}

/* ---------------- LinkedIn (text and link posts, after API approval) ---------------- */

function pm_linkedin_cfg(string $brand): array
{
    $e = pm_env();
    $p = $brand === 'travel' ? 'TM_' : '';
    $c = ['org' => preg_replace('/\D/', '', (string)($e[$p . 'LI_ORG_ID'] ?? '')), 'token' => trim((string)($e[$p . 'LI_TOKEN'] ?? ''))];
    $c['ready'] = $c['org'] !== '' && $c['token'] !== '';
    return $c;
}

/** The LinkedIn-Version header: LI_API_VERSION (TM_ for Travel Malawi) in .env, else the constant. LinkedIn supports each version for about a year. */
function pm_li_version(string $brand = ''): string
{
    $e = pm_env();
    $v = trim((string)($e[($brand === 'travel' ? 'TM_' : '') . 'LI_API_VERSION'] ?? $e['LI_API_VERSION'] ?? ''));
    return preg_match('/^\d{6}$/', $v) ? $v : PM_LI_API_VERSION;
}

/**
 * One LinkedIn REST call. $body: array (sent as JSON), string (raw upload) or null. Returns [http code, response headers (lower-case keys), decoded JSON or null].
 * Tests set $GLOBALS['PM_LI_STUB'] = fn(string $method, string $url, array $headers, $body): array [code, headers, json]; under PM_TEST there is no other route.
 */
function pm_li_request(string $method, string $url, string $token, $body, string $brand = '', array $extra = []): array
{
    $h = array_merge(['Authorization: Bearer ' . $token, 'X-Restli-Protocol-Version: 2.0.0', 'LinkedIn-Version: ' . pm_li_version($brand)], $extra);
    if (is_array($body)) {
        $h[] = 'Content-Type: application/json';
    }
    if (isset($GLOBALS['PM_LI_STUB']) || getenv('PM_TEST')) {
        return isset($GLOBALS['PM_LI_STUB']) ? ($GLOBALS['PM_LI_STUB'])($method, $url, $h, $body) : [0, [], null];
    }
    $heads = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$heads) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $heads[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        }]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    }
    pm_curl_native_ca($ch);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $heads, $raw === false ? null : json_decode((string)$raw, true)];
}

/**
 * Posts to the Company Page. $post is the post row (its 1200x627 card is uploaded first through /rest/images, with its alt text) or a local picture path.
 * Returns [ok, message, post urn]: the urn comes from the x-restli-id header and is kept as li_urn (also in $GLOBALS['PM_LI_LAST_URN'] = [brand, urn]).
 */
function pm_linkedin_post(string $brand, string $text, array|string $post = '', string $alt = '', string $title = ''): array
{
    $image = is_string($post) ? $post : '';
    if (is_array($post) && $post) {
        $img = function_exists('pm_social_card') && ($post['format'] ?? 'image') !== 'text' ? pm_social_card($post, 'link') : '';
        $image = is_string($img) ? $img : '';
        $alt = $alt !== '' ? $alt : (function_exists('pm_variant_alt') ? pm_variant_alt($post) : '');
        $title = $title !== '' ? $title : (string)($post['headline'] ?? '');
    }
    $c = pm_linkedin_cfg($brand);
    if (!$c['ready']) {
        return [false, 'LinkedIn is not connected.', ''];
    }
    $owner = 'urn:li:organization:' . $c['org'];
    $body = ['author' => $owner, 'commentary' => mb_substr($text, 0, 2900), 'visibility' => 'PUBLIC',
        'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []], 'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false];
    $note = '';
    if ($image !== '' && is_file($image)) {
        [$code, , $j] = pm_li_request('POST', 'https://api.linkedin.com/rest/images?action=initializeUpload', $c['token'], ['initializeUploadRequest' => ['owner' => $owner]], $brand);
        $up = (string)($j['value']['uploadUrl'] ?? '');
        $urn = (string)($j['value']['image'] ?? '');
        if ($code === 200 && $up !== '' && $urn !== '') {
            [$code2] = pm_li_request('PUT', $up, $c['token'], (string)file_get_contents($image), $brand, ['Content-Type: application/octet-stream']);
            if ($code2 >= 200 && $code2 < 300) {
                $body['content'] = ['media' => ['id' => $urn, 'altText' => mb_substr($alt, 0, 300)] + ($title !== '' ? ['title' => mb_substr($title, 0, 200)] : [])];
            } else {
                $note = ' (the picture upload failed, so it went out as text)';
            }
        } else {
            $note = ' (LinkedIn did not accept the picture, so it went out as text)';
        }
    }
    [$code, $heads, $j] = pm_li_request('POST', 'https://api.linkedin.com/rest/posts', $c['token'], $body, $brand);
    if ($code === 201) {
        $urn = (string)($heads['x-restli-id'] ?? '');
        $GLOBALS['PM_LI_LAST_URN'] = [$brand, $urn];
        return [true, 'Posted on LinkedIn.' . $note, $urn];
    }
    $why = $code === 401 ? ' (the token has expired: tokens last 60 days)' : ($code === 426 ? ' (LinkedIn no longer supports API version ' . pm_li_version($brand) . ': set LI_API_VERSION in .env to a newer month)' : '');
    return [false, 'LinkedIn: ' . ($j['message'] ?? ($code === 0 ? 'no answer' : 'error ' . $code)) . $why, ''];
}
