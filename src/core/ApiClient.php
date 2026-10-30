<?php

namespace ingeniousai\domainguard\core;

/**
 * DomainGuard API client — framework-free core.
 *
 * Talks to the DomainGuard public developer API — the same gateway that
 * fronts the exposure-report engine on the DomainGuard website:
 *
 *   Base: https://domainguard.getingeniousai.com/api/v1
 *   GET  /health           open, no key
 *   POST /scans            {domain} → 202 + job {id, domain, status, poll}
 *   GET  /scans/{job_id}   poll; when status is "done", the report is included
 *   GET  /scans            this key's recent scans
 *   GET  /usage            monthly usage for the key
 *
 * Auth is the site's own DomainGuard API key (DGAPI…), sent as the
 * X-API-Key header (an Authorization: Bearer <redacted> is also accepted by
 * the gateway). The gateway meters per key by plan (BASIC 50/mo,
 * PRO 2,000/mo, ULTRA 10,000/mo): the usage counter is charged once when
 * a scan is reserved, and a scan that then fails is still counted by the
 * gateway — the plugin never promises otherwise.
 *
 * The report is the engine's exposure report for the scanned domain:
 * registered lookalike domains (a dnstwist-style sweep), the
 * certificate-transparency history (crt.sh), the CAA posture, a 0–100
 * exposure score, and findings. The engine's "won't guess" states
 * (sweep_cut_short, lookalikes_checked of lookalikes_total) pass through
 * verbatim and are shown honestly in the control panel.
 *
 * The HTTP transport and the sleep function are injected so the client
 * is fully testable without a network (see tests/) — the Craft service
 * layer wires in a cURL transport in production.
 */
class ApiClient
{
    public const BASE = 'https://domainguard.getingeniousai.com/api/v1';
    public const VERSION = '1.0.0';

    /** @var callable():string Returns the configured API key ('' when unset). */
    private $keyProvider;

    /** @var callable(string,string,array,?string):array{code:int,body:string} */
    private $transport;

    /** @var callable(int):void */
    private $sleeper;

    /**
     * @param callable $keyProvider fn(): string — the site's DGAPI key.
     * @param callable $transport   fn(string $method, string $url, array $headers, ?string $body): array{code:int, body:string}
     *                              Must throw TransportException on network failure.
     * @param callable|null $sleeper fn(int $seconds): void — defaults to sleep().
     */
    public function __construct(callable $keyProvider, callable $transport, ?callable $sleeper = null)
    {
        $this->keyProvider = $keyProvider;
        $this->transport = $transport;
        $this->sleeper = $sleeper ?: static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * One HTTP call to the gateway.
     *
     * @param string $method GET|POST.
     * @param string $path   Path under /api/v1, e.g. '/scans'.
     * @param array|null $body JSON body for POSTs (null for GET).
     * @return array Decoded JSON body.
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $key = (string) call_user_func($this->keyProvider);
        if ('' === $key && '/health' !== $path) {
            throw new ApiException('no_key', 'Add your DomainGuard API key in DomainGuard → Settings first. A free key takes under a minute to request.');
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'DomainGuard-Craft-Plugin/' . self::VERSION,
        ];
        if ('' !== $key) {
            $headers['X-API-Key'] = $key;
        }

        $encoded = null !== $body ? json_encode($body) : null;

        try {
            $response = call_user_func($this->transport, $method, self::BASE . $path, $headers, $encoded);
        } catch (TransportException $e) {
            throw new ApiException('transport', 'Could not reach DomainGuard: ' . $e->getMessage());
        }

        $code = (int) ($response['code'] ?? 0);
        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($code >= 200 && $code < 300) {
            return $data;
        }
        throw self::mapError($code, $data);
    }

    /**
     * Turn a gateway error body into a readable ApiException. The
     * gateway's error codes are stable strings; unknown codes fall back
     * to the gateway's own detail text.
     */
    public static function mapError(int $code, array $data): ApiException
    {
        $error = isset($data['error']) ? (string) $data['error'] : '';
        $detail = isset($data['detail']) ? (string) $data['detail'] : '';

        switch ($error) {
            case 'unauthorized':
                $msg = 'DomainGuard did not accept this API key. Check the key in Settings — keys start with "DGAPI" — or request a fresh one.';
                break;
            case 'invalid_domain':
                $msg = $detail ?: 'DomainGuard could not scan this site\'s domain.';
                break;
            case 'monthly_quota_exceeded':
                $msg = 'This API key has used its included scans for this month. Scans reset on the 1st, or a higher plan adds more.';
                break;
            case 'concurrency_limit':
                $msg = 'A scan is already running for this key. Wait for it to finish, then try again.';
                break;
            case 'not_found':
                $msg = 'That scan is no longer available. Run a fresh scan.';
                break;
            case 'scan_failed':
                $msg = 'The scan did not complete — try again in a few minutes.';
                break;
            default:
                $msg = $detail ?: sprintf('DomainGuard returned an unexpected answer (HTTP %d). Try again shortly.', $code);
                break;
        }
        return new ApiException($error ?: 'http_' . $code, $msg);
    }

    /** @return array {ok, service, version} */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * Start a scan of a domain.
     *
     * @return array Job view including 'id' and 'status'.
     */
    public function createScan(string $domain): array
    {
        return $this->request('POST', '/scans', ['domain' => $domain]);
    }

    /**
     * @return array Job view; report included when done.
     */
    public function getScan(string $jobId): array
    {
        return $this->request('GET', '/scans/' . rawurlencode($jobId));
    }

    /** @return array This key's recent scans. */
    public function listScans(): array
    {
        return $this->request('GET', '/scans');
    }

    /** @return array Usage snapshot for the configured key. */
    public function usage(): array
    {
        return $this->request('GET', '/usage');
    }

    /**
     * Run a scan to completion (used by the console monitor command,
     * where waiting is fine). Polls every $interval seconds up to
     * $maxWait; returns the finished job view. The engine itself is
     * time-boxed (~45s) and retries once internally, so 300s is a
     * generous ceiling.
     *
     * @throws ApiException
     */
    public function runScanBlocking(string $domain, int $maxWait = 300, int $interval = 8): array
    {
        $job = $this->createScan($domain);
        if (empty($job['id'])) {
            throw new ApiException('bad_job', 'DomainGuard accepted the scan but returned no job reference.');
        }
        $deadline = time() + max(30, $maxWait);
        while (time() < $deadline) {
            call_user_func($this->sleeper, $interval);
            $view = $this->getScan((string) $job['id']);
            if (isset($view['status']) && 'done' === $view['status']) {
                return $view;
            }
            if (isset($view['status']) && 'failed' === $view['status']) {
                throw self::mapError(200, ['error' => 'scan_failed']);
            }
        }
        throw new ApiException('timeout', 'The scan took longer than expected and was left running on DomainGuard\'s side. Check back in a few minutes.');
    }
}
