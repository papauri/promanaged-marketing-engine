<?php
/**
 * Watchdog job (core-platform): tells the owner BEFORE publishing breaks, and pauses publishing safely when it has.
 *  - once a day per brand: Facebook token check (valid? expires soon? permissions missing?) and the LinkedIn token expiry (warn at 10 days)
 *  - every run: posts that failed for good or need a check get one owner alert each; a dead token pauses that brand's publishing
 *    (state auth_down[brand], approved posts stay approved) and is re-checked until it works again.
 * All alerts go through pm_social_alert_once, so nobody is nagged twice for the same thing.
 */
function pm_job_watchdog(string $brand): string
{
    $out = [];
    $label = (pm_brand_name($brand)) . ': ';
    $c = pm_social_cfg($brand);
    // posts that need a person
    foreach (pm_social_posts() as $p) {
        if (($p['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        $cap = mb_substr((string)($p['caption'] ?? ''), 0, 70);
        if (($p['status'] ?? '') === 'failed' && !str_contains((string)($p['error'] ?? ''), 'No social account')) {
            if (pm_social_alert_once('perm:' . $p['id'], 'Social post failed', $label . $cap . "\n" . ($p['error'] ?? '') . "\nOpen Social > Plan to retry or edit it.")) {
                $out[] = 'alerted: failed post';
            }
        } elseif (($p['status'] ?? '') === 'needs_check') {
            if (pm_social_alert_once('check:' . $p['id'], 'Social post needs a check', $label . $cap . "\nIt may or may not be on the Page. Open the Page, then press Mark posted or Retry in Social > Plan.")) {
                $out[] = 'alerted: post needs a check';
            }
        }
    }
    if (!$c['ready']) {
        return implode('; ', $out) ?: 'not connected';
    }
    $down = !empty(pm_social_state()['auth_down'][$brand]);
    if ($down && pm_social_auth_recheck($brand)) {
        $out[] = 'token works again: publishing resumes';
        $down = false;
    }
    $today = date('Y-m-d');
    if ((pm_social_state()['watchdog'][$brand] ?? '') === $today) {
        return implode('; ', $out);
    }
    pm_social_state_set(function (array $s) use ($brand, $today) {
        $s['watchdog'][$brand] = $today;
        return $s;
    });
    $info = pm_fb_token_info($c['token']);
    if (empty($info['ok'])) {
        $msg = (string)($info['error'] ?? 'Token is not valid');
        if (pm_social_err_kind($msg) === 'auth' || $msg === 'Token is not valid') {
            pm_social_state_set(function (array $s) use ($brand, $msg) {
                $s['auth_down'][$brand] = ['at' => time(), 'msg' => mb_substr($msg, 0, 240), 'checked' => time()];
                return $s;
            });
            pm_social_alert_once('auth:' . $brand, 'Facebook token needs renewing', $label . "Publishing is paused: the access token is no longer valid. Approved posts wait safely.\n" . $msg);
            $out[] = 'token invalid: publishing paused';
        } else {
            $out[] = 'token check could not run: ' . mb_substr($msg, 0, 80);
        }
    } else {
        if (!empty($info['expires']) && $info['expires'] < time() + 10 * 86400) {
            $d = max(0, (int)floor(($info['expires'] - time()) / 86400));
            pm_social_alert_once('exp:' . $brand . ':' . date('Y-m', $info['expires']), 'Facebook token expires in ' . $d . ' day(s)', $label . 'Make a new Page token (Social > Accounts) before ' . date('j M', $info['expires']) . ', or posting stops.', 3 * 86400);
            $out[] = "token expires in $d day(s)";
        }
        if (!empty($info['missing'])) {
            pm_social_alert_once('perm-missing:' . $brand, 'Facebook permissions missing', $label . 'The token lacks: ' . implode(', ', $info['missing']) . '. Some features will not work until you add them (Social > Accounts).', 7 * 86400);
            $out[] = 'permissions missing: ' . implode(', ', $info['missing']);
        }
        if ($down) {
            pm_social_state_set(function (array $s) use ($brand) {
                unset($s['auth_down'][$brand]);
                return $s;
            });
        }
    }
    if (function_exists('pm_li_token_days_left')) { // LinkedIn tokens run out silently after about 60 days
        try {
            $d = pm_li_token_days_left($brand);
            if ($d !== null && $d <= 10) {
                pm_social_alert_once('li:' . $brand, 'LinkedIn token expires' . ($d > 0 ? " in $d day(s)" : ' now'), $label . 'Make a new LinkedIn access token before it runs out, or LinkedIn posting stops.', 3 * 86400);
                $out[] = "LinkedIn token: $d day(s) left";
            }
        } catch (Throwable) {
        }
    }
    return implode('; ', $out) ?: 'tokens fine';
}
