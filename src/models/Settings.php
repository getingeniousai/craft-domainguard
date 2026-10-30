<?php

namespace ingeniousai\domainguard\models;

use craft\base\Model;

/**
 * Plugin settings. The API key is the site's own DomainGuard key
 * (DGAPI…) — the same key the WordPress plugin uses; it may be given
 * as an environment-variable reference ($DOMAINGUARD_API_KEY) and is
 * resolved at call time, never copied anywhere else.
 */
class Settings extends Model
{
    /** @var string The site's DomainGuard API key (or $ENV reference). */
    public string $apiKey = '';

    /** @var bool Serve the live score badge at /domainguard-badge.svg. */
    public bool $badgeEnabled = true;

    /** @var bool Pro: enable monitoring runs (console command / cron). */
    public bool $monitorEnabled = false;

    /** @var string Alert recipient; empty = first admin user's email. */
    public string $alertEmail = '';

    /** @var int Score drop (points) that counts as an alert on its own. */
    public int $alertDrop = 10;

    /** @var bool Alert when a new lookalike domain registers. */
    public bool $alertNewLookalike = true;

    /** @var bool Alert when new certificates appear in the CT logs. */
    public bool $alertNewCert = true;

    /** @var bool Alert when CAA protection disappears. */
    public bool $alertCaaLost = true;

    public function rules(): array
    {
        return [
            [['apiKey', 'alertEmail'], 'string'],
            [['alertEmail'], 'email', 'skipOnEmpty' => true],
            [['alertDrop'], 'integer', 'min' => 1, 'max' => 100],
            [['badgeEnabled', 'monitorEnabled', 'alertNewLookalike', 'alertNewCert', 'alertCaaLost'], 'boolean'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'apiKey' => 'DomainGuard API key',
            'badgeEnabled' => 'Live score badge',
            'monitorEnabled' => 'Monitoring (Pro)',
            'alertEmail' => 'Alert email',
            'alertDrop' => 'Alert on score drop of at least',
            'alertNewLookalike' => 'Alert on new lookalike domains',
            'alertNewCert' => 'Alert on new certificates',
            'alertCaaLost' => 'Alert if CAA protection is lost',
        ];
    }

    /** Alert settings in the shape the core Monitor expects. */
    public function monitorSettingsArray(): array
    {
        return [
            'alert_drop' => $this->alertDrop,
            'alert_new_lookalike' => $this->alertNewLookalike,
            'alert_new_cert' => $this->alertNewCert,
            'alert_caa_lost' => $this->alertCaaLost,
        ];
    }
}
