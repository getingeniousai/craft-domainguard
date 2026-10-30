<?php

namespace ingeniousai\domainguard\console\controllers;

use Craft;
use craft\console\Controller;
use ingeniousai\domainguard\core\ApiException;
use ingeniousai\domainguard\DomainWatch;
use yii\console\ExitCode;

/**
 * Monitoring runner (Pro):
 *
 *   php craft domainguard/monitor/run
 *
 * Schedule it with cron (e.g. daily) on the server. Each run performs
 * one full scan of the site's own domain, stores it, and — when the
 * change warrants it (score drop, new lookalike, new certificate, CAA
 * lost) — emails the alert address. It is a quiet no-op when the Pro
 * edition is inactive or monitoring is switched off, and says so.
 */
class MonitorController extends Controller
{
    public $defaultAction = 'run';

    public function actionRun(): int
    {
        $plugin = DomainWatch::$plugin;
        $service = $plugin->scanService;
        $settings = $service->settings();

        if (!$plugin->isPro()) {
            $this->stdout("DomainGuard monitoring is a Pro-edition feature; the active edition is not Pro. Nothing to do.\n");
            return ExitCode::OK;
        }
        if (!$settings->monitorEnabled) {
            $this->stdout("DomainGuard monitoring is switched off in the plugin settings. Nothing to do.\n");
            return ExitCode::OK;
        }

        try {
            $view = $service->runBlocking();
        } catch (ApiException $e) {
            $this->stderr("DomainGuard monitoring scan failed: {$e->getMessage()}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $report = $view['report'] ?? null;
        if (is_array($report) && isset($report['score'])) {
            $this->stdout(sprintf(
                "DomainGuard scan complete for %s: score %d/100.\n",
                (string) ($report['domain'] ?? $service->ownDomain()),
                (int) $report['score']
            ));
        } else {
            $this->stdout("DomainGuard scan finished without a report.\n");
        }
        return ExitCode::OK;
    }
}
