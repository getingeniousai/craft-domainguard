<?php

namespace ingeniousai\domainguard\core;

/**
 * The live footer badge — a flat, shields-style SVG carrying the real
 * score from the last COMPLETED scan, or the honest "not watched yet"
 * state before one exists. Ported 1:1 from the WordPress plugin's
 * DGFS_Badge renderer (serving lives in the Craft controller).
 */
class Badge
{
    /**
     * Badge SVG (flat, shields-style).
     *
     * @param array|null $report Completed report, or null for the
     *                           honest unscanned state.
     */
    public static function svg(?array $report): string
    {
        $esc = static function ($s): string {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        if (!$report || !isset($report['score'])) {
            $right = 'not watched yet';
            $color = '#6b7280';
        } else {
            $score = max(0, min(100, (int) $report['score']));
            $right = $score . '/100';
            $color = Card::scoreColor($score);
        }
        $left = 'DomainGuard';
        $lw = 118;
        $rw = 26 + 8 * strlen($right);
        $w = $lw + $rw;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="28" viewBox="0 0 ' . $w . ' 28" role="img" aria-label="DomainGuard exposure score: ' . $esc($right) . '">'
            . '<rect width="' . $w . '" height="28" rx="6" fill="#06231f"/>'
            . '<rect x="' . $lw . '" width="' . $rw . '" height="28" rx="6" fill="' . $color . '"/>'
            . '<rect x="' . $lw . '" width="8" height="28" fill="' . $color . '"/>'
            . '<text x="14" y="19" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="bold" fill="#f9fafb">' . $esc($left) . '</text>'
            . '<text x="' . ($lw + 14) . '" y="19" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="bold" fill="#04150f">' . $esc($right) . '</text>'
            . '</svg>';
    }

    /**
     * Copy-paste embed snippet for the site's footer. The link is the
     * viral loop: it lands on DomainGuard's free report.
     *
     * @param array|null $report Last completed report (for the alt text).
     */
    public static function embedSnippet(string $badgeUrl, ?array $report): string
    {
        $alt = 'DomainGuard exposure score';
        if ($report && isset($report['score'])) {
            $alt .= ': ' . (int) $report['score'] . '/100';
        }
        return '<a href="' . Card::landingUrl('badge') . '" target="_blank" rel="noopener" title="Run a free DomainGuard exposure scan of your domain">'
            . '<img src="' . htmlspecialchars($badgeUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" height="28" loading="lazy"></a>';
    }
}
