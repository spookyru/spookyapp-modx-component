/**
 * SpookyApp — Section page для TopicFinder.
 *
 * ═══════════════════════════════════════════════════════════════
 * Главная страница (section) модуля TopicFinder.
 * Использует MODx.Component для корректного рендера в MODX3.
 *
 * ВАЖНО: рендерится в div#spookyapp-panel-wrapper-div,
 * который определён в templates/home.tpl
 * ═══════════════════════════════════════════════════════════════
 *
 * Зависимости (порядок загрузки):
 *   1. spookyapp.js
 *   2. topicfinder.grid.js
 *   3. topicfinder.panel.js
 *   4. topicfinder.js        ← этот файл
 *
 * @class SpookyApp.page.TopicFinder
 * @extends MODx.Component
 * @xtype spookyapp-page-topicfinder
 *
 * @package SpookyApp
 * @subpackage JS\Manager\Sections
 */
SpookyApp.page.TopicFinder = function (config) {
    config = config || {};

    Ext.applyIf(config, {
        components: [{
            xtype: 'spookyapp-panel-topicfinder-page',
            renderTo: 'spookyapp-panel-wrapper-div'
        }]
    });

    SpookyApp.page.TopicFinder.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.page.TopicFinder, MODx.Component);
Ext.reg('spookyapp-page-topicfinder', SpookyApp.page.TopicFinder);


// ╔═════════════════════════════════════════════════════════════╗
// ║  Page Panel: header + buttons + main content                ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Обёрточная панель страницы TopicFinder.
 *
 * Содержит:
 * - Заголовок с описанием и кнопками действий
 * - SpookyApp.panel.TopicFinder (основной контент с border layout)
 *
 * @class SpookyApp.panel.TopicFinderPage
 * @extends MODx.Panel
 * @xtype spookyapp-panel-topicfinder-page
 */
SpookyApp.panel.TopicFinderPage = function (config) {
    config = config || {};

    Ext.applyIf(config, {
        id: 'spookyapp-panel-topicfinder-page',
        cls: 'container',
        border: false,
        defaults: {
            border: false
        },
        items: [
            this.buildPageHeader(config),
            this.buildMainContent(config)
        ]
    });

    SpookyApp.panel.TopicFinderPage.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.panel.TopicFinderPage, MODx.Panel, {

    /**
     * Построить заголовок страницы с описанием и кнопками.
     *
     * @param {Object} config Конфигурация
     * @return {Object} Конфигурация header-панели
     */
    buildPageHeader: function (config) {
        var title = _('spookyapp.topicfinder.title') || 'Topic Finder';
        var version = SpookyApp.config.version || '2.0.0';

        return {
            xtype: 'panel',
            border: false,
            cls: 'modx-page-header',
            html: '<h2>'
                + Ext.util.Format.htmlEncode(title)
                + '</h2>',
            tbar: [
                {
                    text: _('spookyapp.btn.get_new_topics') || 'Get New Topics',
                    cls: 'primary-button',
                    id: 'spookyapp-btn-page-get-topics',
                    handler: this.openGetNewTopicsWindow,
                    scope: this
                },
                '-',
                {
                    text: _('spookyapp.btn.export_csv') || 'Export to CSV',
                    id: 'spookyapp-btn-export-csv',
                    handler: this.onExportCsv,
                    scope: this
                },
                {
                    text: _('spookyapp.btn.settings') || 'Settings',
                    id: 'spookyapp-btn-settings',
                    handler: this.onOpenSettings,
                    scope: this
                }
            ]
        };
    },

    /**
     * Построить основной контент (TopicFinder panel).
     *
     * @param {Object} config Конфигурация
     * @return {Object} Конфигурация main-панели
     */
    buildMainContent: function (config) {
        return {
            xtype: 'spookyapp-panel-topicfinder',
            border: false,
            style: 'margin-top: 10px;',
            height: this.calculatePanelHeight()
        };
    },

    /**
     * Рассчитать высоту панели под border layout.
     *
     * @return {number}
     */
    calculatePanelHeight: function () {
        var viewportHeight = Ext.getBody().getViewSize().height || 700;
        return Math.max(400, viewportHeight - 200);
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Get New Topics: модальное окно                          ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Открыть модальное окно "Get New Topics".
     *
     * @return {void}
     */
    openGetNewTopicsWindow: function () {
        if (Ext.ComponentMgr.isRegistered('spookyapp-window-get-topics')) {
            var regWin = MODx.load({
                xtype: 'spookyapp-window-get-topics',
                listeners: {
                    success: {
                        fn: this.onTopicsFetched,
                        scope: this
                    }
                }
            });
            regWin.show();
            return;
        }

        var self = this;

        var win = new Ext.Window({
            title: _('spookyapp.get_topics.title') || 'Get New Topics',
            id: 'spookyapp-window-get-topics-inline',
            width: 520,
            autoHeight: true,
            modal: true,
            closeAction: 'close',
            cls: 'spookyapp-window',
            items: [{
                xtype: 'form',
                id: 'spookyapp-form-get-topics',
                labelAlign: 'top',
                bodyStyle: 'padding: 15px;',
                border: false,
                items: [
                    {
                        xtype: 'checkboxgroup',
                        fieldLabel: _('spookyapp.get_topics.sources') || 'Sources',
                        id: 'spookyapp-get-topics-sources',
                        columns: 3,
                        items: [
                            { boxLabel: 'NewsAPI',   name: 'sources', inputValue: 'newsapi',   checked: true },
                            { boxLabel: 'Reddit',    name: 'sources', inputValue: 'reddit',    checked: true },
                            { boxLabel: 'TMDB',      name: 'sources', inputValue: 'tmdb',      checked: false },
                            { boxLabel: 'IGDB',      name: 'sources', inputValue: 'igdb',      checked: false },
                            { boxLabel: 'GitHub',    name: 'sources', inputValue: 'github',    checked: false },
                            { boxLabel: 'MobileAPI', name: 'sources', inputValue: 'mobileapi', checked: true }
                        ]
                    },
                    {
                        xtype: 'checkboxgroup',
                        fieldLabel: _('spookyapp.get_topics.categories') || 'Categories',
                        id: 'spookyapp-get-topics-categories',
                        columns: 3,
                        items: [
                            { boxLabel: 'IT',            name: 'categories', inputValue: 'IT',            checked: true },
                            { boxLabel: 'Gadgets',       name: 'categories', inputValue: 'Gadgets',       checked: true },
                            { boxLabel: 'Entertainment', name: 'categories', inputValue: 'Entertainment', checked: false },
                            { boxLabel: 'Gaming',        name: 'categories', inputValue: 'Gaming',        checked: false },
                            { boxLabel: 'Sports',        name: 'categories', inputValue: 'Sports',        checked: false }
                        ]
                    },
                    {
                        xtype: 'numberfield',
                        fieldLabel: _('spookyapp.get_topics.min_score') || 'Minimum Score',
                        name: 'min_score',
                        id: 'spookyapp-get-topics-min-score',
                        value: 60,
                        minValue: 0,
                        maxValue: 100,
                        anchor: '50%',
                        allowBlank: false
                    },
                    {
                        xtype: 'numberfield',
                        fieldLabel: _('spookyapp.get_topics.limit') || 'Limit (max topics)',
                        name: 'limit',
                        id: 'spookyapp-get-topics-limit',
                        value: 50,
                        minValue: 1,
                        maxValue: 200,
                        anchor: '50%',
                        allowBlank: false
                    }
                ]
            }],
            buttons: [
                {
                    text: _('spookyapp.btn.fetch_topics') || 'Fetch Topics',
                    cls: 'primary-button',
                    handler: function () {
                        self.doFetchTopics(win);
                    }
                },
                {
                    text: _('spookyapp.btn.cancel') || 'Cancel',
                    handler: function () {
                        win.close();
                    }
                }
            ]
        });

        win.show();
    },

    /**
     * Выполнить AJAX-запрос на получение новых тем.
     *
     * @param {Ext.Window} win Модальное окно
     * @return {void}
     */
    doFetchTopics: function (win) {
        var form = Ext.getCmp('spookyapp-form-get-topics');
        if (!form) { return; }

        var formValues = form.getForm().getValues();

        var sources = [];
        if (formValues.sources) {
            sources = Ext.isArray(formValues.sources)
                ? formValues.sources
                : [formValues.sources];
        }

        var categories = [];
        if (formValues.categories) {
            categories = Ext.isArray(formValues.categories)
                ? formValues.categories
                : [formValues.categories];
        }

        if (sources.length === 0) {
            Ext.Msg.alert(
                _('spookyapp.error') || 'Error',
                _('spookyapp.get_topics.select_source') || 'Please select at least one source.'
            );
            return;
        }

        win.getEl().mask(
            _('spookyapp.get_topics.loading') || 'Fetching topics...',
            'x-mask-loading'
        );

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'topicfinder/refresh',
                sources: Ext.encode(sources),
                categories: Ext.encode(categories),
                min_score: formValues.min_score || 60,
                limit: formValues.limit || 50
            },
            listeners: {
                success: {
                    fn: function (response) {
                        win.getEl().unmask();
                        win.close();
                        this.onTopicsFetched(response);
                    },
                    scope: this
                },
                failure: {
                    fn: function (response) {
                        win.getEl().unmask();
                        Ext.Msg.alert(
                            _('spookyapp.error') || 'Error',
                            response.message || 'Failed to fetch topics.'
                        );
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Callback после успешного получения новых тем.
     *
     * @param {Object} response Ответ сервера
     * @return {void}
     */
    onTopicsFetched: function (response) {
        var data = response.object || response.data || {};
        var total = data.total_fetched || data.total || 0;

        MODx.msg.status({
            title: _('spookyapp.success') || 'Success',
            message: (_('spookyapp.get_topics.fetched') || 'Topics fetched')
                + ': ' + total,
            delay: 4
        });

        var grid = Ext.getCmp('spookyapp-grid-topics');
        if (grid) {
            grid.getStore().baseParams.page = 1;
            grid.refresh();
        }
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Export CSV                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Экспортировать текущие темы в CSV.
     *
     * @return {void}
     */
    onExportCsv: function () {
        var grid = Ext.getCmp('spookyapp-grid-topics');
        var params = {};

        if (grid) {
            params = Ext.apply({}, grid.getStore().baseParams);
        }

        params.action = 'topicfinder/export';
        params.format = 'csv';

        var queryParts = [];
        Ext.iterate(params, function (key, val) {
            if (val !== '' && val !== undefined && val !== null) {
                queryParts.push(encodeURIComponent(key) + '=' + encodeURIComponent(val));
            }
        });

        var exportUrl = SpookyApp.config.connector_url + '?' + queryParts.join('&');
        window.open(exportUrl, '_blank');
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Settings                                               ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Открыть окно настроек.
     *
     * @return {void}
     */
    onOpenSettings: function () {
        if (Ext.ComponentMgr.isRegistered('spookyapp-window-settings')) {
            var win = MODx.load({
                xtype: 'spookyapp-window-settings'
            });
            win.show();
            return;
        }

        MODx.loadPage('system/settings', 'namespace=spookyapp');
    }
});

Ext.reg('spookyapp-panel-topicfinder-page', SpookyApp.panel.TopicFinderPage);