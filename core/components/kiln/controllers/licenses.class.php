<?php
/**
 * Manager page Extras → Kiln Licences (?a=licenses&namespace=kiln).
 *
 * Search every licence, mint any plan for any email (account or not), give a
 * 7-day trial, revoke/unrevoke/extend/renew/transfer/resend, manage teams, look
 * up a customer, and read the licence server's audit log. The UI is
 * assets/themes/kiln/mgr/licenses.js; it calls the KilnWeb\Processors\* classes
 * through MODX's own connector, and they call license.kilndev.com.
 */

use KilnWeb\AccountController;
use KilnWeb\Kiln;
use MODX\Revolution\modExtraManagerController;

class KilnLicensesManagerController extends modExtraManagerController
{
    public function checkPermissions()
    {
        $u = $this->modx->user;
        return $u && ($u->get('sudo') || $u->isMember('Administrator'));
    }

    public function getLanguageTopics()
    {
        return ['kiln:default'];
    }

    public function getPageTitle()
    {
        return 'Kiln Licences';
    }

    public function loadCustomCssJs()
    {
        $kiln = Kiln::get($this->modx);
        $base = rtrim((string) $this->modx->getOption('kiln.theme_url', null, '/assets/themes/kiln/'), '/') . '/mgr/';
        $v = '?v=' . rawurlencode((string) $this->modx->getOption('kiln.asset_version', null, '1'));
        $this->addCss($base . 'licenses.css' . $v);
        $this->addLastJavascript($base . 'licenses.js' . $v);

        $config = [
            'plans' => AccountController::PLAN_LABELS,
            'today' => gmdate('Y-m-d'),
            'yearOn' => gmdate('Y-m-d', strtotime('+1 year')),
            'configured' => $kiln->licenses()->configured(),
            'apiUrl' => (string) $this->modx->getOption('kiln.license_api_url', null, ''),
            'trialsPublic' => (bool) $this->modx->getOption('kiln.trials_public', null, false),
            'sales' => KilnWeb\Processors\Sales\Set::state($kiln->shop()),
        ];
        $this->addHtml('<script>Ext.onReady(function () { KilnLic.config = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '; MODx.load({ xtype: "kilnlic-page" }); });</script>');
    }

    public function getTemplateFile()
    {
        return dirname(__DIR__) . '/templates/licenses.tpl';
    }
}
