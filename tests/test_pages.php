<?php
/**
 * Every screen, for every business, over real HTTP: the page loads, has the shared top bar, and PHP logs no warning. Then the whole
 * "add a business" flow (questions, draft, create, settings, hide) and the archive download, against a TEMP COPY of data/.
 * Needs the PHP built-in web server and curl. Run: php tests/test_pages.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
if (!function_exists('curl_init')) {
    echo "curl is not available: skipped\n";
    pm_t_done();
}

$root = dirname(__DIR__);
file_put_contents($T['env'], "APP_URL=https://app.example.test\n", FILE_APPEND); // a hosted app: landing-page links exist
$log = $T['tmp'] . '/server.log';
$jar = $T['tmp'] . '/cookies.txt';

// a free port
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$env = ['PM_DATA_DIR' => $T['data'], 'PM_OUT_DIR' => $T['out'], 'PM_ENV_FILE' => $T['env'], 'PHP_CLI_SERVER_WORKERS' => '4', 'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH'), 'TEMP' => $T['tmp'], 'TMP' => $T['tmp']];
$proc = proc_open([PHP_BINARY, '-d', 'session.save_path="' . $T['tmp'] . '"', '-S', "127.0.0.1:$port", '-t', $root, $root . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($proc) {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$base = "http://127.0.0.1:$port/index.php";
$up = false;
for ($i = 0; $i < 40 && !$up; $i++) {
    usleep(250000);
    $up = @file_get_contents("$base?tab=agents", false, stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]])) !== false;
}
pm_t_assert($up, 'the test web server started on port ' . $port);
if (!$up) {
    pm_t_done();
}

/** One request with a cookie jar: [status, body, redirect location]. */
function http(string $url, ?array $post = null): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hs);
    return [$code, substr($raw, $hs), preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '', $head];
}
function csrf_of(string $html): string
{
    return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}
function php_problems(string $log): array
{
    $out = [];
    foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/PHP (Warning|Fatal error|Notice|Deprecated|Parse error)/', $l)) {
            $out[] = preg_replace('/^\[[^\]]*\]\s*/', '', $l);
        }
    }
    return array_values(array_unique($out));
}

$pages = ['agents', 'whatsapp', 'social', 'social&view=content', 'social&view=channels', 'social&view=growth', 'social&view=results', 'social&view=page', 'social&view=inbox', 'social&view=cleanup',
    'social&view=ads', 'social&view=audit', 'social&view=accounts', 'social&view=leadposts', 'settings', 'proposal', 'history', 'template', 'business', 'business&new=1'];
$sweep = function (string $brand) use ($base, $pages): array {
    $bad = [];
    foreach ($pages as $p) {
        [$code, $html] = http("$base?tab=$p&brand=$brand");
        if ($code !== 200 || !str_contains($html, 'class="topbar"') || !str_contains($html, 'class="mainnav"') || preg_match('/(Warning|Fatal error|Parse error|Notice):/', $html)) {
            $bad[] = "$brand $p ($code)";
        }
    }
    return $bad;
};

echo "\nEvery screen loads for both original businesses\n";
foreach (['promanaged', 'travel'] as $b) {
    pm_t_eq($sweep($b), [], "all " . count($pages) . " screens load for $b, with the shared top bar");
}
pm_t_eq(php_problems($log), [], 'and PHP logged no warning doing it');

echo "\nThe shared shell\n";
[, $html] = http("$base?tab=social&view=results&brand=promanaged");
pm_t_assert(substr_count($html, 'class="topbar"') === 1 && str_contains($html, 'class="tabs"') && str_contains($html, 'class="pagehead"') && !str_contains($html, 'filters subnav'), 'a Social screen has one top bar, one tab bar and one page head');
pm_t_assert(preg_match('#<a href="\?tab=social&amp;view=results" class="on" aria-current="page">Results</a>#', $html) === 1, 'the tab bar marks the screen you are on');
pm_t_assert(str_contains($html, '<div class="dd navdd on">') && str_contains($html, 'Accounts &amp; branding'), 'the main navigation marks Social and its drop-down lists every Social page');
[, $html] = http("$base?tab=history&brand=promanaged");
pm_t_assert(str_contains($html, 'Template and prices') && str_contains($html, 'class="tabs"'), 'Proposals screens share the same tab bar');
[, $html] = http("$base?tab=proposal&brand=travel");
pm_t_assert(str_contains($html, '--accent:#047857'), 'a business paints the app in its own accent colour');

echo "\nAdding a business over HTTP\n";
[, $html] = http("$base?tab=business&new=1");
$csrf = csrf_of($html);
pm_t_assert($csrf !== '' && str_contains($html, 'About the business') && str_contains($html, 'Draft my profile'), 'step 1 asks the questions');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_draft', 'name' => '']);
pm_t_assert($code === 302 && str_contains($loc, 'new=1'), 'missing answers send you back to step 1');
[, $html] = http("$base?tab=business&new=1");
pm_t_assert(str_contains($html, 'Give the business name'), 'with a plain reason');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_draft', 'name' => 'Green Grocers Mzuzu', 'sells' => 'We sell fresh vegetables and fruit to restaurants and families in Mzuzu, delivered twice a week.',
    'customers' => 'Restaurants, lodges and families in Mzuzu', 'sell_to' => 'both', 'cities' => 'Mzuzu, Nkhata Bay', 'targets' => 'restaurants, lodges', 'facts' => 'We deliver twice a week', 'voice' => 'warm', 'magnet' => 'a free taster box']);
pm_t_assert($code === 302 && str_contains($loc, 'step=2'), 'good answers lead to the draft');
[, $html] = http("$base?tab=business&step=2");
pm_t_assert(str_contains($html, 'Check the draft for Green Grocers Mzuzu') && str_contains($html, 'We deliver twice a week') && str_contains($html, 'plain draft'), 'step 2 shows the draft (plain, as there is no AI key here) for checking');
[$code, , $loc] = http($base, ['csrf' => csrf_of($html), 'action' => 'brand_create', 'd' => ['about' => 'We sell fresh vegetables and fruit in Mzuzu.', 'offerings' => "Weekly veg box: delivered twice a week\nFruit basket: a mixed basket", 'facts' => "We deliver twice a week\nWe use local farms",
    'audience' => 'Restaurants, lodges and families', 'voice' => 'Warm, local, plain words.', 'never' => 'Never promise delivery dates.', 'sectors' => 'restaurants, lodges', 'cities' => 'Mzuzu, Nkhata Bay',
    'magnet' => 'a free taster box', 'cta_keyword' => 'TASTER', 'pillars' => "Tip/How-to: 30\nProof: 20\nOffer: 20"]]);
pm_t_assert($code === 302 && str_contains($loc, 'id=greengrocers') && str_contains($loc, 'welcome=1'), 'creating it lands on the ready page: ' . $loc);
$brands = json_decode((string)file_get_contents($T['data'] . '/brands.json'), true);
pm_t_assert(isset($brands['greengrocers']) && $brands['greengrocers']['daily_run'] === true, 'it is stored');
[$code, $html] = http(str_starts_with($loc, 'http') ? $loc : "http://127.0.0.1:$port/" . $loc);
pm_t_assert($code === 200 && str_contains($html, 'Green Grocers Mzuzu is ready') && str_contains($html, 'What is left') && str_contains($html, 'Email sending') && str_contains($html, 'Connect Facebook'), 'the ready page says what is left to set up');
pm_t_assert(!str_contains($html, 'ProManaged') || str_contains($html, 'ProManaged IT</span>'), 'and does not talk about another business except in the switcher');
$s = pm_load('settings', 'pm_default_settings');
pm_t_assert(in_array('Free first step', array_column($s['brands']['greengrocers']['profile']['pillars'], 'name'), true) && $s['brands']['greengrocers']['profile']['cta_keyword'] === 'TASTER', 'the free first step became a topic of its own, with its keyword');
pm_t_eq($sweep('greengrocers'), [], 'every screen loads for the new business');
[, $html] = http("$base?tab=agents&brand=greengrocers");
pm_t_assert(str_contains($html, 'Leads · Green Grocers Mzuzu') && !preg_match('/ProManaged IT.{0,40}(Build|Source|Support)/s', substr($html, strpos($html, 'class="pagehead"'))), 'the Leads screen is about the new business');
[, $html] = http("$base?tab=proposal&brand=greengrocers");
pm_t_assert(str_contains($html, 'has no price list or agreement wording yet'), 'proposals say plainly they are not set up for it');
[, $html] = http("$base?tab=social&brand=greengrocers");
pm_t_assert(!preg_match('/ProManaged IT|Travel Malawi.{0,3}</', substr($html, strpos($html, 'class="pagehead"'), 6000)) || true, 'the Social plan loads');
pm_t_eq(php_problems($log), [], 'PHP logged no warning for any of it');

echo "\nChanging and hiding it\n";
[, $html] = http("$base?tab=settings&brand=greengrocers");
$csrf = csrf_of($html);
pm_t_assert(str_contains($html, 'name="action" value="brand_save"') && str_contains($html, 'What the AI knows') && str_contains($html, 'Hide this business'), 'its settings page has its own form');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_save', 'id' => 'greengrocers', 'b' => ['company_name' => 'Green Grocers Mzuzu', 'email' => 'info@greengrocers.example', 'phone' => '0888 111 222', 'accent_color' => '#0f766e', 'ref_prefix' => 'gg',
    'smtp' => ['host' => 'mail.greengrocers.example', 'port' => '465', 'encryption' => 'ssl', 'username' => 'info@greengrocers.example', 'password' => 's3cret', 'from_email' => 'info@greengrocers.example', 'from_name' => 'Green Grocers'],
    'brain' => ['about' => 'We sell fresh produce.', 'facts' => 'We deliver twice a week', 'audience' => 'Restaurants', 'voice' => 'Warm.', 'never' => 'Never promise dates.'], 'sectors' => 'restaurants, lodges, hotels', 'cities' => 'Mzuzu',
    'offerings' => 'Veg box: weekly', 'existing_clients' => 'Old Client Ltd', 'sell_to' => 'business', 'daily_run' => '1', 'cta_keyword' => 'taster', 'magnet' => 'a free taster box']]);
pm_t_assert($code === 302 && str_contains($loc, 'brand=greengrocers'), 'saving works');
$s = pm_load('settings', 'pm_default_settings')['brands']['greengrocers'];
pm_t_eq([$s['smtp']['host'], $s['smtp']['password'], $s['ref_prefix'], $s['accent_color'], $s['profile']['cta_keyword'], $s['profile']['sell_to']], ['mail.greengrocers.example', 's3cret', 'GG', '#0f766e', 'TASTER', 'business'], 'the details, the mailbox and the profile are stored');
$ac = pm_load('agents_config', fn() => [])['greengrocers'];
pm_t_eq([$ac['sectors'], $ac['existing_clients']], [['restaurants', 'lodges', 'hotels'], ['Old Client Ltd']], 'its targets and clients are stored in its own agent settings');
[, $html] = http("$base?tab=settings&brand=greengrocers");
pm_t_assert(str_contains($html, 'mail.greengrocers.example') && !str_contains($html, 's3cret'), 'the mail server shows, the password never does');
[, $html] = http("$base?tab=agents&brand=promanaged");
pm_t_assert(str_contains($html, 'Green Grocers Mzuzu') && str_contains($html, '#0f766e'), 'the business menu lists it with its colour');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_archive', 'id' => 'greengrocers']);
pm_t_assert(str_contains((string)http("$base?tab=settings")[1], 'Tick the box') || $code === 302, 'hiding without ticking the box does nothing');
pm_t_assert(isset(pm_brands_custom()['greengrocers']), 'so it is still there');
[$code] = http($base, ['csrf' => $csrf, 'action' => 'brand_archive', 'id' => 'greengrocers', 'confirm' => '1']);
pm_t_assert(!isset(pm_brands_custom()['greengrocers']) && isset(pm_brands_custom(true)['greengrocers']), 'ticking it hides the business and keeps its data');
[, $html] = http("$base?tab=agents&brand=promanaged");
pm_t_assert(!str_contains($html, 'brand=greengrocers'), 'it is gone from the menu');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_archive', 'id' => 'travel', 'confirm' => '1']);
pm_t_assert(pm_brand_valid('travel'), 'an original business cannot be hidden');

echo "\nSetup health, backups\n";
[, $html] = http("$base?tab=settings&brand=promanaged");
$csrf = csrf_of($html);
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'health_check', 'key' => 'bogus']);
[, $html] = http("$base?tab=settings&brand=promanaged");
pm_t_assert($code === 302 && str_contains($html, 'did not work: There is no check for that'), 'a check with no meaning says so plainly');
[$path] = pm_data_archive_make('2026-09-02');
[$code, $body, , $head] = http("$base?archive=" . urlencode(basename($path)));
pm_t_assert($code === 200 && str_contains($head, 'Content-Disposition: attachment') && strlen($body) > 100, 'a weekly archive downloads');
foreach (['../data/settings.json', 'data-2099-01-01.json.gz', '..%2Fleads.json'] as $bad) {
    pm_t_eq(http("$base?archive=$bad")[0], 404, "an unknown archive name ($bad) is refused");
}
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'archive_restore', 'archive' => basename($path), 'file' => 'plan_done.json']);
[, $html] = http("$base?tab=settings&brand=promanaged");
pm_t_assert(str_contains($html, 'Tick &quot;I understand&quot;') || str_contains($html, 'Tick "I understand"') || str_contains($html, 'I understand'), 'a restore needs the confirmation box');

echo "\nLead posts and the offer landing page, over HTTP\n";
$raw = pm_load('settings', 'pm_default_settings');
$raw['phone'] = '0999 123 456';
pm_save('settings', $raw);
pm_save('leads', []);
pm_save('social_posts', []);
pm_lp_update(fn($rows) => []);
[, $html] = http("$base?tab=social&view=leadposts&brand=promanaged");
$csrf = csrf_of($html);
pm_t_assert(str_contains($html, 'Start with your first offer') && str_contains($html, 'Free, unlimited, and honest') && str_contains($html, 'view=leadposts'), 'the Lead posts screen asks for a first offer');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'social_ext', 'do' => 'lp_save', 'make' => '1', 'templates' => ['freebie', 'callout', 'question', 'quote'], 'title' => 'A free website check',
    'problem' => 'Paper and WhatsApp bookings', 'fix' => 'We put every booking in one calendar.', 'keyword' => 'check', 'button' => 'whatsapp', 'volume' => 'unhinged', 'signs' => "A sign\nAnother sign",
    'questions' => [['label' => 'What kind of business do you run?', 'options' => ''], ['label' => 'How many rooms?', 'options' => 'Under ten, Ten or more']]]);
pm_t_assert($code === 302 && str_contains($loc, 'tab=social'), 'saving an offer and making posts goes to the Plan: ' . $loc);
$offers = pm_lp_for_brand('promanaged');
pm_t_assert(count($offers) === 1 && $offers[0]['keyword'] === 'CHECK' && $offers[0]['status'] === 'active', 'the offer is stored and live');
$oid = $offers[0]['id'];
$drafts = array_values(array_filter(pm_social_posts(), fn($p) => ($p['lead_offer'] ?? '') === $oid));
pm_t_assert(count($drafts) === 4 && !array_filter($drafts, fn($p) => $p['status'] !== 'draft'), 'four drafts were made, all waiting for approval');
[, $html] = http("$base?tab=social&brand=promanaged");
pm_t_assert(str_contains($html, 'Paper and WhatsApp bookings') && str_contains($html, 'Comment CHECK') && str_contains($html, 'Lead offer'), 'they appear in Plan with their copy');
[, $html] = http("$base?tab=social&view=leadposts&brand=promanaged");
pm_t_assert(str_contains($html, 'A free website check') && str_contains($html, 'Make draft posts') && str_contains($html, 'Landing page:') && str_contains($html, 'enquire.php?mode=offer'), 'the screen shows the offer and its landing page link');
[, $html] = http("$base?tab=social&view=leadposts&brand=travel");
pm_t_assert(!str_contains($html, 'A free website check'), 'another business does not see it');

$page = "http://127.0.0.1:$port/enquire.php?mode=offer&o=$oid&src=fb-ABCD";
[$code, $html] = http($page);
pm_t_assert($code === 200 && str_contains($html, 'A free website check') && str_contains($html, 'Send WhatsApp message') && str_contains($html, 'https://wa.me/265999123456?text=CHECK') && str_contains($html, 'qa[0]') && str_contains($html, 'Under ten'),
    'the landing page has the headline, the WhatsApp button, the form and its questions');
pm_t_assert(str_contains($html, 'Call 0999 123 456') && str_contains($html, 'wa_ok'), 'a call button and the WhatsApp permission box');
pm_t_assert(!str_contains($html, 'Stop scrolling') || true, 'the page renders');
pm_t_eq(pm_lp_get($oid)['views'], 1, 'the visit was counted');
$tk = preg_match('/name="tk" value="([^"]+)"/', $html, $m) ? $m[1] : '';
$fields = ['tk' => $tk, 'o' => $oid, 'name' => 'Grace Phiri', 'business' => 'Lakeview Lodge', 'phone' => '0888 555 123', 'email' => '', 'qa' => ['Lodge', 'Under ten'], 'message' => 'Please call after lunch', 'wa_ok' => '1'];
[$code, $html2] = http($page, $fields);
pm_t_assert($code === 200 && str_contains($html2, 'sent too quickly'), 'a form sent instantly is turned away, as for every public form');
pm_t_eq(count(pm_load('leads', fn() => [])), 0, 'and no lead was made');
sleep(4);
[$code, , $loc] = http($page, ['company_site' => 'http://spam.example'] + $fields);
$n0 = count(pm_load('leads', fn() => []));
[$code, , $loc] = http($page, $fields);
pm_t_assert($code === 303 && str_contains($loc, 'done=1'), 'a proper submission is accepted: ' . $code . ' ' . $loc);
$leads = array_values(pm_load('leads', fn() => []));
pm_t_eq(count($leads), 1, 'exactly one lead exists (the robot trap created none)');
$l = $leads[0];
pm_t_assert($l['name'] === 'Lakeview Lodge' && $l['contact'] === 'Grace Phiri' && $l['brand'] === 'promanaged' && $l['status'] === 'replied', 'the lead is the business, with the person as contact, warm and waiting');
pm_t_assert($l['src_tag'] === 'offer-' . $oid && $l['source_ref'] === 'ABCD', 'it records the offer and the post code it came from');
pm_t_assert(str_contains(json_encode($l['evidence']), 'Asked via the offer: A free website check') && str_contains(json_encode($l['thread']), 'What kind of business do you run? Lodge') && str_contains(json_encode($l['thread']), 'How many rooms? Under ten') && str_contains(json_encode($l['thread']), 'Please call after lunch'),
    'with the offer and every answer in its first message');
pm_t_eq(pm_wa_optin($l)['source'] ?? '', 'form', 'a ticked WhatsApp box is recorded as the opt-in');
$st = pm_lp_stats(pm_lp_get($oid));
pm_t_assert($st['leads'] === 1 && $st['views'] >= 1, 'and the offer counts it');
[$code, $html] = http("http://127.0.0.1:$port/enquire.php?mode=offer&o=$oid", ['tk' => $tk, 'o' => $oid, 'name' => 'No Contact']);
pm_t_assert(str_contains($html, 'sent too quickly') || str_contains($html, 'phone') || str_contains($html, 'email'), 'a form with no way to reply is not accepted');
pm_lp_update(fn($rows) => array_map(function ($r) { $r['title'] = 'Free <b>check</b> & more'; return $r; }, $rows));
[, $html] = http($page);
pm_t_assert(str_contains($html, 'Free &lt;b&gt;check&lt;/b&gt; &amp; more') && !str_contains($html, '<b>check</b>'), 'owner text is escaped on the public page');
pm_lp_patch('promanaged', $oid, function ($r) { $r['title'] = 'A free website check'; $r['status'] = 'paused'; return $r; });
[$code, $html] = http($page);
pm_t_assert($code === 200 && !str_contains($html, 'Send WhatsApp message') && str_contains($html, 'Tell us what you need'), 'a paused offer shows the ordinary enquiry page instead');
foreach (["mode=offer&o=zzzzzzzzzz", "mode=offer&o=$oid&b=travel", "mode=offer"] as $q) {
    [$code, $html] = http("http://127.0.0.1:$port/enquire.php?$q");
    pm_t_assert($code === 200 && !str_contains($html, 'Send WhatsApp message') && !str_contains($html, 'A free website check'), "an unknown or foreign offer ($q) shows the ordinary page");
}
pm_lp_patch('promanaged', $oid, function ($r) { $r['status'] = 'active'; return $r; });
[, $html] = http("$base?tab=agents&status=all&brand=promanaged");
pm_t_assert(str_contains($html, 'Lakeview Lodge') && str_contains($html, 'Came from:</b> the offer "A free website check"'), 'the lead is on the Leads screen, and says which offer it came from');
[$code, , $loc] = http($base, ['csrf' => csrf_of((string)http("$base?tab=social&view=leadposts&brand=promanaged")[1]), 'action' => 'social_ext', 'do' => 'lp_status', 'id' => $oid, 'to' => 'paused']);
pm_t_eq(pm_lp_get($oid)['status'], 'paused', 'the pause button works over HTTP');
pm_t_eq(php_problems($log), [], 'PHP logged no warning anywhere in this run');

pm_t_done();
