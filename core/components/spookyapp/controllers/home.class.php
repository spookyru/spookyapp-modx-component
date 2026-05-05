<?php

use MODX\Revolution\modExtraManagerController;

/**
 * The home manager controller for SpookyApp.
 *
 */
class SpookyAppHomeManagerController extends modExtraManagerController
{
    /** @var SpookyApp\SpookyApp $SpookyApp */
    public $SpookyApp;


    /**
     *
     */
    public function initialize()
    {
        $this->SpookyApp = $this->modx->services->get('SpookyApp');
        parent::initialize();
    }


    /**
     * @return array
     */
    public function getLanguageTopics()
    {
        return ['spookyapp:default'];
    }


    /**
     * @return bool
     */
    public function checkPermissions()
    {
        return true;
    }


    /**
     * @return null|string
     */
    public function getPageTitle()
    {
        return $this->modx->lexicon('spookyapp');
    }


    /**
     * @return void
     */
    public function loadCustomCssJs()
    {
        $this->addCss($this->SpookyApp->config['cssUrl'] . 'mgr/main.css');
        $this->addCss($this->SpookyApp->config['cssUrl'] . 'mgr/spookyapp.css');
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/spookyapp.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/misc/utils.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/misc/combo.js?time='.time());

        // TopicFinder
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/topicfinder.windows.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/topicfinder.grid.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/topicfinder.panel.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/sections/topicfinder.js?time='.time());
        // ChunkGenerator
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/chunkgenerator.tabs.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/chunkgenerator.searchform.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/chunkgenerator.grid.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/chunkgenerator.detailsform.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/chunkgenerator.panel.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/sections/chunkgenerator.js?time='.time());
        // Home
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/widgets/home.panel.js?time='.time());
        $this->addJavascript($this->SpookyApp->config['jsUrl'] . 'mgr/sections/home.js?time='.time());

        $this->addHtml('<script type="text/javascript">
        SpookyApp.config = ' . json_encode($this->SpookyApp->config) . ';
        SpookyApp.config.connector_url = "' . $this->SpookyApp->config['connectorUrl'] . '";
        Ext.onReady(function() {MODx.load({ xtype: "spookyapp-page-home"});});
       
        </script>');
    }


    /**
     * @return string
     */
    public function getTemplateFile()
    {
        $this->content .= '<div id="spookyapp-panel-home-div"></div>';
        return '';
    }
}
