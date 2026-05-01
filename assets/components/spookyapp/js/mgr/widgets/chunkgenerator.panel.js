// ╔═════════════════════════════════════════════════════════════╗
// ║  ChunkGenerator — Main Panel (border layout)               ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Главная панель Chunk Generator.
 * Левая колонка — список сохранённых чанков из БД.
 * Правая колонка — переключатель типов + поиск/результаты/детали.
 *
 * @class SpookyApp.panel.ChunkGenerator
 * @extends Ext.Panel
 * @xtype spookyapp-panel-chunkgenerator
 */
SpookyApp.panel.ChunkGenerator = function(config) {
    config = config || {};
    var me = this;

    // ── Типы контента ────────────────────────────────────────
    this.typeConfigs = [
        {type: 'cinema',   text: '<i class="fas fa-film"></i>' + _('spookyapp.chunkgenerator.tab_cinema'), 
            visibleColumns: ['id', 'poster', 'title', 'original_title', 'year', 'rating', 'vote_count'],
            detailOptions: ['cast', 'crew', 'screenshots', 'similar', 'seasons', 'images'],
            showYearField: true,
            subtypes: [
                {val: 'movie',  lbl: 'TMDB Фильмы'},
                {val: 'tv',     lbl: 'TMDB Сериалы'},
                {val: 'person', lbl: 'TMDB Персоны'}
            ]},
        {type: 'game',     text: '<i class="fas fa-gamepad"></i> ' + _('spookyapp.chunkgenerator.tab_games'),    
            visibleColumns: ['id', 'poster', 'title', 'year', 'rating', 'vote_count'],
            detailOptions: ['screenshots', 'similar']},
        {type: 'device',   text: '<i class="fas fa-mobile-alt"></i> ' + _('spookyapp.chunkgenerator.tab_devices'),  
            visibleColumns: ['id', 'poster', 'title', 'overview'],
            detailOptions: [],
            sourceOptions: [
                {val: 'rapidapi', lbl: 'RapidAPI (mobile-phones2)'},
                {val: 'mobileapi', lbl: 'MobileApi.dev'}
            ]},
        {type: 'product',  text: '<i class="fas fa-tag"></i> ' + _('spookyapp.chunkgenerator.tab_products'),
            visibleColumns: ['poster', 'title', 'rating', 'price'],
            detailOptions: ['offers'],
            showCountryField: true,
            sourceOptions: [
                {val: 'amazon',   lbl: 'Amazon'},
                {val: 'realtime', lbl: 'Real-Time Product Search'}
            ]},
        {type: 'sport',    text: '<i class="fas fa-futbol"></i> ' + _('spookyapp.chunkgenerator.tab_sport'),
            visibleColumns: ['id', 'title', 'year', 'overview'],
            detailOptions: [],
            subtypes: [
                {val: 'sportapi',   lbl: 'SportAPI7'},
                {val: 'football',   lbl: 'Football-API'},
                {val: 'biathlon',   lbl: 'Biathlon IBU'},
                {val: 'flashsport', lbl: 'Flash Sports'}
            ],
            flashSportOptions: {
                sports: [
                    {val: 1,  lbl: 'Футбол'},
                    {val: 4,  lbl: 'Хоккей'},
                    {val: 12, lbl: 'Волейбол'},
                    {val: 26, lbl: 'Пляжный футбол'},
                    {val: 36, lbl: 'Киберспорт'},
                    {val: 37, lbl: 'Зимние виды'},
                    {val: 41, lbl: 'Биатлон'}
                ],
                modes: [
                    {val: 'match',     lbl: 'Матчи'},
                    {val: 'standings', lbl: 'Таблица'},
                    {val: 'schedule',  lbl: 'Расписание'}
                ]            },
            sportApiOptions: {
                sports: [
                    {val: 'football',   lbl: 'Футбол'},
                    {val: 'ice-hockey', lbl: 'Хоккей'},
                    {val: 'volleyball', lbl: 'Волейбол'},
                    {val: 'esports',    lbl: 'Киберспорт'},
                    {val: 'olympics',   lbl: 'Олимпиада'}
                ]            },
            sportApiOptions: {
                sports: [
                    {val: 'football',   lbl: 'Футбол'},
                    {val: 'ice-hockey', lbl: 'Хоккей'},
                    {val: 'volleyball', lbl: 'Волейбол'},
                    {val: 'esports',    lbl: 'Киберспорт'},
                    {val: 'olympics',   lbl: 'Олимпиада'}
                ]
            }},
        {type: 'github',   text: '<i class="fab fa-github"></i> ' + _('spookyapp.chunkgenerator.tab_github'),
            visibleColumns: ['id', 'title', 'overview', 'rating'],
            detailOptions: []}
    ];
    this.activeType = 'cinema';

    // ── Store сохранённых чанков ─────────────────────────────
    this.savedStore = new Ext.data.JsonStore({
        url: SpookyApp.config.connector_url,
        baseParams: {action: 'chunkgenerator/getlist', limit: 100, sort: 'created_at', dir: 'DESC'},
        root: 'results', totalProperty: 'total', idProperty: 'id',
        fields: [
            {name: 'id',          type: 'int'},
            {name: 'type',        type: 'string'},
            {name: 'external_id', type: 'string'},
            {name: 'title',       type: 'string'},
            {name: 'created_at',  type: 'string'}
        ],
        autoLoad: false
    });

    Ext.applyIf(config, {
        id: 'spookyapp-panel-chunkgenerator',
        layout: 'border',
        border: false,
        items: [
            me.buildWestPanel(),
            me.buildCenterPanel()
        ]
    });

    SpookyApp.panel.ChunkGenerator.superclass.constructor.call(this, config);
    this.on('afterrender', function() { me.savedStore.load(); }, this, {single: true});
};

Ext.extend(SpookyApp.panel.ChunkGenerator, Ext.Panel, {

    // ── Левая панель: список сохранённых чанков ──────────────

    buildWestPanel: function () {
        var me = this;
          return {
              region: 'west',
              id: 'spookyapp-cg-saved-panel',
              title: _('spookyapp.chunkgenerator.saved_chunks') || 'Сохранённые чанки',
              width: 280, minWidth: 280, maxWidth: 340,
              split: true, border: true, autoScroll: false,
              layout: 'border',
              items: [
                      {
                      region: 'north',
                      border: false,
                      xtype: 'toolbar',

                  items: [
                          {
                              xtype: 'combo', id: 'spookyapp-cg-saved-type-filter',
                               emptyText: 'Выбрать тип',
                              mode: 'local', triggerAction: 'all', editable: false, width: 160,
                              store: new Ext.data.ArrayStore({
                                  fields: ['val', 'lbl'],
                                  data: [
                                      ['',          'Все'],
                                      ['movie',     'Фильмы'],
                                      ['tv',        'Сериалы'],
                                      ['person',    'Персоны'],
                                      ['game',      'Игры'],
                                      ['device',    'Устройства'],
                                      ['product',   'Продукты (Amazon)'],
                                      ['football',  'Футбол'],
                                      ['biathlon',  'Биатлон'],
                                      ['github',    'GitHub']
                                  ]
                              }),
                          valueField: 'val',
                          displayField: 'lbl',
                              listeners: {             
                                    afterrender: function(combo) {
                                    combo.wrap.setWidth(156);  
                                    combo.el.setWidth(140);     
                                  },
                                  select: {fn: function(c, rec) {
                                      var t = rec.get('val');
                                      if (t) {
                                          me.savedStore.baseParams.type = t;
                                      } else {
                                          delete me.savedStore.baseParams.type;
                                      }
                                      me.savedStore.load();
                                  }}
                              }
                        },
                          {
                            xtype: 'tbspacer',
                            width: 5
                        },
                          {
                              xtype: 'button',
                            width: 100,
                              text: _('spookyapp.btn.refresh'),
                              handler: function() { me.savedStore.load(); }
                        },
                          '->'
                      ]
                  },
                  {
                      region: 'center',
                      xtype: 'grid',
                      id: 'spookyapp-cg-saved-grid',
                      store: this.savedStore,
                      stripeRows: true, border: false, loadMask: true,
                      autoExpandColumn: 'saved-title',
                      sm: new Ext.grid.RowSelectionModel({singleSelect: true}),
                      columns: [
                          {
                              id: 'saved-title',
                              header: _('spookyapp.topic.title') || 'Заголовок',
                              dataIndex: 'title', sortable: true,
                              renderer: function(v) {
                                  return '<span title="' + Ext.util.Format.htmlEncode(v||'') + '">' +
                                        Ext.util.Format.ellipsis(v||'', 26) + '</span>';
                              }
                          },
                          {
                              header: _('spookyapp.type') || 'Тип', dataIndex: 'type', width: 55, sortable: true
                          },
                          {
                              header: 'Действия',
                              width: 100,
                              sortable: false,
                              menuDisabled: true,
                              renderer: function(v, meta, record) {
                                  return '<button class="spookyapp-cg-saved-btn-details" '
                                      + 'style="margin:1px 1px 1px 0;padding:0 4px;font-size:11px;cursor:pointer; width: 25px; height: 20px;" '
                                      + 'title="' + (_('spookyapp.chunkgenerator.view_details') || 'Детали') + '"><i class="far fa-comment-alt"></i></button>'
                                      + '<button class="spookyapp-cg-saved-btn-delete" '
                                      + 'style="margin:1px 2px;padding:0 5px;font-size:11px;cursor:pointer;background:#d9534f;color:#fff;border:1px solid #c9302c;border-radius:2px; width: 25px; height: 20px;" '
                                      + 'title="' + (_('spookyapp.btn.delete') || 'Удалить') + '"><i class="fas fa-trash-alt"></i></button>';
                              }
                          }
                      ],
                      viewConfig: {
                          forceFit: true,
                          emptyText: '<div style="text-align:center;padding:20px;color:#999;">' +
                              (_('spookyapp.chunkgenerator.no_saved') || 'Нет сохранённых') + '</div>'
                      },
                      listeners: {
                          rowdblclick: {
                              fn: function(grid, rowIndex) {
                                  var rec = grid.getStore().getAt(rowIndex);
                                  if (rec) { me.openSavedChunkDetails(rec); }
                              }
                          },
                          cellclick: {
                              fn: function(grid, rowIndex, colIndex, e) {
                                  var rec  = grid.getStore().getAt(rowIndex);
                                  if (!rec) { return; }
                                  var t = e.getTarget('.spookyapp-cg-saved-btn-details');
                                  if (t) {
                                      me.openSavedChunkDetails(rec);
                                      return;
                                  }
                                  var d = e.getTarget('.spookyapp-cg-saved-btn-delete');
                                  if (d) {
                                      me.deleteSavedChunk(rec);
                                  }
                              }
                          }
                      }
                  }
              ]
          };
    },

    // ── Центральная панель: type toolbar + cards ─────────────

    buildCenterPanel: function () {
        var me = this;
        return {
            region: 'center',
            id: 'spookyapp-cg-center',
            layout: 'border',
            border: false,
            items: [
                me.buildTypeBar(),
                me.buildCardContainer()
            ]
        };
    },

    buildTypeBar: function () {
        var me = this;
        var buttons = [];
        Ext.each(this.typeConfigs, function(cfg, idx) {
            buttons.push({
                text: cfg.text,
                enableToggle: true,
                toggleGroup: 'spookyapp-cg-type',
                pressed: idx === 0,
                allowDepress: false,
                _typeKey: cfg.type,
                cls: idx === 0 ? 'spookyapp-tab-active' : '',
                handler: function() { me.switchType(cfg.type); }
            });
            if (idx < me.typeConfigs.length - 1) { buttons.push('-'); }
        });
        return {
            region: 'north',
            height: 36,
            border: false,
            bodyCssClass: 'x-plain',
            bodyStyle: 'padding:4px 8px; background:#f0f0f0; border-bottom:1px solid #d0d0d0;',
            xtype: 'toolbar',
            id: 'spookyapp-cg-type-bar',
            items: buttons
        };
    },

    buildCardContainer: function () {
        var me = this;
        var cards = [];
        Ext.each(this.typeConfigs, function(cfg) {
            cards.push(me.buildTypeCard(cfg));
        });
        return {
            region: 'center',
            xtype: 'panel',
            id: 'spookyapp-cg-cards',
            layout: 'card',
            activeItem: 0,
            border: false,
            items: cards
        };
    },

    buildTypeCard: function (cfg) {
        var me = this;
        var cardId = 'spookyapp-cg-card-' + cfg.type;
        var searchId = cardId + '-search';
        var gridId   = cardId + '-grid';
        var detailId = cardId + '-details';
        return {
            id: cardId,
            layout: 'border',
            border: false,
            items: [
                {
                    region: 'north',
                    id: searchId,
                    xtype: 'spookyapp-form-chunkgenerator-search',
                    contentType: cfg.type,
                    showYearField: cfg.showYearField || (cfg.type === 'movie' || cfg.type === 'tv'),
                    sourceOptions: cfg.sourceOptions || null,
                    showCountryField: cfg.showCountryField || false,
                    showLanguageField: cfg.showLanguageField || false,
                    subtypes: cfg.subtypes ? Ext.pluck(cfg.subtypes, 'val') : null,
                    subtypeLabels: cfg.subtypes || null,
                    flashSportOptions: cfg.flashSportOptions || null,
                    sportApiOptions: cfg.sportApiOptions || null,
                    sportApiOptions: cfg.sportApiOptions || null,
                    border: false,
                    listeners: {
                        'search-success': {
                            fn: function(results, type, query) {
                                var grid = Ext.getCmp(gridId);
                                if (grid && grid.loadResults) { grid.loadResults(results, type, query); }
                            }
                        },
                        'search-failure': {
                            fn: function(msg) {
                                MODx.msg.alert(_('spookyapp.error') || 'Error', msg || 'Search failed.');
                            }
                        }
                    }
                },
                {
                    region: 'center',
                    id: gridId,
                    xtype: 'spookyapp-grid-chunkgenerator-results',
                    contentType: cfg.type,
                    visibleColumns: cfg.visibleColumns || [],
                    border: false,
                    listeners: {
                        'get-details': {
                            fn: function(record, type) {
                                var details = Ext.getCmp(detailId);
                                if (details) {
                                    if (details.collapsed) { details.expand(); }
                                    // Read selected source from the search form (if present)
                                    var source = '';
                                    var searchForm = Ext.getCmp(searchId);
                                    if (searchForm) {
                                        var sf = searchForm.getForm().findField('source');
                                        if (sf) { source = sf.getValue() || ''; }
                                    }
                                    // For flashsport, encode mode/sport/day in source param
                                    var resolvedType = type || cfg.type;
                                    if (resolvedType === 'flashsport' && searchForm) {
                                        var fsMF = searchForm.getForm().findField('fs_mode');
                                        var fsSF = searchForm.getForm().findField('fs_sport');
                                        var fsDF = searchForm.getForm().findField('fs_day');
                                        var fsMode  = fsMF ? (fsMF.getValue()  || 'match') : 'match';
                                        var fsSport = fsSF ? (fsSF.getValue()  || 1)       : 1;
                                        var fsDay   = fsDF ? (parseInt(fsDF.getValue()) || 0) : 0;
                                        source = fsMode + '|' + fsSport + '|' + fsDay;
                                    }
                                    // For grouped tabs (cinema/sport), resolve detailOptions per subtype
                                    var resolvedOptions = cfg.detailOptions || [];
                                    if (cfg.subtypes) {
                                        var subtypeDetailMap = {
                                            movie:  ['cast', 'crew', 'screenshots', 'similar'],
                                            tv:     ['cast', 'seasons', 'similar'],
                                            person: ['movies', 'tv', 'images'],
                                            football: [], biathlon: [], flashsport: [], sportapi: []
                                        };
                                        resolvedOptions = subtypeDetailMap[resolvedType] || [];
                                    }
                                    details.loadDetails(record, resolvedType, resolvedOptions, source);
                                }
                            }
                        },
                        'chunk-generated': {
                            fn: function(html, type, data) {
                                me.showChunkPreview(html, data, cfg.type);
                            }
                        }
                    }
                },
                {
                    region: 'east',
                    id: detailId,
                    xtype: 'spookyapp-form-chunkgenerator-details',
                    contentType: cfg.type,
                    detailOptions: cfg.detailOptions || [],
                    width: Math.round((window.innerWidth || 1400) * 0.5), minWidth: 500, maxWidth: 1200,
                    split: true, collapsible: true, collapsed: true,
                    title: _('spookyapp.chunkgenerator.details') || 'Детали',
                    border: true,
                    listeners: {
                        'chunk-generated': {
                            fn: function(html, type, data) {
                                me.showChunkPreview(html, data, cfg.type);
                            }
                        }
                    }
                }
            ]
        };
    },
    // ── Действия сохранёнными чанками ──────────────────────────

    /**
     * Открыть детали сохранённого чанка в правой панели.
     *
     * Переключает таб на нужный тип and вызывает loadDetails с external_id в качестве id.
     *
     * @param {Ext.data.Record} rec Строка из savedStore
     */
    openSavedChunkDetails: function(rec) {
        var me = this;
        var type        = rec.get('type')       || 'movie';
        var externalId  = rec.get('external_id') || '';
        var title       = rec.get('title')       || '';

        // Решаем, какой tab-тип соответствует type. cinema = movie|tv|person, sport = football|biathlon|flashsport
        var tabType = type;
        if (type === 'movie' || type === 'tv' || type === 'person') { tabType = 'cinema'; }
        if (type === 'football' || type === 'biathlon' || type === 'flashsport' || type === 'sportapi') { tabType = 'sport'; }

        // Переключаем таб и нажимаем соответствующую кнопку в type-bar
        me.switchType(tabType);
        var typeBar = Ext.getCmp('spookyapp-cg-type-bar');
        if (typeBar) {
            typeBar.items.each(function(btn) {
                if (btn.initialConfig && btn.initialConfig.handler) {
                    // выделяем нужную кнопку в toggleгруппе через text
                }
            });
        }

        var cardId   = 'spookyapp-cg-card-' + tabType;
        var detailId = cardId + '-details';
        var details  = Ext.getCmp(detailId);

        if (!details) { return; }

        if (details.collapsed) { details.expand(); }

        // Строим опции для текущего таба
        var tabCfg = null;
        Ext.each(me.typeConfigs, function(c) { if (c.type === tabType) { tabCfg = c; } });

        var resolvedOptions = tabCfg ? (tabCfg.detailOptions || []) : [];
        if (tabCfg && tabCfg.subtypes) {
            var subtypeDetailMap = {
                movie:      ['cast', 'crew', 'screenshots', 'similar'],
                tv:         ['cast', 'seasons', 'similar'],
                person:     ['movies', 'tv', 'images'],
                football:   [], biathlon: [], flashsport: [], sportapi: []
            };
            resolvedOptions = subtypeDetailMap[type] || [];
        }

        details.loadDetails(
            {id: externalId, title: title},
            type,
            resolvedOptions,
            '', // source — нет при открытии из сохранённых
            rec.get('id') // chunk_id (DB primary key) для загрузки из БД
        );
        // Чанк уже сохранён в БД — сразу устанавливаем lastChunkId,
        // чтобы «Copy Code» и «Generate Code» работали без повторного сохранения.
        details.lastChunkId = rec.get('id');
    },

    /**
     * Удалить сохранённый чанк из БД с подтверждением.
     *
     * @param {Ext.data.Record} rec Строка из savedStore
     */
    deleteSavedChunk: function(rec) {
        var me    = this;
        var id    = rec.get('id');
        var title = rec.get('title');

        Ext.Msg.confirm(
            _('spookyapp.chunkgenerator.delete_confirm_title') || 'Удаление',
            (_('spookyapp.chunkgenerator.delete_confirm') || 'Удалить чанк «') + Ext.util.Format.htmlEncode(title||'') + '»?',
            function(btn) {
                if (btn !== 'yes') { return; }
                MODx.Ajax.request({
                    url: SpookyApp.config.connector_url,
                    params: {
                        action: 'chunkgenerator/deletechunk',
                        id: id
                    },
                    listeners: {
                        success: {
                            fn: function() {
                                MODx.msg.status({
                                    title: _('success'),
                                    message: _('spookyapp.chunkgenerator.deleted') || 'Чанк удалён'
                                });
                                me.savedStore.load();
                            }
                        },
                        failure: {
                            fn: function(r) {
                                MODx.msg.alert(_('error'), r.message || 'Delete failed');
                            }
                        }
                    }
                });
            }
        );
    },
    // ── Переключение типа контента ───────────────────────────

    switchType: function (type) {
        if (this.activeType === type) { return; }
        this.activeType = type;

        // ── Синхронизируем состояние кнопок ─────────────────
        var bar = Ext.getCmp('spookyapp-cg-type-bar');
        if (bar) {
            bar.items.each(function(item) {
                if (item.toggleGroup === 'spookyapp-cg-type') {
                    item.toggle(item._typeKey === type, true);
                    if (item._typeKey === type) {
                        item.el && item.el.addClass('spookyapp-tab-active');
                    } else {
                        item.el && item.el.removeClass('spookyapp-tab-active');
                    }
                }
            });
        }

        var cards = Ext.getCmp('spookyapp-cg-cards');
        if (!cards) { return; }
        var cardId = 'spookyapp-cg-card-' + type;
        var card = Ext.getCmp(cardId);
        if (card) { cards.getLayout().setActiveItem(cardId); }
    },

    // ── Превью готового чанка ────────────────────────────────

    showChunkPreview: function (html, data, type) {
        var title = (data && data.title) ? data.title : (type || 'Chunk');
        var win = new Ext.Window({
            title: _('spookyapp.chunkgenerator.preview_title') || ('Chunk: ' + title),
            width: 700, height: 500, modal: true, maximizable: true,
            layout: 'fit',
            items: [{
                xtype: 'panel',
                autoScroll: true,
                bodyStyle: 'padding:12px; background:#fff;',
                html: html || '<p style="color:#999;">No content</p>'
            }],
            buttons: [{
                text: _('spookyapp.btn.close') || 'Закрыть',
                handler: function() { this.ownerCt.ownerCt.close(); },
                scope: this
            }]
        });
        win.show();
    }
});

Ext.reg('spookyapp-panel-chunkgenerator', SpookyApp.panel.ChunkGenerator);

// ─────────────────────────────────────────────────────────────────────────────

/**
 * SpookyApp — Chunk Generator Section
 *
 * ═══════════════════════════════════════════════════════════════
 * Главная страница Chunk Generator.
 * Точка входа для MODx.Component — содержит табы с различными
 * типами контента (Movies, TV, Games, etc.).
 *
 * Регистрация: MODx.load в контроллере chunkgenerator
 * URL: ?a=home&namespace=spookyapp&page=chunkgenerator
 * ═══════════════════════════════════════════════════════════════
 *
 * @class   SpookyApp.page.ChunkGenerator
 * @extends MODx.Component
 * @xtype   spookyapp-page-chunkgenerator
 *
 * @package SpookyApp
 */
SpookyApp.page.ChunkGenerator = function(config) {
    config = config || {};

    Ext.applyIf(config, {
        xtype: 'modx-component',
        cls: 'container spookyapp-chunkgenerator-page',
        components: [{
            xtype: 'spookyapp-panel-chunkgenerator-tabs',
            renderTo: 'spookyapp-panel-chunkgenerator-div'
        }]
    });

    SpookyApp.page.ChunkGenerator.superclass.constructor.call(this, config);
};
Ext.extend(SpookyApp.page.ChunkGenerator, MODx.Component);
Ext.reg('spookyapp-page-chunkgenerator', SpookyApp.page.ChunkGenerator);