/**
 * SpookyApp — Home Section (entry point).
 *
 * MODx.Component для рендера главной страницы SpookyApp.
 * Рендерит SpookyApp.panel.Home в div#spookyapp-panel-home-div.
 *
 * @class SpookyApp.page.Home
 * @extends MODx.Component
 * @xtype spookyapp-page-home
 */
SpookyApp.page.Home = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        components: [{
            xtype: 'spookyapp-panel-home',
            renderTo: 'spookyapp-panel-home-div'
        }]
    });
    SpookyApp.page.Home.superclass.constructor.call(this, config);
};
Ext.extend(SpookyApp.page.Home, MODx.Component);
Ext.reg('spookyapp-page-home', SpookyApp.page.Home);