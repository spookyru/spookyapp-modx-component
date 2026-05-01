/**
 * SpookyApp — TopicFinder Panel (Border Layout).
 *
 * ═══════════════════════════════════════════════════════════════
 * Основная панель модуля TopicFinder с тремя регионами:
 *   north  — фильтры (categories, sources, score, dates)
 *   center — grid с темами (SpookyApp.grid.Topics)
 *   east   — details panel (информация о выбранной теме)
 * ═══════════════════════════════════════════════════════════════
 *
 * Зависимости (порядок загрузки):
 *   1. spookyapp.js
 *   2. topicfinder.grid.js
 *   3. topicfinder.panel.js ← этот файл
 *
 * Использование:
 *   { xtype: 'spookyapp-panel-topicfinder' }
 *
 * @class SpookyApp.panel.TopicFinder
 * @extends Ext.Panel
 * @xtype spookyapp-panel-topicfinder
 *
 * @package SpookyApp
 * @subpackage JS\Manager\Widgets
 */
SpookyApp.panel.TopicFinderTrends = function (config) {
    config = config || {};

    Ext.applyIf(config, {
        id: 'spookyapp-panel-topicfinder-trends',
        layout: 'border',
        border: false,
        anchor: '100%',
        items: [
            this.buildNorthPanel(config),
            this.buildCenterPanel(config),
            this.buildEastPanel(config)
        ]
    });

    SpookyApp.panel.TopicFinderTrends.superclass.constructor.call(this, config);

    // Store live instance for inline onclick handlers in details HTML
    SpookyApp._tfPanel = this;

    // ── Подписка на событие grid → details ───────────────────
    this.on('afterrender', this.bindGridEvents, this, { single: true });
};

Ext.extend(SpookyApp.panel.TopicFinderTrends, Ext.Panel, {

    // ╔═════════════════════════════════════════════════════════╗
    // ║  NORTH: Фильтры                                         ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Построить панель фильтров (north region).
     *
     * Содержит:
     * - CheckboxGroup: категории
     * - CheckboxGroup: источники
     * - Slider: минимальный score
     * - DateField: период от/до
     * - Кнопки Apply / Reset
     *
     * @param {Object} config Конфигурация
     * @return {Object} Конфигурация north-панели
     */
    buildNorthPanel: function (config) {
        return {
            region: 'north',
            xtype: 'panel',
            id: 'spookyapp-filters-form',
            height: 120,
            split: false,
            border: true,
            collapsible: true,
            //collapseMode: 'mini',
            animCollapse: false,
            title: _('spookyapp.filters.title'),
            titleCollapse: true,
            cls: 'spookyapp-filters-panel',
            bodyStyle: 'padding: 8px 12px;',
            layout: 'column',
            defaults: {
                border: true,
                bodyStyle: 'padding: 20px;'
          },
             tools: [{
               id: 'toggle',
               style: 'padding: 0 4px; width: 20px; height: 20px;',
                  handler: function(event, toolEl, panel){
                      panel.collapsed ? panel.expand() : panel.collapse();
                  }
              }],
            items: [
                // ── Категории ────────────────────────────────
                {
                    columnWidth: 0.20,
                    layout: 'form',
                    labelWidth: 100,
                    items: [{
                        xtype: 'checkboxgroup',
                        fieldLabel: _('spookyapp.filter.categories'),
                        id: 'spookyapp-filter-categories',
                      columns: 2,
                        style: 'margin-top: 4px;',
                        items: [
                            { boxLabel: 'IT',            name: 'cat_it',            inputValue: 'IT' },
                            { boxLabel: 'Gadgets',       name: 'cat_gadgets',       inputValue: 'Gadgets' },
                            { boxLabel: 'Entertainment', name: 'cat_entertainment', inputValue: 'Entertainment' },
                            { boxLabel: 'Gaming',        name: 'cat_gaming',        inputValue: 'Gaming' }
                        ]
                    }]
                },
                // ── Источники ────────────────────────────────
                {
                    columnWidth: 0.20,
                    layout: 'form',
                    labelWidth: 100,
                    items: [{
                        xtype: 'checkboxgroup',
                        fieldLabel: _('spookyapp.filter.sources'),
                        id: 'spookyapp-filter-sources',
                        columns: 2,
                        items: [
                            { boxLabel: 'NewsAPI',   name: 'src_newsapi',   inputValue: 'newsapi' },
                            { boxLabel: 'Reddit',    name: 'src_reddit',    inputValue: 'reddit' },
                            { boxLabel: 'TMDB',      name: 'src_tmdb',      inputValue: 'tmdb' },
                            { boxLabel: 'IGDB',      name: 'src_igdb',      inputValue: 'igdb' },
                            { boxLabel: 'GitHub',    name: 'src_github',    inputValue: 'github' },
                            { boxLabel: 'MobileAPI', name: 'src_mobileapi', inputValue: 'mobileapi' }
                        ]
                    }]
                },
                // ── Min Score ────────────────────────────────
                {
                    columnWidth: 0.10,
                    layout: 'form',
                    labelWidth: 100,
                    items: [{
                        xtype: 'numberfield',
                        fieldLabel: _('spookyapp.filter.min_score'),
                        id: 'spookyapp-filter-min-score',
                        name: 'min_score',
                        minValue: 0,
                        maxValue: 100,
                        value: 0,
                        width: 55,
                        allowBlank: true
                    }]
                },
                // ── Date Range ───────────────────────────────
                {
                    columnWidth: 0.20,
                    layout: 'form',
                    labelWidth: 35,
                    items: [
                        {
                            xtype: 'datefield',
                            fieldLabel: _('spookyapp.filter.date_from'),
                            id: 'spookyapp-filter-date-from',
                            name: 'date_from',
                            format: 'Y-m-d',
                            width: 180,
                            allowBlank: true,
                            style: 'min-width: 64px; padding: 0 2px;',                            
                        },
                        {
                            xtype: 'datefield',
                            fieldLabel: _('spookyapp.filter.date_to'),
                            id: 'spookyapp-filter-date-to',
                            name: 'date_to',
                            format: 'Y-m-d',
                            width: 180,
                            allowBlank: true,
                            style: 'min-width: 64px; padding: 0 2px;', 
                        }
                    ]
                },
                // ── Кнопки ───────────────────────────────────
                {
                    columnWidth: 0.05,
                    layout: 'form',
                    bodyStyle: 'padding: 2px;',
                    items: [
                        {
                            xtype: 'button',
                            text: _('spookyapp.btn.apply') ,
                            cls: 'primary-button',
                            width: '90%',
                            handler: this.applyFilters,
                            scope: this,
                            style: 'margin-bottom: 4px;'
                        },
                        {
                            xtype: 'button',
                            text: _('spookyapp.btn.reset'),
                            width: '90%',
                            handler: this.resetFilters,
                            scope: this
                        }
                    ]
              },
              {
                   columnWidth: 0.30,
                    layout: 'form',
                  items: [
                  {                  
                  }
                ]
                }
            ]
        };
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  CENTER: Grid                                           ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Построить центральную панель с grid'ом тем.
     *
     * @param {Object} config Конфигурация
     * @return {Object} Конфигурация center-панели
     */
    buildCenterPanel: function (config) {
        return {
            region: 'center',
            xtype: 'spookyapp-grid-topics',
            id: 'spookyapp-grid-topics',
            border: true,
            preventRender: true
        };
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  EAST: Details Panel                                    ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Построить панель деталей (east region).
     *
     * Отображает полную информацию о выбранной теме:
     * title, source, category, date, score, description, url, metadata.
     *
     * @param {Object} config Конфигурация
     * @return {Object} Конфигурация east-панели
     */
    buildEastPanel: function (config) {
        return {
            region: 'east',
            xtype: 'panel',
            id: 'spookyapp-details-panel',
            title: _('spookyapp.details.title'),
            width: '50%',
            minWidth: 350,
            maxWidth: 800,
            split: true,
            collapsible: true,
            collapsed: false,
            collapseMode: 'mini',
            animCollapse: false,
            border: true,
            autoScroll: true,
            cls: 'spookyapp-details-panel',
            bodyStyle: 'padding: 10px 5px;',
            html: this.buildEmptyDetailsHtml(),
            tbar: [
                {
                    text: '<i class="fas fa-pencil-alt"></i> ' + (_('spookyapp.btn.rewrite_ai') || 'Rewrite with AI'),
                    id: 'spookyapp-btn-rewrite',
                    disabled: true,
                    handler: this.onRewriteWithAI,
                    scope: this
                },
                {
                    text: '<i class="fas fa-language"></i> ' + (_('spookyapp.btn.translate') || 'Translate'),
                    id: 'spookyapp-btn-translate',
                    disabled: true,
                    handler: this.onTranslate,
                    scope: this
                },
                '->',
                {
                    text: '<i class="fas fa-search"></i> ' + (_('spookyapp.btn.search_news') || 'Search News'),
                    id: 'spookyapp-btn-search-news',
                    disabled: true,
                    handler: this.onSearchNews,
                    scope: this
                }
            ],
            bbar: [
                {
                    text: '<i class="fas fa-save"></i> ' + (_('spookyapp.btn.save_draft') || 'Save Draft'),
                    id: 'spookyapp-btn-save-draft',
                    disabled: true,
                    handler: this.onSaveAsDraft,
                    scope: this
                },
                '->',
                {
                    text: '<i class="fas fa-copy"></i> ' + (_('spookyapp.btn.copy_url') || 'Copy URL'),
                    id: 'spookyapp-btn-copy-url',
                    disabled: true,
                    handler: this.onCopyUrl,
                    scope: this
                },
                {
                    text: '<i class="fas fa-copy"></i> ' + (_('spookyapp.btn.copy_data') || 'Copy Data'),
                    id: 'spookyapp-btn-copy-data',
                    disabled: true,
                    handler: this.onCopyData,
                    scope: this
                },
                '-',
                {
                    text: '<i class="fas fa-trash-alt"></i> ' + (_('spookyapp.btn.delete') || 'Delete'),
                    id: 'spookyapp-btn-delete',
                    disabled: true,
                    cls: 'spookyapp-btn-danger',
                    handler: this.onDeleteTopic,
                    scope: this
                }
            ]
        };
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Event Binding                                          ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Подписаться на события grid'а после рендера панели.
     *
     * @return {void}
     */
    bindGridEvents: function () {
        var grid = Ext.getCmp('spookyapp-grid-topics');
        if (!grid) { return; }

        grid.on('topicSelected', this.updateDetails, this);
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  updateDetails: обновление details panel                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Обновить содержимое details panel данными темы.
     *
     * Вызывается при:
     * - Клике по строке в grid (событие topicSelected)
     * - Программном вызове
     *
     * @param {Object} data Данные темы (из record.data)
     * @return {void}
     */
    updateDetails: function (data) {
        var panel = Ext.getCmp('spookyapp-details-panel');
        if (!panel) { return; }

        // Сохраняем текущие данные
        this.currentTopic = data;

        // Если панель свёрнута — разворачиваем
        if (panel.collapsed) {
            panel.expand(false);
        }

        // Строим HTML
        var html = this.buildDetailsHtml(data);
        panel.body.update(html);

        // Активируем кнопки
        this.setDetailsButtons(true);
    },

    /**
     * Построить HTML для пустого состояния details panel.
     *
     * @return {string} HTML
     */
    buildEmptyDetailsHtml: function () {
        return '<div class="spookyapp-details-empty" style="'
            + 'display:flex; align-items:center; justify-content:center;'
            + 'height:100%; color:#999; font-size:14px; text-align:center;'
            + 'padding:40px;">'
            + '<div>'
            + '<div style="font-size:48px; margin-bottom:16px; opacity:0.3;">📋</div>'
            + '<div>' + (_('spookyapp.details.select_topic'))  + '</div>'
            + '</div>'
            + '</div>';
    },

    /**
     * Построить HTML с деталями темы.
     *
     * Структура:
     * - Header: title (h2, bold)
     * - Meta bar: source badge + category + status + score
     * - Date info
     * - Description (полный текст)
     * - URL (кликабельная ссылка)
     * - Metadata (expandable fieldset)
     *
     * @param {Object} d Данные темы
     * @return {string} HTML
     */
    buildDetailsHtml: function (d) {
        if (!d) { return this.buildEmptyDetailsHtml(); }

        var html = '';
        var metadata = (d.metadata && typeof d.metadata === 'object') ? d.metadata : null;

        // ── Header ───────────────────────────────────────────
        html += '<div class="spookyapp-details-header" style="'
            + 'padding:16px 16px 12px; border-bottom:1px solid #e0e0e0; background:#fafafa;">';

        // Title
        html += '<h2 style="'
            + 'margin:0 0 8px; font-size:16px; font-weight:bold; line-height:1.3; color:#333;">'
            + Ext.util.Format.htmlEncode(d.title || '')
            + '</h2>';

        // Meta bar: source + category + status
        html += '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">';
        html += SpookyApp.util.formatSource(d.source);
        if (d.category) {
            html += '<span style="'
                + 'background:#e8eaf6; color:#3949ab; padding:2px 8px; border-radius:3px; font-size:11px;">'
                + Ext.util.Format.htmlEncode(d.category)
                + '</span>';
        }
        html += SpookyApp.util.formatStatus(d.status);
        html += '</div>';

        html += '</div>'; // /header

        // ── Score + Date row ─────────────────────────────────
        html += '<div style="'
            + 'padding:12px 16px; display:flex; justify-content:space-between;'
            + 'align-items:center; border-bottom:1px solid #f0f0f0;">';

        // Score
        html += '<div>';
        html += '<span style="color:#888; font-size:11px; text-transform:uppercase;">'
            + (_('spookyapp.details.score') || 'Score') + '</span><br>';
        html += '<span style="font-size:24px;">' + SpookyApp.util.formatScore(d.score) + '</span>';
        html += '</div>';

        // Date
        html += '<div style="text-align:right;">';
        html += '<span style="color:#888; font-size:11px; text-transform:uppercase;">'
            + (_('spookyapp.details.published') || 'Published') + '</span><br>';
        html += '<span style="font-size:13px; color:#555;">'
            + SpookyApp.util.formatRelativeDate(d.published_at)
            + '</span>';
        if (d.published_at) {
            html += '<br><span style="font-size:11px; color:#aaa;">'
                + Ext.util.Format.htmlEncode(d.published_at)
                + '</span>';
        }
        html += '</div>';

        html += '</div>'; // /score+date

        // ── Description ──────────────────────────────────────
        html += '<div style="padding:12px 16px; border-bottom:1px solid #f0f0f0;">';
        html += '<div style="color:#888; font-size:11px; text-transform:uppercase; margin-bottom:6px;">'
            + (_('spookyapp.details.description') || 'Description') + '</div>';

        var description = d.description || '';
        if (description) {
            html += '<div style="font-size:13px; line-height:1.6; color:#444;">'
                + Ext.util.Format.htmlEncode(description)
                + '</div>';
        } else {
            html += '<div style="color:#bbb; font-style:italic;">'
                + (_('spookyapp.details.no_description') || 'No description available')
                + '</div>';
        }
        html += '</div>';

        // ── URL ──────────────────────────────────────────────
        if (d.url) {
            html += '<div style="padding:12px 16px; border-bottom:1px solid #f0f0f0;">';
            html += '<div style="color:#888; font-size:11px; text-transform:uppercase; margin-bottom:4px;">'
                + (_('spookyapp.details.url') || 'Source URL') + '</div>';
            html += '<a href="' + Ext.util.Format.htmlEncode(d.url) + '"'
                + ' target="_blank" rel="noopener noreferrer"'
                + ' style="font-size:12px; color:#1565c0; word-break:break-all;"'
                + ' title="' + Ext.util.Format.htmlEncode(d.url) + '">'
                + SpookyApp.util.truncateText(d.url, 80)
                + '</a>';
            html += '</div>';
        }

        // ── Результат рерайта AI ──────────────────────────────
        var rewrites = (metadata && metadata._rewrites) ? metadata._rewrites : null;
        if (rewrites) {
            var rewriteModes = [];
            Ext.iterate(rewrites, function (k) { rewriteModes.push(k); });

            if (rewriteModes.length > 0) {
                var activeMode = d._activeRewriteMode
                    || (rewrites['article'] ? 'article' : rewriteModes[0]);
                var rw = rewrites[activeMode] || {};
                var taId = 'rw_ta_' + activeMode.replace(/\W/g, '_');

                // Build plain-text for textarea: title / lead / content (tags stripped)
                var rwParts = [];
                if (rw.title)   { rwParts.push(rw.title); }
                if (rw.lead)    { rwParts.push(''); rwParts.push(rw.lead); }
                if (rw.content) {
                    var plainRw = String(rw.content).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                    rwParts.push(''); rwParts.push(plainRw);
                }
                if (rw.meta_description) { rwParts.push(''); rwParts.push(rw.meta_description); }
                var rwText = rwParts.join('\n');

                html += '<div style="padding:12px 16px; border-top:3px solid #1565c0; background:#f0f4ff;">';

                // ── Section header ──────────────────────────
                html += '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">';
                html += '<div style="font-size:11px; font-weight:bold; color:#1565c0; text-transform:uppercase; letter-spacing:.05em;">'
                    + (_('spookyapp.details.rewrite') || 'Рерайт AI')
                    + '</div>';
                // Mode tabs (only if >1 mode)
                if (rewriteModes.length > 1) {
                    html += '<div>';
                    Ext.each(rewriteModes, function (m) {
                        var isCur = (m === activeMode);
                        html += '<a href="#" onclick="SpookyApp._tfPanel.switchRewriteMode(\''
                            + m.replace(/['\\/]/g, '') + '\'); return false;"'
                            + ' style="font-size:10px; padding:2px 7px; border-radius:3px;'
                            + ' margin-left:3px; text-decoration:none;'
                            + (isCur ? 'background:#1565c0;color:#fff;' : 'background:#c5cae9;color:#1a237e;')
                            + '">' + Ext.util.Format.htmlEncode(m) + '</a>';
                    });
                    html += '</div>';
                }
                html += '</div>'; // /header

                // ── Textarea ────────────────────────────────
                html += '<textarea id="' + taId + '" '
                    + ' style="width:100%; height:220px;'
                    + ' font-size:13px; font-family:inherit; line-height:1.55;'
                    + ' border:1px solid #c5cae9; border-radius:4px;'
                    + ' padding:8px; color:#333;'
                    + ' outline:none;">'
                    + Ext.util.Format.htmlEncode(rwText)
                    + '</textarea>';

                // ── Copy button ──────────────────────────────
                html += '<div style="margin-top:6px; text-align:right;">'
                    + '<a href="#" onclick="'
                    + 'var ta=document.getElementById(\'' + taId + '\');'
                    + 'if(navigator.clipboard&&navigator.clipboard.writeText){'
                    + 'navigator.clipboard.writeText(ta.value).then(function(){ta.select();});'
                    + '}else{ta.select();document.execCommand(\'copy\');}'
                    + 'return false;"'
                    + ' style="font-size:12px; color:#1565c0; text-decoration:none;'
                    + ' padding:3px 10px; border:1px solid #90caf9; border-radius:3px;'
                    + ' background:#e3f2fd; display:inline-block;">'
                    + '<i class="fas fa-copy" style="margin-right:4px;"></i>'
                    + (_('spookyapp.details.rewrite_copy') || 'Копировать') + '</a>'
                    + '</div>';

                // ── Params ───────────────────────────────────
                var rwp = rw._params || {};
                var toneMap = { neutral:'Нейтральный', formal:'Деловой', casual:'Разговорный',
                                enthusiastic:'Эмоциональный', analytical:'Аналитический' };
                var modeMap = { article:'Статья', news:'Новость', social:'Соцсети',
                                seo:'SEO', title:'Заголовки', custom:'Свой' };
                var paramParts = [];
                if (rwp.mode)        { paramParts.push(modeMap[rwp.mode] || rwp.mode); }
                if (rwp.tone)        { paramParts.push(toneMap[rwp.tone] || rwp.tone); }
                if (rwp.language)    { paramParts.push(rwp.language.toUpperCase()); }
                if (rwp.temperature !== undefined) { paramParts.push('t=' + rwp.temperature); }
                if (rw._generated_at) { paramParts.push(rw._generated_at); }

                if (paramParts.length > 0) {
                    html += '<div style="font-size:11px; color:#90a4ae; margin-top:8px;'
                        + ' border-top:1px solid #c5cae9; padding-top:6px;">'
                        + paramParts.join(' &nbsp;·&nbsp; ') + '</div>';
                }

                html += '</div>'; // /rewrite block
            }
        }

        // ── Metadata (expandable, private keys hidden) ───────
        if (metadata) {
            // Only show public (non-underscore) keys
            var metaKeys = [];
            Ext.iterate(metadata, function (k) {
                if (k.charAt(0) !== '_') { metaKeys.push(k); }
            });

            if (metaKeys.length > 0) {
                html += '<div style="padding:12px 16px;">';

                // Expandable fieldset via <details>/<summary>
                html += '<details class="spookyapp-metadata-details">';
                html += '<summary style="'
                    + 'cursor:pointer; color:#888; font-size:11px;'
                    + 'text-transform:uppercase; outline:none; user-select:none;'
                    + '">'
                    + (_('spookyapp.details.metadata') || 'Metadata')
                    + ' (' + metaKeys.length + ')'
                    + '</summary>';

                html += '<div style="margin-top:8px;">';
                html += '<table style="width:100%; font-size:12px; border-collapse:collapse;">';

                Ext.iterate(metadata, function (key, val) {
                    if (key.charAt(0) === '_') { return; } // skip private keys
                    if (val === null || val === undefined) { return; }

                    var displayVal;
                    if (typeof val === 'object') {
                        displayVal = '<code style="font-size:11px; color:#666;">'
                            + Ext.util.Format.htmlEncode(JSON.stringify(val))
                            + '</code>';
                    } else if (typeof val === 'string' && val.match(/^https?:\/\//)) {
                        displayVal = '<a href="' + Ext.util.Format.htmlEncode(val) + '"'
                            + ' target="_blank" rel="noopener noreferrer"'
                            + ' style="color:#1565c0; word-break:break-all;">'
                            + SpookyApp.util.truncateText(val, 50)
                            + '</a>';
                    } else {
                        displayVal = Ext.util.Format.htmlEncode(String(val));
                    }

                    html += '<tr style="border-bottom:1px solid #f5f5f5;">'
                        + '<td style="padding:4px 8px 4px 0; color:#888; white-space:nowrap; vertical-align:top;">'
                        + Ext.util.Format.htmlEncode(key) + '</td>'
                        + '<td style="padding:4px 0; color:#333;">'
                        + displayVal + '</td>'
                        + '</tr>';
                });

                html += '</table>';
                html += '</div>';
                html += '</details>';
                html += '</div>';
            }
        }

        // ── Cached at ────────────────────────────────────────
        if (d.cached_at) {
            html += '<div style="'
                + 'padding:8px 16px; font-size:11px; color:#bbb; border-top:1px solid #f0f0f0;'
                + '">'
                + (_('spookyapp.details.cached_at') || 'Cached')
                + ': ' + SpookyApp.util.formatRelativeDate(d.cached_at)
                + '</div>';
        }

        return html;
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Filters: Apply / Reset                                 ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Собрать значения фильтров и применить к grid.
     *
     * Обновляет baseParams grid'а и вызывает refresh.
     *
     * @return {void}
     */
    applyFilters: function () {
        var grid = Ext.getCmp('spookyapp-grid-topics');
        if (!grid) { return; }

        var store = grid.getStore();

        // ── Categories (checkboxgroup → массив значений) ─────
        var categories = this.getCheckboxGroupValues('spookyapp-filter-categories');
        store.baseParams.category = categories.join(',');

        // ── Sources ──────────────────────────────────────────
        var sources = this.getCheckboxGroupValues('spookyapp-filter-sources');
        store.baseParams.source = sources.join(',');

        // ── Min Score ────────────────────────────────────────
        var minScoreField = Ext.getCmp('spookyapp-filter-min-score');
        store.baseParams.min_score = minScoreField ? (minScoreField.getValue() || 0) : 0;

        // ── Date Range ───────────────────────────────────────
        var dateFrom = Ext.getCmp('spookyapp-filter-date-from');
        var dateTo = Ext.getCmp('spookyapp-filter-date-to');

        store.baseParams.date_from = dateFrom && dateFrom.getValue()
            ? dateFrom.getValue().format('Y-m-d')
            : '';
        store.baseParams.date_to = dateTo && dateTo.getValue()
            ? dateTo.getValue().format('Y-m-d')
            : '';

        // ── Сброс на первую страницу ─────────────────────────
        store.baseParams.page = 1;
        var paging = grid.getBottomToolbar();
        if (paging && paging.changePage) {
            paging.changePage(1);
        }

        grid.refresh();

        // Status message
        MODx.msg.status({
            title: _('spookyapp.success') || 'Success',
            message: _('spookyapp.filters.applied') || 'Filters applied',
            delay: 2
        });
    },

    /**
     * Сбросить все фильтры в начальное состояние.
     *
     * @return {void}
     */
    resetFilters: function () {
        // ── Reset form ───────────────────────────────────────
        var form = Ext.getCmp('spookyapp-filters-form');
        if (form && form.getForm) {
            form.getForm().reset();
        }

        // ── Reset grid baseParams ────────────────────────────
        var grid = Ext.getCmp('spookyapp-grid-topics');
        if (!grid) { return; }

        var store = grid.getStore();
        delete store.baseParams.category;
        delete store.baseParams.source;
        delete store.baseParams.min_score;
        delete store.baseParams.date_from;
        delete store.baseParams.date_to;
        delete store.baseParams.query;
        store.baseParams.page = 1;

        // ── Reset toolbar search ─────────────────────────────
        var searchField = Ext.getCmp('spookyapp-search-topics');
        if (searchField) {
            searchField.setValue('');
        }

        // ── Reset toolbar combo filters ──────────────────────
        var catCombo = Ext.getCmp('spookyapp-filter-category');
        if (catCombo) { catCombo.clearValue(); }

        var srcCombo = Ext.getCmp('spookyapp-filter-source');
        if (srcCombo) { srcCombo.clearValue(); }

        // ── Refresh ──────────────────────────────────────────
        var paging = grid.getBottomToolbar();
        if (paging && paging.changePage) {
            paging.changePage(1);
        }
        grid.refresh();

        // ── Clear details ────────────────────────────────────
        this.clearDetails();

        MODx.msg.status({
            title: _('spookyapp.success'),
            message: _('spookyapp.filters.reset'),
            delay: 2
        });
    },

    /**
     * Получить массив выбранных значений из CheckboxGroup.
     *
     * @param {string} cmpId ID компонента CheckboxGroup
     * @return {Array.<string>} Массив inputValue выбранных чекбоксов
     */
    getCheckboxGroupValues: function (cmpId) {
        var cmp = Ext.getCmp(cmpId);
        if (!cmp) { return []; }

        var values = [];
        var items = cmp.items;

        if (items && items.each) {
            items.each(function (cb) {
                if (cb.checked || (cb.getValue && cb.getValue())) {
                    values.push(cb.inputValue || cb.getRawValue());
                }
            });
        }

        return values;
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Details Panel: Button Handlers                         ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Включить/выключить кнопки details panel.
     *
     * @param {boolean} enabled Состояние кнопок
     * @return {void}
     */
    setDetailsButtons: function (enabled) {
        var ids = [
            'spookyapp-btn-rewrite',
            'spookyapp-btn-translate',
            'spookyapp-btn-search-news',
            'spookyapp-btn-save-draft',
            'spookyapp-btn-copy-url',
            'spookyapp-btn-copy-data',
            'spookyapp-btn-delete'
        ];

        Ext.each(ids, function (id) {
            var btn = Ext.getCmp(id);
            if (btn) {
                btn.setDisabled(!enabled);
            }
        });
    },

    /**
     * Очистить details panel (вернуть пустое состояние).
     *
     * @return {void}
     */
    clearDetails: function () {
        this.currentTopic = null;

        var panel = Ext.getCmp('spookyapp-details-panel');
        if (panel && panel.body) {
            panel.body.update(this.buildEmptyDetailsHtml());
        }

        this.setDetailsButtons(false);
    },

    /**
     * Rewrite with AI — открыть окно AI-переписывания.
     *
     * @return {void}
     */
    onRewriteWithAI: function () {
        if (!this.currentTopic || !this.currentTopic.id) { return; }

        if (!Ext.ComponentMgr.isRegistered('spookyapp-window-rewrite-ai')) {
            MODx.msg.alert(
                _('spookyapp.error') || 'Error',
                'AI Rewrite window is not registered yet.'
            );
            return;
        }

        var self = this;
        var win = MODx.load({
            xtype: 'spookyapp-window-rewrite-ai',
            record: this.currentTopic,
            listeners: {
                success: {
                    fn: function (evtData) {
                        // evtData = {f: form, a: action} from MODx.Window
                        var a      = evtData.a || {};
                        var result = (a && a.result) ? a.result : {};
                        var obj    = result.object || {};

                        // Inject the saved rewrite into currentTopic so the
                        // details panel shows it immediately without a grid reload
                        if (obj.rewrite && self.currentTopic) {
                            var meta = self.currentTopic.metadata;
                            if (!meta || typeof meta !== 'object') {
                                meta = {};
                            }
                            if (!meta._rewrites) {
                                meta._rewrites = {};
                            }
                            var mode = obj.mode || 'article';
                            meta._rewrites[mode] = Ext.apply(obj.rewrite, {
                                _generated_at: (new Date()).toISOString().replace('T', ' ').substr(0, 19),
                                _params: { mode: mode }
                            });
                            self.currentTopic.metadata = meta;
                            self.updateDetails(self.currentTopic);
                        }

                        // Also refresh the grid so the topic row shows updated status
                        var grid = Ext.getCmp('spookyapp-grid-topics');
                        if (grid) { grid.refresh(); }
                    },
                    scope: this
                }
            }
        });
        win.show();
    },

    /**
     * Save as Draft — создать MODX ресурс-черновик.
     *
     * @return {void}
     */
    onSaveAsDraft: function () {
        if (!this.currentTopic || !this.currentTopic.id) { return; }

        var topic = this.currentTopic;

        MODx.msg.confirm({
            title: _('spookyapp.topic.save_draft') || 'Save as Draft',
            text: (_('spookyapp.topic.save_draft_confirm') || 'Create a draft resource from')
                + ' "' + Ext.util.Format.ellipsis(topic.title || '', 60) + '"?',
            url: SpookyApp.config.connector_url,
            params: {
                action: 'topicfinder/savethemes',
                topics: Ext.encode([topic.id])
            },
            listeners: {
                success: {
                    fn: function (r) {
                        MODx.msg.status({
                            title: _('spookyapp.success') || 'Success',
                            message: _('spookyapp.topic.draft_created') || 'Draft created',
                            delay: 3
                        });
                        var grid = Ext.getCmp('spookyapp-grid-topics');
                        if (grid) { grid.refresh(); }
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Copy URL — скопировать URL темы в буфер обмена.
     *
     * @return {void}
     */
    onCopyUrl: function () {
        if (!this.currentTopic || !this.currentTopic.url) {
            MODx.msg.alert(
                _('spookyapp.error') || 'Error',
                _('spookyapp.details.no_url') || 'No URL available for this topic.'
            );
            return;
        }

        var url = this.currentTopic.url;

        // Clipboard API (modern browsers)
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                MODx.msg.status({
                    title: _('spookyapp.success') || 'Success',
                    message: _('spookyapp.details.url_copied') || 'URL copied to clipboard',
                    delay: 2
                });
            })['catch'](function () {
                // Fallback
                SpookyApp.panel.TopicFinderTrends.fallbackCopyText(url);
            });
        } else {
            SpookyApp.panel.TopicFinderTrends.fallbackCopyText(url);
        }
    },

    /**
     * Copy Data — скопировать title + description + source URL.
     *
     * @return {void}
     */
    onCopyData: function () {
        if (!this.currentTopic) {
            MODx.msg.alert(_('spookyapp.error') || 'Error', 'No topic selected.');
            return;
        }
        var t = this.currentTopic;
        var parts = [];
        if (t.title)       { parts.push(t.title); }
        if (t.description) { parts.push(''); parts.push(t.description); }
        if (t.url)         { parts.push(''); parts.push('Source: ' + t.url); }
        var text = parts.join('\n');
        var onSuccess = function () {
            MODx.msg.status({
                title: _('spookyapp.success') || 'Success',
                message: _('spookyapp.copied') || 'Copied to clipboard',
                delay: 2
            });
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onSuccess)['catch'](function () {
                SpookyApp.panel.TopicFinderTrends.fallbackCopyText(text);
            });
        } else {
            SpookyApp.panel.TopicFinderTrends.fallbackCopyText(text);
        }
    },

    /**
     * Copy the AI rewrite text — called from inline onclick in the details panel.
     * Uses SpookyApp._tfPanel (the live panel instance) set during initComponent.
     */
    doCopyRewrite: function () {
        var t = this.currentTopic;
        if (!t) { return; }
        var meta = t.metadata;
        var rewrites = (meta && typeof meta === 'object') ? (meta._rewrites || null) : null;
        if (!rewrites) { return; }

        var modes = [];
        Ext.iterate(rewrites, function (k) { modes.push(k); });
        var mode = t._activeRewriteMode || (rewrites['article'] ? 'article' : modes[0]);
        var rw   = rewrites[mode] || {};

        var parts = [];
        if (rw.title)            { parts.push(rw.title); }
        if (rw.lead)             { parts.push(''); parts.push(rw.lead); }
        if (rw.content) {
            var plain = String(rw.content).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            parts.push(''); parts.push(plain);
        }
        if (rw.meta_description) { parts.push(''); parts.push(rw.meta_description); }

        var text = parts.join('\n');
        var onSuccess = function () {
            MODx.msg.status({
                title: _('spookyapp.success') || 'Success',
                message: _('spookyapp.copied') || 'Рерайт скопирован',
                delay: 2
            });
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onSuccess)['catch'](function () {
                SpookyApp.panel.TopicFinderTrends.fallbackCopyText(text);
            });
        } else {
            SpookyApp.panel.TopicFinderTrends.fallbackCopyText(text);
        }
    },

    /**
     * Switch the displayed rewrite mode — called from inline onclick.
     */
    switchRewriteMode: function (mode) {
        if (!this.currentTopic) { return; }
        var meta = this.currentTopic.metadata;
        var rewrites = (meta && typeof meta === 'object') ? (meta._rewrites || null) : null;
        if (!rewrites || !rewrites[mode]) { return; }
        this.currentTopic._activeRewriteMode = mode;
        this.updateDetails(this.currentTopic);
    },

    /**
     * Delete — удалить тему.
     *
     * @return {void}
     */
    onDeleteTopic: function () {
        if (!this.currentTopic || !this.currentTopic.id) { return; }

        var topic = this.currentTopic;
        var self = this;

        MODx.msg.confirm({
            title: _('spookyapp.topic.delete') || 'Delete',
            text: (_('spookyapp.topic.delete_confirm') || 'Are you sure you want to delete')
                + ' "' + Ext.util.Format.ellipsis(topic.title || '', 60) + '"?',
            url: SpookyApp.config.connector_url,
            params: {
                action: 'topicfinder/delete',
                id: topic.id
            },
            listeners: {
                success: {
                    fn: function () {
                        MODx.msg.status({
                            title: _('spookyapp.success') || 'Success',
                            message: _('spookyapp.topic.deleted') || 'Topic deleted',
                            delay: 3
                        });
                        self.clearDetails();
                        var grid = Ext.getCmp('spookyapp-grid-topics');
                        if (grid) { grid.refresh(); }
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Перевести тему (title + description) через Яндекс Переводчик.
     *
     * @return {void}
     */
    onTranslate: function () {
        if (!this.currentTopic || !this.currentTopic.id) { return; }

        var topic = this.currentTopic;
        var btn = Ext.getCmp('spookyapp-btn-translate');
        var prevText = btn ? btn.getText() : null;

        if (btn) {
            btn.setDisabled(true);
            btn.setText('...');
        }

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'topicfinder/translate',
                id: topic.id
            },
            listeners: {
                success: {
                    fn: function (r) {
                        if (btn) {
                            btn.setDisabled(false);
                            btn.setText(prevText || _('spookyapp.btn.translate') || 'Перевести');
                        }
                        var updated = r.object || {};
                        if (updated.title)       { this.currentTopic.title       = updated.title; }
                        if (updated.description) { this.currentTopic.description = updated.description; }
                        this.updateDetails(this.currentTopic);
                        MODx.msg.status({
                            title:   _('spookyapp.success') || 'Success',
                            message: _('spookyapp.topic.translated') || 'Переведено',
                            delay:   2
                        });
                    },
                    scope: this
                },
                failure: {
                    fn: function (r) {
                        if (btn) {
                            btn.setDisabled(false);
                            btn.setText(prevText || _('spookyapp.btn.translate') || 'Перевести');
                        }
                        MODx.msg.alert(
                            _('spookyapp.error') || 'Error',
                            r.message || 'Translation failed'
                        );
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Search News — найти новости по теме в NewsAPI.
     *
     * @return {void}
     */
    onSearchNews: function () {
        if (!this.currentTopic || !this.currentTopic.id) { return; }

        var topic = this.currentTopic;
        var query = topic.title || '';

        var btn = Ext.getCmp('spookyapp-btn-search-news');
        if (btn) { btn.setDisabled(true); }

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'topicfinder/searchnews',
                query:  query,
                limit:  10
            },
            listeners: {
                success: {
                    fn: function (r) {
                        if (btn) { btn.setDisabled(false); }
                        var results = (r.object && r.object.results) ? r.object.results : [];
                        if (!results.length) {
                            MODx.msg.alert(
                                _('spookyapp.search_news.title') || 'News Search',
                                _('spookyapp.search_news.no_results') || 'No news found for: ' + query
                            );
                            return;
                        }
                        var html = '<div style="max-height:400px;overflow-y:auto;">';
                        Ext.each(results, function (item) {
                            var url   = item.url || '#';
                            var title = Ext.util.Format.htmlEncode(item.title || '');
                            var src   = Ext.util.Format.htmlEncode(item.source || '');
                            html += '<div style="margin-bottom:10px;border-bottom:1px solid #ddd;padding-bottom:8px;">';
                            html += '<a href="' + url + '" target="_blank" style="font-weight:bold;">' + title + '</a>';
                            if (src) { html += '<br/><small style="color:#888;">' + src + '</small>'; }
                            html += '</div>';
                        });
                        html += '</div>';
                        Ext.Msg.show({
                            title:   (_('spookyapp.search_news.title') || 'News Search') + ': ' + Ext.util.Format.ellipsis(query, 40),
                            msg:     html,
                            buttons: Ext.Msg.OK,
                            icon:    Ext.Msg.INFO,
                            width:   520
                        });
                    },
                    scope: this
                },
                failure: {
                    fn: function (r) {
                        if (btn) { btn.setDisabled(false); }
                        MODx.msg.alert(
                            _('spookyapp.error') || 'Error',
                            r.message || 'News search failed'
                        );
                    },
                    scope: this
                }
            }
        });
    }
});

// ╔═════════════════════════════════════════════════════════════╗
// ║  Static Helpers                                             ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Fallback copy-to-clipboard через скрытый textarea.
 *
 * @param {string} text Текст для копирования
 * @static
 */
SpookyApp.panel.TopicFinderTrends.fallbackCopyText = function (text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        MODx.msg.status({
            title: _('spookyapp.success') || 'Success',
            message: _('spookyapp.details.url_copied') || 'URL copied to clipboard',
            delay: 2
        });
    } catch (e) {
        MODx.msg.alert(
            _('spookyapp.error') || 'Error',
            'Failed to copy. Please copy manually: ' + text
        );
    }
    document.body.removeChild(ta);
};

// ── Регистрация Trends (внутренняя вкладка) ────────────────
Ext.reg('spookyapp-panel-topicfinder-trends', SpookyApp.panel.TopicFinderTrends);

// ╔═════════════════════════════════════════════════════════════╗
// ║  TopicFinder — Scoring Tab                                  ║
// ╚═════════════════════════════════════════════════════════════╝

SpookyApp.panel.TopicFinderScoring = function (config) {
    config = config || {};
    var me = this;

    this.scoreStore = new Ext.data.JsonStore({
        url: SpookyApp.config.connector_url,
        baseParams: {action: 'topicfinder/getlist', limit: 100, start: 0, sort: 'score', dir: 'DESC'},
        root: 'results', totalProperty: 'total', idProperty: 'id',
        fields: [
            {name: 'id', type: 'int'}, {name: 'title', type: 'string'},
            {name: 'source', type: 'string'}, {name: 'score', type: 'float'},
            {name: 'new_score', type: 'float'}, {name: 'delta', type: 'float'}
        ],
        autoLoad: false
    });

    Ext.applyIf(config, {
        id: 'spookyapp-panel-scoring',
        layout: 'fit',
        border: false,
        items: [{
            xtype: 'grid',
            id: 'spookyapp-scoring-grid',
            store: this.scoreStore,
            stripeRows: true, border: false, loadMask: true, autoExpandColumn: 'score-title',
            sm: new Ext.grid.RowSelectionModel(),
            columns: [
                {header: '#', dataIndex: 'id', width: 45, sortable: true},
                {id: 'score-title', header: _('spookyapp.topic.title') || 'Тема', dataIndex: 'title', sortable: true,
                    renderer: function(v) { return '<span title="' + Ext.util.Format.htmlEncode(v||'') + '">' + Ext.util.Format.ellipsis(v||'', 60) + '</span>'; }},
                {header: _('spookyapp.topic.source') || 'Источник', dataIndex: 'source', width: 100, sortable: true},
                {header: _('spookyapp.topic.score') || 'Score', dataIndex: 'score', width: 75, sortable: true,
                    renderer: function(v) {
                        if (!v) { return '0.0'; }
                        var c = v >= 70 ? '#388e3c' : (v >= 40 ? '#f57c00' : '#d32f2f');
                        return '<b style="color:' + c + ';">' + parseFloat(v).toFixed(1) + '</b>';
                    }},
                {header: _('spookyapp.scoring.new_score') || 'New', dataIndex: 'new_score', width: 75,
                    renderer: function(v) {
                        if (!v) { return '<span style="color:#ccc;">&mdash;</span>'; }
                        var c = v >= 70 ? '#388e3c' : (v >= 40 ? '#f57c00' : '#d32f2f');
                        return '<b style="color:' + c + ';">' + parseFloat(v).toFixed(1) + '</b>';
                    }},
                {header: '\u0394', dataIndex: 'delta', width: 65,
                    renderer: function(v) {
                        if (v === null || v === undefined || v === '') { return '<span style="color:#ccc;">&mdash;</span>'; }
                        var c = v > 0 ? '#388e3c' : (v < 0 ? '#d32f2f' : '#888');
                        return '<span style="color:' + c + ';">' + (v > 0 ? '+' : '') + parseFloat(v).toFixed(2) + '</span>';
                    }}
            ],
            viewConfig: {
                forceFit: true,
                emptyText: '<div style="text-align:center;padding:30px;color:#999;">' + (_('spookyapp.scoring.empty') || 'Загрузите темы') + '</div>'
            },
            tbar: [
                {
                    text: '<i class="fas fa-sync-alt"></i> ' + (_('spookyapp.scoring.recalc_all') || 'Пересчитать все'),
                    handler: function() { me.onRecalcAll(); }
                },
                '-',
                {
                    text: '<i class="fas fa-search"></i> ' + (_('spookyapp.btn.refresh') || 'Загрузить темы'),
                    handler: function() { me.scoreStore.load(); }
                }
            ]
        }]
    });

    SpookyApp.panel.TopicFinderScoring.superclass.constructor.call(this, config);
    this.on('afterrender', function() {
        console.log('[SpookyApp] Scoring: store.load() triggered on afterrender');
        this.scoreStore.load();
    }, this, {single: true});
};

Ext.extend(SpookyApp.panel.TopicFinderScoring, Ext.Panel, {
    onRecalcAll: function () {
        var me = this;
        var grid = Ext.getCmp('spookyapp-scoring-grid');
        console.log('[SpookyApp] Scoring: onRecalcAll triggered, grid=', grid);
        if (grid && grid.loadMask) { grid.loadMask.show(); }
        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {action: 'topicfinder/scoring'},
            listeners: {
                success: {
                    fn: function (r) {
                        console.log('[SpookyApp] Scoring: success response', r);
                        if (grid && grid.loadMask) { grid.loadMask.hide(); }
                        var results = (r.object && r.object.results) ? r.object.results : [];
                        console.log('[SpookyApp] Scoring: results count=', results.length);
                        var store = me.scoreStore;
                        Ext.each(results, function(res) {
                            var rec = store.getById(res.id);
                            if (rec) { rec.set('new_score', res.new_score); rec.set('delta', res.delta); rec.commit(); }
                        });
                        MODx.msg.status({message: (_('spookyapp.scoring.done') || '\u041e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u043e: ') + results.length, delay: 3});
                    }
                },
                failure: {
                    fn: function (r) {
                        console.error('[SpookyApp] Scoring: failure response', r);
                        if (grid && grid.loadMask) { grid.loadMask.hide(); }
                        MODx.msg.alert(_('spookyapp.error') || 'Error', r.message || 'Failed');
                    }
                }
            }
        });
    }
});

Ext.reg('spookyapp-panel-topicfinder-scoring', SpookyApp.panel.TopicFinderScoring);

// ╔═════════════════════════════════════════════════════════════╗
// ║  TopicFinder — AI Ideas Tab                                 ║
// ╚═════════════════════════════════════════════════════════════╝

SpookyApp.panel.TopicFinderAIIdeas = function (config) {
    config = config || {};
    var me = this;

    this.ideasStore = new Ext.data.JsonStore({
        root: 'results', totalProperty: 'total', idProperty: 'id',
        fields: [
            {name: 'id', type: 'int'}, {name: 'title', type: 'string'},
            {name: 'description', type: 'string'}, {name: 'category', type: 'string'},
            {name: 'score', type: 'float'}
        ],
        data: {results: [], total: 0}
    });

    Ext.applyIf(config, {
        id: 'spookyapp-panel-ai-ideas',
        layout: 'border',
        border: false,
        items: [
            {
                region: 'west',
                id: 'spookyapp-ai-ideas-settings',
                width: 220, minWidth: 180, maxWidth: 280,
                title: _('spookyapp.ai_ideas.settings') || '\u041d\u0430\u0441\u0442\u0440\u043e\u0439\u043a\u0438',
                split: true, border: true, autoScroll: true,
                bodyStyle: 'padding: 10px;',
                items: [
                    {
                        xtype: 'numberfield',
                        fieldLabel: _('spookyapp.ai_ideas.count') || '\u041a\u043e\u043b-\u0432\u043e',
                        id: 'spookyapp-ai-count', name: 'count', value: 10, minValue: 5, maxValue: 20, anchor: '100%'
                    },
                    {
                        xtype: 'checkboxgroup',
                        fieldLabel: _('spookyapp.ai_ideas.categories') || '\u041a\u0430\u0442\u0435\u0433\u043e\u0440\u0438\u0438',
                        id: 'spookyapp-ai-categories', columns: 2, style: 'margin-top:6px;',
                        items: [
                            {boxLabel: 'IT', inputValue: 'IT', checked: true},
                            {boxLabel: 'Gadgets', inputValue: 'Gadgets'},
                            {boxLabel: 'Entertainment', inputValue: 'Entertainment'},
                            {boxLabel: 'Gaming', inputValue: 'Gaming'},
                            {boxLabel: 'Sports', inputValue: 'Sports'},
                            {boxLabel: 'DIY', inputValue: 'DIY'},
                            {boxLabel: _('spookyapp.ai_ideas.cat_home') || 'Дача & Дом', inputValue: 'Home'},
                            {boxLabel: _('spookyapp.ai_ideas.cat_software') || 'Программы', inputValue: 'Software'},
                            {boxLabel: _('spookyapp.ai_ideas.cat_personal') || 'Личное', inputValue: 'Personal'}
                        ]
                    },
                    {
                        xtype: 'checkbox',
                        boxLabel: _('spookyapp.ai_ideas.use_trends') || '\u0423\u0447\u0435\u0441\u0442\u044c \u0442\u0440\u0435\u043d\u0434\u044b',
                        id: 'spookyapp-ai-use-trends', name: 'use_trends', checked: true, style: 'margin-top:8px;'
                    }
                ],
                bbar: [{
                    text: '<i class="fas fa-magic"></i> ' + (_('spookyapp.ai_ideas.generate') || '\u0413\u0435\u043d\u0435\u0440\u0438\u0440\u043e\u0432\u0430\u0442\u044c'),
                    cls: 'primary-button',
                    handler: function() { me.onGenerateIdeas(); }
                }]
            },
            {
                region: 'center',
                xtype: 'grid',
                id: 'spookyapp-ai-ideas-grid',
                store: this.ideasStore,
                stripeRows: true, border: true, loadMask: true, autoExpandColumn: 'ideas-title',
                sm: new Ext.grid.RowSelectionModel(),
                columns: [
                    {
                        id: 'ideas-title',
                        header: _('spookyapp.topic.title') || '\u0418\u0434\u0435\u044f',
                        dataIndex: 'title', sortable: true,
                        renderer: function(v) {
                            return '<span title="' + Ext.util.Format.htmlEncode(v||'') + '">' + Ext.util.Format.ellipsis(v||'', 70) + '</span>';
                        }
                    },
                    {header: _('spookyapp.topic.category') || '\u041a\u0430\u0442\u0435\u0433\u043e\u0440\u0438\u044f', dataIndex: 'category', width: 110, sortable: true},
                    {
                        header: _('spookyapp.topic.score') || 'Score', dataIndex: 'score', width: 65, sortable: true,
                        renderer: function(v) {
                            if (!v) { return '\u2014'; }
                            var c = v >= 70 ? '#388e3c' : (v >= 40 ? '#f57c00' : '#d32f2f');
                            return '<b style="color:' + c + ';">' + parseFloat(v).toFixed(1) + '</b>';
                        }
                    }
                ],
                viewConfig: {
                    forceFit: true,
                    emptyText: '<div style="text-align:center;padding:30px;color:#999;">' + (_('spookyapp.ai_ideas.empty') || '\u041d\u0430\u0436\u043c\u0438\u0442\u0435 \u00ab\u0413\u0435\u043d\u0435\u0440\u0438\u0440\u043e\u0432\u0430\u0442\u044c\u00bb') + '</div>'
                },
                bbar: [
                    '->',
                    {
                        text: '<i class="fas fa-save"></i> ' + (_('spookyapp.ai_ideas.save_all') || '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0432 \u0442\u0435\u043c\u044b'),
                        id: 'spookyapp-ai-save-all', disabled: true,
                        handler: function() { me.onSaveAllIdeas(); }
                    }
                ]
            }
        ]
    });

    SpookyApp.panel.TopicFinderAIIdeas.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.panel.TopicFinderAIIdeas, Ext.Panel, {
    onGenerateIdeas: function () {
        var me = this;
        var countField = Ext.getCmp('spookyapp-ai-count');
        var count = countField ? (parseInt(countField.getValue()) || 10) : 10;
        var catGroup = Ext.getCmp('spookyapp-ai-categories');
        var categories = [];
        if (catGroup && catGroup.items && catGroup.items.each) {
            catGroup.items.each(function(cb) { if (cb.getValue()) { categories.push(cb.inputValue); } });
        }
        var useTrendsCb = Ext.getCmp('spookyapp-ai-use-trends');
        var useTrends = useTrendsCb ? (useTrendsCb.getValue() ? 1 : 0) : 1;
        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {action: 'topicfinder/generateideas', count: count, categories: categories.join(','), use_trends: useTrends},
            listeners: {
                success: {
                    fn: function (r) {
                        var ideas = (r.object && r.object.ideas) ? r.object.ideas : [];
                        me.ideasStore.loadData({results: ideas, total: ideas.length});
                        var saveBtn = Ext.getCmp('spookyapp-ai-save-all');
                        if (saveBtn) { saveBtn.setDisabled(ideas.length === 0); }
                        MODx.msg.status({message: (_('spookyapp.ai_ideas.generated') || '\u0421\u0433\u0435\u043d\u0435\u0440\u0438\u0440\u043e\u0432\u0430\u043d\u043e: ') + ideas.length, delay: 3});
                    }
                },
                failure: {fn: function (r) { MODx.msg.alert(_('spookyapp.error') || 'Error', r.message || 'Failed'); }}
            }
        });
    },
    onSaveAllIdeas: function () {
        var me = this;
        var records = this.ideasStore.getRange();
        if (!records.length) { return; }
        var ideas = [];
        Ext.each(records, function(r) {
            ideas.push({title: r.get('title'), description: r.get('description'), category: r.get('category'), score: r.get('score')});
        });
        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {action: 'topicfinder/generateideas', ideas: Ext.encode(ideas), save_only: 1},
            listeners: {
                success: {fn: function () { MODx.msg.status({message: (_('spookyapp.ai_ideas.saved') || '\u0421\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u043e: ') + ideas.length, delay: 3}); }},
                failure: {fn: function (r) { MODx.msg.alert(_('spookyapp.error') || 'Error', r.message || 'Failed'); }}
            }
        });
    }
});

Ext.reg('spookyapp-panel-topicfinder-ideas', SpookyApp.panel.TopicFinderAIIdeas);

// ╔═════════════════════════════════════════════════════════════╗
// ║  TopicFinder — Tab Panel (wrapper: 3 вкладки)              ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Главная панель TopicFinder.
 * 3 вкладки: Тренды | Скоринг | AI Идеи
 *
 * @class SpookyApp.panel.TopicFinder
 * @extends Ext.TabPanel
 * @xtype spookyapp-panel-topicfinder
 */
SpookyApp.panel.TopicFinder = function (config) {
    config = config || {};

    Ext.applyIf(config, {
        id: 'spookyapp-panel-topicfinder',
        border: false,
        deferredRender: true,
        enableTabScroll: false,
        activeTab: 0,
        items: [
            {
                title: _('spookyapp.tab.trends') || '\u0422\u0440\u0435\u043d\u0434\u044b',
                id: 'spookyapp-tab-trends',
                layout: 'fit',
                border: false,
                items: [{xtype: 'spookyapp-panel-topicfinder-trends'}]
            },
            {
                title: _('spookyapp.tab.scoring') || '\u0421\u043a\u043e\u0440\u0438\u043d\u0433',
                id: 'spookyapp-tab-scoring',
                layout: 'fit',
                border: false,
                items: [{xtype: 'spookyapp-panel-topicfinder-scoring'}]
            },
            {
                title: _('spookyapp.tab.ai_ideas') || 'AI \u0418\u0434\u0435\u0438',
                id: 'spookyapp-tab-ai-ideas',
                layout: 'fit',
                border: false,
                items: [{xtype: 'spookyapp-panel-topicfinder-ideas'}]
            }
        ]
    });

    SpookyApp.panel.TopicFinder.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.panel.TopicFinder, Ext.TabPanel, {});

// ── Регистрация ExtJS xtype ─────────────────────────────────
Ext.reg('spookyapp-panel-topicfinder', SpookyApp.panel.TopicFinder);