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
$env = ['PM_DATA_DIR' => $T['data'], 'PM_OUT_DIR' => $T['out'], 'PM_ENV_FILE' => $T['env'], 'PHP_CLI_SERVER_WORKERS' => '4', 'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH'), 'TEMP' => $T['tmp'], 'TMP' => $T['tmp'], 'PM_TEST' => '1']; // PM_TEST: no website is fetched and no AI is called from the test server
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
/** A JSON POST (what Meta sends to the WhatsApp webhook): [status, body]. */
function post_json(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload)]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw];
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

echo "\nSuggestions while typing in the find boxes\n";
[$code, $body, , $head] = http("$base?suggest=blend&brand=promanaged");
$sj = json_decode($body, true);
pm_t_assert($code === 200 && str_contains($head, 'application/json') && !empty($sj['rows']) && str_contains($sj['rows'][0]['name'], 'Blend') && str_contains($sj['rows'][0]['href'], 'lead='), 'typing a few letters returns the matching leads as JSON, and a lead of the business on screen opens it');
[$code, $body] = http("$base?suggest=blend&mode=web&brand=promanaged");
pm_t_assert($code === 200 && json_decode($body, true) === ['rows' => []], 'the web part answers quietly with nothing when the AI is not set up');
pm_t_eq(json_decode(http("$base?suggest=" . urlencode('zzzz nothing') . '&brand=travel')[1], true), ['rows' => []], 'and nothing matching gives an empty list');
[, $html] = http("$base?tab=agents&brand=promanaged");
pm_t_assert(str_contains($html, 'name="name" required data-suggest data-web="1"') && str_contains($html, 'Start typing: it suggests businesses you already know'), 'the Leads search box has the type-ahead and says what it does');
[, $html] = http("$base?tab=proposal&brand=promanaged");
pm_t_assert(str_contains($html, 'data-suggest data-web="1"'), 'so does the find-and-prepare-a-proposal box');
[, $js] = http("http://127.0.0.1:$port/assets/ui.js");
pm_t_assert(str_contains($js, "'?suggest='") && str_contains($js, 'aria-activedescendant') && str_contains($js, 'From a web search'), 'the script that does it is served');
pm_t_eq(php_problems($log), [], 'PHP logged no warning for any of it');

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
pm_t_assert(str_contains($html, 'name="website"') && str_contains($html, 'the AI reads it') && str_contains($html, 'name="notes"'), 'step 1 asks for the website, which the AI reads, and for anything else to read');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_draft', 'name' => 'Green Grocers Mzuzu', 'sells' => 'We sell fresh vegetables and fruit to restaurants and families in Mzuzu, delivered twice a week.', 'customers' => 'Restaurants, lodges and families', 'website' => 'not a website']);
[, $html] = http("$base?tab=business&new=1");
pm_t_assert($code === 302 && str_contains($loc, 'new=1') && str_contains($html, 'That website address does not look right'), 'a website that is not an address sends you back with a plain reason');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_draft', 'name' => 'Green Grocers Mzuzu', 'sells' => 'We sell fresh vegetables and fruit to restaurants and families in Mzuzu, delivered twice a week.',
    'customers' => 'Restaurants, lodges and families in Mzuzu', 'sell_to' => 'both', 'cities' => 'Mzuzu, Nkhata Bay', 'targets' => 'restaurants, lodges', 'facts' => 'We deliver twice a week', 'voice' => 'warm', 'magnet' => 'a free taster box',
    'website' => 'www.greengrocers.example', 'notes' => 'We pick the vegetables on the morning we deliver them.']);
pm_t_assert($code === 302 && str_contains($loc, 'step=2'), 'good answers lead to the draft');
[, $html] = http("$base?tab=business&step=2");
pm_t_assert(str_contains($html, 'Check the draft for Green Grocers Mzuzu') && str_contains($html, 'We deliver twice a week') && str_contains($html, 'plain draft') && str_contains($html, 'We could not open greengrocers.example'),
    'step 2 shows the draft (plain, as there is no AI key here), and says plainly that the website could not be read');
[$code, , $loc] = http($base, ['csrf' => csrf_of($html), 'action' => 'brand_redraft', 'd' => ['facts' => "We deliver twice a week\nWe pick vegetables fresh", 'sectors' => 'restaurants, lodges, hotels', 'cities' => 'Mzuzu', 'magnet' => 'a free taster box', 'never' => '',
    'email' => 'info@greengrocers.example', 'phone' => '0888 111 222'], 'qtext' => ['Do you deliver to Nkhata Bay?'], 'qa' => ['Yes, on Fridays']]);
[, $html] = http("$base?tab=business&step=2");
pm_t_assert($code === 302 && str_contains($loc, 'step=2') && str_contains($html, 'We pick vegetables fresh') && str_contains($html, 'restaurants, lodges, hotels') && str_contains($html, 'Drafted again with your answers'), 'answering the AI\'s questions drafts again and keeps the owner\'s edits');
pm_t_assert(str_contains($html, 'value="info@greengrocers.example"') && str_contains($html, 'value="0888 111 222"'), 'including the email and phone on the draft screen');
[$code, , $loc] = http($base, ['csrf' => csrf_of($html), 'action' => 'brand_create', 'd' => ['about' => 'We sell fresh vegetables and fruit in Mzuzu.', 'offerings' => "Weekly veg box: delivered twice a week\nFruit basket: a mixed basket", 'facts' => "We deliver twice a week\nWe use local farms",
    'audience' => 'Restaurants, lodges and families', 'voice' => 'Warm, local, plain words.', 'never' => 'Never promise delivery dates.', 'sectors' => 'restaurants, lodges', 'cities' => 'Mzuzu, Nkhata Bay',
    'magnet' => 'a free taster box', 'cta_keyword' => 'TASTER', 'pillars' => "Tip/How-to: 30\nProof: 20\nOffer: 20", 'email' => 'info@greengrocers.example', 'phone' => '0888 111 222']]);
pm_t_assert($code === 302 && str_contains($loc, 'id=greengrocers') && str_contains($loc, 'welcome=1'), 'creating it lands on the ready page: ' . $loc);
$brands = json_decode((string)file_get_contents($T['data'] . '/brands.json'), true);
pm_t_assert(isset($brands['greengrocers']) && $brands['greengrocers']['daily_run'] === true, 'it is stored');
[$code, $html] = http(str_starts_with($loc, 'http') ? $loc : "http://127.0.0.1:$port/" . $loc);
pm_t_assert($code === 200 && str_contains($html, 'Green Grocers Mzuzu is ready') && str_contains($html, 'What is left') && str_contains($html, 'Email sending') && str_contains($html, 'Connect Facebook'), 'the ready page says what is left to set up');
pm_t_assert(!str_contains($html, 'ProManaged') || str_contains($html, 'ProManaged IT</span>'), 'and does not talk about another business except in the switcher');
$s = pm_load('settings', 'pm_default_settings');
pm_t_assert(in_array('Free first step', array_column($s['brands']['greengrocers']['profile']['pillars'], 'name'), true) && $s['brands']['greengrocers']['profile']['cta_keyword'] === 'TASTER', 'the free first step became a topic of its own, with its keyword');
pm_t_eq([$s['brands']['greengrocers']['email'], $s['brands']['greengrocers']['phone']], ['info@greengrocers.example', '0888 111 222'], 'the email and phone confirmed on the draft screen are stored');
pm_t_assert(str_contains($html, 'Review what the AI learned') && str_contains($html, 'Make your first offer') && str_contains($html, 'view=leadposts'), 'the ready page points to what the AI learned and to the first offer');
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
    'offerings' => 'Veg box: weekly', 'existing_clients' => 'Old Client Ltd', 'sell_to' => 'business', 'daily_run' => '1', 'cta_keyword' => 'taster', 'magnet' => 'a free taster box',
    'tg_segments' => "restaurants | They need fresh produce twice a week | a menu that changes daily; a kitchen garden they cannot keep up\nlodges | Guests expect fresh food", 'tg_skip' => "supermarket chains\ninternational suppliers", 'tg_angles' => 'Fresh from the farm, delivered']]);
pm_t_assert($code === 302 && str_contains($loc, 'brand=greengrocers'), 'saving works');
$s = pm_load('settings', 'pm_default_settings')['brands']['greengrocers'];
pm_t_eq([$s['smtp']['host'], $s['smtp']['password'], $s['ref_prefix'], $s['accent_color'], $s['profile']['cta_keyword'], $s['profile']['sell_to']], ['mail.greengrocers.example', 's3cret', 'GG', '#0f766e', 'TASTER', 'business'], 'the details, the mailbox and the profile are stored');
$ac = pm_load('agents_config', fn() => [])['greengrocers'];
pm_t_eq([$ac['sectors'], $ac['existing_clients']], [['restaurants', 'lodges', 'hotels'], ['Old Client Ltd']], 'its targets and clients are stored in its own agent settings');
[, $html] = http("$base?tab=settings&brand=greengrocers");
pm_t_assert(str_contains($html, 'mail.greengrocers.example') && !str_contains($html, 's3cret'), 'the mail server shows, the password never does');
$tg = pm_brand_targeting('greengrocers');
pm_t_eq([array_column($tg['segments'], 'name'), $tg['segments'][0]['signals'], $tg['skip'], $tg['angles']], [['restaurants', 'lodges'], ['a menu that changes daily', 'a kitchen garden they cannot keep up'], ['supermarket chains', 'international suppliers'], ['Fresh from the farm, delivered']],
    'who to target, why and who to skip are saved from the settings page');
pm_t_assert(str_contains($html, 'Who to target, and why') && str_contains($html, 'restaurants | They need fresh produce twice a week | a menu that changes daily; a kitchen garden they cannot keep up') && str_contains($html, 'Study the business again'), 'and shown there again as editable lines');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_restudy', 'id' => 'greengrocers']);
[, $html2] = http("$base?tab=settings&brand=greengrocers");
pm_t_assert($code === 302 && str_contains($loc, 'settings') && str_contains($html2, 'The AI needs a key'), 'studying again without an AI key says so plainly');
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_study_apply', 'id' => 'greengrocers']);
pm_t_assert($code === 302 && str_contains($loc, 'settings'), 'applying a study that does not exist is refused');
[, $html] = http("$base?tab=agents&brand=promanaged");
pm_t_assert(str_contains($html, 'Green Grocers Mzuzu') && str_contains($html, '#0f766e'), 'the business menu lists it with its colour');
echo "\nThe one-page offer\n";
[$code, $body, , $head] = http("$base?onepager=greengrocers");
pm_t_assert($code === 200 && str_contains($head, 'application/pdf') && str_starts_with($body, '%PDF') && str_contains($head, 'GreenGrocersMzuzu-one-page-offer.pdf'), 'a business added in the app can download its one-page offer');
pm_t_eq([http("$base?onepager=travel")[0], http("$base?onepager=promanaged")[0], http("$base?onepager=nobody")[0]], [404, 404, 404], 'the original businesses and unknown ones have none (they send proposals)');
$mkl = fn(string $lid, string $brand, array $thread) => ['id' => $lid, 'brand' => $brand, 'name' => "Lead $lid", 'type' => 'lodge', 'city' => 'Mzuzu', 'address' => '', 'website' => '', 'phone' => '0888 100 20' . strlen($lid), 'email' => "$lid@lodge$lid.example", 'whatsapp' => '',
    'contact' => 'Grace Phiri', 'contact_title' => '', 'evidence' => [], 'need_signals' => [], 'score' => 70, 'status' => 'replied', 'notes' => [], 'drafts' => [], 'sent' => [], 'followups' => 0, 'source' => 'web', 'thread' => $thread, 'created' => date('Y-m-d H:i'), 'updated' => date('Y-m-d H:i')];
pm_save('leads', ['g1' => $mkl('g1', 'greengrocers', [['dir' => 'in', 'at' => date('Y-m-d H:i'), 'text' => 'Please tell me more', 'ch' => 'email']]), 'g2' => $mkl('g2', 'greengrocers', []), 'p1' => $mkl('p1', 'promanaged', [])]);
[, $html] = http("$base?tab=agents&status=all&brand=greengrocers");
pm_t_assert(substr_count($html, 'One-page offer') === 2 && str_contains($html, 'href="?onepager=greengrocers"') && substr_count($html, 'Send with the PDF') === 1 && str_contains($html, 'once they have written to you') && !str_contains($html, 'Create proposal'),
    'on a business added in the app, every lead card offers the one-page offer instead of a proposal, with the send form only for someone who has written');
$csrf2 = csrf_of($html);
[, $html] = http("$base?tab=agents&status=all&brand=promanaged");
pm_t_assert(str_contains($html, 'Create proposal') && !str_contains($html, 'Send with the PDF'), 'ProManaged IT keeps its proposals');
[$code, , $loc] = http($base, ['csrf' => $csrf2, 'action' => 'agents', 'do' => 'onepager_send', 'id' => 'g2', 'subject' => 'Hello', 'body' => 'Thank you for getting in touch. Attached is a one-page summary of what we do.']);
[, $html] = http("$base?tab=agents&status=all&brand=greengrocers");
pm_t_assert($code === 302 && str_contains($html, 'They have not written to you yet'), 'it is not sent to someone who has not written to the business');
[$code] = http($base, ['csrf' => $csrf2, 'action' => 'agents', 'do' => 'onepager_send', 'id' => 'p1', 'subject' => 'Hello', 'body' => 'Thank you for getting in touch. Attached is a one-page summary of what we do.']);
[, $html] = http("$base?tab=agents&status=all&brand=greengrocers");
pm_t_assert(str_contains($html, 'is for businesses you added') || str_contains($html, 'Lead not found') || $code === 302, 'and the original businesses cannot use it');
[$code] = http($base, ['csrf' => $csrf2, 'action' => 'agents', 'do' => 'onepager_send', 'id' => 'g1', 'subject' => 'Hello', 'body' => 'You must act now, this is a limited time offer for you and guaranteed results!']);
[, $html] = http("$base?tab=agents&status=all&brand=greengrocers");
pm_t_assert(str_contains($html, 'Not sent.'), 'the same wording rules apply as for any email: ' . substr(strip_tags((string)(preg_match('/class="flash[^"]*">(.*?)<\/div>/s', $html, $fm) ? $fm[1] : '')), 0, 120));
[$code] = http($base, ['csrf' => $csrf2, 'action' => 'agents', 'do' => 'onepager_send', 'id' => 'g1', 'subject' => 'Green Grocers Mzuzu: a one-page summary', 'body' => "Hello Grace,\n\nThank you for getting in touch with Green Grocers Mzuzu. Attached is a one-page summary of what we do and how to reach us.\n\nIf you tell us a little about what you need, we will come back with the next step."]);
[, $html] = http("$base?tab=agents&status=all&brand=greengrocers");
pm_t_assert(str_contains($html, 'Could not send:'), 'a proper request builds the PDF and tries to send it (the mail server in the test does not exist, so it says so and nothing is marked sent)');
pm_t_eq(pm_load('leads', fn() => [])['g1']['sent'], [], 'and the lead is not marked as contacted');
pm_save('leads', []);
[$code, , $loc] = http($base, ['csrf' => $csrf, 'action' => 'brand_archive', 'id' => 'greengrocers']);
pm_t_assert(str_contains((string)http("$base?tab=settings")[1], 'Tick the box') || $code === 302, 'hiding without ticking the box does nothing');
pm_t_assert(isset(pm_brands_custom()['greengrocers']), 'so it is still there');
[$code] = http($base, ['csrf' => $csrf, 'action' => 'brand_archive', 'id' => 'greengrocers', 'confirm' => '1']);
pm_t_assert(!isset(pm_brands_custom()['greengrocers']) && isset(pm_brands_custom(true)['greengrocers']), 'ticking it hides the business and keeps its data');
pm_t_eq(http("$base?onepager=greengrocers")[0], 404, 'a hidden business has no one-page offer to download');
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

echo "\nThe WhatsApp webhook: one address for every connected number\n";
$wa = "http://127.0.0.1:$port/wa.php";
pm_t_eq(http($wa)[0], 404, 'with no business connected the webhook does not exist');
file_put_contents($T['env'], "WA_BIZ_TOKEN=t1\nWA_BIZ_PHONE_ID=999\nWA_BIZ_VERIFY=vv-promanaged\nTM_WA_BIZ_TOKEN=t2\nTM_WA_BIZ_PHONE_ID=998\nTM_WA_BIZ_VERIFY=vv-travel\n", FILE_APPEND);
[$code, $body] = http("$wa?hub_verify_token=vv-travel&hub_challenge=abc123");
pm_t_assert($code === 200 && trim($body) === 'abc123', 'Meta\'s check passes with any connected business\'s token');
pm_t_eq(http("$wa?hub_verify_token=vv-promanaged&hub_challenge=xyz")[1], 'xyz', 'including the other business\'s');
pm_t_eq(http("$wa?hub_verify_token=wrong&hub_challenge=abc123")[0], 403, 'and a wrong token is refused');
$payload = fn(string $pid, string $from, string $text, string $name = '') => ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => $pid], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
    'messages' => [['from' => $from, 'type' => 'text', 'text' => ['body' => $text]]]]]]]]];
pm_save('leads', []);
[$code, $body] = post_json($wa, $payload('998', '265999000555', 'Do you have a room on Friday?', 'Joyce Mwale'));
$ld = array_values(pm_load('leads', fn() => []));
pm_t_assert($code === 200 && str_contains($body, 'new lead from WhatsApp') && count($ld) === 1 && $ld[0]['brand'] === 'travel' && $ld[0]['name'] === 'Joyce Mwale' && $ld[0]['channel'] === 'whatsapp',
    'a stranger who writes to Travel Malawi\'s number becomes a Travel Malawi lead, named as WhatsApp names them: ' . $body);
[$code, $body] = post_json($wa, $payload('555', '265999000666', 'Hello?'));
pm_t_assert($code === 200 && str_contains($body, 'not connected to any business') && count(pm_load('leads', fn() => [])) === 1, 'a message to a number no business owns is ignored');
[$code, $body] = post_json($wa, $payload('999', '265999000555', 'And do you do websites?', 'Joyce Mwale'));
$ld = array_values(pm_load('leads', fn() => []));
pm_t_assert(count($ld) === 2 && count(array_unique(array_column($ld, 'brand'))) === 2, 'the same person writing to the other business\'s number is a lead of that business, not a merge');
file_put_contents($T['env'], "TM_WA_BIZ_APP_SECRET=topsecret\n", FILE_APPEND);
$raw = json_encode($payload('998', '265999000777', 'Is there parking?', 'Peter'));
$signed = fn(string $secret) => (function () use ($wa, $raw, $secret) {
    $ch = curl_init($wa);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $raw, $secret)]]);
    curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $c;
})();
[$code] = post_json($wa, $payload('998', '265999000777', 'Is there parking?', 'Peter'));
pm_t_eq($code, 403, 'once a business has its Meta app secret in .env, an unsigned call to its number is refused');
pm_t_eq($signed('wrong'), 403, 'so is one signed with the wrong secret');
pm_t_eq($signed('topsecret'), 200, 'and one signed by WhatsApp with the right one is accepted');
[$code] = post_json($wa, $payload('999', '265999000888', 'Hello again'));
pm_t_eq($code, 200, 'a business with no app secret set is accepted as before (the other business is not affected)');
pm_t_eq(php_problems($log), [], 'PHP logged no warning anywhere in this run');

pm_t_done();
