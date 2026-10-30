<?php

namespace ingeniousai\domainguard\core;

/**
 * VALUE + VIRALITY — the shareable domain exposure score card.
 *
 * After a successful scan the site owner gets a branded 1200×630 score
 * card (SVG: crisp at any size, kilobytes in weight) rendered from the
 * exact stored report — same score, same lookalike count, same cert
 * count, same CAA state as the control-panel result screen. The honesty
 * gate is persistence: only a COMPLETED scan is ever stored as a good
 * report, so a failed, unfinished, or never-run scan produces NO card,
 * ever.
 *
 * Every card and every share link carries UTM tags back to DomainGuard's
 * free report landing, so a viewer of the card can check their own
 * domain in one click — reciprocity, no dark patterns.
 *
 * Ported 1:1 from the WordPress plugin's DGFS_Card.
 */
class Card
{
    /** Landing URL every shared artifact points at (free report, one click). */
    public const LANDING = 'https://domainguard.getingeniousai.com';

    /**
     * Landing URL with UTM attribution for a given share surface.
     *
     * @param string $medium card|badge|share-x|share-linkedin|...
     */
    public static function landingUrl(string $medium): string
    {
        return self::LANDING . '/?utm_source=craft-plugin&utm_medium=' . rawurlencode($medium) . '&utm_campaign=dgfs-virality';
    }

    /**
     * Grade colour band used by the card, the badge, and the CP hero.
     *
     * @param int $score 0–100.
     * @return string Hex colour.
     */
    public static function scoreColor(int $score): string
    {
        $score = (int) $score;
        if ($score >= 90) {
            return '#22c55e';
        }
        if ($score >= 75) {
            return '#84cc16';
        }
        if ($score >= 60) {
            return '#eab308';
        }
        if ($score >= 40) {
            return '#f97316';
        }
        return '#ef4444';
    }

    /**
     * Plain-language band for a score. Presentation of the engine's real
     * score only — the engine ships no grade of its own. Higher is safer:
     * the exposure score starts at 100 and drops for a missing CAA record
     * and for each registered lookalike.
     */
    public static function scoreBand(int $score): string
    {
        $score = (int) $score;
        if ($score >= 90) {
            return 'Well protected';
        }
        if ($score >= 75) {
            return 'Mostly protected';
        }
        if ($score >= 60) {
            return 'Exposed';
        }
        if ($score >= 40) {
            return 'High exposure';
        }
        return 'Critical exposure';
    }

    /** XML-escape a string for SVG text. */
    private static function x($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Truncate to a character budget with an ellipsis. */
    private static function cut($s, int $max): string
    {
        $s = (string) $s;
        if (function_exists('mb_strlen') && mb_strlen($s) > $max) {
            return mb_substr($s, 0, $max - 1) . '…';
        }
        if (strlen($s) > $max) {
            return substr($s, 0, $max - 1) . '…';
        }
        return $s;
    }

    /**
     * The headline finding of a report: the first finding (the engine
     * orders its findings CAA → lookalikes → certificates). Null when
     * the report carries no findings.
     */
    public static function topFinding(array $report): ?array
    {
        if (empty($report['findings']) || !is_array($report['findings'])) {
            return null;
        }
        foreach ($report['findings'] as $f) {
            if (is_array($f) && !empty($f['title'])) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Render the 1200×630 score card as a standalone SVG document.
     *
     * @param mixed $report A COMPLETED gateway report (gate it through
     *                      persistence first — see the class docblock).
     * @return string SVG markup, or '' when the report is unusable.
     */
    public static function svg($report): string
    {
        if (!ReportData::isReport($report)) {
            return '';
        }
        $score = max(0, min(100, (int) $report['score']));
        $band = self::scoreBand($score);
        $domain = (string) $report['domain'];
        $color = self::scoreColor($score);
        $lk = ReportData::lookalikeNames($report);
        $lk_n = count($lk);
        $certs_n = isset($report['ct_cert_count']) ? (int) $report['ct_cert_count'] : count(ReportData::certIds($report));
        $caa_ok = ReportData::caaProtected($report);
        $top = self::topFinding($report);
        $date = '';
        if (!empty($report['generated_at'])) {
            $ts = strtotime((string) $report['generated_at']);
            if ($ts) {
                $date = gmdate('M j, Y', $ts);
            }
        }

        $top_line = '';
        if ($top) {
            $sev = isset($top['severity']) ? strtoupper((string) $top['severity']) : '';
            $top_line = ($sev ? $sev . ': ' : '') . self::cut((string) $top['title'], 74);
        } elseif (0 === $lk_n && $caa_ok) {
            $top_line = 'No lookalikes registered and CAA is in place';
        }

        $stats = [
            ['label' => $lk_n . ' LOOKALIKE' . (1 === $lk_n ? '' : 'S') . ' REGISTERED', 'c' => $lk_n > 0 ? '#f97316' : '#22c55e'],
            ['label' => $certs_n . ' CERT' . (1 === $certs_n ? '' : 'S') . ' IN CT LOGS', 'c' => '#38bdf8'],
            ['label' => $caa_ok ? 'CAA PROTECTED' : 'CAA MISSING', 'c' => $caa_ok ? '#22c55e' : '#ef4444'],
        ];
        $chips = '';
        $chip_x = 64;
        foreach ($stats as $st) {
            $w = 26 + 13 * strlen($st['label']);
            $chips .= '<g transform="translate(' . $chip_x . ',452)"><rect width="' . $w . '" height="44" rx="22" fill="#0d1f1c" stroke="' . $st['c'] . '" stroke-width="2"/><circle cx="24" cy="22" r="7" fill="' . $st['c'] . '"/><text x="42" y="29" font-family="Arial, Helvetica, sans-serif" font-size="17" font-weight="bold" fill="#f9fafb">' . self::x($st['label']) . '</text></g>';
            $chip_x += $w + 18;
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" role="img" aria-label="DomainGuard domain exposure score card">';
        $svg .= '<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#06231f"/><stop offset="1" stop-color="#0b352e"/></linearGradient></defs>';
        $svg .= '<rect width="1200" height="630" fill="url(#bg)"/>';
        $svg .= '<rect x="0" y="0" width="1200" height="10" fill="' . $color . '"/>';
        // Brand row: globe mark + wordmark.
        $svg .= '<g transform="translate(64,44)"><circle cx="30" cy="30" r="28" fill="none" stroke="' . $color . '" stroke-width="4"/><ellipse cx="30" cy="30" rx="13" ry="28" fill="none" stroke="' . $color . '" stroke-width="3"/><path d="M4 22 H56 M4 38 H56" stroke="' . $color . '" stroke-width="3"/></g>';
        $svg .= '<text x="140" y="96" font-family="Arial, Helvetica, sans-serif" font-size="34" font-weight="bold" fill="#f9fafb">DomainGuard</text>';
        $svg .= '<text x="1136" y="96" text-anchor="end" font-family="Arial, Helvetica, sans-serif" font-size="22" fill="#9ca3af">DOMAIN EXPOSURE SCORE</text>';
        // Score block.
        $svg .= '<text x="64" y="330" font-family="Arial, Helvetica, sans-serif" font-size="190" font-weight="bold" fill="' . $color . '">' . $score . '</text>';
        $svg .= '<text x="' . (64 + ($score >= 100 ? 330 : ($score >= 10 ? 225 : 120))) . '" y="330" font-family="Arial, Helvetica, sans-serif" font-size="54" fill="#9ca3af">/100</text>';
        $svg .= '<text x="66" y="392" font-family="Arial, Helvetica, sans-serif" font-size="34" font-weight="bold" fill="#f9fafb">' . self::x(self::cut($band, 34)) . '</text>';
        // Domain + meta.
        $svg .= '<text x="640" y="238" font-family="Arial, Helvetica, sans-serif" font-size="40" font-weight="bold" fill="#f9fafb">' . self::x(self::cut($domain, 34)) . '</text>';
        $svg .= '<text x="640" y="288" font-family="Arial, Helvetica, sans-serif" font-size="24" fill="#9ca3af">' . self::x('Lookalikes · certificate history · CAA' . ($date ? ' · scanned ' . $date : '')) . '</text>';
        if ($top_line) {
            $svg .= '<text x="640" y="352" font-family="Arial, Helvetica, sans-serif" font-size="23" fill="#e5e7eb">' . self::x($top_line) . '</text>';
        }
        $svg .= $chips;
        // Footer: the viral loop, stated plainly.
        $svg .= '<rect x="0" y="548" width="1200" height="82" fill="#04150f"/>';
        $svg .= '<text x="64" y="600" font-family="Arial, Helvetica, sans-serif" font-size="25" fill="#f9fafb">Is anyone impersonating <tspan font-weight="bold">your</tspan> domain?</text>';
        $svg .= '<text x="1136" y="600" text-anchor="end" font-family="Arial, Helvetica, sans-serif" font-size="25" font-weight="bold" fill="' . $color . '">domainguard.getingeniousai.com</text>';
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * One-click share copy for a completed report.
     */
    public static function shareText(array $report): string
    {
        $score = isset($report['score']) ? (int) $report['score'] : 0;
        $domain = isset($report['domain']) ? (string) $report['domain'] : '';
        $lk_n = count(ReportData::lookalikeNames($report));
        if (0 === $lk_n) {
            return sprintf('My domain %s scored %d/100 on a DomainGuard exposure scan — no lookalike domains registered. Is yours clean? Check free:', $domain, $score);
        }
        return sprintf('My domain %s scored %d/100 on a DomainGuard exposure scan — %d lookalike domain%s already registered. Is someone impersonating yours? Check free:', $domain, $score, $lk_n, 1 === $lk_n ? '' : 's');
    }

    /**
     * Share-intent URLs for the main networks, each carrying the UTM'd
     * free-scan landing link.
     *
     * @return array<string,string> network => URL.
     */
    public static function shareUrls(array $report): array
    {
        $text = self::shareText($report);
        return [
            'x' => 'https://twitter.com/intent/tweet?text=' . rawurlencode($text) . '&url=' . rawurlencode(self::landingUrl('share-x')),
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode(self::landingUrl('share-linkedin')),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode(self::landingUrl('share-facebook')) . '&quote=' . rawurlencode($text),
            'whatsapp' => 'https://wa.me/?text=' . rawurlencode($text . ' ' . self::landingUrl('share-whatsapp')),
        ];
    }
}
