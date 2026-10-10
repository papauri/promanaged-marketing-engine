<?php
/**
 * Extension registry for the Social screens. Other modules (lib/sx_*.php) plug in by NAME, nothing is edited here:
 *   pm_panel_<slot>_<name>(string $vb, array $ctx): string   HTML panels, rendered by pm_social_panels($slot, $vb, $ctx)
 *   pm_do_<name>(string $vb): array{msg,kind,to}             form handlers, POST action=social_ext&do=<name>
 *   pm_job_<name>(string $brand): string / pm_jobg_<name>()  jobs run by social_run.php and cron.php (lib/social_jobs.php)
 *   pm_today_<name>(string $brand): array                    "today" items [text, href, urgency 0..2]
 *   pm_hook_after_publish_<mod>(array $p, array $c): ?array  patch merged into a post right after it is published
 * Slots: plan_top plan_kpi plan_card plan_done plan_bottom growth_top growth_bottom inbox_top accounts lead_card wa_top results channels.
 */

/** Sub-navigation of the Social screen: view => label, in order. */
function pm_social_tabs(): array
{
    return ['' => 'Plan', 'content' => 'Content', 'channels' => 'Channels', 'growth' => 'Growth', 'results' => 'Results', 'page' => 'Page', 'inbox' => 'Inbox',
        'cleanup' => 'Clean-up', 'ads' => 'Ads', 'audit' => 'Audit', 'accounts' => 'Accounts &amp; branding'];
}

/** The view file for a Social sub-view (absolute path). A tab whose module is not installed falls back to the Plan view. */
function pm_social_view_file(string $view): string
{
    $name = match (true) {
        $view === 'accounts' => 'view_social_accounts.php',
        $view === 'content' => 'view_social_content.php',
        $view === 'channels' => 'view_social_channels.php',
        $view === 'results' => 'view_social_results.php',
        in_array($view, ['page', 'inbox', 'audit'], true) => 'view_social_page.php',
        in_array($view, ['growth', 'cleanup', 'ads'], true) => 'view_social_growth.php',
        default => 'view_social.php',
    };
    return is_file(__DIR__ . '/' . $name) ? __DIR__ . '/' . $name : __DIR__ . '/view_social.php';
}

/** Names of the registered functions pm_<kind>_<slot>_*, sorted. */
function pm_social_registered(string $prefix): array
{
    $out = [];
    foreach (get_defined_functions()['user'] as $f) {
        if (str_starts_with($f, $prefix)) {
            $out[] = $f;
        }
    }
    sort($out);
    return $out;
}

/** Concatenated HTML of every pm_panel_<slot>_<name>($vb, $ctx). A panel that throws is skipped. */
function pm_social_panels(string $slot, string $vb, array $ctx = []): string
{
    if (!preg_match('/^[a-z_]+$/', $slot)) {
        return '';
    }
    $h = '';
    foreach (pm_social_registered('pm_panel_' . $slot . '_') as $fn) {
        try {
            $h .= (string)$fn($vb, $ctx);
        } catch (Throwable) {
        }
    }
    return $h;
}

/** Runs a form handler pm_do_<do>($vb). Always returns ['msg','kind','to']. */
function pm_social_ext_run(string $do, string $vb): array
{
    $fn = 'pm_do_' . $do;
    if (!preg_match('/^[a-z0-9_]+$/', $do) || !function_exists($fn)) {
        return ['msg' => 'Unknown action.', 'kind' => 'err', 'to' => 'social'];
    }
    try {
        $r = (array)$fn($vb);
    } catch (Throwable $e) {
        return ['msg' => 'Could not do that: ' . $e->getMessage(), 'kind' => 'err', 'to' => 'social'];
    }
    return ['msg' => (string)($r['msg'] ?? ''), 'kind' => ($r['kind'] ?? 'ok') === 'err' ? 'err' : 'ok', 'to' => (string)($r['to'] ?? 'social')];
}

/** Red/amber strip shown on every Social screen: a dead token blocks publishing for that brand. */
function pm_social_banner(): string
{
    $h = '';
    try {
        $down = (array)(pm_social_state()['auth_down'] ?? []);
        $b = pm_brand();
        if (!empty($down[$b])) {
            $h .= '<div class="flash err sx-banner"><b>Publishing is paused for ' . pm_h(pm_brand_name($b)) . '.</b> '
                . pm_h((string)($down[$b]['msg'] ?? 'The Facebook access token is no longer valid.')) . ' Approved posts wait safely. Make a new Page token in <a href="?tab=social&amp;view=accounts">Accounts</a>; posting resumes by itself.</div>';
        }
    } catch (Throwable) {
    }
    return $h;
}
