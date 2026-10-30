<?php

namespace ingeniousai\domainguard\core;

/**
 * Pure helpers over a DomainGuard gateway report — the acceptance test
 * behind the honesty gate, plus the extractions the card, badge,
 * monitor, and history all share. Ported 1:1 from the WordPress
 * plugin's DGFS_Store helpers so both plugins read a report the same
 * way.
 *
 * Persistence itself lives in the Craft layer (the plugin's own DB
 * table); only COMPLETED reports are ever persisted as "good", which is
 * what makes the honesty gate real: a failed or unfinished scan never
 * lands there, so no share artifact is ever produced from one.
 */
class ReportData
{
    public const HISTORY_CAP = 52;

    /**
     * The bare domain for a site URL: lowercased host, or '' when the
     * URL carries no host (e.g. a relative base URL).
     */
    public static function domainFromUrl(?string $url): string
    {
        if (null === $url || '' === $url) {
            return '';
        }
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? strtolower($host) : '';
    }

    /**
     * A usable DomainGuard report has, at minimum, a domain, a score
     * and a findings list. Used as the acceptance test by the honesty
     * gate.
     *
     * @param mixed $report Candidate value.
     */
    public static function isReport($report): bool
    {
        return is_array($report)
            && isset($report['domain'])
            && isset($report['score'])
            && isset($report['findings'])
            && is_array($report['findings']);
    }

    /**
     * Registered lookalike domains in a report, as plain names.
     *
     * @return string[]
     */
    public static function lookalikeNames(array $report): array
    {
        $names = [];
        if (empty($report['lookalikes_registered']) || !is_array($report['lookalikes_registered'])) {
            return $names;
        }
        foreach ($report['lookalikes_registered'] as $lk) {
            if (isset($lk['perm']) && '' !== (string) $lk['perm']) {
                $names[] = (string) $lk['perm'];
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Certificate IDs in a report's CT history.
     *
     * @return string[]
     */
    public static function certIds(array $report): array
    {
        $ids = [];
        if (empty($report['ct_certs']) || !is_array($report['ct_certs'])) {
            return $ids;
        }
        foreach ($report['ct_certs'] as $cert) {
            if (isset($cert['cert_id']) && '' !== (string) $cert['cert_id']) {
                $ids[] = (string) $cert['cert_id'];
            }
        }
        return $ids;
    }

    /**
     * Whether the report's CAA posture restricts issuance (an `issue`
     * property is present).
     */
    public static function caaProtected(array $report): bool
    {
        return !empty($report['caa']['has_issue']);
    }

    /**
     * One score-history entry for a completed report (newest last,
     * capped by the caller at HISTORY_CAP).
     */
    public static function historyEntry(array $report, ?int $ts = null): array
    {
        return [
            'ts' => $ts ?? time(),
            'score' => isset($report['score']) ? (int) $report['score'] : 0,
            'band' => Card::scoreBand(isset($report['score']) ? (int) $report['score'] : 0),
            'lookalikes' => count(self::lookalikeNames($report)),
            'certs' => isset($report['ct_cert_count']) ? (int) $report['ct_cert_count'] : count(self::certIds($report)),
            'caa_ok' => self::caaProtected($report) ? 1 : 0,
            'lk_names' => self::lookalikeNames($report),
        ];
    }

    /**
     * Titles of a report's findings — used by monitoring to detect
     * newly-appeared findings between scans.
     *
     * @return string[]
     */
    public static function findingTitles(array $report): array
    {
        $titles = [];
        if (empty($report['findings']) || !is_array($report['findings'])) {
            return $titles;
        }
        foreach ($report['findings'] as $f) {
            $titles[] = isset($f['title']) ? (string) $f['title'] : '';
        }
        return $titles;
    }
}
