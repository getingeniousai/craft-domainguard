<?php
/**
 * Behavioral suite for DomainGuard Domain Watch (Craft CMS).
 *
 * Run: php tests/run-tests.php — exits non-zero on any failure.
 * Drives the plugin's framework-free core through the real user flows:
 * gateway calls over a mocked transport, the honesty gate, card/badge
 * rendering from stored reports, monitor change decisions, takedown
 * packet building, and the Plugin Store packaging rules.
 */

require __DIR__ . '/craft-stubs.php';

use ingeniousai\domainguard\core\ApiClient;
use ingeniousai\domainguard\core\ApiException;
use ingeniousai\domainguard\core\Badge;
use ingeniousai\domainguard\core\Card;
use ingeniousai\domainguard\core\Monitor;
use ingeniousai\domainguard\core\ReportData;
use ingeniousai\domainguard\core\Takedown;
use ingeniousai\domainguard\core\TransportException;

$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "ok   - $label\n";
    } else {
        $fail++;
        echo "FAIL - $label\n";
    }
}

function client_with(MockTransport $t, string $key = 'DGAPI-test-key'): ApiClient
{
    return new ApiClient(
        static function () use ($key): string {
            return $key;
        },
        $t,
        static function (int $s): void {
        }
    );
}

$root = __DIR__ . '/..';

/* ---------------------------------------------------------------------
 * Packaging (Plugin Store rules from Craft's own docs)
 * ------------------------------------------------------------------- */
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
check('composer.json is valid JSON', is_array($composer));
check('composer type is craft-plugin', ($composer['type'] ?? '') === 'craft-plugin');
check('package name follows vendor/craft- convention', ($composer['name'] ?? '') === 'ingeniousai/craft-domainguard');
check('extra.handle is domainguard', ($composer['extra']['handle'] ?? '') === 'domainguard');
check('extra.class names the plugin class', ($composer['extra']['class'] ?? '') === 'ingeniousai\\domainguard\\DomainWatch');
check('plugin name does not begin with "Craft" and names no edition', 0 !== strpos($composer['extra']['name'] ?? '', 'Craft') && !preg_match('/\b(Lite|Plus|Pro)\b/', $composer['extra']['name'] ?? ''));
check('license is proprietary (Craft license)', ($composer['license'] ?? '') === 'proprietary');
check('requires Craft 4.8.1+ or 5.x explicitly', ($composer['require']['craftcms/cms'] ?? '') === '^4.8.1 || ^5.0.0');
check('version is semver 1.0.0', ($composer['version'] ?? '') === '1.0.0');
check('support email is the orders inbox', ($composer['support']['email'] ?? '') === 'orders@getingeniousai.com');

$license = (string) file_get_contents($root . '/LICENSE.md');
check('LICENSE.md carries the copyright holder Ingenious AI', false !== strpos($license, 'Copyright © Ingenious AI'));
check('LICENSE.md is the Craft license', false !== strpos($license, 'Don’t use the same license on more than one project'));

$changelog = (string) file_get_contents($root . '/CHANGELOG.md');
check('CHANGELOG has a dated 1.0.0 heading', (bool) preg_match('/^## 1\.0\.0 - \d{4}-\d{2}-\d{2}/m', $changelog));

foreach (['src/icon.svg', 'src/icon-mask.svg'] as $icon) {
    $xml = simplexml_load_file($root . '/' . $icon);
    check("$icon exists and is valid square SVG", false !== $xml && (string) $xml['width'] === (string) $xml['height']);
}
foreach (['src/templates/index.twig', 'src/templates/settings.twig', 'src/templates/takedown.twig', 'src/DomainWatch.php', 'src/migrations/Install.php'] as $f) {
    check("$f exists", is_file($root . '/' . $f));
}

/* ---------------------------------------------------------------------
 * ApiClient — gateway contract over a mocked transport
 * ------------------------------------------------------------------- */
$t = new MockTransport();
$t->queue[] = ['code' => 200, 'body' => '{"ok":true,"service":"domainguard","version":"1.4.0"}'];
$health = client_with($t, '')->health();
check('health works with NO key configured', ($health['ok'] ?? false) === true);
check('health sent no X-API-Key header', !isset($t->calls[0]['headers']['X-API-Key']));

$t = new MockTransport();
try {
    client_with($t, '')->createScan('example.com');
    check('scan without a key is refused', false);
} catch (ApiException $e) {
    check('scan without a key is refused (no_key)', 'no_key' === $e->errorCode);
    check('refusal happens before any HTTP call', 0 === count($t->calls));
}

$t = new MockTransport();
$t->queue[] = ['code' => 202, 'body' => '{"id":"job_123","domain":"example.com","status":"queued"}'];
$job = client_with($t)->createScan('example.com');
check('createScan returns the job view', ($job['id'] ?? '') === 'job_123');
check('createScan sent the key header', ($t->calls[0]['headers']['X-API-Key'] ?? '') === 'DGAPI-test-key');
check('createScan POSTed the domain as JSON', json_decode((string) $t->calls[0]['body'], true) === ['domain' => 'example.com']);
check('createScan hit the v1 scans endpoint', substr($t->calls[0]['url'], -strlen('/api/v1/scans')) === '/api/v1/scans');

$t = new MockTransport();
$t->queue[] = ['code' => 200, 'body' => '{"ok":true}'];
client_with($t)->getScan('job/with space');
check('getScan URL-encodes the job id', false !== strpos($t->calls[0]['url'], 'job%2Fwith%20space'));

$error_cases = [
    [401, '{"error":"unauthorized"}', 'unauthorized', 'DGAPI'],
    [429, '{"error":"monthly_quota_exceeded"}', 'monthly_quota_exceeded', 'used its included scans'],
    [409, '{"error":"concurrency_limit"}', 'concurrency_limit', 'already running'],
    [404, '{"error":"not_found"}', 'not_found', 'no longer available'],
    [422, '{"error":"invalid_domain","detail":"That domain does not resolve."}', 'invalid_domain', 'That domain does not resolve.'],
    [500, '{"error":"weird","detail":"Gateway hiccup."}', 'weird', 'Gateway hiccup.'],
    [500, '{}', 'http_500', 'HTTP 500'],
];
foreach ($error_cases as [$code, $body, $expect_code, $expect_msg]) {
    $t = new MockTransport();
    $t->queue[] = ['code' => $code, 'body' => $body];
    try {
        client_with($t)->usage();
        check("gateway error $expect_code surfaces", false);
    } catch (ApiException $e) {
        check("gateway error maps to $expect_code", $e->errorCode === $expect_code && false !== strpos($e->getMessage(), $expect_msg));
    }
}

$t = new MockTransport();
$t->queue[] = new TransportException('Connection timed out');
try {
    client_with($t)->health();
    check('transport failure surfaces', false);
} catch (ApiException $e) {
    check('transport failure maps to transport + gateway wording', 'transport' === $e->errorCode && false !== strpos($e->getMessage(), 'Could not reach DomainGuard'));
}

$t = new MockTransport();
$t->queue = [
    ['code' => 202, 'body' => '{"id":"j1","status":"queued"}'],
    ['code' => 200, 'body' => '{"id":"j1","status":"running"}'],
    ['code' => 200, 'body' => '{"id":"j1","status":"done","report":' . json_encode(report_fixture()) . '}'],
];
$view = client_with($t)->runScanBlocking('example-site.test');
check('runScanBlocking polls to done and returns the report', ($view['status'] ?? '') === 'done' && ReportData::isReport($view['report'] ?? null));

$t = new MockTransport();
$t->queue = [
    ['code' => 202, 'body' => '{"id":"j2","status":"queued"}'],
    ['code' => 200, 'body' => '{"id":"j2","status":"failed"}'],
];
try {
    client_with($t)->runScanBlocking('example-site.test');
    check('failed scan surfaces scan_failed', false);
} catch (ApiException $e) {
    check('failed scan surfaces scan_failed', 'scan_failed' === $e->errorCode);
}

$t = new MockTransport();
$t->queue = [['code' => 202, 'body' => '{"status":"queued"}']];
try {
    client_with($t)->runScanBlocking('example-site.test');
    check('job without id surfaces bad_job', false);
} catch (ApiException $e) {
    check('job without id surfaces bad_job', 'bad_job' === $e->errorCode);
}

/* ---------------------------------------------------------------------
 * ReportData — the honesty gate + extractions
 * ------------------------------------------------------------------- */
$fixture = report_fixture();
check('isReport accepts the gateway fixture', ReportData::isReport($fixture));
check('isReport rejects a bare string', !ReportData::isReport('nope'));
check('isReport rejects a report missing findings', !ReportData::isReport(['domain' => 'x', 'score' => 1]));
check('isReport rejects null', !ReportData::isReport(null));
check('lookalikeNames are sorted plain names', ReportData::lookalikeNames($fixture) === ['examp1e-site.test', 'example-sites.test']);
check('certIds extracts ids in order', ReportData::certIds($fixture) === ['c1', 'c2', 'c3']);
check('caaProtected false when no issue property', false === ReportData::caaProtected($fixture));
check('caaProtected true with an issue record', true === ReportData::caaProtected(report_fixture(['caa' => ['has_issue' => true]])));
check('domainFromUrl lowercases the host', ReportData::domainFromUrl('https://EXAMPLE.com/path?q=1') === 'example.com');
check('domainFromUrl handles ports', ReportData::domainFromUrl('https://example.com:8443/') === 'example.com');
check('domainFromUrl of a relative URL is empty', ReportData::domainFromUrl('/just/a/path') === '');
check('domainFromUrl of empty is empty', ReportData::domainFromUrl('') === '' && ReportData::domainFromUrl(null) === '');
check('findingTitles pulls titles', ReportData::findingTitles($fixture)[0] === '2 lookalike domains are registered');
$entry = ReportData::historyEntry($fixture, 1761686400);
check('historyEntry carries score/band/counts', $entry['score'] === 70 && $entry['band'] === 'Exposed' && $entry['lookalikes'] === 2 && $entry['certs'] === 12 && $entry['caa_ok'] === 0 && $entry['ts'] === 1761686400);

/* ---------------------------------------------------------------------
 * Card + Badge — artifacts only from real reports
 * ------------------------------------------------------------------- */
check('scoreColor bands', Card::scoreColor(95) === '#22c55e' && Card::scoreColor(80) === '#84cc16' && Card::scoreColor(70) === '#eab308' && Card::scoreColor(45) === '#f97316' && Card::scoreColor(10) === '#ef4444');
check('scoreBand labels', Card::scoreBand(95) === 'Well protected' && Card::scoreBand(70) === 'Exposed' && Card::scoreBand(30) === 'Critical exposure');
check('card is empty for a non-report', Card::svg(['domain' => 'x']) === '' && Card::svg(null) === '');
$svg = Card::svg($fixture);
check('card renders the real score + domain', false !== strpos($svg, '>70<') && false !== strpos($svg, 'example-site.test'));
check('card chips carry the real counts', false !== strpos($svg, '2 LOOKALIKES REGISTERED') && false !== strpos($svg, '12 CERTS IN CT LOGS') && false !== strpos($svg, 'CAA MISSING'));
check('card headline is the first finding', false !== strpos($svg, 'HIGH: 2 lookalike domains are registered'));
check('card is 1200x630', false !== strpos($svg, 'width="1200" height="630"'));
$evil = report_fixture(['domain' => 'x"><script>alert(1)</script>']);
check('card escapes a hostile domain', false === strpos(Card::svg($evil), '<script>') && false !== strpos(Card::svg($evil), '&lt;script&gt;'));
check('share text (lookalikes) names the count', false !== strpos(Card::shareText($fixture), '2 lookalike domains already registered'));
$clean = report_fixture(['lookalikes_registered' => []]);
check('share text (clean) says so', false !== strpos(Card::shareText($clean), 'no lookalike domains registered'));
$urls = Card::shareUrls($fixture);
check('share URLs cover x/linkedin/facebook/whatsapp', isset($urls['x'], $urls['linkedin'], $urls['facebook'], $urls['whatsapp']));
check('landing URL carries craft-plugin UTM', false !== strpos(Card::landingUrl('card'), 'utm_source=craft-plugin'));

$badge_none = Badge::svg(null);
check('badge without a scan is the honest unscanned state', false !== strpos($badge_none, 'not watched yet'));
$badge = Badge::svg($fixture);
check('badge shows the real score + band colour', false !== strpos($badge, '70/100') && false !== strpos($badge, '#eab308'));
$snippet = Badge::embedSnippet('https://example.com/domainguard-badge.svg', $fixture);
check('embed snippet links the UTM landing + badge URL', false !== strpos($snippet, 'utm_medium=badge') && false !== strpos($snippet, 'domainguard-badge.svg') && false !== strpos($snippet, 'alt="DomainGuard exposure score: 70/100"'));

/* ---------------------------------------------------------------------
 * Monitor — change decisions
 * ------------------------------------------------------------------- */
$settings = ['alert_drop' => 10, 'alert_new_lookalike' => true, 'alert_new_cert' => true, 'alert_caa_lost' => true];
$same = Monitor::evaluate($fixture, $fixture, $settings);
check('identical reports: no alert', false === $same['alert'] && 0 === $same['drop']);
$dropped = Monitor::evaluate($fixture, report_fixture(['score' => 55]), $settings);
check('15-point drop alerts', true === $dropped['alert'] && 15 === $dropped['drop']);
$small = Monitor::evaluate($fixture, report_fixture(['score' => 66]), $settings);
check('4-point drop alone does not alert', false === $small['alert']);
$with_new = report_fixture();
$with_new['lookalikes_registered'][] = ['perm' => 'examp1e-sites.test', 'fuzzer' => 'addition', 'mx' => false];
$new_lk = Monitor::evaluate($fixture, $with_new, $settings);
check('new lookalike detected + alerts', $new_lk['new_lookalikes'] === ['examp1e-sites.test'] && true === $new_lk['alert']);
$off = $settings;
$off['alert_new_lookalike'] = false;
check('lookalike toggle off silences that signal', [] === Monitor::evaluate($fixture, $with_new, $off)['new_lookalikes']);
$with_cert = report_fixture();
$with_cert['ct_certs'][] = ['cert_id' => 'c4', 'issuer' => 'Sectigo', 'serial' => 'dd', 'not_before' => '2026-10-01', 'not_after' => '2027-01-01', 'names' => ['example-site.test'], 'entry_ts' => '2026-10-01'];
check('new certificate detected by id', 1 === Monitor::evaluate($fixture, $with_cert, $settings)['new_certs']);
$rolled = report_fixture(['ct_cert_count' => 15]);
check('new certificates fall back to the count when ids roll off', 3 === Monitor::evaluate($fixture, $rolled, $settings)['new_certs']);
$caa_before = report_fixture(['caa' => ['has_issue' => true]]);
$caa_lost = Monitor::evaluate($caa_before, $fixture, $settings);
check('losing CAA protection alerts', true === $caa_lost['caa_lost'] && true === $caa_lost['alert']);
$text = Monitor::alertText('example-site.test', $fixture, report_fixture(['score' => 55]), $dropped, $settings, 'https://example.com/admin/domainguard');
check('alert subject carries the score move', false !== strpos($text['subject'], 'score 70 → 55'));
check('alert body links the CP report', false !== strpos($text['body'], 'https://example.com/admin/domainguard'));

/* ---------------------------------------------------------------------
 * Takedown — packets from observed facts only
 * ------------------------------------------------------------------- */
$packets = Takedown::packets($fixture);
check('one packet per registered lookalike', 2 === count($packets));
check('packets are empty for a non-report', [] === Takedown::packets(['x' => 1]));
check('packets are empty with no lookalikes', [] === Takedown::packets($clean));
$mx_packet = $packets[0];
check('MX packet says the lookalike publishes MX', false !== strpos($mx_packet['text'], 'publishes MX records') && true === $mx_packet['has_mx']);
check('MX packet step warns about mail', false !== strpos($mx_packet['steps'][2], 'accepts mail'));
$nomx_packet = $packets[1];
check('no-MX packet says so honestly', false !== strpos($nomx_packet['text'], 'does not publish MX records') && false === $nomx_packet['has_mx']);
check('packet names domain, lookalike, technique, date', false !== strpos($mx_packet['text'], 'My domain: example-site.test') && false !== strpos($mx_packet['text'], 'Lookalike domain: example-sites.test') && false !== strpos($mx_packet['text'], 'addition') && false !== strpos($mx_packet['text'], 'Oct 28, 2026'));
check('packet carries the observed score + cert count', false !== strpos($mx_packet['text'], 'exposure score at observation: 70/100') && false !== strpos($mx_packet['text'], 'at observation: 12'));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
