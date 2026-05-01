/**
 * SpookyApp — Home Panel (Mode Switcher).
 *
 * Главная панель компонента с переключателем режимов (кнопки):
 *   [О чем писать] — Topic Finder
 *   [Что использовать] — Chunk Generator
 *
 * Использует Ext.layout.CardLayout для переключения панелей без перезагрузки.
 *
 * @class SpookyApp.panel.Home
 * @extends MODx.Panel
 * @xtype spookyapp-panel-home
 */
SpookyApp.panel.Home = function (config) {
    config = config || {};

    this.activeMode = 'topicfinder';

    Ext.apply(config, {
        id: 'spookyapp-panel-home',
        baseCls: 'modx-formpanel',
        layout: 'anchor',
        border: false,
        items: [
            this.buildHeader(),
            this.buildModeBar(),
            this.buildCardPanel()
        ]
    });
    SpookyApp.panel.Home.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.panel.Home, MODx.Panel, {

    /**
     * Header с заголовком и версией.
     */
    buildHeader: function () {
        var version = SpookyApp.config.version || '1.0.0';
        return {
            xtype: 'panel',
            border: false,
            cls: 'modx-page-header',
            html: '<h2>' + (_('spookyapp') || 'SpookyApp')
                + ' <small style="font-size:12px; color:#999; font-weight:normal;">v' + version + '</small>'
                + '</h2>'
        };
    },

    /**
     * Toolbar с кнопками переключения режимов.
     */
    buildModeBar: function () {
        return {
            xtype: 'toolbar',
            id: 'spookyapp-mode-bar',
            cls: 'spookyapp-mode-bar',
            style: 'margin-bottom: 10px; padding: 4px 0;',
            items: [
                {
                    text: '<i class="fas fa-coffee"></i> ' + _('spookyapp.mode.topicfinder'),
                    id: 'spookyapp-mode-btn-topicfinder',
                    cls: 'primary-button',
                    enableToggle: true,
                    toggleGroup: 'spookyapp-mode',
                    pressed: true,
                    allowDepress: false,
                    handler: function () { this.switchMode('topicfinder'); },
                    scope: this
                },
                {
                    text: '<i class="fas fa-comment-alt"></i> ' + _('spookyapp.mode.chunkgenerator'),
                    id: 'spookyapp-mode-btn-chunkgenerator',
                    enableToggle: true,
                    toggleGroup: 'spookyapp-mode',
                    pressed: false,
                    allowDepress: false,
                    handler: function () { this.switchMode('chunkgenerator'); },
                    scope: this
                },
                '-',
                {
                    text: '<i class="fas fa-robot"></i> ' + _('spookyapp.mode.aichat'),
                    id: 'spookyapp-mode-btn-aichat',
                    enableToggle: true,
                    toggleGroup: 'spookyapp-mode',
                    pressed: false,
                    allowDepress: false,
                    handler: function () { this.switchMode('aichat'); },
                    scope: this
                }
            ]
        };
    },

    /**
     * Card-layout контейнер для панелей режимов.
     */
    buildCardPanel: function () {
        var viewportHeight = Ext.getBody().getViewSize().height || 700;
        var panelHeight = Math.max(450, viewportHeight - 180);

        return {
            xtype: 'panel',
            id: 'spookyapp-card-container',
            layout: 'card',
            activeItem: 0,
            border: false,
            anchor: '100%',
            height: panelHeight,
            defaults: { border: false },
            items: [
                {
                    id: 'spookyapp-card-topicfinder',
                    xtype: 'spookyapp-panel-topicfinder',
                    border: false
                },
                {
                    id: 'spookyapp-card-chunkgenerator',
                    xtype: 'spookyapp-panel-chunkgenerator',
                    border: false
                },
                {
                    id: 'spookyapp-card-aichat',
                    xtype: 'panel',
                    border: false,
                    layout: 'fit',
                    bodyStyle: 'padding: 40px; text-align: center; background: #fafafa;',
                    html: '<h3 style="color:#555;margin-bottom:12px;">AI Чат</h3><p style="color:#999;">Coming soon</p>'
                }
            ]
        };
    },

    /**
     * Переключить активный режим.
     *
     * @param {string} mode 'topicfinder' | 'chunkgenerator' | 'aichat'
     */
    switchMode: function (mode) {
        if (this.activeMode === mode) { return; }
        this.activeMode = mode;

        var container = Ext.getCmp('spookyapp-card-container');
        if (!container) { return; }

        var cardMap = {
            'topicfinder': 0,
            'chunkgenerator': 1,
            'aichat': 2
        };
        var idx = cardMap[mode];
        if (idx === undefined) { return; }

        container.getLayout().setActiveItem(idx);
    }
});

Ext.reg('spookyapp-panel-home', SpookyApp.panel.Home);
