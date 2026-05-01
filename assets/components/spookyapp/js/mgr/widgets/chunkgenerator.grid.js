/**
 * SpookyApp — Chunk Generator Results Grid
 *
 * ═══════════════════════════════════════════════════════════════
 * Таблица результатов поиска.
 * Использует локальный Ext.data.JsonStore (данные приходят
 * из SearchForm, а не напрямую из connector).
 *
 * Конфигурация:
 *   - contentType (string):     Тип контента
 *   - visibleColumns (array):   Видимые колонки
 *
 * Events:
 *   - get-details(record, type) — по клику "Get Details"
 *
 * Колонки:
 *   id | poster | title | original_title | year | rating |
 *   vote_count | overview | actions
 * ═══════════════════════════════════════════════════════════════
 *
 * @class   SpookyApp.grid.ChunkGeneratorResults
 * @extends Ext.grid.GridPanel
 * @xtype   spookyapp-grid-chunkgenerator-results
 *
 * @package SpookyApp
 */
SpookyApp.grid.ChunkGeneratorResults = function(config) {
    config = config || {};

    this.contentType = config.contentType || 'movie';
    this.visibleColumns = config.visibleColumns || ['id', 'title', 'year', 'rating'];

    // ── Store ────────────────────────────────────────────────
    this.resultStore = new Ext.data.JsonStore({
        root: 'results',
        totalProperty: 'total',
        idProperty: 'id',
        fields: [
            { name: 'id',             type: 'auto' },
            { name: 'title',          type: 'string' },
            { name: 'original_title', type: 'string' },
            { name: 'year',           type: 'string' },
            { name: 'overview',       type: 'string' },
            { name: 'poster',         type: 'string' },
            { name: 'rating',         type: 'float' },
            { name: 'vote_count',     type: 'int' },
            { name: 'price',          type: 'string' },
            { name: 'brand',          type: 'string' }
        ],
        data: { results: [], total: 0 }
    });

    Ext.applyIf(config, {
        xtype: 'grid',
        cls: 'spookyapp-chunkgen-results-grid',
        store: this.resultStore,
        columns: this.buildColumns(),
        viewConfig: {
            forceFit: true,
            emptyText: _('spookyapp.chunkgenerator.no_results')
                || '<div style="text-align:center;padding:40px;color:#999;">'
                + '<p style="font-size:14px;">No results</p>'
                + '<p>Use the search form above to find content</p></div>',
            deferEmptyText: false
        },
        autoExpandColumn: 'title-col-' + this.contentType,
        stripeRows: true,
        loadMask: true,
        border: false,
        sm: new Ext.grid.RowSelectionModel({ singleSelect: true }),
        tbar: this.buildToolbar(),
        bbar: this.buildBottomBar(),
        listeners: {
            rowdblclick: {
                fn: this.onRowDblClick,
                scope: this
            }
        }
    });

    // ── Регистрируем события ─────────────────────────────────
    this.addEvents('get-details');

    SpookyApp.grid.ChunkGeneratorResults.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.grid.ChunkGeneratorResults, Ext.grid.GridPanel, {

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Build Columns                                          ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Построить колонки грида.
     *
     * @return {Array} Массив Ext.grid.Column
     */
    buildColumns: function() {
        var cols = [];
        var me = this;
        var visible = this.visibleColumns;

        // ── Row Number ───────────────────────────────────────
        cols.push(new Ext.grid.RowNumberer({ width: 30 }));

        // ── ID ───────────────────────────────────────────────
        cols.push({
            header: 'ID',
            dataIndex: 'id',
            width: 60,
            sortable: true,
            hidden: visible.indexOf('id') === -1
        });

        // ── Poster (thumbnail) ───────────────────────────────
        cols.push({
            header: '',
            dataIndex: 'poster',
            width: 50,
            sortable: false,
            hidden: visible.indexOf('poster') === -1,
            renderer: function(value) {
                if (value) {
                    return '<img src="' + Ext.util.Format.htmlEncode(value)
                        + '" style="width:40px;height:auto;border-radius:3px;" />';
                }
                return '<span style="color:#ccc;">—</span>';
            }
        });

        // ── Title ────────────────────────────────────────────
        cols.push({
            id: 'title-col-' + this.contentType,
            header: _('spookyapp.chunkgenerator.col_title') || 'Title',
            dataIndex: 'title',
            sortable: true,
            renderer: function(value, meta, record) {
                var out = '<b>' + Ext.util.Format.htmlEncode(value) + '</b>';
                var orig = record.get('original_title');
                if (orig && orig !== value) {
                    out += '<br><span style="color:#888;font-size:11px;">'
                        + Ext.util.Format.htmlEncode(orig) + '</span>';
                }
                return out;
            }
        });

        // ── Original Title (отдельная, скрыта по умолчанию) ──
        cols.push({
            header: _('spookyapp.chunkgenerator.col_original_title') || 'Original Title',
            dataIndex: 'original_title',
            width: 150,
            sortable: true,
            hidden: true
        });

        // ── Year ─────────────────────────────────────────────
        cols.push({
            header: _('spookyapp.chunkgenerator.col_year') || 'Year',
            dataIndex: 'year',
            width: 60,
            sortable: true,
            hidden: visible.indexOf('year') === -1,
            align: 'center'
        });

        // ── Rating ───────────────────────────────────────────
        cols.push({
            header: _('spookyapp.chunkgenerator.col_rating') || 'Rating',
            dataIndex: 'rating',
            width: 65,
            sortable: true,
            hidden: visible.indexOf('rating') === -1,
            align: 'center',
            renderer: function(value) {
                if (value === null || value === undefined || value === 0) {
                    return '<span style="color:#ccc;">—</span>';
                }
                var color = value >= 7 ? '#4caf50' : (value >= 5 ? '#ff9800' : '#f44336');
                return '<span style="color:' + color + ';font-weight:bold;">'
                    + '★ ' + parseFloat(value).toFixed(1) + '</span>';
            }
        });

        // ── Vote Count ───────────────────────────────────────
        cols.push({
            header: _('spookyapp.chunkgenerator.col_votes') || 'Votes',
            dataIndex: 'vote_count',
            width: 60,
            sortable: true,
            hidden: visible.indexOf('vote_count') === -1,
            align: 'center',
            renderer: function(value) {
                if (!value) return '';
                if (value >= 1000) {
                    return (value / 1000).toFixed(1) + 'k';
                }
                return value;
            }
        });

        // ── Overview (truncated) ─────────────────────────────
        cols.push({
            header: _('spookyapp.chunkgenerator.col_overview') || 'Overview',
            dataIndex: 'overview',
            width: 200,
            sortable: false,
            hidden: visible.indexOf('overview') === -1,
            renderer: function(value) {
                if (!value) return '';
                var text = Ext.util.Format.htmlEncode(value);
                return '<span title="' + text + '">'
                    + Ext.util.Format.ellipsis(text, 120) + '</span>';
            }
        });
        // ── Price ────────────────────────────────────────────
        cols.push({
            header: _('spookyapp.chunkgenerator.col_price') || 'Price',
            dataIndex: 'price',
            width: 90,
            sortable: false,
            hidden: visible.indexOf('price') === -1,
            align: 'right',
            renderer: function(value) {
                if (!value) return '<span style="color:#ccc;">—</span>';
                return '<span style="color:#2196F3;font-weight:bold;">'
                    + Ext.util.Format.htmlEncode(value) + '</span>';
            }
        });
        // ── Actions ──────────────────────────────────────────
        cols.push({
            header: _('spookyapp.chunkgenerator.col_actions') || 'Actions',
            dataIndex: 'id',
            width: 100,
            sortable: false,
            fixed: true,
            menuDisabled: true,
            align: 'center',
            renderer: function(value, meta, record) {
                return '<button class="spookyapp-btn spookyapp-btn-details" '
                    + 'data-id="' + value + '" '
                    + 'title="' + (_('spookyapp.chunkgenerator.get_details') || 'Get Details') + '">'
                    + '<i class="icon icon-info-circle"></i> '
                    + (_('spookyapp.chunkgenerator.details') || 'Details')
                    + '</button>';
            }
        });

        return cols;
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Build Bars                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Построить верхний toolbar.
     *
     * @return {Array} Toolbar items
     */
    buildToolbar: function() {
        return [
            {
                xtype: 'tbtext',
                id: 'spookyapp-chunkgen-grid-status-' + this.contentType,
                text: _('spookyapp.chunkgenerator.ready') || 'Ready'
            },
            '->',
            {
                xtype: 'tbtext',
                id: 'spookyapp-chunkgen-grid-count-' + this.contentType,
                text: ''
            }
        ];
    },

    /**
     * Построить нижний toolbar (пагинация).
     *
     * @return {Object} Bottom bar config
     */
    buildBottomBar: function() {
        return {
            xtype: 'toolbar',
            items: [
                {
                    text: '« ' + (_('spookyapp.chunkgenerator.prev_page') || 'Prev'),
                    id: 'spookyapp-chunkgen-grid-prev-' + this.contentType,
                    disabled: true,
                    handler: function() {
                        this.changePage(-1);
                    },
                    scope: this
                },
                {
                    xtype: 'tbtext',
                    id: 'spookyapp-chunkgen-grid-page-' + this.contentType,
                    text: ''
                },
                {
                    text: (_('spookyapp.chunkgenerator.next_page') || 'Next') + ' »',
                    id: 'spookyapp-chunkgen-grid-next-' + this.contentType,
                    disabled: true,
                    handler: function() {
                        this.changePage(1);
                    },
                    scope: this
                },
                '->',
                {
                    text: _('spookyapp.chunkgenerator.export_json') || 'Export JSON',
                    handler: this.exportJSON,
                    scope: this
                }
            ]
        };
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Data Management                                        ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var {String} Текущий запрос */
    currentQuery: '',

    /** @var {String} Текущий тип поиска */
    currentSearchType: '',

    /** @var {Number} Текущая страница */
    currentPage: 1,

    /** @var {Number} Общее количество */
    currentTotal: 0,

    /**
     * Загрузить результаты поиска в grid.
     *
     * @param {Array}  results Массив результатов
     * @param {String} type    Тип контента
     * @param {String} query   Поисковый запрос
     */
    loadResults: function(results, type, query) {
        this.currentSearchType = type;
        this.currentQuery = query;
        this.currentTotal = results.length;

        this.resultStore.loadData({
            results: results || [],
            total: results.length
        });

        // ── Обновляем статус ─────────────────────────────────
        var statusCmp = Ext.getCmp('spookyapp-chunkgen-grid-status-' + this.contentType);
        if (statusCmp) {
            statusCmp.setText(
                '<b>' + Ext.util.Format.htmlEncode(query) + '</b>'
                + ' (' + type + ')'
            );
        }

        var countCmp = Ext.getCmp('spookyapp-chunkgen-grid-count-' + this.contentType);
        if (countCmp) {
            countCmp.setText(
                (_('spookyapp.chunkgenerator.results_count') || 'Results: ')
                + results.length
            );
        }
    },

    /**
     * Переключить страницу.
     *
     * @param {Number} direction -1 = назад, +1 = вперёд
     */
    changePage: function(direction) {
        // Пагинация управляется через SearchForm → повторный search
        // с другой страницей. Здесь просто обновляем UI.
        this.currentPage += direction;
        if (this.currentPage < 1) this.currentPage = 1;

        var pageCmp = Ext.getCmp('spookyapp-chunkgen-grid-page-' + this.contentType);
        if (pageCmp) {
            pageCmp.setText(
                (_('spookyapp.chunkgenerator.page') || 'Page') + ': ' + this.currentPage
            );
        }
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Event Handlers                                         ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Обработчик двойного клика на строку.
     *
     * @param {Ext.grid.GridPanel} grid  Grid
     * @param {Number}             index Индекс строки
     * @param {Ext.EventObject}    e     Event
     */
    onRowDblClick: function(grid, index, e) {
        var record = this.resultStore.getAt(index);
        if (record) {
            this.fireEvent('get-details', record.data, this.currentSearchType || this.contentType);
        }
    },

    /**
     * Обработчик клика на кнопки в гриде.
     * Делегирование через cellclick.
     */
    initEvents: function() {
        SpookyApp.grid.ChunkGeneratorResults.superclass.initEvents.call(this);

        this.on('cellclick', function(grid, rowIndex, columnIndex, e) {
            var target = e.getTarget('.spookyapp-btn-details');
            if (target) {
                var record = this.resultStore.getAt(rowIndex);
                if (record) {
                    this.fireEvent(
                        'get-details',
                        record.data,
                        this.currentSearchType || this.contentType
                    );
                }
            }
        }, this);
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Export                                                 ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Экспортировать результаты как JSON.
     */
    exportJSON: function() {
        var records = [];
        this.resultStore.each(function(rec) {
            records.push(rec.data);
        });

        var json = Ext.encode(records);
        SpookyApp.utils.copyToClipboard(json);

        MODx.msg.status({
            title: _('success'),
            message: _('spookyapp.chunkgenerator.json_copied') || 'JSON copied to clipboard'
        });
    }
});
Ext.reg('spookyapp-grid-chunkgenerator-results', SpookyApp.grid.ChunkGeneratorResults);