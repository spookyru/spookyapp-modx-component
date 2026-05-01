/**
 * SpookyApp — Chunk Generator Details Form
 *
 * ═══════════════════════════════════════════════════════════════
 * Форма с детальной информацией о выбранном элементе.
 * Загружает данные через processor chunkgenerator/getdetails
 * и позволяет:
 *   - Просматривать все поля
 *   - Переводить текст (Translate)
 *   - Генерировать озвучку (Voice Over)
 *   - Генерировать чанк (Preview/Save/Copy)
 *
 * Конфигурация:
 *   - contentType (string):  Тип контента
 *   - detailOptions (array): Доступные опции загрузки
 *
 * Events:
 *   - chunk-generated(html, type, data)
 *   - details-loaded(data, type)
 * ═══════════════════════════════════════════════════════════════
 *
 * @class   SpookyApp.form.ChunkGeneratorDetails
 * @extends Ext.form.FormPanel
 * @xtype   spookyapp-form-chunkgenerator-details
 *
 * @package SpookyApp
 */
SpookyApp.form.ChunkGeneratorDetails = function(config) {
    config = config || {};

    this.contentType = config.contentType || 'movie';
    this.detailOptions = config.detailOptions || [];
    this.currentData = null;
    this.currentType = null;
    this.currentId = null;
    this.currentSource = '';
    this.lastChunkId = null;

    Ext.applyIf(config, {
        xtype: 'form',
        cls: 'spookyapp-chunkgen-details-form',
        bodyStyle: 'padding: 10px;',
        border: false,
        autoScroll: true,
        labelWidth: 120,
        labelAlign: 'top',
        defaults: {
            anchor: '100%'
        },
        items: [{
            xtype: 'panel',
            id: 'spookyapp-chunkgen-details-placeholder-' + this.contentType,
            border: false,
            bodyStyle: 'text-align:center;padding:80px 20px;color:#999;',
            html: '<p style="font-size:14px;">'
                + (_('spookyapp.chunkgenerator.select_item') || 'Select an item from search results')
                + '</p>'
                + '<p>' + (_('spookyapp.chunkgenerator.dblclick_hint') || 'Double-click or press "Details" button') + '</p>'
        }],
        tbar: this.buildTopToolbar(),
        bbar: this.buildBottomToolbar()
    });

    // ── Регистрируем события ─────────────────────────────────
    this.addEvents('chunk-generated', 'details-loaded');

    SpookyApp.form.ChunkGeneratorDetails.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.form.ChunkGeneratorDetails, Ext.form.FormPanel, {

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Toolbars                                               ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Верхний toolbar — Options checkboxes.
     *
     * @return {Array}
     */
    buildTopToolbar: function() {
        var items = [];

        items.push({
            xtype: 'tbtext',
            text: '<b>' + (_('spookyapp.chunkgenerator.load_options') || 'Load:') + '</b>'
        });

        Ext.each(this.detailOptions, function(opt) {
            items.push({
                xtype: 'checkbox',
                boxLabel: _('spookyapp.chunkgenerator.opt_' + opt) || opt,
                name: 'opt_' + opt,
                id: 'spookyapp-chunkgen-opt-' + opt + '-' + this.contentType,
                checked: true,
                style: 'margin: 0 5px;'
            });
        }, this);

        items.push('->');

        items.push({
            text: _('spookyapp.chunkgenerator.reload') || 'Reload',
            handler: this.reloadDetails,
            scope: this
        });

        return items;
    },

    /**
     * Нижний toolbar — кнопки действий.
     *
     * @return {Array}
     */
    buildBottomToolbar: function() {
        return [
           /* {
                text: _('spookyapp.chunkgenerator.preview_chunk') || 'Preview Chunk',
                id: 'spookyapp-chunkgen-btn-preview-' + this.contentType,
                disabled: true,
                handler: this.doPreviewChunk,
                scope: this
            },
            '-',
            */
            {
                text: _('spookyapp.chunkgenerator.save_to_db') || 'Save to DB',
                id: 'spookyapp-chunkgen-btn-save-' + this.contentType,
                disabled: true,
                handler: this.doSaveChunk,
                scope: this
            },
            '-',
            {
                xtype: 'tbtext',
                text: _('spookyapp.chunkgenerator.output_format') || 'Формат:'
            },
            {
                xtype: 'combo',
                id: 'spookyapp-chunkgen-output-format-' + this.contentType,
                width: 110,
                mode: 'local',
               //style: 'min-width: 64px; padding: 2px;',
                triggerAction: 'all',
                editable: false,
                value: 'card',
                store: new Ext.data.ArrayStore({
                    fields: ['value', 'label'],
                    data: [
                        ['card',     _('spookyapp.chunkgenerator.fmt_card')     || 'Карточка'],
                        ['brief',    _('spookyapp.chunkgenerator.fmt_brief')    || 'Краткое'],
                        ['text',     _('spookyapp.chunkgenerator.fmt_text')     || 'Текст'],
                        ['telegram', _('spookyapp.chunkgenerator.fmt_telegram') || 'Telegram']
                    ]
                }),
                displayField: 'label',
                valueField: 'value',
                listeners: {
                afterrender: function(combo) {
                combo.wrap.setWidth(110);  // принудительно установи ширину обёртки
                combo.el.setWidth(82);     // 110 - 28px (ширина кнопки)
              }
            }
            },
            {
                text: _('spookyapp.chunkgenerator.copy_code') || 'Copy Code',
                id: 'spookyapp-chunkgen-btn-copy-' + this.contentType,
                disabled: true,
                handler: this.doCopyChunkCode,
                scope: this
            },
            {
                text: _('spookyapp.chunkgenerator.export_json') || 'Export JSON',
                id: 'spookyapp-chunkgen-btn-export-' + this.contentType,
                disabled: true,
                handler: this.doExportJSON,
                scope: this
            },
            {
                text: '<i class="fas fa-code"></i> ' + _('spookyapp.chunkgenerator.generate_code') || 'Generate Code',
              id: 'spookyapp-chunkgen-btn-generate-' + this.contentType,
                disabled: true,
                handler: this.doGenerateEmbedCodes,
                scope: this
            },
            '->',
            {
                text: _('spookyapp.chunkgenerator.reset') || 'Reset',
                handler: this.doReset,
                scope: this
            }
        ];
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Load Details                                           ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Загрузить детали элемента.
     *
     * @param {Object} record  Запись из grid (id, title, type, ...)
     * @param {String} type    Тип контента
     * @param {Array}  options Опции загрузки из tab config
     * @param {String} source  API source (e.g. 'rapidapi' | 'mobileapi')
     */
    loadDetails: function(record, type, options, source, chunkDbId) {
        this.currentId     = record.id;
        this.currentType   = type;
        this.currentSource = source || '';
        this.currentChunkDbId = chunkDbId || 0;
        // Title is used on the PHP side as a fallback search query (MobileApi.dev)
        this.currentTitle  = (typeof record.get === 'function')
            ? (record.get('title') || '')
            : (record.title || '');

        // ── Собираем выбранные опции из чекбоксов ────────────
        var selectedOptions = [];
        var allOptions = options || this.detailOptions;

        Ext.each(allOptions, function(opt) {
            var cb = Ext.getCmp('spookyapp-chunkgen-opt-' + opt + '-' + this.contentType);
            if (cb && cb.getValue()) {
                selectedOptions.push(opt);
            }
        }, this);

        // ── Маска загрузки ───────────────────────────────────
        var mask = new Ext.LoadMask(this.getEl(), {
            msg: _('spookyapp.chunkgenerator.loading_details') || 'Loading details...'
        });
        mask.show();

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/getdetails',
                type: type,
                id: record.id,
                title: this.currentTitle || '',
                options: Ext.encode(selectedOptions),
                source: this.currentSource || '',
                chunk_id: this.currentChunkDbId || 0
            },
            listeners: {
                success: {
                    fn: function(r) {
                        mask.hide();
                        var data = (r.object && r.object.data) ? r.object.data : {};
                        this.currentData = data;
                        this.renderDetailsFields(data, type);
                        this.enableButtons(true);
                        this.fireEvent('details-loaded', data, type);
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        mask.hide();
                        MODx.msg.alert(
                            _('error'),
                            r.message || _('spookyapp.chunkgenerator.err_details_failed')
                        );
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Перезагрузить текущие детали.
     */
    reloadDetails: function() {
        if (this.currentId && this.currentType) {
            this.loadDetails(
                { id: this.currentId, title: this.currentTitle || '' },
                this.currentType,
                this.detailOptions,
                this.currentSource || ''
            );
        }
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Render Details Fields                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Рендер полей формы на основе полученных данных.
     *
     * Очищает текущие поля и создаёт новые в зависимости от типа
     * контента и доступных данных.
     *
     * @param {Object} data Данные объекта
     * @param {String} type Тип контента
     */
    renderDetailsFields: function(data, type) {
        // ── Удаляем старые поля ──────────────────────────────
        this.removeAll(true);

        var items = [];

        // ── Poster / Image ───────────────────────────────────
        var image = data.poster || data.image || data.photo || data.backdrop
            || (Ext.isArray(data.images) && data.images.length > 0 ? data.images[0] : null)
            || null;
        if (image) {
            items.push({
                xtype: 'panel',
                border: false,
                bodyStyle: 'text-align: center; padding: 5px 0 10px;',
                html: '<img src="' + Ext.util.Format.htmlEncode(image)
                    + '" style="max-width:200px;max-height:300px;border-radius:5px;box-shadow:0 2px 8px rgba(0,0,0,0.2);" />'
            });
        }

        // ── Определяем конфигурацию полей по типу ────────────
        var fieldConfigs = this.getFieldConfigsForType(type);

        // ── Строим поля на основе данных ─────────────────────
        Ext.each(fieldConfigs, function(fc) {
            var value = this.getNestedValue(data, fc.key);
            if (value === undefined || value === null) return;

            var fieldItem = this.buildDetailField(fc, value, data, type);
            if (fieldItem) {
                items.push(fieldItem);
            }
        }, this);

        // ── Arrays: cast, crew, genres, etc. ─────────────────
        var arrayFields = this.getArrayFieldsForType(type);
        Ext.each(arrayFields, function(af) {
            var val = data[af.key];
            if (af.display === 'incidents_timeline') {
                // incidents is an object {goals, cards, substitutions, timeline}
                if (val && typeof val === 'object') {
                    items.push(this.buildArrayField(af, val));
                }
            } else if (val && Ext.isArray(val) && val.length > 0) {
                items.push(this.buildArrayField(af, val));
            }
        }, this);

        // ── SportAPI7: кнопка Voice (составной текст) ────────
        if (type === 'sportapi') {
            items.push({
                xtype: 'panel',
                border: false,
                bodyStyle: 'padding: 6px 0 2px;',
                items: [{
                    xtype: 'button',
                  text: '<i class="fas fa-volume-up"></i> ' + _('spookyapp.chunkgenerator.voiceover') || 'Voice Over',
                    style: 'position:relative;bottom:0px;left:50%;',
                    cls: 'spookyapp-btn-voiceover',
                    handler: function() {
                        this.doVoiceoverSportapi(data);
                    },
                    scope: this
                }]
            });
        }

        // ── Movie: кнопка Voice (составной текст) ────────────
        if (type === 'movie') {
            items.push({
                xtype: 'panel',
                border: false,
                bodyStyle: 'padding: 6px 0 2px;',
                items: [{
                    xtype: 'button',
                    text: '<i class="fas fa-volume-up"></i> ' + (_('spookyapp.chunkgenerator.voiceover') || 'Voice Over'),
                    cls: 'spookyapp-btn-voiceover',
                    handler: function() {
                        this.doVoiceoverMovie(data);
                    },
                    scope: this
                }]
            });
        }

        // ── TV: кнопка Voice (составной текст) ──────────────
        if (type === 'tv') {
            items.push({
                xtype: 'panel',
                border: false,
                bodyStyle: 'padding: 6px 0 2px;',
                items: [{
                    xtype: 'button',
                    text: '<i class="fas fa-volume-up"></i> ' + (_('spookyapp.chunkgenerator.voiceover') || 'Voice Over'),
                    cls: 'spookyapp-btn-voiceover',
                    handler: function() {
                        this.doVoiceoverTV(data);
                    },
                    scope: this
                }]
            });
        }

        // ── Person: кнопка Voice (составной текст) ──────────
        if (type === 'person') {
            items.push({
                xtype: 'panel',
                border: false,
                bodyStyle: 'padding: 6px 0 2px;',
                items: [{
                    xtype: 'button',
                    text: '<i class="fas fa-volume-up"></i> ' + (_('spookyapp.chunkgenerator.voiceover') || 'Voice Over'),
                    cls: 'spookyapp-btn-voiceover',
                    handler: function() {
                        this.doVoiceoverPerson(data);
                    },
                    scope: this
                }]
            });
        }

        // ── Game: кнопка Voice (составной текст) ─────────────
        if (type === 'game') {
            items.push({
                xtype: 'panel',
                border: false,
                bodyStyle: 'padding: 6px 0 2px;',
                items: [{
                    xtype: 'button',
                    text: '<i class="fas fa-volume-up"></i> ' + (_('spookyapp.chunkgenerator.voiceover') || 'Voice Over'),
                    cls: 'spookyapp-btn-voiceover',
                    handler: function() {
                        this.doVoiceoverGame(data);
                    },
                    scope: this
                }]
            });
        }

        // ── Product: кнопка Voice (составной текст) ──────────
        if (type === 'product') {
            items.push({
                xtype: 'panel',
                border: false,
                bodyStyle: 'padding: 6px 0 2px;',
                items: [{
                    xtype: 'button',
                    text: '<i class="fas fa-volume-up"></i> ' + (_('spookyapp.chunkgenerator.voiceover') || 'Voice Over'),
                    cls: 'spookyapp-btn-voiceover',
                    handler: function() {
                        this.doVoiceoverProduct(data);
                    },
                    scope: this
                }]
            });
        }

        // (Chunk Settings fieldset removed — format/template are in bottom toolbar)

        // ── Добавляем все поля ───────────────────────────────
        if (items.length === 0) {
            items.push({
                xtype: 'panel',
                border: false,
                html: '<p style="color:#999;text-align:center;">No data available</p>'
            });
        }

        Ext.each(items, function(item) {
            this.add(item);
        }, this);

        this.doLayout();
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Field Configurations per Type                          ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить конфигурацию полей для типа контента.
     *
     * @param {String} type Тип контента
     * @return {Array} Массив конфигураций полей
     */
    getFieldConfigsForType: function(type) {
        var common = [
            { key: 'id',    label: 'ID',    xtype: 'displayfield' },
            { key: 'title', label: 'Title', xtype: 'textfield',   translatable: true }
        ];

        var specific = {
            movie: [
                { key: 'original_title',    label: 'Original Title',    xtype: 'textfield' },
                { key: 'original_language', label: 'Original Language', xtype: 'displayfield' },
                { key: 'tagline',           label: 'Tagline',           xtype: 'textfield', translatable: true },
                { key: 'overview',       label: 'Overview',       xtype: 'textarea',   translatable: true, rows: 5 },
                { key: 'release_date',   label: 'Release Date',   xtype: 'displayfield' },
                { key: 'runtime',        label: 'Runtime (min)',  xtype: 'displayfield' },
                { key: 'budget',         label: 'Budget',         xtype: 'displayfield', renderer: 'currency' },
                { key: 'revenue',        label: 'Revenue',        xtype: 'displayfield', renderer: 'currency' },
                { key: 'rating',         label: 'Rating',         xtype: 'displayfield', renderer: 'rating' },
                { key: 'imdb_id',        label: 'IMDb ID',        xtype: 'displayfield' },
                { key: 'status',         label: 'Status',         xtype: 'displayfield' },
                { key: 'homepage',       label: 'Homepage',       xtype: 'displayfield', renderer: 'link' }
            ],
            tv: [
                { key: 'original_title',     label: 'Original Title',    xtype: 'textfield' },
                { key: 'original_language',  label: 'Original Language', xtype: 'displayfield' },
                { key: 'tagline',            label: 'Tagline',           xtype: 'textfield', translatable: true },
                { key: 'overview',           label: 'Overview',          xtype: 'textarea',  translatable: true, rows: 5 },
                { key: 'first_air_date',     label: 'First Air Date',    xtype: 'displayfield' },
                { key: 'last_air_date',      label: 'Last Air Date',     xtype: 'displayfield' },
                { key: 'last_episode',       label: 'Последний эпизод',  xtype: 'displayfield' },
                { key: 'next_episode',       label: 'Следующий эпизод',  xtype: 'displayfield' },
                { key: 'number_of_seasons',  label: 'Seasons',           xtype: 'displayfield' },
                { key: 'number_of_episodes', label: 'Episodes',          xtype: 'displayfield' },
                { key: 'type',               label: 'Type',              xtype: 'displayfield' },
                { key: 'rating',             label: 'Rating',            xtype: 'displayfield', renderer: 'rating' },
                { key: 'status',             label: 'Status',            xtype: 'displayfield' }
            ],
            person: [
                { key: 'name',                  label: 'Name',             xtype: 'textfield', translatable: true },
                { key: 'biography',             label: 'Biography (RU)',   xtype: 'textarea', rows: 6 },
                { key: 'biography_en',          label: 'Biography (EN)',   xtype: 'textarea', translatable: true, rows: 5 },
                { key: 'birthday',              label: 'Birthday',         xtype: 'displayfield' },
                { key: 'deathday',              label: 'Death',            xtype: 'displayfield' },
                { key: 'place_of_birth',        label: 'Place of Birth',   xtype: 'displayfield' },
                { key: 'known_for_department',  label: 'Known For',        xtype: 'displayfield' },
                { key: 'imdb_id',               label: 'IMDb ID',          xtype: 'displayfield' },
                { key: 'popularity',            label: 'Popularity',       xtype: 'displayfield' },
                { key: 'wikipedia_url',         label: 'Wikipedia',        xtype: 'displayfield', renderer: 'link' },
                { key: 'instagram_id',          label: 'Instagram',        xtype: 'displayfield' },
                { key: 'facebook_id',           label: 'Facebook',         xtype: 'displayfield' },
                { key: 'twitter_id',            label: 'Twitter/X',        xtype: 'displayfield' },
                { key: 'tiktok_id',             label: 'TikTok',           xtype: 'displayfield' },
                { key: 'youtube_id',            label: 'YouTube',          xtype: 'displayfield' }
            ],
            game: [
                { key: 'description', label: 'Description', xtype: 'textarea', translatable: true, rows: 6 },
                { key: 'released',    label: 'Released',     xtype: 'displayfield' },
                { key: 'rating',      label: 'Rating',       xtype: 'displayfield', renderer: 'rating' },
                { key: 'metacritic',  label: 'Metacritic',   xtype: 'displayfield' },
                { key: 'playtime',    label: 'Playtime (h)', xtype: 'displayfield' },
                { key: 'website',     label: 'Website',      xtype: 'displayfield', renderer: 'link' },
                { key: 'esrb_rating', label: 'ESRB',         xtype: 'displayfield' }
            ],
            device: [
                { key: 'brand',        label: 'Brand',        xtype: 'displayfield' },
                { key: 'release_date', label: 'Release Date', xtype: 'displayfield' },
                { key: 'os',           label: 'OS',           xtype: 'displayfield' },
                { key: 'chipset',      label: 'Chipset',      xtype: 'displayfield' },
                { key: 'display',      label: 'Display',      xtype: 'displayfield' },
                { key: 'camera',       label: 'Camera',       xtype: 'displayfield' },
                { key: 'battery',      label: 'Battery',      xtype: 'displayfield' },
                { key: 'ram',          label: 'RAM',          xtype: 'displayfield' },
                { key: 'storage',      label: 'Storage',      xtype: 'displayfield' },
                { key: 'detail_url',   label: 'Source URL',   xtype: 'displayfield', renderer: 'link' }
            ],
            product: [
                { key: 'description',         label: 'Description', xtype: 'textarea',    translatable: true, rows: 4 },
                { key: 'product_attributes',  label: 'Attributes',  xtype: 'textarea',    translatable: true, rows: 3 },
                { key: 'rating',              label: 'Rating',      xtype: 'displayfield', renderer: 'rating' },
                { key: 'typical_price_range', label: 'Price Range', xtype: 'displayfield' }
            ],
            football: [
                { key: 'date',              label: 'Дата',          xtype: 'displayfield' },
                { key: 'status',            label: 'Статус',        xtype: 'displayfield' },
                { key: 'round',             label: 'Тур',           xtype: 'displayfield' },
                { key: 'tournament',        label: 'Турнир',        xtype: 'displayfield', renderer: 'tournament_obj' },
                { key: 'home_team',         label: 'Хозяева',       xtype: 'displayfield', renderer: 'team_obj' },
                { key: 'away_team',         label: 'Гости',         xtype: 'displayfield', renderer: 'team_obj' },
                { key: 'score',             label: 'Счёт',          xtype: 'displayfield', renderer: 'score_obj' },
                { key: 'venue',             label: 'Стадион',       xtype: 'displayfield' },
                { key: 'referee',           label: 'Судья',         xtype: 'displayfield' }
            ],
            biathlon: [],
            flashsport: [],
            sportapi: [
                { key: 'date',              label: 'Дата',          xtype: 'displayfield' },
                { key: 'status',            label: 'Статус',        xtype: 'textfield' },
                { key: 'round',             label: 'Тур/Раунд',     xtype: 'displayfield' },
                { key: 'tournament.name',   label: 'Турнир',        xtype: 'textfield' },
                { key: 'tournament.category', label: 'Категория',   xtype: 'displayfield' },
                { key: 'tournament.country',  label: 'Страна',      xtype: 'displayfield' },
                { key: 'season.name',       label: 'Сезон',         xtype: 'displayfield' },
                { key: 'home_team.name',    label: 'Хозяева',       xtype: 'textfield' },
                { key: 'away_team.name',    label: 'Гости',         xtype: 'textfield' },
                { key: 'score.home',        label: 'Счёт (хоз)',    xtype: 'displayfield' },
                { key: 'score.away',        label: 'Счёт (гос)',    xtype: 'displayfield' },
                { key: 'venue',             label: 'Стадион',       xtype: 'textfield' },
                { key: 'referee',           label: 'Судья',         xtype: 'textfield' }
            ],
            github: [
                { key: 'full_name',   label: 'Repository',    xtype: 'displayfield' },
                { key: 'description', label: 'Description',   xtype: 'textarea',    translatable: true, voiceover: true, rows: 4 },
                { key: 'language',    label: 'Language',      xtype: 'displayfield' },
                { key: 'stars',       label: 'Stars',         xtype: 'displayfield' },
                { key: 'forks',       label: 'Forks',         xtype: 'displayfield' },
                { key: 'watchers',    label: 'Watchers',      xtype: 'displayfield' },
                { key: 'open_issues', label: 'Open Issues',   xtype: 'displayfield' },
                { key: 'license',     label: 'License',       xtype: 'displayfield' },
                { key: 'created_at',  label: 'Created',       xtype: 'displayfield' },
                { key: 'updated_at',  label: 'Updated',       xtype: 'displayfield' },
                { key: 'pushed_at',   label: 'Last Push',     xtype: 'displayfield' },
                { key: 'homepage',    label: 'Homepage',      xtype: 'displayfield', renderer: 'link' },
                { key: 'html_url',    label: 'GitHub URL',    xtype: 'displayfield', renderer: 'link' }
            ]
        };

        return common.concat(specific[type] || []);
    },

    /**
     * Получить конфигурации массивных полей для типа.
     *
     * @param {String} type Тип
     * @return {Array}
     */
    getArrayFieldsForType: function(type) {
        var configs = {
            movie: [
                { key: 'genres',               label: 'Genres',            display: 'tags' },
                { key: 'countries',            label: 'Countries',         display: 'tags' },
                { key: 'production_companies', label: 'Студии',            display: 'companies' },
                { key: 'cast',                 label: 'В ролях',           display: 'cast_table' },
                { key: 'crew',                 label: 'Съёмочная группа',  display: 'crew_table' },
                { key: 'screenshots',          label: 'Screenshots',       display: 'images' },
                { key: 'similar',              label: 'Similar',           display: 'list', titleKey: 'title' }
            ],
            tv: [
                { key: 'genres',               label: 'Genres',            display: 'tags' },
                { key: 'origin_country',       label: 'Country',           display: 'tags' },
                { key: 'created_by',           label: 'Создатели',         display: 'tags' },
                { key: 'production_companies', label: 'Студии',            display: 'companies' },
                { key: 'networks',             label: 'Каналы',            display: 'networks' },
                { key: 'seasons',              label: 'Сезоны',            display: 'table', columns: ['season_number', 'name', 'episode_count', 'air_date'] },
                { key: 'cast',                 label: 'В ролях',           display: 'cast_table' },
                { key: 'crew',                 label: 'Съёмочная группа',  display: 'crew_table' },
                { key: 'similar',              label: 'Similar',           display: 'list', titleKey: 'title' }
            ],
            person: [
                { key: 'also_known_as',  label: 'Also Known As', display: 'tags' },
                { key: 'movie_credits',  label: 'Movie Credits', display: 'table', columns: ['title', 'character', 'year', 'rating'] },
                { key: 'tv_credits',     label: 'TV Credits',    display: 'table', columns: ['title', 'character', 'year'] },
                { key: 'images',         label: 'Photos',        display: 'images' }
            ],
            game: [
                { key: 'genres',       label: 'Genres',      display: 'tags' },
                { key: 'platforms',    label: 'Platforms',   display: 'tags' },
                { key: 'developers',   label: 'Developers',  display: 'tags' },
                { key: 'publishers',   label: 'Publishers',  display: 'tags' },
                { key: 'screenshots',  label: 'Screenshots', display: 'images' },
                { key: 'similar',      label: 'Similar',     display: 'list', titleKey: 'title' }
            ],
            device: [
                { key: 'specifications', label: 'Specifications', display: 'specs' }
            ],
            product: [
                { key: 'images', label: 'Photos',  display: 'images' },
                { key: 'offers', label: 'Offers',  display: 'table', columns: ['title', 'price', 'url'] }
            ],
            sportapi: [
                { key: 'incidents', label: 'События матча', display: 'incidents_timeline' }
            ],
            github: [
                { key: 'topics', label: 'Topics', display: 'tags' }
            ]
        };

        return configs[type] || [];
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Build Individual Fields                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Строит одно поле формы.
     *
     * @param {Object} fc    Конфигурация поля
     * @param {Mixed}  value Значение
     * @param {Object} data  Все данные
     * @param {String} type  Тип контента
     * @return {Object|null} Ext component config
     */
    buildDetailField: function(fc, value, data, type) {
        var fieldId = 'spookyapp-chunkgen-field-' + fc.key + '-' + this.contentType;
        var displayValue = this.formatValue(value, fc.renderer);

        // ── Display field ────────────────────────────────────
        if (fc.xtype === 'displayfield') {
            return {
                xtype: 'displayfield',
                fieldLabel: fc.label,
                id: fieldId,
                value: displayValue,
                cls: 'spookyapp-detail-field'
            };
        }

        // ── Text / Textarea ──────────────────────────────────
        var field = {
            xtype: fc.xtype || 'textfield',
            fieldLabel: fc.label,
            id: fieldId,
            name: 'detail_' + fc.key,
            value: String(value),
            anchor: '100%',
            readOnly: false,
            cls: 'spookyapp-detail-field-editable'
        };

        if (fc.xtype === 'textarea') {
            field.grow = true;
            field.growMin = 60;
            field.growMax = 300;
            if (fc.rows) {
                field.height = fc.rows * 20;
            }
        }

        // ── Container с кнопками Translate / Voiceover ───────
        if (fc.translatable || fc.voiceover) {
            var containerItems = [field];
            var buttonItems = [];

            if (fc.translatable) {
                buttonItems.push({
                    xtype: 'button',
                    text: _('spookyapp.chunkgenerator.translate') || 'Translate',
                    cls: 'spookyapp-btn-translate',
                    handler: function() {
                        this.doTranslateField(fieldId, fc.key);
                    },
                    scope: this
                });
            }

            if (fc.voiceover) {
                buttonItems.push({
                    xtype: 'button',
                    text: _('spookyapp.chunkgenerator.voiceover') || 'Voice Over',
                    cls: 'spookyapp-btn-voiceover',
                    handler: function() {
                        this.doVoiceoverField(fieldId, fc.key);
                    },
                    scope: this
                });
            }

            if (buttonItems.length > 0) {
                containerItems.push({
                    xtype: 'toolbar',
                    border: false,
                    bodyStyle: 'padding: 2px 0;',
                    items: buttonItems
                });
            }

            return {
                xtype: 'panel',
                border: false,
                anchor: '100%',
                layout: 'form',
                labelWidth: 120,
                labelAlign: 'top',
                cls: 'spookyapp-detail-field-wrapper',
                items: containerItems
            };
        }

        return field;
    },

    /**
     * Строит поле для массива.
     *
     * @param {Object} af  Конфигурация
     * @param {Array}  arr Массив данных
     * @return {Object} Ext component config
     */
    buildArrayField: function(af, arr) {
        switch (af.display) {
            case 'tags':
                return this.buildTagsField(af, arr);
            case 'table':
                return this.buildTableField(af, arr);
            case 'images':
                return this.buildImagesField(af, arr);
            case 'list':
                return this.buildListField(af, arr);
            case 'specs':
                return this.buildSpecsField(af, arr);
            case 'cast_table':
                return this.buildCastTableField(af, arr);
            case 'crew_table':
                return this.buildCrewTableField(af, arr);
            case 'companies':
                return this.buildCompaniesField(af, arr);
            case 'networks':
                return this.buildNetworksField(af, arr);
            case 'incidents_timeline':
                return this.buildIncidentsTimelineField(af, arr);
            default:
                return this.buildTagsField(af, arr);
        }
    },

    /**
     * Теги (genres, platforms, etc.)
     */
    buildTagsField: function(af, arr) {
        var tags = arr.map(function(item) {
            var text = (typeof item === 'string') ? item : (item.name || item.title || item);
            return '<span class="spookyapp-tag">' + Ext.util.Format.htmlEncode(String(text)) + '</span>';
        }).join(' ');

        return {
            xtype: 'panel',
            border: false,
            cls: 'spookyapp-detail-tags-field',
            html: '<label class="x-form-item-label" style="display:block;font-weight:bold;margin-bottom:3px;">'
                + Ext.util.Format.htmlEncode(af.label) + '</label>'
                + '<div class="spookyapp-tags-container">' + tags + '</div>',
            style: 'margin-bottom: 8px;'
        };
    },

    /**
     * Таблица (cast, crew, seasons, etc.)
     */
    buildTableField: function(af, arr) {
        var columns = af.columns || [];
        var headerHtml = '<tr>';
        Ext.each(columns, function(col) {
            headerHtml += '<th style="padding:4px 8px;text-align:left;border-bottom:1px solid #ddd;">'
                + Ext.util.Format.htmlEncode(col) + '</th>';
        });
        headerHtml += '</tr>';

        var bodyHtml = '';
        var maxRows = 30;
        Ext.each(arr.slice(0, maxRows), function(item) {
            bodyHtml += '<tr>';
            Ext.each(columns, function(col) {
                var val = item[col] !== undefined ? item[col] : '';
                if (col === 'photo' && val) {
                    val = '<img src="' + Ext.util.Format.htmlEncode(String(val))
                        + '" style="width:30px;height:auto;border-radius:3px;" />';
                } else {
                    val = Ext.util.Format.htmlEncode(String(val));
                }
                bodyHtml += '<td style="padding:4px 8px;border-bottom:1px solid #f0f0f0;">' + val + '</td>';
            });
            bodyHtml += '</tr>';
        });

        if (arr.length > maxRows) {
            bodyHtml += '<tr><td colspan="' + columns.length
                + '" style="padding:4px 8px;color:#999;font-style:italic;">'
                + '... and ' + (arr.length - maxRows) + ' more</td></tr>';
        }

        return {
            xtype: 'fieldset',
            title: af.label + ' (' + arr.length + ')',
            collapsible: true,
            collapsed: arr.length > 5,
            autoScroll: true,
            style: 'max-height: 300px;',
            html: '<table class="spookyapp-detail-table" style="width:100%;border-collapse:collapse;font-size:12px;">'
                + '<thead>' + headerHtml + '</thead>'
                + '<tbody>' + bodyHtml + '</tbody>'
                + '</table>'
        };
    },

    /**
     * Изображения (screenshots, photos)
     */
    buildImagesField: function(af, arr) {
        var html = '<div class="spookyapp-detail-images" style="display:flex;flex-wrap:wrap;gap:5px;">';
        Ext.each(arr.slice(0, 10), function(url) {
            var imgUrl = typeof url === 'string' ? url : (url.image || url.file_path || '');
            if (imgUrl) {
                html += '<a href="' + Ext.util.Format.htmlEncode(imgUrl)
                    + '" target="_blank"><img src="' + Ext.util.Format.htmlEncode(imgUrl)
                    + '" style="width:100px;height:auto;border-radius:3px;" /></a>';
            }
        });
        html += '</div>';

        return {
            xtype: 'fieldset',
            title: af.label + ' (' + arr.length + ')',
            collapsible: true,
            collapsed: true,
            html: html
        };
    },

    /**
     * Список (similar movies, etc.)
     */
    buildListField: function(af, arr) {
        var html = '<ul class="spookyapp-detail-list" style="list-style:none;padding:0;margin:0;">';
        Ext.each(arr.slice(0, 10), function(item) {
            var title = item[af.titleKey || 'title'] || item.name || '';
            var year = item.year || '';
            var rating = item.rating || '';
            html += '<li style="padding:3px 0;border-bottom:1px solid #f0f0f0;">'
                + '<b>' + Ext.util.Format.htmlEncode(title) + '</b>';
            if (year) html += ' (' + Ext.util.Format.htmlEncode(String(year)) + ')';
            if (rating) html += ' ★' + parseFloat(rating).toFixed(1);
            html += '</li>';
        });
        html += '</ul>';

        return {
            xtype: 'fieldset',
            title: af.label + ' (' + arr.length + ')',
            collapsible: true,
            collapsed: true,
            html: html
        };
    },

    /**
     * Спецификации (для device) — редактируемые поля с сохранением правок.
     */
    buildSpecsField: function(af, arr) {
        var me = this;

        // Текущие правки из данных (specs_overrides: {Section: {Key: "val"}})
        var overrides = (me.currentData && me.currentData.specs_overrides)
            ? me.currentData.specs_overrides
            : {};

        // Фиксируем external_id — используется для сохранения
        var extId = me.currentData && me.currentData.id ? me.currentData.id : '';

        // Уникальный префикс для id полей, чтобы избежать коллизий
        var pfx = 'spookyapp-specsedit-' + Ext.id(null, 'sp');

        // ── Строим fieldset с одним контейнером для полей ────────────────
        var sectionItems = [];

        Ext.each(arr, function(section) {
            if (!section || !section.specs || !Ext.isArray(section.specs)) {
                return;
            }

            var stitle = section.title || '';
            var sectionOverrides = overrides[stitle] || {};
            var specFields = [];

            Ext.each(section.specs, function(spec) {
                var k = spec.key || '';
                var v = spec.val || spec.value || '';
                if (!k && !v) return;

                // Если есть правка — показываем override-значение; иначе — API
                var fieldVal = sectionOverrides[k] !== undefined ? sectionOverrides[k] : v;
                var isOverridden = sectionOverrides[k] !== undefined;

                var fieldId = pfx + '-' + stitle.replace(/\s+/g, '_') + '-' + k.replace(/\s+/g, '_');

                specFields.push({
                    xtype: 'container',
                    layout: 'hbox',
                    style: 'margin-bottom:3px;',
                    items: [
                        {
                            xtype: 'label',
                            text: k || '\u00a0',
                            style: 'width:140px;min-width:140px;padding-top:4px;font-size:11px;'
                                + (isOverridden ? 'color:#1a6b1a;font-weight:bold;' : 'color:#555;'),
                            cls: 'spookyapp-spec-label'
                        },
                        {
                            xtype: 'textfield',
                            id: fieldId,
                            value: fieldVal,
                            flex: 1,
                            height: 24,
                            style: isOverridden
                                ? 'border-color:#4caf50!important;background:#f5fff5;'
                                : '',
                            listeners: {
                                change: function(field) {
                                    var cur = field.getValue();
                                    var orig = field.initialConfig.specOriginal || '';
                                    if (cur !== orig && cur !== '') {
                                        field.el.applyStyles('border-color:#4caf50!important;background:#f5fff5;');
                                    } else if (cur === '') {
                                        field.el.applyStyles('border-color:#e53935!important;background:#fff8f8;');
                                    } else {
                                        field.el.applyStyles('border-color:;background:;');
                                    }
                                },
                                blur: function(field) {
                                    var cur = field.getValue();
                                    var orig = field.initialConfig.specOriginal || '';
                                    // Сохраняем только если значение изменилось
                                    if (cur === orig) return;
                                    me.doSaveSpecsOverrides(pfx, arr, extId, true);
                                }
                            },
                            // Метаданные для сбора при сохранении
                            specSection: stitle,
                            specKey: k,
                            specOriginal: v
                        }
                    ]
                });
            });

            if (specFields.length === 0) return;

            var hasOverride = Object.keys(sectionOverrides).length > 0;
            sectionItems.push({
                xtype: 'fieldset',
                title: stitle || 'Specs',
                collapsible: true,
                collapsed: !hasOverride, // Раскрыты только разделы с правками
                cls: hasOverride ? 'spookyapp-specs-section-overridden' : '',
                style: hasOverride ? 'border-color:#4caf50;' : '',
                items: specFields
            });
        });

        return {
            xtype: 'fieldset',
            title: af.label + ' (' + arr.length + ' ' + (_('spookyapp.chunkgenerator.specs_sections') || 'разделов') + ')',
            collapsible: true,
            collapsed: false,
            autoScroll: true,
            style: 'max-height:560px;overflow-y:auto;',
            items: [
                {
                    xtype: 'panel',
                    border: false,
                    bodyStyle: 'padding:4px 0 2px;color:#666;font-size:11px;',
                    html: '<i>' + (_('spookyapp.chunkgenerator.specs_edit_hint')
                        || 'Правки сохраняются автоматически при переходе к следующему полю. Зелёная рамка = сохранено. Пустое поле = вернуть оригинал из API.') + '</i>'
                }
            ].concat(sectionItems)
        };
    },

    /**
     * Сохранить правки спецификаций в БД.
     * Собирает все поля по префиксу pfx из sections arr.
     */
    doSaveSpecsOverrides: function(pfx, arr, extId, quiet) {
        var overrides = {};

        Ext.each(arr, function(section) {
            if (!section || !section.specs) return;
            var stitle = section.title || '';

            Ext.each(section.specs, function(spec) {
                var k = spec.key || '';
                if (!k) return;

                var fieldId = pfx + '-' + stitle.replace(/\s+/g, '_') + '-' + k.replace(/\s+/g, '_');
                var field = Ext.getCmp(fieldId);
                if (!field) return;

                var val = field.getValue();
                var orig = spec.val || spec.value || '';

                // Сохраняем только если значение изменено или явно пусто (сброс)
                if (val !== orig) {
                    if (!overrides[stitle]) overrides[stitle] = {};
                    overrides[stitle][k] = val;
                }
            });
        });

        if (Object.keys(overrides).length === 0) {
            MODx.msg.status(_('spookyapp.chunkgenerator.no_changes') || 'Нет изменений для сохранения');
            return;
        }

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/savespecsoverrides',
                external_id: extId,
                overrides: Ext.encode(overrides)
            },
            listeners: {
                success: {
                    fn: function(r) {
                        if (r.object && r.object.overrides) {
                            if (this.currentData) {
                                this.currentData.specs_overrides = r.object.overrides;
                            }
                        }
                        if (!quiet) {
                            var cnt = (r.object && r.object.saved_count) || 0;
                            MODx.msg.status(
                                (_('spookyapp.chunkgenerator.specs_saved') || 'Правки сохранены') + ': ' + cnt
                            );
                        }
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        MODx.msg.alert(_('error'), r.message || 'Failed to save specs overrides');
                    }
                }
            }
        });
    },

    /**
     * Сбросить все правки (сохранить пустой объект).
     */
    doResetSpecsOverrides: function(pfx, arr, extId) {
        MODx.msg.confirm({
            title: _('spookyapp.chunkgenerator.reset_spec_overrides') || 'Сбросить правки',
            text: _('spookyapp.chunkgenerator.reset_spec_overrides_confirm')
                || 'Сбросить все правки и показывать оригинальные значения из API?',
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/savespecsoverrides',
                external_id: extId,
                overrides: '{}'
            },
            listeners: {
                success: {
                    fn: function() {
                        if (this.currentData) {
                            this.currentData.specs_overrides = {};
                        }
                        MODx.msg.status(_('spookyapp.chunkgenerator.specs_reset') || 'Правки сброшены');
                        this.reloadDetails();
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Таблица актёров с кнопками перевода имени и роли.
     */
    buildCastTableField: function(af, arr) {
        var uid = Ext.id(null, 'spa-c-');
        var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        html += '<thead><tr style="background:#f5f5f5;">';
        html += '<th style="padding:4px;width:38px;"></th>';
        html += '<th style="padding:4px 6px;text-align:left;">Имя <span style="font-size:10px;color:#bbb;">/ orig.</span></th>';
        html += '<th style="padding:4px 6px;text-align:left;">Роль</th>';
        html += '<th style="padding:4px;width:45px;text-align:center;">★</th>';
        html += '</tr></thead><tbody>';
        var limit = Math.min(arr.length, 40);
        for (var i = 0; i < limit; i++) {
            var p = arr[i];
            var bg = i % 2 === 0 ? '#fff' : '#fafafa';
            var ni = uid + 'n' + i;
            var ci = uid + 'c' + i;
            html += '<tr style="background:' + bg + ';vertical-align:top;">';
            html += '<td style="padding:2px 4px;">';
            if (p.photo) html += '<img src="' + Ext.util.Format.htmlEncode(p.photo) + '" style="width:36px;height:auto;border-radius:3px;" />';
            html += '</td>';
            html += '<td style="padding:3px 6px;">';
            html += '<div style="font-weight:bold;">' + Ext.util.Format.htmlEncode(p.name || '') + '</div>';
            if (p.original_name && p.original_name !== p.name) {
                html += '<div style="font-size:10px;color:#aaa;">' + Ext.util.Format.htmlEncode(p.original_name) + '</div>';
            }
            html += '<input id="' + ni + '" type="text" placeholder="Рус. имя..." '
                + 'style="width:100%;box-sizing:border-box;margin-top:2px;font-size:11px;border:1px solid #e0e0e0;border-radius:3px;padding:2px 4px;" />';
            html += '<a href="#" class="spa-cast-tr" data-text="' + Ext.util.Format.htmlEncode(p.name || '') + '" '
                + 'data-target="' + ni + '" style="font-size:10px;color:#bbb;text-decoration:none;">✎ ru</a>';
            html += '</td>';
            html += '<td style="padding:3px 6px;">';
            html += '<div>' + Ext.util.Format.htmlEncode(p.character || '') + '</div>';
            html += '<input id="' + ci + '" type="text" placeholder="Рус. роль..." '
                + 'style="width:100%;box-sizing:border-box;margin-top:2px;font-size:11px;border:1px solid #e0e0e0;border-radius:3px;padding:2px 4px;" />';
            html += '<a href="#" class="spa-cast-tr" data-text="' + Ext.util.Format.htmlEncode(p.character || '') + '" '
                + 'data-target="' + ci + '" style="font-size:10px;color:#bbb;text-decoration:none;">✎ ru</a>';
            html += '</td>';
            html += '<td style="padding:3px 4px;text-align:center;color:#999;font-size:11px;">' + (p.popularity || '') + '</td>';
            html += '</tr>';
        }
        if (arr.length > limit) {
            html += '<tr><td colspan="4" style="padding:4px 8px;color:#999;font-style:italic;">... и ещё ' + (arr.length - limit) + '</td></tr>';
        }
        html += '</tbody></table>';
        return {
            xtype: 'panel',
            border: true,
            title: af.label + ' (' + arr.length + ')',
            collapsible: true,
            collapsed: arr.length > 5,
            autoScroll: true,
            style: 'max-height:420px;margin-bottom:8px;',
            html: html,
            listeners: {
                afterrender: function(panel) {
                    panel.getEl().on('click', function(e) {
                        var t = e.getTarget('.spa-cast-tr');
                        if (!t) return;
                        e.preventDefault();
                        var text = t.getAttribute('data-text');
                        var targetId = t.getAttribute('data-target');
                        var el = document.getElementById(targetId);
                        if (!text || !el) return;
                        el.value = '...';
                        MODx.Ajax.request({
                            url: SpookyApp.config.connector_url,
                            params: { action: 'chunkgenerator/translate', text: text, source_lang: 'en', target_lang: 'ru' },
                            listeners: {
                                success: { fn: function(r) { el.value = (r.object && r.object.translated_text) || ''; } },
                                failure: { fn: function() { el.value = ''; } }
                            }
                        });
                    });
                }
            }
        };
    },

    /**
     * Таблица съёмочной группы.
     */
    buildCrewTableField: function(af, arr) {
        var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        html += '<thead><tr style="background:#f5f5f5;">';
        html += '<th style="padding:4px;width:38px;"></th>';
        html += '<th style="padding:4px 6px;text-align:left;">Имя</th>';
        html += '<th style="padding:4px 6px;text-align:left;font-size:11px;color:#888;">Ориг.</th>';
        html += '<th style="padding:4px 6px;text-align:left;">Должность</th>';
        html += '<th style="padding:4px 6px;text-align:left;font-size:11px;color:#888;">Отдел</th>';
        html += '</tr></thead><tbody>';
        var limit = Math.min(arr.length, 30);
        for (var i = 0; i < limit; i++) {
            var p = arr[i];
            var bg = i % 2 === 0 ? '#fff' : '#fafafa';
            html += '<tr style="background:' + bg + ';">';
            html += '<td style="padding:2px 4px;">';
            if (p.photo) html += '<img src="' + Ext.util.Format.htmlEncode(p.photo) + '" style="width:36px;height:auto;border-radius:3px;" />';
            html += '</td>';
            html += '<td style="padding:3px 6px;font-weight:bold;">' + Ext.util.Format.htmlEncode(p.name || '') + '</td>';
            html += '<td style="padding:3px 6px;font-size:11px;color:#aaa;">' + Ext.util.Format.htmlEncode(p.original_name || '') + '</td>';
            html += '<td style="padding:3px 6px;">' + Ext.util.Format.htmlEncode(p.job || '') + '</td>';
            html += '<td style="padding:3px 6px;font-size:11px;color:#888;">' + Ext.util.Format.htmlEncode(p.department || '') + '</td>';
            html += '</tr>';
        }
        if (arr.length > limit) {
            html += '<tr><td colspan="5" style="padding:4px 8px;color:#999;font-style:italic;">... и ещё ' + (arr.length - limit) + '</td></tr>';
        }
        html += '</tbody></table>';
        return {
            xtype: 'panel',
            border: true,
            title: af.label + ' (' + arr.length + ')',
            collapsible: true,
            collapsed: true,
            autoScroll: true,
            style: 'max-height:350px;margin-bottom:8px;',
            html: html
        };
    },

    /**
     * Карточки студий/компаний.
     */
    buildCompaniesField: function(af, arr) {
        var html = '<div style="display:flex;flex-wrap:wrap;gap:8px;padding:4px;">';
        Ext.each(arr, function(c) {
            html += '<div style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;'
                + 'background:#f9f9f9;border:1px solid #e8e8e8;border-radius:4px;">';
            if (c.logo_path) {
                html += '<img src="' + Ext.util.Format.htmlEncode(c.logo_path)
                    + '" style="height:22px;width:auto;max-width:80px;object-fit:contain;" />';
            }
            html += '<span style="font-size:12px;">' + Ext.util.Format.htmlEncode(c.name || '') + '</span>';
            html += '</div>';
        });
        html += '</div>';
        return {
            xtype: 'panel',
            border: false,
            html: '<label class="x-form-item-label" style="display:block;font-weight:bold;margin-bottom:3px;">'
                + Ext.util.Format.htmlEncode(af.label) + '</label>' + html,
            style: 'margin-bottom:8px;'
        };
    },

    /**
     * Карточки телеканалов/сетей.
     */
    buildNetworksField: function(af, arr) {
        var html = '<div style="display:flex;flex-wrap:wrap;gap:8px;padding:4px;">';
        Ext.each(arr, function(n) {
            var name = (typeof n === 'string') ? n : (n.name || '');
            var logo = (typeof n === 'object') ? (n.logo_path || null) : null;
            html += '<div style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;'
                + 'background:#f9f9f9;border:1px solid #e8e8e8;border-radius:4px;">';
            if (logo) {
                html += '<img src="' + Ext.util.Format.htmlEncode(logo)
                    + '" style="height:22px;width:auto;max-width:80px;object-fit:contain;" />';
            }
            html += '<span style="font-size:12px;">' + Ext.util.Format.htmlEncode(name) + '</span>';
            html += '</div>';
        });
        html += '</div>';
        return {
            xtype: 'panel',
            border: false,
            html: '<label class="x-form-item-label" style="display:block;font-weight:bold;margin-bottom:3px;">'
                + Ext.util.Format.htmlEncode(af.label) + '</label>' + html,
            style: 'margin-bottom:8px;'
        };
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Incidents Timeline (SportAPI7)                         ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Таймлайн событий матча: голы, карточки, замены.
     * data — объект {goals, cards, substitutions, timeline}
     */
    buildIncidentsTimelineField: function(af, data) {
        var timeline  = data.timeline      || [];
        var goals     = data.goals         || [];
        var cards     = data.cards         || [];
        var subs      = data.substitutions || [];

        if (!timeline.length && !goals.length) {
            return null;
        }

        var iStyle = 'border:1px solid #ccc;border-radius:3px;padding:1px 4px;font-size:11px;background:#fffde7;width:110px;vertical-align:middle;';

        // ── Таблица голов ──────────────────────────────────────
        var goalsHtml = '';
        if (goals.length > 0) {
            goalsHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px;">'
                + '<thead><tr>'
                + '<th style="width:48%;text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">\u0425\u043e\u0437\u044f\u0435\u0432\u0430</th>'
                + '<th style="width:10%;text-align:center;padding:4px;border-bottom:2px solid #ddd;">\u23F1</th>'
                + '<th style="width:42%;text-align:right;padding:4px 6px;border-bottom:2px solid #ddd;">\u0413\u043e\u0441\u0442\u0438</th>'
                + '</tr></thead><tbody>';

            var goalI = 0;
            Ext.each(goals, function(g) {
                var gi   = goalI++;
                var pInp = '<input type="text" id="spookyapp-inc-goal-' + gi + '-player" value="' + Ext.util.Format.htmlEncode(g.player) + '" style="' + iStyle + '">';
                var aInp = g.assist
                    ? '<input type="text" id="spookyapp-inc-goal-' + gi + '-assist" value="' + Ext.util.Format.htmlEncode(g.assist) + '" style="' + iStyle + ';font-size:10px;" placeholder="\u0410\u0441\u0441\u0438\u0441\u0442">'
                    : '';
                var score = Ext.util.Format.htmlEncode(g.score);
                var pHome = g.isHome
                    ? ('\u26BD\uFE0F ' + pInp + (aInp ? ' ' + aInp : '') + ' <b>' + score + '</b>')
                    : '';
                var pAway = !g.isHome
                    ? ('<b>' + score + '</b> \u26BD\uFE0F ' + pInp + (aInp ? ' ' + aInp : ''))
                    : '';
                goalsHtml += '<tr>'
                    + '<td style="padding:3px 6px;border-bottom:1px solid #f0f0f0;">' + pHome + '</td>'
                    + '<td style="padding:3px 4px;text-align:center;color:#666;border-bottom:1px solid #f0f0f0;">' + Ext.util.Format.htmlEncode(g.time_label) + '</td>'
                    + '<td style="padding:3px 6px;text-align:right;border-bottom:1px solid #f0f0f0;">' + pAway + '</td>'
                    + '</tr>';
            });
            goalsHtml += '</tbody></table>';
        }

        // ── Карточки ───────────────────────────────────────────
        var cardsHtml = '';
        if (cards.length > 0) {
            cardsHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px;">'
                + '<thead><tr>'
                + '<th style="width:48%;text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">\u0425\u043e\u0437\u044f\u0435\u0432\u0430</th>'
                + '<th style="width:10%;text-align:center;padding:4px;border-bottom:2px solid #ddd;">\u23F1</th>'
                + '<th style="width:42%;text-align:right;padding:4px 6px;border-bottom:2px solid #ddd;">\u0413\u043e\u0441\u0442\u0438</th>'
                + '</tr></thead><tbody>';

            var cardI = 0;
            Ext.each(cards, function(c) {
                var ci   = cardI++;
                var icon = c['class'] === 'yellowRed' ? '\uD83D\uDFE5\uD83D\uDFE8' : (c['class'] === 'red' ? '\uD83D\uDFE5' : '\uD83D\uDFE8');
                var pInp = '<input type="text" id="spookyapp-inc-card-' + ci + '-player" value="' + Ext.util.Format.htmlEncode(c.player) + '" style="' + iStyle + '">';
                var info = icon + ' ' + pInp
                    + (c.reason ? ' <span style="color:#888;font-size:11px;">(' + Ext.util.Format.htmlEncode(c.reason) + ')</span>' : '');
                var pHome = c.isHome ? info : '';
                var pAway = !c.isHome ? info : '';
                cardsHtml += '<tr>'
                    + '<td style="padding:3px 6px;border-bottom:1px solid #f0f0f0;">' + pHome + '</td>'
                    + '<td style="padding:3px 4px;text-align:center;color:#666;border-bottom:1px solid #f0f0f0;">' + Ext.util.Format.htmlEncode(c.time_label) + '</td>'
                    + '<td style="padding:3px 6px;text-align:right;border-bottom:1px solid #f0f0f0;">' + pAway + '</td>'
                    + '</tr>';
            });
            cardsHtml += '</tbody></table>';
        }

        // ── Замены ─────────────────────────────────────────────
        var subsHtml = '';
        if (subs.length > 0) {
            subsHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px;">'
                + '<thead><tr>'
                + '<th style="width:48%;text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">\u0425\u043e\u0437\u044f\u0435\u0432\u0430</th>'
                + '<th style="width:10%;text-align:center;padding:4px;border-bottom:2px solid #ddd;">\u23F1</th>'
                + '<th style="width:42%;text-align:right;padding:4px 6px;border-bottom:2px solid #ddd;">\u0413\u043e\u0441\u0442\u0438</th>'
                + '</tr></thead><tbody>';

            var subI = 0;
            Ext.each(subs, function(s) {
                var si   = subI++;
                var inInp  = '<input type="text" id="spookyapp-inc-sub-' + si + '-in"  value="' + Ext.util.Format.htmlEncode(s.playerIn)  + '" style="' + iStyle + '">';
                var outInp = '<input type="text" id="spookyapp-inc-sub-' + si + '-out" value="' + Ext.util.Format.htmlEncode(s.playerOut) + '" style="' + iStyle + '">';
                var info = '\uD83D\uDD04 \u2191' + inInp + ' \u2193' + outInp;
                var pHome = s.isHome ? info : '';
                var pAway = !s.isHome ? info : '';
                subsHtml += '<tr>'
                    + '<td style="padding:3px 6px;border-bottom:1px solid #f0f0f0;">' + pHome + '</td>'
                    + '<td style="padding:3px 4px;text-align:center;color:#666;border-bottom:1px solid #f0f0f0;">' + Ext.util.Format.htmlEncode(s.time_label) + '</td>'
                    + '<td style="padding:3px 6px;text-align:right;border-bottom:1px solid #f0f0f0;">' + pAway + '</td>'
                    + '</tr>';
            });
            subsHtml += '</tbody></table>';
        }

        var html = '';
        if (goalsHtml) {
            html += '<p style="font-weight:bold;margin:6px 0 2px;font-size:12px;">\u0413\u043e\u043b\u044b</p>' + goalsHtml;
        }
        if (cardsHtml) {
            html += '<p style="font-weight:bold;margin:6px 0 2px;font-size:12px;">\u041a\u0430\u0440\u0442\u043e\u0447\u043a\u0438</p>' + cardsHtml;
        }
        if (subsHtml) {
            html += '<p style="font-weight:bold;margin:6px 0 2px;font-size:12px;">\u0417\u0430\u043c\u0435\u043d\u044b</p>' + subsHtml;
        }

        if (!html) return null;

        return {
            xtype: 'fieldset',
            title: af.label + ' (' + (goals.length + cards.length + subs.length) + ')',
            collapsible: true,
            collapsed: false,
            autoScroll: true,
            style: 'max-height:500px;',
            html: html
        };
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Value Formatters                                       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Форматировать значение для отображения.
     *
     * @param {Mixed}  value    Значение
     * @param {String} renderer Тип рендерера
     * @return {String}
     */
    formatValue: function(value, renderer) {
        if (value === null || value === undefined) return '';

        switch (renderer) {
            case 'rating':
                var v = parseFloat(value);
                if (isNaN(v) || v === 0) return '—';
                var color = v >= 7 ? '#4caf50' : (v >= 5 ? '#ff9800' : '#f44336');
                return '<span style="color:' + color + ';font-weight:bold;">★ ' + v.toFixed(1) + '</span>';

            case 'currency':
                var num = parseInt(value, 10);
                if (!num) return '—';
                return '$' + num.toLocaleString();

            case 'link':
                if (!value) return '—';
                return '<a href="' + Ext.util.Format.htmlEncode(String(value))
                    + '" target="_blank">' + Ext.util.Format.htmlEncode(String(value)) + '</a>';

            default:
                return Ext.util.Format.htmlEncode(String(value));
        }
    },

    /**
     * Получить вложенное значение из объекта по ключу (dot notation).
     *
     * @param {Object} obj Объект
     * @param {String} key Ключ (e.g. 'a.b.c')
     * @return {Mixed}
     */
    getNestedValue: function(obj, key) {
        var parts = key.split('.');
        var current = obj;
        for (var i = 0; i < parts.length; i++) {
            if (current === null || current === undefined) return undefined;
            current = current[parts[i]];
        }
        return current;
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Actions: Translate                                     ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Перевести значение поля.
     *
     * @param {String} fieldId ID поля
     * @param {String} key     Ключ данных
     */
    doTranslateField: function(fieldId, key) {
        var field = Ext.getCmp(fieldId);
        if (!field) return;

        var text = field.getValue ? field.getValue() : '';
        if (!text) {
            MODx.msg.alert(_('warning'), _('spookyapp.chunkgenerator.err_text_empty') || 'Text is empty');
            return;
        }

        // ── Диалог выбора языков ─────────────────────────────
        var dialog = new Ext.Window({
            title: _('spookyapp.chunkgenerator.translate') || 'Translate',
            width: 400,
            height: 200,
            modal: true,
            layout: 'form',
            bodyStyle: 'padding: 15px;',
            labelWidth: 100,
            items: [
                {
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.source_lang') || 'Source',
                    id: 'spookyapp-translate-source',
                    store: new Ext.data.ArrayStore({
                        fields: ['code', 'name'],
                        data: [['en', 'English'], ['ru', 'Русский'], ['de', 'Deutsch'], ['fr', 'Français'], ['es', 'Español']]
                    }),
                    displayField: 'name',
                    valueField: 'code',
                    mode: 'local',
                    triggerAction: 'all',
                    value: 'en',
                    anchor: '100%'
                },
                {
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.target_lang') || 'Target',
                    id: 'spookyapp-translate-target',
                    store: new Ext.data.ArrayStore({
                        fields: ['code', 'name'],
                        data: [['ru', 'Русский'], ['en', 'English'], ['de', 'Deutsch'], ['fr', 'Français'], ['es', 'Español']]
                    }),
                    displayField: 'name',
                    valueField: 'code',
                    mode: 'local',
                    triggerAction: 'all',
                    value: 'ru',
                    anchor: '100%'
                }
            ],
            buttons: [
                {
                    text: _('spookyapp.chunkgenerator.translate') || 'Translate',
                    cls: 'primary-button',
                    handler: function() {
                        var sourceLang = Ext.getCmp('spookyapp-translate-source').getValue();
                        var targetLang = Ext.getCmp('spookyapp-translate-target').getValue();
                        dialog.close();
                        this.executeTranslation(fieldId, text, sourceLang, targetLang);
                    },
                    scope: this
                },
                {
                    text: _('cancel'),
                    handler: function() { dialog.close(); }
                }
            ]
        });

        dialog.show();
    },

    /**
     * Выполнить перевод.
     *
     * @param {String} fieldId    ID поля
     * @param {String} text       Текст
     * @param {String} sourceLang Исходный язык
     * @param {String} targetLang Целевой язык
     */
    executeTranslation: function(fieldId, text, sourceLang, targetLang) {
        var mask = new Ext.LoadMask(this.getEl(), {
            msg: _('spookyapp.chunkgenerator.translating') || 'Translating...'
        });
        mask.show();

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/translate',
                text: text,
                source_lang: sourceLang,
                target_lang: targetLang
            },
            listeners: {
                success: {
                    fn: function(r) {
                        mask.hide();
                        var translated = r.object ? r.object.translated_text : '';
                        if (translated) {
                            var field = Ext.getCmp(fieldId);
                            if (field && field.setValue) {
                                field.setValue(translated);
                            }
                            MODx.msg.status({
                                title: _('success'),
                                message: _('spookyapp.chunkgenerator.translated') || 'Translated successfully'
                            });
                        }
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        mask.hide();
                        MODx.msg.alert(_('error'), r.message || 'Translation failed');
                    }
                }
            }
        });
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Actions: Voiceover                                     ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Озвучить матч SportAPI7 (составной текст из нескольких полей).
     *
     * @param {Object} data Данные матча
     */
    doVoiceoverSportapi: function(data) {
        var parts = [];

        // Дата
        var date = data.date || '';
        if (date) parts.push(date + '.');

        // Турнир
        var tournament = (data.tournament && data.tournament.name) ? data.tournament.name : '';
        if (tournament) parts.push('Турнир: ' + tournament + '.');

        // Тур / Раунд
        var round = data.round || '';
        if (round) parts.push('Тур: ' + round + '.');

        // Команды
        var homeTeam = (data.home_team && data.home_team.name) ? data.home_team.name : (data.home_team || '');
        var awayTeam = (data.away_team && data.away_team.name) ? data.away_team.name : (data.away_team || '');
        if (homeTeam || awayTeam) {
            parts.push((homeTeam || '?') + ' против ' + (awayTeam || '?') + '.');
        }

        // Счёт
        var scoreHome = (data.score && data.score.home !== undefined) ? data.score.home : null;
        var scoreAway = (data.score && data.score.away !== undefined) ? data.score.away : null;
        if (scoreHome !== null && scoreAway !== null) {
            parts.push('Счёт: ' + scoreHome + ' — ' + scoreAway + '.');
        }

        // События матча (голы, карточки, замены)
        var incidents = data.incidents;
        if (incidents) {
            var eventParts = [];

            // Голы
            var goals = incidents.goals || [];
            Ext.each(goals, function(g) {
                var team = g.team === 'home' ? homeTeam : awayTeam;
                var minute = g.minute ? g.minute + '\'' : '';
                var scorer = g.player_name || '';
                if (scorer || minute) {
                    eventParts.push('Гол' + (minute ? ' ' + minute : '') + (team ? ', ' + team : '') + (scorer ? ' — ' + scorer : '') + '.');
                }
            });

            // Карточки
            var cards = incidents.cards || [];
            Ext.each(cards, function(c) {
                var team = c.team === 'home' ? homeTeam : awayTeam;
                var minute = c.minute ? c.minute + '\'' : '';
                var player = c.player_name || '';
                var color = c.type === 'red' ? 'Красная карточка' : 'Жёлтая карточка';
                if (player || minute) {
                    eventParts.push(color + (minute ? ' ' + minute : '') + (team ? ', ' + team : '') + (player ? ' — ' + player : '') + '.');
                }
            });

            if (eventParts.length > 0) {
                parts.push('События: ' + eventParts.join(' '));
            }
        }

        var text = parts.join(' ');

        if (!text) {
            MODx.msg.alert(_('warning'), _('spookyapp.chunkgenerator.err_text_empty') || 'Text is empty');
            return;
        }

        // Переиспользуем стандартный диалог voiceover
        this.doVoiceoverText(text);
    },

    /**
     * Озвучить фильм (возрастной рейтинг, название, сюжет, актёры, режиссёр, дата премьеры, продолжительность).
     *
     * @param {Object} data Данные фильма
     */
    doVoiceoverMovie: function(data) {
        var parts = [];

        var ageRating = data.age_rating || data.content_rating || '';
        if (ageRating) parts.push('Возрастной рейтинг: ' + ageRating + '.');

        var title = data.title || '';
        if (title) parts.push(title + '.');

        var overview = data.overview || '';
        if (overview) parts.push(overview);

        // Главные актёры (топ 5)
        var cast = data.cast;
        if (cast && Ext.isArray(cast) && cast.length > 0) {
            var castNames = [];
            Ext.each(cast.slice(0, 5), function(p) {
                if (p.name) castNames.push(p.name);
            });
            if (castNames.length > 0) {
                parts.push('В главных ролях: ' + castNames.join(', ') + '.');
            }
        }

        // Режиссёр
        var crew = data.crew;
        if (crew && Ext.isArray(crew)) {
            var directors = [];
            Ext.each(crew, function(p) {
                if (p.job === 'Director' && p.name) directors.push(p.name);
            });
            if (directors.length > 0) {
                parts.push('Режиссёр: ' + directors.join(', ') + '.');
            }
        }

        var releaseDate = data.release_date || '';
        if (releaseDate) parts.push('Дата премьеры: ' + releaseDate + '.');

        var runtime = data.runtime || 0;
        if (runtime) parts.push('Продолжительность: ' + runtime + ' мин.');

        var text = parts.join(' ');

        if (!text) {
            MODx.msg.alert(_('warning'), _('spookyapp.chunkgenerator.err_text_empty') || 'Text is empty');
            return;
        }

        this.doVoiceoverText(text);
    },

    /**
     * Озвучить сериал (возрастной рейтинг, название, сюжет, сезоны/серии, продолжительность, последняя серия, создатели).
     *
     * @param {Object} data Данные сериала
     */
    doVoiceoverTV: function(data) {
        var parts = [];

        var ageRating = data.age_rating || data.content_rating || '';
        if (ageRating) parts.push('Возрастной рейтинг: ' + ageRating + '.');

        var title = data.title || '';
        if (title) parts.push(title + '.');

        var overview = data.overview || '';
        if (overview) parts.push(overview);

        var seasons = data.number_of_seasons || 0;
        var episodes = data.number_of_episodes || 0;
        if (seasons || episodes) {
            var seStr = seasons ? seasons + ' сез.' : '';
            var epStr = episodes ? episodes + ' сер.' : '';
            parts.push('Количество: ' + [seStr, epStr].filter(Boolean).join(', ') + '.'); 
        }

        var episodeRunTime = data.episode_run_time || 0;
        if (episodeRunTime) parts.push('Средняя продолжительность эпизода: ' + episodeRunTime + ' мин.');

        // Последняя вышедшая серия
        var lastEp = data.last_episode_to_air;
        if (lastEp && lastEp.air_date) {
            parts.push('Последняя серия вышла: ' + lastEp.air_date + '.');
        } else {
            var lastAirDate = data.last_air_date || '';
            if (lastAirDate) parts.push('Последний выход: ' + lastAirDate + '.');
        }

        // Создатели
        var createdBy = data.created_by;
        if (createdBy && Ext.isArray(createdBy) && createdBy.length > 0) {
            var creatorNames = [];
            Ext.each(createdBy, function(p) {
                var n = typeof p === 'object' ? (p.name || '') : String(p);
                if (n) creatorNames.push(n);
            });
            if (creatorNames.length > 0) {
                parts.push('Создатели: ' + creatorNames.join(', ') + '.');
            }
        }

        var text = parts.join(' ');

        if (!text) {
            MODx.msg.alert(_('warning'), _('spookyapp.chunkgenerator.err_text_empty') || 'Text is empty');
            return;
        }

        this.doVoiceoverText(text);
    },

    /**
     * Озвучить персону (name, birthday, deathday, popular movies/tv).
     *
     * @param {Object} data Данные персоны
     */
    doVoiceoverPerson: function(data) {
        var parts = [];

        var name = data.name || '';
        if (name) parts.push(name + '.');

        var birthday = data.birthday || '';
        if (birthday) parts.push('Дата рождения: ' + birthday + '.');

        var deathday = data.deathday || '';
        if (deathday) parts.push('Дата смерти: ' + deathday + '.');

        // Популярные фильмы — топ 5 по rating
        var movieCredits = data.movie_credits;
        if (movieCredits && Ext.isArray(movieCredits) && movieCredits.length > 0) {
            var sorted = [].concat(movieCredits).sort(function(a, b) {
                return ((b.rating || b.vote_average || 0) - (a.rating || a.vote_average || 0));
            });
            var topMovies = [];
            Ext.each(sorted.slice(0, 5), function(m) {
                var t = m.title || m.name || '';
                if (t) topMovies.push(t);
            });
            if (topMovies.length > 0) {
                parts.push('Популярные фильмы: ' + topMovies.join(', ') + '.');
            }
        }

        // Популярные сериалы — топ 5 по rating
        var tvCredits = data.tv_credits;
        if (tvCredits && Ext.isArray(tvCredits) && tvCredits.length > 0) {
            var sortedTv = [].concat(tvCredits).sort(function(a, b) {
                return ((b.rating || b.vote_average || 0) - (a.rating || a.vote_average || 0));
            });
            var topTv = [];
            Ext.each(sortedTv.slice(0, 5), function(m) {
                var t = m.title || m.name || '';
                if (t) topTv.push(t);
            });
            if (topTv.length > 0) {
                parts.push('Популярные сериалы: ' + topTv.join(', ') + '.');
            }
        }

        var text = parts.join(' ');

        if (!text) {
            MODx.msg.alert(_('warning'), _('spookyapp.chunkgenerator.err_text_empty') || 'Text is empty');
            return;
        }

        this.doVoiceoverText(text);
    },

    /**
     * Озвучить игру (title, esrb_rating, released, genres, developers, platforms).
     *
     * @param {Object} data Данные игры
     */
    doVoiceoverGame: function(data) {
        var parts = [];

        var title = data.title || '';
        if (title) parts.push(title + '.');

        var esrb = data.esrb_rating || '';
        if (esrb) parts.push('Возрастной рейтинг: ' + esrb + '.');

        var released = data.released || '';
        if (released) parts.push('Дата выхода: ' + released + '.');

        var genres = data.genres;
        if (genres && Ext.isArray(genres) && genres.length > 0) {
            var genreNames = [];
            Ext.each(genres, function(g) {
                genreNames.push(typeof g === 'object' ? (g.name || '') : String(g));
            });
            parts.push('Жанры: ' + genreNames.filter(Boolean).join(', ') + '.');
        }

        var developers = data.developers;
        if (developers && Ext.isArray(developers) && developers.length > 0) {
            var devNames = [];
            Ext.each(developers, function(d) {
                devNames.push(typeof d === 'object' ? (d.name || '') : String(d));
            });
            parts.push('Разработчик: ' + devNames.filter(Boolean).join(', ') + '.');
        }

        var platforms = data.platforms;
        if (platforms && Ext.isArray(platforms) && platforms.length > 0) {
            var platNames = [];
            Ext.each(platforms, function(p) {
                platNames.push(typeof p === 'object' ? (p.name || '') : String(p));
            });
            parts.push('Платформы: ' + platNames.filter(Boolean).join(', ') + '.');
        }

        var text = parts.join(' ');

        if (!text) {
            MODx.msg.alert(_('warning'), _('spookyapp.chunkgenerator.err_text_empty') || 'Text is empty');
            return;
        }

        this.doVoiceoverText(text);
    },

    /**
     * Озвучить товар (title + description + product_attributes).
     *
     * @param {Object} data Данные товара
     */
    doVoiceoverProduct: function(data) {
        var parts = [];

        var title = data.title || '';
        if (title) parts.push(title + '.');

        var description = data.description || '';
        if (description) {
            // Strip HTML tags for voice
            var cleanDesc = description.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            if (cleanDesc) parts.push(cleanDesc);
        }

        var attrs = data.product_attributes || '';
        if (attrs) {
            // Strip HTML tags
            var cleanAttrs = attrs.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            if (cleanAttrs) parts.push(cleanAttrs);
        }

        var text = parts.join('\n\n');

        if (!text) {
            MODx.msg.alert(_('warning'), _('spookyapp.chunkgenerator.err_text_empty') || 'Text is empty');
            return;
        }

        this.doVoiceoverText(text);
    },

    /**
     * Озвучить значение поля.
     *
     * @param {String} fieldId ID поля
     * @param {String} key     Ключ данных
     */
    doVoiceoverField: function(fieldId, key) {
        var field = Ext.getCmp(fieldId);
        if (!field) return;

        var text = field.getValue ? field.getValue() : '';
        if (!text) {
            MODx.msg.alert(_('warning'), _('spookyapp.chunkgenerator.err_text_empty') || 'Text is empty');
            return;
        }

        this.doVoiceoverText(text);
    },

    /**
     * Открыть диалог озвучки для произвольного текста.
     *
     * @param {String} text Текст для озвучки
     */
    doVoiceoverText: function(text) {
        // ── Диалог настроек ──────────────────────────────────
        var dialog = new Ext.Window({
            title: _('spookyapp.chunkgenerator.voiceover') || 'Voice Over',
            width: 400,
            height: 250,
            modal: true,
            layout: 'form',
            bodyStyle: 'padding: 15px;',
            labelWidth: 100,
            items: [
                {
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.voice') || 'Voice',
                    id: 'spookyapp-voiceover-voice',
                    store: new Ext.data.ArrayStore({
                        fields: ['code', 'name'],
                        data: [
                            ['jane', 'Jane (Female)'],
                            ['zahar', 'Zahar (Male)'],
                            ['alena', 'Alena (Female)'],
                            ['filipp', 'Filipp (Male)'],
                            ['ermil', 'Ermil (Male)'],
                            ['omazh', 'Omazh (Female)']
                        ]
                    }),
                    displayField: 'name',
                    valueField: 'code',
                    mode: 'local',
                    triggerAction: 'all',
                    value: 'jane',
                    anchor: '100%'
                },
                {
                    xtype: 'sliderfield',
                    fieldLabel: _('spookyapp.chunkgenerator.speed') || 'Speed',
                    id: 'spookyapp-voiceover-speed',
                    minValue: 1,
                    maxValue: 30,
                    value: 10,
                    anchor: '100%',
                    tipText: function(thumb) {
                        return (thumb.value / 10).toFixed(1) + 'x';
                    }
                },
                {
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.emotion') || 'Emotion',
                    id: 'spookyapp-voiceover-emotion',
                    store: new Ext.data.ArrayStore({
                        fields: ['code', 'name'],
                        data: [
                            ['neutral', 'Neutral'],
                            ['good', 'Good (Friendly)'],
                            ['evil', 'Evil (Angry)']
                        ]
                    }),
                    displayField: 'name',
                    valueField: 'code',
                    mode: 'local',
                    triggerAction: 'all',
                    value: 'neutral',
                    anchor: '100%'
                }
            ],
            buttons: [
                {
                    text: _('spookyapp.chunkgenerator.generate_audio') || 'Generate Audio',
                    cls: 'primary-button',
                    handler: function() {
                        var voice = Ext.getCmp('spookyapp-voiceover-voice').getValue();
                        var speed = (Ext.getCmp('spookyapp-voiceover-speed').getValue() / 10).toFixed(1);
                        var emotion = Ext.getCmp('spookyapp-voiceover-emotion').getValue();
                        dialog.close();
                        this.executeVoiceover(text, voice, speed, emotion);
                    },
                    scope: this
                },
                {
                    text: _('cancel'),
                    handler: function() { dialog.close(); }
                }
            ]
        });

        dialog.show();
    },

    /**
     * Выполнить синтез речи.
     *
     * @param {String} text    Текст
     * @param {String} voice   Голос
     * @param {String} speed   Скорость
     * @param {String} emotion Эмоция
     */
    executeVoiceover: function(text, voice, speed, emotion) {
        var mask = new Ext.LoadMask(this.getEl(), {
            msg: _('spookyapp.chunkgenerator.generating_audio') || 'Generating audio...'
        });
        mask.show();

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/voiceover',
                text: text,
                voice: voice,
                speed: speed,
                emotion: emotion
            },
            listeners: {
                success: {
                    fn: function(r) {
                        mask.hide();
                        var data = r.object || {};
                        this.showAudioPlayer(data);
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        mask.hide();
                        MODx.msg.alert(_('error'), r.message || 'Voiceover failed');
                    }
                }
            }
        });
    },

    /**
     * Показать аудио плеер.
     *
     * @param {Object} data Данные: file_url, file_size, duration_estimate
     */
    showAudioPlayer: function(data) {
        var win = new Ext.Window({
            title: _('spookyapp.chunkgenerator.audio_ready') || 'Audio Ready',
            width: 500,
            height: 200,
            modal: true,
            bodyStyle: 'padding: 20px; text-align: center;',
            html: '<audio controls style="width: 100%; margin-bottom: 15px;">'
                + '<source src="' + Ext.util.Format.htmlEncode(data.file_url || '')
                + '" type="audio/ogg"></audio>'
                + '<p style="color:#666;">'
                + (_('spookyapp.chunkgenerator.file_size') || 'Size') + ': '
                + (data.file_size ? Math.round(data.file_size / 1024) + ' KB' : '—')
                + ' | '
                + (_('spookyapp.chunkgenerator.duration') || 'Duration') + ': ~'
                + (data.duration_estimate || '?') + 's'
                + '</p>',
            buttons: [
                {
                    text: _('spookyapp.chunkgenerator.copy_url') || 'Copy URL',
                    handler: function() {
                        SpookyApp.utils.copyToClipboard(data.file_url || '');
                        MODx.msg.status({
                            title: _('success'),
                            message: _('spookyapp.chunkgenerator.copied') || 'Copied!'
                        });
                    }
                },
                {
                    text: _('close'),
                    handler: function() { win.close(); }
                }
            ]
        });
        win.show();
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Actions: Chunk Generation                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Генерация и предпросмотр чанка.
     */
    doPreviewChunk: function() {
        if (!this.currentData) return;

        // ── Собираем текущие данные из формы ──────────────────
        var updatedData = this.collectFormData();

        var mask = new Ext.LoadMask(this.getEl(), {
            msg: _('spookyapp.chunkgenerator.generating') || 'Generating chunk...'
        });
        mask.show();

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/generatechunk',
                type: this.currentType,
                data: Ext.encode(updatedData),
                template: '',
                format: 'html'
            },
            listeners: {
                success: {
                    fn: function(r) {
                        mask.hide();
                        var html = r.object ? r.object.chunk_code : '';
                        this.lastGeneratedChunk = html;
                        this.fireEvent('chunk-generated', html, this.currentType, updatedData);
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        mask.hide();
                        MODx.msg.alert(_('error'), r.message || 'Generation failed');
                    }
                }
            }
        });
    },

    /**
     * Сохранить чанк в БД.
     */
    doSaveChunk: function() {
        if (!this.currentData) return;

        // Сначала генерируем чанк, потом сохраняем
        var updatedData = this.collectFormData();

        var mask = new Ext.LoadMask(this.getEl(), {
            msg: _('spookyapp.chunkgenerator.saving') || 'Saving...'
        });
        mask.show();

        // ── Save (без предварительной генерации chunk_code) ────────
        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/savechunk',
                type: this.currentType,
                external_id: String(updatedData.id || this.currentId || ''),
                title: updatedData.title || updatedData.name || '',
                data: Ext.encode(updatedData)
            },
            listeners: {
                success: {
                    fn: function(r) {
                        mask.hide();
                        var action = r.object ? r.object.action : '';
                        this.lastChunkId = (r.object && r.object.chunk_id)
                            ? r.object.chunk_id : null;
                        MODx.msg.status({
                            title: _('success'),
                            message: (_('spookyapp.chunkgenerator.saved') || 'Saved!')
                                + ' (' + action + ')'
                        });
                        // Обновляем список сохранённых чанков слева
                        var savedGrid = Ext.getCmp('spookyapp-cg-saved-grid');
                        if (savedGrid) { savedGrid.getStore().load(); }
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        mask.hide();
                        MODx.msg.alert(_('error'), r.message || 'Generation failed');
                    }
                }
            }
        });
    },

    /**
     * Сохранить JSON полных данных в буфер обмена.
     */
    doExportJSON: function() {
        if (!this.currentData) return;
        var json = Ext.encode(this.currentData);
        SpookyApp.utils.copyToClipboard(json);
        MODx.msg.status({
            title: _('success'),
            message: _('spookyapp.chunkgenerator.json_copied') || 'JSON copied to clipboard'
        });
    },

    /**
     * Копировать код чанка.
     *
     * Читает формат из нижнего тулбара (spookyapp-chunkgen-output-format-*):
     *   — html     → генерирует HTML на сервере и копирует без открытия превью
     *   — card / brief / text / telegram → копирует сниппет-вызов
     *                 [[SpookyAppChunk? &id=`N` &type=`T` &format=`F`]]
     *                 (требует предварительного сохранения чанка в БД)
     */
    doCopyChunkCode: function() {
        // Читаем формат из нижнего тулбара
        var outputFormat = 'html';
        var outFmtField = Ext.getCmp('spookyapp-chunkgen-output-format-' + this.contentType);
        if (outFmtField) { outputFormat = outFmtField.getValue() || 'html'; }

        if (outputFormat === 'html') {
            // Генерируем HTML и копируем — без открытия окна превью
            this.doGenerateAndCopy();
            return;
        }

        // Для сниппет-вызова нужен chunk_id из БД
        if (!this.lastChunkId) {
            MODx.msg.alert(
                _('spookyapp.chunkgenerator.copy_code') || 'Copy Code',
                _('spookyapp.chunkgenerator.err_save_first')
                    || 'Сначала сохраните чанк в БД (кнопка «Save to DB»), затем копируйте.'
            );
            return;
        }

        var modxCall = '[[SpookyAppChunk?'
            + ' &id=`' + this.lastChunkId + '`'
            + ' &type=`' + (this.currentType || '') + '`'
            + ' &format=`' + outputFormat + '`'
            + ']]';

        SpookyApp.utils.copyToClipboard(modxCall);
        MODx.msg.status({
            title: _('success'),
            message: (_('spookyapp.chunkgenerator.copied') || 'Скопировано!')
                + ' (' + outputFormat + ')'
        });
    },

    /**
     * Сгенерировать HTML чанк тихо (без открытия окна превью) и скопировать в буфер.
     */
    doGenerateAndCopy: function() {
        if (!this.currentData) return;

        var updatedData = this.collectFormData();

        var mask = new Ext.LoadMask(this.getEl(), {
            msg: _('spookyapp.chunkgenerator.generating') || 'Generating...'
        });
        mask.show();

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/generatechunk',
                type: this.currentType,
                data: Ext.encode(updatedData),
                template: '',
                format: 'html'
            },
            listeners: {
                success: {
                    fn: function(r) {
                        mask.hide();
                        var html = r.object ? r.object.chunk_code : '';
                        this.lastGeneratedChunk = html;
                        SpookyApp.utils.copyToClipboard(html);
                        MODx.msg.status({
                            title: _('success'),
                            message: _('spookyapp.chunkgenerator.copied') || 'HTML скопирован!'
                        });
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        mask.hide();
                        MODx.msg.alert(_('error'), r.message || 'Generation failed');
                    },
                    scope: this
                }
            }
        });
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Actions: Generate Embed Codes                          ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Запросить у сервера все 3 формата кода вставки для сохранённого чанка.
     *
     * Требует, чтобы чанк был предварительно сохранён в БД (lastChunkId != null).
     */
    doGenerateEmbedCodes: function() {
        if (!this.lastChunkId) {
            MODx.msg.alert(
                _('spookyapp.chunkgenerator.embed_codes') || 'Generate Code',
                _('spookyapp.chunkgenerator.err_save_first')
                    || 'Сначала сохраните чанк в БД (Save to DB), затем нажмите Generate Code.'
            );
            return;
        }

        var mask = new Ext.LoadMask(this.getEl(), {
            msg: _('spookyapp.chunkgenerator.generating') || 'Generating...'
        });
        mask.show();

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: {
                action: 'chunkgenerator/generatechunk',
                chunk_id: this.lastChunkId
            },
            listeners: {
                success: {
                    fn: function(r) {
                        mask.hide();
                        var codes = r.object ? r.object.embed_codes : null;
                        if (!codes) {
                            MODx.msg.alert(_('error'), 'No embed codes returned');
                            return;
                        }
                        this.showEmbedCodesWindow(codes, r.object.chunk_id);
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        mask.hide();
                        MODx.msg.alert(_('error'), r.message || 'Failed to generate embed codes');
                    }
                }
            }
        });
    },

    /**
     * Показать окно с тремя вкладками: MODX, Telegram, HTML.
     *
     * @param {Object} codes     { modx, telegram, html }
     * @param {Number} chunkId   ID записи в БД
     */
    showEmbedCodesWindow: function(codes, chunkId) {
        var me = this;

        var makeTab = function(tabTitle, code) {
            var textareaId = Ext.id(null, 'spa-embed-ta-');
            return {
                title: tabTitle,
                layout: 'border',
                border: false,
                items: [
                    {
                        region: 'north',
                        height: 52,
                        border: false,
                        xtype: 'toolbar',
                        items: [{
                          text: _('spookyapp.chunkgenerator.copy') || 'Копировать',
                          style: 'margin: 6px;',
                            handler: function() {
                                var ta = Ext.getCmp(textareaId);
                                SpookyApp.utils.copyToClipboard(ta ? ta.getValue() : code);
                                MODx.msg.status({
                                    title: _('success'),
                                    message: _('spookyapp.chunkgenerator.copied') || 'Скопировано!'
                                });
                            }
                        }]
                    },
                    {
                        region: 'center',
                        xtype: 'textarea',
                        id: textareaId,
                        value: code || '',
                        readOnly: true,
                        hideLabel: true,
                        anchor: '100%',
                        cls: 'spookyapp-embed-textarea',
                        style: 'font-family: monospace; font-size: 12px;'
                    }
                ]
            };
        };

        var titleSuffix = chunkId ? ' #' + chunkId : '';
        var win = new Ext.Window({
            title: (_('spookyapp.chunkgenerator.embed_codes') || 'Embed Codes') + titleSuffix,
            width: 660,
            height: 500,
            modal: true,
            maximizable: true,
            layout: 'fit',
            items: [{
                xtype: 'tabpanel',
                activeTab: 0,
                border: false,
                deferredRender: false,
                items: [
                    makeTab('MODX', codes.modx || ''),
                    makeTab('Telegram', codes.telegram || ''),
                    //makeTab('HTML', codes.html || '')
                ]
            }],
            buttons: [{
                text: _('close') || 'Закрыть',
                handler: function() { win.close(); }
            }]
        });

        win.show();
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Helpers                                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Собрать данные из формы (обновлённые пользователем).
     *
     * @return {Object} Данные
     */
    collectFormData: function() {
        var data = Ext.apply({}, this.currentData || {});

        // ── Обновляем текстовые поля ─────────────────────────
        var fieldConfigs = this.getFieldConfigsForType(this.currentType);

        Ext.each(fieldConfigs, function(fc) {
            if (fc.xtype === 'textfield' || fc.xtype === 'textarea') {
                var fieldId = 'spookyapp-chunkgen-field-' + fc.key + '-' + this.contentType;
                var field = Ext.getCmp(fieldId);
                if (field && field.getValue) {
                    // Handle dot-notation keys (e.g. 'tournament.name' → data.tournament.name)
                    var keys = fc.key.split('.');
                    if (keys.length > 1) {
                        var obj = data;
                        for (var k = 0; k < keys.length - 1; k++) {
                            if (!obj[keys[k]] || typeof obj[keys[k]] !== 'object') {
                                obj[keys[k]] = {};
                            }
                            obj = obj[keys[k]];
                        }
                        obj[keys[keys.length - 1]] = field.getValue();
                    } else {
                        data[fc.key] = field.getValue();
                    }
                }
            }
        }, this);

        // ── Обновляем имена игроков из incidents (DOM inputs) ─
        if (data.incidents) {
            var inc = data.incidents;
            Ext.each(inc.goals || [], function(g, i) {
                var pEl = document.getElementById('spookyapp-inc-goal-' + i + '-player');
                var aEl = document.getElementById('spookyapp-inc-goal-' + i + '-assist');
                if (pEl) { g.player = pEl.value; }
                if (aEl) { g.assist = aEl.value; }
            });
            Ext.each(inc.cards || [], function(c, i) {
                var pEl = document.getElementById('spookyapp-inc-card-' + i + '-player');
                if (pEl) { c.player = pEl.value; }
            });
            Ext.each(inc.substitutions || [], function(s, i) {
                var inEl  = document.getElementById('spookyapp-inc-sub-' + i + '-in');
                var outEl = document.getElementById('spookyapp-inc-sub-' + i + '-out');
                if (inEl)  { s.playerIn  = inEl.value; }
                if (outEl) { s.playerOut = outEl.value; }
            });
        }

        return data;
    },

    /**
     * Сбросить форму.
     */
    doReset: function() {
        this.currentData = null;
        this.currentType = null;
        this.currentId = null;
        this.lastGeneratedChunk = null;
        this.lastChunkId = null;

        this.removeAll(true);
        this.add({
            xtype: 'panel',
            border: false,
            bodyStyle: 'text-align:center;padding:80px 20px;color:#999;',
            html: '<p style="font-size:14px;">'
                + (_('spookyapp.chunkgenerator.select_item') || 'Select an item from search results')
                + '</p>'
        });
        this.doLayout();

        this.enableButtons(false);
    },

    /**
     * Включить/выключить кнопки действий.
     *
     * @param {Boolean} enabled Состояние
     */
    enableButtons: function(enabled) {
        var ids = [
            'spookyapp-chunkgen-btn-preview-' + this.contentType,
            'spookyapp-chunkgen-btn-save-' + this.contentType,
            'spookyapp-chunkgen-btn-copy-' + this.contentType,
            'spookyapp-chunkgen-btn-export-' + this.contentType,
            'spookyapp-chunkgen-btn-generate-' + this.contentType
        ];

        Ext.each(ids, function(id) {
            var btn = Ext.getCmp(id);
            if (btn) {
                btn.setDisabled(!enabled);
            }
        });
    }
});
Ext.reg('spookyapp-form-chunkgenerator-details', SpookyApp.form.ChunkGeneratorDetails);
