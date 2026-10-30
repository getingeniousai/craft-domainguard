<?php

namespace ingeniousai\domainguard\core;

/**
 * Monitoring decisions — compare two completed reports and decide
 * whether the change is worth an email. The decision logic and the
 * alert copy are ported 1:1 from the WordPress plugin's DGFS_Monitor;
 * sending lives in the Craft layer (Craft's mailer), and the Pro gate
 * lives in the caller, exactly as dgfs_is_pro() gated the WP check().
 */
class Monitor
{
    /**
     * Compare two completed reports against the alert settings.
     *
     * @param array $previous Previous completed report.
     * @param array $current  New completed report.
     * @param array $settings Keys: alert_drop, alert_new_lookalike,
     *                        alert_new_cert, alert_caa_lost.
     * @return array{drop:int,new_lookalikes:string[],new_certs:int,caa_lost:bool,alert:bool}
     */
    public static function evaluate(array $previous, array $current, array $settings): array
    {
        $old_score = isset($previous['score']) ? (int) $previous['score'] : 0;
        $new_score = isset($current['score']) ? (int) $current['score'] : 0;
        $threshold = isset($settings['alert_drop']) ? max(1, (int) $settings['alert_drop']) : 10;
        $drop = $old_score - $new_score;

        $new_lookalikes = [];
        if (!empty($settings['alert_new_lookalike'])) {
            $new_lookalikes = array_values(array_diff(
                ReportData::lookalikeNames($current),
                ReportData::lookalikeNames($previous)
            ));
        }

        $new_certs = 0;
        if (!empty($settings['alert_new_cert'])) {
            $new_certs = count(array_diff(
                ReportData::certIds($current),
                ReportData::certIds($previous)
            ));
            if (0 === $new_certs) {
                // CT history is capped in the report; fall back to the
                // count when the window rolled past the stored IDs.
                $old_n = isset($previous['ct_cert_count']) ? (int) $previous['ct_cert_count'] : 0;
                $new_n = isset($current['ct_cert_count']) ? (int) $current['ct_cert_count'] : 0;
                if ($new_n > $old_n) {
                    $new_certs = $new_n - $old_n;
                }
            }
        }

        $caa_lost = false;
        if (!empty($settings['alert_caa_lost'])) {
            $caa_lost = ReportData::caaProtected($previous) && !ReportData::caaProtected($current);
        }

        return [
            'drop' => $drop,
            'new_lookalikes' => $new_lookalikes,
            'new_certs' => $new_certs,
            'caa_lost' => $caa_lost,
            'alert' => ($drop >= $threshold) || !empty($new_lookalikes) || $new_certs > 0 || $caa_lost,
        ];
    }

    /**
     * Compose the alert email for an evaluation that fired.
     *
     * @param array $eval Result of evaluate().
     * @return array{subject:string,body:string}
     */
    public static function alertText(string $domain, array $previous, array $current, array $eval, array $settings, string $reportUrl): array
    {
        $old_score = isset($previous['score']) ? (int) $previous['score'] : 0;
        $new_score = isset($current['score']) ? (int) $current['score'] : 0;
        $threshold = isset($settings['alert_drop']) ? max(1, (int) $settings['alert_drop']) : 10;

        $lines = [];
        $lines[] = sprintf('DomainGuard monitoring alert for %s', $domain);
        $lines[] = '';
        if ($eval['drop'] >= $threshold) {
            $lines[] = sprintf('Exposure score dropped %d points: %d → %d (%s).', $eval['drop'], $old_score, $new_score, Card::scoreBand($new_score));
        } else {
            $lines[] = sprintf('Exposure score: %d → %d (%s).', $old_score, $new_score, Card::scoreBand($new_score));
        }
        if ($eval['new_lookalikes']) {
            $lines[] = '';
            $lines[] = 'Newly registered lookalike domains:';
            foreach ($eval['new_lookalikes'] as $name) {
                $lines[] = ' - ' . $name;
            }
            $lines[] = 'Pro includes a ready-to-send Takedown Packet for each: DomainGuard in the control panel.';
        }
        if ($eval['new_certs'] > 0) {
            $lines[] = '';
            $lines[] = sprintf('%d new certificate(s) appeared in the certificate-transparency logs — review the issuers in the full report.', $eval['new_certs']);
        }
        if ($eval['caa_lost']) {
            $lines[] = '';
            $lines[] = 'Your CAA protection disappeared: any certificate authority may now issue certificates for your domain. Restore the CAA record.';
        }
        $lines[] = '';
        $lines[] = 'Full report: ' . $reportUrl;
        $lines[] = 'Scanned by DomainGuard — https://domainguard.getingeniousai.com';

        $subject = sprintf('[DomainGuard] %s: score %d → %d%s', $domain, $old_score, $new_score, $eval['new_lookalikes'] ? ' — new lookalike registered' : '');

        return ['subject' => $subject, 'body' => implode("\n", $lines)];
    }
}
