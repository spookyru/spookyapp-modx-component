/**
 * SpookyApp — Chunk Generator Tabs
 *
 * ═══════════════════════════════════════════════════════════════
 * Панель вкладок Chunk Generator.
 * Каждая вкладка содержит единообразную структуру:
 *   [Search Form] → [Results Grid] → [Details Form]
 *
 * Вкладки:
 *   Movies | TV Shows | Person | Games | Devices | Sports | Products
 *
 * Каждая вкладка — BorderLayout:
 *   north:  SearchForm (поиск)
 *   center: ResultsGrid (результаты)
 *   east:   DetailsForm (детали, collapsible)
 * ═══════════════════════════════════════════════════════════════
 *
 * @class   SpookyApp.panel.ChunkGeneratorTabs
 * @extends Ext.TabPanel
 * @xtype   spookyapp-panel-chunkgenerator-tabs
 *
 * @package SpookyApp
 */
SpookyApp.panel.ChunkGeneratorTabs = function(config) {
    config = config || {};

    // ── Определяем конфигурации вкладок ──────────────────────
    this.tabConfigs = [
        {
            type: 'movie',
            title: '<i class="fas fa-film"></i> ' + _('spookyapp.chunkgenerator.tab_movies'),
            yearField: true,
            columns: ['id', 'title', 'original_title', 'year', 'rating', 'vote_count'],
            detailOptions: ['cast', 'crew', 'screenshots', 'similar']
        },
        {
            type: 'tv',
            title: '<i class="fas fa-tv"></i> ' + (_('spookyapp.chunkgenerator.tab_tv') || 'TV Shows'),
            yearField: true,
            columns: ['id', 'title', 'original_title', 'year', 'rating', 'vote_count'],
            detailOptions: ['cast', 'seasons', 'similar']
        },
        {
            type: 'person',
            title: '<i class="fas fa-user"></i> ' + (_('spookyapp.chunkgenerator.tab_person') || 'Person'),
            yearField: false,
            columns: ['id', 'poster', 'title', 'overview', 'rating'],
            detailOptions: ['movies', 'tv', 'images']
        },
        {
            type: 'game',
            title: '<i class="fas fa-gamepad"></i> ' + (_('spookyapp.chunkgenerator.tab_games') || 'Games'),
            yearField: false,
            columns: ['id', 'title', 'year', 'rating', 'vote_count'],
            detailOptions: ['screenshots', 'similar']
        },
        {
            type: 'device',
            title: '<i class="fas fa-mobile-alt"></i> ' + (_('spookyapp.chunkgenerator.tab_devices') || 'Devices'),
            yearField: false,
            columns: ['id', 'title', 'overview', 'poster'],
            detailOptions: []
        },
        {
            type: 'sport',
            title: '<i class="fas fa-futbol"></i> ' + (_('spookyapp.chunkgenerator.tab_sports') || 'Sports'),
            yearField: false,
            columns: ['id', 'title', 'year', 'overview'],
            detailOptions: [],
            subtypes: ['match', 'tournament', 'team', 'league', 'biathlon_schedule', 'biathlon_results']
        },
        {
            type: 'product',
            title: '<i class="fas fa-tag"></i> ' + (_('spookyapp.chunkgenerator.tab_products') || 'Products'),
            yearField: false,
            columns: ['id', 'title', 'overview', 'rating', 'poster'],
            detailOptions: ['offers']
        }
    ];

    Ext.applyIf(config, {
        id: 'spookyapp-chunkgenerator-tabs',
        xtype: 'modx-tabs',
        cls: 'spookyapp-chunkgenerator-tabs',
        bodyStyle: 'padding: 0;',
        border: false,
        deferredRender: true,
        enableTabScroll: true,
        activeTab: 0,
        stateful: true,
        stateId: 'spookyapp-chunkgenerator-tabs-state',
        stateEvents: ['tabchange'],
        defaults: {
            border: false,
            autoHeight: false,
            height: 700,
            layout: 'border'
        },
        items: this.buildTabs()
    });

    SpookyApp.panel.ChunkGeneratorTabs.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.panel.ChunkGeneratorTabs, Ext.TabPanel, {

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Build Tabs                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Строит массив panel-ов для каждой вкладки.
     *
     * @return {Array} Массив Ext.Panel конфигураций
     */
    buildTabs: function() {
        var tabs = [];

        Ext.each(this.tabConfigs, function(cfg) {
            tabs.push(this.buildTabPanel(cfg));
        }, this);

        return tabs;
    },

    /**
     * Строит одну вкладку с BorderLayout.
     *
     * @param {Object} cfg Конфигурация вкладки
     * @return {Object} Ext.Panel config
     */
    buildTabPanel: function(cfg) {
        var tabId = 'spookyapp-chunkgen-tab-' + cfg.type;

        return {
            id: tabId,
            title: cfg.title,
            iconCls: cfg.iconCls || '',
            layout: 'border',
            border: false,
            defaults: {
                border: false
            },
            items: [
                // ── NORTH: Search Form ───────────────────────
                {
                    region: 'north',
                    height: cfg.subtypes ? 120 : 80,
                    split: false,
                    xtype: 'spookyapp-form-chunkgenerator-search',
                    contentType: cfg.type,
                    showYearField: cfg.yearField !== false,
                    subtypes: cfg.subtypes || null,
                    listeners: {
                        'search-success': {
                            fn: function(results, type, query) {
                                this.onSearchSuccess(cfg.type, results, type, query);
                            },
                            scope: this
                        }
                    }
                },
                // ── CENTER: Results Grid ─────────────────────
                {
                    region: 'center',
                    xtype: 'spookyapp-grid-chunkgenerator-results',
                    id: tabId + '-grid',
                    contentType: cfg.type,
                    visibleColumns: cfg.columns || [],
                    listeners: {
                        'get-details': {
                            fn: function(record, type) {
                                this.onGetDetails(cfg.type, record, cfg.detailOptions);
                            },
                            scope: this
                        }
                    }
                },
                // ── EAST: Details Form ───────────────────────
                {
                    region: 'east',
                    width: 500,
                    minWidth: 350,
                    maxWidth: 700,
                    split: true,
                    collapsible: true,
                    collapsed: true,
                    collapseMode: 'mini',
                    title: _('spookyapp.chunkgenerator.details') || 'Details',
                    xtype: 'spookyapp-form-chunkgenerator-details',
                    id: tabId + '-details',
                    contentType: cfg.type,
                    detailOptions: cfg.detailOptions || [],
                    autoScroll: true,
                    listeners: {
                        'chunk-generated': {
                            fn: function(html, type, data) {
                                this.onChunkGenerated(cfg.type, html, data);
                            },
                            scope: this
                        }
                    }
                }
            ]
        };
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Event Handlers                                         ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Обработчик успешного поиска — загрузка результатов в grid.
     *
     * @param {String} tabType   Тип вкладки
     * @param {Array}  results   Результаты поиска
     * @param {String} type      Фактический тип поиска
     * @param {String} query     Поисковый запрос
     */
    onSearchSuccess: function(tabType, results, type, query) {
        var gridId = 'spookyapp-chunkgen-tab-' + tabType + '-grid';
        var grid = Ext.getCmp(gridId);

        if (grid) {
            grid.loadResults(results, type, query);
        }
    },

    /**
     * Обработчик запроса деталей — загрузка в details form.
     *
     * @param {String} tabType Тип вкладки
     * @param {Object} record  Запись из grid
     * @param {Array}  options Опции загрузки
     */
    onGetDetails: function(tabType, record, options) {
        var detailsId = 'spookyapp-chunkgen-tab-' + tabType + '-details';
        var details = Ext.getCmp(detailsId);

        if (details) {
            // Разворачиваем панель деталей
            if (details.collapsed) {
                details.expand(true);
            }
            details.loadDetails(record, tabType, options);
        }
    },

    /**
     * Обработчик генерации чанка.
     *
     * @param {String} tabType Тип вкладки
     * @param {String} html    Сгенерированный HTML
     * @param {Object} data    Данные чанка
     */
    onChunkGenerated: function(tabType, html, data) {
        // Показываем preview окно
        this.showChunkPreview(html, data, tabType);
    },

    /**
     * Показать окно предпросмотра чанка.
     *
     * @param {String} html    HTML код
     * @param {Object} data    Данные
     * @param {String} tabType Тип контента
     */
    showChunkPreview: function(html, data, tabType) {
        var win = new Ext.Window({
            title: _('spookyapp.chunkgenerator.preview') || 'Chunk Preview',
            width: 800,
            height: 600,
            layout: 'border',
            modal: true,
            items: [
                {
                    region: 'center',
                    xtype: 'textarea',
                    id: 'spookyapp-chunk-preview-code',
                    value: html,
                    style: 'font-family: monospace; font-size: 12px;'
                },
                {
                    region: 'south',
                    height: 250,
                    xtype: 'panel',
                    title: _('spookyapp.chunkgenerator.visual_preview') || 'Visual Preview',
                    collapsible: true,
                    autoScroll: true,
                    html: '<div class="spookyapp-chunk-visual-preview">' + html + '</div>'
                }
            ],
            buttons: [
                {
                    text: '<i class="fas fa-copy"></i> ' + (_('spookyapp.chunkgenerator.copy_code') || 'Copy Code'),
                    handler: function() {
                        var code = Ext.getCmp('spookyapp-chunk-preview-code').getValue();
                        SpookyApp.utils.copyToClipboard(code);
                        MODx.msg.status({
                            title: _('success'),
                            message: _('spookyapp.chunkgenerator.copied') || 'Copied to clipboard'
                        });
                    }
                },
                {
                    text: '<i class="fas fa-save"></i> ' + (_('spookyapp.chunkgenerator.save_to_db') || 'Save to Database'),
                    handler: function() {
                        this.saveChunkToDatabase(html, data, tabType, win);
                    },
                    scope: this
                },
                {
                    text: _('cancel'),
                    handler: function() {
                        win.close();
                    }
                }
            ]
        });

        win.show();
    },

    /**
     * Сохранить чанк в базу данных.
     *
     * @param {String}     html    HTML код
     * @param {Object}     data    Данные
     * @param {String}     tabType Тип контента
     * @param {Ext.Window} win     Окно preview
     */
    saveChunkToDatabase: function(html, data, tabType, win) {
        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/savechunk',
                type: tabType,
                external_id: data.id || '',
                title: data.title || data.name || '',
                data: Ext.encode(data),
                chunk_code: html
            },
            listeners: {
                success: {
                    fn: function(r) {
                        MODx.msg.status({
                            title: _('success'),
                            message: (_('spookyapp.chunkgenerator.saved') || 'Saved!') +
                                ' (ID: ' + (r.object ? r.object.chunk_id : '?') + ')'
                        });
                        if (win) win.close();
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        MODx.msg.alert(
                            _('error'),
                            r.message || _('spookyapp.chunkgenerator.err_save_failed')
                        );
                    }
                }
            }
        });
    }
});
Ext.reg('spookyapp-panel-chunkgenerator-tabs', SpookyApp.panel.ChunkGeneratorTabs);


// ╔═════════════════════════════════════════════════════════════╗
// ║  Utility: Copy to Clipboard                                 ║
// ╚═════════════════════════════════════════════════════════════╝
Ext.ns('SpookyApp.utils');

/**
 * Копировать текст в буфер обмена.
 *
 * @param {String} text Текст для копирования
 * @return {Boolean}
 */
SpookyApp.utils.copyToClipboard = function(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
        return true;
    }
    // Fallback для старых браузеров
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        return true;
    } catch (e) {
        return false;
    } finally {
        document.body.removeChild(textarea);
    }
};
