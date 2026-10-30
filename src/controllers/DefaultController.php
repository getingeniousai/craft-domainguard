<?php

namespace ingeniousai\domainguard\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use ingeniousai\domainguard\core\ApiException;
use ingeniousai\domainguard\core\Badge;
use ingeniousai\domainguard\core\Card;
use ingeniousai\domainguard\core\ReportData;
use ingeniousai\domainguard\core\Takedown;
use ingeniousai\domainguard\DomainWatch;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control-panel section + the public badge endpoint.
 *
 * Everything CP-side requires the accessCp permission. The badge
 * action is the single anonymous one: it serves only the score of the
 * last completed scan (or the honest unscanned state), and only when
 * the badge is enabled in settings.
 */
class DefaultController extends Controller
{
    protected array|bool|int $allowAnonymous = ['badge'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if ('badge' !== $action->id && !Craft::$app->getUser()->checkPermission('accessCp')) {
            throw new ForbiddenHttpException('You need control-panel access to use DomainGuard.');
        }
        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = DomainWatch::$plugin;
        $service = $plugin->scanService;
        $settings = $service->settings();

        $lastScan = null;
        $report = null;
        $history = [];
        try {
            $lastScan = $service->lastScan();
            $report = $service->lastGoodReport();
            $history = $service->history();
        } catch (\Throwable $e) {
            Craft::warning('DomainGuard storage read failed: ' . $e->getMessage(), 'domainguard');
        }

        $usage = null;
        if ('' !== trim($settings->apiKey)) {
            $usage = $service->usage();
        }

        return $this->renderTemplate('domainguard/index', [
            'plugin' => $plugin,
            'isPro' => $plugin->isPro(),
            'settings' => $settings,
            'hasKey' => '' !== trim($settings->apiKey),
            'ownDomain' => $service->ownDomain(),
            'lastScan' => $lastScan,
            'report' => $report,
            'history' => $history,
            'usage' => $usage,
            'scoreColor' => $report ? Card::scoreColor((int) $report['score']) : '#6b7280',
            'scoreBand' => $report ? Card::scoreBand((int) $report['score']) : '',
            'lookalikes' => $report ? ($report['lookalikes_registered'] ?? []) : [],
            'lookalikeNames' => $report ? ReportData::lookalikeNames($report) : [],
            'certs' => $report ? ($report['ct_certs'] ?? []) : [],
            'certCount' => $report ? (int) ($report['ct_cert_count'] ?? count($report['ct_certs'] ?? [])) : 0,
            'caa' => $report ? ($report['caa'] ?? null) : null,
            'caaProtected' => $report ? ReportData::caaProtected($report) : false,
            'shareUrls' => $report ? Card::shareUrls($report) : [],
            'shareText' => $report ? Card::shareText($report) : '',
            'cardUrl' => UrlHelper::cpUrl('domainguard/card'),
            'badgeUrl' => Craft::$app->getSites()->getPrimarySite()->getBaseUrl() . 'domainguard-badge.svg',
            'embedSnippet' => Badge::embedSnippet(
                Craft::$app->getSites()->getPrimarySite()->getBaseUrl() . 'domainguard-badge.svg',
                $report
            ),
            'takedownPackets' => ($report && $plugin->isPro()) ? Takedown::packets($report) : [],
        ]);
    }

    /**
     * Start a scan of this site's own domain (AJAX).
     */
    public function actionRunScan(): Response
    {
        $this->requirePostRequest();
        $service = DomainWatch::$plugin->scanService;
        $domain = $service->ownDomain();
        if ('' === $domain) {
            return $this->asJson([
                'success' => false,
                'error' => 'Craft could not determine this site\'s domain from the primary site URL.',
            ]);
        }
        try {
            $job = $service->apiClient()->createScan($domain);
        } catch (ApiException $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
        if (empty($job['id'])) {
            return $this->asJson([
                'success' => false,
                'error' => 'DomainGuard accepted the scan but returned no job reference.',
            ]);
        }
        return $this->asJson([
            'success' => true,
            'jobId' => (string) $job['id'],
            'status' => (string) ($job['status'] ?? 'queued'),
        ]);
    }

    /**
     * Poll a running scan (AJAX). Persists the result when it lands.
     */
    public function actionScanStatus(): Response
    {
        $jobId = (string) Craft::$app->getRequest()->getParam('jobId', '');
        if ('' === $jobId) {
            return $this->asJson(['success' => false, 'error' => 'Missing job reference.']);
        }
        $service = DomainWatch::$plugin->scanService;
        try {
            $view = $service->apiClient()->getScan($jobId);
        } catch (ApiException $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
        $status = (string) ($view['status'] ?? '');
        if ('done' === $status) {
            $service->finishScan($view);
            return $this->asJson(['success' => true, 'status' => 'done']);
        }
        if ('failed' === $status) {
            $service->finishScan($view);
            return $this->asJson([
                'success' => true,
                'status' => 'failed',
                'error' => 'The scan did not complete — try again in a few minutes.',
            ]);
        }
        return $this->asJson(['success' => true, 'status' => $status ?: 'running']);
    }

    /**
     * The shareable score card (SVG), from the last completed scan.
     */
    public function actionCard(): Response
    {
        $report = DomainWatch::$plugin->scanService->lastGoodReport();
        $svg = $report ? Card::svg($report) : '';
        if ('' === $svg) {
            // Honest empty state: no completed scan, no card. A simple
            // slate that says exactly that — never a fabricated score.
            $svg = Badge::svg(null);
        }
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'image/svg+xml; charset=utf-8');
        $response->getHeaders()->set('Cache-Control', 'private, max-age=60');
        $response->data = $svg;
        return $response;
    }

    /**
     * The public live badge (SVG) at /domainguard-badge.svg.
     */
    public function actionBadge(): Response
    {
        $service = DomainWatch::$plugin->scanService;
        $settings = $service->settings();
        $report = null;
        if ($settings->badgeEnabled) {
            try {
                $report = $service->lastGoodReport();
            } catch (\Throwable $e) {
                $report = null;
            }
        }
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'image/svg+xml; charset=utf-8');
        $response->getHeaders()->set('Cache-Control', 'public, max-age=300');
        if (!$settings->badgeEnabled) {
            $response->getHeaders()->set('X-Robots-Tag', 'noindex');
        }
        $response->data = Badge::svg($report);
        return $response;
    }

    /**
     * One Takedown Packet (Pro), ready to copy and send.
     */
    public function actionTakedown(string $perm): Response
    {
        $plugin = DomainWatch::$plugin;
        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException('Takedown Packets are part of DomainGuard Pro.');
        }
        $report = $plugin->scanService->lastGoodReport();
        if (!$report || empty($report['lookalikes_registered']) || !is_array($report['lookalikes_registered'])) {
            throw new NotFoundHttpException('No completed scan with lookalikes is stored yet.');
        }
        foreach ($report['lookalikes_registered'] as $lk) {
            if (is_array($lk) && isset($lk['perm']) && 0 === strcasecmp((string) $lk['perm'], $perm)) {
                return $this->renderTemplate('domainguard/takedown', [
                    'plugin' => $plugin,
                    'packet' => Takedown::packet($report, $lk),
                ]);
            }
        }
        throw new NotFoundHttpException('That lookalike is not in the latest completed scan.');
    }
}
