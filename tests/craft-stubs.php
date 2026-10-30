<?php
/**
 * Test bootstrap for DomainGuard Domain Watch (Craft CMS).
 *
 * Unlike the WordPress suite (which must stub WP functions), the Craft
 * plugin's logic lives in a framework-free core (src/core/) precisely so
 * it can be driven without a Craft runtime: this file registers a PSR-4
 * autoloader for the plugin and provides the mocked HTTP transport the
 * API-client tests drive.
 *
 * The Craft-facing classes (plugin class, controllers, service,
 * migration, settings model) are syntax-checked with `php -l` in the
 * build loop; they cannot execute here because this machine has no
 * Craft install (Craft needs MySQL/PostgreSQL + the intl extension).
 * Everything user-visible that can be decided without Craft — gateway
 * behaviour, report reading, card/badge rendering, monitoring
 * decisions, takedown packets, packaging rules — is decided in the
 * core and is covered by run-tests.php.
 */

error_reporting(E_ALL);

spl_autoload_register(static function (string $class): void {
    $prefix = 'ingeniousai\\domainguard\\';
    if (0 === strpos($class, $prefix)) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

/**
 * A scripted transport for ApiClient: each call shifts the next
 * response off the queue and records what it was asked to send.
 * A queue entry may be an \Exception to throw.
 */
class MockTransport
{
    /** @var array<int, array{code:int,body:string}|\Exception> */
    public array $queue = [];

    /** @var array<int, array{method:string,url:string,headers:array,body:?string}> */
    public array $calls = [];

    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (!$this->queue) {
            throw new \RuntimeException('MockTransport queue exhausted');
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Exception) {
            throw $next;
        }
        return $next;
    }
}

/** A completed report in the exact DomainGuard gateway shape. */
function report_fixture(array $overrides = []): array
{
    return array_merge([
        'domain' => 'example-site.test',
        'generated_at' => '2026-10-28T12:00:00Z',
        'score' => 70,
        'ct_cert_count' => 12,
        'ct_certs' => [
            ['cert_id' => 'c1', 'issuer' => "Let's Encrypt", 'serial' => 'aa', 'not_before' => '2026-09-01', 'not_after' => '2026-11-30', 'names' => ['example-site.test'], 'entry_ts' => '2026-09-01'],
            ['cert_id' => 'c2', 'issuer' => "Let's Encrypt", 'serial' => 'bb', 'not_before' => '2026-06-01', 'not_after' => '2026-08-30', 'names' => ['www.example-site.test'], 'entry_ts' => '2026-06-01'],
            ['cert_id' => 'c3', 'issuer' => 'Sectigo', 'serial' => 'cc', 'not_before' => '2025-12-01', 'not_after' => '2026-12-01', 'names' => ['example-site.test'], 'entry_ts' => '2025-12-01'],
        ],
        'caa' => [
            'records' => ['0 iodef "mailto:security@example-site.test"'],
            'has_issue' => false,
            'has_issuewild' => false,
            'has_iodef' => true,
        ],
        'lookalikes_registered' => [
            ['perm' => 'example-sites.test', 'fuzzer' => 'addition', 'mx' => true],
            ['perm' => 'examp1e-site.test', 'fuzzer' => 'homoglyph', 'mx' => false],
        ],
        'lookalikes_checked' => 812,
        'lookalikes_total' => 1050,
        'sweep_cut_short' => false,
        'findings' => [
            ['severity' => 'high', 'title' => '2 lookalike domains are registered', 'detail' => 'example-sites.test, examp1e-site.test'],
            ['severity' => 'medium', 'title' => 'CAA record does not restrict issuance', 'detail' => 'No issue property found.'],
        ],
    ], $overrides);
}
