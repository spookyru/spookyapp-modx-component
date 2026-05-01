/**
 * SpookyApp — Grid тем TopicFinder.
 *
 * ═══════════════════════════════════════════════════════════════
 * Таблица с найденными темами из различных API-источников.
 * Поддерживает фильтрацию, сортировку, пагинацию, поиск,
 * контекстное меню и массовые операции.
 * ═══════════════════════════════════════════════════════════════
 *
 * Зависимости (порядок загрузки):
 *   1. spookyapp.js      — namespace, config, util, lexicon
 *   2. topicfinder.grid.js ← этот файл
 *
 * Использование:
 *   { xtype: 'spookyapp-grid-topics' }
 *
 * Connector:
 *   POST connector.php?action=topicfinder/getlist
 *
 * @class SpookyApp.grid.Topics
 * @extends MODx.grid.Grid
 * @xtype spookyapp-grid-topics
 *
 * @package SpookyApp
 * @subpackage JS\Manager\Widgets
 */
SpookyApp.grid.Topics = function (config) {
    config = config || {};

    // ── Колонки ──────────────────────────────────────────────
    var sm = new Ext.grid.CheckboxSelectionModel();
    var columns = [
        sm,
        {
            header: 'ID',
            dataIndex: 'id',
            width: 55,
            sortable: true,
            fixed: true,
            renderer: function (v) {
                return '<span style="color:#aaa; font-size:11px;">' + (v || '') + '</span>';
            }
        },
        {
            header: _('spookyapp.topic.score') || 'Score',
            dataIndex: 'score',
            width: 60,
            sortable: true,
            fixed: true,
            renderer: SpookyApp.grid.Topics.renderScore
        },
        {
            header: _('spookyapp.topic.title') || 'Title',
            dataIndex: 'title',
            width: 300,
            sortable: true,
            renderer: SpookyApp.grid.Topics.renderTitle
        },
        {
            header: _('spookyapp.topic.source') || 'Source',
            dataIndex: 'source',
            width: 100,
            sortable: true,
            fixed: true,
            renderer: SpookyApp.grid.Topics.renderSource
        },
        {
            header: _('spookyapp.topic.category') || 'Category',
            dataIndex: 'category',
            width: 100,
            sortable: true,
            fixed: true
        },
        {
            header: _('spookyapp.topic.status') || 'Status',
            dataIndex: 'status',
            width: 90,
            sortable: true,
            fixed: true,
            renderer: SpookyApp.grid.Topics.renderStatus
        },
        {
            header: _('spookyapp.topic.published_at') || 'Published',
            dataIndex: 'published_at',
            width: 120,
            sortable: true,
            fixed: true,
            renderer: SpookyApp.grid.Topics.renderDate
        }
    ];

    Ext.applyIf(config, {
        id: 'spookyapp-grid-topics',
        url: SpookyApp.config.connector_url,
        baseParams: {
            action: 'topicfinder/getlist'
        },
        autosave: false,
        save_action: '',
        preventSaveRefresh: false,

        // ── Пагинация ────────────────────────────────────────
        paging: true,
        pageSize: 20,
        remoteSort: true,
        primaryKey: 'id',

        // Сортировка по умолчанию: новые записи вверху
        sortInfo: { field: 'id', direction: 'DESC' },

        // ── Selection Model ──────────────────────────────────
        sm: sm,

        // ── Колонки и поля ───────────────────────────────────
        columns: columns,
        fields: [
            { name: 'id',           type: 'int' },
            { name: 'source',       type: 'string' },
            { name: 'title',        type: 'string' },
            { name: 'url',          type: 'string' },
            { name: 'description',  type: 'string' },
            { name: 'category',     type: 'string' },
            { name: 'published_at', type: 'string' },
            { name: 'score',        type: 'float' },
            { name: 'status',       type: 'string' },
            { name: 'metadata',     type: 'auto' },
            { name: 'cached_at',    type: 'string' }
        ],

        // ── Toolbar ──────────────────────────────────────────
        tbar: this.buildToolbar(config),

        // ── Listeners ────────────────────────────────────────
        listeners: {
            rowclick: {
                fn: this.onRowClick,
                scope: this
            },
            rowdblclick: {
                fn: this.onRowDblClick,
                scope: this
            }
        }
    });

    SpookyApp.grid.Topics.superclass.constructor.call(this, config);

    // ── Кастомный event для details panel ────────────────────
    this.addEvents('topicSelected');
};

Ext.extend(SpookyApp.grid.Topics, MODx.grid.Grid, {

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Toolbar                                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Построить toolbar с кнопками и фильтрами.
     *
     * @param {Object} config Конфигурация грида
     * @return {Array} Элементы тулбара
     */
    buildToolbar: function (config) {
        return [
            {
                text: _('spookyapp.btn.refresh') || 'Refresh',
                cls: 'primary-button',
                handler: this.onRefreshGrid,
                scope: this
            },
            {
                text: _('spookyapp.btn.get_new_topics') || 'Get New Topics',
                cls: 'primary-button',
                handler: this.onGetNewTopics,
                scope: this
            },
            '-',
            // ── Фильтр по категории ─────────────────────────
           /* {
                xtype: 'label',
                html: (_('spookyapp.filter.category') || 'Category') + ':&nbsp;',
                cls: 'spookyapp-toolbar-label'
            },*/
            {
                xtype: 'combo',
                id: 'spookyapp-filter-category',
                emptyText: _('spookyapp.filter.all') || 'All',
                width: 120,
                listWidth: 150,
                displayField: 'label',
                valueField: 'value',
                mode: 'local',
                editable: false,
                triggerAction: 'all',
                store: new Ext.data.ArrayStore({
                    fields: ['value', 'label'],
                    data: [
                        ['',              _('spookyapp.filter.all')           || 'All'],
                        ['IT',            'IT'],
                        ['Gadgets',       'Gadgets'],
                        ['Entertainment', 'Entertainment'],
                        ['Gaming',        'Gaming']
                    ]
                }),
                listeners: {
                    select: {
                        fn: this.onFilterCategory,
                        scope: this
                    }
                }
            },
            // ── Фильтр по источнику ─────────────────────────
           /* {
                xtype: 'label',
                html: (_('spookyapp.filter.source') || 'Source') + ':&nbsp;',
                cls: 'spookyapp-toolbar-label'
            },*/
            {
                xtype: 'combo',
                id: 'spookyapp-filter-source',
                emptyText: _('spookyapp.filter.all') || 'All',
                width: 120,
                listWidth: 150,
                displayField: 'label',
                valueField: 'value',
                mode: 'local',
                editable: false,
                triggerAction: 'all',
                store: new Ext.data.ArrayStore({
                    fields: ['value', 'label'],
                    data: [
                        ['', _('spookyapp.filter.all') || 'All'],
                        ['newsapi',   'NewsAPI'],
                        ['reddit',    'Reddit'],
                        ['tmdb',      'TMDB'],
                        ['igdb',      'IGDB'],
                        ['github',    'GitHub'],
                        ['mobileapi', 'MobileAPI']
                    ]
                }),
                listeners: {
                    select: {
                        fn: this.onFilterSource,
                        scope: this
                    }
                }
            },
            '-',
            {
                text: ' ' + _('spookyapp.btn.delete_selected') || 'Delete Selected',
                id: 'spookyapp-btn-delete-selected',
                handler: this.onDeleteSelected,
                scope: this
            },
            '->',
            // ── Поиск ───────────────────────────────────────
            {
                xtype: 'textfield',
                id: 'spookyapp-search-topics',
                emptyText: _('spookyapp.search') || 'Search...',
                width: 200,
                listeners: {
                    change: {
                        fn: this.onSearchChange,
                        scope: this
                    },
                    render: {
                        fn: function (cmp) {
                            new Ext.KeyMap(cmp.getEl(), {
                                key: Ext.EventObject.ENTER,
                                fn: this.onSearchEnter,
                                scope: this
                            });
                        },
                        scope: this
                    }
                }
            },
            {
                xtype: 'button',
                iconCls: 'fas fa-search',
                handler: this.onSearchEnter,
                scope: this
            },
            {
                xtype: 'button',
                text: '✕',
                tooltip: _('spookyapp.search.clear') || 'Clear search',
                handler: this.onSearchClear,
                scope: this
            }
        ];
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Context Menu                                           ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Построить контекстное меню (правый клик по строке).
     *
     * @param {Ext.data.Record} record Выбранная запись
     * @param {Ext.menu.Menu} m Объект меню
     * @return {void}
     */
    getMenu: function (record, m) {
        return [
            {
                text: _('spookyapp.topic.view_details') || 'View Details',
                handler: this.onViewDetails,
                scope: this
            },
            '-',
            {
                text: _('spookyapp.topic.rewrite_ai') || 'Rewrite with AI',
                handler: this.onRewriteWithAI,
                scope: this
            },
            {
                text: _('spookyapp.topic.save_draft') || 'Save as Draft',
                handler: this.onSaveAsDraft,
                scope: this
            },
            '-',
            {
                text: _('spookyapp.topic.delete') || 'Delete',
                cls: 'menu-item-danger',
                handler: this.onDeleteTopic,
                scope: this
            }
        ];
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Toolbar Handlers                                       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Обновить грид (перезагрузить данные).
     *
     * @return {void}
     */
    onRefreshGrid: function () {
        this.getStore().baseParams.page = 1;
        this.refresh();
    },

    /**
     * Удалить выбранные (чекбоксом) темы.
     *
     * @return {void}
     */
    onDeleteSelected: function () {
        var selected = this.getSelectionModel().getSelections();
        if (!selected || selected.length === 0) {
            Ext.Msg.alert(
                _('spookyapp.topic.delete') || 'Delete',
                _('spookyapp.topic.delete_none_selected') || 'Please select topics to delete first.'
            );
            return;
        }
        var ids = selected.map(function (r) { return r.get('id'); });
        var self = this;
        Ext.Msg.confirm(
            _('spookyapp.topic.delete') || 'Delete',
            'Delete ' + ids.length + ' selected topic(s)? This cannot be undone.',
            function (btn) {
                if (btn !== 'yes') { return; }
                Ext.Ajax.request({
                    url: SpookyApp.config.connector_url,
                    params: { action: 'topicfinder/deletemultiple', ids: ids.join(',') },
                    success: function (response) {
                        var r = null;
                        try { r = Ext.decode(response.responseText); } catch (e) {}
                        if (r && r.success) {
                            MODx.msg.status({
                                title: _('spookyapp.success') || 'Success',
                                message: r.message || 'Topics deleted.',
                                delay: 4
                            });
                            self.getSelectionModel().clearSelections();
                            self.onRefreshGrid();
                        } else {
                            Ext.Msg.alert(
                                _('spookyapp.error') || 'Error',
                                (r && r.message) || 'Delete failed.'
                            );
                        }
                    },
                    failure: function () {
                        Ext.Msg.alert(_('spookyapp.error') || 'Error', 'Request failed.');
                    }
                });
            }
        );
    },

    /**
     * Показать модальное окно с превью топиков из Dry Run.
     *
     * @param {Object} data Объект из ответа процессора (fetched, top_topics, by_source, by_category, duration_sec)
     * @return {void}
     */
    showDryRunPreview: function (data) {
        var topics = data.top_topics || [];
        var rows = [];

        Ext.each(topics, function (t) {
            var title = Ext.util.Format.htmlEncode(t.title || '(no title)');
            var link = t.url
                ? '<a href="' + Ext.util.Format.htmlEncode(t.url) + '" target="_blank">' + title + '</a>'
                : title;
            rows.push(
                '<tr style="border-bottom:1px solid #eee">'
                + '<td style="padding:4px 6px;font-weight:bold;color:#336">' + (t.score || 0) + '</td>'
                + '<td style="padding:4px 6px;">' + link + '</td>'
                + '<td style="padding:4px 6px;color:#666;font-size:11px;">'
                    + (t.source_label || t.source || '') + '</td>'
                + '</tr>'
            );
        });

        var sources = [];
        Ext.iterate(data.by_source || {}, function (k, v) { sources.push(k + ': ' + v); });
        var cats = [];
        Ext.iterate(data.by_category || {}, function (k, v) { cats.push(k + ': ' + v); });

        var html = '<div style="padding:8px;">'
            + '<p style="margin:0 0 8px;color:#555;">'
            + '<b>Dry Run</b> — ' + (data.fetched || topics.length) + ' topics fetched in ' + (data.duration_sec || 0) + 's'
            + (sources.length ? ' &nbsp;|&nbsp; Sources: ' + sources.join(', ') : '')
            + (cats.length ? '<br>Categories: ' + cats.join(', ') : '')
            + '</p>'
            + '<div style="max-height:340px;overflow-y:auto;">'
            + '<table style="width:100%;border-collapse:collapse;">'
            + '<thead><tr style="background:#f0f0f0">'
            + '<th style="padding:4px 6px;text-align:left;width:50px;">Score</th>'
            + '<th style="padding:4px 6px;text-align:left;">Title</th>'
            + '<th style="padding:4px 6px;text-align:left;width:100px;">Source</th>'
            + '</tr></thead>'
            + '<tbody>' + rows.join('') + '</tbody>'
            + '</table></div>'
            + '<p style="margin:8px 0 0;color:#888;font-size:11px;">Uncheck "Dry Run" and click "Get New Topics" again to save these to the database.</p>'
            + '</div>';

        Ext.Msg.show({
            title: 'Dry Run Preview — ' + (data.fetched || 0) + ' topics',
            msg: html,
            buttons: {ok: 'Close'},
            icon: Ext.MessageBox.INFO,
            minWidth: 560,
            fn: Ext.emptyFn
        });
    },

    /**
     * Открыть окно "Get New Topics" — запуск сбора тем из API.
     *
     * @return {void}
     */
    onGetNewTopics: function () {
        // Если xtype зарегистрирован — используем его
        if (Ext.ComponentMgr.isRegistered('spookyapp-window-get-topics')) {
            var regWin = MODx.load({
                xtype: 'spookyapp-window-get-topics',
                listeners: {
                    success: {
                        fn: function () { this.onRefreshGrid(); },
                        scope: this
                    }
                }
            });
            regWin.show();
            return;
        }

        // Fallback: inline window with per-source params
        var self = this;

        var win = new Ext.Window({
            title: _('spookyapp.get_topics.title') || 'Get New Topics',
            width: 660,
            height: 580,
            modal: true,
            closeAction: 'close',
            layout: 'fit',
            items: [{
                xtype: 'form',
                id: 'spookyapp-form-get-topics-grid',
                labelAlign: 'top',
                autoScroll: true,
                bodyStyle: 'padding:12px;',
                border: false,
                defaults: { anchor: '100%' },
                items: [
                    // ── Common options row ──────────────────────────────
                    {
                        xtype: 'panel',
                        layout: 'column',
                        border: false,
                        style: 'margin-bottom:6px;',
                        items: [
                            {
                                columnWidth: 0.28,
                                layout: 'form',
                                border: false,
                                labelWidth: 85,
                                items: [{
                                    xtype: 'numberfield',
                                    fieldLabel: _('spookyapp.get_topics.min_score') || 'Min Score',
                                    name: 'min_score', value: 5, minValue: 0, maxValue: 100
                                }]
                            },
                            {
                                columnWidth: 0.28,
                                layout: 'form',
                                border: false,
                                labelWidth: 85,
                                items: [{
                                    xtype: 'numberfield',
                                    fieldLabel: _('spookyapp.get_topics.limit') || 'Max Topics',
                                    name: 'max_topics', value: 50, minValue: 1, maxValue: 200
                                }]
                            },
                            {
                                columnWidth: 0.44,
                                layout: 'form',
                                border: false,
                                labelWidth: 70,
                                items: [{
                                    xtype: 'checkboxgroup',
                                    fieldLabel: 'Options',
                                    columns: 1,
                                    items: [
                                        { boxLabel: 'Force Refresh (ignore cache)', name: 'force_refresh', inputValue: '1' },
                                        { boxLabel: 'Dry Run (no DB save)',         name: 'dry_run',       inputValue: '1' },
                                        { boxLabel: 'Cleanup old topics (>30d)',    name: 'cleanup_old',   inputValue: '1' }
                                    ]
                                }]
                            }
                        ]
                    },
                    // ── RealTime News ─────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-realtime-news', title: 'RealTime News (real-time-news-data.p.rapidapi.com)',
                        checkboxToggle: true, checkboxName: '_use_realtime_news',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'checkboxgroup',
                                fieldLabel: 'Topics (leave all unchecked = top headlines)',
                                columns: 3,
                                items: [
                                    { boxLabel: 'Technology',    name: 'rt_topic', inputValue: 'TECHNOLOGY' },
                                    { boxLabel: 'Sports',        name: 'rt_topic', inputValue: 'SPORTS' },
                                    { boxLabel: 'Entertainment', name: 'rt_topic', inputValue: 'ENTERTAINMENT' },
                                    { boxLabel: 'Business',      name: 'rt_topic', inputValue: 'BUSINESS' },
                                    { boxLabel: 'Science',       name: 'rt_topic', inputValue: 'SCIENCE' },
                                    { boxLabel: 'Health',        name: 'rt_topic', inputValue: 'HEALTH' }
                                ]
                            },
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.33, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Language',
                                            name: 'rt_lang', value: 'en',
                                            store: [['en','English'], ['ru','Russian']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.34, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Country',
                                            name: 'rt_country', value: 'US',
                                            store: [['US','US'], ['RU','RU'], ['GB','GB'], ['DE','DE'], ['FR','FR']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.33, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Time period',
                                            name: 'rt_time_period', value: '7d',
                                            store: [['1h','1 hour'], ['1d','1 day'], ['7d','7 days'], ['1m','1 month'], ['anytime','Anytime']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '100%'
                                        }]
                                    }
                                ]
                            },
                            {
                                xtype: 'textfield',
                                fieldLabel: 'Keyword search (overrides topics above)',
                                name: 'rt_keyword',
                                emptyText: 'e.g. AI robots, iPhone 17, GPT-5'
                            }
                        ]
                    },
                    // ── TheNewsAPI ────────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-thenewsapi', title: 'TheNewsAPI (api.thenewsapi.com)',
                        checkboxToggle: true, checkboxName: '_use_thenewsapi',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'checkboxgroup', fieldLabel: 'Categories', columns: 4,
                                items: [
                                    { boxLabel: 'Tech',          name: 'tna_cat', inputValue: 'tech' },
                                    { boxLabel: 'Sports',        name: 'tna_cat', inputValue: 'sports' },
                                    { boxLabel: 'Business',      name: 'tna_cat', inputValue: 'business' },
                                    { boxLabel: 'Entertainment', name: 'tna_cat', inputValue: 'entertainment' },
                                    { boxLabel: 'Science',       name: 'tna_cat', inputValue: 'science' },
                                    { boxLabel: 'Health',        name: 'tna_cat', inputValue: 'health' },
                                    { boxLabel: 'Food',          name: 'tna_cat', inputValue: 'food' },
                                    { boxLabel: 'Travel',        name: 'tna_cat', inputValue: 'travel' }
                                ]
                            },
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [{
                                    columnWidth: 0.4, layout: 'form', border: false, labelAlign: 'top',
                                    items: [{
                                        xtype: 'combo', fieldLabel: 'Language',
                                        name: 'tna_lang', value: 'en',
                                        store: [['en','English'], ['ru','Russian']],
                                        valueField: 'field1', displayField: 'field2',
                                        triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                    }]
                                }]
                            }
                        ]
                    },
                    // ── NewsData ──────────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-newsdata', title: 'NewsData (newsdata.io)',
                        checkboxToggle: true, checkboxName: '_use_newsdata',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'checkboxgroup', fieldLabel: 'Categories', columns: 4,
                                items: [
                                    { boxLabel: 'Technology',    name: 'nd_cat', inputValue: 'technology' },
                                    { boxLabel: 'Sports',        name: 'nd_cat', inputValue: 'sports' },
                                    { boxLabel: 'Business',      name: 'nd_cat', inputValue: 'business' },
                                    { boxLabel: 'Entertainment', name: 'nd_cat', inputValue: 'entertainment' },
                                    { boxLabel: 'Science',       name: 'nd_cat', inputValue: 'science' },
                                    { boxLabel: 'Health',        name: 'nd_cat', inputValue: 'health' },
                                    { boxLabel: 'Lifestyle',     name: 'nd_cat', inputValue: 'lifestyle' },
                                    { boxLabel: 'Food',          name: 'nd_cat', inputValue: 'food' },
                                    { boxLabel: 'Tourism',       name: 'nd_cat', inputValue: 'tourism' },
                                    { boxLabel: 'Environment',   name: 'nd_cat', inputValue: 'environment' },
                                    { boxLabel: 'Domestic',      name: 'nd_cat', inputValue: 'domestic' },
                                    { boxLabel: 'Other',         name: 'nd_cat', inputValue: 'other' }
                                ]
                            },
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [{
                                    columnWidth: 0.4, layout: 'form', border: false, labelAlign: 'top',
                                    items: [{
                                        xtype: 'combo', fieldLabel: 'Language',
                                        name: 'nd_lang', value: 'en',
                                        store: [['en','English'], ['ru','Russian']],
                                        valueField: 'field1', displayField: 'field2',
                                        triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                    }]
                                }]
                            }
                        ]
                    },
                    // ── Reddit ───────────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-reddit', title: 'Reddit',
                        checkboxToggle: true, checkboxName: '_use_reddit',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'textfield', fieldLabel: 'Subreddits (comma-separated)',
                                name: 'reddit_subreddits', value: 'technology, programming, gadgets, gaming',
                                emptyText: 'e.g. technology, programming, gadgets, gaming'
                            },
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Sort',
                                            name: 'reddit_sort', value: 'hot',
                                            store: [['hot','Hot'], ['top','Top (Week)'], ['rising','Rising'], ['new','New']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'numberfield', fieldLabel: 'Min upvotes',
                                            name: 'reddit_min_upvotes', value: 0, minValue: 0, maxValue: 100000, anchor: '80%'
                                        }]
                                    }
                                ]
                            }
                        ]
                    },
                    // ── TMDB Trends ──────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-tmdb-trends', title: 'TMDB Trends (trending)',
                        checkboxToggle: true, checkboxName: '_use_tmdb_trends',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'checkboxgroup', fieldLabel: 'Media Type (leave all unchecked = all types)',
                                columns: 3,
                                items: [
                                    { boxLabel: 'Movies',   name: 'tmdb_tr_types', inputValue: 'movie' },
                                    { boxLabel: 'TV Shows', name: 'tmdb_tr_types', inputValue: 'tv' },
                                    { boxLabel: 'Persons',  name: 'tmdb_tr_types', inputValue: 'person' }
                                ]
                            },
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Period',
                                            name: 'tmdb_tr_period', value: 'week',
                                            store: [['day','Today'], ['week','This week']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Language',
                                            name: 'tmdb_tr_lang', value: 'ru-RU',
                                            store: [['ru-RU','Русский'], ['en-US','English']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '100%'
                                        }]
                                    }
                                ]
                            }
                        ]
                    },
                    // ── TMDB Upcoming ────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-tmdb-upcoming', title: 'TMDB Upcoming (premieres)',
                        checkboxToggle: true, checkboxName: '_use_tmdb_upcoming',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Language',
                                            name: 'tmdb_up_lang', value: 'ru-RU',
                                            store: [['ru-RU','Русский'], ['en-US','English']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'textfield', fieldLabel: 'Region (ISO 3166-1)',
                                            name: 'tmdb_up_region', value: 'US', anchor: '100%'
                                        }]
                                    }
                                ]
                            }
                        ]
                    },
                    // ── Games (RAWG) ────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-games', title: 'Games (RAWG)',
                        checkboxToggle: true, checkboxName: '_use_rawg',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Type',
                                            name: 'games_type', value: 'popular',
                                            store: [['popular','Popular'], ['new_releases','New Releases']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Platform',
                                            name: 'games_platform', value: '',
                                            store: [['','All platforms'], ['4','PC'], ['187','PlayStation 5'], ['1','Xbox'], ['7','Nintendo Switch']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '100%'
                                        }]
                                    }
                                ]
                            },
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'datefield', fieldLabel: 'Released from',
                                            name: 'games_date_from', format: 'Y-m-d', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'datefield', fieldLabel: 'Released to',
                                            name: 'games_date_to', format: 'Y-m-d', anchor: '100%'
                                        }]
                                    }
                                ]
                            }
                        ]
                    },
                    // ── Devices (MobileApi) ──────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-devices', title: 'Mobile Devices',
                        checkboxToggle: true, checkboxName: '_use_mobileapi',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Device type',
                                            name: 'devices_type', value: '',
                                            store: [['','All types'], ['Smartphones','Smartphones'], ['Tablets','Tablets']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'numberfield', fieldLabel: 'Release year',
                                            name: 'devices_year', value: new Date().getFullYear(), minValue: 2020, maxValue: 2030, anchor: '80%'
                                        }]
                                    }
                                ]
                            }
                        ]
                    },
                    // ── GitHub ───────────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-github', title: 'GitHub Trending',
                        checkboxToggle: true, checkboxName: '_use_github',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'textfield', fieldLabel: 'Language',
                                            name: 'github_language', emptyText: 'e.g. python, javascript', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Period',
                                            name: 'github_period', value: 'daily',
                                            store: [['daily','Daily'], ['weekly','Weekly'], ['monthly','Monthly']],
                                            valueField: 'field1', displayField: 'field2',
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '100%'
                                        }]
                                    }
                                ]
                            }
                        ]
                    },
                    // ── Sports ───────────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-sports', title: 'Sports (FlashLive)',
                        checkboxToggle: true, checkboxName: '_use_flashlive',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.4, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'combo', fieldLabel: 'Sport',
                                            name: 'sports_sport_id', value: '1',
                                            valueField: 'field1', displayField: 'field2',
                                            store: [
                                                ['1','Soccer'], ['2','Tennis'], ['3','Basketball'],
                                                ['4','Hockey'], ['5','American Football'], ['6','Baseball'],
                                                ['7','Handball'], ['8','Rugby Union'], ['12','Volleyball'],
                                                ['13','Cricket'], ['14','Darts'], ['15','Snooker'],
                                                ['16','Boxing'], ['21','Badminton'], ['23','Golf'],
                                                ['24','Field Hockey'], ['25','Table Tennis'],
                                                ['28','MMA'], ['31','Motorsport'], ['36','eSports'],
                                                ['37','Winter Sports'], ['38','Ski Jumping'],
                                                ['39','Alpine Skiing'], ['40','Cross Country'],
                                                ['41','Biathlon'], ['42','Kabaddi']
                                            ],
                                            triggerAction: 'all', editable: false, mode: 'local', anchor: '95%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.3, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'numberfield',
                                            fieldLabel: 'Day offset (-1=yesterday)',
                                            name: 'sports_indent_days', value: -1, minValue: -7, maxValue: 7, anchor: '80%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.3, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'numberfield', fieldLabel: 'Timezone (UTC offset)',
                                            name: 'sports_timezone', value: 4, minValue: -12, maxValue: 14, anchor: '90%'
                                        }]
                                    }
                                ]
                            }
                        ]
                    },
                    // ── Football ─────────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-football', title: 'Football (API-Football)',
                        checkboxToggle: true, checkboxName: '_use_apifootball',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'panel', layout: 'column', border: false,
                                items: [
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'datefield', fieldLabel: 'Date',
                                            name: 'football_date', format: 'Y-m-d', emptyText: 'Today', anchor: '90%'
                                        }]
                                    },
                                    {
                                        columnWidth: 0.5, layout: 'form', border: false, labelAlign: 'top',
                                        items: [{
                                            xtype: 'textfield', fieldLabel: 'League ID',
                                            name: 'football_league', emptyText: 'e.g. 39 (Premier League)', anchor: '100%'
                                        }]
                                    }
                                ]
                            }
                        ]
                    },
                    // ── Biathlon ─────────────────────────────────────────
                    {
                        xtype: 'fieldset', id: 'fs-biathlon', title: 'Biathlon (IBU)',
                        checkboxToggle: true, checkboxName: '_use_ibu',
                        collapsed: true, collapsible: true,
                        labelAlign: 'top', defaults: { anchor: '100%' },
                        items: [
                            {
                                xtype: 'datefield', fieldLabel: 'Season start date',
                                name: 'biathlon_date', format: 'Y-m-d', emptyText: 'Leave empty = current season'
                            }
                        ]
                    }
                ] // end form items
            }], // end window items
            buttons: [
                {
                    text: _('spookyapp.btn.fetch_topics') || 'Fetch Topics',
                    cls: 'primary-button',
                    handler: function () {
                        var form = Ext.getCmp('spookyapp-form-get-topics-grid');
                        if (!form) { return; }
                        var vals = form.getForm().getValues();

                        // ── Collect active sources from uncollapsed fieldsets ──
                        var fsSourceMap = {
                            'fs-realtime-news': 'realtime_news',
                            'fs-thenewsapi':    'thenewsapi',
                            'fs-newsdata':      'newsdata',
                            'fs-reddit':         'reddit',
                            'fs-tmdb-trends':    'tmdb_trends',
                            'fs-tmdb-upcoming':  'tmdb_upcoming',
                            'fs-games':          'rawg',
                            'fs-devices':   'mobileapi',
                            'fs-github':    'github',
                            'fs-sports':    'flashlive',
                            'fs-football':  'apifootball',
                            'fs-biathlon':  'ibu'
                        };
                        var sources = [];
                        Ext.iterate(fsSourceMap, function(fsId, srcVal) {
                            var fs = Ext.getCmp(fsId);
                            if (fs && !fs.collapsed) { sources.push(srcVal); }
                        });

                        if (sources.length === 0) {
                            Ext.Msg.alert(_('spookyapp.error') || 'Error',
                                _('spookyapp.get_topics.select_source') || 'Please expand at least one source section.');
                            return;
                        }

                        // ── Build source_options JSON ──────────────────────────
                        var sourceOptions = {};
                        var formApi = form.getForm();

                        // Helper: get field value via component API (handles emptyText and combo valueField correctly)
                        var fv = function(name, def) {
                            var f = formApi.findField(name);
                            if (!f) { return (def !== undefined ? def : ''); }
                            var v = f.getValue();
                            return (v !== undefined && v !== null && v !== '') ? v : (def !== undefined ? def : '');
                        };
                        // Helper: collect all checked checkbox inputValues for a given name.
                        // In ExtJS3, CheckboxGroup registers itself (not its children) in BasicForm.items.
                        // So we must scan both the field itself AND its inner .items collection.
                        // NOTE: use getValue() not .checked — .checked is only the initial config
                        // value and does NOT update when the user interacts with the checkbox.
                        var cc = function(name) {
                            var checked = [];
                            formApi.items.each(function(f) {
                                // CheckboxGroup: its individual checkboxes are in f.items
                                if (f.items && typeof f.items.each === 'function') {
                                    f.items.each(function(cb) {
                                        if (cb.name === name && cb.getValue()) {
                                            checked.push(cb.inputValue || '');
                                        }
                                    });
                                // Standalone checkbox
                                } else if (f.name === name && f.getValue()) {
                                    checked.push(f.inputValue || '');
                                }
                            });
                            return checked;
                        };

                        // RealTime News options
                        if (!Ext.getCmp('fs-realtime-news').collapsed) {
                            sourceOptions['realtime_news'] = {
                                topics:      cc('rt_topic'),
                                keyword:     fv('rt_keyword', '').trim(),
                                lang:        fv('rt_lang', 'en'),
                                country:     fv('rt_country', 'US'),
                                time_period: fv('rt_time_period', '7d')
                            };
                        }
                        // TheNewsAPI options
                        if (!Ext.getCmp('fs-thenewsapi').collapsed) {
                            sourceOptions['thenewsapi'] = {
                                categories: cc('tna_cat'),
                                lang:       fv('tna_lang', 'en')
                            };
                        }
                        // NewsData options
                        if (!Ext.getCmp('fs-newsdata').collapsed) {
                            sourceOptions['newsdata'] = {
                                categories: cc('nd_cat'),
                                lang:       fv('nd_lang', 'en')
                            };
                        }
                        // Reddit options
                        if (!Ext.getCmp('fs-reddit').collapsed) {
                            var subs = (vals['reddit_subreddits'] || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                            sourceOptions['reddit'] = {
                                subreddits: subs,
                                sort: fv('reddit_sort', 'hot'),
                                min_upvotes: parseInt(vals['reddit_min_upvotes'] || 0, 10)
                            };
                        }
                        // TMDB Trends options
                        if (!Ext.getCmp('fs-tmdb-trends').collapsed) {
                            var tmdbTrTypes = cc('tmdb_tr_types');
                            sourceOptions['tmdb_trends'] = {
                                types:    tmdbTrTypes,
                                period:   fv('tmdb_tr_period', 'week'),
                                language: fv('tmdb_tr_lang', 'ru-RU')
                            };
                        }
                        // TMDB Upcoming options
                        if (!Ext.getCmp('fs-tmdb-upcoming').collapsed) {
                            sourceOptions['tmdb_upcoming'] = {
                                language: fv('tmdb_up_lang', 'ru-RU'),
                                region:   (fv('tmdb_up_region', 'US').trim().toUpperCase() || 'US')
                            };
                        }
                        // Games options
                        if (!Ext.getCmp('fs-games').collapsed) {
                            sourceOptions['games'] = {
                                type: fv('games_type', 'popular'),
                                platform: fv('games_platform', ''),
                                date_from: vals['games_date_from'] || '',
                                date_to: vals['games_date_to'] || ''
                            };
                        }
                        // Devices options
                        if (!Ext.getCmp('fs-devices').collapsed) {
                            sourceOptions['devices'] = {
                                type: fv('devices_type', ''),
                                year: parseInt(vals['devices_year'] || new Date().getFullYear(), 10)
                            };
                        }
                        // GitHub options
                        if (!Ext.getCmp('fs-github').collapsed) {
                            sourceOptions['github'] = {
                                language: vals['github_language'] || '',
                                period: fv('github_period', 'daily')
                            };
                        }
                        // Sports/Football/Biathlon options
                        if (!Ext.getCmp('fs-sports').collapsed) {
                            var indentDaysVal = parseInt(vals['sports_indent_days'], 10);
                            var tzVal = parseInt(fv('sports_timezone', '4'), 10);
                            sourceOptions['sports'] = {
                                sport_id: fv('sports_sport_id', '1'),
                                indent_days: isNaN(indentDaysVal) ? -1 : indentDaysVal,
                                timezone: isNaN(tzVal) ? 4 : tzVal
                            };
                        }
                        if (!Ext.getCmp('fs-football').collapsed) {
                            sourceOptions['football'] = {
                                date: vals['football_date'] || '',
                                league: vals['football_league'] || ''
                            };
                        }
                        if (!Ext.getCmp('fs-biathlon').collapsed) {
                            sourceOptions['biathlon'] = { date: vals['biathlon_date'] || '' };
                        }

                        console.log('[SpookyApp] GetNewTopics: sources=', sources, 'source_options=', sourceOptions);

                        win.getEl().mask(_('spookyapp.get_topics.loading') || 'Fetching topics...', 'x-mask-loading');

                        Ext.Ajax.request({
                            url: SpookyApp.config.connector_url,
                            params: {
                                action: 'topicfinder/refresh',
                                sources: sources.join(','),
                                min_score: vals.min_score || 5,
                                max_topics: vals.max_topics || 50,
                                force_refresh: vals.force_refresh ? 1 : 0,
                                dry_run: vals.dry_run ? 1 : 0,
                                cleanup_old: vals.cleanup_old ? 1 : 0,
                                source_options: Ext.encode(sourceOptions)
                            },
                            timeout: 120000,
                            success: function(response) {
                                win.getEl().unmask();
                                var rawText = response.responseText || '';
                                var r = null;
                                try {
                                    r = Ext.decode(rawText);
                                } catch (e) {
                                    console.error('[SpookyApp] GetNewTopics: JSON parse error. Raw response (first 300 chars):', rawText.substr(0, 300));
                                    Ext.Msg.alert(
                                        _('spookyapp.error') || 'Error',
                                        'Server returned invalid response. Check MODX error log.\n\n' + rawText.substr(0, 200)
                                    );
                                    return;
                                }
                                if (!r) {
                                    console.error('[SpookyApp] GetNewTopics: empty/null JSON response');
                                    Ext.Msg.alert(_('spookyapp.error') || 'Error', 'Server returned empty response.');
                                    return;
                                }
                                if (r.success) {
                                    win.close();
                                    var data = r.object || {};
                                    console.log('[SpookyApp] GetNewTopics success:', data);

                                    if (data.dry_run && data.top_topics && data.top_topics.length > 0) {
                                        // ── Dry Run: show preview window with fetched topics ──
                                        self.showDryRunPreview(data);
                                    } else {
                                        // ── Real save: notify + refresh grid ─────────────────
                                        var msg = 'Fetched: ' + (data.fetched || 0)
                                            + '  |  Saved: ' + (data.saved_new || 0)
                                            + '  |  Updated: ' + (data.updated || 0)
                                            + '  |  Time: ' + (data.duration_sec || 0) + 's';
                                        MODx.msg.status({ title: 'Topics Loaded', message: msg, delay: 6 });
                                        self.onRefreshGrid();
                                    }
                                } else {
                                    console.error('[SpookyApp] GetNewTopics API error:', r);
                                    Ext.Msg.alert(_('spookyapp.error') || 'Error', r.message || 'Failed to fetch topics');
                                }
                            },
                            failure: function(response) {
                                win.getEl().unmask();
                                var status = response.status || 0;
                                var rawText = response.responseText || '';
                                console.error('[SpookyApp] GetNewTopics HTTP failure. Status:', status, 'Response (first 500):', rawText.substr(0, 500));
                                var errMsg;
                                if (status === 0 || rawText === '') {
                                    errMsg = 'Request timed out or was aborted. The API call may take too long. Try again or check the MODX error log.';
                                } else {
                                    errMsg = 'HTTP ' + status + ' error.';
                                    try {
                                        var r = Ext.decode(rawText);
                                        if (r && r.message) { errMsg = r.message; }
                                    } catch (e) {
                                        errMsg += '\n\n' + rawText.substr(0, 200);
                                    }
                                }
                                Ext.Msg.alert(_('spookyapp.error') || 'Error', errMsg);
                            }
                        });
                    }
                },
                {
                    text: _('spookyapp.btn.cancel') || 'Cancel',
                    handler: function () { win.close(); }
                }
            ]
        });
        win.show();
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Filter Handlers                                        ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Фильтр по категории.
     *
     * @param {Ext.form.ComboBox} combo
     * @param {Ext.data.Record} record
     * @return {void}
     */
    onFilterCategory: function (combo, record) {
        var value = combo.getValue();
        var store = this.getStore();
        store.baseParams.category = value || '';
        store.baseParams.page = 1;
        this.getBottomToolbar().changePage(1);
        this.refresh();
    },

    /**
     * Фильтр по источнику.
     *
     * @param {Ext.form.ComboBox} combo
     * @param {Ext.data.Record} record
     * @return {void}
     */
    onFilterSource: function (combo, record) {
        var value = combo.getValue();
        var store = this.getStore();
        store.baseParams.source = value || '';
        store.baseParams.page = 1;
        this.getBottomToolbar().changePage(1);
        this.refresh();
    },

    /**
     * Поиск: по нажатию Enter или кнопке.
     *
     * @return {void}
     */
    onSearchEnter: function () {
        var field = Ext.getCmp('spookyapp-search-topics');
        if (!field) { return; }
        var query = field.getValue().trim();
        var store = this.getStore();
        store.baseParams.query = query;
        store.baseParams.page = 1;
        this.getBottomToolbar().changePage(1);
        this.refresh();
    },

    /**
     * Поиск: по change (с debounce через 500ms пустого значения).
     *
     * @param {Ext.form.TextField} field
     * @param {string} newVal
     * @param {string} oldVal
     * @return {void}
     */
    onSearchChange: function (field, newVal, oldVal) {
        // Если поле очистили — сразу обновляем
        if (!newVal && oldVal) {
            this.onSearchClear();
        }
    },

    /**
     * Очистить поиск и сбросить фильтр.
     *
     * @return {void}
     */
    onSearchClear: function () {
        var field = Ext.getCmp('spookyapp-search-topics');
        if (field) {
            field.setValue('');
        }
        var store = this.getStore();
        store.baseParams.query = '';
        store.baseParams.page = 1;
        this.getBottomToolbar().changePage(1);
        this.refresh();
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Row Handlers                                           ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Клик по строке — передать данные в details panel.
     *
     * Генерирует событие 'topicSelected' с данными записи.
     *
     * @param {Ext.grid.GridPanel} grid
     * @param {number} rowIndex
     * @param {Ext.EventObject} e
     * @return {void}
     */
    onRowClick: function (grid, rowIndex, e) {
        var record = this.getStore().getAt(rowIndex);
        if (record) {
            this.fireEvent('topicSelected', record.data);
        }
    },

    /**
     * Двойной клик — открыть модальное окно с деталями.
     *
     * @param {Ext.grid.GridPanel} grid
     * @param {number} rowIndex
     * @param {Ext.EventObject} e
     * @return {void}
     */
    onRowDblClick: function (grid, rowIndex, e) {
        var record = this.getStore().getAt(rowIndex);
        if (!record) { return; }

        this.showDetailsWindow(record.data);
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Context Menu Handlers                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * View Details — открыть окно с полной информацией.
     *
     * @return {void}
     */
    onViewDetails: function () {
        var record = this.menu.record;
        if (!record) { return; }

        this.showDetailsWindow(record);
    },

    /**
     * Rewrite with AI — отправить тему на переписывание.
     *
     * @return {void}
     */
    onRewriteWithAI: function () {
        var record = this.menu.record;
        if (!record || !record.id) { return; }

        // Проверяем, зарегистрирован ли xtype
        if (!Ext.ComponentMgr.isRegistered('spookyapp-window-rewrite-ai')) {
            MODx.msg.alert(
                _('spookyapp.error') || 'Error',
                'AI Rewrite window is not registered yet.'
            );
            return;
        }

        var win = MODx.load({
            xtype: 'spookyapp-window-rewrite-ai',
            record: record,
            listeners: {
                success: {
                    fn: function () {
                        this.refresh();
                    },
                    scope: this
                }
            }
        });
        win.show();
    },

    /**
     * Save as Draft — создать MODX-ресурс из темы.
     *
     * @return {void}
     */
    onSaveAsDraft: function () {
        var record = this.menu.record;
        if (!record || !record.id) { return; }

        MODx.msg.confirm({
            title: _('spookyapp.topic.save_draft') || 'Save as Draft',
            text: (_('spookyapp.topic.save_draft_confirm') || 'Create a draft resource from topic')
                + ' "' + Ext.util.Format.ellipsis(record.title || '', 60) + '"?',
            url: SpookyApp.config.connector_url,
            params: {
                action: 'topicfinder/savethemes',
                topics: Ext.encode([record.id])
            },
            listeners: {
                success: {
                    fn: function (response) {
                        var data = response.object || response.a || {};
                        var created = data.total_created || 0;
                        MODx.msg.status({
                            title: _('spookyapp.success') || 'Success',
                            message: (_('spookyapp.topic.draft_created') || 'Draft created')
                                + ' (' + created + ')',
                            delay: 3
                        });
                        this.refresh();
                    },
                    scope: this
                },
                failure: {
                    fn: function (response) {
                        MODx.msg.alert(
                            _('spookyapp.error') || 'Error',
                            response.message || 'Failed to save draft.'
                        );
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Delete — удалить тему из БД.
     *
     * @return {void}
     */
    onDeleteTopic: function () {
        // If multiple rows are selected, delegate to bulk delete
        var selected = this.getSelectionModel().getSelections();
        if (selected && selected.length > 1) {
            this.onDeleteSelected();
            return;
        }
        var record = this.menu.record;
        if (!record || !record.id) { return; }

        MODx.msg.confirm({
            title: _('spookyapp.topic.delete') || 'Delete',
            text: (_('spookyapp.topic.delete_confirm') || 'Are you sure you want to delete topic')
                + ' "' + Ext.util.Format.ellipsis(record.title || '', 60) + '"?',
            url: SpookyApp.config.connector_url,
            params: {
                action: 'topicfinder/delete',
                id: record.id
            },
            listeners: {
                success: {
                    fn: function () {
                        MODx.msg.status({
                            title: _('spookyapp.success') || 'Success',
                            message: _('spookyapp.topic.deleted') || 'Topic deleted.',
                            delay: 3
                        });
                        this.refresh();
                    },
                    scope: this
                },
                failure: {
                    fn: function (response) {
                        MODx.msg.alert(
                            _('spookyapp.error') || 'Error',
                            response.message || 'Failed to delete topic.'
                        );
                    },
                    scope: this
                }
            }
        });
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Helpers                                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Открыть модальное окно с деталями темы.
     *
     * @param {Object} data Данные записи
     * @return {void}
     */
    showDetailsWindow: function (data) {
        if (!data || !data.id) { return; }

        // Проверяем, зарегистрирован ли xtype
        if (Ext.ComponentMgr.isRegistered('spookyapp-window-topic-details')) {
            var win = MODx.load({
                xtype: 'spookyapp-window-topic-details',
                record: data
            });
            win.show();
            return;
        }

        // Fallback: простое Ext.Window с информацией
        var description = data.description || '';
        var url = data.url || '';
        var metadata = data.metadata || {};

        var html = '<div class="spookyapp-details-fallback" style="padding:10px;">'
            + '<h3 style="margin:0 0 10px;">' + Ext.util.Format.htmlEncode(data.title || '') + '</h3>'
            + '<div style="margin-bottom:8px;">'
            + SpookyApp.util.formatSource(data.source) + '&nbsp;&nbsp;'
            + SpookyApp.util.formatScore(data.score) + '&nbsp;&nbsp;'
            + SpookyApp.util.formatStatus(data.status)
            + '</div>'
            + '<p style="color:#555; line-height:1.5;">' + Ext.util.Format.htmlEncode(description) + '</p>';

        if (url) {
            html += '<p><a href="' + Ext.util.Format.htmlEncode(url) + '" target="_blank"'
                + ' rel="noopener noreferrer">' + Ext.util.Format.htmlEncode(url) + '</a></p>';
        }

        // metadata
        if (metadata && typeof metadata === 'object') {
            html += '<hr style="margin:10px 0;">';
            html += '<div style="font-size:11px; color:#888;">';
            Ext.iterate(metadata, function (key, val) {
                if (val && typeof val !== 'object') {
                    html += '<div><strong>' + Ext.util.Format.htmlEncode(key) + ':</strong> '
                        + Ext.util.Format.htmlEncode(String(val)) + '</div>';
                }
            });
            html += '</div>';
        }

        html += '</div>';

        var win = new Ext.Window({
            title: _('spookyapp.topic.details') || 'Topic Details',
            width: 600,
            maxHeight: 500,
            autoScroll: true,
            modal: true,
            html: html,
            buttons: [{
                text: _('spookyapp.btn.close') || 'Close',
                handler: function () { win.close(); }
            }]
        });
        win.show();
    }
});

// ╔═════════════════════════════════════════════════════════════╗
// ║  Static Renderers                                           ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Renderer: Score с цветовой кодировкой.
 *
 * @param {number|string} value Значение score
 * @param {Object} metaData Метаданные ячейки
 * @param {Ext.data.Record} record Запись
 * @return {string} HTML
 * @static
 */
SpookyApp.grid.Topics.renderScore = function (value, metaData, record) {
    return SpookyApp.util.formatScore(value);
};

/**
 * Renderer: Title — жирный шрифт, обрезка до 80 символов.
 *
 * @param {string} value Заголовок
 * @param {Object} metaData Метаданные ячейки
 * @param {Ext.data.Record} record Запись
 * @return {string} HTML
 * @static
 */
SpookyApp.grid.Topics.renderTitle = function (value, metaData, record) {
    if (!value) { return ''; }

    var escaped = Ext.util.Format.htmlEncode(value);
    var truncated = SpookyApp.util.truncateText(escaped, 80);

    var html = '<span style="font-weight:bold;"'
        + ' title="' + escaped + '"'
        + '>' + truncated + '</span>';

    return html;
};

/**
 * Renderer: Source — цветной badge с названием источника.
 *
 * @param {string} value Идентификатор источника
 * @param {Object} metaData Метаданные ячейки
 * @param {Ext.data.Record} record Запись
 * @return {string} HTML
 * @static
 */
SpookyApp.grid.Topics.renderSource = function (value, metaData, record) {
    return SpookyApp.util.formatSource(value);
};

/**
 * Renderer: Status — badge со статусом.
 *
 * @param {string} value Статус
 * @param {Object} metaData Метаданные ячейки
 * @param {Ext.data.Record} record Запись
 * @return {string} HTML
 * @static
 */
SpookyApp.grid.Topics.renderStatus = function (value, metaData, record) {
    return SpookyApp.util.formatStatus(value);
};

/**
 * Renderer: Published date — относительная дата.
 *
 * @param {string} value Дата публикации
 * @param {Object} metaData Метаданные ячейки
 * @param {Ext.data.Record} record Запись
 * @return {string} HTML
 * @static
 */
SpookyApp.grid.Topics.renderDate = function (value, metaData, record) {
    if (!value) { return '—'; }

    var relative = SpookyApp.util.formatRelativeDate(value);
    var escaped = Ext.util.Format.htmlEncode(value);

    return '<span title="' + escaped + '">' + relative + '</span>';
};

// ── Регистрация ExtJS xtype ─────────────────────────────────
Ext.reg('spookyapp-grid-topics', SpookyApp.grid.Topics);