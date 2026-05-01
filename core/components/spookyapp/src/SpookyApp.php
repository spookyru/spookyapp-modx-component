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
        // Always use __DIR__ for filesystem paths — DB system settings store URL paths, not FS paths
        $corePath = dirname(__DIR__) . '/';
        $assetsUrl = $this->modx->getOption('spookyapp_assets_url', $config, $this->modx->getOption('assets_url') . 'components/spookyapp/');
        $connectorUrl = $modx->getOption('spookyapp_connector_url', $config, $modx->getOption('assets_url') . 'components/spookyapp/');


        $this->config = array_merge([
            'corePath' => $corePath,
            'modelPath' => $corePath . 'model/',
            'processorsPath' => $corePath . 'src/Processors/',
          //$assetsUrl - было в коннекторе
            'connectorUrl' => $connectorUrl . 'connector.php',
            'assetsUrl' => $assetsUrl,
            'cssUrl' => $assetsUrl . 'css/',
            'jsUrl' => $assetsUrl . 'js/',
        ], $config);

        $this->modx->lexicon->load('spookyapp:default');
    }
}
