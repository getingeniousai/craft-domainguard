<?php

namespace ingeniousai\domainguard\services;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use ingeniousai\domainguard\core\ApiClient;
use ingeniousai\domainguard\core\ApiException;
use ingeniousai\domainguard\core\Monitor;
use ingeniousai\domainguard\core\ReportData;
use ingeniousai\domainguard\core\TransportException;
use ingeniousai\domainguard\DomainWatch;
use ingeniousai\domainguard\models\Settings;
use yii\base\Component;

/**
 * The Craft-side service: wires the framework-free ApiClient to the
 * plugin settings (API key, $ENV-aware) and a cURL transport, persists
 * finished scans in the plugin's own table, and sends monitoring
 * alerts through Craft's mailer.
 *
 * Persistence rule (the honesty gate): a row with a report is written
 * ONLY when a scan completed and the report passes
 * ReportData::isReport(). Failed scans are stored as report-less rows
 * so the control panel can show "last scan failed" — they can never
 * feed the card, the badge, or monitoring comparisons.
 */
class ScanService extends Component
{
    public const TABLE = '{{%domainguard_scans}}';

    public function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = DomainWatch::$plugin->getSettings();
        return $settings;
    }

    public function apiClient(): ApiClient
    {
        $settings = $this->settings();
        return new ApiClient(
            static function () use ($settings): string {
                // Support $ENV_VAR references in the key setting.
                $key = App::parseEnv($settings->apiKey);
                return is_string($key) ? trim($key) : '';
            },
            [$this, 'curlTransport']
        );
    }

    /**
     * cURL transport for ApiClient.
     *
     * @return array{code:int,body:string}
     * @throws TransportException
     */
    public function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        if (false === $ch) {
            throw new TransportException('could not initialise an HTTP request.');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        if (false === $raw) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TransportException($error ?: 'network error.');
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => (string) $raw];
    }

    /**
     * This site's own domain, from the primary site's base URL.
     */
    public function ownDomain(): string
    {
        $sites = Craft::$app->getSites();
        $domain = ReportData::domainFromUrl($sites->getPrimarySite()->getBaseUrl());
        if ('' !== $domain) {
            return $domain;
        }
        foreach ($sites->getAllSites() as $site) {
            $domain = ReportData::domainFromUrl($site->getBaseUrl());
            if ('' !== $domain) {
                return $domain;
            }
        }
        return '';
    }

    /**
     * Gateway health, or null when unreachable.
     */
    public function health(): ?array
    {
        try {
            return $this->apiClient()->health();
        } catch (ApiException $e) {
            return null;
        }
    }

    /**
     * Monthly usage for the configured key, or null when unavailable
     * (no key, gateway error) — the CP shows "unavailable", never a
     * guessed number.
     */
    public function usage(): ?array
    {
        try {
            return $this->apiClient()->usage();
        } catch (ApiException $e) {
            return null;
        }
    }

    /**
     * Persist a finished job view. Returns the completed report, or
     * null when the job failed / carried no usable report.
     */
    public function finishScan(array $jobView): ?array
    {
        $status = isset($jobView['status']) ? (string) $jobView['status'] : '';
        $report = isset($jobView['report']) && is_array($jobView['report']) ? $jobView['report'] : null;

        if ('done' === $status && ReportData::isReport($report)) {
            $previous = $this->lastGoodReport();
            $this->insertRow(
                (string) $report['domain'],
                (int) $report['score'],
                $report,
                null
            );
            $this->maybeAlert($previous, $report);
            return $report;
        }

        $error = 'scan_failed';
        if (isset($jobView['error'])) {
            $error = is_string($jobView['error']) ? $jobView['error'] : (string) json_encode($jobView['error']);
        }
        $this->insertRow($this->ownDomain(), null, null, $error);
        return null;
    }

    /**
     * Run a scan to completion and persist it (console monitor path).
     *
     * @throws ApiException
     */
    public function runBlocking(): array
    {
        $domain = $this->ownDomain();
        if ('' === $domain) {
            throw new ApiException('invalid_domain', 'Craft could not determine this site\'s domain from the primary site URL.');
        }
        $view = $this->apiClient()->runScanBlocking($domain);
        $this->finishScan($view);
        return $view;
    }

    private function insertRow(string $domain, ?int $score, ?array $report, ?string $error): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'domain' => $domain,
            'score' => $score,
            'report' => null !== $report ? json_encode($report) : null,
            'error' => $error,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    /**
     * The latest scan row of any kind, as a snapshot:
     * {domain, score, error, dateCreated, report}.
     */
    public function lastScan(): ?array
    {
        $row = (new Query())
            ->from(self::TABLE)
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->one();
        if (!$row) {
            return null;
        }
        $report = null;
        if (!empty($row['report'])) {
            $decoded = json_decode((string) $row['report'], true);
            if (ReportData::isReport($decoded)) {
                $report = $decoded;
            }
        }
        return [
            'domain' => (string) $row['domain'],
            'score' => null !== $row['score'] ? (int) $row['score'] : null,
            'error' => isset($row['error']) ? (string) $row['error'] : null,
            'dateCreated' => (string) $row['dateCreated'],
            'report' => $report,
        ];
    }

    /**
     * The report of the latest COMPLETED scan — the honesty gate for
     * the score card and the badge.
     */
    public function lastGoodReport(): ?array
    {
        $rows = (new Query())
            ->select(['report'])
            ->from(self::TABLE)
            ->where(['not', ['report' => null]])
            ->orderBy(['id' => SORT_DESC])
            ->limit(25)
            ->all();
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['report'], true);
            if (ReportData::isReport($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Score history of completed scans, oldest first, capped.
     */
    public function history(): array
    {
        $rows = (new Query())
            ->select(['report', 'dateCreated'])
            ->from(self::TABLE)
            ->where(['not', ['report' => null]])
            ->orderBy(['id' => SORT_DESC])
            ->limit(ReportData::HISTORY_CAP)
            ->all();
        $entries = [];
        foreach (array_reverse($rows) as $row) {
            $decoded = json_decode((string) $row['report'], true);
            if (ReportData::isReport($decoded)) {
                $ts = strtotime((string) $row['dateCreated']) ?: time();
                $entries[] = ReportData::historyEntry($decoded, $ts);
            }
        }
        return $entries;
    }

    /**
     * Pro monitoring: compare the new report with the previous good
     * one and email on a warranted change. Quiet no-ops: not Pro,
     * monitoring off, no previous report, evaluation says no alert.
     */
    private function maybeAlert(?array $previous, array $current): void
    {
        $plugin = DomainWatch::$plugin;
        $settings = $this->settings();
        if (!$plugin->isPro() || !$settings->monitorEnabled || !$previous) {
            return;
        }
        $eval = Monitor::evaluate($previous, $current, $settings->monitorSettingsArray());
        if (!$eval['alert']) {
            return;
        }
        $email = trim($settings->alertEmail);
        if ('' === $email) {
            $admin = User::find()->admin()->status(null)->one();
            $email = $admin ? (string) $admin->email : '';
        }
        if ('' === $email) {
            return;
        }
        $domain = isset($current['domain']) ? (string) $current['domain'] : $this->ownDomain();
        $text = Monitor::alertText(
            $domain,
            $previous,
            $current,
            $eval,
            $settings->monitorSettingsArray(),
            UrlHelper::cpUrl('domainguard')
        );
        try {
            Craft::$app->getMailer()
                ->compose()
                ->setTo($email)
                ->setSubject($text['subject'])
                ->setTextBody($text['body'])
                ->send();
        } catch (\Throwable $e) {
            Craft::warning('DomainGuard alert email failed: ' . $e->getMessage(), 'domainguard');
        }
    }
}
