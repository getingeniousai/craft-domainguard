<?php

namespace ingeniousai\domainguard\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control-panel assets for the DomainGuard section (scan polling +
 * hero styling).
 */
class DomainGuardAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@ingeniousai/domainguard/resources';
        $this->depends = [CpAsset::class];
        $this->css = ['css/cp.css'];
        $this->js = ['js/cp.js'];
        parent::init();
    }
}
