<?php

namespace ingeniousai\domainguard;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterTemplateRootEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\web\View;
use ingeniousai\domainguard\core\ReportData;
use ingeniousai\domainguard\models\Settings;
use ingeniousai\domainguard\services\ScanService;
use yii\base\Event;

/**
 * DomainGuard Domain Watch — your domain's exposure score, live in the
 * control panel.
 *
 * THE KILLER FEATURE: the Live Lookalike Sweep. One click runs the full
 * DomainGuard engine against this site's own domain — every registered
 * lookalike domain impersonating it, its certificate-transparency
 * history, and its CAA posture — and lands one real 0–100 exposure
 * score in the control panel, with a shareable score card and a live
 * badge minted from that same completed scan (never from a failed or
 * unfinished one).
 *
 * Editions: Lite (free — the complete scan job, score card, badge,
 * history) and Pro (monitoring + Takedown Packets). Nothing is removed
 * from Lite that Lite ever had; Pro adds depth, exactly as the Plugin
 * Store's edition rules intend.
 *
 * @property ScanService $scanService
 */
class DomainWatch extends Plugin
{
    /** @var DomainWatch */
    public static $plugin;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function editions(): array
    {
        return ['lite', 'pro'];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'scanService' => ScanService::class,
        ]);

        // Control-panel section routes.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['domainguard'] = 'domainguard/default/index';
                $event->rules['domainguard/card'] = 'domainguard/default/card';
                $event->rules['domainguard/takedown/<perm:[^\/]+>'] = 'domainguard/default/takedown';
            }
        );

        // Public badge route (front-end): /domainguard-badge.svg
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['domainguard-badge.svg'] = 'domainguard/default/badge';
            }
        );

        // Control-panel templates under the 'domainguard' root.
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootEvent $event) {
                $event->roots['domainguard'] = __DIR__ . '/templates';
            }
        );
    }

    /** Whether the Pro edition is active. */
    public function isPro(): bool
    {
        return $this->is('pro', '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if (null === $item) {
            return null;
        }
        $item['label'] = 'DomainGuard';
        $item['url'] = 'domainguard';
        $item['icon'] = '@ingeniousai/domainguard/icon-mask.svg';
        // A quiet alarm bell: the nav badge counts registered lookalikes
        // in the last completed scan. Zero lookalikes → no badge.
        try {
            $report = $this->scanService->lastGoodReport();
            if ($report) {
                $count = count(ReportData::lookalikeNames($report));
                if ($count > 0) {
                    $item['badgeCount'] = $count;
                }
            }
        } catch (\Throwable $e) {
            // Table not there yet (mid-install) — no badge, no noise.
        }
        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'domainguard/settings',
            [
                'plugin' => $this,
                'settings' => $this->getSettings(),
            ],
            View::TEMPLATE_MODE_CP
        );
    }
}
