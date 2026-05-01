<?php

namespace SpookyApp;

use MODX\Revolution\modX;

class SpookyApp
{
    /** @var \modX $modx */
    public $modx;
    /** @var array $config */
    public $config = [];

    public function __construct(modX $modx, array $config = [])
    {
        $this->modx = $modx;
           //для разработки можно задавать свои пути в системных параметрах. было так
        //$corePath = MODX_CORE_PATH . 'components/spookyapp/';
        //$assetsUrl = MODX_ASSETS_URL . 'components/spookyapp/';
$corePath = $this->modx->getOption('spookyapp_core_path', $config, $this->modx->getOption('core_path') . 'components/spookyapp/');
$assetsUrl = $this->modx->getOption('spookyapp_assets_url', $config, $this->modx->getOption('assets_url') . 'components/spookyapp/');
$connectorUrl = $modx->getOption('spookyapp_connector_url', $config, $modx->getOption('assets_url') . 'components/spookyapp/');


        $this->config = array_merge([
            'corePath' => $corePath,
            'modelPath' => $corePath . 'model/',
            'processorsPath' => $corePath . 'Processors/',
          //$assetsUrl - было в коннекторе
            'connectorUrl' => $connectorUrl . 'connector.php',
            'assetsUrl' => $assetsUrl,
            'cssUrl' => $assetsUrl . 'css/',
            'jsUrl' => $assetsUrl . 'js/',
        ], $config);

        $this->modx->lexicon->load('spookyapp:default');
    }
}
